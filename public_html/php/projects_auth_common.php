<?php

declare(strict_types=1);

/**
 * Gemensam autentiseringsmodul för Projects.
 *
 * Ansvar:
 * - Läsa auth-konfiguration.
 * - Skapa signerad cookie.
 * - Validera signerad cookie.
 * - Ta bort auth-cookie.
 * - Returnera JSON-svar.
 */

function projects_auth_get_config(): array
{
    static $config = null;

    if ($config !== null) {
        return $config;
    }

    $configPath = __DIR__ . '/../../secure/projects_auth_config.php';

    if (!is_file($configPath)) {
        throw new RuntimeException(
            'Auth-konfigurationen saknas: ' . $configPath
        );
    }

    $loaded = require $configPath;

    if (
        !is_array($loaded)
        || !isset($loaded['auth'])
        || !is_array($loaded['auth'])
    ) {
        throw new RuntimeException(
            'Ogiltig struktur i projects_auth_config.php.'
        );
    }

    $config = $loaded['auth'];

    $requiredKeys = [
        'password',
        'cookie_name',
        'cookie_secret',
        'cookie_lifetime',
    ];

    foreach ($requiredKeys as $key) {
        if (!array_key_exists($key, $config)) {
            throw new RuntimeException(
                'Auth-konfigurationen saknar nyckeln: ' . $key
            );
        }
    }

    if (!is_string($config['password']) || $config['password'] === '') {
        throw new RuntimeException(
            'Auth-lösenordet får inte vara tomt.'
        );
    }

    if (
        !is_string($config['cookie_name'])
        || $config['cookie_name'] === ''
    ) {
        throw new RuntimeException(
            'cookie_name får inte vara tomt.'
        );
    }

    if (
        !is_string($config['cookie_secret'])
        || strlen($config['cookie_secret']) < 32
    ) {
        throw new RuntimeException(
            'cookie_secret måste vara minst 32 tecken.'
        );
    }

    if ((int)$config['cookie_lifetime'] <= 0) {
        throw new RuntimeException(
            'cookie_lifetime måste vara större än 0.'
        );
    }

    return $config;
}

function projects_auth_base64url_encode(string $value): string
{
    return rtrim(
        strtr(base64_encode($value), '+/', '-_'),
        '='
    );
}

function projects_auth_base64url_decode(string $value)
{
    $padding = strlen($value) % 4;

    if ($padding !== 0) {
        $value .= str_repeat('=', 4 - $padding);
    }

    return base64_decode(
        strtr($value, '-_', '+/'),
        true
    );
}

function projects_auth_create_cookie_value(int $expires): string
{
    $config = projects_auth_get_config();

    $payloadData = [
        'v' => 1,
        'loggedIn' => true,
        'expires' => $expires,
        'nonce' => bin2hex(random_bytes(16)),
    ];

    $json = json_encode(
        $payloadData,
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
    );

    if ($json === false) {
        throw new RuntimeException(
            'Kunde inte skapa auth-payload.'
        );
    }

    $payload = projects_auth_base64url_encode($json);

    $signature = hash_hmac(
        'sha256',
        $payload,
        (string)$config['cookie_secret']
    );

    return $payload . '.' . $signature;
}

function projects_auth_validate_cookie_value(
    string $cookieValue
): bool {
    $config = projects_auth_get_config();

    $parts = explode('.', $cookieValue, 2);

    if (count($parts) !== 2) {
        return false;
    }

    [$payload, $signature] = $parts;

    if ($payload === '' || $signature === '') {
        return false;
    }

    $expectedSignature = hash_hmac(
        'sha256',
        $payload,
        (string)$config['cookie_secret']
    );

    if (!hash_equals($expectedSignature, $signature)) {
        return false;
    }

    $decoded = projects_auth_base64url_decode($payload);

    if ($decoded === false) {
        return false;
    }

    $data = json_decode($decoded, true);

    if (!is_array($data)) {
        return false;
    }

    if (($data['v'] ?? null) !== 1) {
        return false;
    }

    if (($data['loggedIn'] ?? false) !== true) {
        return false;
    }

    if (!isset($data['expires'])) {
        return false;
    }

    return time() < (int)$data['expires'];
}

function projects_auth_is_logged_in(): bool
{
    try {
        $config = projects_auth_get_config();

        $cookieName = (string)$config['cookie_name'];
        $cookieValue = $_COOKIE[$cookieName] ?? '';

        if (!is_string($cookieValue) || $cookieValue === '') {
            return false;
        }

        return projects_auth_validate_cookie_value(
            $cookieValue
        );
    } catch (Throwable $e) {
        error_log(
            'Projects auth validation error: '
            . $e->getMessage()
        );

        return false;
    }
}

function projects_auth_cookie_options(int $expires): array
{
    $config = projects_auth_get_config();

    return [
        'expires' => $expires,
        'path' => '/',
        'secure' => (bool)($config['cookie_secure'] ?? false),
        'httponly' => true,
        'samesite' => (string)($config['cookie_samesite'] ?? 'Lax'),
    ];
}

function projects_auth_set_cookie(): void
{
    $config = projects_auth_get_config();

    $expires = time()
        + (int)$config['cookie_lifetime'];

    $cookieValue = projects_auth_create_cookie_value(
        $expires
    );

    $result = setcookie(
        (string)$config['cookie_name'],
        $cookieValue,
        projects_auth_cookie_options($expires)
    );

    if ($result !== true) {
        throw new RuntimeException(
            'Kunde inte sätta auth-cookie.'
        );
    }

    $_COOKIE[(string)$config['cookie_name']] = $cookieValue;
}

function projects_auth_clear_cookie(): void
{
    $config = projects_auth_get_config();

    $cookieName = (string)$config['cookie_name'];

    setcookie(
        $cookieName,
        '',
        projects_auth_cookie_options(
            time() - 3600
        )
    );

    unset($_COOKIE[$cookieName]);
}

function projects_auth_json(
    array $data,
    int $status = 200
): void {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    http_response_code($status);

    header(
        'Content-Type: application/json; charset=utf-8'
    );

    header('Cache-Control: no-store, private');
    header('Pragma: no-cache');

    echo json_encode(
        $data,
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
    );

    exit;
}
