<?php
header('Content-Type: application/json');

require_once __DIR__ . '/prune_rates.php';
@prune_rates(__DIR__);

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

// Determine raw device identifier: prefer explicit device_id from client, else cookie, else remote addr
$raw_device = trim($_POST['device_id'] ?? ($_COOKIE['device_id'] ?? ''));
if ($raw_device === '') {
    $raw_device = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
} else {
    $raw_device = preg_replace('/[^a-zA-Z0-9._-]/', '', $raw_device);
}

// Use HMAC with a server-side SECRET_KEY to pseudonymize identifiers
$secret = getenv('SECRET_KEY') ?: '';
if ($secret === '' && file_exists(__DIR__ . '/config.php')) {
    $cfg = include __DIR__ . '/config.php';
    if (is_array($cfg) && !empty($cfg['secret_key'])) {
        $secret = $cfg['secret_key'];
    }
}
if ($secret === '') {
    // WARNING: production should set SECRET_KEY; fallback to plain hash but do not store raw IP in cookies
    error_log('WARNING: SECRET_KEY not set; using fallback hashing (set SECRET_KEY in environment or config.php for better security)');
    $hashed_device = substr(hash('sha256', $raw_device), 0, 16);
} else {
    $hashed_device = substr(hash_hmac('sha256', $raw_device, $secret), 0, 16);
}

// Only set a persistent cookie if the client explicitly consented (e.g., sent consent=1)
$consent = ($_POST['consent'] ?? ($_COOKIE['consent'] ?? '')) === '1';
if ($consent) {
    // set secure cookie with flags; requires PHP 7.3+ for array options
    $cookieValue = $hashed_device;
    $cookieParams = [
        'expires' => time() + 31536000,
        'path' => '/',
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Lax'
    ];
    setcookie('device_id', $cookieValue, $cookieParams);

    // Log consent minimally to consent_log.json for proof (hashed device, domain, timestamp)
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
    // Prune consent entries older than 90 days (90 * 24 * 3600)
    $cutoff = $now - (90 * 24 * 3600);
    $consentEntries = array_values(array_filter($consentEntries, function($e) use ($cutoff) {
        return isset($e['ts']) && $e['ts'] >= $cutoff;
    }));
    $tmp = $consentFile . '.tmp';
    file_put_contents($tmp, json_encode($consentEntries, JSON_PRETTY_PRINT));
    rename($tmp, $consentFile);
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

echo json_encode(['ok' => true, 'domain' => $domain, 'url' => $url, 'created' => $isNew, 'device_id' => $hashed_device]);
