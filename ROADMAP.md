# TheHUB - Development Roadmap

> Senast uppdaterad: 2026-01-14
>
> **OBS:** All projektinformation ska dokumenteras i denna fil!

---

## PROJEKTOMRADEN

| Omrade | Status | Beskrivning |
|--------|--------|-------------|
| Analytics Platform | KLAR | Statistik, KPI:er, trender, rapporter |
| Betalningssystem | 80% KLAR | Swish, Stripe, ordrar, checkout |

---

# DEL 1: ANALYTICS PLATFORM

## STATUSOVERSIKT

| Steg | Beskrivning | Status | Kommentar |
|------|-------------|--------|-----------|
| 0 | Governance & Identity Foundation | [x] KLAR | Grunden for datakvalitet |
| 1 | Databas & Analytics-tabeller | [x] KLAR | Migrationer korda |
| 2 | Analytics Engine (karnlogik) | [x] KLAR | Bulk-optimerad |
| 3 | KPI Dashboard (admin) | [x] KLAR | |
| 4 | Serieflodeanalys | [x] KLAR | NYCKELFUNKTION |
| 5 | Rapportgenerator | [x] KLAR | 6 rapporttyper inkl rookies |
| 6 | Publika Insikter | [x] KLAR | GDPR-kompatibel |
| 7 | Automatisering & Cron | [x] KLAR | Daglig refresh |
| 8 | Admin-verktyg | [x] KLAR | Populate, Reset, Diagnose, Trends |
| 9 | Rookie-analys | [x] KLAR | Detaljerad nyborjarstatistik |
| 10 | Retention & Churn | [x] KLAR | Identifiera inaktiva, win-back |

---

## STEG 0: GOVERNANCE & IDENTITY FOUNDATION

**Mal:** Saker datagrund med identity resolution, historik och KPI-versionering
**Status:** KLAR

### Tabeller som skapats

- `rider_merge_map` - Hantera dubbletter
- `rider_identity_audit` - Audit-logg for identity-andringar
- `rider_affiliations` - Klubbhistorik
- `analytics_cron_runs` - Cron-korningar med las
- `analytics_logs` - Generell loggning
- `v_canonical_riders` - View for canonical lookup

---

## STEG 1: DATABAS & ANALYTICS-TABELLER

**Mal:** Skapa databastabeller for pre-beraknad statistik
**Status:** KLAR

### Tabeller som skapats

- `rider_yearly_stats`
- `series_participation`
- `series_crossover`
- `club_yearly_stats`
- `venue_yearly_stats`
- `analytics_snapshots`

---

## STEG 2: ANALYTICS ENGINE

**Mal:** PHP-klasser som beraknar och lagrar all statistik
**Status:** KLAR

### Filer

- `analytics/includes/AnalyticsEngine.php` - Beraknar all statistik
- `analytics/includes/KPICalculator.php` - KPI-metoder for dashboard
- `analytics/populate-historical.php` - Genererar historisk data

---

## STEG 3-7: DASHBOARD, FLOW, RAPPORTER, INSIKTER, CRON

**Status:** KLAR

### Filer

- `admin/analytics-dashboard.php` - KPI-kort, trender, grafer
- `admin/analytics-flow.php` - Serieflode (NYCKELFUNKTION)
- `admin/analytics-reports.php` - 6 rapporttyper med CSV-export
- `pages/insights.php` - Publik statistiksida (GDPR-kompatibel)
- `analytics/cron/refresh-analytics.php` - Daglig refresh

---

## STEG 8: ADMIN-VERKTYG

**Mal:** Verktyg for att hantera och diagnostisera analytics-data
**Status:** KLAR

### Filer som skapats

- `admin/analytics-populate.php` - Interaktiv populate med progress
- `admin/analytics-reset.php` - Rensa cron-runs eller all data
- `admin/analytics-diagnose.php` - Jamfor events-data med analytics
- `admin/analytics-trends.php` - Chart.js grafer for trender

---

## STEG 9: ROOKIE-ANALYS

**Mal:** Detaljerad analys av nya deltagare (rookies)
**Status:** KLAR

