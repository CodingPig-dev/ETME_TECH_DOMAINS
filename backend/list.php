<?php
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


