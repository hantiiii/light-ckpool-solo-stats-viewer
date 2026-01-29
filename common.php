<?php
// --- Common Configuration v90 ---
$apiTimeout = 15; 
$bitcoinCliUser = 'bitcoinnode'; 
$bitcoinCliPath = '/usr/local/bin/bitcoin-cli'; 

// --- Common Functions ---

/**
 * Executes a bitcoin-cli command securely using argument escaping.
 * @param array|string $command_args Array of arguments (preferred) or string (legacy).
 * @return array ['output' => string|null, 'error' => string|null]
 */
function run_bitcoin_cli($command_args) {
    global $bitcoinCliPath, $bitcoinCliUser;
    
    if (empty($bitcoinCliPath) || !is_executable($bitcoinCliPath)) {
        return ['output' => null, 'error' => 'bitcoin-cli not found or not executable'];
    }

    // Security Hardening: Construct command parts safely
    $cmdParts = [];
    $cmdParts[] = 'sudo -u ' . escapeshellarg($bitcoinCliUser);
    $cmdParts[] = escapeshellcmd($bitcoinCliPath);

    // Handle arguments
    if (is_array($command_args)) {
        foreach ($command_args as $arg) {
            $cmdParts[] = escapeshellarg($arg); // Securely escape every single argument
        }
    } else {
        // Legacy fallback (less secure, strictly for internal hardcoded strings)
        $cmdParts[] = $command_args; 
    }

    $full_command = implode(' ', $cmdParts) . ' 2>&1';
    $output = @shell_exec($full_command); 

    if ($output === null) {
        return ['output' => null, 'error' => 'shell_exec returned null'];
    }
    
    $trimmed = trim($output);
    // Detect RPC errors
    if ($trimmed === '' || stripos($trimmed, 'error code:') !== false || stripos($trimmed, 'error:') !== false) {
        return ['output' => null, 'error' => $trimmed ?: 'Empty output'];
    }

    return ['output' => $trimmed, 'error' => null];
}

function api_fetch($url) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_TIMEOUT, $GLOBALS['apiTimeout']);
    curl_setopt($ch, CURLOPT_USERAGENT, 'CkpoolStatsViewer/v90 (HANTI Security)');
    curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    
    if (strpos($url, 'coinbase.com') !== false) {
       curl_setopt($ch, CURLOPT_HTTPHEADER, array('CB-VERSION: ' . date('Y-m-d')));
    }

    $output = curl_exec($ch);
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpcode == 200 && $output) {
        return $output;
    }
    return null;
}
?>