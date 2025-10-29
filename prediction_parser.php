#!/usr/bin/php
<?php
require_once __DIR__ . '/common.php';

// --- Configuration ---
$dataDir = __DIR__ . '/data';
$networkDbPath = $dataDir . '/network.db';
$logFilePath = '/var/log/ckpool/ckpool.log';
$webUser = 'web1';
$webGroup = 'client1';
$retentionDays = 30;
// $bitcoinCliUser and $bitcoinCliPath s? w common.php

// --- Functions ---
// run_bitcoin_cli() jest w common.php
// api_fetch() jest w common.php

function get_mempool_hashrate(PDO $pdo) {
    $hashrateUrl = 'https://mempool.space/api/v1/mining/hashrate/1d';
    $hashrateOutput = api_fetch($hashrateUrl);
    if ($hashrateOutput) {
        $hrData = json_decode($hashrateOutput, true);
        if ($hrData && isset($hrData['currentHashrate']) && is_numeric($hrData['currentHashrate'])) {
             $hashrateGHS = $hrData['currentHashrate'] / 1e9;
             echo "Fetched currentHashrate from mempool /mining/hashrate/1d endpoint.\n";
             return $hashrateGHS;
        } else {
            $responseSnippet = substr(preg_replace('/\s+/', ' ', trim($hashrateOutput)), 0, 150);
            echo "Warning: Could not parse 'currentHashrate' from mempool /mining/hashrate/1d. Response: ". $responseSnippet . "...\n";
        }
    }
    echo "Mempool hashrate failed or unavailable, trying last stored value...\n";
    try {
        $stmt_last_hr = $pdo->query("SELECT data FROM network_stats WHERE id = 1 LIMIT 1");
        $last_data_json = $stmt_last_hr ? $stmt_last_hr->fetchColumn() : null;
        if ($last_data_json) { $last_data = json_decode($last_data_json, true); if (isset($last_data['network_hashrate']) && is_numeric($last_data['network_hashrate'])) { echo "Using last stored hashrate value: " . round($last_data['network_hashrate'] / 1e9, 2) . " EH/s\n"; return (float)$last_data['network_hashrate']; } }
    } catch (PDOException $e) { echo "Warning: Failed to read last hashrate from DB: " . $e->getMessage() . "\n"; }
    echo "Warning: Failed to get hashrate from API and no valid stored value found.\n";
    return null;
}

function _get_mempool_prediction_data() {
    $difficultyUrl = 'https://mempool.space/api/v1/difficulty-adjustment';
    $difficultyOutput = api_fetch($difficultyUrl);
     if ($difficultyOutput) {
        $diffData = json_decode($difficultyOutput, true);
        if ($diffData && isset($diffData['progressPercent'], $diffData['difficultyChange'], $diffData['timeAvg'], $diffData['estimatedRetargetDate'])) {
             $avgBlockTimeSeconds = isset($diffData['timeAvg']) ? ($diffData['timeAvg'] / 1000) : 600;
             echo "Fetched prediction data from mempool /difficulty-adjustment endpoint.\n";
             return [
                'progress' => round($diffData['progressPercent'], 2),
                'prediction' => round($diffData['difficultyChange'], 2),
                'avg_time' => $avgBlockTimeSeconds,
                'estimated_timestamp' => (int)($diffData['estimatedRetargetDate'] / 1000)
             ];
        } else {
            echo "Warning: Incomplete or invalid data structure received from mempool.space difficulty API. Response: ". substr(preg_replace('/\s+/', ' ', trim($difficultyOutput)), 0, 150) . "...\n";
        }
    }
    return null;
}

