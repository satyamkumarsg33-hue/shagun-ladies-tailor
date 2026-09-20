<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/order-status.php';

// Server-side guard: enforce authenticated admin session
require_admin_login();

$admin = get_logged_in_admin();
$adminId = (int) ($admin['id'] ?? 0);
$adminRole = (string) ($admin['role'] ?? 'super_admin');
$adminRoleLabel = get_admin_role_label($adminRole);
$fullName = htmlspecialchars($admin['full_name'] ?? 'Admin', ENT_QUOTES, 'UTF-8');
$username = htmlspecialchars($admin['username'] ?? '', ENT_QUOTES, 'UTF-8');
$email = htmlspecialchars($admin['email'] ?? '', ENT_QUOTES, 'UTF-8');

$allowedStatuses = get_allowed_statuses_for_role($adminRole);

$flashSuccess = null;
$flashError = null;

// -------------------------------------------------------------
// POST ACTIONS: STATUS & DATE UPDATES
// -------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = trim((string) ($_POST['action'] ?? ''));

    if ($action === 'admin_update_status') {
        $orderRef = trim((string) ($_POST['order_ref'] ?? ''));
        $newStatus = trim((string) ($_POST['new_status'] ?? ''));
        $notes = trim((string) ($_POST['notes'] ?? ''));

        $res = admin_update_order_status($orderRef, $newStatus, $notes, $adminId, $adminRole);
        if ($res['success']) {
            $flashSuccess = "Order {$orderRef} status updated to '" . htmlspecialchars($res['status_label']) . "'.";
        } else {
            $flashError = $res['error'] ?? 'Could not update status.';
        }
    } elseif ($action === 'admin_update_delivery_date') {
        $orderRef = trim((string) ($_POST['order_ref'] ?? ''));
        $newDate = trim((string) ($_POST['admin_delivery_date'] ?? ''));
        $note = trim((string) ($_POST['admin_note'] ?? ''));

        if (!empty($orderRef) && !empty($newDate)) {
            $dateUpdated = false;

            // 1. Update permanent database
            try {
                $pdo = get_db_connection();
                $ordStmt = $pdo->prepare('SELECT id, admin_delivery_date FROM orders WHERE order_ref = :ref LIMIT 1');
                $ordStmt->execute([':ref' => $orderRef]);
                $dbOrd = $ordStmt->fetch();
                if ($dbOrd) {
                    $orderId = (int)$dbOrd['id'];
                    $oldDate = $dbOrd['admin_delivery_date'];
                    $upd = $pdo->prepare('UPDATE orders SET admin_delivery_date = :dt, updated_at = NOW() WHERE id = :id');
                    $upd->execute([':dt' => $newDate, ':id' => $orderId]);

                    $insDate = $pdo->prepare('
                        INSERT INTO order_date_history (
                            order_id, event_type, date_type, old_date, new_date, actor, admin_user_id, note, created_at
                        ) VALUES (
                            :oid, \'admin_update\', \'admin_delivery_date\', :old_dt, :new_dt, \'admin\', :admin_id, :note, NOW()
                        )
                    ');
                    $insDate->execute([
                        ':oid' => $orderId,
                        ':old_dt' => $oldDate,
                        ':new_dt' => $newDate,
                        ':admin_id' => $adminId,
                        ':note' => $note ?: "Admin updated delivery date to {$newDate}"
                    ]);
                    $dateUpdated = true;
                }
            } catch (Throwable $e) {
                error_log('[Admin Delivery Date DB Error] ' . $e->getMessage());
            }

            // 2. Search and update in $_SESSION['customer_orders']
            if (!empty($_SESSION['customer_orders']) && is_array($_SESSION['customer_orders'])) {
                foreach ($_SESSION['customer_orders'] as $uId => $orders) {
                    if (isset($orders[$orderRef])) {
                        $_SESSION['customer_orders'][$uId][$orderRef]['admin_delivery_date'] = $newDate;
                        $_SESSION['customer_orders'][$uId][$orderRef]['admin_note'] = $note;
                        $_SESSION['customer_orders'][$uId][$orderRef]['admin_updated_by'] = 'admin';
                        $_SESSION['customer_orders'][$uId][$orderRef]['admin_updated_at'] = time();
                        if (!isset($_SESSION['customer_orders'][$uId][$orderRef]['date_history'])) {
                            $_SESSION['customer_orders'][$uId][$orderRef]['date_history'] = [];
                        }
                        $_SESSION['customer_orders'][$uId][$orderRef]['date_history'][] = [
                            'type' => 'admin_update',
                            'date' => $newDate,
                            'created_at' => time(),
                            'formatted_created' => date('d M Y, h:i A'),
                            'actor' => 'admin',
                            'note' => $note ?: "Admin updated delivery date to $newDate"
                        ];
                        $dateUpdated = true;
                        break;
                    }
                }
            }

            if (!empty($_SESSION['standard_order']) && (($_SESSION['standard_order']['order_ref'] ?? '') === $orderRef || ($_SESSION['standard_order']['payment']['order_ref'] ?? '') === $orderRef)) {
                $_SESSION['standard_order']['admin_delivery_date'] = $newDate;
                $_SESSION['standard_order']['admin_note'] = $note;
                $dateUpdated = true;
            }
            if (!empty($_SESSION['luxe_wedding']) && (($_SESSION['luxe_wedding']['order_ref'] ?? '') === $orderRef || ($_SESSION['luxe_wedding']['payment']['order_ref'] ?? '') === $orderRef)) {
                $_SESSION['luxe_wedding']['admin_delivery_date'] = $newDate;
                $_SESSION['luxe_wedding']['admin_note'] = $note;
                $dateUpdated = true;
            }

            if ($dateUpdated) {
                $flashSuccess = "Committed delivery date for {$orderRef} updated to " . date('j F Y', strtotime($newDate)) . ".";
            } else {
                $flashError = "Order {$orderRef} not found.";
            }
        }
    }
}

