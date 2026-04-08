<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

const MAX_FILE_SIZE_BYTES = 10 * 1024 * 1024;
const RETENTION_DAYS = 30;

function cleanupOldReplayFiles($baseDir, $retentionDays) {
    if (!is_dir($baseDir)) {
        return;
    }

    $entries = @scandir($baseDir);
    if (!is_array($entries)) {
        return;
    }

    $threshold = time() - ($retentionDays * 86400);
    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $path = $baseDir . DIRECTORY_SEPARATOR . $entry;
        if (is_dir($path)) {
            cleanupOldReplayFiles($path, $retentionDays);
            $sub = @scandir($path);
            if (is_array($sub) && count($sub) === 2) {
                @rmdir($path);
            }
            continue;
        }

        if (!is_file($path) || !preg_match('/\.mtreplay$/i', $entry)) {
            continue;
        }

        $mtime = @filemtime($path);
        if ($mtime !== false && $mtime < $threshold) {
            @unlink($path);
        }
    }
}

try {
    $data = json_decode(file_get_contents('php://input'), true);

    $consent = false;
    if (isset($data['consent_to_store'])) {
        $consent = filter_var($data['consent_to_store'], FILTER_VALIDATE_BOOLEAN);
    }

    if ($consent !== true) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'error' => 'Сохранение запрещено без согласия'
        ]);
        exit();
    }

    if (!isset($data['file_content']) || !is_string($data['file_content']) || $data['file_content'] === '') {
        throw new Exception('Отсутствует содержимое файла');
    }
    if (!isset($data['player_name']) || !isset($data['map_name']) || !isset($data['date_time'])) {
        throw new Exception('Отсутствуют обязательные поля');
    }

    $fileContent = base64_decode($data['file_content'], true);
    if ($fileContent === false) {
        throw new Exception('Некорректный формат файла');
    }

    if (strlen($fileContent) > MAX_FILE_SIZE_BYTES) {
        throw new Exception('Файл превышает лимит размера');
    }

    $playerName = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $data['player_name']);
    $playerName = trim($playerName, '_');
    if ($playerName === '') {
        $playerName = 'unknown';
    }

    $mapName = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $data['map_name']);
    $mapName = trim($mapName, '_');
    if ($mapName === '') {
        $mapName = 'unknown';
    }

    try {
        $date = new DateTime($data['date_time']);
    } catch (Exception $e) {
        $date = new DateTime();
    }

    $uploadDir = __DIR__ . '/../uploads/' . $playerName . '/';
    // Очистка до mkdir: пустая только что созданная папка игрока удалялась как «пустая» и запись файла ломалась.
    cleanupOldReplayFiles(__DIR__ . '/../uploads/', RETENTION_DAYS);
    if (!file_exists($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    $dateFormatted = $date->format('Y-m-d');
    $timeFormatted = $date->format('H-i-s');
    $newFileName = $playerName . '_' . $mapName . '_' . $dateFormatted . '_' . $timeFormatted . '.mtreplay';
    $filePath = $uploadDir . $newFileName;
    $result = file_put_contents($filePath, $fileContent);

    if ($result === false) {
        throw new Exception('Не удалось сохранить файл');
    }

    echo json_encode([
        'success' => true,
        'file_path' => '/uploads/' . $playerName . '/' . $newFileName,
        'file_name' => $newFileName
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
