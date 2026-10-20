<?php
/**
 * EVORA HRMS — Employee API Guard Helper
 * Core PHP 8.2
 * 
 * Include this at the beginning of any employee API endpoint.
 * Enforces an active, approved user session.
 * Rejects unauthenticated requests with 401.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';

// Requires authenticated session strictly with role 'employee'
// Administrators oversee the system through administrative APIs and do not participate in employee clock-in or personal leaves.
$currentUser = requireEmployee();
