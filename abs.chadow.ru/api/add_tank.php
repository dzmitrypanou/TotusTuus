<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/vehicle_code.php';
require_once __DIR__ . '/../config/ensure_dictionary_labels.php';
require_once __DIR__ . '/../config/dictionary_helpers.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

try {
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($data['vehicle_code']) || !isset($data['display_name'])) {
        throw new Exception('Отсутствуют обязательные поля');
    }
    
    $vehicleCode = normalize_vehicle_code($data['vehicle_code']);
    if ($vehicleCode === '') {
        throw new Exception('Пустой код техники');
    }
    $displayName = $data['display_name'];
    
    $db = Database::getInstance();
    ensure_dictionary_labels_tables($db);

    $nationFromCode = explode(':', $vehicleCode, 2)[0] ?? 'unknown';
    ensure_nation_label($db, $nationFromCode);

    $existing = $db->fetchOne(
        "SELECT id FROM tank_dictionary WHERE vehicle_code = ?",
        [$vehicleCode]
    );
    
    if ($existing) {
        echo json_encode([
            'success' => true,
            'message' => 'Танк уже существует в словаре',
            'vehicle_code' => $vehicleCode,
            'display_name' => $displayName
        ]);
        exit();
    }
    
    $nation = $nationFromCode;
    $tankType = $data['tank_type'] ?? 'unknown';
    $tier = $data['tier'] ?? 8;
    $isPremium = $data['is_premium'] ?? false;
    $isCollectible = $data['is_collectible'] ?? false;
    
    $sql = "INSERT INTO tank_dictionary 
            (vehicle_code, display_name_ru, display_name_en, nation, tank_type, tier, is_premium, is_collectible) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
    
    $db->query($sql, [
        $vehicleCode,
        $displayName,
        $displayName,
        $nation,
        $tankType,
        $tier,
        $isPremium ? 1 : 0,
        $isCollectible ? 1 : 0
    ]);
    
    echo json_encode([
        'success' => true,
        'message' => 'Танк успешно добавлен в словарь',
        'vehicle_code' => $vehicleCode,
        'display_name' => $displayName
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ]);
}