### Funktioner

Rookie-rapporten visar:
- Oversikt (antal, andel, snittålder, konsfordelning)
- Aldersfordelning for nya deltagare
- Vilka klasser nya deltagare startar i
- Events med flest nya deltagare
- Klubbar med flest nya deltagare
- Komplett lista over alla rookies med lankar till profiler

---

## STEG 10: RETENTION & CHURN-ANALYS

**Mal:** Identifiera och na ut till inaktiva deltagare
**Status:** KLAR

### Funktioner

Retention-rapporten visar nu:
- **Oversikt**: Retention rate, churn rate, antal slutat, antal comebacks
- **Trend-graf**: 5-ars retention/churn trend med Chart.js
- **Inaktivitetsanalys**: Hur lange har de varit borta? (1 ar, 2 ar, 3+ ar)
- **Churn per segment**: Vilka aldersgrupper/discipliner tappar vi flest fran?
- **Churned-lista**: Deltagare som slutade forra aret (exporterbar)
- **Comebacks**: Deltagare som atervande efter uppehall (exporterbar)
- **Win-Back Targets**: Prioriterad lista over vardefulla inaktiva att kontakta
- **One-Timers**: Deltagare med bara 1-2 starter totalt

### Nya metoder i KPICalculator

- `getChurnedRiders()` - Lista deltagare som slutat
- `getOneTimers()` - Deltagare med 1-2 starter
- `getComebackRiders()` - Atervandare efter uppehall
- `getInactiveByDuration()` - Gruppering per ar inaktiv
- `getRetentionTrend()` - 5-ars trend
- `getChurnBySegment()` - Churn per alder/disciplin/klubb
- `getChurnSummary()` - Sammanfattande nyckeltal
- `getWinBackTargets()` - Prioriterad kontaktlista

### CSV-exporter

- Win-Back Targets (prioriterad lista med profillänkar)
- Churned Riders (forra arets avhoppare)
- Comebacks (atervändare)

---

## STEG 11: ANALYTICS PHASE 2 - AVANCERADE MODULER

**Mal:** Djupgaende analytics med kohort-analys, riskprediktion och geografisk analys
**Status:** KLAR

### Arkitektur

```
┌─────────────────────────────────────────────────────────────────┐
│                    Analytics Platform v2.0                       │
├─────────────────────────────────────────────────────────────────┤
│  ┌──────────┐  ┌──────────┐  ┌──────────┐  ┌──────────┐         │
│  │ Cohort   │  │ At-Risk  │  │ Feeder   │  │ Geography│         │
│  │ Analysis │  │ Predict  │  │ Trends   │  │ Analysis │         │
│  └────┬─────┘  └────┬─────┘  └────┬─────┘  └────┬─────┘         │
│       └─────────────┴─────────────┴─────────────┘                │
│                           │                                      │
│              ┌────────────┴────────────┐                         │
│              │      KPICalculator      │                         │
│              │    + AnalyticsConfig    │                         │
│              └─────────────────────────┘                         │
└─────────────────────────────────────────────────────────────────┘
```

### 11.1 Central konfiguration (AnalyticsConfig.php)

- Aktiv-definition: minst 1 start per sasong
- Churn-definitioner: soft=1ar, medium=2ar, hard=3+ar
- Klass-ranking per ar (Elite=100 ner till Kids=5)
- Riskfaktorer med vikter (totalt 100p)
- Feature flags for valfri funktionalitet
- Regioner med befolkningsdata

### 11.2 Cohort Analysis (admin/analytics-cohorts.php)

- [x] Kohort-baserad retention tracking
- [x] Retention-kurva per kohort (line chart)
- [x] Status breakdown (active, soft/medium/hard churn)
- [x] Jamfor flera kohorter samtidigt
- [x] Average lifespan per kohort
- [x] CSV-export med GDPR-loggning

### 11.3 Rider Journey

- [x] Individuell progressionsvy
- [x] Klassforandringar over tid
- [x] Liknande ryttare-matchning
- [x] Integration med befintligt flow-system

