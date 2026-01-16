# TheHUB Analytics Platform - Teknisk Dokumentation

> Komplett guide till analytics-systemet v3.0.2, dess komponenter och SCF-nivå-rapportering

**Version:** 3.0.2 (Fully Revision-Safe)
**Senast uppdaterad:** 2026-01-16
**Status:** Production Ready - Fully Revision-Safe
**Implementation Status:** KOMPLETT

---

## Innehallsforteckning

1. [Oversikt](#oversikt)
2. [Revision Notes v3.0.2](#revision-notes-v302)
3. [Production Ready Checklist](#production-ready-checklist)
4. [Arkitektur](#arkitektur)
5. [KPI-definitioner (KRITISKT)](#kpi-definitioner-kritiskt)
6. [Snapshot → Export-modell](#snapshot--export-modell)
7. [PDF-policy (TCPDF Mandatory)](#pdf-policy-tcpdf-mandatory)
8. [Identity Resolution och Recalc](#identity-resolution-och-recalc)
9. [Rate Limiting (DB-baserat)](#rate-limiting-db-baserat)
10. [Databas-struktur](#databas-struktur)
11. [Karnkomponenter](#karnkomponenter)
12. [Export och Reproducerbarhet](#export-och-reproducerbarhet)
13. [Datakvalitet](#datakvalitet)
14. [Drift och Cron](#drift-och-cron)
15. [Sakerhet och GDPR](#sakerhet-och-gdpr)
16. [Analytics-sidor](#analytics-sidor)
17. [API-endpoints](#api-endpoints)
18. [Setup och Underhall](#setup-och-underhall)
19. [Changelog](#changelog)
20. [Revision Audit Trail](#revision-audit-trail)

---

## Oversikt

TheHUB Analytics ar ett komplett analysverktyg for svensk cykelsport med **10+ ars data** och stod for SCF-nivå-rapportering. Version 3.0.2 ar **Fully Revision-Safe** - varje export kan aterskaps exakt och tål extern granskning.

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
| **Export Center** | Hantera och verifiera exporter | `analytics-export-center.php` |

### Revisionsprinciper (v3.0.2)

1. **Snapshot-baserad reproducerbarhet** - Varje export MASTE ha `snapshot_id`
2. **EVENT-baserad aktivitet** - `active_rider` mats via eventdeltagande, INTE resultatrader
3. **TCPDF OBLIGATORISK** - Ingen HTML-fallback i production
4. **DB-baserade rate limits** - Configurable via `export_rate_limits` tabell
5. **Deterministisk fingerprint** - Sorterad JSON + stabil encoding
6. **Heuristikmarkeringar** - `at_risk_rider` och `winback_candidate` markeras explicit

---

## Revision Notes v3.0.2

### Kritiska korrigeringar fran v3.0.1

| Problem | Losning i v3.0.2 |
|---------|------------------|
| `active_rider` definierades som resultat-baserad | **Korrigerat**: Nu EVENT-baserad (`COUNT(DISTINCT event_id) >= 1`) |
| `at_risk_rider` framstod som definitiv | **Korrigerat**: Markerad som HEURISTIC |
| `winback_candidate` framstod som definitiv | **Korrigerat**: Markerad som HEURISTIC |
| Rate limits hardkodade (50/h, 200/d) | **Korrigerat**: DB-styrt via `export_rate_limits` |
| PDF fallback till HTML tillatet | **Korrigerat**: TCPDF OBLIGATORISK, exception om saknas |
| Snapshot→Export-modell otydlig | **Korrigerat**: Tydlig dokumentation av vad snapshot laser |
| affected_years-bestamning otydlig | **Korrigerat**: Dokumenterad prioritet (stats→results→manual) |

### Vad en Snapshot laser

En snapshot i v3.0.2 laser:
- **KPI-varden** beraknade vid snapshot-tidpunkten
- **Pre-aggregerade tabeller** (`rider_yearly_stats`, `series_participation`, `club_yearly_stats`)
- **Metadata** (`source_max_updated_at`, `data_fingerprint`, `tables_included`)

En snapshot laser **INTE**:
- Versionerade kopior av tabeller (ingen historiktabell skapas)
- Radata (results, events) - dessa lasas alltid live

**Reproducerbarhet**: Export kan reproduceras exakt sa lange pre-aggregaten INTE har omraknats sedan snapshot skapades.

---

## Production Ready Checklist

**Status: IMPLEMENTERAT 2026-01-16 (v3.0.2)**

### Databas
- [x] Migration `007_production_ready.sql` - Grundstruktur
  - Fil: `analytics/migrations/007_production_ready.sql`
- [x] Migration `008_revision_grade_fixes.sql` - Revision-safe korrigeringar (NY)
  - Fil: `analytics/migrations/008_revision_grade_fixes.sql`
- [x] KPI-definitioner korrigerade (`active_rider` EVENT-baserad)
- [x] `at_risk_rider` och `winback_candidate` markerade som HEURISTIC
- [x] `export_rate_limits` tabell med scope (global/user/ip/role)
- [x] `analytics_recalc_queue.years_source` kolumn tillagd
- [x] `analytics_snapshots.data_fingerprint` kolumn tillagd
- [x] `analytics_kpi_audit` tabell skapad (revisionskrav)
- [x] `analytics_system_config` tabell for PDF-policy

### Kod
- [x] `ExportLogger` - DB-baserade rate limits
  - Fil: `analytics/includes/ExportLogger.php`
  - Metod: `loadRateLimitsFromDb()` laddar fran `export_rate_limits`
  - Metod: `calculateDeterministicFingerprint()` med stabil encoding
- [x] `PdfExportBuilder` - TCPDF OBLIGATORISK
  - Fil: `analytics/includes/PdfExportBuilder.php`
  - Kastar `PdfEngineException` om TCPDF saknas
  - Ingen HTML-fallback
  - Metod: `getPdfEngineStatus()` returnerar OK/MISSING (CRITICAL)
- [x] `IdentityResolver` - Forbattrad affected_years
  - Fil: `analytics/includes/IdentityResolver.php`
  - Metod: `findAffectedYearsWithSource()` med prioritet stats→results→manual
  - Kolumn `years_source` sparas i recalc_queue
- [x] Export Center visar PDF ENGINE STATUS
  - Fil: `admin/analytics-export-center.php`
  - Blockerar PDF-knappar om TCPDF saknas

### Sakerhet/GDPR
- [x] Rate limits lasas fran databas (ej hardkodade)
- [x] `snapshot_id` ALLTID obligatorisk (ingen overstyrning)
- [x] KPI-audit trail for definitionsandringar

---

## Arkitektur

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                           PRESENTATION LAYER                                 │
│  analytics-dashboard │ analytics-cohorts │ analytics-export-center          │
│                                                                              │
│  PdfExportBuilder.php ─── PDF via TCPDF (MANDATORY) + Definitions block     │
│  SVGChartRenderer.php ─── Grafer (PNG via Imagick om tillganglig)           │
└─────────────────────────────────┬───────────────────────────────────────────┘
                                  │
┌─────────────────────────────────▼───────────────────────────────────────────┐
│                           CALCULATION LAYER                                  │
│                                                                              │
│  ┌─────────────────────┐     ┌─────────────────────┐                        │
│  │   KPICalculator     │     │   ExportLogger      │                        │
│  │                     │     │   v3.0.2            │                        │
│  │  - EVENT-baserad    │     │   - snapshot_id REQ │                        │
│  │    active_rider     │     │   - DB rate limits  │                        │
│  │  - Heuristic KPIs   │     │   - Deterministic   │                        │
│  │    markerade        │     │     fingerprint     │                        │
│  └─────────────────────┘     └─────────────────────┘                        │
└─────────────────────────────────┬───────────────────────────────────────────┘
                                  │
┌─────────────────────────────────▼───────────────────────────────────────────┐
│                           ENGINE LAYER                                       │
│                                                                              │
│  ┌─────────────────────┐     ┌─────────────────────┐                        │
│  │  AnalyticsEngine    │     │  IdentityResolver   │                        │
│  │  v3.0.2             │     │  v3.0.2             │                        │
│  │  - calculateYearly  │────▶│  - merge()          │───┐                    │
│  │  - processRecalc()  │     │  - queueRecalc()    │   │                    │
│  │  - heartbeat()      │     │  - affected_years   │   │                    │
│  │  - createSnapshot() │     │    med source       │   │                    │
│  └─────────────────────┘     └─────────────────────┘   │                    │
│                                                        │                    │
│  ┌─────────────────────────────────────────────────────▼──────────────────┐│
│  │  analytics_recalc_queue                                                 ││
│  │  + years_source (stats/results/manual)                                  ││
│  └─────────────────────────────────────────────────────────────────────────┘│
└─────────────────────────────────┬───────────────────────────────────────────┘
                                  │
┌─────────────────────────────────▼───────────────────────────────────────────┐
│                           DATA LAYER                                         │
│                                                                              │
│  PRE-AGGREGATED TABLES              │  REFERENCE TABLES                     │
│  ─────────────────────              │  ────────────────                     │
│  rider_yearly_stats                 │  analytics_kpi_definitions (v3.0.2)  │
│  series_participation               │  analytics_kpi_audit (NY v3.0.2)     │
│  club_yearly_stats                  │  analytics_system_config (NY v3.0.2) │
│  analytics_snapshots (+fingerprint) │  export_rate_limits (v3.0.2)         │
│  analytics_exports                  │                                       │
│                                     │  RAW TABLES                           │
│                                     │  ──────────                           │
│                                     │  results, events, riders, clubs       │
└─────────────────────────────────────────────────────────────────────────────┘
```

---

## KPI-definitioner (KRITISKT)

**KRITISKT:** Dessa definitioner ar **obligatoriska** i alla PDF-exporter och maste vara **exakt korrekta** for revisionsandamal.

### active_rider (KORRIGERAD v3.0.2)

| Attribut | Varde |
|----------|-------|
| **Key** | `active_rider` |
| **Namn (SV)** | Aktiv deltagare |
| **Definition** | En deltagare som deltog i **minst 1 event** under angiven period |
| **Formel** | `COUNT(DISTINCT event_id) >= ACTIVE_MIN_EVENTS` |
| **Implementation** | Baserat pa `rider_yearly_stats.total_events` |
| **ACTIVE_MIN_EVENTS** | 1 (konfigurerbart i `analytics_kpi_definitions`) |

**FEL i v3.0.1:** Definierades som "minst 1 registrerat resultat" (resultatrad-baserad).
**KORRIGERING:** Aktivitet mats via **unika eventdeltaganden**, inte resultatrader.

### Heuristiska KPIs (MARKERADE v3.0.2)

Foljande KPIs ar **prediktiva indikatorer**, INTE definitiva klassificeringar:

| KPI | Markning | Definition |
|-----|----------|------------|
| `at_risk_rider` | **HEURISTIC** | Aktiv forra aret men visar minskat eventdeltagande |
| `winback_candidate` | **HEURISTIC** | Deltagare aktiv for 2+ ar sedan men noll events forra aret |

**Formel (at_risk_rider):**
```
total_events_current < total_events_previous
OR (active_prev_year AND total_events_current = 0)
```

**Formel (winback_candidate):**
```
MAX(season_year WHERE total_events > 0) <= current_year - 2
```

### Alla KPI-definitioner

| kpi_key | definition_sv | formula | category |
|---------|---------------|---------|----------|
| `retention_from_prev` | Andel av förra årets deltagare som också tävlade i år | `(riders_in_both / riders_in_Y-1) * 100` | retention |
| `returning_share_of_current` | Andel av årets deltagare som även tävlade förra året | `(riders_in_both / riders_in_Y) * 100` | retention |
| `churn_rate` | Andel av förra årets deltagare som INTE tävlade i år | `100 - retention_from_prev` | retention |
| `rookie_rate` | Andel av årets deltagare som är förstagångsdeltagare | `(new_riders / total_riders) * 100` | acquisition |
| `active_rider` | **EVENT-BASERAD:** Deltagare med minst 1 event under perioden | `COUNT(DISTINCT event_id) >= 1` | definition |
| `at_risk_rider` | **HEURISTIC:** Aktiv förra året men visar nedgång | Se ovan | definition |
| `winback_candidate` | **HEURISTIC:** Aktiv 2+ år sedan men inte förra året | Se ovan | definition |
| `ltv_events` | Totalt antal events en deltagare har deltagit i | `SUM(events_per_year)` | value |
| `cohort_year` | Första året en deltagare deltog i något event | `MIN(season_year)` | definition |
| `ACTIVE_MIN_EVENTS` | **CONFIG:** Minsta antal events för aktiv | `1` | config |

---

## Snapshot → Export-modell

### Modell A: Snapshot laser KPI + pre-aggregat

v3.0.2 anvander **Modell A**:

```
Snapshot = {
    id: 1234,
    created_at: "2026-01-16 14:30:00",
    source_max_updated_at: "2026-01-16 14:29:00",
    data_fingerprint: "sha256:abc123...",
    tables_included: ["rider_yearly_stats", "series_participation", "club_yearly_stats"]
}
```

**Vad snapshot laser:**
1. KPI-varden beraknade vid tidpunkten
2. Checksums pa pre-aggregerade tabeller
3. Max updated_at fran kalltabeller

**Vad snapshot INTE laser:**
- Versionerade tabellkopior (ingen "rider_yearly_stats_snapshot_1234")
- Radatatabeller

### Export-flode

```
1. Anvandare begar export
         │
         ▼
2. Hamta/skapa snapshot (getOrCreateSnapshot)
         │
         ▼
3. Validera snapshot_id (REQUIRED) → PdfEngineException/InvalidArgumentException
         │
         ▼
4. Hamta data fran pre-aggregerade tabeller
         │
         ▼
5. Berakna DETERMINISTISK fingerprint:
   - Sortera nycklar rekursivt
   - JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK
   - SHA256
         │
         ▼
6. Skapa manifest:
   {
     snapshot_id: 1234,
     generated_at: "2026-01-16T14:30:00Z",
     season_year: 2025,
     source_max_updated_at: "2026-01-16T14:29:00",
     platform_version: "3.0.2",
     calculation_version: "v3",
     data_fingerprint: "sha256:..."
   }
         │
         ▼
7. Spara till analytics_exports
         │
         ▼
8. Om PDF: PdfExportBuilder (TCPDF) med Definition Box
```

### Reproducerbarhet

**Export kan reproduceras EXAKT** om:
1. Snapshot fortfarande finns
2. Pre-aggregerade tabeller INTE har omraknats sedan snapshot skapades

**Om pre-aggregat omraknats:**
- Ny export med samma parametrar kan ge annorlunda data
- Ursprunglig export manifest visar `source_max_updated_at` for jamforelse

---

## PDF-policy (TCPDF Mandatory)

### Policy (v3.0.2)

| Attribut | Varde |
|----------|-------|
| **PDF Engine** | TCPDF (OBLIGATORISK) |
| **Fallback** | INGEN (exception kastas) |
| **HTML-fallback** | FORBJUDEN i production |
| **wkhtmltopdf** | DEPRECATED (returnerar alltid null) |

### Implementation

```php
// PdfExportBuilder constructor
public function __construct(PDO $pdo, int $snapshotId) {
    // v3.0.2: Verifiera att TCPDF ar tillganglig
    if (!self::isTcpdfAvailable()) {
        throw new PdfEngineException(
            'TCPDF is required for PDF export in v3.0.2. ' .
            'Install TCPDF: composer require tecnickcom/tcpdf'
        );
    }
    // ...
}
```

### Export Center visning

```
PDF ENGINE STATUS:
  [x] OK (TCPDF 6.x)
    - PDF-export tillganglig
    - Alla knappar aktiverade

  [ ] MISSING (CRITICAL)
    - PDF-export BLOCKERAD
    - Installera: composer require tecnickcom/tcpdf
```

### Installation

```bash
composer require tecnickcom/tcpdf
```

TCPDF soker automatiskt i:
- `vendor/tecnickcom/tcpdf/tcpdf.php`
- `/usr/share/php/tcpdf/tcpdf.php`
- `analytics/includes/tcpdf/tcpdf.php`

---

## Identity Resolution och Recalc

### Merge → Recalc Policy (v3.0.2)

```
1. IdentityResolver::merge() anropas
         │
         ▼
2. Merge-record skapas i rider_merge_map
         │
         ▼
3. findAffectedYearsWithSource() anropas:
   PRIORITET:
   a) rider_yearly_stats (source='stats') - SNABBAST
   b) raw results (source='results') - FALLBACK
   c) tom lista (source='manual')
         │
         ▼
4. queueRecalc() med:
   - Sorterade rider IDs (deterministisk checksum)
   - affected_years med source
   - years_source kolumn satt
         │
         ▼
5. Checksum beraknas for deduplication (SHA256)
         │
         ▼
6. Jobb laggs i analytics_recalc_queue:
   - trigger_type = 'merge'
   - priority = 3 (hog)
   - years_source = 'stats'/'results'/'manual'
         │
         ▼
7. Nasta cron: processRecalcQueue()
   - Omberaknar yearly stats for affected riders/years
   - IDEMPOTENT (samma input → samma resultat)
```

### affected_years bestamning

```php
public function findAffectedYearsWithSource(array $riderIds): array {
    // 1. Forsoker rider_yearly_stats (primär, snabb)
    $years = query("SELECT DISTINCT season_year FROM rider_yearly_stats WHERE rider_id IN (...)");
    if (!empty($years)) {
        return ['years' => $years, 'source' => 'stats'];
    }

    // 2. Fallback till raw results (om stats saknas)
    $years = query("SELECT DISTINCT YEAR(e.date) FROM results JOIN events...");
    if (!empty($years)) {
        return ['years' => $years, 'source' => 'results'];
    }

    // 3. Tom lista (manuell hantering kravs)
    return ['years' => [], 'source' => 'manual'];
}
```

---

## Rate Limiting (DB-baserat)

### Tabellstruktur (v3.0.2)

```sql
CREATE TABLE export_rate_limits (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    scope ENUM('global', 'user', 'ip', 'role') NOT NULL DEFAULT 'user',
    scope_value VARCHAR(100) NULL,
    max_exports INT UNSIGNED NOT NULL DEFAULT 50,
    window_seconds INT UNSIGNED NOT NULL DEFAULT 3600,
    max_rows_per_export INT UNSIGNED NULL,
    max_rows_per_window INT UNSIGNED NULL,
    current_count INT UNSIGNED DEFAULT 0,
    window_start DATETIME NULL,
    window_end DATETIME NULL,
    enabled TINYINT(1) DEFAULT 1,
    UNIQUE INDEX idx_rate_unique_scope (scope, scope_value)
);
```

### Default-varden

| Scope | Scope Value | Max Exports | Window |
|-------|-------------|-------------|--------|
| global | NULL | 50 | 1 timme |
| global | daily | 200 | 24 timmar |
| role | super_admin | 500 | 1 timme |

### Prioritetsordning

1. **User-specific** (scope='user', scope_value=user_id)
2. **Role-specific** (scope='role', scope_value=role_name)
3. **IP-specific** (scope='ip', scope_value=ip_address)
4. **Global** (scope='global')

### Anvandning

```php
// ExportLogger laddar automatiskt fran DB
$logger = new ExportLogger($pdo);

// Kontrollera (med roll-stod)
$status = $logger->getRateLimitStatus($userId, $ipAddress, $role);
// Returns:
// [
//   'primary' => ['current' => 5, 'limit' => 50, 'window_seconds' => 3600],
//   'hourly' => ['current' => 5, 'limit' => 50],
//   'daily' => ['current' => 12, 'limit' => 200],
//   'can_export' => true
// ]

// Andra via DB
$logger->setRateLimits(100, 500, 'role', 'analytics_admin');
```

---

## Databas-struktur

### Migration 008: Revision Grade Fixes

#### `analytics_exports` (uppdaterad)

```sql
ALTER TABLE analytics_exports
    MODIFY COLUMN snapshot_id INT UNSIGNED NOT NULL,
    MODIFY COLUMN status ENUM('pending', 'processing', 'completed', 'failed') NOT NULL DEFAULT 'pending';
```

#### `analytics_kpi_definitions` (uppdaterad)

```sql
-- active_rider korrigerad
UPDATE analytics_kpi_definitions
SET definition = 'A rider who participated in at least 1 event...',
    formula = 'COUNT(DISTINCT event_id) >= 1'
WHERE kpi_key = 'active_rider';

-- at_risk_rider markerad som HEURISTIC
UPDATE analytics_kpi_definitions
SET definition = 'HEURISTIC: Active last year but showing decline...'
WHERE kpi_key = 'at_risk_rider';
```

#### `export_rate_limits` (NY struktur)

| Kolumn | Typ | Beskrivning |
|--------|-----|-------------|
| scope | ENUM | 'global', 'user', 'ip', 'role' |
| scope_value | VARCHAR(100) | Varde for scope |
| max_exports | INT UNSIGNED | Max antal exporter per window |
| window_seconds | INT UNSIGNED | Fonsterstorlek (3600=1h, 86400=1d) |

#### `analytics_recalc_queue` (uppdaterad)

```sql
ALTER TABLE analytics_recalc_queue
    ADD COLUMN years_source ENUM('stats', 'results', 'manual') DEFAULT 'stats';
```

#### `analytics_snapshots` (uppdaterad)

```sql
ALTER TABLE analytics_snapshots
    ADD COLUMN data_fingerprint VARCHAR(64) NULL,
    ADD COLUMN tables_included JSON NULL;
```

#### `analytics_kpi_audit` (NY)

| Kolumn | Typ | Beskrivning |
|--------|-----|-------------|
| kpi_key | VARCHAR(50) | KPI-nyckel |
| action | ENUM | 'create', 'update', 'deprecate' |
| old_definition | TEXT | Tidigare definition |
| new_definition | TEXT | Ny definition |
| changed_by | VARCHAR(100) | Vem andrade |
| change_reason | TEXT | Varfor |

#### `analytics_system_config` (NY)

| Kolumn | Typ | Beskrivning |
|--------|-----|-------------|
| config_key | VARCHAR(100) | Konfigurationsnyckel |
| config_value | TEXT | Varde |
| config_type | ENUM | 'string', 'int', 'bool', 'json' |
| editable | TINYINT(1) | Om andringsbar via UI |

**Nyckelvarden:**
- `pdf_engine` = 'tcpdf'
- `pdf_fallback_allowed` = '0'
- `snapshot_required_for_export` = '1'
- `rate_limit_source` = 'database'

---

## Karnkomponenter

### 1. ExportLogger.php (v3.0.2)

**Fil:** `analytics/includes/ExportLogger.php`

**Andringar i v3.0.2:**
- DB-baserade rate limits via `loadRateLimitsFromDb()`
- Deterministisk fingerprint via `calculateDeterministicFingerprint()`
- Roll-baserad rate limit support
- Enhanced manifest med alla mandatory fields

```php
// Manifest innehaller (MANDATORY v3.0.2):
[
    'snapshot_id' => 1234,
    'generated_at' => '2026-01-16T14:30:00Z',
    'season_year' => 2025,
    'source_max_updated_at' => '2026-01-16T14:29:00',
    'platform_version' => '3.0.2',
    'calculation_version' => 'v3',
    'data_fingerprint' => 'sha256:abc123...',
    // ...
]
```

### 2. PdfExportBuilder.php (v3.0.2)

**Fil:** `analytics/includes/PdfExportBuilder.php`

**Andringar i v3.0.2:**
- `PdfEngineException` kastas om TCPDF saknas
- `isTcpdfAvailable()` kontrollerar tillganglighet
- `getPdfEngineStatus()` for diagnostik
- wkhtmltopdf-stod BORTTAGET

```php
// Kastar exception om TCPDF saknas
throw new PdfEngineException(
    'TCPDF is required for PDF export in v3.0.2. ' .
    'Install TCPDF: composer require tecnickcom/tcpdf'
);
```

### 3. IdentityResolver.php (v3.0.2)

**Fil:** `analytics/includes/IdentityResolver.php`

**Andringar i v3.0.2:**
- `findAffectedYearsWithSource()` returnerar source-info
- `queueRecalc()` sparar `years_source`
- Deterministisk checksum (sorterade rider IDs)

```php
// Returnerar bade ar och kalla
$result = $resolver->findAffectedYearsWithSource([123, 456]);
// ['years' => [2024, 2025], 'source' => 'stats']
```

### 4. admin/analytics-export-center.php (v3.0.2)

**Fil:** `admin/analytics-export-center.php`

**Andringar i v3.0.2:**
- Visar PDF ENGINE STATUS (OK/MISSING CRITICAL)
- Blockerar PDF-knappar om TCPDF saknas
- Visar Rate Limit Source (database/config)

---

## Datakvalitet

Se `KPICalculator::getDataQualityMetrics()` for matningar av:
- Birth year coverage
- Club coverage
- Gender coverage
- Class coverage
- Potential duplicates
- Merged riders

---

## Drift och Cron

### Heartbeat-logik

```php
foreach ($riders as $i => $rider) {
    if ($i % 100 === 0) {
        $engine->heartbeat();
    }
}
```

### Cron-konfiguration

Tabell `analytics_cron_config` innehaller:
- `timeout_seconds`: Max kortid
- `heartbeat_interval_seconds`: Heartbeat-intervall
- `retry_on_failure`: Auto-retry
- `max_retries`: Max forsok

---

## Sakerhet och GDPR

### PII-identifiering

`ExportLogger::containsPII()` kontrollerar: firstname, lastname, email, phone, birth_year, address, license_number

### Rate Limiting

Nu DB-baserat med scope-stod (global/user/ip/role).

---

## Analytics-sidor

### Export Center (`admin/analytics-export-center.php`)

**Funktioner (v3.0.2):**
- **PDF ENGINE STATUS**: OK/MISSING (CRITICAL)
- **Rate Limit Source**: database/config
- PDF-knappar blockerade om TCPDF saknas

---

## API-endpoints

| Endpoint | Metod | Beskrivning |
|----------|-------|-------------|
| `/api/analytics/export.php` | POST | Generera CSV-export |
| `/api/analytics/create-snapshot.php` | POST | Skapa ny snapshot |
| `/api/analytics/get-manifest.php` | GET | Hamta manifest for export |

---

## Setup och Underhall

### Initial Setup (v3.0.2)

```bash
# 1. Kor migrationer
mysql -u user -p database < analytics/migrations/007_production_ready.sql
mysql -u user -p database < analytics/migrations/008_revision_grade_fixes.sql

# 2. Installera TCPDF
composer require tecnickcom/tcpdf

# 3. Verifiera
php -r "
require 'analytics/includes/PdfExportBuilder.php';
var_dump(PdfExportBuilder::getPdfEngineStatus());
"
```

---

## Changelog

### v3.0.2 (2026-01-16) - Fully Revision-Safe

**Nya filer:**
- `analytics/migrations/008_revision_grade_fixes.sql` - Revision grade korrigeringar

**Uppdaterade filer:**
- `analytics/includes/ExportLogger.php`
  - DB-baserade rate limits
  - Deterministisk fingerprint (`calculateDeterministicFingerprint()`)
  - Enhanced manifest med mandatory fields
  - Roll-baserad rate limit support

- `analytics/includes/PdfExportBuilder.php`
  - TCPDF OBLIGATORISK
  - `PdfEngineException` om TCPDF saknas
  - `getPdfEngineStatus()` metod
  - wkhtmltopdf BORTTAGET

- `analytics/includes/IdentityResolver.php`
  - `findAffectedYearsWithSource()` med prioritet stats→results→manual
  - Deterministisk checksum (sorterade rider IDs)
  - `years_source` sparas i recalc_queue

- `admin/analytics-export-center.php`
  - PDF ENGINE STATUS visning
  - PDF-knappar blockerade om TCPDF saknas
  - Rate Limit Source visning

**Databas-andringar:**
- Nya tabeller: `analytics_kpi_audit`, `analytics_system_config`, `v_export_validation`
- Uppdaterade tabeller: `export_rate_limits` (ny struktur med scope), `analytics_recalc_queue` (+years_source), `analytics_snapshots` (+data_fingerprint, +tables_included)
- KPI-definitioner: `active_rider` korrigerad till EVENT-baserad, `at_risk_rider`/`winback_candidate` markerade som HEURISTIC

### v3.0.1 (2026-01-16) - 100% Production Ready

- Initial production readiness
- Mandatory snapshot_id
- Rate limiting (hardkodat)
- PdfExportBuilder med wkhtmltopdf
- Recalc queue

### v3.0.0 (2026-01-16)

- Initial implementation

---

## Revision Audit Trail

### KPI-definitionsandringar (loggade i analytics_kpi_audit)

| Datum | KPI | Andring | Anledning |
|-------|-----|---------|-----------|
| 2026-01-16 | `active_rider` | Formel: `COUNT(results) >= 1` → `COUNT(DISTINCT event_id) >= 1` | Korrigerat till EVENT-baserad definition for revision-grade compliance |
| 2026-01-16 | `at_risk_rider` | Markerad som HEURISTIC | Tydliggora att det ar en prediktiv indikator |
| 2026-01-16 | `winback_candidate` | Markerad som HEURISTIC | Tydliggora att det ar en prediktiv indikator |

### Verifieringsquery

```sql
-- Kontrollera att alla exporter har snapshot_id
SELECT COUNT(*) as orphan_exports FROM analytics_exports WHERE snapshot_id IS NULL;
-- Forvantad: 0

-- Kontrollera KPI-definitioner
SELECT kpi_key, definition_sv, formula FROM analytics_kpi_definitions
WHERE kpi_key IN ('active_rider', 'at_risk_rider', 'winback_candidate') AND calculation_version = 'v3';

-- Kontrollera PDF-policy
SELECT config_key, config_value FROM analytics_system_config WHERE config_key LIKE 'pdf%';
-- Forvantad: pdf_engine='tcpdf', pdf_fallback_allowed='0'
```

---

*Dokumentation skapad: 2026-01-16*
*Analytics Platform Version: 3.0.2 (Fully Revision-Safe)*
*Calculation Version: v3*
*Implementation Status: KOMPLETT*
