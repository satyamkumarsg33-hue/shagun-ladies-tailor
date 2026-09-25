<?php
/**
 * Shagun Ladies Tailor — Luxe Stitching Measurements Step
 * 
 * Flow:
 * Wedding Details → People → Garments → Luxe Workspace → Measurements → Review & Payment
 * 
 * Business Logic:
 * - Each person in the order independently chooses: Reference Blouse OR Visit Shop.
 * - NO numeric body measurements are asked or stored.
 * - Reference Blouse and customer fabric/material are explained separately.
 * - Grounded with verified Shagun shop address and phone from homepage.
 * - Partial selection allowed: customers can save anytime and return later.
 * - Save & Continue updates $_SESSION['luxe_wedding']['people'] and returns to luxe-workspace.php.
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/order-status.php';
require_once __DIR__ . '/includes/unified-cart.php';
require_user_login('measurements.php');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$luxe = $_SESSION['luxe_wedding'] ?? [];
$unified = get_unified_basket();
$standardItems = $unified['standard_items'] ?? [];
$hasStandard = $unified['has_standard'] ?? false;

// If Luxe order is already completed and no unpurchased standard items remain, redirect to orders
if (function_exists('is_luxe_order_completed') && is_luxe_order_completed($luxe) && !$hasStandard) {
    $completedRef = $luxe['order_ref'] ?? ($luxe['payment']['order_ref'] ?? '');
    if (!empty($completedRef)) {
        header('Location: orders.php?ref=' . urlencode($completedRef));
    } else {
        header('Location: luxe-stitching.php');
    }
    exit;
}

// An already completed Luxe order cannot have its measurements edited
$people = (function_exists('is_luxe_order_completed') && is_luxe_order_completed($luxe)) ? [] : ($luxe['people'] ?? []);

// Demo mode fallback only when accessed directly with neither people nor standard garments
if ((!is_array($people) || count($people) === 0) && count($standardItems) === 0) {
    $people = [
        [
            'name' => 'Ramya',
            'role' => 'Bride',
            'garments' => [
                ['name' => 'Blouse', 'status' => 'completed', 'style_slug' => 'princess-cut'],
                ['name' => 'Blouse', 'status' => 'not-started']
            ],
            'measurement_method' => 'reference_blouse'
        ],
        [
            'name' => 'Gunjan',
            'role' => 'Sister',
            'garments' => [
                ['name' => 'Blouse', 'status' => 'completed', 'style_slug' => 'u-cut']
            ],
            'measurement_method' => 'visit_shop'
        ],
        [
            'name' => 'Anita',
            'role' => 'Mother',
            'garments' => [
                ['name' => 'Lehenga', 'status' => 'not-started']
            ],
            'measurement_method' => null
        ]
    ];
    $demoMode = true;
} else {
    $demoMode = false;
}

// Check completed garments count (both Luxe and Standard physical garments)
$totalGarments = 0;
$completedGarments = 0;
foreach ($people as $p) {
    if (!isset($p['garments']) || !is_array($p['garments'])) {
        continue;
    }
    foreach ($p['garments'] as $g) {
        $totalGarments++;
        $status = is_array($g) ? ($g['status'] ?? '') : '';
        $isCust = is_array($g) && !empty($g['style_slug']);
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
    }
}

// Standard Stitching items in the unified cart are already selected physical garments
foreach ($standardItems as $sItem) {
    $totalGarments++;
    $completedGarments++;
}

// Guard: if not in demo mode and no garments completed, redirect
if (!$demoMode && $completedGarments < 1) {
    header('Location: ' . (!empty($standardItems) ? 'cart.php' : 'luxe-workspace.php'));
    exit;
}

if (!function_exists('update_standard_garment_measurement')) {
    /**
     * Update measurement method for an existing standard stitching garment in session.
     * Maintains ONE PHYSICAL GARMENT = ONE INDEPENDENT RECORD.
     * Does not create duplicate Luxe garments.
     */
    function update_standard_garment_measurement(string $keyOrId, string $method): void {
        if (!in_array($method, ['reference_blouse', 'visit_shop'], true)) {
            return;
        }

        // 1. Update $_SESSION['demo_cart']
        if (isset($_SESSION['demo_cart']['items']) && is_array($_SESSION['demo_cart']['items'])) {
            foreach ($_SESSION['demo_cart']['items'] as $k => &$item) {
                if ((string)$k === (string)$keyOrId || (string)($item['id'] ?? '') === (string)$keyOrId) {
                    $item['measurement_method'] = $method;
                }
            }
            unset($item);
        }
        if (isset($_SESSION['demo_cart']) && is_array($_SESSION['demo_cart'])) {
            foreach ($_SESSION['demo_cart'] as $k => &$item) {
                if ($k === 'items') continue;
                if (is_array($item)) {
                    if ((string)$k === (string)$keyOrId || (string)($item['id'] ?? '') === (string)$keyOrId) {
                        $item['measurement_method'] = $method;
                    }
                }
            }
            unset($item);
        }

        // 2. Also update $_SESSION['standard_order']['measurement_method']
        if (!isset($_SESSION['standard_order']) || !is_array($_SESSION['standard_order'])) {
            $_SESSION['standard_order'] = [];
        }
        $_SESSION['standard_order']['measurement_method'] = $method;
    }
}

