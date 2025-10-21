#!/usr/bin/php
<?php
// --- Configuration ---
$dataDir = __DIR__ . '/data';
$networkDbPath = $dataDir . '/network.db';
$logFilePath = '/var/log/ckpool/ckpool.log'; // Path to ckpool log
$webUser = 'web1';
$webGroup = 'client1';
$retentionDays = 30; // Keep network history for 30 days
$apiTimeout = 15; // Seconds timeout for API calls
// --- Functions ---
function get_live_network_hashrate() {
    $url = 'https://blockchain.info/q/hashrate';
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_TIMEOUT, $GLOBALS['apiTimeout']); 
    curl_setopt($ch, CURLOPT_USERAGENT, 'CkpoolStatsViewer/1.0'); 
    $output = curl_exec($ch);
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpcode == 200 && is_numeric($output)) {
        return (float)$output; // blockchain.info returns GHS
    }
    echo "Warning: Failed to fetch live network hashrate from blockchain.info (HTTP: {$httpcode})\n";
    return null; 
}

function get_difficulty_prediction() {
    $apiUrl = 'https://mempool.space/api/v1/difficulty-adjustment'; 
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $apiUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_TIMEOUT, $GLOBALS['apiTimeout']);
    curl_setopt($ch, CURLOPT_USERAGENT, 'CkpoolStatsViewer/1.0');
    $output = curl_exec($ch);
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpcode == 200 && $output) {
        $data = json_decode($output, true);
        if ($data && isset($data['progressPercent'], $data['difficultyChange'], $data['timeAvg'])) {
             // ZMIANA: Konwersja timeAvg z milisekund na sekundy
             $avgBlockTimeSeconds = isset($data['timeAvg']) ? ($data['timeAvg'] / 1000) : 600; // Podziel przez 1000
             return [
                'progress' => round($data['progressPercent'], 2),
                'prediction' => round($data['difficultyChange'], 2),
                'avg_time' => $avgBlockTimeSeconds // Zapisujemy już w sekundach
             ];
        }
    }
    echo "Warning: Failed to fetch difficulty prediction data from mempool.space (HTTP: {$httpcode})\n";
    return null; // Return null on failure
}
// --- Main ---
echo "Prediction parser started at " . date('Y-m-d H:i:s') . "\n";
if (!is_dir($dataDir)) { @mkdir($dataDir, 0775, true); }
if (file_exists($networkDbPath)) { @chown($networkDbPath, $webUser); @chgrp($networkDbPath, $webGroup); }

