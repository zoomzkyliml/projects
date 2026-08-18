<?php

declare(strict_types=1);

require_once __DIR__ . '/projects_auth_common.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    projects_auth_json([
        'success' => false,
        'message' => 'Endast POST tillåts.',
    ], 405);
}

$config = projects_auth_get_config();
$password = $_POST['password'] ?? '';

if (!is_string($password) || $password !== $config['password']) {
    usleep(300000);

    projects_auth_json([
        'success' => false,
        'message' => 'Fel lösenord.',
    ], 401);
}

projects_auth_set_cookie();

projects_auth_json([
    'success' => true,
    'loggedIn' => true,
]);
