<?php
require_once __DIR__ . '/../includes/bootstrap.php';
header('Content-Type: application/json');
admin_require_ajax();

if (isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    
    $tank = $db->fetchOne(
        "SELECT * FROM tank_dictionary WHERE id = ?",
        [$id]
    );
    
    if ($tank) {
        echo json_encode([
            'success' => true,
            'tank' => $tank
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'error' => 'Танк не найден'
        ]);
    }
} else {
    echo json_encode([
        'success' => false,
        'error' => 'Не указан ID танка'
    ]);
}