<?php
require_once __DIR__ . '/../includes/bootstrap.php';
header('Content-Type: application/json');
admin_require_ajax();

$allowedNations = ['ussr', 'germany', 'usa', 'france', 'uk', 'china', 'japan', 'czech', 'poland', 'sweden', 'italy', 'european', 'intunion', 'unknown'];
$allowedTankTypes = ['heavy', 'medium', 'light', 'td', 'spg', 'unknown'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    admin_require_csrf_ajax();
    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    $displayName = isset($_POST['display_name_ru']) ? trim($_POST['display_name_ru']) : '';
    $displayNameEn = isset($_POST['display_name_en']) ? trim((string) $_POST['display_name_en']) : '';
    $tankType = isset($_POST['tank_type']) ? $_POST['tank_type'] : 'medium';
    $tier = isset($_POST['tier']) ? (int)$_POST['tier'] : 8;
    $techType = isset($_POST['tech_type']) ? $_POST['tech_type'] : 'regular';
    $isModerated = isset($_POST['is_moderated']) ? 1 : 0;
    $nation = isset($_POST['nation']) ? trim($_POST['nation']) : 'unknown';
    if (!in_array($nation, $allowedNations, true)) {
        $nation = 'unknown';
    }
    if (!in_array($tankType, $allowedTankTypes, true)) {
        $tankType = 'medium';
    }
    
    if ($id <= 0 || empty($displayName)) {
        echo json_encode(['success' => false, 'error' => 'Не заполнены обязательные поля']);
        exit();
    }

    if ($displayNameEn === '') {
        $displayNameEn = $displayName;
    }
    
    $isPremium = ($techType === 'premium') ? 1 : 0;
    $isCollectible = ($techType === 'collectible') ? 1 : 0;
    
    $db->update(
        "UPDATE tank_dictionary
         SET display_name_ru = ?,
             display_name_en = ?,
             nation = ?,
             tank_type = ?,
             tier = ?,
             is_premium = ?,
             is_collectible = ?,
             is_moderated = ?
         WHERE id = ?",
        [$displayName, $displayNameEn, $nation, $tankType, $tier, $isPremium, $isCollectible, $isModerated, $id]
    );
    
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'error' => 'Неверный метод запроса']);
}