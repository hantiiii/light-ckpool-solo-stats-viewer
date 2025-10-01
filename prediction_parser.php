#!/usr/bin/php
<?php
// --- Configuration ---
$dataDir = __DIR__ . '/data';
$dbPath = $dataDir . '/history.db';
$webUser = 'web1';
$webGroup = 'client1';
// --------------------

function fetch_json_with_fallback(string $primary_url, string $fallback_url, callable $parser_func) {
    $context = stream_context_create(['http' => ['timeout' => 10, 'ignore_errors' => true]]);
    $data = null;
    
    // Try Primary API
    echo "Attempting to fetch from primary API: $primary_url\n";
    $response = @file_get_contents($primary_url, false, $context);
    if ($response && strpos($http_response_header[0], '200') !== false) {
        $data = $parser_func(json_decode($response, true), 'primary');
        if ($data !== null) {
            echo "Successfully parsed data from primary API.\n";
            return $data;
        }
    }
    echo "Primary API failed or returned invalid data.\n";

    // Try Fallback API
    echo "Attempting to fetch from fallback API: $fallback_url\n";
    $response = @file_get_contents($fallback_url, false, $context);
    if ($response && strpos($http_response_header[0], '200') !== false) {
        $data = $parser_func(json_decode($response, true), 'fallback');
        if ($data !== null) {
            echo "Successfully parsed data from fallback API.\n";
            return $data;
        }
    }
    echo "Fallback API failed or returned invalid data.\n";
    
    return null;
}

function get_difficulty_prediction(): ?array {
    echo "Fetching blockchain data from local node...\n";
    $current_height = (int)trim(@shell_exec('bitcoin-cli getblockcount'));
    if (!$current_height) {
        echo "Failed to get current block height from bitcoin-cli.\n";
        return null;
    }

    $blocks_in_epoch = 2016;
    $last_adjustment_height = floor($current_height / $blocks_in_epoch) * $blocks_in_epoch;
    $blocks_since_adjustment = $current_height - $last_adjustment_height;
    $progress = round(($blocks_since_adjustment / $blocks_in_epoch) * 100, 2);

    if ($blocks_since_adjustment < 20) { // Require at least 20 blocks for a semi-stable prediction
        echo "Not enough blocks in the current epoch ({$blocks_since_adjustment}) for a reliable prediction.\n";
        return ['progress' => $progress, 'prediction' => null, 'avg_time' => null];
    }

    $start_hash = trim(@shell_exec("bitcoin-cli getblockhash {$last_adjustment_height}"));
    if (!$start_hash) return null;
    $start_block = json_decode(@shell_exec("bitcoin-cli getblock {$start_hash}"), true);
    $start_time = $start_block['time'] ?? null;

    $current_hash = trim(@shell_exec("bitcoin-cli getblockhash {$current_height}"));
    if (!$current_hash) return null;
    $current_block = json_decode(@shell_exec("bitcoin-cli getblock {$current_hash}"), true);
    $current_time = $current_block['time'] ?? null;

    if (!$start_time || !$current_time || $current_time <= $start_time) {
        echo "Invalid block timestamps retrieved from node.\n";
        return ['progress' => $progress, 'prediction' => null, 'avg_time' => null];
    }

    $time_elapsed = $current_time - $start_time;
    $avg_block_time = $time_elapsed / $blocks_since_adjustment;
    
    // --- Here you could add the multi-factor logic using external APIs if desired ---
    // For now, we use the robust time-based prediction from the local node.

    $expected_time = $blocks_in_epoch * 600; // 2016 blocks * 10 minutes
    $projected_total_time = $avg_block_time * $blocks_in_epoch;

    if ($projected_total_time == 0) return ['progress' => $progress, 'prediction' => null, 'avg_time' => round($avg_block_time, 2)];

    $prediction = (($expected_time / $projected_total_time) - 1) * 100;

    echo "Prediction generated successfully.\n";
    return [
        'progress' => $progress,
        'prediction' => round($prediction, 2),
        'avg_time' => round($avg_block_time, 2)
    ];
}

$prediction_data = get_difficulty_prediction();

if ($prediction_data) {
    try {
        if (!is_dir($dataDir)) { mkdir($dataDir, 0775, true); }
        $pdo = new PDO('sqlite:' . $dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec("CREATE TABLE IF NOT EXISTS prediction_data (id INTEGER PRIMARY KEY, last_update INTEGER, progress REAL, prediction REAL, avg_time REAL)");
        
        $pdo->exec("DELETE FROM prediction_data");
        $stmt = $pdo->prepare("INSERT INTO prediction_data (id, last_update, progress, prediction, avg_time) VALUES (1, ?, ?, ?, ?)");
        $stmt->execute([time(), $prediction_data['progress'], $prediction_data['prediction'], $prediction_data['avg_time']]);
        
        chown($dbPath, $webUser); chgrp($dbPath, $webGroup);
        echo "Successfully updated difficulty prediction data in the database.\n";
    } catch (PDOException $e) {
        die("ERROR: DB operation failed: " . $e->getMessage() . "\n");
    }
} else {
    echo "Failed to generate prediction data. Skipping database update.\n";
}