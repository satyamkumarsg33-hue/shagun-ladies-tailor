<?php
require_once __DIR__ . '/includes/demo-data.php';
require_once __DIR__ . '/includes/cart.php';
require_once __DIR__ . '/includes/auth.php';
demo_cart_bootstrap();

$isLuxe = (isset($_GET['luxe']) && $_GET['luxe'] == '1') || (isset($_POST['luxe']) && $_POST['luxe'] == '1');
if ($isLuxe) {
    require_user_login();
}
$personParam = $_GET['person'] ?? $_POST['person'] ?? null;
$garmentParam = (string) ($_GET['garment'] ?? $_POST['garment'] ?? 'Blouse');
$garmentIdxParam = isset($_GET['garment_idx']) ? (int) $_GET['garment_idx'] : (isset($_POST['garment_idx']) ? (int) $_POST['garment_idx'] : null);

$styleSlug = (string) ($_GET['style'] ?? $_POST['style'] ?? '');
$style = blouse_style_by_slug($styleSlug);

// If style is invalid, redirect back with context preserved
if ($style === null) {
    if ($isLuxe) {
        $redir = 'blouse-styles.php?luxe=1'
            . ($personParam !== null ? '&person=' . urlencode((string) $personParam) : '')
            . ($garmentParam !== '' ? '&garment=' . urlencode($garmentParam) : '')
            . ($garmentIdxParam !== null ? '&garment_idx=' . $garmentIdxParam : '');
        header('Location: ' . $redir);
    } else {
        header('Location: blouse-styles.php');
    }
    exit;
}

$luxePerson = null;
$luxePersonIndex = null;
$luxeGarmentIndex = null;
$luxeGarmentData = null;

if ($isLuxe) {
    if (!isset($_SESSION['luxe_wedding']) || !is_array($_SESSION['luxe_wedding'])) {
        $_SESSION['luxe_wedding'] = [];
    }
    if (empty($_SESSION['luxe_wedding']['people']) || !is_array($_SESSION['luxe_wedding']['people'])) {
        header('Location: luxe-workspace.php');
        exit;
    }
    $people = &$_SESSION['luxe_wedding']['people'];

    // Resolve person index:
    // Support: 1-based index (person=1 -> 0), 0-based index (person=0 -> 0), or name match
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

    if ($luxePersonIndex !== null && isset($people[$luxePersonIndex])) {
        $luxePerson = &$people[$luxePersonIndex];

        if (!isset($luxePerson['garments']) || !is_array($luxePerson['garments'])) {
            $luxePerson['garments'] = [];
        }

        // Resolve garment index in this person's garments
        if ($garmentIdxParam !== null && isset($luxePerson['garments'][$garmentIdxParam])) {
            $luxeGarmentIndex = $garmentIdxParam;
            $luxeGarmentData = $luxePerson['garments'][$garmentIdxParam];
        } else {
            foreach ($luxePerson['garments'] as $gIdx => $gItem) {
                $gName = is_array($gItem) ? ($gItem['name'] ?? $gItem['garment'] ?? '') : (string) $gItem;
                if (strcasecmp(trim($gName), trim($garmentParam)) === 0) {
                    $luxeGarmentIndex = $gIdx;
                    $luxeGarmentData = $gItem;
                    break;
                }
            }
        }

        if ($luxeGarmentIndex === null) {
            $luxeGarmentIndex = count($luxePerson['garments']);
            $luxeGarmentData = $garmentParam !== '' ? $garmentParam : 'Blouse';
        }
    }
}

$options = blouse_customization_options();

