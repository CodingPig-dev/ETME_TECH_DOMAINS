<?php
$device = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$rateFile = __DIR__ . '/rate_list.json';
$rates = json_decode(file_get_contents($rateFile) ?: '{}', true);
$now = time();
if (isset($rates[$device]) && ($now - $rates[$device]) < 1) {
    http_response_code(429);
    echo json_encode(['error' => 'rate limit exceeded']);
    exit;
}
$rates[$device] = $now;
file_put_contents($rateFile, json_encode($rates));

header('Content-Type: application/json');

$mappingFile = __DIR__ . '/mapping.json';
$mapJson = @file_get_contents($mappingFile);
$data = [];
if ($mapJson !== false) {
    $data = json_decode($mapJson, true) ?: [];
}

echo json_encode(['mappings' => $data]);

