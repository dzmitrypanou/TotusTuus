<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/ensure_wgsrt.php';
require_once __DIR__ . '/../includes/lang.php';
try {
    $db = Database::getInstance();
    ensure_wgsrt_grades_lang_columns($db);
    $lang = abs_detect_lang();
    $grades = $db->fetchAll("
        SELECT 
            grade_name,
            grade_name_en,
            grade_code,
            min_value,
            max_value,
            color,
            description,
            description_en,
            sort_order
        FROM wgsrt_grades 
        ORDER BY sort_order
    ");
    foreach ($grades as &$grade) {
        $nameRu = (string) ($grade['grade_name'] ?? '');
        $nameEn = trim((string) ($grade['grade_name_en'] ?? ''));
        $descRu = (string) ($grade['description'] ?? '');
        $descEn = trim((string) ($grade['description_en'] ?? ''));

        $grade['grade_name'] = ($lang === 'en' && $nameEn !== '') ? $nameEn : $nameRu;
        $grade['description'] = ($lang === 'en' && $descEn !== '') ? $descEn : $descRu;
    }
    unset($grade);
    echo json_encode([
        'success' => true,
        'timestamp' => time(),
        'grades' => $grades
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}