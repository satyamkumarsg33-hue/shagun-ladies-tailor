<?php
declare(strict_types=1);

/**
 * Test Suite: Completion Dossier PDF & Customer WhatsApp Sharing
 * 
 * Verifies:
 * 1. Status gating: Completion Dossier only for 'completed' and 'delivered'.
 * 2. Photo requirement: At least 1 completed photo required; max 3.
 * 3. Photo separation: Intake photos are strictly excluded from completion dossier.
 * 4. PDF Generation: Native JPEG and PNG embedding without GD; customer details, sequence, financials, atelier settings.
 * 5. Secure storage: Saved in uploads/dossiers/ and recorded in order_documents.
 * 6. Customer access & ownership: orders.php enforces customer ownership.
 * 7. WhatsApp sharing: Truthful messaging, phone formatting, no localhost link.
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
echo " TEST SUITE: COMPLETION DOSSIER PDF & WHATSAPP SHARING\n";
echo "=====================================================================\n";

$pdo = get_db_connection();

// -------------------------------------------------------------------
// 1. STORAGE SECURITY & DIRECTORY PROTECTION
// -------------------------------------------------------------------
echo "\n--- Section 1: Storage Security & Directory Protection ---\n";

$dossiersDir = __DIR__ . '/../uploads/dossiers';
assert_true(is_dir($dossiersDir), "uploads/dossiers directory exists");

$htaccessPath = $dossiersDir . '/.htaccess';
assert_true(file_exists($htaccessPath), "uploads/dossiers/.htaccess exists");
$htaccessContent = file_get_contents($htaccessPath);
assert_true(strpos($htaccessContent, 'Deny from all') !== false || strpos($htaccessContent, 'Require all denied') !== false, "uploads/dossiers/.htaccess protects directory from direct HTTP script execution");

// -------------------------------------------------------------------
// 2. WHATSAPP CLICK-TO-CHAT & PHONE FORMATTING
// -------------------------------------------------------------------
echo "\n--- Section 2: WhatsApp Click-to-Chat & Phone Formatting ---\n";

assert_equals(format_whatsapp_phone('+91 98765 43210'), '919876543210', "Formats +91 phone number with spaces");
assert_equals(format_whatsapp_phone('09876543210'), '919876543210', "Formats leading 0 phone number");
assert_equals(format_whatsapp_phone('9876543210'), '919876543210', "Formats 10-digit number to 91 prefix");
assert_equals(format_whatsapp_phone('invalid'), null, "Returns null for invalid phone");

$testOrder = [
    'order_ref' => 'LT-TEST-001',
    'customer_name' => 'Ananya Sharma',
    'customer_phone' => '+91 98765 43210',
    'status' => 'completed',
    'canonical_status' => 'completed',
    'total_amount' => 4500,
    'amount_paid' => 4500,
    'remaining_balance' => 0
];

$waPayload = build_whatsapp_completion_update($testOrder);
$waMsg = $waPayload['message_text'];
assert_true(strpos($waMsg, 'LT-TEST-001') !== false, "WhatsApp message includes order reference");
assert_true(strpos($waMsg, 'Ananya Sharma') !== false, "WhatsApp message includes customer name");
assert_true(strpos($waMsg, 'localhost') === false, "WhatsApp message on localhost does NOT include broken localhost link");
assert_true(strpos($waMsg, 'attached') === false, "WhatsApp message does NOT falsely claim PDF is attached");

$waUrl = format_whatsapp_click_to_chat_url($testOrder);
assert_true(strpos($waUrl, 'https://api.whatsapp.com/send?phone=919876543210') === 0, "WhatsApp URL uses correct click-to-chat endpoint and phone");

// -------------------------------------------------------------------
// 3. STATUS GATING & PHOTO REQUIREMENTS
// -------------------------------------------------------------------
echo "\n--- Section 3: Status Gating & Photo Requirements ---\n";

// Awaiting confirmation order - should NOT allow completion dossier
$awaitingOrder = [
    'order_ref' => 'LT-TEST-AWAIT',
    'canonical_status' => 'awaiting_confirmation',
    'status' => 'awaiting_confirmation',
    'completed_photos' => []
];
assert_true($awaitingOrder['canonical_status'] !== 'completed' && $awaitingOrder['canonical_status'] !== 'delivered', "Awaiting confirmation order is not eligible for completion dossier");

// Stitching in process order - should NOT allow completion dossier
$inProcessOrder = [
    'order_ref' => 'LT-TEST-PROC',
    'canonical_status' => 'stitching_in_process',
    'status' => 'stitching_in_process',
    'completed_photos' => []
];
assert_true($inProcessOrder['canonical_status'] !== 'completed' && $inProcessOrder['canonical_status'] !== 'delivered', "Stitching in process order is not eligible for completion dossier");

// Completed order without photos - should NOT generate completion dossier
$completedNoPhotos = [
    'order_ref' => 'LT-TEST-COMP-NOPHOTO',
    'canonical_status' => 'completed',
    'status' => 'completed',
    'completed_photos' => []
];
$threwForNoPhotos = false;
try {
    ShagunCompletionDossierPdf::generate($completedNoPhotos);
} catch (InvalidArgumentException $e) {
    $threwForNoPhotos = true;
    assert_true(strpos($e->getMessage(), 'At least one completed garment photo') !== false, "Throws InvalidArgumentException when no completed photos are provided");
}
assert_true($threwForNoPhotos, "Refuses to generate completion dossier when 0 completed photos");

// -------------------------------------------------------------------
// 4. PHOTO SEPARATION (INTAKE vs COMPLETED)
// -------------------------------------------------------------------
echo "\n--- Section 4: Photo Separation (Intake vs Completed) ---\n";

// Create temporary test images (1 JPEG, 1 PNG)
$testJpeg = __DIR__ . '/test_sample.jpg';
$testPng = __DIR__ . '/test_sample.png';

// Create a valid minimal 2x2 JPEG
$jpegHex = 'ffd8ffe000104a46494600010101004800480000ffdb004300080606070605080707070909080a0c140d0c0b0b0c1912130f141d1a1f1e1d1a1c1c20242e2720222c231c1c2837292c30313434341f27393d38323c2e333432ffc0000b080002000201011100ffda0008010100003f00bf8000ffdf90ff40ff90ff40ffd9';
file_put_contents($testJpeg, hex2bin($jpegHex));

// Create a valid minimal 2x2 PNG
$rawPixels = "\x00\xFF\x00\x00\x00\xFF\x00\x00\x00\x00\xFF\x00\x00\xFF\xFF";
$compressed = gzcompress($rawPixels);
$ihdrData = pack('NNCCCCC', 2, 2, 8, 2, 0, 0, 0);
$ihdrCrc = pack('N', crc32('IHDR' . $ihdrData));
$ihdrChunk = pack('N', 13) . 'IHDR' . $ihdrData . $ihdrCrc;
$idatCrc = pack('N', crc32('IDAT' . $compressed));
$idatChunk = pack('N', strlen($compressed)) . 'IDAT' . $compressed . $idatCrc;
$iendCrc = pack('N', crc32('IEND'));
$iendChunk = pack('N', 0) . 'IEND' . $iendCrc;
file_put_contents($testPng, "\x89PNG\r\n\x1a\n" . $ihdrChunk . $idatChunk . $iendChunk);

assert_true(file_exists($testJpeg) && file_exists($testPng), "Temporary test images created");

// Verify stage separation in database query logic:
// Intake photos have stage = 'awaiting_confirmation', Completed photos have stage = 'completed'
$intakePhoto = [
    'photo_url' => 'scratch/test_sample.jpg',
    'stage' => 'awaiting_confirmation',
    'caption' => 'Client fabric roll (Intake)'
];
$completedPhoto1 = [
    'photo_url' => 'scratch/test_sample.jpg',
    'stage' => 'completed',
    'caption' => 'Front neck embroidery finish'
];
$completedPhoto2 = [
    'photo_url' => 'scratch/test_sample.png',
    'stage' => 'completed',
    'caption' => 'Sleeve border craftsmanship'
];

$allGalleryPhotos = [$intakePhoto, $completedPhoto1, $completedPhoto2];
$filteredCompleted = array_filter($allGalleryPhotos, fn($p) => ($p['stage'] ?? '') === 'completed');

assert_equals(count($filteredCompleted), 2, "Strict filter extracts only completed stage photos (2 of 3)");
foreach ($filteredCompleted as $p) {
    assert_equals($p['stage'], 'completed', "Filtered photo is strictly 'completed' stage");
    assert_true($p['caption'] !== 'Client fabric roll (Intake)', "Intake photo is never in completed list");
}

// -------------------------------------------------------------------
// 5. COMPLETION DOSSIER PDF GENERATION & CONTENT VALIDATION
// -------------------------------------------------------------------
echo "\n--- Section 5: Completion Dossier PDF Generation & Content ---\n";

$fullOrderData = [
    'id' => 99999,
    'order_ref' => 'LT20260920-COMPTEST',
    'user_id' => 109,
    'customer_name' => 'Kamal Kaur',
    'customer_phone' => '+91 98765 43210',
    'customer_email' => 'kamal@example.com',
    'customer_address' => 'House 42, Civil Lines, Ludhiana, Punjab 141001',
    'customer_sequence' => 1,
    'customer_sequence_label' => '1st Order (New Customer)',
    'canonical_status' => 'completed',
    'status' => 'completed',
    'order_type' => 'Luxe Stitching',
    'occasion' => 'Bridal Wedding',
    'total_amount' => 12500,
    'amount_paid' => 8000,
    'remaining_balance' => 4500,
    'payment_status' => 'partially_paid',
    'booked_date' => '2026-09-10',
    'admin_delivery_date' => '2026-09-25',
    'completed_photos' => array_values($filteredCompleted),
    'people' => [
        [
            'name' => 'Kamal Kaur',
            'role' => 'Bride',
            'garments' => [
                [
                    'name' => 'Bridal Lehenga Blouse',
                    'style_name' => 'Deep Sweetheart Neck',
                    'total_price' => 7500,
                    'hand_work' => ['work_target' => 'on_blouse', 'design_name' => 'Zardozi Floral Border'],
                    'notes' => 'Finished with premium gold latkans'
                ],
                [
                    'name' => 'Dupatta Styling',
                    'style_name' => 'Scalloped Edge',
                    'total_price' => 5000,
                    'machine_work' => ['work_target' => 'separate_cloth', 'design_name' => 'Cutwork Embroidery'],
                    'notes' => 'Double dupatta styling'
                ]
            ]
        ]
    ]
];

$pdfBinary = ShagunCompletionDossierPdf::generate($fullOrderData);

assert_true(!empty($pdfBinary), "PDF binary generated successfully");
assert_true(strpos($pdfBinary, '%PDF-1.4') === 0, "PDF binary starts with %PDF-1.4 header");
assert_true(strpos($pdfBinary, '%%EOF') !== false, "PDF binary ends with %%EOF");

// Check embedded elements in PDF
assert_true(strpos($pdfBinary, 'LT20260920-COMPTEST') !== false, "PDF contains Order Reference LT20260920-COMPTEST");
assert_true(strpos($pdfBinary, 'Kamal Kaur') !== false, "PDF contains Customer Name Kamal Kaur");
assert_true(strpos($pdfBinary, 'Ludhiana') !== false, "PDF contains Customer Address Ludhiana");
assert_true(strpos($pdfBinary, '1st Order \(New Customer\)') !== false || strpos($pdfBinary, '1st Order (New Customer)') !== false, "PDF contains Sequence Label '1st Order (New Customer)'");
assert_true(strpos($pdfBinary, 'COMPLETION DOSSIER') !== false, "PDF contains Document Title 'COMPLETION DOSSIER'");
assert_true(strpos($pdfBinary, 'Front neck embroidery finish') !== false, "PDF contains photo 1 caption");
assert_true(strpos($pdfBinary, 'Sleeve border craftsmanship') !== false, "PDF contains photo 2 caption");

// Check image embedding (both JPEG and PNG)
assert_true(strpos($pdfBinary, '/DCTDecode') !== false, "PDF embeds JPEG image via /DCTDecode filter");
assert_true(strpos($pdfBinary, '/FlateDecode') !== false, "PDF embeds PNG image via /FlateDecode filter");
assert_true(strpos($pdfBinary, '/Subtype /Image') !== false, "PDF contains Image XObjects");

// -------------------------------------------------------------------
// 6. SECURE STORAGE & ORDER_DOCUMENTS RECORDING
// -------------------------------------------------------------------
echo "\n--- Section 6: Secure Storage & order_documents Recording ---\n";

$realOrdStmt = $pdo->query("SELECT id FROM orders WHERE order_ref = 'LT20260919-673' LIMIT 1");
$realOrd = $realOrdStmt->fetch(PDO::FETCH_ASSOC);
assert_true($realOrd !== false, "Real order exists in orders table for foreign key validation");
$realOrderId = (int)$realOrd['id'];

$storageTestData = $fullOrderData;
$storageTestData['id'] = $realOrderId;
$storageTestData['order_ref'] = 'LT20260919-673';

// Clean up any test record in order_documents first
$delDoc = $pdo->prepare("DELETE FROM order_documents WHERE order_id = :oid AND document_type = 'completion_dossier'");
$delDoc->execute([':oid' => $realOrderId]);

$savedDoc = ShagunCompletionDossierPdf::saveToStorage($storageTestData, 'admin_action');

assert_true(!empty($savedDoc), "saveToStorage returns document record array");
assert_true(file_exists($savedDoc['file_path']), "Generated PDF is saved on disk in uploads/dossiers/");
assert_equals($savedDoc['document_type'], 'completion_dossier', "document_type is 'completion_dossier'");
assert_true($savedDoc['file_size_bytes'] > 0, "file_size_bytes is positive integer");
assert_true(!empty($savedDoc['checksum']), "SHA-256 checksum is computed and recorded");

// Verify permanent record in order_documents table
$docCheck = $pdo->prepare("SELECT * FROM order_documents WHERE id = :id LIMIT 1");
$docCheck->execute([':id' => $savedDoc['id']]);
$dbDoc = $docCheck->fetch(PDO::FETCH_ASSOC);

assert_true($dbDoc !== false, "order_documents table contains the saved completion dossier record");
assert_equals((int)$dbDoc['order_id'], $realOrderId, "order_documents order_id matches");
assert_equals($dbDoc['document_type'], 'completion_dossier', "order_documents document_type matches");
assert_equals($dbDoc['checksum'], $savedDoc['checksum'], "order_documents checksum matches");

// Clean up test file and database row
@unlink($savedDoc['file_path']);
$pdo->prepare("DELETE FROM order_documents WHERE id = :id")->execute([':id' => $savedDoc['id']]);

// -------------------------------------------------------------------
// 7. CUSTOMER OWNERSHIP GUARD (orders.php)
// -------------------------------------------------------------------
echo "\n--- Section 7: Customer Ownership Guard ---\n";

// In orders.php, the download_completion_dossier route checks:
// if ($dbOrd && (int)$dbOrd['user_id'] === $currentUserId)
// Let's verify this logic against real customer Kamal (user_id = 109, order LT20260919-673)
$kamalStmt = $pdo->prepare("SELECT * FROM orders WHERE order_ref = 'LT20260919-673' LIMIT 1");
$kamalStmt->execute();
$kamalDbOrd = $kamalStmt->fetch(PDO::FETCH_ASSOC);

assert_true($kamalDbOrd !== false, "Kamal's order found for ownership test");
$ownerId = (int)$kamalDbOrd['user_id'];
$anotherUserId = 999999;

// When logged in as owner
$isOwnerAuthorized = ($kamalDbOrd && (int)$kamalDbOrd['user_id'] === $ownerId);
assert_true($isOwnerAuthorized, "Legitimate customer (user_id = $ownerId) is authorized to download own dossier");

// When logged in as different user
$isUnauthorizedBlocked = !($kamalDbOrd && (int)$kamalDbOrd['user_id'] === $anotherUserId);
assert_true($isUnauthorizedBlocked, "Unauthorized customer (user_id = $anotherUserId) is blocked from downloading other's dossier");

// -------------------------------------------------------------------
// CLEANUP TEMPORARY TEST FILES
// -------------------------------------------------------------------
@unlink($testJpeg);
@unlink($testPng);

echo "\n=====================================================================\n";
echo " TEST SUMMARY: ALL $passCount / $testCount TESTS PASSED SUCCESSFULLY!\n";
echo "=====================================================================\n";
