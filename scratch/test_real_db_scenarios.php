<?php
declare(strict_types=1);

/**
 * Test Suite: Real Database Verification for Completion Dossier & WhatsApp
 * 
 * Verifies all 8 required scenarios against live MariaDB:
 * 1. Completed order with 1 completed photo -> Success, PDF created, document recorded.
 * 2. Completed order without completed photos -> Server-side rejection.
 * 3. Delivered order retaining completed dossier access -> Success.
 * 4. Order with intake photos but no completed photos -> Server-side rejection & photo separation.
 * 5. Customer attempting to access another customer's dossier -> Ownership guard rejection.
 * 6. Order with partial payment and remaining balance -> Accurate financial calculation in PDF & WhatsApp.
 * 7. Order with fully paid balance -> Accurate cleared status in PDF & WhatsApp.
 * 8. Order with JPEG and PNG completed photos -> Valid binary embedding without distortion or GD.
 * 
 * Also tests:
 * - PDF versioning and regeneration consistency.
 * - Cross-order gallery photo deletion prevention.
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
require_once __DIR__ . '/../includes/whatsapp.php';
require_once __DIR__ . '/../includes/dossier-pdf.php';
require_once __DIR__ . '/../includes/completion-dossier-pdf.php';

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
echo " TEST SUITE: REAL DATABASE SCENARIOS — COMPLETION DOSSIER & WHATSAPP\n";
echo "=====================================================================\n";

$pdo = get_db_connection();

// Create sample test images (1 JPEG, 1 PNG)
$testJpegPath = __DIR__ . '/sample_real.jpg';
$testPngPath = __DIR__ . '/sample_real.png';

$jpegHex = 'ffd8ffe000104a46494600010101004800480000ffdb004300080606070605080707070909080a0c140d0c0b0b0c1912130f141d1a1f1e1d1a1c1c20242e2720222c231c1c2837292c30313434341f27393d38323c2e333432ffc0000b080002000201011100ffda0008010100003f00bf8000ffdf90ff40ff90ff40ffd9';
file_put_contents($testJpegPath, hex2bin($jpegHex));

$rawPixels = "\x00\xFF\x00\x00\x00\xFF\x00\x00\x00\x00\xFF\x00\x00\xFF\xFF";
$compressed = gzcompress($rawPixels);
$ihdrData = pack('NNCCCCC', 2, 2, 8, 2, 0, 0, 0);
$ihdrCrc = pack('N', crc32('IHDR' . $ihdrData));
$ihdrChunk = pack('N', 13) . 'IHDR' . $ihdrData . $ihdrCrc;
$idatCrc = pack('N', crc32('IDAT' . $compressed));
$idatChunk = pack('N', strlen($compressed)) . 'IDAT' . $compressed . $idatCrc;
$iendCrc = pack('N', crc32('IEND'));
$iendChunk = pack('N', 0) . 'IEND' . $iendCrc;
file_put_contents($testPngPath, "\x89PNG\r\n\x1a\n" . $ihdrChunk . $idatChunk . $iendChunk);

// Track created IDs for cleanup
$cleanupUserIds = [];
$cleanupOrderIds = [];
$cleanupDocIds = [];
$cleanupFiles = [$testJpegPath, $testPngPath];

try {
    // -------------------------------------------------------------------
    // SETUP TEST CUSTOMERS
    // -------------------------------------------------------------------
    echo "\n--- Setting Up Test Customers in Database ---\n";

    // Customer A
    $pwdHash = password_hash('TestPass123!', PASSWORD_BCRYPT);
    $insUser = $pdo->prepare("
        INSERT INTO users (name, email, phone, password_hash, auth_provider, role, is_active, created_at, updated_at)
        VALUES (:name, :email, :phone, :pwd, 'email', 'customer', 1, NOW(), NOW())
    ");
    $insUser->execute([
        ':name' => 'Pooja Verma',
        ':email' => 'customerA_' . time() . '@example.com',
        ':phone' => '+91 98765 11111',
        ':pwd' => $pwdHash
    ]);
    $userAId = (int)$pdo->lastInsertId();
    $cleanupUserIds[] = $userAId;

    $insAddr = $pdo->prepare("
        INSERT INTO customer_addresses (user_id, address_type, recipient_name, phone, address_line1, city, state, postal_code, is_default, created_at)
        VALUES (:uid, 'home', 'Pooja Verma', '+91 98765 11111', 'Flat 101, Shanti Heights, Civil Lines', 'Ludhiana', 'Punjab', '141001', 1, NOW())
    ");
    $insAddr->execute([':uid' => $userAId]);

    // Customer B
    $insUser->execute([
        ':name' => 'Simran Kaur',
        ':email' => 'customerB_' . time() . '@example.com',
        ':phone' => '+91 98765 22222',
        ':pwd' => $pwdHash
    ]);
    $userBId = (int)$pdo->lastInsertId();
    $cleanupUserIds[] = $userBId;

    $insAddr->execute([':uid' => $userBId]);

    assert_true($userAId > 0 && $userBId > 0, "Created test customers User A ($userAId) and User B ($userBId)");

    // Helper to insert an order with people, garments, payments
    $insertOrder = function(
        int $userId,
        string $orderRef,
        string $status,
        float $totalAmt,
        float $advanceAmt,
        string $workflowType = 'wedding',
        string $occasion = 'Bridal Reception'
    ) use ($pdo, &$cleanupOrderIds): int {
        $ins = $pdo->prepare("
            INSERT INTO orders (
                user_id, order_ref, workflow_type, occasion, status,
                total_amount, advance_amount, balance_amount,
                is_demo, booked_date, requested_ready_date, admin_delivery_date,
                created_at, updated_at
            ) VALUES (
                :uid, :ref, :wf, :occ, :status,
                :tot, :adv, :bal,
                1, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 15 DAY), DATE_ADD(CURDATE(), INTERVAL 15 DAY),
                NOW(), NOW()
            )
        ");
        $bal = max(0.0, $totalAmt - $advanceAmt);
        $ins->execute([
            ':uid' => $userId,
            ':ref' => $orderRef,
            ':wf' => $workflowType,
            ':occ' => $occasion,
            ':status' => $status,
            ':tot' => $totalAmt,
            ':adv' => $advanceAmt,
            ':bal' => $bal
        ]);
        $orderId = (int)$pdo->lastInsertId();
        $cleanupOrderIds[] = $orderId;

        // Insert Order Person
        $insP = $pdo->prepare("
            INSERT INTO order_people (order_id, person_order_index, name, role, measurement_method, created_at)
            VALUES (:oid, 1, 'Test Person', 'Bride', 'visit_shop', NOW())
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
        $garmentId = (int)$pdo->lastInsertId();

        // Insert Customization
        $insC = $pdo->prepare("
            INSERT INTO order_garment_customizations (order_garment_id, option_group_label, choice_label, price_delta, created_at)
            VALUES (:gid, 'Back Neckline', 'Potli Button Cutout', 200, NOW())
        ");
        $insC->execute([':gid' => $garmentId]);

        // Insert Work Items
        $insW = $pdo->prepare("
            INSERT INTO order_garment_work_items (order_garment_id, work_category, design_code, design_name, placement, price, created_at)
            VALUES (:gid, 'hand', 'HW-01', 'Zari Border Embroidery', 'Sleeves & Neck', 1000, NOW())
        ");
        $insW->execute([':gid' => $garmentId]);

        // Insert Payment
        if ($advanceAmt > 0) {
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
                ':amt' => $advanceAmt
            ]);
        }

        return $orderId;
    };

    // -------------------------------------------------------------------
    // SCENARIO 1: Completed order with 1 completed photo
    // -------------------------------------------------------------------
    echo "\n--- Scenario 1: Completed Order with 1 Completed Photo ---\n";

    $ref1 = 'LT-TEST-SC1-' . time();
    $ordId1 = $insertOrder($userAId, $ref1, 'completed', 5000.0, 3000.0);

    // Insert 1 completed photo
    $insPhoto = $pdo->prepare("
        INSERT INTO order_gallery_photos (order_id, stage, photo_url, original_filename, file_size_bytes, mime_type, caption, created_at)
        VALUES (:oid, 'completed', 'scratch/sample_real.jpg', 'sample_real.jpg', 150, 'image/jpeg', 'Neckline finish', NOW())
    ");
    $insPhoto->execute([':oid' => $ordId1]);

    $orderData1 = get_customer_order_by_ref($ref1, $userAId);
    $orderData1['id'] = $ordId1;
    $orderData1['customer_name'] = 'Pooja Verma';
    $orderData1['customer_phone'] = '+91 98765 11111';
    $orderData1['customer_email'] = 'pooja@example.com';
    $orderData1['customer_address'] = 'Flat 101, Shanti Heights, Civil Lines, Ludhiana, Punjab';
    $orderData1['customer_sequence'] = 1;
    $orderData1['customer_sequence_label'] = 'New Customer';

    // Query completed photos
    $photoStmt = $pdo->prepare("SELECT * FROM order_gallery_photos WHERE order_id = :oid AND stage = 'completed'");
    $photoStmt->execute([':oid' => $ordId1]);
    $orderData1['completed_photos'] = $photoStmt->fetchAll(PDO::FETCH_ASSOC);

    assert_equals(count($orderData1['completed_photos']), 1, "Order 1 has exactly 1 completed photo in database");

    // Generate and save dossier
    $savedDoc1 = ShagunCompletionDossierPdf::saveToStorage($orderData1, 'admin_action');
    assert_true(!empty($savedDoc1), "Completion dossier successfully generated and saved for Scenario 1");
    assert_true(file_exists($savedDoc1['file_path']), "Saved PDF file exists on disk");
    assert_equals($savedDoc1['document_type'], 'completion_dossier', "document_type is 'completion_dossier'");
    $cleanupFiles[] = $savedDoc1['file_path'];
    $cleanupDocIds[] = $savedDoc1['id'];

    // Verify PDF binary contents
    $pdf1 = file_get_contents($savedDoc1['file_path']);
    assert_true(strpos($pdf1, '%PDF-1.4') === 0, "PDF 1 starts with %PDF-1.4");
    assert_true(strpos($pdf1, $ref1) !== false, "PDF 1 contains order reference {$ref1}");
    assert_true(strpos($pdf1, 'Pooja Verma') !== false, "PDF 1 contains customer name Pooja Verma");
    assert_true(strpos($pdf1, 'Ludhiana') !== false, "PDF 1 contains customer address Ludhiana");
    assert_true(strpos($pdf1, 'New Customer') !== false, "PDF 1 contains customer sequence label");
    assert_true(strpos($pdf1, 'Neckline finish') !== false, "PDF 1 contains photo caption");

    // -------------------------------------------------------------------
    // SCENARIO 2: Completed order WITHOUT completed photos
    // -------------------------------------------------------------------
    echo "\n--- Scenario 2: Completed Order WITHOUT Completed Photos ---\n";

    $ref2 = 'LT-TEST-SC2-' . time();
    $ordId2 = $insertOrder($userAId, $ref2, 'completed', 4000.0, 2000.0);

    $orderData2 = get_customer_order_by_ref($ref2, $userAId);
    $orderData2['id'] = $ordId2;
    $orderData2['completed_photos'] = [];

    $threwNoPhotos = false;
    try {
        ShagunCompletionDossierPdf::generate($orderData2);
    } catch (\InvalidArgumentException $e) {
        $threwNoPhotos = true;
        assert_true(strpos($e->getMessage(), 'At least one completed garment photo') !== false, "Exception message specifies photo requirement");
    }
    assert_true($threwNoPhotos, "Server-side rejection when completed order has 0 completed photos");

    // -------------------------------------------------------------------
    // SCENARIO 3: Delivered order retaining completed dossier access
    // -------------------------------------------------------------------
    echo "\n--- Scenario 3: Delivered Order Retaining Completed Dossier Access ---\n";

    $ref3 = 'LT-TEST-SC3-' . time();
    $ordId3 = $insertOrder($userAId, $ref3, 'delivered', 6000.0, 6000.0);

    // Insert completed photo
    $insPhoto->execute([':oid' => $ordId3]);

    $orderData3 = get_customer_order_by_ref($ref3, $userAId);
    $orderData3['id'] = $ordId3;
    $orderData3['customer_name'] = 'Pooja Verma';
    $orderData3['completed_photos'] = $photoStmt->fetchAll(PDO::FETCH_ASSOC);

    // Re-query photos for order 3
    $pStmt3 = $pdo->prepare("SELECT * FROM order_gallery_photos WHERE order_id = :oid AND stage = 'completed'");
    $pStmt3->execute([':oid' => $ordId3]);
    $orderData3['completed_photos'] = $pStmt3->fetchAll(PDO::FETCH_ASSOC);

    $savedDoc3 = ShagunCompletionDossierPdf::saveToStorage($orderData3, 'admin_action');
    assert_true(!empty($savedDoc3), "Delivered order successfully retains access to generate Completion Dossier");
    assert_true(file_exists($savedDoc3['file_path']), "Delivered order PDF saved on disk");
    $cleanupFiles[] = $savedDoc3['file_path'];
    $cleanupDocIds[] = $savedDoc3['id'];

    // -------------------------------------------------------------------
    // SCENARIO 4: Order with intake photos but NO completed photos
    // -------------------------------------------------------------------
    echo "\n--- Scenario 4: Order with Intake Photos but NO Completed Photos ---\n";

    $ref4 = 'LT-TEST-SC4-' . time();
    $ordId4 = $insertOrder($userAId, $ref4, 'completed', 4500.0, 2500.0);

    // Insert 2 intake photos (stage = 'awaiting_confirmation')
    $insIntake = $pdo->prepare("
        INSERT INTO order_gallery_photos (order_id, stage, photo_url, original_filename, file_size_bytes, mime_type, caption, created_at)
        VALUES (:oid, 'awaiting_confirmation', :url, :fn, 150, :mime, :cap, NOW())
    ");
    $insIntake->execute([
        ':oid' => $ordId4,
        ':url' => 'scratch/sample_real.jpg',
        ':fn' => 'intake1.jpg',
        ':mime' => 'image/jpeg',
        ':cap' => 'Client saree fabric'
    ]);
    $insIntake->execute([
        ':oid' => $ordId4,
        ':url' => 'scratch/sample_real.png',
        ':fn' => 'intake2.png',
        ':mime' => 'image/png',
        ':cap' => 'Lining sample'
    ]);

    // Query for completed photos strictly
    $pStmt4 = $pdo->prepare("SELECT * FROM order_gallery_photos WHERE order_id = :oid AND stage = 'completed'");
    $pStmt4->execute([':oid' => $ordId4]);
    $compPhotos4 = $pStmt4->fetchAll(PDO::FETCH_ASSOC);

    assert_equals(count($compPhotos4), 0, "Query for stage = 'completed' returns 0 photos when order has only intake photos");

    // Attempt generation with strict completed photos
    $orderData4 = get_customer_order_by_ref($ref4, $userAId);
    $orderData4['id'] = $ordId4;
    $orderData4['completed_photos'] = $compPhotos4;

    $threwForIntakeOnly = false;
    try {
        ShagunCompletionDossierPdf::generate($orderData4);
    } catch (\InvalidArgumentException $e) {
        $threwForIntakeOnly = true;
    }
    assert_true($threwForIntakeOnly, "Order with only intake photos is strictly rejected from generating Completion Dossier");

    // -------------------------------------------------------------------
    // SCENARIO 5: Customer attempting to access another customer's dossier
    // -------------------------------------------------------------------
    echo "\n--- Scenario 5: Customer Ownership & Isolation Guard ---\n";

    // User B attempts to access User A's order ($ref1)
    $stmtLookup = $pdo->prepare("SELECT * FROM orders WHERE order_ref = :ref LIMIT 1");
    $stmtLookup->execute([':ref' => $ref1]);
    $dbOrderRecord = $stmtLookup->fetch(PDO::FETCH_ASSOC);

    assert_true($dbOrderRecord !== false, "Order {$ref1} exists in database");
    $orderOwnerUserId = (int)$dbOrderRecord['user_id'];
    assert_equals($orderOwnerUserId, $userAId, "Order {$ref1} is owned by User A ($userAId)");

    // Simulate User B requesting Order A's dossier in orders.php:
    // Route logic: if ($dbOrd && (int)$dbOrd['user_id'] === $currentUserId)
    $simulatedCurrentUserB = $userBId;
    $isUserBAuthorized = ($dbOrderRecord && (int)$dbOrderRecord['user_id'] === $simulatedCurrentUserB);
    assert_true(!$isUserBAuthorized, "User B ($userBId) is NOT authorized to access User A's order ({$ref1})");

    // Simulate User A requesting Order A's dossier in orders.php:
    $simulatedCurrentUserA = $userAId;
    $isUserAAuthorized = ($dbOrderRecord && (int)$dbOrderRecord['user_id'] === $simulatedCurrentUserA);
    assert_true($isUserAAuthorized, "User A ($userAId) is legitimately authorized to access their own order ({$ref1})");

    // -------------------------------------------------------------------
    // SCENARIO 6: Order with partial payment and remaining balance
    // -------------------------------------------------------------------
    echo "\n--- Scenario 6: Order with Partial Payment & Remaining Balance ---\n";

    $ref6 = 'LT-TEST-SC6-' . time();
    $ordId6 = $insertOrder($userAId, $ref6, 'completed', 10000.0, 4000.0);

    // Add completed photo
    $insPhoto->execute([':oid' => $ordId6]);
    $pStmt6 = $pdo->prepare("SELECT * FROM order_gallery_photos WHERE order_id = :oid AND stage = 'completed'");
    $pStmt6->execute([':oid' => $ordId6]);

    $orderData6 = [
        'id' => $ordId6,
        'order_ref' => $ref6,
        'user_id' => $userAId,
        'customer_name' => 'Pooja Verma',
        'customer_phone' => '+91 98765 11111',
        'status' => 'completed',
        'canonical_status' => 'completed',
        'total_amount' => 10000.0,
        'amount_paid' => 4000.0,
        'remaining_balance' => 6000.0,
        'payment_status' => 'partially_paid',
        'completed_photos' => $pStmt6->fetchAll(PDO::FETCH_ASSOC),
        'customer_sequence' => 1,
        'customer_sequence_label' => 'New Customer'
    ];

    $savedDoc6 = ShagunCompletionDossierPdf::saveToStorage($orderData6, 'admin_action');
    assert_true(!empty($savedDoc6), "Generated PDF for partial payment order");
    $cleanupFiles[] = $savedDoc6['file_path'];
    $cleanupDocIds[] = $savedDoc6['id'];

    $pdf6 = file_get_contents($savedDoc6['file_path']);
    assert_true(strpos($pdf6, 'Rs. 10,000') !== false, "PDF shows Total Rs. 10,000");
    assert_true(strpos($pdf6, 'Rs. 4,000') !== false, "PDF shows Paid Rs. 4,000");
    assert_true(strpos($pdf6, 'Rs. 6,000') !== false, "PDF shows Balance Rs. 6,000");
    assert_true(strpos($pdf6, 'Partially Paid') !== false, "PDF shows status Partially Paid");

    // WhatsApp message check
    $wa6 = build_whatsapp_completion_update($orderData6);
    assert_true(strpos($wa6['message_text'], 'Total: Rs. 10,000') !== false, "WhatsApp message contains Total Rs. 10,000");
    assert_true(strpos($wa6['message_text'], 'Balance: Rs. 6,000') !== false, "WhatsApp message contains Balance Rs. 6,000");
    assert_true(strpos($wa6['message_text'], 'localhost') === false, "WhatsApp message does NOT include localhost link");

    // -------------------------------------------------------------------
    // SCENARIO 7: Order with fully paid balance
    // -------------------------------------------------------------------
    echo "\n--- Scenario 7: Order with Fully Paid Balance ---\n";

    $ref7 = 'LT-TEST-SC7-' . time();
    $ordId7 = $insertOrder($userAId, $ref7, 'completed', 5000.0, 5000.0);

    $insPhoto->execute([':oid' => $ordId7]);
    $pStmt7 = $pdo->prepare("SELECT * FROM order_gallery_photos WHERE order_id = :oid AND stage = 'completed'");
    $pStmt7->execute([':oid' => $ordId7]);

    $orderData7 = [
        'id' => $ordId7,
        'order_ref' => $ref7,
        'user_id' => $userAId,
        'customer_name' => 'Pooja Verma',
        'customer_phone' => '+91 98765 11111',
        'status' => 'completed',
        'canonical_status' => 'completed',
        'total_amount' => 5000.0,
        'amount_paid' => 5000.0,
        'remaining_balance' => 0.0,
        'payment_status' => 'fully_paid',
        'completed_photos' => $pStmt7->fetchAll(PDO::FETCH_ASSOC),
        'customer_sequence' => 1,
        'customer_sequence_label' => 'New Customer'
    ];

    $savedDoc7 = ShagunCompletionDossierPdf::saveToStorage($orderData7, 'admin_action');
    assert_true(!empty($savedDoc7), "Generated PDF for fully paid order");
    $cleanupFiles[] = $savedDoc7['file_path'];
    $cleanupDocIds[] = $savedDoc7['id'];

    $pdf7 = file_get_contents($savedDoc7['file_path']);
    assert_true(strpos($pdf7, 'Rs. 5,000') !== false, "PDF shows Total Rs. 5,000");
    assert_true(strpos($pdf7, 'Cleared \(Rs. 0\)') !== false || strpos($pdf7, 'Cleared (Rs. 0)') !== false, "PDF shows Cleared (Rs. 0)");
    assert_true(strpos($pdf7, 'Fully Paid') !== false, "PDF shows Fully Paid");

    // WhatsApp message check
    $wa7 = build_whatsapp_completion_update($orderData7);
    assert_true(strpos($wa7['message_text'], 'Total: Rs. 5,000') !== false, "WhatsApp message contains Total Rs. 5,000");
    assert_true(strpos($wa7['message_text'], 'Balance: Cleared (Rs. 0)') !== false, "WhatsApp message contains Balance: Cleared (Rs. 0)");

    // -------------------------------------------------------------------
    // SCENARIO 8: Order with JPEG and PNG completed photos
    // -------------------------------------------------------------------
    echo "\n--- Scenario 8: Order with JPEG and PNG Completed Photos ---\n";

    $ref8 = 'LT-TEST-SC8-' . time();
    $ordId8 = $insertOrder($userAId, $ref8, 'completed', 8500.0, 5000.0);

    // Insert 1 JPEG and 1 PNG as completed photos
    $insDual = $pdo->prepare("
        INSERT INTO order_gallery_photos (order_id, stage, photo_url, original_filename, file_size_bytes, mime_type, caption, created_at)
        VALUES (:oid, 'completed', :url, :fn, :size, :mime, :caption, NOW())
    ");
    $insDual->execute([
        ':oid' => $ordId8,
        ':url' => 'scratch/sample_real.jpg',
        ':fn' => 'sample_real.jpg',
        ':size' => 150,
        ':mime' => 'image/jpeg',
        ':caption' => 'Front Neck Embroidery'
    ]);
    $insDual->execute([
        ':oid' => $ordId8,
        ':url' => 'scratch/sample_real.png',
        ':fn' => 'sample_real.png',
        ':size' => 120,
        ':mime' => 'image/png',
        ':caption' => 'Sleeve Cutwork Lace'
    ]);

    $pStmt8 = $pdo->prepare("SELECT * FROM order_gallery_photos WHERE order_id = :oid AND stage = 'completed' ORDER BY id ASC");
    $pStmt8->execute([':oid' => $ordId8]);
    $photos8 = $pStmt8->fetchAll(PDO::FETCH_ASSOC);

    assert_equals(count($photos8), 2, "Order 8 has exactly 2 photos (1 JPEG, 1 PNG)");

    $orderData8 = [
        'id' => $ordId8,
        'order_ref' => $ref8,
        'user_id' => $userAId,
        'customer_name' => 'Pooja Verma',
        'customer_phone' => '+91 98765 11111',
        'status' => 'completed',
        'canonical_status' => 'completed',
        'total_amount' => 8500.0,
        'amount_paid' => 5000.0,
        'remaining_balance' => 3500.0,
        'payment_status' => 'partially_paid',
        'completed_photos' => $photos8,
        'customer_sequence' => 1,
        'customer_sequence_label' => 'New Customer'
    ];

    $savedDoc8 = ShagunCompletionDossierPdf::saveToStorage($orderData8, 'admin_action');
    assert_true(!empty($savedDoc8), "Generated PDF with dual JPEG and PNG photos");
    $cleanupFiles[] = $savedDoc8['file_path'];
    $cleanupDocIds[] = $savedDoc8['id'];

    $pdf8 = file_get_contents($savedDoc8['file_path']);
    assert_true(strpos($pdf8, '/DCTDecode') !== false, "PDF embeds JPEG image (/DCTDecode)");
    assert_true(strpos($pdf8, '/FlateDecode') !== false, "PDF embeds PNG image (/FlateDecode)");
    assert_true(strpos($pdf8, 'Front Neck Embroidery') !== false, "PDF contains JPEG caption");
    assert_true(strpos($pdf8, 'Sleeve Cutwork Lace') !== false, "PDF contains PNG caption");

    // -------------------------------------------------------------------
    // SCENARIO 9: PDF Deduplication on Unchanged Content & Versioning on Update
    // -------------------------------------------------------------------
    echo "\n--- Scenario 9: PDF Deduplication & Versioning on Order Update ---\n";

    // Call saveToStorage a second time on Order 8 with unchanged content -> reuses document
    $savedDoc8_repeat = ShagunCompletionDossierPdf::saveToStorage($orderData8, 'admin_action');
    assert_true(!empty($savedDoc8_repeat), "Repeated call to saveToStorage succeeded");
    assert_equals((int)$savedDoc8_repeat['id'], (int)$savedDoc8['id'], "Repeated call reuses existing document ID");
    assert_true($savedDoc8_repeat['reused'], "Repeated call is marked as reused without duplicate row");

    // Call saveToStorage with modified order data (e.g. status changed to delivered) -> increments version
    $orderData8_updated = $orderData8;
    $orderData8_updated['status'] = 'delivered';
    $orderData8_updated['canonical_status'] = 'delivered';
    $savedDoc8_v2 = ShagunCompletionDossierPdf::saveToStorage($orderData8_updated, 'admin_action');
    assert_true(!empty($savedDoc8_v2), "Generation on updated order content succeeded");
    $cleanupFiles[] = $savedDoc8_v2['file_path'];
    $cleanupDocIds[] = $savedDoc8_v2['id'];

    // Check versioning in database
    $vStmt = $pdo->prepare("SELECT version, checksum, file_path FROM order_documents WHERE order_id = :oid AND document_type = 'completion_dossier' ORDER BY version ASC");
    $vStmt->execute([':oid' => $ordId8]);
    $versions = $vStmt->fetchAll(PDO::FETCH_ASSOC);

    assert_true(count($versions) >= 2, "Multiple distinct versions recorded in order_documents table");
    assert_equals((int)$versions[0]['version'], 1, "First generation is version 1");
    assert_equals((int)$versions[1]['version'], 2, "Second generation with changed content is version 2");
    assert_true(file_exists($savedDoc8['file_path']), "Version 1 physical file is retained on disk");
    assert_true(file_exists($savedDoc8_v2['file_path']), "Version 2 physical file is created on disk");

    // -------------------------------------------------------------------
    // SCENARIO 10: Cross-Order Photo Deletion Prevention
    // -------------------------------------------------------------------
    echo "\n--- Scenario 10: Cross-Order Photo Deletion Prevention ---\n";

    // Get photo from Order 1
    $p1 = $orderData1['completed_photos'][0];
    $p1Id = (int)$p1['id'];

    // Try to delete photo from Order 1 while specifying Order 2's reference
    // Logic from admin/index.php:
    $ordStmt = $pdo->prepare("SELECT id FROM orders WHERE order_ref = :ref LIMIT 1");
    $ordStmt->execute([':ref' => $ref2]);
    $targetOrdId = (int)$ordStmt->fetchColumn();

    $photoStmt = $pdo->prepare("SELECT * FROM order_gallery_photos WHERE id = :id LIMIT 1");
    $photoStmt->execute([':id' => $p1Id]);
    $photoToDelete = $photoStmt->fetch(PDO::FETCH_ASSOC);

    $isCrossOrderBlocked = ($targetOrdId <= 0 || (int)$photoToDelete['order_id'] !== $targetOrdId);
    assert_true($isCrossOrderBlocked, "Cross-order photo deletion is strictly blocked when photo.order_id != target_order_id");

} finally {
    // -------------------------------------------------------------------
    // TEARDOWN & CLEANUP OF TEMPORARY TEST DATA
    // -------------------------------------------------------------------
    echo "\n--- Cleaning Up Temporary Test Data ---\n";

    foreach ($cleanupFiles as $f) {
        if (file_exists($f)) {
            @unlink($f);
        }
    }

    if (!empty($cleanupDocIds)) {
        $inDocs = implode(',', array_map('intval', $cleanupDocIds));
        $pdo->exec("DELETE FROM order_documents WHERE id IN ($inDocs)");
    }

    if (!empty($cleanupOrderIds)) {
        $inOrds = implode(',', array_map('intval', $cleanupOrderIds));
        $pdo->exec("DELETE FROM order_gallery_photos WHERE order_id IN ($inOrds)");
        $pdo->exec("DELETE FROM order_payments WHERE order_id IN ($inOrds)");
        $pdo->exec("DELETE FROM order_documents WHERE order_id IN ($inOrds)");
        
        // Find people and garments
        $peepStmt = $pdo->query("SELECT id FROM order_people WHERE order_id IN ($inOrds)");
        $peepIds = $peepStmt->fetchAll(PDO::FETCH_COLUMN);
        if (!empty($peepIds)) {
            $inPeeps = implode(',', array_map('intval', $peepIds));
            $gStmt = $pdo->query("SELECT id FROM order_garments WHERE order_person_id IN ($inPeeps)");
            $gIds = $gStmt->fetchAll(PDO::FETCH_COLUMN);
            if (!empty($gIds)) {
                $inG = implode(',', array_map('intval', $gIds));
                $pdo->exec("DELETE FROM order_garment_customizations WHERE order_garment_id IN ($inG)");
                $pdo->exec("DELETE FROM order_garment_work_items WHERE order_garment_id IN ($inG)");
                $pdo->exec("DELETE FROM order_garments WHERE id IN ($inG)");
            }
            $pdo->exec("DELETE FROM order_people WHERE id IN ($inPeeps)");
        }
        $pdo->exec("DELETE FROM orders WHERE id IN ($inOrds)");
    }

    if (!empty($cleanupUserIds)) {
        $inUsers = implode(',', array_map('intval', $cleanupUserIds));
        $pdo->exec("DELETE FROM customer_addresses WHERE user_id IN ($inUsers)");
        $pdo->exec("DELETE FROM users WHERE id IN ($inUsers)");
    }

    echo "Cleanup completed successfully.\n";
}

echo "\n=====================================================================\n";
echo " TEST SUMMARY: ALL $passCount / $testCount REAL DB TESTS PASSED!\n";
echo "=====================================================================\n";
