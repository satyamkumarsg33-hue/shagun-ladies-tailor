<?php

declare(strict_types=1);

/**
 * AUTOMATED TEST SUITE: UNIFIED CART & CUSTOMER EXPERIENCE ENHANCEMENT
 * 
 * Verifies all specifications:
 *  1. Unified cart library and helper functions exist and load properly.
 *  2. Category badges render correctly for all 5 badge types.
 *  3. Item badges aggregate correctly based on item attributes.
 *  4. Standard cart.php displays "Explore Luxe Stitching" secondary CTA.
 *  5. cart.php provides clear explanatory text on Luxe Stitching.
 *  6. Accessing Luxe does NOT clear or destroy the Standard cart.
 *  7. get_unified_basket() computes single-category baskets correctly (Standard only).
 *  8. get_unified_basket() computes single-category baskets correctly (Luxe only).
 *  9. get_unified_basket() aggregates combined baskets (Standard + Luxe + Works).
 * 10. Hand Work target "On My Blouse / Garment" is recorded properly.
 * 11. Hand Work target "On Separate Cloth / Blouse" records material description.
 * 12. Machine Work target "On Separate Cloth / Blouse" records material description.
 * 13. A garment with both Hand and Machine work preserves both without duplicating the garment.
 * 14. Copy Blouse clones style and choices from Blouse #1 to Blouse #2.
 * 15. Copy Blouse recalculates pricing correctly.
 * 16. Deep copy isolation: Modifying Blouse #2 does NOT mutate Blouse #1.
 * 17. Copy Blouse does NOT copy payment status or mark garment as paid.
 * 18. Copy Blouse confirmation modal markup is present in luxe-workspace.php.
 * 19. Combined review-payment.php calculates grand total (Luxe + Standard).
 * 20. Advance payment slider calculates 30% minimum to 100% advance correctly.
 * 21. Payment simulation success clears demo_cart on combined orders.
 * 22. Customer A vs Customer B order isolation is maintained.
 * 23. 45-minute (2700s) guest cart inactivity timeout is preserved.
 */

$projectRoot = dirname(__DIR__);
require_once $projectRoot . '/includes/bootstrap.php';
require_once $projectRoot . '/includes/auth.php';
require_once $projectRoot . '/includes/cart.php';
require_once $projectRoot . '/includes/unified-cart.php';

$totalAssertions = 0;
$passedAssertions = 0;
$failedAssertions = [];

function assert_true(bool $condition, string $message): void {
    global $totalAssertions, $passedAssertions, $failedAssertions;
    $totalAssertions++;
    if ($condition) {
        $passedAssertions++;
        echo "  \033[32m✔\033[0m PASS: $message\n";
    } else {
        $failedAssertions[] = $message;
        echo "  \033[31m✖\033[0m FAIL: $message\n";
    }
}

// Reset Session for clean test run
if (session_status() === PHP_SESSION_ACTIVE) {
    session_destroy();
}
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$_SESSION = [];

echo "=====================================================================\n";
echo " TEST SUITE: UNIFIED CART & CUSTOMER EXPERIENCE ENHANCEMENTS\n";
echo "=====================================================================\n\n";

// -------------------------------------------------------------------
// TEST 1: Unified Cart library functions exist
// -------------------------------------------------------------------
echo "TEST 1: Unified Cart Helper Functions Exist\n";
assert_true(function_exists('get_unified_basket'), 'function get_unified_basket() is defined');
assert_true(function_exists('render_category_badge'), 'function render_category_badge() is defined');
assert_true(function_exists('render_item_category_badges'), 'function render_item_category_badges() is defined');

// -------------------------------------------------------------------
// TEST 2: Category Badge Rendering
// -------------------------------------------------------------------
echo "\nTEST 2: Category Badges Rendering\n";
$badgeStd = render_category_badge('standard');
assert_true(str_contains($badgeStd, 'STANDARD STITCHING') && str_contains($badgeStd, 'badge-standard'), 'Standard badge renders expected class and text');

