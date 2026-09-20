<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/unified-cart.php';
require_user_login();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$luxe = $_SESSION['luxe_wedding'] ?? [];
$people = $luxe['people'] ?? [];

/*
 * Temporary demo data
 * ---------------------------------------------
 * This makes luxe-workspace.php open directly
 * even when no People/Garments session exists.
 *
 * Once the Garments flow is connected, the real
 * session data will automatically replace this.
if (!is_array($people)) {
    $people = [];
}
$demoMode = false;


/*
 * Normalize people and garments.
 */
$workspacePeople = [];

foreach ($people as $personIndex => $person) {
    if (!is_array($person)) {
        continue;
    }

    $name = trim((string) ($person['name'] ?? ''));

    if ($name === '') {
        $name = 'Person ' . ($personIndex + 1);
    }

    $role = trim((string) ($person['role'] ?? ''));

    $garments = $person['garments'] ?? [];

    if (!is_array($garments)) {
        $garments = [];
    }

    $normalizedGarments = [];

    foreach ($garments as $garment) {
        if (is_string($garment)) {
            $garmentName = trim($garment);

            if ($garmentName !== '') {
                $normalizedGarments[] = [
                    'name' => $garmentName,
                    'status' => 'not-started',
                    'summary' => ''
                ];
            }
        } elseif (is_array($garment)) {
            $garmentName = trim((string) ($garment['name'] ?? $garment['garment'] ?? ''));

            if ($garmentName !== '') {
                $normalized = $garment;
                $normalized['name'] = $garmentName;
                $normalized['status'] = (string) ($garment['status'] ?? 'not-started');
                $normalized['summary'] = (string) ($garment['summary'] ?? '');
                $normalizedGarments[] = $normalized;
            }
        }
    }

    $workspacePeople[] = [
        'name' => $name,
        'role' => $role,
        'measurement_method' => $person['measurement_method'] ?? null,
        'garments' => $normalizedGarments
    ];
}


// Handle Blouse Duplication / Copy
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'copy_blouse') {
    $personIdx = isset($_POST['person_index']) ? (int) $_POST['person_index'] : -1;
    $srcIdx = isset($_POST['source_garment_index']) ? (int) $_POST['source_garment_index'] : -1;
    $tgtIdx = isset($_POST['target_garment_index']) ? (int) $_POST['target_garment_index'] : -1;
    $copyWork = isset($_POST['copy_work']) && ($_POST['copy_work'] === '1' || $_POST['copy_work'] === 'on');

    if (isset($_SESSION['luxe_wedding']['people'][$personIdx]['garments'][$srcIdx]) &&
        isset($_SESSION['luxe_wedding']['people'][$personIdx]['garments'][$tgtIdx]) &&
        $srcIdx !== $tgtIdx
    ) {
        $source = $_SESSION['luxe_wedding']['people'][$personIdx]['garments'][$srcIdx];
        $target = &$_SESSION['luxe_wedding']['people'][$personIdx]['garments'][$tgtIdx];

        if (is_string($target)) {
            $target = ['name' => $target, 'status' => 'not-started'];
        }

        // Deep copy style and cut customization without shared references
        $target['style_slug'] = (string) ($source['style_slug'] ?? '');
        $target['style_name'] = (string) ($source['style_name'] ?? '');
        $target['base_price'] = (int) ($source['base_price'] ?? 650);
        $target['customization_total'] = (int) ($source['customization_total'] ?? 0);
        $target['choices'] = is_array($source['choices'] ?? null) ? unserialize(serialize($source['choices'])) : [];
        $target['choice_summary'] = is_array($source['choice_summary'] ?? null) ? unserialize(serialize($source['choice_summary'])) : [];
        $target['summary'] = (string) ($source['summary'] ?? '');
        $target['notes'] = (string) ($source['notes'] ?? '');

        if ($copyWork && !empty($source['work_type'])) {
            $target['work_type'] = $source['work_type'];
            $target['work_total'] = (int) ($source['work_total'] ?? 0);
            $target['machine_work'] = is_array($source['machine_work'] ?? null) ? unserialize(serialize($source['machine_work'])) : null;
            $target['hand_work'] = is_array($source['hand_work'] ?? null) ? unserialize(serialize($source['hand_work'])) : null;
        } else {
            if (!isset($target['work_type'])) {
                $target['work_type'] = 'no_work';
                $target['work_total'] = 0;
            }
        }

        $wTotal = (int) ($target['work_total'] ?? 0);
        $target['total_price'] = (int) $target['base_price'] + (int) $target['customization_total'] + $wTotal;

        // Completion check
        $wType = $target['work_type'] ?? null;
        $isW = ($wType === 'no_work') ||
               ($wType === 'machine' && !empty($target['machine_work'])) ||
               ($wType === 'hand' && !empty($target['hand_work'])) ||
               ($wType === 'both' && !empty($target['machine_work']) && !empty($target['hand_work']));

        if (!empty($target['style_slug']) && $isW) {
            $target['status'] = 'completed';
        } else {
            $target['status'] = 'in-progress';
        }
        $target['updated_at'] = time();

        $tgtLabel = ($target['name'] ?? 'Blouse') . ' #' . ($tgtIdx + 1);
        $srcLabel = ($source['name'] ?? 'Blouse') . ' #' . ($srcIdx + 1);
        $_SESSION['luxe_copy_success'] = "{$tgtLabel} updated with choices from {$srcLabel}. Click View / Edit to make individual adjustments.";

        header('Location: luxe-workspace.php');
        exit;
    }
}


