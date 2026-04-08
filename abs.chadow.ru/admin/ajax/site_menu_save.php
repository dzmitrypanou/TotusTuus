<?php
require_once __DIR__ . '/../includes/bootstrap.php';
header('Content-Type: application/json; charset=UTF-8');
admin_require_ajax();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Неверный метод']);
    exit;
}

admin_require_csrf_ajax();

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) {
    echo json_encode(['success' => false, 'error' => 'Неверный формат данных']);
    exit;
}

/** @var array<int, array{label:string,label_en?:string,href:string,is_enabled:bool}> $headerItems */
$headerItems = [];
/** @var array<int, array{label:string,label_en?:string,href:string,is_enabled:bool}> $footerItems */
$footerItems = [];
if (isset($data['header']) && is_array($data['header'])) {
    $headerItems = $data['header'];
} elseif (isset($data['items']) && is_array($data['items'])) {
    $headerItems = $data['items'];
}
if (isset($data['footer']) && is_array($data['footer'])) {
    $footerItems = $data['footer'];
}

try {
    $db->beginTransaction();
    $db->delete('DELETE FROM cms_site_menu', []);
    $insert = function (array $rows, $placement) use ($db) {
        $sortOrder = 0;
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $label = isset($row['label']) ? trim((string) $row['label']) : '';
            $labelEn = isset($row['label_en']) ? trim((string) $row['label_en']) : '';
            if ($label === '') {
                continue;
            }
            $href = site_menu_sanitize_href_input($row['href'] ?? '/');
            $enabled = !empty($row['is_enabled']) ? 1 : 0;
            $sortOrder++;
            $db->insert(
                'INSERT INTO cms_site_menu (label, label_en, href, placement, sort_order, is_enabled) VALUES (?, ?, ?, ?, ?, ?)',
                [$label, $labelEn, $href, $placement, $sortOrder, $enabled]
            );
        }
    };
    $insert($headerItems, 'header');
    $insert($footerItems, 'footer');
    $db->commit();
    echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    try {
        $db->rollback();
    } catch (Throwable $t) {
        // ignore
    }
    echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
