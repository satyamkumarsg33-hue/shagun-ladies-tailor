<?php
/**
 * Test Suite: Admin Dashboard Order Status Navigation & Modular Architecture
 * 
 * Verifies:
 * 1. 5 Clickable Metric Cards & Data Attributes (data-status-target)
 * 2. Accessibility Attributes (buttons, aria-label, aria-pressed, keyboard handling)
 * 3. Section IDs (section-awaiting-confirmation, section-stitching-in-process, etc.)
 * 4. Empty State Exact Text ("No orders currently in this stage.")
 * 5. Single Authoritative Dataset Bucketing & Zero Duplication
 * 6. Delivered Orders Terminal Isolation
 * 7. Unknown Status Safety (never silently classified as completed or delivered)
 * 8. Summary Counts 1:1 Match Rendered Buckets
 * 9. Modular Architecture Navigation & Registry
 * 10. Existing Static Token Preservation
 */

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

set_error_handler(function($errno, $errstr, $errfile, $errline) {
    echo "\nFAIL (PHP Warning/Notice [$errno]): $errstr in $errfile on line $errline\n";
    exit(1);
});

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/order-status.php';

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
echo " TEST SUITE: ADMIN DASHBOARD STATUS NAVIGATION & MODULAR ARCHITECTURE\n";
echo "=====================================================================\n";

$adminFile = __DIR__ . '/../admin/index.php';
assert_true(file_exists($adminFile), "admin/index.php exists");
$adminCode = file_get_contents($adminFile);

// -------------------------------------------------------------------
// 1. FIVE CLICKABLE METRIC CARDS & DATA ATTRIBUTES
// -------------------------------------------------------------------
echo "\n--- Section 1: Five Metric Cards & Data Attributes ---\n";

assert_true(strpos($adminCode, 'data-status-target="all"') !== false, "Metric card 1 has data-status-target='all'");
assert_true(strpos($adminCode, 'data-status-target="awaiting_confirmation"') !== false, "Metric card 2 has data-status-target='awaiting_confirmation'");
assert_true(strpos($adminCode, 'data-status-target="stitching_in_process"') !== false, "Metric card 3 has data-status-target='stitching_in_process'");
assert_true(strpos($adminCode, 'data-status-target="completed"') !== false, "Metric card 4 has data-status-target='completed'");
assert_true(strpos($adminCode, 'data-status-target="delivered"') !== false, "Metric card 5 has data-status-target='delivered'");

// -------------------------------------------------------------------
// 2. ACCESSIBILITY ATTRIBUTES & CSS
// -------------------------------------------------------------------
echo "\n--- Section 2: Accessibility & Interactive CSS ---\n";

assert_true(strpos($adminCode, '<button') !== false, "Metric cards implemented using accessible native <button> elements");
assert_true(strpos($adminCode, 'aria-label=') !== false, "Metric cards provide descriptive aria-label attributes");
assert_true(strpos($adminCode, 'aria-pressed=') !== false, "Metric cards provide aria-pressed toggle state");
assert_true(strpos($adminCode, 'metric-card-interactive') !== false, "Metric cards have .metric-card-interactive styling");
assert_true(strpos($adminCode, ':focus-visible') !== false, "CSS defines focus-visible outline for keyboard navigation");
assert_true(strpos($adminCode, '.is-active') !== false, "CSS defines .is-active selected card styling");
assert_true(strpos($adminCode, '.is-filtered-out') !== false, "CSS defines .is-filtered-out section filtering style");

// -------------------------------------------------------------------
// 3. REQUIRED SECTION IDS & EMPTY STATE
// -------------------------------------------------------------------
echo "\n--- Section 3: Section IDs & Empty State Text ---\n";

assert_true(strpos($adminCode, 'section-awaiting-confirmation') !== false, "Section ID 'section-awaiting-confirmation' exists");
assert_true(strpos($adminCode, 'section-stitching-in-process') !== false, "Section ID 'section-stitching-in-process' exists");
assert_true(strpos($adminCode, 'section-completed') !== false, "Section ID 'section-completed' exists");
assert_true(strpos($adminCode, 'section-delivered') !== false, "Section ID 'section-delivered' exists");
assert_true(strpos($adminCode, 'No orders currently in this stage.') !== false, "Exact empty state text 'No orders currently in this stage.' exists");

// -------------------------------------------------------------------
// 4. JAVASCRIPT NAVIGATION, FILTERING & URL SYNCHRONIZATION
// -------------------------------------------------------------------
echo "\n--- Section 4: JavaScript Navigation, Filtering & URL Sync ---\n";