if ($isLuxe) {
    $personName = !empty($luxePerson['name']) ? $luxePerson['name'] : ('Person ' . (($luxePersonIndex ?? 0) + 1));
    $personRole = !empty($luxePerson['role']) ? $luxePerson['role'] : '';
    $selected = [];
    $notes = '';

    if (is_array($luxeGarmentData)) {
        if (!empty($luxeGarmentData['choices']) && is_array($luxeGarmentData['choices'])) {
            $selected = $luxeGarmentData['choices'];
        }
        if (isset($luxeGarmentData['notes'])) {
            $notes = (string) $luxeGarmentData['notes'];
        }
    }
    $editingId = '';
    $existingItem = null;
} else {
    $editingId = (string) ($_GET['edit'] ?? $_POST['edit_id'] ?? '');
    $existingItem = $editingId !== '' ? demo_cart_item($editingId) : null;
    $selected = $existingItem['choices'] ?? [];
    $personName = $existingItem['person_name'] ?? '';
    $notes = $existingItem['notes'] ?? '';
    $personRole = '';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $notes = trim((string) ($_POST['notes'] ?? ''));
    $submittedChoices = $_POST['choices'] ?? [];
    $selected = [];
    $summary = [];
    $extraTotal = 0;

    foreach ($options as $field => $option) {
        $choice = demo_choice($field, (string) ($submittedChoices[$field] ?? $option['choices'][0]['value'])) ?? $option['choices'][0];
        $selected[$field] = $choice['value'];
        $summary[] = ['field' => $option['label'], 'label' => $choice['label'], 'price' => $choice['price']];
        $extraTotal += $choice['price'];
    }

    if ($isLuxe) {
        // Build readable summary for Luxe Workspace display
        $summaryParts = [$style['name']];
        foreach ($summary as $s) {
            if ($s['price'] > 0 || (isset($s['label']) && !in_array($s['label'], ['Style default', 'Standard sleeve', 'Short', 'No lining', 'No cups', 'No piping', 'Standard finish', 'No additional work']))) {
                $summaryParts[] = $s['label'];
            }
        }
        if (count($summaryParts) === 1 && !empty($summary[0]['label'])) {
            $summaryParts[] = $summary[0]['label'];
        }
        $summaryText = implode(' • ', array_slice($summaryParts, 0, 3));

        $garmentName = $garmentParam !== '' ? $garmentParam : 'Blouse';
        $existingGarment = ($luxePersonIndex !== null && $luxeGarmentIndex !== null && isset($people[$luxePersonIndex]['garments'][$luxeGarmentIndex]) && is_array($people[$luxePersonIndex]['garments'][$luxeGarmentIndex]))
            ? $people[$luxePersonIndex]['garments'][$luxeGarmentIndex]
            : [];

        $existingWorkType = $existingGarment['work_type'] ?? null;
        $existingWorkTotal = isset($existingGarment['work_total']) ? (int) $existingGarment['work_total'] : 0;

        // If work_type is not yet set, initialize from selected embroidery choice if explicitly submitted
        if ($existingWorkType === null && isset($submittedChoices['embroidery'])) {
            if ($submittedChoices['embroidery'] === 'machine') {
                $existingWorkType = 'machine';
                if (empty($existingGarment['machine_work'])) {
                    $existingGarment['machine_work'] = [
                        'design_code' => 'M-024',
                        'design_name' => 'Bridal Motif',
                        'price' => 250,
                        'placement' => 'Neck',
                        'notes' => ''
                    ];
                }
                $existingWorkTotal = (int) ($existingGarment['machine_work']['price'] ?? 250);
            } elseif ($submittedChoices['embroidery'] === 'hand') {
                $existingWorkType = 'hand';
                if (empty($existingGarment['hand_work'])) {
                    $existingGarment['hand_work'] = [
                        'design_code' => 'H-012',
                        'design_name' => 'Zari Floral',
                        'price' => 500,
                        'placement' => 'Neck',
                        'notes' => ''
                    ];
                }
                $existingWorkTotal = (int) ($existingGarment['hand_work']['price'] ?? 500);
            } elseif ($submittedChoices['embroidery'] === 'none') {
                $existingWorkType = 'no_work';
                $existingWorkTotal = 0;
            }
        }

        $isWorkComplete = false;
        if ($existingWorkType === 'no_work') {
            $isWorkComplete = true;
        } elseif ($existingWorkType === 'machine') {
            $isWorkComplete = !empty($existingGarment['machine_work']);
        } elseif ($existingWorkType === 'hand') {
            $isWorkComplete = !empty($existingGarment['hand_work']);
        } elseif ($existingWorkType === 'both') {
            $isWorkComplete = !empty($existingGarment['machine_work']) && !empty($existingGarment['hand_work']);
        }

        $garmentStatus = $isWorkComplete ? 'completed' : 'in-progress';

        // Customization total excludes work total to prevent duplicate charging
        $embroideryChoicePrice = 0;
        if (isset($selected['embroidery'])) {
            if ($selected['embroidery'] === 'machine') {
                $embroideryChoicePrice = 250;
            } elseif ($selected['embroidery'] === 'hand') {
                $embroideryChoicePrice = 500;
            }
        }
        $cleanCustomizationTotal = max(0, $extraTotal - $embroideryChoicePrice);
        $finalTotal = $style['price'] + $cleanCustomizationTotal + $existingWorkTotal;

        $garmentRecord = [
            'name' => $garmentName,
            'status' => $garmentStatus,
            'summary' => $summaryText,
            'style_slug' => $style['slug'],
            'style_name' => $style['name'],
            'style_image' => $style['image'],
            'base_price' => $style['price'],
            'customization_total' => $cleanCustomizationTotal,
            'work_total' => $existingWorkTotal,
            'total_price' => $finalTotal,
            'choices' => $selected,
            'choice_summary' => $summary,
            'notes' => $notes,
            'updated_at' => time()
        ];

        if ($existingWorkType !== null) {
            $garmentRecord['work_type'] = $existingWorkType;
        }
        if (isset($existingGarment['machine_work'])) {
            $garmentRecord['machine_work'] = $existingGarment['machine_work'];
        }
        if (isset($existingGarment['hand_work'])) {
            $garmentRecord['hand_work'] = $existingGarment['hand_work'];
        }

        if ($luxePersonIndex !== null && isset($people[$luxePersonIndex])) {
            if ($luxeGarmentIndex !== null) {
                if (isset($people[$luxePersonIndex]['garments'][$luxeGarmentIndex]) && is_array($people[$luxePersonIndex]['garments'][$luxeGarmentIndex])) {
                    $people[$luxePersonIndex]['garments'][$luxeGarmentIndex] = array_merge(
                        $people[$luxePersonIndex]['garments'][$luxeGarmentIndex],
                        $garmentRecord
                    );
                } else {
                    $people[$luxePersonIndex]['garments'][$luxeGarmentIndex] = $garmentRecord;
                }
            } else {
                $people[$luxePersonIndex]['garments'][] = $garmentRecord;
            }
        }

        header('Location: luxe-workspace.php');
        exit;
    } else {
        $personName = trim((string) ($_POST['person_name'] ?? ''));
        $item = [
            'id' => $existingItem['id'] ?? bin2hex(random_bytes(8)),
            'garment' => 'Blouse',
            'style_slug' => $style['slug'],
            'style_name' => $style['name'],
            'image' => $style['image'],
            'person_name' => $personName !== '' ? $personName : 'For me',
            'notes' => $notes,
            'base_price' => $style['price'],
            'customization_total' => $extraTotal,
            'total' => $style['price'] + $extraTotal,
            'choices' => $selected,
            'summary' => $summary
        ];
        $existingItem !== null ? demo_cart_update($existingItem['id'], $item) : demo_cart_add($item);
        header('Location: cart.php?added=1');
        exit;
    }
}

