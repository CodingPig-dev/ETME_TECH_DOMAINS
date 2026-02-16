<?php
$device = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$rateFile = __DIR__ . '/rate_get.json';
$rates = json_decode(file_get_contents($rateFile) ?: '{}', true);
$now = time();
if (isset($rates[$device]) && ($now - $rates[$device]) < 1) {
    http_response_code(429);
    echo json_encode(['error' => 'rate limit exceeded']);
    exit;
}
$rates[$device] = $now;
file_put_contents($rateFile, json_encode($rates));

$domain = $_GET["domain"] ?? "";
$json = file_get_contents("mapping.json");
$data = json_decode($json, true);

if (isset($data[$domain])) {
    echo json_encode(["url" => $data[$domain]]);
} else {
    echo json_encode(["error" => "unknown domain"]);
}
