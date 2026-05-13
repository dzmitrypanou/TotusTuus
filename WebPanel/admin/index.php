<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/panel_security.php';
require_once __DIR__ . '/../api/db.php';
require_once __DIR__ . '/../api/schema.php';
require_once __DIR__ . '/../includes/panel_auth.php';

panel_configure_session_before_start();
session_start();
panel_ensure_csrf_token();
panel_send_admin_security_headers();

if (($_SERVER['SCRIPT_NAME'] ?? '') === '/admin/index.php') {
    header('Location: /', true, 302);
    exit;
}

$message = null;
$error = null;
$isSetupRequired = false;
$storedPasswordHash = null;
$authReady = false;
$isAjaxRequest = (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest')
    || (($_POST['ajax'] ?? '') === '1');

function ajaxResponse(bool $ok, string $messageText = '', string $errorText = ''): void
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok' => $ok,
        'message' => $messageText,
        'error' => $errorText,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && !panel_post_skips_csrf_check()) {
    if (!panel_csrf_token_valid()) {
        if ($isAjaxRequest) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'ok' => false,
                'message' => '',
                'error' => 'Сесія састарэла або несапраўдны токен. Абнавіце старонку.',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }
        http_response_code(403);
        echo '403 — несапраўдны токен бяспекі. Абнавіце старонку.';
        exit;
    }
}

function getAdminPasswordHash(): ?string
{
    $stmt = db()->query('SELECT password_hash FROM admin_auth WHERE id = 1 LIMIT 1');
    $row = $stmt->fetch();
    if (!is_array($row)) {
        return null;
    }
    return (string)$row['password_hash'];
}

function getCategories(): array
{
    $stmt = db()->query(
        'SELECT c.id, c.name, c.parent_id, c.sort_order, p.name AS parent_name
         FROM prayer_categories c
         LEFT JOIN prayer_categories p ON c.parent_id = p.id
         WHERE c.is_active = 1
         ORDER BY (c.parent_id IS NULL) DESC, c.parent_id ASC, c.sort_order ASC, c.id ASC'
    );
    return $stmt->fetchAll();
}

function categoryPathLabel(array $category): string
{
    $name = (string)$category['name'];
    $parentName = (string)($category['parent_name'] ?? '');
    return $parentName !== '' ? ($parentName . ' -> ' . $name) : $name;
}

function buildCategoryTree(array $categories): array
{
    $byId = [];
    foreach ($categories as $category) {
        $id = (int)$category['id'];
        $byId[$id] = [
            'id' => $id,
            'name' => (string)$category['name'],
            'parent_id' => $category['parent_id'] !== null ? (int)$category['parent_id'] : null,
            'sort_order' => (int)($category['sort_order'] ?? 0),
            'children' => [],
        ];
    }

    $roots = [];
    foreach ($byId as $id => $node) {
        $parentId = $node['parent_id'];
        if ($parentId !== null && isset($byId[$parentId])) {
            $byId[$parentId]['children'][] = &$byId[$id];
        } else {
            $roots[] = &$byId[$id];
        }
    }
    $sortNodes = function (array &$nodes) use (&$sortNodes): void {
        usort($nodes, static function (array $a, array $b): int {
            $cmp = ($a['sort_order'] ?? 0) <=> ($b['sort_order'] ?? 0);
            if ($cmp !== 0) {
                return $cmp;
            }
            return ($a['id'] ?? 0) <=> ($b['id'] ?? 0);
        });
        foreach ($nodes as &$node) {
            if (!empty($node['children'])) {
                $sortNodes($node['children']);
            }
        }
    };

    $sortNodes($roots);
    return $roots;
}

function moveCategory(int $categoryId, string $direction): bool
{
    $stmtCurrent = db()->prepare(
        'SELECT id, parent_id, sort_order
         FROM prayer_categories
         WHERE id = :id
         LIMIT 1'
    );
    $stmtCurrent->execute([':id' => $categoryId]);
    $current = $stmtCurrent->fetch();
    if (!is_array($current)) {
        return false;
    }
    $parentId = $current['parent_id'] !== null ? (int)$current['parent_id'] : null;

    if ($parentId === null) {
        $stmtSiblings = db()->query(
            'SELECT id
             FROM prayer_categories
             WHERE parent_id IS NULL
             ORDER BY sort_order ASC, id ASC'
        );
    } else {
        $stmtSiblings = db()->prepare(
            'SELECT id
             FROM prayer_categories
             WHERE parent_id = :parent_id
             ORDER BY sort_order ASC, id ASC'
        );
        $stmtSiblings->execute([':parent_id' => $parentId]);
    }
    $siblings = $stmtSiblings->fetchAll();
    if (!is_array($siblings) || count($siblings) <= 1) {
        return false;
    }

    $ids = array_map(static fn(array $row): int => (int)$row['id'], $siblings);
    $currentIndex = array_search($categoryId, $ids, true);
    if ($currentIndex === false) {
        return false;
    }

    $targetIndex = $direction === 'up' ? $currentIndex - 1 : $currentIndex + 1;
    if ($targetIndex < 0 || $targetIndex >= count($ids)) {
        return false;
    }

    $tmp = $ids[$currentIndex];
    $ids[$currentIndex] = $ids[$targetIndex];
    $ids[$targetIndex] = $tmp;

    db()->beginTransaction();
    try {
        $update = db()->prepare('UPDATE prayer_categories SET sort_order = :sort_order WHERE id = :id');
        foreach ($ids as $index => $id) {
            $update->execute([
                ':sort_order' => $index + 1,
                ':id' => $id,
            ]);
        }
        db()->commit();
        return true;
    } catch (Throwable $e) {
        db()->rollBack();
        throw $e;
    }
}

function reorderCategoriesByIds(?int $parentId, array $orderedIds): void
{
    if (count($orderedIds) === 0) {
        return;
    }
    if ($parentId === null) {
        $update = db()->prepare(
            'UPDATE prayer_categories
             SET sort_order = :sort_order
             WHERE id = :id
               AND parent_id IS NULL'
        );
        foreach (array_values($orderedIds) as $index => $id) {
            $update->execute([
                ':sort_order' => $index + 1,
                ':id' => (int)$id,
            ]);
        }
    } else {
        $update = db()->prepare(
            'UPDATE prayer_categories
             SET sort_order = :sort_order
             WHERE id = :id
               AND parent_id = :parent_id'
        );
        foreach (array_values($orderedIds) as $index => $id) {
            $update->execute([
                ':sort_order' => $index + 1,
                ':id' => (int)$id,
                ':parent_id' => $parentId,
            ]);
        }
    }
}

function getPrayerForEdit(int $id): ?array
{
    $stmt = db()->prepare(
        'SELECT id, title, text, category_id, language, sort_order
         FROM prayers
         WHERE id = :id
         LIMIT 1'
    );
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}

/** Наступны sort_order у гэтай жа асноўнай катэгорыі (уключаючы NULL). */
function nextPrayerSortOrder(?int $categoryId): int
{
    $stmt = db()->prepare(
        'SELECT COALESCE(MAX(sort_order), 0) + 1 AS n
         FROM prayers
         WHERE category_id <=> :cid'
    );
    $stmt->execute([':cid' => $categoryId]);
    $row = $stmt->fetch();
    return is_array($row) ? (int)($row['n'] ?? 1) : 1;
}

function getPrayerCategoryIds(int $prayerId): array
{
    $stmt = db()->prepare(
        'SELECT category_id
         FROM prayer_category_links
         WHERE prayer_id = :prayer_id
         ORDER BY is_primary DESC, category_id ASC'
    );
    $stmt->execute([':prayer_id' => $prayerId]);
    $rows = $stmt->fetchAll();
    return array_values(array_map(static fn(array $row): int => (int)$row['category_id'], $rows));
}

function savePrayerCategories(int $prayerId, ?int $primaryCategoryId, array $additionalCategoryIds): void
{
    $cleanAdditional = [];
    foreach ($additionalCategoryIds as $categoryId) {
        $value = (int)$categoryId;
        if ($value > 0) {
            $cleanAdditional[] = $value;
        }
    }
    $cleanAdditional = array_values(array_unique($cleanAdditional));
    if ($primaryCategoryId !== null) {
        $cleanAdditional = array_values(array_filter(
            $cleanAdditional,
            static fn(int $categoryId): bool => $categoryId !== $primaryCategoryId
        ));
    }

    $delete = db()->prepare('DELETE FROM prayer_category_links WHERE prayer_id = :prayer_id');
    $delete->execute([':prayer_id' => $prayerId]);

    if ($primaryCategoryId !== null) {
        $insertPrimary = db()->prepare(
            'INSERT INTO prayer_category_links (prayer_id, category_id, is_primary)
             VALUES (:prayer_id, :category_id, 1)'
        );
        $insertPrimary->execute([
            ':prayer_id' => $prayerId,
            ':category_id' => $primaryCategoryId,
        ]);
    }

    if (count($cleanAdditional) > 0) {
        $insertAdditional = db()->prepare(
            'INSERT INTO prayer_category_links (prayer_id, category_id, is_primary)
             VALUES (:prayer_id, :category_id, 0)'
        );
        foreach ($cleanAdditional as $categoryId) {
            $insertAdditional->execute([
                ':prayer_id' => $prayerId,
                ':category_id' => $categoryId,
            ]);
        }
    }
}

function collectCategoryIdsForDeletion(int $rootCategoryId): array
{
    $stmt = db()->query('SELECT id, parent_id FROM prayer_categories');
    $rows = $stmt->fetchAll();
    $childrenByParent = [];
    foreach ($rows as $row) {
        $parentId = $row['parent_id'] !== null ? (int)$row['parent_id'] : 0;
        if (!isset($childrenByParent[$parentId])) {
            $childrenByParent[$parentId] = [];
        }
        $childrenByParent[$parentId][] = (int)$row['id'];
    }

    $result = [];
    $stack = [$rootCategoryId];
    while (count($stack) > 0) {
        $current = array_pop($stack);
        if (in_array($current, $result, true)) {
            continue;
        }
        $result[] = $current;
        foreach ($childrenByParent[$current] ?? [] as $childId) {
            $stack[] = $childId;
        }
    }
    return $result;
}

function songbookUploadsDir(): string
{
    $dir = __DIR__ . '/../uploads/songbook';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    return $dir;
}

/** Пашырэнні выяваў: у т.ч. эканамічныя WebP і AVIF. */
const SONGBOOK_IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'avif'];

/** @return list<string> */
function songbookImageMimeTypes(): array
{
    return [
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/gif',
        'image/avif',
    ];
}

function songbookIsAllowedImagePath(?string $relPath): bool
{
    if ($relPath === null || $relPath === '') {
        return false;
    }
    $ext = strtolower(pathinfo($relPath, PATHINFO_EXTENSION));

    return in_array($ext, SONGBOOK_IMAGE_EXTENSIONS, true);
}

/**
 * @param array<string, mixed> $fileField элементаў з $_FILES['sb_media']
 */
function songbookUploadedImageMimeOk(array $fileField): bool
{
    $tmp = (string)($fileField['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        return false;
    }
    if (!function_exists('finfo_open')) {
        return true;
    }
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    if ($finfo === false) {
        return true;
    }
    $mime = (string)finfo_file($finfo, $tmp);
    finfo_close($finfo);

    return in_array($mime, songbookImageMimeTypes(), true);
}

/**
 * Захаванне выявы спеўніка (JPEG, PNG, WebP, GIF, AVIF).
 *
 * @param array<string, mixed> $fileField элементаў з $_FILES['sb_media']
 */
function songbookApplyUploadedMedia(int $entryId, array $fileField): bool
{
    $tmp = (string)($fileField['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        return false;
    }
    if ((int)($fileField['error'] ?? 0) !== UPLOAD_ERR_OK) {
        return false;
    }
    $orig = (string)($fileField['name'] ?? '');
    $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
    if (!in_array($ext, SONGBOOK_IMAGE_EXTENSIONS, true)) {
        return false;
    }
    if (!songbookUploadedImageMimeOk($fileField)) {
        return false;
    }
    $basename = $entryId . '.' . $ext;
    $destFs = songbookUploadsDir() . '/' . $basename;
    if (!move_uploaded_file($tmp, $destFs)) {
        return false;
    }
    $hash = hash_file('sha256', $destFs);
    $rel = 'uploads/songbook/' . $basename;
    $upd = db()->prepare(
        'UPDATE songbook_entries SET media_path = :path, media_revision = :rev WHERE id = :id'
    );
    $upd->execute([':path' => $rel, ':rev' => $hash, ':id' => $entryId]);

    return true;
}

function songbookDeleteMediaFile(?string $mediaPath): void
{
    if ($mediaPath === null || $mediaPath === '') {
        return;
    }
    $fs = __DIR__ . '/../' . ltrim($mediaPath, '/');
    if (is_file($fs)) {
        @unlink($fs);
    }
}

try {
    ensureAuthTable();
    ensureSchemaAndSeed();
    $storedPasswordHash = getAdminPasswordHash();
    $isSetupRequired = $storedPasswordHash === null;
    $authReady = true;
} catch (Throwable $e) {
    $error = 'Памылка ініцыялізацыі аўтарызацыі/БД: ' . $e->getMessage();
}

if (isset($_POST['logout'])) {
    panel_clear_login_session();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, (string)$params['path'], (string)$params['domain'], (bool)$params['secure'], (bool)$params['httponly']);
    }
    session_destroy();
    header('Location: /');
    exit;
}

if ($authReady && $isSetupRequired && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['setup_password'])) {
    $password = (string)($_POST['setup_password'] ?? '');
    $passwordConfirm = (string)($_POST['setup_password_confirm'] ?? '');
    if (strlen($password) < 8) {
        $error = 'Мінімальная даўжыня пароля — 8 сімвалаў.';
    } elseif ($password !== $passwordConfirm) {
        $error = 'Паролі не супадаюць.';
    } else {
        try {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = db()->prepare(
                'INSERT INTO admin_auth (id, password_hash)
                 VALUES (1, :password_hash)
                 ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash)'
            );
            $stmt->execute([':password_hash' => $hash]);
            $newAdminId = panel_ensure_first_admin_user($hash, 'admin');
            session_regenerate_id(true);
            if ($newAdminId !== null && $newAdminId > 0) {
                panel_set_logged_in_user($newAdminId);
            }
            panel_rotate_csrf_token();
            header('Location: /');
            exit;
        } catch (Throwable $e) {
            $error = 'Памылка ўстаноўкі пароля: ' . $e->getMessage();
        }
    }
}

if ($authReady && !$isSetupRequired && $_SERVER['REQUEST_METHOD'] === 'POST'
    && (isset($_POST['panel_login'], $_POST['panel_password']) || isset($_POST['login_password']))) {
    $loginTry = '';
    if (isset($_POST['panel_login'], $_POST['panel_password'])) {
        $loginTry = (string)$_POST['panel_login'];
        $passwordTry = (string)$_POST['panel_password'];
    } else {
        $passwordTry = (string)$_POST['login_password'];
    }
    $loginResult = panel_attempt_login($loginTry, $passwordTry);
    if ($loginResult['ok']) {
        session_regenerate_id(true);
        panel_set_logged_in_user((int)$loginResult['user_id']);
        panel_rotate_csrf_token();
        header('Location: /');
        exit;
    }
    $error = $loginResult['error'] ?? 'Памылка ўваходу.';
}

$isLoggedIn = $authReady && panel_is_logged_in();
$view = 'categories';
if ($isLoggedIn) {
    $allowedViews = ['categories', 'add-category', 'add-prayer', 'prayers', 'songbook', 'add-songbook', 'kantaral', 'add-kantaral', 'scripture', 'scripture-import', 'scripture-chapter', 'no-access'];
    $requestedView = (string)($_GET['view'] ?? 'categories');
    if (in_array($requestedView, $allowedViews, true)) {
        $view = $requestedView;
    }
    $sec = panel_view_section($view);
    if ($sec !== null && !panel_can_access_section($sec)) {
        $view = panel_first_accessible_view();
    }
}

if ($authReady && $isLoggedIn && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_category_name'])) {
    panel_require_section_for_post('prayers', $isAjaxRequest);
    $categoryName = trim((string)$_POST['create_category_name']);
    $parentIdRaw = (string)($_POST['create_category_parent_id'] ?? '');
    $parentId = $parentIdRaw !== '' ? (int)$parentIdRaw : null;

    if ($categoryName === '') {
        $error = 'Назва катэгорыі абавязковая.';
    } else {
        try {
            $stmtNextOrder = db()->prepare(
                'SELECT COALESCE(MAX(sort_order), 0) + 1 AS next_order
                 FROM prayer_categories
                 WHERE parent_id <=> :parent_id'
            );
            $stmtNextOrder->execute([':parent_id' => $parentId]);
            $nextOrder = (int)($stmtNextOrder->fetch()['next_order'] ?? 1);

            $stmt = db()->prepare(
                'INSERT INTO prayer_categories (name, parent_id, sort_order, is_active)
                 VALUES (:name, :parent_id, :sort_order, 1)'
            );
            $stmt->execute([
                ':name' => $categoryName,
                ':parent_id' => $parentId,
                ':sort_order' => $nextOrder,
            ]);
            $message = $parentId === null
                ? 'Катэгорыя створана.'
                : 'Падкатэгорыя створана.';
        } catch (Throwable $e) {
            $error = 'Памылка пры захаванні катэгорыі: ' . $e->getMessage();
        }
    }
    if ($isAjaxRequest) {
        ajaxResponse($error === null, $message ?? '', $error ?? '');
    }
}

if ($authReady && $isLoggedIn && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_category_id'])) {
    panel_require_section_for_post('prayers', $isAjaxRequest);
    $categoryId = (int)$_POST['update_category_id'];
    $categoryName = trim((string)($_POST['update_category_name'] ?? ''));
    $parentIdRaw = (string)($_POST['update_category_parent_id'] ?? '');
    $parentId = $parentIdRaw !== '' ? (int)$parentIdRaw : null;

    if ($categoryName === '') {
        $error = 'Назва катэгорыі абавязковая.';
    } elseif ($parentId === $categoryId) {
        $error = 'Катэгорыя не можа быць бацькам самой сябе.';
    } else {
        try {
            $stmt = db()->prepare(
                'UPDATE prayer_categories
                 SET name = :name, parent_id = :parent_id
                 WHERE id = :id'
            );
            $stmt->execute([
                ':name' => $categoryName,
                ':parent_id' => $parentId,
                ':id' => $categoryId,
            ]);
            $message = 'Катэгорыя абноўлена.';
        } catch (Throwable $e) {
            $error = 'Памылка пры абноўленні катэгорыі: ' . $e->getMessage();
        }
    }
    if ($isAjaxRequest) {
        ajaxResponse($error === null, $message ?? '', $error ?? '');
    }
}

if ($authReady && $isLoggedIn && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_prayer'], $_POST['title'], $_POST['text'])) {
    panel_require_section_for_post('prayers', $isAjaxRequest);
    $title = trim((string)$_POST['title']);
    $text = trim((string)$_POST['text']);
    $parentCategoryIdRaw = (string)($_POST['parent_category_id'] ?? '');
    $parentCategoryId = $parentCategoryIdRaw !== '' ? (int)$parentCategoryIdRaw : null;
    $existingSubcategoryIdRaw = (string)($_POST['subcategory_id'] ?? '');
    $existingSubcategoryId = $existingSubcategoryIdRaw !== '' ? (int)$existingSubcategoryIdRaw : null;
    $additionalCategoryIdsRaw = $_POST['additional_category_ids'] ?? [];
    $additionalCategoryIds = is_array($additionalCategoryIdsRaw)
        ? array_values(array_filter(array_map('intval', $additionalCategoryIdsRaw), static fn(int $v): bool => $v > 0))
        : [];
    $language = trim((string)($_POST['language'] ?? ''));
    $sortOrderRaw = trim((string)($_POST['sort_order'] ?? ''));
    $sortOrderExplicit = ($sortOrderRaw !== '' && ctype_digit($sortOrderRaw)) ? (int)$sortOrderRaw : null;

    if ($title === '' || $text === '') {
        $error = 'Назва і тэкст малітвы абавязковыя.';
    } else {
        try {
            db()->beginTransaction();
            $categoryId = null;
            if ($existingSubcategoryId !== null) {
                $categoryId = $existingSubcategoryId;
            } elseif ($parentCategoryId !== null) {
                $categoryId = $parentCategoryId;
            }

            $categoryLabel = null;
            if ($categoryId !== null) {
                $stmtCategory = db()->prepare('SELECT name FROM prayer_categories WHERE id = :id LIMIT 1');
                $stmtCategory->execute([':id' => $categoryId]);
                $categoryRow = $stmtCategory->fetch();
                $categoryLabel = is_array($categoryRow) ? (string)$categoryRow['name'] : null;
            }

            $sortOrder = $sortOrderExplicit ?? nextPrayerSortOrder($categoryId);

            $stmt = db()->prepare(
                'INSERT INTO prayers (title, text, category, category_id, language, sort_order, is_active)
                 VALUES (:title, :text, :category, :category_id, :language, :sort_order, 1)'
            );
            $stmt->execute([
                ':title' => $title,
                ':text' => $text,
                ':category' => $categoryLabel,
                ':category_id' => $categoryId,
                ':language' => $language !== '' ? $language : null,
                ':sort_order' => $sortOrder,
            ]);
            $prayerId = (int)db()->lastInsertId();
            savePrayerCategories($prayerId, $categoryId, $additionalCategoryIds);
            db()->commit();
            $message = 'Маліта дададзена. Прыкладанне зможа дагрузіць яе пры абнаўленні.';
        } catch (Throwable $e) {
            if (db()->inTransaction()) {
                db()->rollBack();
            }
            $error = 'Памылка пры захаванні: ' . $e->getMessage();
        }
    }
    if ($isAjaxRequest) {
        ajaxResponse($error === null, $message ?? '', $error ?? '');
    }
}

