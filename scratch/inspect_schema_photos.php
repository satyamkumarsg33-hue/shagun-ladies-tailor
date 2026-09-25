<?php
require_once __DIR__ . '/../includes/auth.php';

$pdo = get_db_connection();
$tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
echo "Tables in shagun_ladies_tailor:\n";
print_r($tables);

foreach ($tables as $tbl) {
    if (str_contains($tbl, 'photo') || str_contains($tbl, 'image') || str_contains($tbl, 'gallery') || str_contains($tbl, 'doc') || str_contains($tbl, 'material')) {
        echo "\nSchema for table {$tbl}:\n";
        $cols = $pdo->query("DESCRIBE {$tbl}")->fetchAll(PDO::FETCH_ASSOC);
        print_r($cols);
    }
}
