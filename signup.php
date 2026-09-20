<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

$raw_redirect = $_GET['redirect'] ?? $_POST['redirect'] ?? 'index.php';
$safe_redirect = get_safe_redirect_url($raw_redirect, 'index.php');

// If already logged in, redirect immediately
if (is_admin_logged_in()) {
    header('Location: admin/index.php');
    exit;
}

if (is_user_logged_in()) {
    header('Location: ' . $safe_redirect);
    exit;
}

$error = '';
$name_val = '';
$email_val = '';
$phone_val = '';
$address_val = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name_val = trim((string) ($_POST['name'] ?? ''));
    $email_val = strtolower(trim((string) ($_POST['email'] ?? '')));
    $phone_val = trim((string) ($_POST['phone'] ?? ''));
    $address_val = trim((string) ($_POST['address'] ?? ($_POST['address_line1'] ?? '')));
    $password_val = (string) ($_POST['password'] ?? '');
    $password_confirm_val = (string) ($_POST['password_confirm'] ?? '');

    // Validate phone number (Indian formats: 10 digits with optional +91 or 0 prefix)
    $clean_phone_digits = preg_replace('/[\s\-\.\(\)\+]/', '', $phone_val);
    if (str_starts_with($clean_phone_digits, '91') && strlen($clean_phone_digits) === 12) {
        $clean_phone_digits = substr($clean_phone_digits, 2);
    } elseif (str_starts_with($clean_phone_digits, '0') && strlen($clean_phone_digits) === 11) {
        $clean_phone_digits = substr($clean_phone_digits, 1);
    }
    
    $phone_provided = isset($_POST['phone']);
    $address_provided = isset($_POST['address']);

    if ($name_val === '' || $email_val === '' || $password_val === '') {
        $error = 'Please fill in all required fields.';
    } elseif (mb_strlen($name_val) < 2) {
        $error = 'Full name must be at least 2 characters.';
    } elseif (!filter_var($email_val, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif ($phone_provided && ($phone_val === '' || !preg_match('/^\d{10}$/', $clean_phone_digits))) {
        $error = 'Please enter a valid 10-digit phone number.';
    } elseif ($address_provided && ($address_val === '' || mb_strlen($address_val) < 5)) {
        $error = 'Please enter a valid address (at least 5 characters).';
    } elseif (strlen($password_val) < 6) {
        $error = 'Password must be at least 6 characters long.';
    } elseif (isset($_POST['password_confirm']) && $password_val !== $password_confirm_val) {
        $error = 'Passwords do not match. Please re-enter.';
    } else {
        try {
            $pdo = get_db_connection();

            // Check for duplicate customer email
            $dupStmt = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
            $dupStmt->execute([':email' => $email_val]);
            if ($dupStmt->fetch()) {
                $error = 'An account with this email address already exists. Please sign in instead.';
            } else {
                $passwordHash = password_hash($password_val, PASSWORD_DEFAULT);

                // Format normalized phone if provided
                $phone_normalized = null;
                if ($phone_val !== '') {
                    $phone_normalized = (str_starts_with(trim($phone_val), '+') || str_starts_with(preg_replace('/[\s\-\.\(\)\+]/', '', $phone_val), '91'))
                        ? '+91 ' . substr($clean_phone_digits, 0, 5) . ' ' . substr($clean_phone_digits, 5)
                        : $clean_phone_digits;
                }

                // Extract PIN code if present
                $pin = '';
                if (preg_match('/\b([1-9][0-9]{5})\b/', $address_val, $mPin)) {
                    $pin = $mPin[1];
                } elseif (!empty($_POST['postal_code'])) {
                    $pin = trim((string)$_POST['postal_code']);
                }

                $city_val = !empty($_POST['city']) ? trim((string)$_POST['city']) : '';
                $state_val = !empty($_POST['state']) ? trim((string)$_POST['state']) : '';

                if ($city_val === '' || $state_val === '') {
                    $segments = array_map('trim', explode(',', $address_val));
                    if (count($segments) >= 2) {
                        $lastSeg = end($segments);
                        $secondLastSeg = prev($segments);
                        if ($pin !== '' && strpos($lastSeg, $pin) !== false) {
                            $cleanedLast = trim(str_replace($pin, '', $lastSeg));
                            if ($cleanedLast !== '' && $state_val === '') {
                                $state_val = $cleanedLast;
                            }
                            if ($secondLastSeg !== false && $city_val === '') {
                                $city_val = $secondLastSeg;
                            }
                        }
                    }
                }

                $address_line1 = $address_val;
                $address_line2 = null;
                if (mb_strlen($address_val) > 250) {
                    $address_line1 = mb_substr($address_val, 0, 250);
                    $address_line2 = mb_substr($address_val, 250, 255);
                }

                // ATOMIC PDO TRANSACTION
                $pdo->beginTransaction();
                try {
                    $insertStmt = $pdo->prepare('
                        INSERT INTO users (name, email, phone, password_hash, auth_provider, role, is_active)
                        VALUES (:name, :email, :phone, :hash, \'email\', \'customer\', 1)
                    ');
                    $insertStmt->execute([
                        ':name' => $name_val,
                        ':email' => $email_val,
                        ':phone' => $phone_normalized,
                        ':hash' => $passwordHash,
                    ]);

                    $newUserId = (int) $pdo->lastInsertId();

                    if ($address_val !== '') {
                        $insertAddrStmt = $pdo->prepare('
                            INSERT INTO customer_addresses (
                                user_id, address_type, recipient_name, phone,
                                address_line1, address_line2, city, state, postal_code, is_default
                            ) VALUES (
                                :user_id, \'home\', :recipient_name, :phone,
                                :address_line1, :address_line2, :city, :state, :postal_code, 1
                            )
                        ');
                        $insertAddrStmt->execute([
                            ':user_id' => $newUserId,
                            ':recipient_name' => $name_val,
                            ':phone' => $phone_normalized,
                            ':address_line1' => mb_substr($address_line1, 0, 255),
                            ':address_line2' => $address_line2,
                            ':city' => mb_substr($city_val, 0, 100),
                            ':state' => mb_substr($state_val, 0, 100),
                            ':postal_code' => mb_substr($pin, 0, 20),
                        ]);
                    }

                    $pdo->commit();
                } catch (Throwable $txEx) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    throw $txEx;
                }

                // Prevent session fixation
                session_regenerate_id(true);

                // Isolate session
                unset($_SESSION['admin_user']);

                $_SESSION['user'] = [
                    'id' => $newUserId,
                    'name' => htmlspecialchars($name_val, ENT_QUOTES, 'UTF-8'),
                    'email' => htmlspecialchars($email_val, ENT_QUOTES, 'UTF-8'),
                    'phone' => htmlspecialchars((string) ($phone_normalized ?? ''), ENT_QUOTES, 'UTF-8'),
                    'role' => 'customer',
                    'auth_provider' => 'email',
                    'logged_in_at' => time()
                ];

                auth_post_login_cart_transfer($newUserId);

                header('Location: ' . $safe_redirect);
                exit;
            }
        } catch (Throwable $e) {
            error_log('[Signup Error] ' . $e->getMessage());
            $error = 'Unable to create account right now. Please try again in a few moments.';
        }
    }
}

include __DIR__ . '/includes/header.php';
?>

<main class="shagun-auth-page">
    <div class="shagun-auth-container">
        <div class="shagun-auth-card">
            <div class="shagun-auth-header">
                <div class="shagun-auth-brand-badge">SHAGUN LUXE</div>
                <h1 class="shagun-auth-title">Create an Account</h1>
                <p class="shagun-auth-subtitle">Join Shagun for tailored wedding & luxury bespoke collections</p>
            </div>

            <?php if (!empty($error)): ?>
                <div class="shagun-auth-alert"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>

            <div class="shagun-auth-body">
                <form method="POST" action="signup.php" class="shagun-auth-form">
                    <input type="hidden" name="redirect" value="<?php echo htmlspecialchars($safe_redirect, ENT_QUOTES, 'UTF-8'); ?>">
                    
                    <div class="form-group-auth">
                        <label for="name">Full Name</label>
                        <input type="text" id="name" name="name" class="form-control-auth" placeholder="e.g. Priya Sharma" value="<?php echo htmlspecialchars($name_val, ENT_QUOTES, 'UTF-8'); ?>" autocomplete="name" required autofocus>
                    </div>

                    <div class="form-group-auth">
                        <label for="email">Email Address</label>
                        <input type="email" id="email" name="email" class="form-control-auth" placeholder="name@example.com" value="<?php echo htmlspecialchars($email_val, ENT_QUOTES, 'UTF-8'); ?>" autocomplete="email" required>
                    </div>

                    <div class="form-group-auth">
                        <label for="phone">Phone Number</label>
                        <input type="tel" id="phone" name="phone" class="form-control-auth" placeholder="e.g. +91 98765 43210" value="<?php echo htmlspecialchars($phone_val, ENT_QUOTES, 'UTF-8'); ?>" autocomplete="tel" required>
                    </div>

                    <div class="form-group-auth">
                        <label for="address">Delivery Address</label>
                        <textarea id="address" name="address" class="form-control-auth" rows="3" placeholder="Enter your house/flat number, street, area, city, state, and PIN code" required><?php echo htmlspecialchars($address_val, ENT_QUOTES, 'UTF-8'); ?></textarea>
                    </div>

                    <div class="form-group-auth">
                        <label for="password">Password</label>
                        <input type="password" id="password" name="password" class="form-control-auth" placeholder="Create a password (min. 6 characters)" autocomplete="new-password" required>
                    </div>

                    <div class="form-group-auth">
                        <label for="password_confirm">Confirm Password</label>
                        <input type="password" id="password_confirm" name="password_confirm" class="form-control-auth" placeholder="Re-enter your password" autocomplete="new-password" required>
                    </div>

                    <button type="submit" class="btn-auth-submit">Create Account</button>
                </form>

                <div class="shagun-auth-footer-links" style="margin-top: 24px;">
                    <p>Already have an account? <a href="login.php?redirect=<?php echo urlencode($safe_redirect); ?>">Sign in</a></p>
                </div>
            </div>
        </div>
    </div>
</main>

<?php include __DIR__ . '/includes/footer.php'; ?>
