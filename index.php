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

// ============ 总体统计（从 cron 缓存读取，避免查询 logs 大表） ============
$shared_channel_ids = [];
$all_channel_ids = [];
foreach ($tap_channels as $ch) {
    $all_channel_ids[] = $ch['channel_id'];
    if ($ch['mode'] === 'shared') {
        $shared_channel_ids[] = $ch['channel_id'];
    }
}
$shared_id_list = implode(',', array_map('intval', $shared_channel_ids));
$all_id_list = implode(',', array_map('intval', $all_channel_ids));

$month_start_ts = strtotime(date('Y-m-01 00:00:00'));
$today_start_ts = strtotime(date('Y-m-d 00:00:00'));
$days_in_month = (int)date('t');
$day_of_month = (int)date('j');
$days_remaining = $days_in_month - $day_of_month + 1;

// 批量读取 tap_state 缓存（由 cron.php 定期更新）
$state_keys = ['tap_open', 'last_check', 'month_used', 'today_used', 'today_allowance', 'today_remaining', 'remaining'];
foreach ($tap_channels as $ch) {
    $ch_id = $ch['channel_id'];
    $state_keys[] = "month_used_{$ch_id}";
    $state_keys[] = "today_used_{$ch_id}";
    $state_keys[] = "today_allowance_{$ch_id}";
    $state_keys[] = "today_remaining_{$ch_id}";
    $state_keys[] = "remaining_{$ch_id}";
}
$state_ph = implode(',', array_fill(0, count($state_keys), '?'));
$stmt = $tap_pdo->prepare("SELECT config_key, config_value FROM tap_state WHERE config_key IN ($state_ph)");
$stmt->execute($state_keys);
$state_cache = [];
while ($row = $stmt->fetch()) {
    $state_cache[$row['config_key']] = $row['config_value'];
}

// 全局月度统计（从缓存读取）
$month_used = (int)($state_cache['month_used'] ?? 0);
$today_used = (int)($state_cache['today_used'] ?? 0);
$today_allowance = (int)($state_cache['today_allowance'] ?? 0);
$today_remaining = (int)($state_cache['today_remaining'] ?? 0);
$remaining = (int)($state_cache['remaining'] ?? 0);
$tap_open = ($state_cache['tap_open'] ?? '1') === '1';
$last_check = $state_cache['last_check'] ?? '从未';

$month_usage_pct = $monthly_tokens > 0 ? min(100, round($month_used / $monthly_tokens * 100, 1)) : 0;
$today_usage_pct = $today_allowance > 0 ? min(100, round($today_used / $today_allowance * 100, 1)) : 0;

// ============ 分渠道统计（从缓存读取） ============
// 批量获取渠道模型
$stmt = $newapi_pdo->prepare("SELECT id, models FROM channels WHERE id IN ($all_id_list)");
$stmt->execute();
$channel_models = [];
while ($row = $stmt->fetch()) {
    $channel_models[(int)$row['id']] = $row['models'] ?: '未知';
}