$personQueryParam = ($luxePersonIndex !== null) ? ($luxePersonIndex + 1) : 1;
$luxeQuery = 'luxe=1&person=' . urlencode((string) $personQueryParam)
    . '&garment=' . urlencode($garmentParam)
    . ($luxeGarmentIndex !== null ? '&garment_idx=' . urlencode((string) $luxeGarmentIndex) : '');

$changeStyleUrl = $isLuxe ? ('blouse-styles.php?' . $luxeQuery) : 'blouse-styles.php';

include __DIR__ . '/includes/header.php';
?>
<main class="customize-page" data-customizer data-base-price="<?php echo $style['price']; ?>">
    <section class="customize-intro">
        <?php if ($isLuxe): ?>
            <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; margin-bottom: 16px;">
                <a href="luxe-workspace.php" class="demo-back" style="margin-bottom: 0;">← Back to Luxe Order Workspace</a>
                <a href="<?php echo htmlspecialchars($changeStyleUrl); ?>" style="font-size: 13px; color: #87521f; font-weight: 600; text-decoration: none;">← Change style</a>
            </div>
            <p class="demo-eyebrow">Luxe Garment Customization</p>
            <div style="display: inline-flex; align-items: center; gap: 8px; padding: 6px 14px; margin-bottom: 12px; background: #fff8f1; border: 1px solid #ead7c1; border-radius: 999px; font-size: 13px; color: #6d5651;">
                <span style="color: #9d6c24; font-weight: 600;">Person <?php echo $personQueryParam; ?>: <?php echo htmlspecialchars($personName); ?><?php if (!empty($personRole)): ?> (<?php echo htmlspecialchars($personRole); ?>)<?php endif; ?></span>
                <span>•</span>
                <strong><?php echo htmlspecialchars($garmentParam); ?></strong>
            </div>
        <?php else: ?>
            <a href="blouse-styles.php" class="demo-back">← Change style</a>
            <p class="demo-eyebrow"><?php echo $editingId !== '' ? 'Edit your garment' : 'Step 2 of 3'; ?></p>
        <?php endif; ?>
        <h1><?php echo htmlspecialchars($style['name']); ?></h1>
        <p>Starting from ₹<?php echo number_format($style['price']); ?>. Final price updates as you customise.</p>
    </section>
    <form method="post" id="customization-form" class="customize-layout" data-customization-form>
        <input type="hidden" name="style" value="<?php echo htmlspecialchars($style['slug']); ?>">
        <?php if ($isLuxe): ?>
            <input type="hidden" name="luxe" value="1">
            <input type="hidden" name="person" value="<?php echo htmlspecialchars((string) $personQueryParam); ?>">
            <input type="hidden" name="garment" value="<?php echo htmlspecialchars($garmentParam); ?>">
            <?php if ($luxeGarmentIndex !== null): ?>
                <input type="hidden" name="garment_idx" value="<?php echo htmlspecialchars((string) $luxeGarmentIndex); ?>">
            <?php endif; ?>
        <?php else: ?>
            <input type="hidden" name="edit_id" value="<?php echo htmlspecialchars($editingId); ?>">
        <?php endif; ?>

        <aside class="customize-visual">
            <img src="<?php echo htmlspecialchars($style['image']); ?>" alt="<?php echo htmlspecialchars($style['name']); ?>">
            <div>
                <span>Selected style</span>
                <strong><?php echo htmlspecialchars($style['name']); ?></strong>
            </div>
        </aside>

        <div class="customize-controls">
            <section class="customize-person">
                <label for="person_name">Who is this garment for?</label>
                <?php if ($isLuxe): ?>
                    <input id="person_name" name="person_name" value="<?php echo htmlspecialchars($personName); ?>" readonly style="background: #faf6f0; cursor: default; font-weight: 500;">
                    <p style="margin: 8px 0 0; font-size: 12px; color: #887265;">This blouse is assigned to <strong><?php echo htmlspecialchars($personName); ?></strong> in your Luxe Wedding Order.</p>
                <?php else: ?>
                    <input id="person_name" name="person_name" value="<?php echo htmlspecialchars($personName); ?>" placeholder="For example: Mom, Sister, or Me">
                <?php endif; ?>
            </section>

            <?php foreach ($options as $field => $option): ?>
                <fieldset class="customize-group">
                    <legend>
                        <?php echo htmlspecialchars($option['label']); ?>
                        <?php if ($option['required']): ?> <span>*</span><?php endif; ?>
                    </legend>
                    <div class="choice-grid">
                        <?php foreach ($option['choices'] as $choice): ?>
                            <?php $isChecked = ($selected[$field] ?? $option['choices'][0]['value']) === $choice['value']; ?>
                            <label class="choice-option">
                                <input type="radio" name="choices[<?php echo htmlspecialchars($field); ?>]" value="<?php echo htmlspecialchars($choice['value']); ?>" data-price="<?php echo $choice['price']; ?>" <?php echo $isChecked ? 'checked' : ''; ?>>
                                <span>
                                    <?php echo htmlspecialchars($choice['label']); ?>
                                    <?php if ($choice['price'] > 0): ?> <em>+₹<?php echo $choice['price']; ?></em><?php endif; ?>
                                </span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </fieldset>
            <?php endforeach; ?>

            <section class="customize-notes">
                <label for="notes">Reference or notes <small>(optional)</small></label>
                <textarea id="notes" name="notes" rows="3" placeholder="Share a fitting preference, reference detail, or special request."><?php echo htmlspecialchars($notes); ?></textarea>
            </section>
        </div>

        <aside class="price-summary" aria-live="polite">
            <p class="demo-eyebrow">Your garment</p>
            <h2>Price summary</h2>
            <p><span>Base stitching</span><strong>₹<?php echo number_format($style['price']); ?></strong></p>
            <p><span>Customisation</span><strong data-customization-price>₹0</strong></p>
            <div><span>Current item total</span><strong data-current-total>₹<?php echo number_format($style['price']); ?></strong></div>
            <?php if ($isLuxe): ?>
                <button class="demo-primary-action" type="submit">Save Blouse to Workspace</button>
                <a href="luxe-workspace.php" class="summary-cart-link">Return to Luxe Workspace</a>
            <?php else: ?>
                <button class="demo-primary-action" type="submit"><?php echo $editingId !== '' ? 'Save Changes' : 'Add This Blouse to Order'; ?></button>
                <a href="cart.php" class="summary-cart-link">View order (<?php echo demo_cart_count(); ?>)</a>
            <?php endif; ?>
        </aside>
    </form>
</main>
<div class="mobile-order-action" data-mobile-price-bar>
    <div><span>Current total</span><strong data-current-total>₹<?php echo number_format($style['price']); ?></strong></div>
    <button type="submit" form="customization-form"><?php echo $isLuxe ? 'Save to Workspace' : ($editingId !== '' ? 'Save Changes' : 'Add to Order'); ?></button>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
