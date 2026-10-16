<?php
/**
 * EVORA HRMS — Administrator Departments API Endpoint
 * Core PHP 8.2
 * 
 * GET:    List all departments with live staff headcount
 * POST:   Create a new department
 * PUT:    Update existing department
 * DELETE: Delete department (strictly blocked if employees are assigned)
 */

declare(strict_types=1);

require_once __DIR__ . '/guard.php';

$pdo = Database::getConnection();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? $_POST['action'] ?? '';

if ($method === 'POST' && ($action === 'delete' || ($_POST['_method'] ?? '') === 'DELETE')) {
    $method = 'DELETE';
} elseif ($method === 'POST' && ($action === 'update' || ($_POST['_method'] ?? '') === 'PUT')) {
    $method = 'PUT';
}

// ---------------------------------------------------------------------
// 1. GET: List all departments with live allocated staff counts
// ---------------------------------------------------------------------
if ($method === 'GET') {
    try {
        $stmt = $pdo->query('
            SELECT 
                d.id, 
                d.name, 
                d.code, 
                d.description, 
                d.created_at, 
                d.updated_at,
                COUNT(u.id) AS staff_count
            FROM departments d
            LEFT JOIN users u ON u.department_id = d.id AND u.status = "approved"
            GROUP BY d.id
            ORDER BY d.id ASC
        ');
        $departments = $stmt->fetchAll();

        jsonResponse(true, 'Departments retrieved successfully.', [
            'departments' => $departments,
            'total'       => count($departments),
        ], 200);

    } catch (PDOException $e) {
        error_log('[EVORA Admin GET Departments Error] ' . $e->getMessage());
        jsonResponse(false, 'Failed to fetch organizational departments.', [], 500);
    }
}

// ---------------------------------------------------------------------
// 2. POST: Create a new department
// ---------------------------------------------------------------------
if ($method === 'POST') {
    requireCsrf();
    $input = getRequestBody();

    $name = trim((string)($input['name'] ?? ''));
    $code = strtoupper(trim((string)($input['code'] ?? '')));
    $desc = trim((string)($input['description'] ?? ''));

    if (empty($name) || mb_strlen($name) < 2 || mb_strlen($name) > 100) {
        jsonResponse(false, 'Please provide a valid department name (2–100 characters).', [], 422);
    }

    if (empty($code) || mb_strlen($code) < 2 || mb_strlen($code) > 20) {
        jsonResponse(false, 'Please provide a department code (2–20 characters, e.g. ENG).', [], 422);
    }

    try {
        // Check duplicate name or code
        $checkStmt = $pdo->prepare('SELECT id, name, code FROM departments WHERE name = :name OR code = :code LIMIT 1');
        $checkStmt->execute([':name' => $name, ':code' => $code]);
        $existing = $checkStmt->fetch();

        if ($existing) {
            if (strcasecmp($existing['name'], $name) === 0) {
                jsonResponse(false, "A department named \"{$name}\" already exists.", [], 409);
            }
            jsonResponse(false, "Department code \"{$code}\" is already assigned to another department.", [], 409);
        }

        $insertStmt = $pdo->prepare('
            INSERT INTO departments (name, code, description, created_at)
            VALUES (:name, :code, :desc, NOW())
        ');
        $insertStmt->execute([
            ':name' => $name,
            ':code' => $code,
            ':desc' => !empty($desc) ? $desc : null,
        ]);

        $newId = (int)$pdo->lastInsertId();

        jsonResponse(true, "Department \"{$name}\" created successfully.", [
            'department' => [
                'id'          => $newId,
                'name'        => $name,
                'code'        => $code,
                'description' => $desc,
                'staff_count' => 0,
            ]
        ], 201);

    } catch (PDOException $e) {
        error_log('[EVORA Admin Create Department Error] ' . $e->getMessage());
        jsonResponse(false, 'Failed to create department.', [], 500);
    }
}

// ---------------------------------------------------------------------
// 3. PUT: Update existing department
// ---------------------------------------------------------------------
if ($method === 'PUT') {
    requireCsrf();
    $input = getRequestBody();

    $id   = isset($input['id']) ? (int)$input['id'] : (isset($_GET['id']) ? (int)$_GET['id'] : 0);
    $name = trim((string)($input['name'] ?? ''));
    $code = strtoupper(trim((string)($input['code'] ?? '')));
    $desc = trim((string)($input['description'] ?? ''));

    if ($id <= 0) {
        jsonResponse(false, 'Invalid department ID.', [], 400);
    }

    if (empty($name) || mb_strlen($name) < 2 || mb_strlen($name) > 100) {
        jsonResponse(false, 'Please provide a valid department name (2–100 characters).', [], 422);
    }

    if (empty($code) || mb_strlen($code) < 2 || mb_strlen($code) > 20) {
        jsonResponse(false, 'Please provide a department code (2–20 characters, e.g. ENG).', [], 422);
    }

    try {
        // Verify department exists
        $deptStmt = $pdo->prepare('SELECT id FROM departments WHERE id = :id LIMIT 1');
        $deptStmt->execute([':id' => $id]);
        if (!$deptStmt->fetch()) {
            jsonResponse(false, 'Department not found.', [], 404);
        }

        // Check duplicate name or code with other departments
        $checkStmt = $pdo->prepare('SELECT id, name, code FROM departments WHERE (name = :name OR code = :code) AND id != :id LIMIT 1');
        $checkStmt->execute([':name' => $name, ':code' => $code, ':id' => $id]);
        $existing = $checkStmt->fetch();

        if ($existing) {
            if (strcasecmp($existing['name'], $name) === 0) {
                jsonResponse(false, "Another department named \"{$name}\" already exists.", [], 409);
            }
            jsonResponse(false, "Department code \"{$code}\" is in use by another department.", [], 409);
        }

        $updateStmt = $pdo->prepare('
            UPDATE departments 
            SET name = :name, code = :code, description = :desc, updated_at = NOW() 
            WHERE id = :id
        ');
        $updateStmt->execute([
            ':name' => $name,
            ':code' => $code,
            ':desc' => !empty($desc) ? $desc : null,
            ':id'   => $id,
        ]);

        jsonResponse(true, "Department \"{$name}\" updated successfully.", [
            'department' => [
                'id'          => $id,
                'name'        => $name,
                'code'        => $code,
                'description' => $desc,
            ]
        ], 200);

    } catch (PDOException $e) {
        error_log('[EVORA Admin Update Department Error] ' . $e->getMessage());
        jsonResponse(false, 'Failed to update department.', [], 500);
    }
}

// ---------------------------------------------------------------------
// 4. DELETE: Safe department deletion with reference checking
// ---------------------------------------------------------------------
if ($method === 'DELETE') {
    requireCsrf();
    $input = getRequestBody();
    $id = isset($input['id']) ? (int)$input['id'] : (isset($_GET['id']) ? (int)$_GET['id'] : 0);

    if ($id <= 0) {
        jsonResponse(false, 'Invalid department ID.', [], 400);
    }

    try {
        // Verify department exists
        $deptStmt = $pdo->prepare('SELECT id, name FROM departments WHERE id = :id LIMIT 1');
        $deptStmt->execute([':id' => $id]);
        $dept = $deptStmt->fetch();

        if (!$dept) {
            jsonResponse(false, 'Department not found.', [], 404);
        }

        // Check if employees are assigned to this department
        $checkStaffStmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE department_id = :id');
        $checkStaffStmt->execute([':id' => $id]);
        $assignedStaff = (int)$checkStaffStmt->fetchColumn();

        if ($assignedStaff > 0) {
            jsonResponse(
                false, 
                "Cannot delete department \"{$dept['name']}\": {$assignedStaff} employee(s) are currently assigned to this unit. Reassign all staff members before deleting this department.", 
                ['assigned_staff' => $assignedStaff], 
                409
            );
        }

        $delStmt = $pdo->prepare('DELETE FROM departments WHERE id = :id');
        $delStmt->execute([':id' => $id]);

        jsonResponse(true, "Department \"{$dept['name']}\" deleted successfully.", ['id' => $id], 200);

    } catch (PDOException $e) {
        error_log('[EVORA Admin Delete Department Error] ' . $e->getMessage());
        jsonResponse(false, 'Failed to delete department.', [], 500);
    }
}

jsonResponse(false, 'Method not allowed.', [], 405);
