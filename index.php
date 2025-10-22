<?php
define('AGGREGATE_WORKER_NAME', '_AGGREGATE_');
$dataDir = __DIR__ . '/data';
$statsDbPath = $dataDir . '/stats.db';
$networkDbPath = $dataDir . '/network.db';

// --- API SECTION ---
if (isset($_GET['fetch_chart_data']) || isset($_GET['fetch_network_chart'])) { header('Content-Type: application/json'); try { $datasets = []; if (isset($_GET['fetch_network_chart'])) { if (!file_exists($networkDbPath)) { throw new Exception("Network database file not found."); } $pdo = new PDO('sqlite:' . $networkDbPath); $since = time() - (30 * 86400); $stmt = $pdo->prepare("SELECT timestamp, network_hashrate_ghs, network_difficulty FROM network_history WHERE timestamp > :since ORDER BY timestamp ASC"); $stmt->execute([':since' => $since]); $results = $stmt->fetchAll(PDO::FETCH_ASSOC); $datasets['hashrate'] = ['labels' => [], 'data' => []]; $datasets['difficulty'] = ['labels' => [], 'data' => []]; foreach ($results as $row) { $ts = $row['timestamp'] * 1000; $datasets['hashrate']['labels'][] = $ts; $datasets['hashrate']['data'][] = round($row['network_hashrate_ghs'], 2); $datasets['difficulty']['labels'][] = $ts; $datasets['difficulty']['data'][] = round($row['network_difficulty'], 2); } } else { if (!file_exists($statsDbPath)) { throw new Exception("Stats database file not found."); } $pdo = new PDO('sqlite:' . $statsDbPath); $btc_address = isset($_GET['btc_address']) ? trim(htmlspecialchars($_GET['btc_address'])) : null; $worker_name = isset($_GET['worker']) ? trim(htmlspecialchars($_GET['worker'])) : null; $range_days = isset($_GET['range']) ? (int)$_GET['range'] : 1; $since = time() - ($range_days * 86400); $interval = 300; if ($range_days > 20) $interval = 21600; elseif ($range_days > 3) $interval = 3600; elseif ($range_days > 1) $interval = 900; $table = $btc_address ? 'hashrate_history' : 'pool_history'; $params = [':interval' => $interval, ':since' => $since]; $where_clause = "WHERE timestamp > :since "; if ($btc_address) { $where_clause .= "AND btc_address = :btc_address AND worker_name = :worker_name "; $params[':btc_address'] = $btc_address; $params[':worker_name'] = $worker_name ?: AGGREGATE_WORKER_NAME; } $query_base = "SELECT (timestamp / :interval) * :interval AS time_bucket, %s FROM {$table} {$where_clause} GROUP BY time_bucket ORDER BY time_bucket ASC"; $series_map = [ 1 => ['5m' => 'hashrate_5m_ghs', '1h' => 'hashrate_1h_ghs'], 7 => ['1h' => 'hashrate_1h_ghs', '1d' => 'hashrate_24h_ghs'], 30 => ['1d' => 'hashrate_24h_ghs'], ]; $series_to_fetch = $series_map[$range_days] ?? $series_map[30]; $sql_selects = []; foreach ($series_to_fetch as $key => $column) { $sql_selects[] = "AVG({$column}) AS avg_{$key}"; } $stmt = $pdo->prepare(sprintf($query_base, implode(', ', $sql_selects))); $stmt->execute($params); $results = $stmt->fetchAll(PDO::FETCH_ASSOC); foreach ($series_to_fetch as $key => $column) { $datasets[$key] = [ 'labels' => array_column($results, 'time_bucket'), 'data' => array_column($results, "avg_{$key}"), ]; } } } catch (Exception $e) { $datasets = ['error' => $e->getMessage()]; } echo json_encode($datasets); exit(); }

// --- DATA FETCHING FROM SQLITE ---
$pool_data = []; $user_summary = null; $user_workers = null; $last_update = null;
$network_difficulty = null; $previous_network_difficulty = null; $network_hashrate = null;
$last_block_reward_btc = null; 
$last_fetched_block_height = null; 
$btc_usd_price = null; // Zostanie pobrane z pool_stats
$difficulty_prediction = null; $network_hashrate_change = null; $error = null;
$btc_address = isset($_GET['btc_address']) ? trim(htmlspecialchars($_GET['btc_address'])) : null;
$user_data_full = null;

try {
    if (!file_exists($statsDbPath)) { throw new Exception("Stats database file not found. Please run parser.php script."); }
    $pdo_stats = new PDO('sqlite:' . $statsDbPath);
    $pdo_stats->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); 
    $pool_row = $pdo_stats->query("SELECT data, last_update FROM pool_stats WHERE id = 1 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $pool_data = $pool_row ? json_decode($pool_row['data'], true) : [];
    $last_update = $pool_row['last_update'] ?? null;
    // POPRAWKA: Pobierz wszystkie dane "na żywo" z stats.db
    $last_fetched_block_height = $pool_data['last_fetched_block_height'] ?? null; 
    $last_block_reward_btc = $pool_data['last_block_reward_btc'] ?? null;
    $btc_usd_price = $pool_data['btc_usd_price'] ?? null; // Cena jest tutaj
    
    if ($btc_address) {
        $user_stmt = $pdo_stats->prepare("SELECT data FROM user_stats WHERE btc_address = ?");
        $user_stmt->execute([$btc_address]);
        $user_row = $user_stmt->fetch(PDO::FETCH_ASSOC);
        $user_data_full = $user_row ? json_decode($user_row['data'], true) : null;
    }
    
    if (file_exists($networkDbPath)) {
        $pdo_net = new PDO('sqlite:' . $networkDbPath);
        $pdo_net->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); 
        $network_row = $pdo_net->query("SELECT data FROM network_stats WHERE id = 1 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        $network_data = $network_row ? json_decode($network_row['data'], true) : [];
        $network_difficulty = $network_data['network_difficulty'] ?? null;
        $previous_network_difficulty = $network_data['previous_network_difficulty'] ?? null;
        $network_hashrate = $network_data['network_hashrate'] ?? null;
        // Usunięto pobieranie ceny stąd

        $prediction_result = $pdo_net->query("SELECT * FROM prediction_data LIMIT 1");
        $difficulty_prediction = $prediction_result ? $prediction_result->fetch(PDO::FETCH_ASSOC) : false;

        $history_result = $pdo_net->query("SELECT network_hashrate_ghs FROM network_history WHERE timestamp >= " . (time() - 90000) . " ORDER BY timestamp ASC LIMIT 1");
        if($history_result) { $old_hashrate = $history_result->fetchColumn(); if ($old_hashrate && $network_hashrate && $old_hashrate > 0) { $network_hashrate_change = (($network_hashrate - $old_hashrate) / $old_hashrate) * 100; } }
    } else { $network_difficulty = null; $previous_network_difficulty = null; $network_hashrate = null; $difficulty_prediction = null; $error = $error ? $error . " Network DB not found." : "Network DB not found."; }

} catch (Exception $e) { 
    $error = "Database Error: " . $e->getMessage(); 
    $pool_data = []; $user_summary = null; $user_workers = null; $last_update = null; $network_difficulty = null; $previous_network_difficulty = null; $network_hashrate = null; $last_block_reward_btc = null; $last_fetched_block_height = null; $btc_usd_price = null; $difficulty_prediction = null; $network_hashrate_change = null; $user_data_full = null; 
}

