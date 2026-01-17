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

// --- POLITYKA RETENCJI (Data Roll-up) ---
$rawRetentionDays = 2;      // Surowe dane (3 min) trzymamy tylko 2 dni (dla wykresu 24H)
$hourlyRetentionDays = 9;   // Dane godzinne trzymamy 9 dni (dla wykresu 7D)
$dailyRetentionDays = 730;  // Dane dzienne trzymamy 2 lata (dla 30D i 1Y)

$aggregatorStateFile = $dataDir . '/aggregator.state'; // Dla dziennego
$hourlyStateFile = $dataDir . '/hourly_aggregator.state'; // Dla godzinnego

define('AGGREGATE_WORKER_NAME', '_AGGREGATE_');
define('SATOSHIS_PER_BTC', 100000000);
define('FALLBACK_BLOCK_SUBSIDY_BTC', 3.125);
// $bitcoinCliUser and $bitcoinCliPath są w common.php

// --- Functions ---
function parse_hashrate_to_ghs(string $hashrateStr): float { $value = (float)$hashrateStr; $unit = strtoupper(substr(trim($hashrateStr), -1)); switch ($unit) { case 'K': return $value / 1000000; case 'M': return $value / 1000; case 'G': return $value; case 'T': return $value * 1000; case 'P': return $value * 1000 * 1000; default: return $value; } }

function get_local_block_height() {
    $result = run_bitcoin_cli('getblockcount');
    if ($result['output'] !== null && is_numeric($result['output'])) { echo "Fetched block height from local node.\n"; return (int)$result['output']; }
    echo "Warning: Failed to get block height from local node. Error: " . ($result['error'] ?? 'Unknown shell_exec error') . "\n"; return null;
}

function get_last_block_reward_btc(int $tipHeight): ?float {
    echo "Fetching reward for last block (height {$tipHeight}) using local node...\n";
    $hashResult = run_bitcoin_cli("getblockhash {$tipHeight}");
    if ($hashResult['output'] === null || strlen($hashResult['output']) < 64) { echo "Warning: Failed to get block hash for height {$tipHeight} from local node.\n"; return null; }
    $blockHash = $hashResult['output'];
    $blockResult = run_bitcoin_cli("getblock {$blockHash} 2");
    if ($blockResult['output'] === null) { echo "Warning: Failed to get block data for hash {$blockHash} from local node.\n"; return null; }
    $blockData = json_decode($blockResult['output'], true);
    $totalRewardSatoshis = 0; 
    if (isset($blockData['tx'][0]['vout']) && is_array($blockData['tx'][0]['vout'])) {
        foreach($blockData['tx'][0]['vout'] as $output) {
           if(isset($output['value']) && is_numeric($output['value'])) { $totalRewardSatoshis += (int)round($output['value'] * SATOSHIS_PER_BTC); }
        }
    } 
    if ($totalRewardSatoshis > 0) { echo "Successfully fetched last block reward from local node: {$totalRewardSatoshis} sats.\n"; return $totalRewardSatoshis / SATOSHIS_PER_BTC; }
    return null;
}

function get_coinbase_btc_usd_price() {
    $apiUrl = 'https://api.coinbase.com/v2/prices/BTC-USD/spot';
    $output = api_fetch($apiUrl);
    if ($output) { $data = json_decode($output, true); if ($data && isset($data['data']['amount']) && is_numeric($data['data']['amount'])) { echo "Fetched BTC price from Coinbase.\n"; return (float)$data['data']['amount']; } } return null;
}
function get_binance_btc_usd_price() {
    $apiUrl = 'https://api.binance.com/api/v3/ticker/price?symbol=BTCUSDT'; $output = api_fetch($apiUrl);
    if ($output) { $data = json_decode($output, true); if ($data && isset($data['price']) && is_numeric($data['price'])) { echo "Fetched BTC price from Binance.\n"; return (float)$data['price']; } } return null;
}
function get_kraken_btc_usd_price() {
    $apiUrl = 'https://api.kraken.com/0/public/Ticker?pair=XBTUSD'; $output = api_fetch($apiUrl);
    if ($output) { $data = json_decode($output, true); if ($data && empty($data['error']) && isset($data['result']['XXBTZUSD']['c'][0])) { echo "Fetched BTC price from Kraken.\n"; return (float)$data['result']['XXBTZUSD']['c'][0]; } } return null;
}

// --- Main ---
echo "Parser (stats.db) started at " . date('Y-m-d H:i:s') . "\n";
if (!is_dir($dataDir)) { @mkdir($dataDir, 0775, true); }

