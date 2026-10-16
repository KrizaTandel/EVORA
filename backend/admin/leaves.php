<?php
/**
 * EVORA HRMS — Administrator Leave Management & Decision API
 * Core PHP 8.2
 * 
 * GET:  List all organizational leave requests with filtering
 * POST: Approve or reject a pending leave request with administrative remarks
 */

declare(strict_types=1);

require_once __DIR__ . '/guard.php';

$pdo = Database::getConnection();
$adminId = (int)$currentAdmin['id'];
$method = $_SERVER['REQUEST_METHOD'];

// ---------------------------------------------------------------------
// 1. GET: List leave requests with filters
// ---------------------------------------------------------------------
if ($method === 'GET') {
    $statusFilter = strtolower(trim((string)($_GET['status'] ?? '')));
    $deptFilter = trim((string)($_GET['department_id'] ?? ''));

    $where = [];
    $params = [];

    if (!empty($statusFilter) && in_array($statusFilter, ['pending', 'approved', 'rejected'], true)) {
        $where[] = 'l.status = :status';
        $params[':status'] = $statusFilter;
    }

    if (!empty($deptFilter) && is_numeric($deptFilter)) {
        $where[] = 'u.department_id = :dept_id';
        $params[':dept_id'] = (int)$deptFilter;
    }

    $whereSql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

    try {
        $sql = "
            SELECT 
                l.id, 
                l.user_id, 
                l.leave_type, 
                l.start_date, 
                l.end_date, 
                l.reason, 
                l.status, 
                l.admin_remarks, 
                l.reviewed_at, 
                l.created_at,
                u.full_name AS employee_name, 
                u.email AS employee_email, 
                u.designation,
                d.name AS department_name, 
                d.code AS department_code,
                rev.full_name AS reviewer_name
            FROM leaves l
            INNER JOIN users u ON l.user_id = u.id
            LEFT JOIN departments d ON u.department_id = d.id
            LEFT JOIN users rev ON l.reviewed_by = rev.id
            {$whereSql}
            ORDER BY 
                CASE WHEN l.status = 'pending' THEN 1 ELSE 2 END, 
                l.created_at DESC
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $leaves = $stmt->fetchAll();

        // Counts
        $countStmt = $pdo->query('
            SELECT 
                COUNT(*) AS total_leaves,
                SUM(CASE WHEN status = "pending" THEN 1 ELSE 0 END) AS pending_count,
                SUM(CASE WHEN status = "approved" THEN 1 ELSE 0 END) AS approved_count
            FROM leaves
        ');
        $counts = $countStmt->fetch();

        jsonResponse(true, 'Leave requests retrieved.', [
            'leaves'        => $leaves,
            'total'         => count($leaves),
            'pending_count' => (int)($counts['pending_count'] ?? 0),
            'approved_count'=> (int)($counts['approved_count'] ?? 0),
        ], 200);

    } catch (PDOException $e) {
        error_log('[EVORA Admin Leaves GET Error] ' . $e->getMessage());
        jsonResponse(false, 'Failed to fetch leave requests.', [], 500);
    }
}

// ---------------------------------------------------------------------
// 2. POST: Approve or reject leave request
// ---------------------------------------------------------------------
if ($method === 'POST') {
    requireCsrf();
    $input = getRequestBody();

    $id      = isset($input['id']) ? (int)$input['id'] : 0;
    $action  = strtolower(trim((string)($input['action'] ?? '')));
    $remarks = trim((string)($input['admin_remarks'] ?? $input['remarks'] ?? ''));

    if ($id <= 0) {
        jsonResponse(false, 'Invalid leave request ID.', [], 400);
    }

    if (!in_array($action, ['approve', 'reject'], true)) {
        jsonResponse(false, 'Invalid action specified. Must be "approve" or "reject".', [], 422);
    }

    try {
        $pdo->beginTransaction();

        // 1. Fetch leave request and verify status is pending
        $stmt = $pdo->prepare('
            SELECT l.id, l.user_id, l.leave_type, l.start_date, l.end_date, l.status, u.full_name, u.email
            FROM leaves l
            INNER JOIN users u ON l.user_id = u.id
            WHERE l.id = :id
            LIMIT 1
            FOR UPDATE
        ');
        $stmt->execute([':id' => $id]);
        $leave = $stmt->fetch();

        if (!$leave) {
            $pdo->rollBack();
            jsonResponse(false, 'Leave request not found.', [], 404);
        }

        if ($leave['status'] !== 'pending') {
            $pdo->rollBack();
            jsonResponse(
                false, 
                "Only pending leave requests can be reviewed. Current status is already '{$leave['status']}'.", 
                ['current_status' => $leave['status']], 
                422
            );
        }

        $newStatus = ($action === 'approve') ? 'approved' : 'rejected';

        // 2. Update record
        $updateStmt = $pdo->prepare('
            UPDATE leaves SET 
                status        = :status, 
                admin_remarks = :remarks, 
                reviewed_by   = :admin_id, 
                reviewed_at   = NOW() 
            WHERE id = :id
        ');
        $updateStmt->execute([
            ':status'   => $newStatus,
            ':remarks'  => !empty($remarks) ? $remarks : null,
            ':admin_id' => $adminId,
            ':id'       => $id,
        ]);

        // 3. Create employee notification
        $title = ($action === 'approve') ? 'Leave Request Approved' : 'Leave Request Rejected';
        $message = ($action === 'approve')
            ? sprintf('Your %s leave request from %s to %s has been approved.', ucfirst($leave['leave_type']), $leave['start_date'], $leave['end_date'])
            : sprintf('Your %s leave request from %s to %s has been rejected.', ucfirst($leave['leave_type']), $leave['start_date'], $leave['end_date']);

        if (!empty($remarks)) {
            $message .= " Remarks: {$remarks}";
        }

        $notifStmt = $pdo->prepare('
            INSERT INTO notifications (user_id, title, message, is_read, created_at)
            VALUES (:uid, :title, :msg, 0, NOW())
        ');
        $notifStmt->execute([
            ':uid'   => (int)$leave['user_id'],
            ':title' => $title,
            ':msg'   => $message,
        ]);

        $pdo->commit();

        $actionWord = ($action === 'approve') ? 'approved' : 'rejected';
        jsonResponse(true, "Leave request for {$leave['full_name']} has been {$actionWord}.", [
            'id'         => $id,
            'status'     => $newStatus,
            'action'     => $action,
        ], 200);

    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[EVORA Admin Review Leave Error] ' . $e->getMessage());
        jsonResponse(false, 'Failed to process leave decision.', [], 500);
    }
}

jsonResponse(false, 'Method not allowed.', [], 405);
