<?php
header('Content-Type: application/json');

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

$device = trim($_POST['device_id'] ?? ($_COOKIE['device_id'] ?? ''));
if ($device === '') {
    $device = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
} else {
    $device = preg_replace('/[^a-zA-Z0-9._-]/', '', $device);
}

if (empty($_COOKIE['device_id']) || $_COOKIE['device_id'] !== $device) {
    setcookie('device_id', $device, time() + 31536000, '/');
}

$password = $_POST['password'] ?? '';
$hash = null;
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

    $last = $rates[$device] ?? 0;
    $now = time();
    $wait = $isAdmin ? 1 : 180;
    if ($now - $last < $wait) {
        $remaining = $wait - ($now - $last);
        echo json_encode(['error' => 'rate_limited', 'retry_seconds' => $remaining, 'device_id' => $device]);
        exit;
    }

    $rates[$device] = $now;
    $tmp = $rateFile . '.tmp';
    file_put_contents($tmp, json_encode($rates, JSON_PRETTY_PRINT));
    rename($tmp, $rateFile);
} else {
    if (!$isAdmin) {
        echo json_encode(['error' => 'forbidden', 'message' => 'existing domain; admin required']);
        exit;
    }
}

$data[$domain] = $url;
$tmp = $mappingFile . '.tmp';
file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT));
rename($tmp, $mappingFile);

echo json_encode(['ok' => true, 'domain' => $domain, 'url' => $url, 'created' => $isNew, 'device_id' => $device]);
