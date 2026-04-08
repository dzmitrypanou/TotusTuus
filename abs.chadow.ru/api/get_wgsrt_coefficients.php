<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
require_once __DIR__ . '/../config/database.php';
try {
    $db = Database::getInstance();
    $coefficients = $db->fetchAll("
        SELECT 
            parameter_name,
            coefficient_value,
            min_value,
            max_value,
            normalization_factor,
            version
        FROM wgsrt_coefficients 
        WHERE is_active = 1
        ORDER BY parameter_name
    ");
    echo json_encode([
        'success' => true,
        'timestamp' => time(),
        'version' => !empty($coefficients) ? $coefficients[0]['version'] : '2.6.0',
        'coefficients' => $coefficients
    ], JSON_NUMERIC_CHECK | JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}