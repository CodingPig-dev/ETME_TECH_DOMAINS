<?php
header('Content-Type: application/json');

$domain = trim($_POST['domain'] ?? '');
$password = $_POST['password'] ?? '';

$hash = getenv('DELETE_PASSWORD_HASH') ?: null;
$plaintext_env = getenv('DELETE_PASSWORD') ?: null;

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
} elseif (!empty($plaintext_env)) {
    if ($password !== '' && hash_equals($plaintext_env, $password)) {
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