// -------------------------------------------------------------
// GET ACTION: ADMIN DOWNLOAD DOSSIER PDF
// -------------------------------------------------------------
if (isset($_GET['action']) && $_GET['action'] === 'download_dossier') {
    require_admin_login();

    $targetRef = trim((string) ($_GET['ref'] ?? ''));
    if ($targetRef !== '') {
        $orderToDownload = null;

        // 1. Query permanent database first with full child records
        try {
            $pdo = get_db_connection();
            $stmt = $pdo->prepare('SELECT user_id FROM orders WHERE order_ref = :ref LIMIT 1');
            $stmt->execute([':ref' => $targetRef]);
            $dbOrd = $stmt->fetch();
            if ($dbOrd) {
                $dbUserId = (int) $dbOrd['user_id'];
                $orderToDownload = get_customer_order_by_ref($targetRef, $dbUserId);
            }
        } catch (Throwable $e) {
            error_log('[Admin Dossier Lookup Error] ' . $e->getMessage());
        }

        // 2. Check session storage if not found in database
        if ($orderToDownload === null) {
            if (!empty($_SESSION['customer_orders']) && is_array($_SESSION['customer_orders'])) {
                foreach ($_SESSION['customer_orders'] as $uId => $orders) {
                    if (isset($orders[$targetRef])) {
                        $orderToDownload = $orders[$targetRef];
                        break;
                    }
                }
            }
            if ($orderToDownload === null && !empty($_SESSION['standard_order']) && (($_SESSION['standard_order']['order_ref'] ?? '') === $targetRef || ($_SESSION['standard_order']['payment']['order_ref'] ?? '') === $targetRef)) {
                $orderToDownload = $_SESSION['standard_order'];
            }
            if ($orderToDownload === null && !empty($_SESSION['luxe_wedding']) && (($_SESSION['luxe_wedding']['order_ref'] ?? '') === $targetRef || ($_SESSION['luxe_wedding']['payment']['order_ref'] ?? '') === $targetRef)) {
                $orderToDownload = $_SESSION['luxe_wedding'];
            }
        }

        if ($orderToDownload !== null) {
            $uId = (int) ($orderToDownload['user_id'] ?? 0);
            if ($uId > 0) {
                $custProfile = get_customer_profile($uId);
                if ($custProfile) {
                    $orderToDownload['customer_name'] = $custProfile['name'];
                    $orderToDownload['customer_phone'] = $custProfile['phone'] ?? '';
                    $orderToDownload['customer_email'] = $custProfile['email'] ?? '';
                    $orderToDownload['customer_address'] = $custProfile['address'] ?? '';
                }
            }
            require_once __DIR__ . '/../includes/dossier-pdf.php';
            ShagunDossierPdf::download($orderToDownload);
            exit;
        } else {
            $flashError = "Order '{$targetRef}' not found for dossier download.";
        }
    } else {
        $flashError = "Invalid order reference.";
    }
}

// -------------------------------------------------------------
// LOAD ORDERS FOR WORKSHOP QUEUE (DATABASE FIRST)
// -------------------------------------------------------------
$allOrders = [];

