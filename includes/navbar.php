<div class="navbar">
    <a href="index.php" class="logo">Shagun Ladies Tailor</a>

    <button class="menu-toggle" type="button" aria-label="Open navigation menu" aria-expanded="false" aria-controls="mobile-menu">
        <span></span>
        <span></span>
        <span></span>
    </button>

    <div class="nav-links">
        <a href="index.php">Home</a>
        <a href="designs.php">Design Gallery</a>
        <a href="#">Our Services</a>
        <a href="process.php">Process</a>
        <a href="contact.php">Contact</a>
        <a href="cart.php" class="nav-cart-link">My Cart<?php if (demo_cart_count() > 0): ?> <span><?php echo demo_cart_count(); ?></span><?php endif; ?></a>

        <?php if (is_admin_logged_in()): 
            $currentAdmin = get_logged_in_admin();
            $adminName = htmlspecialchars($currentAdmin['full_name'] ?? 'Admin', ENT_QUOTES, 'UTF-8');
            $adminEmail = htmlspecialchars($currentAdmin['email'] ?? '', ENT_QUOTES, 'UTF-8');
            $adminRoleLabel = htmlspecialchars(get_admin_role_label(), ENT_QUOTES, 'UTF-8');
        ?>
            <!-- Authenticated Admin Navigation State -->
            <div class="nav-user-dropdown" data-nav-dropdown>
                <button type="button" class="nav-user-btn" id="navAdminMenuBtn" aria-expanded="false" aria-haspopup="true">
                    <span class="nav-user-avatar" style="background: #6b1d28; color: #fff;">
                        <svg viewBox="0 0 24 24" width="14" height="14" fill="currentColor" aria-hidden="true">
                            <path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"/>
                        </svg>
                    </span>
                    <span class="nav-user-name"><?php echo $adminName; ?></span>
                    <span class="nav-dropdown-caret" aria-hidden="true">▾</span>
                </button>
                <div class="nav-user-menu" id="navAdminMenu" aria-labelledby="navAdminMenuBtn">
                    <div class="nav-user-menu-header">
                        <strong><?php echo $adminName; ?></strong>
                        <small><?php echo $adminEmail; ?> &bull; <?php echo $adminRoleLabel; ?></small>
                    </div>
                    <div class="nav-user-menu-divider"></div>
                    <a href="admin/index.php" class="nav-user-menu-item">
                        <span>Admin Dashboard</span>
                    </a>
                    <div class="nav-user-menu-divider"></div>
                    <a href="logout.php" class="nav-user-menu-item nav-user-menu-item-logout">
                        <span>Logout</span>
                    </a>
                </div>
            </div>

        <?php elseif (is_user_logged_in()): 
            $currentUser = get_logged_in_user();
            $userName = htmlspecialchars($currentUser['name'] ?? 'Satyam Kumar SG', ENT_QUOTES, 'UTF-8');
            $userEmail = htmlspecialchars($currentUser['email'] ?? '', ENT_QUOTES, 'UTF-8');
        ?>
            <!-- Authenticated Customer Navigation State -->
            <div class="nav-user-dropdown" data-nav-dropdown>
                <button type="button" class="nav-user-btn" id="navUserMenuBtn" aria-expanded="false" aria-haspopup="true">
                    <span class="nav-user-avatar">
                        <svg viewBox="0 0 24 24" width="14" height="14" fill="currentColor" aria-hidden="true">
                            <path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/>
                        </svg>
                    </span>
                    <span class="nav-user-name"><?php echo $userName; ?></span>
                    <span class="nav-dropdown-caret" aria-hidden="true">▾</span>
                </button>
                <div class="nav-user-menu" id="navUserMenu" aria-labelledby="navUserMenuBtn">
                    <div class="nav-user-menu-header">
                        <strong><?php echo $userName; ?></strong>
                        <small><?php echo $userEmail; ?></small>
                    </div>
                    <div class="nav-user-menu-divider"></div>
                    <a href="orders.php" class="nav-user-menu-item">
                        <span>My Account</span>
                    </a>
                    <a href="orders.php" class="nav-user-menu-item">
                        <span>Orders</span>
                    </a>
                    <div class="nav-user-menu-divider"></div>
                    <a href="logout.php" class="nav-user-menu-item nav-user-menu-item-logout">
                        <span>Logout</span>
                    </a>
                </div>
            </div>

        <?php else: ?>
            <!-- Guest Navigation State -->
            <a href="login.php" class="nav-auth-link">Sign In</a>
        <?php endif; ?>

        <a href="contact.php" class="nav-btn">Book Appointment</a>
    </div>
</div>

<div class="mobile-menu-overlay" data-menu-close></div>

<aside class="mobile-menu" id="mobile-menu" aria-hidden="true">
    <div class="mobile-menu-header">
        <a href="index.php" class="logo mobile-logo">Shagun Ladies Tailor</a>
        <button class="mobile-menu-close" type="button" aria-label="Close navigation menu" data-menu-close>&times;</button>
    </div>

    <nav class="mobile-menu-links" aria-label="Mobile navigation">
        <a href="index.php" data-menu-close>Home</a>
        <a href="designs.php" data-menu-close>Design Gallery</a>
        <a href="#" data-menu-close>Our Services</a>
        <a href="process.php" data-menu-close>Process</a>
        <a href="contact.php" data-menu-close>Contact</a>

        <?php if (is_admin_logged_in()): 
            $currentAdmin = get_logged_in_admin();
            $adminName = htmlspecialchars($currentAdmin['full_name'] ?? 'Admin', ENT_QUOTES, 'UTF-8');
            $adminEmail = htmlspecialchars($currentAdmin['email'] ?? '', ENT_QUOTES, 'UTF-8');
            $adminRoleLabel = htmlspecialchars(get_admin_role_label(), ENT_QUOTES, 'UTF-8');
        ?>
            <!-- Mobile Admin Navigation State -->
            <div class="mobile-menu-user-section">
                <div class="mobile-user-info">
                    <span class="mobile-user-label">Logged in as (<?php echo $adminRoleLabel; ?>)</span>
                    <strong><?php echo $adminName; ?></strong>
                    <small><?php echo $adminEmail; ?></small>
                </div>
                <a href="admin/index.php" class="mobile-user-link" data-menu-close>Admin Dashboard</a>
                <a href="logout.php" class="mobile-logout-link" data-menu-close>Logout</a>
            </div>

        <?php elseif (is_user_logged_in()): 
            $currentUser = get_logged_in_user();
            $userName = htmlspecialchars($currentUser['name'] ?? 'Satyam Kumar SG', ENT_QUOTES, 'UTF-8');
            $userEmail = htmlspecialchars($currentUser['email'] ?? '', ENT_QUOTES, 'UTF-8');
        ?>
            <!-- Mobile Customer Navigation State -->
            <div class="mobile-menu-user-section">
                <div class="mobile-user-info">
                    <span class="mobile-user-label">Logged in as</span>
                    <strong><?php echo $userName; ?></strong>
                    <small><?php echo $userEmail; ?></small>
                </div>
                <a href="orders.php" class="mobile-user-link" data-menu-close>Orders</a>
                <a href="logout.php" class="mobile-logout-link" data-menu-close>Logout</a>
            </div>

        <?php else: ?>
            <!-- Mobile Guest Navigation State -->
            <a href="login.php" class="mobile-auth-link" data-menu-close>Sign In</a>
        <?php endif; ?>

        <a href="contact.php" class="mobile-menu-btn" data-menu-close>Book Appointment</a>
    </nav>
</aside>
