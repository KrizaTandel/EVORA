<?php
/**
 * EVORA HRMS — Administrator Operations Dashboard Data API
 * Core PHP 8.2
 * 
 * Aggregates real MySQL metrics for workforce headcount, registration queues,
 * attendance rates, active leaves, and department headcount allocation.
 */

declare(strict_types=1);

require_once __DIR__ . '/guard.php';

$pdo = Database::getConnection();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(false, 'Method not allowed. GET required.', [], 405);
}

try {
    // 1. Core organizational stats
    $statsStmt = $pdo->query('
        SELECT 
            (SELECT COUNT(*) FROM users WHERE status = "approved") AS total_staff,
            (SELECT COUNT(*) FROM users WHERE status = "pending") AS pending_approvals,
            (SELECT COUNT(*) FROM attendance WHERE date = CURDATE() AND status IN ("present", "late", "half_day")) AS present_today,
            (SELECT COUNT(*) FROM leaves WHERE status = "approved" AND CURDATE() BETWEEN start_date AND end_date) AS active_leaves,
            (SELECT COUNT(*) FROM leaves WHERE status = "pending") AS pending_leaves,
            (SELECT COUNT(*) FROM departments) AS total_departments
    ');
    $stats = $statsStmt->fetch();

    $totalStaff = (int)($stats['total_staff'] ?? 0);
    $presentToday = (int)($stats['present_today'] ?? 0);
    $turnoutPct = ($totalStaff > 0) ? round(($presentToday / $totalStaff) * 100, 1) : 0;

    // 2. Department distribution
    $deptStmt = $pdo->query('
        SELECT 
            d.id, 
            d.name, 
            d.code, 
            COUNT(u.id) AS staff_count
        FROM departments d
        LEFT JOIN users u ON u.department_id = d.id AND u.status = "approved"
        GROUP BY d.id
        ORDER BY staff_count DESC, d.name ASC
    ');
    $departments = $deptStmt->fetchAll();

    // Calculate percentage per department
    foreach ($departments as &$dept) {
        $count = (int)$dept['staff_count'];
        $dept['percentage'] = ($totalStaff > 0) ? round(($count / $totalStaff) * 100) : 0;
    }
    unset($dept);

    // 3. Weekly attendance distribution (current Monday - Friday)
    // Find current Monday
    $monday = date('Y-m-d', strtotime('monday this week'));
    $weekdays = [];
    for ($i = 0; $i < 5; $i++) {
        $dayDate = date('Y-m-d', strtotime("{$monday} +{$i} days"));
        $dayName = date('D', strtotime($dayDate));
        $weekdays[$dayDate] = [
            'date'  => $dayDate,
            'label' => $dayName,
            'count' => 0,
            'pct'   => 0,
        ];
    }

    $weekStmt = $pdo->prepare('
        SELECT date, COUNT(*) AS present_count
        FROM attendance
        WHERE date BETWEEN :mon AND :fri AND status IN ("present", "late", "half_day")
        GROUP BY date
    ');
    $fri = date('Y-m-d', strtotime("{$monday} +4 days"));
    $weekStmt->execute([':mon' => $monday, ':fri' => $fri]);
    while ($row = $weekStmt->fetch()) {
        $d = $row['date'];
        if (isset($weekdays[$d])) {
            $cnt = (int)$row['present_count'];
            $weekdays[$d]['count'] = $cnt;
            $weekdays[$d]['pct'] = ($totalStaff > 0) ? min(100, round(($cnt / $totalStaff) * 100)) : 0;
        }
    }

    // 4. Pending registration approval queue preview (up to 5 latest)
    $queueStmt = $pdo->query('
        SELECT 
            u.id, 
            u.full_name, 
            u.email, 
            u.phone, 
            u.designation,
            u.joined_date, 
            u.created_at,
            d.name AS department_name
        FROM users u
        LEFT JOIN departments d ON u.department_id = d.id
        WHERE u.status = "pending"
        ORDER BY u.created_at DESC
        LIMIT 5
    ');
    $pendingQueue = $queueStmt->fetchAll();

    jsonResponse(true, 'Admin dashboard metrics retrieved.', [
        'stats' => [
            'total_staff'       => $totalStaff,
            'pending_approvals' => (int)($stats['pending_approvals'] ?? 0),
            'present_today'     => $presentToday,
            'turnout_pct'       => $turnoutPct,
            'active_leaves'     => (int)($stats['active_leaves'] ?? 0),
            'pending_leaves'    => (int)($stats['pending_leaves'] ?? 0),
            'total_departments' => (int)($stats['total_departments'] ?? 0),
        ],
        'departments'   => $departments,
        'weekly_trends' => array_values($weekdays),
        'pending_queue' => $pendingQueue,
    ], 200);

} catch (PDOException $e) {
    error_log('[EVORA Admin Dashboard Error] ' . $e->getMessage());
    jsonResponse(false, 'Unable to load dashboard analytics.', [], 500);
}