### 11.4 At-Risk/Churn Prediction (admin/analytics-atrisk.php)

6-faktor riskmodell:
- Declining events (minskande deltagande) - 30p
- No recent activity (ingen aktivitet) - 25p
- Class downgrade (nedflyttning) - 15p
- Single series (bara en serie) - 10p
- Low tenure (kort karriar) - 10p
- High age in class (hog alder) - 10p

- [x] Risk score 0-100 → Low/Medium/High/Critical
- [x] Cron-jobb for daglig caching (refresh-risk-scores.php)
- [x] Filtrering per serie och riskniva
- [x] CSV-export for kampanjer

### 11.5 Feeder Trends

- [x] Tidsserie for serie-overgångar
- [x] Year-over-year jamforelser
- [x] Emerging flows detektion
- [x] Integration med analytics-flow.php

### 11.6 Geographic Analysis (admin/analytics-geography.php)

- [x] Riders per region (21 svenska lan)
- [x] Per capita tackning (riders per 100k inv)
- [x] Events per region
- [x] Regional tillvaxttend (5 ar)
- [x] Underservicerade regioner

### Nya databastabeller (Phase 2)

```sql
rider_cohorts          -- Kohort-lookup (cohort_year, status)
rider_risk_scores      -- Cachade riskpoang med faktorer
regions                -- Svenska lan med befolkningsdata
region_yearly_stats    -- Regional statistik per ar
feeder_trends          -- Historik for serie-floden
analytics_exports      -- GDPR-loggning av exporter
```

### Nya filer (Phase 2)

```
analytics/includes/AnalyticsConfig.php     -- Central konfiguration
analytics/migrations/005_phase2_tables.sql -- Databas-schema
analytics/cron/refresh-risk-scores.php     -- Cron-jobb for risk scores
admin/analytics-cohorts.php                -- Kohort-sida
admin/analytics-atrisk.php                 -- At-risk-sida
admin/analytics-geography.php              -- Geografi-sida
```

### Cron-jobb setup

```bash
# Daglig risk score-uppdatering (kl 03:00)
0 3 * * * /usr/bin/php /path/to/thehub/analytics/cron/refresh-risk-scores.php

# Manuell korning
php analytics/cron/refresh-risk-scores.php --year=2025 --force
```

### KPICalculator - nya metoder (Phase 2)

Cohort-metoder:
- `getCohortRetention()` - Retention per kohort over tid
- `compareCohorts()` - Jamfor flera kohorter
- `getCohortRiders()` - Lista riders i en kohort
- `getCohortStatusBreakdown()` - Status per kohort
- `getCohortAverageLifespan()` - Snittlivslangd
- `getAvailableCohorts()` - Tillgangliga kohorter

Journey-metoder:
- `getRiderJourney()` - Individuell resa
- `getRiderProgression()` - Klassforandringar
- `getSimilarRiders()` - Hitta liknande riders
- `getRiderLastActiveYear()` - Senaste aktiva ar

At-Risk-metoder:
- `getAtRiskRiders()` - Lista hogriskreyttare
- `calculateChurnRisk()` - Berakna risk for enskild rider
- `getRiskDistribution()` - Fordelning per riskniva

Feeder-metoder:
- `getFeederTrend()` - Trenddata for serie-flode
- `getFeederTrendsOverview()` - Oversikt alla floden
- `getEmergingFlows()` - Vaxande floden

Geografi-metoder:
- `getRegionalGrowthTrend()` - Regional tillvaxt
- `getUnderservedRegions()` - Underservicerade omraden
- `getEventsByRegion()` - Events per region

---

## POTENTIELLA FORBATTRINGAR (ANALYTICS)

### Datakvalitet
- [ ] Verifiera rookie-berakningar mot manuella kontroller
- [ ] Jamfor med forsta-start i results-tabellen
- [ ] Forbattra is_rookie logik for edge-cases

### Funktionalitet
- [x] Lagg till filtrering per serie i rookie-rapporten
- [x] Lagg till disciplin-specifik rookie-analys
- [ ] Lagg till geografisk analys (var kommer rookies ifran?)
- [x] Lagg till trendanalys for rookies over tid (5 ar med graf)

