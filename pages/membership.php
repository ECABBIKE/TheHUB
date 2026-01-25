<?php
/**
 * Membership Page
 * Public page for viewing and purchasing memberships
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../config/database.php';

$db = hub_db();
$pageTitle = 'Medlemskap';

// Get all active plans
$plans = $db->query("
    SELECT id, name, description, price_amount, currency, billing_interval,
           billing_interval_count, benefits, discount_percent, stripe_price_id
    FROM membership_plans
    WHERE active = 1
    ORDER BY sort_order, price_amount
")->fetchAll(PDO::FETCH_ASSOC);

// Parse benefits JSON
foreach ($plans as &$plan) {
    $plan['benefits'] = json_decode($plan['benefits'] ?? '[]', true);
}

// Check if viewing success page
$success = isset($_GET['session_id']);

include __DIR__ . '/../includes/header.php';
?>

<main class="container">
    <?php if ($success): ?>
        <!-- Success Message -->
        <div class="card" style="max-width: 600px; margin: var(--space-2xl) auto; text-align: center;">
            <div class="card-body" style="padding: var(--space-2xl);">
                <div style="font-size: 4rem; margin-bottom: var(--space-lg);">
                    <i data-lucide="check-circle" style="color: var(--color-success); width: 80px; height: 80px;"></i>
                </div>
                <h1 style="color: var(--color-success);">Tack for ditt medlemskap!</h1>
                <p class="text-secondary" style="font-size: 1.1rem; margin: var(--space-lg) 0;">
                    Din prenumeration ar nu aktiv. Du kommer att fa ett bekraftelsemail inom kort.
                </p>
                <a href="/membership" class="btn btn-primary">Till medlemssidan</a>
            </div>
        </div>
    <?php else: ?>
        <!-- Membership Plans -->
        <div style="text-align: center; margin: var(--space-2xl) 0;">
            <h1>Bli medlem</h1>
            <p class="text-secondary" style="max-width: 600px; margin: var(--space-md) auto;">
                Bli medlem och fa exklusiva formaner, rabatter pa anmalningar och mycket mer.
            </p>
        </div>

        <?php if (empty($plans)): ?>
            <div class="alert alert-info" style="max-width: 600px; margin: 0 auto;">
                Medlemskap kommer snart! Kom tillbaka senare.
            </div>
        <?php else: ?>
            <div class="membership-plans" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: var(--space-xl); max-width: 1000px; margin: 0 auto;">
                <?php foreach ($plans as $index => $plan): ?>
                    <div class="card plan-card <?= $index === 1 ? 'featured' : '' ?>">
                        <?php if ($index === 1): ?>
                            <div class="plan-badge">Populart val</div>
                        <?php endif; ?>
                        <div class="card-body" style="padding: var(--space-xl);">
                            <h2 style="margin-bottom: var(--space-sm);"><?= htmlspecialchars($plan['name']) ?></h2>
                            <?php if ($plan['description']): ?>
                                <p class="text-muted" style="margin-bottom: var(--space-lg);"><?= htmlspecialchars($plan['description']) ?></p>
                            <?php endif; ?>

                            <div class="plan-price">
                                <span class="price-amount"><?= number_format($plan['price_amount'] / 100, 0) ?></span>
                                <span class="price-currency">kr</span>
                                <span class="price-interval">/<?= $plan['billing_interval'] === 'year' ? 'ar' : 'manad' ?></span>
                            </div>

                            <?php if ($plan['discount_percent'] > 0): ?>
                                <div class="plan-discount">
                                    <i data-lucide="percent"></i>
                                    <?= $plan['discount_percent'] ?>% rabatt pa alla anmalningar
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($plan['benefits'])): ?>
                                <ul class="plan-benefits">
                                    <?php foreach ($plan['benefits'] as $benefit): ?>
                                        <li>
                                            <i data-lucide="check" style="color: var(--color-success);"></i>
                                            <?= htmlspecialchars($benefit) ?>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>

                            <?php if ($plan['stripe_price_id']): ?>
                                <button class="btn btn-primary btn-block subscribe-btn"
                                        data-plan-id="<?= $plan['id'] ?>"
                                        data-plan-name="<?= htmlspecialchars($plan['name']) ?>">
                                    Valj <?= htmlspecialchars($plan['name']) ?>
                                </button>
                            <?php else: ?>
                                <button class="btn btn-secondary btn-block" disabled>
                                    Kommer snart
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Already a member? -->
            <div style="text-align: center; margin-top: var(--space-2xl);">
                <p class="text-muted">Redan medlem?</p>
                <button class="btn btn-secondary" onclick="openPortal()">
                    <i data-lucide="settings"></i> Hantera prenumeration
                </button>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</main>

<!-- Signup Modal -->
<dialog id="signupModal" class="modal">
    <div class="modal-content" style="max-width: 400px;">
        <div class="modal-header">
            <h3>Bli medlem</h3>
            <button onclick="this.closest('dialog').close()" class="btn btn-ghost">
                <i data-lucide="x"></i>
            </button>
        </div>
        <form id="signupForm">
            <input type="hidden" name="plan_id" id="planId">
            <div class="modal-body">
                <p class="text-muted" style="margin-bottom: var(--space-lg);">
                    Fyll i dina uppgifter for att fortsatta till betalning.
                </p>
                <div class="form-group">
                    <label class="form-label">Namn *</label>
                    <input type="text" name="name" id="signupName" class="form-input" required>
                </div>
                <div class="form-group">
                    <label class="form-label">E-post *</label>
                    <input type="email" name="email" id="signupEmail" class="form-input" required>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" onclick="this.closest('dialog').close()" class="btn btn-secondary">Avbryt</button>
                <button type="submit" class="btn btn-primary" id="submitBtn">
                    <span class="btn-text">Fortsatt till betalning</span>
                    <span class="btn-loading" style="display: none;">
                        <i data-lucide="loader-2" class="spin"></i> Laddar...
                    </span>
                </button>
            </div>
        </form>
    </div>
</dialog>

<!-- Portal Modal -->
<dialog id="portalModal" class="modal">
    <div class="modal-content" style="max-width: 400px;">
        <div class="modal-header">
            <h3>Hantera prenumeration</h3>
            <button onclick="this.closest('dialog').close()" class="btn btn-ghost">
                <i data-lucide="x"></i>
            </button>
        </div>
        <form id="portalForm">
            <div class="modal-body">
                <p class="text-muted" style="margin-bottom: var(--space-lg);">
                    Ange din e-postadress for att oppna Stripe kundportal.
                </p>
                <div class="form-group">
                    <label class="form-label">E-post *</label>
                    <input type="email" name="email" id="portalEmail" class="form-input" required>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" onclick="this.closest('dialog').close()" class="btn btn-secondary">Avbryt</button>
                <button type="submit" class="btn btn-primary">Oppna portal</button>
            </div>
        </form>
    </div>
</dialog>

<style>
.plan-card {
    position: relative;
    transition: transform 0.2s, box-shadow 0.2s;
}
.plan-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 12px 40px rgba(0, 0, 0, 0.15);
}
.plan-card.featured {
    border: 2px solid var(--color-accent);
}
.plan-badge {
    position: absolute;
    top: -12px;
    left: 50%;
    transform: translateX(-50%);
    background: var(--color-accent);
    color: var(--color-bg-page);
    padding: var(--space-2xs) var(--space-md);
    border-radius: var(--radius-full);
    font-size: 0.85rem;
    font-weight: 600;
}
.plan-price {
    margin: var(--space-lg) 0;
}
.price-amount {
    font-size: 3rem;
    font-weight: 700;
    font-family: var(--font-heading);
    color: var(--color-accent);
}
.price-currency {
    font-size: 1.5rem;
    color: var(--color-text-secondary);
}
.price-interval {
    font-size: 1rem;
    color: var(--color-text-muted);
}
.plan-discount {
    display: inline-flex;
    align-items: center;
    gap: var(--space-xs);
    background: var(--color-accent-light);
    color: var(--color-accent-text);
    padding: var(--space-xs) var(--space-md);
    border-radius: var(--radius-full);
    font-size: 0.9rem;
    margin-bottom: var(--space-lg);
}
.plan-discount i {
    width: 16px;
    height: 16px;
}
.plan-benefits {
    list-style: none;
    padding: 0;
    margin: var(--space-lg) 0;
}
.plan-benefits li {
    display: flex;
    align-items: flex-start;
    gap: var(--space-sm);
    padding: var(--space-sm) 0;
    color: var(--color-text-secondary);
}
.plan-benefits li i {
    width: 18px;
    height: 18px;
    flex-shrink: 0;
    margin-top: 2px;
}
.btn-block {
    width: 100%;
    margin-top: var(--space-lg);
}

/* Modal styles */
.modal {
    border: none;
    border-radius: var(--radius-lg);
    padding: 0;
    background: var(--color-bg-card);
    color: var(--color-text-primary);
    box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
}
.modal::backdrop {
    background: rgba(0, 0, 0, 0.5);
}
.modal-content {
    width: 100%;
}
.modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: var(--space-lg);
    border-bottom: 1px solid var(--color-border);
}
.modal-body {
    padding: var(--space-lg);
}
.modal-footer {
    display: flex;
    justify-content: flex-end;
    gap: var(--space-sm);
    padding: var(--space-lg);
    border-top: 1px solid var(--color-border);
}
.spin {
    animation: spin 1s linear infinite;
}
@keyframes spin {
    from { transform: rotate(0deg); }
    to { transform: rotate(360deg); }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Subscribe buttons
    document.querySelectorAll('.subscribe-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const planId = this.dataset.planId;
            document.getElementById('planId').value = planId;
            document.getElementById('signupModal').showModal();
        });
    });

    // Signup form
    document.getElementById('signupForm').addEventListener('submit', async function(e) {
        e.preventDefault();

        const btn = document.getElementById('submitBtn');
        btn.querySelector('.btn-text').style.display = 'none';
        btn.querySelector('.btn-loading').style.display = 'inline-flex';
        btn.disabled = true;

        try {
            const response = await fetch('/api/memberships.php?action=create_checkout', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    plan_id: document.getElementById('planId').value,
                    name: document.getElementById('signupName').value,
                    email: document.getElementById('signupEmail').value,
                    success_url: window.location.origin + '/membership?session_id={CHECKOUT_SESSION_ID}',
                    cancel_url: window.location.origin + '/membership'
                })
            });

            const data = await response.json();

            if (data.success && data.checkout_url) {
                window.location.href = data.checkout_url;
            } else {
                alert('Fel: ' + (data.error || 'Kunde inte skapa betalningssession'));
                btn.querySelector('.btn-text').style.display = 'inline';
                btn.querySelector('.btn-loading').style.display = 'none';
                btn.disabled = false;
            }
        } catch (err) {
            alert('Ett fel uppstod. Forsok igen.');
            btn.querySelector('.btn-text').style.display = 'inline';
            btn.querySelector('.btn-loading').style.display = 'none';
            btn.disabled = false;
        }
    });

    // Portal form
    document.getElementById('portalForm').addEventListener('submit', async function(e) {
        e.preventDefault();

        try {
            const response = await fetch('/api/memberships.php?action=create_portal', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    email: document.getElementById('portalEmail').value,
                    return_url: window.location.href
                })
            });

            const data = await response.json();

            if (data.success && data.portal_url) {
                window.location.href = data.portal_url;
            } else {
                alert('Fel: ' + (data.error || 'Kunde inte oppna portalen'));
            }
        } catch (err) {
            alert('Ett fel uppstod. Forsok igen.');
        }
    });
});

function openPortal() {
    document.getElementById('portalModal').showModal();
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
