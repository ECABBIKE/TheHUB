# TheHUB V3.0 – KOMPLETT PROMPT
## Del 4: CSS, JavaScript & API

---

# VIKTIGASTE CSS (tokens.css + theme.css)

Se tidigare prompter (thehub-v3-prompt.md) för komplett CSS.

**Nyckelvariabler:**
- Spacing: `--space-xs` till `--space-2xl`
- Colors: `--color-bg-*`, `--color-text-*`, `--color-accent`
- Layout: `--header-height: 56px`, `--nav-height: 64px`

---

# JAVASCRIPT

## /v3/assets/js/search.js - Live Search

```javascript
const LiveSearch = {
    debounceTimeout: null,
    
    init() {
        document.querySelectorAll('.live-search').forEach(container => {
            const input = container.querySelector('.live-search-input');
            const results = container.querySelector('.live-search-results');
            const type = container.dataset.searchType || 'all';
            
            input.addEventListener('input', () => {
                clearTimeout(this.debounceTimeout);
                const query = input.value.trim();
                
                if (query.length < 2) {
                    results.classList.add('hidden');
                    return;
                }
                
                this.debounceTimeout = setTimeout(() => this.search(query, type, results), 200);
            });
        });
    },
    
    async search(query, type, container) {
        const response = await fetch(`/v3/api/search.php?q=${encodeURIComponent(query)}&type=${type}`);
        const data = await response.json();
        
        container.innerHTML = data.results.map(item => `
            <div class="live-search-result" data-id="${item.id}" data-type="${item.type}">
                <div class="live-search-result-avatar">${item.initials}</div>
                <div class="live-search-result-info">
                    <span class="live-search-result-name">${item.name}</span>
                    <span class="live-search-result-meta">${item.meta}</span>
                </div>
            </div>
        `).join('') || '<div class="live-search-empty">Inga resultat</div>';
        
        container.classList.remove('hidden');
        
        container.querySelectorAll('.live-search-result').forEach(el => {
            el.addEventListener('click', () => {
                const { id, type } = el.dataset;
                window.location.href = `/v3/database/${type}/${id}`;
            });
        });
    }
};

document.addEventListener('DOMContentLoaded', () => LiveSearch.init());
```

---

## /v3/assets/js/registration.js - Anmälningsflöde

```javascript
const Registration = {
    participants: [],
    eventId: null,
    
    init() {
        this.eventId = document.querySelector('[data-event-id]')?.dataset.eventId;
        
        document.querySelector('[data-action="add-participant"]')?.addEventListener('click', () => {
            document.getElementById('participant-add-panel')?.classList.remove('hidden');
        });
        
        // Quick-add (dig själv, barn)
        document.querySelectorAll('[data-action="add-self"], [data-action="add-rider"]').forEach(btn => {
            btn.addEventListener('click', () => this.addExistingRider(btn.dataset.riderId));
        });
        
        // Checkout
        document.querySelector('[data-action="checkout"]')?.addEventListener('click', () => this.checkout());
    },
    
    async addExistingRider(riderId) {
        if (this.participants.find(p => p.rider_id === riderId)) {
            alert('Redan tillagd');
            return;
        }
        
        const response = await fetch(`/v3/api/registration.php?action=get_rider&rider_id=${riderId}&event_id=${this.eventId}`);
        const data = await response.json();
        
        this.participants.push({
            rider_id: riderId,
            name: data.name,
            class_id: data.suggested_class_id,
            class_name: data.suggested_class_name,
            price: data.price
        });
        
        this.updateUI();
    },
    
    updateUI() {
        const list = document.getElementById('selected-participants');
        const summary = document.getElementById('registration-summary');
        
        list.innerHTML = this.participants.map((p, i) => `
            <div class="registration-participant">
                <div class="participant-avatar">${p.name.charAt(0)}</div>
                <div class="participant-info">
                    <span class="participant-name">${p.name}</span>
                    <span class="participant-class">${p.class_name}</span>
                </div>
                <span class="participant-price">${p.price} kr</span>
                <button class="participant-remove" data-index="${i}">×</button>
            </div>
        `).join('');
        
        list.querySelectorAll('.participant-remove').forEach(btn => {
            btn.addEventListener('click', () => {
                this.participants.splice(btn.dataset.index, 1);
                this.updateUI();
            });
        });
        
        const total = this.participants.reduce((sum, p) => sum + p.price, 0);
        document.getElementById('summary-total').textContent = `${total} kr`;
        summary.style.display = this.participants.length ? 'block' : 'none';
    },
    
    async checkout() {
        const response = await fetch('/v3/api/registration.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ event_id: this.eventId, participants: this.participants })
        });
        
        const data = await response.json();
        if (data.checkout_url) {
            WooCommerce.openCheckout(data.checkout_url);
        }
    }
};

document.addEventListener('DOMContentLoaded', () => Registration.init());
```

---

## /v3/assets/js/woocommerce.js - Betalning

```javascript
const WooCommerce = {
    modal: null,
    iframe: null,
    
    init() {
        this.modal = document.getElementById('wc-modal');
        this.iframe = document.getElementById('wc-modal-iframe');
        
        document.querySelectorAll('[data-action="close-modal"]').forEach(btn => {
            btn.addEventListener('click', () => this.closeModal());
        });
        
        window.addEventListener('message', (e) => {
            if (e.data.type === 'payment_complete') {
                this.closeModal();
                window.location.href = '/v3/profile/registrations?success=1';
            }
        });
    },
    
    openCheckout(url) {
        this.iframe.src = url;
        this.modal.classList.remove('hidden');
        document.body.style.overflow = 'hidden';
    },
    
    closeModal() {
        this.modal.classList.add('hidden');
        this.iframe.src = '';
        document.body.style.overflow = '';
    }
};

document.addEventListener('DOMContentLoaded', () => WooCommerce.init());
```

