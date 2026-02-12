<?php
/**
 * Global Shopping Cart Page
 * Multi-event registration cart
 *
 * Loaded via index.php router - header/footer provided by index.php
 */

require_once __DIR__ . '/../includes/payment.php';
// Gravity ID is checked client-side for ALL riders in cart via API
?>

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
            <!-- Subtotal -->
            <div style="display: flex; justify-content: space-between; align-items: center; padding-bottom: var(--space-sm); border-bottom: 1px solid var(--color-border);">
                <span style="font-size: var(--text-md);">Delsumma:</span>
                <span id="subtotalPrice" style="font-size: var(--text-lg); font-weight: var(--weight-semibold);">0 kr</span>
            </div>

            <!-- Series Discount -->
            <div id="seriesDiscount" style="display: none; padding: var(--space-sm) 0; border-bottom: 1px solid var(--color-border);">
                <div style="display: flex; justify-content: space-between; align-items: center; color: var(--color-success);">
                    <span style="font-size: var(--text-sm); display: flex; align-items: center; gap: var(--space-xs);">
                        <i data-lucide="tag" style="width: 16px; height: 16px;"></i>
                        Serierabatt
                    </span>
                    <span id="seriesDiscountAmount" style="font-weight: var(--weight-semibold);">-0 kr</span>
                </div>
            </div>

            <!-- Gravity ID Discount (checked for all riders in cart via API) -->
            <div id="gravityIdDiscount" style="display: none; padding: var(--space-sm) 0; border-bottom: 1px solid var(--color-border);">
                <div style="display: flex; justify-content: space-between; align-items: center; color: var(--color-success);">
                    <span id="gravityIdLabel" style="font-size: var(--text-sm); display: flex; align-items: center; gap: var(--space-xs);">
                        <i data-lucide="badge-check" style="width: 16px; height: 16px;"></i>
                        Gravity ID
                    </span>
                    <span id="gravityIdAmount" style="font-weight: var(--weight-semibold);">-0 kr</span>
                </div>
            </div>

            <!-- Total -->
            <div style="display: flex; justify-content: space-between; align-items: center; margin-top: var(--space-md);">
                <span style="font-size: var(--text-lg); font-weight: var(--weight-semibold);">Totalt:</span>
                <span id="totalPrice" style="font-size: var(--text-2xl); font-weight: var(--weight-bold); color: var(--color-accent);">0 kr</span>
            </div>

            <!-- VAT info -->
            <div id="vatInfo" style="display: flex; justify-content: space-between; align-items: center; padding-top: var(--space-xs);">
                <span style="font-size: var(--text-xs); color: var(--color-text-muted);">varav moms (6%)</span>
                <span id="vatAmount" style="font-size: var(--text-xs); color: var(--color-text-muted);">0 kr</span>
            </div>

            <button id="checkoutBtn" class="btn btn--primary btn--lg btn--block" style="margin-top: var(--space-lg);">
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

