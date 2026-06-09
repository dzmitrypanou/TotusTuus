<?php
declare(strict_types=1);

require_once __DIR__ . '/api_public_guard.php';
api_public_guard_enforce();

require_once dirname(__DIR__) . '/includes/panel_app_version.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$version = panel_totus_app_version();

echo json_encode(
    [
        'version_name' => $version['name'],
        'version_code' => $version['code'],
        'play_store_url' => $version['playStoreUrl'],
        'update_required' => $version['updateRequired'],
        'message' => $version['updateMessage'],
    ],
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
);