// 1. Permanent database orders
try {
    $pdo = get_db_connection();
    $dbOrdersStmt = $pdo->query("SELECT * FROM orders ORDER BY created_at DESC");
    while ($dbOrd = $dbOrdersStmt->fetch()) {
        $ref = (string) $dbOrd['order_ref'];
        $orderId = (int) $dbOrd['id'];
        $dbUserId = (int) $dbOrd['user_id'];
        $custProfile = get_customer_profile($dbUserId);

        // Fetch status history for audit trail
        $shStmt = $pdo->prepare('SELECT * FROM order_status_history WHERE order_id = :oid ORDER BY id ASC');
        $shStmt->execute([':oid' => $orderId]);
        $statusHistory = [];
        while ($sh = $shStmt->fetch()) {
            $statusHistory[] = [
                'old_status' => $sh['old_status'],
                'new_status' => $sh['new_status'],
                'actor' => $sh['actor'],
                'notes' => $sh['notes'],
                'created_at' => strtotime($sh['created_at']),
                'formatted_created' => date('d M Y, h:i A', strtotime($sh['created_at']))
            ];
        }

        $allOrders[$ref] = [
            'id' => $orderId,
            'order_ref' => $ref,
            'user_id' => $dbUserId,
            'customer_name' => $custProfile['name'] ?? null,
            'customer_phone' => $custProfile['phone'] ?? null,
            'customer_email' => $custProfile['email'] ?? null,
            'customer_address' => $custProfile['address'] ?? null,
            'phone_display' => $custProfile['phone_display'] ?? 'Phone number not provided',
            'address_display' => $custProfile['address_display'] ?? 'Address not provided',
            'workflow' => $dbOrd['workflow_type'],
            'order_type' => $dbOrd['workflow_type'] === 'standard' ? 'Standard Stitching' : 'Luxe Stitching',
            'occasion' => $dbOrd['occasion'],
            'status' => $dbOrd['status'],
            'production_status' => $dbOrd['status'],
            'grand_total' => (int) $dbOrd['total_amount'],
            'amount_paid' => (int) $dbOrd['advance_amount'],
            'remaining_balance' => (int) $dbOrd['balance_amount'],
            'booked_date' => $dbOrd['booked_date'],
            'requested_ready_date' => $dbOrd['requested_ready_date'],
            'admin_delivery_date' => $dbOrd['admin_delivery_date'],
            'customer_notes' => $dbOrd['customer_notes'],
            'status_history' => $statusHistory,
            'source' => 'database'
        ];
    }
} catch (\Throwable $e) {
    error_log('[Admin Orders DB Error] ' . $e->getMessage());
}

// 2. Session store orders (merge if not already present, or enrich existing with session garment details)
if (!empty($_SESSION['customer_orders']) && is_array($_SESSION['customer_orders'])) {
    foreach ($_SESSION['customer_orders'] as $uId => $orders) {
        if (is_array($orders)) {
            foreach ($orders as $ref => $ord) {
                if (!isset($allOrders[$ref])) {
                    $custUserId = (int) ($ord['user_id'] ?? $uId);
                    $custProfile = $custUserId > 0 ? get_customer_profile($custUserId) : null;
                    $ord['customer_name'] = $custProfile['name'] ?? ($ord['customer_name'] ?? null);
                    $ord['customer_phone'] = $custProfile['phone'] ?? null;
                    $ord['customer_email'] = $custProfile['email'] ?? ($ord['customer_email'] ?? null);
                    $ord['customer_address'] = $custProfile['address'] ?? null;
                    $ord['phone_display'] = $custProfile['phone_display'] ?? 'Phone number not provided';
                    $ord['address_display'] = $custProfile['address_display'] ?? 'Address not provided';
                    $allOrders[$ref] = $ord;
                } else {
                    if (!empty($ord['people'])) $allOrders[$ref]['people'] = $ord['people'];
                    if (!empty($ord['standard_items'])) $allOrders[$ref]['standard_items'] = $ord['standard_items'];
                    if (!empty($ord['status_history'])) $allOrders[$ref]['status_history'] = $ord['status_history'];
                    if (!empty($ord['date_history'])) $allOrders[$ref]['date_history'] = $ord['date_history'];
                }
            }
        }
    }
}

