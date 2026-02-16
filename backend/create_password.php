<?php
$device = php_sapi_name() === 'cli' ? gethostname() : ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
$hashed_device = substr(hash('sha256', $device), 0, -3);
$rateFile = __DIR__ . '/rate_create_password.json';
$rates = json_decode(file_get_contents($rateFile) ?: '{}', true);
$now = time();
if (isset($rates[$hashed_device]) && ($now - $rates[$hashed_device]) < 1800) {
    if (php_sapi_name() !== 'cli') {
        http_response_code(429);
        echo json_encode(['error' => 'rate limit exceeded']);
        exit;
    } else {
        fwrite(STDERR, "Rate limit exceeded\n");
        exit(1);
    }
}
$rates[$hashed_device] = $now;
file_put_contents($rateFile, json_encode($rates));

$save = in_array('--save', $argv, true);
$pwdArg = null;
foreach ($argv as $arg) {
    if (strpos($arg, '--password=') === 0) {
        $pwdArg = substr($arg, strlen('--password='));
        break;
    }
    if (strpos($arg, '-p=') === 0) {
        $pwdArg = substr($arg, strlen('-p='));
        break;
    }
}
if ($pwdArg !== null) {
    $pwd = $pwdArg;
} else {
    if (function_exists('readline')) {
        echo "Enter new delete password: ";
        $pwd = trim(readline(''));
    } else {
        echo "Enter new delete password: ";
        $pwd = trim(fgets(STDIN));
    }
}
if ($pwd === '') {
    fwrite(STDERR, "Empty password\n");
    exit(2);
}
$hash = password_hash($pwd, PASSWORD_DEFAULT);
if ($hash === false) {
    fwrite(STDERR, "Failed to create password hash\n");
    exit(3);
}
echo "Password hash:\n" . $hash . "\n";
if ($save) {
    $cfg = "<?php\nreturn [\n    'delete_password_hash' => '" . addslashes($hash) . "'\n];\n";
    $tmp = __DIR__ . '/config.php.tmp';
    file_put_contents($tmp, $cfg);
    rename($tmp, __DIR__ . '/config.php');
    echo "Saved to config.php\n";
}