try {
    $pdo = new PDO('sqlite:' . $networkDbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("CREATE TABLE IF NOT EXISTS network_history (id INTEGER PRIMARY KEY, timestamp INTEGER NOT NULL, network_difficulty REAL, network_hashrate_ghs REAL)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS network_stats (id INTEGER PRIMARY KEY, last_update INTEGER, data TEXT)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS prediction_data (id INTEGER PRIMARY KEY, progress REAL, prediction REAL, avg_time REAL)"); // avg_time będzie teraz w sekundach
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_network_timestamp ON network_history (timestamp)");
} catch (PDOException $e) { die("ERROR: DB connection failed: " . $e->getMessage() . "\n"); }

$now = time();

// --- Network Difficulty and Hashrate ---
$network_difficulty_from_log = null;
$previous_network_difficulty = null;

$stmt_last_diff = $pdo->query("SELECT network_difficulty FROM network_history ORDER BY timestamp DESC LIMIT 1");
$last_recorded_difficulty = $stmt_last_diff ? $stmt_last_diff->fetchColumn() : null;

if (is_readable($logFilePath)) {
    $lines = @file($logFilePath);
    if ($lines !== false) {
        $found_difficulty = null;
        foreach (array_reverse($lines) as $line) {
            if (preg_match('/Network diff set to ([\d\.]+)/', $line, $matches)) {
                $found_difficulty = (float)$matches[1];
                break; 
            }
        }
        if ($found_difficulty !== null) {
            $network_difficulty_from_log = $found_difficulty;
            if ($last_recorded_difficulty !== null && $last_recorded_difficulty != $network_difficulty_from_log) {
                 $previous_network_difficulty = $last_recorded_difficulty;
            } else {
                 $stmt_prev_diff = $pdo->query("SELECT network_difficulty FROM network_history WHERE network_difficulty IS NOT NULL ORDER BY timestamp DESC LIMIT 1 OFFSET 1");
                 $previous_network_difficulty = $stmt_prev_diff ? $stmt_prev_diff->fetchColumn() : null;
            }
        } else {
             $network_difficulty_from_log = $last_recorded_difficulty; 
        }
    } else {
        $network_difficulty_from_log = $last_recorded_difficulty; 
        echo "Warning: Could not read ckpool log file at {$logFilePath}\n";
    }
} else {
     $network_difficulty_from_log = $last_recorded_difficulty; 
     echo "Warning: ckpool log file not found or not readable at {$logFilePath}\n";
}

// --- Get Live Network Hashrate ---
$live_network_hashrate = get_live_network_hashrate(); 
$network_hashrate_to_store = null;

if ($live_network_hashrate !== null) {
    $network_hashrate_to_store = $live_network_hashrate;
    echo "Using live network hashrate: " . round($network_hashrate_to_store / 1e9, 2) . " EH/s\n";
} elseif ($network_difficulty_from_log > 0) {
    $network_hashrate_to_store = $network_difficulty_from_log * pow(2, 32) / 600 / 1e9; // Calculate GHS
    echo "Using calculated network hashrate: " . round($network_hashrate_to_store / 1e9, 2) . " EH/s\n";
} else {
     echo "Warning: Cannot determine network hashrate.\n";
}

// --- Store Network History ---
if ($network_difficulty_from_log !== null || $network_hashrate_to_store !== null) {
    try {
        $stmt_net_hist = $pdo->prepare("INSERT INTO network_history (timestamp, network_difficulty, network_hashrate_ghs) VALUES (?, ?, ?)");
        $stmt_net_hist->execute([$now, $network_difficulty_from_log, $network_hashrate_to_store]);
    } catch (PDOException $e) {
        echo "Error inserting network history: " . $e->getMessage() . "\n";
    }
}

// --- Update Network Stats (current values) ---
$net_data = [
    'network_difficulty' => $network_difficulty_from_log,
    'previous_network_difficulty' => $previous_network_difficulty,
    'network_hashrate' => $network_hashrate_to_store 
];
$net_stmt = $pdo->prepare("INSERT OR REPLACE INTO network_stats (id, last_update, data) VALUES (1, ?, ?)");
$net_stmt->execute([$now, json_encode($net_data)]);


// --- Get and Store Difficulty Prediction ---
$prediction_data = get_difficulty_prediction(); 

if ($prediction_data !== null) {
    $pred_stmt = $pdo->prepare("INSERT OR REPLACE INTO prediction_data (id, progress, prediction, avg_time) VALUES (1, ?, ?, ?)");
    $pred_stmt->execute([
        $prediction_data['progress'],
        $prediction_data['prediction'],
        $prediction_data['avg_time'] // Zapisujemy wartość już w sekundach
    ]);
    echo "Prediction data updated: Progress {$prediction_data['progress']}%, Est. Change {$prediction_data['prediction']}%, Avg Time {$prediction_data['avg_time']}s\n"; // Dodano Avg Time do logu
} else {
    echo "Warning: Failed to get valid prediction data.\n";
}

// --- Cleanup old data ---
if (rand(1, 24) === 1) { 
    $cutoff = time() - ($retentionDays * 86400);
    $deleted_rows = $pdo->prepare("DELETE FROM network_history WHERE timestamp < ?")->execute([$cutoff]);
    echo "Cleaned up {$deleted_rows} old network history records.\n";
}

// Ensure correct permissions
chown($networkDbPath, $webUser); chgrp($networkDbPath, $webGroup);

echo "Prediction parser finished successfully.\n";