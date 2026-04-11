<?php
declare(strict_types=1);

/**
 * Адзіны ключ для публічных GET API (прыкладанне).
 * Крыніцы: глядзі totus_effective_public_api_key() у totus_repo_public_key.php
 * (env → .properties у некалькіх месцах → убудаваны рэзерв для дэплою без караня рэпа).
 *
 * Загаловак запыту: X-Totus-Api-Key
 */

const TOTUS_API_KEY_HEADER = 'HTTP_X_TOTUS_API_KEY';

require_once __DIR__ . '/totus_repo_public_key.php';

function totus_public_api_key(): string
{
    return totus_effective_public_api_key();
}

/** Агульныя загалоўкі бяспекі для JSON API (OWASP / baseline). */
function api_public_security_headers(): void
{
    if (headers_sent()) {
        return;
    }
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()');
    header('Cross-Origin-Resource-Policy: cross-origin');
}

function api_public_cors_preflight_headers(): void
{
    api_public_security_headers();
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, OPTIONS');
    header('Access-Control-Allow-Headers: X-Totus-Api-Key, Content-Type');
    header('Access-Control-Max-Age: 86400');
}

function api_public_guard_json_error(int $httpCode, string $errorCode, string $message): void
{
    if (!headers_sent()) {
        api_public_security_headers();
        header('Content-Type: application/json; charset=utf-8');
        header('Access-Control-Allow-Origin: *');
    }
    http_response_code($httpCode);
    echo json_encode(
        ['error' => $errorCode, 'message' => $message],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}

/** Выклікаць у публічных API да db.php і любых вывадаў. */
function api_public_guard_enforce(): void
{
    $method = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if ($method === 'OPTIONS') {
        api_public_cors_preflight_headers();
        http_response_code(204);
        exit;
    }

    $expected = totus_public_api_key();
    if ($expected === '') {
        api_public_guard_json_error(
            503,
            'api_key_not_configured',
            'На серверы не зададзены публічны ключ API (TOTUS_PUBLIC_API_KEY або publicApiKey у totus-app-version.properties).'
        );
    }

    $provided = (string)($_SERVER[TOTUS_API_KEY_HEADER] ?? '');
    if ($provided === '' || !hash_equals($expected, $provided)) {
        api_public_guard_json_error(
            401,
            'invalid_api_key',
            'Нясапраўдны або адсутнічы ключ API (загаловак X-Totus-Api-Key).'
        );
    }

    api_public_security_headers();
}
