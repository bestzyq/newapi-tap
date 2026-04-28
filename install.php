<?php
/**
 * NewAPI-TAP 安装脚本
 * 创建 newapi_tap 数据库及必要的数据表，初始化状态
 */
require_once __DIR__ . '/config.php';

echo "=== {$site_name} 安装程序 ===\n\n";

// Step 1: 创建 newapi_tap 数据库
try {
    $pdo = new PDO(
        "mysql:host=$tap_db_host;charset=utf8mb4",
        $tap_db_user,
        $tap_db_pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `$tap_db_name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    echo "[✓] 数据库 `$tap_db_name` 已创建\n";
} catch (PDOException $e) {
    die("[✗] 创建数据库失败: " . $e->getMessage() . "\n");
}

// 连接到 newapi_tap 数据库
$pdo = new PDO(
    "mysql:host=$tap_db_host;dbname=$tap_db_name;charset=utf8mb4",
    $tap_db_user,
    $tap_db_pass,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

// Step 2: 创建状态表
$pdo->exec("CREATE TABLE IF NOT EXISTS `tap_state` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `config_key` varchar(64) NOT NULL,
    `config_value` text NOT NULL,
    `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `config_key` (`config_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
echo "[✓] tap_state 表已创建\n";

// Step 3: 创建日志表
$pdo->exec("CREATE TABLE IF NOT EXISTS `tap_logs` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `action` varchar(32) NOT NULL DEFAULT '',
    `message` text NOT NULL,
    `detail` text DEFAULT NULL,
    `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_action` (`action`),
    KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
echo "[✓] tap_logs 表已创建\n";

// Step 4: 初始化默认状态
$defaults = [
    'current_month'   => date('Y-m'),
    'tap_open'        => '1',
    'month_used'      => '0',
    'today_used'      => '0',
    'today_allowance' => '0',
    'remaining'       => (string)$monthly_tokens,
    'last_check'      => date('Y-m-d H:i:s'),
];

// 为每个渠道初始化状态
foreach ($tap_channels as $ch) {
    $ch_id = $ch['channel_id'];
    $defaults["tap_open_{$ch_id}"] = '1';
    $defaults["month_used_{$ch_id}"] = '0';
    $defaults["today_used_{$ch_id}"] = '0';
    $defaults["today_allowance_{$ch_id}"] = '0';
    $defaults["today_remaining_{$ch_id}"] = '0';
    $defaults["remaining_{$ch_id}"] = '0';
}

$stmt = $pdo->prepare(
    "INSERT INTO tap_state (config_key, config_value) VALUES (?, ?) 
     ON DUPLICATE KEY UPDATE config_value = IF(config_value = '', VALUES(config_value), config_value)"
);

foreach ($defaults as $key => $value) {
    $stmt->execute([$key, $value]);
}
echo "[✓] 默认状态已初始化\n";

// Step 5: 写入初始日志
$stmt = $pdo->prepare("INSERT INTO tap_logs (action, message, detail) VALUES (?, ?, ?)");
$stmt->execute(['install', '系统安装完成', json_encode([
    'monthly_tokens' => $monthly_tokens,
    'channels' => array_column($tap_channels, 'channel_id'),
])]);
echo "[✓] 安装日志已记录\n";

// Step 6: 验证 newapi 数据库连接及渠道
echo "\n--- 验证 newapi 数据库 ---\n";
try {
    $newapi_pdo = getNewapiDB();

    // 验证 logs 表是否存在
    $stmt = $newapi_pdo->query("SHOW TABLES LIKE 'logs'");
    if ($stmt->rowCount() > 0) {
        echo "[✓] logs 表存在\n";
        $stmt = $newapi_pdo->query("DESCRIBE logs");
        $fields = [];
        while ($row = $stmt->fetch()) {
            $fields[] = $row['Field'];
        }
        echo "    字段: " . implode(', ', $fields) . "\n";
        $required = ['channel_id', 'prompt_tokens', 'completion_tokens', 'created_at'];
        $missing = array_diff($required, $fields);
        if (empty($missing)) {
            echo "    [✓] 必要字段齐全 (channel_id, prompt_tokens, completion_tokens, created_at)\n";
            // 创建复合索引以优化查询性能
            try {
                $newapi_pdo->exec("CREATE INDEX idx_channel_created ON logs (channel_id, created_at)");
                echo "    [✓] 复合索引 idx_channel_created 已创建\n";
            } catch (PDOException $e) {
                if (strpos($e->getMessage(), 'Duplicate key name') !== false) {
                    echo "    [i] 复合索引 idx_channel_created 已存在，跳过\n";
                } else {
                    echo "    [!] 创建复合索引失败: " . $e->getMessage() . " (可手动执行: CREATE INDEX idx_channel_created ON logs (channel_id, created_at))\n";
                }
            }
            // 创建包含 group 的复合索引，优化 free 组统计查询
            try {
                $newapi_pdo->exec("CREATE INDEX idx_channel_group_created ON logs (channel_id, `group`, created_at)");
                echo "    [✓] 复合索引 idx_channel_group_created 已创建\n";
            } catch (PDOException $e) {
                if (strpos($e->getMessage(), 'Duplicate key name') !== false) {
                    echo "    [i] 复合索引 idx_channel_group_created 已存在，跳过\n";
                } else {
                    echo "    [!] 创建复合索引失败: " . $e->getMessage() . " (可手动执行: CREATE INDEX idx_channel_group_created ON logs (channel_id, `group`, created_at))\n";
                }
            }
        } else {
            echo "    [✗] 缺少字段: " . implode(', ', $missing) . "\n";
        }
    } else {
        echo "[✗] logs 表不存在！请确认 newapi 版本\n";
    }

    // 验证渠道是否存在
    echo "\n--- 验证渠道配置 ---\n";
    foreach ($tap_channels as $ch) {
        $stmt = $newapi_pdo->prepare("SELECT id, `group`, models FROM channels WHERE id = ?");
        $stmt->execute([$ch['channel_id']]);
        $row = $stmt->fetch();
        if ($row) {
            $model_count = count(array_filter(explode(',', $row['models'])));
            $mode_labels = ['shared' => '共享月度', 'monthly' => '独立月度', 'daily' => '独立日额', 'unlimited' => '不限量'];
            $count_label = ($ch['count'] ?? 'all') === 'free' ? '，仅统计免费调用' : '';
            $quota_info = $ch['mode'] === 'unlimited' ? '不限量' : ($ch['mode'] === 'daily' ? formatTokens($ch['quota']) . ' tokens/日' : ($ch['mode'] === 'monthly' ? formatTokens($ch['quota']) . ' tokens/月' : '使用总额度均分'));
            $group_info = $ch['mode'] === 'unlimited' ? '' : "，当前分组: {$row['group']}";
            echo "[✓] 渠道 #{$ch['channel_id']} [{$mode_labels[$ch['mode']]}] 存在{$group_info}，模型数: {$model_count}，额度: {$quota_info}{$count_label}\n";
        } else {
            echo "[✗] 渠道 #{$ch['channel_id']} 不存在！请检查配置\n";
        }
    }

    // 验证 abilities 表
    echo "\n--- 验证 abilities 表 ---\n";
    $stmt = $newapi_pdo->query("SHOW TABLES LIKE 'abilities'");
    if ($stmt->rowCount() > 0) {
        echo "[✓] abilities 表存在\n";
        $stmt = $newapi_pdo->query("DESCRIBE abilities");
        $fields = [];
        while ($row = $stmt->fetch()) {
            $fields[] = $row['Field'];
        }
        echo "    字段: " . implode(', ', $fields) . "\n";
        $channel_ids = array_column($tap_channels, 'channel_id');
        $channel_id_list = implode(',', array_map('intval', $channel_ids));
        $stmt = $newapi_pdo->query("SELECT COUNT(*) as cnt FROM abilities WHERE `group` = 'free' AND channel_id IN ($channel_id_list)");
        $free_count = $stmt->fetch()['cnt'];
        echo "    当前 free 组 abilities 记录: {$free_count} 条\n";
    } else {
        echo "[✗] abilities 表不存在！请确认 newapi 版本\n";
    }
} catch (PDOException $e) {
    echo "[✗] 连接 newapi 数据库失败: " . $e->getMessage() . "\n";
}

// Step 7: 检查数据库用户权限
echo "\n--- 权限检查 ---\n";
echo "[i] newapi_tap 库: $tap_db_user@{$tap_db_host} (需要 CREATE/INSERT/UPDATE/SELECT/DELETE)\n";
echo "[i] newapi 库: $newapi_db_user@{$newapi_db_host} (需要 SELECT logs, SELECT/UPDATE channels, SELECT/INSERT/DELETE abilities)\n";

echo "\n=== 安装完成 ===\n";
echo "请设置定时任务，每5分钟执行一次 cron.php：\n\n";
echo "  Linux crontab:\n";
echo "    */5 * * * * php " . __DIR__ . "/cron.php >> " . __DIR__ . "/cron.log 2>&1\n\n";
echo "  Windows 任务计划程序:\n";
echo "    操作: php.exe " . __DIR__ . "\\cron.php\n";
echo "    触发器: 每5分钟重复\n";
