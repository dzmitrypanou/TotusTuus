<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/ensure_dictionary_labels.php';
require_once __DIR__ . '/../config/dictionary_helpers.php';
require_once __DIR__ . '/../config/vehicle_code.php';

try {
    $db = Database::getInstance();
    ensure_dictionary_labels_tables($db);
    merge_duplicate_vehicle_codes($db);
    $nationLabelsRu = nation_label_map($db);
    $nationLabelsEn = nation_label_map_en($db);
    $tankTypeLabelsRu = tank_type_label_map($db);
    $tankTypeLabelsEn = tank_type_label_map_en($db);

    $tanks = $db->fetchAll("
        SELECT 
            vehicle_code,
            display_name_ru,
            display_name_en,
            nation,
            tank_type,
            tier,
            is_premium,
            is_collectible,
            is_moderated
        FROM tank_dictionary 
        ORDER BY display_name_ru
    ");
    
    echo json_encode([
        'success' => true,
        'timestamp' => time(),
        'count' => count($tanks),
        'data' => $tanks,
        // Старые клиенты/рендер: RU-колонка.
        'nation_labels' => $nationLabelsRu,
        'tank_type_labels' => $tankTypeLabelsRu,
        // Новые клиенты: оба языка.
        'nation_labels_ru' => $nationLabelsRu,
        'nation_labels_en' => $nationLabelsEn,
        'tank_type_labels_ru' => $tankTypeLabelsRu,
        'tank_type_labels_en' => $tankTypeLabelsEn
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}