### Statistik-precision
- [x] Disciplinfordelning visar nu BADE primary_discipline OCH faktiskt deltagande
- [x] getDisciplineParticipation() raknar unika deltagare per disciplin fran results/events

### Integration
- [ ] Integrera rookie-data i publik insights-sida
- [ ] Lagg till rookie-statistik pa klubbsidor
- [ ] Lagg till "Ny deltagar"-badge pa rider-profiler

### Felhantering
- [x] Visa faktiska felmeddelanden i analytics-reports (inte bara "ingen data")
- [x] Smart fallback vid tomma analytics-tabeller (visar ar fran events)

---

# DEL 2: BETALNINGSSYSTEM

**Mal:** Lansera biljettforsaljning
**Status:** 80% KLAR - redo for stabilisering

## AKTUELL STATUS

### Orderhantering
| Komponent | Fil | Status |
|-----------|-----|--------|
| Order CRUD | `includes/payment.php` | Fungerar |
| Order items | `includes/payment.php` | Fungerar |
| Multi-rider ordrar | `includes/order-manager.php` | Fungerar |
| Admin orderlista | `admin/orders.php` | Fungerar |

### Gateway-arkitektur
| Gateway | Implementation | Status |
|---------|----------------|--------|
| Manuell Swish | `gateways/ManualGateway.php` | **REDO ATT ANVANDA** |
| Swish Handel | `gateways/SwishGateway.php` + `SwishClient.php` | Implementerad, saknar certifikat |
| Stripe Connect | `gateways/StripeGateway.php` + `StripeClient.php` | Implementerad, saknar API-nycklar |
| Gateway interface | `GatewayInterface.php` | Fungerar |
| PaymentManager | `PaymentManager.php` | Fungerar |

### Frontend
| Sida | Fil | Status |
|------|-----|--------|
| Checkout | `pages/checkout.php` | Fungerar (Swish QR + deeplink) |
| Mina biljetter | `pages/profile/tickets.php` | Finns |
| Mina anmalningar | `pages/profile/registrations.php` | Finns |

---

## KRITISKA BUGGAR ATT FIXA

### 1. Webhooks anvander fel databas-wrapper
**Filer:**
- `api/webhooks/swish-callback.php`
- `api/webhooks/stripe-webhook.php`

**Problem:** Anvander `$GLOBALS['pdo']` istallet for `hub_db()`
**Prioritet:** HOG

### 2. Email-notifieringar saknas
**Problem:** Ingen kod for att skicka bekraftelse-email vid betalning
**Prioritet:** HOG

### 3. Promotor event-banners
**Problem:** Promotor kan inte ladda upp banners till specifika events
**Prioritet:** HOG

### 4. Discount_codes-tabeller saknas
**Problem:** Kod refererar till tabeller som inte finns
**Prioritet:** MEDIUM

---

## LANSERINGS-CHECKLISTA

### Vecka 1: Stabilisering
- [ ] Fixa webhook databas-wrapper
- [ ] Verifiera discount_codes-tabeller
- [ ] Konfigurera Manuell Swish for test-event
- [ ] Testa hela anmalan-flode

### Vecka 2: Email & UX
- [ ] Installera PHPMailer / SMTP
- [ ] Skapa email-template for bekraftelse
- [ ] Forbattra checkout-instruktioner
- [ ] Skapa QR-kod pa Mina biljetter

### Vecka 3: Test & Lansering
- [ ] Pilot-test med riktiga anvandare
- [ ] Konfigurera production Swish-nummer
- [ ] **LANSERING**

---

## FRAMTIDA FEATURES (BETALNING)

### v1.1
- [ ] Early bird / Late fee automatik
- [ ] Check-in med QR-kod
- [ ] Swish Handel (om certifikat finns)

### v1.2
- [ ] Stripe kortbetalning
- [ ] Familjeanmalan
- [ ] Automatisk startnummer-tilldelning

---

# TEKNISKA BESLUT

