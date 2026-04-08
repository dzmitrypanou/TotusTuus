<?php
require_once __DIR__ . '/../includes/bootstrap.php';
header('Content-Type: application/json');
admin_require_ajax();

$stats = $db->fetchOne("
    SELECT 
        COUNT(*) as total,
        SUM(is_premium) as premium,
        SUM(is_collectible) as collectible,
        SUM(CASE WHEN is_premium = 0 AND is_collectible = 0 THEN 1 ELSE 0 END) as regular,
        SUM(is_moderated) as moderated,
        SUM(CASE WHEN is_moderated = 0 THEN 1 ELSE 0 END) as unmoderated,
        SUM(CASE WHEN tank_type = 'heavy' THEN 1 ELSE 0 END) as heavy,
        SUM(CASE WHEN tank_type = 'medium' THEN 1 ELSE 0 END) as medium,
        SUM(CASE WHEN tank_type = 'light' THEN 1 ELSE 0 END) as light,
        SUM(CASE WHEN tank_type = 'td' THEN 1 ELSE 0 END) as td,
        SUM(CASE WHEN tank_type = 'spg' THEN 1 ELSE 0 END) as spg
    FROM tank_dictionary
");

echo json_encode([
    'success' => true,
    'stats' => $stats
]);