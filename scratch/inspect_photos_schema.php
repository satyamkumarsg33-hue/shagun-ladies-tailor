<?php
require_once __DIR__ . '/../includes/auth.php';
$pdo = get_db_connection();

echo "\nStages currently in order_gallery_photos:\n";
$stages = $pdo->query('SELECT stage, count(*) as cnt FROM order_gallery_photos GROUP BY stage')->fetchAll(PDO::FETCH_ASSOC);
foreach ($stages as $s) {
    echo "  Stage '{$s['stage']}': {$s['cnt']} photos\n";
}

echo "\nSample rows:\n";
$rows = $pdo->query('SELECT p.id, p.order_id, o.order_ref, p.stage, p.original_filename, p.photo_url FROM order_gallery_photos p LEFT JOIN orders o ON o.id = p.order_id LIMIT 10')->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) {
    echo "  [{$r['id']}] order_ref: {$r['order_ref']}, stage: {$r['stage']}, path: {$r['photo_url']}\n";
}
