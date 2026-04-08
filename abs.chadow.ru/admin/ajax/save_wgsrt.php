<?php
require_once __DIR__ . '/../includes/bootstrap.php';
header('Content-Type: application/json');
admin_require_ajax();
admin_require_csrf_ajax();
$action = isset($_POST['action']) ? $_POST['action'] : '';
try {
    switch ($action) {
        case 'save_coefficients':
            saveCoefficients($db);
            break;
        case 'save_grades':
            saveGrades($db);
            break;
        case 'delete_grade':
            deleteGrade($db);
            break;
        case 'reset_grades':
            resetGrades($db);
            break;
        default:
            echo json_encode(['success' => false, 'error' => 'Неизвестное действие']);
            break;
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
function saveCoefficients($db) {
    $parameters = ['damage', 'kills', 'assisted', 'received', 'survival', 'hitRatio', 'penRatio', 'spots', 'winRate'];
    foreach ($parameters as $param) {
        $coef = isset($_POST["coef_$param"]) ? floatval($_POST["coef_$param"]) : 0;
        $norm = isset($_POST["norm_$param"]) ? floatval($_POST["norm_$param"]) : 100;
        $min = isset($_POST["min_$param"]) ? floatval($_POST["min_$param"]) : 0;
        $max = isset($_POST["max_$param"]) ? floatval($_POST["max_$param"]) : 10000;
        $db->update(
            "UPDATE wgsrt_coefficients 
             SET coefficient_value = ?, normalization_factor = ?, min_value = ?, max_value = ?, updated_at = NOW() 
             WHERE parameter_name = ?",
            [$coef, $norm, $min, $max, $param]
        );
    }
    echo json_encode(['success' => true]);
}
function saveGrades($db) {
    if (!isset($_POST['grades']) || !is_array($_POST['grades'])) {
        echo json_encode(['success' => false, 'error' => 'Нет данных о градации']);
        return;
    }
    $db->beginTransaction();
    try {
        $existingIds = [];
        $existing = $db->fetchAll("SELECT id FROM wgsrt_grades");
        foreach ($existing as $row) {
            $existingIds[] = $row['id'];
        }
        $processedIds = [];
        foreach ($_POST['grades'] as $grade) {
            $id = isset($grade['id']) && !str_starts_with($grade['id'], 'new_') ? (int)$grade['id'] : null;
            $name = $grade['name'];
            $nameEn = trim((string)($grade['name_en'] ?? ''));
            $code = $grade['code'];
            $min = (int)$grade['min'];
            $max = (int)$grade['max'];
            $desc = $grade['desc'] ?? '';
            $descEn = trim((string)($grade['desc_en'] ?? ''));
            $order = (int)$grade['order'];
            $color = $grade['color'];
            if ($id) {
                $db->update(
                    "UPDATE wgsrt_grades 
                     SET grade_name = ?, grade_name_en = ?, grade_code = ?, min_value = ?, max_value = ?, color = ?, description = ?, description_en = ?, sort_order = ? 
                     WHERE id = ?",
                    [$name, $nameEn !== '' ? $nameEn : $name, $code, $min, $max, $color, $desc, $descEn !== '' ? $descEn : $desc, $order, $id]
                );
                $processedIds[] = $id;
            } else {
                $db->insert(
                    "INSERT INTO wgsrt_grades (grade_name, grade_name_en, grade_code, min_value, max_value, color, description, description_en, sort_order) 
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
                    [$name, $nameEn !== '' ? $nameEn : $name, $code, $min, $max, $color, $desc, $descEn !== '' ? $descEn : $desc, $order]
                );
            }
        }
        $toDelete = array_diff($existingIds, $processedIds);
        foreach ($toDelete as $id) {
            $db->delete("DELETE FROM wgsrt_grades WHERE id = ?", [$id]);
        }
        $db->commit();
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        $db->rollback();
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}
function deleteGrade($db) {
    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    if ($id > 0) {
        $db->delete("DELETE FROM wgsrt_grades WHERE id = ?", [$id]);
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Неверный ID']);
    }
}
function resetGrades($db) {
    $db->delete("DELETE FROM wgsrt_grades");
    $defaultGrades = [
        ['Очень плохой', 'Very bad', 'very-bad', 0, 1999, '#ff4444', 'Игрок с очень низкой эффективностью', 'Player with very low efficiency', 1],
        ['Плохой', 'Bad', 'bad', 2000, 3999, '#ff8844', 'Игрок с низкой эффективностью', 'Player with low efficiency', 2],
        ['Средний', 'Average', 'average', 4000, 5999, '#ffdd44', 'Игрок со средней эффективностью', 'Player with average efficiency', 3],
        ['Хороший', 'Good', 'good', 6000, 7499, '#44ff44', 'Игрок с хорошей эффективностью', 'Player with good efficiency', 4],
        ['Отличный', 'Excellent', 'excellent', 7500, 8999, '#44ddff', 'Игрок с отличной эффективностью', 'Player with excellent efficiency', 5],
        ['Профессионал', 'Professional', 'professional', 9000, 10000, '#aa44ff', 'Игрок с профессиональной эффективностью', 'Player with professional efficiency', 6]
    ];
    foreach ($defaultGrades as $grade) {
        $db->insert(
            "INSERT INTO wgsrt_grades (grade_name, grade_name_en, grade_code, min_value, max_value, color, description, description_en, sort_order) 
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
            $grade
        );
    }
    echo json_encode(['success' => true]);
}