<?php
/**
 * EVORA HRMS — Employee Workspace Dashboard API
 * Core PHP 8.2
 * 
 * Provides real personalized data for the authenticated employee:
 * today's shift status, leave balances, attendance turnout, and shift logs.
 */

declare(strict_types=1);

require_once __DIR__ . '/guard.php';

$pdo = Database::getConnection();
$userId = (int)$currentUser['id'];

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(false, 'Method not allowed. GET required.', [], 405);
}

// Ensure timezone consistency
$env = Database::loadEnv();
$tz = $env['APP_TIMEZONE'] ?? 'Asia/Kolkata';
date_default_timezone_set($tz);

try {
    // 1. Today's attendance record
    $todayStmt = $pdo->prepare('
        SELECT id, date, check_in_time, check_out_time, status, notes
        FROM attendance
        WHERE user_id = :uid AND date = CURDATE()
        LIMIT 1
    ');
    $todayStmt->execute([':uid' => $userId]);
    $todayShift = $todayStmt->fetch() ?: null;

    // 2. Monthly attendance percentage
    $monthStmt = $pdo->prepare('
        SELECT 
            COUNT(*) AS total_logged,
            SUM(CASE WHEN status IN ("present", "late", "half_day") THEN 1 ELSE 0 END) AS present_count
        FROM attendance
        WHERE user_id = :uid AND MONTH(date) = MONTH(CURDATE()) AND YEAR(date) = YEAR(CURDATE())
    ');
    $monthStmt->execute([':uid' => $userId]);
    $monthData = $monthStmt->fetch();

    $loggedCount  = (int)($monthData['total_logged'] ?? 0);
    $presentCount = (int)($monthData['present_count'] ?? 0);
    // Typical work month has ~22 shifts
    $attendancePct = ($loggedCount > 0) ? min(100, round(($presentCount / max($loggedCount, 22)) * 100)) : 100;

    // 3. Leave quota & balance
    $leaveUsageStmt = $pdo->prepare('
        SELECT 
            leave_type,
            SUM(DATEDIFF(end_date, start_date) + 1) AS days_used
        FROM leaves
        WHERE user_id = :uid AND status = "approved" AND YEAR(start_date) = YEAR(CURDATE())
        GROUP BY leave_type
    ');
    $leaveUsageStmt->execute([':uid' => $userId]);
    $usage = $leaveUsageStmt->fetchAll(PDO::FETCH_KEY_PAIR);

    $casualRemaining = max(0, 12 - (int)($usage['casual'] ?? 0));
    $sickRemaining   = max(0, 6 - (int)($usage['sick'] ?? 0));
    $totalRemaining  = $casualRemaining + $sickRemaining;

    // 4. Pending leave requests count
    $pendingLeaveStmt = $pdo->prepare('
        SELECT COUNT(*) FROM leaves 
        WHERE user_id = :uid AND status = "pending"
    ');
    $pendingLeaveStmt->execute([':uid' => $userId]);
    $pendingLeavesCount = (int)$pendingLeaveStmt->fetchColumn();

    // 5. Recent 5 attendance logs
    $recentLogsStmt = $pdo->prepare('
        SELECT id, date, check_in_time, check_out_time, status, notes
        FROM attendance
        WHERE user_id = :uid
        ORDER BY date DESC
        LIMIT 5
    ');
    $recentLogsStmt->execute([':uid' => $userId]);
    $recentLogs = $recentLogsStmt->fetchAll();

    // 6. Weekly attendance rhythm (Mon - Fri of current week)
    $monday = date('Y-m-d', strtotime('monday this week'));
    $weekdays = [];
    for ($i = 0; $i < 5; $i++) {
        $dayDate = date('Y-m-d', strtotime("{$monday} +{$i} days"));
        $dayLabel = date('D', strtotime($dayDate));
        $weekdays[$dayDate] = [
            'date'         => $dayDate,
            'label'        => $dayLabel,
            'hours'        => 0.0,
            'pct'          => 0,
            'status'       => 'absent',
            'check_in'     => null,
            'check_out'    => null,
        ];
    }

    $weekStmt = $pdo->prepare('
        SELECT date, check_in_time, check_out_time, status
        FROM attendance
        WHERE user_id = :uid AND date BETWEEN :mon AND :fri
    ');
    $fri = date('Y-m-d', strtotime("{$monday} +4 days"));
    $weekStmt->execute([':uid' => $userId, ':mon' => $monday, ':fri' => $fri]);
    while ($row = $weekStmt->fetch()) {
        $d = $row['date'];
        if (isset($weekdays[$d])) {
            $weekdays[$d]['status'] = $row['status'];
            $weekdays[$d]['check_in'] = $row['check_in_time'];
            $weekdays[$d]['check_out'] = $row['check_out_time'];

            if (!empty($row['check_in_time']) && !empty($row['check_out_time'])) {
                $secs = strtotime($row['check_out_time']) - strtotime($row['check_in_time']);
                $hrs = round(max(0, $secs) / 3600, 1);
                $weekdays[$d]['hours'] = $hrs;
                $weekdays[$d]['pct'] = min(100, round(($hrs / 9.0) * 100));
            } elseif (!empty($row['check_in_time'])) {
                $weekdays[$d]['hours'] = 8.0;
                $weekdays[$d]['pct'] = 88;
            }
        }
    }

    // 7. Recent notifications
    $notifStmt = $pdo->prepare('
        SELECT id, title, message, is_read, created_at
        FROM notifications
        WHERE user_id = :uid
        ORDER BY created_at DESC
        LIMIT 5
    ');
    $notifStmt->execute([':uid' => $userId]);
    $notifications = $notifStmt->fetchAll();

    jsonResponse(true, 'Employee dashboard data retrieved.', [
        'today' => $todayShift,
        'metrics' => [
            'leaves_remaining'  => $totalRemaining,
            'casual_remaining'  => $casualRemaining,
            'sick_remaining'    => $sickRemaining,
            'pending_leaves'    => $pendingLeavesCount,
            'attendance_pct'    => $attendancePct,
            'shifts_logged'     => $loggedCount,
        ],
        'recent_logs'   => $recentLogs,
        'weekly_rhythm' => array_values($weekdays),
        'notifications' => $notifications,
        'user' => [
            'id'              => $currentUser['id'],
            'name'            => $currentUser['name'],
            'email'           => $currentUser['email'],
            'role'            => $currentUser['role'],
            'department_id'   => $currentUser['department_id'],
            'department_name' => $currentUser['department_name'] ?? 'General',
            'designation'     => $currentUser['designation'] ?? 'Staff Member',
        ]
    ], 200);

} catch (PDOException $e) {
    error_log('[EVORA Employee Dashboard Error] ' . $e->getMessage());
    jsonResponse(false, 'Unable to load workspace metrics.', [], 500);
}
