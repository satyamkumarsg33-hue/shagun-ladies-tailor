<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_user_login();

session_start();

require_once __DIR__ . '/includes/demo-data.php';

$isLuxe = (isset($_GET['luxe']) && $_GET['luxe'] == '1') || (isset($_POST['luxe']) && $_POST['luxe'] == '1');
$personParam = $_GET['person'] ?? $_POST['person'] ?? null;
$garmentParam = (string) ($_GET['garment'] ?? $_POST['garment'] ?? 'Blouse');
$garmentIdxParam = isset($_GET['garment_idx']) ? (int) $_GET['garment_idx'] : (isset($_POST['garment_idx']) ? (int) $_POST['garment_idx'] : null);

if (!isset($_SESSION['luxe_wedding']) || !is_array($_SESSION['luxe_wedding'])) {
    $_SESSION['luxe_wedding'] = [];
}

// If session holds an already completed order, redirect to orders page
if (function_exists('is_luxe_order_completed') && is_luxe_order_completed($_SESSION['luxe_wedding'])) {
    $completedRef = $_SESSION['luxe_wedding']['order_ref'] ?? ($_SESSION['luxe_wedding']['payment']['order_ref'] ?? '');
    if (!empty($completedRef)) {
        header('Location: orders.php?ref=' . urlencode($completedRef));
    } else {
        header('Location: luxe-stitching.php');
    }
    exit;
}

if (empty($_SESSION['luxe_wedding']['people']) || !is_array($_SESSION['luxe_wedding']['people'])) {
    header('Location: luxe-workspace.php');
    exit;
}

$people = &$_SESSION['luxe_wedding']['people'];

// Resolve person index
$luxePersonIndex = null;
if ($personParam !== null && $personParam !== '') {
    if (is_numeric($personParam)) {
        $num = (int) $personParam;
        if ($num >= 1 && isset($people[$num - 1])) {
            $luxePersonIndex = $num - 1;
        } elseif ($num === 0 && isset($people[0])) {
            $luxePersonIndex = 0;
        } elseif (isset($people[$num])) {
            $luxePersonIndex = $num;
        }
    }
    if ($luxePersonIndex === null) {
        foreach ($people as $pIdx => $p) {
            if (strcasecmp(trim((string) ($p['name'] ?? '')), trim((string) $personParam)) === 0) {
                $luxePersonIndex = $pIdx;
                break;
            }
        }
    }
}
if ($luxePersonIndex === null && !empty($people)) {
    $luxePersonIndex = 0;
}

$person = $people[$luxePersonIndex] ?? null;
if (!$person || !isset($person['garments']) || !is_array($person['garments'])) {
    header('Location: luxe-workspace.php');
    exit;
}

// Resolve garment index
$luxeGarmentIndex = null;
if ($garmentIdxParam !== null && isset($person['garments'][$garmentIdxParam])) {
    $luxeGarmentIndex = $garmentIdxParam;
} else {
    foreach ($person['garments'] as $gIdx => $gItem) {
        $gName = is_array($gItem) ? ($gItem['name'] ?? $gItem['garment'] ?? '') : (string) $gItem;
        if (strcasecmp(trim($gName), trim($garmentParam)) === 0) {
            $luxeGarmentIndex = $gIdx;
            break;
        }
    }
}

if ($luxeGarmentIndex === null || !isset($person['garments'][$luxeGarmentIndex])) {
    header('Location: luxe-workspace.php');
    exit;
}

// Normalize current garment
$garment = $person['garments'][$luxeGarmentIndex];
if (is_string($garment)) {
    $garment = [
        'name' => $garment,
        'status' => 'not-started',
        'summary' => ''
    ];
}

$personName = trim((string) ($person['name'] ?? ''));
if ($personName === '') {
    $personName = 'Person ' . ($luxePersonIndex + 1);
}
$personRole = trim((string) ($person['role'] ?? ''));

$garmentName = (string) ($garment['name'] ?? $garmentParam);

// Compute 1-based index among duplicates of this type
$typeCount = 0;
foreach ($person['garments'] as $idx => $g) {
    $gN = is_array($g) ? ($g['name'] ?? $g['garment'] ?? '') : (string) $g;
    if (strcasecmp(trim($gN), trim($garmentName)) === 0) {
        $typeCount++;
    }
    if ($idx === $luxeGarmentIndex) {
        break;
    }
}
$numberedGarmentLabel = $garmentName . ' #' . $typeCount;
$styleName = $garment['style_name'] ?? ($garmentName . ' (Style Not Selected)');
$basePrice = isset($garment['base_price']) ? (int) $garment['base_price'] : 0;
$customizationTotal = isset($garment['customization_total']) ? (int) $garment['customization_total'] : 0;

