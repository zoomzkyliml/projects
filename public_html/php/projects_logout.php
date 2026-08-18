<?php

declare(strict_types=1);

require_once __DIR__ . '/projects_auth_common.php';

projects_auth_clear_cookie();

projects_auth_json([
    'success' => true,
    'loggedIn' => false,
]);
