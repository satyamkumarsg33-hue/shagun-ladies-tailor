

<div class="footer">
    <div class="footer-inner">
        <p>© 2026 Shagun Ladies Tailor</p>

        <div class="footer-links">
            <h3>Privacy</h3>
            <span class="footer-dot">•</span>
            <h3>Terms and Conditions</h3>
            <span class="footer-dot">•</span>
            <h3>Disclaimer</h3>
        </div>
    </div>
</div>

<div class="mobile-footer-info">
    <p>© 2026 Shagun Ladies Tailor</p>

    <div class="mobile-footer-links">
        <a href="#">Privacy</a>
        <span>•</span>
        <a href="#">Terms and Conditions</a>
        <span>•</span>
        <a href="#">Disclaimer</a>
    </div>
</div>

<nav class="mobile-bottom-nav" aria-label="Mobile quick navigation">
    <a href="index.php" class="mobile-bottom-nav-item">
        <span class="mobile-bottom-nav-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="currentColor">
                <path d="M12 3.2 3 10.4v10.4h6.6v-6.3h4.8v6.3H21V10.4L12 3.2Z"></path>
            </svg>
        </span>
        <span>Home</span>
    </a>

    <a href="orders.php" class="mobile-bottom-nav-item">
        <span class="mobile-bottom-nav-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="currentColor">
                <path d="M7 6.5h10l1.4 8.2H8.4L7 6.5Zm1.2-2.3a1.2 1.2 0 1 0 0 2.4 1.2 1.2 0 0 0 0-2.4Zm7.6 0a1.2 1.2 0 1 0 0 2.4 1.2 1.2 0 0 0 0-2.4ZM6 7H4.5v1.8H6l1.8 8.3h8.8v-1.8H9.2L8.9 14h9.9L21 7H6Z"></path>
            </svg>
        </span>
        <span>Orders</span>
    </a>

    <a href="tel:+917019179423" class="mobile-bottom-nav-item">
        <span class="mobile-bottom-nav-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="currentColor">
                <path d="M6.8 3.5a1.8 1.8 0 0 0-1.7 2.3c1.6 5.8 7.2 11.4 13 13a1.8 1.8 0 0 0 2.3-1.7v-2.7c0-.9-.6-1.6-1.4-1.8l-2.9-.7c-.7-.2-1.5.1-1.9.8l-.6 1a14.9 14.9 0 0 1-4.3-4.3l1-.6c.7-.4 1-1.2.8-1.9l-.7-2.9a1.9 1.9 0 0 0-1.8-1.4H6.8Z"></path>
            </svg>
        </span>
        <span>Call Back</span>
    </a>

    <a href="cart.php" class="mobile-bottom-nav-item">
        <span class="mobile-bottom-nav-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="currentColor">
                <path d="M8 6h13l-1.5 8.8a2 2 0 0 1-2 1.7H10.2a2 2 0 0 1-2-1.6L6.2 4.8H3V3h4.8l.5 3Zm2.5 14.2a1.7 1.7 0 1 0 0-3.4 1.7 1.7 0 0 0 0 3.4Zm7 0a1.7 1.7 0 1 0 0-3.4 1.7 1.7 0 0 0 0 3.4Z"></path>
            </svg>
        </span>
        <span>Cart<?php if (demo_cart_count() > 0): ?> (<?php echo demo_cart_count(); ?>)<?php endif; ?></span>
    </a>
</nav>


<!-- WhatsApp Icon -->
<div class="whatsapp-container">
   <div class="whatsapp-icon" id="waIcon">
    <img src="https://cdn-icons-png.flaticon.com/512/733/733585.png" />
</div>
    <button class="whatsapp-dismiss" id="waDismiss" type="button" aria-label="Hide WhatsApp chat button">&times;</button>

<?php if (demo_cart_count() > 0 && !is_user_logged_in()): ?>
<div class="demo-expiry-modal" id="demo-expiry-modal" data-expires-in="<?php echo demo_cart_expires_in(); ?>" aria-hidden="true">
    <div class="demo-expiry-card" role="dialog" aria-modal="true" aria-labelledby="demo-expiry-title">
        <p class="demo-eyebrow">Keep your selections</p>
        <h2 id="demo-expiry-title">Your cart is about to expire</h2>
        <p>Please log in to save your selections and keep your cart safe.</p>
        <div class="demo-expiry-actions">
            <a href="checkout-demo.php?step=login" class="demo-primary-action">Log in with Google</a>
            <button type="button" class="demo-secondary-action" data-cart-continue>Continue as Guest</button>
        </div>
    </div>
</div>
<?php endif; ?>

    <!-- Chat Card -->
    <div class="whatsapp-card" id="waCard">
        <div class="wa-header">
            Shagun Ladies Tailor
            <span id="waClose">&times;</span>
        </div>

        <div class="wa-body">
            Hi 👋 <br>
            How can we help you today?
        </div>

        <a href="https://wa.me/917019179423" target="_blank" class="wa-btn">
            Start Chat
        </a>
    </div>
</div>




<script src="assets/js/script.js?v=20260908-2"></script>
<script src="assets/js/demo-order.js?v=20260908-1"></script>
</body>
</html>
