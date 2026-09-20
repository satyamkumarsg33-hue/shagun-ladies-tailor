(function () {
    const formatter = new Intl.NumberFormat('en-IN');
    const customizer = document.querySelector('[data-customizer]');
    const expiryModal = document.getElementById('demo-expiry-modal');
    let touchTimer;
    let expiryTimer;

    function refreshActivity() {
        window.clearTimeout(touchTimer);
        touchTimer = window.setTimeout(function () {
            fetch('cart-touch.php', { method: 'POST', credentials: 'same-origin' })
                .then(function (response) { return response.json(); })
                .then(function (data) {
                    if (data.expired) {
                        window.location.href = 'cart.php';
                        return;
                    }
                    scheduleExpiryWarning(data.expiresIn);
                })
                .catch(function () { /* Demo activity refresh is intentionally non-blocking. */ });
        }, 400);
    }

    function updatePrice() {
        if (!customizer) return;
        const base = Number(customizer.dataset.basePrice || 0);
        let extra = 0;
        customizer.querySelectorAll('input[data-price]:checked').forEach(function (input) {
            extra += Number(input.dataset.price || 0);
        });
        const total = base + extra;
        document.querySelectorAll('[data-customization-price]').forEach(function (element) { element.textContent = '₹' + formatter.format(extra); });
        document.querySelectorAll('[data-current-total]').forEach(function (element) { element.textContent = '₹' + formatter.format(total); });
    }

    function showExpiryModal() {
        if (!expiryModal) return;
        expiryModal.classList.add('is-visible');
        expiryModal.setAttribute('aria-hidden', 'false');
    }

    function scheduleExpiryWarning(expiresIn) {
        if (!expiryModal || !expiresIn) return;
        window.clearTimeout(expiryTimer);
        const warningDelay = Math.max(0, (Number(expiresIn) - 300) * 1000);
        expiryTimer = window.setTimeout(showExpiryModal, warningDelay);
    }

    if (customizer) {
        customizer.addEventListener('change', function () { updatePrice(); refreshActivity(); });
        customizer.addEventListener('input', refreshActivity);
        updatePrice();
    }

    document.querySelectorAll('[data-cart-continue]').forEach(function (button) {
        button.addEventListener('click', function () {
            refreshActivity();
            expiryModal.classList.remove('is-visible');
            expiryModal.setAttribute('aria-hidden', 'true');
        });
    });

    if (expiryModal) scheduleExpiryWarning(expiryModal.dataset.expiresIn);
})();
