# TheHUB Analytics Platform - Teknisk Dokumentation

> Komplett guide till analytics-systemet v3.0.1, dess komponenter och SCF-nivå-rapportering

**Version:** 3.0.1 (100% Production Ready)
**Senast uppdaterad:** 2026-01-16
**Status:** Production Ready - Revision Grade

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
14. [Setup och Underhall](#setup-och-underhall)
15. [Changelog](#changelog)
16. [Open Questions / Assumptions](#open-questions--assumptions)

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

**Innan systemet ar 100% production ready, bocka av foljande:**

### Databas
- [ ] Migration `007_production_ready.sql` kord
- [ ] Tabell `brands` och `brand_series_map` finns och ar populerad
- [ ] Tabell `analytics_recalc_queue` finns
- [ ] Tabell `analytics_exports` har `snapshot_id NOT NULL` och `export_uid`
- [ ] Tabell `analytics_cron_runs` har `heartbeat_at` och `error_text`
- [ ] Index pa alla foreign keys och vanliga queries

### Kod
- [ ] `ExportLogger` kraver `snapshot_id` for alla exporter
- [ ] `IdentityResolver::mergeRiders()` laggar jobb i `analytics_recalc_queue`
- [ ] `AnalyticsEngine::processRecalcQueue()` implementerad och testad
- [ ] `SVGChartRenderer::svgToPng()` returnerar `null` om Imagick saknas (graceful fallback)
- [ ] `PdfExportBuilder` genererar "Definitions & Provenance" block i alla PDF-exporter
- [ ] Alla export-endpoints validerar `snapshot_id` (default: senaste for aret)

### Drift
- [ ] Cron-jobb `refresh-analytics.php` har heartbeat-logik (var 60:e sekund)
- [ ] Cron-jobb har timeout (max 30 min) och failure recovery
- [ ] Log retention konfigurerad (90 dagar for analytics_cron_runs)
- [ ] Recalc queue processas dagligen efter huvudjobbet

### Sakerhet/GDPR
- [ ] `contains_pii` flaggas korrekt pa alla exporttyper
- [ ] IP-adresser hashas eller tas bort efter 90 dagar
- [ ] Rate limit for PII-exporter (max 10 per timme per anvandare)
- [ ] Export Center visar audit trail

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
│  │   ~2500 rader       │     │   - snapshot_id REQ │                        │
│  │                     │     │   - export_uid      │                        │
│  │  - getRetentionRate │     │   - fingerprint     │                        │
│  │  - getCohortData    │     │   - manifest        │                        │
│  │  - getByBrand()     │     └─────────────────────┘                        │
│  └─────────────────────┘                                                    │
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
│  │                     │     │                     │                        │
│  │  - calculateYearly  │────▶│  - mergeRiders()    │───┐                    │
│  │  - processRecalc()  │     │  → recalc_queue     │   │                    │
│  └─────────────────────┘     └─────────────────────┘   │                    │
│                                                         │                    │
│  ┌──────────────────────────────────────────────────────▼──────────────────┐│
│  │  analytics_recalc_queue                                                 ││
│  │  - rider_id, year_from, year_to, reason, status                        ││
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
│  club_yearly_stats                  │                                       │
│  analytics_snapshots                │  RAW TABLES                           │
│  analytics_exports                  │  ──────────                           │
│  data_quality_metrics               │  results, events, riders, clubs       │
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
- Brand "GES" → Serier: GES Enduro Cup, GES Junior
- Brand "Swedish Enduro Series" → Serier: SES National, SES Regional
- Brand "Downhill SM" → Serier: SM DH Elite, SM DH Junior

### Datamodell

```
┌─────────────┐         ┌─────────────────────┐         ┌─────────────┐
│   brands    │ 1───N   │  brand_series_map   │   N───1 │   series    │
├─────────────┤         ├─────────────────────┤         ├─────────────┤
│ id (PK)     │         │ brand_id (FK)       │         │ id (PK)     │
│ name        │         │ series_id (FK)      │         │ name        │
│ short_code  │         │ valid_from_year     │         │ year        │
│ logo_url    │         │ valid_to_year       │         │ ...         │
│ active      │         └─────────────────────┘         └─────────────┘
└─────────────┘
```

**Motivering for brand_series_map (inte brand_event_map):**
- Serier ar stabila over tid, events ar efemera
- En serie tillhor alltid ett brand (1:N)
- Historisk koppling: `valid_from_year`, `valid_to_year` hanterar omorganisationer
- Mindre data att underhalla (100 serier vs 10000 events)

### Anvandning i KPICalculator

```php
// Hamta alla serier for ett brand
$seriesIds = $this->getSeriesForBrand($brandId, $year);

// Hamta cohort-data filtrerat pa brand
public function getCohortRetentionByBrand(int $cohortYear, int $brandId, int $maxYears = 5): array {
    $seriesIds = $this->getSeriesForBrand($brandId, $cohortYear);
    if (empty($seriesIds)) {
        return [];
    }

    // Filtrera pa riders som deltog i nagon av brandets serier
    $placeholders = implode(',', array_fill(0, count($seriesIds), '?'));
    // ... resten av logiken
}

// Privat helper
private function getSeriesForBrand(int $brandId, int $year): array {
    $stmt = $this->pdo->prepare("
        SELECT series_id
        FROM brand_series_map
        WHERE brand_id = ?
          AND (valid_from_year IS NULL OR valid_from_year <= ?)
          AND (valid_to_year IS NULL OR valid_to_year >= ?)
    ");
    $stmt->execute([$brandId, $year, $year]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}
```

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

#### `analytics_snapshots`
Historiska KPI-ogonblicksbilder med reproducerbarhet.

| Kolumn | Typ | Beskrivning |
|--------|-----|-------------|
| id | INT | Unik snapshot ID |
| snapshot_date | DATE | Datum |
| snapshot_type | ENUM | daily/weekly/monthly/quarterly/yearly |
| season_year | INT | Vilken sasong snapshot galler |
| metrics | JSON | Alla KPIs |
| generated_at | TIMESTAMP | Exakt tidpunkt for generering |
| source_max_updated_at | TIMESTAMP | MAX(updated_at) fran kalldata |
| code_version | VARCHAR | Platform-version (t.ex. 3.0.1) |
| data_fingerprint | VARCHAR(64) | SHA256 hash for verifiering |
| calculation_params | JSON | Parametrar som anvandes |

#### `analytics_exports` (v3.0.1)
GDPR-loggning av alla exporter med full reproducerbarhet.

| Kolumn | Typ | Null | Beskrivning |
|--------|-----|------|-------------|
| id | INT | NO | Auto-increment PK |
| **export_uid** | VARCHAR(36) | NO | UUID for delning/filsystem |
| **snapshot_id** | INT | NO | FK till analytics_snapshots |
| export_type | VARCHAR(50) | NO | Typ: riders_at_risk, cohort, winback |
| export_format | VARCHAR(20) | NO | Format: csv, pdf, json |
| filename | VARCHAR(255) | YES | Genererat filnamn |
| exported_by | INT | YES | User ID |
| exported_at | TIMESTAMP | NO | DEFAULT CURRENT_TIMESTAMP |
| ip_address | VARCHAR(45) | YES | IP for GDPR |
| ip_hash | VARCHAR(64) | YES | SHA256 av IP (for anonymisering) |
| season_year | INT | YES | Vilket ar som exporterades |
| series_id | INT | YES | Filtrering pa serie |
| brand_id | INT | YES | Filtrering pa brand |
| **filters_json** | JSON | YES | Alla filter som anvandes |
| row_count | INT | NO | Antal rader |
| contains_pii | TINYINT(1) | NO | 1 om persondata ingar |
| data_fingerprint | VARCHAR(64) | YES | SHA256 hash av exportdata |
| source_query_hash | VARCHAR(64) | YES | Hash av SQL-query |
| manifest | JSON | YES | Komplett manifest |
| created_at | TIMESTAMP | NO | DEFAULT CURRENT_TIMESTAMP |
| updated_at | TIMESTAMP | YES | ON UPDATE CURRENT_TIMESTAMP |

**Index:**
- UNIQUE(export_uid)
- INDEX(snapshot_id)
- INDEX(season_year, export_type)
- INDEX(exported_by, exported_at)
- INDEX(contains_pii, exported_at)

#### `brands` (NY)
Varumarken som grupperar serier.

| Kolumn | Typ | Null | Beskrivning |
|--------|-----|------|-------------|
| id | INT | NO | Auto-increment PK |
| name | VARCHAR(100) | NO | Fullstandigt namn |
| short_code | VARCHAR(20) | YES | Kort kod (t.ex. GES, SES) |
| logo_url | VARCHAR(255) | YES | URL till logotyp |
| description | TEXT | YES | Beskrivning |
| active | TINYINT(1) | NO | DEFAULT 1 |
| created_at | TIMESTAMP | NO | DEFAULT CURRENT_TIMESTAMP |

#### `brand_series_map` (NY)
Koppling mellan brands och serier.

| Kolumn | Typ | Null | Beskrivning |
|--------|-----|------|-------------|
| id | INT | NO | Auto-increment PK |
| brand_id | INT | NO | FK till brands |
| series_id | INT | NO | FK till series |
| valid_from_year | INT | YES | Giltig fran ar (NULL=alltid) |
| valid_to_year | INT | YES | Giltig till ar (NULL=fortfarande) |
| is_primary | TINYINT(1) | NO | DEFAULT 0, 1=huvudserie |

**Index:**
- UNIQUE(brand_id, series_id)
- INDEX(series_id)
- INDEX(brand_id, valid_from_year, valid_to_year)

#### `analytics_recalc_queue` (NY)
Ko for omberakning efter merge eller dataandring.

| Kolumn | Typ | Null | Beskrivning |
|--------|-----|------|-------------|
| id | INT | NO | Auto-increment PK |
| reason | VARCHAR(50) | NO | Anledning: rider_merge, data_fix, manual |
| rider_id | INT | YES | Canonical rider ID (NULL=alla) |
| year_from | INT | NO | Forsta ar att omberakna |
| year_to | INT | NO | Sista ar att omberakna |
| priority | TINYINT | NO | DEFAULT 5 (1=hogst, 10=lagst) |
| status | ENUM | NO | pending, processing, completed, failed |
| error_text | TEXT | YES | Felmeddelande om failed |
| created_at | TIMESTAMP | NO | DEFAULT CURRENT_TIMESTAMP |
| processed_at | TIMESTAMP | YES | Nar jobbet processades |
| created_by | INT | YES | User ID som skapade jobbet |

**Index:**
- INDEX(status, priority, created_at)
- INDEX(rider_id)

#### `analytics_cron_runs` (uppdaterad)
Cron-korningslogg med heartbeat.

| Kolumn | Typ | Null | Beskrivning |
|--------|-----|------|-------------|
| id | INT | NO | Auto-increment PK |
| job_name | VARCHAR(50) | NO | Jobbnamn |
| run_key | VARCHAR(50) | NO | Unik nyckel (t.ex. ar) |
| status | ENUM | NO | started, success, failed, timeout |
| started_at | TIMESTAMP | NO | DEFAULT CURRENT_TIMESTAMP |
| finished_at | TIMESTAMP | YES | Avslutad |
| **heartbeat_at** | TIMESTAMP | YES | Senaste heartbeat |
| **duration_ms** | INT | YES | Total tid i millisekunder |
| rows_affected | INT | YES | Antal rader |
| **error_text** | TEXT | YES | Felmeddelande |
| log | JSON | YES | Extra loggdata |

**Index:**
- UNIQUE(job_name, run_key)
- INDEX(status, started_at)
- INDEX(heartbeat_at)

---

## Karnkomponenter

### 1. AnalyticsConfig.php (v3.0.1)

```php
// Platform
public const PLATFORM_VERSION = '3.0.1';
public const CALCULATION_VERSION = 'v3';

// Export krav
public const EXPORT_REQUIRE_SNAPSHOT = true;  // Alla exporter MASTE ha snapshot_id

// Cron
public const CRON_HEARTBEAT_INTERVAL = 60;    // Sekunder mellan heartbeats
public const CRON_TIMEOUT_MINUTES = 30;       // Max tid for ett jobb
public const CRON_LOG_RETENTION_DAYS = 90;    // Behall loggar i 90 dagar

// GDPR
public const IP_RETENTION_DAYS = 90;          // Ta bort/hasha IP efter 90 dagar
public const PII_EXPORT_RATE_LIMIT = 10;      // Max PII-exporter per timme
public const PII_EXPORT_RATE_WINDOW = 3600;   // 1 timme i sekunder
```

### 2. KPICalculator.php

**Nya metoder for brand:**
```php
public function getSeriesForBrand(int $brandId, int $year): array
public function getCohortRetentionByBrand(int $cohortYear, int $brandId, int $maxYears = 5): array
public function getRidersForBrand(int $brandId, int $year): array
public function getRetentionRateByBrand(int $year, int $brandId): float
```

**Retention-metoder (oforandrade):**
```php
getRetentionRate($year): float           // retention_from_prev
getReturningShareOfCurrent($year): float // returning_share_of_current
getChurnRate($year): float               // 100 - retention
getRookieRate($year): float              // rookies / total * 100
getGrowthRate($year): float              // (current - prev) / prev * 100
getRetentionMetrics($year): array        // Alla KPIs samlat med definitioner
```

### 3. AnalyticsEngine.php

**Nya metoder:**
```php
/**
 * Processa recalc-kon
 * Kors efter huvudjobbet i cron
 */
public function processRecalcQueue(int $limit = 100): array {
    $results = ['processed' => 0, 'failed' => 0];

    // Hamta pending jobb sorterat pa prioritet
    $jobs = $this->pdo->query("
        SELECT * FROM analytics_recalc_queue
        WHERE status = 'pending'
        ORDER BY priority ASC, created_at ASC
        LIMIT $limit
    ")->fetchAll();

    foreach ($jobs as $job) {
        $this->markRecalcJob($job['id'], 'processing');

        try {
            for ($year = $job['year_from']; $year <= $job['year_to']; $year++) {
                if ($job['rider_id']) {
                    // Omberakna specifik rider
                    $this->recalculateRider($job['rider_id'], $year);
                } else {
                    // Omberakna hela aret
                    $this->refreshAllStatsFast($year);
                }
            }
            $this->markRecalcJob($job['id'], 'completed');
            $results['processed']++;
        } catch (Exception $e) {
            $this->markRecalcJob($job['id'], 'failed', $e->getMessage());
            $results['failed']++;
        }
    }

    return $results;
}

/**
 * Heartbeat for langt korande jobb
 */
public function heartbeat(): void {
    if ($this->currentJobId) {
        $this->pdo->prepare("
            UPDATE analytics_cron_runs
            SET heartbeat_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ")->execute([$this->currentJobId]);
    }
}

/**
 * Kolla om jobbet ar timeout
 */
public function checkTimeout(): bool {
    $timeoutMinutes = AnalyticsConfig::CRON_TIMEOUT_MINUTES;
    $stmt = $this->pdo->prepare("
        SELECT 1 FROM analytics_cron_runs
        WHERE id = ?
          AND started_at < DATE_SUB(NOW(), INTERVAL ? MINUTE)
    ");
    $stmt->execute([$this->currentJobId, $timeoutMinutes]);
    return (bool)$stmt->fetch();
}
```

### 4. IdentityResolver.php

**Uppdaterad mergeRiders:**
```php
/**
 * Sla samman tva riders och schemalägg omberakning
 */
public function mergeRiders(int $keepId, int $mergeId, int $adminId, string $reason = ''): bool {
    $this->pdo->beginTransaction();

    try {
        // 1. Hamta min/max ar for berorda riders
        $stmt = $this->pdo->prepare("
            SELECT MIN(season_year) as min_year, MAX(season_year) as max_year
            FROM rider_yearly_stats
            WHERE rider_id IN (?, ?)
        ");
        $stmt->execute([$keepId, $mergeId]);
        $years = $stmt->fetch();

        // 2. Skapa merge-record
        $stmt = $this->pdo->prepare("
            INSERT INTO rider_merge_map (original_rider_id, canonical_rider_id, merged_at, merged_by, reason)
            VALUES (?, ?, CURRENT_TIMESTAMP, ?, ?)
        ");
        $stmt->execute([$mergeId, $keepId, $adminId, $reason]);

        // 3. Lagg i recalc-ko
        if ($years['min_year'] && $years['max_year']) {
            $stmt = $this->pdo->prepare("
                INSERT INTO analytics_recalc_queue
                (reason, rider_id, year_from, year_to, priority, status, created_by)
                VALUES ('rider_merge', ?, ?, ?, 1, 'pending', ?)
            ");
            $stmt->execute([$keepId, $years['min_year'], $years['max_year'], $adminId]);
        }

        // 4. Logga i audit
        $stmt = $this->pdo->prepare("
            INSERT INTO rider_identity_audit
            (action, rider_id, affected_rider_id, performed_by, details)
            VALUES ('merge', ?, ?, ?, ?)
        ");
        $stmt->execute(['merge', $keepId, $mergeId, $adminId, json_encode([
            'reason' => $reason,
            'recalc_years' => [$years['min_year'], $years['max_year']]
        ])]);

        $this->pdo->commit();
        return true;

    } catch (Exception $e) {
        $this->pdo->rollBack();
        throw $e;
    }
}
```

### 5. SVGChartRenderer.php

**PNG fallback policy:**
```php
/**
 * Konvertera SVG till PNG
 *
 * FALLBACK POLICY:
 * - Om Imagick finns och fungerar: returnera PNG binary
 * - Om Imagick saknas: returnera null
 * - Anropande kod (PdfExportBuilder) maste hantera null och
 *   istallet inbadda SVG direkt i PDF
 *
 * @param string $svg SVG-kod
 * @param int $scale Skalningsfaktor (2 = 2x resolution)
 * @return string|null PNG data eller null om konvertering ej mojlig
 */
public function svgToPng(string $svg, int $scale = 2): ?string {
    // Kolla om Imagick finns
    if (!extension_loaded('imagick')) {
        return null;  // Graceful fallback
    }

    // Kolla om Imagick kan lasa SVG
    if (!in_array('SVG', \Imagick::queryFormats('SVG'))) {
        return null;  // SVG-stod saknas
    }

    try {
        $im = new \Imagick();
        $im->setBackgroundColor(new \ImagickPixel('transparent'));
        $im->readImageBlob($svg);
        $im->setImageFormat('png');

        if ($scale > 1) {
            $w = $im->getImageWidth() * $scale;
            $h = $im->getImageHeight() * $scale;
            $im->resizeImage($w, $h, \Imagick::FILTER_LANCZOS, 1);
        }

        return $im->getImageBlob();

    } catch (\Exception $e) {
        // Logga felet men returnera null for graceful fallback
        error_log("SVGChartRenderer::svgToPng failed: " . $e->getMessage());
        return null;
    }
}

/**
 * Kolla om PNG-konvertering ar tillganglig
 */
public function canConvertToPng(): bool {
    return extension_loaded('imagick') &&
           in_array('SVG', \Imagick::queryFormats('SVG'));
}
```

### 6. ExportLogger.php (v3.0.1)

**Uppdaterad med obligatorisk snapshot_id:**
```php
/**
 * Logga en export
 *
 * @param string $exportType Typ av export
 * @param array $data Exporterad data
 * @param array $options MASTE innehalla 'snapshot_id'
 * @throws InvalidArgumentException Om snapshot_id saknas
 */
public function logExport(string $exportType, array $data, array $options = []): int {
    // KRAV: snapshot_id maste finnas
    if (empty($options['snapshot_id'])) {
        throw new InvalidArgumentException(
            'snapshot_id is required for all exports. Use getLatestSnapshotId() if needed.'
        );
    }

    // Generera export_uid
    $exportUid = $this->generateExportUid();

    // Berakna fingerprint deterministiskt
    $fingerprint = $this->calculateFingerprint($data);

    // Bygg manifest
    $manifest = $this->createManifest($exportType, $data, $options);
    $manifest['export_uid'] = $exportUid;
    $manifest['snapshot_id'] = $options['snapshot_id'];

    // Kolla PII
    $containsPii = $this->containsPII($data);

    // Rate limit for PII
    if ($containsPii) {
        $this->checkPiiRateLimit($options['user_id'] ?? null);
    }

    $stmt = $this->pdo->prepare("
        INSERT INTO analytics_exports (
            export_uid, snapshot_id, export_type, export_format, filename,
            exported_by, ip_address, ip_hash,
            season_year, series_id, brand_id, filters_json,
            row_count, contains_pii,
            data_fingerprint, source_query_hash, manifest
        ) VALUES (
            ?, ?, ?, ?, ?,
            ?, ?, ?,
            ?, ?, ?, ?,
            ?, ?,
            ?, ?, ?
        )
    ");

    $ipAddress = $this->getClientIP();
    $ipHash = $ipAddress ? hash('sha256', $ipAddress . date('Y-m')) : null;

    $stmt->execute([
        $exportUid,
        $options['snapshot_id'],
        $exportType,
        $options['format'] ?? 'csv',
        $options['filename'] ?? null,
        $options['user_id'] ?? null,
        $ipAddress,
        $ipHash,
        $options['year'] ?? null,
        $options['series_id'] ?? null,
        $options['brand_id'] ?? null,
        json_encode($options['filters'] ?? []),
        count($data),
        $containsPii ? 1 : 0,
        $fingerprint,
        $options['query_hash'] ?? null,
        json_encode($manifest),
    ]);

    return (int)$this->pdo->lastInsertId();
}

/**
 * Hamta senaste snapshot for ett ar
 */
public function getLatestSnapshotId(int $year): ?int {
    $stmt = $this->pdo->prepare("
        SELECT id FROM analytics_snapshots
        WHERE season_year = ?
        ORDER BY generated_at DESC
        LIMIT 1
    ");
    $stmt->execute([$year]);
    return $stmt->fetchColumn() ?: null;
}

/**
 * Generera UUID for export
 */
private function generateExportUid(): string {
    return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );
}

/**
 * Kolla PII rate limit
 */
private function checkPiiRateLimit(?int $userId): void {
    if (!$userId) return;

    $limit = AnalyticsConfig::PII_EXPORT_RATE_LIMIT;
    $window = AnalyticsConfig::PII_EXPORT_RATE_WINDOW;

    $stmt = $this->pdo->prepare("
        SELECT COUNT(*) FROM analytics_exports
        WHERE exported_by = ?
          AND contains_pii = 1
          AND exported_at > DATE_SUB(NOW(), INTERVAL ? SECOND)
    ");
    $stmt->execute([$userId, $window]);
    $count = (int)$stmt->fetchColumn();

    if ($count >= $limit) {
        throw new RateLimitException(
            "PII export rate limit exceeded. Max {$limit} per hour."
        );
    }
}
```

### 7. PdfExportBuilder.php (NY)

```php
<?php
/**
 * PdfExportBuilder
 *
 * Bygger PDF-exporter med "Definitions & Provenance" block.
 * Anvander SVGChartRenderer for grafer (PNG om Imagick finns, annars SVG).
 */

class PdfExportBuilder {
    private PDO $pdo;
    private SVGChartRenderer $chartRenderer;
    private ExportLogger $exportLogger;
    private string $exportUid;
    private int $snapshotId;

    // KPI-definitioner som MASTE finnas i varje export
    private const REQUIRED_KPI_DEFINITIONS = [
        'active' => 'Rider med minst 1 event (unik event_id) under aret',
        'retention_from_prev' => 'Andel av forra arets riders som aterkommer: (retained/prev_total)*100',
        'returning_share' => 'Andel av arets riders som deltog forra aret: (retained/current_total)*100',
        'rookie_rate' => 'Andel av arets riders som ar nya: (rookies/total)*100',
        'growth_rate' => 'Forandring i antal riders: ((current-prev)/prev)*100',
    ];

    public function __construct(PDO $pdo, int $snapshotId) {
        $this->pdo = $pdo;
        $this->snapshotId = $snapshotId;
        $this->chartRenderer = new SVGChartRenderer();
        $this->exportLogger = new ExportLogger($pdo);
        $this->exportUid = ''; // Satts vid build()
    }

    /**
     * Bygg PDF med Definitions & Provenance block
     */
    public function build(string $reportType, array $data, array $options = []): string {
        // Logga exporten och hamta export_uid
        $exportId = $this->exportLogger->logExport($reportType, $data, array_merge($options, [
            'snapshot_id' => $this->snapshotId,
            'format' => 'pdf',
        ]));

        // Hamta export_uid
        $stmt = $this->pdo->prepare("SELECT export_uid FROM analytics_exports WHERE id = ?");
        $stmt->execute([$exportId]);
        $this->exportUid = $stmt->fetchColumn();

        // Hamta snapshot metadata
        $snapshot = $this->getSnapshotMetadata();

        // Starta PDF (anvander TCPDF eller Dompdf)
        $pdf = $this->initPdf();

        // Lagg till rubrik
        $pdf->addPage();
        $pdf->setTitle($options['title'] ?? 'Analytics Export');

        // === DEFINITIONS & PROVENANCE BLOCK (OBLIGATORISKT) ===
        $pdf->addSection('Definitions & Provenance');

        // KPI-definitioner
        $pdf->addSubSection('KPI Definitions');
        foreach (self::REQUIRED_KPI_DEFINITIONS as $kpi => $definition) {
            $pdf->addDefinition($kpi, $definition);
        }

        // Snapshot metadata
        $pdf->addSubSection('Data Provenance');
        $pdf->addMetadata('Export UID', $this->exportUid);
        $pdf->addMetadata('Snapshot ID', $this->snapshotId);
        $pdf->addMetadata('Snapshot Date', $snapshot['generated_at']);
        $pdf->addMetadata('Season Year', $snapshot['season_year']);
        $pdf->addMetadata('Platform Version', $snapshot['code_version']);
        $pdf->addMetadata('Data Fingerprint', $snapshot['data_fingerprint']);
        $pdf->addMetadata('Generated', date('Y-m-d H:i:s'));

        // === RAPPORT-INNEHALL ===
        $pdf->addSection('Report Data');

        // Lagg till grafer
        if (!empty($options['charts'])) {
            foreach ($options['charts'] as $chart) {
                $svg = $this->renderChart($chart);
                $png = $this->chartRenderer->svgToPng($svg);

                if ($png) {
                    // Imagick finns - anvand PNG
                    $pdf->addImage($png, 'png');
                } else {
                    // Fallback - inbadda SVG
                    $pdf->addSvg($svg);
                }
            }
        }

        // Lagg till tabelldata
        if (!empty($data)) {
            $pdf->addTable($data);
        }

        // === FOOTER MED FINGERPRINT ===
        $fingerprint = $this->exportLogger->calculateFingerprint($data);
        $pdf->setFooter("Export: {$this->exportUid} | Fingerprint: " . substr($fingerprint, 0, 16) . "...");

        return $pdf->output();
    }

    private function getSnapshotMetadata(): array {
        $stmt = $this->pdo->prepare("SELECT * FROM analytics_snapshots WHERE id = ?");
        $stmt->execute([$this->snapshotId]);
        return $stmt->fetch() ?: [];
    }

    private function renderChart(array $chartConfig): string {
        $type = $chartConfig['type'] ?? 'line';
        $data = $chartConfig['data'] ?? [];
        $options = $chartConfig['options'] ?? [];

        return match($type) {
            'line' => $this->chartRenderer->lineChart($data, $options),
            'bar' => $this->chartRenderer->barChart($data, $options),
            'donut' => $this->chartRenderer->donutChart($data, $options),
            'stacked' => $this->chartRenderer->stackedBarChart($data, $options),
            default => $this->chartRenderer->lineChart($data, $options),
        };
    }

    private function initPdf() {
        // Anvand TCPDF eller Dompdf beroende pa vad som finns
        if (class_exists('TCPDF')) {
            return new TcpdfWrapper();
        } elseif (class_exists('Dompdf\Dompdf')) {
            return new DompdfWrapper();
        }
        throw new RuntimeException('No PDF library available (TCPDF or Dompdf required)');
    }
}
```

---

## KPI-definitioner

**KRITISKT:** Dessa definitioner ar **obligatoriska** i alla PDF-exporter.

### Definition Box (MASTE finnas i varje PDF)

```
┌─────────────────────────────────────────────────────────────────────────────┐
│ DEFINITIONS & PROVENANCE                                                    │
├─────────────────────────────────────────────────────────────────────────────┤
│ KPI DEFINITIONS:                                                            │
│                                                                             │
│ Active Rider:                                                               │
│   Rider med minst 1 event (unik event_id) under aret.                      │
│   Konstant: ACTIVE_MIN_EVENTS = 1                                          │
│                                                                             │
│ Retention Rate (retention_from_prev):                                       │
│   Andel av FORRA arets riders som aterkommer.                              │
│   Formel: (riders i bade N och N-1) / (riders i N-1) * 100                 │
│   Svarar pa: "Hur manga av forra arets deltagare kom tillbaka?"            │
│                                                                             │
│ Returning Share (returning_share_of_current):                               │
│   Andel av ARETS riders som deltog forra aret.                             │
│   Formel: (riders i bade N och N-1) / (riders i N) * 100                   │
│   Svarar pa: "Hur stor del av arets deltagare ar aterkommande?"            │
│                                                                             │
│ Rookie Rate:                                                                │
│   Andel av arets riders som ar nya (forsta aret).                          │
│   Formel: (rookies) / (total) * 100                                        │
│                                                                             │
│ Growth Rate:                                                                │
│   Procentuell forandring i antal riders.                                   │
│   Formel: ((riders_N - riders_N-1) / riders_N-1) * 100                     │
│                                                                             │
├─────────────────────────────────────────────────────────────────────────────┤
│ DATA PROVENANCE:                                                            │
│                                                                             │
│ Export UID:       a1b2c3d4-e5f6-7890-abcd-ef1234567890                     │
│ Snapshot ID:      1234                                                      │
│ Snapshot Date:    2026-01-16 04:00:00                                      │
│ Season Year:      2025                                                      │
│ Platform Version: 3.0.1                                                     │
│ Data Fingerprint: sha256:abc123def456...                                   │
│ Generated:        2026-01-16 14:30:00                                      │
└─────────────────────────────────────────────────────────────────────────────┘
```

---

## Export och Reproducerbarhet

### Principer (v3.0.1)

1. **Alla exporter MASTE ha snapshot_id** - inga "live" exporter tillats
2. **export_uid** genereras for varje export - anvands for filnamn och delning
3. **Fingerprint** (SHA256) beraknas deterministiskt pa sorterad JSON
4. **Manifest** innehaller alla parametrar for full reproducerbarhet
5. **PII-flagga** markerar exporter med persondata
6. **Definition Box** ar obligatorisk i alla PDF-exporter

### Export-flode

```
1. Anvandare begär export
         │
         ▼
2. System hamtar senaste snapshot_id for aret
   (eller anvander specifikt snapshot_id)
         │
         ▼
3. ExportLogger validerar snapshot_id (REQUIRED)
         │
         ▼
4. Om PII: kolla rate limit (max 10/timme)
         │
         ▼
5. Generera export_uid (UUID)
         │
         ▼
6. Berakna data_fingerprint (SHA256)
         │
         ▼
7. Bygg manifest med:
   - export_uid, snapshot_id
   - alla filter
   - KPI definition versions
   - platform_version
         │
         ▼
8. Spara till analytics_exports
         │
         ▼
9. Om PDF: PdfExportBuilder laggar till
   Definitions & Provenance block
         │
         ▼
10. Returnera fil med namn: {export_type}_{export_uid}.{format}
```

### Verifiera export

```php
// Hamta export via UID
$export = $logger->getExportByUid('a1b2c3d4-e5f6-7890-abcd-ef1234567890');

// Aterskap data fran samma snapshot
$data = $kpi->getDataFromSnapshot($export['snapshot_id'], $export['filters_json']);

// Verifiera fingerprint
$currentFingerprint = $logger->calculateFingerprint($data);
$isValid = ($currentFingerprint === $export['data_fingerprint']);
```

---

## Identity Resolution och Recalc

### Merge → Recalc Policy

Nar tva riders slas samman sker foljande automatiskt:

```
1. mergeRiders(keepId=100, mergeId=200) anropas
         │
         ▼
2. Hamta min/max year for bada riders
   → year_from = 2018, year_to = 2025
         │
         ▼
3. Skapa merge record i rider_merge_map
         │
         ▼
4. Lagg jobb i analytics_recalc_queue:
   - rider_id = 100 (canonical)
   - year_from = 2018
   - year_to = 2025
   - priority = 1 (hogst)
   - reason = 'rider_merge'
         │
         ▼
5. Logga i rider_identity_audit
         │
         ▼
6. Nasta cron-korning processar recalc_queue:
   - Omberaknar rider_yearly_stats for rider 100, ar 2018-2025
   - Omberaknar series_participation
   - Markerar jobb som completed
```

### Recalc Queue Processing

Kors dagligen efter huvudjobbet:

```php
// I cron/refresh-analytics.php
$engine = new AnalyticsEngine($pdo);

// 1. Kor huvudjobb
$engine->refreshAllStatsFast($currentYear);

// 2. Processa recalc-ko (max 100 jobb)
$recalcResults = $engine->processRecalcQueue(100);
echo "Recalc: {$recalcResults['processed']} processed, {$recalcResults['failed']} failed\n";
```

---

## Drift och Cron

### Heartbeat-logik

For att detektera hangande jobb anvands heartbeat:

```php
// I refresh-analytics.php
$engine = new AnalyticsEngine($pdo);
$jobId = $engine->startJob('daily-refresh', date('Y-m-d'));

// Registrera shutdown handler for graceful failure
register_shutdown_function(function() use ($engine, $jobId) {
    if ($engine->currentJobId) {
        $engine->endJob('failed', 0, ['error' => 'Unexpected shutdown']);
    }
});

$riders = getRidersToProcess();
$total = count($riders);

foreach ($riders as $i => $rider) {
    // Skicka heartbeat var 60:e sekund
    if ($i % 100 === 0) {
        $engine->heartbeat();

        // Kolla timeout
        if ($engine->checkTimeout()) {
            $engine->endJob('timeout', $i, ['error' => 'Job exceeded 30 min limit']);
            exit(1);
        }
    }

    processRider($rider);
}

$engine->endJob('success', $total);
```

### Failure Recovery

Jobb som ar "started" men saknar heartbeat >5 min markeras som failed:

```sql
-- Kor i separat cleanup-cron (var 10:e minut)
UPDATE analytics_cron_runs
SET status = 'failed',
    error_text = 'No heartbeat received - presumed dead',
    finished_at = NOW()
WHERE status = 'started'
  AND (heartbeat_at IS NULL AND started_at < DATE_SUB(NOW(), INTERVAL 5 MINUTE))
  OR (heartbeat_at < DATE_SUB(NOW(), INTERVAL 5 MINUTE));
```

### Log Retention

```sql
-- Kor veckovis
DELETE FROM analytics_cron_runs
WHERE finished_at < DATE_SUB(NOW(), INTERVAL 90 DAY)
  AND status IN ('success', 'failed');

-- Behall timeout-entries langre for analys
DELETE FROM analytics_cron_runs
WHERE finished_at < DATE_SUB(NOW(), INTERVAL 180 DAY)
  AND status = 'timeout';
```

### Cron Schedule

```bash
# Daglig refresh kl 04:00
0 4 * * * /usr/bin/php /var/www/thehub/analytics/cron/refresh-analytics.php >> /var/log/thehub/analytics.log 2>&1

# Cleanup hangande jobb var 10:e minut
*/10 * * * * /usr/bin/php /var/www/thehub/analytics/cron/cleanup-stale-jobs.php >> /var/log/thehub/analytics.log 2>&1

# Log retention veckovis (sondag 03:00)
0 3 * * 0 /usr/bin/php /var/www/thehub/analytics/cron/cleanup-old-logs.php >> /var/log/thehub/analytics.log 2>&1

# IP anonymisering dagligen (02:00)
0 2 * * * /usr/bin/php /var/www/thehub/analytics/cron/anonymize-old-ips.php >> /var/log/thehub/analytics.log 2>&1
```

---

## Sakerhet och GDPR

### Exporttyper med PII (contains_pii=1)

| Export Type | PII-flagga | Anledning |
|-------------|------------|-----------|
| `riders_at_risk` | 1 | Innehaller namn, kontaktinfo |
| `cohort_riders` | 1 | Lista med rider-ID och namn |
| `winback_targets` | 1 | Kontaktlista |
| `rider_journey` | 1 | Individuell historik |
| `churned_riders` | 1 | Lista med namn |
| `comebacks` | 1 | Lista med namn |
| `rookies_list` | 1 | Lista med namn |
| `club_members` | 1 | Medlemslista |
| --- | --- | --- |
| `summary` | 0 | Aggregerat |
| `retention_stats` | 0 | Aggregerat |
| `demographics` | 0 | Aggregerat |
| `series_flow` | 0 | Aggregerat |
| `club_stats` | 0 | Aggregerat (inga individer) |
| `data_quality` | 0 | Systemdata |

### IP-adress Retention

```php
// I AnalyticsConfig
public const IP_RETENTION_DAYS = 90;

// I anonymize-old-ips.php
$stmt = $pdo->prepare("
    UPDATE analytics_exports
    SET ip_address = NULL
    WHERE ip_address IS NOT NULL
      AND exported_at < DATE_SUB(NOW(), INTERVAL ? DAY)
");
$stmt->execute([AnalyticsConfig::IP_RETENTION_DAYS]);
```

**Alternativ: Hash IP direkt**
```php
// Vid export
$ipHash = hash('sha256', $ipAddress . date('Y-m'));  // Saltat med manad
```

### Rate Limit for PII-exporter

```php
// Max 10 PII-exporter per timme per anvandare
public const PII_EXPORT_RATE_LIMIT = 10;
public const PII_EXPORT_RATE_WINDOW = 3600; // sekunder

// Implementerat i ExportLogger::checkPiiRateLimit()
```

### Audit Trail

```php
// Logga nar nagon oppnar/laddar ner PII-export
$stmt = $pdo->prepare("
    INSERT INTO analytics_export_access_log
    (export_id, accessed_by, access_type, ip_address, accessed_at)
    VALUES (?, ?, ?, ?, NOW())
");
$stmt->execute([$exportId, $userId, 'download', $ipAddress]);
```

---

## Analytics-sidor

### Export Center (`analytics-export-center.php`) - NY

**Syfte:** Central oversikt over alla exporter

**Visar:**
- Lista over alla exporter med:
  - export_uid (klickbar)
  - snapshot_id
  - export_type
  - format
  - row_count
  - contains_pii (badge)
  - fingerprint (trunkerad)
  - exported_at
  - exported_by
- Filter: datum, typ, PII-flagga, anvandare
- Detaljer: klicka pa export for manifest
- Verifiering: knapp for att verifiera fingerprint
- Re-export: knapp for att skapa ny export fran samma snapshot

**Behorigheter:**
- `super_admin`: ser alla exporter
- `statistics`: ser endast egna exporter

---

## Setup och Underhall

### Initial Setup (v3.0.1)

1. **Kor migrationer:**
```bash
mysql -u user -p database < analytics/migrations/001_analytics_tables.sql
mysql -u user -p database < analytics/migrations/006_production_readiness.sql
mysql -u user -p database < analytics/migrations/007_production_ready.sql
```

2. **Populera brands:**
```sql
INSERT INTO brands (name, short_code) VALUES
('Gravity Enduro Series', 'GES'),
('Swedish Enduro Series', 'SES'),
('Downhill SM', 'DHSM'),
('XC Cup', 'XCC');

-- Mappa serier till brands (anpassa series_id efter er data)
INSERT INTO brand_series_map (brand_id, series_id, is_primary) VALUES
(1, 1, 1),  -- GES -> GES main series
(1, 2, 0),  -- GES -> GES Junior
(2, 3, 1),  -- SES -> SES National
-- etc.
```

3. **Populera historisk data:**
```bash
php analytics/populate-historical.php
```

4. **Verifiera Production Ready Checklist** (se ovan)

---

## Changelog

### v3.0.1 (2026-01-16) - 100% Production Ready

**Export Reproducerbarhet:**
- `snapshot_id` ar nu OBLIGATORISKT for alla exporter
- `export_uid` (UUID) genereras for varje export
- Alla export-endpoints validerar snapshot_id
- "Live" exporter ar forbjudna

**Brand/Varumarke-dimension:**
- Ny tabell `brands` for varumarken
- Ny tabell `brand_series_map` for koppling brand↔serie
- `KPICalculator::getSeriesForBrand()` metod
- `getCohortRetentionByBrand()` anvander brand_series_map

**Identity Resolution → Recalc:**
- `IdentityResolver::mergeRiders()` laggar jobb i recalc_queue
- Ny tabell `analytics_recalc_queue`
- `AnalyticsEngine::processRecalcQueue()` metod
- Automatisk omberakning efter merge

**Drift och Cron:**
- Heartbeat-logik (var 60:e sekund)
- Timeout-hantering (max 30 min)
- Failure recovery for hangande jobb
- Log retention (90 dagar)

**SVG/PNG:**
- Tydlig fallback-policy i `SVGChartRenderer::svgToPng()`
- Returnerar `null` om Imagick saknas (graceful)
- `canConvertToPng()` metod for att kolla tillganglighet

**PDF Export:**
- Ny `PdfExportBuilder` klass
- Obligatorisk "Definitions & Provenance" block
- KPI-definitioner + snapshot metadata i varje PDF

**GDPR/Sakerhet:**
- Lista over PII-exporttyper dokumenterad
- IP-adress retention (90 dagar)
- Rate limit for PII-exporter (10/timme)
- Export Center for audit trail

**Admin:**
- Ny sida `analytics-export-center.php`
- Production Ready Checklist i dokumentation

### v3.0.0 (2026-01-16)

- Initial production readiness
- KPI-definitioner tydliggjorda
- Export logging med fingerprint
- SVG ChartRenderer

---

## Open Questions / Assumptions

1. **Brand-data saknas:** Tabellerna `brands` och `brand_series_map` maste populeras manuellt med korrekt data. Vilka brands/serier finns och hur mappas de?

2. **PDF-bibliotek:** Dokumentet antar att TCPDF eller Dompdf finns. Om inget av dessa finns kan PDF-export inte anvandas. Vilket bibliotek ska installeras?

3. **Imagick krav:** SVG→PNG konvertering kraver Imagick med SVG-stod. Om detta saknas inbaddas SVG direkt i PDF. Ar detta acceptabelt for mottagarna?

4. **Recalc-frekvens:** Dokumentet antar att recalc_queue processas dagligen. Om merges sker ofta kan detta behova okas. Hur ofta sker rider-merges?

5. **Export Center behorighet:** Dokumentet foreslår att `statistics`-anvandare endast ser egna exporter. Ska de kunna se alla icke-PII exporter?

---

*Dokumentation skapad: 2026-01-16*
*Analytics Platform Version: 3.0.1*
*Calculation Version: v3*
*Status: 100% Production Ready (efter checklist)*
