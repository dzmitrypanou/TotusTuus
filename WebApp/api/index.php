<?php
declare(strict_types=1);

const TOTUS_PROXY_BUILTIN_PUBLIC_KEY = '1dfd6eaa86797feb6ac4989b9cd705432e81766f27a19730f67240c8360961fa';

const TOTUS_PROXY_FALLBACK_UPSTREAM_BASE = 'https://api.kasciolhomiel.by/api';

const TOTUS_PROXY_ALLOWED = [
    'prayers.php',
    'prayer_category_meta.php',
    'songbook.php',
    'kantaral.php',
    'liturgy_calendar_month.php',
    'liturgy_day.php',
    'solemnities.php',
    'ordo_missae.php',
    'ordo_missae_version.php',
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

    $loaded = require $secretsPath;
    $secrets = is_array($loaded) ? $loaded : null;
}

$key = getenv('TOTUS_PUBLIC_API_KEY');
$key = is_string($key) && trim($key) !== '' ? trim($key) : '';
if ($key === '' && $secrets !== null && isset($secrets['public_api_key']) && is_string($secrets['public_api_key'])) {
    $fromSecrets = trim($secrets['public_api_key']);
    if ($fromSecrets !== '') {
        $key = $fromSecrets;
    }
}
if ($key === '') {
    $loaderCandidates = [
        dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'WebPanel' . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'totus_repo_public_key.php',
        dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'WebPanel' . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'totus_repo_public_key.php',
    ];
    foreach ($loaderCandidates as $totusKeyLoader) {
        if (!is_file($totusKeyLoader)) {
            continue;
        }
        require_once $totusKeyLoader;
        if (function_exists('totus_effective_public_api_key')) {
            $key = totus_effective_public_api_key();
        }
        if ($key !== '') {
            break;
        }
    }
}
if ($key === '') {
    $props = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'totus-app-version.properties';
    if (is_readable($props)) {
        foreach (@file($props, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $line = trim((string)$line);
            if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) {
                continue;
            }
            [$pk, $pv] = explode('=', $line, 2);
            if (trim($pk) === 'publicApiKey' && trim($pv) !== '') {
                $key = trim($pv);
                break;
            }
        }
    }
}
if ($key === '') {
    $key = TOTUS_PROXY_BUILTIN_PUBLIC_KEY;
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
    $upstream = rtrim(TOTUS_PROXY_FALLBACK_UPSTREAM_BASE, '/');
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
if (($route === 'ordo_missae.php' || $route === 'ordo_missae_version.php') && $status === 200) {
    header('Cache-Control: private, max-age=0, must-revalidate');
}
http_response_code($status);
echo $body;