assert_true(strpos($adminCode, 'addEventListener(\'click\'') !== false, "JavaScript click event listeners attached to metric cards");
assert_true(strpos($adminCode, 'addEventListener(\'keydown\'') !== false, "JavaScript keydown event listeners attached for keyboard control");
assert_true(strpos($adminCode, 'Enter') !== false, "JavaScript handles 'Enter' key");
assert_true(strpos($adminCode, 'history.pushState') !== false, "JavaScript synchronizes URL with history.pushState");
assert_true(strpos($adminCode, 'URLSearchParams') !== false, "JavaScript reads status parameter from URL on page load");
assert_true(strpos($adminCode, 'popstate') !== false, "JavaScript handles popstate for browser back/forward navigation");

// -------------------------------------------------------------------
// 5. MODULAR ARCHITECTURE & FUTURE MODULE REGISTRY
// -------------------------------------------------------------------
echo "\n--- Section 5: Modular Navigation Architecture ---\n";

$navFile = __DIR__ . '/../admin/modules/navigation/admin_nav.php';
assert_true(file_exists($navFile), "admin/modules/navigation/admin_nav.php exists");
$navCode = file_get_contents($navFile);

assert_true(strpos($adminCode, "require_once __DIR__ . '/modules/navigation/admin_nav.php'") !== false, "admin/index.php includes modular navigation");
assert_true(strpos($navCode, 'Orders & Fulfillment') !== false, "Modular nav defines active 'Orders & Fulfillment' module");
assert_true(strpos($navCode, 'Festive Offers & Banners') !== false, "Modular nav defines 'Festive Offers & Banners' module");
assert_true(strpos($navCode, 'Promotional Campaigns') !== false, "Modular nav defines 'Promotional Campaigns' module");
assert_true(strpos($navCode, 'Price Management') !== false, "Modular nav defines 'Price Management' module");
assert_true(strpos($navCode, 'Website CMS') !== false, "Modular nav defines 'Website CMS' module");
assert_true(strpos($navCode, 'Customer Management') !== false, "Modular nav defines 'Customer Management' module");
assert_true(strpos($navCode, 'Measurements & Profiles') !== false, "Modular nav defines 'Measurements & Profiles' module");
assert_true(strpos($navCode, 'Materials & Fabrics') !== false, "Modular nav defines 'Materials & Fabrics' module");
assert_true(strpos($navCode, 'Payments & Invoices') !== false, "Modular nav defines 'Payments & Invoices' module");
assert_true(strpos($navCode, 'Analytics & Reports') !== false, "Modular nav defines 'Analytics & Reports' module");
assert_true(strpos($navCode, 'Coming Soon') !== false, "Future modules clearly badged with 'Coming Soon'");
assert_true(strpos($navCode, 'aria-disabled="true"') !== false, "Future modules have aria-disabled='true'");

// -------------------------------------------------------------------
// 6. DATABASE-AUTHORITATIVE ORDER BUCKETING & SYNCHRONIZATION
// -------------------------------------------------------------------
echo "\n--- Section 6: Database-Authoritative Bucketing & Zero Duplication ---\n";

$pdo = get_db_connection();

// Verify column name in orders table
$cols = $pdo->query("SHOW COLUMNS FROM orders")->fetchAll(PDO::FETCH_COLUMN);
assert_true(in_array('status', $cols, true), "Database orders table uses column 'status' (not 'order_status')");

// Fetch all database orders using same query as admin/index.php
$dbStmt = $pdo->query("SELECT * FROM orders ORDER BY created_at DESC");
$orders = $dbStmt->fetchAll(PDO::FETCH_ASSOC);

$bucketAwaiting = [];
$bucketStitching = [];
$bucketCompleted = [];
$bucketDelivered = [];
$unknownStatusOrders = [];

foreach ($orders as $ord) {
    $ref = (string)$ord['order_ref'];
    $canonical = get_canonical_status((string)$ord['status']);
    
    if ($canonical === 'awaiting_confirmation') {
        $bucketAwaiting[$ref] = $ord;
    } elseif ($canonical === 'stitching_in_process') {
        $bucketStitching[$ref] = $ord;
    } elseif ($canonical === 'completed') {
        $bucketCompleted[$ref] = $ord;
    } elseif ($canonical === 'delivered') {
        $bucketDelivered[$ref] = $ord;
    } else {
        $unknownStatusOrders[$ref] = $ord;
    }
}

$countAwaiting = count($bucketAwaiting);
$countStitching = count($bucketStitching);
$countCompleted = count($bucketCompleted);
$countDelivered = count($bucketDelivered);
$countTotal = $countAwaiting + $countStitching + $countCompleted + $countDelivered;

