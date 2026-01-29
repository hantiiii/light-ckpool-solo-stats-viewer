#!/usr/bin/php
<?php
// --- PARSER v90: SECURE & OPTIMIZED ---
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
    
    // Secure Array Call
    $cliCount = run_bitcoin_cli(['getblockcount']);
    
    if ($cliCount['error'] === null && is_numeric(trim($cliCount['output']))) {
        $data['height'] = (int)trim($cliCount['output']);
        // Secure Call with args
        $stats = run_bitcoin_cli(['getblockstats', $data['height'], '["subsidy","totalfee"]']);
        $statsData = json_decode($stats['output'] ?? '{}', true);
        
        if (isset($statsData['subsidy']) && isset($statsData['totalfee'])) {
            $data['reward'] = ($statsData['subsidy'] + $statsData['totalfee']) / 100000000;
        } else {
            $data['reward'] = 50 / pow(2, floor($data['height'] / 210000)); 
        }
    }
    
    $json = api_fetch('https://api.kraken.com/0/public/Ticker?pair=XBTUSD');
    if ($json) {
        $d = json_decode($json, true);
        if (isset($d['result']['XXBTZUSD']['c'][0])) { $data['price'] = (float)$d['result']['XXBTZUSD']['c'][0]; }
    }
    return $data;
}

// --- DB INIT ---
if (!is_dir($dataDir)) { mkdir($dataDir, 0775, true); }
try {
    $pdo = new PDO('sqlite:' . $statsDbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("PRAGMA journal_mode = DELETE"); 
    $pdo->exec("PRAGMA synchronous = NORMAL");
    
    $pdo->exec("CREATE TABLE IF NOT EXISTS pool_stats (id INTEGER PRIMARY KEY, last_update INTEGER, data TEXT)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS pool_history (timestamp INTEGER PRIMARY KEY, hashrate_1m_ghs REAL DEFAULT 0, hashrate_5m_ghs REAL DEFAULT 0, hashrate_1h_ghs REAL DEFAULT 0, shares INTEGER DEFAULT 0, users INTEGER DEFAULT 0, workers INTEGER DEFAULT 0, accepted INTEGER DEFAULT 0, rejected INTEGER DEFAULT 0)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS pool_daily_history (date INTEGER PRIMARY KEY, avg_hashrate_ghs REAL, accepted INTEGER DEFAULT 0, rejected INTEGER DEFAULT 0)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS user_stats (btc_address TEXT PRIMARY KEY, last_update INTEGER, data TEXT)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS user_daily_history (date INTEGER, btc_address TEXT, worker_name TEXT, avg_hashrate_ghs REAL, PRIMARY KEY (date, btc_address, worker_name))");
    $pdo->exec("CREATE TABLE IF NOT EXISTS user_hourly_history (time_bucket INTEGER, btc_address TEXT, worker_name TEXT, avg_hashrate_ghs REAL, PRIMARY KEY (time_bucket, btc_address, worker_name))");
    
    // PERFORMANCE INDEXES
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_pool_hist_ts ON pool_history(timestamp)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_user_hourly_ts ON user_hourly_history(time_bucket)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_user_daily_dt ON user_daily_history(date)");

    // Auto-migracja
    $colsH = $pdo->query("PRAGMA table_info(pool_history)")->fetchAll(PDO::FETCH_COLUMN, 1);
    $reqH = ['hashrate_1m_ghs','hashrate_5m_ghs','hashrate_1h_ghs','shares','users','workers','accepted','rejected'];
    foreach($reqH as $c) { if(!in_array($c, $colsH)) $pdo->exec("ALTER TABLE pool_history ADD COLUMN $c NUMERIC DEFAULT 0"); }
} catch (PDOException $e) { die("DB ERROR: " . $e->getMessage() . "\n"); }

$now = time();
$startTime = microtime(true);

// --- 0. NODE DATA ---
$nodeData = get_real_node_data();

// --- 1. POOL STATS ---
$poolData = [ 'hashrate1m' => 0, 'hashrate5m' => 0, 'hashrate1hr' => 0, 'Users' => 0, 'Workers' => 0, 'accepted' => 0, 'rejected' => 0, 'shares' => 0, 'bestshare' => 0 ];
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
            if (isset($json['bestshare'])) { $poolData['bestshare'] = $json['bestshare']; }
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
    echo "POOL: OK. HR: " . number_format($poolData['hashrate1hr'], 2) . " GH/s.\n";
}

// --- 2. USER STATS ---
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
    $processedCount = 0; 
    
    $pdo->beginTransaction();
    foreach ($files as $file) {
        if (filemtime($file) < $lastRunTime) continue; 
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
    echo "USERS: Processed $processedCount.\n";
}

file_put_contents($lastRunFile, time());
$execTime = microtime(true) - $startTime;
echo "DONE: Execution time: " . number_format($execTime, 3) . "s\n";

if (rand(1, 50) === 1) { 
    $cutoff30d = time() - (30 * 86400); $pdo->prepare("DELETE FROM pool_history WHERE timestamp < ?")->execute([$cutoff30d]);
    $cutoff60d = time() - (60 * 86400); $pdo->prepare("DELETE FROM user_hourly_history WHERE time_bucket < ?")->execute([$cutoff60d]);
}

$pdo = null;
@chown($statsDbPath, $webUser); @chgrp($statsDbPath, $webGroup);
@chown($lastRunFile, $webUser); @chgrp($lastRunFile, $webGroup);
@chown($dataDir, $webUser); @chgrp($dataDir, $webGroup);
?>