try {
    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Tabele RAW (co 3 min)
    $pdo->exec("CREATE TABLE IF NOT EXISTS hashrate_history (id INTEGER PRIMARY KEY, timestamp INTEGER NOT NULL, btc_address TEXT NOT NULL, worker_name TEXT NOT NULL, hashrate_5m_ghs REAL, hashrate_1h_ghs REAL, hashrate_24h_ghs REAL)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS pool_history (id INTEGER PRIMARY KEY, timestamp INTEGER NOT NULL, hashrate_5m_ghs REAL, hashrate_1h_ghs REAL, hashrate_24h_ghs REAL)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_user_timestamp ON hashrate_history (timestamp, btc_address, worker_name)");

    // Tabele LIVE STATS
    $pdo->exec("CREATE TABLE IF NOT EXISTS pool_stats (id INTEGER PRIMARY KEY, last_update INTEGER, data TEXT)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS user_stats (btc_address TEXT PRIMARY KEY, last_update INTEGER, data TEXT)");

    // Tabele HOURLY (co 1h) - NOWE
    $pdo->exec("CREATE TABLE IF NOT EXISTS user_hourly_history (id INTEGER PRIMARY KEY, timestamp INTEGER NOT NULL, btc_address TEXT NOT NULL, worker_name TEXT NOT NULL, avg_hashrate_ghs REAL)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS pool_hourly_history (id INTEGER PRIMARY KEY, timestamp INTEGER NOT NULL, avg_hashrate_ghs REAL)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_user_hourly_ts ON user_hourly_history (timestamp, btc_address, worker_name)");

    // Tabele DAILY (co 24h)
    $pdo->exec("CREATE TABLE IF NOT EXISTS user_daily_history (id INTEGER PRIMARY KEY, date INTEGER NOT NULL, btc_address TEXT NOT NULL, worker_name TEXT NOT NULL, avg_hashrate_ghs REAL)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS pool_daily_history (id INTEGER PRIMARY KEY, date INTEGER NOT NULL, avg_hashrate_ghs REAL)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_user_daily_date ON user_daily_history (date, btc_address, worker_name)");

} catch (PDOException $e) { die("ERROR: DB connection failed: " . $e->getMessage() . "\n"); }

// --- Odczyt starych danych ---
$old_pool_data = null; $old_price = null; $old_height = null; $old_reward_btc = null;
try {
    $stmt_old_data = $pdo->query("SELECT data FROM pool_stats WHERE id = 1 LIMIT 1");
    $old_data_json = $stmt_old_data ? $stmt_old_data->fetchColumn() : null;
    if ($old_data_json) {
        $old_pool_data = json_decode($old_data_json, true);
        $old_price = $old_pool_data['btc_usd_price'] ?? null;
        $old_height = $old_pool_data['last_fetched_block_height'] ?? null;
        $old_reward_btc = $old_pool_data['last_block_reward_btc'] ?? null;
    }
} catch (PDOException $e) {}

$now = time();
$pdo->beginTransaction();

// --- Parsowanie Logów ---
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
            
            $new_height = get_local_block_height();
            if ($new_height === null) { $new_height = $old_height; echo "Using old block height ({$new_height}) due to CLI fetch failure.\n"; }

            $reward_to_store = $old_reward_btc ?? FALLBACK_BLOCK_SUBSIDY_BTC; 
            if ($new_height !== null && ($old_height === null || $new_height > $old_height)) {
                echo "New block detected (Old: " . ($old_height ?? 'N/A') . ", New: {$new_height}). Fetching new reward...\n";
                $new_reward_btc = get_last_block_reward_btc($new_height);
                if ($new_reward_btc !== null) { $reward_to_store = $new_reward_btc; } 
                else { echo "Failed to fetch new reward, using fallback subsidy.\n"; $reward_to_store = FALLBACK_BLOCK_SUBSIDY_BTC; }
            }

            echo "Fetching BTC price with fallbacks...\n";
            $btc_usd_price_to_store = get_coinbase_btc_usd_price(); 
            if ($btc_usd_price_to_store === null) { $btc_usd_price_to_store = get_binance_btc_usd_price(); }
            if ($btc_usd_price_to_store === null) { $btc_usd_price_to_store = get_kraken_btc_usd_price(); }
            if ($btc_usd_price_to_store === null) { echo "Warning: All price APIs failed. Re-using last known price.\n"; $btc_usd_price_to_store = $old_price; } 
            else { echo "Successfully fetched BTC/USD price: $" . number_format($btc_usd_price_to_store, 2) . "\n"; }

            $poolDataParts['btc_usd_price'] = $btc_usd_price_to_store;
            $poolDataParts['last_fetched_block_height'] = $new_height;
            $poolDataParts['last_block_reward_btc'] = $reward_to_store;

            $pool_stmt_main = $pdo->prepare("INSERT OR REPLACE INTO pool_stats (id, last_update, data) VALUES (1, ?, ?)");
            $pool_stmt_main->execute([($poolDataParts['lastupdate'] ?? $now), json_encode($poolDataParts)]);
        }
    }
}
$pdo->commit();

