<?php
/**
 * NewAPI-TAP 定时检查脚本
 * 每5分钟由 cron 执行，检查用量并控制水龙头开关
 * 
 * 渠道模式说明：
 * - shared:  共享月度总额度，按剩余天数均分
 * - monthly: 独立月度额度，按剩余天数均分
 * - daily:   独立日额度，每日固定额度，不参与月度总额计算
 */
require_once __DIR__ . '/config.php';

$newapi_pdo = getNewapiDB();
$tap_pdo = getTapDB();

// ============ 清理旧日志（仅每天凌晨执行一次，避免频繁锁表） ============

$last_cleanup = getState($tap_pdo, 'last_log_cleanup', '');
$today_date = date('Y-m-d');
if ($last_cleanup !== $today_date) {
    $batch_size = 1000;
    do {
        $stmt = $tap_pdo->prepare("DELETE FROM tap_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY) LIMIT $batch_size");
        $stmt->execute([$log_retention_days]);
        $deleted = $stmt->rowCount();
    } while ($deleted >= $batch_size);
    setState($tap_pdo, 'last_log_cleanup', $today_date);
}

// ============ 检查月份切换 ============

$current_month = date('Y-m');
$stored_month = getState($tap_pdo, 'current_month', '');

if ($stored_month !== $current_month) {
    setState($tap_pdo, 'current_month', $current_month);
    writeLog($tap_pdo, 'month_reset', "月份切换: $stored_month → $current_month",
        json_encode(['old_month' => $stored_month, 'new_month' => $current_month]));
}

// ============ 计算全局月度用量（仅 shared 渠道参与） ============

$shared_channel_ids = [];
$shared_free_channel_ids = [];
$all_channel_ids = [];
foreach ($tap_channels as $ch) {
    $all_channel_ids[] = $ch['channel_id'];
    if ($ch['mode'] === 'shared') {
        if ($ch['count'] === 'free') {
            $shared_free_channel_ids[] = $ch['channel_id'];
        } else {
            $shared_channel_ids[] = $ch['channel_id'];
        }
    }
}
$shared_id_list = implode(',', array_map('intval', $shared_channel_ids));
$shared_free_id_list = implode(',', array_map('intval', $shared_free_channel_ids));

$month_start_ts = strtotime(date('Y-m-01 00:00:00'));
$today_start_ts = strtotime(date('Y-m-d 00:00:00'));
$days_in_month = (int)date('t');
$day_of_month = (int)date('j');
$days_remaining = $days_in_month - $day_of_month + 1;

// 全局月度用量（shared 渠道，使用条件聚合合并为 2 次查询代替 4 次）
$global_month_used = 0;
$global_today_used = 0;

// 构建所有 shared 渠道的 IN 列表（合并 shared + shared_free）
$all_shared_ids = array_unique(array_merge($shared_channel_ids, $shared_free_channel_ids));
$all_shared_id_list = implode(',', array_map('intval', $all_shared_ids));

if ($all_shared_id_list !== '') {
    // 构建条件聚合表达式：分别统计 count=all 渠道总量 和 count=free 渠道的 free 组用量
    $shared_case = $shared_id_list !== ''
        ? "SUM(CASE WHEN channel_id IN ($shared_id_list) THEN prompt_tokens + completion_tokens ELSE 0 END)"
        : "0";
    $free_case = $shared_free_id_list !== ''
        ? "SUM(CASE WHEN channel_id IN ($shared_free_id_list) AND `group` = 'free' THEN prompt_tokens + completion_tokens ELSE 0 END)"
        : "0";

    // 月度查询：1 次代替 2 次
    $stmt = $newapi_pdo->prepare(
        "SELECT COALESCE($shared_case, 0) AS shared_total, COALESCE($free_case, 0) AS free_total
         FROM logs WHERE channel_id IN ($all_shared_id_list) AND created_at >= ?"
    );
    $stmt->execute([$month_start_ts]);
    $row = $stmt->fetch();
    $global_month_used = (int)$row['shared_total'] + (int)$row['free_total'];

    // 今日查询：1 次代替 2 次
    $stmt = $newapi_pdo->prepare(
        "SELECT COALESCE($shared_case, 0) AS shared_total, COALESCE($free_case, 0) AS free_total
         FROM logs WHERE channel_id IN ($all_shared_id_list) AND created_at >= ?"
    );
    $stmt->execute([$today_start_ts]);
    $row = $stmt->fetch();
    $global_today_used = (int)$row['shared_total'] + (int)$row['free_total'];
}

