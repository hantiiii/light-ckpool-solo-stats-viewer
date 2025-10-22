#!/usr/bin/php
<?php
require_once __DIR__ . '/common.php';

// --- Configuration ---
$dataDir = __DIR__ . '/data';
$usersDir = '/var/log/ckpool/users/';
$poolDir = '/var/log/ckpool/pool/';
$dbPath = $dataDir . '/stats.db';
$webUser = 'web1';
$webGroup = 'client1';
$retentionDays = 30;
define('AGGREGATE_WORKER_NAME', '_AGGREGATE_');
define('SATOSHIS_PER_BTC', 100000000);
define('FALLBACK_BLOCK_SUBSIDY_BTC', 3.125);
// $bitcoinCliUser and $bitcoinCliPath są teraz w common.php

// --- Functions ---
function parse_hashrate_to_ghs(string $hashrateStr): float { $value = (float)$hashrateStr; $unit = strtoupper(substr(trim($hashrateStr), -1)); switch ($unit) { case 'K': return $value / 1000000; case 'M': return $value / 1000; case 'G': return $value; case 'T': return $value * 1000; case 'P': return $value * 1000 * 1000; default: return $value; } }

// run_bitcoin_cli() jest teraz w common.php
// api_fetch() jest teraz w common.php

function get_local_block_height() {
    $result = run_bitcoin_cli('getblockcount');
    if ($result['output'] !== null && is_numeric($result['output'])) {
        echo "Fetched block height from local node.\n";
        return (int)$result['output'];
    }
    echo "Warning: Failed to get block height from local node. Error: " . ($result['error'] ?? 'Unknown shell_exec error') . "\n";
    return null;
}

function get_last_block_reward_btc(int $tipHeight): ?float {
    echo "Fetching reward for last block (height {$tipHeight}) using local node...\n";
    $hashResult = run_bitcoin_cli("getblockhash {$tipHeight}");
    if ($hashResult['output'] === null || strlen($hashResult['output']) < 64) { echo "Warning: Failed to get block hash for height {$tipHeight} from local node. Error: " . ($hashResult['error'] ?? $hashResult['output']) . "\n"; return null; }
    $blockHash = $hashResult['output'];
    $blockResult = run_bitcoin_cli("getblock {$blockHash} 2");
    if ($blockResult['output'] === null) { echo "Warning: Failed to get block data for hash {$blockHash} from local node. Error: " . ($blockResult['error'] ?? 'null') . "\n"; return null; }
    $blockData = json_decode($blockResult['output'], true);
    $totalRewardSatoshis = 0; $voutCount = 0;
    if (isset($blockData['tx'][0]['vout']) && is_array($blockData['tx'][0]['vout'])) {
        $voutCount = count($blockData['tx'][0]['vout']);
        foreach($blockData['tx'][0]['vout'] as $output) {
           if(isset($output['value']) && is_numeric($output['value'])) { $totalRewardSatoshis += (int)round($output['value'] * SATOSHIS_PER_BTC); }
           else { echo "Warning: Missing or invalid 'value' in local coinbase vout for block {$tipHeight}\n"; }
        }
    } else { echo "Warning: Could not find coinbase transaction outputs (tx[0]['vout']) in local block data for block {$tipHeight}\n"; }
    if ($totalRewardSatoshis > 0) { echo "Successfully fetched last block reward from local node: {$totalRewardSatoshis} sats.\n"; return $totalRewardSatoshis / SATOSHIS_PER_BTC; }
    else { echo "Warning: Calculated zero total reward for block {$tipHeight} from local node. Found {$voutCount} vout entries.\n"; }
    echo "Warning: Failed to fetch valid reward for the last block from local node.\n"; return null;
}

function get_coinbase_btc_usd_price() {
    $apiUrl = 'https://api.coinbase.com/v2/prices/BTC-USD/spot';
    $output = api_fetch($apiUrl);
    if ($output) {
        $data = json_decode($output, true);
        if ($data && isset($data['data']['amount']) && is_numeric($data['data']['amount'])) {
            echo "Fetched BTC price from Coinbase.\n";
            return (float)$data['data']['amount'];
        }
         echo "Warning: Could not parse Coinbase price data. Response: " . substr(preg_replace('/\s+/', ' ', trim($output)), 0, 150) . "...\n";
    }
    return null;
}

function get_binance_btc_usd_price() {
    $apiUrl = 'https://api.binance.com/api/v3/ticker/price?symbol=BTCUSDT'; 
    $output = api_fetch($apiUrl);
    if ($output) {
        $data = json_decode($output, true);
        if ($data && isset($data['price']) && is_numeric($data['price'])) {
            echo "Fetched BTC price from Binance.\n";
            return (float)$data['price'];
        }
         echo "Warning: Could not parse Binance price data. Response: " . substr(preg_replace('/\s+/', ' ', trim($output)), 0, 150) . "...\n";
    }
    return null;
}

