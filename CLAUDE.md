# TheHUB - Development Rules

> LÄS DENNA FIL INNAN DU SKRIVER NÅGON KOD

---

## VERSIONSNUMMER - OBLIGATORISKT

**UPPDATERA ALLTID versionsnumret i `config.php` vid varje push.**

```php
// config.php - Uppdatera APP_BUILD med dagens datum vid varje push
define('APP_VERSION', '1.0');          // Major.Minor version
define('APP_VERSION_NAME', 'Release');  // Version name
define('APP_BUILD', '2026-01-07');      // UPPDATERA DETTA: YYYY-MM-DD
define('DEPLOYMENT_OFFSET', 131);       // Ändra INTE
```

### Vid varje push:
1. Uppdatera `APP_BUILD` till dagens datum (YYYY-MM-DD)
2. Meddela användaren vilken version som skapades

### Format:
Version visas som: `v1.0 [2026-01-07.XXX] - Release`
- XXX = antal git commits + DEPLOYMENT_OFFSET (räknas automatiskt)

### Exempel på meddelande:
```
Pushat: TheHUB v1.0 [2026-01-07.140]
```

## INGA EMOJIS - ALDRIG

**ANVÄND ALDRIG EMOJIS I KOD.** Använd alltid Lucide-ikoner istället.

```php
// FEL - ALDRIG SÅ HÄR
$icon = '🏁';
echo '📍 Plats';

// RÄTT - ALLTID SÅ HÄR
<i data-lucide="flag"></i>
<i data-lucide="map-pin"></i>
```

Vanliga Lucide-ikoner:
- `flag` - Mål/Start
- `map-pin` - Plats/POI
- `route` - Transport
- `cable-car` - Lift
- `save` - Spara
- `pencil` - Redigera
- `x` - Stäng
- `locate` - Min plats

---

## LÅSTA FILER - ÄNDRA ALDRIG

Följande filer får INTE modifieras utan explicit godkännande:

```
assets/css/base.css        # Grundläggande CSS-variabler
assets/css/components.css  # UI-komponenter
assets/css/admin.css       # Admin-panel styling
includes/header.php        # Sidheader
includes/footer.php        # Sidfooter
includes/admin-header.php  # Admin header
includes/admin-sidebar.php # Admin navigation
```

---

## 🎨 DESIGNSYSTEM - ANVÄND ALLTID

### CSS-variabler (OBLIGATORISKT)

```css
:root {
  /* Färger - ANVÄND ENDAST DESSA */
  --color-primary: #171717;
  --color-secondary: #323539;
  --color-text: #7A7A7A;
  --color-accent: #61CE70;
  --color-star: #FDFDFD;
  --color-star-fade: #F9F9F9;
  --color-border: #e5e7eb;
  --color-danger: #ef4444;
  --color-warning: #f59e0b;
  --color-success: #61CE70;

  /* Serie-färger */
  --color-gs-green: #61CE70;
  --color-gs-blue: #004a98;
  --color-ges-orange: #EF761F;
  --color-ggs-green: #8A9A5B;

  /* Spacing - ANVÄND ENDAST DESSA */
  --space-xs: 4px;
  --space-sm: 8px;
  --space-md: 16px;
  --space-lg: 24px;
  --space-xl: 32px;
  --space-2xl: 48px;

  /* Radius */
  --radius-sm: 6px;
  --radius-md: 10px;
  --radius-lg: 16px;
  --radius-full: 9999px;

  /* Shadows */
  --shadow-sm: 0 1px 2px rgba(0,0,0,0.05);
  --shadow-md: 0 4px 12px rgba(0,0,0,0.08);
  --shadow-lg: 0 10px 30px rgba(0,0,0,0.12);
}
```

### ❌ FÖRBJUDET

```css
/* SKRIV ALDRIG DETTA */
background: #61CE70;           /* Använd var(--color-accent) */
padding: 15px;                 /* Använd var(--space-md) eller var(--space-lg) */
border-radius: 8px;            /* Använd var(--radius-sm) */
color: gray;                   /* Använd var(--color-text) */
```

