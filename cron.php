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

// ============ 辅助函数 ============

function getState($tap_pdo, $key, $default = '') {
    $stmt = $tap_pdo->prepare("SELECT config_value FROM tap_state WHERE config_key = ?");
    $stmt->execute([$key]);
    $result = $stmt->fetch();
    return $result ? $result['config_value'] : $default;
}

function setState($tap_pdo, $key, $value) {
    $stmt = $tap_pdo->prepare("INSERT INTO tap_state (config_key, config_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE config_value = ?, updated_at = NOW()");
    $stmt->execute([$key, $value, $value]);
}

function writeLog($tap_pdo, $action, $message, $detail = null) {
    $stmt = $tap_pdo->prepare("INSERT INTO tap_logs (action, message, detail) VALUES (?, ?, ?)");
    $stmt->execute([$action, $message, $detail]);
}

// ============ 清理旧日志 ============

// 分批删除旧日志，避免锁表
$batch_size = 1000;
do {
    $stmt = $tap_pdo->prepare("DELETE FROM tap_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY) LIMIT $batch_size");
    $stmt->execute([$log_retention_days]);
    $deleted = $stmt->rowCount();
} while ($deleted >= $batch_size);

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

// 全局月度用量（shared 渠道，分 count=all 和 count=free 分别查询）
$global_month_used = 0;
$global_today_used = 0;

if ($shared_id_list !== '') {
    $stmt = $newapi_pdo->prepare("SELECT COALESCE(SUM(prompt_tokens + completion_tokens), 0) as total FROM logs WHERE channel_id IN ($shared_id_list) AND created_at >= ?");
    $stmt->execute([$month_start_ts]);
    $global_month_used += (int)$stmt->fetch()['total'];

    $stmt = $newapi_pdo->prepare("SELECT COALESCE(SUM(prompt_tokens + completion_tokens), 0) as total FROM logs WHERE channel_id IN ($shared_id_list) AND created_at >= ?");
    $stmt->execute([$today_start_ts]);
    $global_today_used += (int)$stmt->fetch()['total'];
}

if ($shared_free_id_list !== '') {
    $stmt = $newapi_pdo->prepare("SELECT COALESCE(SUM(prompt_tokens + completion_tokens), 0) as total FROM logs WHERE channel_id IN ($shared_free_id_list) AND `group` = 'free' AND created_at >= ?");
    $stmt->execute([$month_start_ts]);
    $global_month_used += (int)$stmt->fetch()['total'];

    $stmt = $newapi_pdo->prepare("SELECT COALESCE(SUM(prompt_tokens + completion_tokens), 0) as total FROM logs WHERE channel_id IN ($shared_free_id_list) AND `group` = 'free' AND created_at >= ?");
    $stmt->execute([$today_start_ts]);
    $global_today_used += (int)$stmt->fetch()['total'];
}

$global_remaining = max(0, $monthly_tokens - $global_month_used);
$global_today_allowance = $days_remaining > 0 ? intdiv($global_remaining, $days_remaining) : 0;
$global_today_remaining = max(0, $global_today_allowance - $global_today_used);

// ============ 批量查询渠道用量 ============

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

if ($all_count_id_list !== '') {
    $stmt = $newapi_pdo->prepare("SELECT channel_id, COALESCE(SUM(prompt_tokens + completion_tokens), 0) AS total FROM logs WHERE channel_id IN ($all_count_id_list) AND created_at >= ? GROUP BY channel_id");
    $stmt->execute([$today_start_ts]);
    while ($row = $stmt->fetch()) {
        $today_usage_map[(int)$row['channel_id']] = (int)$row['total'];
    }

    $stmt = $newapi_pdo->prepare("SELECT channel_id, COALESCE(SUM(prompt_tokens + completion_tokens), 0) AS total FROM logs WHERE channel_id IN ($all_count_id_list) AND created_at >= ? GROUP BY channel_id");
    $stmt->execute([$month_start_ts]);
    while ($row = $stmt->fetch()) {
        $month_usage_map[(int)$row['channel_id']] = (int)$row['total'];
    }
}