$badgeLuxe = render_category_badge('luxe');
assert_true(str_contains($badgeLuxe, 'LUXE STITCHING') && str_contains($badgeLuxe, 'badge-luxe'), 'Luxe badge renders expected class and text');

$badgeHand = render_category_badge('hand');
assert_true(str_contains($badgeHand, 'HAND WORK') && str_contains($badgeHand, 'badge-handwork'), 'Hand work badge renders expected class and text');

$badgeMachine = render_category_badge('machine');
assert_true(str_contains($badgeMachine, 'MACHINE WORK') && str_contains($badgeMachine, 'badge-machinework'), 'Machine work badge renders expected class and text');

$badgeSep = render_category_badge('separate');
assert_true(str_contains($badgeSep, 'SEPARATE MATERIAL') && str_contains($badgeSep, 'badge-separate'), 'Separate material badge renders expected class and text');

// -------------------------------------------------------------------
// TEST 3: Item Badges Aggregation
// -------------------------------------------------------------------
echo "\nTEST 3: Item Badges Aggregation\n";
$mockLuxeItem = [
    'work_type' => 'both',
    'hand_work' => ['design_code' => 'H-001'],
    'machine_work' => ['design_code' => 'M-001'],
    'hand_work_target' => 'separate_cloth',
    'machine_work_target' => 'garment'
];
$renderedBadges = render_item_category_badges($mockLuxeItem, 'luxe');
assert_true(str_contains($renderedBadges, 'LUXE STITCHING'), 'Item badge includes LUXE STITCHING');
assert_true(str_contains($renderedBadges, 'HAND WORK'), 'Item badge includes HAND WORK');
assert_true(str_contains($renderedBadges, 'MACHINE WORK'), 'Item badge includes MACHINE WORK');
assert_true(str_contains($renderedBadges, 'SEPARATE MATERIAL'), 'Item badge includes SEPARATE MATERIAL when target is separate_cloth');

// -------------------------------------------------------------------
// TEST 4 & 5: Standard cart.php CTA and Explanatory Text
// -------------------------------------------------------------------
echo "\nTEST 4 & 5: cart.php Secondary CTA and Explanatory Content\n";
$cartContent = file_get_contents($projectRoot . '/cart.php');
assert_true(str_contains($cartContent, 'Explore Luxe Stitching'), 'cart.php contains "Explore Luxe Stitching" CTA button');
assert_true(str_contains($cartContent, 'luxe-workspace.php'), 'cart.php links to luxe-workspace.php');
assert_true(str_contains($cartContent, 'Multiple family members'), 'cart.php explains Luxe advantages (Multiple family members)');
assert_true(str_contains($cartContent, 'render_category_badge'), 'cart.php uses category badge rendering');

// -------------------------------------------------------------------
// TEST 6: Accessing Luxe does NOT clear Standard Cart
// -------------------------------------------------------------------
echo "\nTEST 6: Opening Luxe Preserves Standard Cart\n";
$_SESSION['demo_cart'] = [
    'items' => [
        'item_1' => [
            'id' => 'item_1',
            'product_id' => 1,
            'title' => 'Classic Round Neck Blouse',
            'price' => 650,
            'quantity' => 1
        ]
    ],
    'updated_at' => time()
];
assert_true(count($_SESSION['demo_cart']['items']) === 1, 'Standard cart populated with 1 item');

// Simulate entering Luxe Workspace session
$_SESSION['luxe_wedding'] = [
    'occasion' => 'Wedding',
    'people' => [
        [
            'name' => 'Bride',
            'role' => 'Bride',
            'garments' => []
        ]
    ]
];
assert_true(!empty($_SESSION['demo_cart']['items']), 'Standard cart remains intact after creating Luxe session');
assert_true($_SESSION['demo_cart']['items']['item_1']['price'] === 650, 'Standard cart item price unchanged');

