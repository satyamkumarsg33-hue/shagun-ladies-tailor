<?php

declare(strict_types=1);

require_once __DIR__ . '/cart.php';

/**
 * Normalizes and aggregates items from both Standard Cart and Luxe Workspace
 * into a single unified basket model with 4 distinct categories:
 *  1. Standard Stitching
 *  2. Luxe Stitching
 *  3. Hand Work
 *  4. Machine Work
 */
function get_unified_basket(): array
{
    demo_cart_bootstrap();

    $rawCart = demo_cart_items();
    if (isset($rawCart['items']) && is_array($rawCart['items'])) {
        $standardCartItems = $rawCart['items'];
    } elseif (is_array($rawCart)) {
        $standardCartItems = $rawCart;
    } else {
        $standardCartItems = [];
    }

    // Filter out non-array meta values
    $cleanStdItems = [];
    foreach ($standardCartItems as $k => $it) {
        if (is_array($it)) {
            $cleanStdItems[$k] = $it;
        }
    }
    $standardCartItems = $cleanStdItems;

    $luxeWedding = $_SESSION['luxe_wedding'] ?? [];
    // An already completed/paid Luxe session is not an active unpurchased draft
    $hasActiveLuxe = function_exists('has_active_luxe_draft') 
        ? has_active_luxe_draft() 
        : (!empty($luxeWedding['people']) && !(function_exists('is_luxe_order_completed') && is_luxe_order_completed($luxeWedding)));
    $luxePeople = ($hasActiveLuxe && isset($luxeWedding['people']) && is_array($luxeWedding['people'])) ? $luxeWedding['people'] : [];

    $hasStandard = !empty($standardCartItems);
    $hasLuxe = false;
    $hasSeparateCloth = false;

    $standardItems = [];
    $luxeItems = [];
    $allItems = [];

    // Category accumulators
    $categoryTotals = [
        'standard_stitching' => [
            'key' => 'standard_stitching',
            'label' => 'Standard Stitching',
            'badge' => 'STANDARD STITCHING',
            'count' => 0,
            'amount' => 0,
        ],
        'luxe_stitching' => [
            'key' => 'luxe_stitching',
            'label' => 'Luxe Stitching',
            'badge' => 'LUXE STITCHING',
            'count' => 0,
            'amount' => 0,
        ],
        'hand_work' => [
            'key' => 'hand_work',
            'label' => 'Hand Work',
            'badge' => 'HAND WORK',
            'count' => 0,
            'amount' => 0,
        ],
        'machine_work' => [
            'key' => 'machine_work',
            'label' => 'Machine Work',
            'badge' => 'MACHINE WORK',
            'count' => 0,
            'amount' => 0,
        ],
    ];

    // 1. Process Standard Cart Items
    $stdItemNum = 0;
    foreach ($standardCartItems as $rawKey => $item) {
        $stdItemNum++;
        $id = (string) ($item['id'] ?? ('std_' . $stdItemNum));
        $garmentName = (string) ($item['garment'] ?? ($item['title'] ?? 'Blouse'));
        $styleName = (string) ($item['style_name'] ?? ($item['title'] ?? 'Custom Tailored Blouse'));
        $styleSlug = (string) ($item['style_slug'] ?? 'blouse');
        $personName = (string) ($item['person_name'] ?? 'Self');
        $basePrice = (int) ($item['base_price'] ?? ($item['price'] ?? 650));
        $customizationTotal = (int) ($item['customization_total'] ?? 0);
        $total = (int) ($item['total'] ?? ($item['total_price'] ?? ($item['price'] ?? ($basePrice + $customizationTotal))));
        $choices = $item['choices'] ?? [];
        $summary = $item['summary'] ?? [];
        $notes = (string) ($item['notes'] ?? '');
        $image = (string) ($item['image'] ?? 'assets/images/blouse.jpg');

        // Detect work in standard items
        $workType = 'no_work';
        $workPrice = 0;
        $machineWork = null;
        $handWork = null;

        if (isset($choices['embroidery'])) {
            if ($choices['embroidery'] === 'machine') {
                $workType = 'machine';
                $workPrice = 250;
                $machineWork = [
                    'design_code' => 'M-018',
                    'design_name' => 'Machine Embroidery',
                    'price' => 250,
                    'placement' => 'Neck & Sleeves',
                    'work_target' => 'garment',
                ];
            } elseif ($choices['embroidery'] === 'hand') {
                $workType = 'hand';
                $workPrice = 500;
                $handWork = [
                    'design_code' => 'H-012',
                    'design_name' => 'Hand Embroidery',
                    'price' => 500,
                    'placement' => 'Neck & Sleeves',
                    'work_target' => 'garment',
                ];
            }
        } else {
            foreach ($summary as $sRow) {
                $sLabel = strtolower((string) ($sRow['label'] ?? ''));
                $sField = strtolower((string) ($sRow['field'] ?? ''));
                if (strpos($sField, 'embroidery') !== false || strpos($sLabel, 'machine') !== false) {
                    if (strpos($sLabel, 'machine') !== false) {
                        $workType = 'machine';
                        $workPrice = (int) ($sRow['price'] ?? 250);
                        $machineWork = [
                            'design_code' => 'M-018',
                            'design_name' => 'Machine Embroidery',
                            'price' => $workPrice,
                            'placement' => 'Neck & Sleeves',
                            'work_target' => 'garment',
                        ];
                        break;
                    } elseif (strpos($sLabel, 'hand') !== false) {
                        $workType = 'hand';
                        $workPrice = (int) ($sRow['price'] ?? 500);
                        $handWork = [
                            'design_code' => 'H-012',
                            'design_name' => 'Hand Embroidery',
                            'price' => $workPrice,
                            'placement' => 'Neck & Sleeves',
                            'work_target' => 'garment',
                        ];
                        break;
                    }
                }
            }
        }

        $stitchingPrice = $total - $workPrice;
        $categoryTotals['standard_stitching']['count']++;
        $categoryTotals['standard_stitching']['amount'] += $stitchingPrice;

        if ($workType === 'machine') {
            $categoryTotals['machine_work']['count']++;
            $categoryTotals['machine_work']['amount'] += $workPrice;
        } elseif ($workType === 'hand') {
            $categoryTotals['hand_work']['count']++;
            $categoryTotals['hand_work']['amount'] += $workPrice;
        }

        // Customization choices excluding ₹0
        $cleanChoices = [];
        foreach ($summary as $sRow) {
            $cPrice = (int) ($sRow['price'] ?? 0);
            $sField = strtolower((string) ($sRow['field'] ?? ''));
            if (strpos($sField, 'embroidery') !== false) {
                continue;
            }
            if ($cPrice > 0) {
                $cleanChoices[] = [
                    'field' => (string) ($sRow['field'] ?? 'Option'),
                    'label' => (string) ($sRow['label'] ?? ''),
                    'price' => $cPrice,
                ];
            }
        }

        $badges = ['STANDARD STITCHING'];
        if ($workType === 'machine') {
            $badges[] = 'MACHINE WORK';
        } elseif ($workType === 'hand') {
            $badges[] = 'HAND WORK';
        }

        $stdMethod = in_array($item['measurement_method'] ?? ($_SESSION['standard_order']['measurement_method'] ?? ''), ['reference_blouse', 'visit_shop'], true)
            ? ($item['measurement_method'] ?? $_SESSION['standard_order']['measurement_method'])
            : null;

        $normItem = [
            'id' => $id,
            'source' => 'standard',
            'type' => 'Standard Stitching',
            'badges' => $badges,
            'customer_name' => $personName,
            'person_name' => $personName,
            'person_role' => 'Self',
            'measurement_method' => $stdMethod,
            'garment_name' => $garmentName,
            'item_number' => $stdItemNum,
            'item_label' => $garmentName . ' #' . $stdItemNum,
            'style_name' => $styleName,
            'style_slug' => $styleSlug,
            'image' => $image,
            'base_price' => $basePrice,
            'stitching_price' => $stitchingPrice,
            'customization_choices' => $cleanChoices,
            'work_type' => $workType,
            'machine_work' => $machineWork,
            'hand_work' => $handWork,
            'work_price' => $workPrice,
            'total_price' => $total,
            'notes' => $notes,
            'edit_url' => 'customize-blouse.php?style=' . urlencode($styleSlug) . '&edit=' . urlencode($id),
            'remove_url' => 'cart.php?remove=' . urlencode($id),
        ];

        $standardItems[] = $normItem;
        $allItems[] = $normItem;
    }

    // 2. Process Luxe Workspace Garments
    if (is_array($luxePeople) && !empty($luxePeople)) {
        foreach ($luxePeople as $personIndex => $person) {
            if (!isset($person['garments']) || !is_array($person['garments'])) {
                continue;
            }

            $personName = trim((string) ($person['name'] ?? ('Person ' . ($personIndex + 1))));
            $personRole = trim((string) ($person['role'] ?? ''));
            $personMethod = in_array($person['measurement_method'] ?? '', ['reference_blouse', 'visit_shop'], true)
                ? (string) $person['measurement_method']
                : null;
            $typeCounters = [];

            foreach ($person['garments'] as $garmentIndex => $garment) {
                if (!is_array($garment)) {
                    continue;
                }

                $machineWork = $garment['machine_work'] ?? null;
                $handWork = $garment['hand_work'] ?? null;
                $machinePrice = !empty($machineWork['price']) ? (int) $machineWork['price'] : 0;
                $handPrice = !empty($handWork['price']) ? (int) $handWork['price'] : 0;
                $workTotal = $machinePrice + $handPrice;

                $rawWorkType = isset($garment['work_type']) ? (string) $garment['work_type'] : '';
                $hasMw = !empty($machineWork) && ((!empty($machineWork['type']) && $machineWork['type'] !== 'none') || $machinePrice > 0);
                $hasHw = !empty($handWork) && ((!empty($handWork['type']) && $handWork['type'] !== 'none') || $handPrice > 0);

                if (!empty($rawWorkType)) {
                    $workType = $rawWorkType;
                } elseif ($hasMw && $hasHw) {
                    $workType = 'both';
                } elseif ($hasMw) {
                    $workType = 'machine';
                } elseif ($hasHw) {
                    $workType = 'hand';
                } else {
                    $workType = 'no_work';
                }

                $gStatus = (string) ($garment['status'] ?? 'not-started');
                $isCust = !empty($garment['style_slug']);
                $isW = ($workType === 'no_work') ||
                    ($workType === 'machine' && !empty($machineWork)) ||
                    ($workType === 'hand' && !empty($handWork)) ||
                    ($workType === 'both' && !empty($machineWork) && !empty($handWork));

                $isCompleted = ($gStatus === 'completed') || ($isCust && $isW);
                $finalStatus = $isCompleted ? 'completed' : ($isCust || $gStatus === 'in-progress' || (!empty($rawWorkType) && $rawWorkType !== 'no_work') ? 'in-progress' : 'not-started');

                $hasLuxe = true;
                $gName = (string) ($garment['name'] ?? 'Blouse');
                $typeCounters[$gName] = ($typeCounters[$gName] ?? 0) + 1;
                $numberedLabel = $gName . ' #' . $typeCounters[$gName];

                $styleSlug = (string) ($garment['style_slug'] ?? 'princess-cut');
                $styleName = (string) ($garment['style_name'] ?? ($isCust ? ($gName . ' (' . $styleSlug . ')') : 'Standard Style'));
                $basePrice = (int) ($garment['base_price'] ?? ($gName === 'Saree' ? 400 : ($gName === 'Lehenga' ? 1200 : 650)));
                $customizationTotal = (int) ($garment['customization_total'] ?? 0);
                $totalPrice = isset($garment['total_price'])
                    ? (int) $garment['total_price']
                    : (isset($garment['price']) ? (int) $garment['price'] : ($basePrice + $customizationTotal + $workTotal));
                $stitchingPrice = $totalPrice - $workTotal;

                // Category totals
                $categoryTotals['luxe_stitching']['count']++;
                $categoryTotals['luxe_stitching']['amount'] += $stitchingPrice;

                if ($machinePrice > 0) {
                    $categoryTotals['machine_work']['count']++;
                    $categoryTotals['machine_work']['amount'] += $machinePrice;
                }
                if ($handPrice > 0) {
                    $categoryTotals['hand_work']['count']++;
                    $categoryTotals['hand_work']['amount'] += $handPrice;
                }

                // Clean choice summary (non-zero prices)
                $cleanChoices = [];
                if (!empty($garment['choice_summary']) && is_array($garment['choice_summary'])) {
                    foreach ($garment['choice_summary'] as $cs) {
                        $p = (int) ($cs['price'] ?? 0);
                        if ($p > 0) {
                            $cleanChoices[] = [
                                'field' => (string) ($cs['field'] ?? ''),
                                'label' => (string) ($cs['label'] ?? ''),
                                'price' => $p,
                            ];
                        }
                    }
                }

                // Badges
                $badges = ['LUXE STITCHING'];
                if ($workType === 'machine') {
                    $badges[] = 'MACHINE WORK';
                } elseif ($workType === 'hand') {
                    $badges[] = 'HAND WORK';
                } elseif ($workType === 'both') {
                    $badges[] = 'MACHINE WORK';
                    $badges[] = 'HAND WORK';
                }

                // Target checks
                $hwTarget = $garment['hand_work_target'] ?? ($handWork['work_target'] ?? '');
                $mwTarget = $garment['machine_work_target'] ?? ($machineWork['work_target'] ?? '');
                $hwDesc = $garment['hand_material_description'] ?? ($handWork['material_description'] ?? '');
                $mwDesc = $garment['machine_material_description'] ?? ($machineWork['material_description'] ?? '');
                $isSeparate = ($hwTarget === 'separate_cloth' || $hwTarget === 'separate_material' || $mwTarget === 'separate_cloth' || $mwTarget === 'separate_material');
                if ($isSeparate) {
                    $hasSeparateCloth = true;
                    if (!in_array('SEPARATE MATERIAL', $badges, true)) {
                        $badges[] = 'SEPARATE MATERIAL';
                    }
                }

                $editUrl = 'customize-blouse.php?style=' . urlencode($styleSlug) . '&luxe=1&person=' . ($personIndex + 1) . '&garment=' . urlencode($gName) . '&garment_idx=' . $garmentIndex;

                $garmentMeasurementMethod = in_array($garment['measurement_method'] ?? '', ['reference_blouse', 'visit_shop'], true)
                    ? (string) $garment['measurement_method']
                    : $personMethod;

                $normLuxeItem = [
                    'id' => 'luxe_' . $personIndex . '_' . $garmentIndex,
                    'source' => 'luxe',
                    'type' => 'Luxe Stitching',
                    'badges' => $badges,
                    'customer_name' => $personName,
                    'person_name' => $personName,
                    'person_role' => $personRole,
                    'person_index' => $personIndex,
                    'garment_index' => $garmentIndex,
                    'measurement_method' => $garmentMeasurementMethod,
                    'garment_name' => $gName,
                    'item_number' => $typeCounters[$gName],
                    'item_label' => $numberedLabel,
                    'style_name' => $styleName,
                    'style_slug' => $styleSlug,
                    'base_price' => $basePrice,
                    'stitching_price' => $stitchingPrice,
                    'customization_choices' => $cleanChoices,
                    'work_type' => $workType,
                    'machine_work' => $machineWork,
                    'hand_work' => $handWork,
                    'machine_work_target' => $mwTarget,
                    'hand_work_target' => $hwTarget,
                    'machine_material_description' => $mwDesc,
                    'hand_material_description' => $hwDesc,
                    'work_price' => $workTotal,
                    'total_price' => $totalPrice,
                    'status' => $finalStatus,
                    'is_completed' => $isCompleted,
                    'notes' => (string) ($garment['notes'] ?? ''),
                    'edit_url' => $editUrl,
                ];

                $luxeItems[] = $normLuxeItem;
                $allItems[] = $normLuxeItem;
            }
        }
    }

    $grandTotal = 0;
    foreach ($categoryTotals as $cat) {
        $grandTotal += $cat['amount'];
    }

    $counts = [
        'standard' => $categoryTotals['standard_stitching']['count'],
        'luxe' => $categoryTotals['luxe_stitching']['count'],
        'hand_work' => $categoryTotals['hand_work']['count'],
        'machine_work' => $categoryTotals['machine_work']['count'],
    ];

    $categoryAmounts = [
        'standard' => $categoryTotals['standard_stitching']['amount'],
        'luxe' => $categoryTotals['luxe_stitching']['amount'],
        'hand_work' => $categoryTotals['hand_work']['amount'],
        'machine_work' => $categoryTotals['machine_work']['amount'],
        'standard_stitching' => $categoryTotals['standard_stitching']['amount'],
        'luxe_stitching' => $categoryTotals['luxe_stitching']['amount'],
    ];

    $minAdvancePercent = 30;
    $minAdvanceAmount = (int) ceil($grandTotal * ($minAdvancePercent / 100));
    $maxAdvanceAmount = $grandTotal;
    $initialAdvanceAmount = (int) round($grandTotal * 0.50);
    if ($initialAdvanceAmount < $minAdvanceAmount) {
        $initialAdvanceAmount = $minAdvanceAmount;
    }

    $luxePeopleCount = is_array($luxePeople) ? count($luxePeople) : 0;
    $standardGarmentCount = count($standardItems);
    $luxeGarmentCount = count($luxeItems);
    $totalPhysicalGarments = $standardGarmentCount + $luxeGarmentCount;
    $hasIncompleteLuxe = false;
    foreach ($luxeItems as $li) {
        if (empty($li['is_completed'])) {
            $hasIncompleteLuxe = true;
            break;
        }
    }

    $hasMissingMeasurement = false;
    foreach ($allItems as $it) {
        if (empty($it['measurement_method'])) {
            $hasMissingMeasurement = true;
            break;
        }
    }

    return [
        'has_standard' => $hasStandard,
        'has_luxe' => $hasLuxe,
        'is_combined' => ($hasStandard && $hasLuxe),
        'has_separate_cloth' => $hasSeparateCloth,
        'has_incomplete_luxe' => $hasIncompleteLuxe,
        'has_missing_measurement' => $hasMissingMeasurement,
        'standard_items' => $standardItems,
        'luxe_items' => $luxeItems,
        'all_items' => $allItems,
        'item_count' => count($allItems),
        'total_items' => count($allItems),
        'standard_item_count' => $standardGarmentCount,
        'luxe_garment_count' => $luxeGarmentCount,
        'luxe_people_count' => $luxePeopleCount,
        'total_physical_garments' => $totalPhysicalGarments,
        'total_physical_garment_count' => $totalPhysicalGarments,
        'category_totals' => $categoryAmounts,
        'category_breakdown' => $categoryTotals,
        'counts' => $counts,
        'grand_total' => $grandTotal,
        'min_advance_percent' => $minAdvancePercent,
        'min_advance_amount' => $minAdvanceAmount,
        'max_advance_amount' => $maxAdvanceAmount,
        'initial_advance_amount' => $initialAdvanceAmount,
    ];
}

