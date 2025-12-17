<?php
/**
 * Organizer App - Registration Flow
 * Huvudskärm för att registrera deltagare på plats
 *
 * Flöde:
 * 1. Välj typ (befintlig åkare / ny åkare)
 * 2. Sök/fyll i uppgifter
 * 3. Välj klass
 * 4. Betalning
 * 5. Bekräftelse
 */

require_once __DIR__ . '/config.php';
requireOrganizer();

// Hämta event
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

// Räkna registreringar
$counts = countEventRegistrations($eventId);

$pageTitle = 'Registrering';
$showHeader = true;
$headerTitle = $event['name'];
$headerSubtitle = (new DateTime($event['date']))->format('j M Y');
$showBackButton = true;
$backUrl = 'dashboard.php';
$showLogout = true;

include __DIR__ . '/includes/header.php';
?>

<!-- Statistik -->
<div class="org-stats">
    <div class="org-stat">
        <div class="org-stat__value"><?= (int)$counts['total'] ?></div>
        <div class="org-stat__label">Totalt</div>
    </div>
    <div class="org-stat">
        <div class="org-stat__value"><?= (int)$counts['onsite'] ?></div>
        <div class="org-stat__label">Plats</div>
    </div>
    <div class="org-stat">
        <div class="org-stat__value"><?= (int)$counts['paid'] ?></div>
        <div class="org-stat__label">Betalda</div>
    </div>
    <div class="org-stat">
        <div class="org-stat__value"><?= (int)$counts['unpaid'] ?></div>
        <div class="org-stat__label">Obetalda</div>
    </div>
</div>

<!-- Snabbåtgärder -->
<div class="org-flex org-gap-md org-mb-lg">
    <a href="participants.php?event=<?= $eventId ?>" class="org-btn org-btn--secondary" style="flex:1">
        <i data-lucide="users"></i>
        Deltagarlista
    </a>
    <a href="export.php?event=<?= $eventId ?>" class="org-btn org-btn--secondary" style="flex:1">
        <i data-lucide="download"></i>
        Exportera
    </a>
</div>

