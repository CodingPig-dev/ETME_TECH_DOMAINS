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
$device = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$secret = getenv('SECRET_KEY') ?: '';
if ($secret === '' && file_exists(__DIR__ . '/config.php')) {
    $cfg = include __DIR__ . '/config.php';
    if (is_array($cfg) && !empty($cfg['secret_key'])) {
        $secret = $cfg['secret_key'];
    }
}
if ($secret === '') {
    error_log('WARNING: SECRET_KEY not set; using fallback hashing (set SECRET_KEY in environment or config.php for better security)');
    $hashed_device = substr(hash('sha256', $device), 0, 16);
} else {
    $hashed_device = substr(hash_hmac('sha256', $device, $secret), 0, 16);
}
$rateFile = __DIR__ . '/rate_list.json';
$rates = json_decode(file_get_contents($rateFile) ?: '{}', true);
$now = time();
$rates = array_filter($rates, function($timestamp) use ($now) {
    return ($now - $timestamp) < 86400;
});
if (isset($rates[$hashed_device]) && ($now - $rates[$hashed_device]) < 1) {
    http_response_code(429);
    echo json_encode(['error' => 'rate limit exceeded']);
    exit;
}
$rates[$hashed_device] = $now;
file_put_contents($rateFile, json_encode($rates));
header('Content-Type: application/json');
$mappingFile = __DIR__ . '/mapping.json';
$mapJson = @file_get_contents($mappingFile);
$data = [];
if ($mapJson !== false) {
    $data = json_decode($mapJson, true) ?: [];
}

echo json_encode(['mappings' => $data]);
