<?php
require_once __DIR__ . '/../includes/bootstrap.php';
header('Content-Type: application/json');
admin_require_ajax();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['id'])) {
    admin_require_csrf_ajax();
    $id = (int)$_POST['id'];
    
    $db->delete("DELETE FROM tank_dictionary WHERE id = ?", [$id]);
    
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'error' => 'Неверные параметры']);
}