<?php
/**
 * Shagun Ladies Tailor — Luxe Stitching Review & Payment Step
 * 
 * Flow:
 * Wedding Details → People → Garments → Luxe Workspace → Measurements → Review & Payment
 * 
 * Checkpoint Business Rules:
 * - Review & Payment is the final checkpoint before advance payment confirmation.
 * - Access is strictly guarded: all physical garments must be completed, and each person must have a selected measurement method.
 * - Duplicate physical garments are never combined ("Blouse #1", "Blouse #2" remain independent).
 * - ₹0 customization rows remain hidden.
 * - Reuses existing math: Garment Total = Base + Customization + Work; Person Total = sum of garments; Grand Total = sum of people.
 * - Customers can choose ANY advance payment amount from Minimum Advance (30%) up to Full Order Amount (100%).
 * - Mandatory declaration checkbox starts unchecked and enables the payment button once checked.
 * - Real payment gateway is not integrated yet; prepares order and saves selected advance amount.
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/unified-cart.php';
require_user_login('review-payment.php');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$luxe = $_SESSION['luxe_wedding'] ?? [];

// If the customer has Standard items in the cart and NO active Luxe draft,
// route them to the Standard Stitching checkout flow.
$hasActiveLuxe = function_exists('has_active_luxe_draft') ? has_active_luxe_draft() : false;
$hasStandardInCart = function_exists('demo_cart_items') && !empty(demo_cart_items());

if (!$hasActiveLuxe && $hasStandardInCart) {
    header('Location: checkout.php');
    exit;
}

// If session holds an already completed order, redirect to orders page
if (function_exists('is_luxe_order_completed') && is_luxe_order_completed($luxe)) {
    $completedRef = $luxe['order_ref'] ?? ($luxe['payment']['order_ref'] ?? '');
    if (!empty($completedRef)) {
        header('Location: orders.php?ref=' . urlencode($completedRef));
    } else {
        header('Location: luxe-stitching.php');
    }
    exit;
}

$people = $luxe['people'] ?? [];

// Demo mode fallback when accessed directly
if (!is_array($people) || count($people) === 0) {
    $people = [
        [
            'name' => 'Ramya',
            'role' => 'Bride',
            'measurement_method' => 'reference_blouse',
            'garments' => [
                [
                    'name' => 'Blouse',
                    'status' => 'completed',
                    'style_slug' => 'u-cut',
                    'style_name' => 'U-Cut Blouse',
                    'base_price' => 650,
                    'customization_total' => 290,
                    'work_total' => 350,
                    'total_price' => 1290,
                    'work_type' => 'machine',
                    'machine_work' => [
                        'design_code' => 'M-024',
                        'design_name' => 'Bridal Motif',
                        'price' => 350,
                        'placement' => 'Neck'
                    ],
                    'choice_summary' => [
                        ['field' => 'Sleeve style', 'label' => 'Elbow Length', 'price' => 140],
                        ['field' => 'Neck design', 'label' => 'U-Cut', 'price' => 90],
                        ['field' => 'Back design', 'label' => 'Standard', 'price' => 60],
                        ['field' => 'Lining', 'label' => 'Style default', 'price' => 0]
                    ]
                ],
                [
                    'name' => 'Blouse',
                    'status' => 'completed',
                    'style_slug' => 'princess-cut',
                    'style_name' => 'Princess Cut Blouse',
                    'base_price' => 800,
                    'customization_total' => 150,
                    'work_total' => 500,
                    'total_price' => 1450,
                    'work_type' => 'hand',
                    'hand_work' => [
                        'design_code' => 'H-012',
                        'design_name' => 'Zari Floral',
                        'price' => 500,
                        'placement' => 'Neck'
                    ],
                    'choice_summary' => [
                        ['field' => 'Sleeve style', 'label' => 'Half Sleeve', 'price' => 50],
                        ['field' => 'Neck design', 'label' => 'Round', 'price' => 40],
                        ['field' => 'Back design', 'label' => 'Hook', 'price' => 60],
                        ['field' => 'Lining', 'label' => 'Style default', 'price' => 0]
                    ]
                ]
            ]
        ],
        [
            'name' => 'Gunjan',
            'role' => 'Sister',
            'measurement_method' => 'visit_shop',
            'garments' => [
                [
                    'name' => 'Blouse',
                    'status' => 'completed',
                    'style_slug' => 'square-neck',
                    'style_name' => 'Square Neck Blouse',
                    'base_price' => 650,
                    'customization_total' => 190,
                    'work_total' => 450,
                    'total_price' => 1290,
                    'work_type' => 'both',
                    'machine_work' => [
                        'design_code' => 'M-018',
                        'design_name' => 'Paisley Border',
                        'price' => 200,
                        'placement' => 'Border'
                    ],
                    'hand_work' => [
                        'design_code' => 'H-006',
                        'design_name' => 'Aari Work',
                        'price' => 250,
                        'placement' => 'Neck'
                    ],
                    'choice_summary' => [
                        ['field' => 'Sleeve style', 'label' => 'Elbow Length', 'price' => 100],
                        ['field' => 'Neck design', 'label' => 'Square', 'price' => 40],
                        ['field' => 'Back design', 'label' => 'Dori', 'price' => 50]
                    ]
                ],
                [
                    'name' => 'Blouse',
                    'status' => 'completed',
                    'style_slug' => 'v-cut',
                    'style_name' => 'V-Cut Blouse',
                    'base_price' => 700,
                    'customization_total' => 350,
                    'work_total' => 0,
                    'total_price' => 1050,
                    'work_type' => 'no_work',
                    'choice_summary' => [
                        ['field' => 'Sleeve style', 'label' => 'Short Sleeve', 'price' => 80],
                        ['field' => 'Neck design', 'label' => 'V-Cut', 'price' => 70],
                        ['field' => 'Back design', 'label' => 'Deep', 'price' => 100],
                        ['field' => 'Latkan', 'label' => 'Latkan', 'price' => 100]
                    ]
                ]
            ]
        ]
    ];
    $demoMode = true;
} else {
    $demoMode = false;
}

// -------------------------------------------------------------
// CHECKPOINT VALIDATION
// -------------------------------------------------------------
$totalGarments = 0;
$completedGarments = 0;
$allMeasurementsSelected = true;

foreach ($people as $p) {
    if (!isset($p['garments']) || !is_array($p['garments']) || empty($p['garments'])) {
        $totalGarments++;
        $pMethod = $p['measurement_method'] ?? null;
        if (empty($pMethod) || !in_array($pMethod, ['reference_blouse', 'visit_shop'], true)) {
            $allMeasurementsSelected = false;
        }
        continue;
    }

    foreach ($p['garments'] as $g) {
        $totalGarments++;
        $status = is_array($g) ? ($g['status'] ?? '') : '';
        $gName = strtolower(is_array($g) ? ($g['name'] ?? '') : '');
        $isBlouse = ($gName === 'blouse');
        $isCust = $isBlouse ? !empty($g['style_slug']) : true;
        $wType = is_array($g) ? ($g['work_type'] ?? null) : null;
        $isW = false;

        if ($wType === 'no_work') {
            $isW = true;
        } elseif ($wType === 'machine') {
            $isW = !empty($g['machine_work']);
        } elseif ($wType === 'hand') {
            $isW = !empty($g['hand_work']);
        } elseif ($wType === 'both') {
            $isW = !empty($g['machine_work']) && !empty($g['hand_work']);
        }

        if ($status === 'completed' || ($isCust && $isW)) {
            $completedGarments++;
        }

        // Each individual physical garment must have a valid measurement method
        $gMethod = is_array($g) ? ($g['measurement_method'] ?? ($p['measurement_method'] ?? null)) : null;
        if (empty($gMethod) || !in_array($gMethod, ['reference_blouse', 'visit_shop'], true)) {
            $allMeasurementsSelected = false;
        }
    }
}

// -------------------------------------------------------------
// PRICING CALCULATION (UNIFIED CART AWARE) & CHECKPOINT
// -------------------------------------------------------------
$unified = get_unified_basket();
$hasStandard = $unified['has_standard'];
$hasLuxe = $unified['has_luxe'];
$isCombined = $unified['is_combined'];
$categoryBreakdown = $unified['category_breakdown'] ?? [];
$categoryTotals = $unified['category_totals'] ?? [];
$standardItems = $unified['standard_items'];

// Check standard items measurement completeness
if ($hasStandard && !empty($standardItems)) {
    foreach ($standardItems as $sItem) {
        $totalGarments++;
        $completedGarments++;
        $sMethod = $sItem['measurement_method'] ?? ($_SESSION['standard_order']['measurement_method'] ?? null);
        if (empty($sMethod) || !in_array($sMethod, ['reference_blouse', 'visit_shop'], true)) {
            $allMeasurementsSelected = false;
        }
    }
}

// Access guard when not in demo mode
if (!$demoMode) {
    // If any garment is incomplete, redirect to workspace
    if ($totalGarments === 0 || $completedGarments < $totalGarments) {
        header('Location: luxe-workspace.php');
        exit;
    }

    // If any measurement method is missing, redirect to measurements
    if (!$allMeasurementsSelected) {
        header('Location: measurements.php?missing=1');
        exit;
    }
}
$standardItemCount = $unified['standard_item_count'];
$luxeGarmentCount = $unified['luxe_garment_count'];
$luxePeopleCount = count($people);
$totalPhysicalGarments = $unified['total_physical_garment_count'] ?? (count($standardItems) + $luxeGarmentCount);
$occasion = !empty($luxe['occasion']) ? ucfirst($luxe['occasion']) : 'Wedding';

$luxeGrandTotal = 0;
$personTotals = [];

foreach ($people as $personIndex => $person) {
    $pTotal = 0;
    if (isset($person['garments']) && is_array($person['garments'])) {
        foreach ($person['garments'] as $g) {
            $gPrice = isset($g['total_price'])
                ? (int) $g['total_price']
                : (isset($g['price']) ? (int) $g['price'] : 0);
            $pTotal += $gPrice;
        }
    }
    $personTotals[$personIndex] = $pTotal;
    $luxeGrandTotal += $pTotal;
}

$standardTotal = 0;
if ($hasStandard) {
    foreach ($standardItems as $sItem) {
        $standardTotal += (int) ($sItem['total_price'] ?? 0);
    }
}

// Combined Grand Total
$grandTotal = $luxeGrandTotal + $standardTotal;

// Advance Payment configuration
$minAdvancePercent = 30; // 30% minimum advance
$minAdvanceAmount = (int) ceil($grandTotal * ($minAdvancePercent / 100));
$maxAdvanceAmount = $grandTotal;
$initialAdvanceAmount = (int) round($grandTotal * 0.50); // Default to 50% preset
if ($initialAdvanceAmount < $minAdvanceAmount) {
    $initialAdvanceAmount = $minAdvanceAmount;
}

// -------------------------------------------------------------
// POST HANDLING (Order Preparation / Confirmation)
// -------------------------------------------------------------
$orderConfirmed = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $declaration = isset($_POST['declaration']) && $_POST['declaration'] === '1';
    $submittedAdvance = isset($_POST['advance_amount']) ? (int) $_POST['advance_amount'] : $initialAdvanceAmount;

    // Clamp advance amount
    if ($submittedAdvance < $minAdvanceAmount) {
        $submittedAdvance = $minAdvanceAmount;
    }
    if ($submittedAdvance > $maxAdvanceAmount) {
        $submittedAdvance = $maxAdvanceAmount;
    }

    $remainingBalance = $grandTotal - $submittedAdvance;

    if ($declaration) {
        $_SESSION['luxe_wedding']['advance_payment'] = [
            'order_total' => $grandTotal,
            'min_advance_percent' => $minAdvancePercent,
            'min_advance_amount' => $minAdvanceAmount,
            'selected_amount' => $submittedAdvance,
            'remaining_balance' => $remainingBalance,
            'declaration_confirmed' => true,
            'has_standard' => $hasStandard,
            'is_combined' => ($hasStandard && !empty($unified['luxe_items'])),
            'standard_item_count' => count($standardItems),
            'luxe_garment_count' => count($unified['luxe_items'] ?? []),
            'luxe_people_count' => count($people),
            'total_physical_garment_count' => count($unified['all_items'] ?? []),
            'occasion' => !empty($luxe['occasion']) ? ucfirst($luxe['occasion']) : 'Wedding',
            'updated_at' => time()
        ];
        $orderConfirmed = true;
        header('Location: payment.php');
        exit;
    }
}

// Avatar palette
$avatarPalette = [
    ['bg' => '#fce8e6', 'color' => '#a83232'],
    ['bg' => '#e8effc', 'color' => '#325aa8'],
    ['bg' => '#eaf5ea', 'color' => '#2d7a3a'],
    ['bg' => '#fef3e2', 'color' => '#a87020'],
    ['bg' => '#f3e8fc', 'color' => '#7a2da8']
];

include __DIR__ . '/includes/header.php';
?>

<main class="luxe-review-page" data-review-payment-page data-grand-total="<?php echo $grandTotal; ?>" data-min-advance="<?php echo $minAdvanceAmount; ?>" data-min-percent="<?php echo $minAdvancePercent; ?>">

    <!-- =========================================
         LUXE DECLARATION READY TOAST NOTIFICATION
    ========================================== -->
    <div
        id="luxe-toast-notification"
        class="luxe-toast-notification"
        role="status"
        aria-live="polite"
        aria-atomic="true"
        data-luxe-toast
        style="display: none;"
    >
        <div class="luxe-toast-icon-wrap" aria-hidden="true">
            <span class="luxe-toast-icon">✓</span>
        </div>
        <div class="luxe-toast-body">
            <strong class="luxe-toast-title">Ready for Payment</strong>
            <p class="luxe-toast-message">Your selected advance amount of <span id="toast-amount-display">₹<?php echo number_format($initialAdvanceAmount); ?></span> is ready to proceed.</p>
        </div>
        <button
            type="button"
            class="luxe-toast-close"
            id="luxe-toast-close"
            aria-label="Close notification"
        >&times;</button>
    </div>

    <!-- =========================================
         LUXE PROGRESS STEPPER
    ========================================== -->
    <?php
    require_once __DIR__ . '/includes/luxe-stepper.php';
    render_luxe_stepper(6);
    ?>

    <!-- =========================================
         PAGE HEADER
    ========================================== -->
    <section class="luxe-review-header">
        <div class="luxe-review-container">
            <p class="luxe-workspace-eyebrow">LUXE STITCHING</p>
            <h1>Review & Payment</h1>
            <p class="luxe-review-subtitle">
                Review your complete Luxe order before confirming your advance payment.
            </p>

            <div class="luxe-review-notice">
                <span class="luxe-notice-icon">ⓘ</span>
                <p>
                    Please check every person, garment, customization, work requirement, and measurement method before continuing.
                </p>
            </div>
        </div>
    </section>

    <!-- =========================================
         REVIEW CONTENT (TWO COLUMNS)
    ========================================== -->
    <section class="luxe-review-content">
        <div class="luxe-review-container luxe-review-grid">

            <!-- =========================================
                 LEFT COLUMN: ORDER DETAILS BY PERSON
            ========================================== -->
            <div class="luxe-review-main">

                <?php if ($hasStandard && !empty($standardItems)): ?>
                    <article class="luxe-review-person-card standard-stitching-review-card">
                        <div class="luxe-review-person-top">
                            <div class="luxe-review-person-meta">
                                <div class="luxe-review-avatar" style="background-color: #e8effc; color: #325aa8;">
                                    S
                                </div>
                                <div>
                                    <h2>Standard Stitching Items</h2>
                                    <span class="luxe-review-garment-count"><?php echo count($standardItems); ?> <?php echo count($standardItems) === 1 ? 'garment' : 'garments'; ?></span>
                                </div>
                            </div>
                            <div class="luxe-review-method-badge">
                                <div class="luxe-method-badge-info">
                                    <span class="luxe-method-badge-icon">👔</span>
                                    <div>
                                        <span class="luxe-method-badge-label">Tailoring Category</span>
                                        <strong class="luxe-method-badge-value">Standard Stitching</strong>
                                    </div>
                                </div>
                                <a href="cart.php" class="luxe-review-edit-btn">
                                    <span>✎</span> Edit Cart
                                </a>
                            </div>
                            <?php
                            $stdPrimaryMethod = $standardItems[0]['measurement_method'] ?? ($_SESSION['standard_order']['measurement_method'] ?? null);
                            ?>
                            <?php if (!empty($stdPrimaryMethod)): ?>
                                <div class="luxe-review-method-badge">
                                    <div class="luxe-method-badge-info">
                                        <span class="luxe-method-badge-icon"><?php echo $stdPrimaryMethod === 'visit_shop' ? '🏪' : '📦'; ?></span>
                                        <div>
                                            <span class="luxe-method-badge-label">Measurement</span>
                                            <strong class="luxe-method-badge-value"><?php echo $stdPrimaryMethod === 'visit_shop' ? 'Visit Shop' : 'Reference Blouse'; ?></strong>
                                        </div>
                                    </div>
                                    <a href="measurements.php" class="luxe-review-edit-btn">
                                        <span>✎</span> Edit
                                    </a>
                                </div>
                            <?php endif; ?>
                        </div>

                        <p class="luxe-review-method-note">
                            Individual bespoke garments from our standard blouse catalogue.
                        </p>

                        <div class="luxe-review-garments">
                            <?php foreach ($standardItems as $stdIdx => $stdItem): ?>
                                <div class="luxe-review-garment-card">
                                    <div class="luxe-review-garment-thumb">
                                        <img src="<?php echo htmlspecialchars($stdItem['image']); ?>" alt="<?php echo htmlspecialchars($stdItem['style_name']); ?>" style="width: 48px; height: 48px; border-radius: 8px; object-fit: cover;">
                                    </div>
                                    <div class="luxe-review-garment-info">
                                        <div class="luxe-card-badge-row">
                                            <?php foreach ($stdItem['badges'] as $b): ?>
                                                <?php echo render_category_badge($b); ?>
                                            <?php endforeach; ?>
                                        </div>
                                        <div class="luxe-review-garment-title-row">
                                            <strong><?php echo htmlspecialchars($stdItem['item_label']); ?></strong>
                                            <span class="luxe-review-style-tag"><?php echo htmlspecialchars($stdItem['style_name']); ?></span>
                                            <?php if (!empty($stdItem['measurement_method'])): ?>
                                                <span class="luxe-tag-badge measurement" style="display: inline-flex; align-items: center; gap: 4px; padding: 2px 8px; border-radius: 4px; font-size: 11px; font-weight: 600; background: #eaf6ed; color: #257037; border: 1px solid #cbe9d2;">
                                                    <span><?php echo $stdItem['measurement_method'] === 'visit_shop' ? '🏪' : '📦'; ?></span>
                                                    <span><?php echo $stdItem['measurement_method'] === 'visit_shop' ? 'Visit Shop' : 'Reference Blouse'; ?></span>
                                                </span>
                                            <?php endif; ?>
                                        </div>

                                        <?php if (!empty($stdItem['customization_choices'])): ?>
                                            <ul class="luxe-review-choices-list">
                                                <?php foreach ($stdItem['customization_choices'] as $cChoice): ?>
                                                    <li>• <?php echo htmlspecialchars($cChoice['field'] . ': ' . $cChoice['label']); ?></li>
                                                <?php endforeach; ?>
                                            </ul>
                                        <?php else: ?>
                                            <p class="luxe-review-default-note">• Style default options</p>
                                        <?php endif; ?>
                                    </div>

                                    <div class="luxe-review-work-info">
                                        <span class="luxe-review-work-label">Work Type</span>
                                        <?php if ($stdItem['work_type'] === 'machine'): ?>
                                            <div class="luxe-review-work-val">
                                                <span>🧵</span>
                                                <div>
                                                    <strong>Machine Work</strong>
                                                    <small>Target: On My Blouse / Garment</small>
                                                </div>
                                            </div>
                                        <?php elseif ($stdItem['work_type'] === 'hand'): ?>
                                            <div class="luxe-review-work-val">
                                                <span>✨</span>
                                                <div>
                                                    <strong>Hand Work</strong>
                                                    <small>Target: On My Blouse / Garment</small>
                                                </div>
                                            </div>
                                        <?php else: ?>
                                            <div class="luxe-review-work-val">
                                                <span>⭕</span>
                                                <div>
                                                    <strong>No Work</strong>
                                                    <small>Plain Stitching</small>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                        <a href="<?php echo htmlspecialchars($stdItem['edit_url']); ?>" class="luxe-review-edit-garment-btn">
                                            <span>✎</span> Edit
                                        </a>
                                    </div>

                                    <div class="luxe-review-garment-price">
                                        ₹<?php echo number_format($stdItem['total_price']); ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <div class="luxe-review-person-subtotal">
                            <span>Standard Stitching Total</span>
                            <strong>₹<?php echo number_format($standardTotal); ?></strong>
                        </div>
                    </article>
                <?php endif; ?>

                <?php foreach ($people as $personIndex => $person): ?>
                    <?php
                    $personName = trim((string) ($person['name'] ?? ''));
                    if ($personName === '') {
                        $personName = 'Person ' . ($personIndex + 1);
                    }
                    $personInitial = mb_strtoupper(mb_substr($personName, 0, 1));
                    $garmentCount = isset($person['garments']) && is_array($person['garments']) ? count($person['garments']) : 0;
                    $garmentText = $garmentCount === 1 ? '1 garment' : ($garmentCount . ' garments');
                    $mMethod = $person['measurement_method'] ?? 'reference_blouse';
                    $paletteItem = $avatarPalette[$personIndex % count($avatarPalette)];

                    $personNumber = $personIndex + 1;
                    $possessiveName = (substr($personName, -1) === 's') ? $personName . "'" : $personName . "'s";
                    ?>

                    <article class="luxe-review-person-card">

                        <!-- Person Header -->
                        <div class="luxe-review-person-top">
                            <div class="luxe-review-person-meta">
                                <div class="luxe-review-avatar" style="background-color: <?php echo $paletteItem['bg']; ?>; color: <?php echo $paletteItem['color']; ?>;">
                                    <?php echo htmlspecialchars($personInitial); ?>
                                </div>
                                <div>
                                    <h2><?php echo htmlspecialchars($personName); ?></h2>
                                    <span class="luxe-review-garment-count"><?php echo htmlspecialchars($garmentText); ?></span>
                                </div>
                            </div>

                            <!-- Measurement Method Tag & Edit Link -->
                            <div class="luxe-review-method-badge">
                                <div class="luxe-method-badge-info">
                                    <span class="luxe-method-badge-icon">
                                        <?php if ($mMethod === 'reference_blouse'): ?>
                                            👔
                                        <?php else: ?>
                                            🏪
                                        <?php endif; ?>
                                    </span>
                                    <div>
                                        <span class="luxe-method-badge-label">Measurement Method</span>
                                        <strong class="luxe-method-badge-value">
                                            <?php echo $mMethod === 'reference_blouse' ? 'Reference Blouse' : 'Visit Shop'; ?>
                                        </strong>
                                    </div>
                                </div>
                                <a href="measurements.php" class="luxe-review-edit-btn">
                                    <span>✎</span> Edit
                                </a>
                            </div>
                        </div>

                        <!-- Measurement Method Note -->
                        <p class="luxe-review-method-note">
                            <?php if ($mMethod === 'reference_blouse'): ?>
                                Customer will provide one correctly fitting blouse as the fitting reference. <strong>Customer-provided fabric/material is separate.</strong>
                            <?php else: ?>
                                Measurement will be taken at Shagun Ladies Tailor.
                            <?php endif; ?>
                        </p>

                        <!-- Physical Garments Stack -->
                        <div class="luxe-review-garments">
                            <?php
                            $typeCounters = [];
                            ?>
                            <?php if (isset($person['garments']) && is_array($person['garments'])): ?>
                                <?php foreach ($person['garments'] as $garmentIndex => $garment): ?>
                                    <?php
                                    $garmentName = $garment['name'] ?? 'Blouse';
                                    $typeCounters[$garmentName] = ($typeCounters[$garmentName] ?? 0) + 1;
                                    $numberedLabel = $garmentName . ' #' . $typeCounters[$garmentName];
                                    $styleSlug = $garment['style_slug'] ?? 'u-cut';
                                    $styleName = $garment['style_name'] ?? ($garmentName . ' (' . $styleSlug . ')');

                                    // Extract clean style title (e.g. "U-Cut" from "U-Cut Blouse")
                                    $styleTag = str_ireplace(' Blouse', '', $styleName);

                                    $garmentPrice = isset($garment['total_price'])
                                        ? (int) $garment['total_price']
                                        : (isset($garment['price']) ? (int) $garment['price'] : 0);

                                    // Work Type
                                    $workType = $garment['work_type'] ?? 'no_work';

                                    // Customization rows (only > 0)
                                    $choiceSummary = $garment['choice_summary'] ?? [];
                                    $nonZeroChoices = [];
                                    if (is_array($choiceSummary)) {
                                        foreach ($choiceSummary as $cs) {
                                            if (isset($cs['price']) && (int) $cs['price'] > 0) {
                                                $nonZeroChoices[] = ($cs['field'] ?? 'Option') . ': ' . ($cs['label'] ?? '');
                                            }
                                        }
                                    }

                                    // Edit URL
                                    $editUrl = 'customize-blouse.php?style=' . urlencode($styleSlug) . '&luxe=1&person=' . $personNumber . '&garment=' . urlencode($garmentName) . '&garment_idx=' . $garmentIndex;
                                    ?>

                                    <div class="luxe-review-garment-card">
                                        <!-- Thumbnail Image / Illustration -->
                                        <div class="luxe-review-garment-thumb">
                                            <svg width="42" height="42" viewBox="0 0 24 24" fill="none" stroke="#7a5528" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
                                                <path d="M20.38 3.46L16 2a4 4 0 0 1-8 0L3.62 3.46a2 2 0 0 0-1.34 2.23l.58 3.47a1 1 0 0 0 .99.84H6v10a2 2 0 0 0 2 2h8a2 2 0 0 0 2-2V10h2.15a1 1 0 0 0 .99-.84l.58-3.47a2 2 0 0 0-1.34-2.23z"/>
                                            </svg>
                                        </div>

                                        <!-- Garment Info & Customizations -->
                                        <div class="luxe-review-garment-info">
                                            <div class="luxe-card-badge-row">
                                                <?php echo render_category_badge('LUXE STITCHING'); ?>
                                                <?php if ($workType === 'machine'): ?>
                                                    <?php echo render_category_badge('MACHINE WORK'); ?>
                                                <?php elseif ($workType === 'hand'): ?>
                                                    <?php echo render_category_badge('HAND WORK'); ?>
                                                <?php elseif ($workType === 'both'): ?>
                                                    <?php echo render_category_badge('MACHINE WORK'); ?>
                                                    <?php echo render_category_badge('HAND WORK'); ?>
                                                <?php endif; ?>
                                                <?php
                                                $mTarget = $garment['machine_work']['work_target'] ?? 'garment';
                                                $hTarget = $garment['hand_work']['work_target'] ?? 'garment';
                                                if ($mTarget === 'separate_material' || $hTarget === 'separate_material'):
                                                ?>
                                                    <?php echo render_category_badge('SEPARATE MATERIAL'); ?>
                                                <?php endif; ?>
                                            </div>

                                            <div class="luxe-review-garment-title-row">
                                                <strong><?php echo htmlspecialchars($numberedLabel); ?></strong>
                                                <span class="luxe-review-style-tag"><?php echo htmlspecialchars($styleTag); ?></span>
                                            </div>

                                            <?php if (count($nonZeroChoices) > 0): ?>
                                                <ul class="luxe-review-choices-list">
                                                    <?php foreach ($nonZeroChoices as $choiceText): ?>
                                                        <li>• <?php echo htmlspecialchars($choiceText); ?></li>
                                                    <?php endforeach; ?>
                                                </ul>
                                            <?php else: ?>
                                                <p class="luxe-review-default-note">• Style default options</p>
                                            <?php endif; ?>
                                        </div>

                                        <!-- Work Type Section -->
                                        <div class="luxe-review-work-info">
                                            <span class="luxe-review-work-label">Work Type</span>

                                            <?php if ($workType === 'machine'): ?>
                                                <div class="luxe-review-work-val">
                                                    <span>🧵</span>
                                                    <div>
                                                        <strong>Machine Work</strong>
                                                        <?php if (!empty($garment['machine_work']['design_code'])): ?>
                                                            <small>Design: <?php echo htmlspecialchars($garment['machine_work']['design_code']); ?> <?php echo !empty($garment['machine_work']['design_name']) ? '— ' . htmlspecialchars($garment['machine_work']['design_name']) : ''; ?></small>
                                                        <?php endif; ?>
                                                        <small class="luxe-work-target-tag">Target: <?php echo $mTarget === 'separate_material' ? 'On Separate Cloth / Blouse' : 'On My Blouse / Garment'; ?></small>
                                                        <?php if (!empty($garment['machine_work']['material_description'])): ?>
                                                            <small class="luxe-mat-desc">Material: <?php echo htmlspecialchars($garment['machine_work']['material_description']); ?></small>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            <?php elseif ($workType === 'hand'): ?>
                                                <div class="luxe-review-work-val">
                                                    <span>✨</span>
                                                    <div>
                                                        <strong>Hand Work</strong>
                                                        <?php if (!empty($garment['hand_work']['design_code'])): ?>
                                                            <small>Design: <?php echo htmlspecialchars($garment['hand_work']['design_code']); ?> <?php echo !empty($garment['hand_work']['design_name']) ? '— ' . htmlspecialchars($garment['hand_work']['design_name']) : ''; ?></small>
                                                        <?php endif; ?>
                                                        <small class="luxe-work-target-tag">Target: <?php echo $hTarget === 'separate_material' ? 'On Separate Cloth / Blouse' : 'On My Blouse / Garment'; ?></small>
                                                        <?php if (!empty($garment['hand_work']['material_description'])): ?>
                                                            <small class="luxe-mat-desc">Material: <?php echo htmlspecialchars($garment['hand_work']['material_description']); ?></small>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            <?php elseif ($workType === 'both'): ?>
                                                <div class="luxe-review-work-val">
                                                    <span>🌟</span>
                                                    <div>
                                                        <strong>Both Hand + Machine</strong>
                                                        <?php if (!empty($garment['machine_work']['design_code'])): ?>
                                                            <small>Machine: <?php echo htmlspecialchars($garment['machine_work']['design_code']); ?> (Target: <?php echo $mTarget === 'separate_material' ? 'Separate Cloth' : 'Garment'; ?>)</small>
                                                        <?php endif; ?>
                                                        <?php if (!empty($garment['hand_work']['design_code'])): ?>
                                                            <small>Hand: <?php echo htmlspecialchars($garment['hand_work']['design_code']); ?> (Target: <?php echo $hTarget === 'separate_material' ? 'Separate Cloth' : 'Garment'; ?>)</small>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            <?php else: ?>
                                                <div class="luxe-review-work-val">
                                                    <span>⭕</span>
                                                    <div>
                                                        <strong>No Work</strong>
                                                        <small>Plain Stitching</small>
                                                    </div>
                                                </div>
                                            <?php endif; ?>

                                            <a href="<?php echo htmlspecialchars($editUrl); ?>" class="luxe-review-edit-garment-btn">
                                                <span>✎</span> Edit Garment
                                            </a>
                                        </div>

                                        <!-- Garment Price -->
                                        <div class="luxe-review-garment-price">
                                            ₹<?php echo number_format($garmentPrice); ?>
                                        </div>
                                    </div>

                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>

                        <!-- Person Total Bar -->
                        <div class="luxe-review-person-subtotal">
                            <span><?php echo htmlspecialchars($possessiveName); ?> Total</span>
                            <strong>₹<?php echo number_format($personTotals[$personIndex] ?? 0); ?></strong>
                        </div>

                    </article>

                <?php endforeach; ?>

                <!-- Back Action -->
                <div class="luxe-review-back-wrap">
                    <a href="measurements.php" class="luxe-workspace-bottom-secondary">
                        ← Back to Measurements
                    </a>
                </div>

            </div>


            <!-- =========================================
                 RIGHT COLUMN: ORDER SUMMARY & ADVANCE
            ========================================== -->
            <aside class="luxe-review-sidebar">

                <form method="POST" action="review-payment.php" id="review-payment-form">

                    <!-- CARD 1: ORDER SUMMARY -->
                    <section class="luxe-sidebar-card">
                        <div class="luxe-sidebar-card-title">
                            <span class="luxe-sidebar-card-icon">📋</span>
                            <h3>Order Summary</h3>
                        </div>

                        <div class="luxe-summary-list">
                            <?php if ($isCombined): ?>
                                <div class="luxe-summary-row" style="display: flex; justify-content: space-between; padding: 6px 0; font-size: 13.5px; border-bottom: 1px solid #f5ede4;">
                                    <span class="luxe-label" style="color: #73695e;">Order Type</span>
                                    <strong class="luxe-value" style="color: #57141f; text-align: right;">1. Standard Stitching<br>2. Luxe Stitching</strong>
                                </div>
                                <div class="luxe-summary-row" style="display: flex; justify-content: space-between; padding: 6px 0; font-size: 13.5px; border-bottom: 1px solid #f5ede4;">
                                    <span class="luxe-label" style="color: #73695e;">Standard Items</span>
                                    <strong class="luxe-value" style="color: #1f1c19;"><?php echo $standardItemCount; ?></strong>
                                </div>
                                <?php if (!empty($occasion)): ?>
                                    <div class="luxe-summary-row" style="display: flex; justify-content: space-between; padding: 6px 0; font-size: 13.5px; border-bottom: 1px solid #f5ede4;">
                                        <span class="luxe-label" style="color: #73695e;">Luxe Occasion</span>
                                        <strong class="luxe-value" style="color: #1f1c19;"><?php echo htmlspecialchars($occasion); ?></strong>
                                    </div>
                                <?php endif; ?>
                                <div class="luxe-summary-row" style="display: flex; justify-content: space-between; padding: 6px 0; font-size: 13.5px; border-bottom: 1px solid #f5ede4;">
                                    <span class="luxe-label" style="color: #73695e;">Luxe People</span>
                                    <strong class="luxe-value" style="color: #1f1c19;"><?php echo $luxePeopleCount; ?></strong>
                                </div>
                                <div class="luxe-summary-row" style="display: flex; justify-content: space-between; padding: 6px 0; font-size: 13.5px; border-bottom: 1px solid #f5ede4;">
                                    <span class="luxe-label" style="color: #73695e;">Luxe Garments</span>
                                    <strong class="luxe-value" style="color: #1f1c19;"><?php echo $luxeGarmentCount; ?></strong>
                                </div>
                                <div class="luxe-summary-row" style="display: flex; justify-content: space-between; padding: 6px 0; font-size: 13.5px; border-bottom: 1px solid #f5ede4;">
                                    <span class="luxe-label" style="color: #73695e;">Total Physical Garments</span>
                                    <strong class="luxe-value" style="color: #1f1c19;"><?php echo $totalPhysicalGarments; ?></strong>
                                </div>
                            <?php elseif ($hasStandard && !$hasLuxe): ?>
                                <div class="luxe-summary-row" style="display: flex; justify-content: space-between; padding: 6px 0; font-size: 13.5px; border-bottom: 1px solid #f5ede4;">
                                    <span class="luxe-label" style="color: #73695e;">Order Type</span>
                                    <strong class="luxe-value" style="color: #57141f;">Standard Stitching</strong>
                                </div>
                                <div class="luxe-summary-row" style="display: flex; justify-content: space-between; padding: 6px 0; font-size: 13.5px; border-bottom: 1px solid #f5ede4;">
                                    <span class="luxe-label" style="color: #73695e;">Standard Items</span>
                                    <strong class="luxe-value" style="color: #1f1c19;"><?php echo $standardItemCount; ?></strong>
                                </div>
                                <div class="luxe-summary-row" style="display: flex; justify-content: space-between; padding: 6px 0; font-size: 13.5px; border-bottom: 1px solid #f5ede4;">
                                    <span class="luxe-label" style="color: #73695e;">Total Physical Garments</span>
                                    <strong class="luxe-value" style="color: #1f1c19;"><?php echo $totalPhysicalGarments; ?></strong>
                                </div>
                            <?php else: ?>
                                <div class="luxe-summary-row" style="display: flex; justify-content: space-between; padding: 6px 0; font-size: 13.5px; border-bottom: 1px solid #f5ede4;">
                                    <span class="luxe-label" style="color: #73695e;">Order Type</span>
                                    <strong class="luxe-value" style="color: #57141f;">Luxe Stitching</strong>
                                </div>
                                <div class="luxe-summary-row" style="display: flex; justify-content: space-between; padding: 6px 0; font-size: 13.5px; border-bottom: 1px solid #f5ede4;">
                                    <span class="luxe-label" style="color: #73695e;">Occasion</span>
                                    <strong class="luxe-value" style="color: #1f1c19;"><?php echo htmlspecialchars($occasion); ?></strong>
                                </div>
                                <div class="luxe-summary-row" style="display: flex; justify-content: space-between; padding: 6px 0; font-size: 13.5px; border-bottom: 1px solid #f5ede4;">
                                    <span class="luxe-label" style="color: #73695e;">Luxe People</span>
                                    <strong class="luxe-value" style="color: #1f1c19;"><?php echo $luxePeopleCount; ?></strong>
                                </div>
                                <div class="luxe-summary-row" style="display: flex; justify-content: space-between; padding: 6px 0; font-size: 13.5px; border-bottom: 1px solid #f5ede4;">
                                    <span class="luxe-label" style="color: #73695e;">Luxe Garments</span>
                                    <strong class="luxe-value" style="color: #1f1c19;"><?php echo $luxeGarmentCount; ?></strong>
                                </div>
                                <div class="luxe-summary-row" style="display: flex; justify-content: space-between; padding: 6px 0; font-size: 13.5px; border-bottom: 1px solid #f5ede4;">
                                    <span class="luxe-label" style="color: #73695e;">Total Physical Garments</span>
                                    <strong class="luxe-value" style="color: #1f1c19;"><?php echo $totalPhysicalGarments; ?></strong>
                                </div>
                            <?php endif; ?>

                            <div style="margin: 10px 0 6px; border-top: 1px dashed #e8ddcf;"></div>

                            <?php if ($hasStandard && !empty($standardItems)): ?>
                                <div class="luxe-summary-item">
                                    <span>Standard Stitching (<?php echo count($standardItems); ?> <?php echo count($standardItems) === 1 ? 'piece' : 'pieces'; ?>)</span>
                                    <strong>₹<?php echo number_format($standardTotal); ?></strong>
                                </div>
                            <?php endif; ?>

                            <?php foreach ($people as $pIndex => $pItem): ?>
                                <?php
                                $pItemName = trim((string) ($pItem['name'] ?? ''));
                                if ($pItemName === '') {
                                    $pItemName = 'Person ' . ($pIndex + 1);
                                }
                                $pGarmentCount = isset($pItem['garments']) && is_array($pItem['garments']) ? count($pItem['garments']) : 0;
                                $gCountText = $pGarmentCount === 1 ? '1 garment' : ($pGarmentCount . ' garments');
                                ?>
                                <div class="luxe-summary-item">
                                    <span><?php echo htmlspecialchars($pItemName); ?> (<?php echo htmlspecialchars($gCountText); ?>)</span>
                                    <strong>₹<?php echo number_format($personTotals[$pIndex] ?? 0); ?></strong>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <div class="luxe-summary-grand-total">
                            <span>Total Order Amount</span>
                            <strong>₹<?php echo number_format($grandTotal); ?></strong>
                        </div>
                    </section>


                    <!-- CARD 1B: CATEGORY BREAKDOWN -->
                    <section class="luxe-sidebar-card category-breakdown-card">
                        <div class="luxe-sidebar-card-title">
                            <span class="luxe-sidebar-card-icon">🏷️</span>
                            <h3>Category Breakdown</h3>
                        </div>

                        <div class="category-breakdown-list">
                            <div class="category-breakdown-row">
                                <span class="shagun-category-badge badge-standard">STANDARD STITCHING</span>
                                <span class="cat-count"><?php echo (int) ($categoryBreakdown['standard_stitching']['count'] ?? 0); ?> items</span>
                                <strong class="cat-amount">₹<?php echo number_format((int) ($categoryBreakdown['standard_stitching']['amount'] ?? 0)); ?></strong>
                            </div>
                            <div class="category-breakdown-row">
                                <span class="shagun-category-badge badge-luxe">LUXE STITCHING</span>
                                <span class="cat-count"><?php echo (int) ($categoryBreakdown['luxe_stitching']['count'] ?? 0); ?> garments</span>
                                <strong class="cat-amount">₹<?php echo number_format((int) ($categoryBreakdown['luxe_stitching']['amount'] ?? 0)); ?></strong>
                            </div>
                            <div class="category-breakdown-row">
                                <span class="shagun-category-badge badge-handwork">HAND WORK</span>
                                <span class="cat-count"><?php echo (int) ($categoryBreakdown['hand_work']['count'] ?? 0); ?> items</span>
                                <strong class="cat-amount">₹<?php echo number_format((int) ($categoryBreakdown['hand_work']['amount'] ?? 0)); ?></strong>
                            </div>
                            <div class="category-breakdown-row">
                                <span class="shagun-category-badge badge-machinework">MACHINE WORK</span>
                                <span class="cat-count"><?php echo (int) ($categoryBreakdown['machine_work']['count'] ?? 0); ?> items</span>
                                <strong class="cat-amount">₹<?php echo number_format((int) ($categoryBreakdown['machine_work']['amount'] ?? 0)); ?></strong>
                            </div>
                        </div>
                    </section>


                    <!-- CARD 2: CHOOSE ADVANCE PAYMENT -->
                    <section class="luxe-sidebar-card">
                        <div class="luxe-sidebar-card-title">
                            <span class="luxe-sidebar-card-icon">👛</span>
                            <h3>Choose Advance Payment</h3>
                        </div>

                        <p class="luxe-advance-intro">
                            You can pay any amount from the minimum advance up to the full order amount.
                        </p>

                        <div class="luxe-advance-limits">
                            <div>
                                <span>Minimum Advance (<?php echo $minAdvancePercent; ?>%)</span>
                                <strong>₹<?php echo number_format($minAdvanceAmount); ?></strong>
                            </div>
                            <div style="text-align:right;">
                                <span>Maximum (100%)</span>
                                <strong>₹<?php echo number_format($maxAdvanceAmount); ?></strong>
                            </div>
                        </div>

                        <!-- Range Slider -->
                        <div class="luxe-slider-wrap">
                            <input
                                type="range"
                                id="advance-slider"
                                class="luxe-advance-slider"
                                min="<?php echo $minAdvanceAmount; ?>"
                                max="<?php echo $maxAdvanceAmount; ?>"
                                step="10"
                                value="<?php echo $initialAdvanceAmount; ?>"
                            >
                            <div class="luxe-slider-labels">
                                <span>₹<?php echo number_format($minAdvanceAmount); ?></span>
                                <span>₹<?php echo number_format($maxAdvanceAmount); ?></span>
                            </div>
                        </div>

                        <!-- Quick Select Presets -->
                        <div class="luxe-quick-select-wrap">
                            <label class="luxe-quick-label">Quick Select</label>
                            <div class="luxe-quick-buttons">
                                <button type="button" class="luxe-quick-btn" data-percent="30">30%</button>
                                <button type="button" class="luxe-quick-btn is-active" data-percent="50">50%</button>
                                <button type="button" class="luxe-quick-btn" data-percent="75">75%</button>
                                <button type="button" class="luxe-quick-btn" data-percent="100">100%</button>
                            </div>
                        </div>

                        <!-- Numeric Amount Field -->
                        <div class="luxe-advance-input-wrap">
                            <label for="advance-input">Your Advance Amount</label>
                            <div class="luxe-input-prefix-box">
                                <span class="currency-symbol">₹</span>
                                <input
                                    type="number"
                                    id="advance-input"
                                    name="advance_amount"
                                    class="luxe-advance-num-input"
                                    min="<?php echo $minAdvanceAmount; ?>"
                                    max="<?php echo $maxAdvanceAmount; ?>"
                                    value="<?php echo $initialAdvanceAmount; ?>"
                                >
                            </div>
                        </div>

                        <!-- Real-time Balance Breakdown -->
                        <div class="luxe-advance-breakdown">
                            <div class="luxe-breakdown-row">
                                <span>You will pay now</span>
                                <strong id="pay-now-val">₹<?php echo number_format($initialAdvanceAmount); ?></strong>
                            </div>
                            <div class="luxe-breakdown-row">
                                <span>Remaining Balance</span>
                                <strong id="remaining-val">₹<?php echo number_format($grandTotal - $initialAdvanceAmount); ?></strong>
                            </div>
                        </div>
                    </section>


                    <!-- CARD 3: MEASUREMENT CONFIRMATION (DECLARATION) -->
                    <section class="luxe-sidebar-card">
                        <div class="luxe-sidebar-card-title">
                            <span class="luxe-sidebar-card-icon">📑</span>
                            <h3>Measurement Confirmation</h3>
                        </div>

                        <p class="luxe-declaration-intro">
                            Please confirm the measurement method for each person in this order.
                        </p>

                        <!-- Person Method Review List -->
                        <div class="luxe-confirmation-people-list">
                            <?php if ($hasStandard && !empty($standardItems)): ?>
                                <div class="luxe-confirm-person-item">
                                    <div class="luxe-confirm-person-left">
                                        <span class="luxe-confirm-avatar" style="background-color: #e8effc; color: #325aa8;">
                                            S
                                        </span>
                                        <strong>Standard Stitching (You)</strong>
                                    </div>
                                    <span class="luxe-confirm-method-name">
                                        Reference Blouse / Shop Visit
                                    </span>
                                </div>
                            <?php endif; ?>

                            <?php foreach ($people as $pIdx => $pObj): ?>
                                <?php
                                $pObjName = trim((string) ($pObj['name'] ?? ''));
                                if ($pObjName === '') {
                                    $pObjName = 'Person ' . ($pIdx + 1);
                                }
                                $pObjInitial = mb_strtoupper(mb_substr($pObjName, 0, 1));
                                $pMethodVal = $pObj['measurement_method'] ?? 'reference_blouse';
                                $paletteI = $avatarPalette[$pIdx % count($avatarPalette)];
                                ?>
                                <div class="luxe-confirm-person-item">
                                    <div class="luxe-confirm-person-left">
                                        <span class="luxe-confirm-avatar" style="background-color: <?php echo $paletteI['bg']; ?>; color: <?php echo $paletteI['color']; ?>;">
                                            <?php echo htmlspecialchars($pObjInitial); ?>
                                        </span>
                                        <strong><?php echo htmlspecialchars($pObjName); ?></strong>
                                    </div>
                                    <span class="luxe-confirm-method-name">
                                        <?php echo $pMethodVal === 'reference_blouse' ? 'Reference Blouse' : 'Visit Shop'; ?>
                                    </span>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <!-- Declaration Checkbox -->
                        <label class="luxe-declaration-label">
                            <input type="checkbox" name="declaration" id="declaration-checkbox" value="1">
                            <span>
                                I confirm that I have selected the correct measurement method for each person in this order. I understand that the selected method will be used for their stitching requirements.
                            </span>
                        </label>

                        <!-- Action Submit Button (Disabled by default) -->
                        <button
                            type="submit"
                            id="confirm-pay-btn"
                            class="luxe-confirm-pay-btn is-disabled"
                            disabled
                        >
                            Confirm & Pay <span id="btn-amount-display">₹<?php echo number_format($initialAdvanceAmount); ?></span> →
                        </button>

                        <p class="luxe-payment-secure-note">
                            <span>🔒</span> You will be redirected to our secure payment page.
                        </p>
                    </section>

                </form>

            </aside>

        </div>
    </section>

</main>

<?php include __DIR__ . '/includes/footer.php'; ?>
