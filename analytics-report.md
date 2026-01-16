# TheHUB Analytics Platform - Teknisk Dokumentation

> Komplett guide till analytics-systemet v3.0, dess komponenter och SCF-nivå-rapportering

**Version:** 3.0.0
**Senast uppdaterad:** 2026-01-16
**Status:** Production Ready

---

## Innehallsforteckning

1. [Oversikt](#oversikt)
2. [Arkitektur](#arkitektur)
3. [Databas-struktur](#databas-struktur)
4. [Karnkomponenter](#karnkomponenter)
5. [KPI-definitioner (VIKTIGT)](#kpi-definitioner)
6. [Analytics-sidor](#analytics-sidor)
7. [Datakvalitet](#datakvalitet)
8. [Export och Reproducerbarhet](#export-och-reproducerbarhet)
9. [Setup och Underhall](#setup-och-underhall)
10. [Changelog](#changelog)

---

## Oversikt

TheHUB Analytics ar ett komplett analysverktyg for svensk cykelsport med **10+ ars data** och stod for SCF-nivå-rapportering. Systemet beraknar och visualiserar nyckeltal (KPIs) for:

| Omrade | Beskrivning | Huvudsida |
|--------|-------------|-----------|
| **Retention & Growth** | Hur manga riders kommer tillbaka varje ar? | `analytics-dashboard.php` |
| **Demographics** | Alders- och konsfordelning | `analytics-reports.php` |
| **Series Flow** | Hur ror sig riders mellan serier? | `analytics-flow.php` |
| **Club Performance** | Klubbarnas tillvaxt och framgang | `analytics-clubs.php` |
| **Geographic Distribution** | Var finns riders geografiskt? | `analytics-geography.php` |
| **Cohort Analysis** | Hur utvecklas en argang over tid? | `analytics-cohorts.php` |
| **At-Risk Prediction** | Vilka riders riskerar att sluta? | `analytics-atrisk.php` |
| **Data Quality** | Hur komplett ar var data? | `analytics-data-quality.php` |

### Behorigheter

Analytics kraver en av foljande:
- `super_admin` roll
- `statistics` permission (kan tilldelas utan admin-rattigheter)

### Principer

1. **Pre-aggregerad data** - Tunga berakningar gors en gang och sparas
2. **Identity Resolution** - Dubbletter hanteras automatiskt via canonical IDs
3. **Reproducerbarhet** - Alla exporter loggas med fingerprint
4. **GDPR-kompatibel** - Aggregerad data publikt, persondata bakom behorighet

---

## Arkitektur

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                           PRESENTATION LAYER                                 │
│  analytics-dashboard │ analytics-cohorts │ analytics-atrisk │ etc.          │
│                                                                              │
│  SVGChartRenderer.php ─── Grafer for PDF (ingen Node.js)                    │
└─────────────────────────────────┬───────────────────────────────────────────┘
                                  │
┌─────────────────────────────────▼───────────────────────────────────────────┐
│                           CALCULATION LAYER                                  │
│                                                                              │
│  ┌─────────────────────┐     ┌─────────────────────┐                        │
│  │   KPICalculator     │     │   ExportLogger      │                        │
│  │   ~2500 rader       │     │   GDPR-loggning     │                        │
│  │                     │     │   Manifest          │                        │
│  │  - getRetentionRate │     │   Fingerprint       │                        │
│  │  - getCohortData    │     └─────────────────────┘                        │
│  │  - getRiskScores    │                                                    │
│  │  - getDataQuality   │     ┌─────────────────────┐                        │
│  └─────────────────────┘     │  AnalyticsConfig    │                        │
│                              │  v3.0               │                        │
│                              │  - KPI-definitioner │                        │
│                              │  - Klassrankning    │                        │
│                              │  - Riskfaktorer     │                        │
│                              │  - Troskel          │                        │
│                              └─────────────────────┘                        │
└─────────────────────────────────┬───────────────────────────────────────────┘
                                  │
┌─────────────────────────────────▼───────────────────────────────────────────┐
│                           ENGINE LAYER                                       │
│                                                                              │
│  ┌─────────────────────┐     ┌─────────────────────┐                        │
│  │  AnalyticsEngine    │     │  IdentityResolver   │                        │
│  │                     │     │                     │                        │
│  │  - calculateYearly  │────▶│  - resolveRider()   │                        │
│  │  - calculateSeries  │     │  - getCanonicalId() │                        │
│  │  - calculateClubs   │     │  - mergeRiders()    │                        │
│  │  - Job management   │     └─────────────────────┘                        │
│  └─────────────────────┘                                                    │
└─────────────────────────────────┬───────────────────────────────────────────┘
                                  │
┌─────────────────────────────────▼───────────────────────────────────────────┐
│                           DATA LAYER                                         │
│                                                                              │
│  PRE-AGGREGATED TABLES              │  RAW TABLES                           │
│  ─────────────────────              │  ──────────                           │
│  rider_yearly_stats                 │  results                              │
│  series_participation               │  events                               │
│  series_crossover                   │  riders                               │
│  club_yearly_stats                  │  clubs                                │
│  venue_yearly_stats                 │  series                               │
│  analytics_snapshots (v2)           │                                       │
│  analytics_exports                  │                                       │
│  data_quality_metrics               │                                       │
│  analytics_kpi_definitions          │                                       │
└─────────────────────────────────────────────────────────────────────────────┘
```

### Dataflode

1. **Radata** lagras i `results`, `events`, `riders`, `clubs`
2. **IdentityResolver** matchar riders mot canonical IDs
3. **AnalyticsEngine** beraknar och aggregerar data till pre-aggregerade tabeller
4. **KPICalculator** laser fran pre-aggregerade tabeller (snabbt)
5. **ExportLogger** loggar alla exporter med manifest och fingerprint
6. **SVGChartRenderer** skapar grafer for PDF-export
7. **Admin-sidor** visualiserar med Chart.js (web) eller SVG (PDF)

---

## Databas-struktur

### Karntabeller

#### `rider_yearly_stats`
Per-rider, per-ar aggregerad statistik.

| Kolumn | Typ | Beskrivning |
|--------|-----|-------------|
| rider_id | INT | Canonical rider ID |
| season_year | INT | Sasong (t.ex. 2025) |
| total_events | INT | Antal unika events deltagit i |
| total_series | INT | Antal unika serier |
| total_points | DECIMAL | Totala poang |
| best_position | INT | Basta placering |
| avg_position | DECIMAL | Genomsnittsplacering |
| primary_discipline | VARCHAR | Huvuddisciplin (mest deltaganden) |
| primary_series_id | INT | Huvudserie |
| is_rookie | TINYINT | 1 = forsta aret nagonsin |
| is_retained | TINYINT | 1 = aterkom fran forriga aret |
| calculation_version | VARCHAR | Vilken version som beraknade |
| calculated_at | TIMESTAMP | Nar berakningen gjordes |

#### `series_participation`
Detaljerat seriedeltagande per rider och ar.

| Kolumn | Typ | Beskrivning |
|--------|-----|-------------|
| rider_id | INT | Canonical rider ID |
| series_id | INT | Serie-ID |
| season_year | INT | Sasong |
| events_attended | INT | Antal events i serien |
| first_event_date | DATE | Forsta event i serien detta ar |
| last_event_date | DATE | Sista event i serien detta ar |
| total_points | DECIMAL | Poang i serien |
| final_rank | INT | Slutplacering |
| is_entry_series | TINYINT | 1 = forsta serien nagonsin for ridern |

#### `series_crossover`
Rider-floden mellan serier.

| Kolumn | Typ | Beskrivning |
|--------|-----|-------------|
| rider_id | INT | Canonical rider ID |
| from_series_id | INT | Ursprungsserie |
| to_series_id | INT | Malserie |
| from_year | INT | Ar fran |
| to_year | INT | Ar till |
| crossover_type | ENUM | `same_year`, `next_year`, `multi_year` |

#### `analytics_snapshots` (v2)
Historiska KPI-ogonblicksbilder med reproducerbarhet.

| Kolumn | Typ | Beskrivning |
|--------|-----|-------------|
| id | INT | Unik ID |
| snapshot_date | DATE | Datum |
| snapshot_type | ENUM | daily/weekly/monthly/quarterly/yearly |
| metrics | JSON | Alla KPIs |
| **generated_at** | TIMESTAMP | Exakt tidpunkt for generering |
| **season_year** | INT | Vilken sasong snapshot galler |
| **source_max_updated_at** | TIMESTAMP | MAX(updated_at) fran kalldata |
| **code_version** | VARCHAR | Platform-version (t.ex. 3.0.0) |
| **data_fingerprint** | VARCHAR(64) | SHA256 hash for verifiering |

#### `analytics_exports` (NY)
GDPR-loggning av alla exporter.

| Kolumn | Typ | Beskrivning |
|--------|-----|-------------|
| id | INT | Unik export-ID |
| export_type | VARCHAR | Typ: riders_at_risk, cohort, winback, etc. |
| export_format | VARCHAR | Format: csv, pdf, json |
| exported_by | INT | User ID |
| exported_at | TIMESTAMP | Tidpunkt |
| ip_address | VARCHAR | IP for GDPR |
| season_year | INT | Vilket ar som exporterades |
| row_count | INT | Antal rader |
| contains_pii | TINYINT | 1 om persondata ingår |
| data_fingerprint | VARCHAR(64) | SHA256 hash av data |
| manifest | JSON | Komplett manifest |

#### `data_quality_metrics` (NY)
Daglig datakvalitetsmatning per sasong.

| Kolumn | Typ | Beskrivning |
|--------|-----|-------------|
| season_year | INT | Sasong |
| measured_at | TIMESTAMP | Matningstidpunkt |
| birth_year_coverage | DECIMAL | % riders med fodelseår |
| club_coverage | DECIMAL | % riders med klubb |
| gender_coverage | DECIMAL | % riders med kon |
| class_coverage | DECIMAL | % results med klass |
| potential_duplicates | INT | Antal potentiella dubbletter |
| merged_riders | INT | Antal sammanslagna riders |

#### `analytics_kpi_definitions` (NY)
Dokumentation av alla KPI-definitioner.

| Kolumn | Typ | Beskrivning |
|--------|-----|-------------|
| kpi_code | VARCHAR | Unik kod (t.ex. retention_from_prev) |
| kpi_name | VARCHAR | Lasbart namn |
| description | TEXT | Fullstandig beskrivning |
| formula | TEXT | Matematisk formel |
| numerator_desc | VARCHAR | Beskrivning av taljare |
| denominator_desc | VARCHAR | Beskrivning av namnare |
| implementation_method | VARCHAR | PHP-metod som implementerar KPI |

---

## Karnkomponenter

### 1. AnalyticsConfig.php (v3.0)

Centraliserad konfiguration for hela analytics-plattformen.

**Platform-information:**
```php
PLATFORM_VERSION = '3.0.0';
CALCULATION_VERSION = 'v3';
```

**Aktivitetsdefinition:**
```php
// "Active ar Y" = rider har minst N unika events under season_year=Y
// OBS: Detta ar EVENTS (unika event_id), inte starter/heat/resultatrader
ACTIVE_MIN_EVENTS = 1;
```

**Retention-typer (VIKTIGT):**
```php
// Typ 1: Classic retention - "Hur manga av forra arets riders kom tillbaka?"
RETENTION_TYPE_FROM_PREV = 'retention_from_prev';
// Formel: (riders i bade N och N-1) / (riders i N-1) * 100

// Typ 2: Returning share - "Hur stor andel av arets riders ar aterkommande?"
RETENTION_TYPE_RETURNING_SHARE = 'returning_share_of_current';
// Formel: (riders i bade N och N-1) / (riders i N) * 100
```

**Churn-nivåer:**
```php
SOFT_CHURN_YEARS = 1;   // 1 ar inaktiv
MEDIUM_CHURN_YEARS = 2; // 2 ar inaktiv
HARD_CHURN_YEARS = 3;   // 3+ ar inaktiv
```

**Klassrankning (ars-versionerad):**
```php
CLASS_RANKING_2024 = [
    'Elite' => 100,    // Hogst
    'Senior' => 80,
    'Junior' => 60,
    'Sport' => 30,
    'Fun' => 10,       // Lagst
];

// FALLBACK: Om klass ar okand, returnerar getClassRank() null
// At-Risk ignorerar class_downgrade for okanda klasser
```

**Dynamisk serie-cutoff:**
```php
USE_DYNAMIC_SERIES_CUTOFF = true;

// Cutoff baserat pa MAX(last_event_date) + 14 dagar
// istallet for statiskt datum
getSeasonActivityCutoffDate($year, $seriesId, $pdo);
```

**Riskfaktorer for At-Risk:**
| Faktor | Vikt | Beskrivning |
|--------|------|-------------|
| declining_events | 30 | Minskande starter over tid |
| no_recent_activity | 25 | Ingen aktivitet efter cutoff |
| class_downgrade | 15 | Gatt ner i klass (ignoreras for okanda klasser) |
| single_series | 10 | Endast en serie |
| low_tenure | 10 | Kort karriar (1-2 ar) |
| high_age_in_class | 10 | Hog alder i klassen |

**Datakvalitetstrosklar:**
```php
DATA_QUALITY_THRESHOLDS = [
    'birth_year_coverage' => 0.5,  // 50% av riders maste ha birth_year
    'club_coverage' => 0.3,        // 30% maste ha club
    'class_coverage' => 0.7,       // 70% av results maste ha klass
];
```

### 2. KPICalculator.php (~2500 rader)

Huvudklass for alla KPI-berakningar.

**Retention & Growth:**
```php
// Classic retention (forra arets perspektiv)
getRetentionRate($year): float  // 0-100%

// Returning share (arets perspektiv) - NY
getReturningShareOfCurrent($year): float  // 0-100%

// Churn (inverterad retention)
getChurnRate($year): float  // 100 - retention

// Samlade metrics - NY
getRetentionMetrics($year): array
// Returnerar:
// [
//     'retention_from_prev' => ['value' => X, 'definition' => '...', 'formula' => '...'],
//     'returning_share_of_current' => [...],
//     'churn_rate' => [...],
//     'rookie_rate' => [...],
//     'growth_rate' => [...],
// ]

// Tillvaxt
getGrowthRate($year): float
getGrowthTrend($years): array

// Rookies
getNewRidersCount($year): int
getRookieRate($year): float  // NY - andel nya
```

**Datakvalitet (NY):**
```php
// Hamta datakvalitetsmetrics
getDataQualityMetrics($year): array
// Returnerar:
// [
//     'birth_year_coverage' => 72.5,  // %
//     'club_coverage' => 85.2,
//     'gender_coverage' => 68.1,
//     'class_coverage' => 91.0,
//     'potential_duplicates' => 15,
//     'quality_status' => 'good'|'warning'|'critical',
// ]

// Spara till databas
saveDataQualityMetrics($year): bool
```

**Cohort:**
```php
getCohortRetention($year, $maxYears = 5): array
getCohortRetentionByBrand($year, $brandId): array
getCohortStatusBreakdown($year): array
getCohortRiders($year, $status = null): array
getCohortAverageLifespan($year): float
```

**Series Flow:**
```php
getEntryPointDistribution($year): array
calculateFeederMatrix($year): array
getSeriesLoyaltyRate($seriesId, $year): float
getSeriesOverlap($series1, $series2, $year): array
```

**At-Risk:**
```php
getRidersAtRisk($year, $limit = 100): array
calculateRiskScore($riderId, $year): int  // 0-100
```

### 3. AnalyticsEngine.php

Motor for databerakning och lagring.

**Berakningsmetoder:**
```php
calculateYearlyStats($year): int
calculateYearlyStatsBulk($year): int  // Snabbare, en SQL
calculateSeriesParticipation($year): int
calculateSeriesCrossover($year): int
calculateClubStats($year): int
calculateVenueStats($year): int

// Kor allt
refreshAllStats($year): array
refreshAllStatsFast($year): array  // Anvander bulk-metoder
```

**Jobbhantering:**
```php
startJob($name, $key, $force = false): int|false
endJob($status, $rowsAffected, $log = []): void
```

### 4. SVGChartRenderer.php (NY)

PHP-baserad grafrendering utan Node.js-beroenden.

**Graftyper:**
```php
$renderer = new SVGChartRenderer(['width' => 600, 'height' => 300]);

// Linjediagram (trender)
$svg = $renderer->lineChart([
    'labels' => ['2020', '2021', '2022', '2023', '2024'],
    'datasets' => [
        ['label' => 'Retention', 'data' => [65, 70, 68, 72, 75], 'color' => '#37d4d6'],
    ]
]);

// Stapeldiagram
$svg = $renderer->barChart([
    'labels' => ['U15', '15-25', '26-35', '36-45', '46+'],
    'values' => [120, 350, 480, 320, 150],
]);

// Donut-diagram
$svg = $renderer->donutChart([
    'labels' => ['Enduro', 'DH', 'XC'],
    'values' => [450, 230, 180],
]);

// Sparkline (mini-trend)
$svg = $renderer->sparkline([65, 70, 68, 72, 75], ['width' => 100, 'height' => 30]);

// Stacked bar
$svg = $renderer->stackedBarChart([
    'labels' => ['2022', '2023', '2024'],
    'datasets' => [
        ['label' => 'Active', 'data' => [500, 520, 550], 'color' => '#10b981'],
        ['label' => 'Churned', 'data' => [100, 90, 80], 'color' => '#ef4444'],
    ]
]);
```

**Farger:** Anvander TheHUB designsystem automatiskt.

**PNG-export:**
```php
$png = $renderer->svgToPng($svg, 2);  // 2x scale for retina
file_put_contents('chart.png', $png);
```

### 5. ExportLogger.php (NY)

GDPR-kompatibel loggning av alla exporter.

**Logga export:**
```php
$logger = new ExportLogger($pdo);

$exportId = $logger->logExport('riders_at_risk', $data, [
    'year' => 2024,
    'format' => 'csv',
    'user_id' => $_SESSION['user_id'],
    'filters' => ['min_risk' => 60],
]);
```

**Skapa manifest:**
```php
$manifest = $logger->createManifest('cohort_export', $data, [
    'year' => 2020,
    'snapshot_id' => 123,
]);
// Returnerar:
// [
//     'export_type' => 'cohort_export',
//     'exported_at' => '2026-01-16 14:30:00',
//     'platform_version' => '3.0.0',
//     'row_count' => 450,
//     'data_fingerprint' => 'sha256...',
//     'contains_pii' => true,
//     'pii_fields' => ['firstname', 'lastname'],
//     ...
// ]
```

**Verifiera export:**
```php
$isValid = $logger->verifyExport($exportId, $data);  // true/false
```

**Statistik:**
```php
$stats = $logger->getExportStats('month');
// [
//     'total_exports' => 45,
//     'unique_users' => 8,
//     'pii_exports' => 12,
//     'top_types' => [...]
// ]
```

### 6. IdentityResolver.php

Hanterar rider-identitet och sammanslagning av dubbletter.

```php
$resolver = new IdentityResolver($pdo);

// Hitta canonical ID
$canonicalId = $resolver->getCanonicalId($riderId);

// Sla samman dubbletter
$resolver->mergeRiders($keepId, $mergeId, $adminId, $reason);
```

---

## KPI-definitioner

**KRITISKT:** Dessa definitioner maste anvandas konsekvent i alla rapporter.

### Retention Rate (retention_from_prev)

**Fraga:** "Hur manga procent av forra arets riders kom tillbaka?"

```
Taljare:   Riders som deltog BADE ar N OCH ar N-1
Namnare:   Riders som deltog ar N-1
Formel:    (retained / prev_total) * 100
```

**Exempel:**
- 2023 hade 1000 riders
- 2024 har 800 av dessa 1000 tillbaka
- Retention = 800/1000 = **80%**

**Implementation:** `KPICalculator::getRetentionRate($year)`

---

### Returning Share (returning_share_of_current)

**Fraga:** "Hur stor andel av ARETS deltagare ar aterkommande?"

```
Taljare:   Riders som deltog BADE ar N OCH ar N-1
Namnare:   Riders som deltog ar N
Formel:    (retained / current_total) * 100
```

**Exempel:**
- 2024 har 1100 riders totalt
- 800 av dessa deltog aven 2023
- Returning share = 800/1100 = **72.7%**

**Implementation:** `KPICalculator::getReturningShareOfCurrent($year)`

---

### Churn Rate

**Fraga:** "Hur manga procent av forra arets riders SLUTADE?"

```
Formel:    100 - retention_from_prev
```

**Exempel:**
- Retention = 80%
- Churn = 100 - 80 = **20%**

**Implementation:** `KPICalculator::getChurnRate($year)`

---

### Rookie Rate

**Fraga:** "Hur stor andel av arets riders ar NYBORJARE?"

```
Taljare:   Riders dar MIN(season_year) = aktuellt ar
Namnare:   Alla riders ar N
Formel:    (rookies / total) * 100
```

**Implementation:** `KPICalculator::getRookieRate($year)`

---

### Growth Rate

**Fraga:** "Hur mycket vaxte/minskade deltagarantalet?"

```
Formel:    ((riders_N - riders_N-1) / riders_N-1) * 100
```

**Implementation:** `KPICalculator::getGrowthRate($year)`

---

### Active Rider

**Definition:** En rider ar "aktiv ar Y" om:
- Rider har minst **1 registrerad event** (unik event_id) under season_year=Y
- **OBS:** "Event" = unik event_id, INTE antal starter/heat/resultatrader

**Konstant:** `AnalyticsConfig::ACTIVE_MIN_EVENTS = 1`

---

## Analytics-sidor

### Dashboard (`analytics-dashboard.php`)

**Syfte:** Huvudoversikt med alla nyckeltal

**Visar:**
- KPI-sammanfattning (retention, churn, growth, rookies)
- Tillvaxttrend (5 ar)
- Top 10 klubbar
- Aldersfordelning (donut chart)
- Disciplinfordelning
- Entry points (var borjar riders)

**Filter:** Ar, Jamfor med annat ar

---

### Kohort-analys (`analytics-cohorts.php`)

**Syfte:** Folj hur en argang utvecklas over tid

**Nyckelkoncept:**
- Kohort = alla som borjade samma ar
- Retention = % som fortfarande ar aktiva
- Churn-kategorier: soft (1 ar), medium (2 ar), hard (3+ ar)

**Filter:** Varumarke, Kohort-ar, Multi-kohort jamforelse

---

### At-Risk (`analytics-atrisk.php`)

**Syfte:** Identifiera riders som riskerar att sluta

**Risk-nivaer:**
- 0-39: Lag risk (gron)
- 40-59: Medel risk (gul)
- 60-79: Hog risk (orange)
- 80+: Kritisk (rod)

---

### Datakvalitet (`analytics-data-quality.php`) - NY

**Syfte:** Analysera och forbattra datakvaliteten

**Visar:**
- Overall status (good/warning/critical)
- Coverage per falt med procent och troskel
- Potentiella dubbletter
- Matningshistorik
- Rekommendationer

**Trosklar:**
- Birth year: 50%
- Club: 30%
- Class: 70%
- Event date: 90%

---

### Series Flow (`analytics-flow.php`)

**Syfte:** Visualisera rider-floden mellan serier

---

### Reports (`analytics-reports.php`)

**Rapporttyper:**
1. Arssammanfattning (Summary)
2. Retention & Churn-analys
3. Serie-analys
4. Klubbrapport
5. Demografisk Oversikt
6. Nya Deltagare (Rookies)

**Export:**
- CSV (alla rapporter)
- PDF (med SVGChartRenderer)

---

## Datakvalitet

### Varfor datakvalitet ar viktigt

Analyser ar bara sa bra som underliggande data. Om 50% av riders saknar fodelseår blir aldersbaserade analyser otillforlitliga.

### Matning

**Automatiskt:**
```php
$kpi = new KPICalculator($pdo);
$metrics = $kpi->getDataQualityMetrics(2024);
```

**Spara till databas:**
```php
$kpi->saveDataQualityMetrics(2024);  // Sparar till data_quality_metrics
```

### Kvalitetsstatus

| Status | Kriterier |
|--------|-----------|
| **Good** | Alla falt over troskel |
| **Warning** | 1 falt under troskel |
| **Critical** | 2+ falt under troskel |

### Rekommendationer

Systemet ger automatiska rekommendationer baserat pa data:

- **Lat fodelseår-tackning:** At-Risk berakningar som anvander alder kan bli opalitliga
- **Lat klubb-tackning:** Klubbstatistik och geografisk analys blir ofullstandig
- **Potentiella dubbletter:** Anvand Rider Merge-verktyget for att granska

---

## Export och Reproducerbarhet

### Principer

1. **Alla exporter loggas** med fullstandig metadata
2. **Fingerprint** (SHA256) sparas for verifiering
3. **Manifest** innehaller alla parametrar for reproducerbarhet
4. **PII-flagga** markerar exporter med persondata

### Anvandning

**Logga export:**
```php
$logger = new ExportLogger($pdo);
$exportId = $logger->logExport('riders_at_risk', $data, [
    'year' => 2024,
    'user_id' => $_SESSION['user_id'],
]);
```

**Verifiera tidigare export:**
```php
$manifest = $logger->getManifest($exportId);
$isValid = $logger->verifyExport($exportId, $currentData);
```

### GDPR

- Exporter med persondata (`contains_pii = 1`) loggas separat
- IP-adress sparas for sparbarhet
- Statistik tillganglig: `$logger->getExportStats('month')`

---

## Setup och Underhall

### Initial Setup

1. **Kor migrationer:**
```bash
mysql -u user -p database < analytics/migrations/001_analytics_tables.sql
mysql -u user -p database < analytics/migrations/006_production_readiness.sql
```

2. **Populera historisk data:**
```bash
php analytics/populate-historical.php
```
Eller via admin: `/admin/analytics-populate.php`

### Dagligt Underhall

**Cron-jobb** (`analytics/cron/refresh-analytics.php`):
```bash
0 4 * * * /usr/bin/php /var/www/thehub/analytics/cron/refresh-analytics.php
```

Kor dagligen och:
- Uppdaterar rider_yearly_stats
- Beraknar series_participation
- Uppdaterar club_stats
- Skapar snapshots

### Reset

**Vid behov av omberakning:**
```bash
/admin/analytics-reset.php
```

**OBS:** Kraver ombefolkning efterat!

---

## Changelog

### v3.0.0 (2026-01-16) - Production Readiness

**Prio 1 - Korrekthet & Exporter:**
- Tydliggjorda KPI-definitioner med separata metoder
  - `getRetentionRate()` vs `getReturningShareOfCurrent()`
- Snapshot v2 med reproducerbarhet
  - `generated_at`, `season_year`, `source_max_updated_at`, `code_version`, `data_fingerprint`
- Export logging med GDPR-sparbarhet
  - Ny tabell `analytics_exports`
  - `ExportLogger.php` klass
- KPI-definitionstabell `analytics_kpi_definitions`

**Prio 2 - At-Risk & Klasslogik:**
- Ars-versionerad klassrankning (`CLASS_RANKING_BY_YEAR`)
- Fallback for okanda klasser (ignorerar `class_downgrade`)
- Ny metod `isClassDowngrade()`
- Dynamisk serie-cutoff baserat pa `last_event_date`

**Prio 3 - Skalbarhet:**
- Datakvalitetspanel (`admin/analytics-data-quality.php`)
- Ny tabell `data_quality_metrics`
- SVG ChartRenderer for PDF-export utan Node.js
  - Line, Bar, Donut, Sparkline, Stacked Bar

**Nya metoder i KPICalculator:**
- `getReturningShareOfCurrent()`
- `getRookieRate()`
- `getRetentionMetrics()`
- `getDataQualityMetrics()`
- `saveDataQualityMetrics()`

**Nya filer:**
- `analytics/includes/SVGChartRenderer.php`
- `analytics/includes/ExportLogger.php`
- `analytics/migrations/006_production_readiness.sql`
- `admin/analytics-data-quality.php`
- `api/analytics/save-quality-metrics.php`

### v2.0.0 (2026-01-14)

- Cohort-analys med varumarkesfilter
- At-Risk prediction
- Series flow-visualisering
- Geography-analys

### v1.0.0 (2025)

- Initial release
- Basic KPI-berakningar
- Pre-aggregerade tabeller

---

*Dokumentation skapad: 2026-01-16*
*Analytics Platform Version: 3.0.0*
*Calculation Version: v3*
