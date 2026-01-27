<?php
// --- CONFIGURATION ---
// Wersja v84: Restored Block Height Display
ini_set('display_errors', 0);
error_reporting(E_ALL);

if (isset($_GET['fetch_chart_data']) || isset($_GET['fetch_network_chart']) || isset($_GET['fetch_daily_chart'])) {
    header('Content-Type: application/json');
    header('Cache-Control: public, max-age=60'); 
}

define('AGGREGATE_WORKER_NAME', '_AGGREGATE_');
$dataDir = __DIR__ . '/data';
$statsDbPath = $dataDir . '/stats.db';
$networkDbPath = $dataDir . '/network.db';

// --- API SECTION ---
if (isset($_GET['fetch_chart_data']) || isset($_GET['fetch_network_chart']) || isset($_GET['fetch_daily_chart'])) { 
    try { 
        $datasets = []; 
        if (isset($_GET['fetch_network_chart'])) { 
            if (!file_exists($networkDbPath)) throw new Exception("Network DB unavailable");
            $pdo_net = new PDO('sqlite:' . $networkDbPath);
            $since = time() - (730 * 86400); 
            $query = "SELECT MIN(timestamp) as timestamp, AVG(network_hashrate_ghs) as network_hashrate_ghs, MAX(network_difficulty) as network_difficulty FROM network_history WHERE timestamp > :since GROUP BY (timestamp / 86400) ORDER BY timestamp ASC";
            $stmt = $pdo_net->prepare($query); $stmt->execute([':since' => $since]); $results = $stmt->fetchAll(PDO::FETCH_ASSOC); 
            $datasets['hashrate'] = ['labels' => [], 'data' => []]; $datasets['difficulty'] = ['labels' => [], 'data' => []]; 
            foreach ($results as $row) { $ts = $row['timestamp'] * 1000; $datasets['hashrate']['labels'][] = $ts; $datasets['hashrate']['data'][] = round($row['network_hashrate_ghs'], 2); $datasets['difficulty']['labels'][] = $ts; $datasets['difficulty']['data'][] = round($row['network_difficulty'], 2); } 
        } elseif (isset($_GET['fetch_daily_chart'])) {
            if (!file_exists($statsDbPath)) throw new Exception("Stats DB unavailable");
            $pdo_stats = new PDO('sqlite:' . $statsDbPath);
            $btc_address = isset($_GET['btc_address']) ? trim(htmlspecialchars($_GET['btc_address'])) : null;
            $worker_name = isset($_GET['worker']) ? trim(htmlspecialchars($_GET['worker'])) : null;
            $range_days = isset($_GET['range']) ? (int)$_GET['range'] : 365;
            $since = time() - ($range_days * 86400);
            $params = [':since' => $since];
            if ($btc_address) { $table = 'user_daily_history'; $where_clause = "WHERE date > :since AND btc_address = :btc_address AND worker_name = :worker_name "; $params[':btc_address'] = $btc_address; $params[':worker_name'] = $worker_name ?: AGGREGATE_WORKER_NAME; } else { $table = 'pool_daily_history'; $where_clause = "WHERE date > :since "; }
            try { $pdo_stats->query("SELECT 1 FROM $table LIMIT 1"); } catch (Exception $e) { throw new Exception("Table $table not ready."); }
            $query = "SELECT date, avg_hashrate_ghs FROM {$table} {$where_clause} ORDER BY date ASC";
            $stmt = $pdo_stats->prepare($query); $stmt->execute($params); $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $datasets['1d'] = [ 'labels' => array_column($results, 'date'), 'data' => array_column($results, 'avg_hashrate_ghs'), ];
        } else { 
            if (!file_exists($statsDbPath)) throw new Exception("Stats DB unavailable");
            $pdo_stats = new PDO('sqlite:' . $statsDbPath);
            $btc_address = isset($_GET['btc_address']) ? trim(htmlspecialchars($_GET['btc_address'])) : null; 
            $worker_name = isset($_GET['worker']) ? trim(htmlspecialchars($_GET['worker'])) : null; 
            $range_days = isset($_GET['range']) ? (int)$_GET['range'] : 1; 
            if ($range_days <= 1) { 
                if ($btc_address) { $table = 'user_hourly_history'; $col_time = 'time_bucket'; $groupBy = "GROUP BY time_bucket"; $interval = 3600; $series_map = ['1h' => 'avg_hashrate_ghs']; } 
                else { $table = 'pool_history'; $col_time = 'timestamp'; $groupBy = "GROUP BY time_bucket"; $interval = 300; $series_map = ['5m' => 'hashrate_5m_ghs', '1h' => 'hashrate_1h_ghs']; }
            } elseif ($range_days <= 30) { 
                if ($btc_address) { $table = 'user_hourly_history'; $col_time = 'time_bucket'; $interval = 3600; $series_map = ['1h' => 'avg_hashrate_ghs']; } 
                else { $table = 'pool_history'; $col_time = 'timestamp'; $interval = 3600; $series_map = ['1h' => 'hashrate_1h_ghs']; } $groupBy = "GROUP BY time_bucket"; 
            } else { $table = $btc_address ? 'user_daily_history' : 'pool_daily_history'; $col_time = 'date'; $groupBy = "GROUP BY time_bucket"; $interval = 86400; $series_map = ['1d' => 'avg_hashrate_ghs']; }
            $since = time() - ($range_days * 86400); $params = [':since' => $since]; $where_clause = "WHERE $col_time > :since ";
            if ($btc_address) { $where_clause .= "AND btc_address = :btc_address AND worker_name = :worker_name "; $params[':btc_address'] = $btc_address; $params[':worker_name'] = $worker_name ?: AGGREGATE_WORKER_NAME; }
            try { $pdo_stats->query("SELECT 1 FROM $table LIMIT 1"); } catch (Exception $e) { throw new Exception("Table $table not ready."); }
            $sql_selects = []; foreach ($series_map as $key => $column) { $sql_selects[] = "AVG({$column}) AS avg_{$key}"; }
            $query = "SELECT ($col_time / $interval) * $interval AS time_bucket, " . implode(', ', $sql_selects) . " FROM {$table} {$where_clause} {$groupBy} ORDER BY time_bucket ASC";
            $stmt = $pdo_stats->prepare($query); $stmt->execute($params); $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($series_map as $key => $column) { $datasets[$key] = [ 'labels' => array_column($results, 'time_bucket'), 'data' => array_column($results, "avg_{$key}"), ]; } 
        } 
    } catch (Exception $e) { $datasets = ['error' => $e->getMessage()]; } 
    echo json_encode($datasets); exit(); 
}

