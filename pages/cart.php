<?php
/**
 * Global Shopping Cart Page
 * Multi-event registration cart
 */

require_once __DIR__ . '/../hub-config.php';

$pageInfo = [
    'title' => 'Kundvagn',
    'section' => 'cart'
];

include __DIR__ . '/../components/header.php';
?>

<main class="main-content">
    <div class="container" style="max-width: 800px; margin: 0 auto;">
        <h1 style="margin-bottom: var(--space-lg);">
            <i data-lucide="shopping-cart"></i>
            Kundvagn
        </h1>

        <div id="cartContent">
            <div id="emptyCart" style="display: none; text-align: center; padding: var(--space-3xl) var(--space-lg);">
                <i data-lucide="shopping-cart" style="width: 64px; height: 64px; color: var(--color-text-muted); margin-bottom: var(--space-lg);"></i>
                <h2 style="color: var(--color-text-secondary); font-size: var(--text-xl); margin-bottom: var(--space-sm);">Kundvagnen är tom</h2>
                <p style="color: var(--color-text-muted); margin-bottom: var(--space-lg);">Du har inga anmälningar i kundvagnen</p>
                <a href="/calendar" class="btn btn--primary">
                    <i data-lucide="calendar"></i>
                    Bläddra bland event
                </a>
            </div>

            <div id="cartItems"></div>

            <?php if (!hub_is_logged_in()): ?>
            <!-- Guest Checkout Form -->
            <div id="guestCheckoutForm" class="card" style="display: none; margin-top: var(--space-xl);">
                <div class="card-header">
                    <h3>Dina uppgifter</h3>
                </div>
                <div class="card-body">
                    <p style="color: var(--color-text-secondary); margin-bottom: var(--space-md);">
                        Fyll i dina uppgifter för att slutföra köpet.
                    </p>
                    <div class="form-group">
                        <label class="form-label">E-post *</label>
                        <input type="email" id="guestEmail" class="form-input" placeholder="din@email.se" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Namn *</label>
                        <input type="text" id="guestName" class="form-input" placeholder="För- och efternamn" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Telefon (valfritt)</label>
                        <input type="tel" id="guestPhone" class="form-input" placeholder="070-123 45 67">
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <div id="cartSummary" style="display: none; margin-top: var(--space-xl); padding: var(--space-lg); background: var(--color-bg-surface); border-radius: var(--radius-md); border: 1px solid var(--color-border);">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: var(--space-md);">
                    <span style="font-size: var(--text-lg); font-weight: var(--weight-semibold);">Totalt:</span>
                    <span id="totalPrice" style="font-size: var(--text-2xl); font-weight: var(--weight-bold); color: var(--color-accent);">0 kr</span>
                </div>

                <button id="checkoutBtn" class="btn btn--primary btn--lg btn--block">
                    <i data-lucide="credit-card"></i>
                    Gå till betalning
                </button>

                <button id="clearCartBtn" class="btn btn--ghost btn--block" style="margin-top: var(--space-sm);">
                    <i data-lucide="trash-2"></i>
                    Töm kundvagn
                </button>
            </div>
        </div>
    </div>
</main>

