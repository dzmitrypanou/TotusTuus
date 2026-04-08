<?php
require_once __DIR__ . '/../includes/bootstrap.php';
header('Content-Type: application/json');
admin_require_ajax();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['id']) && isset($_POST['moderated'])) {
    admin_require_csrf_ajax();
    $id = (int)$_POST['id'];
    $moderated = (int)$_POST['moderated'];
    
    try {
        $db->update(
            "UPDATE tank_dictionary SET is_moderated = ? WHERE id = ?",
            [$moderated, $id]
        );
        
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
} else {
    echo json_encode(['success' => false, 'error' => 'Неверные параметры']);
}