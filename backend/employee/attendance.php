<?php
/**
 * EVORA HRMS — Employee Attendance Endpoint
 * Core PHP 8.2
 * 
 * GET:  Retrieve current employee's attendance history & today's shift status
 * POST: Employee self-service check-in and check-out
 * Enforces session identity, duplicate check-in prevention, and timezone consistency.
 */

declare(strict_types=1);

require_once __DIR__ . '/guard.php';

$pdo = Database::getConnection();
$userId = (int)$currentUser['id'];
$method = $_SERVER['REQUEST_METHOD'];

// Ensure timezone consistency
$env = Database::loadEnv();
$tz = $env['APP_TIMEZONE'] ?? 'Asia/Kolkata';
date_default_timezone_set($tz);

// ---------------------------------------------------------------------
// 1. GET: Fetch employee's today status & attendance history
// ---------------------------------------------------------------------
if ($method === 'GET') {
    $monthFilter = trim((string)($_GET['month'] ?? ''));
    $statusFilter = strtolower(trim((string)($_GET['status'] ?? 'all')));

    try {
        // Today's attendance record
        $todayStmt = $pdo->prepare('
            SELECT id, user_id, date, check_in_time, check_out_time, status, notes
            FROM attendance
            WHERE user_id = :uid AND date = CURDATE()
            LIMIT 1
        ');
        $todayStmt->execute([':uid' => $userId]);
        $todayRecord = $todayStmt->fetch() ?: null;

        // Build history query
        $where = ['user_id = :uid'];
        $params = [':uid' => $userId];

        if ($monthFilter !== '' && is_numeric($monthFilter)) {
            $monthNum = (int)$monthFilter;
            if ($monthNum >= 1 && $monthNum <= 12) {
                $where[] = 'MONTH(date) = :month AND YEAR(date) = YEAR(CURDATE())';
                $params[':month'] = $monthNum;
            }
        }

        if ($statusFilter !== 'all' && in_array($statusFilter, ['present', 'late', 'half_day', 'absent'], true)) {
            $where[] = 'status = :status';
            $params[':status'] = $statusFilter;
        }

        $whereSql = 'WHERE ' . implode(' AND ', $where);
        $historyStmt = $pdo->prepare("
            SELECT id, user_id, date, check_in_time, check_out_time, status, notes, created_at
            FROM attendance
            {$whereSql}
            ORDER BY date DESC
            LIMIT 60
        ");
        $historyStmt->execute($params);
        $history = $historyStmt->fetchAll();

        // Calculate attendance summary metrics
        $summaryStmt = $pdo->prepare('
            SELECT 
                COUNT(*) AS total_shifts,
                SUM(CASE WHEN status = "present" THEN 1 ELSE 0 END) AS present_count,
                SUM(CASE WHEN status = "late" THEN 1 ELSE 0 END) AS late_count,
                SUM(CASE WHEN status = "half_day" THEN 1 ELSE 0 END) AS half_day_count
            FROM attendance
            WHERE user_id = :uid AND MONTH(date) = MONTH(CURDATE()) AND YEAR(date) = YEAR(CURDATE())
        ');
        $summaryStmt->execute([':uid' => $userId]);
        $metrics = $summaryStmt->fetch();

        jsonResponse(true, 'Attendance data retrieved.', [
            'today'   => $todayRecord,
            'records' => $history,
            'metrics' => [
                'total_shifts'    => (int)($metrics['total_shifts'] ?? 0),
                'present_count'   => (int)($metrics['present_count'] ?? 0),
                'late_count'      => (int)($metrics['late_count'] ?? 0),
                'half_day_count'  => (int)($metrics['half_day_count'] ?? 0),
            ],
            'current_time' => date('h:i A'),
            'current_date' => date('Y-m-d'),
        ], 200);

    } catch (PDOException $e) {
        error_log('[EVORA Employee Attendance GET Error] ' . $e->getMessage());
        jsonResponse(false, 'Unable to load attendance records.', [], 500);
    }
}

// ---------------------------------------------------------------------
// 2. POST: Employee Check-In or Check-Out
// ---------------------------------------------------------------------
if ($method === 'POST') {
    requireCsrf();
    $input = getRequestBody();
    $action = strtolower(trim((string)($input['action'] ?? '')));

    if (!in_array($action, ['check_in', 'check_out'], true)) {
        jsonResponse(false, 'Invalid attendance action specified. Must be "check_in" or "check_out".', [], 422);
    }

    $today = date('Y-m-d');
    $currentTime = date('H:i:s');

    try {
        $pdo->beginTransaction();

        // Check if an attendance record exists for today
        $stmt = $pdo->prepare('
            SELECT id, user_id, date, check_in_time, check_out_time, status, notes
            FROM attendance
            WHERE user_id = :uid AND date = :today
            LIMIT 1
            FOR UPDATE
        ');
        $stmt->execute([':uid' => $userId, ':today' => $today]);
        $existing = $stmt->fetch();

        // ----------------- Action: CHECK IN -----------------
        if ($action === 'check_in') {
            if ($existing && !empty($existing['check_in_time'])) {
                $pdo->rollBack();
                $formattedTime = date('h:i A', strtotime($existing['check_in_time']));
                jsonResponse(
                    false, 
                    "You have already clocked in today at {$formattedTime}.", 
                    ['record' => $existing], 
                    409
                );
            }

            // Determine status: Shift standard starts at 09:15 AM
            $cutoffTime = '09:15:00';
            $status = ($currentTime > $cutoffTime) ? 'late' : 'present';
            $notes = ($status === 'late') ? 'Late arrival recorded' : 'Standard check-in';

            if ($existing) {
                // Update record if created previously
                $updateStmt = $pdo->prepare('
                    UPDATE attendance 
                    SET check_in_time = :time, status = :status, notes = :notes 
                    WHERE id = :id
                ');
                $updateStmt->execute([
                    ':time'   => $currentTime,
                    ':status' => $status,
                    ':notes'  => $notes,
                    ':id'     => $existing['id'],
                ]);
                $recordId = (int)$existing['id'];
            } else {
                // Insert fresh attendance row
                $insertStmt = $pdo->prepare('
                    INSERT INTO attendance (user_id, date, check_in_time, status, notes, created_at)
                    VALUES (:uid, :today, :time, :status, :notes, NOW())
                ');
                $insertStmt->execute([
                    ':uid'    => $userId,
                    ':today'  => $today,
                    ':time'   => $currentTime,
                    ':status' => $status,
                    ':notes'  => $notes,
                ]);
                $recordId = (int)$pdo->lastInsertId();
            }

            $pdo->commit();

            $formattedTime = date('h:i A', strtotime($currentTime));
            jsonResponse(true, "Successfully clocked in at {$formattedTime}. Status: " . ucfirst($status) . ".", [
                'action' => 'check_in',
                'record' => [
                    'id'             => $recordId,
                    'date'           => $today,
                    'check_in_time'  => $currentTime,
                    'check_out_time' => null,
                    'status'         => $status,
                    'notes'          => $notes,
                ]
            ], 200);
        }

        // ----------------- Action: CHECK OUT -----------------
        if ($action === 'check_out') {
            if (!$existing || empty($existing['check_in_time'])) {
                $pdo->rollBack();
                jsonResponse(false, 'Cannot clock out: You have not clocked in for today yet.', [], 422);
            }

            if (!empty($existing['check_out_time'])) {
                $pdo->rollBack();
                $formattedTime = date('h:i A', strtotime($existing['check_out_time']));
                jsonResponse(
                    false, 
                    "You have already clocked out today at {$formattedTime}.", 
                    ['record' => $existing], 
                    409
                );
            }

            $updateStmt = $pdo->prepare('
                UPDATE attendance 
                SET check_out_time = :time 
                WHERE id = :id
            ');
            $updateStmt->execute([
                ':time' => $currentTime,
                ':id'   => $existing['id'],
            ]);

            $pdo->commit();

            $formattedTime = date('h:i A', strtotime($currentTime));
            jsonResponse(true, "Shift concluded. Successfully clocked out at {$formattedTime}.", [
                'action' => 'check_out',
                'record' => [
                    'id'             => $existing['id'],
                    'date'           => $today,
                    'check_in_time'  => $existing['check_in_time'],
                    'check_out_time' => $currentTime,
                    'status'         => $existing['status'],
                ]
            ], 200);
        }

    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[EVORA Attendance Action Error] ' . $e->getMessage());
        jsonResponse(false, 'An internal error occurred while recording attendance.', [], 500);
    }
}

jsonResponse(false, 'Method not allowed.', [], 405);
