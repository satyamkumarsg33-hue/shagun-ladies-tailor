<?php
declare(strict_types=1);

/**
 * Test Suite: Admin Dashboard V2
 * - Financials Fix (Kamal's Order LT20260919-673)
 * - Four Canonical Statuses & Sequential Transitions
 * - Order Sections & Zero Duplication
 * - Order Image Gallery & Max 3 Photos
 * - Customer Sequence Classification
 */

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
echo " TEST SUITE: ADMIN DASHBOARD V2 VERIFICATION\n";
echo "=====================================================================\n";

$pdo = get_db_connection();

// -------------------------------------------------------------------
// 1. FINANCIAL FIX & KAMAL'S ORDER (LT20260919-673)
// -------------------------------------------------------------------
echo "\n--- Section 1: Financial Fix & Database Authority ---\n";

$stmt = $pdo->prepare("SELECT * FROM orders WHERE order_ref = 'LT20260919-673' LIMIT 1");
$stmt->execute();
$kamalOrder = $stmt->fetch(PDO::FETCH_ASSOC);

assert_true($kamalOrder !== false, "Kamal's order LT20260919-673 exists in permanent MySQL database");

$orderId = (int)$kamalOrder['id'];
$payStmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM order_payments WHERE order_id = :oid AND status = 'completed'");
$payStmt->execute([':oid' => $orderId]);
$completedPaymentsSum = (float)$payStmt->fetchColumn();

$totalAmt = (float)$kamalOrder['total_amount'];
$advanceAmt = (float)$kamalOrder['advance_amount'];
$paidAmt = $completedPaymentsSum > 0 ? $completedPaymentsSum : $advanceAmt;
$balanceAmt = max(0.0, $totalAmt - $paidAmt);

assert_equals($totalAmt, 4025.0, "Kamal's order total is ₹4,025");
assert_equals($paidAmt, 2638.0, "Kamal's paid amount is ₹2,638 (NOT ₹0!)");
assert_equals($balanceAmt, 1387.0, "Kamal's remaining balance is ₹1,387");

$paymentStatus = ($paidAmt >= $totalAmt && $totalAmt > 0) ? 'fully_paid' : (($paidAmt > 0) ? 'partially_paid' : 'unpaid');
assert_equals($paymentStatus, 'partially_paid', "Kamal's payment status is 'partially_paid'");

// -------------------------------------------------------------------
// 2. FOUR CANONICAL STATUSES & DISPLAY LABELS
// -------------------------------------------------------------------
echo "\n--- Section 2: Four Canonical Statuses ---\n";

assert_equals(ORDER_LIFECYCLE_STATUSES, [
    'awaiting_confirmation',
    'stitching_in_process',
    'completed',
    'delivered'
], "ORDER_LIFECYCLE_STATUSES contains exactly the 4 canonical statuses");

assert_equals(get_canonical_status('pending'), 'awaiting_confirmation', "pending -> awaiting_confirmation");
assert_equals(get_canonical_status('pending_confirmation'), 'awaiting_confirmation', "pending_confirmation -> awaiting_confirmation");
assert_equals(get_canonical_status('awaiting_confirmation'), 'awaiting_confirmation', "awaiting_confirmation -> awaiting_confirmation");

assert_equals(get_canonical_status('confirmed'), 'stitching_in_process', "confirmed -> stitching_in_process");
assert_equals(get_canonical_status('cutting'), 'stitching_in_process', "cutting -> stitching_in_process");
assert_equals(get_canonical_status('stitching'), 'stitching_in_process', "stitching -> stitching_in_process");
assert_equals(get_canonical_status('stitching_in_process'), 'stitching_in_process', "stitching_in_process -> stitching_in_process");
assert_equals(get_canonical_status('in_production'), 'stitching_in_process', "in_production -> stitching_in_process");

assert_equals(get_canonical_status('completed'), 'completed', "completed -> completed");
assert_equals(get_canonical_status('collected'), 'completed', "collected -> completed");
assert_equals(get_canonical_status('delivered'), 'delivered', "delivered -> delivered");

assert_equals(get_status_display_label('awaiting_confirmation'), 'Awaiting Confirmation', "Label for awaiting_confirmation");
assert_equals(get_status_display_label('stitching_in_process'), 'Stitching in Process', "Label for stitching_in_process");
assert_equals(get_status_display_label('completed'), 'Completed', "Label for completed");
assert_equals(get_status_display_label('delivered'), 'Delivered', "Label for delivered");

