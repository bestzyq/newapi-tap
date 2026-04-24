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

// ============ 总体统计 ============
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

$month_usage_pct = $monthly_tokens > 0 ? min(100, round($month_used / $monthly_tokens * 100, 1)) : 0;
$today_usage_pct = $today_allowance > 0 ? min(100, round($today_used / $today_allowance * 100, 1)) : 0;

// ============ 分渠道统计 ============
$channel_stats = [];
foreach ($tap_channels as $ch) {
    $ch_id = $ch['channel_id'];
    $ch_monthly = $ch['monthly_tokens'] > 0 ? $ch['monthly_tokens'] : 0;

    $stmt = $newapi_pdo->prepare("SELECT models FROM channels WHERE id = ?");
    $stmt->execute([$ch_id]);
    $models = $stmt->fetchColumn() ?: '未知';

    $stmt = $newapi_pdo->prepare("SELECT COALESCE(SUM(prompt_tokens + completion_tokens), 0) as total FROM logs WHERE channel_id = ? AND created_at >= ?");
    $stmt->execute([$ch_id, $month_start_ts]);
    $ch_month_used = (int)$stmt->fetch()['total'];

    $stmt = $newapi_pdo->prepare("SELECT COALESCE(SUM(prompt_tokens + completion_tokens), 0) as total FROM logs WHERE channel_id = ? AND created_at >= ?");
    $stmt->execute([$ch_id, $today_start_ts]);
    $ch_today_used = (int)$stmt->fetch()['total'];

    if ($ch_monthly > 0) {
        $ch_remaining = max(0, $ch_monthly - $ch_month_used);
        $ch_today_allowance = $days_remaining > 0 ? intdiv($ch_remaining, $days_remaining) : 0;
    } else {
        $ch_monthly = 0;
        $ch_remaining = 0;
        $ch_today_allowance = 0;
    }
    $ch_today_remaining = max(0, $ch_today_allowance - $ch_today_used);

    $ch_month_pct = $ch_monthly > 0 ? min(100, round($ch_month_used / $ch_monthly * 100, 1)) : 0;
    $ch_today_pct = $ch_today_allowance > 0 ? min(100, round($ch_today_used / $ch_today_allowance * 100, 1)) : 0;

    $channel_stats[] = [
        'channel_id'      => $ch_id,
        'models'          => $models,
        'monthly_tokens'  => $ch_monthly,
        'month_used'      => $ch_month_used,
        'remaining'       => $ch_remaining,
        'month_pct'       => $ch_month_pct,
        'today_allowance' => $ch_today_allowance,
        'today_used'      => $ch_today_used,
        'today_remaining' => $ch_today_remaining,
        'today_pct'       => $ch_today_pct,
    ];
}

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
    <title><?= htmlspecialchars($GLOBALS['site_name']) ?> - 登录</title>
    <link rel="stylesheet" href="style.css">
</head>
<body class="login-body">
    <div class="login-box">
        <h1><?= htmlspecialchars($GLOBALS['site_name']) ?></h1>
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
    <title><?= htmlspecialchars($site_name) ?> - 免费额度水龙头控制</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <div class="container">
        <!-- Header -->
        <div class="header">
            <div class="header-row">
                <div>
                    <h1><?= htmlspecialchars($site_name) ?></h1>
                    <div class="subtitle">免费额度水龙头控制系统</div>
                </div>
                <a href="/>" class="btn-back" target="_blank">返回主页</a>
                <?php if (!empty($api_site_url)): ?>
                <a href="<?= htmlspecialchars($api_site_url) ?>" class="btn-back" target="_blank">返回API站</a>
                <?php endif; ?>
            </div>
        </div>

        <!-- Tap Status -->
        <div class="tap-banner <?= $tap_open ? 'open' : 'closed' ?>">
            <?= $tap_open ? '水龙头开启中 — 免费额度可用' : '水龙头已关闭 — 今日额度已耗尽' ?>
        </div>

        <!-- Auto Refresh -->
        <div class="refresh-bar">
            <span class="dot"></span>页面每 30 秒自动刷新 &nbsp;|&nbsp; 上次检查: <?= htmlspecialchars($last_check) ?>
        </div>

        <!-- Monthly Stats (Overall) -->
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

        <!-- Channel Status with per-channel quota -->
        <div class="section">
            <h2>渠道状态</h2>
            <?php foreach ($channel_stats as $cs): ?>
            <div class="channel-card">
                <div class="channel-card-header">
                    <span class="channel-id">#<?= $cs['channel_id'] ?></span>
                    <span class="channel-models"><code class="code-blue"><?= htmlspecialchars($cs['models']) ?></code></span>
                </div>
                <?php if ($cs['monthly_tokens'] > 0): ?>
                <div class="channel-card-stats">
                    <div class="channel-stat">
                        <span class="channel-stat-label">月度额度</span>
                        <span class="channel-stat-value"><?= formatTokens($cs['monthly_tokens']) ?></span>
                    </div>
                    <div class="channel-stat">
                        <span class="channel-stat-label">本月已用</span>
                        <span class="channel-stat-value warning"><?= formatTokens($cs['month_used']) ?></span>
                    </div>
                    <div class="channel-stat">
                        <span class="channel-stat-label">今日额度</span>
                        <span class="channel-stat-value info"><?= formatTokens($cs['today_allowance']) ?></span>
                    </div>
                    <div class="channel-stat">
                        <span class="channel-stat-label">今日已用</span>
                        <span class="channel-stat-value <?= $cs['today_pct'] >= 100 ? 'danger' : 'success' ?>"><?= formatTokens($cs['today_used']) ?></span>
                    </div>
                </div>
                <div class="progress-wrap">
                    <div class="progress-bar">
                        <div class="progress-fill <?= $cs['month_pct'] > 80 ? 'red' : ($cs['month_pct'] > 50 ? 'yellow' : 'green') ?>" style="width: <?= $cs['month_pct'] ?>%"></div>
                    </div>
                    <div class="progress-text">
                        <span>月度已用 <?= $cs['month_pct'] ?>%</span>
                        <span>剩余 <?= formatTokens($cs['remaining']) ?></span>
                    </div>
                </div>
                <?php else: ?>
                <div class="channel-card-stats">
                    <div class="channel-stat">
                        <span class="channel-stat-label">本月已用</span>
                        <span class="channel-stat-value warning"><?= formatTokens($cs['month_used']) ?></span>
                    </div>
                    <div class="channel-stat">
                        <span class="channel-stat-label">今日已用</span>
                        <span class="channel-stat-value info"><?= formatTokens($cs['today_used']) ?></span>
                    </div>
                </div>
                <div class="channel-no-quota">使用总额度均分</div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
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
            <?= htmlspecialchars($site_name) ?> v1.0 · 检查间隔 <?= $check_interval ?>秒 · <?= date('Y-m-d H:i:s') ?>
        </div>
    </div>

    <script>
        setTimeout(function() { location.reload(); }, 30000);
    </script>
</body>
</html>
