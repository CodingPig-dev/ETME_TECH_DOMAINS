<?php
// prune_rates.php
// Contains function prune_rates($dir, $rate_ttl, $consent_ttl)
// When executed directly (CLI), it runs and prints a message.

function prune_rates($dir = __DIR__, $rate_ttl = 86400, $consent_ttl = 0) {
    if ($consent_ttl === 0) $consent_ttl = 90 * 24 * 3600;
    $lockFile = $dir . '/.prune_lock';
    $fp = @fopen($lockFile, 'c');
    if (! $fp) return false;
    // Try exclusive non-blocking lock
    if (!flock($fp, LOCK_EX | LOCK_NB)) {
        fclose($fp);
        return false;
    }

    try {
        $now = time();
        $cutoff = $now - $rate_ttl;
        $pattern = $dir . '/rate_*.json';
        $files = glob($pattern);
        foreach ($files as $file) {
            $json = @file_get_contents($file);
            if ($json === false) continue;
            $data = json_decode($json, true);
            if (!is_array($data)) $data = [];
            $changed = false;
            foreach ($data as $k => $v) {
                if (!is_numeric($v) || intval($v) < $cutoff) {
                    unset($data[$k]);
                    $changed = true;
                }
            }
            if ($changed) {
                $tmp = $file . '.tmp';
                file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT));
                rename($tmp, $file);
            }
        }

        // Prune consent log
        $consentFile = $dir . '/consent_log.json';
        if (file_exists($consentFile)) {
            $json = @file_get_contents($consentFile);
            $entries = $json !== false ? (json_decode($json, true) ?: []) : [];
            if (is_array($entries)) {
                $cut = $now - $consent_ttl;
                $before = count($entries);
                $entries = array_values(array_filter($entries, function($e) use ($cut) {
                    return isset($e['ts']) && is_numeric($e['ts']) && $e['ts'] >= $cut;
                }));
                if (count($entries) !== $before) {
                    $tmp = $consentFile . '.tmp';
                    file_put_contents($tmp, json_encode($entries, JSON_PRETTY_PRINT));
                    rename($tmp, $consentFile);
                }
            }
        }
    } finally {
        flock($fp, LOCK_UN);
        fclose($fp);
    }

    return true;
}

// If executed directly from CLI or as top-level script, run once and print status.
if (php_sapi_name() === 'cli' && realpath($argv[0] ?? '') === realpath(__FILE__)) {
    prune_rates(__DIR__);
    echo "Prune complete.\n";
}
