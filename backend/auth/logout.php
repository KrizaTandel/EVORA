<?php
/**
 * EVORA HRMS — User Logout Endpoint
 * Core PHP 8.2
 * 
 * Flushes $_SESSION, clears browser session cookie, and destroys server session state.
 * Supports both JSON API requests and direct browser visits.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';

initSession();

// Wipe all session variables
$_SESSION = [];

// Invalidate session cookie in client browser
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        [
            'expires'  => time() - 42000,
            'path'     => $params['path'] ?? '/',
            'domain'   => $params['domain'] ?? '',
            'secure'   => (bool)($params['secure'] ?? false),
            'httponly' => (bool)($params['httponly'] ?? true),
            'samesite' => $params['samesite'] ?? 'Lax',
        ]
    );
}

// Destroy active session
if (session_status() === PHP_SESSION_ACTIVE) {
    session_destroy();
}

// If direct browser navigation (HTML request)
$acceptHeader = $_SERVER['HTTP_ACCEPT'] ?? '';
if ($_SERVER['REQUEST_METHOD'] === 'GET' && str_contains($acceptHeader, 'text/html')) {
    header('Location: ../../frontend/pages/login.html');
    exit;
}

jsonResponse(true, 'Signed out successfully.', [
    'redirect' => 'pages/login.html'
], 200);
