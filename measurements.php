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
require_user_login('measurements.php');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$luxe = $_SESSION['luxe_wedding'] ?? [];
$people = $luxe['people'] ?? [];

// Demo mode fallback when accessed directly
if (!is_array($people) || count($people) === 0) {
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

// Check completed garments count
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

// Guard: if not in demo mode and no garments completed, redirect to workspace
if (!$demoMode && $completedGarments < 1) {
    header('Location: luxe-workspace.php');
    exit;
}

$allMeasurementsSelected = true;
foreach ($people as $p) {
    $mMethod = $p['measurement_method'] ?? null;
    if (empty($mMethod) || !in_array($mMethod, ['reference_blouse', 'visit_shop'], true)) {
        $allMeasurementsSelected = false;
        break;
    }
}
$canProceedReview = ($totalGarments > 0 && $completedGarments === $totalGarments && $allMeasurementsSelected);

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedMeasurements = $_POST['measurements'] ?? [];

    if ($demoMode) {
        $_SESSION['luxe_wedding'] = [
            'wedding_date' => '18 Dec 2026',
            'people' => $people
        ];
    }

    if (isset($_SESSION['luxe_wedding']['people']) && is_array($_SESSION['luxe_wedding']['people'])) {
        foreach ($_SESSION['luxe_wedding']['people'] as $idx => &$pRecord) {
            if (isset($submittedMeasurements[$idx])) {
                $method = trim((string) $submittedMeasurements[$idx]);
                if (in_array($method, ['reference_blouse', 'visit_shop'], true)) {
                    $pRecord['measurement_method'] = $method;
                }
            }
        }
        unset($pRecord);
    }

    // If all garments complete and all measurements selected, advance to review & payment
    $canProceedToReview = false;
    if ($totalGarments > 0 && $completedGarments === $totalGarments) {
        $allSelected = true;
        if (isset($_SESSION['luxe_wedding']['people']) && is_array($_SESSION['luxe_wedding']['people'])) {
            foreach ($_SESSION['luxe_wedding']['people'] as $pCheck) {
                $m = $pCheck['measurement_method'] ?? null;
                if (empty($m) || !in_array($m, ['reference_blouse', 'visit_shop'], true)) {
                    $allSelected = false;
                    break;
                }
            }
            $canProceedToReview = $allSelected;
        }
    }

    if ($canProceedToReview) {
        header('Location: review-payment.php');
    } else {
        header('Location: luxe-workspace.php');
    }
    exit;
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
            <form method="POST" action="measurements.php" id="measurements-form">

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

                    <article class="luxe-measurement-person-card" data-person-card data-person-index="<?php echo $personIndex; ?>">

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

                            <span class="luxe-measurement-status <?php echo $isSelected ? 'is-selected' : 'is-unselected'; ?>" data-status-badge>
                                <span class="badge-text-desktop"><?php echo $isSelected ? 'Measurement selected' : 'Not selected yet'; ?></span>
                                <span class="badge-text-mobile"><?php echo $isSelected ? 'Selected' : 'Not selected'; ?></span>
                            </span>
                        </div>

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
