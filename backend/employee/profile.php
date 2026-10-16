<?php
/**
 * EVORA HRMS — Employee Self-Service Profile API
 * Core PHP 8.2
 * 
 * GET:  Fetch current employee's profile
 * POST: Update employee's editable contact details (full name, phone)
 */

declare(strict_types=1);

require_once __DIR__ . '/guard.php';

$pdo = Database::getConnection();
$userId = (int)$currentUser['id'];
$method = $_SERVER['REQUEST_METHOD'];

// ---------------------------------------------------------------------
// 1. GET: Fetch full profile
// ---------------------------------------------------------------------
if ($method === 'GET') {
    try {
        $stmt = $pdo->prepare('
            SELECT 
                u.id, 
                u.full_name, 
                u.email, 
                u.role, 
                u.status, 
                u.department_id, 
                u.designation, 
                u.phone, 
                u.joined_date, 
                u.created_at,
                d.name AS department_name, 
                d.code AS department_code
            FROM users u
            LEFT JOIN departments d ON u.department_id = d.id
            WHERE u.id = :id
            LIMIT 1
        ');
        $stmt->execute([':id' => $userId]);
        $user = $stmt->fetch();

        if (!$user) {
            jsonResponse(false, 'User record not found.', [], 404);
        }

        jsonResponse(true, 'Profile retrieved.', ['user' => $user], 200);

    } catch (PDOException $e) {
        error_log('[EVORA Employee Profile GET Error] ' . $e->getMessage());
        jsonResponse(false, 'Unable to load profile information.', [], 500);
    }
}

// ---------------------------------------------------------------------
// 2. POST: Update editable contact attributes
// ---------------------------------------------------------------------
if ($method === 'POST') {
    requireCsrf();
    $input = getRequestBody();

    $fullName = trim((string)($input['full_name'] ?? $input['name'] ?? ''));
    $phone    = trim((string)($input['phone'] ?? ''));

    if (empty($fullName) || mb_strlen($fullName) < 2 || mb_strlen($fullName) > 120) {
        jsonResponse(false, 'Please provide a valid full name (2–120 characters).', [], 422);
    }

    if (!empty($phone) && mb_strlen($phone) > 20) {
        jsonResponse(false, 'Phone number cannot exceed 20 characters.', [], 422);
    }

    try {
        $updateStmt = $pdo->prepare('
            UPDATE users 
            SET full_name = :name, phone = :phone, updated_at = NOW() 
            WHERE id = :id
        ');
        $updateStmt->execute([
            ':name'  => $fullName,
            ':phone' => !empty($phone) ? $phone : null,
            ':id'    => $userId,
        ]);

        // Reflect name in active session
        $_SESSION['full_name'] = $fullName;

        jsonResponse(true, 'Profile updated successfully.', [
            'user' => [
                'id'        => $userId,
                'full_name' => $fullName,
                'phone'     => $phone,
            ]
        ], 200);

    } catch (PDOException $e) {
        error_log('[EVORA Employee Profile POST Error] ' . $e->getMessage());
        jsonResponse(false, 'An error occurred while saving profile changes.', [], 500);
    }
}

jsonResponse(false, 'Method not allowed.', [], 405);
