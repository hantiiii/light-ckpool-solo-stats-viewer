#!/usr/bin/php
<?php
require_once __DIR__ . '/common.php';

// --- CONFIGURATION ---
$poolStatusFile = '/var/log/ckpool/pool/pool.status'; 
$usersDir       = '/var/log/ckpool/users/'; 
$lastRunFile    = __DIR__ . '/data/parser_last_run.txt';

$dataDir = __DIR__ . '/data';
$statsDbPath = $dataDir . '/stats.db';
$webUser = 'web1';
$webGroup = 'client1';

// --- HELPER FUNCTIONS ---
function parse_hashrate_to_ghs_local($hashrateStr) {
    if (is_numeric($hashrateStr)) return (float)$hashrateStr;
    $value = (float)$hashrateStr;
    $unit = strtoupper(substr(trim((string)$hashrateStr), -1));
    if (!in_array($unit, ['K','M','G','T','P','E'])) return $value;
    switch ($unit) {
        case 'K': return $value / 1000000;
        case 'M': return $value / 1000;
        case 'G': return $value;
        case 'T': return $value * 1000;
        case 'P': return $value * 1000 * 1000;
        case 'E': return $value * 1000 * 1000 * 1000;
        default: return $value;
    }
}

function get_real_node_data() {
    $data = ['height' => 0, 'reward' => 0, 'price' => 0];
    
    // 1. Block Height
    $cliCount = run_bitcoin_cli('getblockcount');
    
    if ($cliCount['error'] === null && is_numeric(trim($cliCount['output']))) {
        $data['height'] = (int)trim($cliCount['output']);
        
        // 2. Block Reward
        $statsCmd = "getblockstats {$data['height']} '[\"subsidy\",\"totalfee\"]'";
        $stats = run_bitcoin_cli($statsCmd);
        $statsData = json_decode($stats['output'] ?? '{}', true);

        if (isset($statsData['subsidy']) && isset($statsData['totalfee'])) {
            $totalSats = $statsData['subsidy'] + $statsData['totalfee'];
            $data['reward'] = $totalSats / 100000000;
        } else {
            $subsidy = 50;
            $halvings = floor($data['height'] / 210000);
            $data['reward'] = $subsidy / pow(2, $halvings); 
        }
    } else {
        echo "WARNING: Local node unreachable (" . ($cliCount['error'] ?? 'Unknown') . ").\n";
    }

    // 3. Price
    $json = api_fetch('https://api.kraken.com/0/public/Ticker?pair=XBTUSD');
    if ($json) {
        $d = json_decode($json, true);
        if (isset($d['result']['XXBTZUSD']['c'][0])) {
            $data['price'] = (float)$d['result']['XXBTZUSD']['c'][0];
        }
    }
    
    return $data;
}

// --- DB INIT ---
if (!is_dir($dataDir)) { mkdir($dataDir, 0775, true); }

