<?php
/**
 * EVORA HRMS — Shared Departments List Endpoint
 * Core PHP 8.2
 * 
 * Provides read-only list of organizational departments for signup,
 * directory filters, and employee creation dropdowns.
 */

declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(false, 'Method not allowed. GET required.', [], 405);
}

try {
    $pdo = Database::getConnection();
    $stmt = $pdo->query('SELECT id, name, code, description FROM departments ORDER BY name ASC');
    $departments = $stmt->fetchAll();

    jsonResponse(true, 'Departments retrieved.', ['departments' => $departments], 200);

} catch (PDOException $e) {
    error_log('[EVORA Public Departments Error] ' . $e->getMessage());
    jsonResponse(false, 'Unable to load organizational departments.', [], 500);
}
