<?php
/**
 * Organizer App - Platsregistrering
 * Enkel vy för deltagare att registrera sig på plats
 */

require_once __DIR__ . '/config.php';
requireOrganizer();

$eventId = (int)($_GET['event'] ?? 0);
if (!$eventId) {
    header('Location: dashboard.php');
    exit;
}

requireEventAccess($eventId);

$event = getEventWithClasses($eventId);
if (!$event) {
    die('Eventet hittades inte.');
}

$pageTitle = $event['name'];
$showHeader = true;
$headerTitle = $event['name'];
$showBackButton = true;
$backUrl = 'dashboard.php';

include __DIR__ . '/includes/header.php';
?>

<div class="org-card">
    <div class="org-card__body">

        <!-- Steg 1: Sök ditt namn -->
        <div id="step-search">
            <h2 class="org-text-center" style="margin: 0 0 var(--space-lg) 0;">Sök ditt namn</h2>

            <div class="org-search" style="margin-bottom: var(--space-lg);">
                <i data-lucide="search" class="org-search__icon"></i>
                <input
                    type="text"
                    id="search-rider"
                    class="org-input"
                    placeholder="Skriv ditt namn..."
                    autocomplete="off"
                    style="font-size: var(--text-lg);"
                >
            </div>

            <div id="search-results" class="org-hidden" style="margin-bottom: var(--space-lg);"></div>

            <div class="org-text-center" style="margin-bottom: var(--space-md);">
                <span class="org-text-muted">Hittar du inte dig själv?</span>
            </div>

            <button type="button" id="btn-new-rider" class="org-btn org-btn--ghost org-btn--large org-btn--block">
                <i data-lucide="user-plus"></i>
                Registrera ny deltagare
            </button>
        </div>

        <!-- Steg 2: Fyll i uppgifter -->
        <div id="step-form" class="org-hidden">
            <h2 class="org-text-center" style="margin: 0 0 var(--space-lg) 0;">Dina uppgifter</h2>

            <form id="form-rider">
                <input type="hidden" id="rider_id" name="rider_id" value="">

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: var(--space-md);">
                    <div class="org-form-group">
                        <label class="org-label">Förnamn *</label>
                        <input type="text" id="first_name" name="first_name" class="org-input" required>
                    </div>
                    <div class="org-form-group">
                        <label class="org-label">Efternamn *</label>
                        <input type="text" id="last_name" name="last_name" class="org-input" required>
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: var(--space-md);">
                    <div class="org-form-group">
                        <label class="org-label">Födelseår</label>
                        <input type="number" id="birth_year" name="birth_year" class="org-input" placeholder="1990" min="1920" max="<?= date('Y') ?>">
                    </div>
                    <div class="org-form-group">
                        <label class="org-label">Kön</label>
                        <select id="gender" name="gender" class="org-select">
                            <option value="">Välj...</option>
                            <option value="M">Man</option>
                            <option value="F">Kvinna</option>
                        </select>
                    </div>
                </div>

                <div class="org-form-group">
                    <label class="org-label">E-post</label>
                    <input type="email" id="email" name="email" class="org-input" placeholder="namn@exempel.se">
                </div>

                <div class="org-form-group">
                    <label class="org-label">Telefon</label>
                    <input type="tel" id="phone" name="phone" class="org-input" placeholder="070-123 45 67">
                </div>

                <div class="org-form-group">
                    <label class="org-label">Klubb / Team</label>
                    <input type="text" id="club_name" name="club_name" class="org-input" placeholder="Valfritt">
                </div>

                <div class="org-form-group">
                    <label class="org-label">Licensnummer</label>
                    <input type="text" id="license_number" name="license_number" class="org-input" placeholder="Valfritt">
                </div>

                <div class="org-form-group">
                    <label class="org-label">ICE (nödkontakt)</label>
                    <input type="text" id="ice_contact" name="ice_contact" class="org-input" placeholder="Namn och telefon">
                </div>

                <div style="display: flex; gap: var(--space-md); margin-top: var(--space-lg);">
                    <button type="button" class="org-btn org-btn--ghost" onclick="showStep('search')">
                        <i data-lucide="arrow-left"></i>
                        Tillbaka
                    </button>
                    <button type="submit" class="org-btn org-btn--primary" style="flex: 1;">
                        Välj klass
                        <i data-lucide="arrow-right"></i>
                    </button>
                </div>
            </form>
        </div>

        <!-- Steg 3: Välj klass -->
        <div id="step-class" class="org-hidden">
            <div id="rider-info" class="org-text-center" style="margin-bottom: var(--space-lg);">
                <div id="rider-name" style="font-size: var(--text-xl); font-weight: var(--weight-semibold);"></div>
                <div id="rider-club" class="org-text-muted"></div>
            </div>

            <h2 class="org-text-center" style="margin: 0 0 var(--space-lg) 0;">Välj klass</h2>

            <div id="class-grid" class="org-class-grid">
                <?php foreach ($event['classes'] as $class): ?>
                    <button type="button"
                            class="org-class-btn"
                            data-class-id="<?= $class['id'] ?>"
                            data-class-name="<?= htmlspecialchars($class['display_name'] ?: $class['name']) ?>"
                            data-class-price="<?= (int)$class['onsite_price'] ?>">
                        <div class="org-class-btn__name">
                            <?= htmlspecialchars($class['display_name'] ?: $class['name']) ?>
                        </div>
                        <div class="org-class-btn__price">
                            <?= (int)$class['onsite_price'] ?> kr
                        </div>
                    </button>
                <?php endforeach; ?>
            </div>

            <div style="margin-top: var(--space-lg);">
                <button type="button" class="org-btn org-btn--ghost" onclick="showStep('form')">
                    <i data-lucide="arrow-left"></i>
                    Tillbaka
                </button>
            </div>
        </div>

        <!-- Steg 4: Betalning -->
        <div id="step-payment" class="org-hidden">
            <div class="org-text-center">
                <div id="payment-info" style="margin-bottom: var(--space-lg);">
                    <div id="payment-name" style="font-size: var(--text-lg); font-weight: var(--weight-semibold);"></div>
                    <div id="payment-class" class="org-text-muted"></div>
                </div>

                <div id="payment-amount" style="font-size: 48px; font-weight: var(--weight-bold); margin-bottom: var(--space-lg);"></div>

                <?php if (!empty($event['payment_config']['swish_number'])): ?>
                    <div style="background: var(--color-bg-surface); padding: var(--space-md); border-radius: var(--radius-lg); display: inline-block; margin-bottom: var(--space-md); border: 1px solid var(--color-border);">
                        <img id="swish-qr" src="" alt="Swish QR" style="width: 160px; height: 160px;">
                    </div>
                    <div class="org-text-muted" style="margin-bottom: var(--space-lg);">
                        Swish: <strong><?= formatSwishNumber($event['payment_config']['swish_number']) ?></strong>
                    </div>
                <?php endif; ?>

                <div style="display: flex; flex-direction: column; gap: var(--space-sm);">
                    <button type="button" id="btn-paid" class="org-btn org-btn--primary org-btn--large">
                        <i data-lucide="check"></i>
                        Jag har betalat
                    </button>
                    <button type="button" id="btn-pay-later" class="org-btn org-btn--ghost">
                        Betala senare
                    </button>
                    <button type="button" class="org-btn org-btn--ghost" onclick="showStep('class')">
                        <i data-lucide="arrow-left"></i>
                        Ändra klass
                    </button>
                </div>
            </div>
        </div>

        <!-- Steg 5: Klart -->
        <div id="step-done" class="org-hidden">
            <div class="org-text-center" style="padding: var(--space-xl) 0;">
                <div style="width: 64px; height: 64px; background: var(--color-success); border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto var(--space-md);">
                    <i data-lucide="check" style="width: 32px; height: 32px; color: white;"></i>
                </div>

                <h1 style="margin: 0 0 var(--space-xs) 0;">Registrerad!</h1>
                <div id="done-name" style="font-size: var(--text-lg);"></div>
                <div id="done-class" class="org-text-muted" style="margin-bottom: var(--space-lg);"></div>

                <div id="done-bib" style="font-size: 64px; font-weight: var(--weight-bold); color: var(--color-accent); margin-bottom: var(--space-lg);"></div>

                <div id="done-status" style="margin-bottom: var(--space-xl);"></div>

                <button type="button" id="btn-next" class="org-btn org-btn--primary org-btn--large org-btn--block">
                    <i data-lucide="plus"></i>
                    Nästa deltagare
                </button>
            </div>
        </div>

    </div>
