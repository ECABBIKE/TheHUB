<?php
/**
 * Membership Page
 * Public page for viewing and purchasing memberships
 */

$pdo = hub_db();

// Get all active plans
$plans = [];
try {
    $stmt = $pdo->query("
        SELECT id, name, description, price_amount, currency, billing_interval,
               billing_interval_count, benefits, discount_percent, stripe_price_id
        FROM membership_plans
        WHERE active = 1
        ORDER BY sort_order, price_amount
    ");
    $plans = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Parse benefits JSON
    foreach ($plans as &$plan) {
        $plan['benefits'] = json_decode($plan['benefits'] ?? '[]', true);
    }
} catch (Exception $e) {
    // Tables might not exist yet
    error_log("Membership page error: " . $e->getMessage());
}

// Check if viewing success page
$success = isset($_GET['session_id']);
?>

<div class="page-header">
    <h1 class="page-title">
        <i data-lucide="users" class="page-icon"></i>
        Medlemskap
    </h1>
    <p class="page-subtitle">Bli medlem och få exklusiva förmåner</p>
</div>

<?php if ($success): ?>
<!-- Success Message -->
<div class="card" style="max-width: 600px; margin: var(--space-xl) auto; text-align: center;">
    <div class="card-body" style="padding: var(--space-2xl);">
        <div style="margin-bottom: var(--space-lg); color: var(--color-success);">
            <i data-lucide="check-circle" style="width: 64px; height: 64px;"></i>
        </div>
        <h2 style="color: var(--color-success); margin-bottom: var(--space-md);">Tack för ditt medlemskap!</h2>
        <p class="text-muted" style="margin-bottom: var(--space-lg);">
            Din prenumeration är nu aktiv. Du kommer att få ett bekräftelsemail inom kort.
        </p>
        <a href="/membership" class="btn btn-primary">Till medlemssidan</a>
    </div>
</div>

<?php elseif (empty($plans)): ?>
<!-- No plans yet -->
<div class="empty-state">
    <div class="empty-icon"><i data-lucide="users"></i></div>
    <h3>Medlemskap kommer snart</h3>
    <p>Vi arbetar på medlemskapsfunktioner. Kom tillbaka senare!</p>
</div>

<?php else: ?>
<!-- Membership Plans -->
<div class="membership-plans">
    <?php foreach ($plans as $index => $plan): ?>
        <div class="card plan-card <?= $index === 1 ? 'featured' : '' ?>">
            <?php if ($index === 1): ?>
                <div class="plan-badge">Populärt val</div>
            <?php endif; ?>

            <div class="card-body">
                <h2 class="plan-name"><?= htmlspecialchars($plan['name']) ?></h2>

                <?php if ($plan['description']): ?>
                    <p class="text-muted plan-desc"><?= htmlspecialchars($plan['description']) ?></p>
                <?php endif; ?>

                <div class="plan-price">
                    <span class="price-amount"><?= number_format($plan['price_amount'] / 100, 0) ?></span>
                    <span class="price-suffix">kr/<?= $plan['billing_interval'] === 'year' ? 'år' : 'mån' ?></span>
                </div>

                <?php if ($plan['discount_percent'] > 0): ?>
                    <div class="plan-discount">
                        <i data-lucide="percent"></i>
                        <?= $plan['discount_percent'] ?>% rabatt på alla anmälningar
                    </div>
                <?php endif; ?>

                <?php if (!empty($plan['benefits'])): ?>
                    <ul class="plan-benefits">
                        <?php foreach ($plan['benefits'] as $benefit): ?>
                            <li>
                                <i data-lucide="check"></i>
                                <?= htmlspecialchars($benefit) ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>

                <?php if ($plan['stripe_price_id']): ?>
                    <button class="btn btn-primary btn-block subscribe-btn"
                            data-plan-id="<?= $plan['id'] ?>"
                            data-plan-name="<?= htmlspecialchars($plan['name']) ?>">
                        Välj <?= htmlspecialchars($plan['name']) ?>
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
<div class="membership-footer">
    <p class="text-muted">Redan medlem?</p>
    <button class="btn btn-secondary" id="openPortalBtn">
        <i data-lucide="settings"></i> Hantera prenumeration
    </button>
</div>

<!-- Signup Modal -->
<dialog id="signupModal" class="hub-modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Bli medlem</h3>
            <button class="modal-close" onclick="this.closest('dialog').close()">
                <i data-lucide="x"></i>
            </button>
        </div>
        <form id="signupForm">
            <input type="hidden" name="plan_id" id="planId">
            <div class="modal-body">
                <p class="text-muted" style="margin-bottom: var(--space-lg);">
                    Fyll i dina uppgifter för att fortsätta till betalning.
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
                <button type="button" class="btn btn-secondary" onclick="this.closest('dialog').close()">Avbryt</button>
                <button type="submit" class="btn btn-primary" id="submitBtn">Fortsätt till betalning</button>
            </div>
        </form>
    </div>
</dialog>

<!-- Portal Modal -->
<dialog id="portalModal" class="hub-modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Hantera prenumeration</h3>
            <button class="modal-close" onclick="this.closest('dialog').close()">
                <i data-lucide="x"></i>
            </button>
        </div>
        <form id="portalForm">
            <div class="modal-body">
                <p class="text-muted" style="margin-bottom: var(--space-lg);">
                    Ange din e-postadress för att öppna Stripe kundportal.
                </p>
                <div class="form-group">
                    <label class="form-label">E-post *</label>
                    <input type="email" name="email" id="portalEmail" class="form-input" required>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="this.closest('dialog').close()">Avbryt</button>
                <button type="submit" class="btn btn-primary">Öppna portal</button>
            </div>
        </form>
    </div>
</dialog>

<style>
.membership-plans {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
    gap: var(--space-xl);
    max-width: 900px;
    margin: var(--space-xl) auto;
}

.plan-card {
    position: relative;
    transition: transform 0.2s, box-shadow 0.2s;
}
.plan-card:hover {
    transform: translateY(-4px);
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
    white-space: nowrap;
}

.plan-name {
    margin-bottom: var(--space-xs);
}
.plan-desc {
    margin-bottom: var(--space-lg);
}

.plan-price {
    margin: var(--space-lg) 0;
    display: flex;
    align-items: baseline;
    gap: var(--space-xs);
}
.price-amount {
    font-size: 2.5rem;
    font-weight: 700;
    font-family: var(--font-heading);
    color: var(--color-accent);
}
.price-suffix {
    font-size: 1rem;
    color: var(--color-text-muted);
}

.plan-discount {
    display: inline-flex;
    align-items: center;
    gap: var(--space-xs);
    background: var(--color-accent-light);
    color: var(--color-accent-text);
    padding: var(--space-xs) var(--space-sm);
    border-radius: var(--radius-full);
    font-size: 0.875rem;
    margin-bottom: var(--space-md);
}
.plan-discount i {
    width: 14px;
    height: 14px;
}

.plan-benefits {
    list-style: none;
    padding: 0;
    margin: var(--space-md) 0 var(--space-lg);
}
.plan-benefits li {
    display: flex;
    align-items: flex-start;
    gap: var(--space-sm);
    padding: var(--space-xs) 0;
    color: var(--color-text-secondary);
    font-size: 0.9rem;
}
.plan-benefits li i {
    width: 16px;
    height: 16px;
    color: var(--color-success);
    flex-shrink: 0;
    margin-top: 2px;
}

.btn-block {
    width: 100%;
}

.membership-footer {
    text-align: center;
    margin-top: var(--space-2xl);
    padding-top: var(--space-xl);
    border-top: 1px solid var(--color-border);
}
.membership-footer p {
    margin-bottom: var(--space-sm);
}

/* Modal styles */
.hub-modal {
    border: none;
    border-radius: var(--radius-lg);
    padding: 0;
    background: var(--color-bg-card);
    color: var(--color-text-primary);
    box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
    max-width: 400px;
    width: 90%;
}
.hub-modal::backdrop {
    background: rgba(0, 0, 0, 0.6);
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
.modal-header h3 {
    margin: 0;
}
.modal-close {
    background: none;
    border: none;
    color: var(--color-text-muted);
    cursor: pointer;
    padding: var(--space-xs);
}
.modal-close:hover {
    color: var(--color-text-primary);
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

/* Mobile edge-to-edge */
@media (max-width: 767px) {
    .membership-plans {
        grid-template-columns: 1fr;
    }
    .plan-card {
        margin-left: -16px;
        margin-right: -16px;
        border-radius: 0 !important;
        border-left: none !important;
        border-right: none !important;
        width: calc(100% + 32px);
    }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Subscribe buttons
    document.querySelectorAll('.subscribe-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            document.getElementById('planId').value = this.dataset.planId;
            document.getElementById('signupModal').showModal();
        });
    });

    // Open portal button
    document.getElementById('openPortalBtn')?.addEventListener('click', function() {
        document.getElementById('portalModal').showModal();
    });

    // Signup form
    document.getElementById('signupForm')?.addEventListener('submit', async function(e) {
        e.preventDefault();

        const btn = document.getElementById('submitBtn');
        const originalText = btn.textContent;
        btn.textContent = 'Laddar...';
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
                btn.textContent = originalText;
                btn.disabled = false;
            }
        } catch (err) {
            alert('Ett fel uppstod. Försök igen.');
            btn.textContent = originalText;
            btn.disabled = false;
        }
    });

    // Portal form
    document.getElementById('portalForm')?.addEventListener('submit', async function(e) {
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
                alert('Fel: ' + (data.error || 'Kunde inte öppna portalen'));
            }
        } catch (err) {
            alert('Ett fel uppstod. Försök igen.');
        }
    });
});
</script>
<?php endif; ?>
