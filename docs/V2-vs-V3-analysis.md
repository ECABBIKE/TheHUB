# DJUPGÅENDE ANALYS: V2 vs V3

**Datum:** 2025-11-29
**Syfte:** Jämförelse av plattformarna för att identifiera skillnader och åtgärder

---

## SAMMANFATTNING AV PROBLEMET

V2 och V3 är **två helt olika arkitekturer** som delar samma databas men har fundamentalt olika:
- Routing-system
- Layout-struktur
- CSS-approach
- Komponentstruktur

---

## 1. ARKITEKTURSKILLNADER

| Aspekt | V2 (Produktion) | V3 (Måldesign) |
|--------|-----------------|----------------|
| **URL-struktur** | `/results.php`, `/series.php` | `/v3/results`, `/v3/series` |
| **Entry point** | Varje `.php`-fil direkt | `index.php` → `router.php` |
| **Layout** | `include header/footer` per fil | Centraliserad `app-layout` |
| **Header** | Ingen separat header-komponent | `components/header.php` med user-dropdown |
| **Sidebar** | `navigation-v3.php` (72px) | `components/sidebar.php` (72px) |
| **Breadcrumbs** | Saknas | `components/breadcrumb.php` |
| **Mobile nav** | Saknas | `components/mobile-nav.php` |

---

## 2. CSS-SKILLNADER

### V2 assets/css/ (totalt ~99KB)

| Fil | Storlek | Kommentar |
|-----|---------|-----------|
| `compatibility.css` | 13KB | Mappar gamla .gs-* klasser |
| `responsive.css` | 9KB | Breakpoints (saknas i V3) |
| `theme-base.css` | 26KB | Stor, äldre baseline (saknas i V3) |
| `components.css` | 11KB | |
| `layout.css` | 6KB | |

### V3 assets/css/ (totalt ~33KB)

| Fil | Storlek | Kommentar |
|-----|---------|-----------|
| `components.css` | 8KB | Renare, modernare |
| `layout.css` | 5KB | Kompaktare |
| `utilities.css` | 5KB | |

### Kärnproblemet

V2-sidorna har **inline `<style>`-taggar med hårdkodade färger** medan V3 använder **CSS-variabler konsekvent**.

#### Exempel från `results.php` (V2) - DÅLIGT:
```css
.gs-result-date {
  background: #667eea;  /* Borde vara var(--color-accent) */
  color: white;
}
.gs-result-title {
  color: #1a202c;  /* Borde vara var(--color-text-primary) */
}
```

#### Motsvarande i `v3/pages/results.php` (V3) - BRA:
```css
.event-date-col {
  background: var(--color-accent);
  color: white;
}
.event-name {
  color: var(--color-text-primary);  /* Fungerar i dark mode! */
}
```

---

## 3. SIDA-FÖR-SIDA JÄMFÖRELSE

### results.php vs v3/results

| Element | V2 | V3 |
|---------|----|----|
| Layout | Grid med kort | Lista med rader |
| Datumvisning | Badge med `#667eea` | Färgad ruta med dag/månad |
| Filter | Select-boxar i card | Filter-bar med stickier labels |
| Responsivitet | Media query `640px` | Media query `599px` + orientation |
| Dark mode | Trasig (hårdkodade färger) | Fungerar (CSS-variabler) |

### series.php vs v3/series

| Element | V2 | V3 |
|---------|----|----|
| Layout | `.gs-series-list` (horisontell) | `.series-grid` (grid auto-fill) |
| Kort-design | Logo+info horizontellt | Logo+badge+stats vertikalt |
| CSS-klasser | `.gs-series-*` (behöver compatibility.css) | `.series-*` (standalone) |
| Statistik | Inline med ikoner | Separata stat-boxar |

### ranking/ vs v3/ranking

