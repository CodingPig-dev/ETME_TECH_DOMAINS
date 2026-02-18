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
$password = $_POST['password'] ?? '';
$hash = getenv('DELETE_PASSWORD_HASH') ?: null;
if (empty($hash)) {
    if (file_exists(__DIR__ . '/config.php')) {
        $cfg = include __DIR__ . '/config.php';
        if (is_array($cfg) && !empty($cfg['delete_password_hash'])) {
            $hash = $cfg['delete_password_hash'];
        }
    }
}
$allowed = false;
if (!empty($hash)) {
    if ($password !== '' && password_verify($password, $hash)) {
        $allowed = true;
    }
}
if (! $allowed) {
    echo json_encode(['error' => 'forbidden', 'message' => 'invalid or missing password']);
    exit;
}
if ($domain === '') {
    echo json_encode(['error' => 'domain required']);
    exit;
}
$mappingFile = __DIR__ . '/mapping.json';
$mapJson = @file_get_contents($mappingFile);
$data = [];
if ($mapJson !== false) {
    $data = json_decode($mapJson, true) ?: [];
}
if (!isset($data[$domain])) {
    echo json_encode(['error' => 'unknown domain']);
    exit;
}
unset($data[$domain]);
$tmp = $mappingFile . '.tmp';
file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT));
rename($tmp, $mappingFile);
echo json_encode(['ok' => true, 'domain' => $domain]);
