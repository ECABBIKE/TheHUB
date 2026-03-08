# Festival-system för TheHUB - Implementeringsplan

> Skapad: 2026-03-08

## Sammanfattning

Festivaler är en **hybrid-entitet** som grupperar befintliga tävlingsevent OCH har egna aktiviteter (clinics, föreläsningar, grouprides, workshops). Ett tävlingsevent kan tillhöra BÅDE en serie OCH en festival samtidigt.

**Anmälningsmodell:** Festivalpass + à la carte (köp pass ELLER enskilda aktiviteter)
**Behörighet:** Admin + Promotorer kan skapa/hantera festivaler
**Biljettköp:** Full integration med TheHUB:s checkout (Stripe/Swish)

---

## Fas 1: Databasschema & Grundstruktur

### Nya tabeller

#### `festivals` (Huvudtabell)
```sql
CREATE TABLE festivals (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    slug VARCHAR(100) UNIQUE,
    description TEXT,
    short_description VARCHAR(500),
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    location VARCHAR(255),
    venue_id INT NULL,
    logo_media_id INT NULL,
    header_banner_media_id INT NULL,
    website VARCHAR(255),
    contact_email VARCHAR(255),
    contact_phone VARCHAR(50),
    venue_coordinates VARCHAR(100),
    venue_map_url VARCHAR(255),

    -- Pass/biljett
    pass_enabled TINYINT(1) DEFAULT 0,
    pass_name VARCHAR(100) DEFAULT 'Festivalpass',
    pass_description TEXT,
    pass_price DECIMAL(10,2) NULL,
    pass_max_quantity INT NULL,

    -- Ekonomi
    payment_recipient_id INT NULL,

    -- Status & visning
    status ENUM('draft','published','completed','cancelled') DEFAULT 'draft',
    active TINYINT(1) DEFAULT 1,

    -- Sponsorer (ärv-mönster från events)
    inherit_series_sponsors VARCHAR(100) DEFAULT '',

    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (venue_id) REFERENCES venues(id) ON DELETE SET NULL,
    FOREIGN KEY (logo_media_id) REFERENCES media(id) ON DELETE SET NULL,
    FOREIGN KEY (header_banner_media_id) REFERENCES media(id) ON DELETE SET NULL,
    FOREIGN KEY (payment_recipient_id) REFERENCES payment_recipients(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

#### `festival_events` (Junction: festival → befintliga tävlingsevent)
```sql
CREATE TABLE festival_events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    festival_id INT NOT NULL,
    event_id INT NOT NULL,
    sort_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uk_festival_event (festival_id, event_id),
    FOREIGN KEY (festival_id) REFERENCES festivals(id) ON DELETE CASCADE,
    FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

#### `festival_activities` (Egna aktiviteter: clinics, rides, etc.)
```sql
CREATE TABLE festival_activities (
    id INT AUTO_INCREMENT PRIMARY KEY,
    festival_id INT NOT NULL,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    activity_type ENUM('clinic','lecture','groupride','workshop','social','other') DEFAULT 'other',

    -- Tid
    date DATE NOT NULL,
    start_time TIME NULL,
    end_time TIME NULL,

    -- Plats (inom festivalområdet)
    location_detail VARCHAR(255),

    -- Ledare/instruktör
    instructor_name VARCHAR(255),
    instructor_info TEXT,

    -- Anmälan & pris
    price DECIMAL(10,2) DEFAULT 0.00,
    max_participants INT NULL,
    registration_opens DATETIME NULL,
    registration_deadline DATETIME NULL,
    included_in_pass TINYINT(1) DEFAULT 1,

    -- Visning
    sort_order INT DEFAULT 0,
    active TINYINT(1) DEFAULT 1,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_festival (festival_id),
    INDEX idx_date (date),
    FOREIGN KEY (festival_id) REFERENCES festivals(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

#### `festival_activity_registrations` (Anmälningar till aktiviteter)
```sql
CREATE TABLE festival_activity_registrations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    activity_id INT NOT NULL,
    rider_id INT NULL,
    order_id INT NULL,

    first_name VARCHAR(100) NOT NULL,
    last_name VARCHAR(100) NOT NULL,
    email VARCHAR(255) NOT NULL,
    phone VARCHAR(50),

    status ENUM('pending','confirmed','cancelled') DEFAULT 'pending',
    payment_status ENUM('unpaid','paid','refunded') DEFAULT 'unpaid',

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_activity (activity_id),
    INDEX idx_rider (rider_id),
    INDEX idx_order (order_id),
    FOREIGN KEY (activity_id) REFERENCES festival_activities(id) ON DELETE CASCADE,
    FOREIGN KEY (rider_id) REFERENCES riders(id) ON DELETE SET NULL,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

