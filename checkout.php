<?php
/**
 * Shagun Ladies Tailor — Standard Stitching Modern Checkout Flow
 * 
 * Flow:
 * Cart → Authentication Check → Measurement & Reference → Review & Payment → Secure Payment → Order Confirmation → Order Dossier
 * 
 * Architectural Guarantees:
 * - Completely separate session context: $_SESSION['standard_order'] (never pollutes $_SESSION['luxe_wedding']).
 * - Authentication: uses existing includes/auth.php (is_user_logged_in, require_user_login).
 *   Already logged in users skip login completely and enter Measurement directly.
 * - No numeric body measurements online: customer selects Reference Blouse OR Visit Shop.
 * - Independent physical garments: each cart item (Blouse #1, Blouse #2, etc.) is preserved with its individual styles, choices, and work.
 * - Advance payment: 30% to 100% selection with live amount calculation, declaration checkbox, and toast notification.
 * - Server-side payment security: amount strictly read from session; client URL parameter tampering is rejected.
 * - Order Dossier integration: official PDF generated via includes/dossier-pdf.php with "Payment Status: Demo Payment Recorded".
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/cart.php';
require_once __DIR__ . '/includes/unified-cart.php';
demo_cart_bootstrap();

$hasLuxeInProgress = false;
if (!empty($_SESSION['luxe_wedding']['people']) && is_array($_SESSION['luxe_wedding']['people'])) {
    foreach ($_SESSION['luxe_wedding']['people'] as $p) {
        if (!empty($p['garments']) && is_array($p['garments'])) {
            $hasLuxeInProgress = true;
            break;
        }
    }
}

// Strictly guard access: Guests are redirected to login.php?redirect=checkout.php
require_user_login('checkout.php');

$user = get_logged_in_user();
$userId = (int) ($user['id'] ?? 0);
$dbProfile = $userId > 0 ? get_customer_profile($userId) : null;

$customerName = trim((string) ($dbProfile['name'] ?? ($user['name'] ?? '')));
if ($customerName === '') {
    $customerName = 'Customer';
}
$customerEmail = trim((string) ($dbProfile['email'] ?? ($user['email'] ?? '')));
$customerPhone = trim((string) ($dbProfile['phone'] ?? ($user['phone'] ?? '')));
$customerAddress = trim((string) ($dbProfile['address'] ?? ''));

if (!isset($_SESSION['standard_order']) || !is_array($_SESSION['standard_order'])) {
    $_SESSION['standard_order'] = [];
}

$cartItems = demo_cart_items();
$cartTotal = demo_cart_total();
$hasCompletedOrder = !empty($_SESSION['standard_order']['payment']['status']) && $_SESSION['standard_order']['payment']['status'] === 'completed';

// If no items in cart and no active confirmed order, return to cart
if (empty($cartItems) && !$hasCompletedOrder) {
    header('Location: cart.php');
    exit;
}

// Convert cart items to independent physical garments with work detection and choice summary
$standardGarments = [];
if (!empty($cartItems)) {
    foreach ($cartItems as $index => $item) {
        $gName = $item['garment'] ?? 'Blouse';
        $styleSlug = $item['style_slug'] ?? 'custom';
        $styleName = $item['style_name'] ?? 'Custom Tailored';
        $basePrice = (int) ($item['base_price'] ?? 650);
        $totalPrice = (int) ($item['total'] ?? $basePrice);
        $customizationTotal = (int) ($item['customization_total'] ?? 0);
        $choices = $item['choices'] ?? [];
        $summary = $item['summary'] ?? [];
        $notes = $item['notes'] ?? '';
        $image = $item['image'] ?? 'assets/images/blouse.jpg';

        // Detect work type (machine, hand, no_work)
        $workType = 'no_work';
        $machineWork = null;
        $handWork = null;
        $workPrice = 0;

        if (isset($choices['embroidery'])) {
            if ($choices['embroidery'] === 'machine') {
                $workType = 'machine';
                $workPrice = 250;
                $machineWork = [
                    'design_code' => 'M-018',
                    'design_name' => 'Machine Embroidery',
                    'price' => 250,
                    'placement' => 'Neck & Sleeves'
                ];
            } elseif ($choices['embroidery'] === 'hand') {
                $workType = 'hand';
                $workPrice = 500;
                $handWork = [
                    'design_code' => 'H-012',
                    'design_name' => 'Hand Embroidery',
                    'price' => 500,
                    'placement' => 'Neck & Sleeves'
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
                            'placement' => 'Neck & Sleeves'
                        ];
                        break;
                    } elseif (strpos($sLabel, 'hand') !== false) {
                        $workType = 'hand';
                        $workPrice = (int) ($sRow['price'] ?? 500);
                        $handWork = [
                            'design_code' => 'H-012',
                            'design_name' => 'Hand Embroidery',
                            'price' => $workPrice,
                            'placement' => 'Neck & Sleeves'
                        ];
                        break;
                    }
                }
            }
        }

        // Filter ₹0 rows and separate embroidery from choice summary
        $cleanSummary = [];
        foreach ($summary as $sRow) {
            $cPrice = (int) ($sRow['price'] ?? 0);
            $sField = strtolower((string) ($sRow['field'] ?? ''));
            if (strpos($sField, 'embroidery') !== false) {
                continue;
            }
            if ($cPrice > 0) {
                $cleanSummary[] = $sRow;
            }
        }

        $cleanCustomizationTotal = max(0, $customizationTotal - $workPrice);

        $standardGarments[] = [
            'id' => $item['id'] ?? ('item_' . $index),
            'name' => $gName,
            'style_slug' => $styleSlug,
            'style_name' => $styleName,
            'image' => $image,
            'base_price' => $basePrice,
            'customization_total' => $cleanCustomizationTotal,
            'work_total' => $workPrice,
            'total_price' => $totalPrice,
            'work_type' => $workType,
            'machine_work' => $machineWork,
            'hand_work' => $handWork,
            'choice_summary' => $cleanSummary,
            'notes' => $notes,
            'status' => 'completed'
        ];
    }
} elseif (!empty($_SESSION['standard_order']['people'][0]['garments'])) {
    $standardGarments = $_SESSION['standard_order']['people'][0]['garments'];
}

// Order totals
$orderGrandTotal = 0;
foreach ($standardGarments as $g) {
    $orderGrandTotal += (int) ($g['total_price'] ?? 0);
}
if ($orderGrandTotal === 0) {
    $orderGrandTotal = $cartTotal;
}

// Verified shop details (Electronic City Phase 1)
$shopName = 'Shagun Ladies Tailor';
$shopAddress = 'Velankanni Road, Electronic City Phase 1, Bengaluru - 560100, Karnataka, India';
$shopPhone = '+91 7019179423';
$mapsUrl = 'https://maps.google.com/?q=Shagun+Ladies+Tailor+Velankanni+Road+Electronic+City+Phase+1+Bengaluru+560100';

// Determine Step
$step = (string) ($_GET['step'] ?? '');
if ($step === '') {
    if ($hasCompletedOrder) {
        $step = 'confirmation';
    } elseif (!empty($_SESSION['standard_order']['advance_payment'])) {
        $step = 'payment';
    } elseif (!empty($_SESSION['standard_order']['measurement_method'])) {
        $step = 'review';
    } else {
        $step = 'measurement';
    }
}

// -------------------------------------------------------------
// POST HANDLERS
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    // Step 1: Save Measurements & Requested Ready Date
    if ($action === 'save_measurements') {
        $method = trim((string) ($_POST['measurement_method'] ?? ''));
        if (!in_array($method, ['reference_blouse', 'visit_shop'], true)) {
            $method = 'reference_blouse';
        }
        $requestedDate = trim((string) ($_POST['requested_ready_date'] ?? ''));
        if (empty($requestedDate)) {
            $requestedDate = date('Y-m-d', strtotime('+15 days'));
        }
        $orderNotes = trim((string) ($_POST['notes'] ?? ''));

        $_SESSION['standard_order']['workflow'] = 'standard';
        $_SESSION['standard_order']['order_type'] = 'Standard Stitching';
        $_SESSION['standard_order']['occasion'] = 'Standard Stitching';
        $_SESSION['standard_order']['measurement_method'] = $method;
        $_SESSION['standard_order']['requested_ready_date'] = $requestedDate;
        $_SESSION['standard_order']['notes'] = $orderNotes;
        $_SESSION['standard_order']['customer_name'] = $customerName;
        $_SESSION['standard_order']['customer_email'] = $customerEmail;
        $_SESSION['standard_order']['customer_phone'] = $customerPhone;
        $_SESSION['standard_order']['people'] = [
            [
                'name' => $customerName,
                'role' => 'Customer',
                'measurement_method' => $method,
                'garments' => $standardGarments
            ]
        ];

        header('Location: checkout.php?step=review');
        exit;
    }

    // Step 2: Confirm Review & Save Advance Payment
    if ($action === 'confirm_review') {
        $declaration = isset($_POST['declaration']) && $_POST['declaration'] === '1';
        $minPercent = 30;
        $minAdvance = (int) ceil($orderGrandTotal * 0.30);
        $maxAdvance = $orderGrandTotal;
        $submittedAdvance = isset($_POST['advance_amount']) ? (int) $_POST['advance_amount'] : (int) round($orderGrandTotal * 0.50);

        if ($submittedAdvance < $minAdvance) {
            $submittedAdvance = $minAdvance;
        }
        if ($submittedAdvance > $maxAdvance) {
            $submittedAdvance = $maxAdvance;
        }

        if ($declaration) {
            $_SESSION['standard_order']['advance_payment'] = [
                'order_total' => $orderGrandTotal,
                'min_advance_percent' => $minPercent,
                'min_advance_amount' => $minAdvance,
                'selected_amount' => $submittedAdvance,
                'remaining_balance' => $orderGrandTotal - $submittedAdvance,
                'declaration_confirmed' => true,
                'updated_at' => time()
            ];
            header('Location: checkout.php?step=payment');
            exit;
        }
    }

    // Step 3: Payment Simulation Handlers
    if ($action === 'simulate_success') {
        // Idempotency: If already completed, do not regenerate ref or duplicate record
        if (!empty($_SESSION['standard_order']['payment']['status']) && $_SESSION['standard_order']['payment']['status'] === 'completed') {
            header('Location: checkout.php?step=confirmation');
            exit;
        }

        $advanceAmount = (int) ($_SESSION['standard_order']['advance_payment']['selected_amount'] ?? ceil($orderGrandTotal * 0.5));
        $remainingBalance = $orderGrandTotal - $advanceAmount;
        $orderRef = 'LT' . date('Ymd') . '-' . rand(100, 999);
        $bookedDate = date('Y-m-d');
        $requestedDate = $_SESSION['standard_order']['requested_ready_date'] ?? date('Y-m-d', strtotime('+15 days'));
        $custUserId = (int)($user['id'] ?? 0);

        $_SESSION['standard_order']['user_id'] = $custUserId;
        $_SESSION['standard_order']['customer_email'] = $customerEmail;
        $_SESSION['standard_order']['order_ref'] = $orderRef;
        $_SESSION['standard_order']['status'] = 'pending_confirmation';
        $_SESSION['standard_order']['production_status'] = 'pending_confirmation';
        $_SESSION['standard_order']['booked_date'] = $bookedDate;
        $_SESSION['standard_order']['admin_delivery_date'] = $requestedDate;
        $_SESSION['standard_order']['payment'] = [
            'status' => 'completed',
            'order_ref' => $orderRef,
            'amount_paid' => $advanceAmount,
            'remaining_balance' => $remainingBalance,
            'booked_date' => $bookedDate,
            'paid_at' => time()
        ];
        $_SESSION['standard_order']['date_history'] = [
            [
                'type' => 'customer_request',
                'date' => $requestedDate,
                'created_at' => time(),
                'formatted_created' => date('d M Y'),
                'actor' => 'customer',
                'note' => 'Customer requested ready date: ' . $requestedDate
            ]
        ];
        $_SESSION['standard_order']['people'] = [
            [
                'name' => $customerName,
                'role' => 'Customer',
                'measurement_method' => $_SESSION['standard_order']['measurement_method'] ?? 'reference_blouse',
                'garments' => $standardGarments
            ]
        ];

        // Idempotent save to customer orders store
        save_customer_completed_order($_SESSION['standard_order']);

        // Clear cart now that order is confirmed
        demo_cart_clear();

        header('Location: checkout.php?step=confirmation');
        exit;
    }

    if ($action === 'simulate_failed') {
        $_SESSION['standard_order']['payment_error'] = 'Payment could not be processed. Please try again or select another payment method.';
        header('Location: checkout.php?step=payment&failed=1');
        exit;
    }
}

// Current step data
$currentMethod = $_SESSION['standard_order']['measurement_method'] ?? 'reference_blouse';
$currentRequestedDate = $_SESSION['standard_order']['requested_ready_date'] ?? date('Y-m-d', strtotime('+15 days'));
$currentNotes = $_SESSION['standard_order']['notes'] ?? '';
$advancePayment = $_SESSION['standard_order']['advance_payment'] ?? null;

$minPercent = 30;
$minAdvance = (int) ceil($orderGrandTotal * 0.30);
$maxAdvance = $orderGrandTotal;
$defaultAdvance = (int) round($orderGrandTotal * 0.50);
if ($defaultAdvance < $minAdvance) {
    $defaultAdvance = $minAdvance;
}
$selectedAdvance = $advancePayment['selected_amount'] ?? $defaultAdvance;
$remainingAmount = $orderGrandTotal - $selectedAdvance;

// Active confirmed order details
$confirmedOrderRef = $_SESSION['standard_order']['payment']['order_ref'] ?? ('LT' . date('Ymd') . '-001');
$confirmedPaidAmount = (int) ($_SESSION['standard_order']['payment']['amount_paid'] ?? $selectedAdvance);
$confirmedRemaining = (int) ($_SESSION['standard_order']['payment']['remaining_balance'] ?? $remainingAmount);
$confirmedBookedDate = $_SESSION['standard_order']['booked_date'] ?? date('Y-m-d');
$confirmedReadyDate = $_SESSION['standard_order']['requested_ready_date'] ?? $currentRequestedDate;
$confirmedDeliveryDate = $_SESSION['standard_order']['admin_delivery_date'] ?? $confirmedReadyDate;

// Step numbers: 1 = Measurements, 2 = Review, 3 = Payment, 4 = Confirmation
$activeStepNum = 1;
if ($step === 'review') $activeStepNum = 2;
elseif ($step === 'payment') $activeStepNum = 3;
elseif ($step === 'confirmation') $activeStepNum = 4;

include __DIR__ . '/includes/header.php';
?>

<main class="luxe-checkout-standard-page">

    <!-- =========================================
         STANDARD CHECKOUT LUXURY STEPPER
    ========================================== -->
    <section class="luxe-stepper-wrap" aria-label="Order progress">
        <div class="luxe-stepper-container" style="max-width: 800px; margin: 0 auto; padding: 24px 16px 12px;">
            <div class="standard-stepper" style="display: flex; align-items: center; justify-content: space-between; position: relative;">
                
                <!-- STEP 1: MEASUREMENTS -->
                <div class="std-step-item <?php echo $activeStepNum >= 1 ? 'is-active' : ''; ?>" style="display: flex; flex-direction: column; align-items: center; z-index: 2;">
                    <a href="<?php echo $hasCompletedOrder ? '#' : 'checkout.php?step=measurement'; ?>" style="text-decoration: none; display: flex; flex-direction: column; align-items: center;">
                        <span class="std-step-circle" style="width: 36px; height: 36px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 14px; background: <?php echo $activeStepNum >= 1 ? '#6b1d28' : '#f7f2e8'; ?>; color: <?php echo $activeStepNum >= 1 ? '#ffffff' : '#73695e'; ?>; border: 2px solid <?php echo $activeStepNum >= 1 ? '#6b1d28' : '#e8ddcf'; ?>;">
                            <?php echo $activeStepNum > 1 ? '✓' : '1'; ?>
                        </span>
                        <span class="std-step-label" style="margin-top: 6px; font-size: 12px; font-weight: 600; color: <?php echo $activeStepNum === 1 ? '#6b1d28' : '#73695e'; ?>;">
                            Measurements
                        </span>
                    </a>
                </div>

                <div class="std-step-line" style="flex: 1; height: 2px; background: <?php echo $activeStepNum >= 2 ? '#6b1d28' : '#e8ddcf'; ?>; margin: -18px 12px 0;"></div>

                <!-- STEP 2: REVIEW -->
                <div class="std-step-item <?php echo $activeStepNum >= 2 ? 'is-active' : ''; ?>" style="display: flex; flex-direction: column; align-items: center; z-index: 2;">
                    <a href="<?php echo ($hasCompletedOrder || empty($_SESSION['standard_order']['measurement_method'])) ? '#' : 'checkout.php?step=review'; ?>" style="text-decoration: none; display: flex; flex-direction: column; align-items: center;">
                        <span class="std-step-circle" style="width: 36px; height: 36px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 14px; background: <?php echo $activeStepNum >= 2 ? '#6b1d28' : '#f7f2e8'; ?>; color: <?php echo $activeStepNum >= 2 ? '#ffffff' : '#73695e'; ?>; border: 2px solid <?php echo $activeStepNum >= 2 ? '#6b1d28' : '#e8ddcf'; ?>;">
                            <?php echo $activeStepNum > 2 ? '✓' : '2'; ?>
                        </span>
                        <span class="std-step-label" style="margin-top: 6px; font-size: 12px; font-weight: 600; color: <?php echo $activeStepNum === 2 ? '#6b1d28' : '#73695e'; ?>;">
                            Review
                        </span>
                    </a>
                </div>

                <div class="std-step-line" style="flex: 1; height: 2px; background: <?php echo $activeStepNum >= 3 ? '#6b1d28' : '#e8ddcf'; ?>; margin: -18px 12px 0;"></div>

                <!-- STEP 3: PAYMENT -->
                <div class="std-step-item <?php echo $activeStepNum >= 3 ? 'is-active' : ''; ?>" style="display: flex; flex-direction: column; align-items: center; z-index: 2;">
                    <a href="<?php echo ($hasCompletedOrder || empty($_SESSION['standard_order']['advance_payment'])) ? '#' : 'checkout.php?step=payment'; ?>" style="text-decoration: none; display: flex; flex-direction: column; align-items: center;">
                        <span class="std-step-circle" style="width: 36px; height: 36px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 14px; background: <?php echo $activeStepNum >= 3 ? '#6b1d28' : '#f7f2e8'; ?>; color: <?php echo $activeStepNum >= 3 ? '#ffffff' : '#73695e'; ?>; border: 2px solid <?php echo $activeStepNum >= 3 ? '#6b1d28' : '#e8ddcf'; ?>;">
                            <?php echo $activeStepNum >= 4 ? '✓' : '3'; ?>
                        </span>
                        <span class="std-step-label" style="margin-top: 6px; font-size: 12px; font-weight: 600; color: <?php echo $activeStepNum >= 3 ? '#6b1d28' : '#73695e'; ?>;">
                            Payment
                        </span>
                    </a>
                </div>

            </div>
        </div>
    </section>

    <!-- =========================================================
         STEP 1: MEASUREMENT & REFERENCE
    ========================================================== -->
    <?php if ($step === 'measurement'): ?>
    <section class="luxe-measurements-page" data-measurements-page style="padding: 12px 16px 48px;">
        <div class="luxe-measurements-container" style="max-width: 800px; margin: 0 auto;">

            <div class="luxe-measurements-header" style="text-align: center; margin-bottom: 24px;">
                <p class="luxe-workspace-eyebrow" style="color: #a67a42; font-weight: 700; font-size: 12px; letter-spacing: 1.5px; text-transform: uppercase;">STANDARD STITCHING CHECKOUT</p>
                <h1 style="font-family: 'Playfair Display', serif; color: #57141f; font-size: 32px; margin: 8px 0;">Measurements & Reference</h1>
                <p class="luxe-measurements-subtitle" style="color: #73695e; font-size: 15px; max-width: 580px; margin: 0 auto;">
                    Choose how you will provide measurements for your tailored garments. No numeric body measurements are asked or stored online.
                </p>
            </div>

            <form method="POST" action="checkout.php?step=measurement" class="standard-measurement-form">
                <input type="hidden" name="action" value="save_measurements">

                <!-- CARD 1: TIMELINE / DATE PICKER -->
                <article class="luxe-person-card" style="background: #ffffff; border: 1px solid #e8ddcf; border-radius: 12px; padding: 24px; margin-bottom: 24px; box-shadow: 0 2px 12px rgba(107, 29, 40, 0.04);">
                    <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 16px;">
                        <span style="font-size: 20px;">📅</span>
                        <div>
                            <h2 style="font-size: 18px; color: #1f1c19; margin: 0;">When do you need your garments? <span style="color: #a83232;">*</span></h2>
                            <p style="font-size: 13px; color: #73695e; margin: 4px 0 0;">Select the date by which you would like your garments to be ready.</p>
                        </div>
                    </div>

                    <div style="max-width: 320px;">
                        <input
                            type="date"
                            id="requested-ready-date"
                            name="requested_ready_date"
                            min="<?php echo date('Y-m-d'); ?>"
                            value="<?php echo htmlspecialchars((string) $currentRequestedDate); ?>"
                            required
                            style="width: 100%; padding: 12px 14px; border: 1px solid #e8ddcf; border-radius: 8px; font-size: 15px; color: #1f1c19; background: #fdfcf9;"
                        >
                    </div>
                </article>

                <!-- CARD 2: MEASUREMENT METHOD SELECTION -->
                <article class="luxe-person-card" data-person-card style="background: #ffffff; border: 1px solid #e8ddcf; border-radius: 12px; padding: 24px; margin-bottom: 24px; box-shadow: 0 2px 12px rgba(107, 29, 40, 0.04);">
                    <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 18px;">
                        <span style="font-size: 20px;">✂️</span>
                        <div>
                            <h2 style="font-size: 18px; color: #1f1c19; margin: 0;">Measurement Method <span style="color: #a83232;">*</span></h2>
                            <p style="font-size: 13px; color: #73695e; margin: 4px 0 0;">This fitting reference will be used for all physical garments in this order.</p>
                        </div>
                    </div>

                    <div class="luxe-method-options" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 16px;">

                        <!-- Option A: Reference Blouse -->
                        <label class="luxe-method-card <?php echo $currentMethod === 'reference_blouse' ? 'is-selected' : ''; ?>" style="display: flex; align-items: flex-start; gap: 12px; padding: 18px; border: 1.5px solid <?php echo $currentMethod === 'reference_blouse' ? '#6b1d28' : '#e8ddcf'; ?>; border-radius: 10px; cursor: pointer; background: <?php echo $currentMethod === 'reference_blouse' ? '#fdfcf9' : '#ffffff'; ?>;">
                            <input
                                type="radio"
                                name="measurement_method"
                                value="reference_blouse"
                                <?php echo $currentMethod === 'reference_blouse' ? 'checked' : ''; ?>
                                style="margin-top: 4px;"
                            >
                            <div>
                                <strong style="display: block; font-size: 15px; color: #1f1c19; margin-bottom: 4px;">Reference Blouse</strong>
                                <p style="font-size: 13px; color: #73695e; margin: 0; line-height: 1.4;">
                                    The physical blouse that fits you correctly will be used as the fitting reference.
                                </p>
                            </div>
                        </label>

                        <!-- Option B: Visit Shop -->
                        <label class="luxe-method-card <?php echo $currentMethod === 'visit_shop' ? 'is-selected' : ''; ?>" style="display: flex; align-items: flex-start; gap: 12px; padding: 18px; border: 1.5px solid <?php echo $currentMethod === 'visit_shop' ? '#6b1d28' : '#e8ddcf'; ?>; border-radius: 10px; cursor: pointer; background: <?php echo $currentMethod === 'visit_shop' ? '#fdfcf9' : '#ffffff'; ?>;">
                            <input
                                type="radio"
                                name="measurement_method"
                                value="visit_shop"
                                <?php echo $currentMethod === 'visit_shop' ? 'checked' : ''; ?>
                                style="margin-top: 4px;"
                            >
                            <div>
                                <strong style="display: block; font-size: 15px; color: #1f1c19; margin-bottom: 4px;">Visit Shop</strong>
                                <p style="font-size: 13px; color: #73695e; margin: 0; line-height: 1.4;">
                                    Visit Shagun Ladies Tailor for measurement at our boutique.
                                </p>
                            </div>
                        </label>

                    </div>

                    <!-- Contextual Information Panel -->
                    <div class="luxe-contextual-panel" data-contextual-panel style="margin-top: 20px; padding: 18px; background: #fbf9f6; border: 1px solid #e8ddcf; border-radius: 8px;">
                        
                        <!-- Reference Blouse Details -->
                        <div class="luxe-panel-content" data-panel-type="reference_blouse" style="<?php echo $currentMethod === 'reference_blouse' ? '' : 'display: none;'; ?>">
                            <div style="margin-bottom: 12px;">
                                <strong style="color: #6b1d28; font-size: 14px;">📦 Reference Blouse Instructions:</strong>
                                <p style="font-size: 13px; color: #57141f; margin: 4px 0 8px;">
                                    Please provide one blouse that fits you correctly. You can bring it to our shop or send it along with your customer-provided fabric/material.
                                </p>
                                <p style="font-size: 12px; color: #73695e; margin: 0;">
                                    Can't visit in person? You can send your reference garment and fabric/material using any courier or delivery service.
                                </p>
                            </div>
                            <div style="border-top: 1px solid #e8ddcf; padding-top: 12px;">
                                <span style="font-size: 12px; color: #73695e; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 600;">Deliver / Send To:</span>
                                <strong style="display: block; font-size: 14px; color: #1f1c19; margin: 2px 0;"><?php echo htmlspecialchars($shopName); ?></strong>
                                <address style="font-size: 13px; color: #73695e; font-style: normal; margin-bottom: 12px;"><?php echo htmlspecialchars($shopAddress); ?></address>
                                <div style="display: flex; flex-wrap: wrap; gap: 8px;">
                                    <a href="<?php echo htmlspecialchars($mapsUrl); ?>" target="_blank" rel="noopener" class="luxe-panel-btn" style="display: inline-flex; align-items: center; gap: 6px; padding: 8px 14px; background: #ffffff; border: 1px solid #e8ddcf; border-radius: 6px; font-size: 13px; color: #1f1c19; text-decoration: none;">
                                        <span>📍</span> Google Maps
                                    </a>
                                    <button type="button" class="luxe-panel-btn" data-copy-btn data-copy-text="<?php echo htmlspecialchars($shopName . ', ' . $shopAddress); ?>" style="display: inline-flex; align-items: center; gap: 6px; padding: 8px 14px; background: #ffffff; border: 1px solid #e8ddcf; border-radius: 6px; font-size: 13px; color: #1f1c19; cursor: pointer;">
                                        <span>📋</span> Copy Address
                                    </button>
                                    <a href="tel:<?php echo htmlspecialchars($shopPhone); ?>" class="luxe-panel-btn" style="display: inline-flex; align-items: center; gap: 6px; padding: 8px 14px; background: #ffffff; border: 1px solid #e8ddcf; border-radius: 6px; font-size: 13px; color: #1f1c19; text-decoration: none;">
                                        <span>📞</span> Call
                                    </a>
                                </div>
                            </div>
                        </div>

                        <!-- Visit Shop Details -->
                        <div class="luxe-panel-content" data-panel-type="visit_shop" style="<?php echo $currentMethod === 'visit_shop' ? '' : 'display: none;'; ?>">
                            <div style="margin-bottom: 12px;">
                                <strong style="color: #6b1d28; font-size: 14px;">🏪 Visit Shop Instructions:</strong>
                                <p style="font-size: 13px; color: #57141f; margin: 4px 0 8px;">
                                    Visit Shagun Ladies Tailor at our Electronic City boutique for professional measurements and styling guidance.
                                </p>
                            </div>
                            <div style="border-top: 1px solid #e8ddcf; padding-top: 12px;">
                                <span style="font-size: 12px; color: #73695e; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 600;">Shop Address:</span>
                                <strong style="display: block; font-size: 14px; color: #1f1c19; margin: 2px 0;"><?php echo htmlspecialchars($shopName); ?></strong>
                                <address style="font-size: 13px; color: #73695e; font-style: normal; margin-bottom: 12px;"><?php echo htmlspecialchars($shopAddress); ?></address>
                                <div style="display: flex; flex-wrap: wrap; gap: 8px;">
                                    <a href="<?php echo htmlspecialchars($mapsUrl); ?>" target="_blank" rel="noopener" class="luxe-panel-btn" style="display: inline-flex; align-items: center; gap: 6px; padding: 8px 14px; background: #ffffff; border: 1px solid #e8ddcf; border-radius: 6px; font-size: 13px; color: #1f1c19; text-decoration: none;">
                                        <span>📍</span> Google Maps
                                    </a>
                                    <button type="button" class="luxe-panel-btn" data-copy-btn data-copy-text="<?php echo htmlspecialchars($shopName . ', ' . $shopAddress); ?>" style="display: inline-flex; align-items: center; gap: 6px; padding: 8px 14px; background: #ffffff; border: 1px solid #e8ddcf; border-radius: 6px; font-size: 13px; color: #1f1c19; cursor: pointer;">
                                        <span>📋</span> Copy Address
                                    </button>
                                    <a href="tel:<?php echo htmlspecialchars($shopPhone); ?>" class="luxe-panel-btn" style="display: inline-flex; align-items: center; gap: 6px; padding: 8px 14px; background: #ffffff; border: 1px solid #e8ddcf; border-radius: 6px; font-size: 13px; color: #1f1c19; text-decoration: none;">
                                        <span>📞</span> Call
                                    </a>
                                </div>
                            </div>
                        </div>

                    </div>
                </article>

                <!-- CARD 3: SPECIAL INSTRUCTIONS / NOTES -->
                <article class="luxe-person-card" style="background: #ffffff; border: 1px solid #e8ddcf; border-radius: 12px; padding: 24px; margin-bottom: 24px; box-shadow: 0 2px 12px rgba(107, 29, 40, 0.04);">
                    <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 12px;">
                        <span style="font-size: 20px;">📝</span>
                        <div>
                            <h2 style="font-size: 18px; color: #1f1c19; margin: 0;">Special Instructions (Optional)</h2>
                            <p style="font-size: 13px; color: #73695e; margin: 2px 0 0;">Any specific fitting requests, sleeve preferences, or delivery instructions.</p>
                        </div>
                    </div>
                    <textarea
                        name="notes"
                        rows="3"
                        placeholder="e.g. Please ensure double stitching on armholes, prefer loose sleeve fit..."
                        style="width: 100%; padding: 12px 14px; border: 1px solid #e8ddcf; border-radius: 8px; font-size: 14px; color: #1f1c19; background: #fdfcf9; resize: vertical;"
                    ><?php echo htmlspecialchars((string) $currentNotes); ?></textarea>
                </article>

                <!-- BOTTOM SUBMIT ROW -->
                <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px;">
                    <a href="cart.php" style="font-size: 14px; color: #6b1d28; font-weight: 600; text-decoration: none;">
                        ← Back to Cart
                    </a>
                    <button type="submit" class="demo-primary-action" style="background: #6b1d28; color: #ffffff; padding: 14px 32px; border: none; border-radius: 8px; font-size: 15px; font-weight: 700; cursor: pointer;">
                        Continue to Review & Advance →
                    </button>
                </div>

            </form>
        </div>
    </section>
    <?php endif; ?>


    <!-- =========================================================
         STEP 2: REVIEW & ADVANCE PAYMENT
    ========================================================== -->
    <?php if ($step === 'review'): ?>
    <section
        class="luxe-review-page"
        data-review-payment-page
        data-grand-total="<?php echo $orderGrandTotal; ?>"
        data-min-advance="<?php echo $minAdvance; ?>"
        data-min-percent="<?php echo $minPercent; ?>"
        style="padding: 12px 16px 48px;"
    >
        <!-- TOAST NOTIFICATION -->
        <div class="luxe-toast-notification" data-luxe-toast style="display: none;">
            <span class="luxe-toast-icon">✓</span>
            <div class="luxe-toast-text">
                <strong>Ready for Advance Payment</strong>
                <span>Selected: <span id="toast-amount-display">₹<?php echo number_format($selectedAdvance); ?></span></span>
            </div>
            <button type="button" id="luxe-toast-close" class="luxe-toast-close" aria-label="Close notification">✕</button>
        </div>

        <div class="luxe-review-container" style="max-width: 1100px; margin: 0 auto;">

            <div class="luxe-review-header" style="text-align: center; margin-bottom: 28px;">
                <p class="luxe-workspace-eyebrow" style="color: #a67a42; font-weight: 700; font-size: 12px; letter-spacing: 1.5px; text-transform: uppercase;">STEP 2 OF 3 · ORDER SUMMARY</p>
                <h1 style="font-family: 'Playfair Display', serif; color: #57141f; font-size: 32px; margin: 8px 0;">Review Your Order</h1>
                <p class="luxe-review-subtitle" style="color: #73695e; font-size: 15px;">
                    Verify each physical garment, styling customizations, and select your advance payment amount.
                </p>
            </div>

            <?php if ($hasLuxeInProgress): ?>
                <div style="background: linear-gradient(135deg, #fdfbf7 0%, #f9f3ec 100%); border: 1.5px solid #d4af37; border-radius: 12px; padding: 18px 22px; margin-bottom: 24px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 16px; box-shadow: 0 4px 16px rgba(212, 175, 55, 0.12);">
                    <div style="display: flex; align-items: center; gap: 14px;">
                        <span style="font-size: 26px;">👑</span>
                        <div>
                            <strong style="color: #6b1d28; font-size: 15px; display: block;">Combined Order Option: You have Luxe Stitching garments in progress</strong>
                            <p style="color: #73695e; font-size: 13px; margin: 3px 0 0;">You can review and pay for both your Standard Stitching and Luxe Stitching garments together in a single unified payment with advance options.</p>
                        </div>
                    </div>
                    <a href="review-payment.php" class="btn" style="background: linear-gradient(135deg, #6b1d28 0%, #8a2433 100%); color: #ffffff; text-decoration: none; padding: 10px 20px; border-radius: 8px; font-weight: 600; font-size: 13px; display: inline-flex; align-items: center; gap: 8px; box-shadow: 0 2px 8px rgba(107, 29, 40, 0.2);">
                        <span>Unified Review & Payment</span>
                        <span>→</span>
                    </a>
                </div>
            <?php endif; ?>

            <div class="luxe-review-layout" style="display: grid; grid-template-columns: 1fr 380px; gap: 24px; align-items: start;">

                <!-- LEFT COLUMN: INDEPENDENT PHYSICAL GARMENTS -->
                <div class="luxe-review-main">
                    
                    <!-- ORDER CONTEXT CARD -->
                    <article class="luxe-review-person-card" style="background: #ffffff; border: 1px solid #e8ddcf; border-radius: 12px; padding: 20px; margin-bottom: 20px; box-shadow: 0 2px 12px rgba(107, 29, 40, 0.04);">
                        <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;">
                            <div>
                                <span style="font-size: 11px; text-transform: uppercase; color: #a67a42; font-weight: 700; letter-spacing: 1px;">Customer Details</span>
                                <h2 style="font-size: 18px; color: #1f1c19; margin: 2px 0;"><?php echo htmlspecialchars($customerName); ?></h2>
                                <span style="font-size: 13px; color: #73695e;"><?php echo htmlspecialchars($customerEmail); ?> · <?php echo htmlspecialchars($customerPhone); ?></span>
                            </div>
                            <div style="text-align: right;">
                                <span style="font-size: 11px; text-transform: uppercase; color: #a67a42; font-weight: 700; letter-spacing: 1px;">Fitting Reference</span>
                                <div style="display: inline-block; padding: 4px 12px; border-radius: 999px; background: #fdfcf9; border: 1px solid #e8ddcf; font-size: 13px; font-weight: 600; color: #6b1d28; margin-top: 4px;">
                                    <?php echo $currentMethod === 'visit_shop' ? '🏪 Visit Shop' : '📦 Reference Blouse'; ?>
                                </div>
                            </div>
                        </div>
                    </article>

                    <!-- PHYSICAL GARMENTS LIST -->
                    <h3 style="font-size: 16px; color: #6b1d28; margin: 0 0 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px;">
                        Physical Garments (<?php echo count($standardGarments); ?>)
                    </h3>

                    <?php foreach ($standardGarments as $gIndex => $garment): ?>
                        <?php
                        $garmentNum = $gIndex + 1;
                        $gTitle = ($garment['name'] ?? 'Blouse') . ' #' . $garmentNum;
                        $gStyle = $garment['style_name'] ?? 'Custom Style';
                        $gTotal = (int) ($garment['total_price'] ?? 0);
                        $wType = $garment['work_type'] ?? 'no_work';
                        $choices = $garment['choice_summary'] ?? [];
                        ?>
                        <article class="luxe-review-garment-item" style="background: #ffffff; border: 1px solid #e8ddcf; border-radius: 12px; padding: 20px; margin-bottom: 16px; box-shadow: 0 2px 10px rgba(0,0,0,0.02);">
                            
                            <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 12px; margin-bottom: 16px; border-bottom: 1px solid #f0e8de; padding-bottom: 12px;">
                                <div style="display: flex; align-items: center; gap: 12px;">
                                    <div style="width: 44px; height: 44px; border-radius: 8px; overflow: hidden; background: #fdfcf9; border: 1px solid #e8ddcf; display: flex; align-items: center; justify-content: center;">
                                        <img src="<?php echo htmlspecialchars($garment['image'] ?? 'assets/images/blouse.jpg'); ?>" alt="<?php echo htmlspecialchars($gTitle); ?>" style="width: 100%; height: 100%; object-fit: cover;">
                                    </div>
                                    <div>
                                        <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap; margin-bottom: 2px;">
                                            <h4 style="font-size: 16px; color: #1f1c19; margin: 0;"><?php echo htmlspecialchars($gTitle); ?> · <?php echo htmlspecialchars($gStyle); ?></h4>
                                            <?php echo render_category_badge('standard'); ?>
                                            <?php if ($wType === 'hand'): ?>
                                                <?php echo render_category_badge('hand'); ?>
                                            <?php elseif ($wType === 'machine'): ?>
                                                <?php echo render_category_badge('machine'); ?>
                                            <?php endif; ?>
                                        </div>
                                        <span style="font-size: 12px; color: #73695e;">Base Stitching: ₹<?php echo number_format((int)($garment['base_price'] ?? 0)); ?></span>
                                    </div>
                                </div>
                                <strong style="font-size: 18px; color: #6b1d28; font-weight: 700;">
                                    ₹<?php echo number_format($gTotal); ?>
                                </strong>
                            </div>

                            <!-- Customization Choices -->
                            <div style="margin-bottom: 12px;">
                                <span style="font-size: 12px; font-weight: 700; color: #a67a42; text-transform: uppercase;">Customization Choices</span>
                                <?php if (!empty($choices)): ?>
                                    <ul style="list-style: none; padding: 0; margin: 6px 0 0; display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 6px;">
                                        <?php foreach ($choices as $choice): ?>
                                            <li style="font-size: 13px; color: #57141f; display: flex; justify-content: space-between; padding: 4px 8px; background: #fdfcf9; border-radius: 4px; border: 1px solid #f0e8de;">
                                                <span><?php echo htmlspecialchars($choice['field'] ?? ''); ?>: <strong><?php echo htmlspecialchars($choice['label'] ?? ''); ?></strong></span>
                                                <span style="color: #6b1d28; font-weight: 600;">+₹<?php echo number_format((int)($choice['price'] ?? 0)); ?></span>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php else: ?>
                                    <p style="font-size: 13px; color: #73695e; margin: 4px 0 0;">Standard style defaults applied.</p>
                                <?php endif; ?>
                            </div>

                            <!-- Embroidery / Work Details -->
                            <div style="border-top: 1px dashed #e8ddcf; padding-top: 10px; display: flex; justify-content: space-between; align-items: center;">
                                <span style="font-size: 12px; font-weight: 700; color: #a67a42; text-transform: uppercase;">Embroidery & Artisanal Work:</span>
                                <?php if ($wType === 'machine' && !empty($garment['machine_work'])): ?>
                                    <span style="font-size: 13px; color: #198c40; font-weight: 600;">
                                        ⚡ Machine Work (<?php echo htmlspecialchars($garment['machine_work']['design_code'] ?? 'M-018'); ?>) — ₹<?php echo number_format((int)($garment['work_total'] ?? 250)); ?>
                                    </span>
                                <?php elseif ($wType === 'hand' && !empty($garment['hand_work'])): ?>
                                    <span style="font-size: 13px; color: #a67a42; font-weight: 600;">
                                        ✨ Hand Work (<?php echo htmlspecialchars($garment['hand_work']['design_code'] ?? 'H-012'); ?>) — ₹<?php echo number_format((int)($garment['work_total'] ?? 500)); ?>
                                    </span>
                                <?php else: ?>
                                    <span style="font-size: 13px; color: #73695e;">No additional embroidery</span>
                                <?php endif; ?>
                            </div>

                        </article>
                    <?php endforeach; ?>

                    <div style="margin-top: 24px;">
                        <a href="checkout.php?step=measurement" style="font-size: 14px; color: #6b1d28; font-weight: 600; text-decoration: none;">
                            ← Back to Measurements
                        </a>
                    </div>

                </div>

                <!-- RIGHT COLUMN: ORDER SUMMARY & ADVANCE PAYMENT SELECTOR -->
                <aside class="luxe-review-sidebar">
                    <form method="POST" action="checkout.php?step=review" id="review-payment-form">
                        <input type="hidden" name="action" value="confirm_review">

                        <!-- ORDER SUMMARY CARD -->
                        <section class="luxe-sidebar-card" style="background: #ffffff; border: 1px solid #e8ddcf; border-radius: 12px; padding: 20px; margin-bottom: 20px; box-shadow: 0 2px 12px rgba(107, 29, 40, 0.04);">
                            <div class="luxe-sidebar-card-title" style="display: flex; align-items: center; gap: 8px; margin-bottom: 14px; border-bottom: 1px solid #f0e8de; padding-bottom: 10px;">
                                <span>📋</span>
                                <h3 style="font-size: 16px; color: #1f1c19; margin: 0;">Order Summary</h3>
                            </div>

                            <div style="display: flex; justify-content: space-between; font-size: 14px; color: #57141f; margin-bottom: 8px;">
                                <span>Physical Garments</span>
                                <strong><?php echo count($standardGarments); ?></strong>
                            </div>

                            <div style="display: flex; justify-content: space-between; font-size: 14px; color: #57141f; margin-bottom: 8px;">
                                <span>Requested Ready Date</span>
                                <strong><?php echo date('d M Y', strtotime($currentRequestedDate)); ?></strong>
                            </div>

                            <div style="border-top: 1px solid #f0e8de; margin: 12px 0; padding-top: 12px; display: flex; justify-content: space-between; align-items: center;">
                                <span style="font-size: 15px; font-weight: 700; color: #1f1c19;">Total Order Amount</span>
                                <strong style="font-size: 20px; color: #6b1d28; font-weight: 700;">₹<?php echo number_format($orderGrandTotal); ?></strong>
                            </div>
                        </section>

                        <!-- CHOOSE ADVANCE PAYMENT CARD -->
                        <section class="luxe-sidebar-card" style="background: #ffffff; border: 1px solid #e8ddcf; border-radius: 12px; padding: 20px; margin-bottom: 20px; box-shadow: 0 2px 12px rgba(107, 29, 40, 0.04);">
                            <div class="luxe-sidebar-card-title" style="display: flex; align-items: center; gap: 8px; margin-bottom: 10px;">
                                <span>👛</span>
                                <h3 style="font-size: 16px; color: #1f1c19; margin: 0;">Choose Advance Payment</h3>
                            </div>

                            <p style="font-size: 12px; color: #73695e; margin: 0 0 16px;">
                                Pay any amount from minimum 30% advance up to the full order amount.
                            </p>

                            <!-- Slider & Input Controls -->
                            <div style="margin-bottom: 16px;">
                                <input
                                    type="range"
                                    id="advance-slider"
                                    min="<?php echo $minAdvance; ?>"
                                    max="<?php echo $maxAdvance; ?>"
                                    step="50"
                                    value="<?php echo $selectedAdvance; ?>"
                                    style="width: 100%; accent-color: #6b1d28; cursor: pointer;"
                                >
                                <div style="display: flex; justify-content: space-between; font-size: 11px; color: #73695e; margin-top: 4px;">
                                    <span>Min: ₹<?php echo number_format($minAdvance); ?> (30%)</span>
                                    <span>Full: ₹<?php echo number_format($maxAdvance); ?></span>
                                </div>
                            </div>

                            <!-- Numeric Amount Field -->
                            <div style="margin-bottom: 16px;">
                                <label style="display: block; font-size: 12px; font-weight: 600; color: #57141f; margin-bottom: 4px;">
                                    Advance Amount (₹)
                                </label>
                                <input
                                    type="number"
                                    id="advance-input"
                                    name="advance_amount"
                                    min="<?php echo $minAdvance; ?>"
                                    max="<?php echo $maxAdvance; ?>"
                                    value="<?php echo $selectedAdvance; ?>"
                                    style="width: 100%; padding: 10px 12px; border: 1px solid #e8ddcf; border-radius: 6px; font-size: 16px; font-weight: 700; color: #6b1d28; background: #fdfcf9;"
                                >
                            </div>

                            <!-- Preset Percentage Buttons -->
                            <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; margin-bottom: 18px;">
                                <button type="button" class="luxe-quick-btn <?php echo $selectedAdvance === $minAdvance ? 'is-active' : ''; ?>" data-percent="30" style="padding: 8px 4px; border: 1px solid #e8ddcf; border-radius: 6px; background: #fdfcf9; font-size: 12px; font-weight: 600; cursor: pointer; color: #57141f;">
                                    30% Min
                                </button>
                                <button type="button" class="luxe-quick-btn <?php echo $selectedAdvance === $defaultAdvance ? 'is-active' : ''; ?>" data-percent="50" style="padding: 8px 4px; border: 1px solid #e8ddcf; border-radius: 6px; background: #fdfcf9; font-size: 12px; font-weight: 600; cursor: pointer; color: #57141f;">
                                    50% Standard
                                </button>
                                <button type="button" class="luxe-quick-btn <?php echo $selectedAdvance === $maxAdvance ? 'is-active' : ''; ?>" data-percent="100" style="padding: 8px 4px; border: 1px solid #e8ddcf; border-radius: 6px; background: #fdfcf9; font-size: 12px; font-weight: 600; cursor: pointer; color: #57141f;">
                                    100% Full
                                </button>
                            </div>

                            <!-- Live Split Box -->
                            <div style="background: #fdfcf9; border: 1px solid #f0e8de; border-radius: 8px; padding: 12px; margin-bottom: 16px;">
                                <div style="display: flex; justify-content: space-between; font-size: 13px; color: #198c40; font-weight: 600; margin-bottom: 4px;">
                                    <span>Pay Advance Now:</span>
                                    <strong id="pay-now-val">₹<?php echo number_format($selectedAdvance); ?></strong>
                                </div>
                                <div style="display: flex; justify-content: space-between; font-size: 13px; color: #73695e;">
                                    <span>Remaining at Collection:</span>
                                    <strong id="remaining-val">₹<?php echo number_format($remainingAmount); ?></strong>
                                </div>
                            </div>

                            <!-- Mandatory Declaration Checkbox -->
                            <label class="luxe-declaration-label" style="display: flex; align-items: flex-start; gap: 8px; font-size: 12px; color: #57141f; line-height: 1.4; margin-bottom: 18px; cursor: pointer;">
                                <input type="checkbox" name="declaration" id="declaration-checkbox" value="1" style="margin-top: 2px;">
                                <span>I confirm that the garment styling, embroidery choices, and measurement reference provided above are correct.</span>
                            </label>

                            <!-- Action Button -->
                            <button
                                type="submit"
                                id="confirm-pay-btn"
                                class="luxe-confirm-pay-btn is-disabled"
                                disabled
                                style="width: 100%; padding: 14px; border: none; border-radius: 8px; font-size: 15px; font-weight: 700; background: #6b1d28; color: #ffffff; cursor: pointer;"
                            >
                                Confirm & Pay <span id="btn-amount-display">₹<?php echo number_format($selectedAdvance); ?></span> →
                            </button>

                            <p style="font-size: 11px; color: #73695e; text-align: center; margin: 10px 0 0;">
                                🔒 You will be redirected to our secure payment simulation.
                            </p>
                        </section>

                    </form>
                </aside>

            </div>

        </div>
    </section>
    <?php endif; ?>


    <!-- =========================================================
         STEP 3: SECURE PAYMENT
    ========================================================== -->
    <?php if ($step === 'payment'): ?>
    <section class="luxe-payment-page" data-payment-page style="padding: 12px 16px 48px;">
        <div class="luxe-payment-container" style="max-width: 800px; margin: 0 auto;">

            <div class="luxe-payment-header" style="text-align: center; margin-bottom: 24px;">
                <p class="luxe-workspace-eyebrow" style="color: #a67a42; font-weight: 700; font-size: 12px; letter-spacing: 1.5px; text-transform: uppercase;">STEP 3 OF 3 · SECURE PAYMENT</p>
                <h1 style="font-family: 'Playfair Display', serif; color: #57141f; font-size: 32px; margin: 8px 0;">Complete Your Payment</h1>
                <p style="color: #73695e; font-size: 15px;">
                    Review your advance payment amount and simulate payment gateway confirmation.
                </p>
            </div>

            <!-- PROTOTYPE DEMO BANNER -->
            <div style="background: #e8effc; border: 1px solid #325aa8; border-radius: 8px; padding: 12px 16px; margin-bottom: 24px; display: flex; align-items: center; gap: 12px;">
                <span style="font-size: 20px;">ℹ️</span>
                <div>
                    <strong style="color: #1a365d; font-size: 13px;">Payment Gateway Integration Coming Soon</strong>
                    <p style="color: #2a4365; font-size: 12px; margin: 2px 0 0;">Payment is currently simulated for demonstration. No real bank transaction or credit card charge will be made.</p>
                </div>
            </div>

            <!-- ORDER SUMMARY CARD -->
            <article style="background: #ffffff; border: 1px solid #e8ddcf; border-radius: 12px; padding: 24px; margin-bottom: 24px; box-shadow: 0 2px 12px rgba(107, 29, 40, 0.04);">
                <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #f0e8de; padding-bottom: 12px; margin-bottom: 12px;">
                    <span style="font-size: 14px; color: #73695e;">Customer Name</span>
                    <strong style="font-size: 15px; color: #1f1c19;"><?php echo htmlspecialchars($customerName); ?></strong>
                </div>
                <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #f0e8de; padding-bottom: 12px; margin-bottom: 12px;">
                    <span style="font-size: 14px; color: #73695e;">Physical Garments</span>
                    <strong style="font-size: 15px; color: #1f1c19;"><?php echo count($standardGarments); ?> items</strong>
                </div>
                <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #f0e8de; padding-bottom: 12px; margin-bottom: 12px;">
                    <span style="font-size: 14px; color: #73695e;">Total Order Amount</span>
                    <strong style="font-size: 15px; color: #1f1c19;">₹<?php echo number_format($orderGrandTotal); ?></strong>
                </div>
                <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #f0e8de; padding-bottom: 12px; margin-bottom: 12px;">
                    <span style="font-size: 15px; font-weight: 700; color: #198c40;">Advance Amount to Pay</span>
                    <strong style="font-size: 22px; color: #198c40; font-weight: 800;">₹<?php echo number_format($selectedAdvance); ?></strong>
                </div>
                <div style="display: flex; justify-content: space-between; align-items: center;">
                    <span style="font-size: 13px; color: #73695e;">Remaining at Collection</span>
                    <strong style="font-size: 14px; color: #6b1d28;">₹<?php echo number_format($remainingAmount); ?></strong>
                </div>
            </article>

            <!-- PAYMENT METHOD TABS -->
            <article style="background: #ffffff; border: 1px solid #e8ddcf; border-radius: 12px; padding: 24px; margin-bottom: 24px; box-shadow: 0 2px 12px rgba(107, 29, 40, 0.04);">
                <div class="luxe-pay-tabs" style="display: flex; gap: 8px; margin-bottom: 20px; border-bottom: 1px solid #f0e8de; padding-bottom: 12px; overflow-x: auto;">
                    <button type="button" class="luxe-pay-tab is-active" data-tab="upi" style="padding: 10px 18px; border: 1px solid #6b1d28; border-radius: 6px; background: #fdfcf9; color: #6b1d28; font-weight: 700; font-size: 13px; cursor: pointer;">
                        📱 UPI
                    </button>
                    <button type="button" class="luxe-pay-tab" data-tab="card" style="padding: 10px 18px; border: 1px solid #e8ddcf; border-radius: 6px; background: #ffffff; color: #73695e; font-weight: 600; font-size: 13px; cursor: pointer;">
                        💳 Cards
                    </button>
                    <button type="button" class="luxe-pay-tab" data-tab="netbanking" style="padding: 10px 18px; border: 1px solid #e8ddcf; border-radius: 6px; background: #ffffff; color: #73695e; font-weight: 600; font-size: 13px; cursor: pointer;">
                        🏦 Net Banking
                    </button>
                    <button type="button" class="luxe-pay-tab" data-tab="wallets" style="padding: 10px 18px; border: 1px solid #e8ddcf; border-radius: 6px; background: #ffffff; color: #73695e; font-weight: 600; font-size: 13px; cursor: pointer;">
                        👛 Wallets
                    </button>
                </div>

                <div id="tab-panel-upi" class="luxe-pay-panel is-active">
                    <p style="font-size: 13px; color: #57141f; margin-bottom: 12px;">Instant advance payment via any UPI app (Google Pay, PhonePe, Paytm, BHIM):</p>
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); gap: 10px;">
                        <div style="padding: 12px; border: 1px solid #e8ddcf; border-radius: 8px; text-align: center; background: #fdfcf9; font-size: 13px; font-weight: 600; color: #1f1c19;">GPay</div>
                        <div style="padding: 12px; border: 1px solid #e8ddcf; border-radius: 8px; text-align: center; background: #fdfcf9; font-size: 13px; font-weight: 600; color: #1f1c19;">PhonePe</div>
                        <div style="padding: 12px; border: 1px solid #e8ddcf; border-radius: 8px; text-align: center; background: #fdfcf9; font-size: 13px; font-weight: 600; color: #1f1c19;">Paytm</div>
                    </div>
                </div>

                <div id="tab-panel-card" class="luxe-pay-panel" style="display: none;">
                    <p style="font-size: 13px; color: #57141f;">Credit Card / Debit Card (Visa, MasterCard, RuPay)</p>
                </div>

                <div id="tab-panel-netbanking" class="luxe-pay-panel" style="display: none;">
                    <p style="font-size: 13px; color: #57141f;">All major Indian banks supported (HDFC, ICICI, SBI, Axis)</p>
                </div>

                <div id="tab-panel-wallets" class="luxe-pay-panel" style="display: none;">
                    <p style="font-size: 13px; color: #57141f;">Amazon Pay, Mobikwik, Airtel Money</p>
                </div>

                <!-- SIMULATION ACTIONS -->
                <div style="margin-top: 24px; border-top: 1px solid #f0e8de; padding-top: 20px;">
                    <form method="POST" action="checkout.php?step=payment" style="display: flex; flex-direction: column; gap: 12px;">
                        <button
                            type="submit"
                            name="action"
                            value="simulate_success"
                            class="demo-primary-action"
                            style="width: 100%; background: #198c40; color: #ffffff; padding: 15px; border: none; border-radius: 8px; font-size: 16px; font-weight: 700; cursor: pointer;"
                        >
                            Simulate Payment Success (Pay ₹<?php echo number_format($selectedAdvance); ?>) →
                        </button>
                        
                        <button
                            type="submit"
                            name="action"
                            value="simulate_failed"
                            style="width: 100%; background: transparent; color: #a83232; padding: 10px; border: 1px solid #e8ddcf; border-radius: 8px; font-size: 13px; font-weight: 600; cursor: pointer;"
                        >
                            Simulate Payment Failure (Test Error State)
                        </button>
                    </form>
                </div>

            </article>

            <div>
                <a href="checkout.php?step=review" style="font-size: 14px; color: #6b1d28; font-weight: 600; text-decoration: none;">
                    ← Back to Review & Payment
                </a>
            </div>

        </div>
    </section>
    <?php endif; ?>


    <!-- =========================================================
         STEP 4: ORDER CONFIRMATION
    ========================================================== -->
    <?php if ($step === 'confirmation'): ?>
    <section class="luxe-confirmation-page" style="padding: 24px 16px 64px;">
        <div class="luxe-confirmation-container" style="max-width: 680px; margin: 0 auto; text-align: center;">

            <!-- Green Confirmed Check -->
            <div style="width: 68px; height: 68px; border-radius: 50%; background: #e8f6ec; border: 2px solid #198c40; color: #198c40; display: flex; align-items: center; justify-content: center; font-size: 32px; margin: 0 auto 16px;">
                ✓
            </div>

            <h1 style="font-family: 'Playfair Display', serif; color: #57141f; font-size: 32px; margin: 0 0 8px;">Payment Successful</h1>
            <p style="color: #73695e; font-size: 15px; margin: 0 0 24px;">Your order has been booked and confirmed with Shagun Ladies Tailor.</p>

            <!-- ORDER RECEIPT CARD -->
            <article style="background: #ffffff; border: 1px solid #e8ddcf; border-radius: 12px; padding: 24px; text-align: left; margin-bottom: 28px; box-shadow: 0 2px 16px rgba(107, 29, 40, 0.05);">
                
                <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #f0e8de; padding-bottom: 12px; margin-bottom: 12px;">
                    <span style="font-size: 13px; color: #73695e;">Order Reference</span>
                    <div style="display: flex; align-items: center; gap: 6px;">
                        <strong style="font-size: 16px; color: #1f1c19;" id="order-ref-text"><?php echo htmlspecialchars($confirmedOrderRef); ?></strong>
                        <button type="button" data-copy-btn data-copy-text="<?php echo htmlspecialchars($confirmedOrderRef); ?>" style="border: none; background: #fdfcf9; border: 1px solid #e8ddcf; border-radius: 4px; padding: 4px 8px; font-size: 12px; cursor: pointer;" title="Copy reference">❐</button>
                    </div>
                </div>

                <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #f0e8de; padding-bottom: 12px; margin-bottom: 12px;">
                    <span style="font-size: 13px; color: #73695e;">Order Type</span>
                    <strong style="font-size: 14px; color: #6b1d28;">Standard Stitching</strong>
                </div>

                <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #f0e8de; padding-bottom: 12px; margin-bottom: 12px;">
                    <span style="font-size: 13px; color: #73695e;">Customer Name</span>
                    <strong style="font-size: 14px; color: #1f1c19;"><?php echo htmlspecialchars($customerName); ?></strong>
                </div>

                <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #f0e8de; padding-bottom: 12px; margin-bottom: 12px;">
                    <span style="font-size: 13px; color: #73695e;">Advance Paid</span>
                    <strong style="font-size: 16px; color: #198c40; font-weight: 800;">₹<?php echo number_format($confirmedPaidAmount); ?></strong>
                </div>

                <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #f0e8de; padding-bottom: 12px; margin-bottom: 12px;">
                    <span style="font-size: 13px; color: #73695e;">Remaining Balance</span>
                    <strong style="font-size: 14px; color: #6b1d28;">₹<?php echo number_format($confirmedRemaining); ?></strong>
                </div>

                <div style="display: flex; justify-content: space-between; align-items: center;">
                    <span style="font-size: 13px; color: #73695e;">Requested Ready Date (Locked)</span>
                    <strong style="font-size: 14px; color: #1f1c19;">🔒 <?php echo date('d M Y', strtotime($confirmedReadyDate)); ?></strong>
                </div>

            </article>

            <!-- ACTION BUTTONS: VIEW ORDER & DOWNLOAD DOSSIER & HOME -->
            <div style="display: flex; flex-direction: column; gap: 12px;">
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 12px;">
                    <a
                        href="orders.php?ref=<?php echo urlencode($confirmedOrderRef); ?>"
                        class="luxe-btn-view-order"
                        style="display: flex; align-items: center; justify-content: center; gap: 8px; padding: 14px 20px; background: #6b1d28; color: #ffffff; border-radius: 8px; font-size: 14px; font-weight: 700; text-decoration: none;"
                    >
                        <span>📄</span> View Order
                    </a>

                    <a
                        href="orders.php?action=download_dossier&ref=<?php echo urlencode($confirmedOrderRef); ?>"
                        class="luxe-btn-download-dossier"
                        style="display: flex; align-items: center; justify-content: center; gap: 8px; padding: 14px 20px; background: #fdfcf9; border: 1.5px solid #a67a42; color: #57141f; border-radius: 8px; font-size: 14px; font-weight: 700; text-decoration: none;"
                    >
                        <span>⬇</span> Download SHAGUN — Order Dossier (PDF)
                    </a>
                </div>

                <a
                    href="index.php"
                    class="luxe-btn-back-home"
                    style="display: inline-flex; align-items: center; justify-content: center; gap: 6px; padding: 12px; color: #73695e; font-size: 13px; font-weight: 600; text-decoration: none;"
                >
                    <span>🏠</span> Back to Home
                </a>
            </div>

        </div>
    </section>
    <?php endif; ?>

</main>

<?php include __DIR__ . '/includes/footer.php'; ?>