if (!empty($_SESSION['standard_order']['payment']['status']) && $_SESSION['standard_order']['payment']['status'] === 'completed') {
    $ref = $_SESSION['standard_order']['order_ref'] ?? ($_SESSION['standard_order']['payment']['order_ref'] ?? '');
    if ($ref !== '' && !isset($allOrders[$ref])) {
        $custUserId = (int) ($_SESSION['standard_order']['user_id'] ?? 0);
        $custProfile = $custUserId > 0 ? get_customer_profile($custUserId) : null;
        $_SESSION['standard_order']['customer_name'] = $custProfile['name'] ?? null;
        $_SESSION['standard_order']['customer_phone'] = $custProfile['phone'] ?? null;
        $_SESSION['standard_order']['customer_email'] = $custProfile['email'] ?? null;
        $_SESSION['standard_order']['customer_address'] = $custProfile['address'] ?? null;
        $_SESSION['standard_order']['phone_display'] = $custProfile['phone_display'] ?? 'Phone number not provided';
        $_SESSION['standard_order']['address_display'] = $custProfile['address_display'] ?? 'Address not provided';
        $allOrders[$ref] = $_SESSION['standard_order'];
    }
}

if (!empty($_SESSION['luxe_wedding']['payment']['status']) && $_SESSION['luxe_wedding']['payment']['status'] === 'completed') {
    $ref = $_SESSION['luxe_wedding']['order_ref'] ?? ($_SESSION['luxe_wedding']['payment']['order_ref'] ?? '');
    if ($ref !== '' && !isset($allOrders[$ref])) {
        $custUserId = (int) ($_SESSION['luxe_wedding']['user_id'] ?? 0);
        $custProfile = $custUserId > 0 ? get_customer_profile($custUserId) : null;
        $_SESSION['luxe_wedding']['customer_name'] = $custProfile['name'] ?? null;
        $_SESSION['luxe_wedding']['customer_phone'] = $custProfile['phone'] ?? null;
        $_SESSION['luxe_wedding']['customer_email'] = $custProfile['email'] ?? null;
        $_SESSION['luxe_wedding']['customer_address'] = $custProfile['address'] ?? null;
        $_SESSION['luxe_wedding']['phone_display'] = $custProfile['phone_display'] ?? 'Phone number not provided';
        $_SESSION['luxe_wedding']['address_display'] = $custProfile['address_display'] ?? 'Address not provided';
        $allOrders[$ref] = $_SESSION['luxe_wedding'];
    }
}

// Group into 3 categories
$adminCategories = [
    'awaiting_confirmation' => [],
    'in_production' => [],
    'completed' => []
];

foreach ($allOrders as $ref => $ord) {
    $curStatus = strtolower(trim((string) ($ord['status'] ?? ($ord['production_status'] ?? 'pending_confirmation'))));
    $cat = get_status_category($curStatus);
    if (isset($adminCategories[$cat])) {
        $adminCategories[$cat][$ref] = $ord;
    } else {
        $adminCategories['in_production'][$ref] = $ord;
    }
}

