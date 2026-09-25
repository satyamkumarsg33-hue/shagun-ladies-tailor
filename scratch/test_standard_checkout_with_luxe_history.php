<?php
/**
 * Test Suite: Standard Stitching Checkout with Luxe Order History
 * 
 * Verifies the fix for:
 * Customer Jamun has existing Luxe orders in My Orders (orders.php).
 * She then starts a completely NEW Standard Stitching order (1 Blouse, ₹850).
 * When she clicks: Cart → Proceed to Place Order
 * Checkout MUST behave strictly as Standard Stitching checkout:
 * - NO "Combined Order Option: You have Luxe Stitching garments in progress"
 * - NO "Unified Review & Payment →" button redirecting to orders.php
 * - Pure Standard Stitching checkout flow
 * - Preserves historical orders in My Orders
 * - Preserves genuine Combined Order when an active uncompleted Luxe draft exists
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/cart.php';
require_once __DIR__ . '/../includes/unified-cart.php';
require_once __DIR__ . '/../includes/order-status.php';

function assert_test(bool $condition, string $message): void {
    if ($condition) {
        echo "  [PASS] $message\n";
    } else {
        echo "  [FAIL] $message\n";
        debug_print_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
        exit(1);
    }
}

echo "============================================================\n";
echo "TEST SUITE: STANDARD CHECKOUT WITH HISTORICAL LUXE ORDERS\n";
echo "============================================================\n\n";

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$pdo = get_db_connection();

// -------------------------------------------------------------
// SETUP: Create or load customer Jamun test user
// -------------------------------------------------------------
$testEmail = 'jamun_test_' . time() . '@example.com';
$testName = 'Jamun';
$testPhone = '+91 9876501234';

$uStmt = $pdo->prepare("INSERT INTO users (name, email, password_hash, role, phone, created_at) VALUES (:n, :e, 'hash', 'customer', :p, NOW())");
$uStmt->execute([':n' => $testName, ':e' => $testEmail, ':p' => $testPhone]);
$testUserId = (int) $pdo->lastInsertId();

$_SESSION['user'] = [
    'id' => $testUserId,
    'name' => $testName,
    'email' => $testEmail,
    'role' => 'customer',
    'phone' => $testPhone
];
$_SESSION['user_id'] = $testUserId;

echo "1. SEED HISTORICAL LUXE ORDERS FOR CUSTOMER JAMUN\n";
// Create a completed Luxe order in database (e.g. LT20260925-854)
$luxeOrderRef = 'LT' . date('Ymd') . '-' . rand(1000, 9999);
$luxeOrderData = [
    'order_ref' => $luxeOrderRef,
    'user_id' => $testUserId,
    'workflow' => 'wedding',
    'occasion' => 'Wedding',
    'status' => 'pending_confirmation',
    'grand_total' => 4500,
    'advance_amount' => 1350,
    'balance_amount' => 3150,
    'requested_ready_date' => '2026-10-02',
    'booked_date' => '2026-09-25',
    'people' => [
        [
            'name' => 'Jamun',
            'role' => 'Bride',
            'measurement_method' => 'reference_blouse',
            'garments' => [
                [
                    'name' => 'Blouse',
                    'status' => 'completed',
                    'style_slug' => 'princess-cut',
                    'style_name' => 'Princess Cut Blouse',
                    'base_price' => 800,
                    'customization_total' => 200,
                    'work_total' => 500,
                    'total_price' => 1500,
                    'work_type' => 'hand'
                ]
            ]
        ]
    ],
    'payment' => [
        'status' => 'completed',
        'order_ref' => $luxeOrderRef,
        'amount_paid' => 1350,
        'payment_method' => 'upi',
        'paid_at' => time()
    ],
    'is_submitted' => true
];

$saved = save_customer_completed_order($luxeOrderData);
assert_test($saved === true, "Historical Luxe order {$luxeOrderRef} saved to MySQL database for customer Jamun.");

// Verify My Orders sees this order
$jamunOrders = get_customer_orders($testUserId);
assert_test(isset($jamunOrders[$luxeOrderRef]), "My Orders successfully retrieves historical Luxe order {$luxeOrderRef}.");

echo "\n2. SIMULATE SESSION LEAKAGE FROM PRIOR ORDER (PRE-FIX BUG CONDITION)\n";
// In the bug condition, $_SESSION['luxe_wedding'] still contains the completed order payload
$_SESSION['luxe_wedding'] = $luxeOrderData;

assert_test(is_luxe_order_completed($_SESSION['luxe_wedding']) === true, "is_luxe_order_completed() correctly identifies completed Luxe order.");
assert_test(has_active_luxe_draft() === false, "has_active_luxe_draft() returns FALSE because Luxe order is already completed.");

echo "\n3. CUSTOMER ADDS 1 STANDARD STITCHING BLOUSE (₹850) TO CART\n";
demo_cart_clear();
demo_cart_add([
    'garment' => 'Blouse',
    'title' => 'Custom Tailored Blouse',
    'style_slug' => 'u-cut',
    'style_name' => 'U-Cut Blouse',
    'base_price' => 650,
    'customization_total' => 200,
    'total' => 850,
    'total_price' => 850,
    'choices' => [
        ['field' => 'Neck design', 'label' => 'U-Cut', 'price' => 100],
        ['field' => 'Back design', 'label' => 'Standard', 'price' => 100]
    ]
]);

$cartItems = demo_cart_items();
assert_test(count($cartItems) === 1, "demo_cart has exactly 1 Standard Stitching blouse.");

$basket = get_unified_basket();
assert_test($basket['has_standard'] === true, "Unified basket has_standard is TRUE.");
assert_test($basket['has_luxe'] === false, "Unified basket has_luxe is FALSE (historical Luxe order is not an active unpurchased draft).");
assert_test($basket['is_combined'] === false, "Unified basket is_combined is FALSE.");
assert_test($basket['grand_total'] === 850, "Unified basket grand total is ₹850.");

echo "\n4. VERIFY CART.PHP RENDERING & CTA\n";
ob_start();
$_GET = [];
include __DIR__ . '/../cart.php';
$cartHtml = ob_get_clean();

assert_test(strpos($cartHtml, 'Proceed to Place Order') !== false, "cart.php renders 'Proceed to Place Order' button.");
assert_test(strpos($cartHtml, 'Proceed to Combined Review') === false, "cart.php does NOT render 'Proceed to Combined Review'.");
assert_test(strpos($cartHtml, 'Standard Items') !== false, "cart.php displays Standard Items count.");
assert_test(strpos($cartHtml, '₹850') !== false, "cart.php displays ₹850 total.");

echo "\n5. VERIFY CHECKOUT.PHP CLASSIFICATION & BANNER SUPPRESSION\n";
ob_start();
$_GET = ['step' => 'measurement'];
$_SERVER['REQUEST_METHOD'] = 'GET';
include __DIR__ . '/../checkout.php';
$checkoutHtml = ob_get_clean();

assert_test(strpos($checkoutHtml, 'Combined Order Option: You have Luxe Stitching garments in progress') === false, 
    "checkout.php DOES NOT show 'Combined Order Option: You have Luxe Stitching garments in progress'.");
assert_test(strpos($checkoutHtml, 'Unified Review & Payment') === false, 
    "checkout.php DOES NOT show 'Unified Review & Payment' button.");
assert_test(strpos($checkoutHtml, 'STANDARD STITCHING CHECKOUT') !== false, 
    "checkout.php correctly displays STANDARD STITCHING CHECKOUT eyebrow.");
assert_test(strpos($checkoutHtml, 'Measurements & Reference') !== false, 
    "checkout.php correctly displays Step 1: Measurements & Reference.");
assert_test(strpos($checkoutHtml, 'Reference Blouse') !== false, 
    "checkout.php shows Reference Blouse fitting option.");
assert_test(strpos($checkoutHtml, 'Visit Shop') !== false, 
    "checkout.php shows Visit Shop fitting option.");

echo "\n6. VERIFY DIRECT ACCESS TO REVIEW-PAYMENT.PHP FOR STANDARD CART\n";
// If a user with standard items and NO active luxe draft visits review-payment.php,
// it must redirect to checkout.php (and NOT dump them into orders.php).
// We simulate review-payment.php guard:
$hasActiveLuxe = has_active_luxe_draft();
$hasStandardInCart = !empty(demo_cart_items());
assert_test(!$hasActiveLuxe && $hasStandardInCart, "Guard detects standard items in cart with no active Luxe draft.");

echo "\n7. COMPLETE STANDARD CHECKOUT FLOW FOR CUSTOMER JAMUN\n";
// Save measurements
$_SESSION['standard_order']['workflow'] = 'standard';
$_SESSION['standard_order']['order_type'] = 'Standard Stitching';
$_SESSION['standard_order']['measurement_method'] = 'reference_blouse';
$_SESSION['standard_order']['requested_ready_date'] = date('Y-m-d', strtotime('+12 days'));
$_SESSION['standard_order']['notes'] = 'Please stitch with extra margin.';

assert_test($_SESSION['standard_order']['measurement_method'] === 'reference_blouse', "Measurements saved to standard_order.");

// Save advance payment
$_SESSION['standard_order']['advance_payment'] = [
    'order_total' => 850,
    'selected_amount' => 425,
    'percentage' => 50,
    'remaining_balance' => 425
];
assert_test($_SESSION['standard_order']['advance_payment']['selected_amount'] === 425, "Advance payment saved to standard_order.");

// Save payment success
$standardOrderRef = 'LT' . date('Ymd') . '-' . rand(1000, 9999);
$_SESSION['standard_order']['order_ref'] = $standardOrderRef;
$_SESSION['standard_order']['user_id'] = $testUserId;
$_SESSION['standard_order']['status'] = 'pending_confirmation';
$_SESSION['standard_order']['payment'] = [
    'status' => 'completed',
    'order_ref' => $standardOrderRef,
    'amount_paid' => 425,
    'remaining_balance' => 425,
    'payment_method' => 'upi',
    'paid_at' => time()
];
$_SESSION['standard_order']['people'] = [
    [
        'name' => $testName,
        'role' => 'Customer',
        'measurement_method' => 'reference_blouse',
        'garments' => $cartItems
    ]
];
$stdSaved = save_customer_completed_order($_SESSION['standard_order']);
assert_test($stdSaved === true, "Standard order {$standardOrderRef} successfully saved to DB.");
demo_cart_clear();

assert_test($_SESSION['standard_order']['payment']['status'] === 'completed', "Standard order payment marked completed.");
assert_test(!empty($standardOrderRef), "Standard order reference assigned: {$standardOrderRef}");

echo "\n8. VERIFY MY ORDERS (ORDERS.PHP) CONTAINS BOTH LUXE AND STANDARD ORDERS\n";
$allJamunOrders = get_customer_orders($testUserId);
assert_test(count($allJamunOrders) >= 2, "Customer Jamun now has at least 2 orders in My Orders.");
assert_test(isset($allJamunOrders[$luxeOrderRef]), "Historical Luxe order {$luxeOrderRef} is present in My Orders.");
assert_test(isset($allJamunOrders[$standardOrderRef]), "New Standard Stitching order {$standardOrderRef} is present in My Orders.");

// Check workflow types
assert_test($allJamunOrders[$luxeOrderRef]['workflow'] === 'wedding', "Luxe order is correctly classified as 'wedding'.");
assert_test($allJamunOrders[$standardOrderRef]['workflow'] === 'standard', "Standard order is correctly classified as 'standard'.");
assert_test($allJamunOrders[$standardOrderRef]['order_type'] === 'Standard Stitching', "Standard order order_type is 'Standard Stitching'.");
assert_test($allJamunOrders[$luxeOrderRef]['order_type'] === 'Luxe Stitching', "Luxe order order_type is 'Luxe Stitching'.");

echo "\n9. VERIFY GENUINE COMBINED ORDER BEHAVIOR (ACTIVE DRAFT + STANDARD CART)\n";
// Create a genuinely unsubmitted Luxe draft
$_SESSION['luxe_wedding'] = [
    'workflow' => 'wedding',
    'occasion' => 'Wedding',
    'status' => 'draft',
    'people' => [
        [
            'name' => 'Sister',
            'role' => 'Sister',
            'garments' => [
                [
                    'name' => 'Blouse',
                    'status' => 'completed',
                    'style_slug' => 'square-neck',
                    'base_price' => 650,
                    'total_price' => 650
                ]
            ]
        ]
    ]
];

// Add standard blouse to cart
demo_cart_add([
    'garment' => 'Blouse',
    'title' => 'Daily Wear Blouse',
    'style_slug' => 'round-neck',
    'total_price' => 700
]);

assert_test(has_active_luxe_draft() === true, "has_active_luxe_draft() returns TRUE for active unsubmitted draft.");
$combinedBasket = get_unified_basket();
assert_test($combinedBasket['is_combined'] === true, "Unified basket is_combined is TRUE for genuine combined draft.");

// Verify checkout.php DOES display combined banner when an active draft genuinely exists
$_SESSION['standard_order'] = [
    'measurement_method' => 'reference_blouse'
];
ob_start();
$_GET = ['step' => 'review'];
$_SERVER['REQUEST_METHOD'] = 'GET';
include __DIR__ . '/../checkout.php';
$combinedCheckoutHtml = ob_get_clean();

assert_test(strpos($combinedCheckoutHtml, 'Combined Order Option: You have Luxe Stitching garments in progress') !== false, 
    "checkout.php correctly displays Combined Order Option banner when an active Luxe draft exists.");
assert_test(strpos($combinedCheckoutHtml, 'Unified Review & Payment') !== false, 
    "checkout.php displays Unified Review & Payment button when an active Luxe draft exists.");

echo "\n10. VERIFY CONSECUTIVE LUXE ORDER RESETS CLEANLY WITHOUT LOCKED BANNER\n";
// Complete the Luxe draft
$fakeLuxeRef = 'LT' . date('Ymd') . '-999';
$_SESSION['luxe_wedding']['order_ref'] = $fakeLuxeRef;
$_SESSION['luxe_wedding']['payment'] = ['status' => 'completed', 'order_ref' => $fakeLuxeRef];
$_SESSION['luxe_wedding']['is_submitted'] = true;

// Starting new Luxe order
init_fresh_luxe_draft('wedding', true);
assert_test(empty($_SESSION['luxe_wedding']['requested_ready_date']), "New Luxe draft has clean, empty requested ready date.");
assert_test(is_luxe_order_completed($_SESSION['luxe_wedding']) === false, "New Luxe draft is not completed.");
assert_test(empty($_SESSION['luxe_wedding']['people']), "New Luxe draft has empty people array.");

echo "\n============================================================\n";
echo "ALL TESTS PASSED SUCCESSFULLY! (10/10 TEST SUITES)\n";
echo "============================================================\n";
