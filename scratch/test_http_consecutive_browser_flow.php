<?php
/**
 * Real HTTP Integration Test: Consecutive Luxe Orders in Browser Environment
 * 
 * Simulates a real browser session making HTTP requests to Apache:
 * 1. Logs in as a customer.
 * 2. Creates & completes Order 1.
 * 3. Navigates directly to luxe-wedding.php in the same session.
 * 4. Asserts that the date field is completely unlocked and clean.
 * 5. Creates & completes Order 2.
 * 6. Asserts both orders exist independently in orders.php.
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

echo "============================================================\n";
echo "HTTP BROWSER SIMULATION: CONSECUTIVE ORDERS IN SAME SESSION\n";
echo "============================================================\n\n";

$cookieFile = __DIR__ . '/cookie_' . time() . '.txt';

function http_request(string $url, string $method = 'GET', array $postData = [], string $cookieFile = ''): array {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
    curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
    }

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $effectiveUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    curl_close($ch);

    return [
        'code' => $httpCode,
        'body' => (string)$response,
        'url' => $effectiveUrl
    ];
}

$baseUrl = 'http://localhost/shagun-ladies-tailor/';

$pdo = get_db_connection();

// 1. Setup isolated customer in DB
$testEmail = 'http_customer_' . time() . '@example.com';
$testPass = 'password123';
$testHash = password_hash($testPass, PASSWORD_BCRYPT);
$testName = 'Browser Test Customer';
$testPhone = '+91 9988776655';

$uStmt = $pdo->prepare("INSERT INTO users (name, email, password_hash, role, phone, created_at) VALUES (:n, :e, :h, 'customer', :p, NOW())");
$uStmt->execute([':n' => $testName, ':e' => $testEmail, ':h' => $testHash, ':p' => $testPhone]);
$testUserId = (int) $pdo->lastInsertId();

$orderRefA = '';
$orderRefB = '';

try {
    // 2. Perform real login via POST to login.php
    $loginRes = http_request($baseUrl . 'login.php', 'POST', [
        'email' => $testEmail,
        'password' => $testPass,
        'redirect' => 'luxe-stitching.php'
    ], $cookieFile);

    assert_http($loginRes['code'] === 200, "Login HTTP request succeeded (Status: {$loginRes['code']})");

    // 3. Start Order A: luxe-wedding.php
    $orderADate = date('Y-m-d', strtotime('+8 days'));
    $step1Res = http_request($baseUrl . 'people.php', 'POST', [
        'workflow' => 'wedding',
        'requested_ready_date' => $orderADate,
        'people_count' => '1',
        'wedding_notes' => 'HTTP Order A Bridal'
    ], $cookieFile);
    assert_http($step1Res['code'] === 200, "Step 1 POST to people.php succeeded");

    // Save people
    $step2Res = http_request($baseUrl . 'people.php', 'POST', [
        'save_people' => '1',
        'person_name' => ['Aarthi'],
        'person_role' => ['Bride'],
        'person_garments' => ['["Blouse"]']
    ], $cookieFile);
    assert_http($step2Res['code'] === 200, "Step 2 POST to people.php succeeded");

    // Configure garment in workspace
    $saveGarmentRes = http_request($baseUrl . 'customize-blouse.php?luxe=1&person=0&garment_idx=0&style=u-cut', 'POST', [
        'action' => 'save_customization',
        'luxe' => '1',
        'person' => '0',
        'garment_idx' => '0',
        'style' => 'u-cut',
        'neckline' => 'round',
        'front_neckline' => 'round',
        'back_neckline' => 'deep-round',
        'sleeve_type' => 'regular',
        'opening_side' => 'back',
        'padding' => 'yes',
        'lining' => 'cotton',
        'piping' => 'contrast',
        'tassels' => 'latkan'
    ], $cookieFile);
    assert_http($saveGarmentRes['code'] === 200, "Customization saved for Garment 0");

    // Save work type (no_work) to mark garment completed
    $workRes = http_request($baseUrl . 'luxe-work.php?person=0&garment_idx=0', 'POST', [
        'work_type' => 'no_work'
    ], $cookieFile);
    assert_http($workRes['code'] === 200, "Work saved as no_work (garment status: completed)");

    // Save measurements
    $measRes = http_request($baseUrl . 'measurements.php', 'POST', [
        'action' => 'save_measurements',
        'measurements' => [0 => 'reference_blouse'],
        'garment_measurements' => [0 => [0 => 'reference_blouse']]
    ], $cookieFile);
    assert_http($measRes['code'] === 200, "Measurements saved for Garment 0");

    // Review & set advance payment
    $revRes = http_request($baseUrl . 'review-payment.php', 'POST', [
        'advance_amount' => '600',
        'declaration' => '1'
    ], $cookieFile);
    assert_http($revRes['code'] === 200, "Advance payment recorded");

    // Complete payment in payment.php
    $payRes = http_request($baseUrl . 'payment.php', 'POST', [
        'action' => 'simulate_success',
        'payment_method' => 'upi',
        'payment_method_label' => 'UPI / QR Code'
    ], $cookieFile);
    assert_http($payRes['code'] === 200, "Payment simulated successfully for Order A");
    assert_http(strpos($payRes['body'], 'Payment Successful') !== false, "Payment success screen rendered for Order A");

    // Extract Order Reference from payment success body
    if (preg_match('/id="order-ref-text">([^<]+)<\/strong>/', $payRes['body'], $m)) {
        $orderRefA = trim($m[1]);
    }
    assert_http(!empty($orderRefA), "Captured Order A Reference: $orderRefA");

    // =========================================================
    // CRITICAL BUG VERIFICATION:
    // Without logging out, visit luxe-wedding.php for Order B!
    // =========================================================
    echo "\n--- Real Browser Request: Visiting luxe-wedding.php for Order B ---\n";
    $luxeWeddingRes = http_request($baseUrl . 'luxe-wedding.php', 'GET', [], $cookieFile);
    assert_http($luxeWeddingRes['code'] === 200, "Navigated to luxe-wedding.php in same session (HTTP 200)");

    $body = $luxeWeddingRes['body'];

    // 1. Verify "Order Confirmed: Your ready date is locked" is NOT present
    assert_http(
        strpos($body, 'Order Confirmed: Your ready date is locked') === false,
        "PASS: 'Order Confirmed: Your ready date is locked' message is ABSENT"
    );

    // 2. Locate the date input element
    preg_match('/<input[^>]+id="requested-ready-date"[^>]*>/i', $body, $inputMatches);
    $dateInputHtml = $inputMatches[0] ?? '';
    assert_http(!empty($dateInputHtml), "Found requested-ready-date input: $dateInputHtml");

    // 3. Verify date input is UNLOCKED
    assert_http(strpos($dateInputHtml, 'disabled') === false, "PASS: Date input has NO 'disabled' attribute");
    assert_http(strpos($dateInputHtml, 'readonly') === false, "PASS: Date input has NO 'readonly' attribute");
    assert_http(strpos($dateInputHtml, 'required') !== false, "PASS: Date input has 'required' attribute");
    assert_http(strpos($dateInputHtml, 'value=""') !== false, "PASS: Date input value is EMPTY");

    // =========================================================
    // SUBMIT & COMPLETE ORDER B WITH NEW DATE
    // =========================================================
    echo "\n--- Submitting Order B with New Date ---\n";
    $orderBDate = date('Y-m-d', strtotime('+22 days'));

    $step1BRes = http_request($baseUrl . 'people.php', 'POST', [
        'workflow' => 'wedding',
        'requested_ready_date' => $orderBDate,
        'people_count' => '1',
        'wedding_notes' => 'HTTP Order B Sangeet'
    ], $cookieFile);
    assert_http($step1BRes['code'] === 200, "Order B Step 1 POST to people.php succeeded");

    // Save people for Order B
    $step2BRes = http_request($baseUrl . 'people.php', 'POST', [
        'save_people' => '1',
        'person_name' => ['Sneha'],
        'person_role' => ['Sister'],
        'person_garments' => ['["Blouse"]']
    ], $cookieFile);
    assert_http($step2BRes['code'] === 200, "Order B Step 2 POST to people.php succeeded");

    // Customize
    $saveGarmentBRes = http_request($baseUrl . 'customize-blouse.php?luxe=1&person=0&garment_idx=0&style=princess-cut', 'POST', [
        'action' => 'save_customization',
        'luxe' => '1',
        'person' => '0',
        'garment_idx' => '0',
        'style' => 'princess-cut',
        'neckline' => 'v-neck',
        'front_neckline' => 'v-neck',
        'back_neckline' => 'square',
        'sleeve_type' => 'elbow',
        'opening_side' => 'front',
        'padding' => 'no',
        'lining' => 'cotton',
        'piping' => 'self',
        'tassels' => 'none'
    ], $cookieFile);
    assert_http($saveGarmentBRes['code'] === 200, "Order B Customization saved");

    // Save work type for Order B
    $workBRes = http_request($baseUrl . 'luxe-work.php?person=0&garment_idx=0', 'POST', [
        'work_type' => 'no_work'
    ], $cookieFile);
    assert_http($workBRes['code'] === 200, "Order B Work saved as no_work (garment status: completed)");

    // Measurements
    $measBRes = http_request($baseUrl . 'measurements.php', 'POST', [
        'action' => 'save_measurements',
        'measurements' => [0 => 'visit_shop'],
        'garment_measurements' => [0 => [0 => 'visit_shop']]
    ], $cookieFile);
    assert_http($measBRes['code'] === 200, "Order B Measurements saved");

    // Advance payment
    $revBRes = http_request($baseUrl . 'review-payment.php', 'POST', [
        'advance_amount' => '500',
        'declaration' => '1'
    ], $cookieFile);
    assert_http($revBRes['code'] === 200, "Order B Advance payment recorded");

    // Complete payment
    $payBRes = http_request($baseUrl . 'payment.php', 'POST', [
        'action' => 'simulate_success',
        'payment_method' => 'card',
        'payment_method_label' => 'Credit / Debit Card'
    ], $cookieFile);
    assert_http($payBRes['code'] === 200, "Payment simulated successfully for Order B");

    if (preg_match('/id="order-ref-text">([^<]+)<\/strong>/', $payBRes['body'], $mB)) {
        $orderRefB = trim($mB[1]);
    }
    assert_http(!empty($orderRefB), "Captured Order B Reference: $orderRefB");
    assert_http($orderRefA !== $orderRefB, "Order A Ref ($orderRefA) !== Order B Ref ($orderRefB)");

    // 4. Verify in orders.php
    $ordersRes = http_request($baseUrl . 'orders.php', 'GET', [], $cookieFile);
    assert_http($ordersRes['code'] === 200, "orders.php loaded successfully");
    assert_http(strpos($ordersRes['body'], $orderRefA) !== false, "orders.php displays Order A ($orderRefA)");
    assert_http(strpos($ordersRes['body'], $orderRefB) !== false, "orders.php displays Order B ($orderRefB)");

    // Verify DB records
    $chkA = $pdo->prepare("SELECT requested_ready_date FROM orders WHERE order_ref = :ref");
    $chkA->execute([':ref' => $orderRefA]);
    $dbDateA = $chkA->fetchColumn();

    $chkB = $pdo->prepare("SELECT requested_ready_date FROM orders WHERE order_ref = :ref");
    $chkB->execute([':ref' => $orderRefB]);
    $dbDateB = $chkB->fetchColumn();

    assert_http($dbDateA === $orderADate, "Order A in DB has date: $orderADate");
    assert_http($dbDateB === $orderBDate, "Order B in DB has date: $orderBDate");
    assert_http($dbDateA !== $dbDateB, "Both dates are completely independent ($dbDateA vs $dbDateB)");

    echo "\n============================================================\n";
    echo "REAL HTTP BROWSER FLOW 100% VERIFIED!\n";
    echo "BUG RESOLVED: Consecutive orders in same login session work cleanly.\n";
    echo "============================================================\n";

} finally {
    if (file_exists($cookieFile)) {
        @unlink($cookieFile);
    }
    // Clean up DB records
    foreach ([$orderRefA, $orderRefB] as $ref) {
        if (!empty($ref)) {
            $pdo->prepare("DELETE FROM order_payments WHERE order_id IN (SELECT id FROM orders WHERE order_ref = :r)")->execute([':r' => $ref]);
            $pdo->prepare("DELETE FROM order_garment_customizations WHERE order_garment_id IN (SELECT id FROM order_garments WHERE order_person_id IN (SELECT id FROM order_people WHERE order_id IN (SELECT id FROM orders WHERE order_ref = :r)))")->execute([':r' => $ref]);
            $pdo->prepare("DELETE FROM order_garments WHERE order_person_id IN (SELECT id FROM order_people WHERE order_id IN (SELECT id FROM orders WHERE order_ref = :r))")->execute([':r' => $ref]);
            $pdo->prepare("DELETE FROM order_people WHERE order_id IN (SELECT id FROM orders WHERE order_ref = :r)")->execute([':r' => $ref]);
            $pdo->prepare("DELETE FROM order_status_history WHERE order_id IN (SELECT id FROM orders WHERE order_ref = :r)")->execute([':r' => $ref]);
            $pdo->prepare("DELETE FROM order_date_history WHERE order_id IN (SELECT id FROM orders WHERE order_ref = :r)")->execute([':r' => $ref]);
            $pdo->prepare("DELETE FROM orders WHERE order_ref = :r")->execute([':r' => $ref]);
        }
    }
    if ($testUserId > 0) {
        $pdo->prepare("DELETE FROM users WHERE id = :uid")->execute([':uid' => $testUserId]);
    }
}
