<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';

try {
    $pdo = get_db_connection();
    $sql = file_get_contents(__DIR__ . '/../database/migrations/001_create_order_gallery_photos.sql');
    $pdo->exec($sql);
    echo "SUCCESS: order_gallery_photos table created or already exists.\n";
    
    // Verify table
    $stmt = $pdo->query("SHOW TABLES LIKE 'order_gallery_photos'");
    $table = $stmt->fetchColumn();
    echo "VERIFIED TABLE: $table\n";

    $stmt = $pdo->query("SHOW COLUMNS FROM order_gallery_photos");
    $cols = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cols as $col) {
        echo " - {$col['Field']} ({$col['Type']})\n";
    }
} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
