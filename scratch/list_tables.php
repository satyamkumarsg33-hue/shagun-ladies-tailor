<?php
require_once __DIR__ . '/../includes/auth.php';
$pdo = get_db_connection();
foreach ($pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN) as $t) {
    echo $t . "\n";
}
