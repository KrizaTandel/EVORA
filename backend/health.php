<?php
/**
 * EVORA HRMS — Database Health Check Endpoint
 * 
 * DEVELOPMENT ONLY:
 * Verifies live MySQL connectivity using the shared PDO singleton.
 * Returns clean JSON without exposing sensitive credentials, SQL errors, or system paths.
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . '/config/database.php';

try {
    // Attempt connection via the shared singleton
    $pdo = Database::getConnection();

    // Harmless, non-destructive check query
    $stmt = $pdo->query('SELECT 1 AS alive');
    $result = $stmt->fetch();

    if ($result && isset($result['alive']) && (int)$result['alive'] === 1) {
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'message' => 'Database connection successful'
        ], JSON_UNESCAPED_SLASHES);
        exit;
    }

    throw new RuntimeException('Health verification query failed');
} catch (Throwable $e) {
    // Return generic error; do not expose internal exceptions or credentials
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database connection failed'
    ], JSON_UNESCAPED_SLASHES);
    exit;
}