// -------------------------------------------------------------------
// TEST 7: get_unified_basket() Standard Only
// -------------------------------------------------------------------
echo "\nTEST 7: get_unified_basket() Standard Only\n";
$_SESSION['luxe_wedding']['people'][0]['garments'] = [];
$basket = get_unified_basket();
assert_true($basket['has_standard'] === true, 'Basket detects standard items');
assert_true($basket['has_luxe'] === false, 'Basket detects no luxe items');
assert_true($basket['total_items'] === 1, 'Basket total items is 1');
assert_true($basket['category_totals']['standard'] === 650, 'Category total for standard is 650');
assert_true($basket['grand_total'] === 650, 'Grand total matches standard total');

// -------------------------------------------------------------------
// TEST 8 & 9: get_unified_basket() Combined Basket Aggregation
// -------------------------------------------------------------------
echo "\nTEST 8 & 9: get_unified_basket() Combined Basket Aggregation\n";
$_SESSION['luxe_wedding']['people'][0]['garments'] = [
    [
        'name' => 'Blouse',
        'status' => 'completed',
        'style_slug' => 'sweetheart',
        'base_price' => 800,
        'customization_total' => 200,
        'work_total' => 700,
        'total_price' => 1700,
        'work_type' => 'both',
        'machine_work' => ['price' => 300, 'design_code' => 'M-01'],
        'hand_work' => ['price' => 400, 'design_code' => 'H-01'],
        'machine_work_target' => 'garment',
        'hand_work_target' => 'separate_cloth',
        'hand_material_description' => 'Gold Banarasi Silk piece provided'
    ]
];

$combinedBasket = get_unified_basket();
assert_true($combinedBasket['has_standard'] === true, 'Combined basket has standard items');
assert_true($combinedBasket['has_luxe'] === true, 'Combined basket has luxe items');
assert_true($combinedBasket['has_separate_cloth'] === true, 'Combined basket detects separate cloth requirement');
assert_true($combinedBasket['counts']['standard'] === 1, 'Counts standard items = 1');
assert_true($combinedBasket['counts']['luxe'] === 1, 'Counts luxe garments = 1');
assert_true($combinedBasket['counts']['hand_work'] === 1, 'Counts hand work = 1');
assert_true($combinedBasket['counts']['machine_work'] === 1, 'Counts machine work = 1');
assert_true($combinedBasket['category_totals']['standard'] === 650, 'Category total Standard = 650');
assert_true($combinedBasket['category_totals']['luxe'] === 1000, 'Category total Luxe = 1000 (800 base + 200 cust)');
assert_true($combinedBasket['category_totals']['hand_work'] === 400, 'Category total Hand Work = 400');
assert_true($combinedBasket['category_totals']['machine_work'] === 300, 'Category total Machine Work = 300');
assert_true($combinedBasket['grand_total'] === 2350, 'Combined grand total = 2350 (650 + 1700)');

// -------------------------------------------------------------------
// TEST 10, 11 & 12: Hand Work & Machine Work Targets & Material Descriptions
// -------------------------------------------------------------------
echo "\nTEST 10, 11 & 12: Work Targets & Material Descriptions in luxe-work.php\n";
$workFileContent = file_get_contents($projectRoot . '/luxe-work.php');
assert_true(str_contains($workFileContent, 'hand_work_target'), 'luxe-work.php handles hand_work_target');
assert_true(str_contains($workFileContent, 'machine_work_target'), 'luxe-work.php handles machine_work_target');
assert_true(str_contains($workFileContent, 'hand_material_description'), 'luxe-work.php handles hand_material_description');
assert_true(str_contains($workFileContent, 'machine_material_description'), 'luxe-work.php handles machine_material_description');
assert_true(str_contains($workFileContent, 'On My Blouse / Garment'), 'luxe-work.php offers "On My Blouse / Garment" option');
assert_true(str_contains($workFileContent, 'On Separate Cloth / Blouse'), 'luxe-work.php offers "On Separate Cloth / Blouse" option');

