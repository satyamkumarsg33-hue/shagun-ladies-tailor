<?php
/**
 * Shagun Ladies Tailor — Luxe Stitching Secure Payment Step
 * 
 * Flow:
 * Wedding Details → People → Garments → Luxe Workspace → Measurements → Review & Payment → Payment Page
 * 
 * Checkpoint & Security Rules:
 * - Displays and confirms the selected advance payment amount from review-payment.php.
 * - Does NOT trust URL query parameter for amount (e.g. ?amount=xxx is ignored).
 * - Amount is strictly read-only and sourced from server-side session: $_SESSION['luxe_wedding']['advance_payment'].
 * - Access is guarded: garments completed, measurements selected, advance payment recorded.
 * - Demo fallback mode provided if accessed directly without active session.
 * - Supports 4 distinct payment states: Ready, Processing, Success, Failed.
 * - Action buttons "View Order" and "Download Order Dossier" appear ONLY on Payment Success.
 * - Uses strictly Shagun terminology: "SHAGUN — Order Dossier".
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/cart.php';
require_user_login('payment.php');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$luxe = $_SESSION['luxe_wedding'] ?? [];
$people = $luxe['people'] ?? [];
$advancePayment = $luxe['advance_payment'] ?? null;

// Determine if we are in demo mode
if (!is_array($people) || count($people) === 0) {
    $demoMode = true;
    $peopleCount = 2;
    $garmentCount = 3;
    $occasion = 'Wedding';
    $grandTotal = 5235;
    $advanceAmount = 2618;
    $remainingBalance = 2617;
    $orderRef = 'LT' . date('Ymd') . '-001';
} else {
    $demoMode = false;
    
    // Checkpoint validation
    $totalGarments = 0;
    $completedGarments = 0;
    $allMeasurementsSelected = true;

    foreach ($people as $p) {
        $mMethod = $p['measurement_method'] ?? null;
        if (empty($mMethod) || !in_array($mMethod, ['reference_blouse', 'visit_shop'], true)) {
            $allMeasurementsSelected = false;
        }

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

    // Access Guards
    if ($totalGarments === 0 || $completedGarments < $totalGarments) {
        header('Location: luxe-workspace.php');
        exit;
    }

    if (!$allMeasurementsSelected) {
        header('Location: measurements.php');
        exit;
    }

    if (empty($advancePayment) || empty($advancePayment['selected_amount'])) {
        header('Location: review-payment.php');
        exit;
    }

    $peopleCount = count($people);
    $garmentCount = $totalGarments;
    $occasion = !empty($luxe['occasion']) ? ucfirst($luxe['occasion']) : 'Wedding';
    $grandTotal = (int) ($advancePayment['order_total'] ?? 0);
    $advanceAmount = (int) ($advancePayment['selected_amount'] ?? 0);
    $remainingBalance = $grandTotal - $advanceAmount;
    
    // Order Reference
    if (empty($_SESSION['luxe_wedding']['payment']['order_ref'])) {
        $orderRef = 'LT' . date('Ymd') . '-' . str_pad((string) mt_rand(1, 999), 3, '0', STR_PAD_LEFT);
    } else {
        $orderRef = $_SESSION['luxe_wedding']['payment']['order_ref'];
    }
}

// Order type and combined detection
$hasStandardInAdvance = !empty($advancePayment['has_standard']) || !empty($advancePayment['is_combined']);
$hasSavedStandardItems = !empty($_SESSION['luxe_wedding']['standard_items']);
$hasActiveStandardCart = !empty($_SESSION['demo_cart']['items']) || !empty($_SESSION['demo_cart']);

$standardItemCount = 0;
if (isset($advancePayment['standard_item_count'])) {
    $standardItemCount = (int) $advancePayment['standard_item_count'];
} elseif ($hasSavedStandardItems) {
    $standardItemCount = count($_SESSION['luxe_wedding']['standard_items']);
} elseif ($hasActiveStandardCart && function_exists('demo_cart_items')) {
    $stdItems = demo_cart_items();
    $standardItemCount = is_array($stdItems) ? count($stdItems) : 0;
}

$isCombinedOrder = ($hasStandardInAdvance || $hasSavedStandardItems) && $standardItemCount > 0;
$isStandardOnly = ($standardItemCount > 0 && ($garmentCount ?? 0) === 0);
$totalPhysicalGarmentCount = $isCombinedOrder ? (($garmentCount ?? 0) + $standardItemCount) : ($isStandardOnly ? $standardItemCount : ($garmentCount ?? 0));

// -------------------------------------------------------------
// POST HANDLING & DEMO SIMULATION
// -------------------------------------------------------------
$paymentState = 'ready'; // Default state: ready

if (isset($_SESSION['luxe_wedding']['payment']['status']) && $_SESSION['luxe_wedding']['payment']['status'] === 'completed') {
    $paymentState = 'success';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $loggedInUser = get_logged_in_user();
    $custUserId = (int)($loggedInUser['id'] ?? 0);
    $custEmail = $loggedInUser['email'] ?? '';

    if ($action === 'simulate_success') {
        $custProfile = $custUserId > 0 ? get_customer_profile($custUserId) : null;
        $custName = $custProfile['name'] ?? ($loggedInUser['name'] ?? 'Customer');
        $custPhone = $custProfile['phone'] ?? ($loggedInUser['phone'] ?? '');
        $custAddress = $custProfile['address'] ?? '';

        $bookedDate = date('Y-m-d');
        $_SESSION['luxe_wedding']['user_id'] = $custUserId;
        $_SESSION['luxe_wedding']['customer_name'] = $custName;
        $_SESSION['luxe_wedding']['customer_phone'] = $custPhone;
        $_SESSION['luxe_wedding']['customer_address'] = $custAddress;
        $_SESSION['luxe_wedding']['customer_email'] = $custEmail;
        $_SESSION['luxe_wedding']['booked_date'] = $bookedDate;
        $_SESSION['luxe_wedding']['order_ref'] = $orderRef;
        $_SESSION['luxe_wedding']['status'] = 'pending_confirmation';
        $_SESSION['luxe_wedding']['production_status'] = 'pending_confirmation';

        // Ensure requested date and admin delivery date are set
        if (empty($_SESSION['luxe_wedding']['requested_ready_date'])) {
            $_SESSION['luxe_wedding']['requested_ready_date'] = $_SESSION['luxe_wedding']['wedding_date'] ?? date('Y-m-d', strtotime('+15 days'));
        }
        if (empty($_SESSION['luxe_wedding']['admin_delivery_date'])) {
            $_SESSION['luxe_wedding']['admin_delivery_date'] = $_SESSION['luxe_wedding']['requested_ready_date'];
        }

        // Capture payment method and label
        $selectedPaymentMethod = trim((string)($_POST['payment_method'] ?? 'upi'));
        if (!in_array($selectedPaymentMethod, ['upi', 'card', 'netbanking', 'wallet', 'cash'], true)) {
            $selectedPaymentMethod = 'upi';
        }
        $selectedPaymentLabel = trim((string)($_POST['payment_method_label'] ?? 'UPI / QR Code (Demo Simulation)'));

        $_SESSION['luxe_wedding']['payment'] = [
            'status' => 'completed',
            'order_ref' => $orderRef,
            'amount_paid' => $advanceAmount,
            'remaining_balance' => $remainingBalance,
            'booked_date' => $bookedDate,
            'payment_method' => $selectedPaymentMethod,
            'payment_method_label' => $selectedPaymentLabel,
            'paid_at' => time()
        ];

        // If unified cart with Standard Stitching items, record them
        $hasActiveStd = !empty($_SESSION['demo_cart']['items']) || (function_exists('demo_cart_items') && !empty(demo_cart_items()));
        if (!empty($advancePayment['has_standard']) || !empty($advancePayment['is_combined']) || $isCombinedOrder || $hasActiveStd) {
            if (empty($_SESSION['luxe_wedding']['standard_items'])) {
                if (!empty($_SESSION['demo_cart']['items'])) {
                    $_SESSION['luxe_wedding']['standard_items'] = $_SESSION['demo_cart']['items'];
                } elseif (function_exists('demo_cart_items')) {
                    $cItems = demo_cart_items();
                    if (!empty($cItems)) {
                        $_SESSION['luxe_wedding']['standard_items'] = $cItems;
                    }
                }
            }
        }

        // Persist order to MySQL database inside transaction
        $saved = save_customer_completed_order($_SESSION['luxe_wedding']);

        if ($saved) {
            $paymentState = 'success';
            // Only clear cart after successful transaction commit
            if (function_exists('demo_cart_clear')) {
                demo_cart_clear();
            }
        } else {
            $paymentState = 'failed';
            unset($_SESSION['luxe_wedding']['payment']);
        }
    } elseif ($action === 'simulate_failed') {
        $_SESSION['luxe_wedding']['payment'] = [
            'status' => 'failed',
            'order_ref' => $orderRef,
            'failed_at' => time()
        ];
        $paymentState = 'failed';
    } elseif ($action === 'admin_update_date') {
        // Admin estimated delivery date update handler (strictly guarded to admin sessions)
        $newAdminDate = trim((string) ($_POST['admin_delivery_date'] ?? ''));
        $adminNote = trim((string) ($_POST['admin_note'] ?? ''));
        if (!empty($newAdminDate) && is_admin_logged_in()) {
            $_SESSION['luxe_wedding']['admin_delivery_date'] = $newAdminDate;
            $_SESSION['luxe_wedding']['admin_note'] = $adminNote;
            $_SESSION['luxe_wedding']['admin_updated_by'] = 'admin';
            $_SESSION['luxe_wedding']['admin_updated_at'] = time();
            if (!isset($_SESSION['luxe_wedding']['date_history']) || !is_array($_SESSION['luxe_wedding']['date_history'])) {
                $_SESSION['luxe_wedding']['date_history'] = [];
            }
            $_SESSION['luxe_wedding']['date_history'][] = [
                'type' => 'admin_update',
                'date' => $newAdminDate,
                'created_at' => time(),
                'formatted_created' => date('d M Y, h:i A'),
                'actor' => 'admin',
                'note' => $adminNote ?: "Admin updated estimated delivery date to $newAdminDate"
            ];
        }
    } elseif ($action === 'reset_ready') {
        unset($_SESSION['luxe_wedding']['payment']);
        $paymentState = 'ready';
    }
}

// Optional GET parameter for state preview/test (without overriding session amounts)
if (isset($_GET['state']) && in_array($_GET['state'], ['ready', 'processing', 'success', 'failed'], true)) {
    $paymentState = $_GET['state'];
}

include __DIR__ . '/includes/header.php';
?>

<main class="luxe-payment-page" data-payment-page data-amount="<?php echo $advanceAmount; ?>" data-state="<?php echo htmlspecialchars($paymentState); ?>">

    <!-- =========================================
         LUXE PROGRESS STEPPER (7 STEPS)
    ========================================== -->
    <?php
    require_once __DIR__ . '/includes/luxe-stepper.php';
    render_luxe_stepper(7, [
        'payment_state' => $paymentState,
    ]);
    ?>

    <!-- =========================================================
         STATE A: PAYMENT READY
    ========================================================== -->
    <div id="payment-state-ready" class="luxe-state-section <?php echo ($paymentState === 'ready') ? 'is-visible' : ''; ?>">
        
        <!-- PAGE HEADER -->
        <section class="luxe-payment-header">
            <div class="luxe-payment-container">
                <p class="luxe-workspace-eyebrow">LUXE STITCHING</p>
                <h1>Secure Payment</h1>
                <p class="luxe-payment-subtitle">
                    Complete your advance payment to confirm your order.
                </p>

                <div class="luxe-payment-security-badge">
                    <span class="luxe-lock-icon" aria-hidden="true">🔒</span>
                    <p>Your payment information is secure and encrypted. No card details are stored on our servers.</p>
                </div>
            </div>
        </section>

        <!-- MAIN TWO-COLUMN CONTENT -->
        <section class="luxe-payment-content">
            <div class="luxe-payment-container luxe-payment-grid">

                <!-- LEFT COLUMN: ORDER SUMMARY -->
                <div class="luxe-payment-summary-col">
                    <div class="luxe-payment-card luxe-summary-card">
                        <div class="luxe-card-header">
                            <span class="luxe-card-header-icon" aria-hidden="true">📄</span>
                            <h2>Order Summary</h2>
                        </div>

                        <div class="luxe-summary-table">
                            <?php if ($isCombinedOrder): ?>
                                <div class="luxe-summary-row">
                                    <span class="luxe-label">Order Type</span>
                                    <strong class="luxe-value">1. Standard Stitching, 2. Luxe Stitching</strong>
                                </div>
                                <div class="luxe-summary-row">
                                    <span class="luxe-label">Standard Items</span>
                                    <strong class="luxe-value"><?php echo $standardItemCount; ?></strong>
                                </div>
                                <?php if (!empty($occasion)): ?>
                                    <div class="luxe-summary-row">
                                        <span class="luxe-label">Luxe Occasion</span>
                                        <strong class="luxe-value"><?php echo htmlspecialchars($occasion); ?></strong>
                                    </div>
                                <?php endif; ?>
                                <div class="luxe-summary-row">
                                    <span class="luxe-label">Luxe People</span>
                                    <strong class="luxe-value"><?php echo $peopleCount; ?></strong>
                                </div>
                                <div class="luxe-summary-row">
                                    <span class="luxe-label">Luxe Garments</span>
                                    <strong class="luxe-value"><?php echo $garmentCount; ?></strong>
                                </div>
                                <div class="luxe-summary-row">
                                    <span class="luxe-label">Total Physical Garments</span>
                                    <strong class="luxe-value"><?php echo $totalPhysicalGarmentCount; ?></strong>
                                </div>
                            <?php elseif ($isStandardOnly): ?>
                                <div class="luxe-summary-row">
                                    <span class="luxe-label">Order Type</span>
                                    <strong class="luxe-value">Standard Stitching</strong>
                                </div>
                                <div class="luxe-summary-row">
                                    <span class="luxe-label">Standard Items</span>
                                    <strong class="luxe-value"><?php echo $standardItemCount; ?></strong>
                                </div>
                                <div class="luxe-summary-row">
                                    <span class="luxe-label">Total Physical Garments</span>
                                    <strong class="luxe-value"><?php echo $totalPhysicalGarmentCount; ?></strong>
                                </div>
                            <?php else: ?>
                                <div class="luxe-summary-row">
                                    <span class="luxe-label">Order Type</span>
                                    <strong class="luxe-value">Luxe Stitching</strong>
                                </div>
                                <div class="luxe-summary-row">
                                    <span class="luxe-label">Occasion</span>
                                    <strong class="luxe-value"><?php echo htmlspecialchars($occasion); ?></strong>
                                </div>
                                <div class="luxe-summary-row">
                                    <span class="luxe-label">People</span>
                                    <strong class="luxe-value"><?php echo $peopleCount; ?></strong>
                                </div>
                                <div class="luxe-summary-row">
                                    <span class="luxe-label">Physical Garments</span>
                                    <strong class="luxe-value"><?php echo $garmentCount; ?></strong>
                                </div>
                            <?php endif; ?>

                            <div class="luxe-summary-divider"></div>

                            <div class="luxe-summary-row">
                                <span class="luxe-label">Grand Total</span>
                                <strong class="luxe-value">₹<?php echo number_format($grandTotal); ?></strong>
                            </div>
                            <div class="luxe-summary-row">
                                <span class="luxe-label">Advance Amount (Selected)</span>
                                <strong class="luxe-value luxe-highlight-amount">₹<?php echo number_format($advanceAmount); ?></strong>
                            </div>
                            <div class="luxe-summary-row">
                                <span class="luxe-label">Remaining Balance</span>
                                <strong class="luxe-value">₹<?php echo number_format($remainingBalance); ?></strong>
                            </div>
                        </div>

                        <div class="luxe-summary-note">
                            <span class="luxe-note-icon" aria-hidden="true">ⓘ</span>
                            <p>You are paying the advance amount to confirm your order. The remaining balance can be paid later at our shop.</p>
                        </div>
                    </div>
                </div>

                <!-- RIGHT COLUMN: PAYMENT OPTIONS & PAY BUTTON -->
                <div class="luxe-payment-methods-col">
                    <div class="luxe-payment-card luxe-pay-box">
                        <div class="luxe-card-header">
                            <span class="luxe-card-header-icon" aria-hidden="true">💳</span>
                            <h2>Pay ₹<?php echo number_format($advanceAmount); ?></h2>
                        </div>
                        <p class="luxe-pay-subtext">Choose a payment method to complete your advance payment.</p>

                        <!-- Payment Method Tabs -->
                        <div class="luxe-pay-tabs" role="tablist" aria-label="Payment Methods">
                            <button type="button" class="luxe-pay-tab is-active" role="tab" aria-selected="true" data-tab="upi">
                                <span class="luxe-tab-icon" aria-hidden="true">⚡</span>
                                <span class="luxe-tab-text">UPI</span>
                            </button>
                            <button type="button" class="luxe-pay-tab" role="tab" aria-selected="false" data-tab="card">
                                <span class="luxe-tab-icon" aria-hidden="true">💳</span>
                                <span class="luxe-tab-text">Card</span>
                            </button>
                            <button type="button" class="luxe-pay-tab" role="tab" aria-selected="false" data-tab="netbanking">
                                <span class="luxe-tab-icon" aria-hidden="true">🏛️</span>
                                <span class="luxe-tab-text">Net Banking</span>
                            </button>
                            <button type="button" class="luxe-pay-tab" role="tab" aria-selected="false" data-tab="wallets">
                                <span class="luxe-tab-icon" aria-hidden="true">👛</span>
                                <span class="luxe-tab-text">Wallet / Others</span>
                            </button>
                        </div>

                        <!-- Tab Panels Container -->
                        <div class="luxe-pay-panel-wrap">
                            
                            <!-- 1. UPI PANEL -->
                            <div id="tab-panel-upi" class="luxe-pay-panel is-active" role="tabpanel">
                                <h3 class="luxe-panel-title">Pay using UPI</h3>
                                <p class="luxe-panel-desc">Scan the QR code with any UPI app or click on a UPI app below.</p>

                                <div class="luxe-upi-content">
                                    <div class="luxe-qr-block">
                                        <div class="luxe-qr-frame">
                                            <!-- SVG Vector QR Code Visual Representation -->
                                            <svg class="luxe-qr-svg" viewBox="0 0 100 100" width="128" height="128" aria-label="UPI QR Code">
                                                <rect width="100" height="100" fill="#ffffff" />
                                                <!-- Top Left Finder Pattern -->
                                                <rect x="6" y="6" width="24" height="24" fill="#1d1b19" rx="2" />
                                                <rect x="10" y="10" width="16" height="16" fill="#ffffff" rx="1" />
                                                <rect x="14" y="14" width="8" height="8" fill="#1d1b19" rx="1" />
                                                <!-- Top Right Finder Pattern -->
                                                <rect x="70" y="6" width="24" height="24" fill="#1d1b19" rx="2" />
                                                <rect x="74" y="10" width="16" height="16" fill="#ffffff" rx="1" />
                                                <rect x="78" y="14" width="8" height="8" fill="#1d1b19" rx="1" />
                                                <!-- Bottom Left Finder Pattern -->
                                                <rect x="6" y="70" width="24" height="24" fill="#1d1b19" rx="2" />
                                                <rect x="10" y="74" width="16" height="16" fill="#ffffff" rx="1" />
                                                <rect x="14" y="78" width="8" height="8" fill="#1d1b19" rx="1" />
                                                <!-- Data Matrix Points -->
                                                <rect x="36" y="8" width="6" height="6" fill="#1d1b19" />
                                                <rect x="48" y="8" width="6" height="6" fill="#1d1b19" />
                                                <rect x="58" y="8" width="6" height="6" fill="#1d1b19" />
                                                <rect x="36" y="20" width="6" height="6" fill="#1d1b19" />
                                                <rect x="44" y="24" width="8" height="6" fill="#1d1b19" />
                                                <rect x="8" y="38" width="8" height="6" fill="#1d1b19" />
                                                <rect x="22" y="38" width="6" height="6" fill="#1d1b19" />
                                                <rect x="34" y="36" width="12" height="12" fill="#1d1b19" rx="2" />
                                                <rect x="52" y="36" width="8" height="6" fill="#1d1b19" />
                                                <rect x="66" y="38" width="8" height="6" fill="#1d1b19" />
                                                <rect x="80" y="38" width="12" height="6" fill="#1d1b19" />
                                                <rect x="10" y="52" width="6" height="8" fill="#1d1b19" />
                                                <rect x="24" y="50" width="6" height="8" fill="#1d1b19" />
                                                <rect x="38" y="54" width="8" height="8" fill="#1d1b19" />
                                                <rect x="52" y="48" width="12" height="6" fill="#1d1b19" />
                                                <rect x="70" y="50" width="8" height="8" fill="#1d1b19" />
                                                <rect x="84" y="52" width="8" height="8" fill="#1d1b19" />
                                                <rect x="38" y="68" width="6" height="8" fill="#1d1b19" />
                                                <rect x="50" y="70" width="10" height="6" fill="#1d1b19" />
                                                <rect x="66" y="66" width="6" height="8" fill="#1d1b19" />
                                                <rect x="78" y="72" width="14" height="6" fill="#1d1b19" />
                                                <rect x="36" y="84" width="8" height="8" fill="#1d1b19" />
                                                <rect x="50" y="82" width="8" height="10" fill="#1d1b19" />
                                                <rect x="64" y="84" width="8" height="8" fill="#1d1b19" />
                                                <rect x="80" y="84" width="12" height="8" fill="#1d1b19" />
                                            </svg>
                                        </div>
                                    </div>

                                    <div class="luxe-upi-or-divider">
                                        <span>OR</span>
                                    </div>

                                    <div class="luxe-upi-apps">
                                        <p class="luxe-upi-apps-label">Pay with UPI App</p>
                                        <div class="luxe-upi-grid">
                                            <button type="button" class="luxe-upi-app-btn" data-app="gpay">
                                                <span class="luxe-app-badge gpay-badge">GPay</span>
                                                <span class="luxe-app-name">Google Pay</span>
                                            </button>
                                            <button type="button" class="luxe-upi-app-btn" data-app="phonepe">
                                                <span class="luxe-app-badge phonepe-badge">Pe</span>
                                                <span class="luxe-app-name">PhonePe</span>
                                            </button>
                                            <button type="button" class="luxe-upi-app-btn" data-app="paytm">
                                                <span class="luxe-app-badge paytm-badge">Paytm</span>
                                                <span class="luxe-app-name">Paytm</span>
                                            </button>
                                            <button type="button" class="luxe-upi-app-btn" data-app="bhim">
                                                <span class="luxe-app-badge bhim-badge">BHIM</span>
                                                <span class="luxe-app-name">BHIM</span>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- 2. CARD PANEL -->
                            <div id="tab-panel-card" class="luxe-pay-panel" role="tabpanel" style="display: none;">
                                <h3 class="luxe-panel-title">Credit / Debit Card</h3>
                                <div class="luxe-card-form">
                                    <div class="luxe-field-group">
                                        <label for="demo-card-num">Card Number</label>
                                        <input type="text" id="demo-card-num" placeholder="•••• •••• •••• ••••" maxlength="19" readonly value="•••• •••• •••• 4242">
                                    </div>
                                    <div class="luxe-field-row">
                                        <div class="luxe-field-group">
                                            <label for="demo-card-exp">Valid Thru</label>
                                            <input type="text" id="demo-card-exp" placeholder="MM / YY" maxlength="5" readonly value="12 / 28">
                                        </div>
                                        <div class="luxe-field-group">
                                            <label for="demo-card-cvv">CVV</label>
                                            <input type="password" id="demo-card-cvv" placeholder="•••" maxlength="4" readonly value="123">
                                        </div>
                                    </div>
                                    <div class="luxe-field-group">
                                        <label for="demo-card-name">Name on Card</label>
                                        <input type="text" id="demo-card-name" placeholder="Cardholder Name" readonly value="Customer Demo">
                                    </div>
                                    <p class="luxe-card-demo-note">🔒 Demo Mode: Inputs are read-only to protect sensitive information.</p>
                                </div>
                            </div>

                            <!-- 3. NET BANKING PANEL -->
                            <div id="tab-panel-netbanking" class="luxe-pay-panel" role="tabpanel" style="display: none;">
                                <h3 class="luxe-panel-title">Net Banking</h3>
                                <p class="luxe-panel-desc">Select from popular banks or search your bank.</p>
                                <div class="luxe-bank-grid">
                                    <label class="luxe-bank-item is-selected">
                                        <input type="radio" name="bank_option" value="sbi" checked>
                                        <span>SBI</span>
                                    </label>
                                    <label class="luxe-bank-item">
                                        <input type="radio" name="bank_option" value="hdfc">
                                        <span>HDFC Bank</span>
                                    </label>
                                    <label class="luxe-bank-item">
                                        <input type="radio" name="bank_option" value="icici">
                                        <span>ICICI Bank</span>
                                    </label>
                                    <label class="luxe-bank-item">
                                        <input type="radio" name="bank_option" value="axis">
                                        <span>Axis Bank</span>
                                    </label>
                                </div>
                            </div>

                            <!-- 4. WALLET PANEL -->
                            <div id="tab-panel-wallets" class="luxe-pay-panel" role="tabpanel" style="display: none;">
                                <h3 class="luxe-panel-title">Wallets & Others</h3>
                                <p class="luxe-panel-desc">Pay directly from your linked digital wallet.</p>
                                <div class="luxe-wallet-list">
                                    <label class="luxe-wallet-item is-selected">
                                        <input type="radio" name="wallet_option" value="paytm" checked>
                                        <span>Paytm Wallet</span>
                                    </label>
                                    <label class="luxe-wallet-item">
                                        <input type="radio" name="wallet_option" value="phonepe">
                                        <span>PhonePe Wallet</span>
                                    </label>
                                    <label class="luxe-wallet-item">
                                        <input type="radio" name="wallet_option" value="amazonpay">
                                        <span>Amazon Pay</span>
                                    </label>
                                </div>
                            </div>

                        </div>

                        <!-- PAY BUTTON -->
                        <button type="button" id="pay-submit-btn" class="luxe-pay-btn">
                            <span class="luxe-btn-lock">🔒</span>
                            <span class="luxe-btn-text">Pay ₹<?php echo number_format($advanceAmount); ?></span>
                        </button>

                        <p class="luxe-pay-redirect-note">
                            <span aria-hidden="true">ⓘ</span> You will be redirected to a secure payment page (Demo Mode).
                        </p>

                        <!-- DEMO SIMULATION CONTROLS -->
                        <div class="luxe-demo-sim-panel">
                            <div class="luxe-sim-header">
                                <span class="luxe-sim-badge">Payment Gateway Integration Coming Soon</span>
                            </div>
                            <p class="luxe-sim-desc">
                                Since no live payment gateway is connected yet, use these controlled buttons to test the flow:
                            </p>
                            <div class="luxe-sim-actions">
                                <form method="POST" action="payment.php" class="luxe-sim-form" id="simulate-success-form" style="display:inline;">
                                    <input type="hidden" name="action" value="simulate_success">
                                    <input type="hidden" name="payment_method" id="sim-payment-method" value="upi">
                                    <input type="hidden" name="payment_method_label" id="sim-payment-method-label" value="UPI / QR Code (Demo Simulation)">
                                    <button type="submit" id="simulate-success-btn" class="luxe-sim-btn is-success">
                                        ✓ Simulate Successful Payment
                                    </button>
                                </form>
                                <form method="POST" action="payment.php" class="luxe-sim-form" style="display:inline;">
                                    <input type="hidden" name="action" value="simulate_failed">
                                    <button type="submit" id="simulate-failed-btn" class="luxe-sim-btn is-failed">
                                        ✕ Simulate Failed Payment
                                    </button>
                                </form>
                            </div>
                        </div>

                    </div>
                </div>

            </div>
        </section>

        <!-- BOTTOM NAVIGATION & TRUST BADGES -->
        <section class="luxe-payment-footer-section">
            <div class="luxe-payment-container luxe-payment-footer-row">
                <a href="review-payment.php" class="luxe-btn-back-review">
                    ← Back to Review & Payment
                </a>

                <div class="luxe-trust-badges">
                    <div class="luxe-trust-item">
                        <span class="luxe-trust-icon" aria-hidden="true">🛡️</span>
                        <div class="luxe-trust-text">
                            <strong>Secure Payments</strong>
                            <span>SSL Encrypted</span>
                        </div>
                    </div>
                    <div class="luxe-trust-item">
                        <span class="luxe-trust-icon" aria-hidden="true">🔒</span>
                        <div class="luxe-trust-text">
                            <strong>Trusted & Safe</strong>
                            <span>Your data is protected</span>
                        </div>
                    </div>
                    <div class="luxe-trust-item">
                        <span class="luxe-trust-icon" aria-hidden="true">🎧</span>
                        <div class="luxe-trust-text">
                            <strong>Need Help?</strong>
                            <span>Contact Us</span>
                        </div>
                    </div>
                </div>
            </div>
        </section>

    </div>

    <!-- =========================================================
         STATE B: PAYMENT PROCESSING
    ========================================================== -->
    <div id="payment-state-processing" class="luxe-state-section <?php echo ($paymentState === 'processing') ? 'is-visible' : ''; ?>" style="<?php echo ($paymentState !== 'processing') ? 'display: none;' : ''; ?>">
        <section class="luxe-processing-section">
            <div class="luxe-payment-container luxe-processing-container">
                
                <h1 class="luxe-processing-title">Processing Payment</h1>
                <p class="luxe-processing-subtitle">Please do not close this page.</p>

                <!-- Processing Spinner Ring -->
                <div class="luxe-spinner-wrap">
                    <div class="luxe-spinner-ring">
                        <div class="luxe-spinner-inner">
                            <span class="luxe-spinner-lock" aria-hidden="true">🔒</span>
                        </div>
                    </div>
                </div>

                <div class="luxe-processing-status-box">
                    <h2 class="luxe-status-heading">Processing your payment...</h2>
                    <p class="luxe-status-desc">This may take a few seconds.</p>

                    <!-- Animated Checklist -->
                    <ul class="luxe-processing-checklist">
                        <li class="checklist-item is-done">
                            <span class="check-icon">✓</span>
                            <span>Connecting to secure payment gateway</span>
                        </li>
                        <li class="checklist-item is-done">
                            <span class="check-icon">✓</span>
                            <span>Validating payment details</span>
                        </li>
                        <li class="checklist-item is-active">
                            <span class="check-icon check-spinner"></span>
                            <span>Processing your payment</span>
                        </li>
                        <li class="checklist-item is-pending">
                            <span class="check-icon">◯</span>
                            <span>Please wait...</span>
                        </li>
                    </ul>
                </div>

                <div class="luxe-processing-warning">
                    <span class="luxe-warning-icon" aria-hidden="true">ⓘ</span>
                    <p>Do not press the back button or close this page while the payment is being processed.</p>
                </div>

            </div>
        </section>
    </div>

    <!-- =========================================================
         STATE C: PAYMENT SUCCESS
    ========================================================== -->
    <div id="payment-state-success" class="luxe-state-section <?php echo ($paymentState === 'success') ? 'is-visible' : ''; ?>" style="<?php echo ($paymentState !== 'success') ? 'display: none;' : ''; ?>">
        <section class="luxe-success-section">
            <div class="luxe-payment-container luxe-success-container">
                
                <!-- Success Badge -->
                <div class="luxe-success-badge" aria-hidden="true">
                    <span class="luxe-success-check">✓</span>
                </div>

                <h1 class="luxe-success-title">Payment Successful</h1>
                <p class="luxe-success-subtitle">Your advance payment has been received successfully.</p>

                <!-- Order Receipt Card -->
                <div class="luxe-success-card">
                    
                    <div class="luxe-success-table">
                        <div class="luxe-success-row">
                            <span class="luxe-label">Amount Paid</span>
                            <strong class="luxe-value luxe-success-amount">₹<?php echo number_format($advanceAmount); ?></strong>
                        </div>
                        <div class="luxe-success-row">
                            <span class="luxe-label">Order Reference</span>
                            <div class="luxe-ref-copy-wrap">
                                <strong class="luxe-value" id="order-ref-text"><?php echo htmlspecialchars($orderRef); ?></strong>
                                <button type="button" id="copy-ref-btn" class="luxe-copy-btn" title="Copy order reference" aria-label="Copy order reference">❐</button>
                            </div>
                        </div>
                        <?php if ($isCombinedOrder): ?>
                            <div class="luxe-success-row">
                                <span class="luxe-label">Order Type</span>
                                <strong class="luxe-value">1. Standard Stitching, 2. Luxe Stitching</strong>
                            </div>
                            <div class="luxe-summary-row">
                                <span class="luxe-label">Standard Items</span>
                                <strong class="luxe-value"><?php echo $standardItemCount; ?></strong>
                            </div>
                            <?php if (!empty($occasion)): ?>
                                <div class="luxe-summary-row">
                                    <span class="luxe-label">Luxe Occasion</span>
                                    <strong class="luxe-value"><?php echo htmlspecialchars($occasion); ?></strong>
                                </div>
                            <?php endif; ?>
                            <div class="luxe-summary-row">
                                <span class="luxe-label">Luxe People</span>
                                <strong class="luxe-value"><?php echo $peopleCount; ?></strong>
                            </div>
                            <div class="luxe-summary-row">
                                <span class="luxe-label">Luxe Garments</span>
                                <strong class="luxe-value"><?php echo $garmentCount; ?></strong>
                            </div>
                            <div class="luxe-summary-row">
                                <span class="luxe-label">Total Physical Garments</span>
                                <strong class="luxe-value"><?php echo $totalPhysicalGarmentCount; ?></strong>
                            </div>
                        <?php elseif ($isStandardOnly): ?>
                            <div class="luxe-success-row">
                                <span class="luxe-label">Order Type</span>
                                <strong class="luxe-value">Standard Stitching</strong>
                            </div>
                            <div class="luxe-summary-row">
                                <span class="luxe-label">Standard Items</span>
                                <strong class="luxe-value"><?php echo $standardItemCount; ?></strong>
                            </div>
                            <div class="luxe-summary-row">
                                <span class="luxe-label">Total Physical Garments</span>
                                <strong class="luxe-value"><?php echo $totalPhysicalGarmentCount; ?></strong>
                            </div>
                        <?php else: ?>
                            <div class="luxe-success-row">
                                <span class="luxe-label">Order Type</span>
                                <strong class="luxe-value">Luxe Stitching</strong>
                            </div>
                            <div class="luxe-summary-row">
                                <span class="luxe-label">Occasion</span>
                                <strong class="luxe-value"><?php echo htmlspecialchars($occasion); ?></strong>
                            </div>
                            <div class="luxe-summary-row">
                                <span class="luxe-label">People</span>
                                <strong class="luxe-value"><?php echo $peopleCount; ?></strong>
                            </div>
                            <div class="luxe-summary-row">
                                <span class="luxe-label">Physical Garments</span>
                                <strong class="luxe-value"><?php echo $garmentCount; ?></strong>
                            </div>
                        <?php endif; ?>

                        <div class="luxe-summary-divider"></div>

                        <div class="luxe-summary-row">
                            <span class="luxe-label">Grand Total</span>
                            <strong class="luxe-value">₹<?php echo number_format($grandTotal); ?></strong>
                        </div>
                        <div class="luxe-summary-row">
                            <span class="luxe-label">Advance Paid</span>
                            <strong class="luxe-value luxe-success-amount">₹<?php echo number_format($advanceAmount); ?></strong>
                        </div>
                        <div class="luxe-summary-row">
                            <span class="luxe-label">Remaining Balance</span>
                            <strong class="luxe-value">₹<?php echo number_format($remainingBalance); ?></strong>
                        </div>
                    </div>

                    <!-- Green Order Confirmed Banner -->
                    <div class="luxe-order-confirmed-banner">
                        <span class="luxe-confirmed-check" aria-hidden="true">✓</span>
                        <div class="luxe-confirmed-text">
                            <strong>Your order is now confirmed.</strong>
                            <p>We will start working on your order as per the selected measurements and design choices.</p>
                        </div>
                    </div>

                    <!-- ACTION BUTTONS: VIEW ORDER & DOWNLOAD ORDER DOSSIER & BACK TO HOME -->
                    <div class="luxe-success-actions-wrapper">
                        <!-- Desktop action layout -->
                        <div class="luxe-success-actions-desktop">
                            <div class="luxe-success-btn-row">
                                <a href="orders.php?ref=<?php echo urlencode($orderRef); ?>" class="luxe-btn-view-order" id="view-order-btn-desktop">
                                    <span class="luxe-action-icon" aria-hidden="true">📄</span>
                                    <span>View Order</span>
                                </a>
                                <a href="orders.php?action=download_dossier&ref=<?php echo urlencode($orderRef); ?>" class="luxe-btn-download-dossier" id="download-dossier-btn-desktop">
                                    <span class="luxe-action-icon" aria-hidden="true">⬇</span>
                                    <span>Download SHAGUN — Order Dossier (PDF)</span>
                                </a>
                            </div>
                            <div class="luxe-home-btn-row">
                                <a href="index.php" class="luxe-btn-back-home" id="back-home-btn-desktop">
                                    <span class="luxe-action-icon" aria-hidden="true">🏠</span>
                                    <span>Back to Home</span>
                                </a>
                            </div>
                        </div>

                        <!-- Mobile action layout (stacked vertically) -->
                        <div class="luxe-success-actions-mobile">
                            <a href="orders.php?ref=<?php echo urlencode($orderRef); ?>" class="luxe-btn-view-order" id="view-order-btn-mobile">
                                <span class="luxe-action-icon" aria-hidden="true">📄</span>
                                <span>View Order</span>
                            </a>
                            <a href="orders.php?action=download_dossier&ref=<?php echo urlencode($orderRef); ?>" class="luxe-btn-download-dossier" id="download-dossier-btn-mobile">
                                <span class="luxe-action-icon" aria-hidden="true">⬇</span>
                                <span>Download SHAGUN — Order Dossier (PDF)</span>
                            </a>
                            <a href="index.php" class="luxe-btn-back-home" id="back-home-btn-mobile">
                                <span class="luxe-action-icon" aria-hidden="true">🏠</span>
                                <span>Back to Home</span>
                            </a>
                        </div>
                    </div>

                </div>

            </div>
        </section>
    </div>

    <!-- =========================================================
         STATE D: PAYMENT FAILED
    ========================================================== -->
    <div id="payment-state-failed" class="luxe-state-section <?php echo ($paymentState === 'failed') ? 'is-visible' : ''; ?>" style="<?php echo ($paymentState !== 'failed') ? 'display: none;' : ''; ?>">
        <section class="luxe-failed-section">
            <div class="luxe-payment-container luxe-failed-container">
                
                <!-- Failed Badge -->
                <div class="luxe-failed-badge" aria-hidden="true">
                    <span class="luxe-failed-icon">✕</span>
                </div>

                <h1 class="luxe-failed-title">Payment Failed</h1>
                <p class="luxe-failed-subtitle">Your payment could not be processed.</p>

                <div class="luxe-failed-card">
                    <div class="luxe-failed-message">
                        <span class="luxe-failed-alert-icon" aria-hidden="true">⚠️</span>
                        <p>No payment was recorded or charged. Please check your payment details or choose another payment method.</p>
                    </div>

                    <div class="luxe-failed-actions">
                        <form method="POST" action="payment.php" style="display:inline;">
                            <input type="hidden" name="action" value="reset_ready">
                            <button type="submit" id="try-again-btn" class="luxe-btn-try-again">
                                Try Again
                            </button>
                        </form>
                        <a href="review-payment.php" class="luxe-btn-back-review-secondary">
                            Back to Review & Payment
                        </a>
                    </div>
                </div>

            </div>
        </section>
    </div>

</main>

<?php include __DIR__ . '/includes/footer.php'; ?>
