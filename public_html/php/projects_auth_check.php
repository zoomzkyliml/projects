<?php

declare(strict_types=1);

require_once __DIR__ . '/projects_auth_common.php';

projects_auth_json([
    'success' => true,
    'loggedIn' => projects_auth_is_logged_in(),
]);