---

# API ENDPOINTS

## /v3/api/search.php

```php
<?php
require_once dirname(__DIR__) . '/config.php';
header('Content-Type: application/json');

$query = trim($_GET['q'] ?? '');
$type = $_GET['type'] ?? 'all';

if (strlen($query) < 2) {
    echo json_encode(['results' => []]);
    exit;
}

$pdo = hub_db();
$results = [];

// Sök åkare
if ($type === 'all' || $type === 'riders') {
    $stmt = $pdo->prepare("
        SELECT r.id, r.first_name, r.last_name, c.name as club_name
        FROM riders r LEFT JOIN clubs c ON r.club_id = c.id
        WHERE CONCAT(r.first_name, ' ', r.last_name) LIKE ?
        LIMIT 10
    ");
    $stmt->execute(["%{$query}%"]);
    
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $results[] = [
            'id' => $row['id'],
            'type' => 'rider',
            'name' => $row['first_name'] . ' ' . $row['last_name'],
            'meta' => $row['club_name'] ?? '',
            'initials' => strtoupper(substr($row['first_name'], 0, 1))
        ];
    }
}

// Sök klubbar
if ($type === 'all' || $type === 'clubs') {
    $stmt = $pdo->prepare("SELECT id, name FROM clubs WHERE name LIKE ? LIMIT 10");
    $stmt->execute(["%{$query}%"]);
    
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $results[] = [
            'id' => $row['id'],
            'type' => 'club',
            'name' => $row['name'],
            'meta' => '',
            'initials' => strtoupper(substr($row['name'], 0, 1))
        ];
    }
}

echo json_encode(['results' => $results]);
```

---

## /v3/api/registration.php

```php
<?php
require_once dirname(__DIR__) . '/config.php';
header('Content-Type: application/json');

// GET: Hämta åkare-info
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $_GET['action'] === 'get_rider') {
    $riderId = intval($_GET['rider_id']);
    $eventId = intval($_GET['event_id']);
    
    $pdo = hub_db();
    $stmt = $pdo->prepare("SELECT * FROM riders WHERE id = ?");
    $stmt->execute([$riderId]);
    $rider = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Hämta klasser för eventet
    $stmt = $pdo->prepare("SELECT * FROM event_classes WHERE event_id = ?");
    $stmt->execute([$eventId]);
    $classes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // TODO: Välj rätt klass baserat på ålder/kön
    $suggested = $classes[0] ?? null;
    
    echo json_encode([
        'name' => $rider['first_name'] . ' ' . $rider['last_name'],
        'suggested_class_id' => $suggested['id'] ?? null,
        'suggested_class_name' => $suggested['name'] ?? 'Välj klass',
        'price' => $suggested['price'] ?? 0
    ]);
    exit;
}

// POST: Skapa anmälan
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    $eventId = intval($data['event_id']);
    $participants = $data['participants'];
    
    $pdo = hub_db();
    $pdo->beginTransaction();
    
    $registrationIds = [];
    foreach ($participants as $p) {
        $stmt = $pdo->prepare("
            INSERT INTO registrations (event_id, rider_id, class_id, status) 
            VALUES (?, ?, ?, 'pending')
        ");
        $stmt->execute([$eventId, $p['rider_id'], $p['class_id']]);
        $registrationIds[] = $pdo->lastInsertId();
    }
    
    $pdo->commit();
    
    // Returnera WooCommerce checkout URL
    echo json_encode([
        'checkout_url' => '/checkout/?registration=' . implode(',', $registrationIds)
    ]);
}
```

---

# DATABASTABELLER SOM BEHÖVS

```sql
-- Förälder-barn koppling
CREATE TABLE rider_parents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    parent_rider_id INT NOT NULL,
    child_rider_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (parent_rider_id) REFERENCES riders(id),
    FOREIGN KEY (child_rider_id) REFERENCES riders(id)
);

-- Klubb-admin
CREATE TABLE club_admins (
    id INT AUTO_INCREMENT PRIMARY KEY,
    rider_id INT NOT NULL,
    club_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (rider_id) REFERENCES riders(id),
    FOREIGN KEY (club_id) REFERENCES clubs(id)
);
```

---

# CHECKLISTA FÖR IMPLEMENTATION

1. [ ] Skapa /v3/ mapp med filstruktur
2. [ ] Koppla till befintlig databas via db.php
3. [ ] Implementera config.php med HUB_NAV
4. [ ] Skapa alla CSS-filer (tokens, theme, layout, components)
5. [ ] Implementera komponenter (header, nav-bottom, search-live)
6. [ ] Skapa sidor (dashboard, calendar, results, database, ranking, profile)
7. [ ] Implementera API-endpoints (search, registration)
8. [ ] Testa live-sök
9. [ ] Testa anmälningsflöde
10. [ ] Koppla WooCommerce för betalning
11. [ ] Lägg till PWA-stöd (manifest.json, sw.js)
12. [ ] Testa på mobil som PWA

---

# NÄSTA STEG

Kör dessa filer i Claude Code så bygger den hela strukturen. 

Börja med:
1. Hämta databasstruktur från V2
2. Skapa /v3/ med config.php och router.php
3. Bygg komponenterna steg för steg
4. Testa varje del innan nästa
