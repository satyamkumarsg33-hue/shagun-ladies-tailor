<?php
/**
 * Shagun Ladies Tailor — Order Status & Lifecycle Architecture
 * 
 * Manages order lifecycle transitions exclusively controlled by Admin:
 *  1. Awaiting Confirmation:
 *     - 'pending', 'pending_confirmation', 'awaiting_confirmation'
 *     - Description: "Orders received and awaiting confirmation or initial processing."
 *  2. In Production:
 *     - 'confirmed', 'measurement_pending', 'materials_pending', 'cutting',
 *       'stitching', 'embroidery', 'quality_check', 'in_progress',
 *       'ready_for_fitting', 'alteration', 'ready_for_delivery'
 *     - Description: "Orders currently being prepared, stitched, embroidered, or checked."
 *  3. Completed Orders:
 *     - 'delivered', 'collected', 'completed'
 *     - Description: "Completed orders ready for collection, delivery, or customer reference."
 * 
 * Strict Business Rules:
 * - Payment completion sets initial status to 'pending_confirmation'.
 * - Payment status and production status are independent.
 * - Customers have strictly read-only visibility into their order status.
 * - Only authorized Admin roles can update status with full audit logging.
 */

declare(strict_types=1);

require_once __DIR__ . '/auth.php';

// Allowlist of recognized internal statuses
const ORDER_STATUS_ALLOWLIST = [
    // Awaiting Confirmation
    'pending',
    'pending_confirmation',
    'awaiting_confirmation',
    // In Production
    'confirmed',
    'measurement_pending',
    'materials_pending',
    'cutting',
    'stitching',
    'embroidery',
    'quality_check',
    'in_progress',
    'in_production',
    'ready_for_fitting',
    'alteration',
    'ready_for_delivery',
    // Completed Orders
    'delivered',
    'collected',
    'completed'
];

/**
 * Maps an internal status to one of the 3 customer-facing categories:
 * - 'awaiting_confirmation'
 * - 'in_production'
 * - 'completed'
 */
function get_status_category(string $status): string {
    $clean = strtolower(trim($status));
    
    $awaiting = [
        'pending',
        'pending_confirmation',
        'awaiting_confirmation'
    ];
    
    $completed = [
        'delivered',
        'collected',
        'completed'
    ];

    if (in_array($clean, $awaiting, true)) {
        return 'awaiting_confirmation';
    }
    if (in_array($clean, $completed, true)) {
        return 'completed';
    }
    return 'in_production';
}

/**
 * Human-readable display label for customer-facing order cards.
 */
function get_status_display_label(string $status): string {
    $clean = strtolower(trim($status));
    return match ($clean) {
        'pending' => 'Order Received',
        'pending_confirmation' => 'Awaiting Confirmation',
        'awaiting_confirmation' => 'Awaiting Confirmation',
        'confirmed' => 'Order Confirmed',
        'measurement_pending' => 'Measurement Pending',
        'materials_pending' => 'Materials Pending',
        'cutting' => 'Fabric Cutting',
        'stitching' => 'Stitching in Progress',
        'embroidery' => 'Embroidery in Progress',
        'quality_check' => 'Quality Checking',
        'in_progress' => 'In Production',
        'in_production' => 'In Production',
        'ready_for_fitting' => 'Ready for Fitting',
        'alteration' => 'Alterations in Progress',
        'ready_for_delivery' => 'Ready for Delivery',
        'delivered' => 'Delivered',
        'collected' => 'Collected',
        'completed' => 'Completed',
        default => ucwords(str_replace('_', ' ', $clean))
    };
}

/**
 * Returns CSS badge class for status display.
 */
function get_status_badge_class(string $status): string {
    $cat = get_status_category($status);
    return match ($cat) {
        'awaiting_confirmation' => 'status-awaiting',
        'completed' => 'status-completed',
        default => 'status-production'
    };
}

/**
 * Returns allowed target statuses based on Admin role.
 */
function get_allowed_statuses_for_role(string $role): array {
    $role = strtolower(trim($role));
    if ($role === 'super_admin') {
        return ORDER_STATUS_ALLOWLIST;
    }
    if ($role === 'master_tailor') {
        return [
            'cutting',
            'stitching',
            'embroidery',
            'quality_check',
            'in_progress',
            'ready_for_fitting',
            'alteration',
            'ready_for_delivery'
        ];
    }
    if ($role === 'store_manager') {
        return [
            'confirmed',
            'measurement_pending',
            'materials_pending',
            'ready_for_delivery',
            'delivered',
            'collected',
            'completed'
        ];
    }
    return [];
}

/**
 * Check if a given Admin role is permitted to set an order to $targetStatus.
 */
function admin_can_update_status(string $role, string $targetStatus, ?string $currentStatus = null): bool {
    $targetStatus = strtolower(trim($targetStatus));
    if (!in_array($targetStatus, ORDER_STATUS_ALLOWLIST, true)) {
        return false;
    }
    $allowed = get_allowed_statuses_for_role($role);
    return in_array($targetStatus, $allowed, true);
}