if ($authReady && $isLoggedIn && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_prayer_id'])) {
    panel_require_section_for_post('prayers', $isAjaxRequest);
    $prayerId = (int)$_POST['update_prayer_id'];
    $title = trim((string)($_POST['update_title'] ?? ''));
    $text = trim((string)($_POST['update_text'] ?? ''));
    $parentCategoryIdRaw = (string)($_POST['update_parent_category_id'] ?? '');
    $parentCategoryId = $parentCategoryIdRaw !== '' ? (int)$parentCategoryIdRaw : null;
    $subcategoryIdRaw = (string)($_POST['update_subcategory_id'] ?? '');
    $subcategoryId = $subcategoryIdRaw !== '' ? (int)$subcategoryIdRaw : null;
    $legacyCategoryIdRaw = (string)($_POST['update_category_id'] ?? '');
    $legacyCategoryId = $legacyCategoryIdRaw !== '' ? (int)$legacyCategoryIdRaw : null;
    $categoryId = $subcategoryId ?? $parentCategoryId ?? $legacyCategoryId;
    $additionalCategoryIdsRaw = $_POST['update_additional_category_ids'] ?? [];
    $additionalCategoryIds = is_array($additionalCategoryIdsRaw)
        ? array_values(array_filter(array_map('intval', $additionalCategoryIdsRaw), static fn(int $v): bool => $v > 0))
        : [];
    $language = trim((string)($_POST['update_language'] ?? ''));
    $updateSortRaw = trim((string)($_POST['update_sort_order'] ?? ''));
    $updateSortOrder = ($updateSortRaw !== '' && ctype_digit($updateSortRaw)) ? max(0, (int)$updateSortRaw) : 0;

    if ($title === '' || $text === '') {
        $error = 'Назва і тэкст малітвы абавязковыя.';
    } else {
        try {
            db()->beginTransaction();
            $categoryLabel = null;
            if ($categoryId !== null) {
                $stmtCategory = db()->prepare('SELECT name FROM prayer_categories WHERE id = :id LIMIT 1');
                $stmtCategory->execute([':id' => $categoryId]);
                $categoryRow = $stmtCategory->fetch();
                $categoryLabel = is_array($categoryRow) ? (string)$categoryRow['name'] : null;
            }

            $stmt = db()->prepare(
                'UPDATE prayers
                 SET title = :title,
                     text = :text,
                     category = :category,
                     category_id = :category_id,
                     language = :language,
                     sort_order = :sort_order
                 WHERE id = :id'
            );
            $stmt->execute([
                ':title' => $title,
                ':text' => $text,
                ':category' => $categoryLabel,
                ':category_id' => $categoryId,
                ':language' => $language !== '' ? $language : null,
                ':sort_order' => $updateSortOrder,
                ':id' => $prayerId,
            ]);
            savePrayerCategories($prayerId, $categoryId, $additionalCategoryIds);
            db()->commit();
            $message = 'Маліта абноўлена.';
        } catch (Throwable $e) {
            if (db()->inTransaction()) {
                db()->rollBack();
            }
            $error = 'Памылка пры абноўленні малітвы: ' . $e->getMessage();
        }
    }
    if ($isAjaxRequest) {
        ajaxResponse($error === null, $message ?? '', $error ?? '');
    }
}

if ($authReady && $isLoggedIn && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_category_id'])) {
    panel_require_section_for_post('prayers', $isAjaxRequest);
    $categoryId = (int)$_POST['delete_category_id'];
    if ($categoryId <= 0) {
        $error = 'Некарэктны ID катэгорыі.';
    } else {
        try {
            $idsToDelete = collectCategoryIdsForDeletion($categoryId);
            if (count($idsToDelete) > 0) {
                $placeholders = implode(',', array_fill(0, count($idsToDelete), '?'));
                $clearStmt = db()->prepare(
                    'UPDATE prayers
                     SET category = NULL, category_id = NULL
                     WHERE category_id IN (' . $placeholders . ')'
                );
                $clearStmt->execute($idsToDelete);

                $deleteStmt = db()->prepare(
                    'DELETE FROM prayer_categories
                     WHERE id IN (' . $placeholders . ')'
                );
                $deleteStmt->execute($idsToDelete);
                $message = 'Катэгорыя і яе падкатэгорыі выдалены.';
            }
        } catch (Throwable $e) {
            $error = 'Памылка пры выдаленні катэгорыі: ' . $e->getMessage();
        }
    }
    if ($isAjaxRequest) {
        ajaxResponse($error === null, $message ?? '', $error ?? '');
    }
}

if ($authReady && $isLoggedIn && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_prayer_id'])) {
    panel_require_section_for_post('prayers', $isAjaxRequest);
    $prayerId = (int)$_POST['delete_prayer_id'];
    if ($prayerId <= 0) {
        $error = 'Некарэктны ID малітвы.';
    } else {
        try {
            $stmt = db()->prepare('DELETE FROM prayers WHERE id = :id');
            $stmt->execute([':id' => $prayerId]);
            $message = 'Маліта выдалена.';
        } catch (Throwable $e) {
            $error = 'Памылка пры выдаленні малітвы: ' . $e->getMessage();
        }
    }
    if ($isAjaxRequest) {
        ajaxResponse($error === null, $message ?? '', $error ?? '');
    }
}

if ($authReady && $isLoggedIn && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_songbook'])) {
    panel_require_section_for_post('songbook', $isAjaxRequest);
    $title = trim((string)($_POST['sb_title'] ?? ''));
    $category = trim((string)($_POST['sb_category'] ?? ''));
    $chapterMajor = (int)($_POST['sb_chapter_major'] ?? 0);
    $subRaw = trim((string)($_POST['sb_subchapter'] ?? ''));
    $subchapter = $subRaw !== '' ? (int)$subRaw : null;
    $contentType = trim((string)($_POST['sb_content_type'] ?? 'text'));
    $textBody = (string)($_POST['sb_text_body'] ?? '');
    $sortOrder = (int)($_POST['sb_sort_order'] ?? 0);
    if (!in_array($contentType, ['text', 'image'], true)) {
        $error = 'Нясапраўдны тып зместу.';
    } elseif ($chapterMajor < 1) {
        $error = 'Нумар главы павінен быць не менш за 1.';
    } elseif ($subchapter !== null && $subchapter < 1) {
        $error = 'Падглава павінна быць не менш за 1.';
    } elseif ($contentType === 'text' && trim($textBody) === '') {
        $error = 'Увядзіце тэкст.';
    } elseif (
        $contentType === 'image' &&
        (!isset($_FILES['sb_media']) || !is_array($_FILES['sb_media']) || (int)($_FILES['sb_media']['error'] ?? 0) !== UPLOAD_ERR_OK)
    ) {
        $error = 'Загрузіце выяву (JPEG, PNG, WebP, GIF або AVIF).';
    } else {
        try {
            $ins = db()->prepare(
                'INSERT INTO songbook_entries (title, category, chapter_major, subchapter, content_type, text_body, media_path, media_revision, sort_order, is_active)
                 VALUES (:title, :cat, :cm, :sc, :ct, :tb, NULL, \'\', :so, 1)'
            );
            $ins->execute([
                ':title' => $title,
                ':cat' => $category,
                ':cm' => $chapterMajor,
                ':sc' => $subchapter,
                ':ct' => $contentType,
                ':tb' => $contentType === 'text' ? $textBody : '',
                ':so' => $sortOrder,
            ]);
            $newId = (int)db()->lastInsertId();
            if ($contentType === 'image') {
                /** @var array<string, mixed> $mediaFile */
                $mediaFile = $_FILES['sb_media'];
                if (!songbookApplyUploadedMedia($newId, $mediaFile)) {
                    db()->prepare('DELETE FROM songbook_entries WHERE id = :id')->execute([':id' => $newId]);
                    $error = 'Не ўдалося захаваць выяву. Праверце фармат (JPEG, PNG, WebP, GIF, AVIF).';
                }
            }
            if ($error === null) {
                $message = 'Запіс спеўніка дададзены.';
            }
        } catch (Throwable $e) {
            $error = 'Памылка пры захаванні: ' . $e->getMessage();
        }
    }
    if ($isAjaxRequest) {
        ajaxResponse($error === null, $message ?? '', $error ?? '');
    }
}

if ($authReady && $isLoggedIn && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_songbook_id'])) {
    panel_require_section_for_post('songbook', $isAjaxRequest);
    $songbookId = (int)$_POST['update_songbook_id'];
    $title = trim((string)($_POST['sb_title'] ?? ''));
    $category = trim((string)($_POST['sb_category'] ?? ''));
    $chapterMajor = (int)($_POST['sb_chapter_major'] ?? 0);
    $subRaw = trim((string)($_POST['sb_subchapter'] ?? ''));
    $subchapter = $subRaw !== '' ? (int)$subRaw : null;
    $contentType = trim((string)($_POST['sb_content_type'] ?? 'text'));
    $textBody = (string)($_POST['sb_text_body'] ?? '');
    $sortOrder = (int)($_POST['sb_sort_order'] ?? 0);
    if ($songbookId <= 0) {
        $error = 'Некарэктны ID.';
    } elseif (!in_array($contentType, ['text', 'image'], true)) {
        $error = 'Нясапраўдны тып зместу.';
    } elseif ($chapterMajor < 1) {
        $error = 'Нумар главы павінен быць не менш за 1.';
    } elseif ($subchapter !== null && $subchapter < 1) {
        $error = 'Падглава павінна быць не менш за 1.';
    } elseif ($contentType === 'text' && trim($textBody) === '') {
        $error = 'Увядзіце тэкст.';
    } else {
        try {
            $curStmt = db()->prepare('SELECT media_path, content_type FROM songbook_entries WHERE id = :id LIMIT 1');
            $curStmt->execute([':id' => $songbookId]);
            $cur = $curStmt->fetch();
            if (!is_array($cur)) {
                $error = 'Запіс не знойдзены.';
            } else {
                $oldPath = $cur['media_path'] !== null ? (string)$cur['media_path'] : '';
                if ($contentType === 'text') {
                    songbookDeleteMediaFile($oldPath !== '' ? $oldPath : null);
                    $upd = db()->prepare(
                        'UPDATE songbook_entries
                         SET title = :title, category = :cat, chapter_major = :cm, subchapter = :sc, content_type = :ct,
                             text_body = :tb, media_path = NULL, media_revision = \'\', sort_order = :so
                         WHERE id = :id'
                    );
                    $upd->execute([
                        ':title' => $title,
                        ':cat' => $category,
                        ':cm' => $chapterMajor,
                        ':sc' => $subchapter,
                        ':ct' => $contentType,
                        ':tb' => $textBody,
                        ':so' => $sortOrder,
                        ':id' => $songbookId,
                    ]);
                } else {
                    $hasNewFile = isset($_FILES['sb_media']) && is_array($_FILES['sb_media'])
                        && (int)($_FILES['sb_media']['error'] ?? 0) === UPLOAD_ERR_OK;
                    if ($contentType === 'image') {
                        if (!$hasNewFile && $oldPath === '') {
                            $error = 'Для выявы загрузіце файл (пры рэдагаванні можна пакінуць бягучы файл).';
                        } elseif (!$hasNewFile && $oldPath !== '' && !songbookIsAllowedImagePath($oldPath)) {
                            $error = 'Бягучы файл не падыходзіць. Загрузіце выяву: JPEG, PNG, WebP, GIF або AVIF.';
                        }
                    }
                    if ($error === null) {
                        $upd = db()->prepare(
                            'UPDATE songbook_entries
                             SET title = :title, category = :cat, chapter_major = :cm, subchapter = :sc, content_type = :ct,
                                 text_body = \'\', sort_order = :so
                             WHERE id = :id'
                        );
                        $upd->execute([
                            ':title' => $title,
                            ':cat' => $category,
                            ':cm' => $chapterMajor,
                            ':sc' => $subchapter,
                            ':ct' => $contentType,
                            ':so' => $sortOrder,
                            ':id' => $songbookId,
                        ]);
                        if ($hasNewFile) {
                            songbookDeleteMediaFile($oldPath !== '' ? $oldPath : null);
                            /** @var array<string, mixed> $mediaFile */
                            $mediaFile = $_FILES['sb_media'];
                            if (!songbookApplyUploadedMedia($songbookId, $mediaFile)) {
                                $error = 'Не ўдалося захаваць выяву. Праверце фармат (JPEG, PNG, WebP, GIF, AVIF).';
                            }
                        }
                    }
                }
                if ($error === null) {
                    $message = 'Запіс абноўлены.';
                }
            }
        } catch (Throwable $e) {
            $error = 'Памылка пры абноўленні: ' . $e->getMessage();
        }
    }
    if ($isAjaxRequest) {
        ajaxResponse($error === null, $message ?? '', $error ?? '');
    }
}

if ($authReady && $isLoggedIn && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_songbook_id'])) {
    panel_require_section_for_post('songbook', $isAjaxRequest);
    $songbookId = (int)$_POST['delete_songbook_id'];
    if ($songbookId <= 0) {
        $error = 'Некарэктны ID.';
    } else {
        try {
            $st = db()->prepare('SELECT media_path FROM songbook_entries WHERE id = :id LIMIT 1');
            $st->execute([':id' => $songbookId]);
            $r = $st->fetch();
            if (is_array($r) && !empty($r['media_path'])) {
                songbookDeleteMediaFile((string)$r['media_path']);
            }
            db()->prepare('DELETE FROM songbook_entries WHERE id = :id')->execute([':id' => $songbookId]);
            $message = 'Запіс спеўніка выдалены.';
        } catch (Throwable $e) {
            $error = 'Памылка пры выдаленні: ' . $e->getMessage();
        }
    }
    if ($isAjaxRequest) {
        ajaxResponse($error === null, $message ?? '', $error ?? '');
    }
}

if ($authReady && $isLoggedIn && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_songbook_category'])) {
    panel_require_section_for_post('songbook', $isAjaxRequest);
    $category = trim((string)($_POST['sb_bulk_category'] ?? ''));
    $idsRaw = $_POST['songbook_bulk_ids'] ?? [];
    if (!is_array($idsRaw)) {
        $idsRaw = [];
    }
    $uniqueIds = [];
    foreach ($idsRaw as $id) {
        $n = (int)$id;
        if ($n > 0) {
            $uniqueIds[$n] = true;
        }
    }
    $ids = array_keys($uniqueIds);
    if (count($ids) === 0) {
        $error = 'Абярыце хаця б адзін запіс.';
    } elseif (strlen($category) > 255) {
        $error = 'Катэгорыя не даўжэй за 255 сімвалаў.';
    } else {
        try {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = db()->prepare(
                'UPDATE songbook_entries SET category = ? WHERE id IN (' . $placeholders . ')'
            );
            $stmt->execute(array_merge([$category], $ids));
            $affected = $stmt->rowCount();
            $message = 'Абноўлена запісаў: ' . (string)$affected . '.';
        } catch (Throwable $e) {
            $error = 'Памылка пры абноўленні катэгорыі: ' . $e->getMessage();
        }
    }
    if ($isAjaxRequest) {
        ajaxResponse($error === null, $message ?? '', $error ?? '');
    }
}

if ($authReady && $isLoggedIn && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_songbook_autonumber'])) {
    panel_require_section_for_post('songbook', $isAjaxRequest);
    $idsRaw = $_POST['songbook_bulk_ids'] ?? [];
    if (!is_array($idsRaw)) {
        $idsRaw = [];
    }
    $ids = [];
    foreach ($idsRaw as $id) {
        $n = (int)$id;
        if ($n > 0 && !isset($ids[$n])) {
            $ids[$n] = count($ids) + 1;
        }
    }
    if (count($ids) === 0) {
        $error = 'Абярыце хаця б адзін запіс.';
    } else {
        try {
            db()->beginTransaction();
            $stmt = db()->prepare(
                'UPDATE songbook_entries
                 SET chapter_major = :chapter_major, subchapter = NULL
                 WHERE id = :id'
            );
            $updated = 0;
            foreach ($ids as $songId => $chapterMajor) {
                $stmt->execute([
                    ':chapter_major' => $chapterMajor,
                    ':id' => $songId,
                ]);
                $updated += $stmt->rowCount();
            }
            db()->commit();
            $message = 'Аўтанумарацыя ўжыта (1…' . (string)count($ids) . '). Абноўлена запісаў: ' . (string)$updated . '.';
        } catch (Throwable $e) {
            if (db()->inTransaction()) {
                db()->rollBack();
            }
            $error = 'Памылка пры аўтанумарацыі: ' . $e->getMessage();
        }
    }
    if ($isAjaxRequest) {
        ajaxResponse($error === null, $message ?? '', $error ?? '');
    }
}

if ($authReady && $isLoggedIn && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_songbook_clear_numbering'])) {
    panel_require_section_for_post('songbook', $isAjaxRequest);
    $idsRaw = $_POST['songbook_bulk_ids'] ?? [];
    if (!is_array($idsRaw)) {
        $idsRaw = [];
    }
    $ids = [];
    foreach ($idsRaw as $id) {
        $n = (int)$id;
        if ($n > 0) {
            $ids[$n] = true;
        }
    }
    $ids = array_keys($ids);
    if (count($ids) === 0) {
        $error = 'Абярыце хаця б адзін запіс.';
    } else {
        try {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = db()->prepare(
                'UPDATE songbook_entries
                 SET chapter_major = 0, subchapter = NULL
                 WHERE id IN (' . $placeholders . ')'
            );
            $stmt->execute($ids);
            $affected = $stmt->rowCount();
            $message = 'Нумарацыя ачышчана. Абноўлена запісаў: ' . (string)$affected . '.';
        } catch (Throwable $e) {
            $error = 'Памылка пры ачыстцы нумарацыі: ' . $e->getMessage();
        }
    }
    if ($isAjaxRequest) {
        ajaxResponse($error === null, $message ?? '', $error ?? '');
    }
}

if ($authReady && $isLoggedIn && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['scripture_import'])) {
    panel_require_section_for_post('scripture', $isAjaxRequest);
    $translationId = trim((string)($_POST['scripture_import_translation'] ?? ''));
    if ($translationId === '') {
        $error = 'Абярыце пераклад.';
    } elseif (!isset($_FILES['scripture_json_file']) || !is_array($_FILES['scripture_json_file'])) {
        $error = 'Файл не атрыманы.';
    } elseif ((int)($_FILES['scripture_json_file']['error'] ?? 0) !== UPLOAD_ERR_OK) {
        $error = 'Памылка загрузкі файла.';
    } else {
        try {
            $raw = (string)file_get_contents((string)$_FILES['scripture_json_file']['tmp_name']);
            $data = scriptureDecodeJsonFile($raw);
            if ($data === null) {
                $error = 'Некарэктны JSON.';
            } else {
                scriptureImportFromArray($translationId, $data);
                $message = 'Тэксты Бібліі імпартаваны ў БД.';
            }
        } catch (Throwable $e) {
            $error = 'Імпарт: ' . $e->getMessage();
        }
    }
}

if ($authReady && $isLoggedIn && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['scripture_save_chapter'])) {
    panel_require_section_for_post('scripture', $isAjaxRequest);
    $translationId = trim((string)($_POST['scripture_tr'] ?? ''));
    $bookId = (int)($_POST['scripture_book_id'] ?? 0);
    $chapter = (int)($_POST['scripture_chapter'] ?? 0);
    $verseTexts = $_POST['verse_text'] ?? [];
    if (!is_array($verseTexts)) {
        $verseTexts = [];
    }
    if ($translationId === '' || $bookId <= 0 || $chapter <= 0) {
        $error = 'Некарэктныя параметры главы.';
    } else {
        try {
            db()->beginTransaction();
            foreach ($verseTexts as $vn => $text) {
                scriptureUpdateVerseText(
                    $translationId,
                    $bookId,
                    $chapter,
                    (int)$vn,
                    (string)$text
                );
            }
            db()->commit();
            $message = 'Сціхі главы захаваны.';
        } catch (Throwable $e) {
            if (db()->inTransaction()) {
                db()->rollBack();
            }
            $error = 'Захаванне: ' . $e->getMessage();
        }
    }
}

if ($authReady && $isLoggedIn && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['move_category_id'], $_POST['move_direction'])) {
    panel_require_section_for_post('prayers', $isAjaxRequest);
    $categoryId = (int)$_POST['move_category_id'];
    $direction = (string)$_POST['move_direction'];
    if ($categoryId <= 0 || !in_array($direction, ['up', 'down'], true)) {
        $error = 'Некарэктныя параметры перамяшчэння.';
    } else {
        try {
            $moved = moveCategory($categoryId, $direction);
            $message = $moved ? 'Парадак катэгорый абноўлены.' : 'Катэгорыю нельга зрухнуць далей.';
        } catch (Throwable $e) {
            $error = 'Памылка пры перамяшчэнні катэгорыі: ' . $e->getMessage();
        }
    }
    if ($isAjaxRequest) {
        ajaxResponse($error === null, $message ?? '', $error ?? '');
    }
}

if ($authReady && $isLoggedIn && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reorder_category_parent_id'], $_POST['reorder_category_ids'])) {
    panel_require_section_for_post('prayers', $isAjaxRequest);
    $parentRaw = (string)$_POST['reorder_category_parent_id'];
    $parentId = $parentRaw === '' ? null : (int)$parentRaw;
    $idsRaw = trim((string)$_POST['reorder_category_ids']);
    $ids = array_values(array_filter(array_map('intval', explode(',', $idsRaw)), static fn(int $v): bool => $v > 0));

    if (count($ids) === 0) {
        $error = 'Некарэктны парадак катэгорый.';
    } else {
        try {
            reorderCategoriesByIds($parentId, $ids);
            $message = 'Парадак катэгорый абноўлены.';
        } catch (Throwable $e) {
            $error = 'Памылка пры сартаванні катэгорый: ' . $e->getMessage();
        }
    }
    if ($isAjaxRequest) {
        ajaxResponse($error === null, $message ?? '', $error ?? '');
    }
}

$scriptureTranslations = [];
$scriptureBooks = [];
$scriptureChapters = [];
$scriptureChapterVerses = [];
$scriptureEditTr = '';
$scriptureEditBookId = 0;
$scriptureEditChapter = 0;