// --- HELPER FUNCTIONS & VARIABLE INIT ---
function format_seconds($seconds) { if ($seconds === null || $seconds < 1) return '0s'; $parts = []; $days = floor($seconds / 86400); if ($days > 0) $parts[] = $days . 'd'; $hours = floor(($seconds % 86400) / 3600); if ($hours > 0) $parts[] = $hours . 'h'; $minutes = floor(($seconds % 3600) / 60); if ($minutes > 0) $parts[] = $minutes . 'm'; $secs = $seconds % 60; if ($secs > 0 || empty($parts)) $parts[] = $secs . 's'; return implode(' ', $parts); } function format_number_auto($number, $decimals = 2) { if ($number === null) return 'N/A'; if ($number == floor($number)) { return number_format($number, 0); } return number_format($number, $decimals); } function format_hashrate($hashrateInput) { if ($hashrateInput === null) return 'N/A'; if (is_numeric($hashrateInput)) { $ghs = (float)$hashrateInput; } else { $value = (float)$hashrateInput; preg_match('/[a-zA-Z]/', $hashrateInput, $matches); $unit = $matches[0] ?? 'G'; $ghs = 0; switch (strtoupper($unit)) { case 'K': $ghs = $value / 1000000; break; case 'M': $ghs = $value / 1000; break; case 'G': $ghs = $value; break; case 'T': $ghs = $value * 1000; break; case 'P': $ghs = $value * 1000 * 1000; break; case 'E': $ghs = $value * 1000 * 1000 * 1000; break; default:  $ghs = $value; } } if ($ghs >= 1000000000000) { return format_number_auto($ghs / 1000000000000) . ' ZH/s'; } elseif ($ghs >= 1000000000) { return format_number_auto($ghs / 1000000000) . ' EH/s'; } elseif ($ghs >= 1000000) { return format_number_auto($ghs / 1000000) . ' PH/s'; } elseif ($ghs >= 1000) { return format_number_auto($ghs / 1000) . ' TH/s'; } else { return format_number_auto($ghs) . ' GH/s'; } } function parse_hashrate_to_ghs(string $hashrateStr): float { $value = (float)$hashrateStr; $unit = strtoupper(substr(trim($hashrateStr), -1)); switch ($unit) { case 'K': return $value / 1000000; case 'M': return $value / 1000; case 'G': return $value; case 'T': return $value * 1000; case 'P': return $value * 1000 * 1000; default: return $value; } } function calculate_block_probability($user_hashrate_ghs, $network_hashrate_ghs, $days) { if ($user_hashrate_ghs <= 0 || $network_hashrate_ghs <= 0) { return 0; } $blocks_in_period = $days * 144; $p_user = $user_hashrate_ghs / $network_hashrate_ghs; $p_not_finding = pow(1 - $p_user, $blocks_in_period); return (1 - $p_not_finding) * 100; } function calculate_time_to_find_block($user_hashrate_ghs, $network_hashrate_ghs) { if ($user_hashrate_ghs <= 0 || $network_hashrate_ghs <= 0) { return 0; } return 600 / ($user_hashrate_ghs / $network_hashrate_ghs); } function format_long_time($seconds) { if ($seconds === null || $seconds <= 0) return "N/A"; $minutes = $seconds / 60; $hours = $minutes / 60; $days = $hours / 24; $months = $days / 30.44; $years = $days / 365.25; if ($years > 1) return format_number_auto($years) . " years"; if ($months > 1) return format_number_auto($months) . " months"; if ($days > 1) return format_number_auto($days) . " days"; return format_number_auto($hours) . " hours"; } function format_share($num) { if ($num === null) return 'N/A'; if (!is_numeric($num) || $num < 1000000) return number_format($num); $units = ['K', 'M', 'G', 'T']; $power = floor(log($num, 1000)); return format_number_auto($num / pow(1000, $power), 2) . $units[$power - 1]; }
$friendly_names = [ 'hashrate1m' => 'Hashrate (1m)', 'hashrate5m' => 'Hashrate (5m)', 'hashrate1hr' => 'Hashrate (1h)', 'hashrate1d' => 'Hashrate (1d)', 'hashrate7d' => 'Hashrate (7d)', 'shares' => 'Shares', 'workers' => 'Workers', 'lastshare' => 'Last Share', 'bestshare' => 'Best Share', 'runtime' => 'Uptime', 'Users' => 'Users', 'Workers' => 'Workers', 'accepted' => 'Accepted', 'rejected' => 'Rejected', 'rejected_percent' => 'Rejected %', 'time_to_block' => 'Est. Time/Block' ]; $script_path = '.'; $user_summary = $user_data_full['summary'] ?? null; $user_workers = $user_data_full['workers'] ?? null; if (empty($network_hashrate) && !empty($network_difficulty)) { $network_hashrate = $network_difficulty * pow(2, 32) / 600 / 1e9; } $analytics = null; if ($user_summary && $network_hashrate) { $user_hashrate_str = $user_summary['hashrate1hr'] ?? '0'; $user_hashrate_ghs = parse_hashrate_to_ghs($user_hashrate_str); $analytics = [ 'prob_month' => calculate_block_probability($user_hashrate_ghs, $network_hashrate, 30.44), 'prob_year' => calculate_block_probability($user_hashrate_ghs, $network_hashrate, 365.25), 'time_to_find' => calculate_time_to_find_block($user_hashrate_ghs, $network_hashrate) ]; } $pool_time_to_block = null; if ($pool_data && $network_hashrate) { $pool_hashrate_str = $pool_data['hashrate1hr'] ?? '0'; $pool_hashrate_ghs = parse_hashrate_to_ghs($pool_hashrate_str); $pool_time_to_block = calculate_time_to_find_block($pool_hashrate_ghs, $network_hashrate); } $estimated_adjustment_date = null; if ($difficulty_prediction && isset($difficulty_prediction['progress'], $difficulty_prediction['avg_time']) && $difficulty_prediction['avg_time'] > 0) { $blocks_remaining = 2016 * (1 - ($difficulty_prediction['progress'] / 100)); $seconds_remaining = $blocks_remaining * $difficulty_prediction['avg_time']; $estimated_timestamp = time() + $seconds_remaining; $estimated_adjustment_date = date('Y-m-d H:i', $estimated_timestamp); } $pool_title = "srv.88x.pl - solo mining pool stats"; $pool_subtitle = "Bitcoin Mining pool based on CKPool - 0% Fee"; $current_pool_hashrate_1h = $pool_data['hashrate1hr'] ?? '0'; $current_pool_users = $pool_data['Users'] ?? '0'; $current_pool_workers = $pool_data['Workers'] ?? '0';

