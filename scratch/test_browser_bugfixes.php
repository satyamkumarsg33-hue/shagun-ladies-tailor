<?php
/**
 * Comprehensive Automated Test Suite: Browser Bugfixes Verification
 * Covers:
 * Bug 1: Explore Luxe Stitching Button Padding and Unification
 * Bug 2: Luxe Workflow Skipping Measurement Logic & Multi-garment Isolation
 * Bug 3: Intake Garment Photos Display in Completion Dossier alongside Completed Photos
 * Bug 4: Customer Profile Data Resolution in Completion Dossier (No false "Not provided")
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/order-status.php';
require_once __DIR__ . '/../includes/luxe-stepper.php';
require_once __DIR__ . '/../includes/completion-dossier-pdf.php';
require_once __DIR__ . '/../includes/unified-cart.php';

$passed = 0;
$failed = 0;

function assert_test(bool $condition, string $description, string $details = ''): void {
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "  [PASS] {$description}\n";
    } else {
        $failed++;
        echo "  [FAIL] {$description}" . ($details !== '' ? " -> {$details}" : '') . "\n";
    }
}

echo "=========================================================\n";
echo "TEST SUITE: BROWSER TESTING BUGFIXES VERIFICATION\n";
echo "=========================================================\n\n";

// ============================================================================
// SECTION 1: BUG 1 — BUTTON PADDING & UNIFICATION
// ============================================================================
echo "--- 1. BUG 1: BUTTON PADDING & STYLING UNIFICATION ---\n";

$cssContent = file_get_contents(__DIR__ . '/../assets/css/style.css');
assert_test(str_contains($cssContent, '.luxe-entry-btn'), "CSS defines .luxe-entry-btn rule");
assert_test(str_contains($cssContent, 'padding: 12px 16px !important'), "CSS enforces unified 12px 16px padding on secondary buttons");
assert_test(str_contains($cssContent, 'box-sizing: border-box !important'), "CSS enforces box-sizing: border-box on secondary buttons");
assert_test(str_contains($cssContent, 'border-radius: 9px !important'), "CSS enforces border-radius: 9px on secondary buttons");
assert_test(str_contains($cssContent, "font-family: 'Poppins', sans-serif !important"), "CSS enforces Poppins font-family on secondary buttons");
assert_test(str_contains($cssContent, 'font-weight: 600 !important'), "CSS enforces font-weight: 600 on secondary buttons");
assert_test(str_contains($cssContent, 'text-align: center !important'), "CSS enforces text-align: center on secondary buttons");

$cartContent = file_get_contents(__DIR__ . '/../cart.php');
assert_test(str_contains($cartContent, 'class="demo-secondary-link luxe-entry-btn"'), "cart.php uses shared demo-secondary-link luxe-entry-btn class for Explore Luxe Stitching");


// ============================================================================
// SECTION 2: BUG 2 — LUXE MEASUREMENT WORKFLOW ENFORCEMENT
// ============================================================================
echo "\n--- 2. BUG 2: LUXE MEASUREMENT WORKFLOW ENFORCEMENT ---\n";

// Test 2.1: Single person with 1 garment missing measurement
$peopleIncomplete = [
    [
        'name' => 'Kavitha',
        'role' => 'Bride',
        'measurement_method' => null,
        'garments' => [
            ['name' => 'Blouse', 'status' => 'completed', 'measurement_method' => null]
        ]
    ]
];
$val1 = validate_order_garments_measurement($peopleIncomplete);
assert_test($val1['all_valid'] === false, "Order with missing garment measurement is marked invalid (all_valid = false)");
assert_test($val1['missing_count'] === 1, "Missing count is accurately 1");

$stepper1 = resolve_luxe_stepper_states(5, [
    'people' => $peopleIncomplete,
    'wedding_date' => '2026-12-25',
    'total_garments' => 1,
    'completed_garments' => 1
]);
$step6_1 = null;
foreach ($stepper1 as $s) {
    if ($s['number'] === 6) {
        $step6_1 = $s;
        break;
    }
}
assert_test($step6_1 !== null && $step6_1['state'] === 'locked', "Review & Payment step (Step 6) is strictly LOCKED when measurement is missing");

// Test 2.2: Multi-garment order where Garment 1 has method but Garment 2 does not
$peoplePartial = [
    [
        'name' => 'Pooja',
        'role' => 'Sister',
        'measurement_method' => null,
        'garments' => [
            ['name' => 'Blouse', 'status' => 'completed', 'measurement_method' => 'reference_blouse'],
            ['name' => 'Blouse', 'status' => 'completed', 'measurement_method' => null]
        ]
    ]
];
$val2 = validate_order_garments_measurement($peoplePartial);
assert_test($val2['all_valid'] === false, "Multi-garment order with 1 unselected garment is marked invalid");
assert_test($val2['valid_count'] === 1 && $val2['missing_count'] === 1, "Correct valid/missing counts (1 valid, 1 missing)");

// Test 2.3: Multi-person multi-garment fully satisfied with mixed valid methods
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$_SESSION['demo_cart'] = [];
$_SESSION['standard_order'] = [];

$peopleComplete = [
    [
        'name' => 'Bride Ramya',
        'role' => 'Bride',
        'measurement_method' => 'visit_shop',
        'garments' => [
            ['name' => 'Blouse', 'status' => 'completed', 'measurement_method' => 'visit_shop'],
            ['name' => 'Blouse', 'status' => 'completed', 'measurement_method' => 'reference_blouse'] // distinct override
        ]
    ],
    [
        'name' => 'Aunt Geeta',
        'role' => 'Aunt',
        'measurement_method' => 'reference_blouse',
        'garments' => [
            ['name' => 'Blouse', 'status' => 'completed', 'measurement_method' => 'reference_blouse']
        ]
    ]
];
$val3 = validate_order_garments_measurement($peopleComplete);
assert_test($val3['all_valid'] === true, "Multi-person multi-garment order with complete methods is valid");
assert_test($val3['total_garments'] === 3 && $val3['valid_count'] === 3, "All 3 physical garments correctly validated");

$stepperComplete = resolve_luxe_stepper_states(5, [
    'people' => $peopleComplete,
    'wedding_date' => '2026-12-25'
]);
$step6_c = null;
foreach ($stepperComplete as $s) {
    if ($s['number'] === 6) {
        $step6_c = $s;
        break;
    }
}
assert_test($step6_c !== null && $step6_c['state'] !== 'locked', "Review & Payment step (Step 6) is UNLOCKED when all garments have measurement methods");

// Test 2.4: Standard stitching garment integration
$stdItems = [
    ['garment' => 'Blouse', 'measurement_method' => 'reference_blouse'],
    ['garment' => 'Kurti', 'measurement_method' => null]
];
$valStd = validate_order_garments_measurement([], $stdItems);
assert_test($valStd['all_valid'] === false, "Standard stitching item without measurement method blocks order");
assert_test($valStd['missing_details'][0]['label'] === "Standard — Kurti #2", "Missing detail identifies standard garment correctly");

// Test 2.5: Ensure no numerical measurement fields exist in measurements.php or order-status.php
$measFile = file_get_contents(__DIR__ . '/../measurements.php');
assert_test(!str_contains($measFile, 'type="number"'), "measurements.php contains zero numerical measurement inputs");
assert_test(str_contains($measFile, 'name="garment_measurements['), "measurements.php supports per-garment measurement overrides");
assert_test(str_contains($measFile, 'luxe-measurements-error-banner'), "measurements.php includes dedicated error banner markup");

// Test 2.6: UX Correction — Standard Garment entering Luxe Flow
assert_test(str_contains($measFile, 'standard-garment-card'), "measurements.php renders dedicated standard-garment-card");
assert_test(str_contains($measFile, 'Original Garment'), "measurements.php displays 'Original Garment' badge for standard items");
assert_test(str_contains($measFile, 'Standard Stitching'), "measurements.php displays 'Standard Stitching' badge");
assert_test(str_contains($measFile, '<strong>Style:</strong>'), "measurements.php displays garment style name prominently");
assert_test(str_contains($measFile, 'Bring a blouse that currently fits you well.'), "measurements.php displays clear Reference Blouse instruction");
assert_test(str_contains($measFile, "We'll take the required measurement at Shagun Ladies Tailor."), "measurements.php displays clear Visit Shop instruction");
assert_test(str_contains($measFile, 'name="standard_measurements['), "measurements.php provides direct radio inputs for standard garments");
assert_test(str_contains($measFile, 'update_standard_garment_measurement'), "measurements.php defines update_standard_garment_measurement helper");

// Test 2.7: Session Persistence & Single Record Maintenance
$_SESSION['demo_cart'] = [
    'items' => [
        'std_blouse_1' => [
            'id' => 'std_blouse_1',
            'garment' => 'Blouse',
            'style_name' => 'Princess Cut Blouse',
            'measurement_method' => null
        ]
    ]
];
$_SESSION['luxe_wedding'] = [
    'wedding_date' => '2026-12-25',
    'people' => [] // No duplicate Luxe person
];
$_SESSION['standard_order'] = [];

// Simulate selecting Reference Blouse
update_standard_garment_measurement('std_blouse_1', 'reference_blouse');

assert_test($_SESSION['demo_cart']['items']['std_blouse_1']['measurement_method'] === 'reference_blouse', 'update_standard_garment_measurement updates $_SESSION["demo_cart"] directly');
assert_test($_SESSION['standard_order']['measurement_method'] === 'reference_blouse', 'update_standard_garment_measurement updates $_SESSION["standard_order"]');
assert_test(count($_SESSION['luxe_wedding']['people']) === 0, "ONE PHYSICAL GARMENT = ONE INDEPENDENT RECORD (No duplicate Luxe person created)");

// Unified basket validation after selection
$unifiedAfter = get_unified_basket();
$valAfter = validate_order_garments_measurement($_SESSION['luxe_wedding']['people'], $unifiedAfter['standard_items']);
assert_test($valAfter['all_valid'] === true, "After selecting method for standard garment, order is 100% valid");

// Test 2.8: CSS & JS rules for is-needed and alert
assert_test(str_contains($cssContent, '.luxe-measurement-status.is-needed'), "CSS defines .luxe-measurement-status.is-needed");
assert_test(str_contains($cssContent, '.luxe-measurement-person-card.is-needed'), "CSS defines .luxe-measurement-person-card.is-needed");
assert_test(str_contains($cssContent, '.luxe-measurement-needed-notice'), "CSS defines .luxe-measurement-needed-notice");

$jsContent = file_get_contents(__DIR__ . '/../assets/js/script.js');
assert_test(str_contains($jsContent, "personCard.classList.remove('is-needed')"), "script.js removes is-needed on card when method selected");
assert_test(str_contains($jsContent, "statusBadge.classList.remove('is-needed')"), "script.js removes is-needed on statusBadge when method selected");
assert_test(str_contains($jsContent, "neededNotice.style.display = 'none'"), "script.js hides neededNotice when method selected");


// ============================================================================
// SECTION 3: BUG 3 — INTAKE PHOTOS IN COMPLETION DOSSIER
// ============================================================================
echo "\n--- 3. BUG 3: INTAKE PHOTOS IN COMPLETION DOSSIER ---\n";

$testIntakePhotos = [
    [
        'photo_url' => 'uploads/order_gallery/test_intake_1.png',
        'caption' => 'Customer Received Silk Fabric',
        'stage' => 'awaiting_confirmation'
    ],
    [
        'photo_url' => 'uploads/order_gallery/test_intake_2.png',
        'caption' => 'Reference Sample Blouse',
        'stage' => 'awaiting_confirmation'
    ]
];

$testCompletedPhotos = [
    [
        'photo_url' => 'uploads/order_gallery/test_completed_1.png',
        'caption' => 'Final Finished Blouse Front',
        'stage' => 'completed'
    ]
];

// Test 3.1: Gate requires >= 1 completed photo
$orderWithoutCompletedPhotos = [
    'order_ref' => 'LT-TEST-NOPHOTOS',
    'status' => 'completed',
    'customer_name' => 'Meera',
    'completed_photos' => [],
    'intake_photos' => $testIntakePhotos
];
$exceptionThrown = false;
try {
    ShagunCompletionDossierPdf::generate($orderWithoutCompletedPhotos);
} catch (\InvalidArgumentException $e) {
    $exceptionThrown = true;
    assert_test(str_contains($e->getMessage(), 'completed garment photo is required'), "Dossier throws exception when 0 completed photos provided");
}
assert_test($exceptionThrown, "Generating dossier without completed photos is strictly blocked");

// Test 3.2: Both intake and completed photos rendered in PDF
$orderWithBothPhotos = [
    'order_ref' => 'LT-TEST-BOTHPIC',
    'status' => 'completed',
    'customer_name' => 'Meera',
    'customer_phone' => '+91 9876543210',
    'customer_address' => 'Electronic City Phase 1, Bangalore',
    'completed_photos' => $testCompletedPhotos,
    'intake_photos' => $testIntakePhotos,
    'people' => $peopleComplete
];
$pdfBinary = ShagunCompletionDossierPdf::generate($orderWithBothPhotos);
assert_test(strlen($pdfBinary) > 1000, "Completion Dossier PDF generates successfully (" . strlen($pdfBinary) . " bytes)");
assert_test(str_contains($pdfBinary, 'INTAKE / RECEIVED GARMENT PHOTOS'), "PDF contains 'INTAKE / RECEIVED GARMENT PHOTOS' section header");
assert_test(str_contains($pdfBinary, 'ORIGINAL SPECIMEN PROOF'), "PDF contains 'ORIGINAL SPECIMEN PROOF' badge");
assert_test(str_contains($pdfBinary, 'COMPLETED GARMENT PHOTOGRAPHS'), "PDF contains 'COMPLETED GARMENT PHOTOGRAPHS' section header");
assert_test(str_contains($pdfBinary, 'ATELIER FINISHING PROOF'), "PDF contains 'ATELIER FINISHING PROOF' badge");
assert_test(str_contains($pdfBinary, 'Customer Received Silk Fabric'), "PDF contains intake photo caption");
assert_test(str_contains($pdfBinary, 'Final Finished Blouse Front'), "PDF contains completed photo caption");


// ============================================================================
// SECTION 4: BUG 4 — REAL CUSTOMER DATA RESOLUTION
// ============================================================================
echo "\n--- 4. BUG 4: REAL CUSTOMER DATA RESOLUTION ---\n";

// Test 4.1: get_customer_orders resolves customer profile
$customerOrders = get_customer_orders(162); // Real user bhavana
assert_test(!empty($customerOrders), "get_customer_orders(162) returns orders for real user 162");
$sampleOrder = reset($customerOrders);
assert_test($sampleOrder['customer_name'] === 'bhavana', "sampleOrder['customer_name'] is 'bhavana'");
assert_test(str_contains($sampleOrder['customer_phone'], '63947'), "sampleOrder['customer_phone'] is '+91 63947 63201'");
assert_test(str_contains($sampleOrder['customer_address'], 'Panchayath'), "sampleOrder['customer_address'] contains 'Panchayath office'");

// Test 4.2: Real order 154 (LT20260922-936) dossier generation without 'Not provided'
$order154Data = get_customer_order_by_ref('LT20260922-936', 162);
assert_test($order154Data !== null, "get_customer_order_by_ref found order LT20260922-936");

$pdf154Binary = ShagunCompletionDossierPdf::generate($order154Data);
assert_test(str_contains($pdf154Binary, 'bhavana'), "Order 154 PDF contains customer name 'bhavana'");
assert_test(str_contains($pdf154Binary, '+91 63947 63201'), "Order 154 PDF contains customer phone '+91 63947 63201'");
assert_test(str_contains($pdf154Binary, 'Panchayath office'), "Order 154 PDF contains customer address snippet");

// Test 4.3: Ensure fallback logic works even when user_id is passed but name/phone are omitted from caller
$sparseOrderData = [
    'order_ref' => 'LT20260922-936',
    'status' => 'completed',
    'user_id' => 162
    // omitted customer_name, customer_phone, customer_address
];
$pdfSparseBinary = ShagunCompletionDossierPdf::generate($sparseOrderData);
assert_test(str_contains($pdfSparseBinary, 'bhavana'), "Sparse orderData auto-resolves name 'bhavana' from database user_id");
assert_test(str_contains($pdfSparseBinary, '+91 63947 63201'), "Sparse orderData auto-resolves phone from database user_id");
assert_test(str_contains($pdfSparseBinary, 'Panchayath office'), "Sparse orderData auto-resolves address from database user_id");

// Verify real order gallery photos on order 154 (both intake and completed are in DB)
assert_test(str_contains($pdf154Binary, 'INTAKE / RECEIVED GARMENT PHOTOS'), "Order 154 PDF automatically picked up DB intake photo");
assert_test(str_contains($pdf154Binary, 'COMPLETED GARMENT PHOTOGRAPHS'), "Order 154 PDF automatically picked up DB completed photo");


// ============================================================================
// FINAL SUMMARY
// ============================================================================
echo "\n=========================================================\n";
echo "SUMMARY: Passed: {$passed} | Failed: {$failed}\n";
echo "=========================================================\n";

if ($failed > 0) {
    exit(1);
}
