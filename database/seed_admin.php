<?php
/**
 * EVORA HRMS — Secure Initial Admin Account Seeder
 * 
 * Usage:
 *   CLI: php database/seed_admin.php
 *   Browser: http://localhost/EVORA/database/seed_admin.php
 * 
 * Project Rule Enforced:
 *   Admin accounts must be provisioned securely.
 *   Public signup must NEVER allow a user to choose the admin role.
 */

// Helper to load simple .env file if it exists
function loadEnv($path) {
    if (!file_exists($path)) {
        return [];
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $env = [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || strpos($line, '#') === 0) continue;
        if (strpos($line, '=') !== false) {
            list($key, $val) = explode('=', $line, 2);
            $key = trim($key);
            $val = trim($val);
            $val = trim($val, "\"'");
            $env[$key] = $val;
        }
    }
    return $env;
}

$rootPath = dirname(__DIR__);
$env = loadEnv($rootPath . '/.env');

$dbHost = $env['DB_HOST'] ?? '127.0.0.1';
$dbPort = $env['DB_PORT'] ?? '3306';
$dbName = $env['DB_NAME'] ?? 'evora_hrms';
$dbUser = $env['DB_USER'] ?? 'root';
$dbPass = $env['DB_PASS'] ?? '';

$isCli = (php_sapi_name() === 'cli');

function outputMsg($msg, $isCli, $type = 'info') {
    if ($isCli) {
        echo "[$type] $msg\n";
    } else {
        $colors = [
            'info' => '#2D4739',
            'success' => '#1F3327',
            'warning' => '#D4A373',
            'error' => '#C86D51'
        ];
        $color = $colors[$type] ?? '#333';
        echo "<p style='font-family: monospace; color: {$color}; margin: 6px 0;'><strong>[{$type}]</strong> {$msg}</p>";
    }
}

if (!$isCli) {
    echo "<!DOCTYPE html><html lang='en'><head><meta charset='UTF-8'><title>EVORA HRMS - Admin Seeder</title>";
    echo "<body style='background: #FBF9F5; color: #2D4739; padding: 2rem; font-family: sans-serif;'>";
    echo "<div style='max-width: 600px; margin: 0 auto; background: #F4F0E8; padding: 1.5rem; border-radius: 8px; border: 1px solid #8A9A86;'>";
    echo "<h2 style='color: #2D4739; margin-top: 0;'>EVORA HRMS — Initial Admin Seeder</h2>";
}

try {
    $dsn = "mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset=utf8mb4";
    $pdo = new PDO($dsn, $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    outputMsg("Connected to MySQL database '{$dbName}' successfully.", $isCli, 'info');

    // Check if an admin already exists
    $stmt = $pdo->prepare("SELECT id, email, full_name FROM users WHERE role = 'admin' LIMIT 1");
    $stmt->execute();
    $existingAdmin = $stmt->fetch();

    if ($existingAdmin) {
        outputMsg("An administrator account already exists (ID: {$existingAdmin['id']}, Email: {$existingAdmin['email']}).", $isCli, 'warning');
        outputMsg("For security, seeder will not overwrite existing admin accounts.", $isCli, 'info');
    } else {
        // Default credentials for initial local setup
        $adminName = 'System Administrator';
        $adminEmail = 'admin@evora.local';
        $adminPassword = 'AdminPassword123!';
        $passwordHash = password_hash($adminPassword, PASSWORD_BCRYPT);

        $insertStmt = $pdo->prepare("
            INSERT INTO users (full_name, email, password_hash, role, status, designation, joined_date)
            VALUES (:name, :email, :pass, 'admin', 'approved', 'Super Administrator', CURDATE())
        ");

        $insertStmt->execute([
            ':name' => $adminName,
            ':email' => $adminEmail,
            ':pass' => $passwordHash,
        ]);

        outputMsg("Default Administrator account provisioned successfully!", $isCli, 'success');
        outputMsg("Email: {$adminEmail}", $isCli, 'info');
        outputMsg("Temporary Password: {$adminPassword}", $isCli, 'info');
        outputMsg("Role: admin | Status: approved", $isCli, 'info');
        outputMsg("IMPORTANT: Change this password immediately after your first login.", $isCli, 'warning');
    }

} catch (PDOException $e) {
    outputMsg("Database Error: " . $e->getMessage(), $isCli, 'error');
    outputMsg("Please verify that MySQL is running in XAMPP and that 'database/evora_hrms.sql' has been imported.", $isCli, 'warning');
}

if (!$isCli) {
    echo "<hr style='border: none; border-top: 1px solid #8A9A86; margin: 1.5rem 0;'>";
    echo "<a href='../frontend/pages/login.html' style='display: inline-block; background: #2D4739; color: #FBF9F5; padding: 10px 18px; text-decoration: none; border-radius: 6px; font-weight: bold;'>Go to Login Page &rarr;</a>";
    echo "</div></body></html>";
}