/*
 * Progress calculation and garment completion check.
 */
$totalGarments = 0;
$completedGarments = 0;
$inProgressGarments = 0;
$notStartedGarments = 0;

foreach ($workspacePeople as &$person) {
    foreach ($person['garments'] as &$garment) {
        $totalGarments++;

        $isCust = !empty($garment['style_slug']);
        $wType = $garment['work_type'] ?? null;
        $isW = false;

        if ($wType === 'no_work') {
            $isW = true;
        } elseif ($wType === 'machine') {
            $isW = !empty($garment['machine_work']);
        } elseif ($wType === 'hand') {
            $isW = !empty($garment['hand_work']);
        } elseif ($wType === 'both') {
            $isW = !empty($garment['machine_work']) && !empty($garment['hand_work']);
        }

        if (($garment['status'] ?? '') === 'completed') {
            if ($wType === null) {
                $wType = 'no_work';
                $garment['work_type'] = 'no_work';
            }
            $isW = true;
            $garment['status'] = 'completed';
            $completedGarments++;
        } elseif ($isCust && $isW) {
            $garment['status'] = 'completed';
            $completedGarments++;
        } elseif ($isCust || ($garment['status'] ?? '') === 'in-progress' || $isW) {
            $garment['status'] = 'in-progress';
            $inProgressGarments++;
        } else {
            $garment['status'] = 'not-started';
            $notStartedGarments++;
        }
    }
    unset($garment);
}
unset($person);

$progressPercent = $totalGarments > 0
    ? (int) round(($completedGarments / $totalGarments) * 100)
    : 0;


/*
 * Find the next unfinished garment.
 */
$nextPersonIndex = null;
$nextGarmentIndex = null;

foreach ($workspacePeople as $personIndex => $person) {
    foreach ($person['garments'] as $garmentIndex => $garment) {
        if ($garment['status'] !== 'completed') {
            $nextPersonIndex = $personIndex;
            $nextGarmentIndex = $garmentIndex;
            break 2;
        }
    }
}

$nextPersonName = $nextPersonIndex !== null
    ? $workspacePeople[$nextPersonIndex]['name']
    : '';

$nextGarmentName = '';
if (
    $nextPersonIndex !== null &&
    isset($workspacePeople[$nextPersonIndex]['garments'][$nextGarmentIndex])
) {
    $targetPerson = $workspacePeople[$nextPersonIndex];
    $targetGarment = $targetPerson['garments'][$nextGarmentIndex];
    $tName = $targetGarment['name'];
    $cnt = 0;
    foreach ($targetPerson['garments'] as $idx => $g) {
        if ($g['name'] === $tName) {
            $cnt++;
        }
        if ($idx === $nextGarmentIndex) {
            break;
        }
    }
    $nextGarmentName = $tName . ' #' . $cnt;
}

$canProceedMeasurements = ($completedGarments >= 1);

$allMeasurementsSelected = true;
foreach ($workspacePeople as $pCheck) {
    $m = $pCheck['measurement_method'] ?? null;
    if (empty($m) || !in_array($m, ['reference_blouse', 'visit_shop'], true)) {
        $allMeasurementsSelected = false;
        break;
    }
}
$canProceedReview = ($totalGarments > 0 && $completedGarments === $totalGarments && $allMeasurementsSelected);

include __DIR__ . '/includes/header.php';

?>

<main
    class="luxe-workspace-page"
    data-luxe-workspace
    data-next-person="<?php echo htmlspecialchars((string) ($nextPersonIndex ?? '')); ?>"
    data-next-garment="<?php echo htmlspecialchars((string) ($nextGarmentIndex ?? '')); ?>"
