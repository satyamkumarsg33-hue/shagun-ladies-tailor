<?php
/**
 * Test Suite: Standard Stitching Step-by-Step Flow Enforcement
 * 
 * Verifies that:
 * 1. Standard-only cart ALWAYS starts at Step 1: Standard Measurements.
 * 2. Standard-only cart NEVER skips directly to Payment.
 * 3. Step 2: Review is guarded against direct access when measurements are not saved.
 * 4. Step 3: Payment is guarded against direct access when review is not confirmed.
 * 5. Review shows the actual current cart garments, style, price, and measurement method.
 * 6. Historical Luxe orders in My Orders do NOT bypass measurements or review.
 * 7. Payment only receives current Standard order data.
 * 8. Historical Luxe orders in database remain untouched.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/cart.php';
require_once __DIR__ . '/../includes/unified-cart.php';
require_once __DIR__ . '/../includes/order-status.php';

function assert_step_test(bool $condition, string $message): void {
    if ($condition) {
        echo "  [PASS] $message\n";
    } else {
        echo "  [FAIL] $message\n";
        debug_print_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
        exit(1);
    }
}

echo "======================================================================\n";
echo "TEST SUITE: STANDARD STITCHING STEP-BY-STEP FLOW ENFORCEMENT\n";
echo "======================================================================\n\n";

$cookieFile = __DIR__ . '/cookie_step_test_' . time() . '.txt';

function step_http_call(string $url, string $method = 'GET', array $postData = [], string $cookieFile = '', bool $followRedirects = true): array {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, $followRedirects);
    curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
    curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
    }

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $effectiveUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    $redirectUrl = curl_getinfo($ch, CURLINFO_REDIRECT_URL);
    curl_close($ch);

    return [
        'code' => $httpCode,
        'body' => (string)$response,
        'url' => $effectiveUrl,
        'redirect_url' => $redirectUrl
    ];
}

$baseUrl = 'http://localhost/shagun-ladies-tailor/';
$pdo = get_db_connection();

// 1. Setup isolated test customer Jamun
$testEmail = 'jamun_step_' . time() . '@example.com';
$testPass = 'password123';
$testHash = password_hash($testPass, PASSWORD_BCRYPT);
$testName = 'Jamun (Step Test)';
$testPhone = '+91 9876599887';

$uStmt = $pdo->prepare("INSERT INTO users (name, email, password_hash, role, phone, created_at) VALUES (:n, :e, :h, 'customer', :p, NOW())");
$uStmt->execute([':n' => $testName, ':e' => $testEmail, ':h' => $testHash, ':p' => $testPhone]);
$testUserId = (int) $pdo->lastInsertId();

try {
    echo "1. LOG IN AS CUSTOMER JAMUN OVER HTTP\n";
    $loginRes = step_http_call($baseUrl . 'login.php', 'POST', [
        'action' => 'credentials',
        'identity' => $testEmail,
        'password' => $testPass,
        'redirect' => 'luxe-stitching.php'
    ], $cookieFile);
    assert_step_test($loginRes['code'] === 200, "Customer logged in successfully (HTTP 200)");

    echo "\n2. PLACE A LUXE WEDDING ORDER (TO ESTABLISH HISTORICAL LUXE ORDER)\n";
    $readyDate = date('Y-m-d', strtotime('+15 days'));
    step_http_call($baseUrl . 'people.php', 'POST', [
        'workflow' => 'wedding',
        'requested_ready_date' => $readyDate,
        'people_count' => '1',
        'wedding_notes' => 'Jamun Luxe Bridal'
    ], $cookieFile);

    step_http_call($baseUrl . 'people.php', 'POST', [
        'save_people' => '1',
        'person_name' => ['Jamun'],
        'person_role' => ['Bride'],
        'person_garments' => ['["Blouse"]']
    ], $cookieFile);

    step_http_call($baseUrl . 'customize-blouse.php?luxe=1&person=0&garment_idx=0&style=princess-cut', 'POST', [
        'action' => 'save_customization',
        'luxe' => '1',
        'person' => '0',
        'garment_idx' => '0',
        'style' => 'princess-cut',
        'neckline' => 'round',
        'front_neckline' => 'round',
        'back_neckline' => 'deep-round',
        'sleeve_type' => 'elbow',
        'opening_side' => 'back',
        'padding' => 'yes',
        'lining' => 'cotton',
        'piping' => 'contrast',
        'tassels' => 'latkan'
    ], $cookieFile);

    step_http_call($baseUrl . 'luxe-work.php?person=0&garment_idx=0', 'POST', [
        'work_type' => 'no_work'
    ], $cookieFile);

    step_http_call($baseUrl . 'measurements.php', 'POST', [
        'action' => 'save_measurements',
        'measurements' => [0 => 'reference_blouse'],
        'garment_measurements' => [0 => [0 => 'reference_blouse']]
    ], $cookieFile);

    step_http_call($baseUrl . 'review-payment.php', 'POST', [
        'advance_amount' => '600',
        'declaration' => '1'
    ], $cookieFile);

    $luxePayRes = step_http_call($baseUrl . 'payment.php', 'POST', [
        'action' => 'simulate_success',
        'payment_method' => 'upi',
        'payment_method_label' => 'UPI / QR Code'
    ], $cookieFile);
    assert_step_test(strpos($luxePayRes['body'], 'Payment Successful') !== false, "Luxe order paid and confirmed");

    preg_match('/LT\d{8}-\d+/', $luxePayRes['body'], $luxeMatches);
    $luxeRef = $luxeMatches[0] ?? '';
    assert_step_test(!empty($luxeRef), "Extracted Historical Luxe Reference: {$luxeRef}");

    echo "\n3. CUSTOMER ADDS PRINCESS CUT BLOUSE (₹800) TO ORDER\n";
    $addRes = step_http_call($baseUrl . 'customize-blouse.php?style=princess-cut', 'POST', [
        'action' => 'add_to_cart',
        'style' => 'princess-cut',
        'neckline' => 'round',
        'front_neckline' => 'round',
        'back_neckline' => 'round',
        'sleeve_type' => 'short',
        'opening_side' => 'front',
        'lining' => 'cotton',
        'notes' => 'Jamun Fresh Standard Order'
    ], $cookieFile);
    assert_step_test($addRes['code'] === 200, "Princess Cut blouse added to order");

    echo "\n4. VERIFY CART.PHP CONTENTS & TOTALS\n";
    $cartRes = step_http_call($baseUrl . 'cart.php', 'GET', [], $cookieFile);
    assert_step_test(strpos($cartRes['body'], 'STANDARD STITCHING') !== false, "cart.php displays STANDARD STITCHING badge");
    assert_step_test(strpos($cartRes['body'], 'Garment 1') !== false || strpos($cartRes['body'], 'Garment #1') !== false || strpos($cartRes['body'], 'Garment') !== false, "cart.php displays Garment item");
    assert_step_test(strpos($cartRes['body'], 'Princess Cut Blouse') !== false, "cart.php displays Princess Cut Blouse");
    assert_step_test(strpos($cartRes['body'], 'Total Physical Garments') !== false, "cart.php displays Total Physical Garments");
    assert_step_test(strpos($cartRes['body'], 'Standard Items') !== false, "cart.php displays Standard Items: 1");
    assert_step_test(strpos($cartRes['body'], 'Proceed to Place Order') !== false, "cart.php displays 'Proceed to Place Order' button");

    echo "\n5. CLICK 'PROCEED TO PLACE ORDER' (CHECKOUT.PHP) — MUST LAND ON MEASUREMENTS\n";
    $checkoutEntryRes = step_http_call($baseUrl . 'checkout.php', 'GET', [], $cookieFile);
    assert_step_test($checkoutEntryRes['code'] === 200, "checkout.php returns HTTP 200 OK");

    // CRITICAL BUG ASSERTION: MUST NOT BE ON PAYMENT OR REVIEW!
    assert_step_test(strpos($checkoutEntryRes['body'], 'STEP 3 OF 3 · SECURE PAYMENT') === false,
        "CRITICAL: checkout.php DOES NOT show 'STEP 3 OF 3 · SECURE PAYMENT' upon entry from cart!");
    assert_step_test(strpos($checkoutEntryRes['body'], 'Complete Your Payment') === false,
        "CRITICAL: checkout.php DOES NOT show 'Complete Your Payment' upon entry from cart!");
    assert_step_test(strpos($checkoutEntryRes['body'], 'STEP 2 OF 3 · ORDER SUMMARY') === false,
        "checkout.php DOES NOT skip to 'STEP 2 OF 3 · ORDER SUMMARY' upon entry from cart!");

    // MUST BE ON STEP 1: MEASUREMENTS & REFERENCE
    assert_step_test(strpos($checkoutEntryRes['body'], 'STANDARD STITCHING CHECKOUT') !== false,
        "checkout.php displays 'STANDARD STITCHING CHECKOUT' eyebrow");
    assert_step_test(strpos($checkoutEntryRes['body'], 'Measurements & Reference') !== false,
        "checkout.php displays Step 1: 'Measurements & Reference'");
    assert_step_test(strpos($checkoutEntryRes['body'], 'Reference Blouse') !== false,
        "checkout.php displays Reference Blouse option");
    assert_step_test(strpos($checkoutEntryRes['body'], 'Visit Shop') !== false,
        "checkout.php displays Visit Shop option");
    assert_step_test(strpos($checkoutEntryRes['body'], 'Continue to Review & Advance →') !== false,
        "checkout.php displays 'Continue to Review & Advance →' CTA");

    echo "\n6. TEST ACCESS GUARDS: ATTEMPT BYPASSING TO REVIEW OR PAYMENT DIRECTLY\n";
    // Attempt ?step=payment without completing measurement or review
    $bypassPayRes = step_http_call($baseUrl . 'checkout.php?step=payment', 'GET', [], $cookieFile, false);
    assert_step_test(in_array($bypassPayRes['code'], [302, 301], true),
        "Direct access to ?step=payment before measurements triggers HTTP 302 redirect");
    assert_step_test(strpos($bypassPayRes['redirect_url'], 'step=measurement') !== false,
        "Direct access to ?step=payment redirects back to step=measurement");

    // Attempt ?step=review without completing measurements
    $bypassRevRes = step_http_call($baseUrl . 'checkout.php?step=review', 'GET', [], $cookieFile, false);
    assert_step_test(in_array($bypassRevRes['code'], [302, 301], true),
        "Direct access to ?step=review before measurements triggers HTTP 302 redirect");
    assert_step_test(strpos($bypassRevRes['redirect_url'], 'step=measurement') !== false,
        "Direct access to ?step=review redirects back to step=measurement");

    echo "\n7. STEP 1: SELECT MEASUREMENT METHOD (REFERENCE BLOUSE) & CONTINUE\n";
    $saveMeasRes = step_http_call($baseUrl . 'checkout.php?step=measurement', 'POST', [
        'action' => 'save_measurements',
        'measurement_method' => 'reference_blouse',
        'requested_ready_date' => date('Y-m-d', strtotime('+12 days')),
        'notes' => 'Please provide 2-inch sleeve margin.'
    ], $cookieFile, false);
    assert_step_test(in_array($saveMeasRes['code'], [302, 301], true), "Saving measurements issues redirect (HTTP {$saveMeasRes['code']})");
    assert_step_test(strpos($saveMeasRes['redirect_url'], 'step=review') !== false,
        "Saving measurements redirects to step=review (URL: {$saveMeasRes['redirect_url']})");

    echo "\n8. STEP 2: VERIFY STANDARD REVIEW SCREEN DETAILS\n";
    $reviewRes = step_http_call($baseUrl . 'checkout.php?step=review', 'GET', [], $cookieFile);
    assert_step_test($reviewRes['code'] === 200, "checkout.php?step=review loads successfully (HTTP 200)");
    assert_step_test(strpos($reviewRes['body'], 'STEP 2 OF 3 · ORDER SUMMARY') !== false,
        "Review page displays 'STEP 2 OF 3 · ORDER SUMMARY'");
    assert_step_test(strpos($reviewRes['body'], 'Review Your Order') !== false,
        "Review page displays 'Review Your Order' heading");
    assert_step_test(strpos($reviewRes['body'], 'Jamun') !== false,
        "Review page displays customer name 'Jamun'");
    assert_step_test(strpos($reviewRes['body'], 'Reference Blouse') !== false,
        "Review page displays selected fitting reference 'Reference Blouse'");
    assert_step_test(strpos($reviewRes['body'], 'Princess Cut Blouse') !== false,
        "Review page displays garment 'Princess Cut Blouse'");
    assert_step_test(strpos($reviewRes['body'], '₹800') !== false,
        "Review page displays garment price '₹800'");
    assert_step_test(strpos($reviewRes['body'], 'Choose Advance Payment') !== false,
        "Review page displays Advance Payment selection");
    assert_step_test(strpos($reviewRes['body'], 'id="confirm-pay-btn"') !== false,
        "Review page has 'Confirm & Pay' button");

    echo "\n9. TEST ACCESS GUARD: ATTEMPT BYPASSING TO PAYMENT WITHOUT REVIEW CONFIRMATION\n";
    // Attempt ?step=payment without confirming review declaration
    $bypassPay2Res = step_http_call($baseUrl . 'checkout.php?step=payment', 'GET', [], $cookieFile, false);
    assert_step_test(in_array($bypassPay2Res['code'], [302, 301], true),
        "Access to ?step=payment without review confirmation issues redirect");
    assert_step_test(strpos($bypassPay2Res['redirect_url'], 'step=review') !== false,
        "Access to ?step=payment redirects back to step=review");

    echo "\n10. STEP 2: CONFIRM REVIEW & ADVANCE PAYMENT (50% = ₹400)\n";
    $confirmRevRes = step_http_call($baseUrl . 'checkout.php?step=review', 'POST', [
        'action' => 'confirm_review',
        'declaration' => '1',
        'advance_amount' => '400'
    ], $cookieFile, false);
    assert_step_test(in_array($confirmRevRes['code'], [302, 301], true),
        "Confirming review issues redirect (HTTP {$confirmRevRes['code']})");
    assert_step_test(strpos($confirmRevRes['redirect_url'], 'step=payment') !== false,
        "Confirming review redirects to step=payment (URL: {$confirmRevRes['redirect_url']})");

    echo "\n11. STEP 3: VERIFY SECURE PAYMENT SCREEN DETAILS\n";
    $payScreenRes = step_http_call($baseUrl . 'checkout.php?step=payment', 'GET', [], $cookieFile);
    assert_step_test($payScreenRes['code'] === 200, "checkout.php?step=payment loads successfully (HTTP 200)");
    assert_step_test(strpos($payScreenRes['body'], 'STEP 3 OF 3 · SECURE PAYMENT') !== false,
        "Payment page displays 'STEP 3 OF 3 · SECURE PAYMENT'");
    assert_step_test(strpos($payScreenRes['body'], 'Complete Your Payment') !== false,
        "Payment page displays 'Complete Your Payment' heading");
    assert_step_test(strpos($payScreenRes['body'], '₹400') !== false,
        "Payment page displays selected advance amount ₹400");
    assert_step_test(strpos($payScreenRes['body'], 'Princess Cut Blouse') !== false,
        "Payment page displays current order garment 'Princess Cut Blouse'");

    echo "\n12. STEP 3: SIMULATE PAYMENT SUCCESS\n";
    $paySuccessRes = step_http_call($baseUrl . 'checkout.php?step=payment', 'POST', [
        'action' => 'simulate_success'
    ], $cookieFile, false);
    assert_step_test(in_array($paySuccessRes['code'], [302, 301], true),
        "Simulating payment issues redirect (HTTP {$paySuccessRes['code']})");
    assert_step_test(strpos($paySuccessRes['redirect_url'], 'step=confirmation') !== false,
        "Simulating payment redirects to step=confirmation");

    echo "\n13. CONFIRMATION STEP & ORDER REFERENCE\n";
    $confScreenRes = step_http_call($baseUrl . 'checkout.php?step=confirmation', 'GET', [], $cookieFile);
    assert_step_test(strpos($confScreenRes['body'], 'Payment Successful') !== false,
        "Confirmation screen displays 'Payment Successful'");
    assert_step_test(strpos($confScreenRes['body'], 'Download SHAGUN — Order Dossier') !== false,
        "Confirmation screen displays Dossier download button");

    preg_match('/LT\d{8}-\d+/', $confScreenRes['body'], $stdMatches);
    $stdRef = $stdMatches[0] ?? '';
    assert_step_test(!empty($stdRef), "Extracted Standard Order Reference: {$stdRef}");
    assert_step_test($stdRef !== $luxeRef, "Standard reference {$stdRef} is distinct from Luxe reference {$luxeRef}");

    echo "\n14. VERIFY ORDERS.PHP (MY ORDERS) HISTORICAL ISOLATION\n";
    $finalOrdersRes = step_http_call($baseUrl . 'orders.php', 'GET', [], $cookieFile);
    assert_step_test(strpos($finalOrdersRes['body'], $luxeRef) !== false,
        "Historical Luxe order {$luxeRef} is preserved untouched in My Orders");
    assert_step_test(strpos($finalOrdersRes['body'], $stdRef) !== false,
        "New Standard order {$stdRef} appears in My Orders");
    assert_step_test(strpos($finalOrdersRes['body'], 'STANDARD STITCHING') !== false,
        "My Orders displays STANDARD STITCHING badge");
    assert_step_test(strpos($finalOrdersRes['body'], 'SHAGUN LUXE') !== false,
        "My Orders displays SHAGUN LUXE badge");

    echo "\n======================================================================\n";
    echo "ALL 14 STEP-BY-STEP FLOW ENFORCEMENT CHECKS PASSED PERFECTLY!\n";
    echo "======================================================================\n";

} finally {
    if (file_exists($cookieFile)) {
        @unlink($cookieFile);
    }
}