### Analytics
- `results` + `events` ar grunddata (source of truth)
- `rider_merge_map` hanterar dubbletter
- GDPR: Publika insikter visar EJ segment < 10 deltagare
- Chart.js for visualiseringar

### Betalning
- PaymentManager + GatewayInterface-arkitektur
- Manuell Swish forst, Swish Handel/Stripe senare
- Webhooks for asynkrona callbacks

---

# KRITISK REGEL: INGA VERSIONSPREFIX

**Anvand ALDRIG versionsnummer (V2, V3, V4) i filnamn, konstanter eller kod.**

```php
// FEL
HUB_V2_ROOT, HUB_V3_ROOT, HUB_V3_URL

// RATT
HUB_ROOT, HUB_URL, ROOT_PATH, INCLUDES_PATH
```

---

# CHANGELOG

### 2026-01-14 (Phase 2)
- Steg 11 KLAR: Analytics Phase 2 - Avancerade moduler
  - AnalyticsConfig.php - Central konfigurationsfil
  - Cohort Analysis - Retention per kohort med trend
  - At-Risk Prediction - 6-faktor riskmodell
  - Geographic Analysis - Regional statistik
  - Feeder Trends - Historisk serie-flodesdata
  - 5 nya databastabeller
  - 20+ nya KPICalculator-metoder
  - Cron-jobb for daglig risk score-uppdatering
  - GDPR-loggning for exporter
- Fixade duplicerad getRetentionTrend()-metod (orsakade vit sida)

### 2026-01-14
- Steg 10 KLAR: Retention & Churn-analys
  - 8 nya metoder i KPICalculator for churn/retention-analys
  - Churned riders-lista (de som slutat)
  - One-timers-lista (1-2 starter totalt)
  - Comeback riders (atervandare efter uppehall)
  - Win-Back Targets (prioriterad kontaktlista)
  - Inaktivitetsanalys (hur lange borta?)
  - Churn per segment (alder, disciplin, klubb)
  - 3 nya CSV-exporter (win-back, churned, comebacks)
  - Retention/churn trend-graf med Chart.js
- Steg 8 KLAR: Admin-verktyg
  - Skapade admin/analytics-populate.php (interaktiv AJAX-baserad populate)
  - Skapade admin/analytics-reset.php (rensa analytics-data)
  - Skapade admin/analytics-diagnose.php (jamfor faktisk vs analytics)
  - Skapade admin/analytics-trends.php (trender over flera sasonger med Chart.js)
  - La till Analytics-sektion i admin/tools.php
  - Optimerade calculateYearlyStatsBulk() - en SQL-query istallet for tusentals
  - Fixade refreshAllStatsFast() att rensa cron-runs fore berakning
- Steg 9 KLAR: Rookie-analys
  - La till 7+ nya metoder i KPICalculator.php for rookie-statistik
  - La till "Nya Deltagare" rapporttyp i analytics-reports.php
  - CSV-export med profillankningar for alla rookies
  - Seriefiltrering i rookie-rapporten
  - 5-ars trendgraf med Chart.js
  - Disciplin-specifik rookie-analys
- Fixade statistik-precision:
  - getDisciplineParticipation() - raknar faktiskt deltagande per disciplin
  - getRookieDisciplineParticipation() - samma for rookies
  - Demographics-rapporten visar nu BADE primary_discipline OCH faktiskt deltagande
  - Loste problemet dar Dual Slalom visade 10 ist for 43 deltagare
- Fixade buggar:
  - Tog bort referens till icke-existerande total_starts-kolumn
  - Forbattrad felhantering visar faktiska exception-meddelanden
- Sammanslog ROADMAP.md och ROADMAP-2026.md till en fil

### 2026-01-13
- Analytics Platform startat
- Steg 0-7 KLAR pa en dag
- Migrationer korda pa produktion
- Admin-navigation uppdaterad med Analytics-grupp

### 2026-01-12
- Betalningssystem inventering
- Tog bort alla V2/V3-prefix fran koden

---

**ANALYTICS PLATFORM KOMPLETT!**
**BETALNINGSSYSTEM REDO FOR LANSERING**
