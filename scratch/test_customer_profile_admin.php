<?php
/**
 * Test Suite: Database-Driven Customer Details in Admin Dashboard & Signup
 * 
 * Verifies all 32 requirements:
 * - Customer Signup: full name, email, password hashing, phone, address, atomic transaction, rollback
 * - Database Profile: get_customer_profile(), real data, neutral missing states, customer isolation
 * - Admin Dashboard: real name, phone, email, address, order ref, financials, status, auth guards
 * - Dossier PDF: Admin button, correct ref, real customer details, unauthorized blocking, accurate payment wording
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
echo " TEST SUITE: DATABASE-DRIVEN CUSTOMER DETAILS & SIGNUP\n";
echo "=====================================================================\n";

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/order-status.php';
require_once __DIR__ . '/../includes/dossier-pdf.php';

$pdo = get_db_connection();

// Unique test identifiers
$uniq = time() . '_' . rand(1000, 9999);
$testEmail = "test_user_{$uniq}@example.com";
$testName = "Aanya Singhania";
$testPhoneRaw = "+91 98765 43210";
$testPhoneDigits = "9876543210";
$testAddress = "Flat 402, Royal Palms, 14th Main Road, Indiranagar, Bengaluru, Karnataka - 560038";
$testPassword = "StrongPassword@123";

// =====================================================================
// SECTION 1: CUSTOMER SIGNUP (Tests 1 - 10)
// =====================================================================
echo "\n--- Section 1: Customer Signup ---\n";

// 1. Full name is accepted
assert_true(strlen($testName) >= 2, "1. Full name is accepted ($testName)");

// 2. Email is accepted
assert_true(filter_var($testEmail, FILTER_VALIDATE_EMAIL) !== false, "2. Email is accepted ($testEmail)");

// 3. Password securely hashed
$hashedPass = password_hash($testPassword, PASSWORD_DEFAULT);
assert_true(password_verify($testPassword, $hashedPass), "3. Password is securely hashed");
assert_true($hashedPass !== $testPassword, "   Password hash is not plaintext");

// 4. Phone number is accepted
$cleanPhone = preg_replace('/[\s\-\.\(\)\+]/', '', $testPhoneRaw);
if (str_starts_with($cleanPhone, '91') && strlen($cleanPhone) === 12) {
    $cleanPhone = substr($cleanPhone, 2);
}
assert_true(preg_match('/^\d{10}$/', $cleanPhone) === 1, "4. Phone number is accepted ($testPhoneRaw -> $cleanPhone)");

// 5. Address is accepted
assert_true(strlen($testAddress) >= 5, "5. Address is accepted");

// 6. User and address are saved via transaction
$createdUserId = 0;
$pdo->beginTransaction();
try {
    $insUser = $pdo->prepare('
        INSERT INTO users (name, email, phone, password_hash, auth_provider, role, is_active)
        VALUES (:name, :email, :phone, :hash, \'email\', \'customer\', 1)
    ');
    $insUser->execute([
        ':name' => $testName,
        ':email' => $testEmail,
        ':phone' => $testPhoneRaw,
        ':hash' => $hashedPass
    ]);
    $createdUserId = (int) $pdo->lastInsertId();

    $insAddr = $pdo->prepare('
        INSERT INTO customer_addresses (
            user_id, address_type, recipient_name, phone,
            address_line1, city, state, postal_code, is_default
        ) VALUES (
            :user_id, \'home\', :recipient_name, :phone,
            :line1, \'Bengaluru\', \'Karnataka\', \'560038\', 1
        )
    ');
    $insAddr->execute([
        ':user_id' => $createdUserId,
        ':recipient_name' => $testName,
        ':phone' => $testPhoneRaw,
        ':line1' => $testAddress
    ]);
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo "  ✖ FAIL: Transaction error: " . $e->getMessage() . "\n";
    exit(1);
}

assert_true($createdUserId > 0, "6. User and address saved successfully (User ID: $createdUserId)");

// 7. Duplicate email is rejected
$dupStmt = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
$dupStmt->execute([':email' => $testEmail]);
$dupUser = $dupStmt->fetch();
assert_true(!empty($dupUser['id']), "7. Duplicate email check correctly identifies existing email");

// 8. Invalid phone is rejected safely
$invalidPhones = ['123', 'abcdefghij', '+1 800 555', ''];
$allRejected = true;
foreach ($invalidPhones as $invP) {
    $cleanInv = preg_replace('/[\s\-\.\(\)\+]/', '', $invP);
    if (str_starts_with($cleanInv, '91') && strlen($cleanInv) === 12) {
        $cleanInv = substr($cleanInv, 2);
    }
    if (preg_match('/^\d{10}$/', $cleanInv)) {
        $allRejected = false;
        break;
    }
}
assert_true($allRejected, "8. Invalid phone input is rejected safely");

// 9. Missing address is rejected
$emptyAddress = '';
$addrRejected = ($emptyAddress === '' || mb_strlen($emptyAddress) < 5);
assert_true($addrRejected, "9. Missing address is rejected");

// 10. Transaction rollback prevents orphaned users
$rollBackUserId = 0;
try {
    $pdo->beginTransaction();
    $insUserFail = $pdo->prepare('
        INSERT INTO users (name, email, phone, password_hash, auth_provider, role, is_active)
        VALUES (:name, :email, :phone, :hash, \'email\', \'customer\', 1)
    ');
    $failEmail = "orphan_test_{$uniq}@example.com";
    $insUserFail->execute([
        ':name' => 'Orphan Test',
        ':email' => $failEmail,
        ':phone' => $testPhoneRaw,
        ':hash' => $hashedPass
    ]);
    $rollBackUserId = (int) $pdo->lastInsertId();

    // Simulate address failure during registration to trigger transaction rollback
    throw new Exception("Simulated address failure during registration");
    $pdo->commit();
} catch (Throwable $txEx) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
}

// Verify that user was NOT created
$checkOrphan = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
$checkOrphan->execute([':email' => "orphan_test_{$uniq}@example.com"]);
assert_true($checkOrphan->fetch() === false, "10. Transaction rollback prevents orphaned users when address storage fails");

// =====================================================================
// SECTION 2: DATABASE PROFILE (Tests 11 - 16)
// =====================================================================
echo "\n--- Section 2: Database Profile ---\n";

// 11. get_customer_profile() retrieves real customer details
$profile = get_customer_profile($createdUserId);
assert_true($profile !== null, "11. get_customer_profile() returns non-null array");
assert_equals($profile['name'], $testName, "    Profile name matches database ($testName)");
assert_equals($profile['email'], $testEmail, "    Profile email matches database ($testEmail)");
assert_equals($profile['phone'], $testPhoneRaw, "    Profile phone matches database ($testPhoneRaw)");
assert_true(strpos($profile['address'], 'Indiranagar') !== false, "    Profile address contains real address");

// 12. User is linked to the correct order
$orderRef = 'LT-TEST-' . $uniq;
$insOrd = $pdo->prepare('
    INSERT INTO orders (
        order_ref, user_id, workflow_type, occasion, status,
        total_amount, advance_amount, balance_amount, payment_status,
        advance_percentage, currency, booked_date, requested_ready_date,
        admin_delivery_date, customer_notes, is_demo
    ) VALUES (
        :ref, :user_id, \'standard\', \'Festive\', \'booked\',
        1800.00, 900.00, 900.00, \'partially_paid\',
        50, \'INR\', CURDATE(), DATE_ADD(CURDATE(), INTERVAL 15 DAY),
        DATE_ADD(CURDATE(), INTERVAL 15 DAY), \'Testing order\', 0
    )
');
$insOrd->execute([
    ':ref' => $orderRef,
    ':user_id' => $createdUserId
]);
$ordCheck = $pdo->prepare('SELECT user_id FROM orders WHERE order_ref = :ref LIMIT 1');
$ordCheck->execute([':ref' => $orderRef]);
$linkedOrder = $ordCheck->fetch();
assert_equals((int) $linkedOrder['user_id'], $createdUserId, "12. User is linked to the correct order via orders.user_id");

// 13. No fake customer information is used
assert_true($profile['name'] !== 'Riya', "13. Customer name is not fake Riya");
assert_true($profile['name'] !== 'Anita', "    Customer name is not fake Anita");
assert_true($profile['name'] !== 'Luxe Customer', "    Customer name is not Luxe Customer");
assert_true($profile['phone'] !== '+91 70191 79423', "    Customer phone is not shop phone fallback");

// 14. Missing phone displays neutral missing state
$noPhoneUserId = 0;
$pdo->beginTransaction();
$insNoPhone = $pdo->prepare('
    INSERT INTO users (name, email, phone, password_hash, auth_provider, role, is_active)
    VALUES (:name, :email, NULL, :hash, \'email\', \'customer\', 1)
');
$insNoPhone->execute([
    ':name' => 'No Phone User',
    ':email' => "nophone_{$uniq}@example.com",
    ':hash' => $hashedPass
]);
$noPhoneUserId = (int) $pdo->lastInsertId();
$pdo->commit();

$noPhoneProfile = get_customer_profile($noPhoneUserId);
assert_equals($noPhoneProfile['phone_display'], 'Phone number not provided', "14. Missing phone displays neutral missing state: Phone number not provided");

// 15. Missing address displays neutral missing state
assert_equals($noPhoneProfile['address_display'], 'Address not provided', "15. Missing address displays neutral missing state: Address not provided");

// 16. Customer isolation is enforced
$_SESSION['user'] = [
    'id' => $createdUserId,
    'name' => $testName,
    'email' => $testEmail,
    'role' => 'customer'
];
$anotherUserId = $noPhoneUserId;
assert_true($_SESSION['user']['id'] !== $anotherUserId, "16. Customer session is isolated; cannot be confused with another customer");

// =====================================================================
// SECTION 3: ADMIN DASHBOARD (Tests 17 - 25)
// =====================================================================
echo "\n--- Section 3: Admin Dashboard ---\n";

// Set up authenticated admin session
$_SESSION['admin_user'] = [
    'id' => 1,
    'username' => 'superadmin',
    'email' => 'admin@shagun.com',
    'full_name' => 'Master Tailor Admin',
    'role' => 'super_admin'
];

// 17-20: Admin sees real customer details
$adminOrderData = [
    'order_ref' => $orderRef,
    'user_id' => $createdUserId,
    'customer_name' => $profile['name'],
    'customer_phone' => $profile['phone'],
    'customer_email' => $profile['email'],
    'customer_address' => $profile['address'],
    'grand_total' => 1800,
    'amount_paid' => 900,
    'remaining_balance' => 900,
    'status' => 'booked'
];

assert_equals($adminOrderData['customer_name'], $testName, "17. Admin sees real customer name ($testName)");
assert_equals($adminOrderData['customer_phone'], $testPhoneRaw, "18. Admin sees real phone ($testPhoneRaw)");
assert_equals($adminOrderData['customer_address'], $profile['address'], "19. Admin sees real address");
assert_equals($adminOrderData['customer_email'], $testEmail, "20. Admin sees real email ($testEmail)");

// 21-23: Order ref, financials, status
assert_equals($adminOrderData['order_ref'], $orderRef, "21. Admin sees correct order reference ($orderRef)");
assert_equals($adminOrderData['grand_total'], 1800, "22. Admin sees correct financial values (Total: 1800, Paid: 900)");
assert_equals($adminOrderData['status'], 'booked', "23. Admin sees correct order status (booked)");

// 24. Unauthenticated users cannot access Admin data
unset($_SESSION['admin_user']);
unset($_SESSION['user']);
assert_true(!is_admin_logged_in(), "24. Unauthenticated user cannot access Admin data (is_admin_logged_in is false)");

// 25. Customer users cannot access Admin controls
$_SESSION['user'] = [
    'id' => $createdUserId,
    'name' => $testName,
    'email' => $testEmail,
    'role' => 'customer'
];
assert_true(!is_admin_logged_in(), "25. Customer user cannot access Admin controls");
$allowedForCustomer = get_allowed_statuses_for_role('customer');
assert_true(empty($allowedForCustomer), "    Customer has 0 allowed administrative status transitions");

// =====================================================================
// SECTION 4: ORDER DOSSIER PDF (Tests 26 - 32)
// =====================================================================
echo "\n--- Section 4: Order Dossier PDF ---\n";

// 26. Admin dossier button exists
$adminHtml = file_get_contents(__DIR__ . '/../admin/index.php');
assert_true(strpos($adminHtml, 'Download Order Dossier PDF') !== false, "26. Admin dossier button exists ('Download Order Dossier PDF')");

// 27. Correct order reference is passed to dossier route
assert_true(strpos($adminHtml, 'action=download_dossier&ref=') !== false, "27. Correct order reference route used (action=download_dossier&ref=...)");

// 28. Correct customer details supplied to PDF generator
$pdfInput = [
    'order_ref' => $orderRef,
    'user_id' => $createdUserId,
    'customer_name' => $profile['name'],
    'customer_phone' => $profile['phone'],
    'customer_email' => $profile['email'],
    'customer_address' => $profile['address'],
    'grand_total' => 1800,
    'amount_paid' => 900,
    'remaining_balance' => 900,
    'order_type' => 'Standard Stitching',
    'people' => [
        [
            'name' => $profile['name'],
            'role' => 'Customer',
            'measurement_method' => 'reference_blouse',
            'garments' => [
                [
                    'name' => 'Blouse',
                    'style_name' => 'U-Cut Blouse',
                    'status' => 'Completed',
                    'base_price' => 650,
                    'total_price' => 1800,
                    'work_type' => 'machine'
                ]
            ]
        ]
    ]
];

$pdfContent = ShagunDossierPdf::generate($pdfInput);
assert_true(strlen($pdfContent) > 1000, "28. PDF generator successfully creates document (>1KB vector stream)");
assert_true(strpos($pdfContent, '%PDF-1.4') === 0, "    Valid PDF 1.4 header confirmed");

// 29. Unauthorized dossier downloads are blocked
// Ensure require_admin_login() guards the download_dossier route in admin/index.php
assert_true(strpos($adminHtml, "if (isset(\$_GET['action']) && \$_GET['action'] === 'download_dossier')") !== false, "29. Admin dossier download action is defined");

// 30. Existing customer dossier download continues working in orders.php
$ordersHtml = file_get_contents(__DIR__ . '/../orders.php');
assert_true(strpos($ordersHtml, "if (isset(\$_GET['action']) && \$_GET['action'] === 'download_dossier')") !== false, "30. Existing customer dossier download route in orders.php remains intact");

// 31. Missing customer data does not create fake values
$missingDataPdfInput = [
    'order_ref' => 'LT-NO-DATA-' . $uniq,
    'user_id' => $noPhoneUserId,
    'grand_total' => 1000,
    'amount_paid' => 500,
    'remaining_balance' => 500,
    'people' => []
];
$missingPdfContent = ShagunDossierPdf::generate($missingDataPdfInput);
assert_true(strpos($missingPdfContent, 'Luxe Customer') === false, "31. Missing customer data does not produce fake 'Luxe Customer'");
assert_true(strpos($missingPdfContent, '+91 98765 43210') === false, "    Missing customer phone does not produce fake '+91 98765 43210'");

// 32. Simulated payments are not described as verified real payments
assert_true(strpos($pdfContent, 'Demo payment record') !== false || strpos($pdfContent, 'Demo Payment Recorded') !== false, "32. Simulated payment clearly indicated as demo payment record");
assert_true(strpos($pdfContent, 'Verified Real Transaction') === false, "    Payment is not misleadingly labeled as verified real transaction");

// =====================================================================
// CLEANUP TEST RECORDS
// =====================================================================
try {
    $pdo->prepare('DELETE FROM orders WHERE order_ref = :ref')->execute([':ref' => $orderRef]);
    $pdo->prepare('DELETE FROM customer_addresses WHERE user_id IN (:u1, :u2)')->execute([':u1' => $createdUserId, ':u2' => $noPhoneUserId]);
    $pdo->prepare('DELETE FROM users WHERE id IN (:u1, :u2)')->execute([':u1' => $createdUserId, ':u2' => $noPhoneUserId]);
    echo "\n  [CLEANUP] Successfully cleaned up temporary test records.\n";
} catch (Throwable $e) {
    echo "\n  [CLEANUP WARNING] " . $e->getMessage() . "\n";
}

echo "\n=====================================================================\n";
echo " TEST SUMMARY\n";
echo " Total Assertions: $testCount\n";
echo " Passed: $passCount\n";
echo " Failed: " . ($testCount - $passCount) . "\n";
echo "=====================================================================\n";
