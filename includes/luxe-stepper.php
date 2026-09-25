<?php
declare(strict_types=1);

/**
 * SHAGUN LADIES TAILOR — LUXE STEPPER COMPONENT
 *
 * One consistent, reusable smart stepper across the entire Luxe Stitching flow.
 * Provides data-driven step definitions, session-aware state evaluation,
 * accessible markup, and smooth navigation shortcuts.
 */

if (!function_exists('get_luxe_stepper_definitions')) {
    /**
     * Returns the master list of Luxe steps.
     * Future steps can be appended here without rewriting the component.
     *
     * @return array<int, array{id: string, number: int, label: string, url: string}>
     */
    function get_luxe_stepper_definitions(): array
    {
        $workflow = 'wedding';
        if (isset($_SESSION['luxe_wedding']) && is_array($_SESSION['luxe_wedding']) && !empty($_SESSION['luxe_wedding']['workflow'])) {
            $workflow = $_SESSION['luxe_wedding']['workflow'];
        }
        $step1Url = ($workflow === 'family') ? 'luxe-family.php' : 'luxe-wedding.php';

        return [
            [
                'id' => 'details',
                'number' => 1,
                'label' => 'Details',
                'url' => $step1Url,
            ],
            [
                'id' => 'people',
                'number' => 2,
                'label' => 'People',
                'url' => 'people.php',
            ],
            [
                'id' => 'garments',
                'number' => 3,
                'label' => 'Garments',
                'url' => 'garments.php',
            ],
            [
                'id' => 'customize',
                'number' => 4,
                'label' => 'Customize',
                'url' => 'luxe-workspace.php',
            ],
            [
                'id' => 'measurements',
                'number' => 5,
                'label' => 'Measurements',
                'url' => 'measurements.php',
            ],
            [
                'id' => 'review',
                'number' => 6,
                'label' => 'Review',
                'url' => 'review-payment.php',
            ],
            [
                'id' => 'payment',
                'number' => 7,
                'label' => 'Payment',
                'url' => 'payment.php',
            ],
        ];
    }
}