$machineDesigns = luxe_machine_work_designs();
$handDesigns = luxe_hand_work_designs();
$placements = luxe_work_placements();

// Helper for checked state
function checked_val($val1, $val2): void {
    if ($val1 === $val2) {
        echo 'checked';
    }
}

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $workType = trim((string) ($_POST['work_type'] ?? 'no_work'));
    if (!in_array($workType, ['no_work', 'machine', 'hand', 'both'], true)) {
        $workType = 'no_work';
    }

    $machineWork = null;
    $handWork = null;
    $machinePrice = 0;
    $handPrice = 0;

    if ($workType === 'machine' || $workType === 'both') {
        $mCode = trim((string) ($_POST['machine_design'] ?? ''));
        $mName = '';
        $mPrice = 0;

        foreach ($machineDesigns as $md) {
            if ($md['code'] === $mCode) {
                $mName = $md['name'];
                $mPrice = (int) $md['price'];
                break;
            }
        }

        if ($mCode === 'custom' || $mName === '') {
            $mCode = 'Custom';
            $mName = trim((string) ($_POST['machine_custom_name'] ?? 'Custom Machine Work'));
            $mPrice = max(0, (int) ($_POST['machine_custom_price'] ?? 350));
        }

        $mPlacement = trim((string) ($_POST['machine_placement'] ?? 'Neck'));
        $mNotes = trim((string) ($_POST['machine_notes'] ?? ''));
        $mTarget = trim((string) ($_POST['machine_work_target'] ?? 'garment'));
        if (!in_array($mTarget, ['garment', 'separate_material'], true)) {
            $mTarget = 'garment';
        }
        $mMatDesc = trim((string) ($_POST['machine_material_description'] ?? ''));

        $machinePrice = $mPrice;
        $machineWork = [
            'design_code' => $mCode,
            'design_name' => $mName,
            'price' => $mPrice,
            'placement' => $mPlacement,
            'notes' => $mNotes,
            'work_target' => $mTarget,
            'material_description' => $mMatDesc,
        ];
    }

    if ($workType === 'hand' || $workType === 'both') {
        $hCode = trim((string) ($_POST['hand_design'] ?? ''));
        $hName = '';
        $hPrice = 0;

        foreach ($handDesigns as $hd) {
            if ($hd['code'] === $hCode) {
                $hName = $hd['name'];
                $hPrice = (int) $hd['price'];
                break;
            }
        }

        if ($hCode === 'custom' || $hName === '') {
            $hCode = 'Custom';
            $hName = trim((string) ($_POST['hand_custom_name'] ?? 'Custom Hand Work'));
            $hPrice = max(0, (int) ($_POST['hand_custom_price'] ?? 800));
        }

        $hPlacement = trim((string) ($_POST['hand_placement'] ?? 'Neck'));
        $hNotes = trim((string) ($_POST['hand_notes'] ?? ''));
        $hTarget = trim((string) ($_POST['hand_work_target'] ?? 'garment'));
        if (!in_array($hTarget, ['garment', 'separate_material'], true)) {
            $hTarget = 'garment';
        }
        $hMatDesc = trim((string) ($_POST['hand_material_description'] ?? ''));

        $handPrice = $hPrice;
        $handWork = [
            'design_code' => $hCode,
            'design_name' => $hName,
            'price' => $hPrice,
            'placement' => $hPlacement,
            'notes' => $hNotes,
            'work_target' => $hTarget,
            'material_description' => $hMatDesc,
        ];
    }

    $workTotal = $machinePrice + $handPrice;
    $totalPrice = $basePrice + $customizationTotal + $workTotal;

    // Check completion status
    $isBlouse = (strtolower((string) $garmentName) === 'blouse');
    $isCustomizationComplete = $isBlouse ? !empty($garment['style_slug']) : true;
    $isWorkComplete = false;
    if ($workType === 'no_work') {
        $isWorkComplete = true;
    } elseif ($workType === 'machine') {
        $isWorkComplete = !empty($machineWork);
    } elseif ($workType === 'hand') {
        $isWorkComplete = !empty($handWork);
    } elseif ($workType === 'both') {
        $isWorkComplete = !empty($machineWork) && !empty($handWork);
    }

    $newStatus = ($isCustomizationComplete && $isWorkComplete) ? 'completed' : 'in-progress';

    // Update physical garment record
    $garment['work_type'] = $workType;
    $garment['work_total'] = $workTotal;
    $garment['machine_work'] = $machineWork;
    $garment['hand_work'] = $handWork;
    $garment['total_price'] = $totalPrice;
    $garment['status'] = $newStatus;
    $garment['updated_at'] = time();

    $people[$luxePersonIndex]['garments'][$luxeGarmentIndex] = $garment;

    header('Location: luxe-workspace.php');
    exit;
}

