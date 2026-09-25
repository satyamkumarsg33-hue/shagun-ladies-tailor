<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';

try {
    $pdo = get_db_connection();
    $sql = file_get_contents(__DIR__ . '/../database/migrations/002_create_order_document_shares.sql');
    $pdo->exec($sql);
    echo "SUCCESS: order_document_shares table created or already exists.\n";
    
    // Verify table
    $stmt = $pdo->query("SHOW TABLES LIKE 'order_document_shares'");
    $table = $stmt->fetchColumn();
    echo "VERIFIED TABLE: $table\n";

    $stmt = $pdo->query("SHOW COLUMNS FROM order_document_shares");
    $cols = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cols as $col) {
        echo " - {$col['Field']} ({$col['Type']})\n";
    }
} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
