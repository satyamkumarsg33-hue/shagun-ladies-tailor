<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

$raw_redirect = $_GET['redirect'] ?? $_POST['redirect'] ?? 'index.php';
$safe_redirect = get_safe_redirect_url($raw_redirect, 'index.php');

// If already logged in, redirect to appropriate destination
if (is_admin_logged_in()) {
    $adminTarget = str_starts_with($safe_redirect, 'admin/') ? $safe_redirect : 'admin/index.php';
    header('Location: ' . $adminTarget);
    exit;
}

if (is_user_logged_in()) {
    header('Location: ' . $safe_redirect);
    exit;
}

$error = '';
$identity_val = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'credentials';
    $identity_val = trim($_POST['identity'] ?? '');
    $password_val = (string) ($_POST['password'] ?? '');

    if ($action === 'google_login') {
        set_demo_user('Satyam Kumar SG', 'satyam@example.com', 'google');
        header('Location: ' . $safe_redirect);
        exit;
    }

    if ($action === 'credentials') {
        $result = auth_authenticate_user($identity_val, $password_val);

        if ($result['success']) {
            if ($result['type'] === 'admin') {
                $target = str_starts_with($safe_redirect, 'admin/') ? $safe_redirect : 'admin/index.php';
                header('Location: ' . $target);
                exit;
            } else {
                header('Location: ' . $safe_redirect);
                exit;
            }
        } else {
            $error = $result['error'] ?? 'Invalid email/username or password.';
        }
    }
}

include __DIR__ . '/includes/header.php';
?>

<main class="shagun-auth-page">
    <div class="shagun-auth-container">
        <div class="shagun-auth-card">
            <div class="shagun-auth-header">
                <div class="shagun-auth-brand-badge">SHAGUN ATELIER</div>
                <h1 class="shagun-auth-title">Welcome to Shagun</h1>
                <p class="shagun-auth-subtitle">Sign in to your account or atelier console</p>
            </div>

            <?php if (!empty($error)): ?>
                <div class="shagun-auth-alert"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>

            <div class="shagun-auth-body">
                <!-- Standard Credentials Form (Customers & Admins) -->
                <form method="POST" action="login.php" class="shagun-auth-form">
                    <input type="hidden" name="redirect" value="<?php echo htmlspecialchars($safe_redirect, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="action" value="credentials">

                    <div class="form-group-auth">
                        <label for="identity">Email or Username</label>
                        <input 
                            type="text" 
                            id="identity" 
                            name="identity" 
                            class="form-control-auth" 
                            placeholder="name@example.com or admin_username" 
                            value="<?php echo htmlspecialchars($identity_val, ENT_QUOTES, 'UTF-8'); ?>" 
                            required 
                            autofocus
                        >
                    </div>

                    <div class="form-group-auth">
                        <label for="password">Password</label>
                        <input 
                            type="password" 
                            id="password" 
                            name="password" 
                            class="form-control-auth" 
                            placeholder="Enter your password" 
                            required
                        >
                    </div>

                    <button type="submit" class="btn-auth-submit">Sign In</button>
                </form>

                <div class="shagun-auth-divider">
                    <span>or</span>
                </div>

                <!-- Demo Customer Google Sign-In Form -->
                <form method="POST" action="login.php" class="shagun-auth-form">
                    <input type="hidden" name="action" value="google_login">
                    <input type="hidden" name="redirect" value="<?php echo htmlspecialchars($safe_redirect, ENT_QUOTES, 'UTF-8'); ?>">
                    
                    <button type="submit" class="btn-google-auth">
                        <svg class="google-icon" viewBox="0 0 24 24" width="20" height="20" aria-hidden="true">
                            <path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/>
                            <path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/>
                            <path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.06H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.94l2.85-2.22.81-.63z"/>
                            <path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.06l3.66 2.84c.87-2.6 3.3-4.52 6.16-4.52z"/>
                        </svg>
                        <span>Continue with Google</span>
                    </button>
                </form>

                <p class="shagun-auth-proto-note">
                    <span class="proto-tag">Demo Mode</span>
                    Google Sign-In logs you in as customer <strong>Satyam Kumar SG</strong> for prototype flows.
                </p>

                <div class="shagun-auth-footer-links" style="margin-top: 20px;">
                    <p>Don't have an account? <a href="signup.php?redirect=<?php echo urlencode($safe_redirect); ?>">Sign up with email</a></p>
                </div>
            </div>
        </div>
    </div>
</main>

<?php include __DIR__ . '/includes/footer.php'; ?>
