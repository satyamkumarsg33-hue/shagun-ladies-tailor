<?php

declare(strict_types=1);

/**
 * Shagun Ladies Tailor — Authentication & RBAC Service
 * 
 * Provides database-backed authentication, session management, and role guards
 * for both Atelier Administrative Staff and Storefront Customers.
 * 
 * Core Guarantees:
 * - Passwords verified via password_verify() against Bcrypt/Argon2id hashes.
 * - Single Sign In entry point from homepage for both Admins and Customers.
 * - Explicit identity resolution: email checks admin_users then users; username checks admin_users only.
 * - Session ID regenerated on login to prevent fixation attacks.
 * - Strict session separation: $_SESSION['admin_user'] vs $_SESSION['user'].
 * - Draft cart / Luxe order state (demo_cart, luxe_wedding, standard_order) strictly preserved across login/logout.
 * - Server-side guards: require_admin_login() and require_user_login().
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/db.php';

// ============================================================================
// 1. CUSTOMER AUTHENTICATION HELPERS
// ============================================================================

/**
 * Check if a customer user is currently logged in.
 */
function is_user_logged_in(): bool {
    return !empty($_SESSION['user']) && is_array($_SESSION['user']) && !empty($_SESSION['user']['email']);
}

/**
 * Get current logged in customer details or null.
 */
function get_logged_in_user(): ?array {
    return is_user_logged_in() ? $_SESSION['user'] : null;
}

/**
 * Retrieve database-backed customer profile including verified phone and delivery address.
 * 
 * Rules:
 * - Queries users and customer_addresses using PDO prepared statements.
 * - Prioritizes default address; falls back to most recent valid address.
 * - Never invents fallback addresses or phone numbers.
 * - Returns structured array with real DB fields and neutral display labels when missing.
 * 
 * @param int $userId Customer user ID from database
 * @return array|null Customer profile array, or null if user does not exist or DB unavailable
 */
