<?php
/**
 * EVORA HRMS — Employee Registration Endpoint
 * Core PHP 8.2
 * 
 * Enforces:
 * - Public signups strictly provision role: 'employee' and status: 'pending'
 * - Server-side validation of full_name, email, phone, department, password, password confirmation
 * - Secure password hashing using PASSWORD_DEFAULT
 * - Duplicate email prevention with safe generic messaging
 * - Department existence verification
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'Method not allowed. POST required.', [], 405);
}

$input = getRequestBody();

$fullName        = trim((string)($input['full_name'] ?? $input['name'] ?? ''));
$email           = strtolower(trim((string)($input['email'] ?? '')));
$phone           = trim((string)($input['phone'] ?? ''));
$deptId          = isset($input['department_id']) ? (int)$input['department_id'] : (isset($input['department']) ? (int)$input['department'] : 0);
$password        = (string)($input['password'] ?? '');
$passwordConfirm = isset($input['password_confirm']) ? (string)$input['password_confirm'] : (isset($input['confirm_password']) ? (string)$input['confirm_password'] : null);

// Essential Server-side Validation
if (empty($fullName) || mb_strlen($fullName) < 2 || mb_strlen($fullName) > 120) {
    jsonResponse(false, 'Please provide a valid full name (2–120 characters).', [], 422);
}

if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 150) {
    jsonResponse(false, 'Please provide a valid work email address.', [], 422);
}

if (empty($password) || mb_strlen($password) < 6) {
    jsonResponse(false, 'Password must be at least 6 characters in length.', [], 422);
}

if (mb_strlen($password) > 255) {
    jsonResponse(false, 'Password exceeds maximum allowable length.', [], 422);
}

if ($passwordConfirm !== null && $password !== $passwordConfirm) {
    jsonResponse(false, 'Password confirmation does not match.', [], 422);
}

if (!empty($phone) && mb_strlen($phone) > 20) {
    jsonResponse(false, 'Phone number cannot exceed 20 characters.', [], 422);
}

if ($deptId <= 0) {
    jsonResponse(false, 'Please select a valid organizational department.', [], 422);
}

try {
    $pdo = Database::getConnection();

    // Verify department exists
    $deptStmt = $pdo->prepare('SELECT id, name FROM departments WHERE id = :dept_id LIMIT 1');
    $deptStmt->execute([':dept_id' => $deptId]);
    $dept = $deptStmt->fetch();

    if (!$dept) {
        jsonResponse(false, 'Selected department does not exist. Please choose a valid department.', [], 422);
    }

    // Check duplicate email safely
    $checkStmt = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
    $checkStmt->execute([':email' => $email]);
    if ($checkStmt->fetch()) {
        jsonResponse(false, 'An account with this work email address is already registered or pending review.', [], 409);
    }

    // Hash password with PHP PASSWORD_DEFAULT
    $passwordHash = password_hash($password, PASSWORD_DEFAULT);

    // Strictly hardcode role='employee' and status='pending'
    $insertStmt = $pdo->prepare('
        INSERT INTO users (full_name, email, password_hash, role, status, department_id, phone, joined_date, created_at)
        VALUES (:full_name, :email, :password_hash, "employee", "pending", :dept_id, :phone, CURDATE(), NOW())
    ');

    $insertStmt->execute([
        ':full_name'     => $fullName,
        ':email'         => $email,
        ':password_hash' => $passwordHash,
        ':dept_id'       => $deptId,
        ':phone'         => !empty($phone) ? $phone : null,
    ]);

    $newUserId = (int)$pdo->lastInsertId();

    // Create system notification for pending onboarding
    try {
        $notifStmt = $pdo->prepare('
            INSERT INTO notifications (user_id, title, message, is_read, created_at)
            VALUES (:user_id, :title, :message, 0, NOW())
        ');
        $notifStmt->execute([
            ':user_id' => $newUserId,
            ':title'   => 'Registration Received',
            ':message' => sprintf(
                'Welcome to EVORA HRMS, %s. Your employee registration for %s has been received and is awaiting administrator verification.',
                $fullName,
                $dept['name']
            ),
        ]);
    } catch (Exception $ne) {
        error_log('[EVORA Notification Warning] ' . $ne->getMessage());
    }

    jsonResponse(true, 'Registration submitted successfully. Your account is pending administrator review before you can sign in.', [
        'user' => [
            'id'         => $newUserId,
            'full_name'  => $fullName,
            'email'      => $email,
            'department' => $dept['name'],
            'role'       => 'employee',
            'status'     => 'pending',
        ]
    ], 201);

} catch (PDOException $e) {
    error_log('[EVORA Signup DB Error] ' . $e->getMessage());
    jsonResponse(false, 'An unexpected error occurred while processing registration. Please try again.', [], 500);
}