>

    <?php if (!empty($_SESSION['luxe_copy_success'])): ?>
        <div class="luxe-copy-toast-banner" role="status" aria-live="polite">
            <span class="luxe-copy-toast-icon">✓</span>
            <span><?php echo htmlspecialchars($_SESSION['luxe_copy_success']); unset($_SESSION['luxe_copy_success']); ?></span>
        </div>
    <?php endif; ?>

    <!-- =========================================
         LUXE PROGRESS STEPPER
    ========================================== -->
    <?php
    require_once __DIR__ . '/includes/luxe-stepper.php';
    render_luxe_stepper(4, [
        'can_proceed_measurements' => $canProceedMeasurements,
        'can_proceed_review' => $canProceedReview,
    ]);
    ?>


    <!-- =========================================
         PAGE HEADER
    ========================================== -->
    <section class="luxe-workspace-header">

        <div class="luxe-workspace-container">

            <p class="luxe-workspace-eyebrow">
                LUXE STITCHING
            </p>

            <h1>Luxe Order Workspace</h1>

            <p class="luxe-workspace-intro">
                <?php if (($luxe['workflow'] ?? '') === 'family'): ?>
                    Customize each garment for every family member. You can complete them in any order.
                <?php else: ?>
                    Customize each garment for every person in your wedding. You can complete them in any order.
                <?php endif; ?>
            </p>

            <div class="luxe-workspace-event-bar">

                <div>
                    <span class="luxe-workspace-meta-label">
                        Requested Ready Date
                    </span>

                    <strong>
                        <?php
                        $rawDate = (string) ($luxe['requested_ready_date'] ?? ($luxe['wedding_date'] ?? ''));
                        if ($rawDate !== '') {
                            $ts = strtotime($rawDate);
                            $dispDate = $ts ? date('j M Y', $ts) : $rawDate;
                        } else {
                            $dispDate = count($workspacePeople) > 0 ? 'Pending Selection' : 'Not specified';
                        }
                        echo htmlspecialchars($dispDate);
                        ?>
                    </strong>
                </div>

                <a href="people.php" class="luxe-workspace-edit-link">
                    Edit People / Garments
                </a>

            </div>

        </div>

    </section>


    <!-- =========================================
         WORKSPACE CONTENT
    ========================================== -->
    <section class="luxe-workspace-content">

        <div class="luxe-workspace-container luxe-workspace-grid">

            <!-- =================================
                 MAIN WORKSPACE
            ================================== -->
            <div class="luxe-workspace-main">

                <?php if (count($workspacePeople) === 0): ?>

                    <div class="luxe-workspace-empty" style="text-align: center; padding: 50px 24px; background: #ffffff; border-radius: 16px; border: 1.5px solid #e8ddcf; max-width: 540px; margin: 30px auto; box-shadow: 0 4px 20px rgba(0,0,0,0.03);">
                        <span style="font-size: 40px; display: block; margin-bottom: 12px;">👑</span>
                        <h2 style="font-family: 'Playfair Display', serif; font-size: 24px; color: #57141f; margin: 0 0 10px;">Your Luxe workspace is empty.</h2>
                        <p style="color: #73695e; font-size: 14.5px; margin: 0 0 22px; line-height: 1.5;">
                            Start by adding a person and their garments.
                        </p>
                        <a href="luxe-stitching.php" class="luxe-workspace-primary" style="display: inline-block; background: linear-gradient(135deg, #6b1d28 0%, #8a2433 100%); color: #ffffff; padding: 11px 26px; border-radius: 8px; font-weight: 600; text-decoration: none; font-size: 13.5px;">
                            Start Luxe Stitching →
                        </a>
                    </div>

                <?php else: ?>

                    <?php foreach ($workspacePeople as $personIndex => $person): ?>

                        <article
                            class="luxe-person-workspace"
                            data-person-card
                            data-person-index="<?php echo $personIndex; ?>"
                        >

                            <div class="luxe-person-workspace-header">

                                <div>

                                    <span class="luxe-person-number">
                                        Person <?php echo $personIndex + 1; ?>
                                    </span>

                                    <h2>
                                        <?php echo htmlspecialchars($person['name']); ?>
                                    </h2>

                                    <?php if ($person['role'] !== ''): ?>
                                        <p>
                                            <?php echo htmlspecialchars($person['role']); ?>
                                        </p>
                                    <?php endif; ?>

                                </div>

                                <span class="luxe-person-garment-count">
                                    <?php echo count($person['garments']); ?>
                                    <?php echo count($person['garments']) === 1 ? 'garment' : 'garments'; ?>
                                </span>

                            </div>


                            <div class="luxe-workspace-garments">

                                <?php
                                $typeCounters = [];
                                ?>
                                <?php foreach ($person['garments'] as $garmentIndex => $garment): ?>

                                    <?php
                                    $garmentName = $garment['name'];
                                    $typeCounters[$garmentName] = ($typeCounters[$garmentName] ?? 0) + 1;
                                    $garmentItemNumber = $typeCounters[$garmentName];
                                    $numberedGarmentLabel = $garmentName . ' #' . $garmentItemNumber;

                                    $status = $garment['status'];

                                    $statusLabel = 'Not started';

                                    if ($status === 'completed') {
                                        $statusLabel = 'Completed';
                                    } elseif ($status === 'in-progress') {
                                        $statusLabel = 'In progress';
                                    }

                                    $isBlouse = strtolower($garmentName) === 'blouse';

                                    // Find previously configured blouses for this person to enable Copy action
                                    $otherConfiguredBlouses = [];
                                    $pCounts = [];
                                    foreach ($person['garments'] as $oIdx => $oG) {
                                        $oName = is_array($oG) ? ($oG['name'] ?? 'Blouse') : (string) $oG;
                                        $pCounts[$oName] = ($pCounts[$oName] ?? 0) + 1;
                                        if ($oIdx !== $garmentIndex && strtolower($oName) === 'blouse' && is_array($oG) && !empty($oG['style_slug'])) {
                                            $otherConfiguredBlouses[] = [
                                                'index' => $oIdx,
                                                'label' => $oName . ' #' . $pCounts[$oName],
                                                'style_name' => $oG['style_name'] ?? 'Customized Blouse',
                                                'has_work' => !empty($oG['work_type']) && $oG['work_type'] !== 'no_work',
                                            ];
                                        }
                                    }

                                    $customizeUrl = '#';
                                    $personNumber = $personIndex + 1;

                                    if ($isBlouse) {
                                        if (!empty($garment['style_slug'])) {
                                            $customizeUrl = 'customize-blouse.php?style=' . urlencode($garment['style_slug']) . '&luxe=1&person=' . $personNumber . '&garment=' . urlencode($garmentName) . '&garment_idx=' . $garmentIndex;
                                        } else {
                                            $customizeUrl = 'blouse-styles.php?luxe=1&person=' . $personNumber . '&garment=' . urlencode($garmentName) . '&garment_idx=' . $garmentIndex;
                                        }
                                    }

                                    $workUrl = 'luxe-work.php?luxe=1&person=' . $personNumber . '&garment=' . urlencode($garmentName) . '&garment_idx=' . $garmentIndex;

                                    $isCustComplete = !empty($garment['style_slug']);
                                    $workType = $garment['work_type'] ?? null;
                                    $isWorkComplete = false;

                                    if ($workType === 'no_work') {
                                        $isWorkComplete = true;
                                    } elseif ($workType === 'machine') {
                                        $isWorkComplete = !empty($garment['machine_work']);
                                    } elseif ($workType === 'hand') {
                                        $isWorkComplete = !empty($garment['hand_work']);
                                    } elseif ($workType === 'both') {
                                        $isWorkComplete = !empty($garment['machine_work']) && !empty($garment['hand_work']);
                                    }

                                    // Fallback for pre-existing completed fixtures
                                    if ($status === 'completed' && $workType === null) {
                                        $workType = 'no_work';
                                        $isWorkComplete = true;
                                    }
                                    ?>

                                    <article
                                        class="luxe-workspace-garment-card status-<?php echo htmlspecialchars($status); ?>"
                                        data-garment-card
                                        data-person-index="<?php echo $personIndex; ?>"
                                        data-garment-index="<?php echo $garmentIndex; ?>"
                                    >

                                        <div class="luxe-workspace-garment-image">

                                            <?php
                                            $image = 'assets/images/blouse.jpg';

                                            if (stripos($garmentName, 'lehenga') !== false) {
                                                $image = 'assets/images/lehenga.jpg';
                                            } elseif (stripos($garmentName, 'kurti') !== false) {
                                                $image = 'assets/images/kurti.jpg';
                                            } elseif (stripos($garmentName, 'saree') !== false) {
                                                $image = 'assets/images/luxe-2.jpg';
                                            } elseif (stripos($garmentName, 'gown') !== false) {
                                                $image = 'assets/images/luxe-4.jpg';
                                            }
                                            ?>

                                            <img
                                                src="<?php echo htmlspecialchars($image); ?>"
                                                alt="<?php echo htmlspecialchars($garmentName); ?>"
                                            >

                                        </div>


                                        <div class="luxe-workspace-garment-info">

                                            <div class="luxe-workspace-garment-top">

                                                <div>

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
                                                        <?php if ((!empty($garment['machine_work']['work_target']) && $garment['machine_work']['work_target'] === 'separate_material') || (!empty($garment['hand_work']['work_target']) && $garment['hand_work']['work_target'] === 'separate_material')): ?>
                                                            <?php echo render_category_badge('SEPARATE MATERIAL'); ?>
                                                        <?php endif; ?>
                                                    </div>

                                                    <?php if ($status === 'completed' && !empty($garment['style_name'])): ?>
                                                        <span class="luxe-garment-badge-num"><?php echo htmlspecialchars($numberedGarmentLabel); ?></span>
                                                        <h3><?php echo htmlspecialchars($garment['style_name']); ?></h3>
                                                    <?php elseif ($isCustComplete && !empty($garment['style_name'])): ?>
                                                        <span class="luxe-garment-badge-num"><?php echo htmlspecialchars($numberedGarmentLabel); ?></span>
                                                        <h3><?php echo htmlspecialchars($garment['style_name']); ?></h3>
                                                    <?php else: ?>
                                                        <h3><?php echo htmlspecialchars($numberedGarmentLabel); ?></h3>
                                                    <?php endif; ?>

                                                    <span class="luxe-garment-status status-<?php echo htmlspecialchars($status); ?>">
                                                        <?php echo htmlspecialchars($statusLabel); ?>
                                                    </span>

                                                </div>

                                            </div>


                                            <?php if ($status === 'completed'): ?>

                                                <?php
                                                $customizationRows = [];
                                                if (!empty($garment['choice_summary']) && is_array($garment['choice_summary'])) {
                                                    $customizationRows = $garment['choice_summary'];
                                                } elseif (!empty($garment['choices']) && is_array($garment['choices'])) {
                                                    $allOptions = function_exists('blouse_customization_options') ? blouse_customization_options() : [];
                                                    foreach ($garment['choices'] as $fKey => $cVal) {
                                                        if (isset($allOptions[$fKey])) {
                                                            $opt = $allOptions[$fKey];
                                                            $foundChoice = null;
                                                            foreach ($opt['choices'] as $c) {
                                                                if ($c['value'] === $cVal) {
                                                                    $foundChoice = $c;
                                                                    break;
                                                                }
                                                            }
                                                            $customizationRows[] = [
                                                                'field' => $opt['label'],
                                                                'label' => $foundChoice ? $foundChoice['label'] : (string) $cVal,
                                                                'price' => $foundChoice ? (int) $foundChoice['price'] : 0
                                                            ];
                                                        }
                                                    }
                                                } elseif (!empty($garment['summary']) && is_string($garment['summary'])) {
                                                    $parts = array_map('trim', explode('•', $garment['summary']));
                                                    foreach ($parts as $p) {
                                                        if ($p !== '') {
                                                            $customizationRows[] = [
                                                                'field' => '',
                                                                'label' => $p,
                                                                'price' => null
                                                            ];
                                                        }
                                                    }
                                                }

                                                // Filter out customization rows whose price is exactly 0 (handling 0, "0", 0.00, "0.00")
                                                $visibleCustomizationRows = [];
                                                foreach ($customizationRows as $cRow) {
                                                    if (isset($cRow['price']) && $cRow['price'] !== null && $cRow['price'] !== '') {
                                                        if (is_numeric($cRow['price']) && (float) $cRow['price'] == 0) {
                                                            continue;
                                                        }
                                                    }
                                                    $visibleCustomizationRows[] = $cRow;
                                                }

                                                $savedGarmentTotal = isset($garment['total_price'])
                                                    ? (int) $garment['total_price']
                                                    : (isset($garment['price']) ? (int) $garment['price'] : null);
                                                ?>

                                                <div class="luxe-customization-details">
                                                    <?php if (!empty($visibleCustomizationRows)): ?>
                                                        <div class="luxe-customization-heading">Customization:</div>
                                                        <ul class="luxe-customization-list">
                                                            <?php foreach ($visibleCustomizationRows as $cRow): ?>
                                                                <?php
                                                                $field = trim((string) ($cRow['field'] ?? ''));
                                                                $label = trim((string) ($cRow['label'] ?? ''));
                                                                $price = (isset($cRow['price']) && $cRow['price'] !== null && $cRow['price'] !== '' && is_numeric($cRow['price']))
                                                                    ? (float) $cRow['price']
                                                                    : null;
                                                                ?>
                                                                <li class="luxe-customization-item">
                                                                    <div class="luxe-customization-desc">
                                                                        <span class="luxe-customization-bullet">•</span>
                                                                        <?php if ($field !== ''): ?>
                                                                            <span class="luxe-customization-field"><?php echo htmlspecialchars($field); ?>:</span>
                                                                        <?php endif; ?>
                                                                        <span class="luxe-customization-label"><?php echo htmlspecialchars($label); ?></span>
                                                                    </div>
                                                                    <?php if ($price !== null): ?>
                                                                        <span class="luxe-customization-price">+₹<?php echo number_format($price); ?></span>
                                                                    <?php endif; ?>
                                                                </li>
                                                            <?php endforeach; ?>
                                                        </ul>
                                                    <?php endif; ?>

                                                    <!-- WORK SECTION -->
                                                    <div class="luxe-workspace-work-details">
                                                        <div class="luxe-work-heading-bar">
                                                            <div class="luxe-customization-heading">Work:</div>
                                                            <a href="<?php echo htmlspecialchars($workUrl); ?>" class="luxe-work-edit-inline-link">Edit Work</a>
                                                        </div>

                                                        <div class="luxe-work-display-content">
                                                            <?php if ($workType === 'no_work'): ?>
                                                                <div class="luxe-work-display-line">
                                                                    <span class="luxe-work-badge-tag">No Work</span>
                                                                    <span class="luxe-work-desc-text">Clean tailored finish without additional embroidery</span>
                                                                </div>
                                                            <?php elseif ($workType === 'machine'): ?>
                                                                <div class="luxe-work-display-line">
                                                                    <div class="luxe-work-info-col">
                                                                        <strong class="luxe-work-title">Machine Work</strong>
                                                                        <span class="luxe-work-design-title">
                                                                            <?php echo htmlspecialchars(($garment['machine_work']['design_code'] ?? 'M-024') . ' — ' . ($garment['machine_work']['design_name'] ?? 'Bridal Motif')); ?>
                                                                        </span>
                                                                        <?php if (!empty($garment['machine_work']['placement'])): ?>
                                                                            <small class="luxe-work-sub">Placement: <?php echo htmlspecialchars($garment['machine_work']['placement']); ?></small>
                                                                        <?php endif; ?>
                                                                    </div>
                                                                    <span class="luxe-work-price-badge">₹<?php echo number_format((float)($garment['machine_work']['price'] ?? 350)); ?></span>
                                                                </div>
                                                            <?php elseif ($workType === 'hand'): ?>
                                                                <div class="luxe-work-display-line">
                                                                    <div class="luxe-work-info-col">
                                                                        <strong class="luxe-work-title">Hand Work</strong>
                                                                        <span class="luxe-work-design-title">
                                                                            <?php echo htmlspecialchars(($garment['hand_work']['design_code'] ?? 'H-012') . ' — ' . ($garment['hand_work']['design_name'] ?? 'Zari Floral')); ?>
                                                                        </span>
                                                                        <?php if (!empty($garment['hand_work']['placement'])): ?>
                                                                            <small class="luxe-work-sub">Placement: <?php echo htmlspecialchars($garment['hand_work']['placement']); ?></small>
                                                                        <?php endif; ?>
                                                                    </div>
                                                                    <span class="luxe-work-price-badge">₹<?php echo number_format((float)($garment['hand_work']['price'] ?? 800)); ?></span>
                                                                </div>
                                                            <?php elseif ($workType === 'both'): ?>
                                                                <div class="luxe-work-both-block">
                                                                    <strong class="luxe-work-title">Both Hand + Machine</strong>
                                                                    <?php if (!empty($garment['machine_work'])): ?>
                                                                        <div class="luxe-work-display-line is-stacked">
                                                                            <div class="luxe-work-info-col">
                                                                                <strong>Machine:</strong>
                                                                                <span class="luxe-work-design-title">
                                                                                    <?php echo htmlspecialchars(($garment['machine_work']['design_code'] ?? 'M-024') . ' — ' . ($garment['machine_work']['design_name'] ?? 'Bridal Motif')); ?>
                                                                                </span>
                                                                                <?php if (!empty($garment['machine_work']['placement'])): ?>
                                                                                    <small class="luxe-work-sub"><?php echo htmlspecialchars($garment['machine_work']['placement']); ?></small>
                                                                                <?php endif; ?>
                                                                            </div>
                                                                            <span class="luxe-work-price-badge">₹<?php echo number_format((float)($garment['machine_work']['price'] ?? 350)); ?></span>
                                                                        </div>
                                                                    <?php endif; ?>
                                                                    <?php if (!empty($garment['hand_work'])): ?>
                                                                        <div class="luxe-work-display-line is-stacked">
                                                                            <div class="luxe-work-info-col">
                                                                                <strong>Hand:</strong>
                                                                                <span class="luxe-work-design-title">
                                                                                    <?php echo htmlspecialchars(($garment['hand_work']['design_code'] ?? 'H-012') . ' — ' . ($garment['hand_work']['design_name'] ?? 'Zari Floral')); ?>
                                                                                </span>
                                                                                <?php if (!empty($garment['hand_work']['placement'])): ?>
                                                                                    <small class="luxe-work-sub"><?php echo htmlspecialchars($garment['hand_work']['placement']); ?></small>
                                                                                <?php endif; ?>
                                                                            </div>
                                                                            <span class="luxe-work-price-badge">₹<?php echo number_format((float)($garment['hand_work']['price'] ?? 800)); ?></span>
                                                                        </div>
                                                                    <?php endif; ?>
                                                                </div>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>

                                                    <?php if ($savedGarmentTotal !== null): ?>
                                                        <div class="luxe-garment-total">
                                                            <span>Garment Total:</span>
                                                            <strong>₹<?php echo number_format($savedGarmentTotal); ?></strong>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>

                                            <?php elseif ($status === 'in-progress'): ?>

                                                <?php if ($isCustComplete && !$isWorkComplete): ?>
                                                    <!-- Customization is done, Work requirement is missing -->
                                                    <div class="luxe-incomplete-steps-card">
                                                        <div class="luxe-step-check is-done">
                                                            <span class="luxe-step-check-icon">✓</span>
                                                            <span>Customization ✓</span>
                                                        </div>
                                                        <div class="luxe-step-check is-missing">
                                                            <span class="luxe-step-check-icon">○</span>
                                                            <span>Work: Not selected</span>
                                                        </div>
                                                    </div>
                                                <?php else: ?>
                                                    <p>
                                                        Your customization has been started.
                                                        Continue where you left off.
                                                    </p>
                                                <?php endif; ?>

                                            <?php else: ?>

                                                <p>
                                                    Choose style and customize your
                                                    <?php echo strtolower(htmlspecialchars($garmentName)); ?>.
                                                </p>

                                            <?php endif; ?>


                                            <div class="luxe-workspace-garment-actions">

                                                <?php if ($status === 'completed'): ?>

                                                    <a
                                                        href="<?php echo htmlspecialchars($customizeUrl); ?>"
                                                        class="luxe-workspace-secondary-action"
                                                    >
                                                        View / Edit
                                                    </a>

                                                    <button
                                                        type="button"
                                                        class="luxe-workspace-text-action"
                                                        data-toggle-incomplete
                                                    >
                                                        Mark as Incomplete
                                                    </button>

                                                <?php elseif ($status === 'in-progress'): ?>

                                                    <?php if ($isCustComplete && !$isWorkComplete): ?>
                                                        <a
                                                            href="<?php echo htmlspecialchars($workUrl); ?>"
                                                            class="luxe-workspace-primary-action luxe-choose-work-btn"
                                                        >
                                                            Choose Work
                                                        </a>

                                                        <a
                                                            href="<?php echo htmlspecialchars($customizeUrl); ?>"
                                                            class="luxe-workspace-secondary-action"
                                                        >
                                                            View / Edit Customization
                                                        </a>
                                                    <?php else: ?>
                                                        <a
                                                            href="<?php echo htmlspecialchars($customizeUrl); ?>"
                                                            class="luxe-workspace-primary-action"
                                                        >
                                                            Continue Customizing
                                                        </a>

                                                        <button
                                                            type="button"
                                                            class="luxe-workspace-secondary-action"
                                                            data-view-details
                                                        >
                                                            View Details
                                                        </button>
                                                    <?php endif; ?>

                                                    <?php if ($isBlouse && !empty($otherConfiguredBlouses)): ?>
                                                        <button
                                                            type="button"
                                                            class="luxe-workspace-secondary-action luxe-copy-blouse-btn"
                                                            data-open-copy-modal
                                                            data-person-index="<?php echo $personIndex; ?>"
                                                            data-person-name="<?php echo htmlspecialchars($person['name']); ?>"
                                                            data-target-index="<?php echo $garmentIndex; ?>"
                                                            data-target-label="<?php echo htmlspecialchars($numberedGarmentLabel); ?>"
                                                            data-sources="<?php echo htmlspecialchars(json_encode($otherConfiguredBlouses), ENT_QUOTES, 'UTF-8'); ?>"
                                                        >
                                                            📋 <?php echo count($otherConfiguredBlouses) === 1 ? ('Copy from ' . htmlspecialchars($otherConfiguredBlouses[0]['label'])) : 'Copy Style & Customization'; ?>
                                                        </button>
                                                    <?php endif; ?>

                                                <?php else: ?>

                                                    <a
                                                        href="<?php echo htmlspecialchars($customizeUrl); ?>"
                                                        class="luxe-workspace-primary-action <?php echo !$isBlouse ? 'is-demo-disabled' : ''; ?>"
                                                        <?php echo !$isBlouse ? 'data-demo-unavailable' : ''; ?>
                                                    >
                                                        Start Customizing
                                                    </a>

                                                    <?php if ($isBlouse && !empty($otherConfiguredBlouses)): ?>
                                                        <button
                                                            type="button"
                                                            class="luxe-workspace-secondary-action luxe-copy-blouse-btn"
                                                            data-open-copy-modal
                                                            data-person-index="<?php echo $personIndex; ?>"
                                                            data-person-name="<?php echo htmlspecialchars($person['name']); ?>"
                                                            data-target-index="<?php echo $garmentIndex; ?>"
                                                            data-target-label="<?php echo htmlspecialchars($numberedGarmentLabel); ?>"
                                                            data-sources="<?php echo htmlspecialchars(json_encode($otherConfiguredBlouses), ENT_QUOTES, 'UTF-8'); ?>"
                                                        >
                                                            📋 <?php echo count($otherConfiguredBlouses) === 1 ? ('Copy from ' . htmlspecialchars($otherConfiguredBlouses[0]['label'])) : 'Copy Style & Customization'; ?>
                                                        </button>
                                                    <?php endif; ?>

                                                    <button
                                                        type="button"
                                                        class="luxe-workspace-secondary-action"
                                                        data-view-details
                                                    >
                                                        View Details
                                                    </button>

                                                <?php endif; ?>

                                            </div>

                                        </div>

                                    </article>

                                <?php endforeach; ?>

                            </div>

                            <?php
                            $personTotal = 0;
                            foreach ($person['garments'] as $g) {
                                if (($g['status'] ?? '') === 'completed') {
                                    $pPrice = isset($g['total_price'])
                                        ? (int) $g['total_price']
                                        : (isset($g['price']) ? (int) $g['price'] : 0);
                                    $personTotal += $pPrice;
                                }
                            }

                            $rawPersonName = trim($person['name'] ?? '');
                            if ($rawPersonName === '') {
                                $rawPersonName = 'Person ' . ($personIndex + 1);
                            }
                            $possessiveName = (substr($rawPersonName, -1) === 's')
                                ? $rawPersonName . "'"
                                : $rawPersonName . "'s";
                            $personSubtotalLabel = $possessiveName . ' Total';
                            ?>

                            <div class="luxe-person-subtotal-bar">
                                <span class="luxe-person-subtotal-label">
                                    <?php echo htmlspecialchars($personSubtotalLabel); ?>:
                                </span>
                                <strong class="luxe-person-subtotal-price">
                                    ₹<?php echo number_format($personTotal); ?>
                                </strong>
                            </div>

                        </article>

                    <?php endforeach; ?>

                    <?php
                    $grandTotal = 0;
                    foreach ($workspacePeople as $p) {
                        foreach ($p['garments'] as $g) {
                            if (($g['status'] ?? '') === 'completed') {
                                $gPrice = isset($g['total_price'])
                                    ? (int) $g['total_price']
                                    : (isset($g['price']) ? (int) $g['price'] : 0);
                                $grandTotal += $gPrice;
                            }
                        }
                    }
                    ?>

                    <section class="luxe-workspace-grand-total">
                        <div class="luxe-grand-total-info">
                            <span class="luxe-grand-total-eyebrow">ORDER SUMMARY</span>
                            <h3>Grand Total</h3>
                            <p>Total for all customized Luxe garments</p>
                        </div>
                        <div class="luxe-grand-total-amount">
                            <strong>₹<?php echo number_format($grandTotal); ?></strong>
                        </div>
                    </section>

                <?php endif; ?>

            </div>


            <!-- =================================
                 SIDEBAR
            ================================== -->
            <aside class="luxe-workspace-sidebar">

                <section class="luxe-progress-card">

                    <div class="luxe-progress-card-heading">

                        <div>
                            <span class="luxe-sidebar-eyebrow">
                                YOUR ORDER
                            </span>

                            <h2>Overall Progress</h2>
                        </div>

                        <strong>
                            <?php echo $completedGarments; ?>/<?php echo $totalGarments; ?>
                        </strong>

                    </div>


                    <div class="luxe-progress-bar">

                        <span
                            style="width: <?php echo $progressPercent; ?>%;"
                        ></span>

                    </div>


                    <p class="luxe-progress-percent">
                        <?php echo $progressPercent; ?>% completed
                    </p>


                    <div class="luxe-progress-stats">

                        <div>
                            <strong><?php echo $completedGarments; ?></strong>
                            <span>Completed</span>
                        </div>

                        <div>
                            <strong><?php echo $inProgressGarments; ?></strong>
                            <span>In Progress</span>
                        </div>

                        <div>
                            <strong><?php echo $notStartedGarments; ?></strong>
                            <span>Not Started</span>
                        </div>

                    </div>

                </section>


                <section class="luxe-next-step-card">

                    <span class="luxe-sidebar-eyebrow">
                        NEXT STEP
                    </span>

                    <?php if ($nextGarmentName !== ''): ?>

                        <h2>
                            <?php echo htmlspecialchars($nextPersonName); ?>
                            –
                            <?php echo htmlspecialchars($nextGarmentName); ?>
                        </h2>

                        <p>
                            Continue with the next unfinished garment
                            in your order.
                        </p>

                        <button
                            type="button"
                            class="luxe-workspace-next-button"
                            data-next-item
                        >
                            Continue with Next Item
                            <span>→</span>
                        </button>

                    <?php else: ?>

                        <h2>All garments completed</h2>

                        <p>
                            Your Luxe garments are ready for measurements.
                        </p>

                    <?php endif; ?>

                </section>


                <div class="luxe-workspace-note">
                    <span>ⓘ</span>

                    <p>
                        You can customize garments in any order.
                        All items will be saved automatically.
                    </p>
                </div>

            </aside>

        </div>

    </section>


    <!-- =========================================
         BOTTOM ACTIONS
    ========================================== -->
    <section class="luxe-workspace-bottom">

        <div class="luxe-workspace-container">

            <div class="luxe-workspace-bottom-actions">

                <a
                    href="garments.php"
                    class="luxe-workspace-bottom-secondary"
                >
                    ← Back to Garments
                </a>


                <?php if ($completedGarments >= 1): ?>

                    <a
                        href="measurements.php"
                        class="luxe-workspace-bottom-primary"
                        data-proceed-measurements
                    >
                        Proceed to Measurements →
                    </a>

                <?php else: ?>

                    <button
                        type="button"
                        class="luxe-workspace-bottom-primary is-disabled"
                        data-proceed-measurements
                    >
                        Proceed to Measurements →
                    </button>

                <?php endif; ?>

            </div>


            <?php if ($completedGarments >= 1): ?>

                <p class="luxe-workspace-bottom-note is-ready">
                    Continue to Measurements whenever you're ready
                </p>

            <?php else: ?>

                <p class="luxe-workspace-bottom-note">
                    Complete at least one garment to continue
                </p>

            <?php endif; ?>

        </div>

    </section>

    <!-- =========================================
         MODAL: COPY BLOUSE CONFIGURATION
    ========================================== -->
    <div id="copy-blouse-modal" class="luxe-modal-overlay" style="display: none;" role="dialog" aria-modal="true" aria-labelledby="copy-modal-title">
        <div class="luxe-modal-container">
            <div class="luxe-modal-header copy-modal-header">
                <h3 id="copy-modal-title">Copy Blouse Configuration</h3>
                <button type="button" class="luxe-modal-close copy-modal-close" data-close-copy-modal aria-label="Close modal">&times;</button>
            </div>
            <form method="POST" action="luxe-workspace.php" id="copy-blouse-form">
                <input type="hidden" name="action" value="copy_blouse">
                <input type="hidden" name="person_index" id="copy-person-index" value="">
                <input type="hidden" name="target_garment_index" id="copy-target-index" value="">

                <div class="luxe-modal-body copy-modal-body">
                    <div class="copy-modal-context">
                        <p>Target Garment: <strong id="copy-target-name">Blouse #2</strong> (<span id="copy-person-name">Person</span>)</p>
                    </div>

                    <div class="copy-modal-field" id="copy-source-select-wrap">
                        <label for="copy-source-index" class="copy-modal-label">Select Source Blouse to Copy From:</label>
                        <select name="source_garment_index" id="copy-source-index" class="copy-modal-select" required></select>
                    </div>

                    <div class="copy-modal-confirm-box">
                        <p class="copy-modal-notice">
                            ⓘ <strong>Independent Garment Guarantee:</strong> All cut selections, neck design, sleeve style, and options will be copied to this blouse. Both garments remain completely independent and can be edited separately afterwards.
                        </p>
                        <label class="copy-modal-checkbox-label">
                            <input type="checkbox" name="copy_work" id="copy-work-checkbox" value="1" checked>
                            <span>Also copy Embroidery Work (Machine / Hand Work) from the source blouse</span>
                        </label>
                    </div>
                </div>

                <div class="luxe-modal-footer copy-modal-footer">
                    <button type="button" class="luxe-workspace-secondary-action copy-modal-cancel-btn" data-close-copy-modal>Cancel</button>
                    <button type="submit" class="luxe-workspace-primary-action copy-modal-submit-btn" id="confirm-copy-submit-btn">Confirm & Copy Choices</button>
                </div>
            </form>
        </div>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function () {
        var modal = document.getElementById('copy-blouse-modal');
        var form = document.getElementById('copy-blouse-form');
        var personIdxInput = document.getElementById('copy-person-index');
        var targetIdxInput = document.getElementById('copy-target-index');
        var targetNameSpan = document.getElementById('copy-target-name');
        var personNameSpan = document.getElementById('copy-person-name');
        var sourceSelect = document.getElementById('copy-source-index');

        document.querySelectorAll('[data-open-copy-modal]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var pIdx = btn.getAttribute('data-person-index');
                var pName = btn.getAttribute('data-person-name');
                var tIdx = btn.getAttribute('data-target-index');
                var tLabel = btn.getAttribute('data-target-label');
                var sourcesJson = btn.getAttribute('data-sources');

                personIdxInput.value = pIdx;
                targetIdxInput.value = tIdx;
                targetNameSpan.textContent = tLabel;
                personNameSpan.textContent = pName;

                sourceSelect.innerHTML = '';
                try {
                    var sources = JSON.parse(sourcesJson);
                    sources.forEach(function (src) {
                        var opt = document.createElement('option');
                        opt.value = src.index;
                        opt.textContent = src.label + ' — ' + src.style_name + (src.has_work ? ' (with Embroidery Work)' : '');
                        sourceSelect.appendChild(opt);
                    });
                } catch (e) {
                    console.error('Failed to parse sources', e);
                }

                if (modal) {
                    modal.style.display = 'flex';
                }
            });
        });

        function closeModal() {
            if (modal) {
                modal.style.display = 'none';
            }
        }

        document.querySelectorAll('[data-close-copy-modal]').forEach(function (btn) {
            btn.addEventListener('click', closeModal);
        });

        if (modal) {
            modal.addEventListener('click', function (e) {
                if (e.target === modal) {
                    closeModal();
                }
            });
        }
    });
    </script>
</main>

<?php include __DIR__ . '/includes/footer.php'; ?>