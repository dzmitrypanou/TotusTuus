<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../../config/dictionary_helpers.php';
header('Content-Type: application/json');
admin_require_ajax();

$nationMap = nation_label_map($db);
$typeMap = tank_type_label_map($db);
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$perPage = isset($_GET['perPage']) ? (int)$_GET['perPage'] : 50;
$perPageOptions = [50, 100, 250];
if (!in_array($perPage, $perPageOptions)) {
    $perPage = 50;
}
$offset = ($page - 1) * $perPage;
$search = isset($_GET['search']) ? $_GET['search'] : '';
$type = isset($_GET['type']) ? $_GET['type'] : '';
$nation = isset($_GET['nation']) ? $_GET['nation'] : '';
$moderationFilter = isset($_GET['moderation']) ? $_GET['moderation'] : 'all';
$where = [];
$params = [];
if ($search) {
    $where[] = "(vehicle_code LIKE ? OR display_name_ru LIKE ? OR display_name_en LIKE ?)";
    $searchParam = "%$search%";
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
}
if ($type && $type !== 'all') {
    $where[] = "tank_type = ?";
    $params[] = $type;
}
if ($nation && $nation !== 'all') {
    $where[] = "nation = ?";
    $params[] = $nation;
}
if ($moderationFilter === 'unmoderated') {
    $where[] = "is_moderated = 0";
} elseif ($moderationFilter === 'moderated') {
    $where[] = "is_moderated = 1";
}
$whereClause = $where ? "WHERE " . implode(" AND ", $where) : "";
$totalTanks = $db->fetchOne(
    "SELECT COUNT(*) as count FROM tank_dictionary $whereClause",
    $params
)['count'];
$totalPages = ceil($totalTanks / $perPage);
$tanks = $db->fetchAll(
    "SELECT * FROM tank_dictionary $whereClause ORDER BY display_name_ru LIMIT ? OFFSET ?",
    array_merge($params, [$perPage, $offset])
);
ob_start();
foreach ($tanks as $tank):
    $rowClass = $tank['is_moderated'] ? 'moderated' : 'unmoderated';
?>
<tr class="<?php echo $rowClass; ?>">
    <td><code><?php echo htmlspecialchars($tank['vehicle_code']); ?></code></td>
    <td>
        <?php
            $nameRu = (string) ($tank['display_name_ru'] ?? '');
            $nameEn = trim((string) ($tank['display_name_en'] ?? ''));
            if ($nameEn === '') {
                $nameEn = $nameRu;
            }
        ?>
        <?php echo htmlspecialchars($nameRu); ?> / <?php echo htmlspecialchars($nameEn); ?>
    </td>
    <td><?php echo htmlspecialchars(resolve_dict_label($nationMap, $tank['nation'])); ?></td>
    <td><?php echo htmlspecialchars(resolve_dict_label($typeMap, $tank['tank_type'])); ?></td>
    <td><?php echo $tank['tier']; ?></td>
    <td>
        <?php if ($tank['is_premium']): ?>
            <span class="badge badge-premium"><i class="fas fa-crown"></i> Премиум</span>
        <?php elseif ($tank['is_collectible']): ?>
            <span class="badge badge-collectible"><i class="fas fa-star"></i> Коллекционный</span>
        <?php else: ?>
            <span class="badge"><i class="fas fa-tank"></i> Обычный</span>
        <?php endif; ?>
    </td>
    <td>
        <?php if ($tank['is_moderated']): ?>
            <span class="moderation-badge moderated">
                <i class="fas fa-check-circle"></i> Проверено
            </span>
        <?php else: ?>
            <span class="moderation-badge unmoderated" onclick="markAsModerated(<?php echo $tank['id']; ?>)">
                <i class="fas fa-clock"></i> На проверке
            </span>
        <?php endif; ?>
    </td>
    <td>
        <div class="action-buttons">
            <button type="button" class="action-btn" onclick='openEditModal(<?php echo json_encode($tank); ?>)'>
                <i class="fas fa-edit"></i>
            </button>
            <button type="button" class="action-btn delete" onclick="deleteTank(<?php echo $tank['id']; ?>)">
                <i class="fas fa-trash"></i>
            </button>
        </div>
    </td>
</tr>
<?php endforeach; ?>
<?php
$html = ob_get_clean();
echo json_encode([
    'success' => true,
    'html' => $html,
    'total' => $totalTanks,
    'page' => $page,
    'perPage' => $perPage,
    'totalPages' => $totalPages
]);