if ($authReady && $isLoggedIn) {
    try {
        $scriptureTranslations = scriptureListTranslations();
        if (in_array($view, ['scripture', 'scripture-import', 'scripture-chapter'], true)) {
            if ($view === 'scripture-chapter') {
                $scriptureEditTr = (string)($_GET['tr'] ?? '');
                $scriptureEditBookId = (int)($_GET['book_id'] ?? 0);
                $scriptureEditChapter = (int)($_GET['chapter'] ?? 0);
                if ($scriptureEditTr !== '') {
                    $scriptureBooks = scriptureListBooks($scriptureEditTr);
                    if ($scriptureEditBookId <= 0 && count($scriptureBooks) > 0) {
                        $scriptureEditBookId = (int)$scriptureBooks[0]['book_id'];
                    }
                    $bookIds = array_map(static fn(array $b): int => (int)$b['book_id'], $scriptureBooks);
                    if ($scriptureEditBookId > 0 && count($bookIds) > 0 && !in_array($scriptureEditBookId, $bookIds, true)) {
                        $scriptureEditBookId = (int)$scriptureBooks[0]['book_id'];
                    }
                    if ($scriptureEditBookId > 0) {
                        $scriptureChapters = scriptureListChapters($scriptureEditTr, $scriptureEditBookId);
                    }
                    if ($scriptureEditChapter <= 0 && count($scriptureChapters) > 0) {
                        $scriptureEditChapter = (int)$scriptureChapters[0];
                    }
                    if ($scriptureEditChapter > 0 && count($scriptureChapters) > 0 && !in_array($scriptureEditChapter, $scriptureChapters, true)) {
                        $scriptureEditChapter = (int)$scriptureChapters[0];
                    }
                    if ($scriptureEditTr !== '' && $scriptureEditBookId > 0 && $scriptureEditChapter > 0) {
                        $scriptureChapterVerses = scriptureGetChapterVerses(
                            $scriptureEditTr,
                            $scriptureEditBookId,
                            $scriptureEditChapter
                        );
                    }
                }
            }
        }
    } catch (Throwable $e) {
        if ($error === null) {
            $error = 'Біблія: ' . $e->getMessage();
        }
    }
}

$categories = [];
$topLevelCategories = [];
$subcategoryOptions = [];
$categoryOptions = [];
$byId = [];
$categoryTree = [];
if ($authReady && $isLoggedIn) {
    try {
        $categories = getCategories();
        foreach ($categories as $category) {
            $id = (int)$category['id'];
            $byId[$id] = $category;
            if ($category['parent_id'] === null) {
                $topLevelCategories[] = $category;
            } else {
                $parentId = (int)$category['parent_id'];
                if (!isset($subcategoryOptions[$parentId])) {
                    $subcategoryOptions[$parentId] = [];
                }
                $subcategoryOptions[$parentId][] = $category;
            }
        }
        foreach ($categories as $category) {
            $categoryOptions[(int)$category['id']] = categoryPathLabel($category);
        }
        $categoryTree = buildCategoryTree($categories);
    } catch (Throwable $e) {
        if ($error === null) {
            $error = 'Памылка чытання катэгорый: ' . $e->getMessage();
        }
    }
}

$editCategory = null;
$editCategoryId = isset($_GET['edit_category_id']) ? (int)$_GET['edit_category_id'] : 0;
if ($isLoggedIn && !panel_can_access_section('prayers')) {
    $editCategoryId = 0;
}
if ($editCategoryId > 0) {
    foreach ($categories as $category) {
        if ((int)$category['id'] === $editCategoryId) {
            $editCategory = $category;
            break;
        }
    }
}

$editPrayer = null;
$editPrayerId = isset($_GET['edit_prayer_id']) ? (int)$_GET['edit_prayer_id'] : 0;
if ($isLoggedIn && !panel_can_access_section('prayers')) {
    $editPrayerId = 0;
}
$editPrayerParentCategoryId = null;
$editPrayerSubcategoryId = null;
$editPrayerAdditionalCategoryIds = [];
if ($authReady && $isLoggedIn && $editPrayerId > 0) {
    try {
        $editPrayer = getPrayerForEdit($editPrayerId);
        $editPrayerAdditionalCategoryIds = getPrayerCategoryIds($editPrayerId);
        if ($editPrayer !== null && !empty($editPrayer['category_id'])) {
            $stmtCat = db()->prepare(
                'SELECT id, parent_id
                 FROM prayer_categories
                 WHERE id = :id
                 LIMIT 1'
            );
            $stmtCat->execute([':id' => (int)$editPrayer['category_id']]);
            $catRow = $stmtCat->fetch();
            if (is_array($catRow)) {
                if ($catRow['parent_id'] === null) {
                    $editPrayerParentCategoryId = (int)$catRow['id'];
                } else {
                    $editPrayerParentCategoryId = (int)$catRow['parent_id'];
                    $editPrayerSubcategoryId = (int)$catRow['id'];
                }
                $editPrayerAdditionalCategoryIds = array_values(array_filter(
                    $editPrayerAdditionalCategoryIds,
                    static fn(int $categoryId): bool => $categoryId !== (int)$catRow['id']
                ));
            }
        }
    } catch (Throwable $e) {
        if ($error === null) {
            $error = 'Памылка чытання малітвы для рэдагавання: ' . $e->getMessage();
        }
    }
}

$songbookRows = [];
$songbookCategoryDistinct = [];
$songbookCatEmptyToken = '__songbook_cat_none__';
$songbookCatSelected = [];
$sbCatRaw = $_GET['sb_cat'] ?? null;
if ($sbCatRaw === null) {
    $songbookCatSelected = [];
} elseif (is_string($sbCatRaw)) {
    $s = trim($sbCatRaw);
    if ($s === '' || $s === '*') {
        $songbookCatSelected = [];
    } else {
        $songbookCatSelected = [$s];
    }
} elseif (is_array($sbCatRaw)) {
    $sbTmp = [];
    foreach ($sbCatRaw as $v) {
        $s = trim((string)$v);
        if ($s === '' || $s === '*') {
            continue;
        }
        $sbTmp[$s] = true;
    }
    $songbookCatSelected = array_keys($sbTmp);
} else {
    $songbookCatSelected = [];
}
$songbookListQuery = ['view' => 'songbook'];
if (count($songbookCatSelected) > 0) {
    $songbookListQuery['sb_cat'] = array_values($songbookCatSelected);
}
$songbookListHref = '/?' . http_build_query($songbookListQuery);
$songbookAddEntryQuery = ['view' => 'add-songbook'];
if (count($songbookCatSelected) > 0) {
    $songbookAddEntryQuery['sb_cat'] = array_values($songbookCatSelected);
}
$songbookAddEntryHref = '/?' . http_build_query($songbookAddEntryQuery);
$songbookFilterOpen = count($songbookCatSelected) > 0;
$editSongbook = null;
$editSongbookId = isset($_GET['edit_songbook_id']) ? (int)$_GET['edit_songbook_id'] : 0;
if ($isLoggedIn && !panel_can_access_section('songbook')) {
    $editSongbookId = 0;
}
if ($authReady && $isLoggedIn) {
    try {
        if ($view === 'songbook') {
            $sbOrderSql = ' ORDER BY category ASC, chapter_major ASC, COALESCE(subchapter, 0) ASC, sort_order ASC, id ASC';
            $sbSelectSql = 'SELECT id, title, category, chapter_major, subchapter, content_type, media_path, sort_order, updated_at FROM songbook_entries';
            $sbNamedCats = [];
            $sbIncludeEmptyCat = false;
            foreach ($songbookCatSelected as $selCat) {
                if ($selCat === $songbookCatEmptyToken) {
                    $sbIncludeEmptyCat = true;
                } else {
                    $sbNamedCats[] = $selCat;
                }
            }
            $sbNamedCats = array_values(array_unique($sbNamedCats));
            if (!$sbIncludeEmptyCat && count($sbNamedCats) === 0) {
                $sbStmt = db()->query($sbSelectSql . $sbOrderSql);
            } else {
                $sbConds = [];
                $sbExec = [];
                if (count($sbNamedCats) > 0) {
                    $sbPh = implode(',', array_fill(0, count($sbNamedCats), '?'));
                    $sbConds[] = 'category IN (' . $sbPh . ')';
                    foreach ($sbNamedCats as $nc) {
                        $sbExec[] = $nc;
                    }
                }
                if ($sbIncludeEmptyCat) {
                    $sbConds[] = 'TRIM(COALESCE(category, \'\')) = \'\'';
                }
                $sbWhere = ' WHERE (' . implode(' OR ', $sbConds) . ')';
                $sbStmt = db()->prepare($sbSelectSql . $sbWhere . $sbOrderSql);
                $sbStmt->execute($sbExec);
            }
            $tmpSb = $sbStmt->fetchAll();
            $songbookRows = is_array($tmpSb) ? $tmpSb : [];

            $dStmt = db()->query('SELECT DISTINCT category FROM songbook_entries ORDER BY category ASC');
            $dRaw = $dStmt->fetchAll(PDO::FETCH_COLUMN);
            if (is_array($dRaw)) {
                $songbookCategoryDistinct = array_values(array_map(static fn($v): string => (string)$v, $dRaw));
            }
        }
        if ($editSongbookId > 0) {
            $sbOne = db()->prepare('SELECT * FROM songbook_entries WHERE id = :id LIMIT 1');
            $sbOne->execute([':id' => $editSongbookId]);
            $sbFetched = $sbOne->fetch();
            $editSongbook = is_array($sbFetched) ? $sbFetched : null;
        }
    } catch (Throwable $e) {
        if ($error === null) {
            $error = 'Памылка чытання спеўніка: ' . $e->getMessage();
        }
    }
}

$rows = [];
$prayersTotalFiltered = 0;
$prayerListLanguages = [];
$prLimit = (int)($_GET['pr_limit'] ?? 100);
if (!in_array($prLimit, [25, 50, 100, 200, 500], true)) {
    $prLimit = 100;
}
$prSort = (string)($_GET['pr_sort'] ?? 'sort_order_asc');
$prayerSortSql = [
    'sort_order_asc' => 'MAX(p.sort_order) ASC, p.id ASC',
    'sort_order_desc' => 'MAX(p.sort_order) DESC, p.id DESC',
    'id_desc' => 'p.id DESC',
    'id_asc' => 'p.id ASC',
    'updated_desc' => 'MAX(p.updated_at) DESC',
    'updated_asc' => 'MAX(p.updated_at) ASC',
    'title_asc' => 'MAX(p.title) ASC',
    'title_desc' => 'MAX(p.title) DESC',
    'category_asc' => 'MAX(COALESCE(parent.name, c.name, p.category)) ASC',
    'category_desc' => 'MAX(COALESCE(parent.name, c.name, p.category)) DESC',
    'subcategory_asc' => 'MAX(CASE WHEN c.parent_id IS NULL THEN NULL ELSE c.name END) ASC',
    'subcategory_desc' => 'MAX(CASE WHEN c.parent_id IS NULL THEN NULL ELSE c.name END) DESC',
    'language_asc' => 'MAX(p.language) ASC',
    'language_desc' => 'MAX(p.language) DESC',
];
$orderBy = $prayerSortSql[$prSort] ?? 'MAX(p.sort_order) ASC, p.id ASC';

$prCat = (string)($_GET['pr_cat'] ?? '');
$prParent = (int)($_GET['pr_parent'] ?? 0);
$prLevel = (string)($_GET['pr_level'] ?? '');
$prLang = trim((string)($_GET['pr_lang'] ?? ''));
$prQ = trim((string)($_GET['pr_q'] ?? ''));
$prAdditional = (string)($_GET['pr_additional'] ?? '');
$prActive = (string)($_GET['pr_active'] ?? '');
$prayersFilterOpen = (
    $prQ !== '' || $prCat !== '' || $prParent !== 0 || $prLevel !== '' || $prLang !== ''
    || $prAdditional !== '' || $prActive !== '' || $prSort !== 'sort_order_asc' || $prLimit !== 100
);

