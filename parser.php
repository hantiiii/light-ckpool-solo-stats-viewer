#!/usr/-bin/php
<?php
// --- Configuration ---
$logFilePath = '/var/log/ckpool/ckpool.log';
$outputJsonPath = __DIR__ . '/stats.json';
$dbPath = __DIR__ . '/history.db';
$stateFilePath = __DIR__ . '/parser.state';
$webUser = 'web1';
$webGroup = 'client1';
$retentionDays = 30;
// --------------------

function parse_hashrate(string $hashrateStr): float {
    $value = (float)$hashrateStr;
    $unit = strtoupper(substr($hashrateStr, -1));
    switch ($unit) {
        case 'K': return $value / 1000000; case 'M': return $value / 1000;
        case 'G': return $value; case 'T': return $value * 1000;
        case 'P': return $value * 1000 * 1000; default: return $value;
    }
}

echo "Parser started at " . date('Y-m-d H:i:s') . "\n";

try {
    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("CREATE TABLE IF NOT EXISTS hashrate_history (id INTEGER PRIMARY KEY, timestamp INTEGER, btc_address TEXT, hashrate_5m_ghs REAL, hashrate_1h_ghs REAL, hashrate_24h_ghs REAL)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS pool_history (id INTEGER PRIMARY KEY, timestamp INTEGER, hashrate_5m_ghs REAL, hashrate_1h_ghs REAL, hashrate_24h_ghs REAL)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_user_timestamp ON hashrate_history (timestamp)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_pool_timestamp ON pool_history (timestamp)");
} catch (PDOException $e) { die("ERROR: DB connection failed: " . $e->getMessage() . "\n"); }

if (!is_readable($logFilePath)) die("ERROR: Cannot read log file: {$logFilePath}\n");

$last_offset = file_exists($stateFilePath) ? (int)file_get_contents($stateFilePath) : 0;
$current_size = filesize($logFilePath);
if ($current_size < $last_offset) { $last_offset = 0; }

$handle = fopen($logFilePath, 'r');
fseek($handle, $last_offset);

$latestUserData = []; $poolDataParts = []; $network_difficulty_from_log = null;
$now = time();

$pdo->beginTransaction();
while (($line = fgets($handle)) !== false) {
    if (preg_match('/User\s+([a-zA-Z0-9]+):({.+})/', $line, $matches)) {
        $btcAddress = $matches[1]; $jsonData = json_decode($matches[2], true);
        if ($jsonData) {
            $latestUserData[$btcAddress] = $jsonData;
            $stmt = $pdo->prepare("INSERT INTO hashrate_history (timestamp, btc_address, hashrate_5m_ghs, hashrate_1h_ghs, hashrate_24h_ghs) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$now, $btcAddress, parse_hashrate($jsonData['hashrate5m'] ?? '0'), parse_hashrate($jsonData['hashrate1hr'] ?? '0'), parse_hashrate($jsonData['hashrate1d'] ?? '0')]);
        }
    } elseif (strpos($line, 'Pool:') !== false) {
        if (preg_match('/Pool:({.+})/', $line, $matches)) {
            $poolJsonPart = json_decode($matches[1], true);
            if ($poolJsonPart) {
                $poolDataParts = array_merge($poolDataParts, $poolJsonPart);
                if (isset($poolJsonPart['hashrate5m'])) {
                    $stmt = $pdo->prepare("INSERT INTO pool_history (timestamp, hashrate_5m_ghs, hashrate_1h_ghs, hashrate_24h_ghs) VALUES (?, ?, ?, ?)");
                    $stmt->execute([$now, parse_hashrate($poolJsonPart['hashrate5m'] ?? '0'), parse_hashrate($poolJsonPart['hashrate1hr'] ?? '0'), parse_hashrate($poolJsonPart['hashrate1d'] ?? '0')]);
                }
            }
        }
    } 
    elseif (preg_match('/Network diff set to ([\d\.]+)/', $line, $matches)) {
        $network_difficulty_from_log = (float)$matches[1];
    }
}
$new_offset = ftell($handle);
fclose($handle);
$pdo->commit();

// NOWA LOGIKA: Obliczanie hashrate sieci
$network_hashrate = 0;
if ($network_difficulty_from_log > 0) {
    $network_hashrate = $network_difficulty_from_log * pow(2, 32) / 600; // Wynik w H/s
    $network_hashrate /= 1e9; // Konwersja do GH/s
}

$currentStats = file_exists($outputJsonPath) ? json_decode(file_get_contents($outputJsonPath), true) : [];
if (!empty($latestUserData)) { $currentStats['users'] = array_merge($currentStats['users'] ?? [], $latestUserData); }
if (!empty($poolDataParts)) { $currentStats['pool'] = array_merge($currentStats['pool'] ?? [], $poolDataParts); }
if ($network_difficulty_from_log !== null) { 
    $currentStats['network_difficulty'] = $network_difficulty_from_log;
    $currentStats['network_hashrate'] = $network_hashrate; // Zapisujemy nową wartość
}
$currentStats['last_update'] = $now;

if (file_put_contents($outputJsonPath, json_encode($currentStats, JSON_PRETTY_PRINT))) {
    chown($outputJsonPath, $webUser); chgrp($outputJsonPath, $webGroup); chmod($outputJsonPath, 0644);
}

file_put_contents($stateFilePath, $new_offset);

if (rand(1, 12) === 1) {
    $cutoff = time() - ($retentionDays * 86400);
    $pdo->prepare("DELETE FROM hashrate_history WHERE timestamp < ?")->execute([$cutoff]);
    $pdo->prepare("DELETE FROM pool_history WHERE timestamp < ?")->execute([$cutoff]);
}

chown($dbPath, $webUser); chgrp($dbPath, $webGroup); chmod($dbPath, 0644);
echo "Parser finished successfully.\n";