// --- AGREGACJA GODZINNA (NOWOŚĆ) ---
try {
    $last_hourly_agg = (int)@file_get_contents($hourlyStateFile);
    $current_hour_start = floor(time() / 3600) * 3600;
    // Jeśli ostatnia agregacja była przed obecną godziną
    if ($last_hourly_agg < $current_hour_start) {
        echo "Running HOURLY aggregation...\n";
        $prev_hour_start = $current_hour_start - 3600;
        $prev_hour_end = $current_hour_start - 1;

        $pdo->beginTransaction();
        // Agregacja Userów
        $stmt_users_h = $pdo->prepare(
            "INSERT INTO user_hourly_history (timestamp, btc_address, worker_name, avg_hashrate_ghs)
             SELECT ?, btc_address, worker_name, AVG(hashrate_1h_ghs)
             FROM hashrate_history
             WHERE timestamp BETWEEN ? AND ?
             GROUP BY btc_address, worker_name"
        );
        $stmt_users_h->execute([$prev_hour_start, $prev_hour_start, $prev_hour_end]);
        
        // Agregacja Puli
        $stmt_pool_h = $pdo->prepare(
            "INSERT INTO pool_hourly_history (timestamp, avg_hashrate_ghs)
             SELECT ?, AVG(hashrate_1h_ghs)
             FROM pool_history
             WHERE timestamp BETWEEN ? AND ?"
        );
        $stmt_pool_h->execute([$prev_hour_start, $prev_hour_start, $prev_hour_end]);
        $pdo->commit();

        // Zapisz stan
        @file_put_contents($hourlyStateFile, (string)$now);
        @chown($hourlyStateFile, $webUser); @chgrp($hourlyStateFile, $webGroup);
        echo "Hourly aggregation complete.\n";
    }
} catch (Exception $e) { if ($pdo->inTransaction()) $pdo->rollBack(); echo "Error in hourly aggregation: " . $e->getMessage() . "\n"; }


// --- AGREGACJA DZIENNA ---
try {
    $last_agg_time = (int)@file_get_contents($aggregatorStateFile);
    $today_midnight = strtotime('today midnight');
    
    if ($last_agg_time < $today_midnight && $now > ($today_midnight + 600)) {
        echo "Running DAILY aggregation...\n";
        $yesterday_start = strtotime('yesterday midnight');
        $yesterday_end = $today_midnight - 1;

        $pdo->beginTransaction();
        // Userzy - używamy godzinnych do agregacji (szybciej), jeśli są, lub surowych
        // Dla pewności użyjemy surowych (najdokładniej)
        $stmt_users_d = $pdo->prepare(
            "INSERT INTO user_daily_history (date, btc_address, worker_name, avg_hashrate_ghs)
             SELECT ?, btc_address, worker_name, AVG(hashrate_24h_ghs)
             FROM hashrate_history WHERE timestamp BETWEEN ? AND ?
             GROUP BY btc_address, worker_name"
        );
        $stmt_users_d->execute([$yesterday_start, $yesterday_start, $yesterday_end]);

        $stmt_pool_d = $pdo->prepare(
            "INSERT INTO pool_daily_history (date, avg_hashrate_ghs)
             SELECT ?, AVG(hashrate_24h_ghs)
             FROM pool_history WHERE timestamp BETWEEN ? AND ?"
        );
        $stmt_pool_d->execute([$yesterday_start, $yesterday_start, $yesterday_end]);
        $pdo->commit();

        @file_put_contents($aggregatorStateFile, (string)$now);
        @chown($aggregatorStateFile, $webUser); @chgrp($aggregatorStateFile, $webGroup);
        echo "Daily aggregation complete.\n";
    }
} catch (Exception $e) { if ($pdo->inTransaction()) $pdo->rollBack(); echo "Error in daily aggregation: " . $e->getMessage() . "\n"; }


// --- CZYSZCZENIE DANYCH (RETENCJA) ---
// Uruchom raz dziennie (losowo)
if (rand(1, 480) === 1) { 
    echo "Running data cleanup...\n";
    // 1. Surowe dane (3 min) -> trzymaj tylko 2 dni!
    $cutoff_raw = time() - ($rawRetentionDays * 86400);
    $pdo->prepare("DELETE FROM hashrate_history WHERE timestamp < ?")->execute([$cutoff_raw]);
    $pdo->prepare("DELETE FROM pool_history WHERE timestamp < ?")->execute([$cutoff_raw]);
    
    // 2. Dane godzinne -> trzymaj 9 dni (dla wykresu 7D)
    $cutoff_hourly = time() - ($hourlyRetentionDays * 86400);
    $pdo->prepare("DELETE FROM user_hourly_history WHERE timestamp < ?")->execute([$cutoff_hourly]);
    $pdo->prepare("DELETE FROM pool_hourly_history WHERE timestamp < ?")->execute([$cutoff_hourly]);

    // 3. Dane dzienne -> trzymaj 2 lata
    $cutoff_daily = time() - ($dailyRetentionDays * 86400);
    $pdo->prepare("DELETE FROM user_daily_history WHERE date < ?")->execute([$cutoff_daily]);
    $pdo->prepare("DELETE FROM pool_daily_history WHERE date < ?")->execute([$cutoff_daily]);
    
    echo "Cleanup finished.\n";
}

if (file_exists($dbPath)) { chown($dbPath, $webUser); chgrp($dbPath, $webGroup); }
echo "Stats parser (stats.db) finished successfully.\n";
?>