| Element | V2 | V3 |
|---------|----|----|
| Fil | `/ranking/index.php` (separat mapp) | `/v3/pages/ranking.php` |
| Discipline tabs | Troligen samma | Moderna `.discipline-tabs` |
| Tabell+mobil | Okänt | Dual-view (table desktop, cards mobile) |

---

## 4. VARFÖR V2 HAR FEL DESIGN

1. **Saknar `app-layout` wrapper** - V2 har `<main class="main-content">` direkt, utan grid-layout
2. **Inline CSS överskrider** - Varje V2-sida har `<style>` som skapar konflikter
3. **Hårdkodade färger** - Fungerar inte med dark mode
4. **Gamla `.gs-*` klasser** - Kräver compatibility.css som kanske inte mappas korrekt
5. **Saknar header-komponent** - V2 har ingen user-dropdown eller sökfunktion

---

## 5. ÅTGÄRDSPLAN

### ALTERNATIV A: Redirect V2 till V3 (REKOMMENDERAT)

Detta är den **enklaste och mest underhållsvänliga** lösningen.

#### Steg 1: Skapa `.htaccess` redirects för V2-filer

```apache
# I root .htaccess
RewriteRule ^results\.php$ /v3/results [R=301,L]
RewriteRule ^series\.php$ /v3/series [R=301,L]
RewriteRule ^ranking/?$ /v3/ranking [R=301,L]
```

#### Steg 2: Uppdatera V3-sidorna att hantera V2:s GET-parametrar

- `results.php?series_id=X` → `v3/results?series=X`
- `series-standings.php?id=X` → `v3/series/X`

#### Steg 3: Testa alla användarscenarion

---

### ALTERNATIV B: Konvertera V2-filer till V3-design

Om du behöver behålla V2-URL:erna.

#### Steg 1: Uppdatera `layout-header.php`

- Lägg till V3:s header-komponent med user-dropdown
- Lägg till `app-layout` wrapper
- Lägg till breadcrumbs

#### Steg 2: Ersätt inline CSS i varje V2-sida

- `results.php` - byt ut alla hårdkodade färger mot CSS-variabler
- `series.php` - migrera från `.gs-series-*` till V3:s `.series-*`
- Etc.

#### Steg 3: Ta bort `compatibility.css` och `theme-base.css` (om inte längre behövs)

---

## 6. KONKRET ARBETSORDNING (Alternativ B)

### Fas 1: Layout-header.php (1 fil)
- Lägg till V3-header med user-dropdown
- Wrap content i `app-layout`

### Fas 2: results.php (1 fil)
- Kopiera V3:s inline CSS
- Uppdatera HTML-struktur till V3:s event-row format

### Fas 3: series.php (1 fil)
- Kopiera V3:s inline CSS
- Uppdatera till `.series-grid` layout

### Fas 4: ranking/ (1 fil)
- Synka med V3:s ranking.php

### Fas 5: Rensa upp
- Ta bort oanvänd CSS
- Testa dark mode

---

## 7. HYBRID-LÖSNING: Proxy-filer

Om du **måste** ha `.php`-URL:er kan du göra V2-filerna till "proxy-filer":

```php
<?php
// results.php - Proxy till V3
$_GET['page'] = 'results';
include __DIR__ . '/v3/index.php';
```

---

## REKOMMENDATION

**Använd Alternativ A (redirect)** om det är möjligt. V3 är redan korrekt designad och fungerar. Att upprätthålla två versioner av samma sidor är onödigt dubbelarbete.

---

## RELEVANTA FILER

### V2 (Produktion)
- `/results.php`
- `/series.php`
- `/ranking/index.php`
- `/includes/layout-header.php`
- `/includes/navigation-v3.php`
- `/assets/css/*`

### V3 (Måldesign)
- `/v3/index.php` (entry point)
- `/v3/router.php` (routing)
- `/v3/pages/results.php`
- `/v3/pages/series/index.php`
- `/v3/pages/ranking.php`
- `/v3/components/header.php`
- `/v3/components/sidebar.php`
- `/v3/assets/css/*`
