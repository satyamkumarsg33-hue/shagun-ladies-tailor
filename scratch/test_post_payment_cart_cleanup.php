<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

function assert_test(bool $cond, string $msg): void {
    if ($cond) {
        echo "  [PASS] $msg\n";
    } else {
        echo "  [FAIL] $msg\n";
        throw new Exception("Test failed: $msg");
    }
}

function http_req(string $url, string $method = 'GET', array $params = [], ?string $cookieFile = null, bool $follow = true): array {
    $ch = curl_init();
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => $follow,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HEADER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false
    ];

    if ($cookieFile !== null) {
        $opts[CURLOPT_COOKIEJAR] = $cookieFile;
        $opts[CURLOPT_COOKIEFILE] = $cookieFile;
    }

    if ($method === 'POST') {
        $opts[CURLOPT_POST] = true;
        $opts[CURLOPT_POSTFIELDS] = http_build_query($params);
    } else {
        if (!empty($params)) {
            $url .= (strpos($url, '?') !== false ? '&' : '?') . http_build_query($params);
        }
    }

    $opts[CURLOPT_URL] = $url;
    curl_setopt_array($ch, $opts);

    $raw = curl_exec($ch);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $effectiveUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    $redirectUrl = curl_getinfo($ch, CURLINFO_REDIRECT_URL);

    $headers = substr((string)$raw, 0, $headerSize);
    $body = substr((string)$raw, $headerSize);

    curl_close($ch);

    return [
        'code' => $code,
        'headers' => $headers,
        'body' => $body,
        'url' => $effectiveUrl,
        'redirect_url' => $redirectUrl
    ];
}

$baseUrl = 'http://localhost/shagun-ladies-tailor/';
$cookieFile = __DIR__ . '/cookie_cart_test_' . time() . '.txt';

// Create a test user
$pdo = get_db_connection();
$email = 'cart_test_' . time() . '@example.com';
$password = 'secret123';
$hash = password_hash($password, PASSWORD_BCRYPT);
$name = 'Cart Test Customer';
$phone = '9876543210';

$stmt = $pdo->prepare("INSERT INTO users (name, email, password_hash, role, phone, created_at) VALUES (?, ?, ?, 'customer', ?, NOW())");
$stmt->execute([$name, $email, $hash, $phone]);
$userId = (int) $pdo->lastInsertId();

