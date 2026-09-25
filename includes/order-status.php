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

// Four Canonical Order Lifecycle Statuses for Admin and Storefront
const ORDER_LIFECYCLE_STATUSES = [
    'awaiting_confirmation',
    'stitching_in_process',
    'completed',
    'delivered'
];

// Allowlist of recognized internal and legacy statuses
const ORDER_STATUS_ALLOWLIST = [
    // 4 Canonical Lifecycle Statuses
    'awaiting_confirmation',
    'stitching_in_process',
    'completed',
    'delivered',
    // Historical / Legacy Aliases
    'pending',
    'pending_confirmation',
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
    'collected'
];

/**
 * Maps any internal or legacy status to one of the 4 canonical lifecycle statuses:
 * - 'awaiting_confirmation'
 * - 'stitching_in_process'
 * - 'completed'
 * - 'delivered'
 */
function get_canonical_status(string $status): ?string {
    $clean = strtolower(trim($status));
    if (in_array($clean, ['pending', 'pending_confirmation', 'awaiting_confirmation'], true)) {
        return 'awaiting_confirmation';
    }
    if (in_array($clean, [
        'confirmed', 'measurement_pending', 'materials_pending', 'cutting',
        'stitching', 'embroidery', 'quality_check', 'in_progress',
        'in_production', 'ready_for_fitting', 'alteration', 'ready_for_delivery',
        'stitching_in_process'
    ], true)) {
        return 'stitching_in_process';
    }
    if (in_array($clean, ['completed', 'collected'], true)) {
        return 'completed';
    }
    if ($clean === 'delivered') {
        return 'delivered';
    }
    return null;
}

/**
 * Check if a status string is recognized by the canonical lifecycle or allowlist.
 */
function is_recognized_order_status(string $status): bool {
    return get_canonical_status($status) !== null;
}

/**
 * Maps an internal status to one of the 3 customer-facing categories:
 * - 'awaiting_confirmation'
 * - 'in_production'
 * - 'completed'
 * Preserved for backward compatibility with existing tests and modules.
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
 * Human-readable display label for customer-facing order cards and admin dashboard.
 */
function get_status_display_label(string $status): string {
    $clean = strtolower(trim($status));
    $canonical = get_canonical_status($clean);
    return match ($canonical) {
        'awaiting_confirmation' => 'Awaiting Confirmation',
        'stitching_in_process' => 'Stitching in Process',
        'completed' => 'Completed',
        'delivered' => 'Delivered',
        default => ucwords(str_replace('_', ' ', $clean))
    };
}

/**
 * Returns CSS badge class for status display.
 */
