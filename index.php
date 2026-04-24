<?php
/**
 * NewAPI-TAP 仪表盘
 */
require_once __DIR__ . '/config.php';

// 访问密钥验证
if (!empty($access_key)) {
    session_start();
    if (isset($_POST['key'])) {
        if ($_POST['key'] === $access_key) {
            $_SESSION['tap_auth'] = true;
        } else {
            $_SESSION['tap_auth'] = false;
        }
    }
    if (empty($_SESSION['tap_auth'])) {
        showLoginPage();
        exit;
    }
}

$newapi_pdo = getNewapiDB();
$tap_pdo = getTapDB();

// 获取状态
function getState($tap_pdo, $key, $default = '') {
    $stmt = $tap_pdo->prepare("SELECT config_value FROM tap_state WHERE config_key = ?");
    $stmt->execute([$key]);
    $result = $stmt->fetch();
    return $result ? $result['config_value'] : $default;
}

// 实时计算（从 logs 查询配置渠道的用量，确保数据准确）
$channel_ids = array_column($tap_channels, 'channel_id');
$channel_id_list = implode(',', array_map('intval', $channel_ids));

$month_start_ts = strtotime(date('Y-m-01 00:00:00'));
$stmt = $newapi_pdo->prepare("SELECT COALESCE(SUM(prompt_tokens + completion_tokens), 0) as total FROM logs WHERE channel_id IN ($channel_id_list) AND created_at >= ?");
$stmt->execute([$month_start_ts]);
$month_used = (int)$stmt->fetch()['total'];

$today_start_ts = strtotime(date('Y-m-d 00:00:00'));
$stmt = $newapi_pdo->prepare("SELECT COALESCE(SUM(prompt_tokens + completion_tokens), 0) as total FROM logs WHERE channel_id IN ($channel_id_list) AND created_at >= ?");
$stmt->execute([$today_start_ts]);
$today_used = (int)$stmt->fetch()['total'];

$remaining = max(0, $monthly_tokens - $month_used);
$days_in_month = (int)date('t');
$day_of_month = (int)date('j');
$days_remaining = $days_in_month - $day_of_month + 1;
$today_allowance = $days_remaining > 0 ? intdiv($remaining, $days_remaining) : 0;
$today_remaining = max(0, $today_allowance - $today_used);
$tap_open = getState($tap_pdo, 'tap_open', '1') === '1';
$last_check = getState($tap_pdo, 'last_check', '从未');

// 月度使用百分比
$month_usage_pct = $monthly_tokens > 0 ? min(100, round($month_used / $monthly_tokens * 100, 1)) : 0;
$today_usage_pct = $today_allowance > 0 ? min(100, round($today_used / $today_allowance * 100, 1)) : 0;

// 获取最近日志
$stmt = $tap_pdo->prepare("SELECT * FROM tap_logs ORDER BY created_at DESC LIMIT 20");
$stmt->execute();
$recent_logs = $stmt->fetchAll();

// 每日用量趋势（最近7天）
$daily_stats = [];
for ($i = 6; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-$i days"));
    $day_start = strtotime("$date 00:00:00");
    $day_end = strtotime("$date 23:59:59");
    $stmt = $newapi_pdo->prepare("SELECT COALESCE(SUM(prompt_tokens + completion_tokens), 0) as total FROM logs WHERE channel_id IN ($channel_id_list) AND created_at >= ? AND created_at <= ?");
    $stmt->execute([$day_start, $day_end]);
    $day_total = (int)$stmt->fetch()['total'];
    $daily_stats[] = ['date' => $date, 'total' => $day_total];
}