// Current values for pre-population
$savedWorkType = $garment['work_type'] ?? 'no_work';
$savedMachine = $garment['machine_work'] ?? null;
$savedHand = $garment['hand_work'] ?? null;

$selectedMachineCode = $savedMachine['design_code'] ?? ($machineDesigns[0]['code'] ?? 'M-024');
$selectedMachinePlacement = $savedMachine['placement'] ?? 'Neck';
$selectedMachineNotes = $savedMachine['notes'] ?? '';
$selectedMachineTarget = $savedMachine['work_target'] ?? 'garment';
$selectedMachineMatDesc = $savedMachine['material_description'] ?? '';

$selectedHandCode = $savedHand['design_code'] ?? ($handDesigns[0]['code'] ?? 'H-012');
$selectedHandPlacement = $savedHand['placement'] ?? 'Neck';
$selectedHandNotes = $savedHand['notes'] ?? '';
$selectedHandTarget = $savedHand['work_target'] ?? 'garment';
$selectedHandMatDesc = $savedHand['material_description'] ?? '';

// Handle incoming design selection from "View All" on designs.php
if (isset($_GET['select_machine']) && $_GET['select_machine'] !== '') {
    $incomingMCode = trim((string) $_GET['select_machine']);
    foreach ($machineDesigns as $mDes) {
        if ($mDes['code'] === $incomingMCode) {
            $selectedMachineCode = $incomingMCode;
            if ($savedWorkType !== 'both') {
                $savedWorkType = 'machine';
            }
            break;
        }
    }
}

if (isset($_GET['select_hand']) && $_GET['select_hand'] !== '') {
    $incomingHCode = trim((string) $_GET['select_hand']);
    foreach ($handDesigns as $hDes) {
        if ($hDes['code'] === $incomingHCode) {
            $selectedHandCode = $incomingHCode;
            if ($savedWorkType !== 'both') {
                $savedWorkType = 'hand';
            }
            break;
        }
    }
}

// Compute active display names and prices for selected design previews
$currentMachineName = 'Bridal Motif';
$currentMachinePrice = 350;
foreach ($machineDesigns as $mDes) {
    if ($mDes['code'] === $selectedMachineCode) {
        $currentMachineName = $mDes['name'];
        $currentMachinePrice = (int) $mDes['price'];
        break;
    }
}

$currentHandName = 'Zari Floral';
$currentHandPrice = 800;
foreach ($handDesigns as $hDes) {
    if ($hDes['code'] === $selectedHandCode) {
        $currentHandName = $hDes['name'];
        $currentHandPrice = (int) $hDes['price'];
        break;
    }
}

include __DIR__ . '/includes/header.php';
?>