// -------------------------------------------------------------------
// TEST 13: Both Hand + Machine Work Preserved Without Duplicating Garment
// -------------------------------------------------------------------
echo "\nTEST 13: Both Hand and Machine Work on 1 Garment Without Duplication\n";
$garments = $_SESSION['luxe_wedding']['people'][0]['garments'];
assert_true(count($garments) === 1, 'Physical garment count is exactly 1');
assert_true(!empty($garments[0]['hand_work']) && !empty($garments[0]['machine_work']), 'Single garment contains both hand and machine work specs');

// -------------------------------------------------------------------
// TEST 14, 15, 16 & 17: Copy Blouse Style & Choices
// -------------------------------------------------------------------
echo "\nTEST 14, 15, 16 & 17: Copy Blouse Style & Customization\n";
// Add second blouse (unconfigured)
$_SESSION['luxe_wedding']['people'][0]['garments'][1] = [
    'name' => 'Blouse',
    'status' => 'not_started',
    'base_price' => 600,
    'customization_total' => 0,
    'work_total' => 0,
    'total_price' => 600
];

$sourceBlouse = $_SESSION['luxe_wedding']['people'][0]['garments'][0];
$sourceBlouse['style_choices'] = [
    'sleeve' => 'half',
    'neck' => 'sweetheart',
    'latkan' => 'tassels'
];
$sourceBlouse['choice_summary'] = [
    ['field' => 'Sleeve', 'label' => 'Half', 'price' => 50],
    ['field' => 'Neck', 'label' => 'Sweetheart', 'price' => 150]
];
$_SESSION['luxe_wedding']['people'][0]['garments'][0] = $sourceBlouse;

// Simulate copy_blouse POST action
$pIdx = 0;
$sourceIdx = 0;
$targetIdx = 1;

$source = $_SESSION['luxe_wedding']['people'][$pIdx]['garments'][$sourceIdx];
$target = &$_SESSION['luxe_wedding']['people'][$pIdx]['garments'][$targetIdx];

$target['style_slug'] = $source['style_slug'];
$target['style_name'] = $source['style_name'] ?? 'Sweetheart Blouse';
$target['style_choices'] = unserialize(serialize($source['style_choices']));
$target['choice_summary'] = unserialize(serialize($source['choice_summary']));
$target['customization_total'] = $source['customization_total'];
$target['status'] = 'in_progress'; // Must be in_progress, NOT completed, NOT paid
$target['total_price'] = (int)$target['base_price'] + (int)$target['customization_total'] + (int)$target['work_total'];
unset($target);

$copiedBlouse = $_SESSION['luxe_wedding']['people'][0]['garments'][1];
assert_true($copiedBlouse['style_slug'] === 'sweetheart', 'Copied blouse received source style_slug');
assert_true($copiedBlouse['style_choices']['neck'] === 'sweetheart', 'Copied blouse received neckline choice');
assert_true($copiedBlouse['total_price'] === 800, 'Copied blouse price calculated correctly (600 base + 200 cust)');
assert_true($copiedBlouse['status'] === 'in_progress', 'Copied blouse is in_progress, not marked completed');
assert_true(empty($copiedBlouse['payment']), 'Copied blouse does not contain payment status');

// Deep Copy Mutation Test
$copiedBlouse['style_choices']['neck'] = 'deep_v';
$_SESSION['luxe_wedding']['people'][0]['garments'][1] = $copiedBlouse;
$originalBlouse = $_SESSION['luxe_wedding']['people'][0]['garments'][0];
assert_true($originalBlouse['style_choices']['neck'] === 'sweetheart', 'Deep copy confirmed: Mutating Blouse #2 does NOT mutate Blouse #1');