/**
 * Central Admin Order Status Updater.
 * 
 * Enforces:
 * 1. Admin authentication and authorization.
 * 2. Status allowlist validation.
 * 3. Role-based transition permissions.
 * 4. Immutable status history logging (order_status_history).
 * 5. Activity log entry where supported.
 * 6. Customer session isolation and protection against browser forging.
 * 
 * @param string $orderRef Target order reference
 * @param string $newStatus Requested new status
 * @param string|null $notes Optional admin note explaining the update
 * @param int|null $adminId Optional override for testing
 * @param string|null $adminRole Optional override for testing
 * @return array ['success' => bool, 'error' => string|null, ...]
 */
function admin_update_order_status(
    string $orderRef,
    string $newStatus,
    ?string $notes = null,
    ?int $adminId = null,
    ?string $adminRole = null
): array {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    $orderRef = trim($orderRef);
    $newStatus = strtolower(trim($newStatus));
    $notes = trim((string) $notes);

    // 1. Resolve Admin identity and authorization
    $admin = get_logged_in_admin();
    $resolvedAdminId = $adminId ?? (int) ($admin['id'] ?? 0);
    $resolvedRole = $adminRole ?? (string) ($admin['role'] ?? '');

    if ($resolvedAdminId <= 0 || empty($resolvedRole)) {
        return [
            'success' => false,
            'error' => 'Unauthorized. Administrative session required.',
            'order_ref' => $orderRef
        ];
    }

    // Explicit check: customers are strictly forbidden
    if (is_user_logged_in() && !is_admin_logged_in() && empty($adminRole)) {
        return [
            'success' => false,
            'error' => 'Forbidden. Customer accounts cannot modify order status.',
            'order_ref' => $orderRef
        ];
    }

    // 2. Validate status against allowlist
    if (!in_array($newStatus, ORDER_STATUS_ALLOWLIST, true)) {
        return [
            'success' => false,
            'error' => "Invalid order status '{$newStatus}'.",
            'order_ref' => $orderRef
        ];
    }

    // 3. Check role-based permission
    if (!admin_can_update_status($resolvedRole, $newStatus)) {
        $roleLabel = get_admin_role_label($resolvedRole);
        return [
            'success' => false,
            'error' => "Admin role '{$roleLabel}' is not authorized to set status to '{$newStatus}'.",
            'order_ref' => $orderRef
        ];
    }

    // 4. Find order in storage (session store & DB)
    $foundInSession = false;
    $targetUserId = null;
    $oldStatus = 'pending_confirmation';
    $orderFound = false;

    // Search $_SESSION['customer_orders']
    if (!empty($_SESSION['customer_orders']) && is_array($_SESSION['customer_orders'])) {
        foreach ($_SESSION['customer_orders'] as $uId => $orders) {
            if (isset($orders[$orderRef])) {
                $targetUserId = (int) $uId;
                $oldStatus = (string) ($_SESSION['customer_orders'][$uId][$orderRef]['status'] ?? 'pending_confirmation');
                $foundInSession = true;
                $orderFound = true;
                break;
            }
        }
    }

    // Check active session drafts if applicable
    if (!$orderFound) {
        if (!empty($_SESSION['standard_order']) && (($_SESSION['standard_order']['order_ref'] ?? '') === $orderRef || ($_SESSION['standard_order']['payment']['order_ref'] ?? '') === $orderRef)) {
            $oldStatus = (string) ($_SESSION['standard_order']['status'] ?? 'pending_confirmation');
            $targetUserId = (int) ($_SESSION['standard_order']['user_id'] ?? 0);
            $orderFound = true;
            $foundInSession = true;
        } elseif (!empty($_SESSION['luxe_wedding']) && (($_SESSION['luxe_wedding']['order_ref'] ?? '') === $orderRef || ($_SESSION['luxe_wedding']['payment']['order_ref'] ?? '') === $orderRef)) {
            $oldStatus = (string) ($_SESSION['luxe_wedding']['status'] ?? 'pending_confirmation');
            $targetUserId = (int) ($_SESSION['luxe_wedding']['user_id'] ?? 0);
            $orderFound = true;
            $foundInSession = true;
        }
    }

    // Database lookup if available
    $dbOrderId = null;
    try {
        if (function_exists('get_db_connection')) {
            $pdo = get_db_connection();
            $stmt = $pdo->prepare("SELECT id, user_id, status FROM orders WHERE order_ref = :ref LIMIT 1");
            $stmt->execute([':ref' => $orderRef]);
            $dbOrder = $stmt->fetch();
            if ($dbOrder) {
                $dbOrderId = (int) $dbOrder['id'];
                if (!$orderFound) {
                    $oldStatus = (string) $dbOrder['status'];
                    $targetUserId = (int) $dbOrder['user_id'];
                    $orderFound = true;
                }
            }
        }
    } catch (\Throwable $e) {
        // Database error logging
        error_log('[Admin Status Lookup Error] ' . $e->getMessage());
    }

    if (!$orderFound) {
        return [
            'success' => false,
            'error' => "Order '{$orderRef}' not found.",
            'order_ref' => $orderRef
        ];
    }

    $timestamp = time();
    $historyEntry = [
        'old_status' => $oldStatus,
        'new_status' => $newStatus,
        'actor' => 'admin',
        'admin_user_id' => $resolvedAdminId,
        'admin_role' => $resolvedRole,
        'notes' => $notes ?: ("Admin status updated to " . get_status_display_label($newStatus)),
        'created_at' => $timestamp,
        'formatted_created' => date('d M Y, h:i A', $timestamp)
    ];

    // 5. Update session storage
    if ($foundInSession) {
        if ($targetUserId !== null && isset($_SESSION['customer_orders'][$targetUserId][$orderRef])) {
            $_SESSION['customer_orders'][$targetUserId][$orderRef]['status'] = $newStatus;
            $_SESSION['customer_orders'][$targetUserId][$orderRef]['production_status'] = $newStatus;
            $_SESSION['customer_orders'][$targetUserId][$orderRef]['updated_at'] = $timestamp;
            if (!isset($_SESSION['customer_orders'][$targetUserId][$orderRef]['status_history']) || !is_array($_SESSION['customer_orders'][$targetUserId][$orderRef]['status_history'])) {
                $_SESSION['customer_orders'][$targetUserId][$orderRef]['status_history'] = [];
            }
            $_SESSION['customer_orders'][$targetUserId][$orderRef]['status_history'][] = $historyEntry;

            if (get_status_category($newStatus) === 'completed' && empty($_SESSION['customer_orders'][$targetUserId][$orderRef]['completed_at'])) {
                $_SESSION['customer_orders'][$targetUserId][$orderRef]['completed_at'] = $timestamp;
            }
        }

        if (!empty($_SESSION['standard_order']) && (($_SESSION['standard_order']['order_ref'] ?? '') === $orderRef || ($_SESSION['standard_order']['payment']['order_ref'] ?? '') === $orderRef)) {
            $_SESSION['standard_order']['status'] = $newStatus;
            $_SESSION['standard_order']['production_status'] = $newStatus;
            if (!isset($_SESSION['standard_order']['status_history'])) $_SESSION['standard_order']['status_history'] = [];
            $_SESSION['standard_order']['status_history'][] = $historyEntry;
            if (get_status_category($newStatus) === 'completed' && empty($_SESSION['standard_order']['completed_at'])) {
                $_SESSION['standard_order']['completed_at'] = $timestamp;
            }
        }

        if (!empty($_SESSION['luxe_wedding']) && (($_SESSION['luxe_wedding']['order_ref'] ?? '') === $orderRef || ($_SESSION['luxe_wedding']['payment']['order_ref'] ?? '') === $orderRef)) {
            $_SESSION['luxe_wedding']['status'] = $newStatus;
            $_SESSION['luxe_wedding']['production_status'] = $newStatus;
            if (!isset($_SESSION['luxe_wedding']['status_history'])) $_SESSION['luxe_wedding']['status_history'] = [];
            $_SESSION['luxe_wedding']['status_history'][] = $historyEntry;
            if (get_status_category($newStatus) === 'completed' && empty($_SESSION['luxe_wedding']['completed_at'])) {
                $_SESSION['luxe_wedding']['completed_at'] = $timestamp;
            }
        }
    }

    // 6. Update database records if available
    try {
        if (function_exists('get_db_connection') && $dbOrderId !== null) {
            $pdo = get_db_connection();
            $upStmt = $pdo->prepare("UPDATE orders SET status = :status, updated_at = NOW() WHERE id = :id");
            $upStmt->execute([':status' => $newStatus, ':id' => $dbOrderId]);

            // Validate admin_id against admin_users to prevent foreign key violation
            $adminDbId = null;
            if ($resolvedAdminId > 0) {
                $chkAdmin = $pdo->prepare('SELECT id FROM admin_users WHERE id = :aid LIMIT 1');
                $chkAdmin->execute([':aid' => $resolvedAdminId]);
                if ($chkAdmin->fetch()) {
                    $adminDbId = $resolvedAdminId;
                }
            }

            $histStmt = $pdo->prepare("
                INSERT INTO order_status_history (order_id, old_status, new_status, actor, admin_user_id, notes, created_at)
                VALUES (:order_id, :old_status, :new_status, 'admin', :admin_id, :notes, NOW())
            ");
            $histStmt->execute([
                ':order_id' => $dbOrderId,
                ':old_status' => $oldStatus,
                ':new_status' => $newStatus,
                ':admin_id' => $adminDbId,
                ':notes' => $historyEntry['notes']
            ]);
        }
    } catch (\Throwable $e) {
        error_log('[Admin Status DB Update Error] ' . $e->getMessage());
    }

    return [
        'success' => true,
        'error' => null,
        'order_ref' => $orderRef,
        'old_status' => $oldStatus,
        'new_status' => $newStatus,
        'status_category' => get_status_category($newStatus),
        'status_label' => get_status_display_label($newStatus),
        'history_entry' => $historyEntry
    ];
}
