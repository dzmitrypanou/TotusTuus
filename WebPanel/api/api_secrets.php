<?php
declare(strict_types=1);

require_once __DIR__ . '/totus_repo_public_key.php';

return [
    'public_api_key' => totus_effective_public_api_key(),
];