### ✅ KORREKT

```css
/* SKRIV ALLTID DETTA */
background: var(--color-accent);
padding: var(--space-md);
border-radius: var(--radius-sm);
color: var(--color-text);
```

---

## MOBILDESIGN - EDGE-TO-EDGE STANDARD

**Alla innehallssektioner ska ga kant-till-kant pa mobil.**

Detta ar den globala standarden fran 2025. Pa mobil (max-width: 767px) ska alla kort, tabeller och innehallsblock fylla hela skarmbredden for att maximera datautrymme.

### Hur det fungerar

Reglerna finns i `assets/css/components.css` och tillampas automatiskt pa:
- `.card` - Alla kort
- `.filter-row` - Filterrader
- `.filters-bar` - Filterbarer
- `.table-responsive` - Tabeller
- `.table-wrapper` - Tabellwrappers
- `.result-list` - Resultatlistor
- `.event-row` - Eventrader
- `.alert` - Alerts/meddelanden

### CSS-tekniken

```css
@media(max-width:767px){
  .card,
  .filter-row,
  .table-responsive,
  .alert {
    margin-left: -16px;
    margin-right: -16px;
    border-radius: 0 !important;
    border-left: none !important;
    border-right: none !important;
    width: calc(100% + 32px);
  }
}
```

### VIKTIGT - container padding

Pa mobil anvander `.main-content` padding: `var(--space-md)` (16px).
Negativa marginaler maste matcha detta: `-16px`.

Pa extra smala skarmar (599px portrait) anvands `var(--space-sm)` (8px) istallet.

### Nar du skapar nya komponenter

Om du skapar nya innehallsblock som ska ga edge-to-edge pa mobil:

1. Lagg till klassen i components.css under mobil-regeln (max-width:767px)
2. ELLER anvand samma CSS-monster manuellt:

```css
@media(max-width:767px){
  .din-nya-komponent {
    margin-left: -16px;
    margin-right: -16px;
    border-radius: 0 !important;
    border-left: none !important;
    border-right: none !important;
    width: calc(100% + 32px);
  }
}
```

---

## SIDMALLAR

### Admin-sida (KOPIERA EXAKT)

```php
<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();

$pageTitle = 'Sidtitel';
include __DIR__ . '/../includes/admin-header.php';
?>

<div class="admin-content">
    <div class="page-header">
        <h1><?= $pageTitle ?></h1>
    </div>

</div>

<?php include __DIR__ . '/../includes/admin-footer.php'; ?>
```

### Publik sida (KOPIERA EXAKT)

```php
<?php
require_once __DIR__ . '/config/database.php';

$pageTitle = 'Sidtitel';
include __DIR__ . '/includes/header.php';
?>

<main class="container">
    <!-- DITT INNEHÅLL HÄR -->
</main>

<?php include __DIR__ . '/includes/footer.php'; ?>
```

---

## 🧩 KOMPONENTER - KOPIERA, ÄNDRA INTE

### Kort (Card)

```html
<div class="card">
    <div class="card-header">
        <h3>Titel</h3>
    </div>
    <div class="card-body">
        Innehåll
    </div>
</div>
```

### Flikar (Tabs)

```html
<div class="tabs">
    <nav class="tabs-nav">
        <button class="tab-btn active" data-tab="tab1">Flik 1</button>
        <button class="tab-btn" data-tab="tab2">Flik 2</button>
    </nav>
    <div class="tab-content active" id="tab1">
        Innehåll 1
    </div>
    <div class="tab-content" id="tab2">
        Innehåll 2
    </div>
</div>
```

```js
// Tab-switching (lägg i footer eller separat JS)
document.querySelectorAll('.tab-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        const tabId = btn.dataset.tab;
        document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
        document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
        btn.classList.add('active');
        document.getElementById(tabId).classList.add('active');
    });
});
```

### Knappar