function get_kraken_btc_usd_price() {
    $apiUrl = 'https://api.kraken.com/0/public/Ticker?pair=XBTUSD';
    $output = api_fetch($apiUrl);
    if ($output) {
        $data = json_decode($output, true);
        if ($data && empty($data['error']) && isset($data['result']['XXBTZUSD']['c'][0])) {
            echo "Fetched BTC price from Kraken.\n";
            return (float)$data['result']['XXBTZUSD']['c'][0]; 
        }
         echo "Warning: Could not parse Kraken price data. Response: " . substr(preg_replace('/\s+/', ' ', trim($output)), 0, 150) . "...\n";
    }
    return null;
}


// --- Main ---
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
            if ($json_content === false) { 
                echo "Warning: Could not read user file {$filePath}\n";
                continue; 
            }
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
    } else {
         echo "Warning: Failed to scan users directory {$usersDir}\n";
    }
} else {
    echo "Warning: Users directory not found at {$usersDir}\n";
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
            
            // Pobierz dane "na żywo" (co 5 min)
            $current_height = get_local_block_height();
            $last_block_reward_btc = ($current_height !== null) ? get_last_block_reward_btc($current_height) : null;
            if ($last_block_reward_btc === null) {
                echo "Using fallback block reward value for pool_stats.\n";
                $last_block_reward_btc = FALLBACK_BLOCK_SUBSIDY_BTC;
            }

            echo "Fetching BTC price with fallbacks...\n";
            $btc_usd_price_to_store = get_coinbase_btc_usd_price(); // 1. Coinbase
            if ($btc_usd_price_to_store === null) {
                echo "Coinbase failed, trying Binance...\n";
                $btc_usd_price_to_store = get_binance_btc_usd_price(); // 2. Binance
            }
            if ($btc_usd_price_to_store === null) {
                echo "Binance failed, trying Kraken...\n";
                $btc_usd_price_to_store = get_kraken_btc_usd_price(); // 3. Kraken
            }
            if ($btc_usd_price_to_store === null) {
                echo "Warning: All price APIs failed. Re-using last known price from DB...\n";
                try {
                    $stmt_old_price = $pdo->query("SELECT data FROM pool_stats WHERE id = 1 LIMIT 1");
                    $old_data_json = $stmt_old_price ? $stmt_old_price->fetchColumn() : null;
                    if ($old_data_json) { $old_data = json_decode($old_data_json, true); $btc_usd_price_to_store = $old_data['btc_usd_price'] ?? null; if ($btc_usd_price_to_store !== null) { echo "Using last stored price: $" . number_format($btc_usd_price_to_store, 2) . "\n"; } else { echo "Warning: No valid stored price found either.\n"; } }
                } catch (PDOException $e) { echo "Warning: Failed to read old price from DB: " . $e->getMessage() . "\n"; }
            } else {
                 echo "Successfully fetched BTC/USD price: $" . number_format($btc_usd_price_to_store, 2) . "\n";
            }

            $poolDataParts['btc_usd_price'] = $btc_usd_price_to_store;
            $poolDataParts['last_fetched_block_height'] = $current_height;
            $poolDataParts['last_block_reward_btc'] = $last_block_reward_btc;

            $pool_stmt_main = $pdo->prepare("INSERT OR REPLACE INTO pool_stats (id, last_update, data) VALUES (1, ?, ?)");
            $pool_stmt_main->execute([($poolDataParts['lastupdate'] ?? $now), json_encode($poolDataParts)]);
        }
    } else {
         echo "Warning: Failed to read pool.status file.\n";
    }
} else {
     echo "Warning: pool.status file or pool directory not found.\n";
}
$pdo->commit();

if (rand(1, 12) === 1) { 
    $cutoff = time() - ($retentionDays * 86400);
    $stmt_del_hr = $pdo->prepare("DELETE FROM hashrate_history WHERE timestamp < ?");
    $stmt_del_hr->execute([$cutoff]);
    $stmt_del_pool = $pdo->prepare("DELETE FROM pool_history WHERE timestamp < ?");
    $stmt_del_pool->execute([$cutoff]);
    echo "Cleaned up old history records (" . $stmt_del_hr->rowCount() . " user, " . $stmt_del_pool->rowCount() . " pool).\n";
}

if (file_exists($dbPath)) {
    chown($dbPath, $webUser); chgrp($dbPath, $webGroup);
}
echo "Stats parser (stats.db) finished successfully.\n";
?>