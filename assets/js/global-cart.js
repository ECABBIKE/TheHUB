/**
 * Global Shopping Cart
 * Handles multi-event registration cart with localStorage persistence
 */

const GlobalCart = (function() {
    const STORAGE_KEY = 'thehub_cart';

    // Get cart from localStorage
    function getCart() {
        try {
            const cartJson = localStorage.getItem(STORAGE_KEY);
            return cartJson ? JSON.parse(cartJson) : [];
        } catch (e) {
            console.error('Error loading cart:', e);
            return [];
        }
    }

    // Save cart to localStorage
    function saveCart(cart) {
        try {
            localStorage.setItem(STORAGE_KEY, JSON.stringify(cart));
            updateCartUI();
            // Dispatch event for other components
            window.dispatchEvent(new CustomEvent('cartUpdated', { detail: { cart } }));
        } catch (e) {
            console.error('Error saving cart:', e);
        }
    }

    // Add item to cart
    function addItem(item) {
        const cart = getCart();

        // Validate required fields based on type
        if (item.type === 'festival_activity') {
            if (!item.activity_id || !item.rider_id || !item.festival_id) {
                throw new Error('Invalid festival activity item: missing required fields');
            }
            // Dedup: same activity + slot + rider
            const existingIndex = cart.findIndex(i =>
                i.type === 'festival_activity' &&
                i.activity_id === item.activity_id &&
                i.rider_id === item.rider_id &&
                (i.slot_id || null) === (item.slot_id || null)
            );
            if (existingIndex >= 0) {
                cart[existingIndex] = item;
            } else {
                cart.push(item);
            }
        } else if (item.type === 'festival_pass') {
            if (!item.festival_id || !item.rider_id) {
                throw new Error('Invalid festival pass item: missing required fields');
            }
            // Dedup: same festival + rider
            const existingIndex = cart.findIndex(i =>
                i.type === 'festival_pass' &&
                i.festival_id === item.festival_id &&
                i.rider_id === item.rider_id
            );
            if (existingIndex >= 0) {
                cart[existingIndex] = item;
            } else {
                cart.push(item);
            }
        } else {
            // Event/series registration - original validation
            if (!item.type || !item.event_id || !item.rider_id || !item.class_id) {
                throw new Error('Invalid cart item: missing required fields');
            }
            // Check if item already exists (same event + rider + class)
            const existingIndex = cart.findIndex(i =>
                i.event_id === item.event_id &&
                i.rider_id === item.rider_id &&
                i.class_id === item.class_id
            );
            if (existingIndex >= 0) {
                cart[existingIndex] = item;
            } else {
                cart.push(item);
            }
        }

        saveCart(cart);
        return cart;
    }

    // Remove item from cart
    function removeItem(eventId, riderId, classId) {
        let cart = getCart();
        cart = cart.filter(item =>
            !(item.event_id === eventId && item.rider_id === riderId && item.class_id === classId)
        );
        saveCart(cart);
        return cart;
    }

    // Remove festival item from cart
    function removeFestivalItem(type, id, riderId, slotId) {
        let cart = getCart();
        if (type === 'festival_activity') {
            cart = cart.filter(item => {
                if (item.type !== 'festival_activity' || item.activity_id !== id || item.rider_id !== riderId) return true;
                if (slotId !== undefined) return (item.slot_id || null) !== slotId;
                return false;
            });
        } else if (type === 'festival_pass') {
            cart = cart.filter(item =>
                !(item.type === 'festival_pass' && item.festival_id === id && item.rider_id === riderId)
            );
        }
        saveCart(cart);
        return cart;
    }

    // Clear entire cart
    function clearCart() {
        saveCart([]);
    }

    // Get cart item count
    function getItemCount() {
        return getCart().length;
    }

    // Get total price
    function getTotalPrice() {
        return getCart().reduce((sum, item) => sum + (parseFloat(item.price) || 0), 0);
    }

    // Group items by event (or festival for festival items)
    function getItemsByEvent() {
        const cart = getCart();
        const grouped = {};

        cart.forEach(item => {
            // Festival items group by festival_id with 'festival_' prefix
            if (item.type === 'festival_activity' || item.type === 'festival_pass' || item.festival_pass_event) {
                const key = 'festival_' + (item.festival_id || 0);
                if (!grouped[key]) {
                    grouped[key] = {
                        event_id: key,
                        event_name: item.festival_name || 'Festival',
                        event_date: item.festival_date || '',
                        is_festival: true,
                        festival_id: item.festival_id,
                        items: []
                    };
                }
                grouped[key].items.push(item);
            } else {
                if (!grouped[item.event_id]) {
                    grouped[item.event_id] = {
                        event_id: item.event_id,
                        event_name: item.event_name,
                        event_date: item.event_date,
                        items: []
                    };
                }
                grouped[item.event_id].items.push(item);
            }
        });

        return Object.values(grouped);
    }

    // Update cart UI (badge in header)
    function updateCartUI() {
        const count = getItemCount();
        const badges = document.querySelectorAll('.cart-badge');

        badges.forEach(badge => {
            if (count > 0) {
                badge.textContent = count;
                badge.style.display = 'flex';
            } else {
                badge.style.display = 'none';
            }
        });

        const totalElements = document.querySelectorAll('.cart-total');
        totalElements.forEach(el => {
            el.textContent = getTotalPrice() + ' kr';
        });
    }

    // Initialize
    function init() {
        updateCartUI();

        // Listen for storage changes from other tabs
        window.addEventListener('storage', function(e) {
            if (e.key === STORAGE_KEY) {
                updateCartUI();
            }
        });
    }

    // Public API
    return {
        init,
        getCart,
        addItem,
        removeItem,
        removeFestivalItem,
        clearCart,
        getItemCount,
        getTotalPrice,
        getItemsByEvent,
        updateCartUI
    };
})();

// Initialize on page load
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', GlobalCart.init);
} else {
    GlobalCart.init();
}

// Make globally available
window.GlobalCart = GlobalCart;
