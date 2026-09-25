<?php
require_once __DIR__ . '/../includes/auth.php';

$pdo = get_db_connection();

echo "--- ORDERS TABLE ---\n";
$orders = $pdo->query("SELECT o.id, o.order_ref, o.user_id, o.total_amount, o.advance_amount, o.balance_amount, o.status, o.payment_status, o.is_demo, u.name, u.email FROM orders o LEFT JOIN users u ON o.user_id = u.id ORDER BY o.id DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
print_r($orders);

echo "\n--- ORDER PAYMENTS TABLE ---\n";
$payments = $pdo->query("SELECT op.id, op.order_id, op.payment_stage, op.amount, op.payment_method, op.status, op.gateway_mode, op.paid_at FROM order_payments op ORDER BY op.id DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
print_r($payments);

echo "\n--- SPECIFIC SEARCH FOR '673' or 'Kamal' ---\n";
$kamalOrders = $pdo->query("SELECT * FROM orders WHERE order_ref LIKE '%673%' OR user_id IN (SELECT id FROM users WHERE name LIKE '%Kamal%')")->fetchAll(PDO::FETCH_ASSOC);
print_r($kamalOrders);

if (!empty($kamalOrders)) {
    foreach ($kamalOrders as $ko) {
        $oid = (int)$ko['id'];
        $kop = $pdo->query("SELECT * FROM order_payments WHERE order_id = {$oid}")->fetchAll(PDO::FETCH_ASSOC);
        echo "Payments for order {$oid} ({$ko['order_ref']}):\n";
        print_r($kop);
    }
}
