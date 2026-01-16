# TheHUB Prestandarapport v2.0

**Datum:** 2026-01-16
**Version:** 2.0 (Uppfoljning av 2025-12-10)
**Analys av:** Claude Opus 4.5

---

## Executive Summary

TheHUB har vuxit betydligt sedan forsta prestandarapporten (december 2025). Analytics-modulen ar nu i version 3.2 med 20+ tabeller och komplex KPI-berakning. **Huvudslutsat:** Sidan ar seg primart pa grund av **N+1 query-problem** och **icke-optimerade assets** - INTE pa grund av databasstorlek. Att flytta analytics till separat databas ar **inte rekommenderat** i nulaget.

---

## Jamforelse: December 2025 vs Januari 2026

| Omrade | Dec 2025 | Jan 2026 | Forandring |
|--------|----------|----------|------------|
| PHP-filer | 324 | ~350+ | +8% |
| CSS-filer | 10 (213 KB) | 57 (510 KB) | +140% |
| JS-filer | 10 (73 KB) | 12 (96 KB) | +30% |
| Analytics-tabeller | ~8 | 20+ | +150% |
| N+1 problem identifierade | 7 | 12+ | +71% |

---

## 1. KRITISKA PRESTANDAPROBLEM

### 1.1 N+1 Query Problem - Publika Sidor

| Fil | Rad | Beskrivning | Extra Queries |
|-----|-----|-------------|---------------|
| `pages/rider.php` | 239-268 | COUNT per race result i loop | +10-20 |
| `pages/rider.php` | 350-366 | H2H statistik per resultat | +20-50 |
| `pages/event.php` | 249-274 | Resultat utan LIMIT | Obegransat |
| `pages/results.php` | 42-70 | Events utan LIMIT | Obegransat |

**Impact for rider.php:** En aktiv cyklist med 30 resultat genererar **60+ extra databas-anrop** per sidladdning.

### 1.2 N+1 Query Problem - Analytics Engine

| Fil | Rad | Beskrivning | Extra Queries |
|-----|-----|-------------|---------------|
| `analytics/includes/AnalyticsEngine.php` | 206-220 | Per-rider yearly stats loop | 5 queries/rider |
| `analytics/includes/AnalyticsEngine.php` | 395-434 | `isFirstSeriesEver()` per deltagande | 2 queries/record |
| `analytics/cron/refresh-risk-scores.php` | 147-178 | Risk score per rider | 5 subqueries/rider |

**Impact:** For 500 riders: **2,500+ databas-anrop** istallet for 1 bulk-query.

**Losning finns:** `calculateYearlyStatsBulk()` metoden existerar men anvands INTE som default.

### 1.3 Saknade Database Index

```sql
-- Rekommenderade nya index (utover de fran december 2025)
ALTER TABLE results ADD INDEX idx_event_class_status (event_id, class_id, status);
ALTER TABLE event_registrations ADD INDEX idx_event_status (event_id, status);
ALTER TABLE rider_club_seasons ADD INDEX idx_rider_year (rider_id, season_year);
```

---

## 2. FRONT-END PRESTANDA

### 2.1 CSS-laddning (KRITISKT)

**Nuvarande:** 12 separata CSS-filer laddas pa VARJE sida = 136 KB baseline

| Fil | Storlek | Notering |
|-----|---------|----------|
| compatibility.css | 28 KB | Legacy-oversattningslager |
| components.css | 23 KB | UI-komponenter |
| utilities.css | 16 KB | Utility classes |
| pwa.css | 9.5 KB | PWA-relaterat |
| layout.css | 9.0 KB | Layout system |

**Problem:**
- Ingen minifiering (0% av filerna ar minifierade)
- Ingen bundling (12 HTTP-requests)
- `compatibility.css` (28 KB) ar ett legacy-lager som borde tas bort

### 2.2 JavaScript-laddning

| Problem | Beskrivning | Impact |
|---------|-------------|--------|
| Chart.js globalt | 70 KB laddas pa ALLA sidor | Unodigt for 90% av sidor |
| event-map.js | 30 KB laddas pa event-sidor | OK men kunde lazy-loadas |
| Ingen defer/async | Scripts blockerar rendering | Forsta render forsenas |

