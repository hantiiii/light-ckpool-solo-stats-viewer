#!/usr/bin/php
<?php
require_once __DIR__ . '/common.php';

// --- CONFIGURATION ---
$dataDir = __DIR__ . '/data';
$networkDbPath = $dataDir . '/network.db';
$webUser = 'web1';
$webGroup = 'client1';

// --- DB INIT ---
if (!is_dir($dataDir)) { mkdir($dataDir, 0775, true); }

try {
    $pdo = new PDO('sqlite:' . $networkDbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // 1. AUTO-FIX DLA PREDICTION_DATA (Już to zrobiłeś, ale zostawiam dla bezpieczeństwa)
    $checkCols = $pdo->query("PRAGMA table_info(prediction_data)")->fetchAll(PDO::FETCH_COLUMN, 1);
    if (!empty($checkCols) && !in_array('timestamp', $checkCols)) {
        $pdo->exec("DROP TABLE prediction_data");
    }

    // Tworzenie tabel (jeśli nie istnieją)
    $pdo->exec("CREATE TABLE IF NOT EXISTS prediction_data (id INTEGER PRIMARY KEY, timestamp INTEGER, progress REAL, prediction REAL, estimated_timestamp INTEGER, hybrid_factors_log TEXT)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS network_history (timestamp INTEGER PRIMARY KEY, network_hashrate_ghs REAL, network_difficulty REAL, block_height INTEGER)");

    // 2. AUTO-FIX DLA NETWORK_HISTORY (To naprawia Twój obecny błąd)
    // Sprawdzamy czy tabela ma kolumnę 'block_height'. Jeśli nie -> dodajemy ją.
    $colsNet = $pdo->query("PRAGMA table_info(network_history)")->fetchAll(PDO::FETCH_COLUMN, 1);
    
    if (!in_array('block_height', $colsNet)) {
        echo "Migrating DB: Adding column 'block_height' to network_history...\n";
        try {
            $pdo->exec("ALTER TABLE network_history ADD COLUMN block_height INTEGER DEFAULT 0");
        } catch (Exception $e) { 
            // Ignorujemy błędy, jeśli kolumna już jakimś cudem jest
        }
    }

} catch (PDOException $e) { die("DB ERROR: " . $e->getMessage() . "\n"); }

echo "--- H.A.N.T.I. Prediction Engine v7 (Final Fix) ---\n";

// 1. Pobierz dane z Noda
$cliResult = run_bitcoin_cli('getblockchaininfo');

if ($cliResult['error'] !== null || empty($cliResult['output'])) {
    die("ERROR: Bitcoin Node Error: " . ($cliResult['error'] ?? 'Empty output') . "\n");
}

$info = json_decode($cliResult['output'], true);

if (!isset($info['headers'])) {
    die("ERROR: JSON decode failed for getblockchaininfo.\n");
}

$currentHeight = (int)$info['headers'];
$currentDiff = (float)$info['difficulty'];

echo "Current Height: $currentHeight\n";
echo "Current Diff:   " . number_format($currentDiff) . "\n";

// 2. Oblicz dane cyklu (Epoch)
$blocksPerEpoch = 2016;
$lastRetargetHeight = floor($currentHeight / $blocksPerEpoch) * $blocksPerEpoch;
$blocksMinedInEpoch = $currentHeight - $lastRetargetHeight;
$progressPercent = ($blocksMinedInEpoch / $blocksPerEpoch) * 100;

echo "Epoch Progress: " . number_format($progressPercent, 2) . "% ($blocksMinedInEpoch / $blocksPerEpoch blocks)\n";

// 3. Pobierz czas ostatniego retargetu
// a) Hash bloku
$hashCli = run_bitcoin_cli("getblockhash $lastRetargetHeight");

if ($hashCli['error']) {
    die("ERROR: getblockhash failed: " . $hashCli['error'] . "\n");
}
$blockHash = trim($hashCli['output']);

// b) Nagłówek bloku
$headerCli = run_bitcoin_cli("getblockheader $blockHash");
$headerData = json_decode($headerCli['output'] ?? '{}', true);

if (!isset($headerData['time'])) {
    $timeStart = time() - ($blocksMinedInEpoch * 600); 
    echo "WARNING: Could not fetch block time directly. Using approximation.\n";
} else {
    $timeStart = $headerData['time'];
}

// 4. Matematyka Predykcji
$timeNow = time();
$timeElapsed = $timeNow - $timeStart;
$expectedTime = $blocksMinedInEpoch * 600; 

if ($timeElapsed > 0 && $blocksMinedInEpoch > 10) {
    $ratio = $expectedTime / $timeElapsed;
    $changePercent = ($ratio - 1) * 100;
    
    $remainingBlocks = $blocksPerEpoch - $blocksMinedInEpoch;
    $avgBlockTime = $timeElapsed / $blocksMinedInEpoch; 
    $timeRemaining = $remainingBlocks * $avgBlockTime;
    $estimatedDate = $timeNow + $timeRemaining;
    
    $networkHashrate = ($currentDiff * pow(2, 32)) / $avgBlockTime / 1e9; 
} else {
    $changePercent = 0;
    $estimatedDate = $timeNow + ((2016 - $blocksMinedInEpoch) * 600);
    $networkHashrate = ($currentDiff * pow(2, 32)) / 600 / 1e9;
}

echo "Prediction:     " . ($changePercent > 0 ? "+" : "") . number_format($changePercent, 2) . "%\n";
echo "Est. Date:      " . date('Y-m-d H:i', $estimatedDate) . "\n";
echo "Net Hashrate:   " . number_format($networkHashrate / 1000000, 2) . " PH/s\n";

// 5. Zapis do bazy
$logMsg = "H.A.N.T.I. v7: Active (Local Node Data).";

$stmt = $pdo->prepare("INSERT OR REPLACE INTO prediction_data (id, timestamp, progress, prediction, estimated_timestamp, hybrid_factors_log) VALUES (1, ?, ?, ?, ?, ?)");
$stmt->execute([
    $timeNow,
    round($progressPercent, 2),
    round($changePercent, 2),
    (int)$estimatedDate,
    $logMsg
]);

echo "SUCCESS: Prediction saved.\n";

// 6. Zapis historii (Teraz zadziała, bo naprawiliśmy tabelę na górze)
$lastHist = $pdo->query("SELECT MAX(timestamp) FROM network_history")->fetchColumn();
if (!$lastHist || ($timeNow - $lastHist) > 3600) {
    $pdo->prepare("INSERT INTO network_history (timestamp, network_hashrate_ghs, network_difficulty, block_height) VALUES (?, ?, ?, ?)")
        ->execute([$timeNow, $networkHashrate, $currentDiff, $currentHeight]);
    echo "History saved.\n";
}

if(file_exists($networkDbPath)) { @chown($networkDbPath, $webUser); @chgrp($networkDbPath, $webGroup); }
?>