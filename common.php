<?php
// --- Common Configuration ---
$apiTimeout = 15; // Timeout for external API calls in seconds
// --- Bitcoin Core Config ---
$bitcoinCliUser = 'bitcoinnode'; // User running bitcoind
$bitcoinCliPath = '/usr/local/bin/bitcoin-cli'; // Path to bitcoin-cli

// --- Common Functions ---

/**
 * Executes a bitcoin-cli command as the specified user.
 * @param string $command_args Arguments to pass to bitcoin-cli (e.g., "getblockcount")
 * @return array ['output' => string|null, 'error' => string|null]
 */
function run_bitcoin_cli($command_args) {
    global $bitcoinCliPath, $bitcoinCliUser;
    if (empty($bitcoinCliPath) || !is_executable($bitcoinCliPath) || empty($bitcoinCliUser)) {
        echo "Warning: bitcoin-cli path ('{$bitcoinCliPath}') or user ('{$bitcoinCliUser}') not configured correctly or cli not executable.\n";
        return ['output' => null, 'error' => 'bitcoin-cli not configured'];
    }
    // Use sudo -u <user> and redirect stderr to stdout
    $full_command = 'sudo -u ' . escapeshellarg($bitcoinCliUser) . ' ' . escapeshellcmd($bitcoinCliPath) . ' ' . $command_args . ' 2>&1';
    $output = @shell_exec($full_command); 

    if ($output === null) {
        return ['output' => null, 'error' => 'shell_exec failed or returned null'];
    }
    $trimmed_output = trim($output);
    // Check for common error patterns
    if ($trimmed_output === '' || strpos(strtolower($trimmed_output), 'error code:') !== false || strpos(strtolower($trimmed_output), 'error:') !== false) {
        return ['output' => null, 'error' => $trimmed_output ?: 'Empty output received'];
    }
    // Assume success
    return ['output' => $trimmed_output, 'error' => null];
}

/**
 * Fetches data from a URL using cURL.
 * @param string $url The URL to fetch.
 * @return string|null The raw response body or null on failure.
 */
function api_fetch($url) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_TIMEOUT, $GLOBALS['apiTimeout']);
    curl_setopt($ch, CURLOPT_USERAGENT, 'CkpoolStatsViewer/1.2 (PHP cURL)');
    curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    
    // Add Coinbase specific header if needed
    if (strpos($url, 'coinbase.com') !== false) {
       curl_setopt($ch, CURLOPT_HTTPHEADER, array('CB-VERSION: ' . date('Y-m-d')));
    }

    $output = curl_exec($ch);
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error_num = curl_errno($ch);
    $curl_error_msg = curl_error($ch);
    curl_close($ch);

    if ($curl_error_num > 0) {
        echo "Warning: cURL error for {$url}: [{$curl_error_num}] {$curl_error_msg}\n";
        return null;
    } elseif ($httpcode != 200) {
        echo "Warning: Failed API fetch for {$url} (HTTP: {$httpcode})\n";
        return $output; // Return raw output to see API error message
    } elseif ($output) {
        return $output;
    }

    echo "Warning: API fetch for {$url} returned empty response (HTTP: {$httpcode})\n";
    return null;
}
?>