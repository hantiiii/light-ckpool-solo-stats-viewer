#!/usr/bin/php
<?php
require_once __DIR__ . '/common.php';

// --- Configuration ---
$dataDir = __DIR__ . '/data';
$networkDbPath = $dataDir . '/network.db';
$statsDbPath = $dataDir . '/stats.db'; 
$logFilePath = '/var/log/ckpool/ckpool.log';
$webUser = 'web1';
$webGroup = 'client1';
$retentionDays = 730; 
define('SATOSHIS_PER_BTC', 100000000);

// --- Functions ---
function get_block_time_at_height($height) {
    $hashCmd = run_bitcoin_cli("getblockhash {$height}");
    $hash = trim($hashCmd['output'] ?? '');
    if (empty($hash) || strpos($hash, 'error') !== false) return null;
    $blockCmd = run_bitcoin_cli("getblock {$hash} 1"); 
    $data = json_decode($blockCmd['output'] ?? '', true);
    return $data['time'] ?? null;
}

function get_block_height_for_prediction(): ?int {
    $result = run_bitcoin_cli('getblockcount');
    if ($result['output'] !== null && is_numeric($result['output'])) {
        echo "Fetched block height from local node.\n";
        return (int)$result['output'];
    }
    // Fallback API
    $tipHeight = api_fetch('https://mempool.space/api/blocks/tip/height');
    if ($tipHeight && is_numeric($tipHeight)) return (int)$tipHeight;
    return null;
}

function get_network_difficulty(): ?float {
    $result = run_bitcoin_cli('getdifficulty');
    if ($result['output'] !== null && is_numeric($result['output'])) { return (float)$result['output']; }
    global $logFilePath;
    if (is_readable($logFilePath)) { $lines = @file($logFilePath); if ($lines !== false) { foreach (array_reverse($lines) as $line) { if (preg_match('/Network diff set to ([\d\.]+)/', $line, $matches)) return (float)$matches[1]; } } }
    return null;
}

function get_mempool_hashrate_api() {
    $hashrateOutput = api_fetch('https://mempool.space/api/v1/mining/hashrate/1d');
    if ($hashrateOutput) { $hrData = json_decode($hashrateOutput, true); if ($hrData && isset($hrData['currentHashrate']) && is_numeric($hrData['currentHashrate'])) { return $hrData['currentHashrate'] / 1e9; } }
    return null;
}

function _get_mempool_prediction_data() {
    $difficultyOutput = api_fetch('https://mempool.space/api/v1/difficulty-adjustment');
     if ($difficultyOutput) { $diffData = json_decode($difficultyOutput, true); if ($diffData && isset($diffData['progressPercent'])) { return [ 'progress' => round($diffData['progressPercent'], 2), 'prediction' => round($diffData['difficultyChange'], 2), 'avg_time' => (isset($diffData['timeAvg']) ? ($diffData['timeAvg'] / 1000) : 600), 'estimated_timestamp' => (int)($diffData['estimatedRetargetDate'] / 1000) ]; } }
    return null;
}

