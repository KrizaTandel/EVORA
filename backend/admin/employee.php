<?php
/**
 * EVORA HRMS — Administrator Single Employee Management API
 * Core PHP 8.2
 * 
 * GET:    Read full details of a single employee
 * POST:   Update employee details (or action=update)
 * DELETE: Delete or safely deactivate employee (or action=delete)
 */

declare(strict_types=1);

require_once __DIR__ . '/guard.php';

$pdo = Database::getConnection();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? $_POST['action'] ?? '';

// Support method overriding via ?action= or _method
if ($method === 'POST' && ($action === 'delete' || ($_POST['_method'] ?? '') === 'DELETE')) {
    $method = 'DELETE';
} elseif ($method === 'POST' && ($action === 'update' || ($_POST['_method'] ?? '') === 'PUT')) {
    $method = 'PUT';
}

// ---------------------------------------------------------------------
// 1. GET: Read employee profile by ID
// ---------------------------------------------------------------------
if ($method === 'GET') {
    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if ($id <= 0) {
        jsonResponse(false, 'Invalid employee ID.', [], 400);
    }

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
                u.updated_at,
                d.name AS department_name, 
                d.code AS department_code
            FROM users u
            LEFT JOIN departments d ON u.department_id = d.id
            WHERE u.id = :id
            LIMIT 1
        ');
        $stmt->execute([':id' => $id]);
        $user = $stmt->fetch();

        if (!$user) {
            jsonResponse(false, 'Employee not found.', [], 404);
        }

        jsonResponse(true, 'Employee details retrieved.', ['employee' => $user], 200);

    } catch (PDOException $e) {
        error_log('[EVORA Admin GET Employee Error] ' . $e->getMessage());
        jsonResponse(false, 'Failed to fetch employee record.', [], 500);
    }
}