if ($free_count_id_list !== '') {
    $stmt = $newapi_pdo->prepare("SELECT channel_id, COALESCE(SUM(prompt_tokens + completion_tokens), 0) AS total FROM logs WHERE channel_id IN ($free_count_id_list) AND `group` = 'free' AND created_at >= ? GROUP BY channel_id");
    $stmt->execute([$today_start_ts]);
    while ($row = $stmt->fetch()) {
        $today_usage_map[(int)$row['channel_id']] = (int)$row['total'];
    }

    $stmt = $newapi_pdo->prepare("SELECT channel_id, COALESCE(SUM(prompt_tokens + completion_tokens), 0) AS total FROM logs WHERE channel_id IN ($free_count_id_list) AND `group` = 'free' AND created_at >= ? GROUP BY channel_id");
    $stmt->execute([$month_start_ts]);
    while ($row = $stmt->fetch()) {
        $month_usage_map[(int)$row['channel_id']] = (int)$row['total'];
    }
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

    // unlimited 模式仅统计用量，不控制水龙头
    if ($ch_mode === 'unlimited') {
        setState($tap_pdo, "month_used_{$ch_id}", (string)$ch_month_used);
        setState($tap_pdo, "today_used_{$ch_id}", (string)$ch_today_used);
        echo "[" . date('Y-m-d H:i:s') . "] 渠道 #{$ch_id} [unlimited] 仅监控 - 本月: " . formatTokens($ch_month_used) . ", 今日: " . formatTokens($ch_today_used) . "\n";
        continue;
    }

    // 判断该渠道的水龙头状态
    $should_open = $ch_today_used < $ch_today_allowance && $ch_today_allowance > 0;
    $currently_open = getState($tap_pdo, $state_key, '1') === '1';

    // 确定期望的分组
    $desired_groups = $should_open ? $channel['open_groups'] : $channel['closed_groups'];

    // 检查实际分组是否与期望一致
    $stmt = $newapi_pdo->prepare("SELECT `group` FROM channels WHERE id = ?");
    $stmt->execute([$ch_id]);
    $actual_group = $stmt->fetchColumn() ?: '';

    $sync_needed = ($actual_group !== $desired_groups);

    if ($should_open !== $currently_open) {
        // 状态切换：开启 → 关闭 或 关闭 → 开启
        $stmt = $newapi_pdo->prepare("UPDATE channels SET `group` = ? WHERE id = ?");
        $stmt->execute([$desired_groups, $ch_id]);

        if ($should_open) {
            $stmt = $newapi_pdo->prepare("SELECT models, priority, weight FROM channels WHERE id = ?");
            $stmt->execute([$ch_id]);
            $ch_info = $stmt->fetch();
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

            setState($tap_pdo, $state_key, '1');
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

            setState($tap_pdo, $state_key, '0');
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
            $stmt = $newapi_pdo->prepare("SELECT models, priority, weight FROM channels WHERE id = ?");
            $stmt->execute([$ch_id]);
            $ch_info = $stmt->fetch();
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

    // 更新该渠道的状态缓存
    setState($tap_pdo, "month_used_{$ch_id}", (string)$ch_month_used);
    setState($tap_pdo, "today_used_{$ch_id}", (string)$ch_today_used);
    setState($tap_pdo, "today_allowance_{$ch_id}", (string)$ch_today_allowance);
    setState($tap_pdo, "today_remaining_{$ch_id}", (string)$ch_today_remaining);
    setState($tap_pdo, "remaining_{$ch_id}", (string)$ch_remaining);

    if ($should_open) {
        $any_open = true;
    } else {
        $any_closed = true;
    }
}

// ============ 更新全局状态缓存 ============

$global_tap_open = $any_open ? '1' : '0';
setState($tap_pdo, 'tap_open', $global_tap_open);
setState($tap_pdo, 'month_used', (string)$global_month_used);
setState($tap_pdo, 'today_used', (string)$global_today_used);
setState($tap_pdo, 'today_allowance', (string)$global_today_allowance);
setState($tap_pdo, 'today_remaining', (string)$global_today_remaining);
setState($tap_pdo, 'remaining', (string)$global_remaining);
setState($tap_pdo, 'last_check', date('Y-m-d H:i:s'));

// ============ 输出摘要 ============

echo "\n--- 全局摘要 (shared 渠道) ---\n";
echo "  月度总额: " . formatTokens($monthly_tokens) . " tokens\n";
echo "  本月已用: " . formatTokens($global_month_used) . " tokens\n";
echo "  月度剩余: " . formatTokens($global_remaining) . " tokens\n";
echo "  本月天数: {$days_in_month}, 已过: {$day_of_month}, 剩余: {$days_remaining}\n";
echo "  今日额度: " . formatTokens($global_today_allowance) . " tokens\n";
echo "  今日已用: " . formatTokens($global_today_used) . " tokens\n";
echo "  今日剩余: " . formatTokens($global_today_remaining) . " tokens\n";