<main class="luxe-work-page" data-luxe-work-page data-initial-work-type="<?php echo htmlspecialchars($savedWorkType); ?>" data-base-price="<?php echo $basePrice; ?>" data-custom-price="<?php echo $customizationTotal; ?>">

    <div class="luxe-work-container">

        <!-- Back link -->
        <div class="luxe-work-nav-bar">
            <a href="luxe-workspace.php" class="luxe-work-back-link">
                ← Back to Luxe Workspace
            </a>
        </div>

        <!-- Target Garment Context Header -->
        <header class="luxe-work-context-card">
            <div class="luxe-work-context-badge">
                <span class="luxe-work-badge-num"><?php echo htmlspecialchars($numberedGarmentLabel); ?></span>
                <?php if ($personRole !== ''): ?>
                    <span class="luxe-work-role-badge"><?php echo htmlspecialchars($personRole); ?></span>
                <?php endif; ?>
            </div>

            <div class="luxe-work-context-details">
                <p class="luxe-work-context-eyebrow">WORK SELECTION FOR</p>
                <h1><?php echo htmlspecialchars($personName); ?></h1>
                <h2><?php echo htmlspecialchars($styleName); ?></h2>
            </div>
        </header>

        <!-- Main Form -->
        <form method="POST" action="luxe-work.php" id="luxe-work-form" class="luxe-work-form">
            <input type="hidden" name="luxe" value="1">
            <input type="hidden" name="person" value="<?php echo htmlspecialchars((string) ($luxePersonIndex + 1)); ?>">
            <input type="hidden" name="garment" value="<?php echo htmlspecialchars($garmentName); ?>">
            <input type="hidden" name="garment_idx" value="<?php echo htmlspecialchars((string) $luxeGarmentIndex); ?>">

            <!-- WORK TYPE SELECTION (4 OPTIONS) -->
            <section class="luxe-work-card">
                <div class="luxe-work-card-header">
                    <span class="luxe-step-indicator">Step 1</span>
                    <h3>Select Work Type</h3>
                    <p>Choose decorative embroidery and artisanal work for this physical garment.</p>
                </div>

                <div class="luxe-work-type-options" role="radiogroup" aria-label="Work Type Options">

                    <!-- 1. No Work -->
                    <label class="luxe-work-type-card <?php echo $savedWorkType === 'no_work' ? 'is-selected' : ''; ?>">
                        <div class="luxe-work-type-radio-wrap">
                            <input
                                type="radio"
                                name="work_type"
                                value="no_work"
                                <?php checked_val($savedWorkType, 'no_work'); ?>
                                data-work-radio
                            >
                        </div>
                        <div class="luxe-work-type-content">
                            <div class="luxe-work-type-title-bar">
                                <strong>No Work</strong>
                                <span class="luxe-work-type-price">+₹0</span>
                            </div>
                            <p>No additional decorative work. Clean tailoring as per chosen cut and finish.</p>
                        </div>
                    </label>

                    <!-- 2. Machine Work -->
                    <label class="luxe-work-type-card <?php echo $savedWorkType === 'machine' ? 'is-selected' : ''; ?>">
                        <div class="luxe-work-type-radio-wrap">
                            <input
                                type="radio"
                                name="work_type"
                                value="machine"
                                <?php checked_val($savedWorkType, 'machine'); ?>
                                data-work-radio
                            >
                        </div>
                        <div class="luxe-work-type-content">
                            <div class="luxe-work-type-title-bar">
                                <strong>Machine Work</strong>
                                <span class="luxe-work-type-price">From +₹250</span>
                            </div>
                            <p>Precise machine embroidery with high-density threads and polished motifs.</p>
                        </div>
                    </label>

                    <!-- 3. Hand Work -->
                    <label class="luxe-work-type-card <?php echo $savedWorkType === 'hand' ? 'is-selected' : ''; ?>">
                        <div class="luxe-work-type-radio-wrap">
                            <input
                                type="radio"
                                name="work_type"
                                value="hand"
                                <?php checked_val($savedWorkType, 'hand'); ?>
                                data-work-radio
                            >
                        </div>
                        <div class="luxe-work-type-content">
                            <div class="luxe-work-type-title-bar">
                                <strong>Hand Work</strong>
                                <span class="luxe-work-type-price">From +₹650</span>
                            </div>
                            <p>Handcrafted zardosi, aari needlework, pearls, and artisanal detailing.</p>
                        </div>
                    </label>

                    <!-- 4. Both Hand + Machine -->
                    <label class="luxe-work-type-card <?php echo $savedWorkType === 'both' ? 'is-selected' : ''; ?>">
                        <div class="luxe-work-type-radio-wrap">
                            <input
                                type="radio"
                                name="work_type"
                                value="both"
                                <?php checked_val($savedWorkType, 'both'); ?>
                                data-work-radio
                            >
                        </div>
                        <div class="luxe-work-type-content">
                            <div class="luxe-work-type-title-bar">
                                <strong>Both Hand + Machine</strong>
                                <span class="luxe-work-type-price">Combined</span>
                            </div>
                            <p>Combine structured machine embroidery with handcrafted focal highlights on this same garment.</p>
                        </div>
                    </label>

                </div>
            </section>


            <!-- MACHINE WORK CONFIGURATION PANEL -->
            <section
                class="luxe-work-card luxe-work-panel"
                id="panel-machine-work"
                data-work-panel="machine"
                style="<?php echo ($savedWorkType === 'machine' || $savedWorkType === 'both') ? '' : 'display: none;'; ?>"
            >
                <div class="luxe-work-card-header">
                    <span class="luxe-step-indicator">Machine Detailing</span>
                    <h3>Machine Work</h3>
                    <p>Choose from popular designs or search by design code or name.</p>
                </div>

                <!-- Machine Work Search Bar -->
                <div class="luxe-work-search-container">
                    <div class="luxe-work-search-bar">
                        <span class="luxe-search-icon" aria-hidden="true">🔍</span>
                        <input
                            type="text"
                            class="luxe-work-search-input"
                            data-work-search="machine"
                            placeholder="Search machine work by code or name"
                            aria-label="Search machine work by code or name"
                            autocomplete="off"
                        >
                        <button type="button" class="luxe-search-clear-btn" data-search-clear="machine" style="display: none;" aria-label="Clear search">×</button>
                    </div>
                </div>

                <!-- Selected Machine Design Banner -->
                <div class="luxe-selected-design-card" data-selected-banner="machine" style="<?php echo ($savedWorkType === 'machine' || $savedWorkType === 'both') ? '' : 'display: none;'; ?>">
                    <div class="luxe-selected-header">
                        <span class="luxe-selected-check">✓ Machine Work Selected</span>
                    </div>
                    <div class="luxe-selected-body">
                        <div class="luxe-selected-meta">
                            <strong data-selected-title="machine"><?php echo htmlspecialchars($selectedMachineCode . ' — ' . $currentMachineName); ?></strong>
                            <span class="luxe-selected-price" data-selected-price="machine">+₹<?php echo number_format($currentMachinePrice); ?></span>
                        </div>
                        <button type="button" class="luxe-change-design-btn" data-change-design="machine">Change</button>
                    </div>
                </div>

                <!-- Machine Designs List (Popular / Search Results) -->
                <div class="luxe-work-field-group">
                    <div class="luxe-designs-header-row">
                        <label class="luxe-work-field-label" data-catalogue-heading="machine">Popular Machine Work Designs</label>
                    </div>

                    <div class="luxe-work-design-grid" data-design-grid="machine">
                        <?php foreach ($machineDesigns as $idx => $mDes): ?>
                            <?php
                                $isPopular = ($idx < 3);
                                $isMSelected = ($selectedMachineCode === $mDes['code']);
                            ?>
                            <label
                                class="luxe-design-card <?php echo $isMSelected ? 'is-selected' : ''; ?>"
                                data-design-card
                                data-work-type="machine"
                                data-code="<?php echo htmlspecialchars($mDes['code']); ?>"
                                data-name="<?php echo htmlspecialchars($mDes['name']); ?>"
                                data-price="<?php echo (int) $mDes['price']; ?>"
                                data-popular="<?php echo $isPopular ? '1' : '0'; ?>"
                                style="<?php echo ($isPopular || $isMSelected) ? '' : 'display: none;'; ?>"
                            >
                                <input
                                    type="radio"
                                    name="machine_design"
                                    value="<?php echo htmlspecialchars($mDes['code']); ?>"
                                    data-price="<?php echo (int) $mDes['price']; ?>"
                                    data-design-name="<?php echo htmlspecialchars($mDes['name']); ?>"
                                    <?php checked_val($selectedMachineCode, $mDes['code']); ?>
                                    class="luxe-design-radio"
                                >
                                <div class="luxe-design-img-wrap">
                                    <img src="<?php echo htmlspecialchars($mDes['image']); ?>" alt="<?php echo htmlspecialchars($mDes['name']); ?>">
                                </div>
                                <div class="luxe-design-meta">
                                    <div class="luxe-design-title-row">
                                        <strong><?php echo htmlspecialchars($mDes['code']); ?> — <?php echo htmlspecialchars($mDes['name']); ?></strong>
                                        <span class="luxe-design-price">+₹<?php echo number_format($mDes['price']); ?></span>
                                    </div>
                                    <p><?php echo htmlspecialchars($mDes['description']); ?></p>
                                    <div class="luxe-design-action-row">
                                        <span class="luxe-design-select-pill">
                                            <?php echo $isMSelected ? 'Selected ✓' : 'Select'; ?>
                                        </span>
                                    </div>
                                </div>
                            </label>
                        <?php endforeach; ?>
                    </div>

                    <!-- No Results State -->
                    <div class="luxe-search-empty-state" data-search-empty="machine" style="display: none;">
                        <p class="luxe-search-empty-title">No machine-work designs found.</p>
                        <p class="luxe-search-empty-hint">Try another code or name, or:</p>
                        <a
                            href="designs.php?luxe=1&person=<?php echo urlencode((string)($luxePersonIndex + 1)); ?>&garment=<?php echo urlencode($garmentName); ?>&garment_idx=<?php echo urlencode((string)$luxeGarmentIndex); ?>&category=machinework"
                            class="luxe-view-all-link-empty"
                        >
                            View All Machine Work →
                        </a>
                    </div>

                    <!-- View All Machine Work Button -->
                    <div class="luxe-view-all-row" data-view-all-row="machine">
                        <a
                            href="designs.php?luxe=1&person=<?php echo urlencode((string)($luxePersonIndex + 1)); ?>&garment=<?php echo urlencode($garmentName); ?>&garment_idx=<?php echo urlencode((string)$luxeGarmentIndex); ?>&category=machinework"
                            class="luxe-view-all-btn"
                            data-view-all-btn="machine"
                        >
                            View All Machine Work →
                        </a>
                    </div>
                </div>

                <div class="luxe-work-field-group">
                    <label class="luxe-work-field-label">Placement:</label>
                    <div class="luxe-placement-pills">
                        <?php foreach ($placements as $pl): ?>
                            <label class="luxe-placement-pill <?php echo $selectedMachinePlacement === $pl ? 'is-selected' : ''; ?>">
                                <input
                                    type="radio"
                                    name="machine_placement"
                                    value="<?php echo htmlspecialchars($pl); ?>"
                                    <?php checked_val($selectedMachinePlacement, $pl); ?>
                                >
                                <span><?php echo htmlspecialchars($pl); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="luxe-work-field-group">
                    <label class="luxe-work-field-label">Work Target:</label>
                    <div class="luxe-work-target-options" role="radiogroup" aria-label="Machine Work Target">
                        <label class="luxe-work-target-pill <?php echo $selectedMachineTarget !== 'separate_material' ? 'is-selected' : ''; ?>">
                            <input
                                type="radio"
                                name="machine_work_target"
                                value="garment"
                                <?php checked_val($selectedMachineTarget, 'garment'); ?>
                                data-target-radio="machine"
                            >
                            <span>On My Blouse / Garment</span>
                        </label>
                        <label class="luxe-work-target-pill <?php echo $selectedMachineTarget === 'separate_material' ? 'is-selected' : ''; ?>">
                            <input
                                type="radio"
                                name="machine_work_target"
                                value="separate_material"
                                <?php checked_val($selectedMachineTarget, 'separate_material'); ?>
                                data-target-radio="machine"
                            >
                            <span>On Separate Cloth / Blouse</span>
                        </label>
                    </div>
                    <div class="luxe-separate-material-box" id="machine-separate-material-box" style="<?php echo $selectedMachineTarget === 'separate_material' ? '' : 'display: none;'; ?>">
                        <label for="machine-mat-desc" class="luxe-subfield-label">Separate Material Description:</label>
                        <input
                            type="text"
                            id="machine-mat-desc"
                            name="machine_material_description"
                            class="luxe-work-text-input"
                            placeholder="e.g. 1 meter raw silk cloth provided by customer"
                            value="<?php echo htmlspecialchars($selectedMachineMatDesc); ?>"
                        >
                        <p class="luxe-field-hint">Please provide separate material for embroidery when submitting measurements/fabric. Our team will verify and record physical intake.</p>
                    </div>
                </div>

                <div class="luxe-work-field-group">
                    <label for="machine-notes" class="luxe-work-field-label">Machine Work Notes (Optional):</label>
                    <textarea
                        id="machine-notes"
                        name="machine_notes"
                        class="luxe-work-textarea"
                        rows="3"
                        placeholder="e.g., Match zari color to saree border, subtle border placement..."
                    ><?php echo htmlspecialchars($selectedMachineNotes); ?></textarea>
                </div>
            </section>


            <!-- HAND WORK CONFIGURATION PANEL -->
            <section
                class="luxe-work-card luxe-work-panel"
                id="panel-hand-work"
                data-work-panel="hand"
                style="<?php echo ($savedWorkType === 'hand' || $savedWorkType === 'both') ? '' : 'display: none;'; ?>"
            >
                <div class="luxe-work-card-header">
                    <span class="luxe-step-indicator">Artisanal Handcraft</span>
                    <h3>Hand Work</h3>
                    <p>Choose from popular designs or search by design code or name.</p>
                </div>

                <!-- Hand Work Search Bar -->
                <div class="luxe-work-search-container">
                    <div class="luxe-work-search-bar">
                        <span class="luxe-search-icon" aria-hidden="true">🔍</span>
                        <input
                            type="text"
                            class="luxe-work-search-input"
                            data-work-search="hand"
                            placeholder="Search hand work by code or name"
                            aria-label="Search hand work by code or name"
                            autocomplete="off"
                        >
                        <button type="button" class="luxe-search-clear-btn" data-search-clear="hand" style="display: none;" aria-label="Clear search">×</button>
                    </div>
                </div>

                <!-- Selected Hand Design Banner -->
                <div class="luxe-selected-design-card" data-selected-banner="hand" style="<?php echo ($savedWorkType === 'hand' || $savedWorkType === 'both') ? '' : 'display: none;'; ?>">
                    <div class="luxe-selected-header">
                        <span class="luxe-selected-check">✓ Hand Work Selected</span>
                    </div>
                    <div class="luxe-selected-body">
                        <div class="luxe-selected-meta">
                            <strong data-selected-title="hand"><?php echo htmlspecialchars($selectedHandCode . ' — ' . $currentHandName); ?></strong>
                            <span class="luxe-selected-price" data-selected-price="hand">+₹<?php echo number_format($currentHandPrice); ?></span>
                        </div>
                        <button type="button" class="luxe-change-design-btn" data-change-design="hand">Change</button>
                    </div>
                </div>

                <!-- Hand Designs List (Popular / Search Results) -->
                <div class="luxe-work-field-group">
                    <div class="luxe-designs-header-row">
                        <label class="luxe-work-field-label" data-catalogue-heading="hand">Popular Hand Work Designs</label>
                    </div>

                    <div class="luxe-work-design-grid" data-design-grid="hand">
                        <?php foreach ($handDesigns as $idx => $hDes): ?>
                            <?php
                                $isPopular = ($idx < 3);
                                $isHSelected = ($selectedHandCode === $hDes['code']);
                            ?>
                            <label
                                class="luxe-design-card <?php echo $isHSelected ? 'is-selected' : ''; ?>"
                                data-design-card
                                data-work-type="hand"
                                data-code="<?php echo htmlspecialchars($hDes['code']); ?>"
                                data-name="<?php echo htmlspecialchars($hDes['name']); ?>"
                                data-price="<?php echo (int) $hDes['price']; ?>"
                                data-popular="<?php echo $isPopular ? '1' : '0'; ?>"
                                style="<?php echo ($isPopular || $isHSelected) ? '' : 'display: none;'; ?>"
                            >
                                <input
                                    type="radio"
                                    name="hand_design"
                                    value="<?php echo htmlspecialchars($hDes['code']); ?>"
                                    data-price="<?php echo (int) $hDes['price']; ?>"
                                    data-design-name="<?php echo htmlspecialchars($hDes['name']); ?>"
                                    <?php checked_val($selectedHandCode, $hDes['code']); ?>
                                    class="luxe-design-radio"
                                >
                                <div class="luxe-design-img-wrap">
                                    <img src="<?php echo htmlspecialchars($hDes['image']); ?>" alt="<?php echo htmlspecialchars($hDes['name']); ?>">
                                </div>
                                <div class="luxe-design-meta">
                                    <div class="luxe-design-title-row">
                                        <strong><?php echo htmlspecialchars($hDes['code']); ?> — <?php echo htmlspecialchars($hDes['name']); ?></strong>
                                        <span class="luxe-design-price">+₹<?php echo number_format($hDes['price']); ?></span>
                                    </div>
                                    <p><?php echo htmlspecialchars($hDes['description']); ?></p>
                                    <div class="luxe-design-action-row">
                                        <span class="luxe-design-select-pill">
                                            <?php echo $isHSelected ? 'Selected ✓' : 'Select'; ?>
                                        </span>
                                    </div>
                                </div>
                            </label>
                        <?php endforeach; ?>
                    </div>

                    <!-- No Results State -->
                    <div class="luxe-search-empty-state" data-search-empty="hand" style="display: none;">
                        <p class="luxe-search-empty-title">No hand-work designs found.</p>
                        <p class="luxe-search-empty-hint">Try another code or name, or:</p>
                        <a
                            href="designs.php?luxe=1&person=<?php echo urlencode((string)($luxePersonIndex + 1)); ?>&garment=<?php echo urlencode($garmentName); ?>&garment_idx=<?php echo urlencode((string)$luxeGarmentIndex); ?>&category=handwork"
                            class="luxe-view-all-link-empty"
                        >
                            View All Hand Work →
                        </a>
                    </div>

                    <!-- View All Hand Work Button -->
                    <div class="luxe-view-all-row" data-view-all-row="hand">
                        <a
                            href="designs.php?luxe=1&person=<?php echo urlencode((string)($luxePersonIndex + 1)); ?>&garment=<?php echo urlencode($garmentName); ?>&garment_idx=<?php echo urlencode((string)$luxeGarmentIndex); ?>&category=handwork"
                            class="luxe-view-all-btn"
                            data-view-all-btn="hand"
                        >
                            View All Hand Work →
                        </a>
                    </div>
                </div>

                <div class="luxe-work-field-group">
                    <label class="luxe-work-field-label">Placement:</label>
                    <div class="luxe-placement-pills">
                        <?php foreach ($placements as $pl): ?>
                            <label class="luxe-placement-pill <?php echo $selectedHandPlacement === $pl ? 'is-selected' : ''; ?>">
                                <input
                                    type="radio"
                                    name="hand_placement"
                                    value="<?php echo htmlspecialchars($pl); ?>"
                                    <?php checked_val($selectedHandPlacement, $pl); ?>
                                >
                                <span><?php echo htmlspecialchars($pl); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="luxe-work-field-group">
                    <label class="luxe-work-field-label">Work Target:</label>
                    <div class="luxe-work-target-options" role="radiogroup" aria-label="Hand Work Target">
                        <label class="luxe-work-target-pill <?php echo $selectedHandTarget !== 'separate_material' ? 'is-selected' : ''; ?>">
                            <input
                                type="radio"
                                name="hand_work_target"
                                value="garment"
                                <?php checked_val($selectedHandTarget, 'garment'); ?>
                                data-target-radio="hand"
                            >
                            <span>On My Blouse / Garment</span>
                        </label>
                        <label class="luxe-work-target-pill <?php echo $selectedHandTarget === 'separate_material' ? 'is-selected' : ''; ?>">
                            <input
                                type="radio"
                                name="hand_work_target"
                                value="separate_material"
                                <?php checked_val($selectedHandTarget, 'separate_material'); ?>
                                data-target-radio="hand"
                            >
                            <span>On Separate Cloth / Blouse</span>
                        </label>
                    </div>
                    <div class="luxe-separate-material-box" id="hand-separate-material-box" style="<?php echo $selectedHandTarget === 'separate_material' ? '' : 'display: none;'; ?>">
                        <label for="hand-mat-desc" class="luxe-subfield-label">Separate Material Description:</label>
                        <input
                            type="text"
                            id="hand-mat-desc"
                            name="hand_material_description"
                            class="luxe-work-text-input"
                            placeholder="e.g. Velvet dupatta border / separate cloth provided by customer"
                            value="<?php echo htmlspecialchars($selectedHandMatDesc); ?>"
                        >
                        <p class="luxe-field-hint">Please provide separate material for embroidery when submitting measurements/fabric. Our team will verify and record physical intake.</p>
                    </div>
                </div>

                <div class="luxe-work-field-group">
                    <label for="hand-notes" class="luxe-work-field-label">Hand Work Notes (Optional):</label>
                    <textarea
                        id="hand-notes"
                        name="hand_notes"
                        class="luxe-work-textarea"
                        rows="3"
                        placeholder="e.g., Antique gold stones, focus heavy work on front neck and cuffs..."
                    ><?php echo htmlspecialchars($selectedHandNotes); ?></textarea>
                </div>
            </section>


            <!-- PRICE SUMMARY & ACTIONS BAR -->
            <section class="luxe-work-summary-card">
                <div class="luxe-work-summary-breakdown">
                    <div class="luxe-summary-row">
                        <span>Base Style:</span>
                        <strong>₹<?php echo number_format($basePrice); ?></strong>
                    </div>
                    <?php if ($customizationTotal > 0): ?>
                        <div class="luxe-summary-row">
                            <span>Customizations:</span>
                            <strong>+₹<?php echo number_format($customizationTotal); ?></strong>
                        </div>
                    <?php endif; ?>
                    <div class="luxe-summary-row" id="summary-machine-row" style="display: none;">
                        <span>Machine Work:</span>
                        <strong id="summary-machine-val">+₹0</strong>
                    </div>
                    <div class="luxe-summary-row" id="summary-hand-row" style="display: none;">
                        <span>Hand Work:</span>
                        <strong id="summary-hand-val">+₹0</strong>
                    </div>
                    <div class="luxe-summary-row is-total">
                        <span>Garment Total:</span>
                        <strong id="summary-total-val">₹<?php echo number_format($basePrice + $customizationTotal); ?></strong>
                    </div>
                </div>

                <div class="luxe-work-actions">
                    <a href="luxe-workspace.php" class="luxe-work-btn-secondary">
                        Cancel
                    </a>
                    <button type="submit" class="luxe-work-btn-primary" id="save-work-btn">
                        Save Work Selection →
                    </button>
                </div>
            </section>

        </form>

    </div>

</main>

<?php include __DIR__ . '/includes/footer.php'; ?>