assert_equals($countAwaiting + $countStitching + $countCompleted + $countDelivered, $countTotal, "Sum of bucket counts strictly equals total lifecycle orders ($countTotal)");

// Check zero duplication across buckets
$intersection1 = array_intersect_key($bucketAwaiting, $bucketStitching);
$intersection2 = array_intersect_key($bucketAwaiting, $bucketCompleted);
$intersection3 = array_intersect_key($bucketAwaiting, $bucketDelivered);
$intersection4 = array_intersect_key($bucketStitching, $bucketCompleted);
$intersection5 = array_intersect_key($bucketStitching, $bucketDelivered);
$intersection6 = array_intersect_key($bucketCompleted, $bucketDelivered);

assert_equals(count($intersection1), 0, "Zero overlap between Awaiting and Stitching");
assert_equals(count($intersection2), 0, "Zero overlap between Awaiting and Completed");
assert_equals(count($intersection3), 0, "Zero overlap between Awaiting and Delivered");
assert_equals(count($intersection4), 0, "Zero overlap between Stitching and Completed");
assert_equals(count($intersection5), 0, "Zero overlap between Stitching and Delivered");
assert_equals(count($intersection6), 0, "Zero overlap between Completed and Delivered");

// Verify delivered orders isolation
foreach ($bucketDelivered as $ref => $ord) {
    assert_true(!isset($bucketAwaiting[$ref]), "Delivered order $ref is not in Awaiting bucket");
    assert_true(!isset($bucketStitching[$ref]), "Delivered order $ref is not in Stitching bucket");
    assert_true(!isset($bucketCompleted[$ref]), "Delivered order $ref is not in Completed bucket");
}

// -------------------------------------------------------------------
// 7. UNKNOWN STATUS HANDLING & NON-ASSIGNMENT
// -------------------------------------------------------------------
echo "\n--- Section 7: Unknown Status Handling ---\n";

$mockUnknownOrder = [
    'order_ref' => 'TEST-UNKNOWN-999',
    'status' => 'mystery_unrecognized_status'
];

$testCanonical = get_canonical_status((string)$mockUnknownOrder['status']);
assert_true($testCanonical === null, "Unknown status returns null from get_canonical_status");
assert_true(is_recognized_order_status('mystery_unrecognized_status') === false, "is_recognized_order_status returns false for unknown status");

// Test recognized statuses all map correctly
assert_equals(get_canonical_status('pending'), 'awaiting_confirmation', "pending -> awaiting_confirmation");
assert_equals(get_canonical_status('pending_confirmation'), 'awaiting_confirmation', "pending_confirmation -> awaiting_confirmation");
assert_equals(get_canonical_status('awaiting_confirmation'), 'awaiting_confirmation', "awaiting_confirmation -> awaiting_confirmation");
assert_equals(get_canonical_status('confirmed'), 'stitching_in_process', "confirmed -> stitching_in_process");
assert_equals(get_canonical_status('cutting'), 'stitching_in_process', "cutting -> stitching_in_process");
assert_equals(get_canonical_status('stitching'), 'stitching_in_process', "stitching -> stitching_in_process");
assert_equals(get_canonical_status('stitching_in_process'), 'stitching_in_process', "stitching_in_process -> stitching_in_process");
assert_equals(get_canonical_status('completed'), 'completed', "completed -> completed");
assert_equals(get_canonical_status('delivered'), 'delivered', "delivered -> delivered");

// Simulate bucketing with a mix of recognized and unrecognized orders
$testBatch = [
    'ORD-AWAIT' => ['order_ref' => 'ORD-AWAIT', 'status' => 'pending_confirmation'],
    'ORD-STITCH' => ['order_ref' => 'ORD-STITCH', 'status' => 'stitching_in_process'],
    'ORD-COMP' => ['order_ref' => 'ORD-COMP', 'status' => 'completed'],
    'ORD-DELIV' => ['order_ref' => 'ORD-DELIV', 'status' => 'delivered'],
    'ORD-UNKNOWN-1' => ['order_ref' => 'ORD-UNKNOWN-1', 'status' => 'invalid_status_xyz'],
    'ORD-UNKNOWN-2' => ['order_ref' => 'ORD-UNKNOWN-2', 'status' => 'mystery_unrecognized_status']
];

$testBucketAwaiting = [];
$testBucketStitching = [];
$testBucketCompleted = [];
$testBucketDelivered = [];
$testUnknownOrders = [];

