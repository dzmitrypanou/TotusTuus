<?php
declare(strict_types=1);

/**
 * Проксі да WebPanel/api: ключ X-Totus-Api-Key толькі на серверы.
 *
 * Канфігурацыя (першая непустая):
 * 1) TOTUS_PUBLIC_API_KEY, TOTUS_UPSTREAM_API_BASE (URL …/api без слеша ў канцы)
 * 2) proxy-secrets.php (шаблон: proxy-secrets.example.php)
 * 3) аўта: SCRIPT_NAME змяшчае /WebApp/api/index.php → upstream http(s)://host/…/WebPanel/api
 */

const TOTUS_PROXY_ALLOWED = [
    'prayers.php',
    'prayer_category_meta.php',
    'songbook.php',
    'liturgy_calendar_month.php',
    'liturgy_day.php',
];

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex, nofollow');

$method = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ($method !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'method_not_allowed', 'message' => 'Only GET is allowed.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$route = (string)($_GET['totus_route'] ?? '');
if ($route === '' || !in_array($route, TOTUS_PROXY_ALLOWED, true)) {
    http_response_code(404);
    echo json_encode(['error' => 'not_found', 'message' => 'Unknown API route.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$secretsPath = __DIR__ . '/proxy-secrets.php';
$secrets = null;
if (is_file($secretsPath)) {
    /** @var mixed $loaded */
    $loaded = require $secretsPath;
    $secrets = is_array($loaded) ? $loaded : null;
}

$key = getenv('TOTUS_PUBLIC_API_KEY');
$key = is_string($key) && $key !== '' ? $key : '';
if ($key === '' && $secrets !== null && isset($secrets['public_api_key']) && is_string($secrets['public_api_key'])) {
    $key = $secrets['public_api_key'];
}

if ($key === '') {
    http_response_code(503);
    echo json_encode(
        [
            'error' => 'proxy_not_configured',
            'message' => 'Задайце TOTUS_PUBLIC_API_KEY або WebApp/api/proxy-secrets.php (public_api_key).',
        ],
        JSON_UNESCAPED_UNICODE
    );
    exit;
}

$upstream = getenv('TOTUS_UPSTREAM_API_BASE');
$upstream = is_string($upstream) && $upstream !== '' ? rtrim($upstream, '/') : '';

if ($upstream === '' && $secrets !== null && isset($secrets['upstream_api_base']) && is_string($secrets['upstream_api_base']) && $secrets['upstream_api_base'] !== '') {
    $upstream = rtrim($secrets['upstream_api_base'], '/');
}

if ($upstream === '') {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
    $script = (string)($_SERVER['SCRIPT_NAME'] ?? '');
    if (preg_match('#^(.*)/WebApp/api/index\.php$#', $script, $m)) {
        $upstream = $scheme . '://' . $host . $m[1] . '/WebPanel/api';
    }
}

if ($upstream === '') {
    http_response_code(503);
    echo json_encode(
        [
            'error' => 'upstream_not_configured',
            'message' => 'Задайце TOTUS_UPSTREAM_API_BASE або upstream_api_base у proxy-secrets.php, або размясцуйце WebApp і WebPanel як у праекце (/WebApp/api → /WebPanel/api).',
        ],
        JSON_UNESCAPED_UNICODE
    );
    exit;
}

$params = $_GET;
unset($params['totus_route']);
$qs = http_build_query($params);
$target = $upstream . '/' . $route . ($qs !== '' ? '?' . $qs : '');

$headers = [
    'X-Totus-Api-Key: ' . $key,
    'Accept: application/json',
];

$body = '';
$status = 502;

if (function_exists('curl_init')) {
    $ch = curl_init($target);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_TIMEOUT, 120);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    $body = (string)curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($body === '' && $status === 0) {
        $status = 502;
        $body = json_encode(
            ['error' => 'upstream_unreachable', 'message' => 'Не ўдалося звязацца з upstream API.'],
            JSON_UNESCAPED_UNICODE
        );
    }
} else {
    $ctx = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => implode("\r\n", $headers) . "\r\n",
            'timeout' => 120,
            'ignore_errors' => true,
        ],
    ]);
    $body = (string)@file_get_contents($target, false, $ctx);
    if (isset($http_response_header[0]) && preg_match('#\s(\d{3})\s#', $http_response_header[0], $mm)) {
        $status = (int)$mm[1];
    } elseif ($body !== '') {
        $status = 200;
    }
}

if ($status < 100) {
    $status = 502;
}
http_response_code($status);
echo $body;