function get_block_height_for_prediction(): ?int {
    $result = run_bitcoin_cli('getblockcount');
    if ($result['output'] !== null && is_numeric($result['output'])) {
        echo "Fetched block height from local node (for prediction calc).\n";
        return (int)$result['output'];
    }
    echo "Warning: Failed to get block height from local node for prediction. Error: " . ($result['error'] ?? 'Unknown shell_exec error') . ". Falling back to Mempool API...\n";
    $mempoolRecentUrl = 'https://mempool.space/api/mempool/recent'; $mempoolRecentOutput = api_fetch($mempoolRecentUrl); if ($mempoolRecentOutput) { $recentData = json_decode($mempoolRecentOutput, true); if (is_array($recentData) && !empty($recentData) && isset($recentData[0]['blockHeight']) && is_numeric($recentData[0]['blockHeight'])) { echo "Fetched block height from Mempool /recent API.\n"; return (int)$recentData[0]['blockHeight']; } }
    $tipHeightUrl = 'https://mempool.space/api/blocks/tip/height'; $tipHeightOutput = api_fetch($tipHeightUrl); if ($tipHeightOutput && is_numeric($tipHeightOutput)) { echo "Fetched block height from Mempool /tip/height API.\n"; return (int)$tipHeightOutput; }
    echo "CRITICAL WARNING: Failed to fetch current block height from all sources for prediction.\n"; return null;
}

function get_network_difficulty(): ?float {
    $result = run_bitcoin_cli('getdifficulty');
    if ($result['output'] !== null && is_numeric($result['output'])) { echo "Fetched difficulty from local node.\n"; return (float)$result['output']; }
    echo "Warning: Failed to get difficulty from local node. Error: " . ($result['error'] ?? 'Unknown shell_exec error') . ". Falling back to ckpool log...\n";
    global $logFilePath;
    if (is_readable($logFilePath)) { $lines = @file($logFilePath); if ($lines !== false) { foreach (array_reverse($lines) as $line) { if (preg_match('/Network diff set to ([\d\.]+)/', $line, $matches)) { echo "Fetched difficulty from ckpool log as fallback.\n"; return (float)$matches[1]; } } } }
    echo "Warning: Failed to get difficulty from any source.\n"; return null;
}

function calculate_local_prediction(int $current_height): ?array {
    $blocks_in_epoch = 2016;
    $last_adjustment_height = floor($current_height / $blocks_in_epoch) * $blocks_in_epoch;
    if ($current_height === $last_adjustment_height && $last_adjustment_height > 0) $last_adjustment_height -= $blocks_in_epoch;
    $blocks_since_adjustment = $current_height - $last_adjustment_height;
    if ($blocks_since_adjustment <= 0) $blocks_since_adjustment = 1;
    $progress = round(($blocks_since_adjustment / $blocks_in_epoch) * 100, 2);
    $min_blocks_for_prediction = 20;
    if ($blocks_since_adjustment < $min_blocks_for_prediction) { echo "Not enough blocks in the current epoch ({$blocks_since_adjustment}/{$min_blocks_for_prediction}) for reliable local prediction calculation.\n"; return ['progress' => $progress, 'prediction' => null, 'avg_time' => null, 'estimated_timestamp' => null]; }
    echo "Calculating prediction locally for epoch starting at {$last_adjustment_height}...\n";
    $startHashResult = run_bitcoin_cli("getblockhash {$last_adjustment_height}");
    if ($startHashResult['output'] === null) { echo "Warning: Failed to get start block hash ({$last_adjustment_height}) for local prediction. Error: " . ($startHashResult['error'] ?? 'null') . "\n"; return ['progress' => $progress, 'prediction' => null, 'avg_time' => null, 'estimated_timestamp' => null]; }
    $startBlockResult = run_bitcoin_cli("getblock {$startHashResult['output']} 1");
    $startBlock = json_decode($startBlockResult['output'], true);
    $start_time = $startBlock['time'] ?? null;
    if ($start_time === null) { echo "Warning: Failed to get start block time for local prediction.\n"; return ['progress' => $progress, 'prediction' => null, 'avg_time' => null, 'estimated_timestamp' => null]; }
    $currentHashResult = run_bitcoin_cli("getblockhash {$current_height}");
     if ($currentHashResult['output'] === null) { echo "Warning: Failed to get current block hash ({$current_height}) for local prediction. Error: " . ($currentHashResult['error'] ?? 'null') . "\n"; return ['progress' => $progress, 'prediction' => null, 'avg_time' => null, 'estimated_timestamp' => null]; }
    $currentBlockResult = run_bitcoin_cli("getblock {$currentHashResult['output']} 1");
    $currentBlock = json_decode($currentBlockResult['output'], true);
    $current_time = $currentBlock['time'] ?? null;
     if ($current_time === null || $current_time <= $start_time) { echo "Warning: Invalid current block time ({$current_time}) vs start time ({$start_time}) for local prediction.\n"; return ['progress' => $progress, 'prediction' => null, 'avg_time' => null, 'estimated_timestamp' => null]; }
    $time_elapsed = $current_time - $start_time;
    $avg_block_time = $time_elapsed / $blocks_since_adjustment;
    $expected_time_for_epoch = $blocks_in_epoch * 600;
    $projected_total_time = $avg_block_time * $blocks_in_epoch;
    if ($projected_total_time == 0) { return ['progress' => $progress, 'prediction' => null, 'avg_time' => round($avg_block_time, 2), 'estimated_timestamp' => null]; }
    $prediction = (($expected_time_for_epoch / $projected_total_time) - 1) * 100;
    $estimated_timestamp = $start_time + $projected_total_time;
    echo "Local prediction calculated successfully.\n";
    return [ 'progress' => $progress, 'prediction' => round($prediction, 2), 'avg_time' => round($avg_block_time, 2), 'estimated_timestamp' => (int)$estimated_timestamp ];
}