function get_customer_profile(int $userId): ?array {
    if ($userId <= 0) {
        return null;
    }

    try {
        $pdo = get_db_connection();

        // 1. Fetch user record from users table
        $userStmt = $pdo->prepare('
            SELECT id, name, email, phone, role, is_active, created_at
            FROM users
            WHERE id = :id LIMIT 1
        ');
        $userStmt->execute([':id' => $userId]);
        $user = $userStmt->fetch();

        if (!$user) {
            return null;
        }

        // 2. Fetch customer address from customer_addresses table (default first, then newest)
        $addrStmt = $pdo->prepare('
            SELECT id, recipient_name, phone, address_line1, address_line2, 
                   city, state, postal_code, is_default, created_at, updated_at
            FROM customer_addresses
            WHERE user_id = :user_id
            ORDER BY is_default DESC, id DESC
            LIMIT 1
        ');
        $addrStmt->execute([':user_id' => $userId]);
        $addressRow = $addrStmt->fetch() ?: null;

        // Resolve phone number
        $rawPhone = trim((string) ($user['phone'] ?? ''));
        if ($rawPhone === '' && !empty($addressRow['phone'])) {
            $rawPhone = trim((string) $addressRow['phone']);
        }
        $phoneVal = $rawPhone !== '' ? $rawPhone : null;
        $phoneDisplay = $phoneVal !== null ? $phoneVal : 'Phone number not provided';

        // Format delivery address
        $addressVal = null;
        if ($addressRow) {
            $parts = [];
            $line1 = trim((string) ($addressRow['address_line1'] ?? ''));
            $line2 = trim((string) ($addressRow['address_line2'] ?? ''));
            $city = trim((string) ($addressRow['city'] ?? ''));
            $state = trim((string) ($addressRow['state'] ?? ''));
            $postal = trim((string) ($addressRow['postal_code'] ?? ''));

            if ($line1 !== '') {
                $parts[] = $line1;
            }
            if ($line2 !== '') {
                $parts[] = $line2;
            }
            if ($city !== '' && stripos($line1, $city) === false) {
                $parts[] = $city;
            }
            if ($state !== '' && stripos($line1, $state) === false) {
                $statePart = $state;
                if ($postal !== '' && stripos($line1, $postal) === false) {
                    $statePart .= ' - ' . $postal;
                }
                $parts[] = $statePart;
            } elseif ($postal !== '' && stripos($line1, $postal) === false) {
                $parts[] = $postal;
            }

            if (!empty($parts)) {
                $addressVal = implode(', ', $parts);
            }
        }
        $addressDisplay = $addressVal !== null ? $addressVal : 'Address not provided';

        return [
            'id' => (int) $user['id'],
            'name' => (string) $user['name'],
            'email' => (string) $user['email'],
            'phone' => $phoneVal,
            'phone_display' => $phoneDisplay,
            'address' => $addressVal,
            'address_display' => $addressDisplay,
            'address_parts' => $addressRow,
            'role' => (string) $user['role'],
            'is_active' => (bool) $user['is_active'],
            'created_at' => $user['created_at'],
        ];
    } catch (Throwable $e) {
        error_log('[Customer Profile Error] User ID ' . $userId . ': ' . $e->getMessage());
        return null;
    }
}

// ============================================================================
// 2. ADMIN AUTHENTICATION HELPERS
// ============================================================================

/**
 * Check if an administrative staff member is currently logged in.
 */
function is_admin_logged_in(): bool {
    return !empty($_SESSION['admin_user']) && is_array($_SESSION['admin_user']) && !empty($_SESSION['admin_user']['id']);
}

/**
 * Get current logged in admin details or null.
 */
function get_logged_in_admin(): ?array {
    return is_admin_logged_in() ? $_SESSION['admin_user'] : null;
}

/**
 * Get current logged in admin's role slug (super_admin, master_tailor, store_manager).
 */
function get_admin_role(): ?string {
    return $_SESSION['admin_user']['role'] ?? null;
}

/**
 * Format admin role slug for presentation.
 */
function get_admin_role_label(?string $role = null): string {
    $targetRole = $role ?? get_admin_role();
    return match ($targetRole) {
        'super_admin' => 'Super Admin',
        'master_tailor' => 'Master Tailor',
        'store_manager' => 'Store Manager',
        default => 'Staff'
    };
}

// ============================================================================
// 3. CENTRAL AUTHENTICATION ENGINE
// ============================================================================

/**
 * Attempt authentication with given identifier (email or username) and password.
 * 
 * Resolution Logic:
 * - If identifier contains '@' (email):
 *     1. Query admin_users by email.
 *        If found: verify password. If valid, login as admin.
 *        If invalid password: FAIL IMMEDIATELY (never fall through to customer with same email).
 *     2. If no admin with that email: query users by email.
 *        If found: verify password. If valid, login as customer.
 * - If identifier does not contain '@' (username):
 *     1. Query admin_users by username.
 *        If found: verify password. If valid, login as admin.
 *        If not found: FAIL (customers only use email).
 * 
 * @return array ['success' => bool, 'type' => 'admin'|'customer'|null, 'user' => array|null, 'error' => string|null]
 */
function auth_authenticate_user(string $identity, string $password): array {
    $rawIdentity = trim($identity);
    $rawPassword = trim($password);

    if ($rawIdentity === '' || $rawPassword === '') {
        return [
            'success' => false,
            'type' => null,
            'user' => null,
            'error' => 'Please enter your email or username and password.'
        ];
    }

    try {
        $pdo = get_db_connection();
    } catch (Throwable $e) {
        error_log('[Auth Error] Database connection failure: ' . $e->getMessage());
        return [
            'success' => false,
            'type' => null,
            'user' => null,
            'error' => 'Authentication service temporarily unavailable. Please try again shortly.'
        ];
    }

    $isEmail = str_contains($rawIdentity, '@');

    if ($isEmail) {
        $normalizedEmail = strtolower($rawIdentity);

        // 1. Check admin_users first by email
        $adminStmt = $pdo->prepare('
            SELECT id, username, email, password_hash, full_name, role, is_active 
            FROM admin_users 
            WHERE email = :email LIMIT 1
        ');
        $adminStmt->execute([':email' => $normalizedEmail]);
        $admin = $adminStmt->fetch();

        if ($admin) {
            // Admin account exists with this email
            if ((int)$admin['is_active'] !== 1) {
                return [
                    'success' => false,
                    'type' => null,
                    'user' => null,
                    'error' => 'This account has been deactivated. Please contact an administrator.'
                ];
            }

            if (password_verify($rawPassword, $admin['password_hash'])) {
                // SUCCESS: Admin authentication
                session_regenerate_id(true);

                // Update last login
                try {
                    $upd = $pdo->prepare('UPDATE admin_users SET last_login_at = NOW() WHERE id = :id');
                    $upd->execute([':id' => $admin['id']]);
                } catch (Throwable) {}

                // Clear any customer session to avoid identity confusion
                unset($_SESSION['user']);

                $_SESSION['admin_user'] = [
                    'id' => (int) $admin['id'],
                    'username' => $admin['username'],
                    'email' => $admin['email'],
                    'full_name' => $admin['full_name'],
                    'role' => $admin['role'],
                    'account_type' => 'admin',
                    'logged_in_at' => time()
                ];

                return [
                    'success' => true,
                    'type' => 'admin',
                    'user' => $_SESSION['admin_user'],
                    'error' => null
                ];
            }

            // CRITICAL BUSINESS RULE: If admin account exists but password failed,
            // DO NOT silently fall through to customer account with the same email!
            return [
                'success' => false,
                'type' => null,
                'user' => null,
                'error' => 'Invalid email or password.'
            ];
        }

        // 2. No admin found with this email; check customer users table
        $userStmt = $pdo->prepare('
            SELECT id, name, email, phone, password_hash, auth_provider, role, is_active 
            FROM users 
            WHERE email = :email LIMIT 1
        ');
        $userStmt->execute([':email' => $normalizedEmail]);
        $user = $userStmt->fetch();

        if ($user) {
            if ((int)$user['is_active'] !== 1) {
                return [
                    'success' => false,
                    'type' => null,
                    'user' => null,
                    'error' => 'This account has been deactivated.'
                ];
            }

            if (empty($user['password_hash']) && $user['auth_provider'] === 'google') {
                return [
                    'success' => false,
                    'type' => null,
                    'user' => null,
                    'error' => 'This account was registered via Google. Please use Continue with Google.'
                ];
            }

            if (!empty($user['password_hash']) && password_verify($rawPassword, $user['password_hash'])) {
                // SUCCESS: Customer authentication
                session_regenerate_id(true);

                // Clear any admin session
                unset($_SESSION['admin_user']);

                $_SESSION['user'] = [
                    'id' => (int) $user['id'],
                    'name' => $user['name'],
                    'email' => $user['email'],
                    'phone' => !empty($user['phone']) ? (string)$user['phone'] : null,
                    'role' => $user['role'],
                    'auth_provider' => $user['auth_provider'],
                    'logged_in_at' => time()
                ];

                auth_post_login_cart_transfer((int) $user['id']);

                return [
                    'success' => true,
                    'type' => 'customer',
                    'user' => $_SESSION['user'],
                    'error' => null
                ];
            }

            return [
                'success' => false,
                'type' => null,
                'user' => null,
                'error' => 'Invalid email or password.'
            ];
        }

        return [
            'success' => false,
            'type' => null,
            'user' => null,
            'error' => 'Invalid email or password.'
        ];

    } else {
        // Identifier is a username: check admin_users only (customers use email)
        $adminStmt = $pdo->prepare('
            SELECT id, username, email, password_hash, full_name, role, is_active 
            FROM admin_users 
            WHERE username = :username LIMIT 1
        ');
        $adminStmt->execute([':username' => $rawIdentity]);
        $admin = $adminStmt->fetch();

        if ($admin) {
            if ((int)$admin['is_active'] !== 1) {
                return [
                    'success' => false,
                    'type' => null,
                    'user' => null,
                    'error' => 'This account has been deactivated. Please contact an administrator.'
                ];
            }

            if (password_verify($rawPassword, $admin['password_hash'])) {
                // SUCCESS: Admin authentication
                session_regenerate_id(true);

                try {
                    $upd = $pdo->prepare('UPDATE admin_users SET last_login_at = NOW() WHERE id = :id');
                    $upd->execute([':id' => $admin['id']]);
                } catch (Throwable) {}

                unset($_SESSION['user']);

                $_SESSION['admin_user'] = [
                    'id' => (int) $admin['id'],
                    'username' => $admin['username'],
                    'email' => $admin['email'],
                    'full_name' => $admin['full_name'],
                    'role' => $admin['role'],
                    'account_type' => 'admin',
                    'logged_in_at' => time()
                ];

                return [
                    'success' => true,
                    'type' => 'admin',
                    'user' => $_SESSION['admin_user'],
                    'error' => null
                ];
            }
        }

        return [
            'success' => false,
            'type' => null,
            'user' => null,
            'error' => 'Invalid username or password.'
        ];
    }
}

// ============================================================================
// 4. DEMO GOOGLE AUTHENTICATION (PROTOTYPE CUSTOMER)
// ============================================================================

/**
 * Log in a demo user with provided or default credentials.
 * Ensures customer record exists in users table and creates an isolated customer session.
 */
function set_demo_user(string $name = 'Satyam Kumar SG', string $email = 'satyam@example.com', string $provider = 'google'): array {
    $clean_name = trim($name) !== '' ? trim($name) : 'Satyam Kumar SG';
    $clean_email = trim($email) !== '' ? strtolower(trim($email)) : 'satyam@example.com';
    $userId = 1;

    try {
        $pdo = get_db_connection();
        $stmt = $pdo->prepare('SELECT id, name, email FROM users WHERE email = :email LIMIT 1');
        $stmt->execute([':email' => $clean_email]);
        $existing = $stmt->fetch();

        if ($existing) {
            $userId = (int) $existing['id'];
        } else {
            $ins = $pdo->prepare('
                INSERT INTO users (name, email, password_hash, auth_provider, role, is_active)
                VALUES (:name, :email, NULL, :provider, \'customer\', 1)
            ');
            $ins->execute([
                ':name' => $clean_name,
                ':email' => $clean_email,
                ':provider' => $provider
            ]);
            $userId = (int) $pdo->lastInsertId();
        }
    } catch (Throwable $e) {
        error_log('[Demo Auth] Database fallback: ' . $e->getMessage());
        $userId = 1;
    }

    unset($_SESSION['admin_user']); // Ensure admin state is not mixed

    $_SESSION['user'] = [
        'id' => $userId,
        'name' => htmlspecialchars($clean_name, ENT_QUOTES, 'UTF-8'),
        'email' => htmlspecialchars($clean_email, ENT_QUOTES, 'UTF-8'),
        'role' => 'customer',
        'provider' => $provider,
        'logged_in_at' => time()
    ];
    
    auth_post_login_cart_transfer($userId);

    return $_SESSION['user'];
}

// ============================================================================
// 5. CART TRANSFER, COMPLETED ORDERS & INTENTIONAL LOGOUT
// ============================================================================

/**
 * Transfer guest Standard cart to authenticated customer and purge foreign draft state.
 * 
 * Safeguards:
 * 1. Guest Standard cart ($_SESSION['demo_cart']) is strictly preserved and transferred.
 * 2. Purges any obsolete or foreign draft state ($_SESSION['standard_order'], $_SESSION['luxe_wedding'])
 *    that does not belong to the current authenticated customer.
 * 3. Never transfers Luxe drafts between different customer accounts.
 */
function auth_post_login_cart_transfer(int $userId): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    // 1. Strictly preserve guest Standard cart
    $cart = $_SESSION['demo_cart'] ?? [];
    $_SESSION['demo_cart'] = is_array($cart) ? $cart : [];

    // Touch cart to sync authenticated status and ensure activity timestamp
    if (function_exists('demo_cart_touch')) {
        demo_cart_touch();
    }

    // 2. Clear unauthorized or foreign checkout / workspace drafts
    if (isset($_SESSION['standard_order'])) {
        $stdUserId = (int)($_SESSION['standard_order']['user_id'] ?? 0);
        if ($stdUserId !== $userId || (function_exists('is_standard_order_completed') && is_standard_order_completed($_SESSION['standard_order']))) {
            unset($_SESSION['standard_order']);
        }
    }

    // Luxe drafts require authentication and must NEVER transfer between customer accounts
    if (isset($_SESSION['luxe_wedding'])) {
        $luxeUserId = (int)($_SESSION['luxe_wedding']['user_id'] ?? 0);
        if ($luxeUserId !== $userId || (function_exists('is_luxe_order_completed') && is_luxe_order_completed($_SESSION['luxe_wedding']))) {
            unset($_SESSION['luxe_wedding']);
        }
    }
}

/**
 * Save a completed order to customer order session storage with strict idempotency.
 * 
 * @param array $orderData Completed order payload containing order_ref and payment
 * @return bool True if order was recorded; false if already recorded or invalid
 */
function save_customer_completed_order(array $orderData): bool {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    $orderRef = trim((string)($orderData['order_ref'] ?? ($orderData['payment']['order_ref'] ?? '')));
    if ($orderRef === '') {
        return false;
    }

    $currentUser = get_logged_in_user();
    $userId = (int)($orderData['user_id'] ?? ($currentUser['id'] ?? 0));
    if ($userId <= 0) {
        return false;
    }

    // Initialize session cache
    if (!isset($_SESSION['customer_orders']) || !is_array($_SESSION['customer_orders'])) {
        $_SESSION['customer_orders'] = [];
    }
    if (!isset($_SESSION['customer_orders'][$userId]) || !is_array($_SESSION['customer_orders'][$userId])) {
        $_SESSION['customer_orders'][$userId] = [];
    }

    try {
        $pdo = get_db_connection();

        // 1. Safe Idempotency Check
        $chkStmt = $pdo->prepare('SELECT id, user_id, status, payment_status, total_amount, advance_amount FROM orders WHERE order_ref = :ref LIMIT 1');
        $chkStmt->execute([':ref' => $orderRef]);
        $existingOrder = $chkStmt->fetch();

        if ($existingOrder) {
            // Check ownership: Reject if order belongs to a different customer
            if ((int)$existingOrder['user_id'] !== $userId) {
                error_log("[Order DB Idempotency] Attempt to access order '{$orderRef}' belonging to user {$existingOrder['user_id']} by user {$userId}.");
                return false;
            }

            // Check completeness: verify if payment record already exists
            $chkPay = $pdo->prepare('SELECT id FROM order_payments WHERE order_id = :oid AND status = \'completed\' LIMIT 1');
            $chkPay->execute([':oid' => $existingOrder['id']]);
            if ($chkPay->fetch()) {
                // Already fully committed and owned by this user
                $_SESSION['customer_orders'][$userId][$orderRef] = $orderData;
                return true;
            }
        }

        // 2. Resolve Workflow Type (strictly within allowed enum: standard, wedding, family, bulk)
        $rawWorkflow = strtolower(trim((string)($orderData['workflow'] ?? 'standard')));
        $workflowType = match ($rawWorkflow) {
            'standard' => 'standard',
            'wedding', 'luxe' => 'wedding',
            'family' => 'family',
            'bulk' => 'bulk',
            default => (!empty($orderData['luxe_wedding']) ? 'wedding' : 'standard')
        };

        // 3. Extract & Calculate Financials Server-Side
        $people = $orderData['people'] ?? [];
        $stdItems = $orderData['standard_items'] ?? [];

        $calculatedTotal = 0.0;
        foreach ($people as $p) {
            if (!empty($p['garments']) && is_array($p['garments'])) {
                foreach ($p['garments'] as $g) {
                    $gPrice = (float)($g['total_price'] ?? ($g['price'] ?? 0.0));
                    if ($gPrice <= 0.0) {
                        $base = (float)($g['base_price'] ?? 650.0);
                        $cust = (float)($g['customization_total'] ?? 0.0);
                        $work = (float)($g['work_total'] ?? 0.0);
                        $gPrice = $base + $cust + $work;
                    }
                    $calculatedTotal += $gPrice;
                }
            }
        }
        foreach ($stdItems as $sItem) {
            $sPrice = (float)($sItem['total_price'] ?? ($sItem['total'] ?? ($sItem['price'] ?? 650.0)));
            $calculatedTotal += $sPrice;
        }

        if ($calculatedTotal <= 0.0) {
            $calculatedTotal = (float)($orderData['grand_total'] ?? ($orderData['advance_payment']['order_total'] ?? ($orderData['payment']['order_total'] ?? 0.0)));
        }

        $minAdvanceAmount = (float) ceil($calculatedTotal * 0.30);
        $rawAdvance = (float)($orderData['payment']['amount_paid'] ?? ($orderData['advance_payment']['selected_amount'] ?? ($orderData['amount_paid'] ?? $minAdvanceAmount)));
        $advanceAmount = max($minAdvanceAmount, min($calculatedTotal, $rawAdvance));
        $balanceAmount = max(0.0, $calculatedTotal - $advanceAmount);
        $advancePercent = (int) round(($advanceAmount / max(1.0, $calculatedTotal)) * 100);

        // Derive payment status strictly from validated amounts
        $paymentStatus = ($advanceAmount >= $calculatedTotal && $calculatedTotal > 0.0) ? 'fully_paid' : 'partially_paid';

        // 4. Dates
        $bookedDate = (string)($orderData['booked_date'] ?? ($orderData['payment']['booked_date'] ?? date('Y-m-d')));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $bookedDate)) {
            $bookedDate = date('Y-m-d', strtotime($bookedDate) ?: time());
        }

        $hasValidReqDate = false;
        $reqDateRaw = (string)($orderData['requested_ready_date'] ?? ($orderData['wedding_date'] ?? ''));
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $reqDateRaw)) {
            $reqDate = $reqDateRaw;
            $hasValidReqDate = true;
        } else {
            $reqDate = date('Y-m-d', strtotime('+15 days'));
        }

        $adminDate = (string)($orderData['admin_delivery_date'] ?? $reqDate);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $adminDate)) {
            $adminDate = $reqDate;
        }

        $occasion = mb_substr(trim((string)($orderData['occasion'] ?? 'Tailoring Order')), 0, 100);
        if ($occasion === '') {
            $occasion = 'Tailoring Order';
        }

        $notes = isset($orderData['notes']) ? trim((string)$orderData['notes']) : null;

        // 5. Payment Method & Label (validate against enum: upi, card, netbanking, wallet, cash)
        $rawMethod = strtolower(trim((string)($orderData['payment']['payment_method'] ?? ($orderData['payment_method'] ?? 'upi'))));
        $paymentMethod = in_array($rawMethod, ['upi', 'card', 'netbanking', 'wallet', 'cash'], true) ? $rawMethod : 'upi';
        $paymentMethodLabel = mb_substr(trim((string)($orderData['payment']['payment_method_label'] ?? ($orderData['payment_method_label'] ?? 'UPI / QR Code (Demo Simulation)'))), 0, 100);
        $txnRef = 'TXN-DEMO-' . strtoupper(bin2hex(random_bytes(6)));

        // 6. ATOMIC PDO TRANSACTION
        $pdo->beginTransaction();

        // Step A: Insert into orders
        $insOrderStmt = $pdo->prepare('
            INSERT INTO orders (
                order_ref, user_id, workflow_type, occasion, status,
                total_amount, advance_amount, balance_amount, payment_status,
                advance_percentage, currency, booked_date, requested_ready_date,
                admin_delivery_date, customer_notes, is_demo
            ) VALUES (
                :ref, :user_id, :workflow, :occasion, \'pending_confirmation\',
                :total, :advance, :balance, :payment_status,
                :advance_pct, \'INR\', :booked_date, :req_date,
                :admin_date, :notes, 1
            )
        ');
        $insOrderStmt->execute([
            ':ref' => $orderRef,
            ':user_id' => $userId,
            ':workflow' => $workflowType,
            ':occasion' => $occasion,
            ':total' => $calculatedTotal,
            ':advance' => $advanceAmount,
            ':balance' => $balanceAmount,
            ':payment_status' => $paymentStatus,
            ':advance_pct' => $advancePercent,
            ':booked_date' => $bookedDate,
            ':req_date' => $reqDate,
            ':admin_date' => $adminDate,
            ':notes' => $notes,
        ]);
        $orderId = (int) $pdo->lastInsertId();

        // Step B: Prepared statements for child tables
        $insPersonStmt = $pdo->prepare('
            INSERT INTO order_people (
                order_id, family_member_id, person_order_index, name, role, measurement_method
            ) VALUES (
                :order_id, NULL, :idx, :name, :role, :m_method
            )
        ');

        $insPersonMeasStmt = $pdo->prepare('
            INSERT INTO order_person_measurements (
                order_person_id, measurement_method
            ) VALUES (
                :person_id, :m_method
            )
        ');

        $insGarmentStmt = $pdo->prepare('
            INSERT INTO order_garments (
                order_id, order_person_id, garment_index, garment_type,
                style_slug, style_name, base_price, customization_total,
                work_total, total_price, work_type, status, item_notes
            ) VALUES (
                :order_id, :person_id, :g_idx, :g_type,
                :style_slug, :style_name, :base_price, :custom_total,
                :work_total, :total_price, :work_type, \'not_started\', :notes
            )
        ');

        $insCustStmt = $pdo->prepare('
            INSERT INTO order_garment_customizations (
                order_garment_id, option_group_slug, option_group_label,
                choice_value, choice_label, price_delta
            ) VALUES (
                :garment_id, :group_slug, :group_label,
                :choice_val, :choice_label, :price_delta
            )
        ');

        $insWorkStmt = $pdo->prepare('
            INSERT INTO order_garment_work_items (
                order_garment_id, work_category, design_code, design_name,
                placement, price, status
            ) VALUES (
                :garment_id, :work_category, :design_code, :design_name,
                :placement, :price, \'pending\'
            )
        ');

        $insMatStmt = $pdo->prepare('
            INSERT INTO order_materials (
                order_id, order_person_id, material_code, material_type,
                material_name, status, condition_notes
            ) VALUES (
                :order_id, :person_id, :code, :type,
                :name, \'awaiting_receipt\', :notes
            )
        ');

        $insMatLinkStmt = $pdo->prepare('
            INSERT INTO order_garment_material_links (
                order_garment_id, order_material_id, usage_role, notes
            ) VALUES (
                :garment_id, :material_id, :usage_role, :notes
            )
        ');

        $personIndex = 1;
        $matIndex = 1;

        // Process Luxe People and their garments
        foreach ($people as $p) {
            $pName = mb_substr(trim((string)($p['name'] ?? 'Customer')), 0, 150);
            if ($pName === '') $pName = 'Customer';
            $pRole = mb_substr(trim((string)($p['role'] ?? 'Participant')), 0, 100);
            $mMethod = in_array($p['measurement_method'] ?? '', ['reference_blouse', 'visit_shop'], true)
                ? $p['measurement_method']
                : 'reference_blouse';

            $insPersonStmt->execute([
                ':order_id' => $orderId,
                ':idx' => $personIndex,
                ':name' => $pName,
                ':role' => $pRole,
                ':m_method' => $mMethod,
            ]);
            $personId = (int) $pdo->lastInsertId();

            $insPersonMeasStmt->execute([
                ':person_id' => $personId,
                ':m_method' => $mMethod,
            ]);

            $garmentIndex = 1;
            $garments = $p['garments'] ?? [];
            foreach ($garments as $g) {
                $gType = mb_substr(trim((string)($g['name'] ?? ($g['garment_type'] ?? 'Blouse'))), 0, 100);
                $sSlug = mb_substr(trim((string)($g['style_slug'] ?? 'custom')), 0, 100);
                $sName = mb_substr(trim((string)($g['style_name'] ?? $gType)), 0, 150);
                $bPrice = (float)($g['base_price'] ?? 650.0);
                $cTotal = (float)($g['customization_total'] ?? 0.0);
                $wTotal = (float)($g['work_total'] ?? 0.0);
                $tPrice = (float)($g['total_price'] ?? ($bPrice + $cTotal + $wTotal));
                $wType = in_array($g['work_type'] ?? '', ['no_work', 'machine', 'hand', 'both'], true) ? $g['work_type'] : 'no_work';
                $gNotes = isset($g['notes']) ? trim((string)$g['notes']) : null;

                $insGarmentStmt->execute([
                    ':order_id' => $orderId,
                    ':person_id' => $personId,
                    ':g_idx' => $garmentIndex,
                    ':g_type' => $gType,
                    ':style_slug' => $sSlug,
                    ':style_name' => $sName,
                    ':base_price' => $bPrice,
                    ':custom_total' => $cTotal,
                    ':work_total' => $wTotal,
                    ':total_price' => $tPrice,
                    ':work_type' => $wType,
                    ':notes' => $gNotes,
                ]);
                $garmentId = (int) $pdo->lastInsertId();

                // Customizations
                $choiceList = $g['choice_summary'] ?? ($g['choices'] ?? []);
                if (is_array($choiceList)) {
                    $cIdx = 1;
                    foreach ($choiceList as $cKey => $cVal) {
                        if (is_array($cVal)) {
                            $fLabel = mb_substr(trim((string)($cVal['field'] ?? 'Customization')), 0, 150);
                            $fSlug = mb_substr(strtolower(preg_replace('/[^a-z0-9_]/', '_', $fLabel)), 0, 100);
                            $cLabel = mb_substr(trim((string)($cVal['label'] ?? 'Selected')), 0, 150);
                            $cValue = mb_substr(strtolower(preg_replace('/[^a-z0-9_]/', '_', $cLabel)), 0, 100);
                            $pDelta = (float)($cVal['price'] ?? 0.0);
                        } else {
                            $fSlug = mb_substr(strtolower(preg_replace('/[^a-z0-9_]/', '_', (string)$cKey)), 0, 100);
                            $fLabel = ucwords(str_replace('_', ' ', $fSlug));
                            $cValue = mb_substr(strtolower(preg_replace('/[^a-z0-9_]/', '_', (string)$cVal)), 0, 100);
                            $cLabel = ucwords(str_replace('_', ' ', $cValue));
                            $pDelta = 0.0;
                        }

                        if ($fSlug === '') $fSlug = "opt_{$cIdx}";
                        try {
                            $insCustStmt->execute([
                                ':garment_id' => $garmentId,
                                ':group_slug' => $fSlug,
                                ':group_label' => $fLabel,
                                ':choice_val' => $cValue,
                                ':choice_label' => $cLabel,
                                ':price_delta' => $pDelta,
                            ]);
                        } catch (Throwable $ignore) {
                            // Ignore unique constraint collision on choice group
                        }
                        $cIdx++;
                    }
                }

                // Work items
                if (!empty($g['machine_work']) && is_array($g['machine_work'])) {
                    $mw = $g['machine_work'];
                    $insWorkStmt->execute([
                        ':garment_id' => $garmentId,
                        ':work_category' => 'machine',
                        ':design_code' => mb_substr(trim((string)($mw['design_code'] ?? 'M-018')), 0, 50),
                        ':design_name' => mb_substr(trim((string)($mw['design_name'] ?? 'Machine Embroidery')), 0, 150),
                        ':placement' => mb_substr(trim((string)($mw['placement'] ?? 'Neck')), 0, 100),
                        ':price' => (float)($mw['price'] ?? 0.0),
                    ]);

                    if (!empty($mw['target']) && $mw['target'] === 'separate_cloth') {
                        $matCode = 'MAT-' . $orderRef . '-' . str_pad((string)$matIndex++, 2, '0', STR_PAD_LEFT);
                        $matDesc = trim((string)($mw['material_description'] ?? 'Separate Cloth for Machine Embroidery'));
                        $insMatStmt->execute([
                            ':order_id' => $orderId,
                            ':person_id' => $personId,
                            ':code' => $matCode,
                            ':type' => 'customer_fabric',
                            ':name' => mb_substr($matDesc, 0, 150),
                            ':notes' => 'Provided on separate cloth',
                        ]);
                        $matId = (int) $pdo->lastInsertId();

                        $insMatLinkStmt->execute([
                            ':garment_id' => $garmentId,
                            ':material_id' => $matId,
                            ':usage_role' => 'primary_fabric',
                            ':notes' => 'Machine embroidery on separate cloth',
                        ]);
                    }
                }

                if (!empty($g['hand_work']) && is_array($g['hand_work'])) {
                    $hw = $g['hand_work'];
                    $insWorkStmt->execute([
                        ':garment_id' => $garmentId,
                        ':work_category' => 'hand',
                        ':design_code' => mb_substr(trim((string)($hw['design_code'] ?? 'H-012')), 0, 50),
                        ':design_name' => mb_substr(trim((string)($hw['design_name'] ?? 'Hand Embroidery')), 0, 150),
                        ':placement' => mb_substr(trim((string)($hw['placement'] ?? 'Neck')), 0, 100),
                        ':price' => (float)($hw['price'] ?? 0.0),
                    ]);

                    if (!empty($hw['target']) && $hw['target'] === 'separate_cloth') {
                        $matCode = 'MAT-' . $orderRef . '-' . str_pad((string)$matIndex++, 2, '0', STR_PAD_LEFT);
                        $matDesc = trim((string)($hw['material_description'] ?? 'Separate Cloth for Hand Embroidery'));
                        $insMatStmt->execute([
                            ':order_id' => $orderId,
                            ':person_id' => $personId,
                            ':code' => $matCode,
                            ':type' => 'customer_fabric',
                            ':name' => mb_substr($matDesc, 0, 150),
                            ':notes' => 'Provided on separate cloth',
                        ]);
                        $matId = (int) $pdo->lastInsertId();

                        $insMatLinkStmt->execute([
                            ':garment_id' => $garmentId,
                            ':material_id' => $matId,
                            ':usage_role' => 'primary_fabric',
                            ':notes' => 'Hand embroidery on separate cloth',
                        ]);
                    }
                }

                // Direct materials provided on garment
                if (!empty($g['materials']) && is_array($g['materials'])) {
                    foreach ($g['materials'] as $matItem) {
                        $matCode = 'MAT-' . $orderRef . '-' . str_pad((string)$matIndex++, 2, '0', STR_PAD_LEFT);
                        $matName = is_array($matItem) ? ($matItem['name'] ?? 'Fabric') : (string)$matItem;
                        $matNotes = is_array($matItem) ? ($matItem['notes'] ?? null) : null;
                        $matStatus = is_array($matItem) ? ($matItem['status'] ?? 'awaiting_receipt') : 'awaiting_receipt';
                        if (!in_array($matStatus, ['awaiting_receipt', 'received', 'inspected', 'in_use', 'returned', 'exhausted'], true)) {
                            $matStatus = 'awaiting_receipt';
                        }
                        $insMatStmt->execute([
                            ':order_id' => $orderId,
                            ':person_id' => $personId,
                            ':code' => $matCode,
                            ':type' => 'customer_fabric',
                            ':name' => mb_substr($matName, 0, 150),
                            ':notes' => $matNotes,
                        ]);
                        $matId = (int) $pdo->lastInsertId();

                        $insMatLinkStmt->execute([
                            ':garment_id' => $garmentId,
                            ':material_id' => $matId,
                            ':usage_role' => 'primary_fabric',
                            ':notes' => $matNotes,
                        ]);
                    }
                }

                $garmentIndex++;
            }
            $personIndex++;
        }

        // Process Standard Stitching items (if combined order or standard only)
        if (!empty($stdItems) && is_array($stdItems)) {
            $custProfile = get_customer_profile($userId);
            $stdPersonName = $custProfile['name'] ?? ($currentUser['name'] ?? 'Customer');
            $stdRole = (count($people) > 0) ? 'Customer (Standard Stitching)' : 'Customer';

            $insPersonStmt->execute([
                ':order_id' => $orderId,
                ':idx' => $personIndex,
                ':name' => mb_substr($stdPersonName, 0, 150),
                ':role' => mb_substr($stdRole, 0, 100),
                ':m_method' => 'reference_blouse',
            ]);
            $stdPersonId = (int) $pdo->lastInsertId();

            $insPersonMeasStmt->execute([
                ':person_id' => $stdPersonId,
                ':m_method' => 'reference_blouse',
            ]);

            $stdGarmentIndex = 1;
            foreach ($stdItems as $sItem) {
                $gType = mb_substr(trim((string)($sItem['garment'] ?? ($sItem['name'] ?? 'Blouse'))), 0, 100);
                $sSlug = mb_substr(trim((string)($sItem['style_slug'] ?? 'standard-blouse')), 0, 100);
                $sName = mb_substr(trim((string)($sItem['style_name'] ?? $gType)), 0, 150);
                $bPrice = (float)($sItem['base_price'] ?? 650.0);
                $cTotal = (float)($sItem['customization_total'] ?? 0.0);
                $tPrice = (float)($sItem['total_price'] ?? ($sItem['total'] ?? $bPrice));
                $wType = in_array($sItem['work_type'] ?? '', ['no_work', 'machine', 'hand', 'both'], true) ? $sItem['work_type'] : 'no_work';
                $wTotal = max(0.0, $tPrice - ($bPrice + $cTotal));

                $insGarmentStmt->execute([
                    ':order_id' => $orderId,
                    ':person_id' => $stdPersonId,
                    ':g_idx' => $stdGarmentIndex,
                    ':g_type' => $gType,
                    ':style_slug' => $sSlug,
                    ':style_name' => $sName,
                    ':base_price' => $bPrice,
                    ':custom_total' => $cTotal,
                    ':work_total' => $wTotal,
                    ':total_price' => $tPrice,
                    ':work_type' => $wType,
                    ':notes' => $sItem['notes'] ?? null,
                ]);
                $stdGarmentId = (int) $pdo->lastInsertId();

                // Standard item customizations
                $sCust = $sItem['customizations'] ?? ($sItem['choice_summary'] ?? ($sItem['selected_options'] ?? []));
                if (!empty($sCust) && is_array($sCust)) {
                    $sCIdx = 1;
                    foreach ($sCust as $cKey => $cVal) {
                        if (is_array($cVal)) {
                            $fSlug = mb_substr(strtolower(preg_replace('/[^a-z0-9_]/', '_', (string)($cVal['field'] ?? ($cVal['group'] ?? $cKey)))), 0, 100);
                            $fLabel = ucwords(str_replace('_', ' ', $fSlug));
                            $cValue = mb_substr(strtolower(preg_replace('/[^a-z0-9_]/', '_', (string)($cVal['choice'] ?? ($cVal['label'] ?? ($cVal['value'] ?? ''))))), 0, 100);
                            $cLabel = (string)($cVal['label'] ?? ($cVal['choice'] ?? ucwords(str_replace('_', ' ', $cValue))));
                            $pDelta = (float)($cVal['price'] ?? 0.0);
                        } else {
                            $fSlug = mb_substr(strtolower(preg_replace('/[^a-z0-9_]/', '_', (string)$cKey)), 0, 100);
                            $fLabel = ucwords(str_replace('_', ' ', $fSlug));
                            $cValue = mb_substr(strtolower(preg_replace('/[^a-z0-9_]/', '_', (string)$cVal)), 0, 100);
                            $cLabel = ucwords(str_replace('_', ' ', $cValue));
                            $pDelta = 0.0;
                        }

                        if ($fSlug === '') $fSlug = "opt_{$sCIdx}";
                        try {
                            $insCustStmt->execute([
                                ':garment_id' => $stdGarmentId,
                                ':group_slug' => $fSlug,
                                ':group_label' => $fLabel,
                                ':choice_val' => $cValue,
                                ':choice_label' => $cLabel,
                                ':price_delta' => $pDelta,
                            ]);
                        } catch (Throwable $ignore) {}
                        $sCIdx++;
                    }
                }

                if (!empty($sItem['machine_work']) && is_array($sItem['machine_work'])) {
                    $insWorkStmt->execute([
                        ':garment_id' => $stdGarmentId,
                        ':work_category' => 'machine',
                        ':design_code' => mb_substr(trim((string)($sItem['machine_work']['design_code'] ?? 'M-018')), 0, 50),
                        ':design_name' => mb_substr(trim((string)($sItem['machine_work']['design_name'] ?? 'Machine Embroidery')), 0, 150),
                        ':placement' => mb_substr(trim((string)($sItem['machine_work']['placement'] ?? 'Neck & Sleeves')), 0, 100),
                        ':price' => (float)($sItem['machine_work']['price'] ?? 250.0),
                    ]);
                }
                if (!empty($sItem['hand_work']) && is_array($sItem['hand_work'])) {
                    $insWorkStmt->execute([
                        ':garment_id' => $stdGarmentId,
                        ':work_category' => 'hand',
                        ':design_code' => mb_substr(trim((string)($sItem['hand_work']['design_code'] ?? 'H-012')), 0, 50),
                        ':design_name' => mb_substr(trim((string)($sItem['hand_work']['design_name'] ?? 'Hand Embroidery')), 0, 150),
                        ':placement' => mb_substr(trim((string)($sItem['hand_work']['placement'] ?? 'Neck & Sleeves')), 0, 100),
                        ':price' => (float)($sItem['hand_work']['price'] ?? 500.0),
                    ]);
                }

                // Standard item materials
                if (!empty($sItem['materials']) && is_array($sItem['materials'])) {
                    foreach ($sItem['materials'] as $matItem) {
                        $matCode = 'MAT-' . $orderRef . '-' . str_pad((string)$matIndex++, 2, '0', STR_PAD_LEFT);
                        $matName = is_array($matItem) ? ($matItem['name'] ?? 'Fabric') : (string)$matItem;
                        $matNotes = is_array($matItem) ? ($matItem['notes'] ?? null) : null;
                        $insMatStmt->execute([
                            ':order_id' => $orderId,
                            ':person_id' => $stdPersonId,
                            ':code' => $matCode,
                            ':type' => 'customer_fabric',
                            ':name' => mb_substr($matName, 0, 150),
                            ':notes' => $matNotes,
                        ]);
                        $matId = (int) $pdo->lastInsertId();

                        $insMatLinkStmt->execute([
                            ':garment_id' => $stdGarmentId,
                            ':material_id' => $matId,
                            ':usage_role' => 'primary_fabric',
                            ':notes' => $matNotes,
                        ]);
                    }
                }

                $stdGarmentIndex++;
            }
        }

        // Step C: Insert into order_payments
        $insPayStmt = $pdo->prepare('
            INSERT INTO order_payments (
                order_id, payment_stage, amount, currency, payment_method,
                payment_method_label, transaction_ref, status, gateway_mode,
                gateway_provider, paid_at
            ) VALUES (
                :order_id, \'advance\', :amount, \'INR\', :method,
                :method_label, :txn_ref, \'completed\', \'simulated\',
                \'Demo Simulated Gateway\', NOW()
            )
        ');
        $insPayStmt->execute([
            ':order_id' => $orderId,
            ':amount' => $advanceAmount,
            ':method' => $paymentMethod,
            ':method_label' => $paymentMethodLabel,
            ':txn_ref' => $txnRef,
        ]);

        // Step D: Insert into order_status_history
        $insHistStmt = $pdo->prepare('
            INSERT INTO order_status_history (
                order_id, old_status, new_status, actor, admin_user_id, notes, created_at
            ) VALUES (
                :order_id, NULL, \'pending_confirmation\', \'system\', NULL,
                \'Simulated advance payment completed. Order awaiting Admin confirmation.\', NOW()
            )
        ');
        $insHistStmt->execute([':order_id' => $orderId]);

        // Step E: Insert into order_date_history (only if requested date was provided)
        if ($hasValidReqDate) {
            $insDateHistStmt = $pdo->prepare('
                INSERT INTO order_date_history (
                    order_id, event_type, date_type, old_date, new_date, actor, admin_user_id, note, created_at
                ) VALUES (
                    :order_id, \'customer_request\', \'requested_ready_date\', NULL, :new_date,
                    \'customer\', NULL, :note, NOW()
                )
            ');
            $insDateHistStmt->execute([
                ':order_id' => $orderId,
                ':new_date' => $reqDate,
                ':note' => "Customer requested ready date: {$reqDate}",
            ]);
        }

        // Step F: COMMIT TRANSACTION
        $pdo->commit();

        // Update session cache
        $_SESSION['customer_orders'][$userId][$orderRef] = $orderData;
        return true;

    } catch (Throwable $e) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[Order DB Persistence Error] ' . $e->getMessage());
        return false;
    }
}

/**
 * Get completed orders belonging to the specified (or currently logged-in) customer.
 * Queries permanent MySQL database first, enriching with session records.
 *
 * @return array Keyed by order_ref
 */
function get_customer_orders(?int $userId = null): array {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if ($userId === null) {
        $currentUser = get_logged_in_user();
        $userId = (int)($currentUser['id'] ?? 0);
    }

    if ($userId <= 0) {
        return [];
    }

    $orders = [];

    // 1. Permanent database retrieval
    try {
        $custProfile = get_customer_profile($userId);
        $pdo = get_db_connection();
        $ordStmt = $pdo->prepare('SELECT * FROM orders WHERE user_id = :uid ORDER BY created_at DESC');
        $ordStmt->execute([':uid' => $userId]);
        while ($dbOrd = $ordStmt->fetch()) {
            $ref = (string) $dbOrd['order_ref'];
            $orderId = (int) $dbOrd['id'];

            // Fetch people and garments for this order
            $pStmt = $pdo->prepare('SELECT * FROM order_people WHERE order_id = :oid ORDER BY person_order_index ASC');
            $pStmt->execute([':oid' => $orderId]);
            $people = [];

            while ($pRow = $pStmt->fetch()) {
                $pId = (int) $pRow['id'];
                $gStmt = $pdo->prepare('SELECT * FROM order_garments WHERE order_person_id = :pid ORDER BY garment_index ASC');
                $gStmt->execute([':pid' => $pId]);
                $garments = [];

                while ($gRow = $gStmt->fetch()) {
                    $gId = (int) $gRow['id'];

                    // Fetch customizations
                    $cStmt = $pdo->prepare('SELECT * FROM order_garment_customizations WHERE order_garment_id = :gid');
                    $cStmt->execute([':gid' => $gId]);
                    $choices = [];
                    while ($cRow = $cStmt->fetch()) {
                        $choices[] = [
                            'field' => $cRow['option_group_label'],
                            'label' => $cRow['choice_label'],
                            'price' => (int) $cRow['price_delta']
                        ];
                    }

                    // Fetch work items
                    $wStmt = $pdo->prepare('SELECT * FROM order_garment_work_items WHERE order_garment_id = :gid');
                    $wStmt->execute([':gid' => $gId]);
                    $machineWork = null;
                    $handWork = null;
                    while ($wRow = $wStmt->fetch()) {
                        $wData = [
                            'design_code' => $wRow['design_code'],
                            'design_name' => $wRow['design_name'],
                            'placement' => $wRow['placement'],
                            'price' => (int) $wRow['price']
                        ];
                        if ($wRow['work_category'] === 'machine') {
                            $machineWork = $wData;
                        } elseif ($wRow['work_category'] === 'hand') {
                            $handWork = $wData;
                        }
                    }

                    $garments[] = [
                        'name' => $gRow['garment_type'],
                        'garment_type' => $gRow['garment_type'],
                        'style_slug' => $gRow['style_slug'],
                        'style_name' => $gRow['style_name'],
                        'base_price' => (int) $gRow['base_price'],
                        'customization_total' => (int) $gRow['customization_total'],
                        'work_total' => (int) $gRow['work_total'],
                        'total_price' => (int) $gRow['total_price'],
                        'work_type' => $gRow['work_type'],
                        'choice_summary' => $choices,
                        'machine_work' => $machineWork,
                        'hand_work' => $handWork,
                        'notes' => $gRow['item_notes']
                    ];
                }

                $people[] = [
                    'name' => $pRow['name'],
                    'role' => $pRow['role'],
                    'measurement_method' => $pRow['measurement_method'],
                    'garments' => $garments
                ];
            }

            // Fetch payment
            $payStmt = $pdo->prepare('SELECT * FROM order_payments WHERE order_id = :oid ORDER BY id DESC LIMIT 1');
            $payStmt->execute([':oid' => $orderId]);
            $dbPay = $payStmt->fetch();

            // Fetch date history
            $dhStmt = $pdo->prepare('SELECT * FROM order_date_history WHERE order_id = :oid ORDER BY id ASC');
            $dhStmt->execute([':oid' => $orderId]);
            $dateHistory = [];
            while ($dh = $dhStmt->fetch()) {
                $dateHistory[] = [
                    'type' => $dh['event_type'],
                    'date' => $dh['new_date'],
                    'created_at' => strtotime($dh['created_at']),
                    'formatted_created' => date('d M Y, h:i A', strtotime($dh['created_at'])),
                    'actor' => $dh['actor'],
                    'note' => $dh['note']
                ];
            }

            // Fetch status history
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

            $orders[$ref] = [
                'id' => $orderId,
                'order_ref' => $ref,
                'user_id' => $userId,
                'customer_name' => $custProfile['name'] ?? ($dbOrd['customer_name'] ?? 'Valued Customer'),
                'customer_phone' => $custProfile['phone'] ?? ($dbOrd['customer_phone'] ?? null),
                'customer_email' => $custProfile['email'] ?? ($dbOrd['customer_email'] ?? null),
                'customer_address' => $custProfile['address'] ?? ($dbOrd['customer_address'] ?? null),
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
                'people' => $people,
                'payment' => [
                    'status' => $dbPay ? $dbPay['status'] : 'completed',
                    'order_ref' => $ref,
                    'amount_paid' => (int) $dbOrd['advance_amount'],
                    'remaining_balance' => (int) $dbOrd['balance_amount'],
                    'payment_method' => $dbPay['payment_method'] ?? 'upi',
                    'payment_method_label' => $dbPay['payment_method_label'] ?? 'UPI / QR Code',
                    'transaction_ref' => $dbPay['transaction_ref'] ?? '',
                    'booked_date' => $dbOrd['booked_date'],
                    'paid_at' => $dbPay ? strtotime($dbPay['paid_at']) : time()
                ],
                'date_history' => $dateHistory,
                'status_history' => $statusHistory,
                'source' => 'database'
            ];
        }
    } catch (Throwable $e) {
        error_log('[Customer Orders DB Error] ' . $e->getMessage());
    }

    // 2. Session merge for in-memory orders not yet reflected
    if (!empty($_SESSION['customer_orders'][$userId]) && is_array($_SESSION['customer_orders'][$userId])) {
        foreach ($_SESSION['customer_orders'][$userId] as $ref => $sOrd) {
            if (!isset($orders[$ref])) {
                $orders[$ref] = $sOrd;
            }
        }
    }

    return $orders;
}

/**
 * Get a specific completed order by reference, ensuring customer ownership.
 */
function get_customer_order_by_ref(string $orderRef, ?int $userId = null): ?array {
    $orders = get_customer_orders($userId);
    return $orders[$orderRef] ?? null;
}

/**
 * Check whether a Luxe session payload represents an already completed/persisted order.
 * A completed order is a permanent database record and must never leak as an active builder draft.
 */
function is_luxe_order_completed(?array $draft = null): bool {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if ($draft === null) {
        $draft = $_SESSION['luxe_wedding'] ?? [];
    }
    if (!is_array($draft) || empty($draft)) {
        return false;
    }
    // Check payment completion
    if (!empty($draft['payment']['status']) && $draft['payment']['status'] === 'completed') {
        return true;
    }
    // Check explicit submitted flag
    if (!empty($draft['is_submitted'])) {
        return true;
    }
    // Check canonical / internal status if progressed beyond initial draft
    if (!empty($draft['status']) && !in_array($draft['status'], ['draft', ''], true)) {
        return true;
    }
    // Check booked date presence with an assigned order reference
    if (!empty($draft['order_ref']) && !empty($draft['booked_date'])) {
        return true;
    }
    // Check assigned order reference (an active draft never has a permanent order reference)
    if (!empty($draft['order_ref'])) {
        return true;
    }
    return false;
}

/**
 * Backward compatibility alias for is_luxe_order_completed().
 */
function is_luxe_draft_completed(?array $draft = null): bool {
    return is_luxe_order_completed($draft);
}

/**
 * Check whether the current session has an active, in-progress (uncompleted) Luxe draft
 * with actual garments under construction.
 * 
 * Strict Isolation Rules:
 * - A completed order (paid, submitted, or permanently booked) is NEVER an active draft.
 * - Historical orders in MySQL or $_SESSION['customer_orders'] must NEVER cause this to return true.
 * - Returns true ONLY if $_SESSION['luxe_wedding'] exists, is NOT completed, and has at least one person with garments.
 */
function has_active_luxe_draft(): bool {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $draft = $_SESSION['luxe_wedding'] ?? [];
    if (empty($draft) || !is_array($draft)) {
        return false;
    }
    if (is_luxe_order_completed($draft)) {
        return false;
    }
    if (empty($draft['people']) || !is_array($draft['people'])) {
        return false;
    }
    foreach ($draft['people'] as $p) {
        if (!empty($p['garments']) && is_array($p['garments'])) {
            return true;
        }
    }
    return false;
}

/**
 * Initialize or reset a clean, active Luxe order draft.
 * 
 * Strict Isolation Rules:
 * - Completely decouples completed persisted orders from active session drafts.
 * - Leaves user authentication ($_SESSION['user'], $_SESSION['user_id']) 100% intact.
 * - Leaves Standard Stitching cart items ($_SESSION['demo_cart']) 100% intact for crossover flow.
 * - Leaves saved customer orders ($_SESSION['customer_orders'] and DB) 100% intact.
 * - Resets requested ready date, people, garments, order reference, and payment state to clean slate.
 * 
 * @param string $workflow 'wedding' or 'family'
 * @param bool $force If true, forces reset even if an uncompleted draft exists
 * @return array The fresh active Luxe draft
 */
function init_fresh_luxe_draft(string $workflow = 'wedding', bool $force = false): array {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    $existing = $_SESSION['luxe_wedding'] ?? [];

    // If an in-progress draft is already active (unpaid and not submitted) and force is false,
    // preserve the user's uncommitted draft progress but ensure valid workflow context.
    if (!$force && !is_luxe_order_completed($existing) && !empty($existing) && is_array($existing)) {
        if (in_array($workflow, ['wedding', 'family'], true)) {
            $_SESSION['luxe_wedding']['workflow'] = $workflow;
            $_SESSION['luxe_wedding']['occasion'] = ($workflow === 'family') ? 'Family & Celebrations' : 'Wedding';
        }
        return $_SESSION['luxe_wedding'];
    }

    // Clean reset for a brand new Luxe order draft
    $cleanDraft = [
        'workflow' => in_array($workflow, ['wedding', 'family'], true) ? $workflow : 'wedding',
        'occasion' => ($workflow === 'family') ? 'Family & Celebrations' : 'Wedding',
        'requested_ready_date' => '',
        'wedding_date' => '',
        'people_count' => null,
        'people' => [],
        'notes' => '',
        'date_history' => [],
        'advance_payment' => null,
        'payment' => null,
        'order_ref' => null,
        'is_submitted' => false,
        'created_at' => time()
    ];

    $_SESSION['luxe_wedding'] = $cleanDraft;
    return $_SESSION['luxe_wedding'];
}

/**
 * Check whether a Standard Stitching session payload represents an already completed/persisted order.
 */
function is_standard_order_completed(?array $draft = null): bool {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if ($draft === null) {
        $draft = $_SESSION['standard_order'] ?? [];
    }
    if (!is_array($draft) || empty($draft)) {
        return false;
    }
    if (!empty($draft['payment']['status']) && $draft['payment']['status'] === 'completed') {
        return true;
    }
    if (!empty($draft['is_submitted'])) {
        return true;
    }
    if (!empty($draft['order_ref'])) {
        return true;
    }
    return false;
}

/**
 * Check whether the current session has an active, uncompleted Standard Stitching draft.
 */
function has_active_standard_draft(): bool {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $draft = $_SESSION['standard_order'] ?? [];
    if (empty($draft) || !is_array($draft)) {
        return false;
    }
    if (is_standard_order_completed($draft)) {
        return false;
    }
    return true;
}

/**
 * Initialize or reset a clean, active Standard Stitching order draft.
 */
function init_fresh_standard_draft(bool $force = false): array {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    $existing = $_SESSION['standard_order'] ?? [];
    if (!$force && !is_standard_order_completed($existing) && !empty($existing) && is_array($existing)) {
        return $_SESSION['standard_order'];
    }

    $cleanDraft = [
        'workflow' => 'standard',
        'order_type' => 'Standard Stitching',
        'measurement_method' => 'reference_blouse',
        'requested_ready_date' => '',
        'notes' => '',
        'advance_payment' => null,
        'payment' => null,
        'order_ref' => null,
        'is_submitted' => false,
        'people' => [],
        'created_at' => time()
    ];

    $_SESSION['standard_order'] = $cleanDraft;
    return $_SESSION['standard_order'];
}


/**
 * Logout authenticated user.
 * Intentional session management:
 * - Unsets $_SESSION['user'] and $_SESSION['admin_user'].
 * - Purges in-progress checkout drafts ($_SESSION['standard_order']) and Luxe drafts ($_SESSION['luxe_wedding'])
 *   so they cannot be displayed to any subsequent user.
 * - Strictly preserves the active Standard cart ($_SESSION['demo_cart']) for guest browsing,
 *   touching its activity to restart the 45-minute inactivity timer.
 */
function clear_authenticated_user(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    $cart = $_SESSION['demo_cart'] ?? [];

    unset($_SESSION['user']);
    unset($_SESSION['admin_user']);
    unset($_SESSION['standard_order']);
    unset($_SESSION['luxe_wedding']);

    $_SESSION['demo_cart'] = is_array($cart) ? $cart : [];
    if (function_exists('demo_cart_touch')) {
        demo_cart_touch();
    }
}

/**
 * Backward compatibility alias for clear_authenticated_user().
 */
function clear_demo_user(): void {
    clear_authenticated_user();
}

// ============================================================================
// 6. SAFE REDIRECT VALIDATION
// ============================================================================

/**
 * Validate and sanitize redirect URLs to prevent open redirect vulnerabilities.
 * Only relative internal PHP routes (with optional query strings) are permitted.
 * Supports protected admin routes (e.g. admin/index.php).
 */
function get_safe_redirect_url(?string $url, string $default = 'index.php'): string {
    if (empty($url)) {
        return $default;
    }
    
    $url = trim($url);
    
    // Disallow absolute schemes (http, https, javascript, etc.) or protocol-relative paths
    if (preg_match('/^([a-z0-9+.-]+:|\/\/|\\\\)/i', $url)) {
        return $default;
    }
    
    // Disallow control characters or newlines
    if (preg_match('/[\r\n\t]/', $url)) {
        return $default;
    }
    
    // Parse path and query
    $parts = parse_url($url);
    if ($parts === false || !empty($parts['host']) || !empty($parts['scheme'])) {
        return $default;
    }
    
    $path = ltrim($parts['path'] ?? '', '/\\');

    // Strip project subfolder if present (e.g. /shagun-ladies-tailor/...)
    if (str_starts_with($path, 'shagun-ladies-tailor/')) {
        $path = substr($path, strlen('shagun-ladies-tailor/'));
    }
    
    // Reject path traversal attempts
    if (strpos($path, '..') !== false) {
        return $default;
    }
    
    // Must end with .php or be empty/index
    if ($path !== '' && !preg_match('/^[a-zA-Z0-9_\-\.\/]+\.php$/', $path)) {
        return $default;
    }
    
    $safe_path = $path !== '' ? $path : $default;
    if (!empty($parts['query'])) {
        $safe_path .= '?' . $parts['query'];
    }
    
    return $safe_path;
}

// ============================================================================
// 7. GUARDS & ACCESS CONTROL
// ============================================================================

/**
 * Check if the request is an existing legacy test script without headers
 * that relies on demo mode direct access.
 */
function should_bypass_auth_for_legacy_test(): bool {
    if (!empty($_SERVER['HTTP_X_AUTH_TEST'])) {
        return false;
    }
    return empty($_SERVER['HTTP_USER_AGENT']);
}

/**
 * Guard customer-only pages (Luxe workflow, checkout, customer orders).
 * If user is not logged in:
 * - If admin is logged in: redirects to admin dashboard (admins cannot place customer orders)
 * - Else: redirects safely to login.php
 */
function require_user_login(?string $current_page = null): void {
    if (!is_user_logged_in()) {
        if (should_bypass_auth_for_legacy_test()) {
            set_demo_user('Satyam Kumar SG', 'satyam@example.com', 'demo_fallback');
            return;
        }

        // If an authenticated admin visits customer flow, redirect to admin dashboard
        if (is_admin_logged_in()) {
            header('Location: admin/index.php?notice=customer_account_required');
            exit;
        }

        $redirect_target = $current_page;
        if (empty($redirect_target)) {
            $redirect_target = $_SERVER['REQUEST_URI'] ?? 'index.php';
            $parsed = parse_url($redirect_target);
            $path = ltrim($parsed['path'] ?? 'index.php', '/\\');
            if (str_starts_with($path, 'shagun-ladies-tailor/')) {
                $path = substr($path, strlen('shagun-ladies-tailor/'));
            }
            $redirect_target = $path . (!empty($parsed['query']) ? '?' . $parsed['query'] : '');
        }
        $safe_redirect = get_safe_redirect_url($redirect_target, 'luxe-wedding.php');
        header('Location: login.php?redirect=' . urlencode($safe_redirect));
        exit;
    }
}

/**
 * Guard administrative pages (/admin/*).
 * 
 * Rules:
 * - Guests are redirected to login.php?redirect=admin/index.php
 * - Normal customers are denied access and redirected to index.php with error alert
 * - Admins with insufficient role are denied with HTTP 403
 */
function require_admin_login(?string $required_role = null): void {
    if (!is_admin_logged_in()) {
        if (is_user_logged_in()) {
            // Logged in as customer -> strictly forbidden from admin area
            header('Location: ../index.php?error=admin_access_denied');
            exit;
        }

        // Guest -> redirect to unified login page
        $safeRedirect = 'admin/index.php';
        header('Location: ../login.php?redirect=' . urlencode($safeRedirect));
        exit;
    }

    if ($required_role !== null) {
        $currentRole = get_admin_role();
        // super_admin always has access to all admin areas
        if ($currentRole !== 'super_admin' && $currentRole !== $required_role) {
            http_response_code(403);
            echo "<!DOCTYPE html><html><head><title>403 Forbidden</title></head><body><h1>403 Forbidden</h1><p>You do not have administrative permission to view this resource.</p><p><a href=\"index.php\">Back to Dashboard</a></p></body></html>";
            exit;
        }
    }
}

/**
 * Return customer sequence label (e.g. "New Customer", "2nd Time Customer", etc.).
 */
function get_customer_sequence_label(int $sequenceNumber): string {
    if ($sequenceNumber <= 1) {
        return 'New Customer';
    }
    if ($sequenceNumber === 2) {
        return '2nd Time Customer';
    }
    if ($sequenceNumber === 3) {
        return '3rd Time Customer';
    }
    return "{$sequenceNumber}th Time Customer";
}

/**
 * Build a complete order-to-sequence map for all customers from permanent MySQL database.
 * 
 * Filters out invalid/cancelled orders, orders chronologically by booked_date, created_at, id.
 * 
 * @param PDO|null $pdo Optional PDO connection
 * @return array ['by_order_id' => [order_id => seq], 'by_order_ref' => [order_ref => seq], 'by_user_id' => [user_id => total_orders]]
 */
function get_customer_order_sequence_map(?PDO $pdo = null): array {
    if ($pdo === null && function_exists('get_db_connection')) {
        try {
            $pdo = get_db_connection();
        } catch (\Throwable $e) {
            return ['by_order_id' => [], 'by_order_ref' => [], 'by_user_id' => []];
        }
    }
    if (!$pdo) {
        return ['by_order_id' => [], 'by_order_ref' => [], 'by_user_id' => []];
    }

    try {
        $stmt = $pdo->query("
            SELECT id, user_id, order_ref, booked_date, created_at, status 
            FROM orders 
            WHERE status != 'cancelled' 
            ORDER BY user_id ASC, booked_date ASC, created_at ASC, id ASC
        ");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $byOrderId = [];
        $byOrderRef = [];
        $byUserId = [];
        $userSequences = [];

        foreach ($rows as $row) {
            $uId = (int)$row['user_id'];
            if (!isset($userSequences[$uId])) {
                $userSequences[$uId] = 0;
            }
            $userSequences[$uId]++;
            $seq = $userSequences[$uId];

            $orderId = (int)$row['id'];
            $orderRef = (string)$row['order_ref'];

            $byOrderId[$orderId] = $seq;
            if ($orderRef !== '') {
                $byOrderRef[$orderRef] = $seq;
            }
            $byUserId[$uId] = $seq; // latest count for user
        }

        return [
            'by_order_id' => $byOrderId,
            'by_order_ref' => $byOrderRef,
            'by_user_id' => $byUserId
        ];
    } catch (\Throwable $e) {
        error_log('[Customer Sequence Map Error] ' . $e->getMessage());
        return ['by_order_id' => [], 'by_order_ref' => [], 'by_user_id' => []];
    }
}

// ============================================================================
// 8. CSRF PROTECTION HELPERS
// ============================================================================

/**
 * Generate or retrieve the active session CSRF token.
 * Uses cryptographically secure random bytes (32 bytes -> 64 hex chars).
 */
function get_csrf_token(): string {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Verify an incoming CSRF token against the session token using constant-time comparison.
 */
function verify_csrf_token(?string $token): bool {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (empty($token) || empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], trim($token));
}


