<?php
/**
 * EVORA HRMS — Administrator Registration Approval Endpoint
 * Core PHP 8.2
 * 
 * POST: Approve or reject an applicant with status 'pending'
 * Only pending registrations may transition to approved or rejected.
 */

declare(strict_types=1);

require_once __DIR__ . '/guard.php';

$pdo = Database::getConnection();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'Method not allowed. POST required.', [], 405);
}

requireCsrf();
$input = getRequestBody();

$id     = isset($input['id']) ? (int)$input['id'] : 0;
$action = strtolower(trim((string)($input['action'] ?? '')));

if ($id <= 0) {
    jsonResponse(false, 'Invalid employee registration ID.', [], 400);
}

if (!in_array($action, ['approve', 'reject'], true)) {
    jsonResponse(false, 'Invalid action specified. Must be "approve" or "reject".', [], 422);
}

try {
    $pdo->beginTransaction();

    // 1. Fetch user and verify current status is pending
    $stmt = $pdo->prepare('SELECT id, full_name, email, role, status FROM users WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $id]);
    $user = $stmt->fetch();

    if (!$user) {
        $pdo->rollBack();
        jsonResponse(false, 'Applicant registration record not found.', [], 404);
    }

    if ($user['status'] !== 'pending') {
        $pdo->rollBack();
        jsonResponse(
            false, 
            "Only pending applications can be reviewed. This account currently has status: '{$user['status']}'.", 
            ['current_status' => $user['status']], 
            422
        );
    }

    $newStatus = ($action === 'approve') ? 'approved' : 'rejected';

    // 2. Update status
    $updateStmt = $pdo->prepare('
        UPDATE users 
        SET status = :status, updated_at = NOW() 
        WHERE id = :id
    ');
    $updateStmt->execute([
        ':status' => $newStatus,
        ':id'     => $id,
    ]);

    // 3. Create system notification for applicant
    $notifTitle = ($action === 'approve') ? 'Registration Approved' : 'Registration Application Update';
    $notifMsg   = ($action === 'approve')
        ? sprintf('Congratulations, %s! Your EVORA employee registration has been approved. You may now log in to access your employee workspace.', $user['full_name'])
        : sprintf('Hello, %s. Your registration application has been reviewed and rejected. Please contact People Operations for any queries.', $user['full_name']);

    $notifStmt = $pdo->prepare('
        INSERT INTO notifications (user_id, title, message, is_read, created_at)
        VALUES (:user_id, :title, :message, 0, NOW())
    ');
    $notifStmt->execute([
        ':user_id' => $id,
        ':title'   => $notifTitle,
        ':message' => $notifMsg,
    ]);

    $pdo->commit();

    $actionVerb = ($action === 'approve') ? 'approved' : 'rejected';
    jsonResponse(true, "Registration for \"{$user['full_name']}\" has been successfully {$actionVerb}.", [
        'id'         => $id,
        'action'     => $action,
        'new_status' => $newStatus,
    ], 200);

} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('[EVORA Admin Approval Error] ' . $e->getMessage());
    jsonResponse(false, 'An internal error occurred while processing the approval decision.', [], 500);
}
