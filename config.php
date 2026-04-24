<?php
/**
 * NewAPI-TAP 配置文件
 * 
 * 水龙头控制系统：每月免费提供一定量 token，每日平均分配，超量关水龙头
 */

// ============ 数据库配置 ============
// newapi 数据库（查询 logs 表统计用量，读写 channels/abilities 表控制水龙头）
$newapi_db_host = 'localhost';
$newapi_db_user = 'newapi';
$newapi_db_pass = 'newapi';
$newapi_db_name = 'newapi';

// newapi-tap 自身数据库（读写，存储状态和日志）
$tap_db_host = 'localhost';
$tap_db_user = 'newapi_tap';
$tap_db_pass = 'newapi_tap';
$tap_db_name = 'newapi_tap';

// ============ 每月免费额度配置 ============
// 单位：token
$monthly_tokens = 100000000; // 1亿 token

// ============ 水龙头渠道配置 ============
// 支持多渠道配置，方便扩展
// open_groups: 水龙头开启时的分组（包含免费组）
// closed_groups: 水龙头关闭时的分组（不含免费组）
// abilities 参数（enabled/priority/weight/tag）自动从 channels 表读取，无需配置
$tap_channels = [
    [
        'channel_id'    => 35,
        'open_groups'   => 'default,vip,svip,free',
        'closed_groups' => 'default,vip,svip',
        'name'          => '免费渠道 #35',
    ],
    // 如需更多渠道，按以下格式添加：
    // [
    //     'channel_id'    => 36,
    //     'open_groups'   => 'default,vip,free',
    //     'closed_groups' => 'default,vip',
    //     'name'          => '免费渠道 #36',
    // ],
];

// ============ 时间配置 ============
$check_interval = 300; // 检查间隔（秒），5分钟
date_default_timezone_set('Asia/Shanghai');

// ============ 日志保留天数 ============
$log_retention_days = 90;

// ============ 访问密钥（可选，为空则不验证） ============
// 建议设置一个密钥以防止未授权访问仪表盘
$access_key = '';  // 例如：'my-secret-key-123'

// ============ 以下为内部函数，一般无需修改 ============

/**
 * 获取 newapi 数据库连接
 * 用于查询 logs、channels、abilities 表
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
 * 用于读写 tap_state 和 tap_logs 表
 * 以及写 newapi 的 channels 表（开关水龙头）
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