try {
    echo "=== 1. LOGIN CUSTOMER ===\n";
    $loginRes = http_req($baseUrl . 'login.php', 'POST', [
        'email' => $email,
        'password' => $password,
        'redirect' => 'cart.php'
    ], $cookieFile);
    assert_test($loginRes['code'] === 200, "Customer logged in (HTTP 200)");

    echo "\n=== 2. ADD FIRST BLOUSE (Panel Cut, ₹850) ===\n";
    $addRes = http_req($baseUrl . 'customize-blouse.php?style=panel-cut', 'POST', [
        'action' => 'add_to_cart',
        'style' => 'panel-cut',
        'base_price' => '850',
        'total_price' => '850'
    ], $cookieFile, false);
    assert_test(in_array($addRes['code'], [301, 302], true), "Blouse 1 added to cart");

    // Verify cart.php shows 1 item
    $cartRes1 = http_req($baseUrl . 'cart.php', 'GET', [], $cookieFile);
    assert_test(strpos($cartRes1['body'], 'Panel Cut Blouse') !== false, "Cart contains Panel Cut Blouse");
    assert_test(strpos($cartRes1['body'], 'No garments yet') === false, "Cart is NOT empty before payment");

    echo "\n=== 3. PROCEED TO CHECKOUT & COMPLETE ORDER 1 ===\n";
    // Step 1: Save measurement
    $step1 = http_req($baseUrl . 'checkout.php?step=measurement', 'POST', [
        'action' => 'save_measurements',
        'measurement_method' => 'reference_blouse',
        'requested_ready_date' => date('Y-m-d', strtotime('+14 days')),
        'notes' => 'First order'
    ], $cookieFile, false);
    assert_test(in_array($step1['code'], [301, 302], true), "Step 1 saved -> redirecting to review");

    // Step 2: Confirm review
    $step2 = http_req($baseUrl . 'checkout.php?step=review', 'POST', [
        'action' => 'confirm_review',
        'declaration' => '1',
        'advance_amount' => '425'
    ], $cookieFile, false);
    assert_test(in_array($step2['code'], [301, 302], true), "Step 2 review confirmed -> redirecting to payment");

    // Step 3: Simulate payment success
    $step3 = http_req($baseUrl . 'checkout.php?step=payment', 'POST', [
        'action' => 'simulate_success'
    ], $cookieFile, false);
    assert_test(in_array($step3['code'], [301, 302], true), "Step 3 payment submitted -> redirecting to confirmation");
    assert_test(strpos($step3['redirect_url'], 'step=confirmation') !== false, "Redirected to step=confirmation");

    preg_match('/ref=([^&]+)/', $step3['redirect_url'], $matches);
    $order1Ref = urldecode($matches[1] ?? '');
    echo "  Order 1 Reference: $order1Ref\n";
    assert_test(!empty($order1Ref), "Order 1 Reference generated: $order1Ref");

    // Load confirmation page
    $confRes1 = http_req($baseUrl . 'checkout.php?step=confirmation&ref=' . urlencode($order1Ref), 'GET', [], $cookieFile);
    assert_test($confRes1['code'] === 200, "Confirmation page loaded for Order 1");
    assert_test(strpos($confRes1['body'], 'Payment Successful') !== false, "Confirmation shows 'Payment Successful'");

    echo "\n=== 4. CHECK CART.PHP AFTER ORDER 1 CONFIRMATION ===\n";
    $cartAfterOrder1 = http_req($baseUrl . 'cart.php', 'GET', [], $cookieFile);
    $hasPanelCutInCart = (strpos($cartAfterOrder1['body'], 'Panel Cut Blouse') !== false);
    $hasEmptyMessage = (strpos($cartAfterOrder1['body'], 'No garments yet') !== false);
    echo "  Panel Cut Blouse still in cart? " . ($hasPanelCutInCart ? 'YES (BUG!)' : 'NO (CLEARED)') . "\n";
    echo "  Empty order message shown? " . ($hasEmptyMessage ? 'YES' : 'NO') . "\n";
    assert_test(!$hasPanelCutInCart, "Purchased Panel Cut Blouse MUST NOT be in cart after payment");
    assert_test($hasEmptyMessage, "cart.php MUST show 'No garments yet' after payment");

    echo "\n=== 5. ADD SECOND BLOUSE (Katori Cut, ₹1150) IN SAME SESSION ===\n";
    $addRes2 = http_req($baseUrl . 'customize-blouse.php?style=katori-cut', 'POST', [
        'action' => 'add_to_cart',
        'style' => 'katori-cut',
        'base_price' => '900',
        'total_price' => '1150',
        'embroidery' => 'machine'
    ], $cookieFile, false);
    assert_test(in_array($addRes2['code'], [301, 302], true), "Blouse 2 added to cart");

    // Verify cart.php shows Blouse 2
    $cartRes2 = http_req($baseUrl . 'cart.php', 'GET', [], $cookieFile);
    assert_test(strpos($cartRes2['body'], 'Katori Cut Blouse') !== false, "Cart contains Katori Cut Blouse");

    echo "\n=== 6. CHECKOUT SECOND ORDER IN SAME SESSION ===\n";
    // Enter checkout.php
    $checkoutEntry = http_req($baseUrl . 'checkout.php', 'GET', [], $cookieFile);
    assert_test(strpos($checkoutEntry['body'], 'Measurements & Reference') !== false, "Enforces Step 1 for new cart");

    // Step 1: Save measurement for second order
    $step1_ord2 = http_req($baseUrl . 'checkout.php?step=measurement', 'POST', [
        'action' => 'save_measurements',
        'measurement_method' => 'visit_shop',
        'requested_ready_date' => date('Y-m-d', strtotime('+16 days')),
        'notes' => 'Second order'
    ], $cookieFile, false);
    assert_test(in_array($step1_ord2['code'], [301, 302], true), "Order 2 Step 1 saved -> redirecting to review");

    // Step 2: Confirm review for second order
    $step2_ord2 = http_req($baseUrl . 'checkout.php?step=review', 'POST', [
        'action' => 'confirm_review',
        'declaration' => '1',
        'advance_amount' => '575'
    ], $cookieFile, false);
    assert_test(in_array($step2_ord2['code'], [301, 302], true), "Order 2 Step 2 review confirmed -> redirecting to payment");

    // Step 3: Simulate payment success for second order
    $step3_ord2 = http_req($baseUrl . 'checkout.php?step=payment', 'POST', [
        'action' => 'simulate_success'
    ], $cookieFile, false);
    assert_test(in_array($step3_ord2['code'], [301, 302], true), "Order 2 Step 3 payment submitted");

    preg_match('/ref=([^&]+)/', $step3_ord2['redirect_url'], $matches2);
    $order2Ref = urldecode($matches2[1] ?? '');
    echo "  Order 2 Reference from payment redirect: $order2Ref\n";
    echo "  Order 1 Reference was: $order1Ref\n";

    // CRITICAL CHECK: Did it generate a new order reference or redirect to order 1?
    assert_test($order2Ref !== $order1Ref, "CRITICAL: Order 2 must have a distinct reference! Got: $order2Ref vs $order1Ref");

    // Step 7: Check cart.php after Order 2 payment
    echo "\n=== 7. CHECK CART.PHP AFTER ORDER 2 CONFIRMATION ===\n";
    $cartAfterOrder2 = http_req($baseUrl . 'cart.php', 'GET', [], $cookieFile);
    $hasKatoriInCart = (strpos($cartAfterOrder2['body'], 'Katori Cut Blouse') !== false);
    $hasEmptyMessage2 = (strpos($cartAfterOrder2['body'], 'No garments yet') !== false);
    echo "  Katori Cut Blouse still in cart? " . ($hasKatoriInCart ? 'YES (BUG!)' : 'NO (CLEARED)') . "\n";
    echo "  Empty order message shown? " . ($hasEmptyMessage2 ? 'YES' : 'NO') . "\n";
    assert_test(!$hasKatoriInCart, "Purchased Katori Cut Blouse MUST NOT be in cart after payment");
    assert_test($hasEmptyMessage2, "cart.php MUST show 'No garments yet' after Order 2 payment");

    // Step 8: Test Payment Failure -> Cart MUST NOT be cleared
    echo "\n=== 8. TEST PAYMENT FAILURE PRESERVES CART ===\n";
    $addRes3 = http_req($baseUrl . 'customize-blouse.php?style=princess-cut', 'POST', [
        'action' => 'add_to_cart',
        'style' => 'princess-cut',
        'base_price' => '800',
        'total_price' => '800'
    ], $cookieFile, false);
    assert_test(in_array($addRes3['code'], [301, 302], true), "Blouse 3 added to cart");

    // Complete Step 1 & Step 2
    http_req($baseUrl . 'checkout.php?step=measurement', 'POST', [
        'action' => 'save_measurements',
        'measurement_method' => 'reference_blouse',
        'requested_ready_date' => date('Y-m-d', strtotime('+14 days'))
    ], $cookieFile, false);

    http_req($baseUrl . 'checkout.php?step=review', 'POST', [
        'action' => 'confirm_review',
        'declaration' => '1',
        'advance_amount' => '400'
    ], $cookieFile, false);

    // Simulate payment failure
    $failPayRes = http_req($baseUrl . 'checkout.php?step=payment', 'POST', [
        'action' => 'simulate_failed'
    ], $cookieFile, false);
    assert_test(in_array($failPayRes['code'], [301, 302], true), "Payment failure triggers redirect");
    assert_test(strpos($failPayRes['redirect_url'], 'failed=1') !== false, "Redirected to payment page with failed=1");

    // Verify cart is STILL populated!
    $cartAfterFailure = http_req($baseUrl . 'cart.php', 'GET', [], $cookieFile);
    assert_test(strpos($cartAfterFailure['body'], 'Princess Cut Blouse') !== false, "CRITICAL: Cart items PRESERVED after payment failure");

    // Verify orders.php contains both completed orders (Order 1 and Order 2)
    echo "\n=== 9. VERIFY ORDERS.PHP CONTAINS BOTH COMPLETED ORDERS ===\n";
    $ordersPage = http_req($baseUrl . 'orders.php', 'GET', [], $cookieFile);
    assert_test(strpos($ordersPage['body'], $order1Ref) !== false, "Order 1 ({$order1Ref}) present in orders.php");
    assert_test(strpos($ordersPage['body'], $order2Ref) !== false, "Order 2 ({$order2Ref}) present in orders.php");

    echo "\n======================================================================\n";
    echo "ALL POST-PAYMENT CART CLEANUP & ISOLATION TESTS PASSED!\n";
    echo "======================================================================\n";

} finally {
    if (file_exists($cookieFile)) {
        @unlink($cookieFile);
    }
}