// ---------------------------------------------------------------------
// 2. PUT / POST: Update employee profile
// ---------------------------------------------------------------------
if ($method === 'PUT' || ($method === 'POST' && empty($action))) {
    requireCsrf();
    $input = getRequestBody();

    $id          = isset($input['id']) ? (int)$input['id'] : (isset($_GET['id']) ? (int)$_GET['id'] : 0);
    $fullName    = trim((string)($input['full_name'] ?? $input['name'] ?? ''));
    $email       = strtolower(trim((string)($input['email'] ?? '')));
    $phone       = trim((string)($input['phone'] ?? ''));
    $deptId      = isset($input['department_id']) ? (int)$input['department_id'] : (isset($input['dept_id']) ? (int)$input['dept_id'] : 0);
    $designation = trim((string)($input['designation'] ?? ''));
    $status      = strtolower(trim((string)($input['status'] ?? 'approved')));
    $role        = isset($input['role']) ? strtolower(trim((string)$input['role'])) : null;

    if ($id <= 0) {
        jsonResponse(false, 'Invalid employee ID.', [], 400);
    }

    if (empty($fullName) || mb_strlen($fullName) < 2 || mb_strlen($fullName) > 120) {
        jsonResponse(false, 'Please provide a valid full name (2–120 characters).', [], 422);
    }

    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 150) {
        jsonResponse(false, 'Please provide a valid work email address.', [], 422);
    }

    if (!empty($phone) && mb_strlen($phone) > 20) {
        jsonResponse(false, 'Phone number cannot exceed 20 characters.', [], 422);
    }

    if ($deptId <= 0) {
        jsonResponse(false, 'Please select a valid department.', [], 422);
    }

    if (empty($designation) || mb_strlen($designation) > 100) {
        jsonResponse(false, 'Please provide a designation (max 100 characters).', [], 422);
    }

    if (!in_array($status, ['approved', 'pending', 'rejected'], true)) {
        jsonResponse(false, 'Invalid account status specified.', [], 422);
    }

    try {
        $pdo->beginTransaction();

        // 1. Fetch existing record
        $targetStmt = $pdo->prepare('SELECT id, full_name, email, role, status FROM users WHERE id = :id LIMIT 1');
        $targetStmt->execute([':id' => $id]);
        $existing = $targetStmt->fetch();

        if (!$existing) {
            $pdo->rollBack();
            jsonResponse(false, 'Employee record not found.', [], 404);
        }

        // 2. Protect last approved administrator from demotion or deactivation
        if ($existing['role'] === 'admin') {
            $isDemoting = ($role !== null && $role !== 'admin');
            $isDeactivating = ($status !== 'approved');

            if ($isDemoting || $isDeactivating) {
                $adminCountStmt = $pdo->prepare('
                    SELECT COUNT(*) FROM users 
                    WHERE role = "admin" AND status = "approved" AND id != :id
                ');
                $adminCountStmt->execute([':id' => $id]);
                $otherAdmins = (int)$adminCountStmt->fetchColumn();

                if ($otherAdmins === 0) {
                    $pdo->rollBack();
                    jsonResponse(false, 'Operation rejected: Cannot demote or deactivate the last approved administrator in the system.', [], 403);
                }
            }
        }

        // 3. Verify department exists
        $deptStmt = $pdo->prepare('SELECT id, name FROM departments WHERE id = :id LIMIT 1');
        $deptStmt->execute([':id' => $deptId]);
        $dept = $deptStmt->fetch();

        if (!$dept) {
            $pdo->rollBack();
            jsonResponse(false, 'Selected department does not exist.', [], 422);
        }

        // 4. Ensure email is unique across other users
        $checkEmailStmt = $pdo->prepare('SELECT id FROM users WHERE email = :email AND id != :id LIMIT 1');
        $checkEmailStmt->execute([':email' => $email, ':id' => $id]);
        if ($checkEmailStmt->fetch()) {
            $pdo->rollBack();
            jsonResponse(false, 'Another employee account is already registered with this email address.', [], 409);
        }

        // 5. Build update query
        $targetRole = ($role !== null && in_array($role, ['admin', 'employee'], true)) ? $role : $existing['role'];

        $updateStmt = $pdo->prepare('
            UPDATE users SET
                full_name     = :name,
                email         = :email,
                phone         = :phone,
                department_id = :dept_id,
                designation   = :desig,
                status        = :status,
                role          = :role,
                updated_at    = NOW()
            WHERE id = :id
        ');

        $updateStmt->execute([
            ':name'    => $fullName,
            ':email'   => $email,
            ':phone'   => !empty($phone) ? $phone : null,
            ':dept_id' => $deptId,
            ':desig'   => $designation,
            ':status'  => $status,
            ':role'    => $targetRole,
            ':id'      => $id,
        ]);

        $pdo->commit();

        jsonResponse(true, "Employee \"{$fullName}\" updated successfully.", [
            'employee' => [
                'id'              => $id,
                'full_name'       => $fullName,
                'email'           => $email,
                'role'            => $targetRole,
                'status'          => $status,
                'department_id'   => $deptId,
                'department_name' => $dept['name'],
                'designation'     => $designation,
                'phone'           => $phone,
            ]
        ], 200);

    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[EVORA Admin Update Employee Error] ' . $e->getMessage());
        jsonResponse(false, 'An error occurred while updating the employee record.', [], 500);
    }
}

// ---------------------------------------------------------------------
// 3. DELETE: Safe removal or deactivation
// ---------------------------------------------------------------------
if ($method === 'DELETE') {
    requireCsrf();
    $input = getRequestBody();
    $id = isset($input['id']) ? (int)$input['id'] : (isset($_GET['id']) ? (int)$_GET['id'] : 0);

    if ($id <= 0) {
        jsonResponse(false, 'Invalid employee ID.', [], 400);
    }

    $currentUserId = (int)($_SESSION['user_id'] ?? 0);
    if ($id === $currentUserId) {
        jsonResponse(false, 'Security constraint: You cannot delete or deactivate your own active session account.', [], 403);
    }

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare('SELECT id, full_name, email, role, status FROM users WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $target = $stmt->fetch();

        if (!$target) {
            $pdo->rollBack();
            jsonResponse(false, 'Employee record not found.', [], 404);
        }

        // Protect last approved admin
        if ($target['role'] === 'admin') {
            $otherAdminsStmt = $pdo->prepare('
                SELECT COUNT(*) FROM users 
                WHERE role = "admin" AND status = "approved" AND id != :id
            ');
            $otherAdminsStmt->execute([':id' => $id]);
            $otherAdmins = (int)$otherAdminsStmt->fetchColumn();

            if ($otherAdmins === 0) {
                $pdo->rollBack();
                jsonResponse(false, 'Cannot delete the only approved administrator in the system.', [], 403);
            }
        }

        // Check historical dependent records
        $attendStmt = $pdo->prepare('SELECT COUNT(*) FROM attendance WHERE user_id = :id');
        $attendStmt->execute([':id' => $id]);
        $attendCount = (int)$attendStmt->fetchColumn();

        $leavesStmt = $pdo->prepare('SELECT COUNT(*) FROM leaves WHERE user_id = :id OR reviewed_by = :id');
        $leavesStmt->execute([':id' => $id]);
        $leavesCount = (int)$leavesStmt->fetchColumn();

        if ($attendCount > 0 || $leavesCount > 0) {
            // Apply safe deactivation policy to preserve historical integrity
            $deactStmt = $pdo->prepare('
                UPDATE users 
                SET status = "rejected", updated_at = NOW() 
                WHERE id = :id
            ');
            $deactStmt->execute([':id' => $id]);
            $pdo->commit();

            jsonResponse(true, "Employee has historical attendance/leave records. Account was safely deactivated (status set to Rejected) to preserve organizational history.", [
                'action'    => 'deactivated',
                'status'    => 'rejected',
                'retained'  => true,
            ], 200);
        }

        // Clean removal if no historical attendance or leaves records exist
        $delNotif = $pdo->prepare('DELETE FROM notifications WHERE user_id = :id');
        $delNotif->execute([':id' => $id]);

        $delUser = $pdo->prepare('DELETE FROM users WHERE id = :id');
        $delUser->execute([':id' => $id]);

        $pdo->commit();

        jsonResponse(true, "Employee record \"{$target['full_name']}\" removed successfully.", [
            'action'  => 'deleted',
            'id'      => $id,
        ], 200);

    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[EVORA Admin Delete Employee Error] ' . $e->getMessage());
        jsonResponse(false, 'Failed to process employee deletion.', [], 500);
    }
}

jsonResponse(false, 'Method not allowed.', [], 405);
