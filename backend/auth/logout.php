<?php
/**
 * EVORA HRMS — User Logout Endpoint
 * Core PHP 8.2
 * 
 * Flushes $_SESSION, clears browser session cookie, and destroys server session state.
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
        time() - 42000,
        $params['path'] ?? '/',
        $params['domain'] ?? '',
        (bool)($params['secure'] ?? false),
        (bool)($params['httponly'] ?? true)
    );
}

// Destroy active session
if (session_status() === PHP_SESSION_ACTIVE) {
    session_destroy();
}

jsonResponse(true, 'Signed out successfully.', [
    'redirect' => 'pages/login.html'
], 200);
