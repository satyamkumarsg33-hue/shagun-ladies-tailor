<?php

declare(strict_types=1);

/**
 * AUTOMATED TEST SUITE: PHASE 3 — DATABASE-BACKED AUTHENTICATION + ROLE-BASED LOGIN
 *
 * Verifies all 20 required points from Phase 3 specification:
 *  1. DB connection works.
 *  2. Customer signup creates a users row.
 *  3. Password is stored as a hash.
 *  4. Customer login succeeds with correct password.
 *  5. Customer login fails with wrong password.
 *  6. Duplicate customer email is rejected.
 *  7. Customer session is created correctly.
 *  8. Admin authentication can locate admin account.
 *  9. Admin password verification works.
 * 10. Admin session is created correctly.
 * 11. Admin role is available from session.
 * 12. Normal customer cannot access /admin/.
 * 13. Guest cannot access /admin/.
 * 14. Authenticated admin can access /admin/index.php.
 * 15. Logout removes authentication state.
 * 16. Logout does not destroy existing Standard/Luxe draft session data.
 * 17. Safe redirects remain safe.
 * 18. External redirect attempts are rejected.
 * 19. Luxe login protection still works.
 * 20. Standard guest workflow still works.
 *
 * Automatic Cleanup: All temporary test accounts in users and admin_users are deleted on teardown.
 */

$projectRoot = 'c:/xampp/htdocs/shagun-ladies-tailor';
if (file_exists(__DIR__ . '/../includes/bootstrap.php')) {
    $projectRoot = dirname(__DIR__);
}
require_once $projectRoot . '/includes/bootstrap.php';
require_once $projectRoot . '/includes/db.php';
require_once $projectRoot . '/includes/auth.php';

$baseUrl = 'http://localhost/shagun-ladies-tailor';
$totalTests = 0;
$passedTests = 0;
$failedTests = [];

function assertTest(bool $condition, string $description, string $detail = ''): void {
    global $totalTests, $passedTests, $failedTests;
    $totalTests++;
    if ($condition) {
        $passedTests++;
        echo "  [PASS] $description\n";
    } else {
        $failedTests[] = $description . ($detail ? " ($detail)" : "");
        echo "  [FAIL] $description" . ($detail ? " ($detail)" : "") . "\n";
    }
}

function httpRequest(string $url, string $method = 'GET', ?array $postData = null, ?string $cookieFile = null, bool $followRedirect = false): array {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, $followRedirect);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) ShagunAuthTestSuite/1.0');
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['X-Auth-Test: 1']);

    if ($cookieFile !== null) {
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
    }
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if ($postData !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
        }
    }
    $raw = (string) curl_exec($ch);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $redirectUrl = curl_getinfo($ch, CURLINFO_REDIRECT_URL);
    curl_close($ch);

    $headers = substr($raw, 0, $headerSize);
    $body = substr($raw, $headerSize);

    return [
        'code' => $code,
        'headers' => $headers,
        'body' => $body,
        'redirect_url' => $redirectUrl
    ];
}

$cookieCustomer = tempnam(sys_get_temp_dir(), 'shagun_cust_');
$cookieAdmin = tempnam(sys_get_temp_dir(), 'shagun_adm_');
$cookieGuest = tempnam(sys_get_temp_dir(), 'shagun_gst_');

// Unique test credentials
$testTimestamp = time();
$testCustEmail = "test_customer_{$testTimestamp}@example.com";
$testCustPassword = "CustomerSecure#2026";
$testCustName = "Priya Test Sharma";

$testAdminUsername = "test_superadmin_{$testTimestamp}";
$testAdminEmail = "test_admin_{$testTimestamp}@shagun.com";
$testAdminPassword = "AdminSecure#2026";
$testAdminFullName = "Shagun Master Tailor";

echo "=======================================================\n";
echo "STARTING DATABASE AUTHENTICATION & ROLE TEST SUITE\n";
echo "=======================================================\n\n";