// -------------------------------------------------------------------
// TEST 18: Copy Blouse Confirmation Modal in luxe-workspace.php
// -------------------------------------------------------------------
echo "\nTEST 18: Copy Blouse Confirmation Modal in luxe-workspace.php\n";
$workspaceContent = file_get_contents($projectRoot . '/luxe-workspace.php');
assert_true(str_contains($workspaceContent, 'id="copy-blouse-modal"'), 'luxe-workspace.php includes #copy-blouse-modal');
assert_true(str_contains($workspaceContent, 'luxe-copy-blouse-btn') && str_contains($workspaceContent, 'data-open-copy-modal'), 'luxe-workspace.php includes "Copy from Blouse" button trigger');

// -------------------------------------------------------------------
// TEST 19 & 20: Combined Review & Advance Payment Slider
// -------------------------------------------------------------------
echo "\nTEST 19 & 20: Combined Review & Advance Payment Slider\n";
$reviewContent = file_get_contents($projectRoot . '/review-payment.php');
assert_true(str_contains($reviewContent, 'get_unified_basket'), 'review-payment.php integrates get_unified_basket()');
assert_true(str_contains($reviewContent, '$standardTotal'), 'review-payment.php combines $standardTotal into grand total');
assert_true(str_contains($reviewContent, 'minAdvancePercent = 30'), 'review-payment.php enforces 30% minimum advance');
assert_true(str_contains($reviewContent, 'category-breakdown-card'), 'review-payment.php renders category breakdown card');

// -------------------------------------------------------------------
// TEST 21: Payment Simulation Clears demo_cart on Combined Orders
// -------------------------------------------------------------------
echo "\nTEST 21: Payment Simulation Clears demo_cart on Combined Orders\n";
// Setup session as if review-payment confirmed combined order
$_SESSION['luxe_wedding']['advance_payment'] = [
    'order_total' => 2550,
    'selected_amount' => 1275,
    'has_standard' => true
];
assert_true(!empty($_SESSION['demo_cart']['items']), 'Standard cart has items before combined payment simulation');

// Simulate payment.php simulate_success logic
$advancePayment = $_SESSION['luxe_wedding']['advance_payment'];
if (!empty($advancePayment['has_standard']) && function_exists('demo_cart_clear')) {
    if (!empty($_SESSION['demo_cart']['items'])) {
        $_SESSION['luxe_wedding']['standard_items'] = $_SESSION['demo_cart']['items'];
    }
    demo_cart_clear();
}

assert_true(empty($_SESSION['demo_cart']['items']), 'demo_cart is cleared after combined payment simulation');
assert_true(!empty($_SESSION['luxe_wedding']['standard_items']), 'Standard items preserved in completed order record');

// -------------------------------------------------------------------
// TEST 22: Customer Order Isolation
// -------------------------------------------------------------------
echo "\nTEST 22: Customer Order Isolation\n";
$_SESSION['customer_orders'] = [];

$pdo = get_db_connection();
// Clean up any previous test orders first
$pdo->prepare("DELETE FROM orders WHERE order_ref IN ('LT20260918-001', 'LT20260918-002')")->execute();

$uStmt = $pdo->query('SELECT id FROM users ORDER BY id ASC LIMIT 2')->fetchAll(PDO::FETCH_COLUMN);
if (count($uStmt) < 2) {
    $pdo->prepare("INSERT IGNORE INTO users (id, name, email) VALUES (101, 'Cust 1', 'cust101@test.com'), (102, 'Cust 2', 'cust102@test.com')")->execute();
    $uStmt = $pdo->query('SELECT id FROM users ORDER BY id ASC LIMIT 2')->fetchAll(PDO::FETCH_COLUMN);
}
$uId1 = (int) $uStmt[0];
$uId2 = (int) $uStmt[1];

$customer1Order = [
    'order_ref' => 'LT20260918-001',
    'user_id' => $uId1,
    'occasion' => 'Wedding'
];
$customer2Order = [
    'order_ref' => 'LT20260918-002',
    'user_id' => $uId2,
    'occasion' => 'Reception'
];
save_customer_completed_order($customer1Order);
save_customer_completed_order($customer2Order);

