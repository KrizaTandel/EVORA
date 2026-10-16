<?php
/**
 * EVORA HRMS — CLI-Only Initial Administrator Provisioner
 * Core PHP 8.2
 * 
 * Usage:
 *   php database/seed_admin.php
 *   php database/seed_admin.php --name="Alex Morgan" --email="admin@evora.com" --password="YourPassword"
 * 
 * Security Enforcements:
 *   - Strictly forbidden from web/HTTP execution.
 *   - Uses password_hash() with PASSWORD_DEFAULT.
 *   - Rejects execution if account already exists (does not overwrite).
 *   - Never prints password hashes or sensitive server details.
 */

declare(strict_types=1);

// Strictly block browser access
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    die("Access Denied: The administrator provisioner is restricted to the Command Line Interface (CLI).\n");
}

require_once dirname(__DIR__) . '/backend/config/database.php';

echo "=====================================================\n";
echo " EVORA HRMS — Secure Administrator Provisioning Tool \n";
echo "=====================================================\n\n";

// Parse CLI command-line options
$shortOpts = "n:e:p:";
$longOpts  = ["name:", "email:", "password:"];
$cliArgs   = getopt($shortOpts, $longOpts);

$name     = $cliArgs['name'] ?? $cliArgs['n'] ?? null;
$email    = $cliArgs['email'] ?? $cliArgs['e'] ?? null;
$password = $cliArgs['password'] ?? $cliArgs['p'] ?? null;

// Prompt interactively if not provided via CLI flags
if (empty($name)) {
    echo "Enter Administrator Full Name: ";
    $name = trim((string)fgets(STDIN));
}

if (empty($email)) {
    echo "Enter Administrator Work Email: ";
    $email = trim((string)fgets(STDIN));
}

if (empty($password)) {
    echo "Enter Administrator Password: ";
    // Read password
    $password = trim((string)fgets(STDIN));
}

$email = strtolower(trim((string)$email));
$name  = trim((string)$name);

if (empty($name) || empty($email) || empty($password)) {
    echo "\n[ERROR] Full name, email, and password are required to provision an administrator.\n";
    exit(1);
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo "\n[ERROR] Invalid email format: '{$email}'.\n";
    exit(1);
}

if (mb_strlen($password) < 8) {
    echo "\n[ERROR] Password must be at least 8 characters in length.\n";
    exit(1);
}

try {
    $pdo = Database::getConnection();

    // 1. Check if an account already exists with this email
    $checkStmt = $pdo->prepare('SELECT id, full_name, role, status FROM users WHERE email = :email LIMIT 1');
    $checkStmt->execute([':email' => $email]);
    $existing = $checkStmt->fetch();

    if ($existing) {
        echo "\n[WARNING] An account with email '{$email}' already exists.\n";
        echo "  - Existing User ID: {$existing['id']}\n";
        echo "  - Existing Role:    {$existing['role']}\n";
        echo "  - Existing Status:  {$existing['status']}\n";
        echo "[SECURITY] Refusing to overwrite existing account or credentials.\n";
        exit(0);
    }

    // 2. Hash password with PASSWORD_DEFAULT
    $passwordHash = password_hash($password, PASSWORD_DEFAULT);

    // 3. Insert new verified Administrator
    $insertStmt = $pdo->prepare('
        INSERT INTO users (full_name, email, password_hash, role, status, designation, joined_date, created_at)
        VALUES (:name, :email, :pass, "admin", "approved", "System Administrator", CURDATE(), NOW())
    ');

    $insertStmt->execute([
        ':name'  => $name,
        ':email' => $email,
        ':pass'  => $passwordHash,
    ]);

    $newId = (int)$pdo->lastInsertId();

    // 4. Verify created database record
    $verifyStmt = $pdo->prepare('SELECT id, full_name, email, role, status, joined_date FROM users WHERE id = :id');
    $verifyStmt->execute([':id' => $newId]);
    $verified = $verifyStmt->fetch();

    if ($verified && $verified['role'] === 'admin' && $verified['status'] === 'approved') {
        echo "\n[SUCCESS] Initial Administrator provisioned successfully!\n";
        echo "-----------------------------------------------------\n";
        echo "  User ID:     {$verified['id']}\n";
        echo "  Full Name:   {$verified['full_name']}\n";
        echo "  Work Email:  {$verified['email']}\n";
        echo "  Role:        {$verified['role']}\n";
        echo "  Status:      {$verified['status']}\n";
        echo "  Joined Date: {$verified['joined_date']}\n";
        echo "-----------------------------------------------------\n";
        echo "You can now authenticate with these credentials at /frontend/pages/login.html\n\n";
        exit(0);
    }

    echo "\n[ERROR] Verification query failed. Record was not confirmed.\n";
    exit(1);

} catch (PDOException $e) {
    echo "\n[DATABASE ERROR] " . $e->getMessage() . "\n";
    exit(1);
}
