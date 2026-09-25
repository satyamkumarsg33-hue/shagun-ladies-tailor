<?php
/**
 * End-to-end simulation: Standard Stitching Garment entering Luxe Flow
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/order-status.php';
require_once __DIR__ . '/../includes/unified-cart.php';

session_start();

echo "=============================================================\n";
echo "SIMULATING REAL USER FLOW: Standard Blouse entering Luxe Flow\n";
echo "=============================================================\n\n";

// 1. User configures Standard Blouse (Princess Cut Blouse)
$_SESSION['demo_cart'] = [
    'items' => [
        'std_item_1' => [
            'id' => 'std_item_1',
            'garment' => 'Blouse',
            'style_slug' => 'princess-cut',
            'style_name' => 'Princess Cut Blouse',
            'base_price' => 650,
            'price' => 650,
            'image' => 'assets/images/blouse.jpg',
            'measurement_method' => null // not chosen yet
        ]
    ]
];
$_SESSION['standard_order'] = [];
$_SESSION['luxe_wedding'] = [
    'wedding_date' => '2026-12-25',
    'people' => [
        [
            'name' => 'Ramya',
            'role' => 'Bride',
            'measurement_method' => 'visit_shop',
            'garments' => [
                ['name' => 'Blouse', 'status' => 'completed', 'style_slug' => 'princess-cut', 'measurement_method' => 'visit_shop']
            ]
        ]
    ]
];

echo "STEP 1: Basket initial state\n";
$basket = get_unified_basket();
echo " - Standard items count: " . count($basket['standard_items']) . "\n";
echo " - Luxe items count: " . count($basket['luxe_items']) . "\n";
echo " - Total physical garments: " . $basket['total_physical_garment_count'] . "\n";

$validation1 = validate_order_garments_measurement($_SESSION['luxe_wedding']['people'], $basket['standard_items']);
echo " - All measurements valid before selection? " . ($validation1['all_valid'] ? 'YES' : 'NO') . "\n";
echo " - Missing garments count: " . $validation1['missing_count'] . "\n";
echo " - Missing garment label: " . $validation1['missing_details'][0]['label'] . "\n\n";

if ($validation1['all_valid'] !== false || $validation1['missing_count'] !== 1) {
    echo "FAILED: Expected 1 missing measurement for Standard Blouse\n";
    exit(1);
}

// 2. Simulate User selecting 'reference_blouse' for standard garment
echo "STEP 2: User selects 'Reference Blouse' for Standard Blouse #1\n";
update_standard_garment_measurement('std_item_1', 'reference_blouse');

echo ' - $_SESSION["demo_cart"] measurement_method: ' . $_SESSION['demo_cart']['items']['std_item_1']['measurement_method'] . "\n";
echo ' - $_SESSION["standard_order"] measurement_method: ' . $_SESSION['standard_order']['measurement_method'] . "\n";
echo " - Luxe people count: " . count($_SESSION['luxe_wedding']['people']) . " (Strictly preserved, no duplicate person created)\n";
echo " - Luxe garments count: " . count($_SESSION['luxe_wedding']['people'][0]['garments']) . " (Strictly preserved, no duplicate garment created)\n\n";

// 3. Re-evaluate basket
echo "STEP 3: Basket re-evaluation after selection\n";
$basketAfter = get_unified_basket();
$validation2 = validate_order_garments_measurement($_SESSION['luxe_wedding']['people'], $basketAfter['standard_items']);
echo " - All measurements valid after selection? " . ($validation2['all_valid'] ? 'YES' : 'NO') . "\n";
echo " - Missing garments count: " . $validation2['missing_count'] . "\n\n";

if ($validation2['all_valid'] !== true || $validation2['missing_count'] !== 0) {
    echo "FAILED: Expected 0 missing measurements after selection\n";
    exit(1);
}

// 4. Test rendering of measurements.php content
echo "STEP 4: Rendering measurements.php standard card verification\n";
// Set up dummy logged in user for auth guard
$_SESSION['user_id'] = 162;
$_SESSION['user_phone'] = '6394763201';
$_SESSION['user_name'] = 'bhavana';

// Capture output of measurements.php
ob_start();
// simulate GET request
$_SERVER['REQUEST_METHOD'] = 'GET';
include __DIR__ . '/../measurements.php';
$htmlOutput = ob_get_clean();

$checks = [
    'Standard Blouse #1' => str_contains($htmlOutput, 'Standard Blouse #1'),
    'Style: Princess Cut Blouse' => str_contains($htmlOutput, 'Princess Cut Blouse'),
    'Original Garment badge' => str_contains($htmlOutput, 'Original Garment'),
    'Standard Stitching badge' => str_contains($htmlOutput, 'Standard Stitching'),
    'Reference Blouse label' => str_contains($htmlOutput, 'Reference Blouse'),
    'Bring a blouse instruction' => str_contains($htmlOutput, 'Bring a blouse that currently fits you well.'),
    'Visit Shop label' => str_contains($htmlOutput, 'Visit Shop'),
    'We will take measurement instruction' => str_contains($htmlOutput, "We'll take the required measurement at Shagun Ladies Tailor."),
    'Radio input standard_measurements' => str_contains($htmlOutput, 'name="standard_measurements[std_item_1]"'),
    'Zero numeric inputs' => !str_contains($htmlOutput, 'type="number"')
];

$allChecksPassed = true;
foreach ($checks as $name => $passed) {
    echo " - Check '{$name}': " . ($passed ? 'PASS' : 'FAIL') . "\n";
    if (!$passed) $allChecksPassed = false;
}

if (!$allChecksPassed) {
    echo "FAILED: Some HTML output checks failed\n";
    exit(1);
}

echo "\n=============================================================\n";
echo "ALL SIMULATION TESTS PASSED SUCCESSFULLY!\n";
echo "=============================================================\n";