// --- Main ---
echo "Prediction parser started at " . date('Y-m-d H:i:s') . "\n";
if (!is_dir($dataDir)) { @mkdir($dataDir, 0775, true); }
if (file_exists($networkDbPath)) { @chown($networkDbPath, $webUser); @chgrp($networkDbPath, $webGroup); }

$pdo = null;
try {
    $pdo = new PDO('sqlite:' . $networkDbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("CREATE TABLE IF NOT EXISTS network_history (id INTEGER PRIMARY KEY, timestamp INTEGER NOT NULL, network_difficulty REAL, network_hashrate_ghs REAL)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS network_stats (id INTEGER PRIMARY KEY, last_update INTEGER, data TEXT)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS prediction_data (id INTEGER PRIMARY KEY, progress REAL, prediction REAL, avg_time REAL, estimated_timestamp INTEGER, hybrid_factors_log TEXT)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_network_timestamp ON network_history (timestamp)");
} catch (PDOException $e) {
    die("CRITICAL ERROR: PDO connection/initialization failed: " . $e->getMessage() . "\n");
}

$now = time();

$current_height = get_block_height_for_prediction();
$network_difficulty_to_store = get_network_difficulty();
$network_hashrate_to_store = get_mempool_hashrate($pdo); 

$local_prediction_data = null;
if ($current_height !== null) { $local_prediction_data = calculate_local_prediction($current_height); }
$mempool_prediction_data = _get_mempool_prediction_data();

$final_prediction_data = [ 'progress' => null, 'prediction' => null, 'avg_time' => null, 'estimated_timestamp' => null, 'hybrid_factors_log' => null ];
$final_prediction_data['progress'] = $local_prediction_data['progress'] ?? $mempool_prediction_data['progress'] ?? null;
$final_prediction_data['avg_time'] = $local_prediction_data['avg_time'] ?? $mempool_prediction_data['avg_time'] ?? null;
$final_prediction_data['estimated_timestamp'] = $local_prediction_data['estimated_timestamp'] ?? $mempool_prediction_data['estimated_timestamp'] ?? null; 

$local_pred_value = $local_prediction_data['prediction'] ?? null;
$mempool_pred_value = $mempool_prediction_data['prediction'] ?? null;
$log_msg = "H.A.N.T.I. Model ?: ";

if ($local_pred_value !== null && $network_hashrate_to_store !== null && $network_difficulty_to_store !== null && $local_prediction_data['avg_time'] > 0) {
    $implied_epoch_hashrate_hs = ($network_difficulty_to_store * pow(2, 32)) / $local_prediction_data['avg_time'];
    $implied_epoch_hashrate_ghs = $implied_epoch_hashrate_hs / 1e9;
    
    if ($implied_epoch_hashrate_ghs > 0) {
        $trend_factor = $network_hashrate_to_store / $implied_epoch_hashrate_ghs;
        $trend_factor = max(0.8, min(1.2, $trend_factor)); 
    } else {
        $trend_factor = 1.0;
    }
    $adjusted_local_prediction = $local_pred_value * $trend_factor;
    $log_msg .= sprintf("Local(%.2f%%) * Trend(%.2f) = Adj.Local(%.2f%%). ", $local_pred_value, $trend_factor, $adjusted_local_prediction);

    if ($mempool_pred_value !== null) {
        $final_prediction_data['prediction'] = round((0.7 * $adjusted_local_prediction) + (0.3 * $mempool_pred_value), 2);
        $log_msg .= sprintf("Hybrid Blend (70%% Adj.Local + 30%% Mempool(%.2f%%)) = Final(%.2f%%).", $mempool_pred_value, $final_prediction_data['prediction']);
        echo $log_msg . "\n";
    } else {
        $final_prediction_data['prediction'] = round($adjusted_local_prediction, 2);
        $log_msg .= "Using Trend-Adjusted Local prediction only.";
        echo $log_msg . "\n";
    }
} elseif ($local_pred_value !== null) {
    $final_prediction_data['prediction'] = $local_pred_value;
    $log_msg = "Using simple local prediction (missing hashrate for trend calc).\n";
    echo $log_msg;
} elseif ($mempool_pred_value !== null) {
    $final_prediction_data['prediction'] = $mempool_pred_value;
    $log_msg = "Using Mempool prediction only (local calculation failed).\n";
    echo $log_msg;
} else {
    $log_msg = "Warning: Could not determine prediction from any source.\n";
    echo $log_msg;
}
$final_prediction_data['hybrid_factors_log'] = $log_msg; 

if ($network_hashrate_to_store === null && $network_difficulty_to_store !== null && $network_difficulty_to_store > 0) { echo "All Hashrate sources (API, DB) failed, calculating from difficulty...\n"; $network_hashrate_to_store = $network_difficulty_to_store * pow(2, 32) / 600 / 1e9; echo "Using calculated network hashrate: " . round($network_hashrate_to_store / 1e9, 2) . " EH/s\n"; }
elseif ($network_hashrate_to_store !== null) { /* Logged */ }
else { echo "Warning: Cannot determine network hashrate from any source.\n"; }

$previous_network_difficulty = null; $stmt_last_diff = $pdo->query("SELECT network_difficulty FROM network_history ORDER BY timestamp DESC LIMIT 1"); $last_recorded_difficulty = $stmt_last_diff ? $stmt_last_diff->fetchColumn() : null; 
$is_new_difficulty = ($last_recorded_difficulty === null) || ($network_difficulty_to_store !== null && abs($last_recorded_difficulty - $network_difficulty_to_store) > 1e-9);
if ($is_new_difficulty) { echo "New difficulty detected ({$network_difficulty_to_store}). Storing in history.\n"; if ($network_difficulty_to_store !== null || $network_hashrate_to_store !== null) { try { $stmt_net_hist = $pdo->prepare("INSERT INTO network_history (timestamp, network_difficulty, network_hashrate_ghs) VALUES (?, ?, ?)"); $stmt_net_hist->execute([$now, $network_difficulty_to_store, $network_hashrate_to_store]); } catch (PDOException $e) { echo "Error inserting network history: " . $e->getMessage() . "\n"; } } $previous_network_difficulty = $last_recorded_difficulty; } else { echo "Difficulty is unchanged. Not writing to history. Finding previous change...\n"; $stmt_prev_diff = $pdo->prepare("SELECT network_difficulty FROM network_history WHERE network_difficulty IS NOT NULL AND ABS(network_difficulty - ?) > 1e-9 ORDER BY timestamp DESC LIMIT 1"); $stmt_prev_diff->execute([$network_difficulty_to_store]); $previous_network_difficulty = $stmt_prev_diff ? $stmt_prev_diff->fetchColumn() : null; if ($previous_network_difficulty === null || $previous_network_difficulty === false) { $stmt_offset = $pdo->query("SELECT network_difficulty FROM network_history WHERE network_difficulty IS NOT NULL ORDER BY timestamp DESC LIMIT 1 OFFSET 1"); $previous_network_difficulty = $stmt_offset ? $stmt_offset->fetchColumn() : null; } }

// --- Store Data ---
$net_data = [
    'network_difficulty' => $network_difficulty_to_store,
    'previous_network_difficulty' => $previous_network_difficulty,
    'network_hashrate' => $network_hashrate_to_store
];
$net_stmt = $pdo->prepare("INSERT OR REPLACE INTO network_stats (id, last_update, data) VALUES (1, ?, ?)");
$net_stmt->execute([$now, json_encode($net_data)]);

if ($final_prediction_data['progress'] !== null) {
    $pred_to_store = $final_prediction_data['prediction'] ?? null;
    $avg_time_to_store = $final_prediction_data['avg_time'] ?? null;
    $ts_to_store = $final_prediction_data['estimated_timestamp'] ?? null; 
    $log_to_store = $final_prediction_data['hybrid_factors_log'] ?? null;

    try {
        $pred_stmt = $pdo->prepare("INSERT OR REPLACE INTO prediction_data (id, progress, prediction, avg_time, estimated_timestamp, hybrid_factors_log) VALUES (1, ?, ?, ?, ?, ?)");
        $pred_stmt->execute([ $final_prediction_data['progress'], $pred_to_store, $avg_time_to_store, $ts_to_store, $log_to_store ]);
        
        if ($pred_to_store !== null && $avg_time_to_store !== null && $ts_to_store !== null) {
            echo "Prediction data updated: Progress {$final_prediction_data['progress']}%, Est. Change {$pred_to_store}%, Avg Time {$avg_time_to_store}s, Est. Date " . date('Y-m-d H:i', $ts_to_store) . "\n";
        } else {
            echo "Prediction data partially updated: Progress {$final_prediction_data['progress']}% (missing prediction/avg_time/timestamp from sources).\n";
        }
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'no column named') !== false) {
            echo "Caught schema error. Attempting to add missing columns to 'prediction_data' table...\n";
            try { $pdo->exec("ALTER TABLE prediction_data ADD COLUMN estimated_timestamp INTEGER"); } catch (PDOException $e_ts) { /* Ignoruj */ }
            try { $pdo->exec("ALTER TABLE prediction_data ADD COLUMN hybrid_factors_log TEXT"); } catch (PDOException $e_log) { /* Ignoruj */ }
            
            echo "Table altered. Retrying data insert...\n";
            try {
                $pred_stmt = $pdo->prepare("INSERT OR REPLACE INTO prediction_data (id, progress, prediction, avg_time, estimated_timestamp, hybrid_factors_log) VALUES (1, ?, ?, ?, ?, ?)");
                $pred_stmt->execute([ $final_prediction_data['progress'], $pred_to_store, $avg_time_to_store, $ts_to_store, $log_to_store ]);
                echo "Data insert successful after schema update.\n";
            } catch (PDOException $e_retry) {
                 echo "CRITICAL ERROR: Failed to insert data even after alter table: " . $e_retry->getMessage() . "\n";
            }
        } else {
            echo "CRITICAL ERROR: Database operation failed: " . $e->getMessage() . "\n";
        }
    }
}
else { echo "Warning: Failed to get valid prediction data from any source.\n"; }

// --- Cleanup ---
// ZMIANA: rand(1, 8) dla uruchomienia ~1 raz dziennie (8 * 3 godz = 24h)
if (rand(1, 8) === 1) { 
    $cutoff = time() - ($retentionDays * 86400); 
    $stmt_cleanup = $pdo->prepare("DELETE FROM network_history WHERE timestamp < ?"); 
    $stmt_cleanup->execute([$cutoff]); 
    $deleted_rows = $stmt_cleanup->rowCount(); 
    echo "Cleaned up {$deleted_rows} old network history records.\n"; 
}
if(file_exists($networkDbPath)) { chown($networkDbPath, $webUser); chgrp($networkDbPath, $webGroup); }
echo "Prediction parser finished successfully.\n";

?>