<!-- Registreringsflöde -->
<div class="org-card">
    <div class="org-card__body">

        <!-- Steg 1: Välj typ -->
        <div id="step-type" class="reg-step">
            <h2 class="org-text-center org-mb-lg">Ny registrering</h2>

            <div class="org-search org-mb-lg">
                <i data-lucide="search" class="org-search__icon"></i>
                <input
                    type="text"
                    id="search-rider"
                    class="org-input org-input--large"
                    placeholder="Sök åkare (namn eller licens)..."
                    autocomplete="off"
                >
            </div>

            <!-- Sökresultat -->
            <div id="search-results" class="org-rider-list org-mb-lg org-hidden"></div>

            <!-- Eller ny åkare -->
            <div class="org-text-center org-mb-md">
                <span class="org-text-muted">eller</span>
            </div>

            <button type="button" id="btn-new-rider" class="org-btn org-btn--ghost org-btn--large org-btn--block">
                <i data-lucide="user-plus"></i>
                Registrera ny åkare
            </button>
        </div>

        <!-- Steg 2: Ny åkare-formulär -->
        <div id="step-new-rider" class="reg-step org-hidden">
            <div class="org-steps">
                <div class="org-step org-step--active"></div>
                <div class="org-step"></div>
                <div class="org-step"></div>
            </div>

            <h2 class="org-text-center org-mb-lg">Fyll i uppgifter</h2>

            <form id="form-new-rider">
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                    <div class="org-form-group">
                        <label class="org-label" for="first_name">Förnamn *</label>
                        <input type="text" id="first_name" name="first_name" class="org-input" required>
                    </div>
                    <div class="org-form-group">
                        <label class="org-label" for="last_name">Efternamn *</label>
                        <input type="text" id="last_name" name="last_name" class="org-input" required>
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                    <div class="org-form-group">
                        <label class="org-label" for="birth_year">Födelseår</label>
                        <input type="number" id="birth_year" name="birth_year" class="org-input"
                               placeholder="T.ex. 1990" min="1920" max="<?= date('Y') ?>">
                    </div>
                    <div class="org-form-group">
                        <label class="org-label" for="gender">Kön</label>
                        <select id="gender" name="gender" class="org-select">
                            <option value="">Välj...</option>
                            <option value="M">Man</option>
                            <option value="F">Kvinna</option>
                        </select>
                    </div>
                </div>

                <div class="org-form-group">
                    <label class="org-label" for="email">E-post</label>
                    <input type="email" id="email" name="email" class="org-input" placeholder="namn@exempel.se">
                </div>

                <div class="org-form-group">
                    <label class="org-label" for="phone">Telefon</label>
                    <input type="tel" id="phone" name="phone" class="org-input" placeholder="070-123 45 67">
                </div>

                <div class="org-form-group">
                    <label class="org-label" for="club_name">Klubb</label>
                    <input type="text" id="club_name" name="club_name" class="org-input" placeholder="Klubbnamn (valfritt)">
                </div>

                <div class="org-form-group">
                    <label class="org-label" for="license_number">Licensnummer</label>
                    <input type="text" id="license_number" name="license_number" class="org-input" placeholder="Valfritt">
                </div>

                <div class="org-flex org-gap-md">
                    <button type="button" class="org-btn org-btn--ghost" onclick="showStep('type')">
                        <i data-lucide="arrow-left"></i>
                        Tillbaka
                    </button>
                    <button type="submit" class="org-btn org-btn--primary" style="flex:1">
                        Välj klass
                        <i data-lucide="arrow-right"></i>
                    </button>
                </div>
            </form>
        </div>

        <!-- Steg 3: Välj klass -->
        <div id="step-class" class="reg-step org-hidden">
            <div class="org-steps">
                <div class="org-step org-step--completed"></div>
                <div class="org-step org-step--active"></div>
                <div class="org-step"></div>
            </div>

            <div id="selected-rider-info" class="org-mb-lg" style="text-align: center;">
                <!-- Fylls i dynamiskt -->
            </div>

            <h2 class="org-text-center org-mb-lg">Välj klass</h2>

            <div id="class-grid" class="org-class-grid">
                <?php foreach ($event['classes'] as $class): ?>
                    <button type="button"
                            class="org-class-btn"
                            data-class-id="<?= $class['id'] ?>"
                            data-class-name="<?= htmlspecialchars($class['name']) ?>"
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

            <?php if (empty($event['classes'])): ?>
                <div class="org-alert org-alert--warning">
                    Inga klasser konfigurerade för detta event. Kontakta administratören.
                </div>
            <?php endif; ?>

            <div class="org-mt-lg">
                <button type="button" class="org-btn org-btn--ghost" onclick="goBackFromClass()">
                    <i data-lucide="arrow-left"></i>
                    Tillbaka
                </button>
            </div>
        </div>

        <!-- Steg 4: Betalning -->
        <div id="step-payment" class="reg-step org-hidden">
            <div class="org-steps">
                <div class="org-step org-step--completed"></div>
                <div class="org-step org-step--completed"></div>
                <div class="org-step org-step--active"></div>
            </div>

            <div class="org-payment">
                <div id="payment-summary" class="org-mb-lg">
                    <!-- Fylls i dynamiskt -->
                </div>

                <div class="org-payment__amount" id="payment-amount">0 kr</div>

                <?php if ($event['payment_config'] && !empty($event['payment_config']['swish_number'])): ?>
                    <div class="org-payment__qr">
                        <img id="swish-qr" src="" alt="Swish QR-kod">
                    </div>

                    <div class="org-payment__swish-info">
                        <div>Swish-nummer:</div>
                        <div class="org-payment__swish-number">
                            <?= formatSwishNumber($event['payment_config']['swish_number']) ?>
                        </div>
                        <?php if ($event['payment_config']['swish_name']): ?>
                            <div class="org-mt-md org-text-muted">
                                <?= htmlspecialchars($event['payment_config']['swish_name']) ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="org-alert org-alert--warning org-mb-lg">
                        Swish ej konfigurerat för detta event.
                    </div>
                <?php endif; ?>

                <div class="org-payment__actions">
                    <button type="button" id="btn-payment-received" class="org-btn org-btn--primary org-btn--large">
                        <i data-lucide="check"></i>
                        Betalning mottagen
                    </button>

                    <button type="button" id="btn-payment-cash" class="org-btn org-btn--secondary org-btn--large">
                        <i data-lucide="banknote"></i>
                        Kontant betalning
                    </button>

                    <button type="button" id="btn-payment-later" class="org-btn org-btn--ghost">
                        Betalas senare
                    </button>

                    <button type="button" class="org-btn org-btn--ghost" onclick="showStep('class')">
                        <i data-lucide="arrow-left"></i>
                        Ändra klass
                    </button>
                </div>
            </div>
        </div>

        <!-- Steg 5: Bekräftelse -->
        <div id="step-confirmation" class="reg-step org-hidden">
            <div class="org-confirmation">
                <div class="org-confirmation__icon">
                    <i data-lucide="check"></i>
                </div>

                <h1 class="org-confirmation__title">Registrerad!</h1>

                <div id="confirmation-name" style="font-size: 24px; margin-bottom: 8px;"></div>
                <div id="confirmation-class" class="org-text-muted"></div>

                <div class="org-confirmation__bib" id="confirmation-bib">#000</div>

                <div id="confirmation-payment-status" class="org-mb-lg"></div>

                <button type="button" id="btn-next-registration" class="org-btn org-btn--primary org-btn--large org-btn--block">
                    <i data-lucide="plus"></i>
                    Registrera nästa åkare
                </button>
            </div>
        </div>

    </div>