<script>
(function() {
    const cartItemsContainer = document.getElementById('cartItems');
    const cartSummary = document.getElementById('cartSummary');
    const emptyCart = document.getElementById('emptyCart');
    const totalPriceEl = document.getElementById('totalPrice');
    const checkoutBtn = document.getElementById('checkoutBtn');
    const clearCartBtn = document.getElementById('clearCartBtn');

    function renderCart() {
        const cart = GlobalCart.getCart();

        if (cart.length === 0) {
            emptyCart.style.display = 'block';
            cartSummary.style.display = 'none';
            cartItemsContainer.innerHTML = '';
            return;
        }

        emptyCart.style.display = 'none';
        cartSummary.style.display = 'block';

        // Show guest form if not logged in
        <?php if (!hub_is_logged_in()): ?>
        const guestForm = document.getElementById('guestCheckoutForm');
        if (guestForm) guestForm.style.display = 'block';
        <?php endif; ?>

        // Group by event
        const byEvent = GlobalCart.getItemsByEvent();

        let html = '';
        byEvent.forEach(eventGroup => {
            html += `
                <div class="card" style="margin-bottom: var(--space-lg);">
                    <div class="card-header">
                        <h3 style="font-size: var(--text-lg); margin: 0;">
                            <i data-lucide="calendar"></i>
                            ${eventGroup.event_name || 'Event #' + eventGroup.event_id}
                        </h3>
                        ${eventGroup.event_date ? `<small style="color: var(--color-text-muted);">${eventGroup.event_date}</small>` : ''}
                    </div>
                    <div class="card-body" style="padding: 0;">
                        <table class="table" style="margin: 0;">
                            <thead>
                                <tr>
                                    <th>Deltagare</th>
                                    <th>Klubb</th>
                                    <th>Klass</th>
                                    <th style="text-align: right;">Pris</th>
                                    <th style="width: 50px;"></th>
                                </tr>
                            </thead>
                            <tbody>
            `;

            eventGroup.items.forEach(item => {
                html += `
                    <tr>
                        <td><strong>${item.rider_name}</strong></td>
                        <td style="color: var(--color-text-secondary);">${item.club_name || '-'}</td>
                        <td>${item.class_name}</td>
                        <td style="text-align: right;">${item.price} kr</td>
                        <td style="text-align: right;">
                            <button class="btn btn--ghost btn--sm remove-item"
                                    data-event="${item.event_id}"
                                    data-rider="${item.rider_id}"
                                    data-class="${item.class_id}"
                                    title="Ta bort">
                                <i data-lucide="x"></i>
                            </button>
                        </td>
                    </tr>
                `;
            });

            html += `
                            </tbody>
                        </table>
                    </div>
                </div>
            `;
        });

        cartItemsContainer.innerHTML = html;

        // Update total
        totalPriceEl.textContent = GlobalCart.getTotalPrice() + ' kr';

        // Re-init Lucide icons
        if (typeof lucide !== 'undefined') lucide.createIcons();

        // Add remove handlers
        document.querySelectorAll('.remove-item').forEach(btn => {
            btn.addEventListener('click', function() {
                const eventId = parseInt(this.dataset.event);
                const riderId = parseInt(this.dataset.rider);
                const classId = parseInt(this.dataset.class);
                GlobalCart.removeItem(eventId, riderId, classId);
                renderCart();
            });
        });
    }

    // Clear cart
    clearCartBtn.addEventListener('click', function() {
        if (confirm('Är du säker på att du vill tömma kundvagnen?')) {
            GlobalCart.clearCart();
            renderCart();
        }
    });

    // Checkout
    checkoutBtn.addEventListener('click', async function() {
        const cart = GlobalCart.getCart();
        if (cart.length === 0) return;

        // Prepare buyer data
        const buyerData = {};

        <?php if (!hub_is_logged_in()): ?>
        // Guest checkout - validate form
        const guestEmail = document.getElementById('guestEmail')?.value?.trim();
        const guestName = document.getElementById('guestName')?.value?.trim();
        const guestPhone = document.getElementById('guestPhone')?.value?.trim();

        if (!guestEmail || !guestName) {
            alert('Fyll i e-post och namn för att fortsätta');
            return;
        }

        // Validate email format
        const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        if (!emailRegex.test(guestEmail)) {
            alert('Ange en giltig e-postadress');
            return;
        }

        buyerData.email = guestEmail;
        buyerData.name = guestName;
        if (guestPhone) buyerData.phone = guestPhone;
        <?php else: ?>
        // Logged in user
        <?php $currentUser = hub_current_user(); ?>
        buyerData.name = '<?= h(($currentUser['firstname'] ?? '') . ' ' . ($currentUser['lastname'] ?? '')) ?>';
        buyerData.email = '<?= h($currentUser['email'] ?? '') ?>';
        <?php endif; ?>

        checkoutBtn.disabled = true;
        checkoutBtn.innerHTML = '<i data-lucide="loader-2" class="spin"></i> Bearbetar...';
        if (typeof lucide !== 'undefined') lucide.createIcons();

        try {

            const response = await fetch('/api/orders.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'create',
                    buyer: buyerData,
                    items: cart
                })
            });

            const data = await response.json();

            if (data.success) {
                // DON'T clear cart yet - user hasn't paid!
                // Cart will be cleared after successful payment (webhook or return page)
                // Store order ID so we can restore it if needed
                sessionStorage.setItem('pending_order_id', data.order.id);

                // Redirect to checkout
                window.location.href = data.order.checkout_url;
            } else {
                alert(data.error || 'Ett fel uppstod');
                checkoutBtn.disabled = false;
                checkoutBtn.innerHTML = '<i data-lucide="credit-card"></i> Gå till betalning';
                if (typeof lucide !== 'undefined') lucide.createIcons();
            }
        } catch (error) {
            console.error('Checkout error:', error);
            alert('Ett fel uppstod. Försök igen.');
            checkoutBtn.disabled = false;
            checkoutBtn.innerHTML = '<i data-lucide="credit-card"></i> Gå till betalning';
            if (typeof lucide !== 'undefined') lucide.createIcons();
        }
    });

    // Listen for cart updates from other pages
    window.addEventListener('cartUpdated', renderCart);

    // Wait for GlobalCart to load (it's in footer)
    function initCart() {
        if (typeof GlobalCart !== 'undefined') {
            renderCart();
        } else {
            setTimeout(initCart, 50);
        }
    }
    initCart();
})();
</script>

<?php include __DIR__ . '/../components/footer.php'; ?>