### 2.3 Forvantad sidstorlek

```
Publik sida (t.ex. kalender):
- Global CSS:         136 KB
- Sid-specifik CSS:    11 KB
- Google Fonts:        80 KB
- Lucide Icons:        19 KB
- Chart.js (oanvant):  70 KB
────────────────────────────
Total:                316 KB (okomprimerat)
Med gzip:             ~90-110 KB
```

---

## 3. ANALYTICS DATABASE - BEHOVS SEPARAT DB?

### 3.1 Nuvarande Analytics-tabellstruktur

**Karndata (pre-aggregerat):**
- `rider_yearly_stats` - Arsstatistik per cyklist
- `series_participation` - Seriedeltagande
- `series_crossover` - Flode mellan serier
- `club_yearly_stats` - Klubbstatistik
- `venue_yearly_stats` - Anlaggningsstatistik

**Journey Analysis (v3.1):**
- `rider_first_season` - Forsta sasong
- `rider_journey_years` - Longitudinell uppfoljning
- `rider_journey_summary` - Journey-patterns
- `brand_journey_aggregates` - Brand-aggregat

**Event Participation (v3.2):**
- `series_participation_distribution` - Deltagarmonster
- `event_unique_participants` - Unika per event
- `event_retention_yearly` - Retention per event
- `event_loyal_riders` - Lojala deltagare

**Stodtabeller:**
- `analytics_snapshots` - KPI-ogonblicksbilder
- `analytics_exports` - Exportlogg
- `analytics_recalc_queue` - Omberakningsko
- `analytics_cron_config` - Cron-konfiguration
- `export_rate_limits` - Rate limiting
- `analytics_kpi_definitions` - KPI-definitioner
- `analytics_kpi_audit` - Andringslogg
- `analytics_system_config` - Systemkonfiguration

### 3.2 Analys: Ska analytics flyttas till separat databas?

#### Argument FOR separat databas:
1. Isolation - Analytics-queries paverkar inte transaktionella operationer
2. Skalbarhet - Kan skala databasservrar oberoende
3. Backup-strategi - Olika backup-frekvens

#### Argument MOT separat databas (STARKARE):
1. **N+1 ar huvudproblemet** - Segan sida beror pa query-monster, inte datavolym
2. **Pre-aggregerade tabeller** - Analytics lagger INTE belastning pa radata
3. **JOIN-beroenden** - Analytics BEHOVER JOIN mot riders, events, results for berakning
4. **Komplexitet** - Tva databaser okar underhallskostnaden avsevart
5. **Latens** - Cross-database queries ar betydligt langsammare
6. **Transaktioner** - Svart att upprathalla dataintegritet over databaser

### 3.3 Rekommendation

**FLYTTA INTE analytics till separat databas.**

Gor istallet:
1. Fixa N+1 queries (storre effekt, lagre komplexitet)
2. Anvand `calculateYearlyStatsBulk()` istallet for per-rider loop
3. Lagg till saknade index
4. Implementera query result caching (Redis/APCu)

**Om framtida behov uppstar:** Overvaag read-replica for analytics istallet for separat databas.

---

## 4. ATGARDSPLAN - PRIORITETSORDNING

### Fas 1: Kritiska Query-fixar (OMEDELBART)

| Atgard | Fil | Forvantad forbattring |
|--------|-----|----------------------|
| Batch form results COUNT | `pages/rider.php:239-268` | -20 queries/sida |
| Batch H2H statistik | `pages/rider.php:350-366` | -30 queries/sida |
| Anvand bulk-metod | `refresh-analytics.php` | -2000+ queries/kor |
| Lagg till LIMIT | `pages/event.php`, `results.php` | Forhindrar overbelastning |

