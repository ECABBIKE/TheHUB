# TheHUB Analytics Platform - ROADMAP

> Senast uppdaterad: 2026-01-13

---

## STATUSOVERSIKT

| Steg | Beskrivning | Status | Kommentar |
|------|-------------|--------|-----------|
| 0 | Governance & Identity Foundation | [x] KLAR | Grunden for datakvalitet |
| 1 | Databas & Analytics-tabeller | [x] KLAR | |
| 2 | Analytics Engine (karnlogik) | [x] KLAR | |
| 3 | KPI Dashboard (admin) | [ ] Ej paborjad | |
| 4 | Serieflodeanalys | [ ] Ej paborjad | NYCKELFUNKTION |
| 5 | Rapportgenerator | [ ] Ej paborjad | |
| 6 | Publika Insikter | [ ] Ej paborjad | |
| 7 | Automatisering & Cron | [ ] Ej paborjad | |

---

## STEG 0: GOVERNANCE & IDENTITY FOUNDATION

**Mal:** Saker datagrund med identity resolution, historik och KPI-versionering
**Tid:** ~2-3 timmar
**Status:** KLAR

### Uppgifter

- [x] Skapa `analytics/includes/sql-runner.php` (FORST!)
- [x] Skapa `analytics/migrations/000_governance_core.sql`
- [x] Skapa `analytics/setup-governance.php`
- [ ] Alla governance-tabeller finns i databasen (kor setup-governance.php)
- [ ] `v_canonical_riders` VIEW fungerar (kor setup-governance.php)
- [x] Skapa `analytics/includes/IdentityResolver.php`
- [x] Skapa `analytics/includes/auth.php` (ingen global $pdo!)
- [x] Verifiera att allt fungerar

### Tabeller som skapas

- `rider_merge_map` - Hantera dubbletter
- `rider_identity_audit` - Audit-logg for identity-andringar
- `rider_affiliations` - Klubbhistorik
- `analytics_cron_runs` - Cron-korningar med las
- `analytics_logs` - Generell loggning
- `v_canonical_riders` - View for canonical lookup

---

## STEG 1: DATABAS & ANALYTICS-TABELLER

**Mal:** Skapa databastabeller for pre-beraknad statistik
**Tid:** ~2-3 timmar
**Status:** KLAR

### Uppgifter

- [x] Skapa `analytics/migrations/001_analytics_tables.sql`
- [x] Skapa `analytics/migrations/002_series_extensions.php`
- [x] Skapa `analytics/migrations/003_seed_series_levels.sql`
- [x] Skapa `analytics/setup-tables.php`
- [ ] Alla 6 analytics-tabeller finns (kor setup-tables.php)
- [ ] `series_level` ar satt pa alla serier (kor setup-tables.php)

### Tabeller som skapas

- `rider_yearly_stats`
- `series_participation`
- `series_crossover`
- `club_yearly_stats`
- `venue_yearly_stats`
- `analytics_snapshots`

---

## STEG 2: ANALYTICS ENGINE (KARNLOGIK)

**Mal:** PHP-klasser som beraknar och lagrar all statistik
**Tid:** ~4-6 timmar
**Status:** KLAR

### Uppgifter

- [x] Skapa `analytics/includes/AnalyticsEngine.php`
- [x] Skapa `analytics/includes/KPICalculator.php`
- [x] Skapa `analytics/populate-historical.php`
- [ ] Historisk data genererad (kor populate-historical.php)
- [ ] Verifiera att siffror ar rimliga

---

## STEG 3: KPI DASHBOARD (ADMIN)

**Mal:** Visuellt admin-dashboard med KPI-kort och grafer
**Tid:** ~4-5 timmar
**Status:** EJ PABORJAD

### Uppgifter

- [ ] Skapa `analytics/index.php` (dashboard)
- [ ] Skapa `assets/css/analytics.css`
- [ ] Skapa `assets/js/analytics-charts.js`
- [ ] Skapa `analytics/api/kpi.php`
- [ ] Skapa `analytics/api/charts.php`
- [ ] System Health Widget

