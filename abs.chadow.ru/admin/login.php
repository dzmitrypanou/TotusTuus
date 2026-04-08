<?php
require_once __DIR__ . '/includes/bootstrap.php';

$_versionRaw = @file_get_contents(__DIR__ . '/../config/version.json');
$_versionData = $_versionRaw ? json_decode($_versionRaw, true) : null;
$appVersion = (is_array($_versionData) && !empty($_versionData['version'])) ? $_versionData['version'] : '3.4.4';

if (admin_is_logged_in()) {
    header('Location: /admin/dashboard');
    exit();
}

$login_error = null;
$loginFailureCounted = false;
$returnUrl = isset($_GET['return']) ? (string) $_GET['return'] : '/admin/dashboard';
if ($returnUrl === '' || strpos($returnUrl, '/') !== 0 || strpos($returnUrl, '//') !== false) {
    $returnUrl = '/admin/dashboard';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    $blockedFor = admin_login_throttle_retry_after_seconds($db);
    if ($blockedFor !== null && $blockedFor > 0) {
        $mins = max(1, (int) ceil($blockedFor / 60));
        $login_error = 'Слишком много неудачных попыток. Повторите через ' . $mins . ' мин.';
    } elseif (!admin_csrf_verify()) {
        $login_error = 'Сессия устарела. Обновите страницу и попробуйте снова.';
    } else {
        $username = $_POST['username'] ?? '';
        $password = $_POST['password'] ?? '';
        $rememberMe = isset($_POST['remember_me']) && $_POST['remember_me'] === '1';
        if (admin_attempt_login($db, $username, $password, $rememberMe)) {
            header('Location: ' . $returnUrl);
            exit();
        }
        $login_error = 'Неверный логин или пароль';
        if (trim((string) $username) !== '' && $password !== '') {
            $loginFailureCounted = true;
            admin_login_throttle_register_failure($db);
            $blockedAfter = admin_login_throttle_retry_after_seconds($db);
            if ($blockedAfter !== null && $blockedAfter > 0) {
                $mins = max(1, (int) ceil($blockedAfter / 60));
                $login_error = 'Слишком много неудачных попыток. Повторите через ' . $mins . ' мин.';
            }
        }
    }
}

$loginThrottleRetry = admin_login_throttle_retry_after_seconds($db);
$loginFormLocked = $loginThrottleRetry !== null && $loginThrottleRetry > 0;
$attemptsRemaining = admin_login_throttle_attempts_remaining($db);

if ($loginFailureCounted && $login_error === 'Неверный логин или пароль') {
    $login_error = 'Неверный логин или пароль. Осталось попыток до блокировки: ' . $attemptsRemaining . '.';
}

$loginToastError = $login_error;
if ($loginToastError === null && $loginFormLocked) {
    $m = max(1, (int) ceil($loginThrottleRetry / 60));
    $loginToastError = 'Слишком много неудачных попыток. Повторите через ' . $m . ' мин.';
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Вход | Админ-панель</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:ital,opsz,wght@0,14..32,100..900;1,14..32,100..900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="stylesheet" href="/admin/css/admin.css?v=<?php echo htmlspecialchars($appVersion); ?>">
    <?php require __DIR__ . '/includes/csrf_head.php'; ?>
</head>
<body class="login-page">
    <div class="container">
        <div class="header">
            <h1>
                <i class="fas fa-lock" style="color: #ffd966;"></i>
                Вход в админ-панель
            </h1>
        </div>
        <div class="login-page__main">
        <div class="login-form">
            <?php if ($loginFormLocked): ?>
                <?php $lockM = max(1, (int) ceil($loginThrottleRetry / 60)); ?>
                <p class="login-attempts-hint login-attempts-hint--locked">Вход временно заблокирован. Повторите через <?php echo (int) $lockM; ?> мин.</p>
            <?php endif; ?>
            <form method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(admin_csrf_token()); ?>">
                <input type="hidden" name="return" value="<?php echo htmlspecialchars($returnUrl); ?>">
                <div class="form-group">
                    <label>Логин</label>
                    <input type="text" name="username" required autocomplete="username" placeholder="Логин" value=""<?php echo $loginFormLocked ? ' disabled' : ''; ?>>
                </div>
                <div class="form-group">
                    <label>Пароль</label>
                    <input type="password" name="password" required autocomplete="current-password" placeholder="Пароль"<?php echo $loginFormLocked ? ' disabled' : ''; ?>>
                </div>
                <div class="login-remember-row">
                    <label class="login-remember-switch" for="remember_me">
                        <input type="checkbox" name="remember_me" value="1" id="remember_me"<?php echo $loginFormLocked ? ' disabled' : ''; ?>>
                        <span class="login-remember-slider" aria-hidden="true"></span>
                        <span class="login-remember-text">Запомнить меня</span>
                    </label>
                </div>
                <button type="submit" name="login" class="btn-primary"<?php echo $loginFormLocked ? ' disabled' : ''; ?>>
                    <i class="fas fa-sign-in-alt"></i> Войти
                </button>
            </form>
            <p class="login-back-wrap">
                <a href="/" class="btn login-back-btn">
                    <i class="fas fa-arrow-left" aria-hidden="true"></i>
                    <span>Вернуться на сайт</span>
                </a>
            </p>
        </div>
        </div>
    </div>
    <script>
    window.__loginToastError = <?php echo json_encode($loginToastError, JSON_UNESCAPED_UNICODE); ?>;
    </script>
    <script src="/admin/js/login.js?v=<?php echo htmlspecialchars($appVersion); ?>"></script>
    <?php include __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