if ($authReady && $isLoggedIn) {
    try {
        $langStmt = db()->query(
            "SELECT DISTINCT TRIM(language) AS lang
             FROM prayers
             WHERE language IS NOT NULL AND TRIM(language) <> ''
             ORDER BY lang ASC"
        );
        foreach ($langStmt->fetchAll() as $lr) {
            if (is_array($lr) && ($lr['lang'] ?? '') !== '') {
                $prayerListLanguages[] = (string)$lr['lang'];
            }
        }

        $where = ['1=1'];
        $params = [];

        if ($prCat === 'none') {
            $where[] = 'p.category_id IS NULL';
        } elseif ($prCat !== '' && ctype_digit($prCat)) {
            $where[] = 'p.category_id = :pr_cat';
            $params[':pr_cat'] = (int)$prCat;
        }

        if ($prParent > 0) {
            $where[] = '(p.category_id IS NOT NULL AND EXISTS (
                SELECT 1 FROM prayer_categories cx
                WHERE cx.id = p.category_id
                  AND (cx.parent_id = :pr_parent OR (cx.parent_id IS NULL AND cx.id = :pr_parent_root))
            ))';
            $params[':pr_parent'] = $prParent;
            $params[':pr_parent_root'] = $prParent;
        }

        if ($prLevel === 'root') {
            $where[] = '(p.category_id IS NOT NULL AND EXISTS (
                SELECT 1 FROM prayer_categories cx WHERE cx.id = p.category_id AND cx.parent_id IS NULL
            ))';
        } elseif ($prLevel === 'sub') {
            $where[] = '(p.category_id IS NOT NULL AND EXISTS (
                SELECT 1 FROM prayer_categories cx WHERE cx.id = p.category_id AND cx.parent_id IS NOT NULL
            ))';
        }

        if ($prLang !== '') {
            $where[] = 'LOWER(TRIM(COALESCE(p.language, \'\'))) = LOWER(:pr_lang)';
            $params[':pr_lang'] = $prLang;
        }

        if ($prQ !== '') {
            $where[] = 'p.title LIKE :pr_q';
            $params[':pr_q'] = '%' . addcslashes($prQ, '%_\\') . '%';
        }

        if ($prAdditional === 'yes') {
            $where[] = 'EXISTS (SELECT 1 FROM prayer_category_links pca WHERE pca.prayer_id = p.id AND pca.is_primary = 0)';
        } elseif ($prAdditional === 'no') {
            $where[] = 'NOT EXISTS (SELECT 1 FROM prayer_category_links pca WHERE pca.prayer_id = p.id AND pca.is_primary = 0)';
        }

        if ($prActive === '1') {
            $where[] = 'p.is_active = 1';
        } elseif ($prActive === '0') {
            $where[] = 'p.is_active = 0';
        }

        $fromSql = 'FROM prayers p
             LEFT JOIN prayer_categories c ON p.category_id = c.id
             LEFT JOIN prayer_categories parent ON c.parent_id = parent.id
             LEFT JOIN prayer_category_links pcl ON p.id = pcl.prayer_id
             LEFT JOIN prayer_categories pc ON pcl.category_id = pc.id';
        $whereSql = implode(' AND ', $where);

        $countStmt = db()->prepare("SELECT COUNT(DISTINCT p.id) AS cnt {$fromSql} WHERE {$whereSql}");
        foreach ($params as $pk => $pv) {
            $countStmt->bindValue($pk, $pv);
        }
        $countStmt->execute();
        $cntRow = $countStmt->fetch();
        $prayersTotalFiltered = is_array($cntRow) ? (int)($cntRow['cnt'] ?? 0) : 0;

        $selectSql = "SELECT
                p.id,
                MAX(p.title) AS title,
                MAX(p.text) AS text,
                MAX(p.category_id) AS category_id,
                MAX(COALESCE(parent.name, c.name, p.category)) AS category,
                MAX(CASE WHEN c.parent_id IS NULL THEN NULL ELSE c.name END) AS subcategory,
                GROUP_CONCAT(CASE WHEN pcl.is_primary = 0 THEN pc.name END ORDER BY pc.name SEPARATOR ', ') AS additional_categories,
                MAX(p.language) AS language,
                MAX(p.sort_order) AS sort_order,
                MAX(p.updated_at) AS updated_at
             {$fromSql}
             WHERE {$whereSql}
             GROUP BY p.id
             ORDER BY {$orderBy}
             LIMIT " . $prLimit;

        $stmt = db()->prepare($selectSql);
        foreach ($params as $pk => $pv) {
            $stmt->bindValue($pk, $pv);
        }
        $stmt->execute();
        $rows = $stmt->fetchAll();
    } catch (Throwable $e) {
        if ($error === null) {
            $error = 'Памылка чытання спіса малітваў: ' . $e->getMessage();
        }
    }
}
?>
<!doctype html>
<html lang="be">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="<?= htmlspecialchars(panel_csrf_token_value(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
  <link rel="icon" href="/favicon.png" type="image/png">
  <link rel="apple-touch-icon" href="/favicon.png">
  <title>Totus Tuus</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,600;1,500&family=DM+Sans:ital,opsz,wght@0,9..40,400;0,9..40,500;0,9..40,600;0,9..40,700;1,9..40,400&display=swap" rel="stylesheet">
  <style>
    :root {
      --bg-deep: #0a0c14;
      --bg-mid: #12182a;
      --bg-glow: #1a2240;
      --card: rgba(22, 28, 46, 0.72);
      --card-solid: #161c2e;
      --text: #e8ecf4;
      --muted: #94a3b8;
      --line: rgba(148, 163, 184, 0.18);
      --accent: #7c6cf0;
      --accent-2: #c4a35a;
      --accent-glow: rgba(124, 108, 240, 0.35);
      --danger: #f87171;
      --success: #4ade80;
      --radius: 14px;
      --radius-sm: 10px;
    }
    * { box-sizing: border-box; }
    html {
      color-scheme: dark;
      scrollbar-gutter: stable;
    }
    body {
      font-family: "DM Sans", system-ui, sans-serif;
      max-width: 1120px;
      margin: 0 auto;
      padding: 28px 16px 48px;
      color: var(--text);
      min-height: 100vh;
      background:
        radial-gradient(ellipse 120% 80% at 100% -20%, rgba(124, 108, 240, 0.22), transparent 50%),
        radial-gradient(ellipse 90% 60% at -10% 50%, rgba(196, 163, 90, 0.08), transparent 45%),
        linear-gradient(165deg, var(--bg-deep) 0%, var(--bg-mid) 42%, var(--bg-glow) 100%);
      background-attachment: fixed;
    }
    h1 {
      margin: 0;
      font-family: "Cormorant Garamond", "Times New Roman", serif;
      font-size: clamp(2rem, 4vw, 2.75rem);
      font-weight: 600;
      letter-spacing: 0.02em;
      line-height: 1.1;
      background: linear-gradient(120deg, #f1f5f9 0%, #e2d5b8 45%, #c7d2fe 100%);
      -webkit-background-clip: text;
      background-clip: text;
      color: transparent;
    }
    h2 { margin: 0 0 12px; font-size: 1.25rem; font-weight: 600; color: var(--text); }
    h3 { margin: 0 0 10px; font-size: 1rem; font-weight: 600; color: var(--text); }
    p { color: var(--muted); line-height: 1.55; }
    p code, .inline-code {
      font-size: 0.9em;
      padding: 2px 7px;
      border-radius: 6px;
      background: rgba(124, 108, 240, 0.15);
      border: 1px solid var(--line);
      color: #ddd6fe;
    }
    .header {
      position: relative;
      overflow: hidden;
      border-radius: calc(var(--radius) + 4px);
      padding: 22px 24px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 20px;
      border: 1px solid var(--line);
      background:
        linear-gradient(135deg, rgba(30, 27, 75, 0.95) 0%, rgba(15, 23, 42, 0.92) 50%, rgba(30, 41, 59, 0.88) 100%);
      box-shadow:
        0 4px 24px rgba(0, 0, 0, 0.35),
        0 0 0 1px rgba(255, 255, 255, 0.04) inset,
        0 1px 0 rgba(255, 255, 255, 0.06) inset;
    }
    .header::before {
      content: "";
      position: absolute;
      inset: 0;
      background: linear-gradient(105deg, transparent 40%, rgba(196, 163, 90, 0.06) 70%, rgba(124, 108, 240, 0.12) 100%);
      pointer-events: none;
    }
    .header > * { position: relative; z-index: 1; }
    .header-brand {
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 4px;
      text-align: center;
    }
    .header-brand h1 {
      text-align: center;
    }
    .header-tagline {
      margin: 0;
      max-width: 22rem;
      font-size: calc(0.8125rem * 0.7);
      font-weight: 500;
      color: var(--muted);
      letter-spacing: 0.04em;
      text-transform: uppercase;
      line-height: 1.4;
      text-align: center;
    }
    a.btn-pill,
    button.btn-pill {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      color: var(--text);
      text-decoration: none;
      font-weight: 600;
      font-size: 0.875rem;
      padding: 8px 12px;
      border-radius: var(--radius-sm);
      background: rgba(255, 255, 255, 0.06);
      border: 1px solid rgba(255, 255, 255, 0.08);
      transition: background 0.15s ease, border-color 0.15s ease, filter 0.15s ease;
      box-sizing: border-box;
      line-height: 1.2;
    }
    button.btn-pill {
      margin-top: 0;
      font-family: inherit;
      cursor: pointer;
      box-shadow: none;
    }
    a.btn-pill:hover,
    button.btn-pill:hover {
      background: rgba(255, 255, 255, 0.1);
      border-color: rgba(255, 255, 255, 0.14);
      filter: none;
    }
    a.btn-pill.active,
    button.btn-pill.active {
      background: linear-gradient(135deg, rgba(124, 108, 240, 0.35), rgba(196, 163, 90, 0.18));
      border-color: rgba(196, 163, 90, 0.35);
      color: #fff;
    }
    .btn-pill--sm {
      padding: 6px 10px;
      font-size: 0.8125rem;
    }
    .btn-pill--purple {
      background: rgba(124, 108, 240, 0.22);
      color: #e0e7ff;
      border-color: rgba(124, 108, 240, 0.3);
    }
    .btn-pill--purple:hover {
      background: rgba(124, 108, 240, 0.32);
      border-color: rgba(124, 108, 240, 0.45);
      color: #fff;
    }
    .btn-pill--gold {
      background: rgba(196, 163, 90, 0.12);
      color: #e8d5a3;
      border-color: rgba(196, 163, 90, 0.28);
    }
    .btn-pill--gold:hover {
      background: rgba(196, 163, 90, 0.2);
      border-color: rgba(196, 163, 90, 0.4);
      color: #f5ecd4;
    }
    .btn-pill--muted {
      background: rgba(15, 23, 42, 0.55);
      color: #94a3b8;
      border-color: rgba(148, 163, 184, 0.2);
    }
    .btn-pill--muted:hover {
      background: rgba(30, 41, 59, 0.75);
      border-color: rgba(148, 163, 184, 0.35);
      color: #cbd5e1;
    }
    .btn-pill--danger {
      background: rgba(248, 113, 113, 0.12);
      color: #fca5a5;
      border-color: rgba(248, 113, 113, 0.28);
    }
    .btn-pill--danger:hover {
      background: rgba(248, 113, 113, 0.2);
      border-color: rgba(248, 113, 113, 0.45);
      color: #fecaca;
    }
    .form-actions-row {
      display: flex;
      flex-wrap: wrap;
      align-items: center;
      gap: 10px 12px;
      margin-top: 14px;
    }
    .form-actions-row button { margin-top: 0; }
    .toolbar-actions { margin: 12px 0 0; display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
    .songbook-intro { margin-bottom: 4px; max-width: 68ch; }
    .songbook-toolbar-top {
      margin: 12px 0 0;
      display: flex;
      flex-wrap: wrap;
      align-items: center;
      gap: 10px;
    }
    .songbook-admin-panel {
      margin-top: 14px;
      padding: 20px 22px;
      border-radius: var(--radius);
      border: 1px solid var(--line);
      background:
        linear-gradient(145deg, rgba(22, 28, 46, 0.72) 0%, rgba(15, 23, 42, 0.55) 100%);
      box-shadow: 0 10px 36px rgba(0, 0, 0, 0.22);
      display: flex;
      flex-direction: column;
      gap: 18px;
    }
    .songbook-panel-section { margin: 0; padding: 0; border: none; }
    .songbook-panel-section__head {
      display: flex;
      flex-wrap: wrap;
      align-items: baseline;
      justify-content: space-between;
      gap: 8px 14px;
      margin-bottom: 6px;
    }
    .songbook-panel-section__title {
      font-size: 0.75rem;
      font-weight: 700;
      letter-spacing: 0.08em;
      text-transform: uppercase;
      color: #94a3b8;
    }
    .songbook-panel-section__meta {
      font-size: 0.8rem;
      color: #a5b4fc;
      font-weight: 600;
    }
    .songbook-panel-section__hint {
      margin: 0 0 12px;
      font-size: 0.875rem;
      line-height: 1.5;
      color: var(--muted);
      max-width: 58rem;
    }
    .songbook-panel-section__hint strong { color: #c4b5fd; font-weight: 600; }
    .songbook-panel-section__empty {
      margin: 0 0 12px;
      font-size: 0.875rem;
      color: var(--muted);
    }
    .songbook-panel-divider {
      height: 1px;
      margin: 0;
      background: linear-gradient(90deg, transparent 0%, rgba(148, 163, 184, 0.22) 20%, rgba(124, 108, 240, 0.2) 50%, rgba(148, 163, 184, 0.22) 80%, transparent 100%);
    }
    .songbook-filter-chips {
      display: flex;
      flex-wrap: wrap;
      gap: 8px 10px;
      margin-bottom: 14px;
    }
    .songbook-filter-chip {
      display: inline-flex;
      align-items: center;
      gap: 9px;
      padding: 8px 14px 8px 11px;
      border-radius: 999px;
      border: 1px solid rgba(148, 163, 184, 0.22);
      background: rgba(10, 12, 20, 0.5);
      cursor: pointer;
      user-select: none;
      font-size: 0.875rem;
      color: #e2e8f0;
      transition: border-color 0.15s ease, background 0.15s ease, box-shadow 0.15s ease;
    }
    .songbook-filter-chip:hover {
      border-color: rgba(124, 108, 240, 0.35);
      background: rgba(124, 108, 240, 0.08);
    }
    .songbook-filter-chip:has(input:checked) {
      border-color: rgba(124, 108, 240, 0.55);
      background: rgba(124, 108, 240, 0.2);
      box-shadow: 0 0 0 1px rgba(124, 108, 240, 0.12);
    }
    .songbook-filter-chip input[type="checkbox"] {
      width: 1rem;
      height: 1rem;
      margin: 0;
      flex-shrink: 0;
      accent-color: #7c6cf0;
      cursor: pointer;
    }
    .songbook-filter-chip span { line-height: 1.25; }
    .songbook-panel-actions {
      display: flex;
      flex-wrap: wrap;
      align-items: center;
      gap: 10px 12px;
    }
    .songbook-panel-actions .btn-pill { margin-top: 0; }
    .panel-filter-details { margin: 0; padding: 0; border: none; }
    .panel-filter-summary {
      display: flex;
      flex-wrap: wrap;
      align-items: center;
      justify-content: space-between;
      gap: 8px 14px;
      padding: 11px 14px;
      border-radius: var(--radius-sm);
      border: 1px solid rgba(148, 163, 184, 0.22);
      background: rgba(10, 12, 20, 0.4);
      cursor: pointer;
      list-style: none;
      user-select: none;
      transition: border-color 0.15s ease, background 0.15s ease;
    }
    .panel-filter-summary:hover {
      border-color: rgba(124, 108, 240, 0.35);
      background: rgba(124, 108, 240, 0.08);
    }
    .panel-filter-summary::-webkit-details-marker { display: none; }
    .panel-filter-summary::marker { content: ''; }
    .panel-filter-summary__title {
      font-size: 0.75rem;
      font-weight: 700;
      letter-spacing: 0.08em;
      text-transform: uppercase;
      color: #94a3b8;
    }
    .panel-filter-summary__hint {
      width: 100%;
      flex-basis: 100%;
      margin: 4px 0 0;
      font-size: 0.78rem;
      font-weight: 400;
      text-transform: none;
      letter-spacing: 0.02em;
      color: var(--muted);
      line-height: 1.35;
    }
    .panel-filter-summary__meta {
      font-size: 0.8rem;
      color: #a5b4fc;
      font-weight: 600;
    }
    .panel-filter-details__body {
      margin-top: 14px;
      padding-top: 2px;
    }
    .prayers-filter-form .panel-filter-details {
      margin-bottom: 10px;
    }
    .songbook-bulk-row {
      display: flex;
      flex-wrap: wrap;
      align-items: center;
      gap: 10px 14px;
    }
    .songbook-bulk-form .bulk-songbook-label {
      display: inline;
      margin: 0;
      font-weight: 600;
      font-size: 0.875rem;
      color: #cbd5e1;
      white-space: nowrap;
    }
    .songbook-bulk-form .bulk-songbook-input {
      width: auto;
      flex: 1 1 220px;
      min-width: 200px;
      max-width: 400px;
      margin: 0;
    }
    .songbook-bulk-form .btn-pill { margin-top: 0; }
    .songbook-admin-panel + table { margin-top: 22px; }
    table th.cell-checkbox, table td.cell-checkbox {
      width: 2.5rem;
      text-align: center;
      vertical-align: middle;
    }
    table th.cell-checkbox input, table td.cell-checkbox input[type="checkbox"] {
      width: auto;
      margin: 0;
    }
    .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-top: 14px; }
    .card {
      background: var(--card);
      backdrop-filter: blur(12px);
      -webkit-backdrop-filter: blur(12px);
      border: 1px solid var(--line);
      border-radius: var(--radius);
      padding: 20px;
      margin-top: 16px;
      box-shadow: 0 12px 40px rgba(0, 0, 0, 0.25);
    }
    .card p { margin: 8px 0 0; }
    label { display: block; margin: 14px 0 7px; font-weight: 600; font-size: 0.875rem; color: #cbd5e1; }
    input, textarea, select {
      width: 100%;
      padding: 11px 12px;
      border: 1px solid var(--line);
      border-radius: var(--radius-sm);
      font: inherit;
      color: var(--text);
      background: rgba(10, 12, 20, 0.55);
      transition: border-color 0.15s ease, box-shadow 0.15s ease;
    }
    input::placeholder, textarea::placeholder { color: var(--muted); opacity: 0.8; }
    input:focus, textarea:focus, select:focus {
      outline: none;
      border-color: rgba(124, 108, 240, 0.55);
      box-shadow: 0 0 0 3px var(--accent-glow);
    }
    textarea { min-height: 170px; resize: vertical; }
    textarea.scripture-verse-field {
      min-height: 3.25rem;
      max-height: 14rem;
      padding: 8px 11px;
      font-size: 0.9rem;
      line-height: 1.45;
    }
    .scripture-chapter-nav { margin-bottom: 4px; }
    .scripture-nav-row {
      display: flex;
      flex-wrap: wrap;
      gap: 14px 18px;
      align-items: flex-end;
      padding: 14px 16px;
      background: rgba(15, 23, 42, 0.45);
      border: 1px solid var(--line);
      border-radius: var(--radius-sm);
    }
    .scripture-nav-field { flex: 1 1 200px; min-width: 0; }
    .scripture-nav-field label {
      display: block;
      margin: 0 0 6px;
      font-size: 0.8rem;
      letter-spacing: 0.02em;
      color: #94a3b8;
    }
    .scripture-nav-field--chapter { flex: 0 0 108px; max-width: 120px; }
    .scripture-nav-field--chapter select { min-width: 0; }
    .scripture-form-actions {
      display: flex;
      flex-wrap: wrap;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
      margin-top: 18px;
    }
    .scripture-form-actions button { margin-top: 0; }
    a.scripture-back-btn {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      padding: 11px 18px;
      border-radius: var(--radius-sm);
      font-weight: 600;
      font-size: 0.9rem;
      text-decoration: none;
      color: #e2e8f0;
      background: rgba(30, 41, 59, 0.75);
      border: 1px solid rgba(148, 163, 184, 0.28);
      box-shadow: 0 4px 14px rgba(0, 0, 0, 0.2);
      transition: background 0.15s ease, border-color 0.15s ease, transform 0.1s ease;
    }
    a.scripture-back-btn:hover {
      background: rgba(51, 65, 85, 0.9);
      border-color: rgba(124, 108, 240, 0.45);
      color: #fff;
    }
    a.scripture-back-btn:active { transform: translateY(1px); }
    a.scripture-back-btn .scripture-back-icon {
      font-size: 1.1rem;
      line-height: 1;
      opacity: 0.9;
    }
    .scripture-bible-toolbar {
      margin: 16px 0 20px;
    }
    a.btn-scripture {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      padding: 11px 20px;
      border-radius: var(--radius-sm);
      font-weight: 600;
      font-size: 0.9rem;
      text-decoration: none;
      border: 1px solid transparent;
      transition: filter 0.15s ease, transform 0.1s ease, border-color 0.15s ease, background 0.15s ease;
    }
    a.btn-scripture:active { transform: translateY(1px); }
    a.btn-scripture-primary {
      color: #fff;
      background: linear-gradient(135deg, #6d5dfc 0%, #8b7cf5 50%, #a78bfa 100%);
      box-shadow: 0 8px 24px rgba(109, 93, 252, 0.35);
    }
    a.btn-scripture-primary:hover {
      filter: brightness(1.08);
      color: #fff;
    }
    a.btn-scripture-secondary {
      color: #e2e8f0;
      background: rgba(30, 41, 59, 0.75);
      border-color: rgba(148, 163, 184, 0.28);
      box-shadow: 0 4px 14px rgba(0, 0, 0, 0.18);
    }
    a.btn-scripture-secondary:hover {
      background: rgba(51, 65, 85, 0.92);
      border-color: rgba(124, 108, 240, 0.45);
      color: #fff;
    }
    ul.scripture-translation-list {
      list-style: none;
      margin: 0;
      padding: 0;
    }
    li.scripture-translation-item {
      display: flex;
      flex-wrap: wrap;
      align-items: center;
      justify-content: space-between;
      gap: 12px 16px;
      padding: 14px 16px;
      margin-top: 10px;
      background: rgba(15, 23, 42, 0.4);
      border: 1px solid var(--line);
      border-radius: var(--radius-sm);
    }
    li.scripture-translation-item:first-of-type { margin-top: 0; }
    .scripture-translation-item .scripture-translation-title {
      display: flex;
      flex-wrap: wrap;
      align-items: center;
      gap: 10px;
      min-width: 0;
    }
    .scripture-translation-item .scripture-translation-title strong {
      font-size: 1rem;
    }
    select[multiple] { min-height: 140px; }
    /* Адзіночныя select: адступ справа і ўласная стрэлка замест сістэмнай ля краю */
    select:not([multiple]) {
      appearance: none;
      -webkit-appearance: none;
      -moz-appearance: none;
      padding: 11px 42px 11px 12px;
      background-color: rgba(10, 12, 20, 0.55);
      background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='14' viewBox='0 0 24 24'%3E%3Cpath fill='%2394a3b8' d='M7 10l5 5 5-5H7z'/%3E%3C/svg%3E");
      background-repeat: no-repeat;
      background-position: right 14px center;
      background-size: 14px 14px;
      cursor: pointer;
    }
    select:not([multiple]):focus {
      background-color: rgba(10, 12, 20, 0.65);
      background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='14' viewBox='0 0 24 24'%3E%3Cpath fill='%23cbd5e1' d='M7 10l5 5 5-5H7z'/%3E%3C/svg%3E");
    }
    option { background: var(--card-solid); color: var(--text); }
    .rich-editor-wrap {
      border: 1px solid var(--line);
      border-radius: var(--radius-sm);
      overflow: hidden;
      background: rgba(10, 12, 20, 0.45);
    }
    .rich-toolbar {
      display: flex;
      flex-wrap: wrap;
      align-items: center;
      gap: 10px;
      padding: 10px;
      border-bottom: 1px solid var(--line);
      background: rgba(15, 23, 42, 0.65);
    }
    .rich-toolbar-group {
      display: inline-flex;
      flex-wrap: wrap;
      align-items: center;
      gap: 6px;
      padding-right: 12px;
      margin-right: 2px;
      border-right: 1px solid var(--line);
    }
    .rich-toolbar-group:last-child {
      border-right: none;
      margin-right: 0;
      padding-right: 0;
    }
    .rich-toolbar-label {
      font-size: 10px;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.08em;
      color: var(--muted);
      margin-right: 2px;
    }
    .rich-btn {
      margin-top: 0;
      padding: 6px 11px;
      border-radius: 8px;
      font-size: 13px;
      background: rgba(124, 108, 240, 0.2);
      color: #e0e7ff;
      border: 1px solid rgba(124, 108, 240, 0.25);
    }
    .rich-btn:hover { background: rgba(124, 108, 240, 0.32); }
    .rich-btn.active {
      background: linear-gradient(135deg, var(--accent), #5b4fc9);
      color: #fff;
      border-color: transparent;
    }
    .rich-btn-icon {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-width: 38px;
      min-height: 38px;
      padding: 7px;
    }
    .rich-btn-icon svg {
      display: block;
      flex-shrink: 0;
    }
    .rich-color-picker-wrap {
      position: relative;
      display: inline-flex;
      align-items: center;
    }
    .rich-color-toggle {
      width: 34px;
      height: 34px;
      min-width: 34px;
      min-height: 34px;
      padding: 0;
      margin-top: 0;
      border-radius: 8px;
      border: 2px solid rgba(148, 163, 184, 0.45);
      background: #ffffff;
      cursor: pointer;
      box-shadow: none;
    }
    .rich-color-picker-wrap.open .rich-color-toggle {
      border-color: #ffffff;
      box-shadow: 0 0 0 2px rgba(124, 108, 240, 0.55);
    }
    .rich-color-dropdown {
      position: absolute;
      top: calc(100% + 8px);
      left: 0;
      z-index: 25;
      display: none;
      width: 168px;
      max-height: min(240px, 55vh);
      padding: 8px;
      border-radius: 10px;
      border: 1px solid rgba(124, 108, 240, 0.35);
      background: rgba(8, 10, 18, 0.98);
      box-shadow: 0 12px 30px rgba(0, 0, 0, 0.35);
      overflow-y: auto;
      overscroll-behavior: contain;
    }
    .rich-color-picker-wrap.open .rich-color-dropdown {
      display: grid;
      grid-template-columns: repeat(6, 1fr);
      gap: 6px;
    }
    .rich-color-swatch {
      width: 18px;
      height: 18px;
      margin: 0;
      padding: 0;
      border-radius: 4px;
      border: 1px solid rgba(148, 163, 184, 0.5);
      cursor: pointer;
      box-shadow: none;
      margin-top: 0;
    }
    .rich-color-swatch:hover {
      filter: brightness(1.1);
    }
    .rich-color-swatch.active,
    .rich-color-swatch:focus-visible {
      border-color: #ffffff;
      box-shadow: 0 0 0 1px rgba(124, 108, 240, 0.65);
      outline: none;
    }
    .rich-color-swatch--white {
      border-color: rgba(203, 213, 225, 0.9);
    }
    .rich-quick-toolbar {
      position: absolute;
      z-index: 40;
      display: none;
      align-items: center;
      gap: 6px;
      padding: 8px;
      border-radius: 10px;
      border: 1px solid rgba(124, 108, 240, 0.35);
      background: rgba(8, 10, 18, 0.96);
      box-shadow: 0 12px 30px rgba(0, 0, 0, 0.35);
    }
    .rich-quick-toolbar .rich-btn {
      min-width: 34px;
      min-height: 34px;
      padding: 6px 9px;
    }
    .rich-quick-toolbar .rich-color-dropdown {
      left: auto;
      right: 0;
    }
    .rich-editor {
      min-height: 200px;
      padding: 14px;
      outline: none;
      color: var(--text);
    }
    .rich-editor:focus { box-shadow: inset 0 0 0 2px rgba(124, 108, 240, 0.25); }
    .rich-editor p { margin: 0 0 8px; color: inherit; }
    .rich-editor ul, .rich-editor ol { margin: 0 0 8px 20px; }
    .rich-editor-hidden { display: none; }
    button {
      margin-top: 14px;
      padding: 11px 18px;
      border: none;
      border-radius: var(--radius-sm);
      font-weight: 600;
      font-size: 0.9rem;
      cursor: pointer;
      background: linear-gradient(135deg, #6d5dfc 0%, #8b7cf5 50%, #a78bfa 100%);
      color: #fff;
      box-shadow: 0 8px 24px rgba(109, 93, 252, 0.35);
      transition: filter 0.15s ease, transform 0.1s ease;
    }
    button:hover { filter: brightness(1.08); }
    button:active { transform: translateY(1px); }
    table {
      width: 100%;
      border-collapse: collapse;
      margin-top: 14px;
      background: var(--card);
      backdrop-filter: blur(12px);
      -webkit-backdrop-filter: blur(12px);
      border-radius: var(--radius);
      overflow: hidden;
      border: 1px solid var(--line);
      box-shadow: 0 12px 40px rgba(0, 0, 0, 0.2);
    }
    th, td { border-bottom: 1px solid var(--line); padding: 12px; text-align: left; vertical-align: top; }
    th {
      background: rgba(15, 23, 42, 0.85);
      font-size: 12px;
      font-weight: 600;
      text-transform: uppercase;
      letter-spacing: 0.06em;
      color: var(--muted);
    }
    tbody tr { transition: background 0.12s ease; }
    tbody tr:hover { background: rgba(124, 108, 240, 0.06); }
    tr:last-child td { border-bottom: none; }
    .actions { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; }
    .actions form { display: inline; margin: 0; }
    .prayers-filter-form { margin: 0 0 8px; }
    #dynamic-sections > .prayers-filter-form:first-child { margin-top: 16px; }
    .prayers-filter-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
      gap: 12px 18px;
      align-items: end;
      padding: 16px 18px;
      background: rgba(15, 23, 42, 0.45);
      border: 1px solid var(--line);
      border-radius: var(--radius-sm);
    }
    .prayers-filter-grid label {
      display: block;
      margin: 0 0 6px;
      font-size: 0.8rem;
      font-weight: 600;
      color: #94a3b8;
    }
    .prayers-filter-actions {
      display: flex;
      flex-wrap: wrap;
      gap: 10px 14px;
      align-items: center;
      margin-top: 14px;
    }
    .prayers-filter-actions button { margin-top: 0; }
    .prayers-list-meta {
      font-size: 0.875rem;
      color: var(--muted);
      margin: 10px 0 0;
    }
    .logout { background: rgba(15, 23, 42, 0.9); box-shadow: none; border: 1px solid var(--line); }
    .tree > ul.tree-level {
      list-style: none;
      margin: 0;
      padding: 0;
      border: none;
    }
    .tree li > ul.tree-level {
      list-style: none;
      margin: 8px 0 0 0;
      padding: 0 0 0 14px;
      border-left: 1px dashed rgba(148, 163, 184, 0.35);
    }
    .tree li { margin: 10px 0; color: var(--text); }
    .tree-card-full {
      --tree-ctrl-h: 32px;
      margin-top: 16px;
    }
    .tree-node {
      display: flex;
      flex-direction: row;
      align-items: center;
      gap: 10px;
      width: 100%;
      min-height: var(--tree-ctrl-h);
      flex-wrap: nowrap;
    }
    .tree-node-meta {
      flex: 1 1 auto;
      min-width: 0;
      display: flex;
      align-items: center;
      gap: 8px;
      flex-wrap: wrap;
    }
    .tree-actions {
      flex: 0 0 auto;
      display: inline-flex;
      align-items: center;
      gap: 6px;
      margin-left: 8px;
    }
    .tree-actions form {
      display: inline-flex;
      margin: 0;
      align-items: center;
    }
    .btn-mini {
      margin-top: 0;
      padding: 5px 10px;
      border-radius: 8px;
      font-size: 12px;
      box-shadow: none;
    }
    .btn-mini.secondary {
      background: rgba(124, 108, 240, 0.22);
      color: #e0e7ff;
      border: 1px solid rgba(124, 108, 240, 0.3);
    }
    .btn-mini.danger {
      background: rgba(248, 113, 113, 0.12);
      color: #fca5a5;
      border: 1px solid rgba(248, 113, 113, 0.25);
    }
    .drag-handle {
      cursor: grab;
      user-select: none;
      color: var(--muted);
      border: 1px dashed rgba(148, 163, 184, 0.4);
      border-radius: 8px;
      padding: 0 8px;
      font-weight: 700;
      background: rgba(0, 0, 0, 0.2);
      min-width: var(--tree-ctrl-h, 32px);
      height: var(--tree-ctrl-h, 32px);
      box-sizing: border-box;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      flex-shrink: 0;
    }
    .drag-handle:active { cursor: grabbing; }
    .tree-item.dragging { opacity: .45; }
    .tree-item.drop-target > .tree-node {
      outline: 2px dashed rgba(124, 108, 240, 0.7);
      outline-offset: 3px;
      border-radius: 8px;
    }
    .tree-node-meta .badge {
      display: inline-flex;
      align-items: center;
      height: var(--tree-ctrl-h, 32px);
      padding: 0 10px;
      border-radius: 999px;
      background: rgba(196, 163, 90, 0.15);
      color: #e8d5a3;
      font-size: 11px;
      font-weight: 600;
      margin-left: 0;
      border: 1px solid rgba(196, 163, 90, 0.25);
      box-sizing: border-box;
      flex-shrink: 0;
    }
    .move-btn {
      margin-top: 0;
      padding: 0;
      min-width: var(--tree-ctrl-h, 32px);
      height: var(--tree-ctrl-h, 32px);
      border-radius: 8px;
      font-size: 12px;
      line-height: 1;
      background: rgba(124, 108, 240, 0.2);
      color: #e0e7ff;
      border: 1px solid rgba(124, 108, 240, 0.28);
      box-shadow: none;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      box-sizing: border-box;
    }
    .tree-actions .btn-pill--sm {
      height: var(--tree-ctrl-h, 32px);
      min-height: var(--tree-ctrl-h, 32px);
      padding: 0 12px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      box-sizing: border-box;
    }
    .tree-actions .btn-mini {
      height: var(--tree-ctrl-h, 32px);
      min-height: var(--tree-ctrl-h, 32px);
      padding: 0 12px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      box-sizing: border-box;
    }
    .badge {
      display: inline-block;
      padding: 3px 10px;
      border-radius: 999px;
      background: rgba(196, 163, 90, 0.15);
      color: #e8d5a3;
      font-size: 11px;
      font-weight: 600;
      margin-left: 6px;
      border: 1px solid rgba(196, 163, 90, 0.25);
    }
    .inline-help { font-size: 13px; color: var(--muted); margin-top: 6px; }
    .hidden { display: none; }
    .busy { opacity: 0.75; pointer-events: none; }
    .spinner {
      display: inline-block;
      width: 14px;
      height: 14px;
      border: 2px solid rgba(255, 255, 255, 0.35);
      border-top-color: #fff;
      border-radius: 50%;
      animation: spin 0.8s linear infinite;
      vertical-align: text-bottom;
      margin-left: 8px;
    }
    .toast-wrap { position: fixed; top: 16px; right: 16px; z-index: 9999; display: flex; flex-direction: column; gap: 8px; }
    .toast {
      min-width: 240px;
      max-width: 360px;
      padding: 12px 14px;
      border-radius: var(--radius-sm);
      color: #fff;
      box-shadow: 0 16px 40px rgba(0, 0, 0, 0.45);
      border: 1px solid rgba(255, 255, 255, 0.1);
      animation: fadeIn 0.2s ease;
    }
    .toast.ok { background: linear-gradient(135deg, #15803d, #22c55e); }
    .toast.err { background: linear-gradient(135deg, #b91c1c, #ef4444); }
    .table-section-title { margin-top: 24px; margin-bottom: 0; }
    @keyframes fadeIn { from { opacity: 0; transform: translateY(-8px); } to { opacity: 1; transform: translateY(0); } }
    @keyframes spin { to { transform: rotate(360deg); } }
    @media (max-width: 900px) {
      .grid { grid-template-columns: 1fr; }
      .header { flex-direction: column; align-items: flex-start; }
      .header-brand { align-self: center; }
    }
    @media (max-width: 1180px) {
      .header { flex-direction: column; align-items: flex-start; }
      .header-brand { align-self: center; }
    }

    /* --- Старонкі ўваходу і першаснай налады --- */
    body.body-auth {
      display: flex;
      flex-direction: column;
      min-height: 100vh;
      max-width: none;
      padding: 0;
    }
    body.body-auth .header {
      display: none;
    }
    .auth-layout {
      flex: 1;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 20px 16px 48px;
      width: 100%;
    }
    .auth-card {
      position: relative;
      width: 100%;
      max-width: 420px;
      padding: 36px 32px 32px;
      border-radius: 22px;
      background:
        linear-gradient(155deg, rgba(26, 32, 54, 0.97) 0%, rgba(15, 23, 42, 0.94) 45%, rgba(17, 24, 39, 0.96) 100%);
      border: 1px solid rgba(148, 163, 184, 0.22);
      box-shadow:
        0 0 0 1px rgba(124, 108, 240, 0.12),
        0 28px 56px rgba(0, 0, 0, 0.5),
        0 0 80px rgba(124, 108, 240, 0.08),
        inset 0 1px 0 rgba(255, 255, 255, 0.06);
      backdrop-filter: blur(20px);
      -webkit-backdrop-filter: blur(20px);
    }
    .auth-card::after {
      content: "";
      position: absolute;
      inset: 0;
      border-radius: inherit;
      pointer-events: none;
      background: radial-gradient(ellipse 80% 50% at 50% -30%, rgba(124, 108, 240, 0.18), transparent 55%);
    }
    .auth-card > * { position: relative; z-index: 1; }
    .auth-card--warning {
      border-color: rgba(251, 191, 36, 0.35);
      box-shadow:
        0 0 0 1px rgba(251, 191, 36, 0.15),
        0 28px 56px rgba(0, 0, 0, 0.5),
        inset 0 1px 0 rgba(255, 255, 255, 0.05);
    }
    .auth-card--warning::after {
      background: radial-gradient(ellipse 80% 50% at 50% -30%, rgba(251, 191, 36, 0.12), transparent 55%);
    }
    .auth-card-head {
      text-align: center;
      margin-bottom: 28px;
    }
    .auth-eyebrow {
      margin: 0 0 8px;
      font-size: 0.6875rem;
      font-weight: 600;
      letter-spacing: 0.16em;
      text-transform: uppercase;
      color: rgba(196, 163, 90, 0.9);
    }
    .auth-icon {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      width: 56px;
      height: 56px;
      margin-bottom: 18px;
      border-radius: 16px;
      background: linear-gradient(145deg, rgba(124, 108, 240, 0.35), rgba(196, 163, 90, 0.12));
      border: 1px solid rgba(124, 108, 240, 0.35);
      box-shadow: 0 8px 24px rgba(109, 93, 252, 0.25);
      color: #e9d5ff;
    }
    .auth-icon svg {
      width: 26px;
      height: 26px;
      opacity: 0.95;
    }
    .auth-card h2 {
      margin: 0 0 10px;
      font-size: 1.45rem;
      font-weight: 700;
      letter-spacing: -0.02em;
      color: var(--text);
    }
    .auth-lead {
      margin: 0;
      font-size: 0.9375rem;
      line-height: 1.25;
      color: var(--muted);
    }
    .auth-alert {
      margin-bottom: 20px;
      padding: 12px 14px;
      border-radius: var(--radius-sm);
      font-size: 0.875rem;
      font-weight: 500;
      line-height: 1.45;
      border: 1px solid rgba(248, 113, 113, 0.35);
      background: rgba(127, 29, 29, 0.35);
      color: #fecaca;
    }
    .auth-alert--system {
      border-color: rgba(251, 191, 36, 0.4);
      background: rgba(120, 53, 15, 0.35);
      color: #fde68a;
    }
    .auth-form label {
      margin-top: 18px;
      margin-bottom: 8px;
      font-size: 0.8125rem;
      letter-spacing: 0.03em;
      text-transform: uppercase;
      color: #94a3b8;
    }
    .auth-form label:first-of-type {
      margin-top: 0;
    }
    .auth-form input[type="password"],
    .auth-form input[type="text"] {
      padding: 14px 16px;
      font-size: 1rem;
      border-radius: 12px;
      background: rgba(10, 12, 20, 0.65);
      border: 1px solid rgba(148, 163, 184, 0.2);
    }
    .auth-form input[type="password"]:focus,
    .auth-form input[type="text"]:focus {
      border-color: rgba(124, 108, 240, 0.55);
      box-shadow: 0 0 0 4px var(--accent-glow);
    }
    .auth-form button[type="submit"] {
      width: 100%;
      margin-top: 26px;
      padding: 14px 20px;
      font-size: 0.9375rem;
      letter-spacing: 0.02em;
      border-radius: 12px;
    }
    .auth-hint {
      margin: 18px 0 0;
      text-align: center;
      font-size: 0.8125rem;
      color: var(--muted);
      line-height: 1.5;
    }
    .auth-hint code {
      font-size: 0.85em;
    }
  </style>
</head>
<body class="<?= (!$authReady || !$isLoggedIn) ? 'body-auth' : '' ?>">
  <div class="header">
    <div class="header-brand">
      <h1>Totus Tuus</h1>
      <p class="header-tagline">Панэль кіравання Святой Памяці<br>Біскупа Казіміра Велікасельца OP</p>
    </div>
    <?php if ($isLoggedIn): ?>
      <?php
        $panelNavPage = 'index';
        $panelNavView = $view;
        $panelNavCalYear = (int)date('Y');
        require __DIR__ . '/../includes/panel_admin_nav.php';
        ?>
    <?php endif; ?>
  </div>

  <?php
  $authLockSvg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>';
  ?>
  <?php if (!$authReady): ?>
    <main class="auth-layout">
      <div class="auth-card auth-card--warning">
        <div class="auth-card-head">
          <p class="auth-eyebrow">Totus Tuus</p>
          <div class="auth-icon"><?= $authLockSvg ?></div>
          <h2>Няма злучэння з БД</h2>
          <p class="auth-lead">Панэль не можа ініцыялізаваць аўтарызацыю. Праверце канфігурацыю і правы доступу.</p>
        </div>
        <?php if ($error !== null && $error !== ''): ?>
          <div class="auth-alert auth-alert--system"><?= htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
        <?php endif; ?>
        <p class="auth-hint">Файл наладаў: <code>api/db.php</code>. Патрэбныя правы SELECT, INSERT, UPDATE, CREATE.</p>
      </div>
    </main>
  <?php elseif ($isSetupRequired): ?>
    <main class="auth-layout">
      <div class="auth-card">
        <div class="auth-card-head">
          <p class="auth-eyebrow">Totus Tuus</p>
          <div class="auth-icon"><?= $authLockSvg ?></div>
          <h2>Першасная налада</h2>
          <p class="auth-lead">Задайце пароль адміністратара — ён будзе захаваны ў базе толькі ў выглядзе надзейнага хэша.</p>
        </div>
        <?php if ($error !== null && $error !== ''): ?>
          <div class="auth-alert"><?= htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
        <?php endif; ?>
        <form method="post" class="auth-form" autocomplete="off"><?= panel_csrf_field() ?>
          <label for="setup_password">Пароль</label>
          <input id="setup_password" name="setup_password" type="password" minlength="8" required autocomplete="new-password" placeholder="Не менш за 8 сімвалаў">

          <label for="setup_password_confirm">Паўтарыце пароль</label>
          <input id="setup_password_confirm" name="setup_password_confirm" type="password" minlength="8" required autocomplete="new-password" placeholder="Той жа пароль яшчэ раз">

          <button type="submit">Захаваць і працягнуць</button>
        </form>
      </div>
    </main>
  <?php elseif (!$isLoggedIn): ?>
    <main class="auth-layout">
      <div class="auth-card">
        <div class="auth-card-head">
          <p class="auth-eyebrow">Totus Tuus</p>
          <div class="auth-icon"><?= $authLockSvg ?></div>
          <h2>Уваход у панэль</h2>
          <p class="auth-lead">Каталог малітваў, катэгорыі і тэксты Бібліі для праграмы.</p>
        </div>
        <?php if ($error !== null && $error !== ''): ?>
          <div class="auth-alert"><?= htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
        <?php endif; ?>
        <form method="post" class="auth-form" autocomplete="off"><?= panel_csrf_field() ?>
          <label for="panel_login">Лагін</label>
          <input id="panel_login" name="panel_login" type="text" required maxlength="64" autocomplete="username" placeholder="Увядзіце лагін" autofocus>

          <label for="panel_password">Пароль</label>
          <input id="panel_password" name="panel_password" type="password" required autocomplete="current-password" placeholder="Увядзіце пароль">

          <button type="submit">Увайсці</button>
        </form>
      </div>
    </main>
  <?php else: ?>
    <div id="dynamic-sections">
      <?php if ($view === 'no-access'): ?>
        <div class="card">
          <h2>Няма доступу</h2>
          <p>Вам не прызначаны ніводзін раздзел панэлі. Звярніцеся да адміністратара, каб атрымаць правы.</p>
          <form method="post" style="margin-top:16px;"><?= panel_csrf_field() ?>
            <button type="submit" name="logout" value="1" class="btn-pill">Выйсці</button>
          </form>
        </div>
      <?php elseif ($view === 'add-category'): ?>
        <div class="card">
          <h2>Дадаць катэгорыю</h2>
          <form method="post" class="js-ajax-form" data-refresh="1"><?= panel_csrf_field() ?>
            <label for="create_category_name">Назва катэгорыі / падкатэгорыі</label>
            <input id="create_category_name" name="create_category_name" required>

            <label for="create_category_parent_id">Бацькоўская катэгорыя (пуста = верхні ўзровень)</label>
            <select id="create_category_parent_id" name="create_category_parent_id">
              <option value="">Абраць бацькоўскую катэгорыю</option>
              <?php foreach ($topLevelCategories as $category): ?>
                <option value="<?= (int)$category['id'] ?>"><?= htmlspecialchars((string)$category['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
              <?php endforeach; ?>
            </select>

            <button type="submit">Захаваць катэгорыю</button>
          </form>
        </div>
      <?php endif; ?>

      <?php if ($view === 'categories'): ?>
        <div class="card tree tree-card-full">
          <h2>Дрэва катэгорый</h2>
          <?php if (count($categoryTree) === 0): ?>
            <p>Катэгорыі пакуль не створаны.</p>
          <?php else: ?>
            <ul class="tree-level" data-parent-id="">
              <?php foreach ($categoryTree as $rootNode): ?>
                <li class="tree-item" data-id="<?= (int)$rootNode['id'] ?>" data-parent-id="">
                  <div class="tree-node">
                    <div class="tree-node-meta">
                      <span class="drag-handle" title="Затрымайце і перацягніце">::</span>
                      <strong><?= htmlspecialchars((string)$rootNode['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>
                      <span class="badge">ID <?= (int)$rootNode['id'] ?></span>
                    </div>
                    <div class="tree-actions">
                      <form method="post" class="js-ajax-form" data-refresh="1"><?= panel_csrf_field() ?>
                        <input type="hidden" name="move_category_id" value="<?= (int)$rootNode['id'] ?>">
                        <input type="hidden" name="move_direction" value="up">
                        <button type="submit" class="move-btn">↑</button>
                      </form>
                      <form method="post" class="js-ajax-form" data-refresh="1"><?= panel_csrf_field() ?>
                        <input type="hidden" name="move_category_id" value="<?= (int)$rootNode['id'] ?>">
                        <input type="hidden" name="move_direction" value="down">
                        <button type="submit" class="move-btn">↓</button>
                      </form>
                      <a class="btn-pill btn-pill--sm btn-pill--purple" href="/?view=add-category&amp;edit_category_id=<?= (int)$rootNode['id'] ?>">Рэд.</a>
                      <form method="post" class="js-ajax-form" data-refresh="1" data-confirm="Выдаліць катэгорыю і ўсе яе падкатэгорыі?"><?= panel_csrf_field() ?>
                        <input type="hidden" name="delete_category_id" value="<?= (int)$rootNode['id'] ?>">
                        <button type="submit" class="btn-mini danger">Выдаліць</button>
                      </form>
                    </div>
                  </div>
                  <?php if (count($rootNode['children']) > 0): ?>
                    <ul class="tree-level" data-parent-id="<?= (int)$rootNode['id'] ?>">
                      <?php foreach ($rootNode['children'] as $childNode): ?>
                        <li class="tree-item" data-id="<?= (int)$childNode['id'] ?>" data-parent-id="<?= (int)$rootNode['id'] ?>">
                          <div class="tree-node">
                            <div class="tree-node-meta">
                              <span class="drag-handle" title="Затрымайце і перацягніце">::</span>
                              <?= htmlspecialchars((string)$childNode['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                              <span class="badge">ID <?= (int)$childNode['id'] ?></span>
                            </div>
                            <div class="tree-actions">
                              <form method="post" class="js-ajax-form" data-refresh="1"><?= panel_csrf_field() ?>
                                <input type="hidden" name="move_category_id" value="<?= (int)$childNode['id'] ?>">
                                <input type="hidden" name="move_direction" value="up">
                                <button type="submit" class="move-btn">↑</button>
                              </form>
                              <form method="post" class="js-ajax-form" data-refresh="1"><?= panel_csrf_field() ?>
                                <input type="hidden" name="move_category_id" value="<?= (int)$childNode['id'] ?>">
                                <input type="hidden" name="move_direction" value="down">
                                <button type="submit" class="move-btn">↓</button>
                              </form>
                              <a class="btn-pill btn-pill--sm btn-pill--purple" href="/?view=add-category&amp;edit_category_id=<?= (int)$childNode['id'] ?>">Рэд.</a>
                              <form method="post" class="js-ajax-form" data-refresh="1" data-confirm="Выдаліць падкатэгорыю?"><?= panel_csrf_field() ?>
                                <input type="hidden" name="delete_category_id" value="<?= (int)$childNode['id'] ?>">
                                <button type="submit" class="btn-mini danger">Выдаліць</button>
                              </form>
                            </div>
                          </div>
                        </li>
                      <?php endforeach; ?>
                    </ul>
                  <?php endif; ?>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
        </div>
      <?php endif; ?>

    <?php if ($view === 'add-category' && $editCategory !== null): ?>
      <div class="card">
        <h2>Рэдагаванне катэгорыі #<?= (int)$editCategory['id'] ?></h2>
        <form method="post"><?= panel_csrf_field() ?>
          <input type="hidden" name="update_category_id" value="<?= (int)$editCategory['id'] ?>">

          <label for="update_category_name">Назва катэгорыі</label>
          <input id="update_category_name" name="update_category_name" value="<?= htmlspecialchars((string)$editCategory['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" required>

          <label for="update_category_parent_id">Бацькоўская катэгорыя</label>
          <select id="update_category_parent_id" name="update_category_parent_id">
            <option value="">Абраць бацькоўскую катэгорыю</option>
            <?php foreach ($topLevelCategories as $category): ?>
              <?php if ((int)$category['id'] !== (int)$editCategory['id']): ?>
                <option value="<?= (int)$category['id'] ?>" <?= ((int)($editCategory['parent_id'] ?? 0) === (int)$category['id']) ? 'selected' : '' ?>>
                  <?= htmlspecialchars((string)$category['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                </option>
              <?php endif; ?>
            <?php endforeach; ?>
          </select>

          <div class="form-actions-row">
            <button type="submit">Захаваць змены катэгорыі</button>
            <a href="/?view=add-category" class="btn-pill btn-pill--gold">Адмяніць</a>
          </div>
        </form>
      </div>
    <?php endif; ?>

    <?php if ($view === 'add-prayer'): ?>
      <div class="card">
        <h2>Дадаць малітву</h2>
        <form method="post" class="js-ajax-form" data-refresh="1"><?= panel_csrf_field() ?>
        <label for="title">Назва малітвы</label>
        <input id="title" name="title" required>

        <label for="parent_category_id">Катэгорыя</label>
        <select id="parent_category_id" name="parent_category_id">
          <option value="">Абраць катэгорыю</option>
          <?php foreach ($topLevelCategories as $category): ?>
            <option value="<?= (int)$category['id'] ?>"><?= htmlspecialchars((string)$category['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
          <?php endforeach; ?>
        </select>

        <div id="subcategory-block" class="hidden">
        <label for="subcategory_id">Падкатэгорыя</label>
          <select id="subcategory_id" name="subcategory_id">
            <option value="">Абраць падкатэгорыю</option>
            <?php foreach ($subcategoryOptions as $parentId => $subcategories): ?>
              <?php foreach ($subcategories as $subcategory): ?>
                <option value="<?= (int)$subcategory['id'] ?>" data-parent-id="<?= (int)$parentId ?>">
                  <?= htmlspecialchars((string)$subcategory['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                </option>
              <?php endforeach; ?>
            <?php endforeach; ?>
          </select>
          <div class="inline-help">Паказваюцца толькі падкатэгорыі абранай катэгорыі.</div>
        </div>

        <label for="language">Мова</label>
        <input id="language" name="language" value="by" placeholder="Беларуская мова (by)">

        <label for="sort_order">Парадак у асноўнай катэгорыі</label>
        <input id="sort_order" name="sort_order" type="number" min="0" step="1" placeholder="Аўта (у канец)">
        <div class="inline-help">Меней — вышэй у праграме. Пуста — наступны нумар пасля максімальнага ў гэтай катэгорыі.</div>

        <label for="additional_category_ids">Дадатковыя катэгорыі</label>
        <select id="additional_category_ids" name="additional_category_ids[]" multiple>
          <?php foreach ($categoryOptions as $categoryId => $categoryLabel): ?>
            <option value="<?= (int)$categoryId ?>">
              <?= htmlspecialchars((string)$categoryLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
            </option>
          <?php endforeach; ?>
        </select>
        <div class="inline-help">Можна абраць некалькі дадатковых катэгорый (Ctrl/Cmd + пстрычка).</div>

        <label for="text">Тэкст малітвы</label>
        <div class="rich-editor-wrap">
          <div class="rich-toolbar">
            <div class="rich-toolbar-group">
              <button type="button" class="rich-btn rich-btn-icon" data-cmd="bold" title="Тоўсты" aria-label="Тоўсты"><b>B</b></button>
              <button type="button" class="rich-btn rich-btn-icon" data-cmd="italic" title="Курсіў" aria-label="Курсіў"><i>I</i></button>
              <button type="button" class="rich-btn rich-btn-icon" data-cmd="underline" title="Падкрэслены" aria-label="Падкрэслены"><u>U</u></button>
            </div>
            <div class="rich-toolbar-group">
              <button type="button" class="rich-btn rich-btn-icon" data-cmd="insertUnorderedList" title="Маркіраваны спіс" aria-label="Маркіраваны спіс">
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M4 10.5c-.83 0-1.5.67-1.5 1.5s.67 1.5 1.5 1.5 1.5-.67 1.5-1.5-.67-1.5-1.5-1.5zm0-6c-.83 0-1.5.67-1.5 1.5S3.17 7.5 4 7.5 5.5 6.83 5.5 6 4.83 4.5 4 4.5zm0 12c-.83 0-1.5.67-1.5 1.5s.67 1.5 1.5 1.5 1.5-.67 1.5-1.5-.67-1.5-1.5-1.5zM7 19h14v-2H7v2zm0-6h14v-2H7v2zm0-8v2h14V5H7z"/></svg>
              </button>
              <button type="button" class="rich-btn rich-btn-icon" data-cmd="insertOrderedList" title="Нумараваны спіс" aria-label="Нумараваны спіс">
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M2 17h2v.5H3v1h1v.5H2v1h3v-4H2v1zm1-9h1V4H2v1h1v3zm-1 3h1.8L2 13.1v.9h3v-1H3.2L5 10.9V10H2v1zm5-6v2h14V5H7zm0 14h14v-2H7v2zm0-6h14v-2H7v2z"/></svg>
              </button>
            </div>
            <div class="rich-toolbar-group">
              <button type="button" class="rich-btn rich-btn-icon" data-cmd="justifyLeft" title="Выраўнованне ўлева" aria-label="Выраўнованне ўлева">L</button>
              <button type="button" class="rich-btn rich-btn-icon" data-cmd="justifyCenter" title="Выраўнованне па цэнтры" aria-label="Выраўнованне па цэнтры">C</button>
              <button type="button" class="rich-btn rich-btn-icon" data-cmd="justifyRight" title="Выраўнованне ўправа" aria-label="Выраўнованне ўправа">R</button>
              <button type="button" class="rich-btn rich-btn-icon" data-cmd="justifyFull" title="Выраўнованне па шырыні" aria-label="Выраўнованне па шырыні">J</button>
            </div>
            <div class="rich-toolbar-group" title="Колер вылучанага тэксту">
              <span class="rich-toolbar-label">Колер</span>
              <div class="rich-color-picker-wrap">
                <button type="button" class="rich-color-toggle" data-color="#ffffff" style="background:#ffffff;" title="Абраць колер" aria-label="Абраць колер"></button>
                <div class="rich-color-dropdown" role="group" aria-label="Колер тэксту">
                  <button type="button" class="rich-color-swatch" data-color="#000000" style="background:#000000;" title="Чорны"></button>
                  <button type="button" class="rich-color-swatch" data-color="#1f2937" style="background:#1f2937;" title="Графіт"></button>
                  <button type="button" class="rich-color-swatch" data-color="#374151" style="background:#374151;" title="Цёмна-шэры"></button>
                  <button type="button" class="rich-color-swatch" data-color="#6b7280" style="background:#6b7280;" title="Шэры"></button>
                  <button type="button" class="rich-color-swatch" data-color="#9ca3af" style="background:#9ca3af;" title="Светла-шэры"></button>
                  <button type="button" class="rich-color-swatch rich-color-swatch--white active" data-color="#ffffff" style="background:#ffffff;" title="Белы"></button>
                  <button type="button" class="rich-color-swatch" data-color="#7f1d1d" style="background:#7f1d1d;" title="Бардовы"></button>
                  <button type="button" class="rich-color-swatch" data-color="#b91c1c" style="background:#b91c1c;" title="Цёмна-чырвоны"></button>
                  <button type="button" class="rich-color-swatch" data-color="#ef4444" style="background:#ef4444;" title="Чырвоны"></button>
                  <button type="button" class="rich-color-swatch" data-color="#f87171" style="background:#f87171;" title="Светла-чырвоны"></button>
                  <button type="button" class="rich-color-swatch" data-color="#7c2d12" style="background:#7c2d12;" title="Карычневы"></button>
                  <button type="button" class="rich-color-swatch" data-color="#c2410c" style="background:#c2410c;" title="Цёмна-аранжавы"></button>
                  <button type="button" class="rich-color-swatch" data-color="#f97316" style="background:#f97316;" title="Аранжавы"></button>
                  <button type="button" class="rich-color-swatch" data-color="#fb923c" style="background:#fb923c;" title="Светла-аранжавы"></button>
                  <button type="button" class="rich-color-swatch" data-color="#854d0e" style="background:#854d0e;" title="Гарчычны"></button>
                  <button type="button" class="rich-color-swatch" data-color="#eab308" style="background:#eab308;" title="Жоўты"></button>
                  <button type="button" class="rich-color-swatch" data-color="#fde047" style="background:#fde047;" title="Светла-жоўты"></button>
                  <button type="button" class="rich-color-swatch" data-color="#3f6212" style="background:#3f6212;" title="Аліўкавы"></button>
                  <button type="button" class="rich-color-swatch" data-color="#15803d" style="background:#15803d;" title="Цёмна-зялёны"></button>
                  <button type="button" class="rich-color-swatch" data-color="#22c55e" style="background:#22c55e;" title="Зялёны"></button>
                  <button type="button" class="rich-color-swatch" data-color="#4ade80" style="background:#4ade80;" title="Светла-зялёны"></button>
                  <button type="button" class="rich-color-swatch" data-color="#0f766e" style="background:#0f766e;" title="Цёмна-бірузовы"></button>
                  <button type="button" class="rich-color-swatch" data-color="#14b8a6" style="background:#14b8a6;" title="Бірузовы"></button>
                  <button type="button" class="rich-color-swatch" data-color="#2dd4bf" style="background:#2dd4bf;" title="Светла-бірузовы"></button>
                  <button type="button" class="rich-color-swatch" data-color="#1e3a8a" style="background:#1e3a8a;" title="Цёмна-сіні"></button>
                  <button type="button" class="rich-color-swatch" data-color="#2563eb" style="background:#2563eb;" title="Сіні"></button>
                  <button type="button" class="rich-color-swatch" data-color="#60a5fa" style="background:#60a5fa;" title="Светла-сіні"></button>
                  <button type="button" class="rich-color-swatch" data-color="#312e81" style="background:#312e81;" title="Індыга"></button>
                  <button type="button" class="rich-color-swatch" data-color="#4f46e5" style="background:#4f46e5;" title="Светла-індыга"></button>
                  <button type="button" class="rich-color-swatch" data-color="#581c87" style="background:#581c87;" title="Цёмна-фіялетавы"></button>
                  <button type="button" class="rich-color-swatch" data-color="#9333ea" style="background:#9333ea;" title="Фіялетавы"></button>
                  <button type="button" class="rich-color-swatch" data-color="#d946ef" style="background:#d946ef;" title="Пурпурны"></button>
                </div>
              </div>
            </div>
            <div class="rich-toolbar-group">
              <button type="button" class="rich-btn" data-cmd="formatBlock" data-value="h3" title="Загаловак">Загаловак</button>
              <button type="button" class="rich-btn" data-cmd="removeFormat" title="Ачысціць фарматаванне">Ачысціць</button>
            </div>
          </div>
          <div id="text_editor" class="rich-editor js-rich-editor" data-target-id="text" contenteditable="true"></div>
        </div>
        <textarea id="text" class="rich-editor-hidden" name="text" required></textarea>

          <input type="hidden" name="create_prayer" value="1">
          <button type="submit" name="create_prayer" value="1">Захаваць малітву</button>
        </form>
      </div>
    <?php endif; ?>

    <?php if ($view === 'prayers' && $editPrayer !== null): ?>
      <?php
      $prayersReturnGet = $_GET;
      unset($prayersReturnGet['edit_prayer_id']);
      $prayersReturnGet['view'] = 'prayers';
      $prayersReturnHref = '/?' . http_build_query($prayersReturnGet);
      ?>
      <div class="card">
        <h2>Рэдагаванне малітвы #<?= (int)$editPrayer['id'] ?></h2>
        <form method="post" class="js-ajax-form" data-refresh="1"><?= panel_csrf_field() ?>
          <input type="hidden" name="update_prayer_id" value="<?= (int)$editPrayer['id'] ?>">

          <label for="update_title">Назва малітвы</label>
          <input id="update_title" name="update_title" value="<?= htmlspecialchars((string)$editPrayer['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" required>

          <label for="update_parent_category_id">Катэгорыя</label>
          <select id="update_parent_category_id" name="update_parent_category_id">
            <option value="">Абраць катэгорыю</option>
            <?php foreach ($topLevelCategories as $category): ?>
              <option value="<?= (int)$category['id'] ?>" <?= ($editPrayerParentCategoryId === (int)$category['id']) ? 'selected' : '' ?>>
                <?= htmlspecialchars((string)$category['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
              </option>
            <?php endforeach; ?>
          </select>

          <div id="update_subcategory-block" class="hidden">
            <label for="update_subcategory_id">Падкатэгорыя</label>
            <select id="update_subcategory_id" name="update_subcategory_id">
              <option value="">Абраць падкатэгорыю</option>
              <?php foreach ($subcategoryOptions as $parentId => $subcategories): ?>
                <?php foreach ($subcategories as $subcategory): ?>
                  <option value="<?= (int)$subcategory['id'] ?>" data-parent-id="<?= (int)$parentId ?>" <?= ($editPrayerSubcategoryId === (int)$subcategory['id']) ? 'selected' : '' ?>>
                    <?= htmlspecialchars((string)$subcategory['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                  </option>
                <?php endforeach; ?>
              <?php endforeach; ?>
            </select>
            <div class="inline-help">Паказваюцца толькі падкатэгорыі абранай катэгорыі.</div>
          </div>

          <label for="update_language">Мова</label>
          <input id="update_language" name="update_language" value="<?= htmlspecialchars((string)($editPrayer['language'] ?? 'by'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" placeholder="Беларуская мова (by)">

          <label for="update_sort_order">Парадак у асноўнай катэгорыі</label>
          <input id="update_sort_order" name="update_sort_order" type="number" min="0" step="1" value="<?= (int)($editPrayer['sort_order'] ?? 0) ?>">
          <div class="inline-help">Меншае лік вышэй у спісе праграмы; пры роўнасці — па ID.</div>

          <label for="update_additional_category_ids">Дополнительные категории</label>
          <select id="update_additional_category_ids" name="update_additional_category_ids[]" multiple>
            <?php foreach ($categoryOptions as $categoryId => $categoryLabel): ?>
              <option value="<?= (int)$categoryId ?>" <?= in_array((int)$categoryId, $editPrayerAdditionalCategoryIds, true) ? 'selected' : '' ?>>
                <?= htmlspecialchars((string)$categoryLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
              </option>
            <?php endforeach; ?>
          </select>
          <div class="inline-help">Дадатковыя катэгорыі для гэтай малітвы.</div>

          <label for="update_text">Тэкст малітвы</label>
          <div class="rich-editor-wrap">
            <div class="rich-toolbar">
              <div class="rich-toolbar-group">
                <button type="button" class="rich-btn rich-btn-icon" data-cmd="bold" title="Тоўсты" aria-label="Тоўсты"><b>B</b></button>
                <button type="button" class="rich-btn rich-btn-icon" data-cmd="italic" title="Курсіў" aria-label="Курсіў"><i>I</i></button>
                <button type="button" class="rich-btn rich-btn-icon" data-cmd="underline" title="Падкрэслены" aria-label="Падкрэслены"><u>U</u></button>
              </div>
              <div class="rich-toolbar-group">
                <button type="button" class="rich-btn rich-btn-icon" data-cmd="insertUnorderedList" title="Маркіраваны спіс" aria-label="Маркіраваны спіс">
                  <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M4 10.5c-.83 0-1.5.67-1.5 1.5s.67 1.5 1.5 1.5 1.5-.67 1.5-1.5-.67-1.5-1.5-1.5zm0-6c-.83 0-1.5.67-1.5 1.5S3.17 7.5 4 7.5 5.5 6.83 5.5 6 4.83 4.5 4 4.5zm0 12c-.83 0-1.5.67-1.5 1.5s.67 1.5 1.5 1.5 1.5-.67 1.5-1.5-.67-1.5-1.5-1.5zM7 19h14v-2H7v2zm0-6h14v-2H7v2zm0-8v2h14V5H7z"/></svg>
                </button>
                <button type="button" class="rich-btn rich-btn-icon" data-cmd="insertOrderedList" title="Нумараваны спіс" aria-label="Нумараваны спіс">
                  <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M2 17h2v.5H3v1h1v.5H2v1h3v-4H2v1zm1-9h1V4H2v1h1v3zm-1 3h1.8L2 13.1v.9h3v-1H3.2L5 10.9V10H2v1zm5-6v2h14V5H7zm0 14h14v-2H7v2zm0-6h14v-2H7v2z"/></svg>
                </button>
              </div>
              <div class="rich-toolbar-group">
                <button type="button" class="rich-btn rich-btn-icon" data-cmd="justifyLeft" title="Выраўнованне ўлева" aria-label="Выраўнованне ўлева">L</button>
                <button type="button" class="rich-btn rich-btn-icon" data-cmd="justifyCenter" title="Выраўнованне па цэнтры" aria-label="Выраўнованне па цэнтры">C</button>
                <button type="button" class="rich-btn rich-btn-icon" data-cmd="justifyRight" title="Выраўнованне ўправа" aria-label="Выраўнованне ўправа">R</button>
                <button type="button" class="rich-btn rich-btn-icon" data-cmd="justifyFull" title="Выраўнованне па шырыні" aria-label="Выраўнованне па шырыні">J</button>
              </div>
              <div class="rich-toolbar-group" title="Колер вылучанага тэксту">
                <span class="rich-toolbar-label">Колер</span>
                <div class="rich-color-picker-wrap">
                  <button type="button" class="rich-color-toggle" data-color="#ffffff" style="background:#ffffff;" title="Абраць колер" aria-label="Абраць колер"></button>
                  <div class="rich-color-dropdown" role="group" aria-label="Колер тэксту">
                    <button type="button" class="rich-color-swatch" data-color="#000000" style="background:#000000;" title="Чорны"></button>
                    <button type="button" class="rich-color-swatch" data-color="#1f2937" style="background:#1f2937;" title="Графіт"></button>
                    <button type="button" class="rich-color-swatch" data-color="#374151" style="background:#374151;" title="Цёмна-шэры"></button>
                    <button type="button" class="rich-color-swatch" data-color="#6b7280" style="background:#6b7280;" title="Шэры"></button>
                    <button type="button" class="rich-color-swatch" data-color="#9ca3af" style="background:#9ca3af;" title="Светла-шэры"></button>
                    <button type="button" class="rich-color-swatch rich-color-swatch--white active" data-color="#ffffff" style="background:#ffffff;" title="Белы"></button>
                    <button type="button" class="rich-color-swatch" data-color="#7f1d1d" style="background:#7f1d1d;" title="Бардовы"></button>
                    <button type="button" class="rich-color-swatch" data-color="#b91c1c" style="background:#b91c1c;" title="Цёмна-чырвоны"></button>
                    <button type="button" class="rich-color-swatch" data-color="#ef4444" style="background:#ef4444;" title="Чырвоны"></button>
                    <button type="button" class="rich-color-swatch" data-color="#f87171" style="background:#f87171;" title="Светла-чырвоны"></button>
                    <button type="button" class="rich-color-swatch" data-color="#7c2d12" style="background:#7c2d12;" title="Карычневы"></button>
                    <button type="button" class="rich-color-swatch" data-color="#c2410c" style="background:#c2410c;" title="Цёмна-аранжавы"></button>
                    <button type="button" class="rich-color-swatch" data-color="#f97316" style="background:#f97316;" title="Аранжавы"></button>
                    <button type="button" class="rich-color-swatch" data-color="#fb923c" style="background:#fb923c;" title="Светла-аранжавы"></button>
                    <button type="button" class="rich-color-swatch" data-color="#854d0e" style="background:#854d0e;" title="Гарчычны"></button>
                    <button type="button" class="rich-color-swatch" data-color="#eab308" style="background:#eab308;" title="Жоўты"></button>
                    <button type="button" class="rich-color-swatch" data-color="#fde047" style="background:#fde047;" title="Светла-жоўты"></button>
                    <button type="button" class="rich-color-swatch" data-color="#3f6212" style="background:#3f6212;" title="Аліўкавы"></button>
                    <button type="button" class="rich-color-swatch" data-color="#15803d" style="background:#15803d;" title="Цёмна-зялёны"></button>
                    <button type="button" class="rich-color-swatch" data-color="#22c55e" style="background:#22c55e;" title="Зялёны"></button>
                    <button type="button" class="rich-color-swatch" data-color="#4ade80" style="background:#4ade80;" title="Светла-зялёны"></button>
                    <button type="button" class="rich-color-swatch" data-color="#0f766e" style="background:#0f766e;" title="Цёмна-бірузовы"></button>
                    <button type="button" class="rich-color-swatch" data-color="#14b8a6" style="background:#14b8a6;" title="Бірузовы"></button>
                    <button type="button" class="rich-color-swatch" data-color="#2dd4bf" style="background:#2dd4bf;" title="Светла-бірузовы"></button>
                    <button type="button" class="rich-color-swatch" data-color="#1e3a8a" style="background:#1e3a8a;" title="Цёмна-сіні"></button>
                    <button type="button" class="rich-color-swatch" data-color="#2563eb" style="background:#2563eb;" title="Сіні"></button>
                    <button type="button" class="rich-color-swatch" data-color="#60a5fa" style="background:#60a5fa;" title="Светла-сіні"></button>
                    <button type="button" class="rich-color-swatch" data-color="#312e81" style="background:#312e81;" title="Індыга"></button>
                    <button type="button" class="rich-color-swatch" data-color="#4f46e5" style="background:#4f46e5;" title="Светла-індыга"></button>
                    <button type="button" class="rich-color-swatch" data-color="#581c87" style="background:#581c87;" title="Цёмна-фіялетавы"></button>
                    <button type="button" class="rich-color-swatch" data-color="#9333ea" style="background:#9333ea;" title="Фіялетавы"></button>
                    <button type="button" class="rich-color-swatch" data-color="#d946ef" style="background:#d946ef;" title="Пурпурны"></button>
                  </div>
                </div>
              </div>
              <div class="rich-toolbar-group">
                <button type="button" class="rich-btn" data-cmd="formatBlock" data-value="h3" title="Загаловак">Загаловак</button>
                <button type="button" class="rich-btn" data-cmd="removeFormat" title="Ачысціць фарматаванне">Ачысціць</button>
              </div>
            </div>
            <div
              id="update_text_editor"
              class="rich-editor js-rich-editor"
              data-target-id="update_text"
              data-initial-html="<?= htmlspecialchars((string)base64_encode((string)$editPrayer['text']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
              contenteditable="true"
            ></div>
          </div>
          <textarea id="update_text" class="rich-editor-hidden" name="update_text" required><?= htmlspecialchars((string)$editPrayer['text'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></textarea>

          <div class="form-actions-row">
            <button type="submit">Захаваць змены малітвы</button>
            <a href="<?= htmlspecialchars($prayersReturnHref, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" class="btn-pill btn-pill--gold">Адмяніць</a>
          </div>
        </form>
      </div>
    <?php endif; ?>

    <?php if ($view === 'prayers'): ?>
    <form method="get" class="prayers-filter-form" action="/">
      <input type="hidden" name="view" value="prayers">
      <details class="panel-filter-details"<?= $prayersFilterOpen ? ' open' : '' ?>>
        <summary class="panel-filter-summary">
          <span class="panel-filter-summary__title">Фільтры каталога малітваў</span>
          <span class="panel-filter-summary__meta"><?= $prayersFilterOpen ? 'Фільтр актыўны' : 'Па змаўчанні' ?></span>
          <span class="panel-filter-summary__hint">Націсніце, каб разгарнуць або згарнуць палі пошуку, катэгорый і сартавання.</span>
        </summary>
        <div class="panel-filter-details__body">
      <div class="prayers-filter-grid">
        <div>
          <label for="pr_q">Пошук па назве</label>
          <input type="search" id="pr_q" name="pr_q" value="<?= htmlspecialchars($prQ, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" placeholder="Частка загалоўка" autocomplete="off">
        </div>
        <div>
          <label for="pr_cat">Асноўная катэгорыя</label>
          <select id="pr_cat" name="pr_cat">
            <option value="" <?= $prCat === '' ? 'selected' : '' ?>>Усе</option>
            <option value="none" <?= $prCat === 'none' ? 'selected' : '' ?>>Без катэгорыі</option>
            <?php foreach ($categoryOptions as $cid => $clabel): ?>
              <option value="<?= (int)$cid ?>" <?= $prCat === (string)(int)$cid ? 'selected' : '' ?>>
                <?= htmlspecialchars((string)$clabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label for="pr_parent">Каранёвая катэгорыя (дрэва)</label>
          <select id="pr_parent" name="pr_parent">
            <option value="0" <?= $prParent === 0 ? 'selected' : '' ?>>Любая</option>
            <?php foreach ($topLevelCategories as $tc): ?>
              <option value="<?= (int)$tc['id'] ?>" <?= $prParent === (int)$tc['id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars((string)$tc['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label for="pr_level">Тып асноўнай прывязкі</label>
          <select id="pr_level" name="pr_level">
            <option value="" <?= $prLevel === '' ? 'selected' : '' ?>>Усе</option>
            <option value="root" <?= $prLevel === 'root' ? 'selected' : '' ?>>Толькі бацькоўская (без падкатэгорыі)</option>
            <option value="sub" <?= $prLevel === 'sub' ? 'selected' : '' ?>>Толькі падкатэгорыя</option>
          </select>
        </div>
        <div>
          <label for="pr_lang">Мова</label>
          <select id="pr_lang" name="pr_lang">
            <option value="" <?= $prLang === '' ? 'selected' : '' ?>>Усе</option>
            <?php foreach ($prayerListLanguages as $lng): ?>
              <option value="<?= htmlspecialchars($lng, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" <?= $prLang === $lng ? 'selected' : '' ?>>
                <?= htmlspecialchars($lng, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label for="pr_additional">Дадатковыя катэгорыі</label>
          <select id="pr_additional" name="pr_additional">
            <option value="" <?= $prAdditional === '' ? 'selected' : '' ?>>Не важна</option>
            <option value="yes" <?= $prAdditional === 'yes' ? 'selected' : '' ?>>Ёсць</option>
            <option value="no" <?= $prAdditional === 'no' ? 'selected' : '' ?>>Няма</option>
          </select>
        </div>
        <div>
          <label for="pr_active">Статус</label>
          <select id="pr_active" name="pr_active">
            <option value="" <?= $prActive === '' ? 'selected' : '' ?>>Усе</option>
            <option value="1" <?= $prActive === '1' ? 'selected' : '' ?>>Актыўныя</option>
            <option value="0" <?= $prActive === '0' ? 'selected' : '' ?>>Неактыўныя</option>
          </select>
        </div>
        <div>
          <label for="pr_sort">Сартаванне</label>
          <select id="pr_sort" name="pr_sort">
            <?php
            $sortChoices = [
                'sort_order_asc' => 'Парадак у катэгорыі (як у праграме)',
                'sort_order_desc' => 'Парадак у катэгорыі — наадварот',
                'id_desc' => 'ID — новыя зверху',
                'id_asc' => 'ID — старыя зверху',
                'updated_desc' => 'Абноўлена — свежыя',
                'updated_asc' => 'Абноўлена — старыя',
                'title_asc' => 'Назва А→Я',
                'title_desc' => 'Назва Я→А',
                'category_asc' => 'Катэгорыя А→Я',
                'category_desc' => 'Катэгорыя Я→А',
                'subcategory_asc' => 'Падкатэгорыя А→Я',
                'subcategory_desc' => 'Падкатэгорыя Я→А',
                'language_asc' => 'Мова А→Я',
                'language_desc' => 'Мова Я→А',
            ];
            foreach ($sortChoices as $sk => $slabel):
            ?>
              <option value="<?= htmlspecialchars($sk, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" <?= $prSort === $sk ? 'selected' : '' ?>><?= htmlspecialchars($slabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label for="pr_limit">На старонцы</label>
          <select id="pr_limit" name="pr_limit">
            <?php foreach ([25, 50, 100, 200, 500] as $lim): ?>
              <option value="<?= $lim ?>" <?= $prLimit === $lim ? 'selected' : '' ?>><?= $lim ?> шт.</option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="prayers-filter-actions">
        <button type="submit">Ужыць фільтры</button>
        <a class="btn-pill btn-pill--sm btn-pill--muted" href="/?view=prayers">Скід</a>
      </div>
        </div>
      </details>
    </form>
    <p class="prayers-list-meta">
      Паказана <?= count($rows) ?> з <?= (int)$prayersTotalFiltered ?> па ўмовах<?= $prayersTotalFiltered > $prLimit ? ' (ліміт ' . (int)$prLimit . ')' : '' ?>.
    </p>
    <table>
      <thead>
        <tr>
          <th>ID</th>
          <th>Порядок</th>
          <th>Назва</th>
          <th>Катэгорыя</th>
          <th>Падкатэгорыя</th>
          <th>Дадатк. катэгорыі</th>
          <th>Мова</th>
          <th>Абноўлена</th>
          <th>Дзеянні</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $row): ?>
          <?php
          $editQuery = $_GET;
          $editQuery['view'] = 'prayers';
          $editQuery['edit_prayer_id'] = (int)$row['id'];
          $editHref = '/?' . http_build_query($editQuery);
          ?>
          <tr>
            <td><?= (int)$row['id'] ?></td>
            <td><?= (int)($row['sort_order'] ?? 0) ?></td>
            <td><?= htmlspecialchars((string)$row['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
            <td><?= htmlspecialchars((string)($row['category'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
            <td><?= htmlspecialchars((string)($row['subcategory'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
            <td><?= htmlspecialchars((string)($row['additional_categories'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
            <td><?= htmlspecialchars((string)($row['language'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
            <td><?= htmlspecialchars((string)$row['updated_at'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
            <td class="actions">
              <a class="btn-pill btn-pill--sm btn-pill--purple" href="<?= htmlspecialchars($editHref, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">Рэдагаваць</a>
              <form method="post" class="js-ajax-form" data-refresh="1" data-confirm="Выдаліць малітву безваротна?"><?= panel_csrf_field() ?>
                <input type="hidden" name="delete_prayer_id" value="<?= (int)$row['id'] ?>">
                <button type="submit" class="btn-pill btn-pill--sm btn-pill--danger">Выдаліць</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>

    <?php if ($view === 'songbook'): ?>
    <h2 class="table-section-title">Спеўнік (загрузка ў праграму праз API)</h2>
    <p class="inline-help songbook-intro">Тэксты і выявы (JPEG, PNG, WebP, GIF, AVIF) у праграме толькі для чытання. Файлы — у <code>uploads/songbook/</code>.</p>
    <div class="songbook-toolbar-top">
      <a class="btn-pill btn-pill--purple" href="<?= htmlspecialchars($songbookAddEntryHref, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">Дадаць запіс</a>
    </div>
    <div class="songbook-admin-panel">
      <form method="get" class="songbook-panel-section songbook-filter-form" aria-label="Фільтр па катэгорыях спеўніка">
        <input type="hidden" name="view" value="songbook">
        <details class="panel-filter-details"<?= $songbookFilterOpen ? ' open' : '' ?>>
          <summary class="panel-filter-summary">
            <span class="panel-filter-summary__title">Выбар катэгорый</span>
            <span class="panel-filter-summary__meta"><?= count($songbookCatSelected) > 0 ? 'У фільтры: ' . (string)count($songbookCatSelected) : 'Усе запісы' ?></span>
            <span class="panel-filter-summary__hint">Націсніце, каб разгарнуць або згарнуць спіс катэгорый і галачак.</span>
          </summary>
          <div class="panel-filter-details__body">
            <p class="songbook-panel-section__hint">Некалькі галачак працуюць як <strong>АБО</strong>: у спісе застаюцца запісы з <em>любой</em> з абраных катэгорый. Зніміце ўсе галачкі і націсніце «Паказаць» або «Скід фільтра», каб паказаць усе запісы.</p>
            <?php
            $songbookHasEmptyCat = false;
            foreach ($songbookCategoryDistinct as $dCat) {
                if (trim($dCat) === '') {
                    $songbookHasEmptyCat = true;
                    break;
                }
            }
            ?>
            <div class="songbook-filter-chips" role="group" aria-label="Катэгорыі">
              <?php if ($songbookHasEmptyCat): ?>
                <label class="songbook-filter-chip">
                  <input type="checkbox" name="sb_cat[]" value="<?= htmlspecialchars($songbookCatEmptyToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" <?= in_array($songbookCatEmptyToken, $songbookCatSelected, true) ? ' checked' : '' ?>>
                  <span>(без катэгорыі)</span>
                </label>
              <?php endif; ?>
              <?php foreach ($songbookCategoryDistinct as $dCat): ?>
                <?php if (trim($dCat) === '') { continue; } ?>
                <label class="songbook-filter-chip">
                  <input type="checkbox" name="sb_cat[]" value="<?= htmlspecialchars($dCat, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" <?= in_array($dCat, $songbookCatSelected, true) ? ' checked' : '' ?>>
                  <span><?= htmlspecialchars($dCat, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                </label>
              <?php endforeach; ?>
            </div>
            <?php if (count($songbookCategoryDistinct) === 0): ?>
              <p class="songbook-panel-section__empty">Катэгорыі з’явяцца пасля дадання запісаў.</p>
            <?php endif; ?>
            <div class="songbook-panel-actions">
              <button type="submit" class="btn-pill btn-pill--purple">Паказаць</button>
              <a class="btn-pill btn-pill--muted" href="/?view=songbook">Скід фільтра</a>
            </div>
          </div>
        </details>
      </form>
      <div class="songbook-panel-divider" role="presentation"></div>
      <form id="songbook-bulk-category-form" method="post" class="songbook-panel-section songbook-bulk-form js-ajax-form" data-refresh="1"><?= panel_csrf_field() ?>
        <div class="songbook-panel-section__head">
          <span class="songbook-panel-section__title">Масавыя дзеянні</span>
        </div>
        <p class="songbook-panel-section__hint">Пазначыце радкі ў табліцы ніжэй. Можна або змяніць ім агульную катэгорыю, або выставіць нумарацыю <strong>1…N</strong> па парадку выбраных радкоў.</p>
        <div class="songbook-bulk-row">
          <label for="sb_bulk_category" class="bulk-songbook-label">Новая катэгорыя</label>
          <input id="sb_bulk_category" name="sb_bulk_category" type="text" maxlength="255" class="bulk-songbook-input" placeholder="Напрыклад, Адвэнт; пуста — без загалоўка">
          <button type="submit" name="bulk_songbook_category" value="1" class="btn-pill btn-pill--gold">Ужыць катэгорыю</button>
          <button type="submit" name="bulk_songbook_autonumber" value="1" class="btn-pill btn-pill--purple">Аўтанумарацыя 1…N</button>
          <button type="submit" name="bulk_songbook_clear_numbering" value="1" class="btn-pill btn-pill--muted">Ачысціць нумарацыю</button>
        </div>
      </form>
    </div>
    <table>
      <thead>
        <tr>
          <th class="cell-checkbox"><input type="checkbox" id="songbook-bulk-select-all" title="Абраць усе" aria-label="Абраць усе запісы"></th>
          <th>ID</th>
          <th>Катэгорыя</th>
          <th>№</th>
          <th>Назва</th>
          <th>Тып</th>
          <th>Файл</th>
          <th>Абноўлена</th>
          <th>Дзеянні</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($songbookRows as $sbr): ?>
          <?php
          $chapterMajorValue = (int)$sbr['chapter_major'];
          $num = '';
          if ($chapterMajorValue > 0) {
              $num = (string)$chapterMajorValue . '.';
              $num .= ($sbr['subchapter'] !== null && $sbr['subchapter'] !== '') ? (string)(int)$sbr['subchapter'] : '';
          }
          $editSbQuery = ['view' => 'add-songbook', 'edit_songbook_id' => (int)$sbr['id']];
          if (count($songbookCatSelected) > 0) {
              $editSbQuery['sb_cat'] = array_values($songbookCatSelected);
          }
          $editSbHref = '/?' . http_build_query($editSbQuery);
          ?>
          <tr>
            <td class="cell-checkbox">
              <input type="checkbox" class="songbook-bulk-id-cb" name="songbook_bulk_ids[]" value="<?= (int)$sbr['id'] ?>" form="songbook-bulk-category-form" aria-label="Абраць запіс <?= (int)$sbr['id'] ?>">
            </td>
            <td><?= (int)$sbr['id'] ?></td>
            <td><?= htmlspecialchars((string)($sbr['category'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
            <td><?= htmlspecialchars($num, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
            <td><?= htmlspecialchars((string)$sbr['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
            <td><?= htmlspecialchars((string)$sbr['content_type'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
            <td><?= htmlspecialchars((string)($sbr['media_path'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
            <td><?= htmlspecialchars((string)($sbr['updated_at'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
            <td class="actions">
              <a class="btn-pill btn-pill--sm btn-pill--purple" href="<?= htmlspecialchars($editSbHref, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">Рэдагаваць</a>
              <form method="post" class="js-ajax-form" data-refresh="1" data-confirm="Выдаліць запіс спеўніка?"><?= panel_csrf_field() ?>
                <input type="hidden" name="delete_songbook_id" value="<?= (int)$sbr['id'] ?>">
                <button type="submit" class="btn-pill btn-pill--sm btn-pill--danger">Выдаліць</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>

    <?php if ($view === 'add-songbook'): ?>
      <div class="card">
        <h2><?= $editSongbook !== null ? 'Рэдагаваць запіс спеўніка' : 'Дадаць запіс спеўніка' ?></h2>
        <?php if ($editSongbookId > 0 && $editSongbook === null): ?>
          <p>Запіс не знойдзены. <a class="btn-pill btn-pill--sm btn-pill--gold" href="<?= htmlspecialchars($songbookListHref, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">Да спісу</a></p>
        <?php else: ?>
        <form method="post" enctype="multipart/form-data" class="<?= $editSongbook !== null ? 'js-ajax-form' : '' ?>" data-refresh="<?= $editSongbook !== null ? '1' : '0' ?>"><?= panel_csrf_field() ?>
          <label for="sb_title">Назва (напрыклад, Абрад)</label>
          <input id="sb_title" name="sb_title" type="text" value="<?= htmlspecialchars((string)($editSongbook['title'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">

          <label for="sb_category">Катэгорыя раздзела (напрыклад, Адвэнт; пуста — без загалоўка ў праграме)</label>
          <input id="sb_category" name="sb_category" type="text" maxlength="255" value="<?= htmlspecialchars((string)($editSongbook['category'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" placeholder="Неабавязкова">

          <label for="sb_chapter_major">Нумар главы (цэлае ≥ 1)</label>
          <input id="sb_chapter_major" name="sb_chapter_major" type="number" min="1" required value="<?= (int)($editSongbook['chapter_major'] ?? 1) ?>">

          <label for="sb_subchapter">Падглава (неабавязкова; пуста — «4.», інакш «4.1», «4.2»…)</label>
          <input id="sb_subchapter" name="sb_subchapter" type="number" min="1" placeholder="пуста = толькі глава" value="<?= $editSongbook !== null && $editSongbook['subchapter'] !== null ? (int)$editSongbook['subchapter'] : '' ?>">

          <label for="sb_sort_order">Парадак сартавання (унутры главы)</label>
          <input id="sb_sort_order" name="sb_sort_order" type="number" value="<?= (int)($editSongbook['sort_order'] ?? 0) ?>">

          <label for="sb_content_type">Тып зместу</label>
          <select id="sb_content_type" name="sb_content_type" required>
            <?php
            $ct = $editSongbook !== null ? (string)($editSongbook['content_type'] ?? 'text') : 'text';
            if (!in_array($ct, ['text', 'image'], true)) {
                $ct = 'text';
            }
            foreach (['text' => 'Тэкст', 'image' => 'Выява'] as $cv => $clabel):
            ?>
              <option value="<?= htmlspecialchars($cv, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" <?= $ct === $cv ? 'selected' : '' ?>><?= htmlspecialchars($clabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
            <?php endforeach; ?>
          </select>

          <label for="sb_text_body">Тэкст (HTML дазволены; для выявы можна пакінуць пустым)</label>
          <textarea id="sb_text_body" name="sb_text_body" rows="12"><?= htmlspecialchars((string)($editSongbook['text_body'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></textarea>

          <label for="sb_media">Выява (абавязкова пры стварэнні з тыпам «Выява»; пры рэдагаванні — толькі калі мяняеце файл). Фарматы: JPEG, PNG, WebP, GIF, AVIF.</label>
          <input id="sb_media" name="sb_media" type="file" accept=".jpg,.jpeg,.png,.webp,.gif,.avif,image/jpeg,image/png,image/webp,image/gif,image/avif">
          <?php if ($editSongbook !== null && !empty($editSongbook['media_path'])): ?>
            <p class="inline-help">Бягучы файл: <code><?= htmlspecialchars((string)$editSongbook['media_path'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></code></p>
          <?php endif; ?>

          <?php if ($editSongbook !== null): ?>
            <input type="hidden" name="update_songbook_id" value="<?= (int)$editSongbook['id'] ?>">
            <div class="form-actions-row">
              <button type="submit">Захаваць</button>
              <a href="<?= htmlspecialchars($songbookListHref, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" class="btn-pill btn-pill--gold">Да спісу</a>
            </div>
          <?php else: ?>
            <input type="hidden" name="create_songbook" value="1">
            <div class="form-actions-row">
              <button type="submit">Стварыць</button>
              <a href="<?= htmlspecialchars($songbookListHref, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" class="btn-pill btn-pill--gold">Да спісу</a>
            </div>
          <?php endif; ?>
        </form>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <?php if ($view === 'scripture'): ?>
      <div class="card">
        <h2>Тэксты Бібліі</h2>
        <p class="inline-help">Імпартуйце JSON у тым жа фармаце, што і файлы ў праграме (assets: books → chapters → verses). Потым рэдагуйце сціхі па главах.</p>
        <div class="scripture-bible-toolbar">
          <a class="btn-scripture btn-scripture-primary" href="/?view=scripture-import">Імпарт JSON у БД</a>
        </div>
        <?php if (count($scriptureTranslations) === 0): ?>
          <p>Няма запісаў перакладаў (мета не створана).</p>
        <?php else: ?>
          <ul class="scripture-translation-list">
            <?php foreach ($scriptureTranslations as $tr): ?>
              <li class="scripture-translation-item">
                <div class="scripture-translation-title">
                  <strong><?= htmlspecialchars((string)$tr['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>
                  <span class="badge"><?= htmlspecialchars((string)$tr['id'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                </div>
                <a class="btn-scripture btn-scripture-secondary" href="/?view=scripture-chapter&amp;tr=<?= urlencode((string)$tr['id']) ?>">Рэдагаваць сціхі</a>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <?php if ($view === 'scripture-import'): ?>
      <div class="card">
        <h2>Імпарт Бібліі (JSON)</h2>
        <form method="post" enctype="multipart/form-data"><?= panel_csrf_field() ?>
          <label for="scripture_import_translation">Пераклад</label>
          <select id="scripture_import_translation" name="scripture_import_translation" required>
            <?php foreach ($scriptureTranslations as $tr): ?>
              <option value="<?= htmlspecialchars((string)$tr['id'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                <?= htmlspecialchars((string)$tr['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
              </option>
            <?php endforeach; ?>
          </select>
          <label for="scripture_json_file">JSON-файл</label>
          <input id="scripture_json_file" name="scripture_json_file" type="file" accept=".json,application/json" required>
          <input type="hidden" name="scripture_import" value="1">
          <button type="submit">Імпартаваць</button>
        </form>
        <p class="inline-help"><a class="btn-pill btn-pill--sm btn-pill--gold" href="/?view=scripture">← Да раздзела Бібліі</a></p>
      </div>
    <?php endif; ?>

    <?php if ($view === 'scripture-chapter'): ?>
      <div class="card">
        <h2>Рэдагаванне сціхаў</h2>
        <?php if ($scriptureEditTr === ''): ?>
          <p>Абярыце пераклад на <a class="btn-pill btn-pill--sm btn-pill--purple" href="/?view=scripture">старонцы Бібліі</a>.</p>
        <?php elseif (count($scriptureBooks) === 0): ?>
          <p>Няма кніг у БД. Спачатку <a class="btn-pill btn-pill--sm btn-pill--purple" href="/?view=scripture-import">імпартуйце JSON</a>.</p>
        <?php else: ?>
          <form method="get" class="scripture-chapter-nav">
            <input type="hidden" name="view" value="scripture-chapter">
            <div class="scripture-nav-row">
              <div class="scripture-nav-field">
                <label for="tr_sel">Пераклад</label>
                <select id="tr_sel" name="tr" onchange="this.form.submit()">
                  <?php foreach ($scriptureTranslations as $tr): ?>
                    <option value="<?= htmlspecialchars((string)$tr['id'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" <?= ($scriptureEditTr === (string)$tr['id']) ? 'selected' : '' ?>>
                      <?= htmlspecialchars((string)$tr['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="scripture-nav-field">
                <label for="book_sel">Кніга</label>
                <select id="book_sel" name="book_id" onchange="this.form.submit()">
                  <?php foreach ($scriptureBooks as $b): ?>
                    <option value="<?= (int)$b['book_id'] ?>" <?= ($scriptureEditBookId === (int)$b['book_id']) ? 'selected' : '' ?>>
                      <?= htmlspecialchars((string)$b['book_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="scripture-nav-field scripture-nav-field--chapter">
                <label for="ch_sel">Глава</label>
                <select id="ch_sel" name="chapter" onchange="this.form.submit()">
                  <?php foreach ($scriptureChapters as $chNum): ?>
                    <option value="<?= (int)$chNum ?>" <?= ($scriptureEditChapter === (int)$chNum) ? 'selected' : '' ?>><?= (int)$chNum ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>
          </form>

          <?php if (count($scriptureChapterVerses) === 0): ?>
            <p>Няма сціхаў для гэтай главы (пусты імпарт або няслушныя параметры).</p>
          <?php else: ?>
            <form method="post"><?= panel_csrf_field() ?>
              <input type="hidden" name="scripture_save_chapter" value="1">
              <input type="hidden" name="scripture_tr" value="<?= htmlspecialchars($scriptureEditTr, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
              <input type="hidden" name="scripture_book_id" value="<?= (int)$scriptureEditBookId ?>">
              <input type="hidden" name="scripture_chapter" value="<?= (int)$scriptureEditChapter ?>">
              <?php foreach ($scriptureChapterVerses as $vr): ?>
                <label for="v<?= (int)$vr['verse'] ?>">Сціх <?= (int)$vr['verse'] ?></label>
                <textarea id="v<?= (int)$vr['verse'] ?>" class="scripture-verse-field" name="verse_text[<?= (int)$vr['verse'] ?>]" rows="2"><?= htmlspecialchars((string)$vr['text'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></textarea>
              <?php endforeach; ?>
              <div class="scripture-form-actions">
                <button type="submit">Захаваць главу</button>
                <a class="scripture-back-btn" href="/?view=scripture"><span class="scripture-back-icon" aria-hidden="true">←</span> Да раздзела Бібліі</a>
              </div>
            </form>
          <?php endif; ?>
        <?php endif; ?>
        <?php
        $showScriptureBackInForm = $scriptureEditTr !== '' && count($scriptureBooks) > 0 && count($scriptureChapterVerses) > 0;
        if (!$showScriptureBackInForm):
        ?>
        <p class="inline-help"><a class="btn-pill btn-pill--sm btn-pill--gold" href="/?view=scripture">← Да раздзела Бібліі</a></p>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    </div>
  <?php endif; ?>
</body>
<div id="toast-wrap" class="toast-wrap"></div>
<script>
  (function () {
    var initialMessage = <?= json_encode($message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    var initialError = <?= json_encode($error, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

    function initSubcategoryFilterFor(parentId, subcategoryId, blockId, keepSelection) {
      var parentSelect = document.getElementById(parentId);
      var subcategorySelect = document.getElementById(subcategoryId);
      var subcategoryBlock = document.getElementById(blockId);
      if (!parentSelect || !subcategorySelect || !subcategoryBlock) return;
      var allOptions = Array.prototype.slice.call(subcategorySelect.querySelectorAll('option[data-parent-id]'));
      var filterSubcategories = function () {
        var selectedParent = parentSelect.value;
        var previousValue = subcategorySelect.value;
        var visibleCount = 0;
        allOptions.forEach(function (opt) {
          var shouldShow = selectedParent !== '' && opt.getAttribute('data-parent-id') === selectedParent;
          opt.classList.toggle('hidden', !shouldShow);
          if (shouldShow) visibleCount += 1;
        });
        if (!keepSelection) {
          subcategorySelect.value = '';
        } else if (previousValue !== '' && subcategorySelect.querySelector('option[value="' + previousValue + '"]:not(.hidden)') === null) {
          subcategorySelect.value = '';
        }
        subcategoryBlock.classList.toggle('hidden', !(selectedParent !== '' && visibleCount > 0));
      };
      parentSelect.addEventListener('change', filterSubcategories);
      filterSubcategories();
    }

    function initSubcategoryFilters() {
      initSubcategoryFilterFor('parent_category_id', 'subcategory_id', 'subcategory-block', false);
      initSubcategoryFilterFor('update_parent_category_id', 'update_subcategory_id', 'update_subcategory-block', true);
    }

    function initAdditionalCategoryFilters() {
      function resolvePrimaryCategory(parentId, subId) {
        var parent = document.getElementById(parentId);
        var sub = document.getElementById(subId);
        if (!parent) return '';
        if (sub && sub.value) return sub.value;
        return parent.value || '';
      }
      function bind(primaryParentId, primarySubId, additionalId) {
        var parent = document.getElementById(primaryParentId);
        var sub = document.getElementById(primarySubId);
        var additional = document.getElementById(additionalId);
        if (!parent || !additional) return;
        var update = function () {
          var primaryCategoryId = resolvePrimaryCategory(primaryParentId, primarySubId);
          Array.prototype.slice.call(additional.options).forEach(function (opt) {
            if (!opt.value) return;
            var isPrimary = primaryCategoryId !== '' && opt.value === primaryCategoryId;
            opt.disabled = isPrimary;
            if (isPrimary) opt.selected = false;
          });
        };
        parent.addEventListener('change', update);
        if (sub) sub.addEventListener('change', update);
        update();
      }
      bind('parent_category_id', 'subcategory_id', 'additional_category_ids');
      bind('update_parent_category_id', 'update_subcategory_id', 'update_additional_category_ids');
    }

    function decodeBase64Unicode(value) {
      if (!value) return '';
      try {
        return decodeURIComponent(Array.prototype.map.call(atob(value), function (c) {
          return '%' + ('00' + c.charCodeAt(0).toString(16)).slice(-2);
        }).join(''));
      } catch (e) {
        return '';
      }
    }

    function initRichEditors() {
      document.querySelectorAll('.rich-quick-toolbar').forEach(function (toolbar) {
        if (toolbar.parentNode) toolbar.parentNode.removeChild(toolbar);
      });

      function createQuickToolbar() {
        var quick = document.createElement('div');
        quick.className = 'rich-quick-toolbar';
        quick.innerHTML = ''
          + '<button type="button" class="rich-btn rich-btn-icon" data-cmd="bold" title="Тоўсты" aria-label="Тоўсты"><b>B</b></button>'
          + '<button type="button" class="rich-btn rich-btn-icon" data-cmd="italic" title="Курсіў" aria-label="Курсіў"><i>I</i></button>'
          + '<button type="button" class="rich-btn rich-btn-icon" data-cmd="justifyLeft" title="Улева" aria-label="Улева">L</button>'
          + '<button type="button" class="rich-btn rich-btn-icon" data-cmd="justifyCenter" title="Па цэнтры" aria-label="Па цэнтры">C</button>'
          + '<button type="button" class="rich-btn rich-btn-icon" data-cmd="justifyRight" title="Управа" aria-label="Управа">R</button>'
          + '<button type="button" class="rich-btn rich-btn-icon" data-cmd="justifyFull" title="Па шырыні" aria-label="Па шырыні">J</button>'
          + '<div class="rich-color-picker-wrap">'
          + '<button type="button" class="rich-color-toggle" data-color="#ffffff" style="background:#ffffff;" title="Абраць колер" aria-label="Абраць колер"></button>'
          + '<div class="rich-color-dropdown" role="group" aria-label="Колер тэксту">'
          + '<button type="button" class="rich-color-swatch" data-color="#000000" style="background:#000000;" title="Чорны"></button>'
          + '<button type="button" class="rich-color-swatch" data-color="#1f2937" style="background:#1f2937;" title="Графіт"></button>'
          + '<button type="button" class="rich-color-swatch" data-color="#374151" style="background:#374151;" title="Цёмна-шэры"></button>'
          + '<button type="button" class="rich-color-swatch" data-color="#6b7280" style="background:#6b7280;" title="Шэры"></button>'
          + '<button type="button" class="rich-color-swatch" data-color="#9ca3af" style="background:#9ca3af;" title="Светла-шэры"></button>'
          + '<button type="button" class="rich-color-swatch rich-color-swatch--white active" data-color="#ffffff" style="background:#ffffff;" title="Белы"></button>'
          + '<button type="button" class="rich-color-swatch" data-color="#7f1d1d" style="background:#7f1d1d;" title="Бардовы"></button>'
          + '<button type="button" class="rich-color-swatch" data-color="#b91c1c" style="background:#b91c1c;" title="Цёмна-чырвоны"></button>'
          + '<button type="button" class="rich-color-swatch" data-color="#ef4444" style="background:#ef4444;" title="Чырвоны"></button>'
          + '<button type="button" class="rich-color-swatch" data-color="#f87171" style="background:#f87171;" title="Светла-чырвоны"></button>'
          + '<button type="button" class="rich-color-swatch" data-color="#7c2d12" style="background:#7c2d12;" title="Карычневы"></button>'
          + '<button type="button" class="rich-color-swatch" data-color="#c2410c" style="background:#c2410c;" title="Цёмна-аранжавы"></button>'
          + '<button type="button" class="rich-color-swatch" data-color="#f97316" style="background:#f97316;" title="Аранжавы"></button>'
          + '<button type="button" class="rich-color-swatch" data-color="#fb923c" style="background:#fb923c;" title="Светла-аранжавы"></button>'
          + '<button type="button" class="rich-color-swatch" data-color="#854d0e" style="background:#854d0e;" title="Гарчычны"></button>'
          + '<button type="button" class="rich-color-swatch" data-color="#eab308" style="background:#eab308;" title="Жоўты"></button>'
          + '<button type="button" class="rich-color-swatch" data-color="#fde047" style="background:#fde047;" title="Светла-жоўты"></button>'
          + '<button type="button" class="rich-color-swatch" data-color="#3f6212" style="background:#3f6212;" title="Аліўкавы"></button>'
          + '<button type="button" class="rich-color-swatch" data-color="#15803d" style="background:#15803d;" title="Цёмна-зялёны"></button>'
          + '<button type="button" class="rich-color-swatch" data-color="#22c55e" style="background:#22c55e;" title="Зялёны"></button>'
          + '<button type="button" class="rich-color-swatch" data-color="#4ade80" style="background:#4ade80;" title="Светла-зялёны"></button>'
          + '<button type="button" class="rich-color-swatch" data-color="#0f766e" style="background:#0f766e;" title="Цёмна-бірузовы"></button>'
          + '<button type="button" class="rich-color-swatch" data-color="#14b8a6" style="background:#14b8a6;" title="Бірузовы"></button>'
          + '<button type="button" class="rich-color-swatch" data-color="#2dd4bf" style="background:#2dd4bf;" title="Светла-бірузовы"></button>'
          + '<button type="button" class="rich-color-swatch" data-color="#1e3a8a" style="background:#1e3a8a;" title="Цёмна-сіні"></button>'
          + '<button type="button" class="rich-color-swatch" data-color="#2563eb" style="background:#2563eb;" title="Сіні"></button>'
          + '<button type="button" class="rich-color-swatch" data-color="#60a5fa" style="background:#60a5fa;" title="Светла-сіні"></button>'
          + '<button type="button" class="rich-color-swatch" data-color="#312e81" style="background:#312e81;" title="Індыга"></button>'
          + '<button type="button" class="rich-color-swatch" data-color="#4f46e5" style="background:#4f46e5;" title="Светла-індыга"></button>'
          + '<button type="button" class="rich-color-swatch" data-color="#581c87" style="background:#581c87;" title="Цёмна-фіялетавы"></button>'
          + '<button type="button" class="rich-color-swatch" data-color="#9333ea" style="background:#9333ea;" title="Фіялетавы"></button>'
          + '<button type="button" class="rich-color-swatch" data-color="#d946ef" style="background:#d946ef;" title="Пурпурны"></button>'
          + '</div>'
          + '</div>';
        document.body.appendChild(quick);
        return quick;
      }

      var editors = document.querySelectorAll('.js-rich-editor');
      editors.forEach(function (editor) {
        if (editor.dataset.editorBound === '1') return;
        editor.dataset.editorBound = '1';
        var targetId = editor.getAttribute('data-target-id');
        if (!targetId) return;
        var hiddenField = document.getElementById(targetId);
        if (!hiddenField) return;

        var initialEncoded = editor.getAttribute('data-initial-html');
        if (initialEncoded && editor.innerHTML.trim() === '') {
          editor.innerHTML = decodeBase64Unicode(initialEncoded);
        }
        if (!initialEncoded && hiddenField.value && editor.innerHTML.trim() === '') {
          editor.innerHTML = hiddenField.value;
        }
        if (editor.innerHTML.trim() === '') {
          editor.innerHTML = '<p></p>';
        }
        hiddenField.value = editor.innerHTML.trim();
        var quickToolbar = createQuickToolbar();
        var savedRange = null;

        function saveSelectionRange() {
          var sel = window.getSelection();
          if (!sel || sel.rangeCount === 0) return;
          var range = sel.getRangeAt(0);
          if (!editor.contains(range.commonAncestorContainer)) return;
          savedRange = range.cloneRange();
        }

        function restoreSelectionRange() {
          if (!savedRange) return false;
          var sel = window.getSelection();
          if (!sel) return false;
          sel.removeAllRanges();
          sel.addRange(savedRange);
          return true;
        }

        function hideQuickToolbar() {
          quickToolbar.style.display = 'none';
          quickToolbar.querySelectorAll('.rich-color-picker-wrap').forEach(function (pickerWrap) {
            pickerWrap.classList.remove('open');
          });
        }

        function positionQuickToolbar() {
          var sel = window.getSelection();
          if (!sel || sel.rangeCount === 0 || sel.isCollapsed) {
            hideQuickToolbar();
            return;
          }
          var range = sel.getRangeAt(0);
          if (!editor.contains(range.commonAncestorContainer)) {
            hideQuickToolbar();
            return;
          }
          saveSelectionRange();
          var rect = range.getBoundingClientRect();
          if (!rect || (rect.width === 0 && rect.height === 0)) {
            hideQuickToolbar();
            return;
          }
          quickToolbar.style.display = 'flex';
          var top = window.scrollY + rect.top - quickToolbar.offsetHeight - 10;
          if (top < window.scrollY + 10) {
            top = window.scrollY + rect.bottom + 10;
          }
          var left = window.scrollX + rect.left + (rect.width / 2) - (quickToolbar.offsetWidth / 2);
          var maxLeft = window.scrollX + window.innerWidth - quickToolbar.offsetWidth - 10;
          if (left < window.scrollX + 10) left = window.scrollX + 10;
          if (left > maxLeft) left = maxLeft;
          quickToolbar.style.top = top + 'px';
          quickToolbar.style.left = left + 'px';
        }

        editor.addEventListener('input', function () {
          hiddenField.value = editor.innerHTML.trim();
          positionQuickToolbar();
        });
        editor.addEventListener('mouseup', positionQuickToolbar);
        editor.addEventListener('keyup', positionQuickToolbar);
        editor.addEventListener('blur', function () {
          setTimeout(function () {
            var active = document.activeElement;
            if (quickToolbar.contains(active)) return;
            hideQuickToolbar();
          }, 0);
        });
        document.addEventListener('selectionchange', positionQuickToolbar);
        window.addEventListener('scroll', positionQuickToolbar, true);
        window.addEventListener('resize', positionQuickToolbar);

        var toolbar = editor.closest('.rich-editor-wrap');
        if (!toolbar) return;
        function runCommand(cmd, value) {
          editor.focus();
          restoreSelectionRange();
          try {
            document.execCommand(cmd, false, value || null);
          } catch (e) {
            return;
          }
          hiddenField.value = editor.innerHTML.trim();
          saveSelectionRange();
          positionQuickToolbar();
        }
        function setActiveColor(color) {
          var normalized = (color || '').toLowerCase();
          var allScopes = [toolbar, quickToolbar];
          allScopes.forEach(function (scope) {
            scope.querySelectorAll('.rich-color-swatch').forEach(function (swatch) {
              var swatchColor = (swatch.getAttribute('data-color') || '').toLowerCase();
              swatch.classList.toggle('active', swatchColor === normalized);
            });
            scope.querySelectorAll('.rich-color-toggle').forEach(function (toggle) {
              toggle.setAttribute('data-color', color);
              toggle.style.background = color;
            });
          });
        }
        function closeAllColorPickers(exceptWrap) {
          [toolbar, quickToolbar].forEach(function (scope) {
            scope.querySelectorAll('.rich-color-picker-wrap.open').forEach(function (pickerWrap) {
              if (exceptWrap && pickerWrap === exceptWrap) return;
              pickerWrap.classList.remove('open');
            });
          });
        }
        function bindColorPickers(scope, keepSelectionOnToggle) {
          scope.querySelectorAll('.rich-color-picker-wrap').forEach(function (pickerWrap) {
            if (pickerWrap.dataset.bound === '1') return;
            pickerWrap.dataset.bound = '1';
            var toggle = pickerWrap.querySelector('.rich-color-toggle');
            if (toggle) {
              toggle.addEventListener('click', function () {
                if (keepSelectionOnToggle) {
                  restoreSelectionRange();
                } else {
                  saveSelectionRange();
                }
                var willOpen = !pickerWrap.classList.contains('open');
                closeAllColorPickers(pickerWrap);
                pickerWrap.classList.toggle('open', willOpen);
              });
            }
            pickerWrap.querySelectorAll('.rich-color-swatch').forEach(function (swatch) {
              swatch.addEventListener('mousedown', function (event) {
                event.preventDefault();
                restoreSelectionRange();
              });
              swatch.addEventListener('click', function () {
                var color = swatch.getAttribute('data-color');
                runCommand('foreColor', color);
                setActiveColor(color);
                pickerWrap.classList.remove('open');
              });
            });
          });
        }
        toolbar.querySelectorAll('.rich-btn').forEach(function (button) {
          if (button.dataset.bound === '1') return;
          button.dataset.bound = '1';
          button.addEventListener('click', function () {
            var cmd = button.getAttribute('data-cmd');
            var value = button.getAttribute('data-value') || null;
            runCommand(cmd, value);
          });
        });
        bindColorPickers(toolbar, false);
        quickToolbar.querySelectorAll('.rich-btn').forEach(function (button) {
          button.addEventListener('mousedown', function (event) {
            event.preventDefault();
            restoreSelectionRange();
          });
          button.addEventListener('click', function () {
            var cmd = button.getAttribute('data-cmd');
            runCommand(cmd, null);
          });
        });
        bindColorPickers(quickToolbar, true);
        document.addEventListener('mousedown', function (event) {
          if (editor.contains(event.target)) return;
          if (event.target.closest('.rich-color-picker-wrap')) return;
          closeAllColorPickers(null);
          if (!quickToolbar.contains(event.target)) {
            hideQuickToolbar();
          }
        });
      });
    }

    function syncRichEditors() {
      document.querySelectorAll('.js-rich-editor').forEach(function (editor) {
        var targetId = editor.getAttribute('data-target-id');
        if (!targetId) return;
        var hiddenField = document.getElementById(targetId);
        if (!hiddenField) return;
        hiddenField.value = editor.innerHTML.trim();
      });
    }

    function showToast(type, text) {
      var wrap = document.getElementById('toast-wrap');
      if (!wrap) return;
      var el = document.createElement('div');
      el.className = 'toast ' + (type === 'ok' ? 'ok' : 'err');
      el.textContent = text;
      wrap.appendChild(el);
      setTimeout(function () {
        if (el.parentNode) el.parentNode.removeChild(el);
      }, 2200);
    }

    async function refreshDynamicSections() {
      var wrapper = document.getElementById('dynamic-sections');
      if (!wrapper) return;
      var response = await fetch(window.location.pathname + window.location.search, { credentials: 'same-origin' });
      var html = await response.text();
      var doc = new DOMParser().parseFromString(html, 'text/html');
      var newWrapper = doc.getElementById('dynamic-sections');
      if (newWrapper) {
        wrapper.innerHTML = newWrapper.innerHTML;
      }
      bindAjaxForms();
      initSubcategoryFilters();
      initAdditionalCategoryFilters();
      initRichEditors();
      initTreeDragAndDrop();
      initSongbookBulkSelectAll();
    }

    function sendReorder(parentId, orderedIds) {
      var formData = new FormData();
      var csrfMeta = document.querySelector('meta[name="csrf-token"]');
      var csrf = csrfMeta ? csrfMeta.getAttribute('content') : '';
      if (csrf) {
        formData.append('csrf_token', csrf);
      }
      formData.append('ajax', '1');
      formData.append('reorder_category_parent_id', parentId);
      formData.append('reorder_category_ids', orderedIds.join(','));
      return fetch(window.location.pathname + window.location.search, {
        method: 'POST',
        body: formData,
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin'
      }).then(function (resp) { return resp.json(); });
    }

    function initTreeDragAndDrop() {
      var draggedItem = null;
      var items = document.querySelectorAll('.tree-item');
      var handles = document.querySelectorAll('.drag-handle');

      handles.forEach(function (handle) {
        if (handle.dataset.dragBound === '1') return;
        handle.dataset.dragBound = '1';
        handle.setAttribute('draggable', 'true');
        handle.addEventListener('dragstart', function (e) {
          var parentItem = handle.closest('.tree-item');
          if (!parentItem) return;
          draggedItem = parentItem;
          parentItem.classList.add('dragging');
          if (e.dataTransfer) {
            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setData('text/plain', parentItem.getAttribute('data-id') || '');
          }
        });
        handle.addEventListener('dragend', function () {
          var parentItem = handle.closest('.tree-item');
          if (parentItem) parentItem.classList.remove('dragging');
          document.querySelectorAll('.tree-item.drop-target').forEach(function (n) { n.classList.remove('drop-target'); });
        });
      });

      function getLevelItems(levelEl) {
        return Array.prototype.slice.call(levelEl.children).filter(function (node) {
          return node.classList && node.classList.contains('tree-item');
        });
      }

      items.forEach(function (item) {
        if (item.dataset.dragBound === '1') return;
        item.dataset.dragBound = '1';

        item.addEventListener('dragover', function (e) {
          var targetItem = item;
          if (!draggedItem || draggedItem === targetItem) return;
          if (draggedItem.getAttribute('data-parent-id') !== targetItem.getAttribute('data-parent-id')) return;
          e.preventDefault();
          targetItem.classList.add('drop-target');
        });
        item.addEventListener('dragleave', function (e) {
          item.classList.remove('drop-target');
        });
        item.addEventListener('drop', async function (e) {
          e.preventDefault();
          var targetItem = item;
          targetItem.classList.remove('drop-target');
          if (!draggedItem || draggedItem === targetItem) return;
          if (draggedItem.getAttribute('data-parent-id') !== targetItem.getAttribute('data-parent-id')) return;

          var parentList = targetItem.parentElement;
          if (!parentList) return;
          var parentId = parentList.getAttribute('data-parent-id') || '';
          var siblings = getLevelItems(parentList);
          var draggedId = draggedItem.getAttribute('data-id');
          var targetId = targetItem.getAttribute('data-id');
          var orderedIds = siblings.map(function (node) {
            return node.getAttribute('data-id');
          }).filter(Boolean);
          var fromIndex = orderedIds.indexOf(draggedId);
          var toIndex = orderedIds.indexOf(targetId);
          if (fromIndex < 0 || toIndex < 0) return;

          orderedIds.splice(fromIndex, 1);
          orderedIds.splice(toIndex, 0, draggedId);

          try {
            var json = await sendReorder(parentId, orderedIds);
            if (json.ok) {
              showToast('ok', json.message || 'Парадак катэгорый абноўлены.');
              await refreshDynamicSections();
            } else {
              showToast('err', json.error || 'Не ўдалося захаваць парадак.');
              await refreshDynamicSections();
            }
          } catch (err) {
            showToast('err', 'Сеткавая памылка пры захаванні парадку.');
            await refreshDynamicSections();
          }
        });
      });
    }

    function initSongbookBulkSelectAll() {
      var master = document.getElementById('songbook-bulk-select-all');
      if (!master || master.dataset.bound === '1') return;
      master.dataset.bound = '1';
      master.addEventListener('change', function () {
        document.querySelectorAll('.songbook-bulk-id-cb').forEach(function (cb) {
          cb.checked = master.checked;
        });
      });
    }

    function bindAjaxForms() {
      var forms = document.querySelectorAll('form.js-ajax-form');
      forms.forEach(function (form) {
        if (form.dataset.bound === '1') return;
        form.dataset.bound = '1';
        form.addEventListener('submit', async function (e) {
          e.preventDefault();
          var confirmText = form.getAttribute('data-confirm');
          if (confirmText && !window.confirm(confirmText)) {
            return;
          }

          var submitButton = form.querySelector('button[type="submit"], button:not([type])');
          var originalHtml = submitButton ? submitButton.innerHTML : '';
          if (submitButton) {
            submitButton.innerHTML = 'Захаванне <span class="spinner"></span>';
          }
          form.classList.add('busy');

          try {
            syncRichEditors();
            var formData = new FormData(form);
            if (e.submitter && e.submitter.name) {
              formData.append(e.submitter.name, e.submitter.value || '1');
            }
            formData.append('ajax', '1');
            var resp = await fetch(window.location.pathname + window.location.search, {
              method: 'POST',
              body: formData,
              headers: { 'X-Requested-With': 'XMLHttpRequest' },
              credentials: 'same-origin'
            });
            var json = await resp.json();
            if (json.ok) {
              showToast('ok', json.message || 'Аперацыя выканана.');
              if (form.getAttribute('data-refresh') === '1') {
                await refreshDynamicSections();
              }
            } else {
              showToast('err', json.error || 'Памылка аперацыі.');
            }
          } catch (err) {
            showToast('err', 'Сеткавая памылка. Паспрабуйце яшчэ раз.');
          } finally {
            form.classList.remove('busy');
            if (submitButton) {
              submitButton.innerHTML = originalHtml;
            }
          }
        });
      });
    }

    initSubcategoryFilters();
    initAdditionalCategoryFilters();
    initRichEditors();
    bindAjaxForms();
    initSongbookBulkSelectAll();
    initTreeDragAndDrop();
    var authShell = document.body.classList.contains('body-auth');
    if (initialMessage) showToast('ok', initialMessage);
    if (initialError && !authShell) showToast('err', initialError);
  })();
</script>
</html>
