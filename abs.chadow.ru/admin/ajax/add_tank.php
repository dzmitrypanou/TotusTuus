<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../../config/dictionary_helpers.php';
header('Content-Type: application/json');
admin_require_ajax();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    admin_require_csrf_ajax();
    $vehicleCode = normalize_vehicle_code($_POST['vehicle_code']);
    if ($vehicleCode === '') {
        echo json_encode(['success' => false, 'error' => 'Укажите код техники']);
        exit;
    }
    $displayName = isset($_POST['display_name_ru']) ? trim((string) $_POST['display_name_ru']) : '';
    $displayNameEn = isset($_POST['display_name_en']) ? trim((string) $_POST['display_name_en']) : '';
    if ($displayNameEn === '') {
        $displayNameEn = $displayName;
    }
    $tankType = $_POST['tank_type'];
    $tier = $_POST['tier'];
    $techType = $_POST['tech_type'];
    $isModerated = isset($_POST['is_moderated']) ? 1 : 0;
    $isPremium = ($techType === 'premium') ? 1 : 0;
    $isCollectible = ($techType === 'collectible') ? 1 : 0;
    $existing = $db->fetchOne("SELECT id FROM tank_dictionary WHERE vehicle_code = ?", [$vehicleCode]);
    if (!$existing) {
        $allowedNations = ['ussr', 'germany', 'usa', 'france', 'uk', 'china', 'japan', 'czech', 'poland', 'sweden', 'italy', 'european', 'intunion', 'unknown'];
        $allowedTankTypes = ['heavy', 'medium', 'light', 'td', 'spg', 'unknown'];
        if (isset($_POST['nation']) && in_array($_POST['nation'], $allowedNations, true)) {
            $nation = $_POST['nation'];
        } else {
            $nation = explode(':', $vehicleCode)[0] ?? 'unknown';
            $nationMap = [
                'ussr' => 'ussr', 'germany' => 'germany', 'usa' => 'usa', 'france' => 'france',
                'uk' => 'uk', 'china' => 'china', 'japan' => 'japan', 'czech' => 'czech',
                'poland' => 'poland', 'sweden' => 'sweden', 'italy' => 'italy', 'european' => 'european',
                'intunion' => 'intunion',
            ];
            $nation = isset($nationMap[$nation]) ? $nation : 'unknown';
        }
        if (!in_array($tankType, $allowedTankTypes, true)) {
            $tankType = 'medium';
        }
        ensure_nation_label($db, $nation);
        $db->insert(
            "INSERT INTO tank_dictionary (vehicle_code, display_name_ru, display_name_en, nation, tank_type, tier, is_premium, is_collectible, is_moderated) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [$vehicleCode, $displayName, $displayNameEn, $nation, $tankType, $tier, $isPremium, $isCollectible, $isModerated]
        );
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Танк с таким кодом уже существует']);
    }
} else {
    echo json_encode(['success' => false, 'error' => 'Неверный метод запроса']);
}