<?php
require_once __DIR__ . '/../includes/db.php';
$pdo = get_db_connection();
$cols = $pdo->query('SHOW COLUMNS FROM order_documents')->fetchAll(PDO::FETCH_ASSOC);
echo "Columns in order_documents:\n";
foreach ($cols as $col) {
    echo " - {$col['Field']} ({$col['Type']})\n";
}
$rows = $pdo->query('SELECT * FROM order_documents')->fetchAll(PDO::FETCH_ASSOC);
echo "Rows in order_documents: " . count($rows) . "\n";
foreach ($rows as $r) {
    echo json_encode($r) . "\n";
}
