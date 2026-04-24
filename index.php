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
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        :root {
            --login-bg: #f8fafc;
            --login-text: #1e293b;
            --login-box-bg: #ffffff;
            --login-box-shadow: rgba(0,0,0,0.1);
            --login-input-bg: #f1f5f9;
            --login-input-border: #e2e8f0;
            --login-input-text: #1e293b;
        }
        @media (prefers-color-scheme: dark) {
            :root {
                --login-bg: #0f172a;
                --login-text: #e2e8f0;
                --login-box-bg: #1e293b;
                --login-box-shadow: rgba(0,0,0,0.3);
                --login-input-bg: #0f172a;
                --login-input-border: #334155;
                --login-input-text: #e2e8f0;
            }
        }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: var(--login-bg); color: var(--login-text); display: flex; justify-content: center; align-items: center; min-height: 100vh; }
        .login-box { background: var(--login-box-bg); padding: 2rem; border-radius: 12px; width: 360px; box-shadow: 0 4px 24px var(--login-box-shadow); }
        .login-box h1 { text-align: center; margin-bottom: 1.5rem; font-size: 1.5rem; }
        .login-box input { width: 100%; padding: 0.75rem 1rem; border: 1px solid var(--login-input-border); border-radius: 8px; background: var(--login-input-bg); color: var(--login-input-text); font-size: 1rem; margin-bottom: 1rem; }
        .login-box button { width: 100%; padding: 0.75rem; border: none; border-radius: 8px; background: #3b82f6; color: #fff; font-size: 1rem; cursor: pointer; font-weight: 600; }
        .login-box button:hover { background: #2563eb; }
        .error { color: #ef4444; text-align: center; margin-bottom: 1rem; font-size: 0.875rem; }
    </style>
</head>
<body>
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
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }

        /* ===== Light theme (default) ===== */
        :root {
            --bg-body: #f8fafc;
            --bg-card: #ffffff;
            --border-card: #e2e8f0;
            --shadow-card-hover: 0 4px 20px rgba(0,0,0,0.08);
            --text-primary: #1e293b;
            --text-secondary: #64748b;
            --text-tertiary: #94a3b8;
            --text-label: #64748b;

            --banner-open-bg: linear-gradient(135deg, #ecfdf5, #d1fae5);
            --banner-open-border: #10b981;
            --banner-open-color: #065f46;
            --banner-closed-bg: linear-gradient(135deg, #fef2f2, #fee2e2);
            --banner-closed-border: #ef4444;
            --banner-closed-color: #991b1b;

            --progress-bg: #e2e8f0;
            --section-heading-border: #e2e8f0;

            --log-border: #f1f5f9;
            --log-time-color: #94a3b8;
            --log-msg-color: #334155;

            --badge-tap-open-bg: #d1fae5; --badge-tap-open-color: #065f46;
            --badge-tap-close-bg: #fee2e2; --badge-tap-close-color: #991b1b;
            --badge-install-bg: #dbeafe; --badge-install-color: #1e40af;
            --badge-month-reset-bg: #ede9fe; --badge-month-reset-color: #5b21b6;

            --chart-value-color: #64748b;
            --chart-label-color: #94a3b8;

            --table-header-color: #64748b;
            --table-header-border: #e2e8f0;
            --table-row-border: #f1f5f9;
            --code-green: #059669;
            --code-red: #dc2626;
            --code-blue: #2563eb;

            --footer-color: #94a3b8;
            --footer-border: #e2e8f0;

            --scrollbar-track: #f1f5f9;
            --scrollbar-thumb: #cbd5e1;
            --scrollbar-thumb-hover: #94a3b8;

            --refresh-dot: #3b82f6;
            --refresh-text: #94a3b8;

            --no-logs-color: #94a3b8;
        }

        /* ===== Dark theme ===== */
        @media (prefers-color-scheme: dark) {
            :root {
                --bg-body: #0f172a;
                --bg-card: #1e293b;
                --border-card: #334155;
                --shadow-card-hover: 0 4px 20px rgba(0,0,0,0.3);
                --text-primary: #e2e8f0;
                --text-secondary: #94a3b8;
                --text-tertiary: #64748b;
                --text-label: #94a3b8;

                --banner-open-bg: linear-gradient(135deg, #065f46, #047857);
                --banner-open-border: #10b981;
                --banner-open-color: #6ee7b7;
                --banner-closed-bg: linear-gradient(135deg, #7f1d1d, #991b1b);
                --banner-closed-border: #ef4444;
                --banner-closed-color: #fca5a5;

                --progress-bg: #334155;
                --section-heading-border: #334155;

                --log-border: #1e293b;
                --log-time-color: #64748b;
                --log-msg-color: #cbd5e1;

                --badge-tap-open-bg: #065f46; --badge-tap-open-color: #6ee7b7;
                --badge-tap-close-bg: #7f1d1d; --badge-tap-close-color: #fca5a5;
                --badge-install-bg: #1e3a5f; --badge-install-color: #7dd3fc;
                --badge-month-reset-bg: #4a1d7a; --badge-month-reset-color: #c4b5fd;

                --chart-value-color: #94a3b8;
                --chart-label-color: #64748b;

                --table-header-color: #94a3b8;
                --table-header-border: #334155;
                --table-row-border: #1e293b;
                --code-green: #6ee7b7;
                --code-red: #fca5a5;
                --code-blue: #93c5fd;

                --footer-color: #475569;
                --footer-border: #1e293b;

                --scrollbar-track: #0f172a;
                --scrollbar-thumb: #334155;
                --scrollbar-thumb-hover: #475569;

                --refresh-dot: #3b82f6;
                --refresh-text: #475569;

                --no-logs-color: #64748b;
            }
        }

        /* ===== Base ===== */
        body { 
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', 'PingFang SC', 'Microsoft YaHei', sans-serif; 
            background: var(--bg-body); 
            color: var(--text-primary); 
            min-height: 100vh;
            line-height: 1.6;
        }
        .container { max-width: 1200px; margin: 0 auto; padding: 2rem 1.5rem; }
        
        /* Header */
        .header { text-align: center; margin-bottom: 2.5rem; }
        .header h1 { font-size: 2rem; font-weight: 700; margin-bottom: 0.5rem; }
        .header .subtitle { color: var(--text-secondary); font-size: 0.95rem; }

        /* Tap Status Banner */
        .tap-banner {
            text-align: center;
            padding: 1.25rem;
            border-radius: 12px;
            margin-bottom: 2rem;
            font-weight: 600;
            font-size: 1.1rem;
            transition: all 0.3s;
        }
        .tap-banner.open {
            background: var(--banner-open-bg);
            border: 1px solid var(--banner-open-border);
            color: var(--banner-open-color);
        }
        .tap-banner.closed {
            background: var(--banner-closed-bg);
            border: 1px solid var(--banner-closed-border);
            color: var(--banner-closed-color);
        }

        /* Cards Grid */
        .cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 1.25rem; margin-bottom: 2rem; }
        .card {
            background: var(--bg-card);
            border: 1px solid var(--border-card);
            border-radius: 12px;
            padding: 1.5rem;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .card:hover { transform: translateY(-2px); box-shadow: var(--shadow-card-hover); }
        .card .label { color: var(--text-label); font-size: 0.85rem; margin-bottom: 0.5rem; text-transform: uppercase; letter-spacing: 0.05em; }
        .card .value { font-size: 1.75rem; font-weight: 700; }
        .card .unit { font-size: 0.85rem; font-weight: 400; color: var(--text-secondary); margin-left: 0.25rem; }
        .card .sub { color: var(--text-tertiary); font-size: 0.8rem; margin-top: 0.25rem; }

        /* Card accent colors */
        .card .value.warning { color: #f59e0b; }
        .card .value.info { color: #3b82f6; }
        .card .value.danger { color: #ef4444; }
        .card .value.success { color: #10b981; }

        /* Progress Bar */
        .progress-wrap { margin-top: 0.75rem; }
        .progress-bar { height: 8px; background: var(--progress-bg); border-radius: 4px; overflow: hidden; }
        .progress-fill { height: 100%; border-radius: 4px; transition: width 0.5s ease; }
        .progress-fill.green { background: linear-gradient(90deg, #10b981, #34d399); }
        .progress-fill.yellow { background: linear-gradient(90deg, #f59e0b, #fbbf24); }
        .progress-fill.red { background: linear-gradient(90deg, #ef4444, #f87171); }
        .progress-text { display: flex; justify-content: space-between; font-size: 0.75rem; color: var(--text-tertiary); margin-top: 0.35rem; }

        /* Sections */
        .section { 
            background: var(--bg-card); 
            border: 1px solid var(--border-card); 
            border-radius: 12px; 
            padding: 1.5rem; 
            margin-bottom: 1.5rem; 
        }
        .section h2 { font-size: 1.1rem; font-weight: 600; margin-bottom: 1rem; padding-bottom: 0.75rem; border-bottom: 1px solid var(--section-heading-border); }

        /* Chart */
        .chart-container { display: flex; align-items: flex-end; gap: 0.5rem; height: 180px; padding-top: 1rem; }
        .chart-bar-wrap { flex: 1; display: flex; flex-direction: column; align-items: center; height: 100%; justify-content: flex-end; }
        .chart-bar { 
            width: 100%; 
            max-width: 48px; 
            border-radius: 4px 4px 0 0; 
            background: linear-gradient(180deg, #3b82f6, #1d4ed8);
            transition: height 0.5s ease;
            min-height: 2px;
            position: relative;
        }
        .chart-bar.today { background: linear-gradient(180deg, #10b981, #059669); }
        .chart-bar-value { font-size: 0.65rem; color: var(--chart-value-color); margin-bottom: 4px; text-align: center; }
        .chart-bar-label { font-size: 0.7rem; color: var(--chart-label-color); margin-top: 6px; }

        /* Logs */
        .log-list { max-height: 400px; overflow-y: auto; }
        .log-item { 
            padding: 0.6rem 0; 
            border-bottom: 1px solid var(--log-border); 
            display: flex; 
            gap: 0.75rem; 
            align-items: flex-start;
            font-size: 0.85rem;
        }
        .log-item:last-child { border-bottom: none; }
        .log-time { color: var(--log-time-color); white-space: nowrap; min-width: 140px; }
        .log-action { 
            padding: 0.15rem 0.5rem; 
            border-radius: 4px; 
            font-size: 0.75rem; 
            font-weight: 600;
            white-space: nowrap;
        }
        .log-action.tap_open { background: var(--badge-tap-open-bg); color: var(--badge-tap-open-color); }
        .log-action.tap_close { background: var(--badge-tap-close-bg); color: var(--badge-tap-close-color); }
        .log-action.install { background: var(--badge-install-bg); color: var(--badge-install-color); }
        .log-action.month_reset { background: var(--badge-month-reset-bg); color: var(--badge-month-reset-color); }
        .log-msg { color: var(--log-msg-color); flex: 1; }

        /* Channel Table */
        .channel-table { width: 100%; border-collapse: collapse; font-size: 0.85rem; }
        .channel-table thead tr { border-bottom: 1px solid var(--table-header-border); }
        .channel-table th { padding: 0.5rem; text-align: left; color: var(--table-header-color); }
        .channel-table td { padding: 0.5rem; }
        .channel-table tbody tr { border-bottom: 1px solid var(--table-row-border); }
        .channel-table tbody tr:last-child { border-bottom: none; }
        .code-green { color: var(--code-green); font-size: 0.8rem; }
        .code-red { color: var(--code-red); font-size: 0.8rem; }
        .code-blue { color: var(--code-blue); font-size: 0.8rem; }

        /* Footer */
        .footer { text-align: center; color: var(--footer-color); font-size: 0.8rem; margin-top: 2rem; padding-top: 1rem; border-top: 1px solid var(--footer-border); }

        /* Responsive */
        @media (max-width: 640px) {
            .container { padding: 1rem; }
            .header h1 { font-size: 1.5rem; }
            .card .value { font-size: 1.35rem; }
            .cards { grid-template-columns: 1fr; }
            .log-time { min-width: 100px; font-size: 0.75rem; }
        }

        /* Auto refresh indicator */
        .refresh-bar {
            text-align: center;
            color: var(--refresh-text);
            font-size: 0.75rem;
            margin-bottom: 1.5rem;
        }
        .refresh-bar .dot {
            display: inline-block;
            width: 6px; height: 6px;
            background: var(--refresh-dot);
            border-radius: 50%;
            margin-right: 4px;
            animation: pulse 2s infinite;
        }
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.3; }
        }

        /* Scrollbar */
        ::-webkit-scrollbar { width: 6px; }
        ::-webkit-scrollbar-track { background: var(--scrollbar-track); }
        ::-webkit-scrollbar-thumb { background: var(--scrollbar-thumb); border-radius: 3px; }
        ::-webkit-scrollbar-thumb:hover { background: var(--scrollbar-thumb-hover); }

        /* No logs placeholder */
        .no-logs { color: var(--no-logs-color); text-align: center; padding: 2rem; }
    </style>
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
