<?php
require_once __DIR__ . '/../includes/db.php';

$pdo = get_db_connection();
echo "=== ORDERS COLUMNS ===\n";
foreach ($pdo->query("SHOW COLUMNS FROM orders")->fetchAll(PDO::FETCH_ASSOC) as $c) {
    echo "{$c['Field']} => {$c['Type']}\n";
}

echo "\n=== ORDER_PEOPLE COLUMNS ===\n";
foreach ($pdo->query("SHOW COLUMNS FROM order_people")->fetchAll(PDO::FETCH_ASSOC) as $c) {
    echo "{$c['Field']} => {$c['Type']}\n";
}

echo "\n=== ORDER_GARMENTS COLUMNS ===\n";
foreach ($pdo->query("SHOW COLUMNS FROM order_garments")->fetchAll(PDO::FETCH_ASSOC) as $c) {
    echo "{$c['Field']} => {$c['Type']}\n";
}

echo "\n=== ORDER_PAYMENTS COLUMNS ===\n";
foreach ($pdo->query("SHOW COLUMNS FROM order_payments")->fetchAll(PDO::FETCH_ASSOC) as $c) {
    echo "{$c['Field']} => {$c['Type']}\n";
}

echo "\n=== EXISTING ORDERS IN DB ===\n";
$orders = $pdo->query("SELECT id, order_ref, user_id, status, payment_status, is_demo, created_at FROM orders")->fetchAll(PDO::FETCH_ASSOC);
echo json_encode($orders, JSON_PRETTY_PRINT) . "\n";

echo "\n=== FOREIGN KEYS ON ORDERS & RELATED TABLES ===\n";
$fks = $pdo->query("
    SELECT TABLE_NAME, COLUMN_NAME, CONSTRAINT_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
    FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA = 'shagun_ladies_tailor' AND REFERENCED_TABLE_NAME IS NOT NULL
")->fetchAll(PDO::FETCH_ASSOC);
foreach ($fks as $fk) {
    echo "{$fk['TABLE_NAME']}.{$fk['COLUMN_NAME']} -> {$fk['REFERENCED_TABLE_NAME']}.{$fk['REFERENCED_COLUMN_NAME']} ({$fk['CONSTRAINT_NAME']})\n";
}

