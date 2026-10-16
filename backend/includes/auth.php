<?php
/**
 * EVORA HRMS — Core Authentication & Session Security Helper
 * Core PHP 8.2
 * 
 * Provides hardened session lifecycle management, role-based access control,
 * CSRF token generation & verification, and secure API JSON responses.
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

    // If SESSION_SECURE is explicitly specified in .env, honor it only when valid
    if (isset($env['SESSION_SECURE']) && $env['SESSION_SECURE'] !== '') {
        $secureEnv = filter_var($env['SESSION_SECURE'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($secureEnv !== null) {
            $isHttps = $secureEnv ? $isHttps : false;
        }
    }

    // Apply strict session cookie configuration if headers not already sent
    if (!headers_sent()) {
        session_set_cookie_params([
            'lifetime' => $lifetime,
            'path'     => '/',
            'domain'   => '',
            'secure'   => $isHttps,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.cookie_httponly', '1');
    }

    if (session_status() !== PHP_SESSION_ACTIVE) {
        @session_start();
    }
}

/**
 * Regenerate session ID safely on privilege changes (mitigate session fixation)
 */
function regenerateSession(): void
{
    if (session_status() === PHP_SESSION_ACTIVE && !headers_sent()) {
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
 * Verify CSRF token from either HTTP header or request body
 */
function verifyCsrfRequest(): bool
{
    initSession();
    $headerToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
    $body = getRequestBody();
    $bodyToken = $body['csrf_token'] ?? null;
    $token = $headerToken ?? $bodyToken;
    return validateCsrfToken($token);
}

/**
 * Require valid CSRF token on state-changing requests
 */
function requireCsrf(): void
{
    if (!verifyCsrfRequest()) {
        jsonResponse(false, 'Invalid or missing CSRF token. Request rejected for security.', [], 403);
    }
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
        'id'              => (int)$_SESSION['user_id'],
        'name'            => $_SESSION['full_name'] ?? '',
        'email'           => $_SESSION['email'] ?? '',
        'role'            => $_SESSION['role'] ?? '',
        'department_id'   => isset($_SESSION['department_id']) ? (int)$_SESSION['department_id'] : null,
        'department_name' => $_SESSION['department_name'] ?? 'General',
        'designation'     => $_SESSION['designation'] ?? null,
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
    }

    return $user;
}

/**
 * Require Administrator role ('admin')
 */
function requireAdmin(): array
{
    return requireRole('admin');
}

/**
 * Require Employee role ('employee')
 */
function requireEmployee(): array
{
    return requireRole('employee');
}

/**
 * Standardized JSON response helper with appropriate HTTP status codes
 */
function jsonResponse(bool $success, string $message, array $data = [], int $httpCode = 200): void
{
    // Clean buffer to prevent accidental output corruption
    while (ob_get_level() > 0) {
        ob_end_clean();
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
        if (empty($raw)) {
            return [];
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    return $_POST;
}