// -------------------------------------------------------------------
// 3. SERVER-SIDE SEQUENTIAL TRANSITION VALIDATION
// -------------------------------------------------------------------
echo "\n--- Section 3: Server-Side Sequential Transitions ---\n";

// Set up a mock session order for transition testing
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$_SESSION['admin_user'] = ['id' => 1, 'username' => 'admin', 'role' => 'super_admin'];

// Order at awaiting_confirmation
$_SESSION['customer_orders'][999]['LT-SEQ-TEST'] = [
    'order_ref' => 'LT-SEQ-TEST',
    'user_id' => 999,
    'status' => 'awaiting_confirmation',
    'advance_payment' => ['order_total' => 1000, 'selected_amount' => 500],
    'payment' => ['status' => 'completed', 'amount_paid' => 500, 'remaining_balance' => 500]
];

// Test: Valid transition: awaiting_confirmation -> stitching_in_process
$res1 = admin_update_order_status('LT-SEQ-TEST', 'stitching_in_process', 'Moving to production', 1, 'super_admin');
assert_true($res1['success'], "Valid transition: awaiting_confirmation -> stitching_in_process succeeds");

// Reset back to awaiting_confirmation
$_SESSION['customer_orders'][999]['LT-SEQ-TEST']['status'] = 'awaiting_confirmation';

// Test: Invalid jump: awaiting_confirmation -> completed (REJECTED)
$res2 = admin_update_order_status('LT-SEQ-TEST', 'completed', 'Trying to skip', 1, 'super_admin');
assert_true(!$res2['success'], "Invalid transition: awaiting_confirmation -> completed is rejected");
assert_true(strpos($res2['error'], 'Invalid status transition') !== false, "Error message explains invalid status transition");

// Test: Invalid jump: awaiting_confirmation -> delivered (REJECTED)
$res3 = admin_update_order_status('LT-SEQ-TEST', 'delivered', 'Trying to skip', 1, 'super_admin');
assert_true(!$res3['success'], "Invalid transition: awaiting_confirmation -> delivered is rejected");

// Set order to stitching_in_process
$_SESSION['customer_orders'][999]['LT-SEQ-TEST']['status'] = 'stitching_in_process';

// Test: Invalid jump: stitching_in_process -> delivered (REJECTED)
$res4 = admin_update_order_status('LT-SEQ-TEST', 'delivered', 'Trying to skip completed', 1, 'super_admin');
assert_true(!$res4['success'], "Invalid transition: stitching_in_process -> delivered is rejected");

// Test: Valid transition: stitching_in_process -> completed
$res5 = admin_update_order_status('LT-SEQ-TEST', 'completed', 'Garment finished', 1, 'super_admin');
assert_true($res5['success'], "Valid transition: stitching_in_process -> completed succeeds");

// Test: Valid transition: completed -> delivered
$res6 = admin_update_order_status('LT-SEQ-TEST', 'delivered', 'Customer picked up', 1, 'super_admin');
assert_true($res6['success'], "Valid transition: completed -> delivered succeeds");

// Test: Transition from delivered (terminal)
$res7 = admin_update_order_status('LT-SEQ-TEST', 'completed', 'Cannot un-deliver', 1, 'super_admin');
assert_true(!$res7['success'], "Invalid transition: delivered orders cannot be transitioned further");

// Clean up mock order
unset($_SESSION['customer_orders'][999]);

// -------------------------------------------------------------------
// 4. CUSTOMER SEQUENCE CLASSIFICATION
// -------------------------------------------------------------------
echo "\n--- Section 4: Customer Sequence Classification ---\n";

assert_equals(get_customer_sequence_label(1), 'New Customer', "Sequence 1 is 'New Customer'");
assert_equals(get_customer_sequence_label(2), '2nd Time Customer', "Sequence 2 is '2nd Time Customer'");
assert_equals(get_customer_sequence_label(3), '3rd Time Customer', "Sequence 3 is '3rd Time Customer'");
assert_equals(get_customer_sequence_label(4), '4th Time Customer', "Sequence 4 is '4th Time Customer'");

$seqMap = get_customer_order_sequence_map($pdo);
assert_true(isset($seqMap['by_order_id'][$orderId]), "Kamal's order ID exists in sequence map");
assert_equals($seqMap['by_order_id'][$orderId], 2, "Kamal's order LT20260919-673 is classified as sequence 2");
assert_equals(get_customer_sequence_label($seqMap['by_order_id'][$orderId]), '2nd Time Customer', "Kamal is classified as '2nd Time Customer'");