$countTotal = count($allOrders);
$countAwaiting = count($adminCategories['awaiting_confirmation']);
$countProduction = count($adminCategories['in_production']);
$countCompleted = count($adminCategories['completed']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Shagun Admin Dashboard | Workshop & Order Management</title>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@500;700&family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-color: #fcf9f5;
            --card-bg: #ffffff;
            --primary: #6b1d28;
            --primary-dark: #4e111b;
            --text-dark: #2b2426;
            --text-muted: #736366;
            --gold: #b38743;
            --gold-light: #f7eedf;
            --border: #ebdcd0;
            --success-bg: #eef8f1;
            --success-border: #c3e6cb;
            --success-text: #1e7e34;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Poppins', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background-color: var(--bg-color);
            color: var(--text-dark);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        .admin-nav {
            background: #ffffff;
            border-bottom: 1px solid var(--border);
            padding: 16px 32px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.04);
        }

        .admin-brand {
            display: flex;
            align-items: center;
            gap: 12px;
            text-decoration: none;
            color: var(--primary);
        }

        .admin-brand h1 {
            font-family: 'Playfair Display', serif;
            font-size: 20px;
            letter-spacing: 0.5px;
            margin: 0;
        }

        .admin-tag {
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            background: var(--gold-light);
            color: var(--gold);
            padding: 3px 8px;
            border-radius: 4px;
        }

        .admin-nav-actions {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .admin-nav-link {
            font-size: 13.5px;
            color: var(--text-muted);
            text-decoration: none;
            transition: color 0.2s ease;
        }

        .admin-nav-link:hover {
            color: var(--primary);
        }

        .btn-logout {
            font-size: 13px;
            font-weight: 500;
            padding: 7px 16px;
            background: #fff5f5;
            color: #c53030;
            border: 1px solid #fed7d7;
            border-radius: 6px;
            text-decoration: none;
            transition: all 0.2s ease;
        }

        .btn-logout:hover {
            background: #fed7d7;
        }

        .admin-main {
            flex: 1;
            max-width: 1080px;
            width: 100%;
            margin: 36px auto;
            padding: 0 24px;
        }

        .admin-card {
            background: var(--card-bg);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 32px;
            box-shadow: 0 6px 24px rgba(71, 39, 39, 0.04);
            margin-bottom: 24px;
        }

        .admin-card-header {
            border-bottom: 1px solid var(--border);
            padding-bottom: 18px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 16px;
        }

        .admin-title-area h2 {
            font-family: 'Playfair Display', serif;
            font-size: 26px;
            color: var(--primary);
            margin-bottom: 4px;
        }

        .admin-title-area p {
            font-size: 13.5px;
            color: var(--text-muted);
        }

        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 5px 12px;
            background: var(--success-bg);
            border: 1px solid var(--success-border);
            color: var(--success-text);
            border-radius: 999px;
            font-size: 12px;
            font-weight: 500;
        }

        .status-badge::before {
            content: '';
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: #28a745;
        }

        /* Stats Grid */
        .admin-metrics-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 16px;
            margin-bottom: 28px;
        }

        .metric-box {
            background: #ffffff;
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 16px;
            text-align: center;
            box-shadow: 0 2px 6px rgba(0,0,0,0.02);
        }

        .metric-box-num {
            font-family: 'Playfair Display', serif;
            font-size: 28px;
            font-weight: 700;
            color: var(--primary);
            line-height: 1.1;
            margin-bottom: 4px;
        }

        .metric-box-label {
            font-size: 11.5px;
            font-weight: 600;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .metric-box.is-awaiting .metric-box-num { color: #b38743; }
        .metric-box.is-completed .metric-box-num { color: #1e7e34; }

        .flash-alert {
            padding: 14px 18px;
            border-radius: 8px;
            margin-bottom: 24px;
            font-size: 14px;
            font-weight: 500;
        }

        .flash-success {
            background: #eef8f1;
            border: 1px solid #c3e6cb;
            color: #1e7e34;
        }

        .flash-error {
            background: #fff5f5;
            border: 1px solid #fed7d7;
            color: #c53030;
        }

        /* Order Queue Table / Cards */
        .order-item-card {
            background: #ffffff;
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 16px;
            transition: box-shadow 0.15s ease, border-color 0.15s ease;
        }

        .order-item-card:hover {
            border-color: var(--gold);
            box-shadow: 0 4px 14px rgba(0,0,0,0.05);
        }

        .order-item-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
            border-bottom: 1px dashed var(--border);
            padding-bottom: 12px;
            margin-bottom: 14px;
        }

        .order-item-ref {
            font-family: 'Playfair Display', serif;
            font-size: 18px;
            font-weight: 700;
            color: var(--primary);
        }

        .order-badge-pill {
            font-size: 11px;
            font-weight: 700;
            padding: 3px 9px;
            border-radius: 6px;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }

        .badge-awaiting {
            background: #fdf5ea;
            color: #9e6c38;
            border: 1px solid #ecc99d;
        }

        .badge-production {
            background: #f8ecf0;
            color: #6b1d28;
            border: 1px solid #e6b8c4;
        }

        .badge-completed {
            background: #eaf6ee;
            color: #2e6945;
            border: 1px solid #b7dfc4;
        }

        .order-item-details-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 14px;
            margin-bottom: 16px;
            font-size: 13px;
        }

        .detail-cell span {
            display: block;
            font-size: 11px;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.03em;
            margin-bottom: 2px;
        }

        .detail-cell strong {
            color: var(--text-dark);
            font-weight: 600;
        }

        .order-item-controls {
            background: #faf6f2;
            border-radius: 8px;
            padding: 14px 16px;
            display: flex;
            gap: 16px;
            align-items: flex-end;
            flex-wrap: wrap;
        }

        .control-form {
            display: flex;
            gap: 10px;
            align-items: flex-end;
            flex-wrap: wrap;
            flex: 1;
            min-width: 280px;
        }

        .control-field {
            display: flex;
            flex-direction: column;
            gap: 4px;
            flex: 1;
            min-width: 140px;
        }

        .control-field label {
            font-size: 11px;
            font-weight: 600;
            color: var(--text-muted);
            text-transform: uppercase;
        }

        .control-field select,
        .control-field input {
            padding: 7px 10px;
            border: 1px solid var(--border);
            border-radius: 6px;
            font-size: 12.5px;
            background: #ffffff;
            font-family: inherit;
        }

        .btn-update-status {
            padding: 8px 16px;
            background: var(--primary);
            color: #ffffff;
            border: none;
            border-radius: 6px;
            font-size: 12.5px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.15s ease;
        }

        .btn-update-status:hover {
            background: var(--primary-dark);
        }

        .status-history-box {
            margin-top: 12px;
            padding-top: 10px;
            border-top: 1px dashed var(--border);
            font-size: 12px;
            color: var(--text-muted);
        }

        .admin-footer {
            text-align: center;
            padding: 24px;
            font-size: 13px;
            color: var(--text-muted);
            border-top: 1px solid var(--border);
            background: #ffffff;
        }

        /* Customer Details Box */
        .order-customer-section {
            background: #fdfaf7;
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 12px 16px;
            margin-bottom: 14px;
        }

        .customer-info-header {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 11px;
            font-weight: 700;
            color: var(--primary);
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 10px;
            border-bottom: 1px dashed var(--border);
            padding-bottom: 6px;
        }

        .customer-info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 12px;
            font-size: 12.5px;
        }

        .customer-cell .cell-label {
            display: block;
            font-size: 10.5px;
            font-weight: 600;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.03em;
            margin-bottom: 2px;
        }

        .customer-cell .cell-val {
            color: var(--text-dark);
            font-weight: 600;
            word-break: break-word;
        }

        .customer-cell.address-cell {
            grid-column: 1 / -1;
        }

        .btn-download-dossier {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 12px;
            background: #ffffff;
            color: var(--primary);
            border: 1px solid var(--border);
            border-radius: 6px;
            font-size: 11.5px;
            font-weight: 600;
            text-decoration: none;
            transition: all 0.15s ease;
        }

        .btn-download-dossier:hover {
            background: var(--gold-light);
            border-color: var(--gold);
            color: var(--primary-dark);
        }

        @media (max-width: 768px) {
            .admin-metrics-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            .order-item-controls {
                flex-direction: column;
                align-items: stretch;
            }
        }
    </style>
