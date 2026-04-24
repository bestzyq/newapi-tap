<?php
/**
 * NewAPI-TAP 定时检查脚本
 * 每5分钟由 cron 执行，检查用量并控制水龙头开关
 * 
 * 逻辑说明：
 * 1. 从 newapi.logs 查询本月配置渠道的 token 消耗 (prompt_tokens + completion_tokens)
 * 2. 计算剩余 token = 月度总额 - 已消耗
 * 3. 计算今日可用 = 剩余 token / 本月剩余天数（含今天）
 * 4. 查询今日已消耗 token
 * 5. 如果今日已消耗 >= 今日可用 → 关闭水龙头
 *    如果今日已消耗 <  今日可用 → 开启水龙头
 * 
 * logs 表关键字段:
 *   id, user_id, created_at(Unix时间戳), prompt_tokens, completion_tokens, channel_id
 */
require_once __DIR__ . '/config.php';

$newapi_pdo = getNewapiDB();  // 读 logs，写 channels
$tap_pdo = getTapDB();        // 读写状态和日志

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

$stmt = $tap_pdo->prepare("DELETE FROM tap_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)");
$stmt->execute([$log_retention_days]);

// ============ 检查月份切换 ============

$current_month = date('Y-m');
$stored_month = getState($tap_pdo, 'current_month', '');

if ($stored_month !== $current_month) {
    setState($tap_pdo, 'current_month', $current_month);
    writeLog($tap_pdo, 'month_reset', "月份切换: $stored_month → $current_month",
        json_encode(['old_month' => $stored_month, 'new_month' => $current_month]));
}

// ============ 计算用量 ============

// logs.created_at 是 Unix 时间戳
$channel_ids = array_column($tap_channels, 'channel_id');
$channel_id_list = implode(',', array_map('intval', $channel_ids));

$month_start_ts = strtotime(date('Y-m-01 00:00:00'));
$today_start_ts = strtotime(date('Y-m-d 00:00:00'));

// 本月已用 token（仅统计配置渠道的用量）
$stmt = $newapi_pdo->prepare("SELECT COALESCE(SUM(prompt_tokens + completion_tokens), 0) as total FROM logs WHERE channel_id IN ($channel_id_list) AND created_at >= ?");
$stmt->execute([$month_start_ts]);
$month_used = (int)$stmt->fetch()['total'];

// 今日已用 token
$stmt = $newapi_pdo->prepare("SELECT COALESCE(SUM(prompt_tokens + completion_tokens), 0) as total FROM logs WHERE channel_id IN ($channel_id_list) AND created_at >= ?");
$stmt->execute([$today_start_ts]);
$today_used = (int)$stmt->fetch()['total'];

// ============ 计算今日额度 ============

$remaining = max(0, $monthly_tokens - $month_used);
$days_in_month = (int)date('t');
$day_of_month = (int)date('j');
$days_remaining = $days_in_month - $day_of_month + 1; // 含今天

$today_allowance = $days_remaining > 0 ? intdiv($remaining, $days_remaining) : 0;
$today_remaining = max(0, $today_allowance - $today_used);

// ============ 判断水龙头状态 ============

$should_open = $today_used < $today_allowance && $today_allowance > 0;
$currently_open = getState($tap_pdo, 'tap_open', '1') === '1';

$action_taken = false;

if ($should_open && !$currently_open) {
    // 开启水龙头
    foreach ($tap_channels as $channel) {
        $stmt = $newapi_pdo->prepare("UPDATE channels SET `group` = ? WHERE id = ?");
        $stmt->execute([$channel['open_groups'], $channel['channel_id']]);
    }
    setState($tap_pdo, 'tap_open', '1');
    writeLog($tap_pdo, 'tap_open', '水龙头已开启', json_encode([
        'today_used' => $today_used,
        'today_allowance' => $today_allowance,
        'today_remaining' => $today_remaining,
        'channels' => $channel_ids,
    ]));
    $action_taken = true;
    echo "[" . date('Y-m-d H:i:s') . "] 水龙头已开启\n";
} elseif (!$should_open && $currently_open) {
    // 关闭水龙头
    foreach ($tap_channels as $channel) {
        $stmt = $newapi_pdo->prepare("UPDATE channels SET `group` = ? WHERE id = ?");
        $stmt->execute([$channel['closed_groups'], $channel['channel_id']]);
    }
    setState($tap_pdo, 'tap_open', '0');
    writeLog($tap_pdo, 'tap_close', '水龙头已关闭', json_encode([
        'today_used' => $today_used,
        'today_allowance' => $today_allowance,
        'reason' => $today_allowance <= 0 ? '月度额度已耗尽' : '今日额度已用完',
        'channels' => $channel_ids,
    ]));
    $action_taken = true;
    echo "[" . date('Y-m-d H:i:s') . "] 水龙头已关闭\n";
}

// ============ 更新状态缓存 ============

setState($tap_pdo, 'month_used', (string)$month_used);
setState($tap_pdo, 'today_used', (string)$today_used);
setState($tap_pdo, 'today_allowance', (string)$today_allowance);
setState($tap_pdo, 'today_remaining', (string)$today_remaining);
setState($tap_pdo, 'remaining', (string)$remaining);
setState($tap_pdo, 'last_check', date('Y-m-d H:i:s'));

// ============ 输出摘要 ============

if (!$action_taken) {
    echo "[" . date('Y-m-d H:i:s') . "] 状态无变化 - 水龙头: " . ($currently_open ? '开启' : '关闭') . "\n";
}

echo "  月度总额: " . formatTokens($monthly_tokens) . " tokens\n";
echo "  本月已用: " . formatTokens($month_used) . " tokens\n";
echo "  月度剩余: " . formatTokens($remaining) . " tokens\n";
echo "  本月天数: {$days_in_month}, 已过: {$day_of_month}, 剩余: {$days_remaining}\n";
echo "  今日额度: " . formatTokens($today_allowance) . " tokens\n";
echo "  今日已用: " . formatTokens($today_used) . " tokens\n";
echo "  今日剩余: " . formatTokens($today_remaining) . " tokens\n";