$errorMessage = null;
if (isset($_GET['missing'])) {
    $errorMessage = "1 or more garments need a measurement method. Please select Reference Blouse or Visit Shop below.";
}

// Evaluate measurement completeness per physical garment
$evalBeforePost = validate_order_garments_measurement($people, $standardItems);
$allMeasurementsSelected = $evalBeforePost['all_valid'];
$canProceedReview = ($totalGarments > 0 && $completedGarments === $totalGarments && $allMeasurementsSelected);

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedPersonMethods = $_POST['measurements'] ?? [];
    $submittedGarmentMethods = $_POST['garment_measurements'] ?? [];
    $submittedStandardMethods = $_POST['standard_measurements'] ?? [];

    // Process Standard Stitching Garment measurement methods
    if (is_array($submittedStandardMethods) && !empty($submittedStandardMethods)) {
        foreach ($submittedStandardMethods as $keyOrId => $candidate) {
            update_standard_garment_measurement((string)$keyOrId, trim((string)$candidate));
        }
    }

    if ($demoMode) {
        $_SESSION['luxe_wedding'] = [
            'wedding_date' => '18 Dec 2026',
            'people' => $people
        ];
    }

    if (isset($_SESSION['luxe_wedding']['people']) && is_array($_SESSION['luxe_wedding']['people'])) {
        foreach ($_SESSION['luxe_wedding']['people'] as $pIdx => &$pRecord) {
            $pPersonMethod = null;
            if (isset($submittedPersonMethods[$pIdx])) {
                $candidate = trim((string)$submittedPersonMethods[$pIdx]);
                if (in_array($candidate, ['reference_blouse', 'visit_shop'], true)) {
                    $pPersonMethod = $candidate;
                    $pRecord['measurement_method'] = $candidate;
                }
            }

            if (isset($pRecord['garments']) && is_array($pRecord['garments'])) {
                foreach ($pRecord['garments'] as $gIdx => &$gRecord) {
                    if (isset($submittedGarmentMethods[$pIdx][$gIdx])) {
                        $gCandidate = trim((string)$submittedGarmentMethods[$pIdx][$gIdx]);
                        if (in_array($gCandidate, ['reference_blouse', 'visit_shop'], true)) {
                            $gRecord['measurement_method'] = $gCandidate;
                        }
                    } elseif ($pPersonMethod !== null && empty($gRecord['measurement_method'])) {
                        // Inherit from person method if garment didn't have a distinct choice
                        $gRecord['measurement_method'] = $pPersonMethod;
                    }
                }
                unset($gRecord);
            }
        }
        unset($pRecord);
    }

    // Refresh unified cart and people to evaluate completeness per physical garment
    $unified = get_unified_basket();
    $standardItems = $unified['standard_items'] ?? [];
    $people = $_SESSION['luxe_wedding']['people'] ?? [];
    $validation = validate_order_garments_measurement($people, $standardItems);

    if (!$validation['all_valid']) {
        $missingCount = $validation['missing_count'] ?? count($validation['missing_details']);
        if ($missingCount === 1) {
            $errorMessage = "1 garment needs a measurement method. Please select Reference Blouse or Visit Shop below.";
        } else {
            $errorMessage = "{$missingCount} garments need a measurement method. Please select Reference Blouse or Visit Shop below.";
        }
        $allMeasurementsSelected = false;
        $canProceedReview = false;
    } else {
        $allMeasurementsSelected = true;
        // If all garments complete and all measurements selected, advance to review & payment
        if ($totalGarments > 0 && $completedGarments === $totalGarments) {
            header('Location: review-payment.php');
        } else {
            header('Location: luxe-workspace.php');
        }
        exit;
    }
}