</head>
<body>

    <header class="admin-nav">
        <a href="index.php" class="admin-brand">
            <h1>Shagun Ladies Tailor</h1>
            <span class="admin-tag">Atelier Console</span>
        </a>
        <div class="admin-nav-actions">
            <a href="../orders.php" class="admin-nav-link" target="_blank">Storefront Orders</a>
            <a href="../logout.php" class="btn-logout">Logout</a>
        </div>
    </header>

    <main class="admin-main">

        <?php if ($flashSuccess): ?>
            <div class="flash-alert flash-success">
                ✓ <?php echo htmlspecialchars($flashSuccess); ?>
            </div>
        <?php endif; ?>

        <?php if ($flashError): ?>
            <div class="flash-alert flash-error">
                ⚠ <?php echo htmlspecialchars($flashError); ?>
            </div>
        <?php endif; ?>

        <!-- ADMIN SESSION PROFILE CARD -->
        <div class="admin-card">
            <div class="admin-card-header">
                <div class="admin-title-area">
                    <h2>Shagun Admin Dashboard</h2>
                    <p>Protected atelier administration &bull; Logged in as <strong><?php echo $fullName; ?></strong> (@<?php echo $username; ?>) &bull; Role: <strong><?php echo $adminRoleLabel; ?></strong></p>
                </div>
                <div class="status-badge">
                    Authentication Successful &bull; Active
                </div>
            </div>

            <!-- METRIC STATS -->
            <div class="admin-metrics-grid">
                <div class="metric-box">
                    <div class="metric-box-num"><?php echo $countTotal; ?></div>
                    <div class="metric-box-label">Total Orders</div>
                </div>
                <div class="metric-box is-awaiting">
                    <div class="metric-box-num"><?php echo $countAwaiting; ?></div>
                    <div class="metric-box-label">Awaiting Confirmation</div>
                </div>
                <div class="metric-box">
                    <div class="metric-box-num"><?php echo $countProduction; ?></div>
                    <div class="metric-box-label">In Production</div>
                </div>
                <div class="metric-box is-completed">
                    <div class="metric-box-num"><?php echo $countCompleted; ?></div>
                    <div class="metric-box-label">Completed Orders</div>
                </div>
            </div>
        </div>

        <!-- ORDER QUEUE MANAGEMENT -->
        <div class="admin-card">
            <div class="admin-card-header">
                <div class="admin-title-area">
                    <h2>Order Queue & Lifecycle Dispatch</h2>
                    <p>Review customer orders, transition lifecycle statuses, and manage committed delivery timelines.</p>
                </div>
            </div>

            <?php if (empty($allOrders)): ?>
                <div style="text-align: center; padding: 40px; color: var(--text-muted);">
                    <p>No customer orders currently in the atelier queue.</p>
                </div>
            <?php else: ?>
                <?php foreach ($allOrders as $ref => $ord): ?>
                    <?php
                    $curStatus = strtolower(trim((string) ($ord['status'] ?? ($ord['production_status'] ?? 'pending_confirmation'))));
                    $cat = get_status_category($curStatus);
                    $badgeClass = match ($cat) {
                        'awaiting_confirmation' => 'badge-awaiting',
                        'completed' => 'badge-completed',
                        default => 'badge-production'
                    };
                    $catLabel = match ($cat) {
                        'awaiting_confirmation' => 'Awaiting Confirmation',
                        'completed' => 'Completed Orders',
                        default => 'In Production'
                    };
                    $statusLabel = get_status_display_label($curStatus);
                    $totalAmt = (int) ($ord['advance_payment']['order_total'] ?? ($ord['grand_total'] ?? ($ord['total_amount'] ?? 0)));
                    $paidAmt = (int) ($ord['payment']['amount_paid'] ?? ($ord['advance_payment']['selected_amount'] ?? ($ord['advance_amount'] ?? 0)));
                    $balanceAmt = (int) ($ord['payment']['remaining_balance'] ?? ($ord['balance_amount'] ?? ($totalAmt - $paidAmt)));
                    $bookedDate = $ord['booked_date'] ?? ($ord['payment']['booked_date'] ?? date('Y-m-d'));
                    $reqDate = $ord['requested_ready_date'] ?? ($ord['wedding_date'] ?? 'Not specified');
                    $delDate = $ord['admin_delivery_date'] ?? $reqDate;
                    $history = $ord['status_history'] ?? [];

                    $custName = $ord['customer_name'] ?? null;
                    $custPhone = $ord['customer_phone'] ?? null;
                    $custEmail = $ord['customer_email'] ?? null;
                    $custAddress = $ord['customer_address'] ?? null;
                    $phoneDisplay = $ord['phone_display'] ?? ($custPhone ?: 'Phone number not provided');
                    $addressDisplay = $ord['address_display'] ?? ($custAddress ?: 'Address not provided');
                    $nameDisplay = $custName ?: (!empty($ord['user_id']) ? 'Customer' : 'Customer account not linked');
                    $emailDisplay = $custEmail ?: 'Email not provided';
                    ?>
                    <div class="order-item-card" id="manage-order-<?php echo htmlspecialchars($ref); ?>">
                        <div class="order-item-top">
                            <div>
                                <span class="order-item-ref"><?php echo htmlspecialchars($ref); ?></span>
                                <span style="font-size: 12.5px; color: var(--text-muted); margin-left: 8px;">
                                    <?php echo htmlspecialchars($ord['order_type'] ?? ($ord['occasion'] ?? 'Tailoring Order')); ?>
                                </span>
                            </div>
                            <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                                <a href="index.php?action=download_dossier&ref=<?php echo urlencode($ref); ?>" class="btn-download-dossier" target="_blank" id="download-dossier-<?php echo htmlspecialchars($ref); ?>">
                                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
                                    Download Order Dossier PDF
                                </a>
                                <span class="order-badge-pill <?php echo $badgeClass; ?>">
                                    <?php echo htmlspecialchars($catLabel); ?> &bull; <?php echo htmlspecialchars($statusLabel); ?>
                                </span>
                            </div>
                        </div>

                        <!-- CUSTOMER INFORMATION SECTION -->
                        <div class="order-customer-section">
                            <div class="customer-info-header">
                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
                                <span>Customer Information</span>
                            </div>
                            <div class="customer-info-grid">
                                <div class="customer-cell">
                                    <span class="cell-label">Customer Name</span>
                                    <strong class="cell-val"><?php echo htmlspecialchars($nameDisplay); ?></strong>
                                </div>
                                <div class="customer-cell">
                                    <span class="cell-label">Phone Number</span>
                                    <strong class="cell-val"><?php echo htmlspecialchars($phoneDisplay); ?></strong>
                                </div>
                                <div class="customer-cell">
                                    <span class="cell-label">Email Address</span>
                                    <strong class="cell-val"><?php echo htmlspecialchars($emailDisplay); ?></strong>
                                </div>
                                <div class="customer-cell address-cell">
                                    <span class="cell-label">Delivery Address</span>
                                    <strong class="cell-val"><?php echo htmlspecialchars($addressDisplay); ?></strong>
                                </div>
                            </div>
                        </div>

                        <div class="order-item-details-grid">
                            <div class="detail-cell">
                                <span>Booked Date</span>
                                <strong><?php echo date('d M Y', strtotime((string)$bookedDate)); ?></strong>
                            </div>
                            <div class="detail-cell">
                                <span>Requested Date</span>
                                <strong><?php echo $reqDate !== 'Not specified' ? date('d M Y', strtotime((string)$reqDate)) : 'Not specified'; ?></strong>
                            </div>
                            <div class="detail-cell">
                                <span>Admin Delivery Date</span>
                                <strong style="color: var(--gold);"><?php echo $delDate !== 'Not specified' ? date('d M Y', strtotime((string)$delDate)) : 'Not specified'; ?></strong>
                            </div>
                            <div class="detail-cell">
                                <span>Financials</span>
                                <strong>₹<?php echo number_format($totalAmt); ?> (Paid: ₹<?php echo number_format($paidAmt); ?>)</strong>
                            </div>
                        </div>

                        <!-- STATUS UPDATE FORM -->
                        <div class="order-item-controls">
                            <form method="POST" action="index.php" class="control-form">
                                <input type="hidden" name="action" value="admin_update_status">
                                <input type="hidden" name="order_ref" value="<?php echo htmlspecialchars($ref); ?>">
                                <div class="control-field">
                                    <label for="status-select-<?php echo htmlspecialchars($ref); ?>">Update Status:</label>
                                    <select name="new_status" id="status-select-<?php echo htmlspecialchars($ref); ?>" required>
                                        <?php foreach ($allowedStatuses as $st): ?>
                                            <option value="<?php echo htmlspecialchars($st); ?>" <?php echo $st === $curStatus ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars(get_status_display_label($st)); ?> (<?php echo htmlspecialchars(get_status_category($st)); ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="control-field">
                                    <label for="notes-<?php echo htmlspecialchars($ref); ?>">Admin Note:</label>
                                    <input type="text" name="notes" id="notes-<?php echo htmlspecialchars($ref); ?>" placeholder="Reason for status change...">
                                </div>
                                <button type="submit" class="btn-update-status">Save Status</button>
                            </form>

                            <!-- DELIVERY DATE UPDATE -->
                            <form method="POST" action="index.php" class="control-form" style="border-left: 1px solid var(--border); padding-left: 14px;">
                                <input type="hidden" name="action" value="admin_update_delivery_date">
                                <input type="hidden" name="order_ref" value="<?php echo htmlspecialchars($ref); ?>">
                                <div class="control-field">
                                    <label for="del-date-<?php echo htmlspecialchars($ref); ?>">Committed Date:</label>
                                    <input type="date" name="admin_delivery_date" id="del-date-<?php echo htmlspecialchars($ref); ?>" value="<?php echo htmlspecialchars((string)$delDate); ?>" required>
                                </div>
                                <div class="control-field">
                                    <label for="del-note-<?php echo htmlspecialchars($ref); ?>">Date Note:</label>
                                    <input type="text" name="admin_note" id="del-note-<?php echo htmlspecialchars($ref); ?>" placeholder="Date change reason...">
                                </div>
                                <button type="submit" class="btn-update-status" style="background: #252321;">Update Date</button>
                            </form>
                        </div>

                        <!-- AUDIT HISTORY -->
                        <?php if (!empty($history)): ?>
                            <div class="status-history-box">
                                <strong>Status History:</strong>
                                <ul style="margin: 4px 0 0 16px;">
                                    <?php foreach ($history as $h): ?>
                                        <li>
                                            <?php echo htmlspecialchars($h['formatted_created'] ?? date('d M Y')); ?>:
                                            Changed from <em><?php echo htmlspecialchars(get_status_display_label($h['old_status'] ?? '')); ?></em> to
                                            <strong><?php echo htmlspecialchars(get_status_display_label($h['new_status'] ?? '')); ?></strong>
                                            <?php if (!empty($h['notes'])): ?>
                                                (<?php echo htmlspecialchars($h['notes']); ?>)
                                            <?php endif; ?>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>

        </div>
    </main>

    <footer class="admin-footer">
        Shagun Ladies Tailor &copy; <?php echo date('Y'); ?> &bull; Bespoke Bridal & Occasion Tailoring &bull; Atelier Management System
    </footer>

</body>
</html>
