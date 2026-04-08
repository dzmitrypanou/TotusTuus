<?php
require_once __DIR__ . '/../includes/bootstrap.php';
header('Content-Type: application/json');
admin_require_ajax();
$grades = $db->fetchAll("
    SELECT id, grade_name, grade_name_en, grade_code, min_value, max_value, color, description, description_en, sort_order
    FROM wgsrt_grades 
    ORDER BY sort_order
");
echo json_encode([
    'success' => true,
    'grades' => $grades
]);