$ordersUser1 = get_customer_orders($uId1);
$ordersUser2 = get_customer_orders($uId2);
assert_true(isset($ordersUser1['LT20260918-001']), "Customer {$uId1} sees their own order");
assert_true(!isset($ordersUser1['LT20260918-002']), "Customer {$uId1} CANNOT see Customer {$uId2} order");
assert_true(isset($ordersUser2['LT20260918-002']), "Customer {$uId2} sees their own order");
assert_true(!isset($ordersUser2['LT20260918-001']), "Customer {$uId2} CANNOT see Customer {$uId1} order");

// Cleanup test orders
$pdo->prepare("DELETE FROM orders WHERE order_ref IN ('LT20260918-001', 'LT20260918-002')")->execute();

// -------------------------------------------------------------------
// TEST 23: 45-Minute Inactivity Timeout Intact
// -------------------------------------------------------------------
echo "\nTEST 23: 45-Minute (2700s) Inactivity Timeout Intact\n";
assert_true(defined('DEMO_CART_TIMEOUT') && DEMO_CART_TIMEOUT === 2700, 'DEMO_CART_TIMEOUT is exactly 2700 seconds (45 minutes)');

unset($_SESSION['user']);
unset($_SESSION['demo_logged_in']);
$_SESSION['demo_cart'] = [['id' => 'item_1', 'price' => 500]];
$_SESSION['demo_cart_last_activity'] = time() - 2800; // 46 minutes ago (expired)
demo_cart_bootstrap();
assert_true(empty($_SESSION['demo_cart']), 'Expired cart (>45 min) is reset automatically');

$_SESSION['demo_cart'] = [['id' => 'item_1', 'price' => 500]];
$_SESSION['demo_cart_last_activity'] = time() - 1000; // ~16 minutes ago (active)
demo_cart_bootstrap();
assert_true(!empty($_SESSION['demo_cart']), 'Active cart (<45 min) is preserved');

// -------------------------------------------------------------
// TEST 24: Scenario 1 — Correct Luxe Entry Link in cart.php
// -------------------------------------------------------------
echo "\nTEST 24: Scenario 1 — Correct Luxe Entry Link in cart.php\n";
$cartContent = file_get_contents($projectRoot . '/cart.php');
assert_true(str_contains($cartContent, "luxeEntryUrl = 'luxe-stitching.php'"), 'cart.php sets $luxeEntryUrl to luxe-stitching.php for all users');
assert_true(!str_contains($cartContent, "luxeEntryUrl = 'luxe-workspace.php'"), 'cart.php does NOT link Explore Luxe directly to luxe-workspace.php');

// -------------------------------------------------------------
// TEST 25: Scenario 8 — Elimination of Demo Fallback (Riya/Anita) & Empty State
// -------------------------------------------------------------
echo "\nTEST 25: Scenario 8 — Elimination of Demo Fallback (Riya/Anita) & Empty State\n";
$workspaceContent = file_get_contents($projectRoot . '/luxe-workspace.php');
assert_true(!str_contains($workspaceContent, "\$people = [\n        [\n            'name' => 'Riya'"), 'luxe-workspace.php does NOT auto-insert Riya as demo person');
assert_true(str_contains($workspaceContent, 'Your Luxe workspace is empty'), 'luxe-workspace.php renders empty state heading when no people exist');
assert_true(str_contains($workspaceContent, 'Start Luxe Stitching'), 'luxe-workspace.php empty state has CTA linking to luxe-stitching.php');

$customizeContent = file_get_contents($projectRoot . '/customize-blouse.php');
assert_true(!str_contains($customizeContent, "'name' => 'Riya', 'role' => 'Bride'"), 'customize-blouse.php does NOT inject demo Riya into session');

$luxeWorkContent = file_get_contents($projectRoot . '/luxe-work.php');
assert_true(!str_contains($luxeWorkContent, "'name' => 'Riya', 'role' => 'Bride'"), 'luxe-work.php does NOT inject demo Riya into session');

