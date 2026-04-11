<?php
declare(strict_types=1);

/**
 * Публічны ключ API: totus-app-version.properties (publicApiKey=…) у некалькіх магчымых месцах,
 * затым TOTUS_PUBLIC_API_KEY, затым убудаваны рэзерв (дэплой толькі каталога api/ без караня рэпа).
 */

/** Супадае з publicApiKey у totus-app-version.properties; зменіце разам з файлом. */
const TOTUS_BUILTIN_PUBLIC_API_KEY = '1dfd6eaa86797feb6ac4989b9cd705432e81766f27a19730f67240c8360961fa';

function totus_read_public_api_key_from_properties_file(string $path): string
{
    if (!is_readable($path)) {
        return '';
    }
    $lines = @file($path, FILE_IGNORE_NEW_LINES) ?: [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        if (strpos($line, '=') === false) {
            continue;
        }
        [$k, $v] = explode('=', $line, 2);
        if (trim($k) !== 'publicApiKey') {
            continue;
        }
        $t = trim($v);
        if ($t !== '') {
            return $t;
        }
        break;
    }

    return '';
}

/**
 * Толькі з .properties (без env і без убудаванага ключа).
 * Шляхі: карань рэпа (monorepo), каталог WebPanel, каталог api (плоскі дэплой).
 */
function totus_repo_root_public_api_key(): string
{
    static $done = false;
    static $cached = '';
    if ($done) {
        return $cached;
    }
    $done = true;
    $candidates = [
        dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'totus-app-version.properties',
        dirname(__DIR__) . DIRECTORY_SEPARATOR . 'totus-app-version.properties',
        __DIR__ . DIRECTORY_SEPARATOR . 'totus-app-version.properties',
    ];
    foreach ($candidates as $path) {
        $k = totus_read_public_api_key_from_properties_file($path);
        if ($k !== '') {
            $cached = $k;

            return $cached;
        }
    }

    return $cached;
}

/**
 * Парадак: зменная асяроддзя → .properties → убудаваны ключ (сервер без файла з рэпа).
 */
function totus_effective_public_api_key(): string
{
    static $memo = null;
    if ($memo !== null) {
        return $memo;
    }
    $env = getenv('TOTUS_PUBLIC_API_KEY');
    if (is_string($env) && trim($env) !== '') {
        $memo = trim($env);

        return $memo;
    }
    $fromFile = totus_repo_root_public_api_key();
    if ($fromFile !== '') {
        $memo = $fromFile;

        return $memo;
    }
    $memo = TOTUS_BUILTIN_PUBLIC_API_KEY;

    return $memo;
}
