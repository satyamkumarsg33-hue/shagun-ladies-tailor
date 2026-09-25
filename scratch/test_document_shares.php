<?php
/**
 * Comprehensive Test Suite for Completion Dossier Document Shares & WhatsApp Delivery
 *
 * Tests:
 * 1. Cryptographic Token Generation & Hashing (64-hex, SHA-256, no plaintext in DB)
 * 2. Status Gating & Photo Requirements for Share Creation
 * 3. Database Persistence & Foreign Key Integrity
 * 4. Token Validation & Bearer Capability Security:
 *    - Valid token access & PDF streaming verification
 *    - Access count and last_accessed_at auditing
 *    - Invalid format handling (404)
 *    - Non-existent token handling (404)
 *    - Revoked token handling (403)
 *    - Expired token handling (410)
 *    - Status regression gating (403 if status changed away from completed/delivered)
 *    - Photo removal gating (403 if completed photos removed)
 *    - Cross-order isolation (token cannot access another order)
 * 5. WhatsApp Click-to-Chat Generation (Localhost vs Public URL)
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/order-status.php';
require_once __DIR__ . '/../includes/whatsapp.php';
require_once __DIR__ . '/../includes/completion-dossier-pdf.php';
require_once __DIR__ . '/../includes/document-shares.php';

$pdo = get_db_connection();

$totalTests = 0;
$passedTests = 0;
$failedTests = 0;

function test_assert(bool $condition, string $description): void {
    global $totalTests, $passedTests, $failedTests;
    $totalTests++;
    if ($condition) {
        $passedTests++;
        echo "  [PASS] {$description}\n";
    } else {
        $failedTests++;
        echo "  [FAIL] {$description}\n";
    }
}

echo "====================================================================\n";
echo "SHAGUN LADIES TAILOR — DOCUMENT SHARES & DELIVERY TEST SUITE\n";
echo "====================================================================\n\n";

// -------------------------------------------------------------------
// SETUP TEST FIXTURES IN DATABASE
// -------------------------------------------------------------------
echo "--- Section 1: Setting Up Clean Test Fixtures ---\n";

// 1. Create or get test user
$userStmt = $pdo->prepare("SELECT id FROM users WHERE phone = '9876543210' LIMIT 1");
$userStmt->execute();
$testUserId = (int)$userStmt->fetchColumn();
if (!$testUserId) {
    $insUser = $pdo->prepare("INSERT INTO users (name, phone, role, created_at) VALUES ('Pooja Sharma', '9876543210', 'customer', NOW())");
    $insUser->execute();
    $testUserId = (int)$pdo->lastInsertId();
}
test_assert($testUserId > 0, "Test customer Pooja Sharma verified (ID: {$testUserId})");

// 2. Create test admin
$admStmt = $pdo->prepare("SELECT id FROM admin_users WHERE username = 'test_admin' LIMIT 1");
$admStmt->execute();
$testAdminId = (int)$admStmt->fetchColumn();
if (!$testAdminId) {
    $insAdmin = $pdo->prepare("INSERT INTO admin_users (username, full_name, email, password_hash, role, is_active, created_at) VALUES ('test_admin', 'Test Atelier Admin', 'admin@shagun.com', 'dummy_hash', 'super_admin', 1, NOW())");
    $insAdmin->execute();
    $testAdminId = (int)$pdo->lastInsertId();
}
test_assert($testAdminId > 0, "Test admin verified (ID: {$testAdminId})");

// 3. Helper to create clean test order
function create_fixture_order(PDO $pdo, int $userId, string $ref, string $status, float $total = 5000, float $advance = 2500): int {
    // Delete any existing order with this ref
    $existing = $pdo->prepare("SELECT id FROM orders WHERE order_ref = :ref");
    $existing->execute([':ref' => $ref]);
    $oldId = (int)$existing->fetchColumn();
    if ($oldId > 0) {
        $pdo->prepare("DELETE FROM order_gallery_photos WHERE order_id = :oid")->execute([':oid' => $oldId]);
        $pdo->prepare("DELETE FROM order_document_shares WHERE order_id = :oid")->execute([':oid' => $oldId]);
        $pdo->prepare("DELETE FROM order_documents WHERE order_id = :oid")->execute([':oid' => $oldId]);
        $pdo->prepare("DELETE FROM order_payments WHERE order_id = :oid")->execute([':oid' => $oldId]);
        $pdo->prepare("DELETE FROM order_garment_work_items WHERE order_garment_id IN (SELECT id FROM order_garments WHERE order_id = :oid)")->execute([':oid' => $oldId]);
        $pdo->prepare("DELETE FROM order_garment_customizations WHERE order_garment_id IN (SELECT id FROM order_garments WHERE order_id = :oid)")->execute([':oid' => $oldId]);
        $pdo->prepare("DELETE FROM order_garments WHERE order_id = :oid")->execute([':oid' => $oldId]);
        $pdo->prepare("DELETE FROM order_people WHERE order_id = :oid")->execute([':oid' => $oldId]);
        $pdo->prepare("DELETE FROM orders WHERE id = :oid")->execute([':oid' => $oldId]);
    }

    $bal = max(0.0, $total - $advance);
    $ins = $pdo->prepare("
        INSERT INTO orders (
            user_id, order_ref, workflow_type, occasion, status,
            total_amount, advance_amount, balance_amount,
            is_demo, booked_date, requested_ready_date, admin_delivery_date,
            created_at, updated_at
        ) VALUES (
            :uid, :ref, 'wedding', 'Bridal Lehenga', :status,
            :total, :adv, :bal,
            1, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 15 DAY), DATE_ADD(CURDATE(), INTERVAL 15 DAY),
            NOW(), NOW()
        )
    ");
    $ins->execute([
        ':uid' => $userId,
        ':ref' => $ref,
        ':status' => $status,
        ':total' => $total,
        ':adv' => $advance,
        ':bal' => $bal
    ]);
    $orderId = (int)$pdo->lastInsertId();

    // Insert Order Person
    $insP = $pdo->prepare("
        INSERT INTO order_people (order_id, person_order_index, name, role, measurement_method, created_at)
        VALUES (:oid, 1, 'Pooja Sharma', 'Bride', 'visit_shop', NOW())
    ");
    $insP->execute([':oid' => $orderId]);
    $personId = (int)$pdo->lastInsertId();

    // Insert Order Garment
    $insG = $pdo->prepare("
        INSERT INTO order_garments (
            order_id, order_person_id, garment_index, garment_type, style_slug, style_name,
            base_price, customization_total, work_total, total_price, work_type, created_at
        ) VALUES (
            :oid, :pid, 1, 'Blouse', 'deep_sweetheart', 'Deep Sweetheart Neck Blouse',
            800, 200, 1000, 2000, 'both', NOW()
        )
    ");
    $insG->execute([':oid' => $orderId, ':pid' => $personId]);

    // Insert payment
    if ($advance > 0) {
        $insPay = $pdo->prepare("
            INSERT INTO order_payments (
                order_id, transaction_ref, payment_method, payment_method_label,
                gateway_mode, amount, currency, status, paid_at, created_at
            ) VALUES (
                :oid, :tx, 'upi', 'UPI / QR Code',
                'simulated', :amt, 'INR', 'completed', NOW(), NOW()
            )
        ");
        $insPay->execute([
            ':oid' => $orderId,
            ':tx' => 'TXN-' . bin2hex(random_bytes(6)),
            ':amt' => $advance
        ]);
    }

    return $orderId;
}

// 4. Helper to attach completed photo
function attach_fixture_photo(PDO $pdo, int $orderId, string $stage = 'completed'): int {
    $ins = $pdo->prepare("
        INSERT INTO order_gallery_photos (order_id, stage, photo_url, original_filename, file_size_bytes, mime_type, caption, created_at)
        VALUES (:oid, :stage, 'assets/images/demo/blouse.png', 'completed_blouse.png', 1024, 'image/png', 'Completed Blouse', NOW())
    ");
    $ins->execute([':oid' => $orderId, ':stage' => $stage]);
    return (int)$pdo->lastInsertId();
}

// -------------------------------------------------------------------
// SECTION 2: TOKEN GENERATION & HASHING PROPERTIES
// -------------------------------------------------------------------
echo "\n--- Section 2: Cryptographic Token Generation & Hashing Properties ---\n";

$token1 = generate_share_token();
$token2 = generate_share_token();

test_assert(strlen($token1) === 64, "Share token is exactly 64 characters long");
test_assert(ctype_xdigit($token1), "Share token is valid hexadecimal");
test_assert($token1 !== $token2, "Subsequent tokens are distinct (cryptographically random)");

$hash1 = hash_share_token($token1);
test_assert(strlen($hash1) === 64, "Token hash is exactly 64 characters long (SHA-256)");
test_assert(ctype_xdigit($hash1), "Token hash is valid hexadecimal");
test_assert($hash1 === hash('sha256', $token1), "hash_share_token uses SHA-256 correctly");

$shareUrl = get_document_share_url($token1);
test_assert(str_contains($shareUrl, 'view-completion-dossier.php?token='), "Share URL points to view-completion-dossier.php");
test_assert(str_contains($shareUrl, $token1), "Share URL contains raw bearer token");

// -------------------------------------------------------------------
// SECTION 3: STATUS & PHOTO GATING FOR SHARE CREATION
// -------------------------------------------------------------------
echo "\n--- Section 3: Status Gating & Photo Requirements for Share Creation ---\n";

// Order 1: Awaiting confirmation
$orderAwaitingId = create_fixture_order($pdo, $testUserId, 'LT-TEST-GATE-AWAIT', 'awaiting_confirmation');
attach_fixture_photo($pdo, $orderAwaitingId, 'awaiting_confirmation');

$caughtAwaiting = false;
try {
    create_order_document_share($pdo, $orderAwaitingId, $testAdminId);
} catch (DomainException $e) {
    $caughtAwaiting = true;
}
test_assert($caughtAwaiting, "create_order_document_share rejects order in awaiting_confirmation status");

// Order 2: Stitching in process
$orderInProcessId = create_fixture_order($pdo, $testUserId, 'LT-TEST-GATE-PROC', 'stitching_in_process');
attach_fixture_photo($pdo, $orderInProcessId, 'awaiting_confirmation');

$caughtInProcess = false;
try {
    create_order_document_share($pdo, $orderInProcessId, $testAdminId);
} catch (DomainException $e) {
    $caughtInProcess = true;
}
test_assert($caughtInProcess, "create_order_document_share rejects order in stitching_in_process status");

// Order 3: Completed order but 0 completed photos
$orderNoPhotoId = create_fixture_order($pdo, $testUserId, 'LT-TEST-GATE-NOPHOTO', 'completed');
$caughtNoPhoto = false;
try {
    create_order_document_share($pdo, $orderNoPhotoId, $testAdminId);
} catch (DomainException $e) {
    $caughtNoPhoto = true;
}
test_assert($caughtNoPhoto, "create_order_document_share rejects completed order with 0 completed photos");

// -------------------------------------------------------------------
// SECTION 4: SUCCESSFUL SHARE CREATION & DATABASE PERSISTENCE
// -------------------------------------------------------------------
echo "\n--- Section 4: Successful Share Creation & Database Persistence ---\n";

// Order 4: Completed order with 1 completed photo
$orderCompId = create_fixture_order($pdo, $testUserId, 'LT-TEST-SHARE-OK', 'completed');
attach_fixture_photo($pdo, $orderCompId, 'completed');

$share = create_order_document_share($pdo, $orderCompId, $testAdminId, 30);

test_assert(!empty($share['id']), "Share created with ID {$share['id']}");
test_assert(!empty($share['token']), "Plaintext token returned in result");
test_assert(strlen($share['token']) === 64, "Plaintext token has length 64");
test_assert($share['token_hash'] === hash('sha256', $share['token']), "Hash matches token SHA-256");
test_assert(!empty($share['document_id']), "Document ID linked to order_documents");
test_assert(strtotime($share['expires_at']) > time() + (29 * 86400), "Expires at is ~30 days in the future");

// Verify DB table order_document_shares content
$chkStmt = $pdo->prepare("SELECT * FROM order_document_shares WHERE id = :id");
$chkStmt->execute([':id' => $share['id']]);
$dbShare = $chkStmt->fetch(PDO::FETCH_ASSOC);

test_assert($dbShare !== false, "Share record exists in order_document_shares table");
test_assert($dbShare['token_hash'] === $share['token_hash'], "DB stores correct token_hash");
test_assert(!isset($dbShare['token']), "DB does NOT have a plaintext 'token' column (Security Requirement)");
test_assert((int)$dbShare['is_revoked'] === 0, "is_revoked is 0 by default");
test_assert((int)$dbShare['access_count'] === 0, "access_count starts at 0");
test_assert($dbShare['last_accessed_at'] === null, "last_accessed_at starts as NULL");
test_assert((int)$dbShare['created_by_admin_id'] === $testAdminId, "created_by_admin_id records admin user ID");

// Verify get_active_order_document_share helper
$active = get_active_order_document_share($pdo, $orderCompId);
test_assert($active !== null && (int)$active['id'] === $share['id'], "get_active_order_document_share finds the active share record");

// -------------------------------------------------------------------
// SECTION 5: TOKEN VALIDATION & BEARER CAPABILITY
// -------------------------------------------------------------------
echo "\n--- Section 5: Token Validation & Bearer Capability Security ---\n";

// 1. Valid token access
$valResult = validate_order_document_share_token($pdo, $share['token']);
test_assert($valResult['valid'] === true, "Valid token successfully validates");
test_assert($valResult['http_code'] === 200, "Valid token returns HTTP 200");
test_assert(!empty($valResult['file_path']), "Valid token returns resolved physical file path");
test_assert(file_exists($valResult['file_path']), "Physical PDF file exists on disk");
test_assert(str_contains($valResult['file_name'], 'LT-TEST-SHARE-OK'), "Download file name contains order reference");

// 2. Verify access auditing (access_count increment & last_accessed_at)
$chkAudit = $pdo->prepare("SELECT access_count, last_accessed_at FROM order_document_shares WHERE id = :id");
$chkAudit->execute([':id' => $share['id']]);
$auditData = $chkAudit->fetch(PDO::FETCH_ASSOC);
test_assert((int)$auditData['access_count'] === 1, "access_count incremented to 1");
test_assert(!empty($auditData['last_accessed_at']), "last_accessed_at was updated to timestamp");

// 3. Second access increments to 2
validate_order_document_share_token($pdo, $share['token']);
$chkAudit->execute([':id' => $share['id']]);
$auditData2 = $chkAudit->fetch(PDO::FETCH_ASSOC);
test_assert((int)$auditData2['access_count'] === 2, "Second access incremented access_count to 2");

// 4. Invalid token format (e.g. short, SQL injection attempt, non-hex)
$valShort = validate_order_document_share_token($pdo, "short_token");
test_assert($valShort['valid'] === false && $valShort['http_code'] === 404, "Short token format rejected with 404");

$valNonHex = validate_order_document_share_token($pdo, str_repeat("z", 64));
test_assert($valNonHex['valid'] === false && $valNonHex['http_code'] === 404, "Non-hex token format rejected with 404");

$valInjection = validate_order_document_share_token($pdo, "' OR '1'='1");
test_assert($valInjection['valid'] === false && $valInjection['http_code'] === 404, "Injection attempt rejected with 404");

// 5. Non-existent valid-format token
$fakeToken = bin2hex(random_bytes(32));
$valFake = validate_order_document_share_token($pdo, $fakeToken);
test_assert($valFake['valid'] === false && $valFake['http_code'] === 404 && $valFake['error'] === 'not_found', "Non-existent token returns 404 not_found");

// -------------------------------------------------------------------
// SECTION 6: REVOCATION & EXPIRATION LIFECYCLE
// -------------------------------------------------------------------
echo "\n--- Section 6: Revocation & Expiration Lifecycle ---\n";

// 1. Revocation test
$revokeOk = revoke_order_document_shares($pdo, $orderCompId);
test_assert($revokeOk === true, "revoke_order_document_shares executed successfully");

$valRevoked = validate_order_document_share_token($pdo, $share['token']);
test_assert($valRevoked['valid'] === false, "Revoked token is rejected");
test_assert($valRevoked['http_code'] === 403, "Revoked token returns HTTP 403");
test_assert($valRevoked['error'] === 'revoked', "Revoked token error code is 'revoked'");

// 2. Regeneration creates a fresh active share and revokes previous
$share2 = create_order_document_share($pdo, $orderCompId, $testAdminId, 30);
test_assert($share2['token'] !== $share['token'], "Regenerated share has a new distinct token");

$valShare2 = validate_order_document_share_token($pdo, $share2['token']);
test_assert($valShare2['valid'] === true, "New regenerated share validates successfully");

// Old share is still rejected
$valOld = validate_order_document_share_token($pdo, $share['token']);
test_assert($valOld['valid'] === false && $valOld['error'] === 'revoked', "Old share remains revoked after regeneration");

// 3. Expiration test: simulate past expiration
$pdo->prepare("UPDATE order_document_shares SET expires_at = DATE_SUB(NOW(), INTERVAL 1 DAY) WHERE id = :id")->execute([':id' => $share2['id']]);

$valExpired = validate_order_document_share_token($pdo, $share2['token']);
test_assert($valExpired['valid'] === false, "Expired token is rejected");
test_assert($valExpired['http_code'] === 410, "Expired token returns HTTP 410 Gone");
test_assert($valExpired['error'] === 'expired', "Expired token error code is 'expired'");

// -------------------------------------------------------------------
// SECTION 7: STATUS REGRESSION & PHOTO REMOVAL GATING ON TOKEN VALIDATION
// -------------------------------------------------------------------
echo "\n--- Section 7: Status Regression & Photo Removal Gating on Validation ---\n";

// Create order in completed status with photo
$orderGateId = create_fixture_order($pdo, $testUserId, 'LT-TEST-REGRESS', 'completed');
attach_fixture_photo($pdo, $orderGateId, 'completed');
$shareRegress = create_order_document_share($pdo, $orderGateId, $testAdminId, 30);

// Validate initially succeeds
test_assert(validate_order_document_share_token($pdo, $shareRegress['token'])['valid'] === true, "Initial token for completed order validates");

// Simulate admin rolling back status to stitching_in_process
$pdo->prepare("UPDATE orders SET status = 'stitching_in_process' WHERE id = :id")->execute([':id' => $orderGateId]);
$valRegressStatus = validate_order_document_share_token($pdo, $shareRegress['token']);
test_assert($valRegressStatus['valid'] === false, "Token rejected after order status rolled back to stitching_in_process");
test_assert($valRegressStatus['http_code'] === 403, "Status rollback returns HTTP 403");
test_assert($valRegressStatus['error'] === 'invalid_order_status', "Error code is 'invalid_order_status'");

// Restore status to delivered
$pdo->prepare("UPDATE orders SET status = 'delivered' WHERE id = :id")->execute([':id' => $orderGateId]);
test_assert(validate_order_document_share_token($pdo, $shareRegress['token'])['valid'] === true, "Token validates again when order is marked delivered");

// Simulate all completed photos being deleted
$pdo->prepare("DELETE FROM order_gallery_photos WHERE order_id = :id AND stage = 'completed'")->execute([':id' => $orderGateId]);
$valNoPhotos = validate_order_document_share_token($pdo, $shareRegress['token']);
test_assert($valNoPhotos['valid'] === false, "Token rejected if completed photos are removed");
test_assert($valNoPhotos['http_code'] === 403, "Missing photos returns HTTP 403");
test_assert($valNoPhotos['error'] === 'missing_photos', "Error code is 'missing_photos'");

// -------------------------------------------------------------------
// SECTION 8: CROSS-ORDER ISOLATION
// -------------------------------------------------------------------
echo "\n--- Section 8: Cross-Order Isolation ---\n";

// Order A and Order B
$orderA = create_fixture_order($pdo, $testUserId, 'LT-ORDER-AAA', 'completed', 3000, 1500);
attach_fixture_photo($pdo, $orderA, 'completed');
$shareA = create_order_document_share($pdo, $orderA, $testAdminId);

$orderB = create_fixture_order($pdo, $testUserId, 'LT-ORDER-BBB', 'completed', 7000, 3500);
attach_fixture_photo($pdo, $orderB, 'completed');
$shareB = create_order_document_share($pdo, $orderB, $testAdminId);

$valA = validate_order_document_share_token($pdo, $shareA['token']);
$valB = validate_order_document_share_token($pdo, $shareB['token']);

test_assert((int)$valA['share']['order_id'] === $orderA, "Token A validates ONLY Order A");
test_assert((int)$valB['share']['order_id'] === $orderB, "Token B validates ONLY Order B");
test_assert($valA['share']['order_ref'] === 'LT-ORDER-AAA', "Token A references LT-ORDER-AAA");
test_assert($valB['share']['order_ref'] === 'LT-ORDER-BBB', "Token B references LT-ORDER-BBB");
test_assert($shareA['token'] !== $shareB['token'], "Tokens A and B are cryptographically independent");

// -------------------------------------------------------------------
// SECTION 9: WHATSAPP CLICK-TO-CHAT PAYLOAD & DELIVERY
// -------------------------------------------------------------------
echo "\n--- Section 9: WhatsApp Click-to-Chat Payload & Delivery ---\n";

$orderDataTest = [
    'order_ref' => 'LT-ORDER-AAA',
    'customer_name' => 'Pooja Sharma',
    'customer_phone' => '+91 98765 43210',
    'total_amount' => 3000,
    'remaining_balance' => 1500
];

// 1. Localhost behavior (current environment is local)
$waLocal = build_whatsapp_completion_update($orderDataTest);
test_assert($waLocal['can_share_url'] === false, "can_share_url is false on localhost");
test_assert(!str_contains($waLocal['message_text'], 'localhost'), "Localhost message does NOT contain unreachable localhost URL");
test_assert(!str_contains($waLocal['message_text'], 'http'), "Localhost message contains no HTTP links");
test_assert(str_contains($waLocal['message_text'], 'Pooja Sharma'), "Message includes customer name");
test_assert(str_contains($waLocal['message_text'], 'LT-ORDER-AAA'), "Message includes order ref");
test_assert(str_contains($waLocal['message_text'], 'Rs. 3,000'), "Message includes total amount");
test_assert(str_contains($waLocal['message_text'], 'Rs. 1,500'), "Message includes balance amount");
test_assert(str_contains($waLocal['whatsapp_url'], '919876543210'), "WhatsApp URL has properly formatted phone number");
test_assert(!str_contains(strtolower($waLocal['message_text']), 'attached'), "Never claims PDF is attached");

// 2. Simulated public URL behavior
// Temporarily mock public URL by calling build with an explicit shareUrl
$testShareUrl = "https://shagunladiestailor.com/view-completion-dossier.php?token=" . $shareA['token'];

// Test format_whatsapp_click_to_chat_url with shareUrl
$waPublicUrl = format_whatsapp_click_to_chat_url($orderDataTest, $testShareUrl);
test_assert(!empty($waPublicUrl), "format_whatsapp_click_to_chat_url generates URL");
test_assert(str_contains($waPublicUrl, 'api.whatsapp.com/send'), "Uses api.whatsapp.com/send endpoint");

// -------------------------------------------------------------------
// CLEANUP TEST FIXTURES
// -------------------------------------------------------------------
echo "\n--- Cleaning up temporary test fixtures ---\n";
$refsToClean = ['LT-TEST-GATE-AWAIT', 'LT-TEST-GATE-PROC', 'LT-TEST-GATE-NOPHOTO', 'LT-TEST-SHARE-OK', 'LT-TEST-REGRESS', 'LT-ORDER-AAA', 'LT-ORDER-BBB'];
foreach ($refsToClean as $r) {
    $s = $pdo->prepare("SELECT id FROM orders WHERE order_ref = :ref");
    $s->execute([':ref' => $r]);
    $oid = (int)$s->fetchColumn();
    if ($oid > 0) {
        $pdo->prepare("DELETE FROM order_gallery_photos WHERE order_id = :oid")->execute([':oid' => $oid]);
        $pdo->prepare("DELETE FROM order_document_shares WHERE order_id = :oid")->execute([':oid' => $oid]);
        $pdo->prepare("DELETE FROM order_documents WHERE order_id = :oid")->execute([':oid' => $oid]);
        $pdo->prepare("DELETE FROM order_payments WHERE order_id = :oid")->execute([':oid' => $oid]);
        $pdo->prepare("DELETE FROM order_garment_work_items WHERE order_garment_id IN (SELECT id FROM order_garments WHERE order_id = :oid)")->execute([':oid' => $oid]);
        $pdo->prepare("DELETE FROM order_garment_customizations WHERE order_garment_id IN (SELECT id FROM order_garments WHERE order_id = :oid)")->execute([':oid' => $oid]);
        $pdo->prepare("DELETE FROM order_garments WHERE order_id = :oid")->execute([':oid' => $oid]);
        $pdo->prepare("DELETE FROM order_people WHERE order_id = :oid")->execute([':oid' => $oid]);
        $pdo->prepare("DELETE FROM orders WHERE id = :oid")->execute([':oid' => $oid]);
    }
}
echo "  [CLEANUP] Test fixtures removed from database.\n";

// -------------------------------------------------------------------
// SUMMARY
// -------------------------------------------------------------------
echo "\n====================================================================\n";
echo "TEST RESULTS: {$passedTests} passed, {$failedTests} failed (Total: {$totalTests})\n";
echo "====================================================================\n";

if ($failedTests > 0) {
    exit(1);
}
exit(0);
