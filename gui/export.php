<?php
$historyFile = '/app/traffic-history.json';
$history = [];
if (file_exists($historyFile)) {
    $decoded = json_decode((string)file_get_contents($historyFile), true);
    if (is_array($decoded)) $history = $decoded;
}

header('Content-Type: application/json; charset=utf-8');
header('Content-Disposition: attachment; filename="traffic-history-' . date('Y-m-d_His') . '.json"');
echo json_encode($history, JSON_PRETTY_PRINT);