if (!function_exists('resolve_luxe_stepper_states')) {
    /**
     * Resolves the visual/workflow state for each step based on session data
     * and page context.
     *
     * State hierarchy:
     * - 'completed' : Step finished in workflow, clickable shortcut to return.
     * - 'current'   : Step currently being viewed on this page.
     * - 'available' : Step unlocked ahead or accessible in current workflow.
     * - 'locked'    : Step not yet unlocked, non-clickable with lock icon.
     *
     * @param int $currentStep Current 1-based step index
     * @param array<string, mixed> $options Optional state overrides
     * @return array<int, array{id: string, number: int, label: string, url: string, state: string}>
     */
    function resolve_luxe_stepper_states(int $currentStep, array $options = []): array
    {
        $steps = get_luxe_stepper_definitions();
        $luxe = $_SESSION['luxe_wedding'] ?? [];
        $isDemo = !empty($_GET['demo']) || !empty($luxe['demo_mode']);

        // 1. Details detection
        $hasRequestedDate = !empty($options['requested_ready_date']) || !empty($options['wedding_date']) || !empty($luxe['requested_ready_date']) || !empty($luxe['wedding_date']);

        // 2. People & Garment stats
        $people = $options['people'] ?? ($luxe['people'] ?? []);
        $peopleCount = is_array($people) ? count($people) : 0;
        $hasNamedPerson = false;
        $totalGarments = 0;
        $completedGarments = 0;
        $allMeasurementsSelected = ($peopleCount > 0);

        if ($peopleCount > 0) {
            foreach ($people as $person) {
                $pName = trim((string) ($person['name'] ?? ''));
                if ($pName !== '') {
                    $hasNamedPerson = true;
                }
                $garments = $person['garments'] ?? [];
                if (is_array($garments) && !empty($garments)) {
                    foreach ($garments as $g) {
                        $totalGarments++;
                        if (is_array($g) && ($g['status'] ?? '') === 'completed') {
                            $completedGarments++;
                        }
                        $gMethod = is_array($g) ? ($g['measurement_method'] ?? ($person['measurement_method'] ?? null)) : null;
                        if (empty($gMethod) || !in_array($gMethod, ['reference_blouse', 'visit_shop'], true)) {
                            $allMeasurementsSelected = false;
                        }
                    }
                } else {
                    $totalGarments++;
                    $mMethod = $person['measurement_method'] ?? null;
                    if (empty($mMethod) || !in_array($mMethod, ['reference_blouse', 'visit_shop'], true)) {
                        $allMeasurementsSelected = false;
                    }
                }
            }
        }

        // Standard stitching items in unified cart check
        if (function_exists('get_unified_basket')) {
            $unified = get_unified_basket();
            if (!empty($unified['standard_items'])) {
                foreach ($unified['standard_items'] as $sItem) {
                    $sMethod = $sItem['measurement_method'] ?? ($_SESSION['standard_order']['measurement_method'] ?? null);
                    if (empty($sMethod) || !in_array($sMethod, ['reference_blouse', 'visit_shop'], true)) {
                        $allMeasurementsSelected = false;
                    }
                }
            }
        }

        // Demo fallback simulation
        if ($isDemo) {
            if ($peopleCount === 0) {
                $peopleCount = 3;
                $hasNamedPerson = true;
            }
            if ($totalGarments === 0) {
                $totalGarments = 4;
                $completedGarments = 1;
            }
        }

        // Unlock permissions
        $canProceedPeople = true; // Step 2 always accessible
        $canProceedGarments = ($peopleCount > 0 || $currentStep >= 3);
        $canProceedCustomize = ($totalGarments > 0 || $currentStep >= 4);

        // Measurements unlocks as soon as at least 1 garment is completed
        $canProceedMeasurements = array_key_exists('can_proceed_measurements', $options)
            ? (bool) $options['can_proceed_measurements']
            : ($completedGarments >= 1 || $currentStep >= 5);

        // Review requires ALL garments completed and all measurements chosen
        $canProceedReview = array_key_exists('can_proceed_review', $options)
            ? (bool) $options['can_proceed_review']
            : ($totalGarments > 0 && $completedGarments === $totalGarments && $allMeasurementsSelected);

        // Payment status
        $isPaymentCompleted = isset($luxe['payment']['status']) && $luxe['payment']['status'] === 'completed';
        if (isset($options['payment_state']) && $options['payment_state'] === 'success') {
            $isPaymentCompleted = true;
        }

        $canProceedPayment = $canProceedReview || ($currentStep >= 7);

        $customStates = $options['step_states'] ?? [];

        $resolved = [];
        foreach ($steps as $step) {
            $num = $step['number'];
            $id = $step['id'];

            if (isset($customStates[$num])) {
                $state = $customStates[$num];
            } elseif (isset($customStates[$id])) {
                $state = $customStates[$id];
            } elseif ($num === $currentStep) {
                if ($num === 7 && $isPaymentCompleted) {
                    $state = 'completed';
                } else {
                    $state = 'current';
                }
            } elseif ($num < $currentStep) {
                // Steps prior to the current step are completed
                $state = 'completed';
            } else {
                // Future steps (num > currentStep)
                switch ($num) {
                    case 2:
                        $state = $canProceedPeople ? 'available' : 'locked';
                        break;
                    case 3:
                        $state = $canProceedGarments ? 'available' : 'locked';
                        break;
                    case 4:
                        $state = $canProceedCustomize ? 'available' : 'locked';
                        break;
                    case 5:
                        $state = $canProceedMeasurements ? 'available' : 'locked';
                        break;
                    case 6:
                        $state = $canProceedReview ? 'available' : 'locked';
                        break;
                    case 7:
                        if ($isPaymentCompleted) {
                            $state = 'completed';
                        } elseif ($canProceedPayment) {
                            $state = 'available';
                        } else {
                            $state = 'locked';
                        }
                        break;
                    default:
                        $state = 'locked';
                        break;
                }
            }

            $step['state'] = $state;
            $resolved[] = $step;
        }

        return $resolved;
    }
}

