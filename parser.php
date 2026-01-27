#!/usr/bin/php
<?php
require_once __DIR__ . '/common.php';

// Konfiguracja
$poolLogFile = '/var/log/ckpool/ckpool.log'; // Dostosuj jeśli inne
$dataDir = __DIR__ . '/data';
$statsDbPath = $dataDir . '/stats.db';
$webUser = 'web1';
$webGroup = 'client1';

// Inicjalizacja Bazy
$pdo = new PDO('sqlite:' . $statsDbPath);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Tworzenie tabel (jeśli nie istnieją)
$pdo->exec("CREATE TABLE IF NOT EXISTS pool_stats (id INTEGER PRIMARY KEY, last_update INTEGER, data TEXT)");
$pdo->exec("CREATE TABLE IF NOT EXISTS pool_history (timestamp INTEGER PRIMARY KEY, hashrate_1m_ghs REAL, hashrate_5m_ghs REAL, hashrate_1h_ghs REAL, shares INTEGER, users INTEGER, workers INTEGER, accepted INTEGER DEFAULT 0, rejected INTEGER DEFAULT 0)");
$pdo->exec("CREATE TABLE IF NOT EXISTS pool_daily_history (date INTEGER PRIMARY KEY, avg_hashrate_ghs REAL, accepted INTEGER DEFAULT 0, rejected INTEGER DEFAULT 0)");
$pdo->exec("CREATE TABLE IF NOT EXISTS user_stats (btc_address TEXT PRIMARY KEY, last_update INTEGER, data TEXT)");

// AUTO-MIGRACJA: Dodanie kolumn accepted/rejected do historii dziennej, jeśli ich nie ma (Fix Error 500)
try { $pdo->exec("ALTER TABLE pool_daily_history ADD COLUMN accepted INTEGER DEFAULT 0"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE pool_daily_history ADD COLUMN rejected INTEGER DEFAULT 0"); } catch (Exception $e) {}

// Parsowanie Logów CKPool
$logContent = file_get_contents($poolLogFile); // Wersja prosta, czyta cały log. W produkcji lepiej czytać `tail`.
// Zakładamy, że log jest rotowany lub używamy logiki z poprzednich wersji do czytania końcówki.
// Tutaj uproszczona logika wyciągania OSTATNICH statystyk z logu.

$lines = file($poolLogFile);
$lastStatsLine = null;
foreach (array_reverse($lines) as $line) {
    if (strpos($line, 'SPS') !== false && strpos($line, 'Users') !== false) {
        $lastStatsLine = $line;
        break;
    }
}

$poolData = [];
if ($lastStatsLine) {
    // Przykładowa linia: ... SPS 1m 123 5m 456 ... Users 1 Workers 2 ... Accepted 1234 Rejected 5 ...
    // CKPool formatowanie bywa różne, tutaj uniwersalny regex
    
    // Hashrate (SPS -> GH/s approx or direct hashrate log if avail)
    // Zakładam standardowy log CKPool.
    preg_match('/1m\s+([\d\.]+)/', $lastStatsLine, $m1);
    preg_match('/5m\s+([\d\.]+)/', $lastStatsLine, $m5);
    preg_match('/1h\s+([\d\.]+)/', $lastStatsLine, $mh);
    preg_match('/Users\s+(\d+)/', $lastStatsLine, $mu);
    preg_match('/Workers\s+(\d+)/', $lastStatsLine, $mw);
    
    // Szukamy Accepted/Rejected w tej samej linii lub w logu ogólnie
    // Często CKPool podaje to jako "Pool stats: ... Accepted: X, Rejected: Y"
    // Jeśli nie ma w linii SPS, szukamy innej.
    
    // Prosta symulacja parsowania statystyk (dostosuj regex pod swój format logów)
    $poolData['hashrate1m'] = isset($m1[1]) ? $m1[1] : 0;
    $poolData['hashrate5m'] = isset($m5[1]) ? $m5[1] : 0;
    $poolData['hashrate1hr'] = isset($mh[1]) ? $mh[1] : 0;
    $poolData['Users'] = isset($mu[1]) ? $mu[1] : 0;
    $poolData['Workers'] = isset($mw[1]) ? $mw[1] : 0;
    
    // Wyciąganie Accepted/Rejected z całego pliku (ostatnie wystąpienie)
    $acc = 0; $rej = 0;
    foreach (array_reverse($lines) as $l) {
        if (preg_match('/Accepted\s+(\d+)/i', $l, $ma)) { $acc = $ma[1]; break; }
    }
    foreach (array_reverse($lines) as $l) {
        if (preg_match('/Rejected\s+(\d+)/i', $l, $mr)) { $rej = $mr[1]; break; }
    }
    $poolData['accepted'] = $acc;
    $poolData['rejected'] = $rej;
    
    // Oblicz %
    $total = $acc + $rej;
    $poolData['rejected_percent'] = $total > 0 ? ($rej / $total) * 100 : 0;
    
    // Zapis do Live Stats
    $pdo->prepare("INSERT OR REPLACE INTO pool_stats (id, last_update, data) VALUES (1, ?, ?)")
        ->execute([time(), json_encode($poolData)]);
        
    // Zapis do Historii Dziennej (Agregacja/Snapshot)
    // Zapisujemy "snapshot" licznika na dany dzień. Dzięki temu jutro będziemy wiedzieć, ile było wczoraj.
    $today = strtotime('today midnight');
    
    // Pobierz obecną średnią hashrate dla dnia (żeby jej nie nadpisać zerem)
    $stmt = $pdo->prepare("SELECT avg_hashrate_ghs FROM pool_daily_history WHERE date = ?");
    $stmt->execute([$today]);
    $existingHashrate = $stmt->fetchColumn();
    
    $newHashrate = $existingHashrate ? ($existingHashrate + $poolData['hashrate1hr']) / 2 : $poolData['hashrate1hr']; // Prosta średnia krocząca
    
    $pdo->prepare("INSERT OR REPLACE INTO pool_daily_history (date, avg_hashrate_ghs, accepted, rejected) VALUES (?, ?, ?, ?)")
        ->execute([$today, $newHashrate, $acc, $rej]);
        
    echo "Pool stats updated. Acc: $acc, Rej: $rej\n";
} else {
    echo "No stats found in log.\n";
}

if(file_exists($statsDbPath)) { chown($statsDbPath, $webUser); chgrp($statsDbPath, $webGroup); }
?>