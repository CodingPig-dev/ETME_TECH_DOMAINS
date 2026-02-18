<?php
if (php_sapi_name() !== 'cli') {
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    header('Content-Type: application/json; charset=utf-8');
    set_exception_handler(function($e){
        http_response_code(500);
        error_log('Uncaught exception: ' . $e->getMessage());
        echo json_encode(['error' => 'internal_server_error', 'message' => 'Internal server error']);
        exit;
    });
    set_error_handler(function($errno, $errstr, $errfile, $errline){
        http_response_code(500);
        error_log("PHP error: $errstr in $errfile:$errline");
        echo json_encode(['error' => 'internal_server_error', 'message' => 'Internal server error']);
        exit;
    });

    // Consent check: require either header X-ETME-Consent: 1 or cookie consent=1
    $consent_ok = false;
    $hdr = $_SERVER['HTTP_X_ETME_CONSENT'] ?? $_SERVER['HTTP_X_ETMECONSENT'] ?? '';
    if ($hdr === '1') $consent_ok = true;
    if (isset($_COOKIE['consent']) && $_COOKIE['consent'] === '1') $consent_ok = true;
    if (! $consent_ok) {
        http_response_code(403);
        echo json_encode(['error' => 'consent_required', 'message' => 'Consent to the privacy policy is required before using this API.']);
        exit;
    }
}
ob_start();
register_shutdown_function(function() {
    $content = ob_get_clean();
    $json_pos = strpos($content, '{"');
    if ($json_pos !== false) {
        $content = substr($content, $json_pos);
    }
    echo $content;
});
$prune_called = false;
if (function_exists('prune_rates')) {
    @prune_rates(__DIR__);
    $prune_called = true;
} else {
    $pruneFile = __DIR__ . '/prune_rates.php';
    if (file_exists($pruneFile)) {
        @include_once $pruneFile;
        if (function_exists('prune_rates')) {
            @prune_rates(__DIR__);
            $prune_called = true;
        }
    }
}
if (! $prune_called) {
    $rate_ttl = 24 * 3600;
    $now = time();
    $pattern = __DIR__ . '/rate_*.json';
    foreach (glob($pattern) as $file) {
        if (!is_file($file)) continue;
        $json = @file_get_contents($file);
        $data = $json ? json_decode($json, true) : [];
        if (!is_array($data)) continue;
        $changed = false;
        foreach ($data as $k => $v) {
            if (!is_numeric($v) || intval($v) < ($now - $rate_ttl)) {
                unset($data[$k]);
                $changed = true;
            }
        }
        if ($changed) {
            $tmp = $file . '.tmp';
            @file_put_contents($tmp, json_encode($data));
            @rename($tmp, $file);
        }
    }
}
$domain = trim($_POST['domain'] ?? '');
$url = trim($_POST['url'] ?? '');
if ($domain === '' || $url === '') {
    echo json_encode(['error' => 'domain and url required']);
    exit;
}
if (!preg_match('/^[a-zA-Z0-9.-]+$/', $domain) || strlen($domain) > 253) {
    echo json_encode(['error' => 'invalid domain']);
    exit;
}
if (!filter_var($url, FILTER_VALIDATE_URL)) {
    echo json_encode(['error' => 'invalid url']);
    exit;
}
$mappingFile = __DIR__ . '/mapping.json';
$rateFile = __DIR__ . '/rate.json';
$mapJson = @file_get_contents($mappingFile);
$data = [];
if ($mapJson !== false) {
    $data = json_decode($mapJson, true) ?: [];
}
$raw_device = trim($_POST['device_id'] ?? ($_COOKIE['device_id'] ?? ''));
if ($raw_device === '') {
    $raw_device = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
} else {
    $raw_device = preg_replace('/[^a-zA-Z0-9._-]/', '', $raw_device);
}
$secret = getenv('SECRET_KEY') ?: '';
if ($secret === '' && file_exists(__DIR__ . '/config.php')) {
    $cfg = include __DIR__ . '/config.php';
    if (is_array($cfg) && !empty($cfg['secret_key'])) {
        $secret = $cfg['secret_key'];
    }
}
if ($secret === '') {
    error_log('WARNING: SECRET_KEY not set; using fallback hashing (set SECRET_KEY in environment or config.php for better security)');
    $hashed_device = substr(hash('sha256', $raw_device), 0, 16);
} else {
    $hashed_device = substr(hash_hmac('sha256', $raw_device, $secret), 0, 16);
}
$consent = ($_POST['consent'] ?? ($_COOKIE['consent'] ?? '')) === '1';
if ($consent) {
    $cookieValue = $hashed_device;
    $cookieParams = [
        'expires' => time() + 31536000,
        'path' => '/',
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Lax'
    ];
    setcookie('device_id', $cookieValue, $cookieParams);
    $consentFile = __DIR__ . '/consent_log.json';
    $consentEntries = [];
    $now = time();
    $consentJson = @file_get_contents($consentFile);
    if ($consentJson !== false) {
        $consentEntries = json_decode($consentJson, true) ?: [];
    }
    $consentEntries[] = [
        'device' => $hashed_device,
        'domain' => $domain,
        'ts' => $now,
        'action' => 'consent_given'
    ];
    $cutoff = $now - (90 * 24 * 3600);
    $consentEntries = array_values(array_filter($consentEntries, function($e) use ($cutoff) {
        return isset($e['ts']) && $e['ts'] >= $cutoff;
    }));
    $tmp = $consentFile . '.tmp';
    file_put_contents($tmp, json_encode($consentEntries));
    rename($tmp, $consentFile);
}
$password = $_POST['password'] ?? '';
$hash = null;
$hash = getenv('DELETE_PASSWORD_HASH') ?: $hash;
if (file_exists(__DIR__ . '/config.php')) {
    $cfg = include __DIR__ . '/config.php';
    if (is_array($cfg) && !empty($cfg['delete_password_hash'])) {
        $hash = $cfg['delete_password_hash'];
    }
}
$isAdmin = false;
if (!empty($hash) && $password !== '' && password_verify($password, $hash)) {
    $isAdmin = true;
}
$isNew = !isset($data[$domain]);
if ($isNew) {
    $rates = [];
    $rateJson = @file_get_contents($rateFile);
    if ($rateJson !== false) {
        $rates = json_decode($rateJson, true) ?: [];
    }
    $last = $rates[$hashed_device] ?? 0;
    $now = time();
    $wait = $isAdmin ? 1 : 180;
    if ($now - $last < $wait) {
        $remaining = $wait - ($now - $last);
        echo json_encode(['error' => 'rate_limited', 'retry_seconds' => $remaining, 'device_id' => $hashed_device]);
        exit;
    }
    $rates[$hashed_device] = $now;
    $tmp = $rateFile . '.tmp';
    file_put_contents($tmp, json_encode($rates));
    rename($tmp, $rateFile);
} else {
    if (!$isAdmin) {
        echo json_encode(['error' => 'forbidden', 'message' => 'existing domain; admin required']);
        exit;
    }
}
$data[$domain] = $url;
$tmp = $mappingFile . '.tmp';
file_put_contents($tmp, json_encode($data));
rename($tmp, $mappingFile);
// Also write a PHP cache file mapping.php for faster includes (atomic)
$phpCache = __DIR__ . '/mapping.php';
$phpTmp = $phpCache . '.tmp';
$export = var_export($data, true);
$phpContent = "<?php\n// generated mapping cache - do not edit\nreturn $export;\n";
file_put_contents($phpTmp, $phpContent);
rename($phpTmp, $phpCache);

echo json_encode(['ok' => true, 'domain' => $domain, 'url' => $url, 'created' => $isNew, 'device_id' => $hashed_device]);