**Kodskelett for rider.php fix:**
```php
// ISTALLET FOR: Loop med COUNT per resultat
// GOR SA HAR: En batch-query med alla COUNTs
$countsByEvent = [];
$eventIds = array_column($formResultsRaw, 'event_id');
$classIds = array_column($formResultsRaw, 'class_id');

$batchStmt = $db->prepare("
    SELECT event_id, class_id, COUNT(*) as cnt
    FROM results
    WHERE (event_id, class_id) IN (/* prepared pairs */)
    AND status = 'finished'
    GROUP BY event_id, class_id
");
// Anvand $countsByEvent i foreach istallet for query per iteration
```

### Fas 2: Asset-optimering (NASTA VECKA)

| Atgard | Beskrivning | Besparingspotential |
|--------|-------------|---------------------|
| Minifiera CSS/JS | Anvand cssnano/terser | 30-40% storleksminskning |
| Bundla CSS | 12 filer -> 2-3 filer | Farre HTTP-requests |
| Lazy-load Chart.js | Endast pa admin/analytics | -70 KB for publika sidor |
| Ta bort compatibility.css | Refaktorera legacy-kod | -28 KB |

### Fas 3: Database-optimering (INOM EN MANAD)

| Atgard | Beskrivning |
|--------|-------------|
| Lagg till index | Se sektion 1.3 |
| Query caching | Implementera med APCu eller Redis |
| Prepared statement pooling | Återanvand statements i loopar |

### Fas 4: Long-term (LOPANDE)

| Atgard | Beskrivning |
|--------|-------------|
| Monitoring | Implementera slow query log |
| Code review | Granska nya queries for N+1 |
| Asset pipeline | Overvaag Vite eller esbuild |

---

## 5. QUICK WINS (GORBAR IDAG)

### 5.1 Anvand bulk-metod for analytics

I `analytics/cron/refresh-analytics.php`, byt:
```php
// FRAN:
$engine->refreshAllStats($year);

// TILL:
$engine->refreshAllStatsFast($year);  // Anvander bulk-query
```

### 5.2 Lazy-load Chart.js

I `includes/layout-footer.php`:
```php
<?php if (isset($needsCharts) && $needsCharts): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<?php endif; ?>
```

### 5.3 Lagg till LIMIT pa event-resultat

I `pages/event.php`:
```php
// Lagg till LIMIT 500 for att forhindra massiva resultatset
ORDER BY {$orderBy} LIMIT 500
```

---

## 6. PRESTANDAMATNING

### Rekommenderade verktyg:

1. **MySQL Slow Query Log**
   ```sql
   SET GLOBAL slow_query_log = 'ON';
   SET GLOBAL long_query_time = 1;  -- 1 sekund
   ```

2. **PHP Query Counter**
   ```php
   // I db.php, lagg till counter
   private static $queryCount = 0;
   public static function getQueryCount() { return self::$queryCount; }
   ```

3. **Browser DevTools**
   - Network tab for asset-laddning
   - Performance tab for rendering

### Mal:

| Metric | Nulage (uppsk.) | Mal |
|--------|-----------------|-----|
| Queries per sida (rider) | 60+ | < 10 |
| Queries per sida (event) | 20+ | < 10 |
| Asset requests | 15+ | < 8 |
| Time to First Byte | ~500ms | < 200ms |
| Sidladdning (mobil 3G) | ~4s | < 2s |

---

## 7. SLUTSATS

TheHUB:s prestandaproblem beror INTE pa databasstorlek eller analytics-komplexitet. **Huvudorsaken ar N+1 query-monster** som skapar hundratals onodiga databas-anrop per sidladdning.

**Prioritera:**
1. Fixa N+1 i `rider.php` (storst impact for besokare)
2. Anvand bulk-metoder i analytics cron
3. Lazy-load Chart.js (enkel vinst)
4. Bundla/minifiera CSS

**Med dessa atgarder forvantas:**
- 70-80% farre databas-queries per sidladdning
- 30-40% mindre asset-storlek
- Marktbart snabbare sida for alla besokare

**Ang. separat analytics-databas:** Inte motiverat. Pre-aggregerade tabeller gor att analytics INTE belastar produktionsdatabasen under normal anvandning. Om framtida behov uppstar, overvaag read-replica istallet.

---

*Rapport genererad: 2026-01-16*
*Analyserad av: Claude Opus 4.5*
*TheHUB Version: v1.0*