if (!function_exists('render_luxe_stepper')) {
    /**
     * Renders the complete, responsive Luxe Stepper HTML.
     *
     * @param int $currentStep Current 1-based step index
     * @param array<string, mixed> $options Optional settings
     * @return void
     */
    function render_luxe_stepper(int $currentStep, array $options = []): void
    {
        $steps = resolve_luxe_stepper_states($currentStep, $options);
        $totalSteps = count($steps);

        $currentStepLabel = '';
        foreach ($steps as $s) {
            if ($s['number'] === $currentStep) {
                $currentStepLabel = $s['label'];
                break;
            }
        }
        ?>
        <nav class="luxe-stepper-section luxe-workspace-progress-section" aria-label="Luxe order progress">
            <div class="luxe-stepper-container">
                <!-- Mobile active step indicator (compact, visible only on mobile) -->
                <div class="luxe-stepper-mobile-current" aria-live="polite">
                    <span class="luxe-mobile-step-pill">Step <span data-mobile-step-num><?php echo (int) $currentStep; ?></span> of <?php echo (int) $totalSteps; ?></span>
                    <strong class="luxe-mobile-step-name" data-mobile-step-name><?php echo htmlspecialchars($currentStepLabel); ?></strong>
                </div>

                <div class="luxe-stepper-scroll" data-luxe-stepper data-current-step="<?php echo (int) $currentStep; ?>">
                    <ol class="luxe-stepper-track luxe-workspace-stepper">
                        <?php foreach ($steps as $index => $step): ?>
                            <?php
                            $state = $step['state'];
                            $num = $step['number'];
                            $label = $step['label'];
                            $url = $step['url'];

                            // Divider line before step (starting at step 2)
                            if ($index > 0) {
                                $prevStep = $steps[$index - 1];
                                $prevState = $prevStep['state'];

                                $dividerIsDone = (
                                    ($prevState === 'completed' && ($state === 'completed' || $state === 'current'))
                                );
                                $dividerClass = $dividerIsDone ? 'is-completed is-done' : 'is-future';
                                echo '<li class="luxe-stepper-divider luxe-workspace-step-line ' . $dividerClass . '" aria-hidden="true"></li>';
                            }
                            ?>
                            <li class="luxe-stepper-item"
                                data-step="<?php echo (int) $num; ?>"
                                data-label="<?php echo htmlspecialchars($label); ?>"
                                data-state="<?php echo htmlspecialchars($state); ?>">
                                <?php if ($state === 'completed'): ?>
                                    <a href="<?php echo htmlspecialchars($url); ?>"
                                       class="luxe-step is-completed luxe-workspace-step is-done"
                                       aria-label="Step <?php echo $num; ?>: <?php echo htmlspecialchars($label); ?> (Completed) - Click to return">
                                        <span class="luxe-step-circle">
                                            <svg class="luxe-step-icon-check" viewBox="0 0 16 16" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                                <polyline points="3 8.5 6.5 12 13 4"></polyline>
                                            </svg>
                                        </span>
                                        <strong><?php echo htmlspecialchars($label); ?></strong>
                                    </a>
                                <?php elseif ($state === 'current'): ?>
                                    <div class="luxe-workspace-step is-active"
                                         aria-current="step"
                                         aria-label="Step <?php echo $num; ?>: <?php echo htmlspecialchars($label); ?> (Current step)">
                                        <span class="luxe-step-circle"><?php echo $num; ?></span>
                                        <strong><?php echo htmlspecialchars($label); ?></strong>
                                    </div>
                                <?php elseif ($state === 'available'): ?>
                                    <a href="<?php echo htmlspecialchars($url); ?>"
                                       class="luxe-step is-available luxe-workspace-step is-available"
                                       aria-label="Step <?php echo $num; ?>: <?php echo htmlspecialchars($label); ?> (Available) - Click to navigate">
                                        <span class="luxe-step-circle"><?php echo $num; ?></span>
                                        <strong><?php echo htmlspecialchars($label); ?></strong>
                                    </a>
                                <?php else: /* locked */ ?>
                                    <div class="luxe-step is-locked luxe-workspace-step"
                                         aria-disabled="true"
                                         aria-label="Step <?php echo $num; ?>: <?php echo htmlspecialchars($label); ?> — locked">
                                        <span class="luxe-step-circle">
                                            <svg class="luxe-step-icon-lock" viewBox="0 0 24 24" width="13" height="13" fill="currentColor" aria-hidden="true">
                                                <path d="M18 8h-1V6c0-2.76-2.24-5-5-5S7 3.24 7 6v2H6c-1.1 0-2 .9-2 2v10c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V10c0-1.1-.9-2-2-2zm-6 9c-1.1 0-2-.9-2-2s.9-2 2-2 2 .9 2 2-.9 2-2 2zm3.1-9H8.9V6c0-1.71 1.39-3.1 3.1-3.1 1.71 0 3.1 1.39 3.1 3.1v2z"/>
                                            </svg>
                                        </span>
                                        <strong><?php echo htmlspecialchars($label); ?></strong>
                                    </div>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ol>
                </div>
            </div>
        </nav>
        <?php
    }
}
