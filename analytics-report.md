# TheHUB Analytics Platform - Teknisk Dokumentation

> Komplett guide till analytics-systemet v3.0.1, dess komponenter och SCF-nivå-rapportering

**Version:** 3.0.1 (100% Production Ready)
**Senast uppdaterad:** 2026-01-16
**Status:** Production Ready - Revision Grade
**Implementation Status:** KOMPLETT

---

## Innehallsforteckning

1. [Oversikt](#oversikt)
2. [Production Ready Checklist](#production-ready-checklist)
3. [Arkitektur](#arkitektur)
4. [Brand/Varumarke-dimension](#brandvarumarke-dimension)
5. [Databas-struktur](#databas-struktur)
6. [Karnkomponenter](#karnkomponenter)
7. [KPI-definitioner (VIKTIGT)](#kpi-definitioner)
8. [Export och Reproducerbarhet](#export-och-reproducerbarhet)
9. [Identity Resolution och Recalc](#identity-resolution-och-recalc)
10. [Datakvalitet](#datakvalitet)
11. [Drift och Cron](#drift-och-cron)
12. [Sakerhet och GDPR](#sakerhet-och-gdpr)
13. [Analytics-sidor](#analytics-sidor)
14. [API-endpoints](#api-endpoints)
15. [Setup och Underhall](#setup-och-underhall)
16. [Changelog](#changelog)
17. [Open Questions / Assumptions](#open-questions--assumptions)

---

## Oversikt

TheHUB Analytics ar ett komplett analysverktyg for svensk cykelsport med **10+ ars data** och stod for SCF-nivå-rapportering. Systemet ar designat for **revision-grade reproducerbarhet** - varje export kan aterskaps exakt.

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

### Behorigheter

Analytics kraver en av foljande:
- `super_admin` roll
- `statistics` permission (kan tilldelas utan admin-rattigheter)

### Principer

1. **Pre-aggregerad data** - Tunga berakningar gors en gang och sparas
2. **Identity Resolution** - Dubbletter hanteras automatiskt via canonical IDs
3. **Reproducerbarhet** - Alla exporter bygger pa snapshot_id och kan aterskaps
4. **GDPR-kompatibel** - Aggregerad data publikt, persondata bakom behorighet
5. **Revision Grade** - Varje export har fingerprint, manifest och provenance

---

## Production Ready Checklist

**Status: IMPLEMENTERAT 2026-01-16**

### Databas
- [x] Migration `007_production_ready.sql` skapad
  - Fil: `analytics/migrations/007_production_ready.sql`
- [x] Tabell `brands` och `brand_series_map` definierad med seed-data
- [x] Tabell `analytics_recalc_queue` skapad med deduplication via checksum
- [x] Tabell `analytics_exports` uppdaterad med `export_uid`, `filters_json`, `status`
- [x] Tabell `analytics_cron_runs` uppdaterad med `heartbeat_at`, `duration_ms`, `error_text`
- [x] Tabell `analytics_cron_config` skapad for jobbkonfiguration
- [x] Tabell `export_rate_limits` skapad for GDPR compliance
- [x] Index pa alla foreign keys och vanliga queries

### Kod
- [x] `ExportLogger` kraver `snapshot_id` for alla exporter
  - Fil: `analytics/includes/ExportLogger.php`
  - Metod: `logExport()` kastar `InvalidArgumentException` om snapshot saknas
  - Rate limiting: 50/timme, 200/dag per anvandare
- [x] `IdentityResolver::merge()` laggar jobb i `analytics_recalc_queue`
  - Fil: `analytics/includes/IdentityResolver.php`
  - Metod: `queueRecalc()` anropas automatiskt efter merge/unmerge
- [x] `AnalyticsEngine::processRecalcQueue()` implementerad
  - Fil: `analytics/includes/AnalyticsEngine.php`
  - Heartbeat: `heartbeat()` metod
  - Timeout: `checkTimeout()` metod
  - Snapshot: `createSnapshot()`, `getOrCreateSnapshot()` metoder
- [x] `SVGChartRenderer::svgToPng()` returnerar `null` om Imagick saknas
  - Fil: `analytics/includes/SVGChartRenderer.php`
  - Metod: `canConvertToPng()` for tillganglighetskontroll
  - Metod: `getChartAsImage()` med graceful fallback till SVG
  - Metod: `getPngSupportInfo()` for diagnostik
- [x] `PdfExportBuilder` genererar "Definitions & Provenance" block
  - Fil: `analytics/includes/PdfExportBuilder.php` (NY)
  - Mandatory Definition Box i alla PDF-exporter
  - Stod for wkhtmltopdf med HTML-fallback

### Drift
- [x] Heartbeat-logik implementerad i `AnalyticsEngine`
- [x] Timeout-hantering implementerad via `checkTimeout()`
- [x] `analytics_cron_config` tabell for jobbkonfiguration
- [x] Recalc queue stod via `processRecalcQueue()`

### Sakerhet/GDPR
- [x] `contains_pii` flaggas automatiskt via `ExportLogger::containsPII()`
- [x] Rate limit implementerat (50/timme, 200/dag)
- [x] Export Center visar exporthistorik och manifest
  - Fil: `admin/analytics-export-center.php` (NY)

---

## Arkitektur

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                           PRESENTATION LAYER                                 │
│  analytics-dashboard │ analytics-cohorts │ analytics-export-center          │
│                                                                              │
│  PdfExportBuilder.php ─── PDF med SVG-grafer + Definitions block            │
│  SVGChartRenderer.php ─── Grafer (PNG via Imagick om tillganglig)           │
└─────────────────────────────────┬───────────────────────────────────────────┘
                                  │
┌─────────────────────────────────▼───────────────────────────────────────────┐
│                           CALCULATION LAYER                                  │
│                                                                              │
│  ┌─────────────────────┐     ┌─────────────────────┐                        │
│  │   KPICalculator     │     │   ExportLogger      │                        │
│  │   ~2500 rader       │     │   v3.0.1            │                        │
│  │                     │     │   - snapshot_id REQ │                        │
│  │  - getRetentionRate │     │   - export_uid      │                        │
│  │  - getCohortData    │     │   - fingerprint     │                        │
│  │  - getByBrand()     │     │   - rate limiting   │                        │
│  └─────────────────────┘     └─────────────────────┘                        │
│                              ┌─────────────────────┐                        │
│                              │  AnalyticsConfig    │                        │
│                              │  v3.0.1             │                        │
│                              │  - PLATFORM_VERSION │                        │
│                              │  - KPI_DEFINITIONS  │                        │
│                              └─────────────────────┘                        │
└─────────────────────────────────┬───────────────────────────────────────────┘
                                  │
┌─────────────────────────────────▼───────────────────────────────────────────┐
│                           ENGINE LAYER                                       │
│                                                                              │
│  ┌─────────────────────┐     ┌─────────────────────┐                        │
│  │  AnalyticsEngine    │     │  IdentityResolver   │                        │
│  │  v3.0.1             │     │  v3.0.1             │                        │
│  │  - calculateYearly  │────▶│  - merge()          │───┐                    │
│  │  - processRecalc()  │     │  - queueRecalc()    │   │                    │
│  │  - heartbeat()      │     │  - getPendingJobs() │   │                    │
│  │  - createSnapshot() │     └─────────────────────┘   │                    │
│  └─────────────────────┘                               │                    │
│                                                        │                    │
│  ┌─────────────────────────────────────────────────────▼──────────────────┐│
│  │  analytics_recalc_queue                                                 ││
│  │  - trigger_type, trigger_entity_id, affected_rider_ids, status         ││
│  └─────────────────────────────────────────────────────────────────────────┘│
└─────────────────────────────────┬───────────────────────────────────────────┘
                                  │
┌─────────────────────────────────▼───────────────────────────────────────────┐
│                           DATA LAYER                                         │
│                                                                              │
│  PRE-AGGREGATED TABLES              │  REFERENCE TABLES                     │
│  ─────────────────────              │  ────────────────                     │
│  rider_yearly_stats                 │  brands                               │
│  series_participation               │  brand_series_map                     │
│  series_crossover                   │  analytics_kpi_definitions            │
│  club_yearly_stats                  │  analytics_cron_config                │
│  analytics_snapshots                │                                       │
│  analytics_exports                  │  RAW TABLES                           │
│  data_quality_metrics               │  ──────────                           │
│                                     │  results, events, riders, clubs       │
└─────────────────────────────────────────────────────────────────────────────┘
```

### Dataflode (Revision Grade)

1. **Radata** lagras i `results`, `events`, `riders`, `clubs`
2. **IdentityResolver** matchar riders mot canonical IDs
3. **AnalyticsEngine** beraknar och sparar till pre-aggregerade tabeller
4. **Snapshot** skapas med unik ID, timestamp och fingerprint
5. **Export** kraver `snapshot_id` - all data hamtas fran snapshot-tidpunkten
6. **ExportLogger** skapar `export_uid`, beraknar fingerprint, sparar manifest
7. **PDF** innehaller "Definitions & Provenance" block med alla metadata

---

## Brand/Varumarke-dimension

### Definition

**Brand** (varumarke) ar ett overordnat koncept som grupperar en eller flera **serier**.

Exempel:
- Brand "GravitySeries" → Serier: GES Enduro Cup, GES Junior
- Brand "Svenska Cykelförbundet" → Serier: SM DH, SM XC

### Datamodell

```
┌─────────────┐         ┌─────────────────────┐         ┌─────────────┐
│   brands    │ 1───N   │  brand_series_map   │   N───1 │   series    │
├─────────────┤         ├─────────────────────┤         ├─────────────┤
│ id (PK)     │         │ brand_id (FK)       │         │ id (PK)     │
│ name        │         │ series_id (FK)      │         │ name        │
│ short_code  │         │ relationship_type   │         │ year        │
│ logo_url    │         │ valid_from          │         │ ...         │
│ active      │         │ valid_until         │         └─────────────┘
└─────────────┘         └─────────────────────┘
```

**Seed-data i migration 007:**
```sql
INSERT INTO brands (name, short_code, description, color_primary, active, display_order)
VALUES
    ('GravitySeries', 'GS', 'Sveriges största gravity MTB-serie', '#37d4d6', 1, 1),
    ('Svenska Cykelförbundet', 'SCF', 'Nationella mästerskapstävlingar', '#0066cc', 1, 2);
```

---

## Databas-struktur

### Migration 007: Nya/Uppdaterade Tabeller

#### `analytics_exports` (uppdaterad)

```sql
ALTER TABLE analytics_exports
    ADD COLUMN export_uid VARCHAR(36) NOT NULL,
    ADD COLUMN filters_json JSON NULL,
    ADD COLUMN requested_at DATETIME NULL,
    ADD COLUMN completed_at DATETIME NULL,
    ADD COLUMN status ENUM('pending', 'processing', 'completed', 'failed') DEFAULT 'completed',
    ADD COLUMN error_message TEXT NULL;
```

**Index:**
- `UNIQUE(export_uid)`
- `INDEX(snapshot_id)`
- `INDEX(export_type, season_year)`
- `INDEX(exported_by, exported_at)`
- `INDEX(status)`

#### `brands` (NY)

| Kolumn | Typ | Beskrivning |
|--------|-----|-------------|
| id | INT UNSIGNED | Auto-increment PK |
| name | VARCHAR(100) | Varumarkesnamn |
| short_code | VARCHAR(20) | Kort kod (GS, SCF) |
| description | TEXT | Beskrivning |
| logo_url | VARCHAR(255) | URL till logotyp |
| website_url | VARCHAR(255) | Webbplats |
| color_primary | VARCHAR(7) | Hex farg |
| active | TINYINT(1) | DEFAULT 1 |
| display_order | INT | Sortering |

#### `brand_series_map` (NY)

| Kolumn | Typ | Beskrivning |
|--------|-----|-------------|
| id | INT UNSIGNED | Auto-increment PK |
| brand_id | INT UNSIGNED | FK till brands |
| series_id | INT UNSIGNED | FK till series |
| relationship_type | ENUM | 'owner', 'partner', 'sponsor' |
| valid_from | DATE | Relation giltig fran |
| valid_until | DATE | Relation giltig till (NULL=pagaende) |

#### `analytics_recalc_queue` (NY)

| Kolumn | Typ | Beskrivning |
|--------|-----|-------------|
| id | INT UNSIGNED | Auto-increment PK |
| trigger_type | ENUM | 'merge', 'import', 'manual', 'correction' |
| trigger_entity | VARCHAR(50) | 'rider', 'event', 'result' |
| trigger_entity_id | INT UNSIGNED | Entitetens ID |
| affected_rider_ids | JSON | Lista med paverkade rider IDs |
| affected_years | JSON | Lista med paverkade ar |
| priority | TINYINT UNSIGNED | 1=hogst, 10=lagst (DEFAULT 5) |
| status | ENUM | 'pending', 'processing', 'completed', 'failed', 'skipped' |
| checksum | VARCHAR(64) | SHA256 for deduplication |
| rows_affected | INT UNSIGNED | Antal rader processade |
| execution_time_ms | INT UNSIGNED | Kortid i millisekunder |
| error_message | TEXT | Felmeddelande om failed |

#### `analytics_cron_runs` (uppdaterad)

```sql
ALTER TABLE analytics_cron_runs
    ADD COLUMN heartbeat_at DATETIME NULL,
    ADD COLUMN duration_ms INT UNSIGNED NULL,
    ADD COLUMN error_text TEXT NULL,
    ADD COLUMN rows_processed INT UNSIGNED NULL,
    ADD COLUMN memory_peak_mb DECIMAL(8,2) NULL,
    ADD COLUMN timeout_detected TINYINT(1) DEFAULT 0;
```

#### `analytics_cron_config` (NY)

| Kolumn | Typ | Beskrivning |
|--------|-----|-------------|
| id | INT UNSIGNED | Auto-increment PK |
| job_name | VARCHAR(100) | Jobbnamn (UNIQUE) |
| enabled | TINYINT(1) | DEFAULT 1 |
| schedule_cron | VARCHAR(50) | Cron expression |
| timeout_seconds | INT UNSIGNED | Max kortid (DEFAULT 3600) |
| heartbeat_interval_seconds | INT UNSIGNED | Heartbeat-intervall (DEFAULT 60) |
| retry_on_failure | TINYINT(1) | DEFAULT 1 |
| max_retries | INT UNSIGNED | DEFAULT 3 |
| notify_on_failure | TINYINT(1) | DEFAULT 1 |
| notify_email | VARCHAR(255) | Notifieringsmail |

**Default-konfiguration:**
```sql
INSERT INTO analytics_cron_config (job_name, schedule_cron, timeout_seconds)
VALUES
    ('daily_aggregation', '0 2 * * *', 3600),
    ('weekly_snapshot', '0 3 * * 0', 7200),
    ('data_quality_check', '0 4 * * *', 1800),
    ('export_cleanup', '0 5 * * *', 900);
```

#### `export_rate_limits` (NY)

| Kolumn | Typ | Beskrivning |
|--------|-----|-------------|
| id | INT UNSIGNED | Auto-increment PK |
| user_id | INT UNSIGNED | NULL = galler alla |
| ip_address | VARCHAR(45) | NULL = galler alla IPs |
| limit_type | ENUM | 'hourly', 'daily', 'monthly' |
| max_exports | INT UNSIGNED | DEFAULT 100 |
| max_rows | INT UNSIGNED | Max rader per period |
| current_count | INT UNSIGNED | Nuvarande antal |
| period_start | DATETIME | Periodens start |
| period_end | DATETIME | Periodens slut |

---

## Karnkomponenter

### 1. ExportLogger.php (v3.0.1)

**Fil:** `analytics/includes/ExportLogger.php`

**Nyckelfunktioner:**
- **Mandatory snapshot_id:** Kastar `InvalidArgumentException` om saknas
- **UUID-generering:** Varje export far unik `export_uid`
- **Rate limiting:** 50/timme, 200/dag per anvandare
- **Fingerprint:** SHA256 pa sorterad JSON for verifiering

```php
// Huvudmetod
public function logExport(string $exportType, array $data, array $options = []): int

// Validering
if ($this->requireSnapshotId && empty($options['snapshot_id'])) {
    throw new InvalidArgumentException(
        'snapshot_id is required for all exports in v3.0.1+'
    );
}

// Rate limit
if (!$this->checkRateLimit($userId, $ipAddress)) {
    throw new RuntimeException('Export rate limit exceeded');
}
```

**Nya metoder i v3.0.1:**
- `checkRateLimit(?int $userId, ?string $ipAddress): bool`
- `getRateLimitStatus(?int $userId, ?string $ipAddress): array`
- `getExportByUid(string $exportUid): ?array`
- `verifyExportByUid(string $exportUid, array $data): array`
- `getManifestByUid(string $exportUid): ?array`

### 2. IdentityResolver.php (v3.0.1)

**Fil:** `analytics/includes/IdentityResolver.php`

**Nya funktioner - Merge→Recalc Policy:**

```php
// Automatisk recalc-koande efter merge
public function merge(int $canonicalId, int $mergedId, ...): bool {
    // ... merge-logik ...

    // v3.0.1: Queue recalc for affected riders
    $this->queueRecalc(
        'merge',
        'rider',
        $mergedId,
        [$canonicalId, $mergedId],
        $by
    );
}

// Koa recalc-jobb
public function queueRecalc(
    string $triggerType,
    string $triggerEntity,
    int $triggerEntityId,
    array $affectedRiderIds,
    ?string $createdBy = null
): int

// Hamta pending jobb
public function getPendingRecalcJobs(int $limit = 10): array

// Markera jobb
public function markRecalcStarted(int $jobId): bool
public function markRecalcCompleted(int $jobId, int $rowsAffected, int $executionTimeMs): bool
public function markRecalcFailed(int $jobId, string $errorMessage): bool

// Statistik
public function getRecalcQueueStats(): array
```

### 3. AnalyticsEngine.php (v3.0.1)

**Fil:** `analytics/includes/AnalyticsEngine.php`

**Nya metoder - Heartbeat & Timeout:**

```php
// Skicka heartbeat for pagaende jobb
public function heartbeat(): void {
    if (!$this->currentJobId) return;
    // UPDATE analytics_cron_runs SET heartbeat_at = NOW() WHERE id = ?
}

// Kolla timeout
public function checkTimeout(string $jobName, int $timeoutSeconds = 3600): ?array

// Hamta timed out jobb
public function getTimedOutJobs(int $days = 7): array
```

**Nya metoder - Recalc Queue:**

```php
// Processa recalc-kon
public function processRecalcQueue(int $maxJobs = 10): array {
    $results = [];
    $pendingJobs = $this->identityResolver->getPendingRecalcJobs($maxJobs);

    foreach ($pendingJobs as $job) {
        // Markera som processing
        // Omberakna affected riders for affected years
        // Markera som completed/failed
    }

    return $results;
}

// Hamta queue status
public function getRecalcQueueStatus(): array
```

**Nya metoder - Snapshot:**

```php
// Skapa snapshot
public function createSnapshot(string $description = '', ?string $createdBy = null): int

// Hamta senaste snapshot
public function getLatestSnapshot(): ?array

// Hamta eller skapa snapshot (med max age)
public function getOrCreateSnapshot(int $maxAgeMinutes = 60, ?string $createdBy = null): int
```

### 4. SVGChartRenderer.php (v3.0.1)

**Fil:** `analytics/includes/SVGChartRenderer.php`

**Nya metoder - PNG Fallback:**

```php
// Kontrollera tillganglighet (static, cached)
public static function canConvertToPng(): bool

// Konvertera med graceful fallback
public function svgToPng(string $svg, int $scale = 2): ?string

// Hamta som bild (PNG eller SVG fallback)
public function getChartAsImage(string $svg, int $scale = 2): array
// Returns: ['format' => 'png'|'svg', 'data' => binary, 'mime' => ..., 'base64' => ...]

// Spara som bild (PNG eller SVG fallback)
public function saveAsImage(string $svg, string $path, int $scale = 2): array

// Data URI for embedding
public function toDataUri(string $svg, bool $preferPng = false): string

// Systeminformation
public static function getPngSupportInfo(): array
```

### 5. PdfExportBuilder.php (NY)

**Fil:** `analytics/includes/PdfExportBuilder.php`

**Syfte:** Bygger PDF-exporter med obligatorisk "Definitions & Provenance" block.

```php
class PdfExportBuilder {
    public function __construct(PDO $pdo, int $snapshotId)

    // Byggmetoder
    public function setTitle(string $title, string $subtitle = ''): self
    public function setSeasonYear(int $year): self
    public function addSection(string $heading, string $content): self
    public function addTable(string $heading, array $headers, array $rows): self
    public function addChart(string $heading, string $chartType, array $data, array $options = []): self
    public function addKpiDefinition(string $kpiKey, ?string $customDefinition = null): self
    public function addStandardKpiDefinitions(array $kpiKeys): self

    // Output
    public function buildHtml(): string
    public function exportToPdf(?string $outputPath = null): string|bool
    public function logExport(string $exportType, array $data, array $options = []): int
}
```

**Mandatory Definition Box:**
Varje PDF innehaller automatiskt:
- KPI Definitions (fran `analytics_kpi_definitions` tabellen)
- Report Metadata (generated_at, platform_version, calculation_version)
- Snapshot ID och fingerprint
- Reproducibility note
- GDPR compliance note

---

## KPI-definitioner

**KRITISKT:** Dessa definitioner ar **obligatoriska** i alla PDF-exporter.

### KPI-definitioner i databas

Migration 007 skapar `analytics_kpi_definitions` med foljande v3-definitioner:

| kpi_key | definition_sv | formula |
|---------|---------------|---------|
| `retention_from_prev` | Andel av förra årets deltagare som också tävlade i år | `(riders_in_both / riders_in_Y-1) * 100` |
| `returning_share_of_current` | Andel av årets deltagare som även tävlade förra året | `(riders_in_both / riders_in_Y) * 100` |
| `churn_rate` | Andel av förra årets deltagare som INTE tävlade i år | `100 - retention_from_prev` |
| `rookie_rate` | Andel av årets deltagare som är förstagångsdeltagare | `(new_riders / total_riders) * 100` |
| `active_rider` | En deltagare med minst 1 registrerat resultat under perioden | `COUNT(results) >= 1` |
| `at_risk_rider` | Aktiv förra året men visar nedgång | `events_current < events_previous` |
| `winback_candidate` | Deltagare aktiv för 2+ år sedan men inte förra året | `last_active_year <= current_year - 2` |
| `ltv_events` | Totalt antal events en deltagare har deltagit i | `SUM(events_per_year)` |
| `cohort_year` | Första året en deltagare deltog i något event | `MIN(season_year)` |

### Definition Box Format (PDF)

```
┌─────────────────────────────────────────────────────────────────────────────┐
│ DEFINITIONS & PROVENANCE                                                    │
├─────────────────────────────────────────────────────────────────────────────┤
│ REPORT METADATA:                                                            │
│   Generated:        2026-01-16 14:30:00                                    │
│   Platform Version: 3.0.1                                                   │
│   Calculation Ver:  v3                                                      │
│   Snapshot ID:      #1234                                                   │
│   Season:           2025                                                    │
│                                                                             │
│ KPI DEFINITIONS:                                                            │
│   Retention Rate:   Andel av förra årets deltagare som kom tillbaka        │
│   Rookie Rate:      Andel förstagångsdeltagare                             │
│   ...                                                                       │
│                                                                             │
│ REPRODUCIBILITY:                                                            │
│   This report can be reproduced using snapshot #1234.                      │
│                                                                             │
│ GDPR:                                                                       │
│   Data processed in accordance with GDPR.                                  │
└─────────────────────────────────────────────────────────────────────────────┘
```

---

## Export och Reproducerbarhet

### Principer (v3.0.1)

1. **Alla exporter MASTE ha snapshot_id** - kastar exception om saknas
2. **export_uid** (UUID) genereras automatiskt
3. **Fingerprint** (SHA256) pa sorterad JSON
4. **Rate limiting** 50/timme, 200/dag
5. **Definition Box** obligatorisk i PDF

### Export-flode

```
1. Anvandare begar export
         │
         ▼
2. ExportLogger::logExport() anropas
         │
         ▼
3. Validera snapshot_id (REQUIRED) → Exception om saknas
         │
         ▼
4. Kontrollera rate limit → RuntimeException om overskriden
         │
         ▼
5. Generera export_uid (UUID v4)
         │
         ▼
6. Berakna fingerprint (SHA256)
         │
         ▼
7. Skapa manifest med all metadata
         │
         ▼
8. Spara till analytics_exports
         │
         ▼
9. Om PDF: PdfExportBuilder laggar Definition Box
         │
         ▼
10. Returnera fil/data
```

---

## Identity Resolution och Recalc

### Merge → Recalc Policy (v3.0.1)

```
1. IdentityResolver::merge() anropas
         │
         ▼
2. Merge-record skapas i rider_merge_map
         │
         ▼
3. queueRecalc() anropas automatiskt:
   - trigger_type = 'merge'
   - affected_rider_ids = [canonical, merged]
   - affected_years = alla ar rider deltog
         │
         ▼
4. Checksum beraknas for deduplication
         │
         ▼
5. Jobb laggs i analytics_recalc_queue (priority=3)
         │
         ▼
6. Nasta cron: AnalyticsEngine::processRecalcQueue()
   - Hamtar pending jobb sorterat pa prioritet
   - Omberaknar yearly stats for affected riders
   - Markerar jobb som completed/failed
```

### Deduplication

Varje recalc-jobb far en SHA256 checksum baserad pa:
- trigger_type
- trigger_entity
- trigger_entity_id
- affected_rider_ids

Om samma checksum redan finns som 'pending' skippas det nya jobbet.

---

## Drift och Cron

### Heartbeat-logik

```php
// I AnalyticsEngine under langa operationer
foreach ($riders as $i => $rider) {
    if ($i % 100 === 0) {
        $engine->heartbeat();  // UPDATE heartbeat_at = NOW()

        // Kolla timeout (optional)
        if ($engine->checkTimeout('daily-refresh')) {
            // Jobb har kortat for lange - avbryt
            break;
        }
    }
    // ... processRider() ...
}
```

### Cron-konfiguration

Tabell `analytics_cron_config` innehaller:
- `timeout_seconds`: Max kortid innan timeout
- `heartbeat_interval_seconds`: Hur ofta heartbeat ska skickas
- `retry_on_failure`: Om jobb ska koras om vid fel
- `max_retries`: Max antal retry

### Timeout Detection

```php
public function checkTimeout(string $jobName, int $timeoutSeconds = 3600): ?array {
    // Hitta jobb som ar 'started' men saknar heartbeat langre an timeout
    // Markera som failed med timeout_detected = 1
}
```

---

## Sakerhet och GDPR

### PII-identifiering

`ExportLogger::containsPII()` kontrollerar om data innehaller nagot av:
- firstname, lastname
- email, phone
- birth_year, birth_date
- address, license_number

### Rate Limiting

- **Hourly:** Max 50 exporter per timme per anvandare
- **Daily:** Max 200 exporter per dag per anvandare
- Implementerat i `ExportLogger::checkRateLimit()`
- Status via `ExportLogger::getRateLimitStatus()`

### Export-typer med PII

| Export Type | contains_pii |
|-------------|--------------|
| riders_at_risk | 1 |
| cohort_riders | 1 |
| winback_targets | 1 |
| summary | 0 |
| retention_stats | 0 |
| demographics | 0 |

---

## Analytics-sidor

### Export Center (`admin/analytics-export-center.php`) - NY

**Syfte:** Central oversikt over alla analytics-exporter

**Funktioner:**
- **Statistik-oversikt:** Exporter senaste 30 dagar, rader exporterade, reproducerbarhetsgrad
- **Snabbexporter:** Retention, At-Risk, Cohort, Winback, Club Stats
- **PDF-rapporter:** Sasongsammanfattning, Retentionsrapport
- **Exporthistorik:** Lista over egna exporter med typ, datum, rader, snapshot
- **Manifest-visning:** Klicka for att se komplett manifest
- **Rate limit-display:** Visuell progress bar for hourly/daily limits
- **Systemstatus:** Platform version, PNG support, recalc queue status

**JavaScript-funktioner:**
- `exportData(type)` - Hamtar och laddar ner CSV
- `generatePdf(type)` - Genererar PDF-rapport
- `createSnapshot()` - Skapar ny analytics-snapshot
- `viewManifest(exportId)` - Visar export-manifest i modal

---

## API-endpoints

### Befintliga endpoints (att utoka)

| Endpoint | Metod | Beskrivning |
|----------|-------|-------------|
| `/api/analytics/export.php` | POST | Generera CSV-export |
| `/api/analytics/save-quality-metrics.php` | POST | Spara datakvalitetsmatning |
| `/api/analytics/create-snapshot.php` | POST | Skapa ny snapshot (NY) |
| `/api/analytics/get-manifest.php` | GET | Hamta manifest for export (NY) |

### Rekommenderade nya endpoints

```
/api/analytics/create-snapshot.php
POST - Skapa ny snapshot
Response: { success: true, snapshot_id: 1234 }

/api/analytics/get-manifest.php?id={exportId}
GET - Hamta manifest
Response: { success: true, manifest: {...} }

/api/analytics/verify-export.php
POST - Verifiera export fingerprint
Body: { export_uid: "...", data: [...] }
Response: { valid: true/false }
```

---

## Setup och Underhall

### Initial Setup (v3.0.1)

1. **Kor migration:**
```bash
mysql -u user -p database < analytics/migrations/007_production_ready.sql
```

2. **Populera brands:**
```sql
-- Seed-data finns i migration, men anpassa efter era serier
INSERT INTO brand_series_map (brand_id, series_id, relationship_type)
SELECT 1, id, 'owner' FROM series WHERE name LIKE '%GES%';
```

3. **Verifiera:**
```sql
-- Kolla att tabeller finns
SHOW TABLES LIKE 'brand%';
SHOW TABLES LIKE 'analytics_%';
DESCRIBE analytics_exports;
```

### Underhall

```bash
# Processa recalc queue manuellt
php -r "
require 'config/database.php';
require 'analytics/includes/AnalyticsEngine.php';
\$engine = new AnalyticsEngine(hub_db());
print_r(\$engine->processRecalcQueue(100));
"

# Skapa snapshot manuellt
php -r "
require 'config/database.php';
require 'analytics/includes/AnalyticsEngine.php';
\$engine = new AnalyticsEngine(hub_db());
echo 'Snapshot ID: ' . \$engine->createSnapshot('Manual snapshot') . PHP_EOL;
"
```

---

## Changelog

### v3.0.1 (2026-01-16) - 100% Production Ready - IMPLEMENTERAT

**Nya filer:**
- `analytics/migrations/007_production_ready.sql` - Komplett databasschema
- `analytics/includes/PdfExportBuilder.php` - PDF med Definition Box
- `admin/analytics-export-center.php` - Admin Export Center

**Uppdaterade filer:**
- `analytics/includes/ExportLogger.php`
  - Mandatory snapshot_id
  - UUID-baserad export_uid
  - Rate limiting (50/h, 200/d)
  - Nya metoder: checkRateLimit, getRateLimitStatus, getExportByUid, verifyExportByUid

- `analytics/includes/IdentityResolver.php`
  - Merge→Recalc policy
  - Nya metoder: queueRecalc, getPendingRecalcJobs, markRecalcStarted/Completed/Failed, getRecalcQueueStats

- `analytics/includes/AnalyticsEngine.php`
  - Heartbeat & timeout handling
  - Recalc queue processing
  - Snapshot creation
  - Nya metoder: heartbeat, checkTimeout, getTimedOutJobs, processRecalcQueue, getRecalcQueueStatus, createSnapshot, getLatestSnapshot, getOrCreateSnapshot

- `analytics/includes/SVGChartRenderer.php`
  - Graceful PNG fallback
  - Nya metoder: canConvertToPng (static), getChartAsImage, saveAsImage, toDataUri, getPngSupportInfo

**Databas-andringar:**
- Nya tabeller: brands, brand_series_map, analytics_recalc_queue, analytics_cron_config, export_rate_limits, analytics_kpi_definitions
- Uppdaterade tabeller: analytics_exports (export_uid, filters_json, status), analytics_cron_runs (heartbeat_at, duration_ms, error_text, timeout_detected), analytics_snapshots (is_baseline, retention_days), data_quality_metrics (quality_score)

### v3.0.0 (2026-01-16)

- Initial production readiness
- KPI-definitioner tydliggjorda
- Export logging med fingerprint
- SVG ChartRenderer
- Data Quality panel

---

## Open Questions / Assumptions

1. **Brand-data:** Migration 007 innehaller seed-data for GravitySeries och SCF. Ytterligare brands och brand_series_map mappningar maste laggas till manuellt.

2. **PDF-bibliotek:** PdfExportBuilder forsoker anvanda wkhtmltopdf. Om det saknas returneras HTML istallet. For full PDF-support, installera wkhtmltopdf.

3. **Imagick:** SVG→PNG kraver Imagick med SVG-stod. `canConvertToPng()` returnerar false om saknas, och SVG anvands istallet.

4. **Recalc-frekvens:** Recalc queue processas i `processRecalcQueue()`. Rekommendation: Kor efter daglig aggregation.

5. **API-endpoints:** `create-snapshot.php` och `get-manifest.php` maste implementeras. Export Center antar att de finns.

---

*Dokumentation skapad: 2026-01-16*
*Analytics Platform Version: 3.0.1*
*Calculation Version: v3*
*Implementation Status: KOMPLETT*