// Verified shop details
$shopName = 'Shagun Ladies Tailor';
$shopAddress = 'Velankanni Road, Electronic City Phase 1, Bengaluru - 560100, Karnataka, India';
$shopPhone = '+91 7019179423';
$mapsUrl = 'https://maps.google.com/?q=Shagun+Ladies+Tailor+Velankanni+Road+Electronic+City+Phase+1+Bengaluru+560100';

// Avatar styling palette
$avatarPalette = [
    ['bg' => '#fce8e6', 'color' => '#a83232'],
    ['bg' => '#e8effc', 'color' => '#325aa8'],
    ['bg' => '#eaf5ea', 'color' => '#2d7a3a'],
    ['bg' => '#fef3e2', 'color' => '#a87020'],
    ['bg' => '#f3e8fc', 'color' => '#7a2da8']
];

include __DIR__ . '/includes/header.php';
?>

<main class="luxe-measurements-page" data-measurements-page>

    <!-- =========================================
         LUXE PROGRESS STEPPER
    ========================================== -->
    <?php
    require_once __DIR__ . '/includes/luxe-stepper.php';
    render_luxe_stepper(5, [
        'can_proceed_review' => $canProceedReview,
    ]);
    ?>

    <!-- =========================================
         PAGE HEADER
    ========================================== -->
    <section class="luxe-measurements-header">
        <div class="luxe-measurements-container">
            <p class="luxe-workspace-eyebrow">LUXE STITCHING</p>
            <h1>Measurements</h1>
            <p class="luxe-measurements-subtitle">
                Choose how you will provide measurements for each person in this order.
            </p>

            <div class="luxe-measurements-notice">
                <span class="luxe-notice-icon">ⓘ</span>
                <p>
                    You can complete this now or come back later. Please select a measurement method for each person before proceeding to Review & Payment.
                </p>
            </div>
        </div>
    </section>

    <!-- =========================================
         PERSON MEASUREMENTS FORM
    ========================================== -->
    <section class="luxe-measurements-content">
        <div class="luxe-measurements-container">
            <?php if (!empty($errorMessage)): ?>
                <div class="luxe-measurements-error-banner" role="alert" style="background: #fffbeb; border: 1px solid #fde68a; color: #92400e; padding: 14px 18px; border-radius: 8px; margin-bottom: 24px; font-size: 14px; line-height: 1.5; display: flex; align-items: center; gap: 12px;">
                    <span style="font-size: 20px; line-height: 1;">⚠️</span>
                    <div><?php echo htmlspecialchars($errorMessage); ?></div>
                </div>
            <?php endif; ?>

            <form method="POST" action="measurements.php" id="measurements-form">

                <!-- =========================================
                     STANDARD STITCHING GARMENTS
                ========================================== -->
                <?php if (!empty($standardItems)): ?>
                    <?php foreach ($standardItems as $sIdx => $sItem): ?>
                        <?php
                        $sGarmentName = trim((string)($sItem['garment_name'] ?? ($sItem['garment'] ?? 'Blouse')));
                        $sStyleName = trim((string)($sItem['style_name'] ?? ($sItem['title'] ?? 'Custom Tailored Blouse')));
                        $sKey = !empty($sItem['id']) ? $sItem['id'] : (string)$sIdx;
                        $sMethod = get_garment_measurement_method($sItem);
                        $sIsSelected = in_array($sMethod, ['reference_blouse', 'visit_shop'], true);
                        $sIsNeeded = !$sIsSelected;
                        $sGarmentNumber = $sIdx + 1;
                        ?>

                        <article class="luxe-measurement-person-card standard-garment-card <?php echo $sIsNeeded ? 'is-needed' : ''; ?>" data-person-card data-standard-card data-standard-index="<?php echo $sIdx; ?>">

                            <!-- Header Row -->
                            <div class="luxe-person-card-top">
                                <div class="luxe-person-meta">
                                    <div class="luxe-person-avatar" style="background-color: #fef3e2; color: #a87020; border: 1.5px solid #fbd38d;">
                                        ✂️
                                    </div>
                                    <div>
                                        <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap; margin-bottom: 2px;">
                                            <h2>Standard <?php echo htmlspecialchars($sGarmentName . ' #' . $sGarmentNumber); ?></h2>
                                            <span class="luxe-badge-pill standard">Standard Stitching</span>
                                            <span class="luxe-badge-pill original">Original Garment</span>
                                        </div>
                                        <span class="luxe-garment-count" style="display: flex; align-items: center; gap: 6px;">
                                            <strong>Style:</strong> <?php echo htmlspecialchars($sStyleName); ?>
                                        </span>
                                    </div>
                                </div>

                                <span class="luxe-measurement-status <?php echo $sIsSelected ? 'is-selected' : 'is-needed'; ?>" data-status-badge>
                                    <span class="badge-text-desktop"><?php echo $sIsSelected ? 'Measurement selected' : 'Measurement needed'; ?></span>
                                    <span class="badge-text-mobile"><?php echo $sIsSelected ? 'Selected' : 'Action needed'; ?></span>
                                </span>
                            </div>

                            <?php if ($sIsNeeded): ?>
                                <div class="luxe-measurement-needed-notice" role="alert">
                                    <span style="font-size: 16px;">⚠️</span>
                                    <div>
                                        <strong>Measurement needed for your Standard Blouse:</strong>
                                        Please choose whether you will provide a fitting Reference Blouse or Visit our shop for measurements.
                                    </div>
                                </div>
                            <?php endif; ?>

                            <!-- Prompt Question -->
                            <h3 class="luxe-person-prompt">
                                How will your Standard <?php echo htmlspecialchars($sGarmentName); ?> measurement be provided?
                            </h3>

                            <!-- Selectable Method Cards -->
                            <div class="luxe-method-grid">

                                <!-- Option 1: Reference Blouse -->
                                <label class="luxe-method-card <?php echo $sMethod === 'reference_blouse' ? 'is-selected' : ''; ?>" data-method-card="reference_blouse">
                                    <input
                                        type="radio"
                                        name="standard_measurements[<?php echo htmlspecialchars($sKey); ?>]"
                                        value="reference_blouse"
                                        <?php echo $sMethod === 'reference_blouse' ? 'checked' : ''; ?>
                                    >
                                    <span class="luxe-method-radio"></span>

                                    <div class="luxe-method-icon">
                                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                            <path d="M20.38 3.46L16 2a4 4 0 0 1-8 0L3.62 3.46a2 2 0 0 0-1.34 2.23l.58 3.47a1 1 0 0 0 .99.84H6v10a2 2 0 0 0 2 2h8a2 2 0 0 0 2-2V10h2.15a1 1 0 0 0 .99-.84l.58-3.47a2 2 0 0 0-1.34-2.23z"/>
                                        </svg>
                                    </div>

                                    <div class="luxe-method-content">
                                        <strong>Reference Blouse</strong>
                                        <p>Bring a blouse that currently fits you well.</p>
                                    </div>
                                </label>

                                <!-- Option 2: Visit Shop -->
                                <label class="luxe-method-card <?php echo $sMethod === 'visit_shop' ? 'is-selected' : ''; ?>" data-method-card="visit_shop">
                                    <input
                                        type="radio"
                                        name="standard_measurements[<?php echo htmlspecialchars($sKey); ?>]"
                                        value="visit_shop"
                                        <?php echo $sMethod === 'visit_shop' ? 'checked' : ''; ?>
                                    >
                                    <span class="luxe-method-radio"></span>

                                    <div class="luxe-method-icon">
                                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                            <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>
                                            <polyline points="9 22 9 12 15 12 15 22"/>
                                        </svg>
                                    </div>

                                    <div class="luxe-method-content">
                                        <strong>Visit Shop</strong>
                                        <p>We'll take the required measurement at Shagun Ladies Tailor.</p>
                                    </div>
                                </label>

                            </div>

                            <!-- Contextual Information Panel -->
                            <div
                                class="luxe-contextual-panel"
                                data-contextual-panel
                                style="<?php echo $sIsSelected ? '' : 'display: none;'; ?>"
                            >

                                <!-- Reference Blouse Details -->
                                <div
                                    class="luxe-panel-content"
                                    data-panel-type="reference_blouse"
                                    style="<?php echo $sMethod === 'reference_blouse' ? '' : 'display: none;'; ?>"
                                >
                                    <div class="luxe-panel-left">
                                        <div class="luxe-panel-heading">
                                            <span class="luxe-panel-icon">📦</span>
                                            <h4>Reference Blouse Selected</h4>
                                        </div>
                                        <p class="luxe-panel-desc">
                                            Please provide one blouse that currently fits you correctly. You can bring it to the shop or send it along with your fabric.
                                        </p>
                                        <p class="luxe-panel-tip">
                                            Can't visit the shop? You can send your reference blouse to us using a courier or parcel service.
                                        </p>
                                    </div>

                                    <div class="luxe-panel-right">
                                        <span class="luxe-panel-meta-title">Send / Bring Materials To:</span>
                                        <strong class="luxe-shop-name"><?php echo htmlspecialchars($shopName); ?></strong>
                                        <address class="luxe-shop-address"><?php echo htmlspecialchars($shopAddress); ?></address>

                                        <div class="luxe-panel-actions">
                                            <a href="<?php echo htmlspecialchars($mapsUrl); ?>" target="_blank" rel="noopener" class="luxe-panel-btn">
                                                <span>📍</span>
                                                <span class="btn-text-desktop">Open in Maps</span>
                                                <span class="btn-text-mobile">Maps</span>
                                            </a>
                                            <button type="button" class="luxe-panel-btn" data-copy-btn data-copy-text="<?php echo htmlspecialchars($shopName . ', ' . $shopAddress); ?>">
                                                <span>📋</span>
                                                <span class="btn-text-desktop">Copy Address</span>
                                                <span class="btn-text-mobile">Copy</span>
                                            </button>
                                            <a href="tel:<?php echo htmlspecialchars($shopPhone); ?>" class="luxe-panel-btn">
                                                <span>📞</span>
                                                <span>Call</span>
                                            </a>
                                        </div>
                                    </div>
                                </div>

                                <!-- Visit Shop Details -->
                                <div
                                    class="luxe-panel-content"
                                    data-panel-type="visit_shop"
                                    style="<?php echo $sMethod === 'visit_shop' ? '' : 'display: none;'; ?>"
                                >
                                    <div class="luxe-panel-left">
                                        <div class="luxe-panel-heading">
                                            <span class="luxe-panel-icon">🏪</span>
                                            <h4>Visit Shop Selected</h4>
                                        </div>
                                        <p class="luxe-panel-desc">
                                            Please visit Shagun Ladies Tailor for measurement. Our master tailors will take your measurements and guide you with the next steps.
                                        </p>
                                    </div>

                                    <div class="luxe-panel-right">
                                        <span class="luxe-panel-meta-title">Our Shop Location</span>
                                        <strong class="luxe-shop-name"><?php echo htmlspecialchars($shopName); ?></strong>
                                        <address class="luxe-shop-address"><?php echo htmlspecialchars($shopAddress); ?></address>

                                        <div class="luxe-panel-actions">
                                            <a href="<?php echo htmlspecialchars($mapsUrl); ?>" target="_blank" rel="noopener" class="luxe-panel-btn">
                                                <span>📍</span>
                                                <span class="btn-text-desktop">Open in Maps</span>
                                                <span class="btn-text-mobile">Maps</span>
                                            </a>
                                            <button type="button" class="luxe-panel-btn" data-copy-btn data-copy-text="<?php echo htmlspecialchars($shopName . ', ' . $shopAddress); ?>">
                                                <span>📋</span>
                                                <span class="btn-text-desktop">Copy Address</span>
                                                <span class="btn-text-mobile">Copy</span>
                                            </button>
                                            <a href="tel:<?php echo htmlspecialchars($shopPhone); ?>" class="luxe-panel-btn">
                                                <span>📞</span>
                                                <span>Call</span>
                                            </a>
                                        </div>
                                    </div>
                                </div>

                            </div>

                        </article>
                    <?php endforeach; ?>
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
                    $currentMethod = $person['measurement_method'] ?? null;
                    $isSelected = in_array($currentMethod, ['reference_blouse', 'visit_shop'], true);

                    $paletteItem = $avatarPalette[$personIndex % count($avatarPalette)];
                    ?>

                    <article class="luxe-measurement-person-card <?php echo $isSelected ? '' : 'is-needed'; ?>" data-person-card data-person-index="<?php echo $personIndex; ?>">

                        <!-- Header Row -->
                        <div class="luxe-person-card-top">
                            <div class="luxe-person-meta">
                                <div class="luxe-person-avatar" style="background-color: <?php echo $paletteItem['bg']; ?>; color: <?php echo $paletteItem['color']; ?>;">
                                    <?php echo htmlspecialchars($personInitial); ?>
                                </div>
                                <div>
                                    <h2><?php echo htmlspecialchars($personName); ?></h2>
                                    <span class="luxe-garment-count"><?php echo htmlspecialchars($garmentText); ?></span>
                                </div>
                            </div>

                            <span class="luxe-measurement-status <?php echo $isSelected ? 'is-selected' : 'is-needed'; ?>" data-status-badge>
                                <span class="badge-text-desktop"><?php echo $isSelected ? 'Measurement selected' : 'Measurement needed'; ?></span>
                                <span class="badge-text-mobile"><?php echo $isSelected ? 'Selected' : 'Action needed'; ?></span>
                            </span>
                        </div>

                        <?php if (!$isSelected): ?>
                            <div class="luxe-measurement-needed-notice" role="alert">
                                <span style="font-size: 16px;">⚠️</span>
                                <div>
                                    <strong>Measurement needed for <?php echo htmlspecialchars($personName); ?>:</strong>
                                    Please choose Reference Blouse or Visit Shop below.
                                </div>
                            </div>
                        <?php endif; ?>

                        <!-- Prompt Question -->
                        <h3 class="luxe-person-prompt">
                            How will <?php echo htmlspecialchars($personName); ?>'s measurement be provided?
                        </h3>

                        <!-- Selectable Method Cards -->
                        <div class="luxe-method-grid">

                            <!-- Option A: Reference Blouse -->
                            <label class="luxe-method-card <?php echo $currentMethod === 'reference_blouse' ? 'is-selected' : ''; ?>" data-method-card="reference_blouse">
                                <input
                                    type="radio"
                                    name="measurements[<?php echo $personIndex; ?>]"
                                    value="reference_blouse"
                                    <?php echo $currentMethod === 'reference_blouse' ? 'checked' : ''; ?>
                                >
                                <span class="luxe-method-radio"></span>

                                <div class="luxe-method-icon">
                                    <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M20.38 3.46L16 2a4 4 0 0 1-8 0L3.62 3.46a2 2 0 0 0-1.34 2.23l.58 3.47a1 1 0 0 0 .99.84H6v10a2 2 0 0 0 2 2h8a2 2 0 0 0 2-2V10h2.15a1 1 0 0 0 .99-.84l.58-3.47a2 2 0 0 0-1.34-2.23z"/>
                                    </svg>
                                </div>

                                <div class="luxe-method-content">
                                    <strong>Reference Blouse</strong>
                                    <p>Provide one blouse that currently fits <?php echo htmlspecialchars($personName); ?> correctly.</p>
                                </div>
                            </label>

                            <!-- Option B: Visit Shop -->
                            <label class="luxe-method-card <?php echo $currentMethod === 'visit_shop' ? 'is-selected' : ''; ?>" data-method-card="visit_shop">
                                <input
                                    type="radio"
                                    name="measurements[<?php echo $personIndex; ?>]"
                                    value="visit_shop"
                                    <?php echo $currentMethod === 'visit_shop' ? 'checked' : ''; ?>
                                >
                                <span class="luxe-method-radio"></span>

                                <div class="luxe-method-icon">
                                    <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>
                                        <polyline points="9 22 9 12 15 12 15 22"/>
                                    </svg>
                                </div>

                                <div class="luxe-method-content">
                                    <strong>Visit Shop</strong>
                                    <p>Visit Shagun Ladies Tailor for measurement at our shop.</p>
                                </div>
                            </label>

                        </div>

                        <!-- Contextual Information Panel -->
                        <div
                            class="luxe-contextual-panel"
                            data-contextual-panel
                            style="<?php echo $isSelected ? '' : 'display: none;'; ?>"
                        >

                            <!-- Reference Blouse Details -->
                            <div
                                class="luxe-panel-content"
                                data-panel-type="reference_blouse"
                                style="<?php echo $currentMethod === 'reference_blouse' ? '' : 'display: none;'; ?>"
                            >
                                <div class="luxe-panel-left">
                                    <div class="luxe-panel-heading">
                                        <span class="luxe-panel-icon">📦</span>
                                        <h4>Reference Blouse Selected</h4>
                                    </div>
                                    <p class="luxe-panel-desc">
                                        Please provide one blouse that fits <?php echo htmlspecialchars($personName); ?> correctly. You can bring it to the shop or send it along with your customer-provided fabric/material.
                                    </p>
                                    <p class="luxe-panel-tip">
                                        Can't visit the shop? You can send your reference blouse and/or customer-provided fabric/material to us using a courier or parcel service.
                                    </p>
                                </div>

                                <div class="luxe-panel-right">
                                    <span class="luxe-panel-meta-title">Send / Bring Materials To:</span>
                                    <strong class="luxe-shop-name"><?php echo htmlspecialchars($shopName); ?></strong>
                                    <address class="luxe-shop-address"><?php echo htmlspecialchars($shopAddress); ?></address>

                                    <div class="luxe-panel-actions">
                                        <a href="<?php echo htmlspecialchars($mapsUrl); ?>" target="_blank" rel="noopener" class="luxe-panel-btn">
                                            <span>📍</span>
                                            <span class="btn-text-desktop">Open in Maps</span>
                                            <span class="btn-text-mobile">Maps</span>
                                        </a>
                                        <button type="button" class="luxe-panel-btn" data-copy-btn data-copy-text="<?php echo htmlspecialchars($shopName . ', ' . $shopAddress); ?>">
                                            <span>📋</span>
                                            <span class="btn-text-desktop">Copy Address</span>
                                            <span class="btn-text-mobile">Copy</span>
                                        </button>
                                        <a href="tel:<?php echo htmlspecialchars($shopPhone); ?>" class="luxe-panel-btn">
                                            <span>📞</span>
                                            <span>Call</span>
                                        </a>
                                    </div>
                                </div>
                            </div>

                            <!-- Visit Shop Details -->
                            <div
                                class="luxe-panel-content"
                                data-panel-type="visit_shop"
                                style="<?php echo $currentMethod === 'visit_shop' ? '' : 'display: none;'; ?>"
                            >
                                <div class="luxe-panel-left">
                                    <div class="luxe-panel-heading">
                                        <span class="luxe-panel-icon">🏪</span>
                                        <h4>Visit Shop Selected</h4>
                                    </div>
                                    <p class="luxe-panel-desc">
                                        Please visit Shagun Ladies Tailor for measurement. Our team will take the measurements and guide you with the next steps.
                                    </p>
                                </div>

                                <div class="luxe-panel-right">
                                    <span class="luxe-panel-meta-title">Our Shop Location</span>
                                    <strong class="luxe-shop-name"><?php echo htmlspecialchars($shopName); ?></strong>
                                    <address class="luxe-shop-address"><?php echo htmlspecialchars($shopAddress); ?></address>

                                    <div class="luxe-panel-actions">
                                        <a href="<?php echo htmlspecialchars($mapsUrl); ?>" target="_blank" rel="noopener" class="luxe-panel-btn">
                                            <span>📍</span>
                                            <span class="btn-text-desktop">Open in Maps</span>
                                            <span class="btn-text-mobile">Maps</span>
                                        </a>
                                        <button type="button" class="luxe-panel-btn" data-copy-btn data-copy-text="<?php echo htmlspecialchars($shopName . ', ' . $shopAddress); ?>">
                                            <span>📋</span>
                                            <span class="btn-text-desktop">Copy Address</span>
                                            <span class="btn-text-mobile">Copy</span>
                                        </button>
                                        <a href="tel:<?php echo htmlspecialchars($shopPhone); ?>" class="luxe-panel-btn">
                                            <span>📞</span>
                                            <span>Call</span>
                                        </a>
                                    </div>
                                </div>
                            </div>

                        </div>

                        <?php if ($garmentCount > 1 && !empty($person['garments'])): ?>
                            <div class="luxe-garments-measurement-breakdown" style="margin-top: 18px; padding: 14px 16px; background: #faf7f2; border: 1px solid #eee5da; border-radius: 8px;">
                                <h4 style="font-size: 13.5px; font-weight: 600; color: #42382e; margin: 0 0 6px;">
                                    Garment-Specific Measurement Handling
                                </h4>
                                <p style="font-size: 12px; color: #73695e; margin: 0 0 12px;">
                                    Each physical garment can have its own measurement method, or inherit <?php echo htmlspecialchars($personName); ?>'s primary selection above.
                                </p>
                                <div style="display: flex; flex-direction: column; gap: 10px;">
                                    <?php foreach ($person['garments'] as $gIdx => $garment): ?>
                                        <?php
                                        $gName = trim((string)($garment['name'] ?? 'Blouse'));
                                        $gStyle = trim((string)($garment['style_name'] ?? ''));
                                        $gDisplay = $gStyle !== '' ? "{$gName} ({$gStyle})" : $gName;
                                        $gSelectedMethod = $garment['measurement_method'] ?? '';
                                        ?>
                                        <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 8px; padding: 8px 12px; background: #ffffff; border: 1px solid #e5dcce; border-radius: 6px;">
                                            <span style="font-weight: 600; font-size: 13px; color: #2b2622;">
                                                <?php echo htmlspecialchars($gDisplay . ' #' . ($gIdx + 1)); ?>
                                            </span>
                                            <div style="display: flex; gap: 14px; font-size: 12.5px;">
                                                <label style="cursor: pointer; display: flex; align-items: center; gap: 5px;">
                                                    <input
                                                        type="radio"
                                                        name="garment_measurements[<?php echo $personIndex; ?>][<?php echo $gIdx; ?>]"
                                                        value="reference_blouse"
                                                        <?php echo ($gSelectedMethod === 'reference_blouse' || ($gSelectedMethod === '' && $currentMethod === 'reference_blouse')) ? 'checked' : ''; ?>
                                                    >
                                                    Reference Blouse
                                                </label>
                                                <label style="cursor: pointer; display: flex; align-items: center; gap: 5px;">
                                                    <input
                                                        type="radio"
                                                        name="garment_measurements[<?php echo $personIndex; ?>][<?php echo $gIdx; ?>]"
                                                        value="visit_shop"
                                                        <?php echo ($gSelectedMethod === 'visit_shop' || ($gSelectedMethod === '' && $currentMethod === 'visit_shop')) ? 'checked' : ''; ?>
                                                    >
                                                    Visit Shop
                                                </label>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>

                    </article>

                <?php endforeach; ?>

                <!-- Bottom Navigation Actions -->
                <div class="luxe-measurements-bottom">
                    <a href="luxe-workspace.php" class="luxe-workspace-bottom-secondary">
                        ← Back to Luxe Workspace
                    </a>

                    <div class="luxe-measurements-submit-wrap">
                        <button type="submit" class="luxe-measurements-save-btn">
                            Save & Continue →
                        </button>
                        <p class="luxe-measurements-subnote">
                            You can update this information later.
                        </p>
                    </div>
                </div>

            </form>
        </div>
    </section>

</main>

<?php include __DIR__ . '/includes/footer.php'; ?>