```html
<button class="btn btn-primary">Primär</button>
<button class="btn btn-secondary">Sekundär</button>
<button class="btn btn-danger">Danger</button>
<button class="btn btn-ghost">Ghost</button>
```

### Formulär

```html
<div class="form-group">
    <label class="form-label">Etikett</label>
    <input type="text" class="form-input" placeholder="...">
</div>

<div class="form-group">
    <label class="form-label">Dropdown</label>
    <select class="form-select">
        <option>Val 1</option>
    </select>
</div>
```

### Tabell

```html
<div class="table-responsive">
    <table class="table">
        <thead>
            <tr>
                <th>Kolumn</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>Data</td>
            </tr>
        </tbody>
    </table>
</div>
```

### Alerts

```html
<div class="alert alert-success">Meddelande</div>
<div class="alert alert-warning">Varning</div>
<div class="alert alert-danger">Fel</div>
```

### Badge

```html
<span class="badge badge-success">Aktiv</span>
<span class="badge badge-warning">Väntande</span>
<span class="badge badge-danger">Inaktiv</span>
```

---

## 📁 NY FIL - CHECKLISTA

När du skapar en ny sida:

1. ✅ Använd rätt sidmall (admin eller publik)
2. ✅ Inkludera INGA egna `<style>`-taggar
3. ✅ Använd befintliga komponenter
4. ✅ Använd CSS-variabler för ALLA värden
5. ✅ Testa på mobil (320px) och desktop

---

## 🚫 REGLER

1. **SKAPA ALDRIG ny CSS** utan att fråga först
2. **ÄNDRA ALDRIG** header, footer, eller sidebar
3. **ANVÄND ALLTID** befintliga komponenter
4. **HARDKODA ALDRIG** färger, spacing eller storlekar
5. **INKLUDERA ALLTID** rätt header/footer

---

## 💬 FRÅGA FÖRST

Om du behöver:
- En ny komponent → Fråga: "Ska jag lägga till X i components.css?"
- Ändra layout → Fråga: "Får jag ändra strukturen på X?"
- Ny styling → Fråga: "Behöver vi ny CSS för detta?"

---

## 🗄️ DATABAS - TABELLSCHEMA

**VIKTIGT:** Kolumnnamn är UTAN understreck!

### riders (deltagare)
```sql
id, firstname, lastname, birth_year, gender,
license_number, license_type, license_year, license_valid_until,
gravity_id, uci_id, club_id, nationality, active,
first_season, experience_level,
stats_total_starts, stats_total_finished, stats_total_wins, stats_total_podiums, stats_total_points,
created_at, updated_at
```

### results (resultat)
```sql
id, event_id, cyclist_id, class_id, position,
finish_time, status, bib_number, points,
ss1, ss2, ss3, ..., ss15,  -- Split times
created_at
```

### events (tävlingar)
```sql
id, name, date, location, venue_id, series_id,
discipline, event_level, event_format, active,
is_championship, organizer_club_id,
stage_names, pricing_template_id
```

### clubs (klubbar)
```sql
id, name, city, country, active
```

### series (serier)
```sql
id, name, year, status, logo
```

---

## 📂 PROJEKTSTRUKTUR

```
thehub/
├── config/
│   └── database.php
├── includes/
│   ├── header.php          # 🔒 LÅST
│   ├── footer.php          # 🔒 LÅST
│   ├── admin-header.php    # 🔒 LÅST
│   ├── admin-sidebar.php   # 🔒 LÅST
│   ├── admin-footer.php    # 🔒 LÅST
│   ├── functions.php
│   └── auth.php
├── assets/
│   ├── css/
│   │   ├── base.css        # 🔒 LÅST - Variabler
│   │   ├── components.css  # 🔒 LÅST - UI-komponenter
│   │   └── admin.css       # 🔒 LÅST - Admin-specifikt
│   └── js/
│       └── main.js
├── admin/
│   ├── index.php
│   └── ...
└── public pages...
```