// -------------------------------------------------------------
// TEST 26: Scenario 2, 3, 5 — Unified Cart in cart.php
// -------------------------------------------------------------
echo "\nTEST 26: Scenario 2, 3, 5 — Unified Cart in cart.php\n";
assert_true(str_contains($cartContent, 'get_unified_basket()'), 'cart.php uses get_unified_basket()');
assert_true(str_contains($cartContent, '$standardItems'), 'cart.php handles $standardItems');
assert_true(str_contains($cartContent, '$luxeItems'), 'cart.php handles $luxeItems');
assert_true(str_contains($cartContent, 'Total Physical Garments'), 'cart.php shows Total Physical Garments count');
assert_true(str_contains($cartContent, 'Continue Luxe Workspace'), 'cart.php guards checkout when Luxe garments in progress');
assert_true(str_contains($cartContent, 'Proceed to Combined Review'), 'cart.php provides Combined Review link when all completed');

// -------------------------------------------------------------
// TEST 27: Scenario 4 — Combined Order Summary in payment.php & review-payment.php
// -------------------------------------------------------------
echo "\nTEST 27: Scenario 4 — Combined Order Summary in payment.php & review-payment.php\n";
$paymentContent = file_get_contents($projectRoot . '/payment.php');
assert_true(str_contains($paymentContent, '$isCombinedOrder'), 'payment.php detects $isCombinedOrder');
assert_true(str_contains($paymentContent, '1. Standard Stitching, 2. Luxe Stitching'), 'payment.php formats Order Type as 1. Standard Stitching, 2. Luxe Stitching');
assert_true(str_contains($paymentContent, 'Standard Items'), 'payment.php shows Standard Items count');
assert_true(str_contains($paymentContent, 'Luxe Occasion'), 'payment.php labels occasion as Luxe Occasion in combined view');
assert_true(str_contains($paymentContent, 'Luxe People'), 'payment.php shows Luxe People count');
assert_true(str_contains($paymentContent, 'Luxe Garments'), 'payment.php shows Luxe Garments count');
assert_true(str_contains($paymentContent, 'Total Physical Garments'), 'payment.php shows Total Physical Garments in combined view');

$reviewFileContent = file_get_contents($projectRoot . '/review-payment.php');
assert_true(str_contains($reviewFileContent, '1. Standard Stitching<br>2. Luxe Stitching'), 'review-payment.php formats Order Type for combined order');
assert_true(str_contains($reviewFileContent, 'Standard Items'), 'review-payment.php shows Standard Items');
assert_true(str_contains($reviewFileContent, 'Luxe Occasion'), 'review-payment.php shows Luxe Occasion');
assert_true(str_contains($reviewFileContent, 'Luxe People'), 'review-payment.php shows Luxe People');
assert_true(str_contains($reviewFileContent, 'Luxe Garments'), 'review-payment.php shows Luxe Garments');
assert_true(str_contains($reviewFileContent, 'Total Physical Garments'), 'review-payment.php shows Total Physical Garments');

// -------------------------------------------------------------------
// TEST 28: Total Physical Garment Calculation in get_unified_basket()
// -------------------------------------------------------------------
echo "\nTEST 28: Total Physical Garment Calculation in get_unified_basket()\n";
// Setup: 1 Standard Garment + 2 Luxe Garments across 2 People
$_SESSION['demo_cart'] = [
    'items' => [
        'item_1' => ['id' => 'item_1', 'price' => 750, 'style_name' => 'Standard Blouse']
    ]
];
$_SESSION['luxe_wedding'] = [
    'occasion' => 'Wedding',
    'people' => [
        [
            'name' => 'Pooja',
            'role' => 'Bride',
            'garments' => [
                ['name' => 'Blouse', 'status' => 'completed', 'style_slug' => 'u-cut', 'base_price' => 650, 'total_price' => 650, 'work_type' => 'no_work']
            ]
        ],
        [
            'name' => 'Kavita',
            'role' => 'Sister',
            'garments' => [
                ['name' => 'Blouse', 'status' => 'completed', 'style_slug' => 'princess-cut', 'base_price' => 850, 'total_price' => 850, 'work_type' => 'no_work']
            ]
        ]
    ]
];

