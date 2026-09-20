<?php

declare(strict_types=1);

/**
 * Shagun Ladies Tailor — Admin User Creation CLI Utility
 *
 * Usage:
 *   C:\xampp\php\php.exe bin/create-admin.php
 *   or non-interactive:
 *   C:\xampp\php\php.exe bin/create-admin.php --username=admin --email=admin@shagun.com --name="Master Admin" --role=super_admin --password=secret
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo "Forbidden: This script can only be run via command line.\n";
    exit(1);
}

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/db.php';

$validRoles = ['super_admin', 'master_tailor', 'store_manager'];

$options = getopt('', ['username:', 'email:', 'name:', 'role:', 'password:', 'phone:']);

$username = $options['username'] ?? null;
$email = $options['email'] ?? null;
$fullName = $options['name'] ?? null;
$role = $options['role'] ?? null;
$password = $options['password'] ?? null;
$phone = $options['phone'] ?? null;

// If required arguments are missing and not interactive, show usage
$isInteractive = defined('STDIN') && stream_isatty(STDIN);

if (!$isInteractive && (empty($username) || empty($email) || empty($fullName) || empty($password))) {
    echo "Usage: C:\\xampp\\php\\php.exe bin/create-admin.php --username=<username> --email=<email> --name=\"<Full Name>\" --password=<password> [--role=super_admin|master_tailor|store_manager] [--phone=<phone>]\n";
    exit(1);
}

// Interactive prompts if not passed as CLI flags
if (empty($username)) {
    echo "Enter Admin Username: ";
    $username = trim((string) fgets(STDIN));
}

if (empty($email)) {
    echo "Enter Admin Email: ";
    $email = trim((string) fgets(STDIN));
}

if (empty($fullName)) {
    echo "Enter Full Name: ";
    $fullName = trim((string) fgets(STDIN));
}

if (empty($role)) {
    echo "Choose Role [super_admin, master_tailor, store_manager] (default: store_manager): ";
    $roleInput = trim((string) fgets(STDIN));
    $role = !empty($roleInput) ? $roleInput : 'store_manager';
}

if (!in_array($role, $validRoles, true)) {
    echo "Error: Invalid role '{$role}'. Must be one of: " . implode(', ', $validRoles) . "\n";
    exit(1);
}

if (empty($password)) {
    echo "Enter Password: ";
    $password = trim((string) fgets(STDIN));
}

if (strlen($password) < 6) {
    echo "Error: Password must be at least 6 characters long.\n";
    exit(1);
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo "Error: Invalid email format '{$email}'.\n";
    exit(1);
}

try {
    $pdo = get_db_connection();
    
    // Check for existing username or email
    $checkStmt = $pdo->prepare('SELECT id, username, email FROM admin_users WHERE username = :u OR email = :e LIMIT 1');
    $checkStmt->execute([':u' => $username, ':e' => $email]);
    $existing = $checkStmt->fetch();
    
    if ($existing) {
        if (strcasecmp($existing['username'], $username) === 0) {
            echo "Error: Admin username '{$username}' already exists (ID: {$existing['id']}).\n";
        } else {
            echo "Error: Admin email '{$email}' already exists (ID: {$existing['id']}).\n";
        }
        exit(1);
    }
    
    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
    
    $insertStmt = $pdo->prepare('
        INSERT INTO admin_users (username, email, password_hash, full_name, role, phone, is_active)
        VALUES (:username, :email, :hash, :full_name, :role, :phone, 1)
    ');
    
    $insertStmt->execute([
        ':username' => $username,
        ':email' => strtolower($email),
        ':hash' => $passwordHash,
        ':full_name' => $fullName,
        ':role' => $role,
        ':phone' => $phone ?: null,
    ]);
    
    $newId = $pdo->lastInsertId();
    echo "Admin user created successfully! ID: {$newId} ({$fullName} [{$role}] <{$email}>)\n";
    exit(0);

} catch (Throwable $e) {
    echo "Database error: " . $e->getMessage() . "\n";
    exit(1);
}