$global_remaining = max(0, $monthly_tokens - $global_month_used);
$global_today_allowance = $days_remaining > 0 ? intdiv($global_remaining, $days_remaining) : 0;
$global_today_remaining = max(0, $global_today_allowance - $global_today_used);

// ============ 批量查询渠道用量（条件聚合，4 次查询合并为 2 次） ============

$all_id_list = implode(',', array_map('intval', $all_channel_ids));
$free_count_ids = [];
$all_count_ids = [];
foreach ($tap_channels as $ch) {
    if ($ch['count'] === 'free') {
        $free_count_ids[] = $ch['channel_id'];
    } else {
        $all_count_ids[] = $ch['channel_id'];
    }
}
$all_count_id_list = implode(',', array_map('intval', $all_count_ids));
$free_count_id_list = implode(',', array_map('intval', $free_count_ids));

$today_usage_map = [];
$month_usage_map = [];

if ($all_id_list !== '') {
    // 构建 WHERE 条件：all_count 渠道统计全部日志，free_count 渠道仅统计 free 组
    $usage_where = '';
    if ($all_count_id_list !== '' && $free_count_id_list !== '') {
        $usage_where = "(channel_id IN ($all_count_id_list) OR (channel_id IN ($free_count_id_list) AND `group` = 'free'))";
    } elseif ($all_count_id_list !== '') {
        $usage_where = "channel_id IN ($all_count_id_list)";
    } else {
        $usage_where = "channel_id IN ($free_count_id_list) AND `group` = 'free'";
    }

    // 今日用量：1 次查询代替 2 次
    $stmt = $newapi_pdo->prepare("SELECT channel_id, COALESCE(SUM(prompt_tokens + completion_tokens), 0) AS total FROM logs WHERE $usage_where AND created_at >= ? GROUP BY channel_id");
    $stmt->execute([$today_start_ts]);
    while ($row = $stmt->fetch()) {
        $today_usage_map[(int)$row['channel_id']] = (int)$row['total'];
    }

    // 月度用量：1 次查询代替 2 次
    $stmt = $newapi_pdo->prepare("SELECT channel_id, COALESCE(SUM(prompt_tokens + completion_tokens), 0) AS total FROM logs WHERE $usage_where AND created_at >= ? GROUP BY channel_id");
    $stmt->execute([$month_start_ts]);
    while ($row = $stmt->fetch()) {
        $month_usage_map[(int)$row['channel_id']] = (int)$row['total'];
    }
}

// ============ 预加载渠道信息（消除循环内 N+1 查询） ============

// 1. 批量获取所有渠道的 group/models/priority/weight
$channel_info_map = [];
if ($all_id_list !== '') {
    $stmt = $newapi_pdo->prepare("SELECT id, `group`, models, priority, weight FROM channels WHERE id IN ($all_id_list)");
    $stmt->execute();
    while ($row = $stmt->fetch()) {
        $channel_info_map[(int)$row['id']] = $row;
    }
}

// 2. 批量获取所有渠道的 tap_state（替代循环内逐个 getState）
$channel_state_keys = [];
foreach ($tap_channels as $ch) {
    $ch_id = $ch['channel_id'];
    if ($ch['mode'] !== 'unlimited') {
        $channel_state_keys[] = "tap_open_{$ch_id}";
    }
}
$channel_state_cache = [];
if (!empty($channel_state_keys)) {
    $state_ph = implode(',', array_fill(0, count($channel_state_keys), '?'));
    $stmt = $tap_pdo->prepare("SELECT config_key, config_value FROM tap_state WHERE config_key IN ($state_ph)");
    $stmt->execute($channel_state_keys);
    while ($row = $stmt->fetch()) {
        $channel_state_cache[$row['config_key']] = $row['config_value'];
    }
}