// H.A.N.T.I. v7 "Solaris" Logic
function calculate_solaris_prediction(int $current_height, float $network_difficulty): array {
    $blocks_in_epoch = 2016;
    $last_adjustment_height = floor($current_height / $blocks_in_epoch) * $blocks_in_epoch;
    // Fix: jeśli jesteśmy dokładnie na bloku zmiany, to ostatnia zmiana była 2016 temu
    if ($current_height === $last_adjustment_height && $last_adjustment_height > 0) $last_adjustment_height -= $blocks_in_epoch;
    
    $blocks_since_adjustment = $current_height - $last_adjustment_height;
    if ($blocks_since_adjustment <= 0) $blocks_since_adjustment = 1;
    
    $progress = round(($blocks_since_adjustment / $blocks_in_epoch) * 100, 2);

    // 1. Sliding Window Hashrate (3 Days / 432 Blocks)
    // To jest serce v7. Liczymy hashrate nie z obecnej epoki, ale z ostatnich 3 dni.
    // Dzięki temu, na początku epoki nie mamy szumów.
    $window_size = 432; 
    $window_start_height = $current_height - $window_size;
    $local_hashrate_ghs = null;
    $real_avg_time_window = 600;

    $w_start_time = get_block_time_at_height($window_start_height);
    $w_current_time = get_block_time_at_height($current_height);

    if ($w_start_time && $w_current_time && $w_current_time > $w_start_time) {
        $window_time_diff = $w_current_time - $w_start_time;
        $real_avg_time_window = $window_time_diff / $window_size;
        if ($real_avg_time_window > 0) {
            // Hashrate = (Diff * 2^32) / Time
            $local_hashrate_ghs = (($network_difficulty * pow(2, 32)) / $real_avg_time_window) / 1e9;
        }
    }

    // 2. Projekcja
    // Zakładamy, że ten "3-dniowy hashrate" utrzyma się do końca obecnej epoki.
    $blocks_remaining = $blocks_in_epoch - $blocks_since_adjustment;
    
    // Ile czasu już minęło w tej epoce?
    $epoch_start_time = get_block_time_at_height($last_adjustment_height);
    $time_elapsed_epoch = ($w_current_time && $epoch_start_time) ? ($w_current_time - $epoch_start_time) : ($blocks_since_adjustment * 600);
    
    // Ile czasu zajmie reszta epoki? (Bazując na hashrate z okna 3 dni)
    $time_remaining_projected = $blocks_remaining * $real_avg_time_window;
    
    // Sumaryczny przewidywany czas epoki
    $total_epoch_time_projected = $time_elapsed_epoch + $time_remaining_projected;
    $target_epoch_time = $blocks_in_epoch * 600;
    
    // Predykcja: (Oczekiwany / Projektowany) - 1
    $prediction = (($target_epoch_time / $total_epoch_time_projected) - 1) * 100;
    $estimated_timestamp = $w_current_time + $time_remaining_projected;

    return [
        'progress' => $progress,
        'prediction' => $prediction,
        'local_hashrate_ghs' => $local_hashrate_ghs,
        'avg_time' => $real_avg_time_window,
        'estimated_timestamp' => (int)$estimated_timestamp,
        'blocks_since' => $blocks_since_adjustment
    ];
}

function get_7_day_avg_price(): ?float {
    $output = api_fetch('https://api.kraken.com/0/public/OHLC?pair=XBTUSD&interval=1440&since=' . (time() - 8 * 86400));
    if ($output) { $data = json_decode($output, true); if (isset($data['result']['XXBTZUSD'])) { $candles = array_slice($data['result']['XXBTZUSD'], -7); if (count($candles) < 7) return null; $total = 0; foreach ($candles as $c) $total += (float)$c[4]; return $total / 7; } } return null;
}

// --- Main ---
echo "Prediction parser (HANTI v7 Solaris) started at " . date('Y-m-d H:i:s') . "\n";
if (!is_dir($dataDir)) { @mkdir($dataDir, 0775, true); }
if (file_exists($networkDbPath)) { @chown($networkDbPath, $webUser); @chgrp($networkDbPath, $webGroup); }

