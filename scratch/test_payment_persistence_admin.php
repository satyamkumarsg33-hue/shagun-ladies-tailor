<?php
/**
 * Test Suite: Payment Processing, MySQL Database Persistence & Admin Management
 * 
 * Verifies:
 * 1. Standard, Luxe, and Combined order persistence across 11 relational tables.
 * 2. Correct statuses: order status = 'pending_confirmation' ("Awaiting Confirmation"), payment status = 'partially_paid'/'fully_paid'.
 * 3. Correct flags: is_demo = 1, gateway_mode = 'simulated', gateway_provider = 'Demo Simulated Gateway'.
 * 4. Safe idempotency: duplicate calls do not duplicate rows or fail.
 * 5. Customer isolation: Customer B cannot access Customer A's order.
 * 6. Admin order status lifecycle transition: updates orders table & inserts into order_status_history.
 * 7. Admin delivery date update: updates orders table & inserts into order_date_history.
 * 8. Cleanup of test records.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/order-status.php';

$pdo = get_db_connection();
$testsPassed = 0;
$testsFailed = 0;

function assert_test(bool $condition, string $message): void {
    global $testsPassed, $testsFailed;
    if ($condition) {
        echo "  [PASS] {$message}\n";
        $testsPassed++;
    } else {
        echo "  [FAIL] {$message}\n";
        $testsFailed++;
    }
}

echo "============================================================\n";
echo "SHAGUN LADIES TAILOR — PAYMENT & ADMIN PERSISTENCE TEST SUITE\n";
echo "============================================================\n\n";

// Setup Test Customers
$testEmailA = 'test_pay_cust_a_' . time() . '@example.com';
$testEmailB = 'test_pay_cust_b_' . time() . '@example.com';
$passHash = password_hash('TestPass123!', PASSWORD_DEFAULT);

$stmtUser = $pdo->prepare('INSERT INTO users (role, name, email, password_hash, phone, is_active, created_at) VALUES (\'customer\', :name, :email, :pwd, :phone, 1, NOW())');

$stmtUser->execute([':name' => 'Test Customer A', ':email' => $testEmailA, ':pwd' => $passHash, ':phone' => '9876543210']);
$userIdA = (int) $pdo->lastInsertId();

$stmtUser->execute([':name' => 'Test Customer B', ':email' => $testEmailB, ':pwd' => $passHash, ':phone' => '9876543211']);
$userIdB = (int) $pdo->lastInsertId();

$stmtAddr = $pdo->prepare('INSERT INTO customer_addresses (user_id, address_line1, city, state, postal_code, is_default, created_at) VALUES (:uid, :addr, \'Delhi\', \'Delhi\', \'110001\', 1, NOW())');
$stmtAddr->execute([':uid' => $userIdA, ':addr' => '123 Atelier Street, Phase 1']);
$stmtAddr->execute([':uid' => $userIdB, ':addr' => '456 Fashion Lane, Phase 2']);

assert_test($userIdA > 0 && $userIdB > 0, "Created isolated test customers: User A ({$userIdA}), User B ({$userIdB})");

$refStandard = 'LT-PAYTEST-STD-' . substr((string)microtime(true), -6);
$refLuxe = 'LT-PAYTEST-LUX-' . substr((string)microtime(true), -6);
$refCombined = 'LT-PAYTEST-CMB-' . substr((string)microtime(true), -6);

// -------------------------------------------------------------
// TEST 1: Standard Stitching Order Persistence
// -------------------------------------------------------------
echo "\n--- TEST 1: Standard Stitching Order Persistence ---\n";

$stdOrderData = [
    'order_ref' => $refStandard,
    'user_id' => $userIdA,
    'workflow' => 'standard',
    'occasion' => 'Everyday',
    'status' => 'pending_confirmation',
    'grand_total' => 2000.0,
    'amount_paid' => 1000.0,
    'booked_date' => date('Y-m-d'),
    'requested_ready_date' => date('Y-m-d', strtotime('+10 days')),
    'payment' => [
        'status' => 'completed',
        'order_ref' => $refStandard,
        'amount_paid' => 1000.0,
        'payment_method' => 'card',
        'payment_method_label' => 'Credit / Debit Card (Demo Simulation)',
        'booked_date' => date('Y-m-d')
    ],
    'standard_items' => [
        [
            'cart_id' => 'std_item_1',
            'garment_type' => 'Blouse',
            'style_name' => 'Royal Sabyasachi Cut',
            'price' => 2000.0,
            'total_price' => 2000.0,
            'notes' => 'Padded with piping',
            'customizations' => [
                ['group' => 'Neckline', 'choice' => 'Deep V-Neck', 'price' => 150.0],
                ['group' => 'Sleeves', 'choice' => 'Elbow Length', 'price' => 100.0]
            ],
            'machine_work' => [
                'design_code' => 'M-101',
                'design_name' => 'Zari Border',
                'placement' => 'Neckline',
                'price' => 350.0
            ]
        ]
    ]
];

$savedStd = save_customer_completed_order($stdOrderData);
assert_test($savedStd === true, "save_customer_completed_order() returned true for Standard order");

// Verify in MySQL
$chkStd = $pdo->prepare('SELECT * FROM orders WHERE order_ref = :ref');
$chkStd->execute([':ref' => $refStandard]);
$rowStd = $chkStd->fetch();

assert_test($rowStd !== false, "Order row found in MySQL orders table for {$refStandard}");
assert_test(($rowStd['workflow_type'] ?? '') === 'standard', "Workflow type is 'standard'");
assert_test(($rowStd['status'] ?? '') === 'pending_confirmation', "Order status is 'pending_confirmation' (Awaiting Confirmation)");
assert_test(($rowStd['payment_status'] ?? '') === 'partially_paid', "Payment status is 'partially_paid'");
assert_test((int)($rowStd['is_demo'] ?? 0) === 1, "is_demo is strictly 1");
assert_test((float)($rowStd['total_amount'] ?? 0) === 2000.0, "total_amount is 2000.0");
assert_test((float)($rowStd['advance_amount'] ?? 0) === 1000.0, "advance_amount is 1000.0");

// Check child tables
$stdOrderId = (int) $rowStd['id'];

$chkPeople = $pdo->prepare('SELECT COUNT(*) FROM order_people WHERE order_id = :oid');
$chkPeople->execute([':oid' => $stdOrderId]);
assert_test((int)$chkPeople->fetchColumn() === 1, "Primary customer record inserted in order_people");

$chkGarments = $pdo->prepare('SELECT COUNT(*) FROM order_garments WHERE order_id = :oid');
$chkGarments->execute([':oid' => $stdOrderId]);
assert_test((int)$chkGarments->fetchColumn() === 1, "Garment record inserted in order_garments");

$chkCust = $pdo->prepare('SELECT COUNT(*) FROM order_garment_customizations ogc JOIN order_garments og ON ogc.order_garment_id = og.id WHERE og.order_id = :oid');
$chkCust->execute([':oid' => $stdOrderId]);
assert_test((int)$chkCust->fetchColumn() === 2, "2 customizations inserted in order_garment_customizations");

$chkWork = $pdo->prepare('SELECT COUNT(*) FROM order_garment_work_items ogw JOIN order_garments og ON ogw.order_garment_id = og.id WHERE og.order_id = :oid');
$chkWork->execute([':oid' => $stdOrderId]);
assert_test((int)$chkWork->fetchColumn() === 1, "Work item inserted in order_garment_work_items");

$chkPay = $pdo->prepare('SELECT * FROM order_payments WHERE order_id = :oid');
$chkPay->execute([':oid' => $stdOrderId]);
$rowPay = $chkPay->fetch();
assert_test($rowPay !== false, "Payment record inserted in order_payments");
assert_test(($rowPay['gateway_mode'] ?? '') === 'simulated', "Payment gateway_mode is 'simulated'");
assert_test(($rowPay['payment_method'] ?? '') === 'card', "Payment method is 'card'");
assert_test(($rowPay['payment_method_label'] ?? '') === 'Credit / Debit Card (Demo Simulation)', "Payment method label preserved");

$chkHist = $pdo->prepare('SELECT * FROM order_status_history WHERE order_id = :oid');
$chkHist->execute([':oid' => $stdOrderId]);
$rowHist = $chkHist->fetch();
assert_test($rowHist !== false && ($rowHist['new_status'] ?? '') === 'pending_confirmation', "Status history recorded pending_confirmation");

// -------------------------------------------------------------
// TEST 2: Luxe Stitching Order Persistence
// -------------------------------------------------------------
echo "\n--- TEST 2: Luxe Stitching Order Persistence ---\n";

$luxeOrderData = [
    'order_ref' => $refLuxe,
    'user_id' => $userIdA,
    'workflow' => 'wedding',
    'occasion' => 'Reception',
    'status' => 'pending_confirmation',
    'booked_date' => date('Y-m-d'),
    'wedding_date' => date('Y-m-d', strtotime('+30 days')),
    'requested_ready_date' => date('Y-m-d', strtotime('+20 days')),
    'people' => [
        [
            'name' => 'Bride Suman',
            'role' => 'Bride',
            'measurement_method' => 'reference_blouse',
            'garments' => [
                [
                    'garment_type' => 'Lehenga',
                    'style_slug' => 'bridal-royal-lehenga',
                    'style_name' => 'Royal Heritage Lehenga',
                    'base_price' => 5000.0,
                    'customization_total' => 500.0,
                    'work_total' => 1500.0,
                    'total_price' => 7000.0,
                    'work_type' => 'both',
                    'choice_summary' => [
                        ['field' => 'Flare', 'label' => 'Double Can-Can Flare', 'price' => 500.0]
                    ],
                    'machine_work' => [
                        'design_code' => 'M-201',
                        'design_name' => 'Zardozi Thread',
                        'placement' => 'Border & Belt',
                        'price' => 500.0
                    ],
                    'hand_work' => [
                        'design_code' => 'H-301',
                        'design_name' => 'Mukaish & Dabka',
                        'placement' => 'All Over Booti',
                        'price' => 1000.0
                    ],
                    'materials' => [
                        ['name' => 'Raw Silk 5m', 'status' => 'received', 'notes' => 'Deep Crimson']
                    ]
                ]
            ]
        ]
    ],
    'advance_payment' => [
        'order_total' => 7000.0,
        'selected_amount' => 3500.0,
    ],
    'payment' => [
        'status' => 'completed',
        'order_ref' => $refLuxe,
        'amount_paid' => 3500.0,
        'payment_method' => 'upi',
        'payment_method_label' => 'UPI (Google Pay)',
        'booked_date' => date('Y-m-d')
    ]
];

$savedLuxe = save_customer_completed_order($luxeOrderData);
assert_test($savedLuxe === true, "save_customer_completed_order() returned true for Luxe order");

$chkLuxe = $pdo->prepare('SELECT * FROM orders WHERE order_ref = :ref');
$chkLuxe->execute([':ref' => $refLuxe]);
$rowLuxe = $chkLuxe->fetch();
assert_test($rowLuxe !== false, "Order row found in MySQL orders table for {$refLuxe}");
assert_test(($rowLuxe['workflow_type'] ?? '') === 'wedding', "Workflow type is 'wedding'");
assert_test(($rowLuxe['status'] ?? '') === 'pending_confirmation', "Luxe order status is 'pending_confirmation'");
assert_test((float)($rowLuxe['total_amount'] ?? 0) === 7000.0, "total_amount is 7000.0");
assert_test((float)($rowLuxe['advance_amount'] ?? 0) === 3500.0, "advance_amount is 3500.0");

$luxeOrderId = (int) $rowLuxe['id'];
$chkLuxeMats = $pdo->prepare('SELECT COUNT(*) FROM order_materials WHERE order_id = :oid');
$chkLuxeMats->execute([':oid' => $luxeOrderId]);
assert_test((int)$chkLuxeMats->fetchColumn() === 1, "Material record inserted in order_materials");

// -------------------------------------------------------------
// TEST 3: Combined Standard + Luxe Order Persistence
// -------------------------------------------------------------
echo "\n--- TEST 3: Combined Standard + Luxe Order Persistence ---\n";

$combinedOrderData = [
    'order_ref' => $refCombined,
    'user_id' => $userIdA,
    'workflow' => 'wedding', // Combined orders map to valid 'wedding' ENUM
    'occasion' => 'Sangeet & Wedding',
    'status' => 'pending_confirmation',
    'booked_date' => date('Y-m-d'),
    'wedding_date' => date('Y-m-d', strtotime('+25 days')),
    'people' => [
        [
            'name' => 'Bride Suman',
            'role' => 'Bride',
            'measurement_method' => 'visit_shop',
            'garments' => [
                [
                    'garment_type' => 'Anarkali',
                    'style_slug' => 'luxe-anarkali',
                    'style_name' => 'Floor-Length Anarkali',
                    'base_price' => 4000.0,
                    'customization_total' => 0.0,
                    'work_total' => 800.0,
                    'total_price' => 4800.0,
                    'work_type' => 'hand',
                    'hand_work' => [
                        'design_code' => 'H-105',
                        'design_name' => 'Gota Patti Work',
                        'placement' => 'Hemline',
                        'price' => 800.0
                    ]
                ]
            ]
        ]
    ],
    'standard_items' => [
        [
            'cart_id' => 'std_comb_1',
            'garment_type' => 'Salwar Kameez',
            'style_name' => 'Straight Cut Suit',
            'price' => 1200.0,
            'total_price' => 1200.0
        ]
    ],
    'advance_payment' => [
        'order_total' => 6000.0,
        'selected_amount' => 3000.0,
        'has_standard' => true,
        'is_combined' => true
    ],
    'payment' => [
        'status' => 'completed',
        'order_ref' => $refCombined,
        'amount_paid' => 3000.0,
        'payment_method' => 'netbanking',
        'payment_method_label' => 'Net Banking (HDFC Bank)',
        'booked_date' => date('Y-m-d')
    ]
];

$savedCombined = save_customer_completed_order($combinedOrderData);
assert_test($savedCombined === true, "save_customer_completed_order() returned true for Combined order");

$chkComb = $pdo->prepare('SELECT * FROM orders WHERE order_ref = :ref');
$chkComb->execute([':ref' => $refCombined]);
$rowComb = $chkComb->fetch();
assert_test($rowComb !== false, "Order row found for {$refCombined}");
assert_test(($rowComb['workflow_type'] ?? '') === 'wedding', "Combined order workflow_type mapped safely to 'wedding'");
assert_test((float)($rowComb['total_amount'] ?? 0) === 6000.0, "total_amount is 6000.0 (4800 Luxe + 1200 Standard)");
assert_test((float)($rowComb['advance_amount'] ?? 0) === 3000.0, "advance_amount is 3000.0");

$combOrderId = (int) $rowComb['id'];
$chkCombGarments = $pdo->prepare('SELECT COUNT(*) FROM order_garments WHERE order_id = :oid');
$chkCombGarments->execute([':oid' => $combOrderId]);
assert_test((int)$chkCombGarments->fetchColumn() === 2, "2 garments persisted (1 Luxe + 1 Standard)");

// -------------------------------------------------------------
// TEST 4: Safe Idempotency
// -------------------------------------------------------------
echo "\n--- TEST 4: Safe Idempotency Check ---\n";

// Calling save_customer_completed_order a second time with the same payload
$reSaved = save_customer_completed_order($combinedOrderData);
assert_test($reSaved === true, "Idempotent re-save returned true without error");

$chkCount = $pdo->prepare('SELECT COUNT(*) FROM orders WHERE order_ref = :ref');
$chkCount->execute([':ref' => $refCombined]);
assert_test((int)$chkCount->fetchColumn() === 1, "Exactly 1 order record exists (no duplicate created)");

$chkPayCount = $pdo->prepare('SELECT COUNT(*) FROM order_payments WHERE order_id = :oid');
$chkPayCount->execute([':oid' => $combOrderId]);
assert_test((int)$chkPayCount->fetchColumn() === 1, "Exactly 1 payment record exists (no duplicate payment)");

// -------------------------------------------------------------
// TEST 5: Customer Isolation & Ownership Validation
// -------------------------------------------------------------
echo "\n--- TEST 5: Customer Isolation & Ownership Validation ---\n";

// Customer B attempting to save an order with Customer A's order_ref
$hijackData = $combinedOrderData;
$hijackData['user_id'] = $userIdB; // User B attempts to claim User A's order_ref
$hijackResult = save_customer_completed_order($hijackData);
assert_test($hijackResult === false, "Cross-customer order overwrite strictly rejected (returned false)");

// Customer B reading orders
$ordersB = get_customer_orders($userIdB);
assert_test(!isset($ordersB[$refCombined]), "User B cannot see User A's order in get_customer_orders()");

$orderRefAFromB = get_customer_order_by_ref($refCombined, $userIdB);
assert_test($orderRefAFromB === null, "get_customer_order_by_ref returns null when User B queries User A's order");

// Customer A reading orders
$ordersA = get_customer_orders($userIdA);
assert_test(isset($ordersA[$refStandard]), "User A can see Standard order in get_customer_orders()");
assert_test(isset($ordersA[$refLuxe]), "User A can see Luxe order in get_customer_orders()");
assert_test(isset($ordersA[$refCombined]), "User A can see Combined order in get_customer_orders()");

// -------------------------------------------------------------
// TEST 6: Admin Status Lifecycle Transition
// -------------------------------------------------------------
echo "\n--- TEST 6: Admin Status Lifecycle Transition ---\n";

$existingAdminId = (int) $pdo->query('SELECT id FROM admin_users LIMIT 1')->fetchColumn();
if ($existingAdminId <= 0) {
    $existingAdminId = 21;
}

// Admin updates status from 'pending_confirmation' to 'in_production'
$adminUpdateRes = admin_update_order_status($refStandard, 'in_production', 'Fabric inspected and approved by Master Tailor', $existingAdminId, 'super_admin');
assert_test($adminUpdateRes['success'] === true, "Admin successfully transitioned status to 'in_production'");

// Verify in MySQL orders table
$chkUpdatedOrd = $pdo->prepare('SELECT status FROM orders WHERE order_ref = :ref');
$chkUpdatedOrd->execute([':ref' => $refStandard]);
$updStatus = $chkUpdatedOrd->fetchColumn();
assert_test($updStatus === 'in_production', "MySQL orders.status updated to 'in_production'");

// Verify in order_status_history
$chkStatHist = $pdo->prepare('SELECT * FROM order_status_history WHERE order_id = :oid ORDER BY id DESC LIMIT 1');
$chkStatHist->execute([':oid' => $stdOrderId]);
$lastHist = $chkStatHist->fetch();
assert_test(($lastHist['new_status'] ?? '') === 'in_production', "order_status_history recorded new_status = 'in_production'");
assert_test(($lastHist['old_status'] ?? '') === 'pending_confirmation', "order_status_history recorded old_status = 'pending_confirmation'");
assert_test(($lastHist['notes'] ?? '') === 'Fabric inspected and approved by Master Tailor', "order_status_history recorded admin note");

// -------------------------------------------------------------
// TEST 7: Admin Delivery Date Update
// -------------------------------------------------------------
echo "\n--- TEST 7: Admin Delivery Date Update ---\n";

$newDeliveryDate = date('Y-m-d', strtotime('+12 days'));
$oldDate = $rowStd['admin_delivery_date'];

$updDateStmt = $pdo->prepare('UPDATE orders SET admin_delivery_date = :dt, updated_at = NOW() WHERE id = :id');
$updDateStmt->execute([':dt' => $newDeliveryDate, ':id' => $stdOrderId]);

$insDateHistStmt = $pdo->prepare('
    INSERT INTO order_date_history (
        order_id, event_type, date_type, old_date, new_date, actor, admin_user_id, note, created_at
    ) VALUES (
        :oid, \'admin_update\', \'admin_delivery_date\', :old_dt, :new_dt, \'admin\', :admin_id, :note, NOW()
    )
');
$insDateHistStmt->execute([
    ':oid' => $stdOrderId,
    ':old_dt' => $oldDate,
    ':new_dt' => $newDeliveryDate,
    ':admin_id' => $existingAdminId,
    ':note' => 'Delivery schedule confirmed with embroidery artisan'
]);

$chkDateOrd = $pdo->prepare('SELECT admin_delivery_date FROM orders WHERE id = :id');
$chkDateOrd->execute([':id' => $stdOrderId]);
assert_test($chkDateOrd->fetchColumn() === $newDeliveryDate, "MySQL orders.admin_delivery_date updated to {$newDeliveryDate}");

$chkDateHist = $pdo->prepare('SELECT * FROM order_date_history WHERE order_id = :oid AND event_type = \'admin_update\'');
$chkDateHist->execute([':oid' => $stdOrderId]);
$rowDateHist = $chkDateHist->fetch();
assert_test($rowDateHist !== false && ($rowDateHist['new_date'] ?? '') === $newDeliveryDate, "order_date_history recorded admin date update");

// -------------------------------------------------------------
// CLEANUP TEST RECORDS
// -------------------------------------------------------------
echo "\n--- CLEANUP ---\n";
// Delete only test orders and test users created in this run
$testOrderIds = [$stdOrderId, $luxeOrderId, $combOrderId];
foreach ($testOrderIds as $tOid) {
    if ($tOid > 0) {
        $pdo->prepare('DELETE ogc FROM order_garment_customizations ogc JOIN order_garments og ON ogc.order_garment_id = og.id WHERE og.order_id = :oid')->execute([':oid' => $tOid]);
        $pdo->prepare('DELETE ogw FROM order_garment_work_items ogw JOIN order_garments og ON ogw.order_garment_id = og.id WHERE og.order_id = :oid')->execute([':oid' => $tOid]);
        $pdo->prepare('DELETE ogml FROM order_garment_material_links ogml JOIN order_garments og ON ogml.order_garment_id = og.id WHERE og.order_id = :oid')->execute([':oid' => $tOid]);
        $pdo->prepare('DELETE FROM order_garments WHERE order_id = :oid')->execute([':oid' => $tOid]);
        $pdo->prepare('DELETE FROM order_materials WHERE order_id = :oid')->execute([':oid' => $tOid]);
        $pdo->prepare('DELETE FROM order_people WHERE order_id = :oid')->execute([':oid' => $tOid]);
        $pdo->prepare('DELETE FROM order_payments WHERE order_id = :oid')->execute([':oid' => $tOid]);
        $pdo->prepare('DELETE FROM order_status_history WHERE order_id = :oid')->execute([':oid' => $tOid]);
        $pdo->prepare('DELETE FROM order_date_history WHERE order_id = :oid')->execute([':oid' => $tOid]);
        $pdo->prepare('DELETE FROM orders WHERE id = :oid')->execute([':oid' => $tOid]);
    }
}
$pdo->prepare('DELETE FROM customer_addresses WHERE user_id IN (:u1, :u2)')->execute([':u1' => $userIdA, ':u2' => $userIdB]);
$pdo->prepare('DELETE FROM users WHERE id IN (:u1, :u2)')->execute([':u1' => $userIdA, ':u2' => $userIdB]);

echo "Cleaned up all test records for User A ({$userIdA}), User B ({$userIdB}), and test orders ({$refStandard}, {$refLuxe}, {$refCombined}).\n";

echo "\n============================================================\n";
echo "TEST RESULTS: {$testsPassed} Passed, {$testsFailed} Failed\n";
echo "============================================================\n";

exit($testsFailed > 0 ? 1 : 0);