$channel_stats = [];
foreach ($tap_channels as $ch) {
    $ch_id = $ch['channel_id'];
    $ch_mode = $ch['mode'];
    $ch_quota = $ch['quota'];
    $models = $channel_models[$ch_id] ?? '未知';

    $ch_month_used = (int)($state_cache["month_used_{$ch_id}"] ?? 0);
    $ch_today_used = (int)($state_cache["today_used_{$ch_id}"] ?? 0);
    $ch_today_allowance = (int)($state_cache["today_allowance_{$ch_id}"] ?? 0);
    $ch_today_remaining = (int)($state_cache["today_remaining_{$ch_id}"] ?? 0);
    $ch_remaining = (int)($state_cache["remaining_{$ch_id}"] ?? 0);

    switch ($ch_mode) {
        case 'unlimited':
            $ch_monthly = 0;
            $ch_month_pct = 0;
            $ch_today_pct = 0;
            break;

        case 'daily':
            $ch_monthly = 0;
            $ch_month_pct = 0;
            $ch_today_pct = $ch_today_allowance > 0 ? min(100, round($ch_today_used / $ch_today_allowance * 100, 1)) : 0;
            break;

        case 'monthly':
            $ch_monthly = $ch_quota;
            $ch_month_pct = $ch_monthly > 0 ? min(100, round($ch_month_used / $ch_monthly * 100, 1)) : 0;
            $ch_today_pct = $ch_today_allowance > 0 ? min(100, round($ch_today_used / $ch_today_allowance * 100, 1)) : 0;
            break;

        case 'shared':
        default:
            $ch_monthly = $monthly_tokens;
            $ch_month_pct = $month_usage_pct;
            $ch_today_pct = $today_usage_pct;
            break;
    }

    $channel_stats[] = [
        'channel_id'      => $ch_id,
        'mode'            => $ch_mode,
        'count'           => $ch['count'],
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

// 每日用量趋势（最近7天，所有渠道）— 单次 GROUP BY 查询
$seven_days_ago_ts = strtotime(date('Y-m-d 00:00:00', strtotime('-6 days')));
$today_end_ts = strtotime(date('Y-m-d 23:59:59'));
$stmt = $newapi_pdo->prepare("SELECT FROM_UNIXTIME(created_at, '%Y-%m-%d') AS day, COALESCE(SUM(prompt_tokens + completion_tokens), 0) AS total FROM logs WHERE channel_id IN ($all_id_list) AND created_at >= ? AND created_at <= ? GROUP BY day");
$stmt->execute([$seven_days_ago_ts, $today_end_ts]);
$daily_map = [];
while ($row = $stmt->fetch()) {
    $daily_map[$row['day']] = (int)$row['total'];
}
$daily_stats = [];
for ($i = 6; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-$i days"));
    $daily_stats[] = ['date' => $date, 'total' => $daily_map[$date] ?? 0];
}

$mode_labels = ['shared' => '共享月度', 'monthly' => '独立月度', 'daily' => '独立日额', 'unlimited' => '不限量'];
$count_labels = ['all' => '全部调用', 'free' => '仅免费调用'];

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
    <link rel="stylesheet" href="style.css?v=2">
</head>
<body>
    <div class="container">
        <!-- Announcement Banner -->
        <?php if (!empty($announcement)): ?>
        <div class="announcement-banner">
            <span class="announcement-icon">&#128227;</span>
            <span class="announcement-text"><?= htmlspecialchars($announcement) ?></span>
        </div>
        <?php endif; ?>

        <!-- Header -->
        <div class="header">
            <div class="header-row">
                <div>
                    <h1><?= htmlspecialchars($site_name) ?></h1>
                    <div class="subtitle">免费额度水龙头控制系统</div>
                </div>
                <div class="header-actions">
                    <a href="/" class="btn-back" target="_blank">返回主页</a>
                    <?php if (!empty($api_site_url)): ?>
                    <a href="<?= htmlspecialchars($api_site_url) ?>" class="btn-back" target="_blank">返回API站</a>
                    <?php endif; ?>
                </div>
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

        <?php if ($shared_id_list !== ''): ?>
        <!-- Monthly Stats (shared channels) -->
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
        <?php endif; ?>

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
                    <span class="channel-mode"><?= $mode_labels[$cs['mode']] ?? $cs['mode'] ?></span>
                    <?php if (($cs['count'] ?? 'all') === 'free'): ?>
                    <span class="channel-count-free">仅免费</span>
                    <?php endif; ?>
                    <span class="channel-models"><code class="code-blue"><?= htmlspecialchars($cs['models']) ?></code></span>
                </div>
                <?php if ($cs['mode'] === 'unlimited'): ?>
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
                <div class="channel-no-quota">不限量渠道</div>
                <?php elseif ($cs['mode'] === 'daily'): ?>
                <div class="channel-card-stats">
                    <div class="channel-stat">
                        <span class="channel-stat-label">日额度</span>
                        <span class="channel-stat-value"><?= formatTokens($cs['today_allowance']) ?></span>
                    </div>
                    <div class="channel-stat">
                        <span class="channel-stat-label">今日已用</span>
                        <span class="channel-stat-value <?= $cs['today_pct'] >= 100 ? 'danger' : 'success' ?>"><?= formatTokens($cs['today_used']) ?></span>
                    </div>
                    <div class="channel-stat">
                        <span class="channel-stat-label">今日剩余</span>
                        <span class="channel-stat-value info"><?= formatTokens($cs['today_remaining']) ?></span>
                    </div>
                </div>
                <div class="progress-wrap">
                    <div class="progress-bar">
                        <div class="progress-fill <?= $cs['today_pct'] > 80 ? 'red' : ($cs['today_pct'] > 50 ? 'yellow' : 'green') ?>" style="width: <?= $cs['today_pct'] ?>%"></div>
                    </div>
                    <div class="progress-text">
                        <span>今日已用 <?= $cs['today_pct'] ?>%</span>
                        <span>剩余 <?= formatTokens($cs['today_remaining']) ?></span>
                    </div>
                </div>
                <?php elseif ($cs['mode'] === 'monthly'): ?>
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
