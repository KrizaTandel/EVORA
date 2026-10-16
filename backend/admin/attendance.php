<?php
/**
 * EVORA HRMS — Administrator Attendance Audit API
 * Core PHP 8.2
 * 
 * GET: Filter daily organizational attendance logs by date, department, and status
 */

declare(strict_types=1);

require_once __DIR__ . '/guard.php';

$pdo = Database::getConnection();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(false, 'Method not allowed. GET required.', [], 405);
}

// Ensure timezone consistency
$env = Database::loadEnv();
$tz = $env['APP_TIMEZONE'] ?? 'Asia/Kolkata';
date_default_timezone_set($tz);

$date = trim((string)($_GET['date'] ?? date('Y-m-d')));
$deptId = isset($_GET['department_id']) ? (int)$_GET['department_id'] : 0;
$statusFilter = strtolower(trim((string)($_GET['status'] ?? '')));

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    $date = date('Y-m-d');
}

$where = ['a.date = :date'];
$params = [':date' => $date];

if ($deptId > 0) {
    $where[] = 'u.department_id = :dept_id';
    $params[':dept_id'] = $deptId;
}

if (!empty($statusFilter) && in_array($statusFilter, ['present', 'late', 'half_day', 'absent'], true)) {
    $where[] = 'a.status = :status';
    $params[':status'] = $statusFilter;
}

$whereSql = 'WHERE ' . implode(' AND ', $where);

try {
    $sql = "
        SELECT 
            a.id, 
            a.user_id, 
            a.date, 
            a.check_in_time, 
            a.check_out_time, 
            a.status, 
            a.notes, 
            a.created_at,
            u.full_name, 
            u.email, 
            u.designation,
            d.name AS department_name, 
            d.code AS department_code
        FROM attendance a
        INNER JOIN users u ON a.user_id = u.id
        LEFT JOIN departments d ON u.department_id = d.id
        {$whereSql}
        ORDER BY a.check_in_time ASC, u.full_name ASC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $records = $stmt->fetchAll();

    // Summary counts for this date
    $sumStmt = $pdo->prepare('
        SELECT 
            COUNT(*) AS total_logged,
            SUM(CASE WHEN a.status = "present" THEN 1 ELSE 0 END) AS present_count,
            SUM(CASE WHEN a.status = "late" THEN 1 ELSE 0 END) AS late_count,
            SUM(CASE WHEN a.status = "half_day" THEN 1 ELSE 0 END) AS half_day_count
        FROM attendance a
        WHERE a.date = :date
    ');
    $sumStmt->execute([':date' => $date]);
    $summary = $sumStmt->fetch();

    jsonResponse(true, 'Attendance audit log retrieved.', [
        'selected_date' => $date,
        'records'       => $records,
        'total'         => count($records),
        'summary'       => [
            'total_logged'    => (int)($summary['total_logged'] ?? 0),
            'present_count'   => (int)($summary['present_count'] ?? 0),
            'late_count'      => (int)($summary['late_count'] ?? 0),
            'half_day_count'  => (int)($summary['half_day_count'] ?? 0),
        ]
    ], 200);

} catch (PDOException $e) {
    error_log('[EVORA Admin Attendance GET Error] ' . $e->getMessage());
    jsonResponse(false, 'Failed to fetch attendance audit log.', [], 500);
}
