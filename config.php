<?php
/**
 * NewAPI-TAP 配置文件
 * 
 * 水龙头控制系统：每月免费提供一定量 token，每日平均分配，超量关水龙头
 * 
 * 配置从 .env 文件读取，请复制 .env.example 为 .env 并填写实际值
 */

// ============ 加载 .env 文件 ============
$env_file = __DIR__ . '/.env';
if (!file_exists($env_file)) {
    die('错误：未找到 .env 文件，请复制 .env.example 为 .env 并填写配置');
}

$_tap_env = [];
$env_lines = file($env_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
foreach ($env_lines as $line) {
    $line = trim($line);
    if ($line === '' || $line[0] === '#') {
        continue;
    }
    if (strpos($line, '=') === false) {
        continue;
    }
    list($key, $value) = explode('=', $line, 2);
    $key = trim($key);
    $value = trim($value);
    $_tap_env[$key] = $value;
}

// ============ 辅助函数：读取环境变量 ============
function env(string $key, string $default = ''): string {
    global $_tap_env;
    return isset($_tap_env[$key]) ? $_tap_env[$key] : $default;
}

function envInt(string $key, int $default = 0): int {
    global $_tap_env;
    return isset($_tap_env[$key]) ? (int)$_tap_env[$key] : $default;
}

// ============ 数据库配置 ============
$newapi_db_host = env('NEWAPI_DB_HOST', 'localhost');
$newapi_db_user = env('NEWAPI_DB_USER', 'newapi');
$newapi_db_pass = env('NEWAPI_DB_PASS', 'newapi');
$newapi_db_name = env('NEWAPI_DB_NAME', 'newapi');

$tap_db_host = env('TAP_DB_HOST', 'localhost');
$tap_db_user = env('TAP_DB_USER', 'newapi_tap');
$tap_db_pass = env('TAP_DB_PASS', 'newapi_tap');
$tap_db_name = env('TAP_DB_NAME', 'newapi_tap');

// ============ 站点配置 ============
$site_name = env('SITE_NAME', 'NewAPI-TAP');
$api_site_url = env('API_SITE_URL', '');
$announcement = env('ANNOUNCEMENT', '');

// ============ 每月免费额度配置 ============
$monthly_tokens = envInt('MONTHLY_TOKENS', 100000000);

// ============ 水龙头渠道配置 ============
// 格式：渠道ID:模式:额度:统计:开启分组:关闭分组
// 模式：shared=共享总额度均分, monthly=独立月度额度, daily=独立日额度, unlimited=不限量(仅监控)
// 统计：all=统计渠道全部调用, free=仅统计free组调用
// unlimited 模式只需4段：渠道ID:unlimited:0:统计
$tap_channels_raw = env('TAP_CHANNELS', '');
$tap_channels = [];
if ($tap_channels_raw !== '') {
    foreach (explode(';', $tap_channels_raw) as $ch_str) {
        $parts = explode(':', $ch_str, 6);
        $mode = isset($parts[1]) ? $parts[1] : 'shared';
        if (!in_array($mode, ['shared', 'monthly', 'daily', 'unlimited'])) {
            $mode = 'shared';
        }

        if ($mode === 'unlimited') {
            $count = isset($parts[3]) ? $parts[3] : 'all';
            if (!in_array($count, ['all', 'free'])) {
                $count = 'all';
            }
            $tap_channels[] = [
                'channel_id'    => (int)$parts[0],
                'mode'          => 'unlimited',
                'quota'         => 0,
                'count'         => $count,
                'open_groups'   => '',
                'closed_groups' => '',
            ];
        } elseif (count($parts) >= 6) {
            $count = $parts[3];
            if (!in_array($count, ['all', 'free'])) {
                $count = 'all';
            }
            $tap_channels[] = [
                'channel_id'    => (int)$parts[0],
                'mode'          => $mode,
                'quota'         => (int)$parts[2],
                'count'         => $count,
                'open_groups'   => $parts[4],
                'closed_groups' => $parts[5],
            ];
        } elseif (count($parts) >= 5) {
            $tap_channels[] = [
                'channel_id'    => (int)$parts[0],
                'mode'          => $mode,
                'quota'         => (int)$parts[2],
                'count'         => 'all',
                'open_groups'   => $parts[3],
                'closed_groups' => $parts[4],
            ];
        }
    }
}

// ============ 时间配置 ============
$check_interval = envInt('CHECK_INTERVAL', 300);
date_default_timezone_set('Asia/Shanghai');

// ============ 日志保留天数 ============
$log_retention_days = envInt('LOG_RETENTION_DAYS', 90);

// ============ 访问密钥 ============
$access_key = env('ACCESS_KEY', '');

// ============ 以下为内部函数，一般无需修改 ============

/**
 * 获取 newapi 数据库连接
 */
function getNewapiDB() {
    global $newapi_db_host, $newapi_db_user, $newapi_db_pass, $newapi_db_name;
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO(
            "mysql:host=$newapi_db_host;dbname=$newapi_db_name;charset=utf8mb4",
            $newapi_db_user,
            $newapi_db_pass,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]
        );
    }
    return $pdo;
}

/**
 * 获取 tap 数据库连接（读写）
 */
function getTapDB() {
    global $tap_db_host, $tap_db_user, $tap_db_pass, $tap_db_name;
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO(
            "mysql:host=$tap_db_host;dbname=$tap_db_name;charset=utf8mb4",
            $tap_db_user,
            $tap_db_pass,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]
        );
    }
    return $pdo;
}

/**
 * 格式化 token 数量为可读显示
 */
function formatTokens($tokens) {
    if ($tokens >= 1000000000) {
        return round($tokens / 1000000000, 2) . 'B';
    }
    if ($tokens >= 1000000) {
        return round($tokens / 1000000, 2) . 'M';
    }
    if ($tokens >= 1000) {
        return round($tokens / 1000, 1) . 'K';
    }
    return number_format($tokens);
}

/**
 * 格式化数字简写（图表用）
 */
function formatNumber($num) {
    return formatTokens($num);
}

/**
 * 读取 tap_state 配置值
 */
function getState($tap_pdo, $key, $default = '') {
    $stmt = $tap_pdo->prepare("SELECT config_value FROM tap_state WHERE config_key = ?");
    $stmt->execute([$key]);
    $result = $stmt->fetch();
    return $result ? $result['config_value'] : $default;
}

/**
 * 写入 tap_state 配置值
 */
function setState($tap_pdo, $key, $value) {
    $stmt = $tap_pdo->prepare("INSERT INTO tap_state (config_key, config_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE config_value = ?, updated_at = NOW()");
    $stmt->execute([$key, $value, $value]);
}

/**
 * 写入操作日志
 */
function writeLog($tap_pdo, $action, $message, $detail = null) {
    $stmt = $tap_pdo->prepare("INSERT INTO tap_logs (action, message, detail) VALUES (?, ?, ?)");
    $stmt->execute([$action, $message, $detail]);
}