#### `festival_passes` (Sålda festivalpass)
```sql
CREATE TABLE festival_passes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    festival_id INT NOT NULL,
    rider_id INT NULL,
    order_id INT NULL,

    first_name VARCHAR(100) NOT NULL,
    last_name VARCHAR(100) NOT NULL,
    email VARCHAR(255) NOT NULL,
    phone VARCHAR(50),

    pass_code VARCHAR(20) UNIQUE,
    status ENUM('active','cancelled','used') DEFAULT 'active',
    payment_status ENUM('unpaid','paid','refunded') DEFAULT 'unpaid',

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_festival (festival_id),
    INDEX idx_rider (rider_id),
    INDEX idx_order (order_id),
    FOREIGN KEY (festival_id) REFERENCES festivals(id) ON DELETE CASCADE,
    FOREIGN KEY (rider_id) REFERENCES riders(id) ON DELETE SET NULL,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

#### `festival_sponsors` (Sponsorer per festival)
```sql
CREATE TABLE festival_sponsors (
    id INT AUTO_INCREMENT PRIMARY KEY,
    festival_id INT NOT NULL,
    sponsor_id INT NOT NULL,
    placement ENUM('header','content','sidebar','partner') DEFAULT 'content',
    display_order INT DEFAULT 0,
    display_size ENUM('large','small') DEFAULT 'large',

    INDEX idx_festival (festival_id),
    FOREIGN KEY (festival_id) REFERENCES festivals(id) ON DELETE CASCADE,
    FOREIGN KEY (sponsor_id) REFERENCES sponsors(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### Ny kolumn på events-tabellen
```sql
ALTER TABLE events ADD COLUMN festival_id INT NULL AFTER series_id;
ALTER TABLE events ADD INDEX idx_festival_id (festival_id);
-- Ingen FK constraint (för flexibilitet, samma mönster som series_id)
```

### Ny kolumn på orders-tabellen
```sql
ALTER TABLE orders ADD COLUMN festival_id INT NULL AFTER series_id;
ALTER TABLE orders ADD INDEX idx_orders_festival_id (festival_id);
```

### Ny item_type i order_items
```sql
-- order_items.item_type behöver stödja 'festival_activity' och 'festival_pass'
-- Om item_type är VARCHAR: inga ändringar behövs
-- Om item_type är ENUM: ALTER TABLE order_items MODIFY item_type ...
```

---

## Fas 2: Routing & Sidstruktur

### Nya routes i router.php
```php
// Publik festival-sektion
'festival' => [
    'index' => '/pages/festival/index.php',    // /festival → lista alla festivaler
    'show'  => '/pages/festival/show.php',      // /festival/5 → festivalsida
],
```

### Nya sidor

#### Publika sidor
| URL | Fil | Beskrivning |
|-----|-----|-------------|
| `/festival` | `pages/festival/index.php` | Lista alla kommande festivaler |
| `/festival/{id}` | `pages/festival/show.php` | Festivalsida med program, event, aktiviteter |

#### Admin-sidor
| URL | Fil | Beskrivning |
|-----|-----|-------------|
| `/admin/festivals.php` | `admin/festivals.php` | Lista/skapa/redigera festivaler |
| `/admin/festival-edit.php?id=X` | `admin/festival-edit.php` | Redigera festival (event, aktiviteter, pass) |

---

## Fas 3: Publik festivalsida (`/festival/{id}`)

### Layout-struktur

```
┌─────────────────────────────────────────┐
│ HERO: Banner, namn, datum, plats        │
│ Festivalpass-knapp (om aktiverat)       │
├─────────────────────────────────────────┤
│ BESKRIVNING (kort intro-text)           │
├──────────────────┬──────────────────────┤
│                  │                      │
│ PROGRAM          │ INFO-PANEL           │
│ (tidslinje per   │ - Datum              │
│  dag med alla    │ - Plats + karta      │
│  event +         │ - Kontakt            │
│  aktiviteter)    │ - Festivalpass-info  │
│                  │ - Sponsorer          │
│                  │                      │
├──────────────────┴──────────────────────┤
│ TÄVLINGSEVENT (kort per event med       │
│ serie-badge, anmälda, status, länk)     │
├─────────────────────────────────────────┤
│ AKTIVITETER (kort per aktivitet med     │
│ typ-ikon, tid, pris, "Boka"-knapp)      │
├─────────────────────────────────────────┤
│ SPONSORER (logo-rad + partners)         │
└─────────────────────────────────────────┘
```

### Mobil: Edge-to-edge, programmet som vertikal tidslinje

### Tävlingsevent-kort visar:
- Event-namn + serie-badge (t.ex. "GravityDH #3")
- Datum + disciplin-ikon
- Anmälda / max
- Status (anmälan öppen/stängd/fullbokat)
- Länk till `/event/{id}`

### Aktivitetskort visar:
- Aktivitetsnamn + typ-ikon (clinic/groupride/etc.)
- Datum + tid
- Instruktör/ledare
- Pris (eller "Ingår i festivalpass")
- Platser kvar
- "Boka"-knapp → lägger i GlobalCart

---

## Fas 4: Checkout-integration

### Nya item-typer i GlobalCart
```javascript
// Festivalpass
{
    type: 'festival_pass',
    festival_id: 5,
    festival_name: 'Götaland Gravity Festival',
    price: 500,
    rider: { ... }
}

// Enskild aktivitet
{
    type: 'festival_activity',
    activity_id: 12,
    festival_id: 5,
    activity_name: 'Enduro Clinic med X',
    price: 200,
    rider: { ... }
}
```

### Backend: order-manager.php
- Ny case i `createMultiRiderOrder()` för `festival_pass` och `festival_activity`
- `festival_pass`:
  - Skapar `festival_passes`-rad
  - Skapar `festival_activity_registrations` för ALLA `included_in_pass`-aktiviteter
  - Genererar pass_code (slumpmässigt)
- `festival_activity`:
  - Skapar `festival_activity_registrations`-rad
  - Kontrollerar att platser finns kvar
  - Om användaren redan har festivalpass: varna/blockera dubbelbokning

### Kapacitetskontroll
- `festival_passes.status = 'active'` räknas mot `festivals.pass_max_quantity`
- `festival_activity_registrations.status IN ('pending','confirmed')` räknas mot `festival_activities.max_participants`

---

## Fas 5: Admin-gränssnitt

### Festival-lista (`/admin/festivals.php`)
- Kort per festival med: namn, datum, plats, antal event, antal aktiviteter, status
- Skapa ny / redigera / arkivera
- Promotorer ser bara festivaler kopplade till sina event

### Festival-redigerare (`/admin/festival-edit.php`)
Flikar:
1. **Grundinfo** - Namn, datum, plats, beskrivning, logga, banner
2. **Tävlingsevent** - Sök och koppla befintliga event, sortering
3. **Aktiviteter** - CRUD för clinics, grouprides, lectures etc.
4. **Festivalpass** - Aktivera/konfigurera pass, pris, maxantal
5. **Sponsorer** - Bildbaserad väljare (samma mönster som event-edit)
6. **Ekonomi** - Sammanfattning av passförsäljning + aktivitetsbokningar

---

## Fas 6: Kalender-integration

### I kalender-listan (`/calendar`)
- Festivaler visas som en **multi-dag-block** med distinkt styling
- Festivalens tävlingsevent visas OCKSÅ individuellt (med festival-badge)
- Ikon: `tent` (Lucide) för festivaler

### På befintliga event-sidor (`/event/{id}`)
- Om eventet tillhör en festival: visa festival-badge i hero-sektionen
- Länk tillbaka till festivalsidan
- Text: "Del av [Festivalnamn]" med festival-ikon

---

## Fasindelning (implementation order)

### Fas 1: Databas + Migration ⏱️ ~1h
- Migration 085: Alla tabeller ovan
- Registrera i admin/migrations.php

### Fas 2: Admin CRUD ⏱️ ~3h
- festivals.php (lista)
- festival-edit.php (redigerare med flikar)
- Registrera i admin-tabs + tools.php
- Routing i router.php (admin)

### Fas 3: Publik festivalsida ⏱️ ~3h
- pages/festival/show.php (festivalsida)
- pages/festival/index.php (lista)
- Routing i router.php (publik)
- CSS i assets/css/pages/festival.css

### Fas 4: Checkout-integration ⏱️ ~2h
- GlobalCart utökat med festival-items
- order-manager.php: nya item-types
- Kapacitetskontroll

### Fas 5: Event-sida integration ⏱️ ~1h
- Festival-badge på event-sidor
- Kalender-visning

### Fas 6: Promotor-stöd ⏱️ ~1h
- Behörighetskontroll
- Promotor-vy i festival-edit

---

## Designbeslut

1. **festivals + festival_events (junction) + events.festival_id**
   - Samma dual-path som series: junction-tabell ÄR sanningskällan, `events.festival_id` är convenience-cache
   - Synkas vid sparning (samma mönster som series_events)

2. **Festival-aktiviteter är INTE events**
   - De har ingen results-tabell, inga klasser, inget timing-API
   - Enkel anmälningsmodell: namn + email + betalning → klar
   - Egen tabell `festival_activities` (inte i events-tabellen)

3. **Festivalpass som "super-biljett"**
   - Köps som en order-item (type: 'festival_pass')
   - Ger automatisk registrering till alla `included_in_pass`-aktiviteter
   - Genererar unik pass_code (för framtida QR-scanning)
   - Tävlingsanmälningar ingår INTE i passet (separata ordrar som vanligt)

4. **Ekonomi**
   - Festival har egen `payment_recipient_id`
   - Pass-intäkter och aktivitets-intäkter kopplas till festivalen
   - Visas i promotor-ekonomivyn under festival-filtret
