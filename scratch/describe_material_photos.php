<?php
require_once __DIR__ . '/../includes/auth.php';
$pdo = get_db_connection();
print_r($pdo->query("DESCRIBE material_photos")->fetchAll(PDO::FETCH_ASSOC));