function showLoginPage() {
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>NewAPI-TAP 登录</title>
    <link rel="stylesheet" href="style.css">
</head>
<body class="login-body">
    <div class="login-box">
        <h1>NewAPI-TAP</h1>
        <?php if (isset($_POST['key']) && $_POST['key'] !== $GLOBALS['access_key']): ?>
            <div class="error">密钥错误，请重试</div>
        <?php endif; ?>
        <form method="POST">
            <input type="password" name="key" placeholder="请输入访问密钥" autofocus>
            <button type="submit">登 录</button>
        </form>
    </div>
</body>
</html>
<?php
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>NewAPI-TAP - 免费额度水龙头控制</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <div class="container">
        <!-- Header -->
        <div class="header">
            <h1>NewAPI-TAP</h1>
            <div class="subtitle">免费额度水龙头控制系统</div>
        </div>

        <!-- Tap Status -->
        <div class="tap-banner <?= $tap_open ? 'open' : 'closed' ?>">
            <?= $tap_open ? '水龙头开启中 — 免费额度可用' : '水龙头已关闭 — 今日额度已耗尽' ?>
        </div>

        <!-- Auto Refresh -->
        <div class="refresh-bar">
            <span class="dot"></span>页面每 30 秒自动刷新 &nbsp;|&nbsp; 上次检查: <?= htmlspecialchars($last_check) ?>
        </div>

        <!-- Monthly Stats -->
        <div class="cards">
            <div class="card">
                <div class="label">月度总额度</div>
                <div class="value"><?= formatTokens($monthly_tokens) ?><span class="unit">tokens</span></div>
                <div class="sub"><?= date('Y') ?>年<?= date('n') ?>月 · 共<?= $days_in_month ?>天</div>
            </div>
            <div class="card">
                <div class="label">本月已消耗</div>
                <div class="value warning"><?= formatTokens($month_used) ?><span class="unit">tokens</span></div>
                <div class="sub">已过 <?= $day_of_month ?> 天 · 日均 <?= formatTokens($day_of_month > 0 ? intdiv($month_used, $day_of_month) : 0) ?></div>
                <div class="progress-wrap">
                    <div class="progress-bar">
                        <div class="progress-fill <?= $month_usage_pct > 80 ? 'red' : ($month_usage_pct > 50 ? 'yellow' : 'green') ?>" style="width: <?= $month_usage_pct ?>%"></div>
                    </div>
                    <div class="progress-text">
                        <span>已用 <?= $month_usage_pct ?>%</span>
                        <span>剩余 <?= formatTokens($remaining) ?></span>
                    </div>
                </div>
            </div>
            <div class="card">
                <div class="label">今日额度</div>
                <div class="value info"><?= formatTokens($today_allowance) ?><span class="unit">tokens</span></div>
                <div class="sub">剩余 <?= $days_remaining ?> 天均分</div>
            </div>
            <div class="card">
                <div class="label">今日已用</div>
                <div class="value <?= $today_usage_pct >= 100 ? 'danger' : 'success' ?>">
                    <?= formatTokens($today_used) ?><span class="unit">tokens</span>
                </div>
                <div class="sub">剩余 <?= formatTokens($today_remaining) ?></div>
                <div class="progress-wrap">
                    <div class="progress-bar">
                        <div class="progress-fill <?= $today_usage_pct > 80 ? 'red' : ($today_usage_pct > 50 ? 'yellow' : 'green') ?>" style="width: <?= min(100, $today_usage_pct) ?>%"></div>
                    </div>
                    <div class="progress-text">
                        <span>已用 <?= $today_usage_pct ?>%</span>
                        <span>剩余 <?= formatTokens($today_remaining) ?></span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Daily Chart -->
        <div class="section">
            <h2>近7日消耗趋势</h2>
            <div class="chart-container">
                <?php 
                $max_daily = max(1, max(array_column($daily_stats, 'total')));
                foreach ($daily_stats as $i => $stat): 
                    $is_today = $stat['date'] === date('Y-m-d');
                    $pct = $stat['total'] > 0 ? max(3, ($stat['total'] / $max_daily) * 100) : 3;
                ?>
                <div class="chart-bar-wrap">
                    <div class="chart-bar-value"><?= formatTokens($stat['total']) ?></div>
                    <div class="chart-bar <?= $is_today ? 'today' : '' ?>" style="height: <?= $pct ?>%"></div>
                    <div class="chart-bar-label"><?= $is_today ? '今日' : date('m/d', strtotime($stat['date'])) ?></div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Channel Status -->
        <div class="section">
            <h2>渠道状态</h2>
            <div style="overflow-x: auto;">
                <table class="channel-table">
                    <thead>
                        <tr>
                            <th>渠道</th>
                            <th>开启分组</th>
                            <th>关闭分组</th>
                            <th>当前分组</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($tap_channels as $ch): 
                            $stmt = $newapi_pdo->prepare("SELECT `group` FROM channels WHERE id = ?");
                            $stmt->execute([$ch['channel_id']]);
                            $current_group = $stmt->fetchColumn() ?: '未知';
                        ?>
                        <tr>
                            <td><?= htmlspecialchars($ch['name']) ?> (#<?= $ch['channel_id'] ?>)</td>
                            <td><code class="code-green"><?= htmlspecialchars($ch['open_groups']) ?></code></td>
                            <td><code class="code-red"><?= htmlspecialchars($ch['closed_groups']) ?></code></td>
                            <td><code class="code-blue"><?= htmlspecialchars($current_group) ?></code></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Logs -->
        <div class="section">
            <h2>操作日志</h2>
            <div class="log-list">
                <?php if (empty($recent_logs)): ?>
                <div class="no-logs">暂无日志</div>
                <?php else: ?>
                <?php foreach ($recent_logs as $log): ?>
                <div class="log-item">
                    <span class="log-time"><?= htmlspecialchars($log['created_at']) ?></span>
                    <span class="log-action <?= htmlspecialchars($log['action']) ?>"><?= htmlspecialchars($log['action']) ?></span>
                    <span class="log-msg"><?= htmlspecialchars($log['message']) ?></span>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Footer -->
        <div class="footer">
            NewAPI-TAP v1.0 · 检查间隔 <?= $check_interval ?>秒 · <?= date('Y-m-d H:i:s') ?>
        </div>
    </div>

    <script>
        // 自动刷新
        setTimeout(function() { location.reload(); }, 30000);
    </script>
</body>
</html>