<script>
(function() {
    // Calculate series discount from cart items
    // Groups series items by rider+class, compares regular total vs season_price
    function calculateSeriesDiscount(cart) {
        const seriesGroups = {};
        cart.forEach(item => {
            if (item.is_series_registration && item.season_price > 0) {
                const key = `${item.rider_id}_${item.class_id}_${item.series_id || 0}`;
                if (!seriesGroups[key]) {
                    seriesGroups[key] = { items: [], season_price: item.season_price };
                }
                seriesGroups[key].items.push(item);
            }
        });

        let totalDiscount = 0;
        Object.values(seriesGroups).forEach(group => {
            const regularTotal = group.items.reduce((sum, item) => sum + (item.price || 0), 0);
            if (regularTotal > group.season_price) {
                totalDiscount += regularTotal - group.season_price;
            }
        });
        return totalDiscount;
    }

    // Calculate per-item series discount for inline display on each registration
    function getPerItemSeriesDiscount(cart) {
        const seriesGroups = {};
        cart.forEach(item => {
            if (item.is_series_registration && item.season_price > 0) {
                const key = `${item.rider_id}_${item.class_id}_${item.series_id || 0}`;
                if (!seriesGroups[key]) {
                    seriesGroups[key] = { items: [], season_price: item.season_price };
                }
                seriesGroups[key].items.push(item);
            }
        });
        const discountMap = {};
        Object.values(seriesGroups).forEach(group => {
            const regularTotal = group.items.reduce((sum, item) => sum + (item.price || 0), 0);
            if (regularTotal > group.season_price) {
                const totalDiscount = regularTotal - group.season_price;
                const perItem = Math.round(totalDiscount / group.items.length);
                group.items.forEach(item => {
                    discountMap[`${item.event_id}_${item.rider_id}_${item.class_id}`] = perItem;
                });
            }
        });
        return discountMap;
    }

    // Update VAT display (6% inclusive: VAT = total * 6 / 106)
    function updateVatDisplay(total) {
        const vatEl = document.getElementById('vatAmount');
        if (vatEl) {
            const vat = Math.round(total * 6 / 106);
            vatEl.textContent = vat + ' kr';
        }
    }

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

        // Calculate per-item series discounts for inline display
        const itemSeriesDiscounts = getPerItemSeriesDiscount(cart);

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
            `;

            eventGroup.items.forEach(item => {
                const itemKey = `${item.event_id}_${item.rider_id}_${item.class_id}`;
                const seriesDiscPerItem = itemSeriesDiscounts[itemKey] || 0;

                // Build per-item discount lines
                let discountLines = '';
                if (seriesDiscPerItem > 0) {
                    discountLines += `<div style="font-size: var(--text-xs); color: var(--color-success); display: flex; align-items: center; gap: 4px; margin-top: 2px;">
                        <i data-lucide="tag" style="width:12px;height:12px;"></i> Serierabatt: -${seriesDiscPerItem} kr
                    </div>`;
                }
                // Gravity ID discount placeholder - filled in async for each rider
                discountLines += `<div class="cart-item-gravity" data-event-id="${item.event_id}" data-rider-id="${item.rider_id}" style="display:none; font-size: var(--text-xs); color: var(--color-success); align-items: center; gap: 4px; margin-top: 2px;"></div>`;

                html += `
                    <div class="cart-item" style="display: flex; justify-content: space-between; align-items: center; gap: var(--space-sm); padding: var(--space-md); border-bottom: 1px solid var(--color-border);">
                        <div style="flex: 1; min-width: 0;">
                            <strong style="display: block;">${item.rider_name}</strong>
                            <span style="font-size: var(--text-sm); color: var(--color-text-secondary);">
                                ${item.class_name || 'Klass'}${item.club_name ? ' &middot; ' + item.club_name : ''}
                            </span>
                            ${discountLines}
                        </div>
                        <div style="display: flex; align-items: center; gap: var(--space-sm); flex-shrink: 0;">
                            <span style="font-weight: var(--weight-semibold);">${item.price} kr</span>
                            <button class="btn btn--danger btn--sm remove-item"
                                    data-eventid="${item.event_id}"
                                    data-riderid="${item.rider_id}"
                                    data-classid="${item.class_id}"
                                    style="padding: var(--space-2xs) var(--space-xs);"
                                    title="Ta bort">
                                <i data-lucide="x" style="width: 16px; height: 16px;"></i>
                            </button>
                        </div>
                    </div>
                `;
            });

            html += `
                    </div>
                </div>
            `;
        });

        cartItemsContainer.innerHTML = html;

        // Calculate subtotal
        const subtotal = GlobalCart.getTotalPrice();
        const subtotalEl = document.getElementById('subtotalPrice');
        if (subtotalEl) subtotalEl.textContent = subtotal + ' kr';

        // Calculate series discount
        const seriesDiscount = calculateSeriesDiscount(cart);
        const seriesDiscountEl = document.getElementById('seriesDiscount');
        const seriesDiscountAmountEl = document.getElementById('seriesDiscountAmount');
        if (seriesDiscount > 0 && seriesDiscountEl && seriesDiscountAmountEl) {
            seriesDiscountEl.style.display = 'block';
            seriesDiscountAmountEl.textContent = '-' + Math.round(seriesDiscount) + ' kr';
        } else if (seriesDiscountEl) {
            seriesDiscountEl.style.display = 'none';
        }

        const afterSeriesDiscount = subtotal - seriesDiscount;

        // Calculate Gravity ID discount for all riders in cart
        calculateGravityIdDiscount(afterSeriesDiscount);

        // Re-init Lucide icons
        if (typeof lucide !== 'undefined') lucide.createIcons();

        // Add remove handlers
        document.querySelectorAll('.remove-item').forEach(btn => {
            btn.addEventListener('click', function() {
                const eventId = parseInt(this.dataset.eventid);
                const riderId = parseInt(this.dataset.riderid);
                const classId = parseInt(this.dataset.classid);
                GlobalCart.removeItem(eventId, riderId, classId);
                renderCart();
            });
        });
    }

    // Calculate Gravity ID discount for ALL riders in cart (not just logged-in user)
    async function calculateGravityIdDiscount(subtotal) {
        const cart = GlobalCart.getCart();
        if (cart.length === 0) {
            totalPriceEl.textContent = '0 kr';
            updateVatDisplay(0);
            return;
        }

        // Get unique (rider_id, event_id) pairs from cart
        const pairs = [];
        const seen = new Set();
        cart.forEach(item => {
            const key = `${item.rider_id}_${item.event_id}`;
            if (!seen.has(key)) {
                seen.add(key);
                pairs.push({ riderId: item.rider_id, eventId: item.event_id });
            }
        });

        try {
            // Fetch Gravity ID discount for each (rider, event) pair
            const discountPromises = pairs.map(({ riderId, eventId }) =>
                fetch(`/api/gravity-id-discount.php?rider_id=${riderId}&event_id=${eventId}`)
                    .then(r => r.json())
                    .then(data => ({ riderId, eventId, discount: data.discount || 0, gravityId: data.gravity_id || null }))
                    .catch(() => ({ riderId, eventId, discount: 0, gravityId: null }))
            );

            const discountResults = await Promise.all(discountPromises);
            let totalDiscount = 0;
            let firstGravityId = null;

            // Update per-item Gravity ID indicators
            discountResults.forEach(({ riderId, eventId, discount, gravityId }) => {
                if (discount > 0) {
                    totalDiscount += discount;
                    if (!firstGravityId && gravityId) firstGravityId = gravityId;

                    document.querySelectorAll(`.cart-item-gravity[data-event-id="${eventId}"][data-rider-id="${riderId}"]`).forEach(el => {
                        el.innerHTML = '<i data-lucide="badge-check" style="width:12px;height:12px;"></i> Gravity ID: -' + discount + ' kr';
                        el.style.display = 'flex';
                    });
                }
            });
            if (typeof lucide !== 'undefined') lucide.createIcons();

            // Update summary UI
            const gravityIdDiscountEl = document.getElementById('gravityIdDiscount');
            const gravityIdAmountEl = document.getElementById('gravityIdAmount');
            const gravityIdLabelEl = document.getElementById('gravityIdLabel');

            if (totalDiscount > 0 && gravityIdDiscountEl && gravityIdAmountEl) {
                gravityIdDiscountEl.style.display = 'block';
                gravityIdAmountEl.textContent = '-' + Math.round(totalDiscount) + ' kr';
                if (gravityIdLabelEl && firstGravityId) {
                    gravityIdLabelEl.innerHTML = '<i data-lucide="badge-check" style="width: 16px; height: 16px;"></i> Gravity ID: ' + firstGravityId;
                }

                const finalTotal = Math.max(0, subtotal - totalDiscount);
                totalPriceEl.textContent = Math.round(finalTotal) + ' kr';
                updateVatDisplay(Math.round(finalTotal));
            } else {
                if (gravityIdDiscountEl) gravityIdDiscountEl.style.display = 'none';
                totalPriceEl.textContent = subtotal + ' kr';
                updateVatDisplay(subtotal);
            }
        } catch (error) {
            console.error('Error calculating Gravity ID discount:', error);
            totalPriceEl.textContent = subtotal + ' kr';
            updateVatDisplay(subtotal);
        }
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

        // Always create a fresh order from current cart contents
        // (old pending orders get cancelled automatically when a new order is created)
        sessionStorage.removeItem('pending_order_id');

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
                // Store order ID to prevent duplicate orders
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