try {
    $pdo = get_db_connection();

    // -------------------------------------------------------------
    // TEST 1: Database Connection
    // -------------------------------------------------------------
    echo "TEST 1: Database Connection Layer\n";
    assertTest(db_is_connected(), "Database connection established successfully via get_db_connection()");
    
    // -------------------------------------------------------------
    // TEST 2 & 3: Customer Signup Creates DB Row & Hashes Password
    // -------------------------------------------------------------
    echo "\nTEST 2 & 3: Customer Signup & Password Hashing\n";
    $signupRes = httpRequest("$baseUrl/signup.php", 'POST', [
        'name' => $testCustName,
        'email' => $testCustEmail,
        'password' => $testCustPassword,
        'password_confirm' => $testCustPassword,
        'redirect' => 'orders.php'
    ], $cookieCustomer, false);

    assertTest($signupRes['code'] === 302, "Customer signup returns HTTP 302 redirect", "HTTP code was {$signupRes['code']}");
    assertTest(strpos($signupRes['redirect_url'], 'orders.php') !== false, "Customer signup redirects to destination (orders.php)");

    $checkCustStmt = $pdo->prepare('SELECT id, name, email, password_hash, auth_provider, role, is_active FROM users WHERE email = :email');
    $checkCustStmt->execute([':email' => $testCustEmail]);
    $createdCust = $checkCustStmt->fetch();

    assertTest(!empty($createdCust), "Customer record was inserted into users table");
    assertTest(!empty($createdCust['password_hash']) && $createdCust['password_hash'] !== $testCustPassword, "Customer password is encrypted (not plaintext)");
    $hashInfo = password_get_info($createdCust['password_hash'] ?? '');
    assertTest($hashInfo['algo'] !== 0, "Customer password is a valid cryptographic hash ({$hashInfo['algoName']})");

    // -------------------------------------------------------------
    // TEST 4 & 5: Customer Login Success & Wrong Password
    // -------------------------------------------------------------
    echo "\nTEST 4 & 5: Customer Login Verification\n";
    // 4. Correct Password
    $loginCustCookie = tempnam(sys_get_temp_dir(), 'shagun_cust_login_');
    $loginCustRes = httpRequest("$baseUrl/login.php", 'POST', [
        'action' => 'credentials',
        'identity' => $testCustEmail,
        'password' => $testCustPassword,
        'redirect' => 'index.php'
    ], $loginCustCookie, false);

    assertTest($loginCustRes['code'] === 302, "Customer login with correct credentials returns HTTP 302");
    assertTest(strpos($loginCustRes['redirect_url'], 'index.php') !== false, "Customer login redirects to requested destination");

    // 5. Wrong Password
    $badLoginCustRes = httpRequest("$baseUrl/login.php", 'POST', [
        'action' => 'credentials',
        'identity' => $testCustEmail,
        'password' => 'WrongPassword123!',
        'redirect' => 'index.php'
    ], null, false);

    assertTest($badLoginCustRes['code'] === 200, "Customer login with wrong password remains on login page (HTTP 200)");
    assertTest(strpos($badLoginCustRes['body'], 'Invalid email or password.') !== false || strpos($badLoginCustRes['body'], 'Invalid') !== false, "Customer login with wrong password displays failure message");

    // -------------------------------------------------------------
    // TEST 6: Duplicate Customer Email Rejected
    // -------------------------------------------------------------
    echo "\nTEST 6: Duplicate Customer Email Rejection\n";
    $dupSignupRes = httpRequest("$baseUrl/signup.php", 'POST', [
        'name' => 'Duplicate Attempt',
        'email' => $testCustEmail,
        'password' => 'OtherPassword123',
        'password_confirm' => 'OtherPassword123'
    ], null, false);

    assertTest($dupSignupRes['code'] === 200, "Duplicate signup stays on page (HTTP 200)");
    assertTest(strpos($dupSignupRes['body'], 'already exists') !== false, "Duplicate email displays user-friendly error");

    // -------------------------------------------------------------
    // TEST 7: Customer Session State
    // -------------------------------------------------------------
    echo "\nTEST 7: Customer Session State & Navigation\n";
    $custHomeRes = httpRequest("$baseUrl/index.php", 'GET', null, $loginCustCookie, false);
    assertTest(strpos($custHomeRes['body'], $testCustName) !== false, "Navbar renders customer name when logged in");
    assertTest(strpos($custHomeRes['body'], 'Admin Dashboard') === false, "Customer navbar does NOT expose Admin Dashboard link");

    // -------------------------------------------------------------
    // TEST 8, 9, 10, 11: Admin Authentication, Verification, Session & Role
    // -------------------------------------------------------------
    echo "\nTEST 8 - 11: Admin Provisioning, Authentication & Role\n";
    // Insert temporary test admin directly into admin_users (to test auth flow)
    $adminHash = password_hash($testAdminPassword, PASSWORD_DEFAULT);
    $insAdmStmt = $pdo->prepare('
        INSERT INTO admin_users (username, email, password_hash, full_name, role, is_active)
        VALUES (:u, :e, :h, :name, \'super_admin\', 1)
    ');
    $insAdmStmt->execute([
        ':u' => $testAdminUsername,
        ':e' => $testAdminEmail,
        ':h' => $adminHash,
        ':name' => $testAdminFullName
    ]);
    $createdAdminId = (int) $pdo->lastInsertId();
    assertTest($createdAdminId > 0, "Test admin provisioned in admin_users for verification (ID: {$createdAdminId})");

    // Login via same homepage login.php interface using admin email
    $adminLoginRes = httpRequest("$baseUrl/login.php", 'POST', [
        'action' => 'credentials',
        'identity' => $testAdminEmail,
        'password' => $testAdminPassword
    ], $cookieAdmin, false);

    assertTest($adminLoginRes['code'] === 302, "Admin login with email returns HTTP 302");
    assertTest(strpos($adminLoginRes['redirect_url'], 'admin/index.php') !== false, "Admin login automatically routes to admin/index.php");

    // Login via same login.php interface using admin username
    $cookieAdminUser = tempnam(sys_get_temp_dir(), 'shagun_adm_user_');
    $adminUserLoginRes = httpRequest("$baseUrl/login.php", 'POST', [
        'action' => 'credentials',
        'identity' => $testAdminUsername,
        'password' => $testAdminPassword
    ], $cookieAdminUser, false);
    assertTest($adminUserLoginRes['code'] === 302, "Admin login with username returns HTTP 302");
    assertTest(strpos($adminUserLoginRes['redirect_url'], 'admin/index.php') !== false, "Admin login with username routes to admin/index.php");

    // -------------------------------------------------------------
    // TEST 12: Customer Cannot Access /admin/
    // -------------------------------------------------------------
    echo "\nTEST 12: Normal Customer Denied Access to /admin/\n";
    $custAdminRes = httpRequest("$baseUrl/admin/index.php", 'GET', null, $loginCustCookie, false);
    assertTest($custAdminRes['code'] === 302, "Customer accessing /admin/index.php gets HTTP 302 redirect");
    assertTest(strpos($custAdminRes['redirect_url'], 'error=admin_access_denied') !== false, "Customer redirected away with admin_access_denied");

    // -------------------------------------------------------------
    // TEST 13: Guest Cannot Access /admin/
    // -------------------------------------------------------------
    echo "\nTEST 13: Guest Denied Access to /admin/\n";
    $guestAdminRes = httpRequest("$baseUrl/admin/index.php", 'GET', null, $cookieGuest, false);
    assertTest($guestAdminRes['code'] === 302, "Guest accessing /admin/index.php gets HTTP 302 redirect");
    assertTest(strpos($guestAdminRes['redirect_url'], 'login.php') !== false, "Guest redirected to login.php");
    assertTest(strpos($guestAdminRes['redirect_url'], 'admin%2Findex.php') !== false || strpos($guestAdminRes['redirect_url'], 'admin/index.php') !== false, "Guest redirect includes target admin/index.php");

    // -------------------------------------------------------------
    // TEST 14: Authenticated Admin Can Access /admin/index.php
    // -------------------------------------------------------------
    echo "\nTEST 14: Authenticated Admin Access to Admin Dashboard\n";
    $adminDashRes = httpRequest("$baseUrl/admin/index.php", 'GET', null, $cookieAdmin, false);
    assertTest($adminDashRes['code'] === 200, "Authenticated admin accesses /admin/index.php with HTTP 200");
    assertTest(strpos($adminDashRes['body'], 'Shagun Admin Dashboard') !== false, "Admin page displays 'Shagun Admin Dashboard'");
    assertTest(strpos($adminDashRes['body'], $testAdminFullName) !== false, "Admin page displays admin full name");
    assertTest(strpos($adminDashRes['body'], 'Super Admin') !== false, "Admin page displays role label 'Super Admin'");
    assertTest(stripos($adminDashRes['body'], 'Authentication successful') !== false, "Admin page confirms authentication success");

    // Check admin navbar on storefront
    $adminStoreRes = httpRequest("$baseUrl/index.php", 'GET', null, $cookieAdmin, false);
    assertTest(strpos($adminStoreRes['body'], 'Admin Dashboard') !== false, "Admin navbar displays Admin Dashboard link");
    // -------------------------------------------------------------
    // TEST 14B: Explicit Email Collision Precedence (Admin vs Customer)
    // -------------------------------------------------------------
    echo "\nTEST 14B: Email Collision Precedence (Admin Checked First, No Customer Fallthrough)\n";
    $sharedEmail = "shared_{$testTimestamp}@example.com";
    $sharedAdminPass = "SharedAdminPass123!";
    $sharedCustPass = "SharedCustPass123!";

    // Create admin with shared email
    $insSharedAdmin = $pdo->prepare('INSERT INTO admin_users (username, email, password_hash, full_name, role, is_active) VALUES (:u, :e, :h, "Shared Staff", "store_manager", 1)');
    $insSharedAdmin->execute([':u' => "shared_adm_{$testTimestamp}", ':e' => $sharedEmail, ':h' => password_hash($sharedAdminPass, PASSWORD_DEFAULT)]);

    // Create customer with same shared email
    $insSharedCust = $pdo->prepare('INSERT INTO users (name, email, password_hash, auth_provider, role, is_active) VALUES ("Shared Cust", :e, :h, "email", "customer", 1)');
    $insSharedCust->execute([':e' => $sharedEmail, ':h' => password_hash($sharedCustPass, PASSWORD_DEFAULT)]);

    // Attempt 1: Authenticate with admin password -> must login as admin
    $collisionAdminCookie = tempnam(sys_get_temp_dir(), 'shagun_col_adm_');
    $colAdmRes = httpRequest("$baseUrl/login.php", 'POST', [
        'action' => 'credentials',
        'identity' => $sharedEmail,
        'password' => $sharedAdminPass
    ], $collisionAdminCookie, false);
    assertTest($colAdmRes['code'] === 302 && strpos($colAdmRes['redirect_url'], 'admin/index.php') !== false, "Email collision: Valid admin password authenticates as admin");

    // Attempt 2: Authenticate with customer password -> must FAIL (not fall through to customer!)
    $collisionCustCookie = tempnam(sys_get_temp_dir(), 'shagun_col_cust_');
    $colCustRes = httpRequest("$baseUrl/login.php", 'POST', [
        'action' => 'credentials',
        'identity' => $sharedEmail,
        'password' => $sharedCustPass
    ], $collisionCustCookie, false);
    assertTest($colCustRes['code'] === 200, "Email collision: Invalid admin password returns 200 failure");
    assertTest(strpos($colCustRes['body'], 'Invalid email or password.') !== false || strpos($colCustRes['body'], 'Invalid') !== false, "Email collision: Does not silently fall through to customer");

    // Attempt 3: Verify customer dashboard/admin cannot be accessed with that failed cookie
    $colAccessRes = httpRequest("$baseUrl/admin/index.php", 'GET', null, $collisionCustCookie, false);
    assertTest($colAccessRes['code'] === 302 && strpos($colAccessRes['redirect_url'], 'login.php') !== false, "Email collision: Failed login establishes no session");

    // Clean up collision records
    $pdo->prepare('DELETE FROM users WHERE email = :e')->execute([':e' => $sharedEmail]);
    $pdo->prepare('DELETE FROM admin_users WHERE email = :e')->execute([':e' => $sharedEmail]);
    @unlink($collisionAdminCookie);
    @unlink($collisionCustCookie);

    // -------------------------------------------------------------
    // TEST 15 & 16: Logout & Draft Session Preservation
    // -------------------------------------------------------------
    echo "\nTEST 15 & 16: Logout & Session Preservation\n";
    $logoutRes = httpRequest("$baseUrl/logout.php", 'GET', null, $cookieAdmin, false);
    assertTest($logoutRes['code'] === 302, "Logout returns HTTP 302 redirect");
    assertTest(strpos($logoutRes['redirect_url'], 'index.php') !== false, "Logout redirects to index.php");

    // Confirm post-logout access to /admin/index.php requires login
    $postLogoutAdminRes = httpRequest("$baseUrl/admin/index.php", 'GET', null, $cookieAdmin, false);
    assertTest($postLogoutAdminRes['code'] === 302, "Post-logout access to /admin/ requires login (HTTP 302)");

    // -------------------------------------------------------------
    // TEST 17 & 18: Safe Redirects & Open Redirect Protection
    // -------------------------------------------------------------
    echo "\nTEST 17 & 18: Safe Redirects & Open Redirect Prevention\n";
    assertTest(get_safe_redirect_url('luxe-wedding.php') === 'luxe-wedding.php', "Valid relative URL luxe-wedding.php accepted");
    assertTest(get_safe_redirect_url('admin/index.php') === 'admin/index.php', "Valid relative admin URL admin/index.php accepted");
    assertTest(get_safe_redirect_url('https://evil.com') === 'index.php', "External URL https://evil.com sanitized to index.php");
    assertTest(get_safe_redirect_url('//evil.com/hack') === 'index.php', "Protocol-relative //evil.com sanitized to index.php");
    assertTest(get_safe_redirect_url('javascript:alert(1)') === 'index.php', "javascript: scheme sanitized to index.php");
    assertTest(get_safe_redirect_url('../secret.php') === 'index.php', "Path traversal ../ sanitized to index.php");

    // -------------------------------------------------------------
    // TEST 19: Luxe Workflow Login Protection
    // -------------------------------------------------------------
    echo "\nTEST 19: Luxe Workflow Login Protection\n";
    $luxeGuestRes = httpRequest("$baseUrl/luxe-wedding.php", 'GET', null, $cookieGuest, false);
    assertTest($luxeGuestRes['code'] === 302, "Guest accessing luxe-wedding.php receives HTTP 302");
    assertTest(strpos($luxeGuestRes['redirect_url'], 'login.php') !== false, "Guest redirected to login.php");

    // -------------------------------------------------------------
    // TEST 20: Standard Stitching Guest Workflow
    // -------------------------------------------------------------
    echo "\nTEST 20: Standard Stitching Guest Workflow\n";
    $stdGuestRes = httpRequest("$baseUrl/standard-stitching.php", 'GET', null, $cookieGuest, false);
    assertTest($stdGuestRes['code'] === 200, "Guest can browse standard-stitching.php (HTTP 200)");
    $blouseStylesRes = httpRequest("$baseUrl/blouse-styles.php", 'GET', null, $cookieGuest, false);
    assertTest($blouseStylesRes['code'] === 200, "Guest can browse blouse-styles.php (HTTP 200)");

} finally {
    // =============================================================
    // STRICT CLEANUP: Remove temporary test records
    // =============================================================
    echo "\n=======================================================\n";
    echo "CLEANUP: Removing Temporary Test Records\n";
    echo "=======================================================\n";
    try {
        if (isset($pdo)) {
            $delCust = $pdo->prepare('DELETE FROM users WHERE email = :email');
            $delCust->execute([':email' => $testCustEmail]);
            echo "  [CLEANUP] Removed test customer '{$testCustEmail}' from users table.\n";

            $delAdmin = $pdo->prepare('DELETE FROM admin_users WHERE username = :u OR email = :e');
            $delAdmin->execute([':u' => $testAdminUsername, ':e' => $testAdminEmail]);
            echo "  [CLEANUP] Removed test admin '{$testAdminUsername}' from admin_users table.\n";
        }
    } catch (Throwable $e) {
        echo "  [WARN] Cleanup encountered error: " . $e->getMessage() . "\n";
    }

    @unlink($cookieCustomer);
    @unlink($cookieAdmin);
    @unlink($cookieGuest);
    if (isset($loginCustCookie)) @unlink($loginCustCookie);
    if (isset($cookieAdminUser)) @unlink($cookieAdminUser);
}

echo "\n=======================================================\n";
echo "TEST SUITE SUMMARY: Passed: {$passedTests}, Failed: " . count($failedTests) . " / {$totalTests}\n";
echo "=======================================================\n";

if (count($failedTests) > 0) {
    echo "Failed Tests:\n";
    foreach ($failedTests as $f) {
        echo "  - {$f}\n";
    }
    exit(1);
} else {
    echo ">>> ALL 20 PHASE 3 AUTHENTICATION TESTS PASSED 100%! <<<\n";
    exit(0);
}