/**
 * Returns HTML markup for luxury category badge(s)
 */
function render_category_badge(string $label): string
{
    $labelTrimmed = trim($label);
    $labelLower = strtolower($labelTrimmed);
    $class = 'badge-standard';
    $displayLabel = strtoupper($labelTrimmed);

    if ($labelLower === 'standard' || $labelLower === 'standard_stitching' || str_contains($labelLower, 'standard stitching')) {
        $class = 'badge-standard';
        $displayLabel = 'STANDARD STITCHING';
    } elseif ($labelLower === 'luxe' || $labelLower === 'luxe_stitching' || str_contains($labelLower, 'luxe stitching')) {
        $class = 'badge-luxe';
        $displayLabel = 'LUXE STITCHING';
    } elseif ($labelLower === 'hand' || $labelLower === 'hand_work' || str_contains($labelLower, 'hand work')) {
        $class = 'badge-handwork';
        $displayLabel = 'HAND WORK';
    } elseif ($labelLower === 'machine' || $labelLower === 'machine_work' || str_contains($labelLower, 'machine work')) {
        $class = 'badge-machinework';
        $displayLabel = 'MACHINE WORK';
    } elseif ($labelLower === 'separate' || $labelLower === 'separate_cloth' || str_contains($labelLower, 'separate material')) {
        $class = 'badge-separate';
        $displayLabel = 'SEPARATE MATERIAL';
    } else {
        if (strpos($displayLabel, 'LUXE') !== false) {
            $class = 'badge-luxe';
        } elseif (strpos($displayLabel, 'HAND') !== false) {
            $class = 'badge-handwork';
        } elseif (strpos($displayLabel, 'MACHINE') !== false) {
            $class = 'badge-machinework';
        } elseif (strpos($displayLabel, 'SEPARATE') !== false) {
            $class = 'badge-separate';
        }
    }

    return '<span class="shagun-category-badge ' . htmlspecialchars($class) . '">' . htmlspecialchars($displayLabel) . '</span>';
}