$pdo = null;
try {
    $pdo = new PDO('sqlite:' . $networkDbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("CREATE TABLE IF NOT EXISTS network_history (id INTEGER PRIMARY KEY, timestamp INTEGER NOT NULL, network_difficulty REAL, network_hashrate_ghs REAL)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS network_stats (id INTEGER PRIMARY KEY, last_update INTEGER, data TEXT)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS prediction_data (id INTEGER PRIMARY KEY, progress REAL, prediction REAL, avg_time REAL, estimated_timestamp INTEGER, hybrid_factors_log TEXT)");
} catch (PDOException $e) { die("CRITICAL ERROR: PDO connection failed.\n"); }

$now = time();

// 1. Dane
$current_height = get_block_height_for_prediction();
$network_difficulty = get_network_difficulty();

if ($current_height === null || $network_difficulty === null) { die("Error: Critical data missing.\n"); }

// 2. Obliczenia (H.A.N.T.I. v7)
$solaris_data = calculate_solaris_prediction($current_height, $network_difficulty);

// Sanity Check dla Hashrate'u (musi być > 100 EH/s)
$local_hr = $solaris_data['local_hashrate_ghs'];
$is_valid = ($local_hr !== null && ($local_hr / 1e9) > 100);

$final_prediction = 0.0;
$log_msg = "H.A.N.T.I. v7 (Solaris) ☀️: ";

if ($is_valid) {
    $final_prediction = $solaris_data['prediction'];
    $log_msg .= sprintf("SmoothHash(3d): %.2f EH/s. Base Pred: %.2f%%. ", $local_hr/1e9, $final_prediction);
} else {
    // Fallback do API, jeśli lokalne obliczenia (np. z powodu braku bloków w lokalnym węźle) zawiodą
    echo "Local calc invalid. Fallback to API.\n";
    $mempool_data = _get_mempool_prediction_data();
    if ($mempool_data) {
        $final_prediction = $mempool_data['prediction'];
        $log_msg .= sprintf("API Fallback: %.2f%%. ", $final_prediction);
        if ($mempool_data['estimated_timestamp']) $solaris_data['estimated_timestamp'] = $mempool_data['estimated_timestamp'];
    }
    // Fix hashrate for storage
    $local_hr = get_mempool_hashrate_api();
}

// 3. Korekta WEC (Cena)
$current_price = null;
if (file_exists($statsDbPath)) { try { $res = $pdo->query("ATTACH DATABASE '$statsDbPath' AS stats")->fetchAll(); $res = $pdo->query("SELECT data FROM stats.pool_stats WHERE id = 1")->fetchColumn(); if ($res) { $d = json_decode($res, true); $current_price = $d['btc_usd_price'] ?? null; } $pdo->exec("DETACH DATABASE stats"); } catch (Exception $e) {} }
$avg_price = get_7_day_avg_price();
if ($current_price && $avg_price > 0) {
    $price_trend = (($current_price / $avg_price) - 1) * 100;
    $price_adj = max(-2.5, min(2.5, $price_trend * 0.2));
    $final_prediction += $price_adj;
    $log_msg .= sprintf("Price(%+.2f%%) adj %+.2f%%. ", $price_trend, $price_adj);
}

$final_prediction = round($final_prediction, 2);
$log_msg .= sprintf("Final: %+.2f%%", $final_prediction);
echo $log_msg . "\n";

// 4. ZAPIS
// Network History (tylko zmiany diff)
$stmt_last = $pdo->query("SELECT network_difficulty FROM network_history ORDER BY timestamp DESC LIMIT 1");
$last_diff = $stmt_last ? $stmt_last->fetchColumn() : null;
if ($network_difficulty !== null) {
    if ($last_diff === null || abs($last_diff - $network_difficulty) > 0.000001) {
        echo "New difficulty stored.\n";
        if ($local_hr !== null) $pdo->prepare("INSERT INTO network_history (timestamp, network_difficulty, network_hashrate_ghs) VALUES (?, ?, ?)")->execute([$now, $network_difficulty, $local_hr]);
    }
}

// Prev diff
$prev_diff = null;
if ($network_difficulty !== null) {
    $stmt_prev = $pdo->prepare("SELECT network_difficulty FROM network_history WHERE network_difficulty IS NOT NULL AND ABS(network_difficulty - ?) > 0.000001 ORDER BY timestamp DESC LIMIT 1");
    $stmt_prev->execute([$network_difficulty]);
    $prev_diff = $stmt_prev->fetchColumn();
}

// Live Stats
$net_data = [ 'network_difficulty' => $network_difficulty, 'previous_network_difficulty' => $prev_diff, 'network_hashrate' => $local_hr ];
$pdo->prepare("INSERT OR REPLACE INTO network_stats (id, last_update, data) VALUES (1, ?, ?)")->execute([$now, json_encode($net_data)]);

// Prediction Data
if ($solaris_data['progress'] !== null) {
    try {
        $pdo->prepare("INSERT OR REPLACE INTO prediction_data (id, progress, prediction, avg_time, estimated_timestamp, hybrid_factors_log) VALUES (1, ?, ?, ?, ?, ?)")
            ->execute([$solaris_data['progress'], $final_prediction, $solaris_data['avg_time'], $solaris_data['estimated_timestamp'], $log_msg]);
        echo "Prediction saved.\n";
    } catch (PDOException $e) {}
}

// Cleanup
if (rand(1, 8) === 1) { $cutoff = time() - ($retentionDays * 86400); $pdo->prepare("DELETE FROM network_history WHERE timestamp < ?")->execute([$cutoff]); }
if(file_exists($networkDbPath)) { chown($networkDbPath, $webUser); chgrp($networkDbPath, $webGroup); }
echo "Finished.\n";
?>