</div>

<?php
$pageScripts = <<<SCRIPT
<script>
(function() {
    const eventId = {$eventId};
    const swishNumber = '{$event['payment_config']['swish_number']}';

    let rider = null;
    let selectedClass = null;

    // Sök
    const searchInput = document.getElementById('search-rider');
    const searchResults = document.getElementById('search-results');

    const doSearch = OrgApp.debounce(async function(q) {
        if (q.length < 2) {
            searchResults.classList.add('org-hidden');
            return;
        }

        try {
            const data = await OrgApp.api('search-rider.php', { query: q });
            if (data.riders && data.riders.length > 0) {
                searchResults.innerHTML = data.riders.map(r => \`
                    <div class="org-event-card" style="cursor: pointer;" data-rider='\${JSON.stringify(r).replace(/'/g, "&#39;")}'>
                        <div class="org-event-card__info">
                            <div class="org-event-card__name">\${r.firstname} \${r.lastname}</div>
                            <div class="org-event-card__meta">\${r.club_name || ''} \${r.birth_year ? '• ' + r.birth_year : ''}</div>
                        </div>
                        <i data-lucide="chevron-right" style="width: 20px; height: 20px; color: var(--color-text-muted);"></i>
                    </div>
                \`).join('');
                searchResults.classList.remove('org-hidden');
                lucide.createIcons();

                searchResults.querySelectorAll('.org-event-card').forEach(el => {
                    el.addEventListener('click', function() {
                        const r = JSON.parse(this.dataset.rider);
                        fillForm(r);
                        showStep('form');
                    });
                });
            } else {
                searchResults.innerHTML = '<div class="org-text-center org-text-muted" style="padding: var(--space-lg);">Ingen träff. Klicka "Registrera ny deltagare".</div>';
                searchResults.classList.remove('org-hidden');
            }
        } catch(e) { console.error(e); }
    }, 300);

    searchInput.addEventListener('input', function() { doSearch(this.value); });

    // Ny deltagare - töm formuläret
    document.getElementById('btn-new-rider').addEventListener('click', () => {
        clearForm();
        showStep('form');
    });

    // Fyll i formuläret med data
    function fillForm(r) {
        document.getElementById('rider_id').value = r.id || '';
        document.getElementById('first_name').value = r.firstname || '';
        document.getElementById('last_name').value = r.lastname || '';
        document.getElementById('birth_year').value = r.birth_year || '';
        document.getElementById('gender').value = r.gender || '';
        document.getElementById('email').value = r.email || '';
        document.getElementById('phone').value = r.phone || '';
        document.getElementById('club_name').value = r.club_name || '';
        document.getElementById('license_number').value = r.license_number || '';
        document.getElementById('ice_contact').value = r.ice_contact || '';
    }

    function clearForm() {
        document.getElementById('form-rider').reset();
        document.getElementById('rider_id').value = '';
    }

    // Formulär submit
    document.getElementById('form-rider').addEventListener('submit', function(e) {
        e.preventDefault();
        const fd = new FormData(this);
        rider = {
            id: fd.get('rider_id') || null,
            firstname: fd.get('first_name'),
            lastname: fd.get('last_name'),
            birth_year: fd.get('birth_year') || null,
            gender: fd.get('gender') || null,
            email: fd.get('email') || null,
            phone: fd.get('phone') || null,
            club_name: fd.get('club_name') || null,
            license_number: fd.get('license_number') || null,
            ice_contact: fd.get('ice_contact') || null
        };
        showStep('class');
        updateRiderDisplay();
    });

    function updateRiderDisplay() {
        document.getElementById('rider-name').textContent = rider.firstname + ' ' + rider.lastname;
        document.getElementById('rider-club').textContent = rider.club_name || '';
    }

    // Klassval
    document.querySelectorAll('.org-class-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            document.querySelectorAll('.org-class-btn').forEach(b => b.classList.remove('org-class-btn--selected'));
            this.classList.add('org-class-btn--selected');

            selectedClass = {
                id: this.dataset.classId,
                name: this.dataset.className,
                price: parseInt(this.dataset.classPrice, 10)
            };

            showStep('payment');
            updatePayment();
        });
    });

    function updatePayment() {
        document.getElementById('payment-name').textContent = rider.firstname + ' ' + rider.lastname;
        document.getElementById('payment-class').textContent = selectedClass.name;
        document.getElementById('payment-amount').textContent = selectedClass.price + ' kr';

        if (swishNumber && selectedClass.price > 0) {
            const qr = \`https://chart.googleapis.com/chart?cht=qr&chs=200x200&chl=C\${swishNumber.replace(/[^0-9]/g, '')};\${selectedClass.price * 100};&choe=UTF-8\`;
            document.getElementById('swish-qr').src = qr;
        }
    }

    // Betalning
    document.getElementById('btn-paid').addEventListener('click', () => createReg('paid'));
    document.getElementById('btn-pay-later').addEventListener('click', () => createReg('unpaid'));

    async function createReg(status) {
        const btn = event.target;
        OrgApp.showLoading(btn);

        try {
            const data = await OrgApp.api('create-registration.php', {
                event_id: eventId,
                rider_id: rider.id,
                first_name: rider.firstname,
                last_name: rider.lastname,
                birth_year: rider.birth_year,
                gender: rider.gender,
                email: rider.email,
                phone: rider.phone,
                club_name: rider.club_name,
                license_number: rider.license_number,
                ice_contact: rider.ice_contact,
                class_id: selectedClass.id,
                class_name: selectedClass.name,
                payment_status: status
            });

            if (data.success) {
                showDone(data.bib_number, status);
            } else {
                OrgApp.showAlert(data.error || 'Något gick fel');
            }
        } catch(e) {
            OrgApp.showAlert('Nätverksfel');
        } finally {
            OrgApp.hideLoading(btn);
        }
    }

    function showDone(bib, status) {
        document.getElementById('done-name').textContent = rider.firstname + ' ' + rider.lastname;
        document.getElementById('done-class').textContent = selectedClass.name;
        document.getElementById('done-bib').textContent = '#' + bib;
        document.getElementById('done-status').innerHTML = status === 'paid'
            ? '<span class="org-status org-status--paid">BETALD</span>'
            : '<span class="org-status org-status--unpaid">EJ BETALD</span>';
        showStep('done');
    }

    document.getElementById('btn-next').addEventListener('click', reset);

    function reset() {
        rider = null;
        selectedClass = null;
        searchInput.value = '';
        searchResults.classList.add('org-hidden');
        clearForm();
        document.querySelectorAll('.org-class-btn').forEach(b => b.classList.remove('org-class-btn--selected'));
        showStep('search');
    }

    window.showStep = function(step) {
        ['search', 'form', 'class', 'payment', 'done'].forEach(s => {
            document.getElementById('step-' + s).classList.add('org-hidden');
        });
        document.getElementById('step-' + step).classList.remove('org-hidden');
        lucide.createIcons();
    };

    lucide.createIcons();
})();
</script>
SCRIPT;

include __DIR__ . '/includes/footer.php';
?>
