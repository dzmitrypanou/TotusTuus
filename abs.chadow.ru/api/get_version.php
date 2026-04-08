<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

try {
    $versionRaw = @file_get_contents(__DIR__ . '/../config/version.json');
    $versionData = $versionRaw ? json_decode($versionRaw, true) : null;
    $version = (is_array($versionData) && !empty($versionData['version'])) ? $versionData['version'] : '3.4.4';

    echo json_encode([
        'success' => true,
        'version' => $version
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
