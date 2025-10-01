#!/usr/bin/php
<?php
// --- Configuration ---
$dataDir = __DIR__ . '/data';
$usersDir = '/var/log/ckpool/users/';
$poolDir = '/var/log/ckpool/pool/';
$logFilePath = '/var/log/ckpool/ckpool.log';
$outputJsonPath = $dataDir . '/stats.json';
$dbPath = $dataDir . '/history.db';
$difficultyHistoryPath = $dataDir . '/difficulty_history.json';
$webUser = 'web1';
$webGroup = 'client1';
$retentionDays = 30;
define('AGGREGATE_WORKER_NAME', '_AGGREGATE_');
// --- Functions ---
function parse_hashrate_to_ghs(string $hashrateStr): float { $value = (float)$hashrateStr; $unit = strtoupper(substr(trim($hashrateStr), -1)); switch ($unit) { case 'K': return $value / 1000000; case 'M': return $value / 1000; case 'G': return $value; case 'T': return $value * 1000; case 'P': return $value * 1000 * 1000; default: return $value; } }

echo "Parser started at " . date('Y-m-d H:i:s') . "\n";
if (!is_dir($dataDir)) { mkdir($dataDir, 0775, true); }

try {
    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("CREATE TABLE IF NOT EXISTS hashrate_history (id INTEGER PRIMARY KEY, timestamp INTEGER NOT NULL, btc_address TEXT NOT NULL, worker_name TEXT NOT NULL, hashrate_5m_ghs REAL, hashrate_1h_ghs REAL, hashrate_24h_ghs REAL)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS pool_history (id INTEGER PRIMARY KEY, timestamp INTEGER NOT NULL, hashrate_5m_ghs REAL, hashrate_1h_ghs REAL, hashrate_24h_ghs REAL)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_user_timestamp ON hashrate_history (timestamp, btc_address, worker_name)");
} catch (PDOException $e) { die("ERROR: DB connection failed: " . $e->getMessage() . "\n"); }

$now = time();
$final_user_data = [];
$pdo->beginTransaction();
if (is_dir($usersDir)) {
    foreach (scandir($usersDir) as $filename) {
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
            $final_user_data[$btcAddress] = ['summary' => $summary, 'workers' => $workers];
        }
    }
}
$poolDataParts = [];
if (is_dir($poolDir) && file_exists($poolDir . 'pool.status')) {
    $pool_lines = file($poolDir . 'pool.status', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach($pool_lines as $line) { $decoded_line = json_decode($line, true); if ($decoded_line) { $poolDataParts = array_merge($poolDataParts, $decoded_line); } }
    if(isset($poolDataParts['hashrate5m'])) {
        $pool_stmt = $pdo->prepare("INSERT INTO pool_history (timestamp, hashrate_5m_ghs, hashrate_1h_ghs, hashrate_24h_ghs) VALUES (?, ?, ?, ?)");
        $pool_stmt->execute([$now, parse_hashrate_to_ghs($poolDataParts['hashrate5m'] ?? '0'), parse_hashrate_to_ghs($poolDataParts['hashrate1hr'] ?? '0'), parse_hashrate_to_ghs($poolDataParts['hashrate1d'] ?? '0')]);
    }
}
$pdo->commit();

$network_difficulty_from_log = null;
if (is_readable($logFilePath)) {
    $lines = file($logFilePath);
    foreach (array_reverse($lines) as $line) { if (preg_match('/Network diff set to ([\d\.]+)/', $line, $matches)) { $network_difficulty_from_log = (float)$matches[1]; break; } }
}

$difficulty_history = file_exists($difficultyHistoryPath) ? json_decode(file_get_contents($difficultyHistoryPath), true) : [];
if (!is_array($difficulty_history)) $difficulty_history = [];
$last_known_difficulty = !empty($difficulty_history) ? end($difficulty_history) : null;
if ($network_difficulty_from_log && $network_difficulty_from_log !== $last_known_difficulty) {
    $difficulty_history[] = $network_difficulty_from_log;
    if (count($difficulty_history) > 10) { $difficulty_history = array_slice($difficulty_history, -10); }
    file_put_contents($difficultyHistoryPath, json_encode(array_values($difficulty_history)));
    chown($difficultyHistoryPath, $webUser); chgrp($difficultyHistoryPath, $webGroup);
}
$previous_network_difficulty = count($difficulty_history) >= 2 ? $difficulty_history[count($difficulty_history) - 2] : null;

$network_hashrate = 0;
if ($network_difficulty_from_log > 0) { $network_hashrate = $network_difficulty_from_log * pow(2, 32) / 600 / 1e9; }

$outputData = file_exists($outputJsonPath) ? json_decode(file_get_contents($outputJsonPath), true) : [];
$outputData['users'] = $final_user_data;
$outputData['pool'] = $poolDataParts;
$outputData['last_update'] = $now;
if ($network_difficulty_from_log) {
    $outputData['network_difficulty'] = $network_difficulty_from_log;
    $outputData['previous_network_difficulty'] = $previous_network_difficulty;
}
if ($network_hashrate) { $outputData['network_hashrate'] = $network_hashrate; }

file_put_contents($outputJsonPath, json_encode($outputData));
chown($outputJsonPath, $webUser); chgrp($outputJsonPath, $webGroup);

if (rand(1, 12) === 1) {
    $cutoff = time() - ($retentionDays * 86400);
    $pdo->prepare("DELETE FROM hashrate_history WHERE timestamp < ?")->execute([$cutoff]);
    $pdo->prepare("DELETE FROM pool_history WHERE timestamp < ?")->execute([$cutoff]);
}
chown($dbPath, $webUser); chgrp($dbPath, $webGroup);
echo "Parser finished successfully. Processed " . count($final_user_data) . " users.\n";