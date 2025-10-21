#!/usr/bin/php
<?php
// --- Configuration ---
$dataDir = __DIR__ . '/data';
$usersDir = '/var/log/ckpool/users/';
$poolDir = '/var/log/ckpool/pool/';
$dbPath = $dataDir . '/stats.db'; // Zapisuje tylko do stats.db
$webUser = 'web1';
$webGroup = 'client1';
$retentionDays = 30;
define('AGGREGATE_WORKER_NAME', '_AGGREGATE_');
// --- Functions ---
function parse_hashrate_to_ghs(string $hashrateStr): float { $value = (float)$hashrateStr; $unit = strtoupper(substr(trim($hashrateStr), -1)); switch ($unit) { case 'K': return $value / 1000000; case 'M': return $value / 1000; case 'G': return $value; case 'T': return $value * 1000; case 'P': return $value * 1000 * 1000; default: return $value; } }

echo "Parser (stats.db) started at " . date('Y-m-d H:i:s') . "\n";
if (!is_dir($dataDir)) { @mkdir($dataDir, 0775, true); }

try {
    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("CREATE TABLE IF NOT EXISTS hashrate_history (id INTEGER PRIMARY KEY, timestamp INTEGER NOT NULL, btc_address TEXT NOT NULL, worker_name TEXT NOT NULL, hashrate_5m_ghs REAL, hashrate_1h_ghs REAL, hashrate_24h_ghs REAL)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS pool_history (id INTEGER PRIMARY KEY, timestamp INTEGER NOT NULL, hashrate_5m_ghs REAL, hashrate_1h_ghs REAL, hashrate_24h_ghs REAL)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS pool_stats (id INTEGER PRIMARY KEY, last_update INTEGER, data TEXT)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS user_stats (btc_address TEXT PRIMARY KEY, last_update INTEGER, data TEXT)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_user_timestamp ON hashrate_history (timestamp, btc_address, worker_name)");
} catch (PDOException $e) { die("ERROR: DB connection failed: " . $e->getMessage() . "\n"); }

$now = time();
$pdo->beginTransaction();
if (is_dir($usersDir)) {
    $userFiles = scandir($usersDir);
    if ($userFiles !== false) {
        foreach ($userFiles as $filename) {
            if ($filename[0] === '.') continue;
            $btcAddress = $filename;
            $filePath = $usersDir . $filename;
            $json_content = @file_get_contents($filePath);
            if ($json_content === false) continue;
            $data = json_decode($json_content, true);
            if ($data && is_array($data)) {
                $summary = $data; unset($summary['worker']); $workers = [];
                if (!empty($data['worker']) && is_array($data['worker'])) {
                    foreach($data['worker'] as $worker_data) {
                        $workerFullName = $worker_data['workername'] ?? $btcAddress;
                        $workerName = strpos($workerFullName, '.') !== false ? substr(strstr($workerFullName, '.'), 1) : 'default';
                        $workers[$workerName] = $worker_data;
                        $stmt = $pdo->prepare("INSERT INTO hashrate_history (timestamp, btc_address, worker_name, hashrate_5m_ghs, hashrate_1h_ghs, hashrate_24h_ghs) VALUES (?, ?, ?, ?, ?, ?)");
                        $stmt->execute([$now, $btcAddress, $workerName, parse_hashrate_to_ghs($worker_data['hashrate5m'] ?? '0'), parse_hashrate_to_ghs($worker_data['hashrate1hr'] ?? '0'), parse_hashrate_to_ghs($worker_data['hashrate1d'] ?? '0')]);
                    }
                }
                $agg_stmt = $pdo->prepare("INSERT INTO hashrate_history (timestamp, btc_address, worker_name, hashrate_5m_ghs, hashrate_1h_ghs, hashrate_24h_ghs) VALUES (?, ?, ?, ?, ?, ?)");
                $agg_stmt->execute([$now, $btcAddress, AGGREGATE_WORKER_NAME, parse_hashrate_to_ghs($summary['hashrate5m'] ?? '0'), parse_hashrate_to_ghs($summary['hashrate1hr'] ?? '0'), parse_hashrate_to_ghs($summary['hashrate1d'] ?? '0')]);
                $user_full_data = ['summary' => $summary, 'workers' => $workers];
                $user_stmt = $pdo->prepare("INSERT OR REPLACE INTO user_stats (btc_address, last_update, data) VALUES (?, ?, ?)");
                $user_stmt->execute([$btcAddress, $now, json_encode($user_full_data)]);
            }
        }
    }
}
$poolDataParts = [];
if (is_dir($poolDir) && file_exists($poolDir . 'pool.status')) {
    $pool_lines = @file($poolDir . 'pool.status', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($pool_lines !== false) {
        foreach($pool_lines as $line) { $decoded_line = json_decode($line, true); if ($decoded_line) { $poolDataParts = array_merge($poolDataParts, $decoded_line); } }
        if (!empty($poolDataParts)) {
            if(isset($poolDataParts['hashrate5m'])) {
                $pool_stmt = $pdo->prepare("INSERT INTO pool_history (timestamp, hashrate_5m_ghs, hashrate_1h_ghs, hashrate_24h_ghs) VALUES (?, ?, ?, ?)");
                $pool_stmt->execute([$now, parse_hashrate_to_ghs($poolDataParts['hashrate5m'] ?? '0'), parse_hashrate_to_ghs($poolDataParts['hashrate1hr'] ?? '0'), parse_hashrate_to_ghs($poolDataParts['hashrate1d'] ?? '0')]);
            }
            $pool_stmt_main = $pdo->prepare("INSERT OR REPLACE INTO pool_stats (id, last_update, data) VALUES (1, ?, ?)");
            $pool_stmt_main->execute([($poolDataParts['lastupdate'] ?? $now), json_encode($poolDataParts)]);
        }
    }
}
$pdo->commit();

if (rand(1, 12) === 1) {
    $cutoff = time() - ($retentionDays * 86400);
    $pdo->prepare("DELETE FROM hashrate_history WHERE timestamp < ?")->execute([$cutoff]);
    $pdo->prepare("DELETE FROM pool_history WHERE timestamp < ?")->execute([$cutoff]);
}
chown($dbPath, $webUser); chgrp($dbPath, $webGroup);
echo "Stats parser (stats.db) finished successfully.\n";