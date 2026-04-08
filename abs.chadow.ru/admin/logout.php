<?php
require_once __DIR__ . '/includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['logout'])) {
    header('Location: /admin/dashboard');
    exit();
}
if (!admin_csrf_verify()) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Доступ запрещён';
    exit();
}

admin_logout();
header('Location: /admin/login');
exit();
