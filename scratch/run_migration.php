<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';

try {
    $pdo = get_db_connection();
    $pdo->exec("ALTER TABLE orders MODIFY COLUMN status VARCHAR(50) NOT NULL DEFAULT 'pending_confirmation'");
    echo "SUCCESS: orders.status column modified to VARCHAR(50) NOT NULL DEFAULT 'pending_confirmation'\n";
    
    // Verify column
    $stmt = $pdo->query("SHOW COLUMNS FROM orders LIKE 'status'");
    $col = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "VERIFIED: Type = {$col['Type']}, Default = {$col['Default']}\n";
} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
