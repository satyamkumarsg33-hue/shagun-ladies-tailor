<?php
require_once __DIR__ . '/../includes/auth.php';
$pdo = get_db_connection();
$admins = $pdo->query('SELECT id, username, email, role FROM admin_users')->fetchAll();
echo "Admin Users Count: " . count($admins) . "\n";
print_r($admins);
