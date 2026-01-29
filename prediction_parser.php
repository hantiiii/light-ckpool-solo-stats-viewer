#!/usr/bin/php
<?php
require_once __DIR__ . '/common.php';

// --- CONFIGURATION v90 ---
$dataDir = __DIR__ . '/data';
$networkDbPath = $dataDir . '/network.db';
$webUser = 'web1';
$webGroup = 'client1';

// --- DB INIT ---
if (!is_dir($dataDir)) { mkdir($dataDir, 0775, true); }

try {
    $pdo = new PDO('sqlite:' . $networkDbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("PRAGMA journal_mode = DELETE");
    $pdo->exec("PRAGMA synchronous = NORMAL");
    
    // Structure
    $pdo->exec("CREATE TABLE IF NOT EXISTS prediction_data (id INTEGER PRIMARY KEY, timestamp INTEGER, progress REAL, prediction REAL, estimated_timestamp INTEGER, hybrid_factors_log TEXT)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS network_history (timestamp INTEGER PRIMARY KEY, network_hashrate_ghs REAL, network_difficulty REAL, block_height INTEGER)");
    
    // PERFORMANCE OPTIMIZATION: Indexes
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_net_hist_ts ON network_history(timestamp)");

    // FixSchema
    $colsNet = $pdo->query("PRAGMA table_info(network_history)")->fetchAll(PDO::FETCH_COLUMN, 1);
    if (!in_array('block_height', $colsNet)) { try { $pdo->exec("ALTER TABLE network_history ADD COLUMN block_height INTEGER DEFAULT 0"); } catch(Exception $e){} }

} catch (PDOException $e) { die("DB ERROR: " . $e->getMessage() . "\n"); }

echo "--- H.A.N.T.I. v8 Catalyst Prediction Engine ---\n";

// 1. Get Node Data (Secure Array Call)
$cliResult = run_bitcoin_cli(['getblockchaininfo']);
if ($cliResult['error'] !== null || empty($cliResult['output'])) { die("ERROR: Node Error: " . ($cliResult['error'] ?? 'Empty') . "\n"); }

$info = json_decode($cliResult['output'], true);
$currentHeight = (int)$info['headers'];
$currentDiff = (float)$info['difficulty'];

echo "Height: $currentHeight | Diff: " . number_format($currentDiff) . "\n";

// 2. Epoch Calculation
$blocksPerEpoch = 2016;
$lastRetargetHeight = floor($currentHeight / $blocksPerEpoch) * $blocksPerEpoch;
$blocksMinedInEpoch = $currentHeight - $lastRetargetHeight;
$progressPercent = ($blocksMinedInEpoch / $blocksPerEpoch) * 100;

// 3. Epoch Start Time
$hashCli = run_bitcoin_cli(['getblockhash', (int)$lastRetargetHeight]);
$blockHash = trim($hashCli['output']);
$headerCli = run_bitcoin_cli(['getblockheader', $blockHash]);
$headerData = json_decode($headerCli['output'] ?? '{}', true);
$timeStart = $headerData['time'] ?? (time() - ($blocksMinedInEpoch * 600));

// 4. Mathematics
$timeNow = time();
$timeElapsed = $timeNow - $timeStart;
$expectedTime = $blocksMinedInEpoch * 600; 

if ($timeElapsed > 0 && $blocksMinedInEpoch > 10) {
    $ratio = $expectedTime / $timeElapsed;
    $changePercent = ($ratio - 1) * 100;
    $avgBlockTime = $timeElapsed / $blocksMinedInEpoch; 
    $remainingBlocks = $blocksPerEpoch - $blocksMinedInEpoch;
    $estimatedDate = $timeNow + ($remainingBlocks * $avgBlockTime);
    $networkHashrate = ($currentDiff * pow(2, 32)) / $avgBlockTime / 1e9; 
} else {
    $changePercent = 0;
    $estimatedDate = $timeNow + ((2016 - $blocksMinedInEpoch) * 600);
    $networkHashrate = ($currentDiff * pow(2, 32)) / 600 / 1e9;
}

echo "Pred: " . number_format($changePercent, 2) . "% | Est: " . date('Y-m-d H:i', $estimatedDate) . "\n";

// 5. Save State
$stmt = $pdo->prepare("INSERT OR REPLACE INTO prediction_data (id, timestamp, progress, prediction, estimated_timestamp, hybrid_factors_log) VALUES (1, ?, ?, ?, ?, ?)");
$stmt->execute([$timeNow, round($progressPercent, 2), round($changePercent, 2), (int)$estimatedDate, "H.A.N.T.I. v8 Catalyst Active"]);

// 6. Save History (Hourly)
$lastHist = $pdo->query("SELECT MAX(timestamp) FROM network_history")->fetchColumn();
if (!$lastHist || ($timeNow - $lastHist) > 3600) {
    $pdo->prepare("INSERT INTO network_history (timestamp, network_hashrate_ghs, network_difficulty, block_height) VALUES (?, ?, ?, ?)")
        ->execute([$timeNow, $networkHashrate, $currentDiff, $currentHeight]);
    echo "History saved.\n";
}

$pdo = null;
@chown($networkDbPath, $webUser); @chgrp($networkDbPath, $webGroup);
?>