$last_block_reward_usd = null;
if ($last_block_reward_btc !== null && $btc_usd_price !== null) {
    $last_block_reward_usd = $last_block_reward_btc * $btc_usd_price;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"> <title>CKPool Stats<?php if ($btc_address): ?> - <?= htmlspecialchars(substr($btc_address, 0, 12)) ?>...<?php endif; ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com"> <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin> 
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script> <script src="https://cdn.jsdelivr.net/npm/chartjs-adapter-date-fns@3.0.0/dist/chartjs-adapter-date-fns.bundle.min.js"></script>
    <script> (function() { const getTheme = () => localStorage.getItem('theme') || (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light'); document.documentElement.setAttribute('data-theme', getTheme()); })(); </script>
    <style> 
         :root { 
            --font-sans: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; 
            --font-mono: 'JetBrains Mono', monospace; 
            --accent-color: #ff0000; --accent-color-dark: #cc0000; --chart-color-1: #ff4444; --chart-color-2: #ffaa00; --chart-color-3: #ffcc00; --background-light: #f0f4f8; --foreground-light: #0f172a; --card-background-light: rgba(255, 255, 255, 0.7); --border-light: rgba(0, 0, 0, 0.08); --input-light: rgba(255, 255, 255, 0.5); --muted-light: #475569; --gradient-start-light: #e0f2fe; --gradient-end-light: #ffffff; --accent-light: var(--accent-color-dark); --accent-hover-light: var(--accent-color); --accent-foreground-light: #ffffff; --text-shadow-light: none; --text-shadow-header-light: none; --background-dark: #000000; --foreground-dark: #f8f8f8; --card-background-dark: rgba(25, 0, 0, 0.7); --border-dark: rgba(255, 0, 0, 0.2); --input-dark: rgba(45, 0, 0, 0.6); --muted-dark: #ffaaaa; --gradient-start-dark: #300000; --gradient-end-dark: #000000; --accent-dark: var(--accent-color); --accent-hover-dark: var(--accent-color-dark); --accent-foreground-dark: #ffffff; --text-shadow-dark: 0 0 1px rgba(255, 0, 0, 0.1); --text-shadow-header-dark: 0 0 3px rgba(255, 0, 0, 0.15); --diff-up: #4ade80; --diff-down: #ff4444; 
        } 
        [data-theme='light'] { --background: var(--background-light); --foreground: var(--foreground-light); --card-background: var(--card-background-light); --border: var(--border-light); --input: var(--input-light); --muted-foreground: var(--muted-light); --accent: var(--accent-light); --accent-hover: var(--accent-hover-light); --accent-foreground: var(--accent-foreground-light); --gradient-start: var(--gradient-start-light); --gradient-end: var(--gradient-end-light); --text-shadow: var(--text-shadow-light); --text-shadow-header: var(--text-shadow-header-light); } 
        [data-theme='dark'] { --background: var(--background-dark); --foreground: var(--foreground-dark); --card-background: var(--card-background-dark); --border: var(--border-dark); --input: var(--input-dark); --muted-foreground: var(--muted-dark); --accent: var(--accent-dark); --accent-hover: var(--accent-hover-dark); --accent-foreground: var(--accent-foreground-dark); --gradient-start: var(--gradient-start-dark); --gradient-end: var(--gradient-end-dark); --text-shadow: var(--text-shadow-dark); --text-shadow-header: var(--text-shadow-header-dark); } 
        @keyframes gradientBG { 0% { background-position: 0% 50%; } 50% { background-position: 100% 50%; } 100% { background-position: 0% 50%; } } 
        * { box-sizing: border-box; margin: 0; padding: 0; } 
        body { font-family: var(--font-sans); background-color: var(--background); color: var(--foreground); min-height: 100vh; display: flex; flex-direction: column; transition: background-color 0.3s, color 0.3s; position: relative; padding-top: 2rem; text-shadow: var(--text-shadow); font-size: 16px; line-height: 1.6; } 
        body::before { content: ''; position: fixed; top: -50%; left: -50%; width: 200%; height: 200%; background: linear-gradient(135deg, var(--gradient-start), var(--background), var(--gradient-end)); background-size: 300% 300%; animation: gradientBG 20s ease infinite; z-index: -1; transition: background 0.5s; opacity: 0.7; }
        .container { width: 90%; max-width: 950px; margin: 0 auto 2rem auto; background-color: var(--card-background); border: 1px solid var(--border); padding: 2.5em; border-radius: 16px; backdrop-filter: blur(10px); -webkit-backdrop-filter: blur(10px); box-shadow: 0 8px 32px rgba(0, 0, 0, 0.2); transition: background-color 0.3s, border-color 0.3s; flex-grow: 1; position: relative; z-index: 1; } 
        h1, h2 { color: var(--foreground); border-bottom: 1px solid var(--border); padding-bottom: 0.5em; font-weight: 700; } 
        h1 { font-size: 1.8em; } 
        h2 { font-size: 1.3em; margin-top: 2.8em; text-align: center; } 
        h3 { margin-top: 0; font-size: 1.15em; color: var(--foreground); font-weight: 600; } 
        [data-theme='dark'] h1, [data-theme='dark'] h2, [data-theme='dark'] h3 { text-shadow: var(--text-shadow-header); } 
        form { margin: 1.5em auto 2.5em auto; display: flex; max-width: 600px; } 
        input[type="text"] { flex-grow: 1; padding: 12px 15px; font-size: 1em; font-family: var(--font-sans); border: 1px solid var(--border); border-radius: 8px 0 0 8px; background-color: var(--input); color: var(--foreground); transition: border-color 0.2s, box-shadow 0.2s; } input[type="text"]:focus { outline: none; border-color: var(--accent); box-shadow: 0 0 0 4px color-mix(in srgb, var(--accent) 25%, transparent); } 
        input[type="submit"] { padding: 12px 24px; font-size: 1em; font-weight: 600; background-color: var(--accent); color: var(--accent-foreground); border: 1px solid var(--accent); border-left: 0; border-radius: 0 8px 8px 0; cursor: pointer; transition: background-color 0.2s; font-family: var(--font-sans); } input[type="submit"]:hover { background-color: var(--accent-hover); border-color: var(--accent-hover); } 
        table { width: 100%; border-collapse: collapse; margin-top: 1em; font-size: 0.9em; } 
        .container > table { max-width: 650px; margin-left: auto; margin-right: auto; } 
        th, td { padding: 10px 15px; border-bottom: 1px solid var(--border); text-align: left; vertical-align: middle; } 
        tr:last-child td { border-bottom: none; } 
        td:not(.key) { font-family: var(--font-mono); font-size: 0.95em; } 
        .key { font-weight: 500; color: var(--muted-foreground); width: 40%; font-family: var(--font-sans); font-size: 1em; } 
        a { color: var(--accent); text-decoration: none; font-weight: 500; } a:hover { text-decoration: underline; } 
        .footer { text-align: center; margin-top: 2.5em; font-size: 0.85em; color: var(--muted-foreground); } 
        .error { color: var(--diff-down); background-color: color-mix(in srgb, var(--diff-down) 15%, transparent); padding: 1em; border: 1px solid var(--diff-down); border-radius: 8px; margin-top: 1.5em; } 
        .progress-container { display: flex; flex-direction: column; gap: 4px; } .progress-bar { width: 100%; background-color: color-mix(in srgb, var(--border) 50%, transparent); border-radius: 4px; overflow: hidden; height: 8px; } .progress-fill { height: 100%; background-color: var(--accent); width: 0%; border-radius: 4px; transition: width 0.5s ease-in-out; } .progress-text { font-size: 0.9em; color: var(--muted-foreground); font-family: var(--font-mono); } 
        .difficulty-info, .probability-info, .prediction-info { font-size: 0.8em; color: var(--muted-foreground); margin-top: 10px; padding-top: 10px; border-top: 1px solid var(--border); } .probability-info strong { font-family: var(--font-mono);}
        .pool-header { text-align: center; padding: 2rem 1rem 1.5rem; margin: 0 auto 2rem auto; background: var(--input); color: var(--foreground); border: 1px solid var(--border); box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1); position: relative; border-radius: 12px; max-width: 950px; width: 90%; z-index: 1; } 
        .pool-header h1 { font-size: 2em; margin-bottom: 0.1em; font-weight: 700; color: var(--foreground); border-bottom: none; padding-bottom: 0; } 
        .pool-header p.subtitle { font-size: 1em; opacity: 0.8; margin-bottom: 1em; color: var(--muted-foreground); } 
        .pool-header .pool-metrics { display: flex; justify-content: center; gap: 1rem; flex-wrap: wrap; margin-top: 1rem; } 
        .pool-header .metric-item { background-color: var(--card-background); padding: 0.5rem 1rem; border-radius: 6px; font-size: 0.95em; font-weight: 500; color: var(--foreground); display: flex; align-items: center; gap: 0.5rem; border: 1px solid var(--border); } 
        .pool-header .metric-item strong { font-size: 1em; color: var(--foreground); font-family: var(--font-mono); } [data-theme='dark'] .pool-header .metric-item strong { text-shadow: none; } 
        .theme-toggle { position: fixed; top: 1rem; right: 1rem; z-index: 1000; width: 40px; height: 40px; padding: 0; border-radius: 50%; display: flex; align-items: center; justify-content: center; background-color: var(--card-background); border: 1px solid var(--border); box-shadow: 0 2px 5px rgba(0,0,0,0.1); cursor: pointer; overflow: hidden; transition: background-color 0.3s, border-color 0.3s, box-shadow 0.3s; } 
        .theme-toggle:hover { background-color: var(--input); } 
        .theme-toggle svg { position: absolute; width: 20px; height: 20px; transition: transform 0.3s ease-out, opacity 0.2s; color: var(--muted-foreground); } 
        html[data-theme='light'] .theme-toggle .sun { transform: translateY(0) scale(1); opacity: 1; } 
        html[data-theme='light'] .theme-toggle .moon { transform: translateY(150%) scale(0.5); opacity: 0; } 
        html[data-theme='dark'] .theme-toggle .sun { transform: translateY(-150%) scale(0.5); opacity: 0; } 
        html[data-theme='dark'] .theme-toggle .moon { transform: translateY(0) scale(1); opacity: 1; } 
        #chart-container { background-color: var(--input); border-radius: 12px; padding: 1.5em; border: 1px solid var(--border); margin-top: 1em; } 
        .chart-controls { display: flex; gap: 0.5rem; margin-top: 2em; justify-content: center; } 
        .chart-controls button { font-family: var(--font-sans); font-size: 0.85em; font-weight: 600; padding: 0.5em 1em; border-radius: 6px; background-color: transparent; border: 1px solid var(--border); color: var(--muted-foreground); cursor: pointer; transition: background-color 0.2s, color 0.2s; } .chart-controls button.active { background-color: var(--accent); color: var(--accent-foreground); border-color: var(--accent); } 
        .connection-info-card { background-color: var(--input); border: 1px solid var(--border); border-radius: 8px; padding: 1.5rem; margin: 2.5em auto; font-size: 0.95em; max-width: 600px; } 
        .connection-info-card h2 { text-align: left; margin-top: 0; font-size: 1.1em; border-bottom: none; padding-bottom: 0; margin-bottom: 1em; font-weight: 600; } 
        .connection-info-card ul { list-style: none; padding-left: 0; text-align: left; } .connection-info-card li { margin-bottom: 0.5em; }
        .connection-info-card code { background-color: color-mix(in srgb, var(--accent) 20%, transparent); color: var(--accent); padding: 0.2em 0.4em; border-radius: 4px; font-family: var(--font-mono); } .full-date { font-size: 0.85em; opacity: 0.7; margin-left: 0.5em; font-family: var(--font-mono); } 
        .workers-toggle { cursor: pointer; } .workers-toggle .key { display: flex; align-items: center; justify-content: space-between; } .workers-toggle .chevron { transition: transform 0.2s ease-in-out; } .workers-toggle.open .chevron { transform: rotate(180deg); } .worker-list-row { display: none; } .worker-list-row > td { padding: 0 !important; border-top: 1px solid var(--border); } .worker-list-content { padding: 1.5em; } .worker-list-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1em; } .worker-list-content table { margin-top: 0; } 
        .show-inactive-btn { font-size: 0.8em; background: none; border: none; cursor: pointer; text-decoration: underline; color: var(--diff-down); opacity: 0.8; font-family: var(--font-sans);} .show-inactive-btn:hover { opacity: 1; } 
        .worker-chart-btn { background: none; border: 1px solid var(--border); color: var(--muted-foreground); padding: 4px 8px; font-size: 0.8em; border-radius: 6px; cursor: pointer; font-family: var(--font-sans);} .worker-chart-btn:hover { background-color: var(--card-background); } 
        .modal-backdrop { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background-color: rgba(0, 0, 0, 0.7); display: none; align-items: center; justify-content: center; z-index: 1000; backdrop-filter: blur(8px); -webkit-backdrop-filter: blur(8px); } .modal-content { background-color: var(--card-background); padding: 2rem; border-radius: 12px; max-width: 90%; width: 800px; position: relative; border: 1px solid var(--border); backdrop-filter: blur(10px); -webkit-backdrop-filter: blur(10px); } .modal-close-btn { position: absolute; top: 0.5rem; right: 0.75rem; background: none; border: none; font-size: 2rem; color: var(--muted-foreground); cursor: pointer; line-height: 1; } .modal-header { display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--border); padding-bottom: 1rem; margin-bottom: 1rem; } .modal-title { font-size: 1.25em; font-weight: 600; color: var(--foreground); } 
        .diff-change { font-size: 0.9em; margin-left: 0.5em; font-weight: 600; font-family: var(--font-mono); } 
        .diff-up { color: var(--diff-up); } .diff-down { color: var(--diff-down); } .clickable-header { cursor: pointer; }
    </style>
</head>
<body>
    <button class="theme-toggle" id="theme-toggle" title="Toggle theme"> 
        <svg class="sun" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="5"></circle><line x1="12" y1="1" x2="12" y2="3"></line><line x1="12" y1="21" x2="12" y2="23"></line><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"></line><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"></line><line x1="1" y1="12" x2="3" y2="12"></line><line x1="21" y1="12" x2="23" y2="12"></line><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"></line><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"></line></svg> 
        <svg class="moon" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"></path></svg> 
    </button>
    
    <?php if (!$btc_address): ?> <div class="pool-header"> <h1><?= htmlspecialchars($pool_title) ?></h1> <?php if ($pool_subtitle): ?> <p class="subtitle"><?= htmlspecialchars($pool_subtitle) ?></p> <?php endif; ?> <div class="pool-metrics"> <div class="metric-item">Pool Hashrate (1h): <strong class="font-mono"><?= htmlspecialchars(format_hashrate($current_pool_hashrate_1h)) ?></strong></div> <div class="metric-item">Active Users: <strong class="font-mono"><?= htmlspecialchars(format_number_auto($current_pool_users)) ?></strong></div> <div class="metric-item">Total Workers: <strong class="font-mono"><?= htmlspecialchars(format_number_auto($current_pool_workers)) ?></strong></div> </div> </div> <?php endif; ?>

    <div class="container">
        <?php if ($btc_address): ?> <div class="header-controls"> <div> <p style="margin-bottom: 1.5em;"><a href="<?= htmlspecialchars($script_path) ?>">&larr; Back to Pool Overview</a></p> <h1>User Statistics</h1> <p style="word-wrap: break-word; font-family: var(--font-mono); color: var(--muted-foreground);"><?= htmlspecialchars($btc_address) ?></p> </div> </div> <?php else: ?> <form action="<?= htmlspecialchars($script_path) ?>" method="get"> <input type="text" name="btc_address" placeholder="Enter BTC address..." required><input type="submit" value="Search"> </form> <div class="connection-info-card"> <h2>Connection Details</h2> <ul style="list-style: none; padding-left: 0;"> <li><strong>Stratum URL:</strong> <code>stratum+tcp://srv.88x.pl:3333</code></li> <li><strong>Username:</strong> <code>YOUR_BTC_ADDRESS</code></li> <li><strong>Password:</strong> <code>x</code></li> <li><strong>Pool Fee:</strong> <strong>0%</strong></li> </ul> </div> <?php endif; ?>
        
        <?php if ($error): ?><p class="error"><?= htmlspecialchars($error) ?></p><?php endif; ?>
        <?php if ($btc_address && !$user_summary && !$error): ?><p class="error">No data found for this BTC address.</p><?php endif; ?>
        
        <?php if (!$btc_address && (isset($pool_data) || isset($network_data))): ?>
            <h2 class="clickable-header" id="network-status-header" title="Click to see 30-day history chart">Network Status</h2>
            <table><tbody>
                <?php if ($network_hashrate !== null): ?><tr><td class="key">Network Hashrate</td><td class="font-mono"><?= htmlspecialchars(format_hashrate($network_hashrate)) ?><?php if ($network_hashrate_change !== null): $class = $network_hashrate_change >= 0 ? 'diff-up' : 'diff-down'; $sign = $network_hashrate_change >= 0 ? '+' : ''; echo ' <span class="diff-change ' . $class . '">(24h: ' . $sign . number_format($network_hashrate_change, 2) . '%)</span>'; endif; ?></td></tr><?php endif; ?>
                <?php if ($network_difficulty !== null): ?><tr><td class="key">Current Difficulty</td><td class="font-mono"><?= htmlspecialchars(format_number_auto($network_difficulty)) ?><?php if ($previous_network_difficulty !== null && $previous_network_difficulty > 0): $change = (($network_difficulty - $previous_network_difficulty) / $previous_network_difficulty) * 100; $class = $change >= 0 ? 'diff-up' : 'diff-down'; $sign = $change >= 0 ? '+' : ''; echo ' <span class="diff-change ' . $class . '">(' . $sign . number_format($change, 2) . '%)</span>'; endif; ?></td></tr><?php endif; ?>
                
                <?php if ($last_block_reward_btc !== null): ?>
                <tr>
                    <td class="key">Prize to Win</td> 
                    <td class="font-mono">
                        <?php if ($btc_usd_price !== null && $last_block_reward_usd !== null): ?>
                            <strong style="font-size: 1.1em; color: var(--diff-up);">$<?= htmlspecialchars(number_format($last_block_reward_usd, 2)) ?></strong>
                            <span style="display: block; opacity: 0.7; font-size: 0.9em;">
                                ($<?= htmlspecialchars(number_format($btc_usd_price, 2)) ?> &times; 
                                <?= htmlspecialchars(number_format($last_block_reward_btc, 6)) ?> BTC)
                                <?php if ($last_fetched_block_height !== null): ?>
                                    (Block #<?= htmlspecialchars(number_format($last_fetched_block_height)) ?>)
                                <?php endif; ?>
                            </span>
                        <?php elseif ($btc_usd_price === null): ?>
                            <?= htmlspecialchars(number_format($last_block_reward_btc, 8)) ?> BTC
                            <span style="display: block; opacity: 0.5; font-style: italic; font-family: var(--font-sans);">
                                (Price API refreshing... 🤷‍♂️)
                            </span>
                        <?php else: ?>
                             <span style="opacity: 0.5; font-style: italic; font-family: var(--font-sans);">(Waiting for block reward data...)</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endif; ?>

                <?php if ($difficulty_prediction): ?><tr><td class="key">Next Adjustment</td><td class="font-mono"><?php echo 'Progress: <strong>' . ($difficulty_prediction['progress'] ?? 'N/A') . '%</strong>'; if (isset($difficulty_prediction['prediction'])) { $pred_val = $difficulty_prediction['prediction']; $pred_class = $pred_val >= 0 ? 'diff-up' : 'diff-down'; $pred_sign = $pred_val >= 0 ? '+' : ''; echo ' &nbsp;&bull;&nbsp; Est. Change: <strong class="' . $pred_class . '">' . $pred_sign . $pred_val . '%</strong>'; } if ($estimated_adjustment_date) { echo '<br><span style="opacity: 0.7;">Est. Date: <strong class="font-mono">' . $estimated_adjustment_date . '</strong></span>'; } ?></td></tr><?php endif; ?>
                
            </tbody></table>
        <?php endif; ?>


        <div class="chart-controls" id="chart-controls"> <button data-range="1" class="active">24H</button> <button data-range="7">7D</button> <button data-range="30">30D</button> </div>
        <div id="chart-container"><canvas id="hashrateChart"></canvas></div>
        
        <?php function render_table($data, $key_order, $friendly_names, $network_difficulty, $previous_difficulty, $analytics, $workers_data = null, $difficulty_prediction = null, $pool_time_to_block = null, $estimated_adjustment_date = null) { $inactive_workers_html = ''; $has_inactive_workers = false; echo '<table><tbody>'; foreach ($key_order as $key) { if ($key === 'workers' && isset($data[$key]) && $data[$key] > 0) { echo '<tr class="workers-toggle" id="workers-toggle" title="Click to expand/collapse"><td class="key"><span>' . ($friendly_names[$key] ?? 'Workers') . '</span> <svg class="chevron" width="16" height="16" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M4 6L8 10L12 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg></td><td>' . htmlspecialchars(format_number_auto($data[$key])) . '</td></tr>'; if ($workers_data) { $active_workers_html = ''; $inactive_workers_table = '<table><thead><tr><th class="key">Name</th><th>Hashrate (5 min)</th><th>Shares</th></tr></thead><tbody>'; foreach ($workers_data as $name => $stats) { $hashrate5m = $stats['hashrate5m'] ?? '0'; if (parse_hashrate_to_ghs($hashrate5m) > 0) { $active_workers_html .= '<tr><td class="key">' . htmlspecialchars($name) . '</td><td>' . htmlspecialchars(format_hashrate($hashrate5m)) . '</td><td>' . htmlspecialchars(format_number_auto($stats['shares'] ?? 0)) . '</td><td><button class="worker-chart-btn" data-worker="' . htmlspecialchars($name) . '">Show Chart</button></td></tr>'; } else { $inactive_workers_table .= '<tr><td class="key">' . htmlspecialchars($name) . '</td><td>' . htmlspecialchars(format_hashrate($hashrate5m)) . '</td><td>' . htmlspecialchars(format_number_auto($stats['shares'] ?? 0)) . '</td></tr>'; $has_inactive_workers = true; } } $inactive_workers_table .= '</tbody></table>'; echo '<tr class="worker-list-row" id="worker-list-row"><td colspan="2"><div class="worker-list-content"><div class="worker-list-header"><h3>Active Workers</h3></div><table><thead><tr><th class="key">Name</th><th>Hashrate (5 min)</th><th>Shares</th><th>Chart</th></tr></thead><tbody>' . $active_workers_html; if ($has_inactive_workers) { echo '<tr><td colspan="4" style="text-align: center; padding-top: 1em;"><button class="show-inactive-btn" id="show-inactive-btn">Show Inactive Workers</button></td></tr>'; } echo '</tbody></table></div></td></tr>'; } continue; } if ($key === 'rejected_percent') { if (isset($data['accepted'], $data['rejected']) && ($data['accepted'] + $data['rejected']) > 0) { $total = $data['accepted'] + $data['rejected']; $percent = ($data['rejected'] / $total) * 100; $label = $friendly_names[$key] ?? 'Rejected %'; $value = format_number_auto($percent, 2) . ' %'; echo '<tr><td class="key">' . htmlspecialchars($label) . '</td><td>' . htmlspecialchars($value) . '</td></tr>'; } continue; } if ($key === 'time_to_block' && $pool_time_to_block) { echo '<tr><td class="key">' . ($friendly_names[$key] ?? 'Est. Time/Block') . '</td><td>' . htmlspecialchars(format_long_time($pool_time_to_block)) . '</td></tr>'; continue; } if (isset($data[$key])) { $label = $friendly_names[$key] ?? ucfirst($key); $value = $data[$key]; echo '<tr><td class="key">' . htmlspecialchars($label) . '</td><td>'; if ($key === 'bestshare' && $workers_data !== null && $network_difficulty !== null && $network_difficulty > 0) { $percentage = ($value / $network_difficulty) * 100; $percentage_capped = min(100, $percentage); $diff_change_html = ''; if ($previous_difficulty !== null && $previous_difficulty > 0) { $change = (($network_difficulty - $previous_difficulty) / $previous_difficulty) * 100; $class = $change >= 0 ? 'diff-up' : 'diff-down'; $sign = $change >= 0 ? '+' : ''; $diff_change_html = ' <span class="diff-change ' . $class . '">(' . $sign . number_format($change, 2) . '%)</span>'; } echo '<div class="progress-container"><span class="progress-text">' . htmlspecialchars(format_number_auto($value)) . ' (' . number_format($percentage, 4) . '%)</span><div class="progress-bar"><div class="progress-fill" style="width: ' . $percentage_capped . '%;"></div></div><div class="difficulty-info">Network Difficulty: ' . htmlspecialchars(format_number_auto($network_difficulty)) . $diff_change_html . '</div>'; if ($difficulty_prediction) { echo '<div class="prediction-info">Next adjustment progress: <strong>' . ($difficulty_prediction['progress'] ?? 'N/A') . '%</strong>.<br>'; if (isset($difficulty_prediction['prediction'])) { $pred_val = $difficulty_prediction['prediction']; $pred_class = $pred_val >= 0 ? 'diff-up' : 'diff-down'; $pred_sign = $pred_val >= 0 ? '+' : ''; echo 'Estimated change: <strong class="' . $pred_class . '">' . $pred_sign . $pred_val . '%</strong>'; } if ($estimated_adjustment_date) { echo ' (Est. <strong class="font-mono">' . $estimated_adjustment_date . '</strong>)'; } echo '</div>'; } if ($analytics) { echo '<div class="probability-info">Based on your 1h hashrate:<br>Avg. time to find a block: <strong>' . format_long_time($analytics['time_to_find']) . '</strong><br>Est. probability: <strong>' . number_format($analytics['prob_month'], 6) . '%</strong>/month, <strong>' . number_format($analytics['prob_year'], 4) . '%</strong>/year.</div>'; } echo '</div>'; } elseif ($key === 'bestshare') { echo htmlspecialchars(format_share($value)); if ($network_difficulty !== null && $network_difficulty > 0) { $percent = ($value / $network_difficulty) * 100; echo ' <span class="full-date">(' . number_format($percent, 4) . '%)</span>'; } } elseif (strpos($key, 'hashrate') === 0) { echo htmlspecialchars(format_hashrate($value)); } elseif (strpos($key, 'SPS') === 0) { echo htmlspecialchars(format_number_auto((float)$value, 3)); } else { if ($key === 'lastshare') { echo '<div class="time-ago" data-timestamp="' . htmlspecialchars($value) . '">...</div>'; } elseif ($key === 'runtime') { echo htmlspecialchars(format_seconds($value)); } else { echo htmlspecialchars(is_array($value) ? json_encode($value) : $value); } } echo '</td></tr>'; } } echo '</tbody></table>'; if (isset($has_inactive_workers) && $has_inactive_workers) { echo '<div id="inactive-workers-data" style="display:none;">' . $inactive_workers_table . '</div>'; } } ?>
        
        <?php if ($user_summary): ?> <?php render_table($user_summary, ['hashrate1m', 'hashrate5m', 'hashrate1hr', 'hashrate1d', 'hashrate7d', 'shares', 'workers', 'lastshare', 'bestshare'], $friendly_names, $network_difficulty, $previous_network_difficulty, $analytics, $user_workers, $difficulty_prediction, null, $estimated_adjustment_date); ?> <?php endif; ?>
        <?php if ($pool_data && !$btc_address): ?> <h2>Pool Statistics</h2> <?php render_table($pool_data, ['hashrate1m', 'hashrate5m', 'hashrate1hr', 'hashrate1d', 'hashrate7d', 'SPS1m', 'SPS5m', 'SPS15m', 'SPS1h', 'Users', 'Workers', 'accepted', 'rejected', 'rejected_percent', 'bestshare', 'time_to_block', 'runtime'], $friendly_names, $network_difficulty, null, null, null, null, $pool_time_to_block, null); ?> <?php endif; ?>
        <?php if ($last_update): ?> <p class="footer">Last updated: <span class="time-ago" data-timestamp="<?= $last_update ?>">...</span></p> <?php endif; ?>
    </div>
    
    <div id="modal-backdrop" class="modal-backdrop"> <div id="modal-content" class="modal-content"> <div class="modal-header"> <h2 id="modal-title" class="modal-title">Worker Chart</h2> <button id="modal-close-btn" class="modal-close-btn">&times;</button> </div> <div id="modal-chart-container"> <canvas id="modalChartCanvas"></canvas> </div> </div> </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const timeAgo = (timestamp) => { const now = new Date(); const past = new Date(timestamp * 1000); const seconds = Math.floor((now - past) / 1000); if (seconds < 60) return `${seconds}s ago`; const minutes = Math.floor(seconds / 60); if (minutes < 60) return `${minutes}m ago`; const hours = Math.floor(minutes / 60); if (hours < 24) return `${hours}h ago`; const days = Math.floor(hours / 24); return `${days}d ago`; };
            const timeElements = document.querySelectorAll('.time-ago');
            timeElements.forEach(element => { const timestamp = parseInt(element.dataset.timestamp, 10); if (!isNaN(timestamp)) { const relativeTime = timeAgo(timestamp); const date = new Date(timestamp * 1000); const year = date.getFullYear(); const month = ('0' + (date.getMonth() + 1)).slice(-2); const day = ('0' + date.getDate()).slice(-2); const hours = ('0' + date.getHours()).slice(-2); const minutes = ('0' + date.getMinutes()).slice(-2); const seconds = ('0' + date.getSeconds()).slice(-2); const fullDate = `${year}-${month}-${day} ${hours}:${minutes}:${seconds}`; element.innerHTML = `${relativeTime} <span class="full-date">(${fullDate})</span>`; } });
            
            const themeToggle = document.getElementById('theme-toggle');
            const themeSwitcher = () => { 
                const currentTheme = document.documentElement.getAttribute('data-theme'); 
                const newTheme = currentTheme === 'light' ? 'dark' : 'light'; 
                document.documentElement.setAttribute('data-theme', newTheme); 
                localStorage.setItem('theme', newTheme); 
                setTimeout(() => {
                     const activeRange = document.querySelector('.chart-controls button.active')?.dataset.range || '1';
                     if (mainChartCanvas) fetchAndRender(activeRange, null, mainChartCanvas, btcAddress ? 'Aggregated Hashrate' : 'Pool Hashrate');
                     if (modalBackdrop.style.display === 'flex') {
                          const modalTitleEl = document.getElementById('modal-title');
                          if(modalTitleEl) {
                              modalChartCanvas = document.getElementById('modalChartCanvas'); 
                              if(modalChartCanvas) {
                                  if(modalTitleEl.textContent.includes('Chart for worker:')) {
                                      const activeWorker = modalTitleEl.textContent.replace('Chart for worker: ', '');
                                      fetchAndRender(activeRange, activeWorker, modalChartCanvas, `Worker: ${activeWorker}`, true);
                                  } else if (modalTitleEl.textContent.includes('Network History')) {
                                       fetchAndRenderNetworkChart();
                                  }
                              }
                          }
                     }
                }, 50); 
            };
            themeToggle.addEventListener('click', themeSwitcher);

            const workersToggle = document.getElementById('workers-toggle');
            const workerListRow = document.getElementById('worker-list-row');
            if(workersToggle && workerListRow) { workersToggle.addEventListener('click', () => { const isOpen = workersToggle.classList.toggle('open'); workerListRow.style.display = isOpen ? 'table-row' : 'none'; }); }

            const mainChartCanvas = document.getElementById('hashrateChart');
            const btcAddress = <?= json_encode($btc_address) ?>;
            
            const seriesConfig = { 
                '5m': { label: '5 min avg', cssColorVar: '--chart-color-1' }, 
                '1h': { label: '1 hour avg', cssColorVar: '--chart-color-2' }, 
                '1d': { label: '24 hour avg', cssColorVar: '--chart-color-3' } 
            };
            let mainChart = null; let modalChart = null;
            
            const modalBackdrop = document.getElementById('modal-backdrop');
            const modalCloseBtn = document.getElementById('modal-close-btn');
            let modalChartCanvas = document.getElementById('modalChartCanvas'); 
            const modalContent = document.getElementById('modal-content');
            const modalTitle = document.getElementById('modal-title');

            const fetchAndRender = async (range, workerName = null, canvas, title, isModal = false) => {
                let url = `?fetch_chart_data=true&range=${range}`;
                if (btcAddress) url += `&btc_address=${encodeURIComponent(btcAddress)}`;
                if (workerName) url += `&worker=${encodeURIComponent(workerName)}`;
                try {
                    const response = await fetch(url); 
                    if (!response.ok) { throw new Error(`HTTP error! status: ${response.status}`); }
                    const datasets = await response.json();
                    if (datasets.error) { throw new Error(datasets.error); }

                    if (isModal && modalChart) modalChart.destroy(); else if (!isModal && mainChart) mainChart.destroy();
                    
                    const style = getComputedStyle(document.body);
                    const foregroundColor = style.getPropertyValue('--foreground').trim();
                    const mutedForegroundColor = style.getPropertyValue('--muted-foreground').trim();
                    const borderColor = style.getPropertyValue('--border').trim();
                    const cardBackgroundColor = style.getPropertyValue('--card-background').trim();

                    const chartDatasets = [];
                    let hasData = false;
                    for (const key in datasets) {
                        if (datasets.hasOwnProperty(key) && seriesConfig[key]) {
                            const lineColor = style.getPropertyValue(seriesConfig[key].cssColorVar).trim();
                            const numericData = datasets[key].data.filter(y => y !== null && y !== undefined && !isNaN(parseFloat(y)));
                            if (numericData.length > 0) hasData = true; 
                            
                            chartDatasets.push({
                                label: seriesConfig[key].label,
                                data: datasets[key].labels.map((ts, index) => ({ x: ts * 1000, y: datasets[key].data[index] })),
                                borderColor: lineColor,
                                backgroundColor: lineColor + '2A', 
                                fill: true,
                                borderWidth: 2,
                                pointRadius: 0,
                                tension: 0.4
                            });
                        }
                    }

                    const chartContainer = canvas.parentElement;
                    let noDataMsg = chartContainer.querySelector('.no-chart-data');
                     let errorMsg = chartContainer.querySelector('.chart-error');
                     if (errorMsg) errorMsg.remove();
                    
                    if (!hasData) {
                        if (!noDataMsg) {
                            noDataMsg = document.createElement('p');
                            noDataMsg.textContent = 'No chart data available for this period.';
                            noDataMsg.className = 'no-chart-data';
                            noDataMsg.style.textAlign = 'center';
                            noDataMsg.style.padding = '2em';
                            noDataMsg.style.color = mutedForegroundColor;
                            chartContainer.insertBefore(noDataMsg, canvas);
                        }
                        canvas.style.display = 'none'; 
                         if (isModal) modalChart = null; else mainChart = null; 
                        return; 
                    } else {
                         if (noDataMsg) noDataMsg.remove(); 
                         canvas.style.display = 'block'; 
                    }


                    const timeUnit = range > 7 ? 'day' : (range > 1 ? 'day' : 'hour');
                    const chartConfig = {
                        type: 'line',
                        data: { datasets: chartDatasets },
                        options: {
                            responsive: true, maintainAspectRatio: true,
                            scales: {
                                x: {
                                    type: 'time',
                                    time: { unit: timeUnit, tooltipFormat: 'yyyy-MM-dd HH:mm', displayFormats: { millisecond: 'HH:mm:ss.SSS', second: 'HH:mm:ss', minute: 'HH:mm', hour: 'HH:mm', day: 'MMM dd', week: 'MMM dd', month: 'MMM yyyy' } },
                                    grid: { color: borderColor }, ticks: { color: mutedForegroundColor }
                                },
                                y: {
                                    beginAtZero: true, title: { display: true, text: 'Hashrate (GH/s)', color: mutedForegroundColor },
                                    grid: { color: borderColor }, ticks: { color: mutedForegroundColor }
                                }
                            },
                            plugins: {
                                legend: { labels: { color: mutedForegroundColor } },
                                title: { display: true, text: title, font: { size: 16, weight: '600', family: 'Inter' }, color: foregroundColor }, 
                                tooltip: { 
                                    mode: 'index', intersect: false, 
                                    backgroundColor: cardBackgroundColor, 
                                    titleColor: foregroundColor, 
                                    bodyColor: foregroundColor,
                                    titleFont: { family: 'Inter' }, 
                                    bodyFont: { family: 'JetBrains Mono' } 
                                }
                            }
                        }
                    };
                    Chart.defaults.font.family = 'Inter'; 
                    Chart.defaults.font.size = 12;
                    Chart.defaults.color = mutedForegroundColor;

                    if (isModal) { modalChart = new Chart(canvas, chartConfig); } else { mainChart = new Chart(canvas, chartConfig); }
                } catch (e) { 
                    console.error("Failed to fetch or render chart data:", e); 
                    const chartContainer = canvas.parentElement;
                    let noDataMsg = chartContainer.querySelector('.no-chart-data');
                    if (noDataMsg) noDataMsg.remove(); 
                    
                    let errorMsg = chartContainer.querySelector('.chart-error');
                     if (!errorMsg) {
                        errorMsg = document.createElement('p');
                        errorMsg.className = 'chart-error error'; 
                        chartContainer.insertBefore(errorMsg, canvas);
                    }
                    errorMsg.textContent = `Chart Error: ${e.message}`;
                    canvas.style.display = 'none'; 
                    if (isModal) modalChart = null; else mainChart = null;
                }
            };

            const fetchAndRenderNetworkChart = async () => {
                if (modalChart) modalChart.destroy();
                let url = `?fetch_network_chart=true`;
                try {
                    const response = await fetch(url); 
                    if (!response.ok) { throw new Error(`HTTP error! status: ${response.status}`); }
                    const datasets = await response.json();
                    if (datasets.error) { throw new Error(datasets.error); }
                    
                    const style = getComputedStyle(document.body);
                    const foregroundColor = style.getPropertyValue('--foreground').trim();
                    const mutedForegroundColor = style.getPropertyValue('--muted-foreground').trim();
                    const borderColor = style.getPropertyValue('--border').trim();
                    const cardBackgroundColor = style.getPropertyValue('--card-background').trim();
                    const color1 = style.getPropertyValue('--chart-color-1').trim();
                    const color2 = style.getPropertyValue('--chart-color-2').trim();

                    const hasHashrateData = datasets.hashrate && datasets.hashrate.labels && datasets.hashrate.data.filter(y => y !== null && y !== undefined).length > 0;
                    const hasDifficultyData = datasets.difficulty && datasets.difficulty.labels && datasets.difficulty.data.filter(y => y !== null && y !== undefined).length > 0;

                    const chartContainer = modalChartCanvas.parentElement;
                     let noDataMsg = chartContainer.querySelector('.no-chart-data');
                      let errorMsg = chartContainer.querySelector('.chart-error');
                     if (errorMsg) errorMsg.remove();
                     
                    if (!hasHashrateData && !hasDifficultyData) {
                         if (!noDataMsg) {
                            noDataMsg = document.createElement('p');
                            noDataMsg.textContent = 'No network history data available.';
                            noDataMsg.className = 'no-chart-data';
                             noDataMsg.style.textAlign = 'center'; noDataMsg.style.padding = '2em'; noDataMsg.style.color = mutedForegroundColor;
                            chartContainer.insertBefore(noDataMsg, modalChartCanvas);
                        }
                        modalChartCanvas.style.display = 'none';
                        modalChart = null;
                        return;
                    } else {
                         if (noDataMsg) noDataMsg.remove();
                         modalChartCanvas.style.display = 'block';
                    }

                    Chart.defaults.font.family = 'Inter'; 
                    Chart.defaults.font.size = 12;
                    Chart.defaults.color = mutedForegroundColor;

                    modalChart = new Chart(modalChartCanvas, {
                        type: 'line',
                        data: {
                            datasets: [
                                { label: 'Network Hashrate (EH/s)', data: hasHashrateData ? datasets.hashrate.labels.map((ts, index) => ({ x: ts, y: datasets.hashrate.data[index] / 1000000000 })) : [], borderColor: color1, backgroundColor: color1 + '2A', yAxisID: 'yHashrate', tension: 0.1, fill: true, pointRadius: 0 },
                                { label: 'Network Difficulty', data: hasDifficultyData ? datasets.difficulty.labels.map((ts, index) => ({ x: ts, y: datasets.difficulty.data[index] })) : [], borderColor: color2, backgroundColor: color2 + '2A', yAxisID: 'yDifficulty', tension: 0.1, fill: false, pointRadius: 0 }
                            ]
                        },
                        options: {
                            responsive: true, maintainAspectRatio: true,
                           scales: { 
                               x: { type: 'time', time: { unit: 'day' }, grid: { color: borderColor }, ticks: { color: mutedForegroundColor } }, 
                               yHashrate: { type: 'linear', position: 'left', title: { display: true, text: 'Hashrate (EH/s)', color: color1 }, grid: { drawOnChartArea: false }, ticks: { color: color1 } }, 
                               yDifficulty: { type: 'linear', position: 'right', title: { display: true, text: 'Difficulty', color: color2 }, ticks: { color: color2 } } 
                           },
                           plugins: { 
                                legend: { labels: { color: mutedForegroundColor } }, 
                                title: { display: true, text: 'Network History (30 Days)', font: { size: 16, weight: '600', family: 'Inter' }, color: foregroundColor }, 
                                tooltip: { 
                                    mode: 'index', intersect: false, 
                                    backgroundColor: cardBackgroundColor, 
                                    titleColor: foregroundColor, 
                                    bodyColor: foregroundColor,
                                    titleFont: { family: 'Inter' }, 
                                    bodyFont: { family: 'JetBrains Mono' }
                                } 
                            }
                        }
                    });
                } catch (e) { 
                    console.error("Failed to fetch or render network chart data:", e); 
                    const chartContainer = modalChartCanvas.parentElement;
                    let noDataMsg = chartContainer.querySelector('.no-chart-data');
                    if (noDataMsg) noDataMsg.remove(); 
                    
                    let errorMsg = chartContainer.querySelector('.chart-error');
                     if (!errorMsg) {
                        errorMsg = document.createElement('p');
                        errorMsg.className = 'chart-error error';
                        chartContainer.insertBefore(errorMsg, modalChartCanvas);
                    }
                    errorMsg.textContent = `Chart Error: ${e.message}`;
                    modalChartCanvas.style.display = 'none';
                    modalChart = null;
                }
            };

            const closeModal = () => {
                modalBackdrop.style.display = 'none';
                if (modalChart) { modalChart.destroy(); modalChart = null; }
                const container = document.getElementById('modal-chart-container');
                container.innerHTML = '<canvas id="modalChartCanvas"></canvas>'; 
                 modalChartCanvas = document.getElementById('modalChartCanvas'); 
            };
            if(modalCloseBtn) modalCloseBtn.addEventListener('click', closeModal);
            if(modalBackdrop) modalBackdrop.addEventListener('click', (e) => { if (e.target === modalBackdrop) closeModal(); });

            document.querySelector('#worker-list-row')?.addEventListener('click', (e) => {
                if (e.target.classList.contains('worker-chart-btn')) {
                    const workerName = e.target.dataset.worker;
                    const currentRange = document.querySelector('.chart-controls button.active').dataset.range;
                    modalTitle.textContent = `Chart for worker: ${workerName}`;
                    modalBackdrop.style.display = 'flex';
                     modalChartCanvas = document.getElementById('modalChartCanvas'); 
                     if (modalChartCanvas) {
                        fetchAndRender(currentRange, workerName, modalChartCanvas, `Worker: ${workerName}`, true);
                     }
                }
            });

            const showInactiveBtn = document.getElementById('show-inactive-btn');
            if (showInactiveBtn) {
                showInactiveBtn.addEventListener('click', () => {
                    const inactiveData = document.getElementById('inactive-workers-data');
                    if (inactiveData) {
                        modalTitle.textContent = 'Inactive Workers';
                        document.getElementById('modal-chart-container').innerHTML = inactiveData.innerHTML;
                        modalBackdrop.style.display = 'flex';
                    }
                });
            }

            const networkStatusHeader = document.getElementById('network-status-header');
            if (networkStatusHeader) {
                networkStatusHeader.addEventListener('click', () => {
                    modalTitle.textContent = 'Network History (30 Days)';
                    modalBackdrop.style.display = 'flex';
                     modalChartCanvas = document.getElementById('modalChartCanvas'); 
                     if (modalChartCanvas) {
                        fetchAndRenderNetworkChart();
                     }
                });
            }

            const chartControls = document.getElementById('chart-controls');
            if (chartControls) {
                chartControls.addEventListener('click', (e) => {
                    if (e.target.tagName === 'BUTTON' && !e.target.classList.contains('active')) {
                        chartControls.querySelectorAll('button').forEach(btn => btn.classList.remove('active'));
                        e.target.classList.add('active');
                        const range = e.target.dataset.range;
                        fetchAndRender(range, null, mainChartCanvas, btcAddress ? 'Aggregated Hashrate' : 'Pool Hashrate');
                    }
                });
            }

            if (mainChartCanvas) {
                fetchAndRender(1, null, mainChartCanvas, btcAddress ? 'Aggregated Hashrate' : 'Pool Hashrate');
            }
        });
    </script>
</body>
</html>