</div>

<?php
$pageScripts = <<<'SCRIPT'
<script>
(function() {
    // State
    let currentStep = 'type';
    let selectedRider = null;  // { id, first_name, last_name, ... } eller null för ny
    let selectedClass = null;  // { id, name, price }
    let registrationId = null;
    let bibNumber = null;

    const eventId = <?= $eventId ?>;
    const swishNumber = '<?= $event['payment_config']['swish_number'] ?? '' ?>';

    // DOM Elements
    const steps = {
        type: document.getElementById('step-type'),
        newRider: document.getElementById('step-new-rider'),
        class: document.getElementById('step-class'),
        payment: document.getElementById('step-payment'),
        confirmation: document.getElementById('step-confirmation')
    };

    // Sök åkare
    const searchInput = document.getElementById('search-rider');
    const searchResults = document.getElementById('search-results');

    const searchRiders = OrgApp.debounce(async function(query) {
        if (query.length < 2) {
            searchResults.classList.add('org-hidden');
            return;
        }

        try {
            const data = await OrgApp.api('search-rider.php', { query });

            if (data.riders && data.riders.length > 0) {
                searchResults.innerHTML = data.riders.map(rider => `
                    <div class="org-rider-item" data-rider='${JSON.stringify(rider).replace(/'/g, "&#39;")}'>
                        <div>
                            <div class="org-rider-item__name">${rider.firstname} ${rider.lastname}</div>
                            <div class="org-rider-item__details">
                                ${rider.license_number ? `Licens: ${rider.license_number}` : ''}
                                ${rider.club_name ? ` • ${rider.club_name}` : ''}
                                ${rider.birth_year ? ` • ${rider.birth_year}` : ''}
                            </div>
                        </div>
                        <i data-lucide="chevron-right"></i>
                    </div>
                `).join('');

                searchResults.classList.remove('org-hidden');
                lucide.createIcons();

                // Lägg till klick-hanterare
                searchResults.querySelectorAll('.org-rider-item').forEach(item => {
                    item.addEventListener('click', function() {
                        const rider = JSON.parse(this.dataset.rider);
                        selectExistingRider(rider);
                    });
                });
            } else {
                searchResults.innerHTML = '<div class="org-text-center org-text-muted" style="padding: 24px;">Ingen åkare hittades. Använd "Registrera ny åkare".</div>';
                searchResults.classList.remove('org-hidden');
            }
        } catch (err) {
            console.error(err);
        }
    }, 300);

    searchInput.addEventListener('input', function() {
        searchRiders(this.value);
    });

    // Välj befintlig åkare
    function selectExistingRider(rider) {
        selectedRider = {
            id: rider.id,
            first_name: rider.firstname,
            last_name: rider.lastname,
            birth_year: rider.birth_year,
            gender: rider.gender,
            club_name: rider.club_name,
            license_number: rider.license_number,
            email: rider.email || '',
            phone: rider.phone || ''
        };

        showStep('class');
        updateRiderInfo();
    }

    // Ny åkare-knapp
    document.getElementById('btn-new-rider').addEventListener('click', function() {
        selectedRider = null;
        showStep('newRider');
    });

    // Ny åkare-formulär
    document.getElementById('form-new-rider').addEventListener('submit', function(e) {
        e.preventDefault();

        const formData = new FormData(this);
        selectedRider = {
            id: null,
            first_name: formData.get('first_name'),
            last_name: formData.get('last_name'),
            birth_year: formData.get('birth_year') || null,
            gender: formData.get('gender') || null,
            email: formData.get('email') || null,
            phone: formData.get('phone') || null,
            club_name: formData.get('club_name') || null,
            license_number: formData.get('license_number') || null
        };

        showStep('class');
        updateRiderInfo();
    });

    // Uppdatera åkarinfo i klassväljaren
    function updateRiderInfo() {
        const infoEl = document.getElementById('selected-rider-info');
        if (selectedRider) {
            infoEl.innerHTML = `
                <div style="font-size: 24px; font-weight: 600;">${selectedRider.first_name} ${selectedRider.last_name}</div>
                <div class="org-text-muted">${selectedRider.club_name || 'Ingen klubb'}</div>
            `;
        }
    }

    // Klassväljare
    document.querySelectorAll('.org-class-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            // Ta bort tidigare val
            document.querySelectorAll('.org-class-btn').forEach(b => b.classList.remove('org-class-btn--selected'));

            // Markera detta val
            this.classList.add('org-class-btn--selected');

            selectedClass = {
                id: this.dataset.classId,
                name: this.dataset.className,
                price: parseInt(this.dataset.classPrice, 10)
            };

            // Gå till betalning
            showStep('payment');
            updatePaymentInfo();
        });
    });

    // Uppdatera betalningsinformation
    function updatePaymentInfo() {
        document.getElementById('payment-amount').textContent = selectedClass.price + ' kr';

        document.getElementById('payment-summary').innerHTML = `
            <div style="font-size: 20px; font-weight: 600;">${selectedRider.first_name} ${selectedRider.last_name}</div>
            <div class="org-text-muted">${selectedClass.name}</div>
        `;

        // Generera Swish QR
        if (swishNumber && selectedClass.price > 0) {
            const qrUrl = `https://chart.googleapis.com/chart?cht=qr&chs=200x200&chl=C${swishNumber.replace(/[^0-9]/g, '')};${selectedClass.price * 100};&choe=UTF-8`;
            document.getElementById('swish-qr').src = qrUrl;
        }
    }

    // Betalningsknappar
    document.getElementById('btn-payment-received').addEventListener('click', function() {
        createRegistration('paid');
    });

    document.getElementById('btn-payment-cash').addEventListener('click', function() {
        createRegistration('paid');
    });

    document.getElementById('btn-payment-later').addEventListener('click', function() {
        createRegistration('unpaid');
    });

    // Skapa registrering
    async function createRegistration(paymentStatus) {
        const btn = event.target;
        OrgApp.showLoading(btn);

        try {
            const data = await OrgApp.api('create-registration.php', {
                event_id: eventId,
                rider_id: selectedRider.id,
                first_name: selectedRider.first_name,
                last_name: selectedRider.last_name,
                birth_year: selectedRider.birth_year,
                gender: selectedRider.gender,
                email: selectedRider.email,
                phone: selectedRider.phone,
                club_name: selectedRider.club_name,
                license_number: selectedRider.license_number,
                class_id: selectedClass.id,
                class_name: selectedClass.name,
                payment_status: paymentStatus
            });

            if (data.success) {
                registrationId = data.registration_id;
                bibNumber = data.bib_number;

                showConfirmation(paymentStatus);
            } else {
                OrgApp.showAlert(data.error || 'Något gick fel');
            }
        } catch (err) {
            OrgApp.showAlert('Nätverksfel. Försök igen.');
            console.error(err);
        } finally {
            OrgApp.hideLoading(btn);
        }
    }

    // Visa bekräftelse
    function showConfirmation(paymentStatus) {
        document.getElementById('confirmation-name').textContent = `${selectedRider.first_name} ${selectedRider.last_name}`;
        document.getElementById('confirmation-class').textContent = selectedClass.name;
        document.getElementById('confirmation-bib').textContent = `#${bibNumber}`;

        const statusEl = document.getElementById('confirmation-payment-status');
        if (paymentStatus === 'paid') {
            statusEl.innerHTML = '<span class="org-status org-status--paid">BETALD</span>';
        } else {
            statusEl.innerHTML = '<span class="org-status org-status--unpaid">EJ BETALD</span>';
        }

        showStep('confirmation');
    }

    // Nästa registrering
    document.getElementById('btn-next-registration').addEventListener('click', function() {
        resetFlow();
    });

    // Återställ flödet
    function resetFlow() {
        selectedRider = null;
        selectedClass = null;
        registrationId = null;
        bibNumber = null;

        // Rensa formulär
        document.getElementById('form-new-rider').reset();
        searchInput.value = '';
        searchResults.classList.add('org-hidden');

        // Avmarkera klasser
        document.querySelectorAll('.org-class-btn').forEach(b => b.classList.remove('org-class-btn--selected'));

        showStep('type');

        // Uppdatera statistik (ladda om sidan för enkelhet)
        // I produktion: hämta uppdaterad statistik via API
    }

    // Visa steg
    window.showStep = function(step) {
        Object.values(steps).forEach(el => el.classList.add('org-hidden'));
        steps[step].classList.remove('org-hidden');
        currentStep = step;
        lucide.createIcons();
    };

    // Tillbaka från klassval
    window.goBackFromClass = function() {
        if (selectedRider && selectedRider.id) {
            // Befintlig åkare - gå till sök
            showStep('type');
        } else {
            // Ny åkare - gå till formuläret
            showStep('newRider');
        }
    };

    // Init
    lucide.createIcons();
})();
</script>
SCRIPT;

include __DIR__ . '/includes/footer.php';
?>
