<?php
declare(strict_types=1);

/**
 * Версія кліента Totus Tuus — тое ж, што ў totus-app-version.properties у карані рэпазіторыя (Android + вэб).
 */
function panel_totus_app_version(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $path = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'totus-app-version.properties';
    $name = '0';
    $code = 0;
    if (is_readable($path)) {
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
            $k = trim($k);
            $v = trim($v);
            if ($k === 'versionName' && $v !== '') {
                $name = $v;
            } elseif ($k === 'versionCode' && $v !== '') {
                $code = (int)$v;
            }
        }
    }
    $cache = ['name' => $name, 'code' => $code];

    return $cache;
}

function panel_totus_app_version_name(): string
{
    return panel_totus_app_version()['name'];
}