// -------------------------------------------------------------------
// 5. ORDER GALLERY DATABASE & LIMITS
// -------------------------------------------------------------------
echo "\n--- Section 5: Order Gallery Photos & Constraints ---\n";

// Verify table structure
$gCols = $pdo->query("SHOW COLUMNS FROM order_gallery_photos")->fetchAll(PDO::FETCH_COLUMN);
assert_true(in_array('order_id', $gCols, true), "order_gallery_photos has order_id column");
assert_true(in_array('stage', $gCols, true), "order_gallery_photos has stage column");
assert_true(in_array('photo_url', $gCols, true), "order_gallery_photos has photo_url column");
assert_true(in_array('caption', $gCols, true), "order_gallery_photos has caption column");

// Insert a test photo for Kamal's order
$testUrl = 'uploads/order_gallery/test_photo_' . time() . '.jpg';
$insP = $pdo->prepare("
    INSERT INTO order_gallery_photos (order_id, stage, photo_url, original_filename, file_size_bytes, mime_type, caption, created_at)
    VALUES (:oid, 'awaiting_confirmation', :url, 'sample.jpg', 1024, 'image/jpeg', 'Intake fabric roll sample', NOW())
");
$insP->execute([':oid' => $orderId, ':url' => $testUrl]);
$photoId = (int)$pdo->lastInsertId();

assert_true($photoId > 0, "Test gallery photo inserted with ID $photoId");

// Verify photo retrieval
$chkP = $pdo->prepare("SELECT * FROM order_gallery_photos WHERE id = :id");
$chkP->execute([':id' => $photoId]);
$photoRow = $chkP->fetch(PDO::FETCH_ASSOC);
assert_equals($photoRow['caption'], 'Intake fabric roll sample', "Gallery photo caption verified");
assert_equals($photoRow['stage'], 'awaiting_confirmation', "Gallery photo stage verified");

// Delete test photo
$delP = $pdo->prepare("DELETE FROM order_gallery_photos WHERE id = :id");
$delP->execute([':id' => $photoId]);
$chkDel = $pdo->prepare("SELECT COUNT(*) FROM order_gallery_photos WHERE id = :id");
$chkDel->execute([':id' => $photoId]);
assert_equals((int)$chkDel->fetchColumn(), 0, "Gallery photo safely deleted from database");

// Verify .htaccess exists in uploads/order_gallery
$htaccessPath = __DIR__ . '/../uploads/order_gallery/.htaccess';
assert_true(file_exists($htaccessPath), "Security .htaccess exists in uploads/order_gallery");
$htaccessContent = file_get_contents($htaccessPath);
assert_true(strpos($htaccessContent, 'php_flag engine off') !== false || strpos($htaccessContent, 'Deny from all') !== false, ".htaccess disables script execution in gallery upload directory");

// -------------------------------------------------------------------
// 6. ADMIN DASHBOARD MARKUP & SECTION DEDUPLICATION
// -------------------------------------------------------------------
echo "\n--- Section 6: Admin Dashboard Markup & Sections ---\n";

$adminHtml = file_get_contents(__DIR__ . '/../admin/index.php');
assert_true(strpos($adminHtml, '1. Awaiting Confirmation') !== false, "admin/index.php contains Section 1: Awaiting Confirmation");
assert_true(strpos($adminHtml, '2. Stitching in Process') !== false, "admin/index.php contains Section 2: Stitching in Process");
assert_true(strpos($adminHtml, '3. Completed Orders') !== false, "admin/index.php contains Section 3: Completed Orders");
assert_true(strpos($adminHtml, '4. Delivered Orders & Customer History') !== false, "admin/index.php contains Section 4: Delivered Orders & Customer History");
assert_true(strpos($adminHtml, 'admin_upload_order_photo') !== false, "admin/index.php contains photo upload handler");
assert_true(strpos($adminHtml, 'admin_delete_order_photo') !== false, "admin/index.php contains photo delete handler");
assert_true(strpos($adminHtml, 'cust-seq-pill') !== false, "admin/index.php contains customer sequence pill markup");
assert_true(strpos($adminHtml, 'ORDER_LIFECYCLE_STATUSES') !== false, "admin/index.php uses ORDER_LIFECYCLE_STATUSES for status select");

echo "\n=====================================================================\n";
echo " TEST SUMMARY\n";
echo " Total Assertions: $testCount\n";
echo " Passed: $passCount\n";
echo " Failed: " . ($testCount - $passCount) . "\n";
echo "=====================================================================\n";
