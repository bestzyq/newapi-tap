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
    putenv("$key=$value");
    $_ENV[$key] = $value;
}

// ============ 辅助函数：读取环境变量 ============
function env(string $key, string $default = ''): string {
    $val = getenv($key);
    return $val !== false ? $val : $default;
}

function envInt(string $key, int $default = 0): int {
    $val = getenv($key);
    return $val !== false ? (int)$val : $default;
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

// ============ 每月免费额度配置 ============
$monthly_tokens = envInt('MONTHLY_TOKENS', 100000000);

// ============ 水龙头渠道配置 ============
// 格式：渠道ID:月度额度:开启分组:关闭分组
// 月度额度为 0 表示使用总额度均分
$tap_channels_raw = env('TAP_CHANNELS', '');
$tap_channels = [];
if ($tap_channels_raw !== '') {
    foreach (explode(';', $tap_channels_raw) as $ch_str) {
        $parts = explode(':', $ch_str, 4);
        if (count($parts) >= 4) {
            $tap_channels[] = [
                'channel_id'    => (int)$parts[0],
                'monthly_tokens' => (int)$parts[1],
                'open_groups'   => $parts[2],
                'closed_groups' => $parts[3],
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