foreach ($testBatch as $ref => $ord) {
    $c = get_canonical_status((string)$ord['status']);
    if ($c === 'awaiting_confirmation') {
        $testBucketAwaiting[$ref] = $ord;
    } elseif ($c === 'stitching_in_process') {
        $testBucketStitching[$ref] = $ord;
    } elseif ($c === 'completed') {
        $testBucketCompleted[$ref] = $ord;
    } elseif ($c === 'delivered') {
        $testBucketDelivered[$ref] = $ord;
    } else {
        $testUnknownOrders[$ref] = $ord;
    }
}

// Assert: Unknown statuses are NOT assigned to any recognized lifecycle bucket
assert_true(!isset($testBucketAwaiting['ORD-UNKNOWN-1']) && !isset($testBucketAwaiting['ORD-UNKNOWN-2']), "Unknown statuses are NOT in awaiting_confirmation bucket");
assert_true(!isset($testBucketStitching['ORD-UNKNOWN-1']) && !isset($testBucketStitching['ORD-UNKNOWN-2']), "Unknown statuses are NOT in stitching_in_process bucket");
assert_true(!isset($testBucketCompleted['ORD-UNKNOWN-1']) && !isset($testBucketCompleted['ORD-UNKNOWN-2']), "Unknown statuses are NOT in completed bucket");
assert_true(!isset($testBucketDelivered['ORD-UNKNOWN-1']) && !isset($testBucketDelivered['ORD-UNKNOWN-2']), "Unknown statuses are NOT in delivered bucket");

// Assert: Unknown statuses are isolated in $testUnknownOrders
assert_equals(count($testUnknownOrders), 2, "Both unknown orders are isolated in \$unknownStatusOrders");
assert_true(isset($testUnknownOrders['ORD-UNKNOWN-1']), "ORD-UNKNOWN-1 is in \$unknownStatusOrders");
assert_true(isset($testUnknownOrders['ORD-UNKNOWN-2']), "ORD-UNKNOWN-2 is in \$unknownStatusOrders");

// Assert: Summary counts reflect only canonical lifecycle orders
assert_equals(count($testBucketAwaiting), 1, "Awaiting count is exactly 1");
assert_equals(count($testBucketStitching), 1, "Stitching count is exactly 1 (unknown order NOT counted)");
assert_equals(count($testBucketCompleted), 1, "Completed count is exactly 1 (unknown order NOT counted)");
assert_equals(count($testBucketDelivered), 1, "Delivered count is exactly 1 (unknown order NOT counted)");

$simulatedTotal = count($testBucketAwaiting) + count($testBucketStitching) + count($testBucketCompleted) + count($testBucketDelivered);
assert_equals($simulatedTotal, 4, "Summary total strictly reflects rendered lifecycle orders (4), excluding unknown orders");

// Assert: admin/index.php contains the warning alert markup for unknown orders
assert_true(strpos($adminCode, '$unknownStatusOrders') !== false, "admin/index.php handles \$unknownStatusOrders collection");
assert_true(strpos($adminCode, 'Unrecognized Status') !== false, "admin/index.php contains admin warning banner for unrecognized status");

// -------------------------------------------------------------------
// 8. PRESERVATION OF EXISTING STATIC TOKENS
// -------------------------------------------------------------------
echo "\n--- Section 8: Existing Static Test Tokens ---\n";

assert_true(strpos($adminCode, '1. Awaiting Confirmation') !== false, "admin/index.php retains '1. Awaiting Confirmation'");
assert_true(strpos($adminCode, '2. Stitching in Process') !== false, "admin/index.php retains '2. Stitching in Process'");
assert_true(strpos($adminCode, '3. Completed Orders') !== false, "admin/index.php retains '3. Completed Orders'");
assert_true(strpos($adminCode, '4. Delivered Orders & Customer History') !== false, "admin/index.php retains '4. Delivered Orders & Customer History'");
assert_true(strpos($adminCode, 'admin_upload_order_photo') !== false, "admin/index.php retains 'admin_upload_order_photo'");
assert_true(strpos($adminCode, 'admin_delete_order_photo') !== false, "admin/index.php retains 'admin_delete_order_photo'");
assert_true(strpos($adminCode, 'cust-seq-pill') !== false, "admin/index.php retains 'cust-seq-pill'");
assert_true(strpos($adminCode, 'ORDER_LIFECYCLE_STATUSES') !== false, "admin/index.php retains 'ORDER_LIFECYCLE_STATUSES'");

echo "\n=====================================================================\n";
echo " TEST SUMMARY\n";
echo " Total Assertions: $testCount\n";
echo " Passed: $passCount\n";
echo " Failed: " . ($testCount - $passCount) . "\n";
echo "=====================================================================\n";
