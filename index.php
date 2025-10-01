<?php
define('AGGREGATE_WORKER_NAME', '_AGGREGATE_');
$dataDir = __DIR__ . '/data';
$dbPath = $dataDir . '/history.db';
$statsJsonPath = $dataDir . '/stats.json';

if (isset($_GET['fetch_chart_data'])) {
    header('Content-Type: application/json');
    $btc_address = isset($_GET['btc_address']) ? trim(htmlspecialchars($_GET['btc_address'])) : null;
    $worker_name = isset($_GET['worker']) ? trim(htmlspecialchars($_GET['worker'])) : null;
    $range_days = isset($_GET['range']) ? (int)$_GET['range'] : 1;
    $since = time() - ($range_days * 86400);
    $datasets = [];
    $interval = 300; if ($range_days > 20) $interval = 21600; elseif ($range_days > 3) $interval = 3600; elseif ($range_days > 1) $interval = 900;
    
    try {
        if (!file_exists($dbPath)) { throw new Exception("Database file not found."); }
        $pdo = new PDO('sqlite:' . $dbPath);
        $table = $btc_address ? 'hashrate_history' : 'pool_history';
        $params = [':interval' => $interval, ':since' => $since];
        $where_clause = "WHERE timestamp > :since ";

        if ($btc_address) {
            $where_clause .= "AND btc_address = :btc_address AND worker_name = :worker_name ";
            $params[':btc_address'] = $btc_address;
            $params[':worker_name'] = $worker_name ?: AGGREGATE_WORKER_NAME;
        }

        $query_base = "SELECT (timestamp / :interval) * :interval AS time_bucket, %s FROM {$table} {$where_clause} GROUP BY time_bucket ORDER BY time_bucket ASC";
        
        $series_map = [ 1 => ['5m' => 'hashrate_5m_ghs', '1h' => 'hashrate_1h_ghs'], 7 => ['1h' => 'hashrate_1h_ghs', '1d' => 'hashrate_24h_ghs'], 30 => ['1d' => 'hashrate_24h_ghs'], ];
        $series_to_fetch = $series_map[$range_days] ?? $series_map[30];
        $sql_selects = [];
        foreach ($series_to_fetch as $key => $column) { $sql_selects[] = "AVG({$column}) AS avg_{$key}"; }
        $stmt = $pdo->prepare(sprintf($query_base, implode(', ', $sql_selects)));
        $stmt->execute($params);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($series_to_fetch as $key => $column) { $datasets[$key] = [ 'labels' => array_column($results, 'time_bucket'), 'data' => array_column($results, "avg_{$key}"), ]; }
    } catch (Exception $e) { $datasets = ['error' => $e->getMessage()]; }
    echo json_encode($datasets);
    exit();
}
$allData = null; $error = null;
if (file_exists($statsJsonPath)) { $allData = json_decode(file_get_contents($statsJsonPath), true); } else { $error = "Statistics file not found. Please run the parser script."; }
function format_seconds($seconds) { if ($seconds < 1) return '0s'; $parts = []; $days = floor($seconds / 86400); if ($days > 0) $parts[] = $days . 'd'; $hours = floor(($seconds % 86400) / 3600); if ($hours > 0) $parts[] = $hours . 'h'; $minutes = floor(($seconds % 3600) / 60); if ($minutes > 0) $parts[] = $minutes . 'm'; $secs = $seconds % 60; if ($secs > 0 || empty($parts)) $parts[] = $secs . 's'; return implode(' ', $parts); }
function format_number_auto($number, $decimals = 2) { if ($number == floor($number)) { return number_format($number, 0); } return number_format($number, $decimals); }
function format_hashrate($hashrateStr) { $value = (float)$hashrateStr; preg_match('/[a-zA-Z]/', $hashrateStr, $matches); $unit = $matches[0] ?? 'G'; $ghs = 0; switch (strtoupper($unit)) { case 'K': $ghs = $value / 1000000; break; case 'M': $ghs = $value / 1000; break; case 'G': $ghs = $value; break; case 'T': $ghs = $value * 1000; break; case 'P': $ghs = $value * 1000 * 1000; break; case 'E': $ghs = $value * 1000 * 1000 * 1000; break; default: $ghs = $value; } if ($ghs < 1000) { return format_number_auto($ghs) . ' GH/s'; } elseif ($ghs < 1000000) { return format_number_auto($ghs / 1000) . ' TH/s'; } elseif ($ghs < 1000000000) { return format_number_auto($ghs / 1000000) . ' PH/s'; } else { return format_number_auto($ghs / 1000000000) . ' EH/s'; } }
function parse_hashrate_to_ghs(string $hashrateStr): float { $value = (float)$hashrateStr; $unit = strtoupper(substr($hashrateStr, -1)); switch ($unit) { case 'K': return $value / 1000000; case 'M': return $value / 1000; case 'G': return $value; case 'T': return $value * 1000; case 'P': return $value * 1000 * 1000; default: return $value; } }
function calculate_block_probability($user_hashrate_ghs, $network_hashrate_ghs, $days) { if ($user_hashrate_ghs <= 0 || $network_hashrate_ghs <= 0) { return 0; } $blocks_in_period = $days * 144; $p_user = $user_hashrate_ghs / $network_hashrate_ghs; $p_not_finding = pow(1 - $p_user, $blocks_in_period); return (1 - $p_not_finding) * 100; }
function calculate_time_to_find_block($user_hashrate_ghs, $network_hashrate_ghs) { if ($user_hashrate_ghs <= 0 || $network_hashrate_ghs <= 0) { return 0; } return 600 / ($user_hashrate_ghs / $network_hashrate_ghs); }
function format_long_time($seconds) { if ($seconds <= 0) return "never"; $minutes = $seconds / 60; $hours = $minutes / 60; $days = $hours / 24; $months = $days / 30.44; $years = $days / 365.25; if ($years > 1) return format_number_auto($years) . " years"; if ($months > 1) return format_number_auto($months) . " months"; if ($days > 1) return format_number_auto($days) . " days"; return format_number_auto($hours) . " hours"; }
$friendly_names = [ 'hashrate1m' => 'Hashrate (1 min)', 'hashrate5m' => 'Hashrate (5 min)', 'hashrate1hr' => 'Hashrate (1 hr)', 'hashrate1d' => 'Hashrate (24 hr)', 'hashrate7d' => 'Hashrate (7 days)', 'shares' => 'Shares', 'workers' => 'Workers', 'lastshare' => 'Last Share Seen', 'bestshare' => 'Best Share', 'runtime' => 'Pool Uptime', 'Users' => 'Users', 'Workers' => 'Total Workers', 'accepted' => 'Accepted Shares', 'rejected' => 'Rejected Shares', 'SPS1m' => 'Shares/sec (1 min)', 'SPS5m' => 'Shares/sec (5 min)', 'SPS15m' => 'Shares/sec (15 min)', 'SPS1h' => 'Shares/sec (1 hr)', 'rejected_percent' => 'Rejected Rate' ];
$btc_address = isset($_GET['btc_address']) ? trim(htmlspecialchars($_GET['btc_address'])) : null;
$script_path = '.';
$pool_data = $allData['pool'] ?? [];
$user_data_full = ($btc_address && isset($allData['users'][$btc_address])) ? $allData['users'][$btc_address] : null;
$user_summary = $user_data_full['summary'] ?? null;
$user_workers = $user_data_full['workers'] ?? null;
$last_update = $allData['last_update'] ?? null;
$network_difficulty = $allData['network_difficulty'] ?? null;
$previous_network_difficulty = $allData['previous_network_difficulty'] ?? null;
$network_hashrate = $allData['network_hashrate'] ?? null;
$difficulty_prediction = null;
if (file_exists($dbPath)) { try { $pdo_pred = new PDO('sqlite:' . $dbPath); $result = $pdo_pred->query("SELECT * FROM prediction_data LIMIT 1"); if ($result) { $difficulty_prediction = $result->fetch(PDO::FETCH_ASSOC); } } catch (Exception $e) {} }
if (empty($network_hashrate) && !empty($network_difficulty)) { $network_hashrate = $network_difficulty * pow(2, 32) / 600 / 1e9; }
$analytics = null;
if ($user_summary && $network_hashrate) {
    $user_hashrate_str = $user_summary['hashrate1hr'] ?? '0';
    $user_hashrate_ghs = parse_hashrate_to_ghs($user_hashrate_str);
    $analytics = [
        'prob_month' => calculate_block_probability($user_hashrate_ghs, $network_hashrate, 30.44),
        'prob_year' => calculate_block_probability($user_hashrate_ghs, $network_hashrate, 365.25),
        'time_to_find' => calculate_time_to_find_block($user_hashrate_ghs, $network_hashrate)
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CKPool Stats<?php if ($btc_address): ?> - <?= substr($btc_address, 0, 12) ?>...<?php endif; ?></title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-adapter-date-fns@3.0.0/dist/chartjs-adapter-date-fns.bundle.min.js"></script>
    <script> (function() { const getTheme = () => localStorage.getItem('theme') || (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light'); document.documentElement.setAttribute('data-theme', getTheme()); })(); </script>
    <style> :root { --font-sans: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; --font-mono: 'SF Mono', 'Fira Code', 'Fira Mono', 'Roboto Mono', monospace; --background: #ffffff; --foreground: #020817; --card-background: #ffffff; --card-foreground: #020817; --muted-foreground: #64748b; --border: #e2e8f0; --input: #f8fafc; --accent: #1e293b; --accent-hover: #0f172a; --accent-foreground: #f8fafc; } [data-theme='dark'] { --background: #020817; --foreground: #f8fafc; --card-background: #0f172a; --card-foreground: #f8fafc; --muted-foreground: #94a3b8; --border: #1e293b; --input: #1e293b; --accent: #cbd5e1; --accent-hover: #f1f5f9; --accent-foreground: #020817; } * { box-sizing: border-box; } body { font-family: var(--font-sans); background-color: var(--background); color: var(--foreground); margin: 0; padding: 2rem; transition: background-color 0.3s, color 0.3s; } .container { max-width: 900px; margin: 0 auto; background-color: var(--card-background); border: 1px solid var(--border); padding: 2.5em; border-radius: 12px; transition: background-color 0.3s, border-color 0.3s; } h1, h2 { color: var(--card-foreground); border-bottom: 1px solid var(--border); padding-bottom: 0.5em; font-weight: 600; } h1 { font-size: 1.75em; } h2 { font-size: 1.25em; margin-top: 2.5em; } form { margin: 1.5em 0 2.5em 0; display: flex; } input[type="text"] { flex-grow: 1; padding: 12px 15px; font-size: 1em; border: 1px solid var(--border); border-radius: 8px 0 0 8px; background-color: var(--input); color: var(--foreground); transition: border-color 0.2s, box-shadow 0.2s; } input[type="text"]:focus { outline: none; border-color: var(--accent); box-shadow: 0 0 0 4px color-mix(in srgb, var(--accent) 25%, transparent); } input[type="submit"], .theme-toggle { padding: 12px 24px; font-size: 1em; font-weight: 600; background-color: var(--accent); color: var(--accent-foreground); border: 1px solid var(--border); cursor: pointer; transition: background-color 0.2s; } input[type="submit"] { border-radius: 0 8px 8px 0; border-left: 0; } input[type="submit"]:hover { background-color: var(--accent-hover); } table { width: 100%; border-collapse: collapse; margin-top: 1em; font-size: 0.95em; } th, td { padding: 12px 15px; border-bottom: 1px solid #f1f5f9; text-align: left; vertical-align: middle; } tr:last-child td { border-bottom: none; } td:not(.key) { font-family: var(--font-mono); } .key { font-weight: 500; color: var(--muted-foreground); width: 35%; } a { color: var(--accent); text-decoration: none; font-weight: 500; } a:hover { text-decoration: underline; } .footer { text-align: center; margin-top: 2.5em; font-size: 0.9em; color: var(--muted-foreground); } .error { color: #dc2626; background-color: #fee2e2; padding: 1em; border: 1px solid #fecaca; border-radius: 8px; } [data-theme='dark'] .error { color: #f87171; background-color: #450a0a; border-color: #7f1d1d; } .progress-container { display: flex; flex-direction: column; gap: 4px; } .progress-bar { width: 100%; background-color: color-mix(in srgb, var(--border) 50%, transparent); border-radius: 4px; overflow: hidden; height: 8px; } .progress-fill { height: 100%; background-color: var(--muted-foreground); width: 0%; border-radius: 4px; transition: width 0.5s ease-in-out; } .progress-text { font-size: 0.9em; color: var(--muted-foreground); } .difficulty-info { font-size: 0.8em; color: var(--muted-foreground); opacity: 0.8; } .probability-info, .prediction-info { font-size: 0.8em; color: var(--muted-foreground); margin-top: 10px; padding-top: 10px; border-top: 1px solid var(--border); } .header-controls { display: flex; justify-content: space-between; align-items: flex-start; } .theme-toggle { position: absolute; top: 1.25rem; right: 1.25rem; padding: 0; width: 38px; height: 38px; border-radius: 50%; display: flex; align-items: center; justify-content: center; background-color: var(--card-background); border: 1px solid var(--border); box-shadow: 0 2px 5px rgba(0,0,0,0.05); overflow: hidden; transition: background-color 0.3s, border-color 0.3s, box-shadow 0.3s; } .theme-toggle:hover { background-color: var(--input); } .theme-toggle svg { position: absolute; transition: transform 0.3s ease-out, opacity 0.2s; color: var(--muted-foreground); } .theme-toggle .sun { transform: translateY(0); } .theme-toggle .moon { transform: translateY(150%); opacity: 0; } [data-theme='dark'] .theme-toggle .sun { transform: translateY(-150%); opacity: 0; } [data-theme='dark'] .theme-toggle .moon { transform: translateY(0); opacity: 1; } .chart-controls { display: flex; gap: 0.5rem; margin-top: 2em; } .chart-controls button { font-family: var(--font-sans); font-size: 0.85em; font-weight: 600; padding: 0.5em 1em; border-radius: 6px; background-color: transparent; border: 1px solid var(--border); color: var(--muted-foreground); cursor: pointer; transition: background-color 0.2s, color 0.2s; } .chart-controls button.active { background-color: var(--accent); color: var(--accent-foreground); border-color: var(--accent); } .connection-info-card { background-color: var(--input); border: 1px solid var(--border); border-radius: 8px; padding: 1.5rem; margin: 2.5em 0; font-size: 0.9em; } .connection-info-card h2 { margin-top: 0; font-size: 1.1em; } .connection-info-card code { background-color: var(--border); padding: 0.2em 0.4em; border-radius: 4px; font-family: var(--font-mono); } .full-date { font-size: 0.85em; opacity: 0.7; margin-left: 0.5em; }
        .workers-toggle { cursor: pointer; } .workers-toggle .key { display: flex; align-items: center; justify-content: space-between; } .workers-toggle .chevron { transition: transform 0.2s ease-in-out; } .workers-toggle.open .chevron { transform: rotate(180deg); } .worker-list-row { display: none; } .worker-list-row td { padding: 0 !important; border-bottom: 1px solid var(--border) !important; } .worker-list-content { padding: 1.5em; background-color: var(--input); } .worker-list-content h3 { margin-top: 0; font-size: 1.1em; color: var(--card-foreground); }
        .worker-chart-btn { background: none; border: 1px solid var(--border); color: var(--muted-foreground); padding: 4px 8px; font-size: 0.8em; border-radius: 6px; cursor: pointer; } .worker-chart-btn:hover { background-color: var(--card-background); }
        .modal-backdrop { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background-color: rgba(15, 23, 42, 0.7); display: none; align-items: center; justify-content: center; z-index: 1000; backdrop-filter: blur(4px); -webkit-backdrop-filter: blur(4px); }
        .modal-content { background-color: var(--card-background); padding: 2rem; border-radius: 12px; max-width: 90%; width: 800px; position: relative; border: 1px solid var(--border); }
        .modal-close-btn { position: absolute; top: 0.5rem; right: 0.75rem; background: none; border: none; font-size: 2rem; color: var(--muted-foreground); cursor: pointer; line-height: 1; }
        .modal-header { display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--border); padding-bottom: 1rem; margin-bottom: 1rem; }
        .modal-title { font-size: 1.25em; font-weight: 600; color: var(--card-foreground); }
        .diff-change { font-size: 0.9em; margin-left: 0.5em; font-weight: 600; }
        .diff-up { color: #22c55e; } .diff-down { color: #ef4444; }
        [data-theme='dark'] .diff-up { color: #4ade80; } [data-theme='dark'] .diff-down { color: #f87171; }
    </style>
</head>
<body>
    <button class="theme-toggle" id="theme-toggle" title="Toggle theme"> <svg class="sun" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="5"></circle><line x1="12" y1="1" x2="12" y2="3"></line><line x1="12" y1="21" x2="12" y2="23"></line><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"></line><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"></line><line x1="1" y1="12" x2="3" y2="12"></line><line x1="21" y1="12" x2="23" y2="12"></line><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"></line><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"></line></svg> <svg class="moon" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"></path></svg> </button>
    <div class="container">
        <div class="header-controls"> <div> <?php if ($btc_address): ?> <p><a href="<?= $script_path ?>">&larr; Back to Overview</a></p> <h1>User Statistics</h1><p style="word-wrap: break-word; font-family: var(--font-mono); color: var(--muted-foreground);"><?= $btc_address ?></p> <?php else: ?> <h1>Pool Overview</h1> <?php endif; ?> </div> </div>
        <?php if (!$btc_address): ?> <form action="<?= $script_path ?>" method="get"> <input type="text" name="btc_address" placeholder="Enter BTC address..." required><input type="submit" value="Search"> </form> <div class="connection-info-card"> <h2>Connection Details</h2> <p>Use the following details to connect your miner:</p> <ul> <li><strong>Stratum URL:</strong> <code>stratum+tcp://srv.88x.pl:3333</code></li> <li><strong>Username:</strong> <code>YOUR_BTC_ADDRESS</code></li> <li><strong>Password:</strong> <code>x</code></li> <li><strong>Pool Fee:</strong> <strong>0%</strong></li> </ul> </div> <?php endif; ?>
        <?php if ($error): ?><p class="error"><?= $error ?></p><?php endif; ?>
        <?php if ($btc_address && !$user_summary): ?><p class="error">No data found for this BTC address.</p><?php endif; ?>
        <div class="chart-controls" id="chart-controls"> <button data-range="1" class="active">24H</button> <button data-range="7">7D</button> <button data-range="30">30D</button> </div>
        <div id="chart-container"><canvas id="hashrateChart"></canvas></div>
        <?php if (!$btc_address && $network_difficulty): ?>
            <h2>Network Status</h2>
            <table><tbody>
                <tr>
                    <td class="key">Current Difficulty</td>
                    <td class="font-mono">
                        <?= htmlspecialchars(number_format($network_difficulty)) ?>
                        <?php if ($previous_network_difficulty > 0):
                            $change = (($network_difficulty - $previous_network_difficulty) / $previous_network_difficulty) * 100;
                            $class = $change >= 0 ? 'diff-up' : 'diff-down';
                            $sign = $change >= 0 ? '+' : '';
                            echo ' <span class="diff-change ' . $class . '">(' . $sign . number_format($change, 2) . '%)</span>';
                        endif; ?>
                    </td>
                </tr>
                <?php if ($difficulty_prediction): ?>
                <tr>
                    <td class="key">Next Adjustment</td>
                    <td class="font-mono">
                        <?php
                        echo 'Progress: <strong>' . ($difficulty_prediction['progress'] ?? 'N/A') . '%</strong>';
                        if (isset($difficulty_prediction['prediction'])) {
                            $pred_val = $difficulty_prediction['prediction'];
                            $pred_class = $pred_val >= 0 ? 'diff-up' : 'diff-down';
                            $pred_sign = $pred_val >= 0 ? '+' : '';
                            echo ' &nbsp;&bull;&nbsp; Est. Change: <strong class="' . $pred_class . '">' . $pred_sign . $pred_val . '%</strong>';
                        }
                        ?>
                    </td>
                </tr>
                <?php endif; ?>
            </tbody></table>
        <?php endif; ?>
        <?php
        function render_table($data, $key_order, $friendly_names, $network_difficulty, $previous_difficulty, $analytics, $workers_data = null, $difficulty_prediction = null) {
            echo '<table><tbody>';
            foreach ($key_order as $key) {
                if ($key === 'workers' && isset($data[$key]) && $data[$key] > 0) {
                    echo '<tr class="workers-toggle" id="workers-toggle" title="Click to expand/collapse"><td class="key"><span>' . ($friendly_names[$key] ?? 'Workers') . '</span> <svg class="chevron" width="16" height="16" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M4 6L8 10L12 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg></td><td>' . htmlspecialchars($data[$key]) . '</td></tr>';
                    if ($workers_data) {
                        echo '<tr class="worker-list-row" id="worker-list-row"><td colspan="2"><div class="worker-list-content"><h3>Worker Details</h3><table><thead><tr><th class="key">Name</th><th>Hashrate (5 min)</th><th>Shares</th><th>Chart</th></tr></thead><tbody>';
                        foreach ($workers_data as $name => $stats) {
                            $hashrate5m = $stats['hashrate5m'] ?? '0';
                            if ((float)$hashrate5m <= 0) continue;
                            echo '<tr><td class="key">' . htmlspecialchars($name) . '</td><td>' . htmlspecialchars(format_hashrate($hashrate5m)) . '</td><td>' . htmlspecialchars(number_format($stats['shares'] ?? 0)) . '</td><td><button class="worker-chart-btn" data-worker="' . htmlspecialchars($name) . '">Show Chart</button></td></tr>';
                        }
                        echo '</tbody></table></div></td></tr>';
                    }
                    continue;
                }
                if ($key === 'rejected_percent') { if (isset($data['accepted'], $data['rejected']) && ($data['accepted'] + $data['rejected']) > 0) { $total = $data['accepted'] + $data['rejected']; $percent = ($data['rejected'] / $total) * 100; $label = $friendly_names[$key]; $value = format_number_auto($percent, 2) . ' %'; echo '<tr><td class="key">' . htmlspecialchars($label) . '</td><td>' . htmlspecialchars($value) . '</td></tr>'; } continue; }
                if (isset($data[$key])) {
                    $label = $friendly_names[$key] ?? ucfirst($key); $value = $data[$key];
                    echo '<tr><td class="key">' . htmlspecialchars($label) . '</td><td>';
                    if ($key === 'bestshare' && $network_difficulty > 0) {
                        $percentage = ($value / $network_difficulty) * 100;
                        $percentage_capped = min(100, $percentage);
                        $diff_change_html = '';
                        if ($previous_difficulty > 0) {
                            $change = (($network_difficulty - $previous_difficulty) / $previous_difficulty) * 100;
                            $class = $change >= 0 ? 'diff-up' : 'diff-down';
                            $sign = $change >= 0 ? '+' : '';
                            $diff_change_html = ' <span class="diff-change ' . $class . '">(' . $sign . number_format($change, 2) . '%)</span>';
                        }
                        echo '<div class="progress-container"><span class="progress-text">' . htmlspecialchars(number_format($value)) . ' (' . number_format($percentage, 4) . '%)</span><div class="progress-bar"><div class="progress-fill" style="width: ' . $percentage_capped . '%;"></div></div><div class="difficulty-info">Network Difficulty: ' . htmlspecialchars(number_format($network_difficulty)) . $diff_change_html . '</div>';
                        if ($difficulty_prediction) { echo '<div class="prediction-info">Next adjustment progress: <strong>' . ($difficulty_prediction['progress'] ?? 'N/A') . '%</strong>.<br>'; if (isset($difficulty_prediction['prediction'])) { $pred_val = $difficulty_prediction['prediction']; $pred_class = $pred_val >= 0 ? 'diff-up' : 'diff-down'; $pred_sign = $pred_val >= 0 ? '+' : ''; echo 'Estimated change: <strong class="' . $pred_class . '">' . $pred_sign . $pred_val . '%</strong>'; } echo '</div>'; }
                        if ($analytics) { echo '<div class="probability-info">Based on your 1h hashrate:<br>Avg. time to find a block: <strong>' . format_long_time($analytics['time_to_find']) . '</strong><br>Est. probability: <strong>' . number_format($analytics['prob_month'], 6) . '%</strong>/month, <strong>' . number_format($analytics['prob_year'], 4) . '%</strong>/year.</div>'; }
                        echo '</div>';
                    } elseif (strpos($key, 'hashrate') === 0) {
                        echo htmlspecialchars(format_hashrate($value));
                    } elseif (strpos($key, 'SPS') === 0) {
                        echo htmlspecialchars(format_number_auto((float)$value, 3));
                    } else {
                        if ($key === 'lastshare') { echo '<div class="time-ago" data-timestamp="' . htmlspecialchars($value) . '">...</div>'; }
                        elseif ($key === 'runtime') { echo htmlspecialchars(format_seconds($value)); }
                        else { echo htmlspecialchars($value); }
                    }
                    echo '</td></tr>';
                }
            }
            echo '</tbody></table>';
        }
        ?>
        
        <?php if ($user_summary): ?>
             <?php render_table($user_summary, ['hashrate1m', 'hashrate5m', 'hashrate1hr', 'hashrate1d', 'hashrate7d', 'shares', 'workers', 'lastshare', 'bestshare'], $friendly_names, $network_difficulty, $previous_network_difficulty, $analytics, $user_workers, $difficulty_prediction); ?>
        <?php endif; ?>

        <?php if ($pool_data && !$btc_address): ?> <h2>Pool Statistics</h2> <?php render_table($pool_data, ['hashrate1m', 'hashrate5m', 'hashrate1hr', 'hashrate1d', 'hashrate7d', 'SPS1m', 'SPS5m', 'SPS15m', 'SPS1h', 'Users', 'Workers', 'accepted', 'rejected', 'rejected_percent', 'runtime'], $friendly_names, null, null, null, null, null); ?> <?php endif; ?>
        <?php if ($last_update): ?> <p class="footer">Last updated: <span class="time-ago" data-timestamp="<?= $last_update ?>">...</span></p> <?php endif; ?>
    </div>
    
    <div id="modal-backdrop" class="modal-backdrop"> <div id="modal-content" class="modal-content"> <div class="modal-header"> <h2 id="modal-title" class="modal-title">Worker Chart</h2> <button id="modal-close-btn" class="modal-close-btn">&times;</button> </div> <div id="modal-chart-container"> <canvas id="modalChartCanvas"></canvas> </div> </div> </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const timeAgo = (timestamp) => { const now = new Date(); const past = new Date(timestamp * 1000); const seconds = Math.floor((now - past) / 1000); if (seconds < 60) return `${seconds}s ago`; const minutes = Math.floor(seconds / 60); if (minutes < 60) return `${minutes}m ago`; const hours = Math.floor(minutes / 60); if (hours < 24) return `${hours}h ago`; const days = Math.floor(hours / 24); return `${days}d ago`; };
            const timeElements = document.querySelectorAll('.time-ago');
            timeElements.forEach(element => { const timestamp = parseInt(element.dataset.timestamp, 10); if (!isNaN(timestamp)) { const relativeTime = timeAgo(timestamp); const date = new Date(timestamp * 1000); const year = date.getFullYear(); const month = ('0' + (date.getMonth() + 1)).slice(-2); const day = ('0' + date.getDate()).slice(-2); const hours = ('0' + date.getHours()).slice(-2); const minutes = ('0' + date.getMinutes()).slice(-2); const seconds = ('0' + date.getSeconds()).slice(-2); const fullDate = `${year}-${month}-${day} ${hours}:${minutes}:${seconds}`; element.innerHTML = `${relativeTime} <span class="full-date">(${fullDate})</span>`; } });
            
            const themeToggle = document.getElementById('theme-toggle');
            const themeSwitcher = () => { const currentTheme = document.documentElement.getAttribute('data-theme'); const newTheme = currentTheme === 'light' ? 'dark' : 'light'; document.documentElement.setAttribute('data-theme', newTheme); localStorage.setItem('theme', newTheme); };
            themeToggle.addEventListener('click', themeSwitcher);

            const workersToggle = document.getElementById('workers-toggle');
            const workerListRow = document.getElementById('worker-list-row');
            if(workersToggle && workerListRow) { workersToggle.addEventListener('click', () => { const isOpen = workersToggle.classList.toggle('open'); workerListRow.style.display = isOpen ? 'table-row' : 'none'; }); }

            const mainChartCanvas = document.getElementById('hashrateChart');
            const btcAddress = <?= json_encode($btc_address) ?>;
            const seriesConfig = { '5m': { label: '5 min avg', color: '#38bdf8' }, '1h': { label: '1 hour avg', color: '#fb923c' }, '1d': { label: '24 hour avg', color: '#a78bfa' } };
            let mainChart = null; let modalChart = null;
            
            const fetchAndRender = async (range, workerName = null, canvas, title, isModal = false) => {
                let url = `?fetch_chart_data=true&range=${range}`;
                if (btcAddress) url += `&btc_address=${btcAddress}`;
                if (workerName) url += `&worker=${workerName}`;
                try {
                    const response = await fetch(url); const datasets = await response.json();
                    if (isModal && modalChart) modalChart.destroy(); else if (!isModal && mainChart) mainChart.destroy();
                    const style = getComputedStyle(document.body); const chartDatasets = [];
                    for (const key in datasets) { if (datasets.hasOwnProperty(key)) { chartDatasets.push({ label: seriesConfig[key].label, data: datasets[key].labels.map((ts, index) => ({ x: ts * 1000, y: datasets[key].data[index] })), borderColor: seriesConfig[key].color, backgroundColor: seriesConfig[key].color + '1A', fill: true, borderWidth: 2, pointRadius: 0, tension: 0.4 }); } }
                    const timeUnit = range > 7 ? 'day' : (range > 1 ? 'day' : 'hour');
                    const chartConfig = {
                        type: 'line', data: { datasets: chartDatasets },
                        options: {
                            responsive: true, maintainAspectRatio: true,
                            scales: { x: { type: 'time', time: { unit: timeUnit, tooltipFormat: 'yyyy-MM-dd HH:mm', displayFormats: { millisecond: 'HH:mm:ss.SSS', second: 'HH:mm:ss', minute: 'HH:mm', hour: 'HH:mm', day: 'MMM dd', week: 'MMM dd', month: 'MMM yyyy' } }, grid: { color: style.getPropertyValue('--border') }, ticks: { color: style.getPropertyValue('--muted-foreground') } }, y: { beginAtZero: true, title: { display: true, text: 'Hashrate (GH/s)', color: style.getPropertyValue('--muted-foreground') }, grid: { color: style.getPropertyValue('--border') }, ticks: { color: style.getPropertyValue('--muted-foreground') } } },
                            plugins: {
                                legend: { labels: { color: style.getPropertyValue('--muted-foreground') } },
                                title: { display: true, text: title, font: { size: 16, weight: '600' }, color: style.getPropertyValue('--foreground') },
                                tooltip: { mode: 'index', intersect: false, backgroundColor: style.getPropertyValue('--card-background'), titleColor: style.getPropertyValue('--foreground'), bodyColor: style.getPropertyValue('--foreground') }
                            }
                        }
                    };
                    if (isModal) { modalChart = new Chart(canvas, chartConfig); } else { mainChart = new Chart(canvas, chartConfig); }
                } catch (e) { console.error("Failed to fetch chart data:", e); }
            };

            const chartControls = document.getElementById('chart-controls');
            if (chartControls) {
                chartControls.addEventListener('click', (e) => {
                    if (e.target.tagName === 'BUTTON') {
                        const range = e.target.dataset.range;
                        fetchAndRender(range, null, mainChartCanvas, btcAddress ? 'Aggregated Hashrate' : 'Pool Hashrate');
                    }
                });
            }

            const modalBackdrop = document.getElementById('modal-backdrop');
            const modalCloseBtn = document.getElementById('modal-close-btn');
            const modalChartCanvas = document.getElementById('modalChartCanvas');
            const modalTitle = document.getElementById('modal-title');
            document.querySelector('#worker-list-row')?.addEventListener('click', (e) => {
                if (e.target.classList.contains('worker-chart-btn')) {
                    const workerName = e.target.dataset.worker;
                    const currentRange = document.querySelector('.chart-controls button.active').dataset.range;
                    modalTitle.textContent = `Chart for worker: ${workerName}`;
                    modalBackdrop.style.display = 'flex';
                    fetchAndRender(currentRange, workerName, modalChartCanvas, `Worker: ${workerName}`, true);
                }
            });
            const closeModal = () => { modalBackdrop.style.display = 'none'; if (modalChart) { modalChart.destroy(); modalChart = null; } };
            if(modalCloseBtn) modalCloseBtn.addEventListener('click', closeModal);
            if(modalBackdrop) modalBackdrop.addEventListener('click', (e) => { if (e.target === modalBackdrop) closeModal(); });

            if (mainChartCanvas) {
                fetchAndRender(1, null, mainChartCanvas, btcAddress ? 'Aggregated Hashrate' : 'Pool Hashrate');
                themeToggle.addEventListener('click', () => setTimeout(() => {
                    const activeRange = document.querySelector('.chart-controls button.active').dataset.range;
                    fetchAndRender(activeRange, null, mainChartCanvas, btcAddress ? 'Aggregated Hashrate' : 'Pool Hashrate');
                    if (modalBackdrop.style.display === 'flex' && modalChart) {
                        const modalTitleEl = document.getElementById('modal-title');
                        if(modalTitleEl) {
                           const activeWorker = modalTitleEl.textContent.replace('Chart for worker: ', '');
                           fetchAndRender(activeRange, activeWorker, modalChartCanvas, `Worker: ${activeWorker}`, true);
                        }
                    }
                }, 100));
            }
        });
    </script>
</body>
</html>