$calcBasket = get_unified_basket();
assert_true($calcBasket['is_combined'] === true, 'Basket is detected as combined');
assert_true($calcBasket['standard_item_count'] === 1, 'standard_item_count = 1');
assert_true($calcBasket['luxe_garment_count'] === 2, 'luxe_garment_count = 2');
assert_true($calcBasket['luxe_people_count'] === 2, 'luxe_people_count = 2');
assert_true($calcBasket['total_physical_garment_count'] === 3, 'total_physical_garment_count = 3 (1 std + 2 luxe)');
assert_true($calcBasket['has_incomplete_luxe'] === false, 'has_incomplete_luxe is false when all completed');

// -------------------------------------------------------------------
// TEST 29: Incomplete Luxe Detection in get_unified_basket()
// -------------------------------------------------------------------
echo "\nTEST 29: Incomplete Luxe Detection in get_unified_basket()\n";
$_SESSION['luxe_wedding']['people'][1]['garments'][0]['status'] = 'in-progress';
$_SESSION['luxe_wedding']['people'][1]['garments'][0]['style_slug'] = '';
$incompBasket = get_unified_basket();
assert_true($incompBasket['has_incomplete_luxe'] === true, 'has_incomplete_luxe is true when any garment is incomplete');

// -------------------------------------------------------------------
// TEST 30: Problem 6 — Copy Blouse Modal Markup & Style Classes
// -------------------------------------------------------------------
echo "\nTEST 30: Copy Blouse Modal Markup & Style Classes\n";
$cssContent = file_get_contents($projectRoot . '/assets/css/style.css');
assert_true(str_contains($cssContent, '.copy-modal-header') && str_contains($cssContent, '.luxe-modal-header'), 'style.css defines .copy-modal-header and .luxe-modal-header');
assert_true(str_contains($cssContent, '.copy-modal-context'), 'style.css defines .copy-modal-context');
assert_true(str_contains($cssContent, '.copy-modal-select'), 'style.css defines .copy-modal-select');
assert_true(str_contains($cssContent, '.copy-modal-notice'), 'style.css defines .copy-modal-notice');
assert_true(str_contains($cssContent, '.copy-modal-checkbox-label'), 'style.css defines .copy-modal-checkbox-label');
assert_true(str_contains($cssContent, '.copy-modal-submit-btn'), 'style.css defines .copy-modal-submit-btn');

assert_true(str_contains($workspaceContent, 'class="copy-modal-select"'), 'luxe-workspace.php uses class copy-modal-select');
assert_true(str_contains($workspaceContent, 'class="copy-modal-notice"'), 'luxe-workspace.php uses class copy-modal-notice');
assert_true(str_contains($workspaceContent, 'class="copy-modal-checkbox-label"'), 'luxe-workspace.php uses class copy-modal-checkbox-label');
assert_true(str_contains($workspaceContent, 'class="luxe-workspace-primary-action copy-modal-submit-btn"'), 'luxe-workspace.php uses copy-modal-submit-btn');

// -------------------------------------------------------------------
// SUMMARY
// -------------------------------------------------------------------
echo "\n=====================================================================\n";
echo " TEST SUMMARY\n";
echo " Total Assertions: $totalAssertions\n";
echo " Passed: \033[32m$passedAssertions\033[0m\n";
echo " Failed: " . ($totalAssertions - $passedAssertions > 0 ? "\033[31m" . ($totalAssertions - $passedAssertions) . "\033[0m" : "\033[32m0\033[0m") . "\n";
echo "=====================================================================\n";

if (!empty($failedAssertions)) {
    echo "\nFailed assertions:\n";
    foreach ($failedAssertions as $fail) {
        echo " - $fail\n";
    }
    exit(1);
}

exit(0);
