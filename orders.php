<?php
/**
 * Shagun Ladies Tailor — Customer Orders & Dossiers Redesign
 * 
 * Supports:
 * - Standard Stitching orders
 * - Luxe Stitching orders
 * - Combined Standard + Luxe orders
 * 
 * Grouped into 3 customer-facing status categories:
 *  1. Awaiting Confirmation: Orders received and awaiting confirmation or initial processing.
 *  2. In Production: Orders currently being prepared, stitched, embroidered, or checked.
 *  3. Completed Orders: Completed orders ready for collection, delivery, or customer reference.
 *     (Sorted newest completed first)
 * 
 * Features:
 * - Summary count bar (Total, Awaiting, Production, Completed)
 * - Compact summary cards with accessible expandable details (View Order Details ↓ / Hide Order Details ↑)
 * - Complete garment, timeline, date history, and financial breakdowns
 * - Official SHAGUN — Order Dossier PDF download
 * - Admin Date Management simulation toolbox
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/order-status.php';
require_user_login('orders.php');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$currentUser = get_logged_in_user();
$currentUserId = (int)($currentUser['id'] ?? 0);
$currentUserEmail = strtolower((string)($currentUser['email'] ?? ''));

$requestedRef = trim((string) ($_GET['ref'] ?? ''));

// Fetch customer orders from persistent session store
$customerOrders = get_customer_orders($currentUserId);
if (!is_array($customerOrders)) {
    $customerOrders = [];
}

// Session orders check
$standard = $_SESSION['standard_order'] ?? [];
$isStandardCompleted = !empty($standard['payment']['status']) && $standard['payment']['status'] === 'completed';
$isStandardOwner = $isStandardCompleted && (
    (!empty($standard['user_id']) && (int)$standard['user_id'] === $currentUserId)
    || (!empty($standard['customer_email']) && strtolower($standard['customer_email']) === $currentUserEmail)
    || (empty($standard['user_id']) && empty($standard['customer_email']))
);

$luxe = $_SESSION['luxe_wedding'] ?? [];
$isLuxeCompleted = !empty($luxe['payment']['status']) && $luxe['payment']['status'] === 'completed';
$isLuxeOwner = $isLuxeCompleted && (
    (!empty($luxe['user_id']) && (int)$luxe['user_id'] === $currentUserId)
    || (!empty($luxe['customer_email']) && strtolower($luxe['customer_email']) === $currentUserEmail)
    || (empty($luxe['user_id']) && empty($luxe['customer_email']))
);

if ($isStandardOwner) {
    $stdRef = (string)($standard['order_ref'] ?? ($standard['payment']['order_ref'] ?? ''));
    if ($stdRef !== '' && !isset($customerOrders[$stdRef])) {
        $customerOrders[$stdRef] = $standard;
    }
}
if ($isLuxeOwner) {
    $luxeRef = (string)($luxe['order_ref'] ?? ($luxe['payment']['order_ref'] ?? ''));
    if ($luxeRef !== '' && !isset($customerOrders[$luxeRef])) {
        $customerOrders[$luxeRef] = $luxe;
    }
}

// -------------------------------------------------------------
// POST HANDLER: Admin Delivery Date Simulation Update
// -------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['action']) && $_POST['action'] === 'admin_update_date') {
    if (!is_admin_logged_in()) {
        header('Location: orders.php?error=admin_required');
        exit;
    }
    $targetRef = trim((string) ($_POST['order_ref'] ?? ''));
    $newDate = trim((string) ($_POST['admin_delivery_date'] ?? ''));
    $note = trim((string) ($_POST['admin_note'] ?? ''));

    if (!empty($newDate) && !empty($targetRef)) {
        if (isset($_SESSION['customer_orders'][$currentUserId][$targetRef])) {
            $_SESSION['customer_orders'][$currentUserId][$targetRef]['admin_delivery_date'] = $newDate;
            $_SESSION['customer_orders'][$currentUserId][$targetRef]['admin_note'] = $note;
            $_SESSION['customer_orders'][$currentUserId][$targetRef]['admin_updated_by'] = 'admin';
            $_SESSION['customer_orders'][$currentUserId][$targetRef]['admin_updated_at'] = time();
            if (!isset($_SESSION['customer_orders'][$currentUserId][$targetRef]['date_history']) || !is_array($_SESSION['customer_orders'][$currentUserId][$targetRef]['date_history'])) {
                $_SESSION['customer_orders'][$currentUserId][$targetRef]['date_history'] = [];
            }
            $_SESSION['customer_orders'][$currentUserId][$targetRef]['date_history'][] = [
                'type' => 'admin_update',
                'date' => $newDate,
                'created_at' => time(),
                'formatted_created' => date('d M Y, h:i A'),
                'actor' => 'admin',
                'note' => $note ?: "Admin updated estimated delivery date to $newDate"
            ];
        }

        if ($isStandardOwner && ((!empty($standard['order_ref']) && $standard['order_ref'] === $targetRef) || (!empty($standard['payment']['order_ref']) && $standard['payment']['order_ref'] === $targetRef))) {
            $_SESSION['standard_order']['admin_delivery_date'] = $newDate;
            $_SESSION['standard_order']['admin_note'] = $note;
            $_SESSION['standard_order']['admin_updated_by'] = 'admin';
            $_SESSION['standard_order']['admin_updated_at'] = time();
            if (!isset($_SESSION['standard_order']['date_history']) || !is_array($_SESSION['standard_order']['date_history'])) {
                $_SESSION['standard_order']['date_history'] = [];
            }
            $_SESSION['standard_order']['date_history'][] = [
                'type' => 'admin_update',
                'date' => $newDate,
                'created_at' => time(),
                'formatted_created' => date('d M Y, h:i A'),
                'actor' => 'admin',
                'note' => $note ?: "Admin updated estimated delivery date to $newDate"
            ];
        }

        if ($isLuxeOwner && ((!empty($luxe['order_ref']) && $luxe['order_ref'] === $targetRef) || (!empty($luxe['payment']['order_ref']) && $luxe['payment']['order_ref'] === $targetRef))) {
            $_SESSION['luxe_wedding']['admin_delivery_date'] = $newDate;
            $_SESSION['luxe_wedding']['admin_note'] = $note;
            $_SESSION['luxe_wedding']['admin_updated_by'] = 'admin';
            $_SESSION['luxe_wedding']['admin_updated_at'] = time();
            if (!isset($_SESSION['luxe_wedding']['date_history']) || !is_array($_SESSION['luxe_wedding']['date_history'])) {
                $_SESSION['luxe_wedding']['date_history'] = [];
            }
            $_SESSION['luxe_wedding']['date_history'][] = [
                'type' => 'admin_update',
                'date' => $newDate,
                'created_at' => time(),
                'formatted_created' => date('d M Y, h:i A'),
                'actor' => 'admin',
                'note' => $note ?: "Admin updated estimated delivery date to $newDate"
            ];
        }

        header('Location: orders.php?ref=' . urlencode($targetRef) . '&updated=1');
        exit;
    }
}

// -------------------------------------------------------------
// GET HANDLER: PDF Dossier Download Route
// -------------------------------------------------------------
if (isset($_GET['action']) && $_GET['action'] === 'download_dossier') {
    require_once __DIR__ . '/includes/dossier-pdf.php';
    $targetRef = trim((string) ($_GET['ref'] ?? ''));
    $orderToDownload = null;

    if ($targetRef !== '') {
        if (isset($customerOrders[$targetRef])) {
            $orderToDownload = $customerOrders[$targetRef];
        } elseif ($isStandardOwner && ((!empty($standard['order_ref']) && $standard['order_ref'] === $targetRef) || (!empty($standard['payment']['order_ref']) && $standard['payment']['order_ref'] === $targetRef))) {
            $orderToDownload = $standard;
        } elseif ($isLuxeOwner && ((!empty($luxe['order_ref']) && $luxe['order_ref'] === $targetRef) || (!empty($luxe['payment']['order_ref']) && $luxe['payment']['order_ref'] === $targetRef))) {
            $orderToDownload = $luxe;
        }
    }
    if ($orderToDownload === null && !empty($customerOrders)) {
        $orderToDownload = end($customerOrders);
    }

    if ($orderToDownload !== null) {
        $pdfData = $orderToDownload;
        $uId = (int) ($pdfData['user_id'] ?? ($user['id'] ?? 0));
        if ($uId > 0 && function_exists('get_customer_profile')) {
            $custProfile = get_customer_profile($uId);
            if ($custProfile) {
                $pdfData['customer_name'] = $custProfile['name'];
                $pdfData['customer_phone'] = $custProfile['phone'] ?? '';
                $pdfData['customer_email'] = $custProfile['email'] ?? '';
                $pdfData['customer_address'] = $custProfile['address'] ?? '';
            }
        }
        $isStd = ($pdfData['order_type'] ?? '') === 'Standard Stitching' || ($pdfData['workflow'] ?? '') === 'standard';
        if (empty($pdfData['order_type'])) {
            $pdfData['order_type'] = $isStd ? 'Standard Stitching' : 'Luxe Stitching';
        }
        if (empty($pdfData['occasion'])) {
            $pdfData['occasion'] = $isStd ? 'Standard Stitching' : 'Wedding';
        }
        if (empty($pdfData['booked_date'])) {
            $pdfData['booked_date'] = $pdfData['payment']['booked_date'] ?? date('Y-m-d');
        }
        if (empty($pdfData['requested_ready_date'])) {
            $pdfData['requested_ready_date'] = $pdfData['wedding_date'] ?? date('Y-m-d', strtotime('+15 days'));
        }
        if (empty($pdfData['admin_delivery_date'])) {
            $pdfData['admin_delivery_date'] = $pdfData['requested_ready_date'];
        }
        ShagunDossierPdf::download($pdfData);
        exit;
    } else {
        header('Location: orders.php');
        exit;
    }
}

// -------------------------------------------------------------
// UNIFIED ORDER NORMALIZATION
// -------------------------------------------------------------
function normalize_customer_order(array $raw): array {
    $orderRef = (string)($raw['order_ref'] ?? ($raw['payment']['order_ref'] ?? ''));
    if ($orderRef === '') {
        $orderRef = 'LT' . date('Ymd') . '-001';
    }

    $workflow = (string)($raw['workflow'] ?? '');
    $declaredType = (string)($raw['order_type'] ?? '');
    $hasSavedStd = !empty($raw['standard_items']) && is_array($raw['standard_items']);
    $hasPeople = !empty($raw['people']) && is_array($raw['people']);

    // Check if people array is just a standard order single customer wrapper
    $isStandardPeople = false;
    if ($hasPeople && count($raw['people']) === 1 && ($raw['people'][0]['role'] ?? '') === 'Customer' && $workflow === 'standard') {
        $isStandardPeople = true;
    }

    // Determine order category / type
    if ($hasSavedStd && $hasPeople && !$isStandardPeople) {
        $isCombined = true;
        $isStandardOnly = false;
        $isLuxeOnly = false;
        $orderTypeLabel = 'Combined Order';
        $orderTypeBadge = 'STANDARD + LUXE';
    } elseif ($workflow === 'standard' || $declaredType === 'Standard Stitching' || $isStandardPeople) {
        $isCombined = false;
        $isStandardOnly = true;
        $isLuxeOnly = false;
        $orderTypeLabel = 'Standard Stitching';
        $orderTypeBadge = 'STANDARD STITCHING';
    } else {
        $isCombined = false;
        $isStandardOnly = false;
        $isLuxeOnly = true;
        $orderTypeLabel = 'Luxe Stitching';
        $orderTypeBadge = 'SHAGUN LUXE';
    }

    // Dates
    $bookedDateRaw = (string)($raw['booked_date'] ?? ($raw['payment']['booked_date'] ?? date('Y-m-d')));
    $bookedTimestamp = strtotime($bookedDateRaw) ?: time();
    $bookedFormatted = date('j F Y', $bookedTimestamp);

    $requestedDateRaw = (string)($raw['requested_ready_date'] ?? ($raw['wedding_date'] ?? ''));
    $requestedFormatted = !empty($requestedDateRaw) ? date('j F Y', strtotime($requestedDateRaw)) : 'Not specified';

    $estimatedDateRaw = (string)($raw['admin_delivery_date'] ?? $requestedDateRaw);
    $estimatedFormatted = !empty($estimatedDateRaw) ? date('j F Y', strtotime($estimatedDateRaw)) : $requestedFormatted;

    $adminUpdated = !empty($raw['admin_updated_by']);
    $adminNote = (string)($raw['admin_note'] ?? '');
    $dateHistory = (array)($raw['date_history'] ?? []);

    // Financials
    $advancePayment = $raw['advance_payment'] ?? [];
    $payment = $raw['payment'] ?? [];
    $grandTotal = (int)($advancePayment['order_total'] ?? ($raw['grand_total'] ?? (($payment['amount_paid'] ?? 0) + ($payment['remaining_balance'] ?? 0))));
    $advancePaid = (int)($payment['amount_paid'] ?? ($advancePayment['selected_amount'] ?? $grandTotal));
    $remainingBalance = (int)($payment['remaining_balance'] ?? ($grandTotal - $advancePaid));
    if ($remainingBalance < 0) {
        $remainingBalance = 0;
    }

    // Standard items list
    $standardGarments = [];
    if ($hasSavedStd) {
        $standardGarments = $raw['standard_items'];
    } elseif ($isStandardPeople && !empty($raw['people'][0]['garments'])) {
        $standardGarments = $raw['people'][0]['garments'];
    }

    // Luxe people list
    $luxePeople = [];
    if ($hasPeople && !$isStandardPeople) {
        $luxePeople = $raw['people'];
    }

    // Physical garment counts
    $stdGarmentCount = count($standardGarments);
    $luxeGarmentCount = 0;
    foreach ($luxePeople as $p) {
        if (!empty($p['garments']) && is_array($p['garments'])) {
            $luxeGarmentCount += count($p['garments']);
        }
    }
    $totalPhysicalGarments = $stdGarmentCount + $luxeGarmentCount;
    if ($totalPhysicalGarments === 0) {
        $totalPhysicalGarments = (int)($raw['garment_count'] ?? 1);
    }

    // Status Category Mapping from includes/order-status.php
    $rawStatus = strtolower(trim((string)($raw['status'] ?? ($raw['production_status'] ?? 'pending_confirmation'))));
    if ($rawStatus === '') {
        $rawStatus = 'pending_confirmation';
    }

    $statusCategory = function_exists('get_status_category') ? get_status_category($rawStatus) : 'awaiting_confirmation';
    $statusLabel = function_exists('get_status_display_label') ? get_status_display_label($rawStatus) : ucwords(str_replace('_', ' ', $rawStatus));
    $statusBadgeClass = function_exists('get_status_badge_class') ? get_status_badge_class($rawStatus) : 'status-production';

    $completedTimestamp = 0;
    if ($statusCategory === 'completed') {
        $completedTimestamp = (int)($raw['completed_at'] ?? ($payment['paid_at'] ?? $bookedTimestamp));
    }

    return [
        'order_ref' => $orderRef,
        'order_type_label' => $orderTypeLabel,
        'order_type_badge' => $orderTypeBadge,
        'is_combined' => $isCombined,
        'is_standard' => $isStandardOnly,
        'is_luxe' => $isLuxeOnly,
        'booked_date_raw' => $bookedDateRaw,
        'booked_timestamp' => $bookedTimestamp,
        'booked_formatted' => $bookedFormatted,
        'requested_date_raw' => $requestedDateRaw,
        'requested_formatted' => $requestedFormatted,
        'estimated_date_raw' => $estimatedDateRaw,
        'estimated_formatted' => $estimatedFormatted,
        'admin_updated' => $adminUpdated,
        'admin_note' => $adminNote,
        'date_history' => $dateHistory,
        'grand_total' => $grandTotal,
        'amount_paid' => $advancePaid,
        'remaining_balance' => $remainingBalance,
        'standard_garments' => $standardGarments,
        'luxe_people' => $luxePeople,
        'total_physical_garments' => $totalPhysicalGarments,
        'raw_status' => $rawStatus,
        'status_category' => $statusCategory,
        'status_label' => $statusLabel,
        'status_badge_class' => $statusBadgeClass,
        'completed_timestamp' => $completedTimestamp,
        'raw' => $raw
    ];
}

// -------------------------------------------------------------
// BUCKET ORDERS INTO 3 REQUIRED CATEGORIES
// -------------------------------------------------------------
$categories = [
    'awaiting_confirmation' => [
        'key' => 'awaiting_confirmation',
        'heading' => 'Awaiting Confirmation',
        'description' => 'Orders received and awaiting confirmation or initial processing.',
        'empty_text' => 'No orders currently awaiting confirmation.',
        'orders' => []
    ],
    'in_production' => [
        'key' => 'in_production',
        'heading' => 'In Production',
        'description' => 'Orders currently being prepared, stitched, embroidered, or checked.',
        'empty_text' => 'No orders currently in production.',
        'orders' => []
    ],
    'completed' => [
        'key' => 'completed',
        'heading' => 'Completed Orders',
        'description' => 'Completed orders ready for collection, delivery, or customer reference.',
        'empty_text' => 'No completed orders yet.',
        'orders' => []
    ]
];

$allNormalizedOrders = [];

foreach ($customerOrders as $rawOrder) {
    if (!is_array($rawOrder)) {
        continue;
    }
    $norm = normalize_customer_order($rawOrder);
    $allNormalizedOrders[$norm['order_ref']] = $norm;
    if (isset($categories[$norm['status_category']])) {
        $categories[$norm['status_category']]['orders'][] = $norm;
    } else {
        $categories['in_production']['orders'][] = $norm;
    }
}

// CRITICAL REQUIREMENT: Completed Orders must be sorted NEWEST COMPLETED FIRST
usort($categories['completed']['orders'], function (array $a, array $b): int {
    return ($b['completed_timestamp'] <=> $a['completed_timestamp']);
});

$totalOrdersCount = count($allNormalizedOrders);
$awaitingCount = count($categories['awaiting_confirmation']['orders']);
$inProductionCount = count($categories['in_production']['orders']);
$completedCount = count($categories['completed']['orders']);

include __DIR__ . '/includes/header.php';
?>

<main class="customer-orders-page">
    <div class="customer-orders-container">

        <!-- PAGE HEADER -->
        <header class="customer-orders-header">
            <div class="orders-badge-row">
                <span class="luxe-dossier-tag">MY ACCOUNT</span>
            </div>
            <h1 class="customer-orders-title">My Orders</h1>
            <p class="customer-orders-subtitle">
                Manage your bespoke tailoring orders, track production timelines, and download official Order Dossiers.
            </p>
        </header>

        <!-- TOP SUMMARY METRICS BAR -->
        <section class="orders-summary-bar" aria-label="Orders Status Summary">
            <div class="orders-metric-card">
                <span class="orders-metric-num"><?php echo $totalOrdersCount; ?></span>
                <span class="orders-metric-label">Total Orders</span>
            </div>
            <a href="#cat-awaiting_confirmation" class="orders-metric-card is-awaiting" style="text-decoration: none;">
                <span class="orders-metric-num"><?php echo $awaitingCount; ?></span>
                <span class="orders-metric-label">Awaiting Confirmation</span>
            </a>
            <a href="#cat-in_production" class="orders-metric-card is-production" style="text-decoration: none;">
                <span class="orders-metric-num"><?php echo $inProductionCount; ?></span>
                <span class="orders-metric-label">In Production</span>
            </a>
            <a href="#cat-completed" class="orders-metric-card is-completed" style="text-decoration: none;">
                <span class="orders-metric-num"><?php echo $completedCount; ?></span>
                <span class="orders-metric-label">Completed</span>
            </a>
        </section>

        <?php if ($totalOrdersCount === 0): ?>
            <!-- ZERO ORDERS OVERALL EMPTY STATE -->
            <section class="customer-orders-empty-state">
                <div class="empty-icon" aria-hidden="true">🛍️</div>
                <h2>No orders yet.</h2>
                <p>
                    You haven't placed any bespoke tailoring orders with Shagun yet. Once you complete an order via Standard Stitching or Shagun Luxe, your official Order Dossier and tailoring timeline will appear here.
                </p>
                <div class="empty-actions">
                    <a href="standard-stitching.php" class="luxe-btn-home">
                        Explore Standard Stitching
                    </a>
                    <a href="luxe-stitching.php" class="luxe-btn-download-pdf">
                        Start Luxe Wedding
                    </a>
                </div>
            </section>
        <?php else: ?>

            <!-- CATEGORY SECTIONS -->
            <?php foreach ($categories as $catKey => $cat): ?>
                <section class="order-category-section" id="cat-<?php echo htmlspecialchars($catKey); ?>">
                    <div class="order-category-header">
                        <div class="cat-title-wrap">
                            <h2 class="order-category-title"><?php echo htmlspecialchars($cat['heading']); ?></h2>
                            <span class="order-category-count-badge"><?php echo count($cat['orders']); ?></span>
                        </div>
                        <p class="order-category-desc"><?php echo htmlspecialchars($cat['description']); ?></p>
                    </div>

                    <?php if (empty($cat['orders'])): ?>
                        <div class="order-category-empty">
                            <p><?php echo htmlspecialchars($cat['empty_text']); ?></p>
                        </div>
                    <?php else: ?>
                        <div class="order-cards-list">
                            <?php foreach ($cat['orders'] as $order): ?>
                                <?php
                                $isOpen = ($requestedRef !== '' && $order['order_ref'] === $requestedRef) || ($totalOrdersCount === 1);
                                ?>
                                <details class="customer-order-card" id="order-<?php echo htmlspecialchars($order['order_ref']); ?>" <?php echo $isOpen ? 'open' : ''; ?>>
                                    <summary class="order-card-summary">
                                        <div class="order-summary-top">
                                            <div class="order-ref-date">
                                                <strong class="order-ref-text"><?php echo htmlspecialchars($order['order_ref']); ?></strong>
                                                <span class="order-date-text">Booked <?php echo htmlspecialchars($order['booked_formatted']); ?></span>
                                            </div>
                                            <div class="order-summary-badges">
                                                <span class="order-type-badge"><?php echo htmlspecialchars($order['order_type_badge']); ?></span>
                                                <span class="order-status-badge <?php echo htmlspecialchars($order['status_badge_class']); ?>">
                                                    <?php echo htmlspecialchars($order['status_label']); ?>
                                                </span>
                                            </div>
                                        </div>

                                        <div class="order-summary-metrics">
                                            <div class="summary-metric-item">
                                                <span class="metric-label">Garments</span>
                                                <strong class="metric-val"><?php echo $order['total_physical_garments']; ?> <?php echo $order['total_physical_garments'] === 1 ? 'Garment' : 'Garments'; ?></strong>
                                            </div>
                                            <div class="summary-metric-item">
                                                <span class="metric-label">Total Amount</span>
                                                <strong class="metric-val">₹<?php echo number_format($order['grand_total']); ?></strong>
                                            </div>
                                            <div class="summary-metric-item">
                                                <span class="metric-label">Advance Paid</span>
                                                <strong class="metric-val text-success">₹<?php echo number_format($order['amount_paid']); ?></strong>
                                            </div>
                                            <div class="summary-metric-item">
                                                <span class="metric-label">Remaining Balance</span>
                                                <strong class="metric-val <?php echo $order['remaining_balance'] > 0 ? 'text-balance' : 'text-muted'; ?>">
                                                    <?php echo $order['remaining_balance'] > 0 ? ('₹' . number_format($order['remaining_balance'])) : '₹0 (Paid)'; ?>
                                                </strong>
                                            </div>
                                            <div class="order-accordion-indicator" aria-hidden="true">
                                                <span class="indicator-show">View Order Details ↓</span>
                                                <span class="indicator-hide">Hide Order Details ↑</span>
                                            </div>
                                        </div>
                                    </summary>

                                    <!-- EXPANDED DETAILED BREAKDOWN -->
                                    <div class="order-card-details">

                                        <!-- SECTION 1: TIMELINE & DATES -->
                                        <div class="order-detail-section">
                                            <h3 class="detail-section-title">
                                                <span class="section-icon" aria-hidden="true">📅</span>
                                                <span>Timeline & Critical Dates</span>
                                            </h3>
                                            <div class="luxe-date-grid">
                                                <div class="luxe-date-card">
                                                    <span class="luxe-date-label">Booked Date</span>
                                                    <strong class="luxe-date-val"><?php echo htmlspecialchars($order['booked_formatted']); ?></strong>
                                                    <small class="luxe-date-note">Confirmed server-side upon booking</small>
                                                </div>
                                                <div class="luxe-date-card">
                                                    <span class="luxe-date-label">Requested Ready Date</span>
                                                    <strong class="luxe-date-val"><?php echo htmlspecialchars($order['requested_formatted']); ?></strong>
                                                    <small class="luxe-date-note">Customer's requested delivery date</small>
                                                </div>
                                                <div class="luxe-date-card luxe-highlight-delivery">
                                                    <span class="luxe-date-label">Estimated Delivery Date</span>
                                                    <strong class="luxe-date-val"><?php echo htmlspecialchars($order['estimated_formatted']); ?></strong>
                                                    <small class="luxe-date-note">
                                                        <?php if ($order['admin_updated'] && $order['estimated_date_raw'] !== $order['requested_date_raw']): ?>
                                                            Updated by Shagun Ladies Tailor
                                                        <?php else: ?>
                                                            Current committed date
                                                        <?php endif; ?>
                                                    </small>
                                                </div>
                                            </div>

                                            <?php if ($order['admin_updated'] && $order['estimated_date_raw'] !== $order['requested_date_raw']): ?>
                                                <div class="luxe-date-update-notice" style="margin-top: 14px;">
                                                    <span class="notice-icon" aria-hidden="true">ⓘ</span>
                                                    <div class="notice-body">
                                                        <strong>Estimated delivery date updated by Shagun Ladies Tailor.</strong>
                                                        <?php if (!empty($order['admin_note'])): ?>
                                                            <p><?php echo htmlspecialchars($order['admin_note']); ?></p>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            <?php endif; ?>

                                            <?php if (!empty($order['date_history']) && is_array($order['date_history'])): ?>
                                                <div class="luxe-date-history-wrap" style="margin-top: 18px;">
                                                    <h4 class="luxe-history-title">Date History</h4>
                                                    <ul class="luxe-history-list">
                                                        <?php foreach ($order['date_history'] as $entry): ?>
                                                            <?php
                                                            $entryDate = isset($entry['created_at']) ? date('d M Y', (int)$entry['created_at']) : date('d M Y');
                                                            $actorLabel = ($entry['actor'] ?? '') === 'admin' ? 'Admin updated estimated delivery date:' : 'Customer requested:';
                                                            $targetDateStr = !empty($entry['date']) ? date('j F Y', strtotime($entry['date'])) : 'Not specified';
                                                            ?>
                                                            <li class="luxe-history-item">
                                                                <span class="history-bullet" aria-hidden="true">●</span>
                                                                <div class="history-content">
                                                                    <span class="history-date"><?php echo htmlspecialchars($entryDate); ?></span>
                                                                    <span class="history-desc">
                                                                        <strong><?php echo htmlspecialchars($actorLabel); ?></strong>
                                                                        <?php echo htmlspecialchars($targetDateStr); ?>
                                                                    </span>
                                                                    <?php if (!empty($entry['note'])): ?>
                                                                        <small class="history-note"><?php echo htmlspecialchars($entry['note']); ?></small>
                                                                    <?php endif; ?>
                                                                </div>
                                                            </li>
                                                        <?php endforeach; ?>
                                                    </ul>
                                                </div>
                                            <?php endif; ?>
                                        </div>

                                        <!-- SECTION 2: GARMENTS BREAKDOWN -->
                                        <div class="order-detail-section">
                                            <h3 class="detail-section-title">
                                                <span class="section-icon" aria-hidden="true">✂️</span>
                                                <span>Garments & Work Specifications</span>
                                            </h3>

                                            <!-- Standard Items (if any) -->
                                            <?php if (!empty($order['standard_garments'])): ?>
                                                <div class="order-garments-subgroup">
                                                    <h4 class="subgroup-heading">
                                                        <span class="subgroup-tag">STANDARD STITCHING</span>
                                                        <span>(<?php echo count($order['standard_garments']); ?> <?php echo count($order['standard_garments']) === 1 ? 'Garment' : 'Garments'; ?>)</span>
                                                    </h4>
                                                    <div class="standard-garments-grid">
                                                        <?php foreach ($order['standard_garments'] as $sIdx => $sItem): ?>
                                                            <?php
                                                            $sTitle = $sItem['garment'] ?? ($sItem['title'] ?? ('Garment #' . ($sIdx + 1)));
                                                            $sStyle = $sItem['style_name'] ?? ($sItem['style_slug'] ?? 'Custom Tailored');
                                                            $sPrice = (int)($sItem['total'] ?? ($sItem['price'] ?? 0));
                                                            $sChoices = $sItem['choices'] ?? ($sItem['summary'] ?? []);
                                                            ?>
                                                            <div class="order-garment-item-card">
                                                                <div class="garment-card-top">
                                                                    <strong><?php echo htmlspecialchars($sTitle); ?> #<?php echo ($sIdx + 1); ?></strong>
                                                                    <span class="garment-price">₹<?php echo number_format($sPrice); ?></span>
                                                                </div>
                                                                <span class="garment-style-name"><?php echo htmlspecialchars($sStyle); ?></span>

                                                                <?php if (!empty($sChoices) && is_array($sChoices)): ?>
                                                                    <ul class="garment-choices-list">
                                                                        <?php foreach ($sChoices as $cKey => $cVal): ?>
                                                                            <?php
                                                                            $lbl = is_array($cVal) ? ($cVal['label'] ?? ($cVal['field'] ?? 'Option')) : (is_string($cKey) ? ucfirst($cKey) : 'Choice');
                                                                            $val = is_array($cVal) ? ($cVal['value'] ?? ($cVal['label'] ?? '')) : (string)$cVal;
                                                                            if ($val === '') continue;
                                                                            ?>
                                                                            <li><strong><?php echo htmlspecialchars($lbl); ?>:</strong> <?php echo htmlspecialchars($val); ?></li>
                                                                        <?php endforeach; ?>
                                                                    </ul>
                                                                <?php endif; ?>
                                                            </div>
                                                        <?php endforeach; ?>
                                                    </div>
                                                </div>
                                            <?php endif; ?>

                                            <!-- Luxe Items (if any) -->
                                            <?php if (!empty($order['luxe_people'])): ?>
                                                <div class="order-garments-subgroup">
                                                    <h4 class="subgroup-heading">
                                                        <span class="subgroup-tag">SHAGUN LUXE</span>
                                                        <span>(Family & Wedding Garments)</span>
                                                    </h4>
                                                    <div class="luxe-dossier-people-list">
                                                        <?php foreach ($order['luxe_people'] as $pIdx => $person): ?>
                                                            <div class="luxe-dossier-person">
                                                                <div class="person-header">
                                                                    <strong><?php echo htmlspecialchars($person['name'] ?? ('Person ' . ($pIdx + 1))); ?></strong>
                                                                    <span class="role-badge"><?php echo htmlspecialchars($person['role'] ?? 'Outfit'); ?></span>
                                                                    <span class="method-tag">
                                                                        <?php echo ($person['measurement_method'] ?? '') === 'visit_shop' ? 'Visit Shop' : 'Reference Blouse'; ?>
                                                                    </span>
                                                                </div>
                                                                <?php if (!empty($person['garments']) && is_array($person['garments'])): ?>
                                                                    <ul class="dossier-garments">
                                                                        <?php foreach ($person['garments'] as $gIdx => $g): ?>
                                                                            <?php
                                                                            $gName = (string)($g['name'] ?? 'Blouse');
                                                                            $gStyle = (string)($g['style_name'] ?? ($g['style_slug'] ?? 'Custom Style'));
                                                                            $gPrice = (int)($g['total_price'] ?? ($g['price'] ?? 0));
                                                                            $mw = $g['machine_work'] ?? null;
                                                                            $hw = $g['hand_work'] ?? null;
                                                                            $mwTarget = $g['machine_work_target'] ?? ($mw['work_target'] ?? '');
                                                                            $hwTarget = $g['hand_work_target'] ?? ($hw['work_target'] ?? '');
                                                                            ?>
                                                                            <li>
                                                                                <div class="garment-main-info">
                                                                                    <span class="garment-name-styled"><?php echo htmlspecialchars($gName); ?> #<?php echo ($gIdx + 1); ?> (<?php echo htmlspecialchars($gStyle); ?>)</span>
                                                                                    <div class="garment-work-tags">
                                                                                        <?php if (!empty($mw)): ?>
                                                                                            <span class="badge-mini badge-machine">Machine Work <?php echo $mwTarget === 'separate_cloth' ? '• Separate Cloth' : '• On Blouse'; ?></span>
                                                                                        <?php endif; ?>
                                                                                        <?php if (!empty($hw)): ?>
                                                                                            <span class="badge-mini badge-hand">Hand Work <?php echo $hwTarget === 'separate_cloth' ? '• Separate Cloth' : '• On Blouse'; ?></span>
                                                                                        <?php endif; ?>
                                                                                    </div>
                                                                                </div>
                                                                                <strong>₹<?php echo number_format($gPrice); ?></strong>
                                                                            </li>
                                                                        <?php endforeach; ?>
                                                                    </ul>
                                                                <?php endif; ?>
                                                            </div>
                                                        <?php endforeach; ?>
                                                    </div>
                                                </div>
                                            <?php endif; ?>

                                        </div>

                                        <!-- SECTION 3: FINANCIAL RECORD -->
                                        <div class="order-detail-section">
                                            <h3 class="detail-section-title">
                                                <span class="section-icon" aria-hidden="true">💳</span>
                                                <span>Financial Record</span>
                                            </h3>
                                            <div class="luxe-dossier-financials">
                                                <div class="fin-row">
                                                    <span>Total Order Amount</span>
                                                    <strong>₹<?php echo number_format($order['grand_total']); ?></strong>
                                                </div>
                                                <div class="fin-row is-paid">
                                                    <span>Advance Paid (Confirmed)</span>
                                                    <strong>₹<?php echo number_format($order['amount_paid']); ?></strong>
                                                </div>
                                                <div class="fin-row">
                                                    <span>Remaining Balance</span>
                                                    <strong class="<?php echo $order['remaining_balance'] > 0 ? 'text-balance' : ''; ?>">
                                                        ₹<?php echo number_format($order['remaining_balance']); ?>
                                                    </strong>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- SECTION 4: ACTIONS & DOWNLOAD DOSSIER -->
                                        <div class="order-card-action-bar">
                                            <div class="action-left">
                                                <a href="orders.php?action=download_dossier&ref=<?php echo urlencode($order['order_ref']); ?>" class="luxe-btn-download-pdf" id="download-dossier-pdf-btn">
                                                    <span class="btn-icon" aria-hidden="true">⬇</span>
                                                    <span>Download SHAGUN — Order Dossier (PDF)</span>
                                                </a>
                                                <button type="button" class="luxe-btn-print" onclick="window.print();">
                                                    🖨️ Print Record
                                                </button>
                                            </div>
                                        </div>

                                        <?php if (is_admin_logged_in()): ?>
                                        <!-- ADMIN SIMULATION TOOLBOX (PER ORDER) - STRICTLY RESTRICTED TO ADMIN SESSIONS -->
                                        <div class="luxe-admin-test-panel">
                                            <div class="admin-panel-title">
                                                <span>🛠️ Admin Date Management Simulation (Staff Console)</span>
                                            </div>
                                            <p class="admin-panel-desc">
                                                Simulate Shagun Tailor updating the committed delivery date for <strong><?php echo htmlspecialchars($order['order_ref']); ?></strong>. The customer's requested date is strictly preserved, and the update is logged to the Date History.
                                            </p>
                                            <form method="POST" action="orders.php" class="admin-date-form">
                                                <input type="hidden" name="action" value="admin_update_date">
                                                <input type="hidden" name="order_ref" value="<?php echo htmlspecialchars($order['order_ref']); ?>">
                                                <div class="admin-form-fields">
                                                    <div class="field">
                                                        <label for="admin-del-date-<?php echo htmlspecialchars($order['order_ref']); ?>">New Estimated Delivery Date:</label>
                                                        <input type="date" id="admin-del-date-<?php echo htmlspecialchars($order['order_ref']); ?>" name="admin_delivery_date" value="2026-10-30" required>
                                                    </div>
                                                    <div class="field">
                                                        <label for="admin-del-note-<?php echo htmlspecialchars($order['order_ref']); ?>">Admin Note / Reason:</label>
                                                        <input type="text" id="admin-del-note-<?php echo htmlspecialchars($order['order_ref']); ?>" name="admin_note" value="Customer contacted regarding fitting timing. New delivery date agreed: 30 October 2026.">
                                                    </div>
                                                    <button type="submit" class="admin-submit-btn">Update Estimated Date</button>
                                                </div>
                                            </form>
                                        </div>
                                        <?php endif; ?>

                                    </div>
                                </details>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </section>
            <?php endforeach; ?>

        <?php endif; ?>

        <!-- BACK LINK -->
        <div class="orders-bottom-nav">
            <a href="index.php" class="luxe-btn-home">
                ← Return to Home
            </a>
        </div>

    </div>
</main>

<?php include __DIR__ . '/includes/footer.php'; ?>