function get_status_badge_class(string $status): string {
    $canonical = get_canonical_status($status);
    return match ($canonical) {
        'awaiting_confirmation' => 'status-awaiting',
        'stitching_in_process' => 'status-production',
        'completed' => 'status-completed',
        'delivered' => 'status-delivered',
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
            'stitching_in_process',
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
            'stitching_in_process',
            'completed',
            'delivered',
            'confirmed',
            'measurement_pending',
            'materials_pending',
            'ready_for_delivery',
            'collected'
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

    // Sequential transition validation:
    // Lifecycle: awaiting_confirmation -> stitching_in_process -> completed -> delivered
    $canonicalOld = get_canonical_status($oldStatus);
    $canonicalNew = get_canonical_status($newStatus);

    if ($canonicalOld !== $canonicalNew) {
        $allowedNext = match ($canonicalOld) {
            'awaiting_confirmation' => 'stitching_in_process',
            'stitching_in_process' => 'completed',
            'completed' => 'delivered',
            'delivered' => null,
            default => null
        };

        if ($allowedNext === null || $canonicalNew !== $allowedNext) {
            $oldLabel = get_status_display_label($canonicalOld);
            $newLabel = get_status_display_label($canonicalNew);
            $expectedLabel = $allowedNext !== null ? get_status_display_label($allowedNext) : 'None';
            return [
                'success' => false,
                'error' => "Invalid status transition. Orders in '{$oldLabel}' cannot be moved to '{$newLabel}'. Allowed next stage: '{$expectedLabel}'.",
                'order_ref' => $orderRef,
                'old_status' => $oldStatus,
                'attempted_status' => $newStatus
            ];
        }
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

/**
 * Resolve the authoritative measurement method for an individual physical garment.
 *
 * Checks in priority order:
 * 1. $garment['measurement_method']
 * 2. $person['measurement_method'] (if inherited from person selection)
 * 3. $_SESSION['standard_order']['measurement_method'] (for standard tailoring items)
 *
 * Allowed valid customer-facing choices are:
 * - 'reference_blouse' (Sample reference garment provided)
 * - 'visit_shop' (Measurement pending at shop)
 *
 * Returns null if no valid measurement method is selected.
 */
function get_garment_measurement_method(array $garment, ?array $person = null): ?string {
    $allowed = ['reference_blouse', 'visit_shop'];

    // 1. Direct garment-level method
    if (!empty($garment['measurement_method']) && in_array($garment['measurement_method'], $allowed, true)) {
        return (string) $garment['measurement_method'];
    }

    // 2. Inherited person-level method
    if ($person !== null && !empty($person['measurement_method']) && in_array($person['measurement_method'], $allowed, true)) {
        return (string) $person['measurement_method'];
    }

    // 3. Inherited standard stitching order method if garment source is standard
    $isStd = ($garment['source'] ?? '') === 'standard' || !empty($garment['is_standard']);
    if ($isStd && !empty($_SESSION['standard_order']['measurement_method']) && in_array($_SESSION['standard_order']['measurement_method'], $allowed, true)) {
        return (string) $_SESSION['standard_order']['measurement_method'];
    }

    return null;
}

/**
 * Checks whether an individual physical garment has a valid measurement method.
 */
function has_valid_garment_measurement(array $garment, ?array $person = null): bool {
    return get_garment_measurement_method($garment, $person) !== null;
}

/**
 * Evaluates all physical garments in an order for measurement completeness.
 *
 * @param array $people Luxe people array with garments
 * @param array $standardItems Standard items array
 * @return array{all_valid: bool, total_garments: int, valid_count: int, missing_count: int, missing_details: array}
 */
function validate_order_garments_measurement(array $people, array $standardItems = []): array {
    $total = 0;
    $valid = 0;
    $missing = 0;
    $missingDetails = [];

    // 1. Evaluate Luxe garments per person
    foreach ($people as $pIdx => $person) {
        $pName = trim((string)($person['name'] ?? ('Person ' . ($pIdx + 1))));
        $garments = $person['garments'] ?? [];
        if (is_array($garments) && !empty($garments)) {
            foreach ($garments as $gIdx => $garment) {
                if (!is_array($garment)) {
                    continue;
                }
                $total++;
                $gName = trim((string)($garment['name'] ?? 'Garment'));
                $mMethod = get_garment_measurement_method($garment, $person);
                if ($mMethod !== null) {
                    $valid++;
                } else {
                    $missing++;
                    $missingDetails[] = [
                        'type' => 'luxe',
                        'person_index' => $pIdx,
                        'person_name' => $pName,
                        'garment_index' => $gIdx,
                        'garment_name' => $gName,
                        'label' => "{$pName} — {$gName} #" . ($gIdx + 1)
                    ];
                }
            }
        } else {
            // Person with no garments array: check person-level method
            $pMethod = $person['measurement_method'] ?? null;
            if (in_array($pMethod, ['reference_blouse', 'visit_shop'], true)) {
                $valid++;
            } else {
                $missing++;
                $missingDetails[] = [
                    'type' => 'luxe_person',
                    'person_index' => $pIdx,
                    'person_name' => $pName,
                    'garment_index' => null,
                    'garment_name' => 'Garments',
                    'label' => "{$pName} (Measurement Method)"
                ];
            }
            $total++;
        }
    }

    // 2. Evaluate Standard Stitching items
    foreach ($standardItems as $sIdx => $sItem) {
        if (!is_array($sItem)) {
            continue;
        }
        $total++;
        $sName = trim((string)($sItem['garment_name'] ?? ($sItem['garment'] ?? 'Standard Blouse')));
        $mMethod = get_garment_measurement_method($sItem);
        if ($mMethod !== null) {
            $valid++;
        } else {
            $missing++;
            $missingDetails[] = [
                'type' => 'standard',
                'person_index' => null,
                'person_name' => 'Self',
                'garment_index' => $sIdx,
                'garment_name' => $sName,
                'label' => "Standard — {$sName} #" . ($sIdx + 1)
            ];
        }
    }

    return [
        'all_valid' => ($total > 0 && $missing === 0),
        'total_garments' => $total,
        'valid_count' => $valid,
        'missing_count' => $missing,
        'missing_details' => $missingDetails
    ];
}

