<?php
require_once __DIR__ . '/includes/bootstrap.php';
$_versionRaw = @file_get_contents(__DIR__ . '/../config/version.json');
$_versionData = $_versionRaw ? json_decode($_versionRaw, true) : null;
$appVersion = (is_array($_versionData) && !empty($_versionData['version'])) ? $_versionData['version'] : '3.4.4';

admin_require_web_admin();

$users = [];
$db_error = null;
try {
    $users = $db->fetchAll('SELECT id, username, role, created_at FROM admin_users ORDER BY username');
} catch (Exception $e) {
    $db_error = $e->getMessage();
}
$currentId = (int) $_SESSION['admin_user_id'];
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Пользователи | Админ-панель</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:ital,opsz,wght@0,14..32,100..900;1,14..32,100..900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="stylesheet" href="/admin/css/admin.css?v=<?php echo htmlspecialchars($appVersion); ?>">
    <style>
        .users-page .action-btn { min-width: 32px; text-align: center; }
        .users-page .table-wrapper table th:nth-child(3),
        .users-page .table-wrapper table td:nth-child(3) {
            min-width: 240px;
            white-space: nowrap;
        }
    </style>
    <?php require __DIR__ . '/includes/csrf_head.php'; ?>
</head>
<body class="users-page">
    <div class="container">
        <div class="header">
            <h1>
                <i class="fas fa-users-cog" style="color: #ffd966;"></i>
                Пользователи админ-панели
            </h1>
            <?php $navCurrent = 'users'; include __DIR__ . '/includes/header_nav.php'; ?>
        </div>

        <?php if (isset($db_error)): ?>
            <div class="alert alert-danger"><?php echo htmlspecialchars($db_error); ?></div>
        <?php else: ?>
            <div class="header-with-button">
                <h2><i class="fas fa-list"></i> Учётные записи</h2>
                <button type="button" class="btn btn-primary" onclick="openUserModal(null)">
                    <i class="fas fa-plus"></i> Добавить пользователя
                </button>
            </div>
            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th>Логин</th>
                            <th>Роль</th>
                            <th>Создан</th>
                            <th>Действия</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $u): ?>
                            <tr>
                                <td><code><?php echo htmlspecialchars($u['username']); ?></code></td>
                                <td><?php echo $u['role'] === 'admin' ? 'Администратор' : 'Пользователь'; ?></td>
                                <td><?php echo htmlspecialchars($u['created_at'] ?? ''); ?></td>
                                <td>
                                    <div class="action-buttons">
                                        <button type="button" class="action-btn" onclick='openUserModal(<?php echo json_encode($u, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>)' title="Изменить">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <?php if ((int) $u['id'] !== $currentId): ?>
                                            <button type="button" class="action-btn delete" onclick="deleteUser(<?php echo (int) $u['id']; ?>, <?php echo json_encode($u['username']); ?>)" title="Удалить">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <div class="modal" id="userModal">
            <div class="modal-content">
                <h2 id="userModalTitle"><i class="fas fa-user"></i> Пользователь</h2>
                <form id="userForm">
                    <input type="hidden" name="id" id="user_id" value="">
                    <div class="form-group">
                        <label>Логин</label>
                        <input type="text" name="username" id="user_username" required pattern="[a-zA-Z0-9_\-\.]{3,64}" placeholder="латиница, цифры, _ - ." autocomplete="off">
                    </div>
                    <div class="form-group">
                        <label>Пароль</label>
                        <input type="password" name="password" id="user_password" value="" autocomplete="new-password" placeholder="">
                        <small id="user_password_hint" style="color:#9aa7b2;display:block;margin-top:6px;">Минимум 8 символов. Для редактирования оставьте пустым, чтобы не менять.</small>
                    </div>
                    <div class="form-group">
                        <label>Роль</label>
                        <div class="custom-select">
                            <select name="role" id="user_role">
                                <option value="user">Пользователь</option>
                                <option value="admin">Администратор</option>
                            </select>
                        </div>
                    </div>
                    <div style="display: flex; gap: 10px; margin-top: 20px;">
                        <button type="submit" class="btn btn-primary" style="flex: 1;">
                            <i class="fas fa-save"></i> Сохранить
                        </button>
                        <button type="button" class="btn" onclick="closeUserModal()">
                            <i class="fas fa-times"></i> Отмена
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php include __DIR__ . '/includes/footer.php'; ?>
    <?php if (!isset($db_error)): ?>
        <script>
            window.__adminCurrentUserId = <?php echo (int) $currentId; ?>;
        </script>
        <script src="/admin/js/users.js?v=<?php echo htmlspecialchars($appVersion); ?>"></script>
    <?php endif; ?>
</body>
</html>
