# TheHUB Analytics Platform - Teknisk Dokumentation

> Komplett guide till analytics-systemet, dess komponenter och framtida forbattringar

---

## Innehallsforteckning

1. [Oversikt](#oversikt)
2. [Arkitektur](#arkitektur)
3. [Databas-struktur](#databas-struktur)
4. [Karnkomponenter](#karnkomponenter)
5. [Analytics-sidor](#analytics-sidor)
6. [KPI-definitioner](#kpi-definitioner)
7. [Setup och Underhall](#setup-och-underhall)
8. [Forbattringsforslag](#forbattringsforslag)

---

## Oversikt

TheHUB Analytics ar ett komplett analysverktyg for svensk cykelsport. Systemet beraknar och visualiserar nyckeltal (KPIs) for:

- **Retention & Growth** - Hur manga riders kommer tillbaka varje ar?
- **Demographics** - Alders- och konsfordelning
- **Series Flow** - Hur rör sig riders mellan serier?
- **Club Performance** - Klubbarnas tillvaxt och framgang
- **Geographic Distribution** - Var finns riders geografiskt?
- **Cohort Analysis** - Hur utvecklas en årgång over tid?
- **At-Risk Prediction** - Vilka riders riskerar att sluta?

### Behorigheter

Analytics kräver en av följande:
- `super_admin` roll
- `statistics` permission (kan tilldelas utan admin-rattigheter)

---

## Arkitektur

```
┌─────────────────────────────────────────────────────────────────┐
│                        ADMIN UI LAYER                           │
│  analytics-dashboard.php  │  analytics-cohorts.php  │  etc.     │
└───────────────────────────┬─────────────────────────────────────┘
                            │
┌───────────────────────────▼─────────────────────────────────────┐
│                     KPICalculator.php                           │
│  - getAllKPIs()          - getCohortRetention()                 │
│  - getRetentionRate()    - getRiskScores()                      │
│  - getGrowthTrend()      - getTopClubs()                        │
└───────────────────────────┬─────────────────────────────────────┘
                            │
┌───────────────────────────▼─────────────────────────────────────┐
│                    AnalyticsEngine.php                          │
│  - Beraknar och sparar pre-aggregerad data                      │
│  - Hanterar jobb-körningar och loggning                         │
│  - Använder IdentityResolver för rider-matching                 │
└───────────────────────────┬─────────────────────────────────────┘
                            │
┌───────────────────────────▼─────────────────────────────────────┐
│                     PRE-AGGREGATED TABLES                       │
│  rider_yearly_stats  │  series_participation  │  club_yearly_   │
│  series_crossover    │  venue_yearly_stats    │  analytics_     │
└─────────────────────────────────────────────────────────────────┘
```

### Dataflode

1. **Radata** lagras i `results`, `events`, `riders`, `clubs`
2. **AnalyticsEngine** processerar och aggregerar data
3. **Pre-aggregerade tabeller** ger snabb åtkomst
4. **KPICalculator** anvands av UI för berakningar
5. **Admin-sidor** visualiserar med Chart.js

---

## Databas-struktur

### Kärntabeller

#### `rider_yearly_stats`
Per-rider, per-ar aggregerad statistik.

| Kolumn | Typ | Beskrivning |
|--------|-----|-------------|
| rider_id | INT | Canonical rider ID |
| season_year | INT | Sasong (t.ex. 2025) |
| total_events | INT | Antal events deltagit i |
| total_series | INT | Antal unika serier |
| total_points | DECIMAL | Totala poang |
| best_position | INT | Basta placering |
| avg_position | DECIMAL | Genomsnittsplacering |
| primary_discipline | VARCHAR | Huvuddisciplin |
| primary_series_id | INT | Huvudserie |
| is_rookie | TINYINT | 1 = forsta aret |
| is_retained | TINYINT | 1 = återkom från förra året |

#### `series_participation`
Detaljerat seriedeltagande per rider och ar.

| Kolumn | Typ | Beskrivning |
|--------|-----|-------------|
| rider_id | INT | Canonical rider ID |
| series_id | INT | Serie-ID |
| season_year | INT | Sasong |
| events_attended | INT | Antal events i serien |
| first_event_date | DATE | Forsta event |
| last_event_date | DATE | Sista event |
| total_points | DECIMAL | Poang i serien |
| final_rank | INT | Slutplacering |
| is_entry_series | TINYINT | 1 = forsta serien nagonsin |

#### `series_crossover`
Rider-flöden mellan serier.

| Kolumn | Typ | Beskrivning |
|--------|-----|-------------|
| rider_id | INT | Canonical rider ID |
| from_series_id | INT | Ursprungsserie |
| to_series_id | INT | Målserie |
| from_year | INT | År från |
| to_year | INT | År till |
| crossover_type | ENUM | same_year, next_year, multi_year |

#### `club_yearly_stats`
Klubbstatistik per ar.

| Kolumn | Typ | Beskrivning |
|--------|-----|-------------|
| club_id | INT | Klubb-ID |
| season_year | INT | Sasong |
| active_riders | INT | Aktiva medlemmar |
| new_riders | INT | Nya detta år |
| retained_riders | INT | Återkommande |
| churned_riders | INT | Förlorade |
| total_points | DECIMAL | Totala poang |
| wins | INT | Antal segrar |

#### `analytics_snapshots`
Historiska KPI-ogonblicksbilder.

| Kolumn | Typ | Beskrivning |
|--------|-----|-------------|
| snapshot_date | DATE | Datum |
| snapshot_type | ENUM | daily/weekly/monthly/quarterly/yearly |
| metrics | JSON | Alla KPIs |

---

## Karnkomponenter

### 1. AnalyticsConfig.php

Centraliserad konfiguration för hela analytics-plattformen.

**Nyckelkonstanter:**

```php
// Aktivitetsdefinition
ACTIVE_MIN_STARTS = 1;  // Minst 1 start = aktiv

// Churn-definitioner
SOFT_CHURN_YEARS = 1;   // 1 år inaktiv
MEDIUM_CHURN_YEARS = 2; // 2 år inaktiv
HARD_CHURN_YEARS = 3;   // 3+ år inaktiv

// Kohort-inställningar
COHORT_MIN_SIZE = 10;           // Minsta kohort att visa
COHORT_MAX_DISPLAY_YEARS = 10;  // Max år i trendvisning
```

**Klass-rankning:**
Hierarkiskt system för att avgöra upgrade/downgrade:
- Elite = 100 (högst)
- Senior = 80
- Junior = 60
- Sport = 30
- Fun = 10 (lägst)

**Riskfaktorer för At-Risk:**
| Faktor | Vikt | Beskrivning |
|--------|------|-------------|
| declining_events | 30 | Minskande starter |
| no_recent_activity | 25 | Ingen aktivitet efter cutoff |
| class_downgrade | 15 | Gått ner i klass |
| single_series | 10 | Endast en serie |
| low_tenure | 10 | Kort karriär (1-2 år) |
| high_age_in_class | 10 | Hög ålder i klassen |

### 2. KPICalculator.php

Huvudklass för alla KPI-beräkningar. ~2200 rader kod.

**Retention & Growth:**
- `getRetentionRate($year)` - % som återkom
- `getChurnRate($year)` - % som slutade
- `getNewRidersCount($year)` - Antal rookies
- `getGrowthTrend($years)` - Tillväxt över tid

**Demographics:**
- `getAgeDistribution($year)` - Åldersfördelning
- `getGenderDistribution($year)` - Könsfördelning
- `getDisciplineDistribution($year)` - Per disciplin

**Cohort (med varumärkesfilter):**
- `getCohortRetention($year)` - Kohort-retention
- `getCohortRetentionByBrand($year, $brandId)` - Filtrerat på varumärke
- `getCohortStatusBreakdown($year)` - Active/Soft/Medium/Hard churn
- `getCohortRiders($year)` - Lista på riders
- `getCohortAverageLifespan($year)` - Snitt säsonger

**Series Flow:**
- `getEntryPointDistribution($year)` - Var börjar riders?
- `calculateFeederMatrix($year)` - Flöden mellan serier
- `getSeriesLoyalty($year)` - Lojalitet per serie

**Club:**
- `getTopClubs($year, $limit)` - Största klubbar
- `getClubGrowth($clubId)` - Klubbens tillväxt
- `getClubRetention($clubId)` - Klubbens retention

**At-Risk:**
- `getRidersAtRisk($year)` - Riders med hög churn-risk
- `calculateRiskScore($riderId)` - Individuell riskscore

### 3. AnalyticsEngine.php

Motor för databeräkning och lagring.

**Huvudfunktioner:**
- `populateRiderYearlyStats($year)` - Fyll rider_yearly_stats
- `populateSeriesParticipation($year)` - Fyll series_participation
- `populateSeriesCrossover($year)` - Beräkna flöden
- `populateClubStats($year)` - Klubbstatistik

**Jobbhantering:**
- `startJob($name, $key)` - Starta jobb med låsning
- `completeJob($jobId, $stats)` - Markera klart
- `failJob($jobId, $error)` - Markera misslyckat

### 4. IdentityResolver.php

Hanterar rider-identitet och sammanslagning av dubbletter.

**Funktioner:**
- `resolveRider($firstName, $lastName, $birthYear)` - Hitta canonical ID
- `getCanonicalId($riderId)` - Hämta master-ID
- `mergeRiders($keepId, $mergeId)` - Slå samman

---

## Analytics-sidor

### Dashboard (`analytics-dashboard.php`)

**Syfte:** Huvudöversikt med alla nyckeltal

**Visar:**
- KPI-sammanfattning (retention, churn, growth)
- Tillväxttrend (5 år)
- Top 10 klubbar
- Åldersfördelning (donut chart)
- Disciplinfördelning
- Entry points (var börjar riders)

**Filter:**
- År (dropdown)
- Jämför med annat år

---

### Kohort-analys (`analytics-cohorts.php`)

**Syfte:** Följ hur en årgång utvecklas över tid

**Nyckelkoncept:**
- Kohort = alla som började samma år
- Retention = % som fortfarande är aktiva
- Churn-kategorier: soft (1 år), medium (2 år), hard (3+ år)

**Filter:**
- Varumärke (GES, Swedish Enduro Series, etc.)
- Kohort-år
- Multi-kohort jämförelse

**Visualisering:**
- Retention-kurva (linje)
- Status-fördelning (donut)
- Rider-lista med status

---

### At-Risk (`analytics-atrisk.php`)

**Syfte:** Identifiera riders som riskerar att sluta

**Riskfaktorer:**
| Faktor | Poäng | Indikation |
|--------|-------|------------|
| Minskande starter | 30p | Tappar intresse |
| Ingen aktivitet | 25p | Har inte startat på länge |
| Klassförändring | 15p | Gått ner i klass |
| En serie | 10p | Låg investering |
| Kort karriär | 10p | Lättare att sluta |
| Hög ålder | 10p | Naturlig avgång |

**Risk-nivåer:**
- 0-39: Låg risk (grön)
- 40-59: Medel risk (gul)
- 60-79: Hög risk (orange)
- 80+: Kritisk (röd)

---

### Series Flow (`analytics-flow.php`)

**Syfte:** Visualisera rider-flöden mellan serier

**Visar:**
- Sankey-diagram med flöden
- Entry points (första serien)
- Crossover-mönster
- Feeder-analys (vilka serier "matar" andra)

---

### Series Compare (`analytics-series-compare.php`)

**Syfte:** Jämför varumärken/serier

**Funktioner:**
- Välj upp till 3 grupper av varumärken
- Aggregerar statistik per grupp
- Visar retention, growth, demografi

---

### Club Analytics (`analytics-clubs.php`)

**Syfte:** Klubbspecifik analys

**Visar:**
- Klubbens tillväxt över tid
- Retention vs genomsnitt
- Aktiva riders per år
- Top performers

---

### Geography (`analytics-geography.php`)

**Syfte:** Geografisk fördelning

**Visar:**
- Riders per region/län
- Penetration per capita
- Trendanalys per region

---

### Trends (`analytics-trends.php`)

**Syfte:** Historiska trender

**Visar:**
- Långsiktiga KPI-trender
- Jämförelser över flera säsonger
- Säsongsmönster

---

### Reports (`analytics-reports.php`)

**Syfte:** Generera och exportera rapporter

**Format:**
- PDF-rapporter
- CSV-export
- Scheduled reports (planerat)

---

### Diagnostics (`analytics-diagnose.php`)

**Syfte:** Felsökning av analytics-data

**Visar:**
- Datakvalitetsanalys
- Saknade fält
- Beräkningsstatus
- Diskrepanser

---

## KPI-definitioner

### Retention Rate
```
retention_rate = (riders_year_N som finns i year_N-1) / riders_year_N-1 × 100
```

### Churn Rate
```
churn_rate = 100 - retention_rate
```

### Growth Rate
```
growth_rate = (riders_year_N - riders_year_N-1) / riders_year_N-1 × 100
```

### Rookie Rate
```
rookie_rate = new_riders / total_active × 100
```

### Series Loyalty
```
loyalty = riders_som_återkommer_till_serie / riders_förra_året × 100
```

### Risk Score
```
risk_score = Σ(faktor_vikt × faktor_applicerar) / total_möjlig_vikt × 100
```

---

## Setup och Underhall

### Initial Setup

1. **Kör governance-migration:**
```bash
/analytics/setup-governance.php
```

2. **Kör tabell-migration:**
```bash
/analytics/setup-tables.php
```

3. **Populera historisk data:**
```bash
/admin/analytics-populate.php
```

### Dagligt Underhåll

**Cron-jobb** (`analytics/cron/refresh-analytics.php`):
- Körs dagligen
- Uppdaterar rider_yearly_stats
- Räknar om risk scores
- Skapar snapshots

### Reset

**Vid behov av omberäkning:**
```bash
/admin/analytics-reset.php
```
- Rensar alla analytics-tabeller
- Kräver ombefolkning efteråt

---

## Forbattringsforslag

### 1. Performance-optimering

**Problem:** Stora kohorter kan ta lång tid att beräkna

**Lösning:**
- Materialiserade vyer för vanliga queries
- Asynkron beräkning med jobb-kö
- Caching av tunga beräkningar

```php
// Exempel: Cache kohort-data
$cacheKey = "cohort_{$year}_{$brandId}";
$data = $cache->get($cacheKey);
if (!$data) {
    $data = $kpiCalc->getCohortRetentionByBrand($year, $brandId);
    $cache->set($cacheKey, $data, 3600); // 1 timme
}
```

### 2. Realtidsuppdateringar

**Problem:** Data uppdateras bara via cron

**Lösning:**
- Event-driven uppdateringar
- Trigger vid nytt resultat
- WebSocket för live-dashboard

### 3. Prediktiv Analys

**Problem:** At-Risk är reaktiv, inte prediktiv

**Lösning:**
- Machine learning-modell för churn-prediction
- Tidsserieanalys för säsongsmönster
- Clustering för rider-segmentering

```python
# Exempel: Sklearn churn-modell
from sklearn.ensemble import RandomForestClassifier

features = ['events_last_year', 'events_this_year', 'tenure',
            'class_change', 'series_count']
model = RandomForestClassifier()
model.fit(X_train, y_train)
predictions = model.predict_proba(X_current)
```

### 4. Jämförelse med Benchmark

**Problem:** Svårt att veta om siffror är "bra"

**Lösning:**
- Branschjämförelser
- Historiska genomsnitt
- Målsättningar med alerts

### 5. Förbättrad Visualisering

**Problem:** Statiska grafer

**Lösning:**
- Interaktiva drill-down
- Animated transitions
- Mobile-first design
- Export till PowerPoint

### 6. Varumärkes-jämförelse i Cohort Compare

**Problem:** Multi-kohort jämförelse saknar varumärkesfilter

**Lösning:**
```php
// Lägg till i KPICalculator
public function compareCohortsByBrand(array $cohortYears, int $brandId, int $maxYears = 5): array {
    $result = [];
    foreach ($cohortYears as $cohortYear) {
        $retention = $this->getCohortRetentionByBrand($cohortYear, $brandId, $cohortYear + $maxYears);
        $result[$cohortYear] = [
            'cohort_year' => $cohortYear,
            'retention_data' => $retention,
        ];
    }
    return $result;
}
```

### 7. Automatiska Alerts

**Problem:** Måste manuellt kolla dashboard

**Lösning:**
- E-post vid signifikanta ändringar
- Slack-integration
- Tröskelvärden för KPIs

```php
if ($kpis['retention_rate'] < 50) {
    sendAlert("Retention under 50%! Nuvarande: {$kpis['retention_rate']}%");
}
```

### 8. A/B-testning för Retention

**Problem:** Svårt att mäta effekt av åtgärder

**Lösning:**
- Experimentramverk
- Kontrollgrupper
- Statistisk signifikans

### 9. Integration med Externa Datakällor

**Problem:** Begränsad data

**Lösning:**
- Väder-data (påverkar deltagande?)
- Ekonomiska indikatorer
- Sociala medier-sentiment

### 10. Dokumentation och Utbildning

**Problem:** Komplex data kräver tolkning

**Lösning:**
- Inbyggda förklaringar
- Video-tutorials
- Glossary med definitioner

---

## Sammanfattning

TheHUB Analytics är ett kraftfullt system med:

**Styrkor:**
- Omfattande KPI-beräkningar
- Pre-aggregerad data för snabb åtkomst
- Flexibel konfiguration (AnalyticsConfig)
- Varumärkesfiltrering i kohort-analys
- Identity resolution för dubbletthantering

**Förbättringsområden:**
- Performance vid stora dataset
- Realtidsuppdateringar
- Prediktiv analys
- Benchmark-jämförelser
- Automatiserade alerts

---

*Dokumentation skapad: 2026-01-16*
*Analytics Platform Version: 2.0*
