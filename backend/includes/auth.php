<?php
/**
 * EVORA HRMS — Core Authentication & Session Security Helper
 * Core PHP 8.2
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/database.php';

/**
 * Configure and start a hardened PHP session
 */
function initSession(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $env = Database::loadEnv();
    $lifetime = (int)($env['SESSION_LIFETIME'] ?? 7200);

    // Determine if HTTPS is active
    $isHttps = (
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
        (!empty($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443) ||
        (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
    );

    // Apply strict session cookie configuration
    session_set_cookie_params([
        'lifetime' => $lifetime,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    // Additional PHP INI security hardening
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');

    session_start();
}

/**
 * Regenerate session ID safely on privilege changes
 */
function regenerateSession(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }
}

/**
 * Get or generate a CSRF protection token for the active session
 */
function getCsrfToken(): string
{
    initSession();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Validate a submitted CSRF token
 */
function validateCsrfToken(?string $token): bool
{
    initSession();
    if (empty($token) || empty($_SESSION['csrf_token'])) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Check if current visitor has an authenticated session
 */
function isAuthenticated(): bool
{
    initSession();
    return !empty($_SESSION['user_id']) && !empty($_SESSION['role']);
}

/**
 * Return current session identity or null
 */
function getCurrentUser(): ?array
{
    if (!isAuthenticated()) {
        return null;
    }

    return [
        'id'            => (int)$_SESSION['user_id'],
        'name'          => $_SESSION['full_name'] ?? '',
        'email'         => $_SESSION['email'] ?? '',
        'role'          => $_SESSION['role'] ?? '',
        'department_id' => isset($_SESSION['department_id']) ? (int)$_SESSION['department_id'] : null,
        'designation'   => $_SESSION['designation'] ?? null,
    ];
}

/**
 * Require authenticated session; responds with 401 JSON if unauthorized
 */
function requireAuth(): array
{
    initSession();
    $user = getCurrentUser();

    if ($user === null) {
        jsonResponse(false, 'Authentication required to access this resource.', [], 401);
        exit;
    }

    return $user;
}

/**
 * Require specific role (e.g. 'admin' or 'employee')
 * 
 * @param string|array $allowedRoles
 * @return array Current user identity
 */
function requireRole(string|array $allowedRoles): array
{
    $user = requireAuth();
    $roles = is_array($allowedRoles) ? $allowedRoles : [$allowedRoles];

    if (!in_array($user['role'], $roles, true)) {
        jsonResponse(false, 'Access forbidden: Insufficient privileges for this operation.', [], 403);
        exit;
    }

    return $user;
}

/**
 * Standardized JSON response helper with appropriate HTTP status codes
 */
function jsonResponse(bool $success, string $message, array $data = [], int $httpCode = 200): void
{
    // Clean buffer to prevent accidental output corruption
    if (ob_get_length()) {
        ob_clean();
    }

    http_response_code($httpCode);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

    $payload = [
        'success' => $success,
        'message' => $message,
    ];

    if (!empty($data)) {
        $payload = array_merge($payload, $data);
    }

    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Parse JSON or form POST request body safely
 */
function getRequestBody(): array
{
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (str_contains($contentType, 'application/json')) {
        $raw = file_get_contents('php://input');
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    return $_POST;
}
