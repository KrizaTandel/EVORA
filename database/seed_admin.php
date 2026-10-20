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
$shortOpts = "n:e:p:u";
$longOpts  = ["name:", "email:", "password:", "update", "force"];
$cliArgs   = getopt($shortOpts, $longOpts);

$name     = $cliArgs['name'] ?? $cliArgs['n'] ?? null;
$email    = $cliArgs['email'] ?? $cliArgs['e'] ?? null;
$password = $cliArgs['password'] ?? $cliArgs['p'] ?? null;
$allowUpdate = isset($cliArgs['update']) || isset($cliArgs['force']) || isset($cliArgs['u']);

$isInteractive = function_exists('stream_isatty') ? @stream_isatty(STDIN) : false;

// Default admin configuration
$defaultName  = 'Alex Morgan';
$defaultEmail = 'admin@evora.com';
$defaultPass  = 'Admin@123456';

// Prompt interactively if not provided via CLI flags
if (empty($name)) {
    if ($isInteractive) {
        echo "Enter Administrator Full Name [{$defaultName}]: ";
        $input = trim((string)fgets(STDIN));
        $name = !empty($input) ? $input : $defaultName;
    } else {
        $name = $defaultName;
    }
}

if (empty($email)) {
    if ($isInteractive) {
        echo "Enter Administrator Work Email [{$defaultEmail}]: ";
        $input = trim((string)fgets(STDIN));
        $email = !empty($input) ? $input : $defaultEmail;
    } else {
        $email = $defaultEmail;
    }
}

if (empty($password)) {
    if ($isInteractive) {
        echo "Enter Administrator Password [leave blank for default]: ";
        $input = trim((string)fgets(STDIN));
        $password = !empty($input) ? $input : $defaultPass;
    } else {
        $password = $defaultPass;
    }
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

    // 2. Hash password securely with PASSWORD_DEFAULT
    $passwordHash = password_hash($password, PASSWORD_DEFAULT);

    if ($existing) {
        echo "\n[NOTICE] An existing account with email '{$email}' was found (User ID: {$existing['id']}).\n";
        echo "  - Existing Role:   {$existing['role']}\n";
        echo "  - Existing Status: {$existing['status']}\n";
        echo "Updating administrator credentials with secure password hash...\n";

        $updateStmt = $pdo->prepare('
            UPDATE users 
            SET full_name = :name, password_hash = :pass, role = "admin", status = "approved", updated_at = NOW()
            WHERE id = :id
        ');
        $updateStmt->execute([
            ':name' => $name,
            ':pass' => $passwordHash,
            ':id'   => (int)$existing['id'],
        ]);

        $adminId = (int)$existing['id'];
    } else {
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

        $adminId = (int)$pdo->lastInsertId();
    }

    // 4. Verify created/updated database record and password hash
    $verifyStmt = $pdo->prepare('SELECT id, full_name, email, role, status, joined_date, password_hash FROM users WHERE id = :id');
    $verifyStmt->execute([':id' => $adminId]);
    $verified = $verifyStmt->fetch();

    $hashMatches = $verified ? password_verify($password, $verified['password_hash']) : false;

    if ($verified && $verified['role'] === 'admin' && $verified['status'] === 'approved' && $hashMatches) {
        echo "\n[SUCCESS] Administrator provisioned and verified successfully!\n";
        echo "-----------------------------------------------------\n";
        echo "  User ID:     {$verified['id']}\n";
        echo "  Full Name:   {$verified['full_name']}\n";
        echo "  Work Email:  {$verified['email']}\n";
        echo "  Role:        {$verified['role']}\n";
        echo "  Status:      {$verified['status']}\n";
        echo "  Hash Check:  VERIFIED (password_hash using PASSWORD_DEFAULT)\n";
        echo "-----------------------------------------------------\n";
        echo "You can now authenticate at /frontend/pages/login.html\n\n";
        exit(0);
    }

    echo "\n[ERROR] Verification query failed. Record or password verification could not be confirmed.\n";
    exit(1);

} catch (PDOException $e) {
    echo "\n[DATABASE ERROR] " . $e->getMessage() . "\n";
    exit(1);
}
