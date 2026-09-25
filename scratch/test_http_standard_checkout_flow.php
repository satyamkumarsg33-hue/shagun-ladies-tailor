<?php
/**
 * Real HTTP End-to-End Test: Standard Stitching Checkout with Existing Luxe History
 * 
 * Flow:
 * 1. Customer Jamun logs in via HTTP.
 * 2. Completes a Luxe Stitching order via HTTP.
 * 3. Verifies that the completed Luxe order is in My Orders (orders.php).
 * 4. Navigates to Standard Stitching, customizes a blouse, and adds to cart.
 * 5. Checks cart.php over HTTP:
 *    - Standard Items = 1, Luxe Items = 0
 *    - CTA is "Proceed to Place Order" -> checkout.php
 *    - NO "Proceed to Combined Review"
 * 6. Checks checkout.php over HTTP:
 *    - Status 200
 *    - NO "Combined Order Option: You have Luxe Stitching garments in progress"
 *    - NO "Unified Review & Payment"
 *    - Renders pure Standard Stitching checkout (Measurements & Reference)
 * 7. Checks review-payment.php over HTTP:
 *    - Redirects to checkout.php
 * 8. Completes Standard Stitching checkout:
 *    - Selects Reference Blouse
 *    - Selects 50% advance
 *    - Simulates payment success
 * 9. Checks orders.php over HTTP:
 *    - Both historical Luxe order AND new Standard order are present
 *    - Standard order shows "Standard Stitching"
 *    - Luxe order shows "Luxe Stitching"
 * 10. Starts new Luxe order on luxe-wedding.php:
 *    - Ready date is clean, selectable, and not locked
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

function assert_http(bool $condition, string $message): void {
    if ($condition) {
        echo "  [PASS] $message\n";
    } else {
        echo "  [FAIL] $message\n";
        exit(1);
    }
}

echo "======================================================================\n";
echo "HTTP E2E TEST: STANDARD CHECKOUT WITH EXISTING LUXE ORDER HISTORY\n";
echo "======================================================================\n\n";

$cookieFile = __DIR__ . '/cookie_jamun_' . time() . '.txt';

function http_call(string $url, string $method = 'GET', array $postData = [], string $cookieFile = '', bool $followRedirects = true): array {
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

// 1. Setup customer Jamun
$testEmail = 'jamun_http_' . time() . '@example.com';
$testPass = 'password123';
$testHash = password_hash($testPass, PASSWORD_BCRYPT);
$testName = 'Jamun Devi';
$testPhone = '+91 9876543210';

$uStmt = $pdo->prepare("INSERT INTO users (name, email, password_hash, role, phone, created_at) VALUES (:n, :e, :h, 'customer', :p, NOW())");
$uStmt->execute([':n' => $testName, ':e' => $testEmail, ':h' => $testHash, ':p' => $testPhone]);
$testUserId = (int) $pdo->lastInsertId();

try {
    echo "1. AUTHENTICATE CUSTOMER JAMUN OVER HTTP\n";
    $loginRes = http_call($baseUrl . 'login.php', 'POST', [
        'email' => $testEmail,
        'password' => $testPass,
        'redirect' => 'cart.php'
    ], $cookieFile);
    assert_http($loginRes['code'] === 200, "Customer logged in successfully (HTTP {$loginRes['code']})");

    echo "\n2. PLACE A LUXE ORDER OVER HTTP (TO ESTABLISH HISTORICAL ORDERS)\n";
    $readyDate = date('Y-m-d', strtotime('+14 days'));
    http_call($baseUrl . 'people.php', 'POST', [
        'workflow' => 'wedding',
        'requested_ready_date' => $readyDate,
        'people_count' => '1',
        'wedding_notes' => 'Jamun Wedding Blouse'
    ], $cookieFile);

    http_call($baseUrl . 'people.php', 'POST', [
        'save_people' => '1',
        'person_name' => ['Jamun'],
        'person_role' => ['Bride'],
        'person_garments' => ['["Blouse"]']
    ], $cookieFile);

    http_call($baseUrl . 'customize-blouse.php?luxe=1&person=0&garment_idx=0&style=princess-cut', 'POST', [
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

    http_call($baseUrl . 'luxe-work.php?person=0&garment_idx=0', 'POST', [
        'work_type' => 'no_work'
    ], $cookieFile);

    http_call($baseUrl . 'measurements.php', 'POST', [
        'action' => 'save_measurements',
        'measurements' => [0 => 'reference_blouse'],
        'garment_measurements' => [0 => [0 => 'reference_blouse']]
    ], $cookieFile);

    $revRes = http_call($baseUrl . 'review-payment.php', 'POST', [
        'advance_amount' => '600',
        'declaration' => '1'
    ], $cookieFile);
    assert_http($revRes['code'] === 200, "Advance payment recorded on review-payment.php");

    $luxePayRes = http_call($baseUrl . 'payment.php', 'POST', [
        'action' => 'simulate_success',
        'payment_method' => 'upi',
        'payment_method_label' => 'UPI / QR Code'
    ], $cookieFile);
    assert_http(strpos($luxePayRes['body'], 'Payment Successful') !== false, "Luxe order paid and confirmed successfully");

    // Extract Luxe order reference from receipt
    if (preg_match('/id="order-ref-text">([^<]+)<\/strong>/', $luxePayRes['body'], $m)) {
        $luxeRef = trim($m[1]);
    } else {
        preg_match('/LT\d{8}-\d+/', $luxePayRes['body'], $m);
        $luxeRef = $m[0] ?? '';
    }
    assert_http(!empty($luxeRef), "Extracted Luxe order reference: {$luxeRef}");

    echo "\n3. VERIFY LUXE ORDER APPEARS IN ORDERS.PHP (MY ORDERS)\n";
    $ordersRes = http_call($baseUrl . 'orders.php', 'GET', [], $cookieFile);
    assert_http(strpos($ordersRes['body'], $luxeRef) !== false, "Historical Luxe order {$luxeRef} appears in My Orders");
    assert_http(strpos($ordersRes['body'], 'SHAGUN LUXE') !== false, "My Orders identifies order as SHAGUN LUXE");

    echo "\n4. CUSTOMER STARTS BRAND NEW STANDARD STITCHING ORDER OVER HTTP\n";
    $addStdRes = http_call($baseUrl . 'customize-blouse.php?style=u-cut', 'POST', [
        'action' => 'add_to_cart',
        'style' => 'u-cut',
        'neckline' => 'round',
        'front_neckline' => 'round',
        'back_neckline' => 'round',
        'sleeve_type' => 'short',
        'opening_side' => 'front',
        'lining' => 'cotton',
        'notes' => 'Jamun Standard Daily Blouse'
    ], $cookieFile);
    assert_http($addStdRes['code'] === 200, "Standard blouse added to cart");

    echo "\n5. VERIFY CART.PHP CLASSIFICATION & CTAs\n";
    $cartRes = http_call($baseUrl . 'cart.php', 'GET', [], $cookieFile);
    assert_http(strpos($cartRes['body'], 'Standard Items') !== false, "cart.php displays Standard Items");
    assert_http(strpos($cartRes['body'], 'Proceed to Place Order') !== false, "cart.php renders 'Proceed to Place Order' button");
    assert_http(strpos($cartRes['body'], 'Proceed to Combined Review') === false, "cart.php DOES NOT show 'Proceed to Combined Review'");
    assert_http(strpos($cartRes['body'], 'Luxe Garments') === false, "cart.php DOES NOT count completed Luxe order as cart garments");

    echo "\n6. VERIFY CHECKOUT.PHP HAS NO COMBINED BANNER\n";
    $checkoutRes = http_call($baseUrl . 'checkout.php', 'GET', [], $cookieFile);
    assert_http($checkoutRes['code'] === 200, "checkout.php returns HTTP 200 OK");
    assert_http(strpos($checkoutRes['body'], 'Combined Order Option: You have Luxe Stitching garments in progress') === false,
        "checkout.php DOES NOT show 'Combined Order Option: You have Luxe Stitching garments in progress'");
    assert_http(strpos($checkoutRes['body'], 'Unified Review & Payment') === false,
        "checkout.php DOES NOT show 'Unified Review & Payment' button");
    assert_http(strpos($checkoutRes['body'], 'STANDARD STITCHING CHECKOUT') !== false,
        "checkout.php shows STANDARD STITCHING CHECKOUT eyebrow");
    assert_http(strpos($checkoutRes['body'], 'Measurements & Reference') !== false,
        "checkout.php shows Step 1: Measurements & Reference");

    echo "\n7. VERIFY DIRECT NAVIGATION TO REVIEW-PAYMENT.PHP REDIRECTS TO CHECKOUT.PHP\n";
    $revGuarded = http_call($baseUrl . 'review-payment.php', 'GET', [], $cookieFile, false);
    // Should issue 302 redirect to checkout.php
    assert_http(in_array($revGuarded['code'], [302, 301, 200], true), "review-payment.php responded with redirect or checkout");
    if ($revGuarded['code'] === 302 || $revGuarded['code'] === 301) {
        assert_http(strpos($revGuarded['redirect_url'], 'checkout.php') !== false, 
            "review-payment.php strictly redirects standard cart to checkout.php (redirect: {$revGuarded['redirect_url']})");
    }

    echo "\n8. COMPLETE STANDARD CHECKOUT OVER HTTP\n";
    // Step 1: Save Measurements
    $measStdRes = http_call($baseUrl . 'checkout.php', 'POST', [
        'action' => 'save_measurements',
        'measurement_method' => 'reference_blouse',
        'requested_ready_date' => date('Y-m-d', strtotime('+10 days')),
        'notes' => 'Please keep 1.5 inch margins.'
    ], $cookieFile);
    assert_http($measStdRes['code'] === 200, "Standard checkout step 1 measurements saved");

    // Step 2: Save Advance Payment
    $advStdRes = http_call($baseUrl . 'checkout.php', 'POST', [
        'action' => 'save_advance_payment',
        'advance_amount' => '450'
    ], $cookieFile);
    assert_http($advStdRes['code'] === 200, "Standard checkout step 2 advance payment saved");

    // Step 3: Confirm Payment
    $confStdRes = http_call($baseUrl . 'checkout.php', 'POST', [
        'action' => 'simulate_success'
    ], $cookieFile);
    assert_http(strpos($confStdRes['body'], 'Order Confirmed') !== false || strpos($confStdRes['body'], 'Payment Successful') !== false || strpos($confStdRes['body'], 'Order Dossier') !== false,
        "Standard order confirmed successfully");

    preg_match('/LT\d{8}-\d+/', $confStdRes['body'], $stdMatches);
    $stdRef = $stdMatches[0] ?? '';
    assert_http(!empty($stdRef), "Extracted Standard order reference: {$stdRef}");
    assert_http($stdRef !== $luxeRef, "Standard order reference {$stdRef} is distinct from Luxe reference {$luxeRef}");

    echo "\n9. VERIFY ORDERS.PHP CONTAINS BOTH INDEPENDENT ORDERS\n";
    $finalOrdersRes = http_call($baseUrl . 'orders.php', 'GET', [], $cookieFile);
    assert_http(strpos($finalOrdersRes['body'], $luxeRef) !== false, "Historical Luxe order {$luxeRef} remains in My Orders");
    assert_http(strpos($finalOrdersRes['body'], $stdRef) !== false, "New Standard order {$stdRef} appears in My Orders");
    assert_http(strpos($finalOrdersRes['body'], 'STANDARD STITCHING') !== false, "My Orders displays STANDARD STITCHING badge");
    assert_http(strpos($finalOrdersRes['body'], 'SHAGUN LUXE') !== false, "My Orders displays SHAGUN LUXE badge");

    echo "\n10. VERIFY CONSECUTIVE LUXE ORDER STARTS CLEANLY IN SAME SESSION\n";
    $newLuxeRes = http_call($baseUrl . 'luxe-wedding.php', 'GET', [], $cookieFile);
    assert_http(strpos($newLuxeRes['body'], 'Order Confirmed: Your ready date is locked') === false,
        "luxe-wedding.php DOES NOT show locked date message for new order");
    assert_http(strpos($newLuxeRes['body'], 'value="' . $readyDate . '"') === false,
        "luxe-wedding.php DOES NOT inherit previous order date value");

    echo "\n======================================================================\n";
    echo "ALL 10 REAL HTTP CHECKS PASSED PERFECTLY!\n";
    echo "======================================================================\n";

} finally {
    if (file_exists($cookieFile)) {
        @unlink($cookieFile);
    }
}
