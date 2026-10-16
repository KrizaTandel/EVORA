<?php
/**
 * EVORA HRMS — Administrator Employees API Endpoint
 * Core PHP 8.2
 * 
 * GET:  List employees with search & filtering (name, email, department, status, role)
 * POST: Create a new employee record by Administrator
 */

declare(strict_types=1);

require_once __DIR__ . '/guard.php';

$pdo = Database::getConnection();
$method = $_SERVER['REQUEST_METHOD'];

// ---------------------------------------------------------------------
// 1. GET: List employees with search, department & status filters
// ---------------------------------------------------------------------
if ($method === 'GET') {
    $search = trim((string)($_GET['search'] ?? $_GET['q'] ?? ''));
    $deptFilter = trim((string)($_GET['department_id'] ?? $_GET['dept'] ?? ''));
    $statusFilter = trim((string)($_GET['status'] ?? ''));
    $roleFilter = trim((string)($_GET['role'] ?? ''));

    $where = [];
    $params = [];

    if ($search !== '') {
        $where[] = '(u.full_name LIKE :search_name OR u.email LIKE :search_email OR u.designation LIKE :search_desig)';
        $term = "%{$search}%";
        $params[':search_name'] = $term;
        $params[':search_email'] = $term;
        $params[':search_desig'] = $term;
    }

    if ($deptFilter !== '') {
        if (is_numeric($deptFilter)) {
            $where[] = 'u.department_id = :dept_id';
            $params[':dept_id'] = (int)$deptFilter;
        } else {
            $where[] = 'd.name = :dept_name';
            $params[':dept_name'] = $deptFilter;
        }
    }

    if ($statusFilter !== '') {
        $validStatuses = ['pending', 'approved', 'rejected'];
        if (in_array(strtolower($statusFilter), $validStatuses, true)) {
            $where[] = 'u.status = :status';
            $params[':status'] = strtolower($statusFilter);
        }
    }

    if ($roleFilter !== '') {
        $validRoles = ['admin', 'employee'];
        if (in_array(strtolower($roleFilter), $validRoles, true)) {
            $where[] = 'u.role = :role';
            $params[':role'] = strtolower($roleFilter);
        }
    }

    $whereSql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

    try {
        $sql = "
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
            {$whereSql}
            ORDER BY u.id DESC
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $employees = $stmt->fetchAll();

        // Also fetch count of pending approvals for notification badges
        $pendingCountStmt = $pdo->query("SELECT COUNT(*) FROM users WHERE status = 'pending'");
        $pendingCount = (int)$pendingCountStmt->fetchColumn();

        // Total staff count
        $totalCountStmt = $pdo->query("SELECT COUNT(*) FROM users WHERE status = 'approved'");
        $totalApproved = (int)$totalCountStmt->fetchColumn();

        jsonResponse(true, 'Employees retrieved successfully.', [
            'employees'     => $employees,
            'total'         => count($employees),
            'pending_count' => $pendingCount,
            'total_approved'=> $totalApproved,
        ], 200);

    } catch (PDOException $e) {
        error_log('[EVORA Admin Employees GET Error] ' . $e->getMessage());
        jsonResponse(false, 'Failed to retrieve employee directory.', [], 500);
    }
}

// ---------------------------------------------------------------------
// 2. POST: Create a new employee record by Admin
// ---------------------------------------------------------------------
if ($method === 'POST') {
    requireCsrf();
    $input = getRequestBody();

    $fullName    = trim((string)($input['full_name'] ?? $input['name'] ?? ''));
    $email       = strtolower(trim((string)($input['email'] ?? '')));
    $phone       = trim((string)($input['phone'] ?? ''));
    $deptId      = isset($input['department_id']) ? (int)$input['department_id'] : (isset($input['dept_id']) ? (int)$input['dept_id'] : 0);
    $designation = trim((string)($input['designation'] ?? ''));
    $status      = strtolower(trim((string)($input['status'] ?? 'approved')));
    $role        = strtolower(trim((string)($input['role'] ?? 'employee')));
    $password    = (string)($input['password'] ?? '');
    $joinedDate  = trim((string)($input['joined_date'] ?? ''));

    // Validation
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
        jsonResponse(false, 'Please provide a job title/designation (max 100 characters).', [], 422);
    }

    if (!in_array($status, ['approved', 'pending'], true)) {
        $status = 'approved';
    }

    if (!in_array($role, ['employee', 'admin'], true)) {
        $role = 'employee';
    }

    // Default password if not provided by admin
    if (empty($password)) {
        $password = 'EvoraPass' . mt_rand(1000, 9999) . '!';
    } elseif (mb_strlen($password) < 6) {
        jsonResponse(false, 'Password must be at least 6 characters in length.', [], 422);
    }

    $passwordHash = password_hash($password, PASSWORD_DEFAULT);

    if (empty($joinedDate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $joinedDate)) {
        $joinedDate = date('Y-m-d');
    }

    try {
        $pdo->beginTransaction();

        // 1. Verify department exists
        $deptStmt = $pdo->prepare('SELECT id, name FROM departments WHERE id = :id LIMIT 1');
        $deptStmt->execute([':id' => $deptId]);
        $dept = $deptStmt->fetch();

        if (!$dept) {
            $pdo->rollBack();
            jsonResponse(false, 'The selected department does not exist.', [], 422);
        }

        // 2. Check duplicate email
        $checkStmt = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
        $checkStmt->execute([':email' => $email]);
        if ($checkStmt->fetch()) {
            $pdo->rollBack();
            jsonResponse(false, 'An account with this email address already exists.', [], 409);
        }

        // 3. Insert user
        $insertStmt = $pdo->prepare('
            INSERT INTO users (full_name, email, password_hash, role, status, department_id, designation, phone, joined_date, created_at)
            VALUES (:name, :email, :pass, :role, :status, :dept_id, :desig, :phone, :joined, NOW())
        ');

        $insertStmt->execute([
            ':name'    => $fullName,
            ':email'   => $email,
            ':pass'    => $passwordHash,
            ':role'    => $role,
            ':status'  => $status,
            ':dept_id' => $deptId,
            ':desig'   => $designation,
            ':phone'   => !empty($phone) ? $phone : null,
            ':joined'  => $joinedDate,
        ]);

        $newId = (int)$pdo->lastInsertId();

        // 4. Create welcome notification
        $notifStmt = $pdo->prepare('
            INSERT INTO notifications (user_id, title, message, is_read, created_at)
            VALUES (:user_id, :title, :message, 0, NOW())
        ');
        $notifStmt->execute([
            ':user_id' => $newId,
            ':title'   => 'Welcome to EVORA HRMS',
            ':message' => sprintf(
                'Welcome, %s! Your employee profile has been created in the %s department as %s.',
                $fullName,
                $dept['name'],
                $designation
            ),
        ]);

        $pdo->commit();

        jsonResponse(true, "Employee \"{$fullName}\" created successfully.", [
            'user' => [
                'id'              => $newId,
                'full_name'       => $fullName,
                'email'           => $email,
                'role'            => $role,
                'status'          => $status,
                'department_id'   => $deptId,
                'department_name' => $dept['name'],
                'designation'     => $designation,
                'phone'           => $phone,
                'joined_date'     => $joinedDate,
            ]
        ], 201);

    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[EVORA Admin Create Employee Error] ' . $e->getMessage());
        jsonResponse(false, 'An error occurred while creating the employee record.', [], 500);
    }
}

jsonResponse(false, 'Method not allowed.', [], 405);