// 3. 收集待批量写入的状态
$pending_states = [];
function queueState($key, $value) {
    global $pending_states;
    $pending_states[$key] = $value;
}
function flushStates($tap_pdo) {
    global $pending_states;
    if (empty($pending_states)) return;
    $sql = "INSERT INTO tap_state (config_key, config_value) VALUES ";
    $pairs = [];
    $params = [];
    foreach ($pending_states as $k => $v) {
        $pairs[] = "(?, ?)";
        $params[] = $k;
        $params[] = $v;
    }
    $sql .= implode(', ', $pairs);
    $sql .= " ON DUPLICATE KEY UPDATE config_value = VALUES(config_value), updated_at = NOW()";
    $stmt = $tap_pdo->prepare($sql);
    $stmt->execute($params);
    $pending_states = [];
}

// ============ 逐渠道检查与控制 ============

$any_open = false;
$any_closed = false;

foreach ($tap_channels as $channel) {
    $ch_id = $channel['channel_id'];
    $ch_mode = $channel['mode'];
    $ch_quota = $channel['quota'];
    $state_key = "tap_open_{$ch_id}";

    // 从批量查询结果中获取用量
    $ch_today_used = $today_usage_map[$ch_id] ?? 0;
    $ch_month_used = $month_usage_map[$ch_id] ?? 0;

    // 根据模式确定今日额度
    switch ($ch_mode) {
        case 'unlimited':
            $ch_today_allowance = 0;
            $ch_today_remaining = 0;
            $ch_monthly = 0;
            $ch_remaining = 0;
            $ch_month_pct = 0;
            break;

        case 'daily':
            $ch_today_allowance = $ch_quota;
            $ch_today_remaining = max(0, $ch_today_allowance - $ch_today_used);
            $ch_monthly = 0;
            $ch_remaining = 0;
            $ch_month_pct = 0;
            break;

        case 'monthly':
            $ch_monthly = $ch_quota;
            $ch_remaining = max(0, $ch_monthly - $ch_month_used);
            $ch_today_allowance = $days_remaining > 0 ? intdiv($ch_remaining, $days_remaining) : 0;
            $ch_today_remaining = max(0, $ch_today_allowance - $ch_today_used);
            $ch_month_pct = $ch_monthly > 0 ? min(100, round($ch_month_used / $ch_monthly * 100, 1)) : 0;
            break;

        case 'shared':
        default:
            $ch_monthly = $monthly_tokens;
            $ch_remaining = $global_remaining;
            $ch_today_allowance = $global_today_allowance;
            $ch_today_used = $global_today_used;
            $ch_month_used = $global_month_used;
            $ch_today_remaining = $global_today_remaining;
            $ch_month_pct = $monthly_tokens > 0 ? min(100, round($global_month_used / $monthly_tokens * 100, 1)) : 0;
            break;
    }

    // unlimited 模式仅统计用量，不控制水龙头，但计入全局开放状态
    if ($ch_mode === 'unlimited') {
        $any_open = true;
        queueState("month_used_{$ch_id}", (string)$ch_month_used);
        queueState("today_used_{$ch_id}", (string)$ch_today_used);
        echo "[" . date('Y-m-d H:i:s') . "] 渠道 #{$ch_id} [unlimited] 仅监控 - 本月: " . formatTokens($ch_month_used) . ", 今日: " . formatTokens($ch_today_used) . "\n";
        continue;
    }

    // 判断该渠道的水龙头状态
    $should_open = $ch_today_used < $ch_today_allowance && $ch_today_allowance > 0;
    $currently_open = ($channel_state_cache[$state_key] ?? '1') === '1';

    // 确定期望的分组
    $desired_groups = $should_open ? $channel['open_groups'] : $channel['closed_groups'];

    // 从预加载缓存获取实际分组（消除 N+1 查询）
    $actual_group = $channel_info_map[$ch_id]['group'] ?? '';

    $sync_needed = ($actual_group !== $desired_groups);

    if ($should_open !== $currently_open) {
        // 状态切换：开启 → 关闭 或 关闭 → 开启
        $stmt = $newapi_pdo->prepare("UPDATE channels SET `group` = ? WHERE id = ?");
        $stmt->execute([$desired_groups, $ch_id]);

        if ($should_open) {
            // 从预加载缓存获取渠道信息（消除 N+1 查询）
            $ch_info = $channel_info_map[$ch_id] ?? [];
            $models_str = $ch_info['models'] ?? '';
            $ch_priority = (int)($ch_info['priority'] ?? 0);
            $ch_weight = (int)($ch_info['weight'] ?? 0);
            $models = array_filter(array_map('trim', explode(',', $models_str)));

            $abilities_added = 0;
            foreach ($models as $model) {
                $stmt = $newapi_pdo->prepare(
                    "INSERT INTO abilities (`group`, model, channel_id, enabled, priority, weight, tag) 
                     VALUES ('free', ?, ?, 1, ?, ?, '') 
                     ON DUPLICATE KEY UPDATE enabled = VALUES(enabled), priority = VALUES(priority), weight = VALUES(weight), tag = VALUES(tag)"
                );
                $stmt->execute([
                    $model,
                    $ch_id,
                    $ch_priority,
                    $ch_weight,
                ]);
                $abilities_added++;
            }

            queueState($state_key, '1');
            writeLog($tap_pdo, 'tap_open', "渠道 #{$ch_id} [{$ch_mode}] 水龙头已开启", json_encode([
                'channel_id' => $ch_id,
                'mode' => $ch_mode,
                'today_used' => $ch_today_used,
                'today_allowance' => $ch_today_allowance,
                'today_remaining' => $ch_today_remaining,
                'abilities_added' => $abilities_added,
            ]));
            $any_open = true;
            echo "[" . date('Y-m-d H:i:s') . "] 渠道 #{$ch_id} [{$ch_mode}] 水龙头已开启 (添加 {$abilities_added} 条 abilities)\n";
        } else {
            $stmt = $newapi_pdo->prepare("DELETE FROM abilities WHERE `group` = 'free' AND channel_id = ?");
            $stmt->execute([$ch_id]);
            $abilities_removed = $stmt->rowCount();

            queueState($state_key, '0');
            writeLog($tap_pdo, 'tap_close', "渠道 #{$ch_id} [{$ch_mode}] 水龙头已关闭", json_encode([
                'channel_id' => $ch_id,
                'mode' => $ch_mode,
                'today_used' => $ch_today_used,
                'today_allowance' => $ch_today_allowance,
                'reason' => $ch_today_allowance <= 0 ? '额度已耗尽' : '今日额度已用完',
                'abilities_removed' => $abilities_removed,
            ]));
            $any_closed = true;
            echo "[" . date('Y-m-d H:i:s') . "] 渠道 #{$ch_id} [{$ch_mode}] 水龙头已关闭 (移除 {$abilities_removed} 条 abilities)\n";
        }
    } elseif ($sync_needed) {
        // 状态未变但分组需要同步
        $stmt = $newapi_pdo->prepare("UPDATE channels SET `group` = ? WHERE id = ?");
        $stmt->execute([$desired_groups, $ch_id]);

        if ($should_open) {
            // 从预加载缓存获取渠道信息（消除 N+1 查询）
            $ch_info = $channel_info_map[$ch_id] ?? [];
            $models_str = $ch_info['models'] ?? '';
            $ch_priority = (int)($ch_info['priority'] ?? 0);
            $ch_weight = (int)($ch_info['weight'] ?? 0);
            $models = array_filter(array_map('trim', explode(',', $models_str)));

            foreach ($models as $model) {
                $stmt = $newapi_pdo->prepare(
                    "INSERT INTO abilities (`group`, model, channel_id, enabled, priority, weight, tag) 
                     VALUES ('free', ?, ?, 1, ?, ?, '') 
                     ON DUPLICATE KEY UPDATE enabled = VALUES(enabled), priority = VALUES(priority), weight = VALUES(weight), tag = VALUES(tag)"
                );
                $stmt->execute([
                    $model,
                    $ch_id,
                    $ch_priority,
                    $ch_weight,
                ]);
            }

            writeLog($tap_pdo, 'tap_sync', "渠道 #{$ch_id} [{$ch_mode}] 分组同步", json_encode([
                'channel_id' => $ch_id,
                'mode' => $ch_mode,
                'old_group' => $actual_group,
                'new_group' => $desired_groups,
            ]));
            echo "[" . date('Y-m-d H:i:s') . "] 渠道 #{$ch_id} [{$ch_mode}] 分组同步: {$actual_group} → {$desired_groups}\n";
        } else {
            $stmt = $newapi_pdo->prepare("DELETE FROM abilities WHERE `group` = 'free' AND channel_id = ?");
            $stmt->execute([$ch_id]);

            writeLog($tap_pdo, 'tap_sync', "渠道 #{$ch_id} [{$ch_mode}] 分组同步", json_encode([
                'channel_id' => $ch_id,
                'mode' => $ch_mode,
                'old_group' => $actual_group,
                'new_group' => $desired_groups,
            ]));
            echo "[" . date('Y-m-d H:i:s') . "] 渠道 #{$ch_id} [{$ch_mode}] 分组同步: {$actual_group} → {$desired_groups}\n";
        }
    } else {
        echo "[" . date('Y-m-d H:i:s') . "] 渠道 #{$ch_id} [{$ch_mode}] 状态无变化 - 水龙头: " . ($currently_open ? '开启' : '关闭') . "\n";
    }

    // 更新该渠道的状态缓存（加入队列，稍后批量写入）
    queueState("month_used_{$ch_id}", (string)$ch_month_used);
    queueState("today_used_{$ch_id}", (string)$ch_today_used);
    queueState("today_allowance_{$ch_id}", (string)$ch_today_allowance);
    queueState("today_remaining_{$ch_id}", (string)$ch_today_remaining);
    queueState("remaining_{$ch_id}", (string)$ch_remaining);

    if ($should_open) {
        $any_open = true;
    } else {
        $any_closed = true;
    }
}

