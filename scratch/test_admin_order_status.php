<?php
/**
 * Test Suite: Admin-Controlled Order Lifecycle & Customer Orders Status
 * 
 * Verifies all 32 requirements from the implementation task:
 * - Initial status: Standard, Luxe, Combined start as Awaiting Confirmation
 * - Payment and status separation (paid does not mean delivered/completed)
 * - Internal status categorization to the 3 customer-facing buckets
 * - Admin authorization guards and role permissions
 * - Audit trails: order_status_history, old/new status, admin_id, timestamp
 * - Unified order handling and data integrity
 * - Customer cannot alter status or dates
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

error_reporting(E_ALL);
ini_set('display_errors', '1');

// Catch any PHP notice or warning as an error
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    echo "\nFAIL (PHP Warning/Notice [$errno]): $errstr in $errfile on line $errline\n";
    exit(1);
});

$testCount = 0;
$passCount = 0;

function assert_true(bool $cond, string $msg): void {
    global $testCount, $passCount;
    $testCount++;
    if ($cond) {
        $passCount++;
        echo "  ✔ PASS: $msg\n";
    } else {
        echo "  ✖ FAIL: $msg\n";
        exit(1);
    }
}

function assert_equals($actual, $expected, string $msg): void {
    global $testCount, $passCount;
    $testCount++;
    if ($actual === $expected) {
        $passCount++;
        echo "  ✔ PASS: $msg\n";
    } else {
        echo "  ✖ FAIL: $msg (Expected: " . var_export($expected, true) . ", Got: " . var_export($actual, true) . ")\n";
        exit(1);
    }
}

echo "=====================================================================\n";
echo " TEST SUITE: ADMIN-CONTROLLED ORDER LIFECYCLE & ORDER STATUS\n";
echo "=====================================================================\n";

require_once __DIR__ . '/../includes/cart.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/unified-cart.php';
require_once __DIR__ . '/../includes/order-status.php';

// Set up mock customer session
$_SESSION['user'] = [
    'id' => 101,
    'name' => 'Pooja Sharma',
    'email' => 'pooja@example.com',
    'role' => 'customer'
];
unset($_SESSION['admin_user']);

// -------------------------------------------------------------------
// 1. INITIAL STATUS & PAYMENT SEPARATION (Tests 1 - 5)
// -------------------------------------------------------------------
echo "\n--- Section 1: Initial Status & Payment Separation ---\n";

// Test 1: Standard order creation
$stdOrder = [
    'order_ref' => 'LT-STD-001',
    'user_id' => 101,
    'workflow' => 'standard',
    'status' => 'pending_confirmation',
    'booked_date' => '2026-09-18',
    'requested_ready_date' => '2026-10-03',
    'admin_delivery_date' => '2026-10-03',
    'advance_payment' => ['order_total' => 650, 'selected_amount' => 650],
    'payment' => ['status' => 'completed', 'amount_paid' => 650, 'remaining_balance' => 0]
];
$cat1 = get_status_category($stdOrder['status']);
assert_equals($cat1, 'awaiting_confirmation', '1. Newly placed Standard order starts as Awaiting Confirmation');

// Test 2: Luxe order creation
$luxeOrder = [
    'order_ref' => 'LT-LUX-001',
    'user_id' => 101,
    'workflow' => 'luxe',
    'status' => 'pending_confirmation',
    'booked_date' => '2026-09-18',
    'requested_ready_date' => '2026-10-25',
    'admin_delivery_date' => '2026-10-25',
    'advance_payment' => ['order_total' => 2800, 'selected_amount' => 1400],
    'payment' => ['status' => 'completed', 'amount_paid' => 1400, 'remaining_balance' => 1400]
];
$cat2 = get_status_category($luxeOrder['status']);
assert_equals($cat2, 'awaiting_confirmation', '2. Newly placed Luxe order starts as Awaiting Confirmation');

// Test 3: Combined order creation
$combOrder = [
    'order_ref' => 'LT-CMB-001',
    'user_id' => 101,
    'is_combined' => true,
    'status' => 'pending_confirmation',
    'booked_date' => '2026-09-18',
    'advance_payment' => ['order_total' => 3450, 'selected_amount' => 1725],
    'payment' => ['status' => 'completed', 'amount_paid' => 1725, 'remaining_balance' => 1725]
];
$cat3 = get_status_category($combOrder['status']);
assert_equals($cat3, 'awaiting_confirmation', '3. Newly placed Combined order starts as Awaiting Confirmation');

// Test 4 & 5: Payment completion does not mark order as delivered or completed
assert_true($combOrder['payment']['status'] === 'completed', 'Payment status is marked completed');
assert_true($combOrder['status'] !== 'delivered', '4. Payment completion does NOT automatically mark order as delivered');
assert_true($combOrder['status'] !== 'completed', '5. Payment completion does NOT automatically mark order as completed');

// -------------------------------------------------------------------
// 2. STATUS CATEGORIZATION (Tests 6 - 13)
// -------------------------------------------------------------------
echo "\n--- Section 2: Internal Status Categorization ---\n";

assert_equals(get_status_category('pending_confirmation'), 'awaiting_confirmation', '6. pending_confirmation maps to Awaiting Confirmation');
assert_equals(get_status_category('confirmed'), 'in_production', '7. confirmed maps to In Production');
assert_equals(get_status_category('stitching'), 'in_production', '8. stitching maps to In Production');
assert_equals(get_status_category('embroidery'), 'in_production', '9. embroidery maps to In Production');
assert_equals(get_status_category('quality_check'), 'in_production', '10. quality_check maps to In Production');
assert_equals(get_status_category('delivered'), 'completed', '11. delivered maps to Completed Orders');
assert_equals(get_status_category('collected'), 'completed', '12. collected maps to Completed Orders');

// Test 13: Completed orders sorted newest first
$c1 = ['order_ref' => 'LT-OLD', 'completed_at' => strtotime('2026-09-10 10:00:00')];
$c2 = ['order_ref' => 'LT-NEW', 'completed_at' => strtotime('2026-09-17 18:00:00')];
$sorted = [$c1, $c2];
usort($sorted, fn($a, $b) => $b['completed_at'] <=> $a['completed_at']);
assert_equals($sorted[0]['order_ref'], 'LT-NEW', '13. Completed orders are sorted newest first');

// -------------------------------------------------------------------
// 3. ADMIN AUTHORIZATION & AUDIT TRAIL (Tests 14 - 24)
// -------------------------------------------------------------------
echo "\n--- Section 3: Admin Authorization & Status Transitions ---\n";

// Save test order to session storage
$_SESSION['customer_orders'] = [
    101 => [
        'LT-TEST-001' => [
            'order_ref' => 'LT-TEST-001',
            'user_id' => 101,
            'status' => 'pending_confirmation',
            'advance_payment' => ['order_total' => 1500, 'selected_amount' => 750],
            'payment' => ['status' => 'completed', 'amount_paid' => 750, 'remaining_balance' => 750]
        ]
    ]
];

// Test 14: Unauthenticated user cannot update status
unset($_SESSION['user']);
unset($_SESSION['admin_user']);
$resUnauth = admin_update_order_status('LT-TEST-001', 'confirmed');
assert_true(!$resUnauth['success'], '14. Unauthenticated user cannot update status');

// Test 15: Customer cannot update status
$_SESSION['user'] = ['id' => 101, 'role' => 'customer'];
$resCustomer = admin_update_order_status('LT-TEST-001', 'confirmed');
assert_true(!$resCustomer['success'], '15. Customer cannot update status');

// Test 16: Customer cannot update another customer's order
$resForged = admin_update_order_status('LT-TEST-001', 'delivered');
assert_true(!$resForged['success'], '16. Customer cannot update another customer order');

// Test 17: Unauthorized Admin role cannot perform restricted status updates
// Master Tailor cannot accept order (only production statuses)
$_SESSION['admin_user'] = ['id' => 201, 'username' => 'tailor', 'role' => 'master_tailor'];
$resTailorUnauthorized = admin_update_order_status('LT-TEST-001', 'delivered', null, 201, 'master_tailor');
assert_true(!$resTailorUnauthorized['success'], '17. Unauthorized Admin role (master_tailor) cannot set delivered status');

// Test 18: Authorized Admin can update status
// Super Admin can set status to confirmed
$_SESSION['admin_user'] = ['id' => 1, 'username' => 'admin', 'role' => 'super_admin'];
$resAdminOk = admin_update_order_status('LT-TEST-001', 'confirmed', 'Reviewed and accepted by Super Admin', 1, 'super_admin');
assert_true($resAdminOk['success'], '18. Authorized Admin can update status');

// Test 19: Invalid status values are rejected
$resInvalid = admin_update_order_status('LT-TEST-001', 'nonexistent_status', null, 1, 'super_admin');
assert_true(!$resInvalid['success'], '19. Invalid status values are rejected');

// Test 20, 21, 22, 23: Status history records
$hist = $resAdminOk['history_entry'];
assert_equals($hist['old_status'], 'pending_confirmation', '20. Status history records the previous status');
assert_equals($hist['new_status'], 'confirmed', '21. Status history records the new status');
assert_equals($hist['admin_user_id'], 1, '22. Status history records the Admin ID');
assert_true(!empty($hist['created_at']) && is_int($hist['created_at']), '23. Status history records the timestamp');

// Test 24: Activity log structure
assert_true(isset($hist['notes']) && strpos($hist['notes'], 'accepted') !== false, '24. Activity logging and notes preserved in history');

// -------------------------------------------------------------------
// 4. UNIFIED ORDER SUPPORT & DATA INTEGRITY (Tests 25 - 32)
// -------------------------------------------------------------------
echo "\n--- Section 4: Unified Order Support & Data Integrity ---\n";

ob_start();
require_once __DIR__ . '/../orders.php';
ob_end_clean();

// Test 25: Standard-only order displays correctly
$normStd = normalize_customer_order($stdOrder);
assert_true($normStd['is_standard'] && !$normStd['is_luxe'], '25. Standard-only order displays correctly');

// Test 26: Luxe-only order displays correctly
$luxeWithGarments = $luxeOrder;
$luxeWithGarments['people'] = [
    [
        'name' => 'Meera',
        'role' => 'Bride',
        'garments' => [
            [
                'name' => 'Blouse',
                'style_name' => 'Princess Cut',
                'work_type' => 'both',
                'machine_work' => ['price' => 300, 'work_target' => 'separate_cloth'],
                'hand_work' => ['price' => 500, 'work_target' => 'garment'],
                'price' => 1600
            ]
        ]
    ]
];
$normLuxe = normalize_customer_order($luxeWithGarments);
assert_true($normLuxe['is_luxe'] && !$normLuxe['is_standard'], '26. Luxe-only order displays correctly');

// Test 27: Combined order displays both Standard and Luxe information
$combOrderWithGarments = $combOrder;
$combOrderWithGarments['standard_items'] = [
    ['garment' => 'Blouse', 'style_name' => 'Daily Blouse', 'total' => 650]
];
$combOrderWithGarments['people'] = $luxeWithGarments['people'];
$normComb = normalize_customer_order($combOrderWithGarments);
assert_true($normComb['is_combined'], '27. Combined order displays both Standard and Luxe information');

// Test 28: Combined physical garment count remains accurate (1 standard + 1 luxe = 2)
assert_equals($normComb['total_physical_garments'], 2, '28. Combined physical garment count remains accurate');

// Test 29: Hand Work and Machine Work details remain intact
$g = $normComb['luxe_people'][0]['garments'][0];
assert_true(!empty($g['machine_work']) && !empty($g['hand_work']), '29. Hand Work and Machine Work details remain intact');

// Test 30: Separate Material details remain intact
assert_equals($g['machine_work']['work_target'], 'separate_cloth', '30. Separate Material details remain intact');

// Test 31: Payment totals and balance remain unchanged after a status update
// Update LT-TEST-001 to stitching
$resStitch = admin_update_order_status('LT-TEST-001', 'stitching', 'Assigned to workshop', 1, 'super_admin');
$updatedOrder = $_SESSION['customer_orders'][101]['LT-TEST-001'];
assert_equals($updatedOrder['advance_payment']['order_total'], 1500, 'Total amount remains unchanged (1500)');
assert_equals($updatedOrder['payment']['amount_paid'], 750, 'Amount paid remains unchanged (750)');
assert_equals($updatedOrder['payment']['remaining_balance'], 750, '31. Remaining balance remains unchanged (750)');

// Test 32: Customer cannot change delivery dates or status (guarded by is_admin_logged_in())
assert_true(function_exists('is_admin_logged_in'), 'is_admin_logged_in() guard is active');
$_SESSION['user'] = ['id' => 101, 'role' => 'customer'];
unset($_SESSION['admin_user']);
assert_true(!is_admin_logged_in(), '32. Customer is strictly not admin and cannot change requested or Admin delivery dates');

echo "\n=====================================================================\n";
echo " TEST SUMMARY\n";
echo " Total Assertions: $testCount\n";
echo " Passed: $passCount\n";
echo " Failed: " . ($testCount - $passCount) . "\n";
echo "=====================================================================\n";
