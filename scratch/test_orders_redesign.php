<?php
/**
 * Test Suite: PHP Warning Fix, Cart Cleanup Synchronization, and Orders Redesign
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

error_reporting(E_ALL);
ini_set('display_errors', '1');

// Catch any PHP notice/warning as an error
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    echo "\nFAIL (PHP Warning/Notice [$errno]): $errstr in $errfile on line $errline\n";
    exit(1);
});

$_SESSION['user'] = [
    'id' => 101,
    'name' => 'Pooja Sharma',
    'email' => 'pooja@example.com',
    'role' => 'customer'
];

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
echo " TEST SUITE: ORDERS REDESIGN, WARNING FIX & CART CLEANUP\n";
echo "=====================================================================\n";

require_once 'C:/xampp/htdocs/shagun-ladies-tailor/includes/cart.php';
require_once 'C:/xampp/htdocs/shagun-ladies-tailor/includes/auth.php';
require_once 'C:/xampp/htdocs/shagun-ladies-tailor/includes/unified-cart.php';

// -------------------------------------------------------------------
// TEST GROUP 1: No Undefined Variable $workType Warning Under E_ALL
// -------------------------------------------------------------------
echo "\nTEST GROUP 1: Undefined Variable Fix in get_unified_basket()\n";
$_SESSION['demo_cart'] = [];
$_SESSION['luxe_wedding'] = [
    'people' => [
        [
            'name' => 'Kavita',
            'role' => 'Sister of Bride',
            'garments' => [
                [
                    // No work_type specified, only machine_work
                    'name' => 'Blouse',
                    'style_slug' => 'princess-cut',
                    'machine_work' => ['price' => 350, 'work_target' => 'separate_cloth']
                ],
                [
                    // No work_type specified, only hand_work
                    'name' => 'Blouse',
                    'style_slug' => 'boat-neck',
                    'hand_work' => ['price' => 500, 'work_target' => 'garment']
                ],
                [
                    // No work_type specified, both machine and hand
                    'name' => 'Blouse',
                    'style_slug' => 'v-neck',
                    'machine_work' => ['price' => 250],
                    'hand_work' => ['price' => 450]
                ],
                [
                    // No work at all
                    'name' => 'Blouse',
                    'style_slug' => 'round-neck'
                ]
            ]
        ]
    ]
];

$basket = get_unified_basket();
assert_true(count($basket['all_items']) === 4, 'All 4 garments processed without PHP notices or warnings');
assert_equals($basket['all_items'][0]['work_type'], 'machine', 'Garment 0 derived work_type machine');
assert_true(in_array('MACHINE WORK', $basket['all_items'][0]['badges'], true), 'Garment 0 has MACHINE WORK badge');
assert_true(in_array('SEPARATE MATERIAL', $basket['all_items'][0]['badges'], true), 'Garment 0 has SEPARATE MATERIAL badge');
assert_equals($basket['all_items'][1]['work_type'], 'hand', 'Garment 1 derived work_type hand');
assert_true(in_array('HAND WORK', $basket['all_items'][1]['badges'], true), 'Garment 1 has HAND WORK badge');
assert_equals($basket['all_items'][2]['work_type'], 'both', 'Garment 2 derived work_type both');
assert_equals($basket['all_items'][3]['work_type'], 'no_work', 'Garment 3 derived work_type no_work');

// -------------------------------------------------------------------
// TEST GROUP 2: Cart Cleanup vs Active Draft Separation
// -------------------------------------------------------------------
echo "\nTEST GROUP 2: Cart Cleanup & Paid Order Separation\n";
// When payment is completed:
$_SESSION['luxe_wedding']['payment'] = [
    'status' => 'completed',
    'order_ref' => 'LT20260918-001'
];
$paidBasket = get_unified_basket();
assert_true(!$paidBasket['has_luxe'], 'Paid Luxe order is NOT included in pre-payment basket has_luxe');
assert_equals(count($paidBasket['all_items']), 0, 'Paid Luxe garments do not appear as active cart items');

// When payment is failed or pending, drafts are preserved:
$_SESSION['luxe_wedding']['payment']['status'] = 'failed';
$failedBasket = get_unified_basket();
assert_true($failedBasket['has_luxe'], 'Failed payment retains active Luxe draft');
assert_equals(count($failedBasket['all_items']), 4, 'Failed payment retains all draft garments');

// -------------------------------------------------------------------
// TEST GROUP 3: Order Normalization in orders.php
// -------------------------------------------------------------------
echo "\nTEST GROUP 3: Order Normalization Architecture\n";
ob_start();
require_once 'C:/xampp/htdocs/shagun-ladies-tailor/orders.php';
ob_end_clean();

// Check if normalize_customer_order is defined
assert_true(function_exists('normalize_customer_order'), 'function normalize_customer_order() is defined');

// Case 3A: Standard-only order
$stdRaw = [
    'order_ref' => 'LT20260918-STD',
    'workflow' => 'standard',
    'order_type' => 'Standard Stitching',
    'booked_date' => '2026-09-18',
    'requested_ready_date' => '2026-10-03',
    'admin_delivery_date' => '2026-10-03',
    'status' => 'confirmed',
    'advance_payment' => ['order_total' => 650, 'selected_amount' => 650],
    'payment' => ['status' => 'completed', 'amount_paid' => 650, 'remaining_balance' => 0],
    'people' => [
        [
            'name' => 'Pooja',
            'role' => 'Customer',
            'measurement_method' => 'reference_blouse',
            'garments' => [
                ['name' => 'Blouse', 'style_name' => 'Princess Cut', 'total' => 650]
            ]
        ]
    ]
];
$normStd = normalize_customer_order($stdRaw);
assert_true($normStd['is_standard'], 'Standard-only order correctly flagged as is_standard');
assert_true(!$normStd['is_luxe'], 'Standard-only order is not is_luxe');
assert_true(!$normStd['is_combined'], 'Standard-only order is not is_combined');
assert_equals($normStd['total_physical_garments'], 1, 'Standard-only garment count is 1');
assert_equals($normStd['status_category'], 'in_production', 'Confirmed order mapped to in_production');

// Case 3B: Luxe-only order
$luxeRaw = [
    'order_ref' => 'LT20260918-LUX',
    'workflow' => 'luxe',
    'order_type' => 'Luxe Stitching',
    'booked_date' => '2026-09-18',
    'requested_ready_date' => '2026-10-25',
    'admin_delivery_date' => '2026-10-25',
    'status' => 'stitching',
    'advance_payment' => ['order_total' => 2800, 'selected_amount' => 1400],
    'payment' => ['status' => 'completed', 'amount_paid' => 1400, 'remaining_balance' => 1400],
    'people' => [
        [
            'name' => 'Pooja',
            'role' => 'Bride',
            'garments' => [
                ['name' => 'Blouse', 'style_name' => 'Royal Sabyasachi', 'price' => 1800]
            ]
        ],
        [
            'name' => 'Neha',
            'role' => 'Sister',
            'garments' => [
                ['name' => 'Blouse', 'style_name' => 'Sweetheart', 'price' => 1000]
            ]
        ]
    ]
];
$normLuxe = normalize_customer_order($luxeRaw);
assert_true($normLuxe['is_luxe'], 'Luxe-only order correctly flagged as is_luxe');
assert_true(!$normLuxe['is_standard'], 'Luxe-only order is not is_standard');
assert_true(!$normLuxe['is_combined'], 'Luxe-only order is not is_combined');
assert_equals($normLuxe['total_physical_garments'], 2, 'Luxe-only garment count across 2 people is 2');
assert_equals($normLuxe['status_category'], 'in_production', 'Stitching status mapped to in_production');

// Case 3C: Combined Standard + Luxe order
$combRaw = [
    'order_ref' => 'LT20260918-CMB',
    'booked_date' => '2026-09-18',
    'status' => 'pending_confirmation',
    'advance_payment' => ['order_total' => 3450, 'selected_amount' => 1725, 'is_combined' => true],
    'payment' => ['status' => 'completed', 'amount_paid' => 1725, 'remaining_balance' => 1725],
    'standard_items' => [
        ['garment' => 'Blouse', 'style_name' => 'Daily Blouse', 'total' => 650]
    ],
    'people' => [
        [
            'name' => 'Pooja',
            'role' => 'Bride',
            'garments' => [
                ['name' => 'Blouse', 'style_name' => 'Bridal Blouse', 'price' => 1800],
                ['name' => 'Lehenga', 'style_name' => 'Bridal Lehenga', 'price' => 1000]
            ]
        ]
    ]
];
$normComb = normalize_customer_order($combRaw);
assert_true($normComb['is_combined'], 'Combined order correctly flagged as is_combined');
assert_equals($normComb['total_physical_garments'], 3, 'Combined total physical garments is 3 (1 std + 2 luxe)');
assert_equals($normComb['status_category'], 'awaiting_confirmation', 'Pending confirmation mapped to awaiting_confirmation');
assert_equals($normComb['order_type_badge'], 'STANDARD + LUXE', 'Combined badge is STANDARD + LUXE');

// -------------------------------------------------------------------
// TEST GROUP 4: 3 Status Categories & Completed Sorting (Newest First)
// -------------------------------------------------------------------
echo "\nTEST GROUP 4: 3 Customer Status Categories & Newest-First Sorting\n";

$orderCompletedOlder = [
    'order_ref' => 'LT20260901-001',
    'status' => 'completed',
    'booked_date' => '2026-09-01',
    'completed_at' => strtotime('2026-09-10 10:00:00'),
    'advance_payment' => ['order_total' => 1000, 'selected_amount' => 1000],
    'payment' => ['status' => 'completed', 'amount_paid' => 1000, 'remaining_balance' => 0]
];
$orderCompletedNewer = [
    'order_ref' => 'LT20260915-002',
    'status' => 'delivered',
    'booked_date' => '2026-09-15',
    'completed_at' => strtotime('2026-09-17 15:30:00'),
    'advance_payment' => ['order_total' => 2000, 'selected_amount' => 2000],
    'payment' => ['status' => 'completed', 'amount_paid' => 2000, 'remaining_balance' => 0]
];

$normOlder = normalize_customer_order($orderCompletedOlder);
$normNewer = normalize_customer_order($orderCompletedNewer);

assert_equals($normOlder['status_category'], 'completed', 'Completed order mapped to completed category');
assert_equals($normNewer['status_category'], 'completed', 'Delivered order mapped to completed category');

// Test sorting:
$completedBucket = [$normOlder, $normNewer];
usort($completedBucket, function($a, $b) {
    return ($b['completed_timestamp'] <=> $a['completed_timestamp']);
});

assert_equals($completedBucket[0]['order_ref'], 'LT20260915-002', 'Newest completed order appears FIRST');
assert_equals($completedBucket[1]['order_ref'], 'LT20260901-001', 'Older completed order appears SECOND');

// -------------------------------------------------------------------
// TEST GROUP 5: Orders Page Content & HTML Markup Verification
// -------------------------------------------------------------------
echo "\nTEST GROUP 5: Orders Page Markup & Exact Content Checks\n";
$ordersHtml = file_get_contents('C:/xampp/htdocs/shagun-ladies-tailor/orders.php');

assert_true(strpos($ordersHtml, 'Awaiting Confirmation') !== false, 'orders.php contains category heading: Awaiting Confirmation');
assert_true(strpos($ordersHtml, 'Orders received and awaiting confirmation or initial processing.') !== false, 'orders.php contains exact description for Awaiting Confirmation');

assert_true(strpos($ordersHtml, 'In Production') !== false, 'orders.php contains category heading: In Production');
assert_true(strpos($ordersHtml, 'Orders currently being prepared, stitched, embroidered, or checked.') !== false, 'orders.php contains exact description for In Production');

assert_true(strpos($ordersHtml, 'Completed Orders') !== false, 'orders.php contains category heading: Completed Orders');
assert_true(strpos($ordersHtml, 'Completed orders ready for collection, delivery, or customer reference.') !== false, 'orders.php contains exact description for Completed Orders');

assert_true(strpos($ordersHtml, 'View Order Details ↓') !== false, 'orders.php contains View Order Details ↓ toggle text');
assert_true(strpos($ordersHtml, 'Hide Order Details ↑') !== false, 'orders.php contains Hide Order Details ↑ toggle text');

assert_true(strpos($ordersHtml, 'download_dossier') !== false, 'orders.php retains download_dossier action');
assert_true(strpos($ordersHtml, 'download-dossier-pdf-btn') !== false, 'orders.php retains #download-dossier-pdf-btn');
assert_true(strpos($ordersHtml, 'admin_update_date') !== false, 'orders.php retains admin_update_date simulation form');

// -------------------------------------------------------------------
// TEST GROUP 6: CSS Classes Defined
// -------------------------------------------------------------------
echo "\nTEST GROUP 6: CSS Rules in style.css\n";
$cssContent = file_get_contents('C:/xampp/htdocs/shagun-ladies-tailor/assets/css/style.css');
assert_true(strpos($cssContent, '.customer-orders-page') !== false, 'style.css defines .customer-orders-page');
assert_true(strpos($cssContent, '.orders-summary-bar') !== false, 'style.css defines .orders-summary-bar');
assert_true(strpos($cssContent, '.order-category-section') !== false, 'style.css defines .order-category-section');
assert_true(strpos($cssContent, '.customer-order-card') !== false, 'style.css defines .customer-order-card');
assert_true(strpos($cssContent, '.order-card-summary') !== false, 'style.css defines .order-card-summary');
assert_true(strpos($cssContent, '.status-awaiting') !== false, 'style.css defines .status-awaiting');
assert_true(strpos($cssContent, '.status-production') !== false, 'style.css defines .status-production');
assert_true(strpos($cssContent, '.status-completed') !== false, 'style.css defines .status-completed');

echo "\n=====================================================================\n";
echo " TEST SUMMARY\n";
echo " Total Assertions: $testCount\n";
echo " Passed: $passCount\n";
echo " Failed: " . ($testCount - $passCount) . "\n";
echo "=====================================================================\n";
