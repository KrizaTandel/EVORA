<?php
/**
 * EVORA HRMS — Administrator API Guard Helper
 * Core PHP 8.2
 * 
 * Include this at the beginning of any admin API endpoint.
 * Enforces an active, approved administrator session.
 * Rejects unauthenticated requests with 401 and non-admin requests with 403.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';

// Requires authenticated session with role 'admin'
$currentAdmin = requireAdmin();
