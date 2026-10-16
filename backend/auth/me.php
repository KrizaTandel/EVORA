<?php
/**
 * EVORA HRMS — Current User Session Identity Endpoint
 * Core PHP 8.2
 * 
 * Returns currently authenticated user profile and active CSRF token.
 * Returns 401 Unauthorized if no active session exists or status is no longer approved.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';

initSession();

if (!isAuthenticated()) {
    jsonResponse(false, 'No active authenticated session.', [
        'authenticated' => false
    ], 401);
}

try {
    $pdo = Database::getConnection();

    // Query fresh user details from DB to reflect live changes
    $stmt = $pdo->prepare('
        SELECT u.id, u.full_name, u.email, u.role, u.status, u.department_id, u.designation, u.phone, u.joined_date, d.name AS department_name
        FROM users u
        LEFT JOIN departments d ON u.department_id = d.id
        WHERE u.id = :id
        LIMIT 1
    ');
    $stmt->execute([':id' => (int)$_SESSION['user_id']]);
    $user = $stmt->fetch();

    if (!$user || $user['status'] !== 'approved') {
        // If user was revoked or deleted while logged in, destroy session immediately
        $_SESSION = [];
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
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        jsonResponse(false, 'Your session has expired or your account status changed.', [
            'authenticated' => false
        ], 401);
    }

    jsonResponse(true, 'Active session verified.', [
        'authenticated' => true,
        'user' => [
            'id'              => (int)$user['id'],
            'name'            => $user['full_name'],
            'email'           => $user['email'],
            'role'            => $user['role'],
            'status'          => $user['status'],
            'department_id'   => $user['department_id'] ? (int)$user['department_id'] : null,
            'department_name' => $user['department_name'] ?? 'General',
            'designation'     => $user['designation'] ?? ($user['role'] === 'admin' ? 'Administrator' : 'Staff Member'),
            'phone'           => $user['phone'] ?? '',
            'joined_date'     => $user['joined_date'] ?? '',
        ],
        'csrf_token' => getCsrfToken(),
    ], 200);

} catch (PDOException $e) {
    error_log('[EVORA Me DB Error] ' . $e->getMessage());
    jsonResponse(false, 'Internal error verifying session.', [], 500);
}