// --- MAIN PAGE FETCH ---
$pool_data = []; $user_summary = null; $user_workers = null; $last_update = null;
$network_difficulty = null; $previous_network_difficulty = null; $network_hashrate = null;
$last_block_reward_btc = null; $last_fetched_block_height = null; $btc_usd_price = null; 
$difficulty_prediction = null; $network_hashrate_change = null; $error_msg = null; 
$btc_address = isset($_GET['btc_address']) ? trim(htmlspecialchars($_GET['btc_address'])) : null;
$user_data_full = null;
$accepted_30d = null; $rejected_30d = null; $rejected_percent_30d = null;

try {
    if (!file_exists($statsDbPath)) { throw new Exception("Stats DB not found."); }
    $pdo_stats = new PDO('sqlite:' . $statsDbPath);
    $pdo_stats->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pool_row = $pdo_stats->query("SELECT data, last_update FROM pool_stats WHERE id = 1 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $pool_data = $pool_row ? json_decode($pool_row['data'], true) : [];
    $last_update = $pool_row['last_update'] ?? null;
    
    if ($pool_data) {
        $last_fetched_block_height = $pool_data['last_fetched_block_height'] ?? null; 
        $last_block_reward_btc = $pool_data['last_block_reward_btc'] ?? null;
        $btc_usd_price = $pool_data['btc_usd_price'] ?? null; 
    }
    
    // 30-Day Logic
    if (!$btc_address && isset($pool_data['accepted']) && isset($pool_data['rejected'])) {
        $curr_acc = (float)$pool_data['accepted']; $curr_rej = (float)$pool_data['rejected'];
        $time_30d_ago = time() - 2592000;
        $old_data = false;
        try {
            $stmt_old = $pdo_stats->prepare("SELECT accepted, rejected FROM pool_daily_history WHERE date <= ? ORDER BY date DESC LIMIT 1");
            $stmt_old->execute([$time_30d_ago]);
            $old_data = $stmt_old->fetch(PDO::FETCH_ASSOC);
            if (!$old_data) { $stmt_oldest = $pdo_stats->query("SELECT accepted, rejected FROM pool_daily_history ORDER BY date ASC LIMIT 1"); $old_data = $stmt_oldest->fetch(PDO::FETCH_ASSOC); }
        } catch (Exception $e) { }
        
        if ($old_data) {
            $old_acc = (float)$old_data['accepted']; $old_rej = (float)$old_data['rejected'];
            $diff_acc = $curr_acc - $old_acc; $diff_rej = $curr_rej - $old_rej;
            if ($diff_acc < 0) $diff_acc = $curr_acc; if ($diff_rej < 0) $diff_rej = $curr_rej;
            $accepted_30d = $diff_acc; $rejected_30d = $diff_rej;
            $total_30d = $accepted_30d + $rejected_30d;
            $rejected_percent_30d = $total_30d > 0 ? ($rejected_30d / $total_30d) * 100 : 0;
        } else { $accepted_30d = 0; $rejected_30d = 0; $rejected_percent_30d = 0; }
    }

    if ($btc_address) {
        $user_stmt = $pdo_stats->prepare("SELECT data FROM user_stats WHERE btc_address = ?");
        $user_stmt->execute([$btc_address]);
        $user_row = $user_stmt->fetch(PDO::FETCH_ASSOC);
        $user_data_full = $user_row ? json_decode($user_row['data'], true) : null;
        if ($user_data_full) {
            $user_summary = $user_data_full; 
            if (isset($user_data_full['worker']) && is_array($user_data_full['worker'])) {
                $user_workers = $user_data_full['worker'];
                $user_workers_formatted = [];
                foreach($user_workers as $w) { if (isset($w['workername'])) { $user_workers_formatted[$w['workername']] = $w; } }
                $user_workers = $user_workers_formatted;
            } else { $user_workers = null; }
        }
    }
    
    if (file_exists($networkDbPath)) {
        $pdo_net = new PDO('sqlite:' . $networkDbPath);
        $pdo_net->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $hist_row = $pdo_net->query("SELECT network_hashrate_ghs, network_difficulty FROM network_history ORDER BY timestamp DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        if ($hist_row) { $network_difficulty = $hist_row['network_difficulty']; $network_hashrate = $hist_row['network_hashrate_ghs']; }
        if ($network_difficulty) {
            $prev_row = $pdo_net->prepare("SELECT network_difficulty FROM network_history WHERE timestamp < ? ORDER BY timestamp DESC LIMIT 1");
            $prev_row->execute([time() - 86400]);
            $previous_network_difficulty = $prev_row->fetchColumn();
        }
        $prediction_result = $pdo_net->query("SELECT * FROM prediction_data WHERE id = 1 LIMIT 1");
        $difficulty_prediction = $prediction_result ? $prediction_result->fetch(PDO::FETCH_ASSOC) : false;
        $history_result = $pdo_net->query("SELECT network_hashrate_ghs FROM network_history WHERE timestamp >= " . (time() - 90000) . " ORDER BY timestamp ASC LIMIT 1");
        if($history_result) { $old_hashrate = $history_result->fetchColumn(); if ($old_hashrate && $network_hashrate && $old_hashrate > 0) { $network_hashrate_change = (($network_hashrate - $old_hashrate) / $old_hashrate) * 100; } }
    }
} catch (Exception $e) { $error_msg = "Data Error: " . $e->getMessage(); }

function format_seconds($seconds) { if ($seconds === null || $seconds < 1) return '0s'; $parts = []; $days = floor($seconds / 86400); if ($days > 0) $parts[] = $days . 'd'; $hours = floor(($seconds % 86400) / 3600); if ($hours > 0) $parts[] = $hours . 'h'; $minutes = floor(($seconds % 3600) / 60); if ($minutes > 0) $parts[] = $minutes . 'm'; $secs = $seconds % 60; if ($secs > 0 || empty($parts)) $parts[] = $secs . 's'; return implode(' ', $parts); } 
function format_number_auto($number, $decimals = 2) { if ($number === null || !is_numeric($number)) return 'N/A'; if ($number == floor($number)) { return number_format($number, 0, '.', ','); } return number_format($number, $decimals, '.', ','); } 
function format_metric_pl($number) { if ($number === null || !is_numeric($number)) return 'N/A'; if ($number >= 1000000000) { return number_format($number / 1000000000, 2, ',', ' ') . ' mld'; } elseif ($number >= 1000000) { return number_format($number / 1000000, 2, ',', ' ') . ' mln'; } elseif ($number >= 1000) { return number_format($number / 1000, 1, ',', ' ') . ' tys.'; } return number_format($number, 0, ',', ' '); }
function format_hashrate($hashrateInput) { if ($hashrateInput === null) return 'N/A'; if (is_numeric($hashrateInput)) { $ghs = (float)$hashrateInput; } else { $value = (float)$hashrateInput; preg_match('/[a-zA-Z]/', $hashrateInput, $matches); $unit = $matches[0] ?? 'G'; $ghs = 0; switch (strtoupper($unit)) { case 'K': $ghs = $value / 1000000; break; case 'M': $ghs = $value / 1000; break; case 'G': $ghs = $value; break; case 'T': $ghs = $value * 1000; break; case 'P': $ghs = $value * 1000 * 1000; break; case 'E': $ghs = $value * 1000 * 1000 * 1000; break; default:  $ghs = $value; } } if ($ghs >= 1000000000000) { return format_number_auto($ghs / 1000000000000) . ' ZH/s'; } elseif ($ghs >= 1000000000) { return format_number_auto($ghs / 1000000000) . ' EH/s'; } elseif ($ghs >= 1000000) { return format_number_auto($ghs / 1000000) . ' PH/s'; } elseif ($ghs >= 1000) { return format_number_auto($ghs / 1000) . ' TH/s'; } else { return format_number_auto($ghs) . ' GH/s'; } } 
function parse_hashrate_to_ghs($hashrateStr) { $value = (float)$hashrateStr; $unit = strtoupper(substr(trim((string)$hashrateStr), -1)); switch ($unit) { case 'K': return $value / 1000000; case 'M': return $value / 1000; case 'G': return $value; case 'T': return $value * 1000; case 'P': return $value * 1000 * 1000; default: return $value; } } 
function calculate_block_probability($user_hashrate_ghs, $network_hashrate_ghs, $days) { if ($user_hashrate_ghs <= 0 || $network_hashrate_ghs <= 0) { return 0; } $blocks_in_period = $days * 144; $p_user = $user_hashrate_ghs / $network_hashrate_ghs; $p_not_finding = pow(1 - $p_user, $blocks_in_period); return (1 - $p_not_finding) * 100; } 
function calculate_time_to_find_block($user_hashrate_ghs, $network_difficulty) { if ($user_hashrate_ghs <= 0 || $network_difficulty <= 0) { return 0; } return ($network_difficulty * 4294967296) / ($user_hashrate_ghs * 1000000000); } 
function format_long_time($seconds) { if ($seconds === null || $seconds <= 0) return "N/A"; $minutes = $seconds / 60; $hours = $minutes / 60; $days = $hours / 24; $months = $days / 30.44; $years = $days / 365.25; if ($years > 1) return format_number_auto($years) . " years"; if ($months > 1) return format_number_auto($months) . " months"; if ($days > 1) return format_number_auto($days) . " days"; return format_number_auto($hours) . " hours"; } 
function format_share($num) { if ($num === null || !is_numeric($num)) return 'N/A'; if ($num <= 0) return '0'; if ($num < 1000000) return number_format($num); $units = ['K', 'M', 'G', 'T']; $power = floor(log($num, 1000)); return format_number_auto($num / pow(1000, $power), 2) . $units[$power - 1]; }

if (!$btc_address && $accepted_30d !== null) { $pool_data['accepted'] = $accepted_30d; $pool_data['rejected'] = $rejected_30d; $pool_data['rejected_percent'] = $rejected_percent_30d; $friendly_names['accepted'] = 'Accepted (30d)'; $friendly_names['rejected'] = 'Rejected (30d)'; } else { $friendly_names['accepted'] = 'Accepted'; $friendly_names['rejected'] = 'Rejected'; }
$friendly_names = array_merge($friendly_names, [ 'hashrate1m' => 'Hashrate (1m)', 'hashrate5m' => 'Hashrate (5m)', 'hashrate1hr' => 'Hashrate (1h)', 'hashrate1d' => 'Hashrate (1d)', 'hashrate7d' => 'Hashrate (7d)', 'shares' => 'Shares', 'workers' => 'Workers', 'lastshare' => 'Last Share', 'bestshare' => 'Best Share', 'runtime' => 'Uptime', 'Users' => 'Users', 'Workers' => 'Workers', 'rejected_percent' => 'Rejected %', 'time_to_block' => 'Est. Time/Block' ]); 
$script_path = '.'; $user_summary = $user_summary ?? null; 
if (empty($network_hashrate) && !empty($network_difficulty)) { $network_hashrate = $network_difficulty * pow(2, 32) / 600 / 1e9; } 
$analytics = null; if ($user_summary && $network_hashrate) { $user_hashrate_str = $user_summary['hashrate1hr'] ?? '0'; $user_hashrate_ghs = parse_hashrate_to_ghs($user_hashrate_str); $time_to_find_val = calculate_time_to_find_block($user_hashrate_ghs, $network_difficulty); $analytics = [ 'prob_month' => calculate_block_probability($user_hashrate_ghs, $network_hashrate, 30.44), 'prob_year' => calculate_block_probability($user_hashrate_ghs, $network_hashrate, 365.25), 'time_to_find' => $time_to_find_val ]; } 
$pool_time_to_block = null; if ($pool_data && $network_difficulty) { $pool_hashrate_str = $pool_data['hashrate1hr'] ?? '0'; $pool_hashrate_ghs = parse_hashrate_to_ghs($pool_hashrate_str); $pool_time_to_block = calculate_time_to_find_block($pool_hashrate_ghs, $network_difficulty); } 
$estimated_adjustment_date = null; if ($difficulty_prediction && isset($difficulty_prediction['estimated_timestamp']) && $difficulty_prediction['estimated_timestamp'] > 0) { $estimated_adjustment_date = date('Y-m-d H:i', (int)$difficulty_prediction['estimated_timestamp']); }
$pool_title = "srv.88x.pl"; $pool_subtitle = "H.A.N.T.I. v7 Solaris & CKPool Node"; $current_pool_hashrate_1h = $pool_data['hashrate1hr'] ?? '0'; $current_pool_users = $pool_data['Users'] ?? '0'; $current_pool_workers = $pool_data['Workers'] ?? '0';
$last_block_reward_usd = null; if ($last_block_reward_btc !== null && $btc_usd_price !== null) { $last_block_reward_usd = $last_block_reward_btc * $btc_usd_price; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"> 
    <title>Solo Mining Stats | <?= htmlspecialchars($pool_title) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com"> <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin> 
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script> <script src="https://cdn.jsdelivr.net/npm/chartjs-adapter-date-fns@3.0.0/dist/chartjs-adapter-date-fns.bundle.min.js"></script>
    <script> (function() { const getTheme = () => localStorage.getItem('theme') || (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light'); document.documentElement.setAttribute('data-theme', getTheme()); })(); </script>
    <style>
        :root { --font-sans: 'Inter', sans-serif; --font-mono: 'JetBrains Mono', monospace; --bg: #0f1115; --card-bg: #161b22; --border: #30363d; --text-main: #f0f6fc; --text-muted: #8b949e; --accent: #238636; --danger: #da3633; }
        [data-theme='light'] { --bg: #f6f8fa; --card-bg: #ffffff; --border: #d0d7de; --text-main: #24292f; --text-muted: #57606a; --accent: #1a7f37; }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: var(--font-sans); background: var(--bg); color: var(--text-main); line-height: 1.5; padding-bottom: 3rem; }
        .dashboard-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 1rem; max-width: 1300px; margin: 2rem auto; padding: 0 1rem; justify-content: center; }
        .full-width { grid-column: 1 / -1; }
        .card { background: var(--card-bg); border: 1px solid var(--border); border-radius: 12px; padding: 1.5rem; box-shadow: 0 4px 12px rgba(0,0,0,0.05); }
        .kpi-card { display: flex; flex-direction: column; justify-content: space-between; height: 100%; }
        .kpi-title { font-size: 0.85rem; color: var(--text-muted); font-weight: 500; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 0.5rem; }
        .kpi-value { font-family: var(--font-mono); font-size: 1.6rem; font-weight: 700; color: var(--text-main); }
        .kpi-sub { font-size: 0.85rem; margin-top: 0.25rem; font-weight: 500; }
        .text-green { color: var(--accent); } .text-red { color: var(--danger); }
        .error-box { grid-column: 1 / -1; background: var(--danger); color: white; padding: 1rem; border-radius: 8px; text-align: center; font-weight: bold; }
        header { max-width: 1200px; margin: 0 auto; padding: 2rem 1rem 1rem; display: flex; justify-content: space-between; align-items: center; }
        .brand h1 { font-size: 1.5rem; font-weight: 700; display: flex; align-items: center; gap: 0.5rem; }
        .brand span { font-family: var(--font-mono); background: var(--accent); color: white; padding: 2px 8px; border-radius: 4px; font-size: 0.8rem; }
        .subtitle { color: var(--text-muted); font-size: 0.9rem; margin-top: 0.25rem; }
        .hanti-box { background: linear-gradient(145deg, var(--card-bg), rgba(35, 134, 54, 0.05)); border-left: 4px solid var(--accent); }
        .hanti-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; }
        .hanti-badge { font-family: var(--font-mono); font-size: 0.75rem; background: var(--border); padding: 2px 6px; border-radius: 4px; }
        table { width: 100%; border-collapse: collapse; margin-top: 1rem; font-size: 0.9rem; }
        th, td { padding: 0.75rem 0; border-bottom: 1px solid var(--border); text-align: left; }
        th { color: var(--text-muted); font-weight: 500; }
        td.font-mono { font-family: var(--font-mono); }
        tr:last-child td { border-bottom: none; }
        input[type="text"] { background: var(--bg); border: 1px solid var(--border); color: var(--text-main); padding: 0.6rem; border-radius: 6px 0 0 6px; width: 70%; font-family: var(--font-mono); }
        input[type="submit"] { background: var(--accent); border: 1px solid var(--accent); color: white; padding: 0.6rem 1.2rem; border-radius: 0 6px 6px 0; cursor: pointer; font-weight: 600; }
        .chart-wrapper { position: relative; height: 350px; width: 100%; margin-top: 1rem; }
        .chart-controls { display: flex; gap: 0.5rem; justify-content: center; margin-bottom: 1rem; }
        .chart-btn { background: transparent; border: 1px solid var(--border); color: var(--text-muted); padding: 4px 12px; border-radius: 6px; cursor: pointer; font-size: 0.85rem; font-weight: 600; }
        .chart-btn.active { background: var(--accent); color: white; border-color: var(--accent); }
        .diff-up { color: var(--accent); } .diff-down { color: var(--danger); }
        .theme-toggle { background: transparent; border: 1px solid var(--border); color: var(--text-main); width: 40px; height: 40px; border-radius: 50%; cursor: pointer; display: flex; align-items: center; justify-content: center; }
        .clickable { cursor: pointer; text-decoration: underline; text-decoration-style: dotted; text-underline-offset: 4px; }
        .modal-backdrop { position: fixed; inset: 0; background: rgba(0,0,0,0.8); display: none; align-items: center; justify-content: center; z-index: 999; backdrop-filter: blur(5px); }
        .modal-content { background: var(--card-bg); padding: 2rem; border-radius: 12px; width: 90%; max-width: 900px; border: 1px solid var(--border); }
        .close-btn { float: right; background: none; border: none; color: var(--text-muted); font-size: 1.5rem; cursor: pointer; }
        .footer { font-size: 0.75rem; color: var(--text-muted); margin-top: 1rem; opacity: 0.8; }
    </style>
</head>
<body>

<header>
    <div class="brand"><h1>srv.88x.pl <span>NODE</span></h1><div class="subtitle">Solo Mining Intelligence • H.A.N.T.I. v7 Solaris</div></div>
    <button class="theme-toggle" id="theme-toggle">?</button>
</header>

<div class="dashboard-grid">
    <?php if ($error_msg): ?>
        <div class="error-box"><?= htmlspecialchars($error_msg) ?></div>
    <?php endif; ?>

    <?php if (!$btc_address && (isset($pool_data) || isset($network_data))): ?>
    <div class="card kpi-card"><div><div class="kpi-title">Network Hashrate</div><div class="kpi-value"><?= htmlspecialchars(format_hashrate($network_hashrate)) ?></div><?php if ($network_hashrate_change !== null): $class = $network_hashrate_change >= 0 ? 'text-green' : 'text-red'; $sign = $network_hashrate_change >= 0 ? '+' : ''; echo '<div class="kpi-sub '.$class.'">24h Change: '.$sign.number_format($network_hashrate_change, 2).'%</div>'; endif; ?></div><div class="kpi-sub clickable" id="network-status-header">View History Chart</div></div>
    <div class="card kpi-card hanti-box"><div><div class="hanti-header"><div class="kpi-title">Difficulty Prediction</div><div class="hanti-badge">HANTI v7</div></div><?php if ($difficulty_prediction && isset($difficulty_prediction['prediction'])): $pred = $difficulty_prediction['prediction']; $p_class = $pred >= 0 ? 'text-green' : 'text-red'; $p_sign = $pred >= 0 ? '+' : ''; ?><div class="kpi-value <?= $p_class ?>"><?= $p_sign . number_format($pred, 2) ?>%</div><div class="kpi-sub">Progress: <?= $difficulty_prediction['progress'] ?>% <br>Est: <?= $estimated_adjustment_date ?></div><?php else: ?><div class="kpi-value">Calc...</div><?php endif; ?></div></div>
    <div class="card kpi-card"><div><div class="kpi-title">Current Difficulty</div><div class="kpi-value" style="font-size: 1.4rem;"><?= htmlspecialchars(format_number_auto($network_difficulty)) ?></div><?php if ($previous_network_difficulty): $diff_chg = (($network_difficulty - $previous_network_difficulty)/$previous_network_difficulty)*100; $d_class = $diff_chg >= 0 ? 'text-green' : 'text-red'; echo '<div class="kpi-sub '.$d_class.'">Prev: '.($diff_chg>=0?'+':'').number_format($diff_chg, 2).'%</div>'; endif; ?></div></div>
    <div class="card kpi-card"><div><div class="kpi-title">Block Reward</div><div class="kpi-value text-green">$<?= $last_block_reward_usd ? number_format($last_block_reward_usd, 0, '.', ',') : '---' ?></div><div class="kpi-sub"><?= number_format($last_block_reward_btc, 6) ?> BTC</div>
    <div class="kpi-sub" style="font-size: 0.75rem; opacity: 0.7;">Block #<?= number_format($last_fetched_block_height) ?></div>
    <?php if ($btc_usd_price): ?>
        <div class="kpi-sub" style="margin-top: 4px; border-top: 1px solid var(--border); padding-top: 4px;">1 BTC = $<?= number_format($btc_usd_price, 0, '.', ',') ?></div>
    <?php endif; ?>
    </div></div>
    <?php endif; ?>

    <div class="card full-width">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1rem;"><h3><?= $btc_address ? 'Worker Performance' : 'Pool Hashrate History' ?></h3><div class="chart-controls" id="chart-controls"><button class="chart-btn active" data-range="1">24H</button><button class="chart-btn" data-range="7">7D</button><button class="chart-btn" data-range="30">30D</button><button class="chart-btn" data-range="365">1Y</button></div></div>
        <div class="chart-wrapper"><canvas id="hashrateChart"></canvas></div>
    </div>

    <div class="card full-width">
        <?php if ($btc_address): ?> 
            <div style="display:flex; justify-content:space-between;"><h3>User: <span class="font-mono"><?= substr($btc_address, 0, 8) ?>...</span></h3><a href="<?= htmlspecialchars($script_path) ?>" style="color:var(--accent);">Back to Pool</a></div>
            <?php if ($user_summary): render_table($user_summary, ['hashrate1m', 'hashrate5m', 'hashrate1hr', 'hashrate1d', 'hashrate7d', 'shares', 'workers', 'lastshare', 'bestshare'], $friendly_names, $network_difficulty, $previous_network_difficulty, $analytics, $user_workers, null, null, null); else: ?><p class="error">No data found.</p><?php endif; ?>
        <?php else: ?> 
            <h3>Check Your Stats</h3><form action="<?= htmlspecialchars($script_path) ?>" method="get" style="display:flex; margin-top:1rem;"><input type="text" name="btc_address" placeholder="Enter BTC address..." required><input type="submit" value="Search"></form>
            <div style="margin-top:2rem;"><h3 style="margin-bottom:1rem;">Pool Statistics</h3>
            <?php if ($pool_data): ?>
                <?php render_table($pool_data, ['hashrate1m', 'hashrate5m', 'hashrate1hr', 'hashrate1d', 'hashrate7d', 'SPS1m', 'SPS5m', 'SPS15m', 'SPS1h', 'Users', 'Workers', 'accepted', 'rejected', 'rejected_percent', 'bestshare', 'time_to_block', 'runtime'], $friendly_names, $network_difficulty, null, null, null, null, $pool_time_to_block, null); ?>
            <?php else: ?>
                <p>Waiting for parser data...</p>
            <?php endif; ?>
            </div>
        <?php endif; ?>
        <?php if ($last_update): ?> <p class="footer">Last updated: <span class="time-ago" data-timestamp="<?= $last_update ?>">...</span></p> <?php endif; ?>
    </div>
</div>

<div id="modal-backdrop" class="modal-backdrop"><div class="modal-content"><button id="modal-close-btn" class="close-btn">&times;</button><h2 id="modal-title" style="margin-bottom:1rem;">Chart</h2><div style="height:400px; width:100%;"><canvas id="modalChartCanvas"></canvas></div></div></div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        const timeAgo = (timestamp) => { const now = new Date(); const past = new Date(timestamp * 1000); const seconds = Math.floor((now - past) / 1000); if (seconds < 60) return `${seconds}s ago`; const minutes = Math.floor(seconds / 60); if (minutes < 60) return `${minutes}m ago`; const hours = Math.floor(minutes / 60); if (hours < 24) return `${hours}h ago`; const days = Math.floor(hours / 24); return `${days}d ago`; };
        document.querySelectorAll('.time-ago').forEach(el => { const ts = parseInt(el.dataset.timestamp); if(!isNaN(ts)) el.innerText = timeAgo(ts); });

        const themeToggle = document.getElementById('theme-toggle');
        themeToggle.addEventListener('click', () => { const newTheme = document.documentElement.getAttribute('data-theme') === 'light' ? 'dark' : 'light'; document.documentElement.setAttribute('data-theme', newTheme); localStorage.setItem('theme', newTheme); location.reload(); });
        document.addEventListener('click', (e) => { if(e.target.closest('.workers-toggle')) { const row = document.getElementById('worker-list-row'); row.style.display = row.style.display === 'none' ? 'table-row' : 'none'; } });

        const mainCanvas = document.getElementById('hashrateChart');
        const btcAddress = <?= json_encode($btc_address) ?>;
        let mainChart;

        async function loadChart(range, worker = null, canvas = mainCanvas, isModal = false) {
            let url = range == 365 ? '?fetch_daily_chart=true' : '?fetch_chart_data=true';
            url += `&range=${range}`;
            if(btcAddress) url += `&btc_address=${btcAddress}`;
            if(worker) url += `&worker=${worker}`;
            const res = await fetch(url);
            const data = await res.json();
            if(data.error) { console.error("Chart Error:", data.error); return; }

            const datasets = [];
            const colors = ['#238636', '#1f6feb', '#a371f7'];
            let colorIdx = 0;
            for(const k in data) {
                if(data[k].data) {
                    datasets.push({ label: k, data: data[k].labels.map((t, i) => ({x: t*1000, y: data[k].data[i]})), borderColor: colors[colorIdx++], borderWidth: 2, pointRadius: 0, tension: 0.4 });
                }
            }
            const config = { type: 'line', data: { datasets }, options: { responsive: true, maintainAspectRatio: false, scales: { x: { type: 'time', time: { unit: range > 30 ? 'day' : 'hour' }, grid: { color: '#30363d' } }, y: { beginAtZero: true, grid: { color: '#30363d' } } } } };
            if(isModal) { if(window.modalChart) window.modalChart.destroy(); window.modalChart = new Chart(canvas, config); } else { if(mainChart) mainChart.destroy(); mainChart = new Chart(canvas, config); }
        }

        if(mainCanvas) loadChart(1);
        document.getElementById('chart-controls').addEventListener('click', (e) => { if(e.target.tagName === 'BUTTON') { document.querySelectorAll('.chart-btn').forEach(b => b.classList.remove('active')); e.target.classList.add('active'); loadChart(e.target.dataset.range); } });

        document.getElementById('network-status-header')?.addEventListener('click', async () => {
            document.getElementById('modal-backdrop').style.display = 'flex';
            document.getElementById('modal-title').innerText = 'Network History (2 Years)';
            const res = await fetch('?fetch_network_chart=true');
            const d = await res.json();
            const ctx = document.getElementById('modalChartCanvas');
            if(window.modalChart) window.modalChart.destroy();
            window.modalChart = new Chart(ctx, { type: 'line', data: { datasets: [ { label: 'Hashrate', data: d.hashrate.labels.map((t,i)=>({x:t, y:d.hashrate.data[i]})), borderColor: '#238636', yAxisID: 'y' }, { label: 'Difficulty', data: d.difficulty.labels.map((t,i)=>({x:t, y:d.difficulty.data[i]})), borderColor: '#a371f7', yAxisID: 'y1' } ] }, options: { responsive: true, maintainAspectRatio: false, scales: { x: { type: 'time', time: { unit: 'month' } }, y: { position: 'left' }, y1: { position: 'right', grid: { drawOnChartArea: false } } } } });
        });
        document.getElementById('modal-close-btn').addEventListener('click', () => { document.getElementById('modal-backdrop').style.display = 'none'; });
    });
</script>

<?php 
// RENDER TABLE HELPER
function render_table($data, $key_order, $friendly_names, $network_difficulty, $previous_difficulty, $analytics, $workers_data = null, $difficulty_prediction = null, $pool_time_to_block = null, $estimated_adjustment_date = null) { 
    $inactive_workers_html = ''; $has_inactive_workers = false; 
    echo '<table><tbody>'; 
    foreach ($key_order as $key) { 
        if ($key === 'workers' && isset($data[$key]) && $data[$key] > 0) { 
            echo '<tr class="workers-toggle" id="workers-toggle" title="Click to expand/collapse"><td class="key"><span>' . ($friendly_names[$key] ?? 'Workers') . '</span> <svg class="chevron" width="16" height="16" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M4 6L8 10L12 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg></td><td class="font-mono">' . htmlspecialchars(format_number_auto($data[$key])) . '</td></tr>'; 
            if ($workers_data) { 
                $active_workers_html = ''; $inactive_workers_table = '<table><thead><tr><th class="key">Name</th><th>Hashrate (5 min)</th><th>Shares</th></tr></thead><tbody>'; 
                foreach ($workers_data as $name => $stats) { 
                    $hashrate5m = $stats['hashrate5m'] ?? '0'; 
                    if (parse_hashrate_to_ghs($hashrate5m) > 0) { $active_workers_html .= '<tr><td class="key">' . htmlspecialchars($name) . '</td><td class="font-mono">' . htmlspecialchars(format_hashrate($hashrate5m)) . '</td><td class="font-mono">' . htmlspecialchars(format_number_auto($stats['shares'] ?? 0)) . '</td><td><button class="worker-chart-btn" data-worker="' . htmlspecialchars($name) . '">Show Chart</button></td></tr>'; } 
                    else { $inactive_workers_table .= '<tr><td class="key">' . htmlspecialchars($name) . '</td><td class="font-mono">' . htmlspecialchars(format_hashrate($hashrate5m)) . '</td><td class="font-mono">' . htmlspecialchars(format_number_auto($stats['shares'] ?? 0)) . '</td></tr>'; $has_inactive_workers = true; } 
                } 
                $inactive_workers_table .= '</tbody></table>'; 
                echo '<tr class="worker-list-row" id="worker-list-row"><td colspan="2"><div class="worker-list-content"><div class="worker-list-header"><h3>Active Workers</h3></div><table><thead><tr><th class="key">Name</th><th>Hashrate (5 min)</th><th>Shares</th><th>Chart</th></tr></thead><tbody>' . $active_workers_html; 
                if ($has_inactive_workers) { echo '<tr><td colspan="4" style="text-align: center; padding-top: 1em;"><button class="show-inactive-btn" id="show-inactive-btn">Show Inactive Workers</button></td></tr>'; } 
                echo '</tbody></table></div></td></tr>'; 
            } continue; 
        } 
        if ($key === 'rejected_percent') { 
            if (isset($data['accepted'], $data['rejected']) && ($data['accepted'] + $data['rejected']) > 0) { 
                $total = $data['accepted'] + $data['rejected']; $percent = ($data['rejected'] / $total) * 100; 
                $label = $friendly_names[$key] ?? 'Rejected %'; $value = format_number_auto($percent, 2) . ' %'; 
                echo '<tr><td class="key">' . htmlspecialchars($label) . '</td><td class="font-mono">' . htmlspecialchars($value) . '</td></tr>'; 
            } continue; 
        } 
        if ($key === 'time_to_block' && $pool_time_to_block) { echo '<tr><td class="key">' . ($friendly_names[$key] ?? 'Est. Time/Block') . '</td><td class="font-mono">' . htmlspecialchars(format_long_time($pool_time_to_block)) . '</td></tr>'; continue; } 
        if (isset($data[$key])) { 
            $label = $friendly_names[$key] ?? ucfirst($key); $value = $data[$key]; 
            echo '<tr><td class="key">' . htmlspecialchars($label) . '</td><td class="font-mono">'; 
            if ($key === 'bestshare' && $workers_data !== null && $network_difficulty !== null && $network_difficulty > 0) { 
                $percentage = ($value / $network_difficulty) * 100; $percentage_capped = min(100, $percentage); 
                $diff_change_html = ''; 
                if ($previous_difficulty !== null && $previous_difficulty > 0) { $change = (($network_difficulty - $previous_difficulty) / $previous_difficulty) * 100; $class = $change >= 0 ? 'diff-up' : 'diff-down'; $sign = $change >= 0 ? '+' : ''; $diff_change_html = ' <span class="diff-change ' . $class . '">(' . $sign . number_format($change, 2) . '%)</span>'; } 
                echo '<div class="progress-container"><span class="progress-text">' . htmlspecialchars(format_number_auto($value)) . ' (' . number_format($percentage, 4) . '%)</span><div class="progress-bar"><div class="progress-fill" style="width: ' . $percentage_capped . '%;"></div></div><div class="difficulty-info">Network Difficulty: ' . htmlspecialchars(format_number_auto($network_difficulty)) . $diff_change_html . '</div>'; 
                if ($difficulty_prediction) { echo '<div class="prediction-info">Next adjustment progress: <strong>' . ($difficulty_prediction['progress'] ?? 'N/A') . '%</strong>.<br>'; if (isset($difficulty_prediction['prediction'])) { $pred_val = $difficulty_prediction['prediction']; $pred_class = $pred_val >= 0 ? 'diff-up' : 'diff-down'; $pred_sign = $pred_val >= 0 ? '+' : ''; echo 'Estimated change: <strong class="' . $pred_class . '">' . $pred_sign . $pred_val . '%</strong>'; } if ($estimated_adjustment_date) { echo ' (Est. <strong class="font-mono">' . $estimated_adjustment_date . '</strong>)'; } echo '</div>'; } 
                if ($analytics) { echo '<div class="probability-info">Based on your 1h hashrate:<br>Avg. time to find a block: <strong>' . format_long_time($analytics['time_to_find']) . '</strong><br>Est. probability: <strong>' . number_format($analytics['prob_month'], 6) . '%</strong>/month, <strong>' . number_format($analytics['prob_year'], 4) . '%</strong>/year.</div>'; } 
                echo '</div>'; 
            } elseif ($key === 'bestshare') { echo htmlspecialchars(format_share($value)); if ($network_difficulty !== null && $network_difficulty > 0) { $percent = ($value / $network_difficulty) * 100; echo ' <span class="full-date">(' . number_format($percent, 4) . '%)</span>'; } } 
            elseif (strpos($key, 'hashrate') === 0) { echo htmlspecialchars(format_hashrate($value)); } 
            elseif (strpos($key, 'SPS') === 0) { echo htmlspecialchars(format_number_auto((float)$value, 3)); } 
            else { 
                if ($key === 'lastshare') { echo '<div class="time-ago" data-timestamp="' . htmlspecialchars($value) . '">...</div>'; } 
                elseif ($key === 'runtime') { echo htmlspecialchars(format_seconds($value)); } 
                else { 
                    if(($key === 'accepted' || $key === 'rejected') && is_numeric($value)) { echo htmlspecialchars(format_metric_pl($value)); } 
                    else { echo htmlspecialchars(is_array($value) ? json_encode($value) : $value); } 
                } 
            } 
            echo '</td></tr>'; 
        } 
    } 
    echo '</tbody></table>'; 
    if (isset($has_inactive_workers) && $has_inactive_workers) { echo '<div id="inactive-workers-data" style="display:none;">' . $inactive_workers_table . '</div>'; } 
}
?>
</body>
</html>