---

## STEG 4: SERIEFLODEANALYS

**Mal:** Visualisera hur cyklister ror sig mellan serier
**Tid:** ~3-4 timmar
**Status:** EJ PABORJAD

**OBS: DETTA AR NYCKELFUNKTIONEN!**

### Uppgifter

- [ ] Skapa `analytics/reports/series-flow.php`
- [ ] Utoka KPICalculator med flodesmetoder
- [ ] Skapa API-endpoint for serieflode
- [ ] Skapa `assets/css/series-flow.css`

---

## STEG 5: RAPPORTGENERATOR

**Mal:** Exportera rapporter som PDF (via HTML) och Excel (via CSV)
**Tid:** ~3-4 timmar
**Status:** EJ PABORJAD

### Uppgifter

- [ ] Skapa `analytics/includes/ReportGenerator.php`
- [ ] Skapa rapportmallar i `analytics/templates/`
- [ ] Skapa `assets/css/report-print.css`
- [ ] Skapa `analytics/reports/index.php`
- [ ] Skapa `analytics/api/export.php`

---

## STEG 6: PUBLIKA INSIKTER

**Mal:** Publik sida med aggregerad statistik (GDPR-saker)
**Tid:** ~2-3 timmar
**Status:** EJ PABORJAD

### Uppgifter

- [ ] Skapa `public/insights/index.php`
- [ ] Skapa `assets/css/insights.css`
- [ ] SEO & Meta tags
- [ ] Sponsor CTA

---

## STEG 7: AUTOMATISERING & CRON

**Mal:** Automatisk uppdatering av analytics-data
**Tid:** ~2-3 timmar
**Status:** EJ PABORJAD

### Uppgifter

- [ ] Skapa `analytics/cron/daily-stats.php`
- [ ] Skapa `analytics/cron/monthly-snapshot.php`
- [ ] Skapa `analytics/cron/yearly-rollup.php`
- [ ] Skapa `analytics/cron/integrity-check.php`
- [ ] System Health i Dashboard
- [ ] Dokumentera cron-instruktioner

---

## TEKNISKA BESLUT

### Beslut 1: Source of Truth
- `results` + `events` ar grunddata
- Analytics-tabeller kan alltid rebuildas fran dessa

### Beslut 2: Identity Resolution
- `rider_merge_map` hanterar dubbletter
- Alla analytics-queries gar via `IdentityResolver`
- Canonical rider_id anvands ALLTID

### Beslut 3: Klubbhistorik
- `rider_affiliations` med valid_from/valid_to
- Ersatter riders.club_id for historisk analys

### Beslut 4: KPI-versionering
- Alla berakningar marks med `calculation_version`
- Startar pa v1

### Beslut 5: GDPR - Public N=10
- Publika insikter visar EJ segment < 10 deltagare
- Anvand `maskSmallSegments()` for all publik data

### Beslut 6: Serieflode (Sankey)
- Chart.js for linje/doughnut/bar
- Pseudo-Sankey (HTML/CSS) for flodesvisualisering
- Ingen extern sankey-lib

---

## CHANGELOG

### 2026-01-13
- Projekt startat
- Steg 0 KLAR: Governance & Identity Foundation
  - Skapade sql-runner.php med robust SQL-parsing
  - Skapade governance-tabeller (migrations)
  - Skapade IdentityResolver for dubblett-hantering
  - Skapade analytics auth.php med CSRF och rollkontroll
- Steg 1 KLAR: Databas & Analytics-tabeller
  - Skapade 001_analytics_tables.sql (6 tabeller)
  - Skapade 002_series_extensions.php (series_level, region, etc.)
  - Skapade 003_seed_series_levels.sql (kategorisering)
  - Skapade setup-tables.php
- Steg 2 KLAR: Analytics Engine (karnlogik)
  - Skapade AnalyticsEngine.php (beraknar all statistik)
  - Skapade KPICalculator.php (KPI-metoder for dashboard)
  - Skapade populate-historical.php (genererar historisk data)

