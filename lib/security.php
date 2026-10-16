<?php
$cookieHeader = $_SERVER['HTTP_COOKIE'] ?? '';

$sessionCookieHashes = [];

foreach (explode(';', $cookieHeader) as $cookiePart) {
    $cookiePart = trim($cookiePart);

    if (str_starts_with($cookiePart, 'PHPSESSID=')) {
        $value = substr($cookiePart, strlen('PHPSESSID='));

        $sessionCookieHashes[] = substr(
            hash('sha256', $value),
            0,
            12
        );
    }
}


// Shared security primitives for web entry points.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!function_exists('app_csrf_token')) {
    function app_csrf_token(): string
    {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['csrf_token'];
    }
}

if (!function_exists('app_require_csrf')) {
    function app_require_csrf(): void
    {
        $submitted = (string) ($_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        $stored = (string) ($_SESSION['csrf_token'] ?? '');

        if ($stored === '' || $submitted === '' || !hash_equals($stored, $submitted)) {
            http_response_code(403);
            exit('Invalid CSRF token.');
        }
    }
}

if (!function_exists('app_require_method')) {
    function app_require_method(string ...$allowedMethods): void
    {
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? ''));
        $allowedMethods = array_map('strtoupper', $allowedMethods);

        if (!in_array($method, $allowedMethods, true)) {
            http_response_code(405);
            header('Allow: ' . implode(', ', $allowedMethods));
            exit('HTTP method not allowed.');
        }
    }
}

