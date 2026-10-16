<?php
/**
 * EVORA HRMS — User Authentication & Login Endpoint
 * Core PHP 8.2
 * 
 * Verifies credentials against MySQL `users` table.
 * Enforces approval gating: only status='approved' can establish sessions.
 * Regenerates session ID to prevent session fixation.
 * Defends against timing attacks and credential enumeration.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'Method not allowed. POST required.', [], 405);
}

initSession();

$input = getRequestBody();

$email    = strtolower(trim((string)($input['email'] ?? '')));
$password = (string)($input['password'] ?? '');

if (empty($email) || empty($password)) {
    jsonResponse(false, 'Please provide both work email and password.', [], 422);
}

try {
    $pdo = Database::getConnection();

    // Query user by email with department name
    $stmt = $pdo->prepare('
        SELECT u.id, u.full_name, u.email, u.password_hash, u.role, u.status, u.department_id, u.designation, d.name AS department_name
        FROM users u
        LEFT JOIN departments d ON u.department_id = d.id
        WHERE u.email = :email
        LIMIT 1
    ');
    $stmt->execute([':email' => $email]);
    $user = $stmt->fetch();

    // Mitigate timing attacks by verifying a dummy hash if user is not found
    $dummyHash = '$2y$10$abcdefghijklmnopqrstuuABCDEFGHIJKLMNOPQRSTUVWXYZ012345';
    $hashToCheck = $user ? $user['password_hash'] : $dummyHash;
    $passwordMatches = password_verify($password, $hashToCheck);

    // Constant-time check: reject without leaking whether the email exists
    if (!$user || !$passwordMatches) {
        jsonResponse(false, 'Invalid email or password. Please verify your credentials.', [], 401);
    }

    // Gate authentication on account approval status
    if ($user['status'] === 'pending') {
        jsonResponse(
            false,
            'Your account registration is currently pending administrator verification. You will be able to sign in once an administrator approves your profile.',
            ['status' => 'pending'],
            403
        );
    }

    if ($user['status'] === 'rejected') {
        jsonResponse(
            false,
            'Your registration application has been rejected. Please contact People Operations for assistance.',
            ['status' => 'rejected'],
            403
        );
    }

    if ($user['status'] !== 'approved') {
        jsonResponse(false, 'Your account is inactive or disabled. Please contact your administrator.', [], 403);
    }

    // Upgrade legacy or weak hashes if cost/algorithm updated
    if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
        $newHash = password_hash($password, PASSWORD_DEFAULT);
        $rehashStmt = $pdo->prepare('UPDATE users SET password_hash = :hash WHERE id = :id');
        $rehashStmt->execute([':hash' => $newHash, ':id' => $user['id']]);
    }

    // Establish secure session with ID regeneration
    regenerateSession();

    $_SESSION['user_id']         = (int)$user['id'];
    $_SESSION['full_name']       = $user['full_name'];
    $_SESSION['email']           = $user['email'];
    $_SESSION['role']            = $user['role'];
    $_SESSION['department_id']   = $user['department_id'] ? (int)$user['department_id'] : null;
    $_SESSION['department_name'] = $user['department_name'] ?? 'General';
    $_SESSION['designation']     = $user['designation'] ?? ($user['role'] === 'admin' ? 'Administrator' : 'Staff Member');
    $_SESSION['logged_in_at']    = time();

    // Determine role-based landing redirection relative to frontend/pages/
    $redirect = ($user['role'] === 'admin') ? 'admin/dashboard.html' : 'employee/dashboard.html';

    jsonResponse(true, 'Sign in successful. Redirecting to workspace...', [
        'role'       => $user['role'],
        'redirect'   => $redirect,
        'user'       => [
            'id'              => (int)$user['id'],
            'name'            => $user['full_name'],
            'email'           => $user['email'],
            'role'            => $user['role'],
            'department_id'   => $user['department_id'] ? (int)$user['department_id'] : null,
            'department_name' => $user['department_name'] ?? 'General',
            'designation'     => $_SESSION['designation'],
        ],
        'csrf_token' => getCsrfToken(),
    ], 200);

} catch (PDOException $e) {
    error_log('[EVORA Login DB Error] ' . $e->getMessage());
    jsonResponse(false, 'An internal error occurred during authentication. Please try again.', [], 500);
}
