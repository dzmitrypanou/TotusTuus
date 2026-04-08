<?php
/**
 * Общий фрагмент <head> и открытие <body> для страниц админки.
 * Задайте: $pageTitle, опционально $appVersion, $bodyClass, $extraHead (HTML после admin.css).
 */
if (!isset($appVersion)) {
    $_avRaw = @file_get_contents(__DIR__ . '/../../config/version.json');
    $_avData = $_avRaw ? json_decode($_avRaw, true) : null;
    $appVersion = (is_array($_avData) && !empty($_avData['version'])) ? $_avData['version'] : '3.4.4';
}
$pageTitle = isset($pageTitle) ? $pageTitle : 'Админка';
$bodyClass = isset($bodyClass) ? trim((string) $bodyClass) : '';
$extraHead = isset($extraHead) ? (string) $extraHead : '';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle); ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:ital,opsz,wght@0,14..32,100..900;1,14..32,100..900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="stylesheet" href="/admin/css/admin.css?v=<?php echo htmlspecialchars($appVersion); ?>">
    <?php require __DIR__ . '/csrf_head.php'; ?>
    <?php echo $extraHead; ?>
</head>
<body<?php echo $bodyClass !== '' ? ' class="' . htmlspecialchars($bodyClass) . '"' : ''; ?>>
