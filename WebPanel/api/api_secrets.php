<?php
declare(strict_types=1);

/**
 * Legacy: той жа ключ, што totus_effective_public_api_key().
 *
 * @return array{public_api_key: string}
 */
require_once __DIR__ . '/totus_repo_public_key.php';

return [
    'public_api_key' => totus_effective_public_api_key(),
];