try {
    $pdo = new PDO('sqlite:' . $statsDbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // WYŁĄCZAMY WAL (Powrót do kompatybilności z ROOTem)
    // Dzięki temu nie powstają pliki root-only (.wal/.shm), które blokują stronę www.
    $pdo->exec("PRAGMA journal_mode = DELETE"); 
    $pdo->exec("PRAGMA synchronous = NORMAL");

    $pdo->exec("CREATE TABLE IF NOT EXISTS pool_stats (id INTEGER PRIMARY KEY, last_update INTEGER, data TEXT)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS pool_history (timestamp INTEGER PRIMARY KEY, hashrate_1m_ghs REAL DEFAULT 0, hashrate_5m_ghs REAL DEFAULT 0, hashrate_1h_ghs REAL DEFAULT 0, shares INTEGER DEFAULT 0, users INTEGER DEFAULT 0, workers INTEGER DEFAULT 0, accepted INTEGER DEFAULT 0, rejected INTEGER DEFAULT 0)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS pool_daily_history (date INTEGER PRIMARY KEY, avg_hashrate_ghs REAL, accepted INTEGER DEFAULT 0, rejected INTEGER DEFAULT 0)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS user_stats (btc_address TEXT PRIMARY KEY, last_update INTEGER, data TEXT)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS user_daily_history (date INTEGER, btc_address TEXT, worker_name TEXT, avg_hashrate_ghs REAL, PRIMARY KEY (date, btc_address, worker_name))");
    $pdo->exec("CREATE TABLE IF NOT EXISTS user_hourly_history (time_bucket INTEGER, btc_address TEXT, worker_name TEXT, avg_hashrate_ghs REAL, PRIMARY KEY (time_bucket, btc_address, worker_name))");

    $colsH = $pdo->query("PRAGMA table_info(pool_history)")->fetchAll(PDO::FETCH_COLUMN, 1);
    $reqH = ['hashrate_1m_ghs','hashrate_5m_ghs','hashrate_1h_ghs','shares','users','workers','accepted','rejected'];
    foreach($reqH as $c) { if(!in_array($c, $colsH)) $pdo->exec("ALTER TABLE pool_history ADD COLUMN $c NUMERIC DEFAULT 0"); }

} catch (PDOException $e) { die("DB ERROR: " . $e->getMessage() . "\n"); }

$now = time();
$startTime = microtime(true);

// --- 0. DANE RYNKOWE ---
$nodeData = get_real_node_data();
echo "NODE: Height {$nodeData['height']} | Reward {$nodeData['reward']} | Price \${$nodeData['price']}\n";

// --- 1. POOL STATS ---
$poolData = [ 'hashrate1m' => 0, 'hashrate5m' => 0, 'hashrate1hr' => 0, 'Users' => 0, 'Workers' => 0, 'accepted' => 0, 'rejected' => 0, 'shares' => 0 ];
$poolData['last_fetched_block_height'] = $nodeData['height'];
$poolData['last_block_reward_btc'] = $nodeData['reward'];
$poolData['btc_usd_price'] = $nodeData['price'];

if (file_exists($poolStatusFile)) {
    $lines = file($poolStatusFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $json = json_decode($line, true);
        if (!$json) continue;
        if (isset($json['Users'])) { $poolData['Users'] = $json['Users']; $poolData['Workers'] = $json['Workers']; }
        if (isset($json['hashrate1hr'])) {
            $poolData['hashrate1m'] = parse_hashrate_to_ghs_local($json['hashrate1m']);
            $poolData['hashrate5m'] = parse_hashrate_to_ghs_local($json['hashrate5m']);
            $poolData['hashrate1hr'] = parse_hashrate_to_ghs_local($json['hashrate1hr']);
        }
        if (isset($json['accepted'])) { 
            $poolData['accepted'] = $json['accepted']; 
            $poolData['rejected'] = $json['rejected']; 
            $poolData['shares'] = $json['accepted'] + $json['rejected'];
        }
    }
    $total = $poolData['accepted'] + $poolData['rejected'];
    $poolData['rejected_percent'] = $total > 0 ? ($poolData['rejected'] / $total) * 100 : 0;

    $pdo->beginTransaction();
    $pdo->prepare("INSERT OR REPLACE INTO pool_stats (id, last_update, data) VALUES (1, ?, ?)")->execute([$now, json_encode($poolData)]);
    $stmt = $pdo->prepare("INSERT INTO pool_history (timestamp, hashrate_1m_ghs, hashrate_5m_ghs, hashrate_1h_ghs, shares, users, workers, accepted, rejected) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([$now, $poolData['hashrate1m'], $poolData['hashrate5m'], $poolData['hashrate1hr'], $poolData['shares'], $poolData['Users'], $poolData['Workers'], $poolData['accepted'], $poolData['rejected']]);

    $today = strtotime('today midnight');
    $stmtAvg = $pdo->prepare("SELECT avg_hashrate_ghs FROM pool_daily_history WHERE date = ?");
    $stmtAvg->execute([$today]);
    $oldAvg = $stmtAvg->fetchColumn();
    $newAvg = $oldAvg ? ($oldAvg + $poolData['hashrate1hr']) / 2 : $poolData['hashrate1hr'];
    $pdo->prepare("INSERT OR REPLACE INTO pool_daily_history (date, avg_hashrate_ghs, accepted, rejected) VALUES (?, ?, ?, ?)")->execute([$today, $newAvg, $poolData['accepted'], $poolData['rejected']]);
    $pdo->commit();

    echo "POOL: OK. HR 1h: " . number_format($poolData['hashrate1hr'], 2) . " GH/s.\n";
}

// --- 2. USER STATS (INCREMENTAL) ---
$lastRunTime = 0;
if (file_exists($lastRunFile)) { $lastRunTime = (int)file_get_contents($lastRunFile); }

if (is_dir($usersDir)) {
    $stmtUserStats = $pdo->prepare("INSERT OR REPLACE INTO user_stats (btc_address, last_update, data) VALUES (?, ?, ?)");
    $hourBucket = floor($now / 3600) * 3600;
    $stmtSelectHourly = $pdo->prepare("SELECT avg_hashrate_ghs FROM user_hourly_history WHERE time_bucket = ? AND btc_address = ? AND worker_name = ?");
    $stmtInsertHourly = $pdo->prepare("INSERT OR REPLACE INTO user_hourly_history (time_bucket, btc_address, worker_name, avg_hashrate_ghs) VALUES (?, ?, ?, ?)");
    $stmtSelectDaily = $pdo->prepare("SELECT avg_hashrate_ghs FROM user_daily_history WHERE date = ? AND btc_address = ? AND worker_name = ?");
    $stmtInsertDaily = $pdo->prepare("INSERT OR REPLACE INTO user_daily_history (date, btc_address, worker_name, avg_hashrate_ghs) VALUES (?, ?, ?, ?)");

    $files = glob($usersDir . '*');
    $processedCount = 0; $skippedCount = 0;

    $pdo->beginTransaction();
    foreach ($files as $file) {
        if (filemtime($file) < $lastRunTime) { $skippedCount++; continue; }
        $filename = basename($file);
        if ($filename == '.' || $filename == '..') continue;
        
        $content = file_get_contents($file);
        $userData = json_decode($content, true);

        if ($userData) {
            $btcAddress = $filename;
            $stmtUserStats->execute([$btcAddress, $now, $content]);
            $uHr1h = parse_hashrate_to_ghs_local($userData['hashrate1hr'] ?? 0);
            
            $stmtSelectHourly->execute([$hourBucket, $btcAddress, '_AGGREGATE_']);
            $currUAvg = $stmtSelectHourly->fetchColumn();
            $newUAvg = $currUAvg ? ($currUAvg + $uHr1h) / 2 : $uHr1h;
            $stmtInsertHourly->execute([$hourBucket, $btcAddress, '_AGGREGATE_', $newUAvg]);

            $stmtSelectDaily->execute([$today, $btcAddress, '_AGGREGATE_']);
            $currUDAvg = $stmtSelectDaily->fetchColumn();
            $newUDAvg = $currUDAvg ? ($currUDAvg + $uHr1h) / 2 : $uHr1h;
            $stmtInsertDaily->execute([$today, $btcAddress, '_AGGREGATE_', $newUDAvg]);

            if (isset($userData['worker']) && is_array($userData['worker'])) {
                foreach ($userData['worker'] as $w) {
                    $wName = $w['workername'];
                    if (strpos($wName, '.') !== false) { $parts = explode('.', $wName); $wNameShort = end($parts); } else { $wNameShort = $wName; }
                    $wHr1h = parse_hashrate_to_ghs_local($w['hashrate1hr'] ?? 0);
                    $stmtSelectHourly->execute([$hourBucket, $btcAddress, $wNameShort]);
                    $currWAvg = $stmtSelectHourly->fetchColumn();
                    $newWAvg = $currWAvg ? ($currWAvg + $wHr1h) / 2 : $wHr1h;
                    $stmtInsertHourly->execute([$hourBucket, $btcAddress, $wNameShort, $newWAvg]);
                }
            }
            $processedCount++;
        }
    }
    $pdo->commit();
    echo "USERS: Processed $processedCount. Skipped $skippedCount.\n";
}

file_put_contents($lastRunFile, time());
$execTime = microtime(true) - $startTime;
echo "DONE: Execution time: " . number_format($execTime, 3) . "s\n";

// --- CLEANUP & PERMISSIONS (FIX DLA ROOT vs WEB1) ---
// To jest najważniejsza część. Ponieważ skrypt chodzi jako ROOT, 
// musimy na koniec oddać pliki użytkownikowi web1, żeby strona mogła je czytać.
if (rand(1, 50) === 1) { $cutoff = time() - (30 * 86400); $pdo->prepare("DELETE FROM pool_history WHERE timestamp < ?")->execute([$cutoff]); }

// Zamknij połączenie przed chown
$pdo = null;

// Nadaj uprawnienia (naprawa po roocie)
@chown($statsDbPath, $webUser); @chgrp($statsDbPath, $webGroup);
@chown($lastRunFile, $webUser); @chgrp($lastRunFile, $webGroup);
// Na wszelki wypadek katalog danych
@chown($dataDir, $webUser); @chgrp($dataDir, $webGroup);
?>