/**
 * Returns combined or stacked badges for an item
 */
function render_item_category_badges(mixed $itemOrBadges, ?string $defaultSource = null): string
{
    $badges = [];
    if (is_array($itemOrBadges)) {
        // If passed a sequential list of strings e.g. ['STANDARD STITCHING', 'HAND WORK']
        $isStringList = true;
        foreach ($itemOrBadges as $val) {
            if (!is_string($val)) {
                $isStringList = false;
                break;
            }
        }

        if ($isStringList && !empty($itemOrBadges)) {
            $badges = $itemOrBadges;
        } else {
            // It is an item associative array
            if (!empty($itemOrBadges['badges']) && is_array($itemOrBadges['badges'])) {
                $badges = $itemOrBadges['badges'];
            } else {
                $source = $defaultSource ?? ($itemOrBadges['source'] ?? 'standard');
                if ($source === 'luxe') {
                    $badges[] = 'LUXE STITCHING';
                } else {
                    $badges[] = 'STANDARD STITCHING';
                }

                $wType = $itemOrBadges['work_type'] ?? 'no_work';
                if ($wType === 'both') {
                    $badges[] = 'HAND WORK';
                    $badges[] = 'MACHINE WORK';
                } elseif ($wType === 'hand') {
                    $badges[] = 'HAND WORK';
                } elseif ($wType === 'machine') {
                    $badges[] = 'MACHINE WORK';
                }

                $hwTarget = $itemOrBadges['hand_work_target'] ?? '';
                $mwTarget = $itemOrBadges['machine_work_target'] ?? '';
                if ($hwTarget === 'separate_cloth' || $hwTarget === 'separate_material' || $mwTarget === 'separate_cloth' || $mwTarget === 'separate_material') {
                    $badges[] = 'SEPARATE MATERIAL';
                }
            }
        }
    }

    if (empty($badges)) {
        return '';
    }

    $html = '<div class="shagun-badge-group" style="display: inline-flex; gap: 4px; flex-wrap: wrap;">';
    foreach ($badges as $badge) {
        $html .= render_category_badge((string) $badge);
    }
    $html .= '</div>';
    return $html;
}

/**
 * Update measurement method for an existing standard stitching garment in session.
 * Maintains ONE PHYSICAL GARMENT = ONE INDEPENDENT RECORD.
 * Does not create duplicate Luxe garments.
 *
 * @param string $keyOrId Item ID or cart array index
 * @param string $method 'reference_blouse' or 'visit_shop'
 */
function update_standard_garment_measurement(string $keyOrId, string $method): void {
    if (!in_array($method, ['reference_blouse', 'visit_shop'], true)) {
        return;
    }

    if (session_status() === PHP_SESSION_NONE) {
        session_start();
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