// ============ 更新全局状态缓存（批量写入） ============

$global_tap_open = $any_open ? '1' : '0';
queueState('tap_open', $global_tap_open);
queueState('month_used', (string)$global_month_used);
queueState('today_used', (string)$global_today_used);
queueState('today_allowance', (string)$global_today_allowance);
queueState('today_remaining', (string)$global_today_remaining);
queueState('remaining', (string)$global_remaining);
queueState('last_check', date('Y-m-d H:i:s'));

// 一次性批量写入所有待更新的状态（替代逐条 INSERT/UPDATE）
flushStates($tap_pdo);

// ============ 缓存 7 天趋势数据（供 index.php 读取，避免每次查 logs 大表） ============

if ($all_id_list !== '') {
    $seven_days_ago_ts = strtotime(date('Y-m-d 00:00:00', strtotime('-6 days')));
    $today_end_ts = strtotime(date('Y-m-d 23:59:59'));
    $stmt = $newapi_pdo->prepare(
        "SELECT FROM_UNIXTIME(created_at, '%Y-%m-%d') AS day, COALESCE(SUM(prompt_tokens + completion_tokens), 0) AS total
         FROM logs WHERE channel_id IN ($all_id_list) AND created_at >= ? AND created_at <= ? GROUP BY day"
    );
    $stmt->execute([$seven_days_ago_ts, $today_end_ts]);
    $daily_trend = [];
    while ($row = $stmt->fetch()) {
        $daily_trend[$row['day']] = (int)$row['total'];
    }
    // 填充缺失日期
    for ($i = 6; $i >= 0; $i--) {
        $date = date('Y-m-d', strtotime("-$i days"));
        if (!isset($daily_trend[$date])) {
            $daily_trend[$date] = 0;
        }
    }
    ksort($daily_trend);
    // 写入缓存（此时 flushStates 已执行，单独写入 1 条）
    $stmt = $tap_pdo->prepare("INSERT INTO tap_state (config_key, config_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE config_value = VALUES(config_value), updated_at = NOW()");
    $stmt->execute(['daily_trend', json_encode($daily_trend)]);
}

// ============ 输出摘要 ============

echo "\n--- 全局摘要 (shared 渠道) ---\n";
echo "  月度总额: " . formatTokens($monthly_tokens) . " tokens\n";
echo "  本月已用: " . formatTokens($global_month_used) . " tokens\n";
echo "  月度剩余: " . formatTokens($global_remaining) . " tokens\n";
echo "  本月天数: {$days_in_month}, 已过: {$day_of_month}, 剩余: {$days_remaining}\n";
echo "  今日额度: " . formatTokens($global_today_allowance) . " tokens\n";
echo "  今日已用: " . formatTokens($global_today_used) . " tokens\n";
echo "  今日剩余: " . formatTokens($global_today_remaining) . " tokens\n";
