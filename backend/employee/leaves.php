<?php
/**
 * EVORA HRMS — Employee Leave Management API
 * Core PHP 8.2
 * 
 * GET:  List current employee's leave requests and balance calculation
 * POST: Apply for a new leave (strictly status 'pending', overlap check, CSRF enforced)
 */

declare(strict_types=1);

require_once __DIR__ . '/guard.php';

$pdo = Database::getConnection();
$userId = (int)$currentUser['id'];
$method = $_SERVER['REQUEST_METHOD'];

// ---------------------------------------------------------------------
// 1. GET: Fetch employee's personal leave requests & balance
// ---------------------------------------------------------------------
if ($method === 'GET') {
    try {
        $stmt = $pdo->prepare('
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
                u.full_name AS reviewer_name
            FROM leaves l
            LEFT JOIN users u ON l.reviewed_by = u.id
            WHERE l.user_id = :uid
            ORDER BY l.id DESC
        ');
        $stmt->execute([':uid' => $userId]);
        $leaves = $stmt->fetchAll();

        // Calculate leave days used this calendar year
        $quotaStmt = $pdo->prepare('
            SELECT 
                leave_type,
                SUM(DATEDIFF(end_date, start_date) + 1) AS days_used
            FROM leaves
            WHERE user_id = :uid AND status = "approved" AND YEAR(start_date) = YEAR(CURDATE())
            GROUP BY leave_type
        ');
        $quotaStmt->execute([':uid' => $userId]);
        $usage = $quotaStmt->fetchAll(PDO::FETCH_KEY_PAIR);

        $casualUsed = (int)($usage['casual'] ?? 0);
        $sickUsed   = (int)($usage['sick'] ?? 0);
        $paidUsed   = (int)($usage['paid'] ?? 0);

        // Standard annual allowances
        $casualAllowance = 12;
        $sickAllowance   = 6;

        jsonResponse(true, 'Leave requests retrieved.', [
            'leaves'  => $leaves,
            'balance' => [
                'casual_remaining' => max(0, $casualAllowance - $casualUsed),
                'sick_remaining'   => max(0, $sickAllowance - $sickUsed),
                'paid_used'        => $paidUsed,
                'total_pending'    => count(array_filter($leaves, fn($l) => $l['status'] === 'pending')),
            ]
        ], 200);

    } catch (PDOException $e) {
        error_log('[EVORA Employee Leaves GET Error] ' . $e->getMessage());
        jsonResponse(false, 'Unable to load leave records.', [], 500);
    }
}

// ---------------------------------------------------------------------
// 2. POST: Apply for a new leave
// ---------------------------------------------------------------------
if ($method === 'POST') {
    requireCsrf();
    $input = getRequestBody();

    $typeRaw   = strtolower(trim((string)($input['leave_type'] ?? 'casual')));
    $startDate = trim((string)($input['start_date'] ?? ''));
    $endDate   = trim((string)($input['end_date'] ?? ''));
    $reason    = trim((string)($input['reason'] ?? ''));

    // Map UI values like "paid annual", "casual leave" to enum values
    $typeMap = [
        'casual'        => 'casual',
        'sick'          => 'sick',
        'paid'          => 'paid',
        'paid annual'   => 'paid',
        'unpaid'        => 'unpaid',
    ];

    $leaveType = $typeMap[$typeRaw] ?? null;
    if ($leaveType === null) {
        jsonResponse(false, 'Invalid leave type. Choose from Casual, Sick, Paid Annual, or Unpaid.', [], 422);
    }

    // Normalize and validate start/end dates flexibly (supports YYYY-MM-DD, DD-MM-YYYY, etc.)
    $normalizeDate = static function(string $raw): ?string {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        // Standard ISO format: YYYY-MM-DD
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $raw, $m)) {
            if (checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
                return sprintf('%04d-%02d-%02d', (int)$m[1], (int)$m[2], (int)$m[3]);
            }
        }

        // Localized format: DD-MM-YYYY or DD/MM/YYYY
        if (preg_match('/^(\d{1,2})[- \/.](\d{1,2})[- \/.](\d{4})$/', $raw, $m)) {
            if (checkdate((int)$m[2], (int)$m[1], (int)$m[3])) {
                return sprintf('%04d-%02d-%02d', (int)$m[3], (int)$m[2], (int)$m[1]);
            }
        }

        $ts = strtotime($raw);
        if ($ts !== false) {
            return date('Y-m-d', $ts);
        }

        return null;
    };

    $startDate = $normalizeDate((string)($input['start_date'] ?? ''));
    $endDate   = $normalizeDate((string)($input['end_date'] ?? ''));

    if (!$startDate || !$endDate) {
        jsonResponse(false, 'Please provide valid start and end dates (YYYY-MM-DD).', [], 422);
    }

    if ($endDate < $startDate) {
        jsonResponse(false, 'End date cannot precede the start date.', [], 422);
    }

    if (empty($reason) || mb_strlen($reason) < 4) {
        jsonResponse(false, 'Please describe the reason for your absence (at least 4 characters).', [], 422);
    }

    try {
        $pdo->beginTransaction();

        // Check for overlapping pending or approved leave requests
        $overlapStmt = $pdo->prepare('
            SELECT id, start_date, end_date, status 
            FROM leaves 
            WHERE user_id = :uid 
              AND status IN ("pending", "approved") 
              AND (start_date <= :end_date AND end_date >= :start_date)
            LIMIT 1
        ');
        $overlapStmt->execute([
            ':uid'        => $userId,
            ':start_date' => $startDate,
            ':end_date'   => $endDate,
        ]);
        $overlapping = $overlapStmt->fetch();

        if ($overlapping) {
            $pdo->rollBack();
            jsonResponse(
                false, 
                "You already have a {$overlapping['status']} leave request covering these dates ({$overlapping['start_date']} to {$overlapping['end_date']}).", 
                [], 
                409
            );
        }

        // Strictly enforce status = 'pending'
        $insertStmt = $pdo->prepare('
            INSERT INTO leaves (user_id, leave_type, start_date, end_date, reason, status, created_at)
            VALUES (:uid, :type, :start, :end, :reason, "pending", NOW())
        ');
        $insertStmt->execute([
            ':uid'    => $userId,
            ':type'   => $leaveType,
            ':start'  => $startDate,
            ':end'    => $endDate,
            ':reason' => $reason,
        ]);

        $newId = (int)$pdo->lastInsertId();

        // Create personal notification
        $notifStmt = $pdo->prepare('
            INSERT INTO notifications (user_id, title, message, is_read, created_at)
            VALUES (:uid, :title, :message, 0, NOW())
        ');
        $notifStmt->execute([
            ':uid'     => $userId,
            ':title'   => 'Leave Request Logged',
            ':message' => sprintf(
                'Your %s leave request from %s to %s has been submitted and is awaiting administrator review.',
                ucfirst($leaveType),
                $startDate,
                $endDate
            ),
        ]);

        $pdo->commit();

        jsonResponse(true, 'Leave application submitted successfully. An administrator will review your request.', [
            'leave' => [
                'id'         => $newId,
                'leave_type' => $leaveType,
                'start_date' => $startDate,
                'end_date'   => $endDate,
                'reason'     => $reason,
                'status'     => 'pending',
            ]
        ], 201);

    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[EVORA Employee Apply Leave Error] ' . $e->getMessage());
        jsonResponse(false, 'Failed to submit leave request. Please try again.', [], 500);
    }
}

jsonResponse(false, 'Method not allowed.', [], 405);
