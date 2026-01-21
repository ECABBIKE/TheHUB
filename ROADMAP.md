# TheHUB - Development Roadmap

> Senast uppdaterad: 2026-01-21
>
> **OBS:** Uppdatera denna fil efter varje implementerad funktion!
> **Se även:** `/admin/roadmap.php` för en interaktiv vy

---

## PROJEKTOMRADEN

| Omrade | Status | Beskrivning |
|--------|--------|-------------|
| Analytics Platform | KLAR | Statistik, KPI:er, trender, rapporter |
| Betalningssystem | 80% KLAR | Swish, Stripe, ordrar, checkout |
| Event Ratings | KLAR | Deltagarfeedback pa events |
| Win-Back System | KLAR | Återengagera churnade deltagare |

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
| 11 | Avancerade moduler | [x] KLAR | Cohort, At-Risk, Geography |
| 12 | Production Readiness | [x] KLAR | KPI-definitioner, Export logging, Datakvalitet |
| 13 | First Season Journey | [x] KLAR | Rookie-analys, retention predictors |
| 14 | Longitudinal Journey | [x] KLAR | År 1-4 tracking, career patterns |
| 15 | Brand Dimension | [x] KLAR | Multi-brand filtering (1-12 brands) |
| 16 | Event Participation | [x] KLAR | Single-event riders, event loyalty, retention |

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
- [x] Lagg till filtrering per serie i rookie-rapporten (fungerar for ALL data nu)
- [x] Lagg till disciplin-specifik rookie-analys
- [x] Lagg till geografisk analys (analytics-geography.php)
- [x] Lagg till trendanalys for rookies over tid (5 ar med graf)
- [x] Finare aldersgrupper (5-12, 13-14, 15-16, 17-18, etc)
- [x] Karriarvags-analys (feeder pipeline, national-to-regional flow)

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

# DEL 3: KLUBB RF-REGISTRERING

**Mal:** Synkronisera klubbar med officiella förbundsregister (SCF, NCF, DCU)
**Status:** KLAR

## FUNKTIONER

### RF-registreringsverktyg (admin/club-rf-registration.php)
- [x] Komplett lista over alla 20 SCF-distrikt
- [x] ~400 SCF-registrerade klubbar inlagda
- [x] ~385 NCF-klubbar (Norges Cykleforbund)
- [x] ~290 DCU-klubbar (Danmarks Cykle Union)
- [x] En-klicks synkronisering mot TheHUB-klubbar
- [x] Manuell koppling for klubbar som inte matchas automatiskt
- [x] Statistik per distrikt (klubbar, riders)
- [x] Striktare matching-algoritm (langdkvot > 0.7)

### Stavningskontroll (admin/club-rf-spelling.php) - NY!
- [x] Jämför klubbnamn mot officiella SCF/NCF/DCU-register
- [x] Förbundsbadges (SCF blå, NCF röd, DCU gul)
- [x] Bulk-uppdatering av stavningar till officiell version
- [x] Låsning av namn (name_locked) för att förhindra automatiska ändringar
- [x] Statistik: korrekt stavning, avvikande, ej i registret
- [x] Delad datafil för förbundsklubbar

### Klubbprofil-badges
- [x] RF-badge visar "RF-registrerad 2025" for aktiva klubbar
- [x] Distriktsbadge visar SCF-tillhorighet (t.ex. "Stockholms")
- [x] CSS-styling for badges (dark/light mode)

### Databasfalt
- `rf_registered` (TINYINT) - 1 om förbundsregistrerad
- `rf_registered_year` (INT) - Registreringsar
- `scf_district` (VARCHAR) - SCF-distriktstillhorighet
- `federation` (VARCHAR) - Förbund (SCF, NCF, DCU) - NY!
- `name_locked` (TINYINT) - 1 om namn är låst - NY!

### Migrationer
- Migration 112: Återställ klubbnamn från backup (706 klubbar)
- Migration 113: Lägg till name_locked och federation kolumner

### SCF-distrikt inkluderade
1. Bohuslän-Dals Cykelförbund
2. Dalarnas Cykelförbund
3. Gästriklands Cykelförbund
4. Göteborgs Cykelförbund
5. Hallands Cykelförbund
6. Hälsinglands Cykelförbund
7. Jämtland-Härjedalens Cykelförbund
8. Norrbottens Cykelförbund
9. Skånes Cykelförbund
10. Smålands Cykelförbund
11. Stockholms Cykelförbund
12. Södermanlands Cykelförbund
13. Upplands Cykelförbund
14. Värmlands Cykelförbund
15. Västerbottens Cykelförbund
16. Västergötlands Cykelförbund
17. Västernorrlands Cykelförbund
18. Västmanlands Cykelförbund
19. Örebro Läns Cykelförbund
20. Östergötlands Cykelförbund

---

# DEL 4: EVENT RATINGS

**Mal:** Deltagare kan betygsatta events for att ge arrangorerna feedback
**Status:** KLAR

## FUNKTIONER

### Event Rating System
- [x] Deltagare med resultat kan betygsatta events
- [x] 10 standardfragor (banmarkeringar, sakerhet, faciliteter, etc.)
- [x] Overgripande betyg 1-10
- [x] Valfri anonym kommentar
- [x] Tidsfonstrer: 30 dagar efter event
- [x] En rating per deltagare per event

### Profil-integration (pages/profile/event-ratings.php)
- [x] Lista over events att betygsatta
- [x] Formulär med slider for varje fraga
- [x] Oversikt over tidigare betyg
- [x] Quick-link fran Min Sida

### Admin-rapport (admin/analytics-event-ratings.php)
- [x] Aggregerade betyg per event (anonymiserade)
- [x] Genomsnitt per fraga med visuella staplar
- [x] Filtrering per ar och serie
- [x] Sammanfattande statistik

### Databastabeller
- `event_ratings` - Huvudtabell for betyg
- `event_rating_questions` - Fragedefinitioner
- `event_rating_answers` - Svar per fraga
- `v_event_ratings_summary` - Aggregerad vy
- `v_event_question_averages` - Snitt per fraga

### Standardfragor
1. Banmarkeringar och skyltning
2. Banans kvalitet och underhall
3. Sakerhet och sjukvardsberedskap
4. Anmalan och incheckningsprocess
5. Tidtagning och resultathantering
6. Tidsschema och punktlighet
7. Faciliteter (parkering, toaletter, etc)
8. Stamning och upplevelse
9. Varde for pengarna
10. Skulle rekommendera till andra

### Framtida forbattringar
- [ ] Notis till deltagare efter event
- [ ] Visa aggregerade betyg offentligt (nar tillrackligt med data)
- [ ] Export till CSV for arrangor
- [ ] Jamforelse mot serie-snitt

---

# DEL 5: WIN-BACK SURVEY SYSTEM

**Mal:** Aterengagera deltagare som slutat tavla
**Status:** KLAR

## FUNKTIONER

### Participant Analysis (admin/participant-analysis.php)
- [x] Identifiera "churnade" deltagare (tavlade historiskt men ej 2025)
- [x] Identifiera deltagare som ej tavlat i SweCup Enduro
- [x] CSV-export av malgrupper

### Win-Back Campaigns (admin/winback-campaigns.php)
- [x] Kampanjhantering med varumarkesfilter
- [x] Fragor: flerval, enkelval, skala 1-10, fritext
- [x] Fragor kan vara globala eller kampanjspecifika
- [x] Automatisk rabattkod vid avslutad enkat
- [x] Inbjudningssystem via email direkt fran TheHUB
- [x] Kontaktstatistik (har email / saknar email)
- [x] Svarsstatistik och resultatvisning (anonymiserat)

### Promotor Access Control
- [x] Kampanjer kan tilldelas en agare (promotor)
- [x] Promotors kan hantera sina egna kampanjer
- [x] Valfri delning av resultat till alla promotors
- [x] Administratorer kan redigera alla kampanjer

### Survey Form (pages/profile/winback-survey.php)
- [x] Automatisk kvalificeringskontroll
- [x] Anonym enkat (som event ratings)
- [x] Generering av unik rabattkod vid avslutande
- [x] Kopiera rabattkod till urklipp

### Databastabeller
- `winback_campaigns` - Kampanjer med varumarkesfilter
- `winback_questions` - Fragor med typ och alternativ
- `winback_responses` - Enkatsvar
- `winback_answers` - Svar per fraga
- `winback_invitations` - Inbjudningsspårning

### Migrationer
- `021_winback_survey.sql` - Grundtabeller och standardfragor
- `022_winback_invitations.sql` - Inbjudningar och promotor-atkomst

### Framtida forbattringar
- [ ] Automatiska paminelser till deltagare som ej svarat
- [ ] SMS-inbjudningar (integration med SMS-gateway)
- [ ] Tracking av oppnade email
- [ ] Koppling till anmalningssystem for rabattkoder

---

# CHANGELOG

### 2026-01-21 (Win-Back Survey System)
- **Branch: claude/participant-analysis-tool-v8luL**

- **Ny funktion: Win-Back Survey System**
  - Identifiering av churnade deltagare (ej tavlat 2025)
  - Kampanjhantering med varumarkesfilter
  - Enkatsystem med fragor (flerval, enkelval, skala, fritext)
  - Automatisk rabattkod vid avslutad enkat
  - Inbjudningssystem via email direkt fran TheHUB
  - Kontaktstatistik (har email / saknar email)
  - Svarsstatistik och resultatvisning

- **Promotor Access Control**
  - Kampanjer kan tilldelas agare (promotor)
  - Promotors kan hantera sina kampanjer
  - Valfri delning av resultat till alla promotors

- **Nya filer:**
  - `admin/participant-analysis.php` - Enkel analysvy
  - `admin/winback-campaigns.php` - Kampanjhantering
  - `pages/profile/winback-survey.php` - Enkatformular
  - `admin/roadmap.php` - Interaktiv roadmap-vy
  - `Tools/migrations/021_winback_survey.sql`
  - `Tools/migrations/022_winback_invitations.sql`

- **Uppdaterade filer:**
  - `admin/migrations.php` - Lagt till auto-detektion for nya migrationer
  - `admin/tools.php` - Lankar till nya verktyg
  - `ROADMAP.md` - Dokumentation
  - `CLAUDE.md` - Instruktion om roadmap-uppdatering

### 2026-01-19 (PHP 7 Kompatibilitet & Bugfixar)
- **Branch: claude/add-event-ratings-dj5ED**

- **KRITISK FIX: PHP 7 Kompatibilitet**
  - Servern kör PHP 7.x, inte PHP 8.0+
  - Ersatte alla `match()` expressions (PHP 8.0+) med array lookups:
    - `admin/components/unified-layout.php` (2 st)
    - `includes/layout-header.php` (favicon MIME)
  - Ersatte arrow functions `fn()` (PHP 7.4+) med traditional functions:
    - `pages/news/index.php`
    - `pages/profile/event-ratings.php`

- **FIX: Saknad funktion i auth.php**
  - `news-moderation.php` anropade `requireAdmin()` som inte fanns
  - Lade till `requireAdmin()` som alias för `requireLogin()`
  - Fixade anropet i `news-moderation.php`

- **FIX: Event-ratings route saknades**
  - Lade till `'event-ratings' => '/pages/profile/event-ratings.php'` i router.php

- **FIX: Fel ikon för Nyheter i mobil-navigation**
  - `mobile-nav.php` saknade `'news' => 'newspaper'` i icon-mappningen
  - Föll tillbaka till 'info'-ikonen istället för 'newspaper'

- **FIX: News-sidan mobilanpassning**
  - Lade till `.news-filter-bar` i edge-to-edge CSS (per Claude.md)

- **Filer ändrade:**
  - `admin/components/unified-layout.php`
  - `includes/layout-header.php`
  - `includes/auth.php`
  - `admin/news-moderation.php`
  - `pages/news/index.php`
  - `pages/profile/event-ratings.php`
  - `router.php`
  - `components/mobile-nav.php`
  - `assets/css/pages/news.css`

### 2026-01-18 (Event Ratings System)
- **Ny funktion: Event Ratings**
  - Deltagare kan betygsatta events de deltagit i
  - 10 standardfragor + overgripande betyg + kommentar
  - Anonym feedback for arrangorerna
  - Migration: `Tools/migrations/016_event_ratings.sql`
  - Profil-sida: `pages/profile/event-ratings.php`
  - Admin-rapport: `admin/analytics-event-ratings.php`
  - Quick-link tillagd pa Min Sida

### 2026-01-18 (Analytics Consistency & Data Fixes)
- **KRITISK FIX: Korrekt brands-tabell**
  - KPICalculator använde fel tabell (`brands` istället för `series_brands`)
  - Orsakade att felaktiga varumärken ("Svenska Cykelförbundet", "GravitySeries") visades
  - Fixat alla JOINs och SELECTs till `series_brands`

- **KRITISK FIX: Rookie-rapporter använder faktiskt deltagande**
  - Tidigare: Filtrerade på `primary_series_id` (bara riders vars huvudserie matchade)
  - Nu: Filtrerar på faktiskt deltagande via `results`-tabellen
  - Fixade metoder:
    - `getRookiesList()` - Komplett refactoring
    - `getRookieAgeDistribution()` - Ny subquery-filter
    - `getRookieAverageAge()` - Ny subquery-filter
    - `getRookieGenderDistribution()` - Ny subquery-filter
    - `getClubsWithMostRookies()` - Ny subquery-filter

- **Ny metod: getSeriesParticipants()**
  - Räknar unika deltagare i en serie via results-tabellen
  - Används för att visa korrekt "Totalt aktiva" när serie är vald

- **Fix: Cross-Participation NULL handling**
  - Ändrat från `s2.brand_id != ?` till `(s2.brand_id IS NULL OR s2.brand_id != ?)`
  - NULL != X returnerar NULL i SQL, inte true
  - Nu räknas serier utan brand korrekt som "andra varumärken"

- **Fix: Rookies-rapport total counts**
  - `total_rookies` beräknas nu från filtrerad lista
  - `total_riders` använder `getSeriesParticipants()` när serie vald

- **Resultat: Konsistenta siffror**
  - Dashboard och Rookies-rapport visar nu samma antal vid samma filter
  - Exempel: Swecup Enduro 2025 visar nu korrekt 143 rookies på båda ställen

### 2026-01-16 (Analytics v3.2 - Event Participation)
- **Steg 16 KLAR: Event Participation Analysis**
  - Migration: `Tools/migrations/012_event_participation_analysis.sql`
  - Admin UI: `admin/analytics-event-participation.php`
  - 4 vyer: Distribution, Unique, Retention, Loyalty
  - Brand filtering support (1-12 brands)
  - GDPR-kompatibelt (min 10 individer per segment)

- **Unified Migration Tool**
  - ETT verktyg: `/admin/migrations.php`
  - Auto-detektion av körda migrationer
  - Mobilanpassat
  - Alla gamla migrationsverktyg arkiverade (50+ filer)

- **Analytics Dashboard Navigation**
  - Ny navigation grid med alla analytics-moduler
  - Snabbnavigering mellan alla analys-sidor

- **Nya filer:**
  - `admin/migrations.php` - Unified migration tool
  - `admin/analytics-event-participation.php` - Event Participation UI
  - `api/analytics/event-participation-export.php` - Export API
  - `Tools/migrations/012_event_participation_analysis.sql`

- **Nya KPICalculator-metoder:**
  - `getSeriesParticipationDistribution()` - Distribution per serie
  - `getEventsWithUniqueParticipants()` - Event med unika deltagare
  - `getEventRetention()` - År-till-år event retention
  - `getEventLoyalRiders()` - Multi-year same-event deltagare

### 2026-01-16 (Analytics v3.1 - Journey Analysis)
- **Steg 13-15 KLAR: Journey Analysis + Brand Dimension**
  - First Season Journey: Rookie-analys och retention predictors
  - Longitudinal Journey: År 1-4 tracking
  - Brand Dimension: Multi-brand filtering
  - Migrations: 009-011 i `Tools/migrations/`

### 2026-01-16 (Analytics Production Readiness)
- **Steg 12 KLAR: Production Readiness Improvements**
  - Baserat pa extern granskning for SCF-nivå-rapportering

- **Prio 1 - Korrekthet & Exporter:**
  - Tydliggjorda KPI-definitioner i AnalyticsConfig.php
    - `retention_from_prev` vs `returning_share_of_current`
    - Dokumenterade formler och skillnader
  - Snapshot v2: Nya kolumner for reproducerbarhet
    - `generated_at`, `season_year`, `source_max_updated_at`, `code_version`
    - `data_fingerprint` for verifiering
  - Export logging med GDPR-sparbarhet
    - Ny tabell `analytics_exports`
    - Manifest med fingerprint
    - Ny klass `ExportLogger.php`
  - KPI-definitionstabell `analytics_kpi_definitions`

- **Prio 2 - At-Risk & Klasslogik:**
  - CLASS_RANKING_BY_YEAR med ars-versionering
  - Fallback for okanda klasser (ignorerar class_downgrade)
  - Ny metod `isClassDowngrade()` i AnalyticsConfig
  - Dynamisk serie-cutoff baserat pa `last_event_date`
  - Konfigurerbart via `USE_DYNAMIC_SERIES_CUTOFF`

- **Prio 3 - Skalbarhet:**
  - Datakvalitetspanel: `admin/analytics-data-quality.php`
    - Matning av coverage per falt
    - Potentiella dubbletter
    - Rekommendationer och troskelvarningar
  - Ny tabell `data_quality_metrics`
  - SVG ChartRenderer for PDF-export utan Node.js
    - Line, Bar, Donut, Sparkline, Stacked Bar
    - TheHUB designsystem-farger

- **Nya metoder i KPICalculator:**
  - `getReturningShareOfCurrent()` - Alternativ retention-metric
  - `getRookieRate()` - Andel nya deltagare
  - `getRetentionMetrics()` - Alla retention-KPIs samlat
  - `getDataQualityMetrics()` - Datakvalitetsmatning
  - `saveDataQualityMetrics()` - Spara till databas

- **Nya filer:**
  - `analytics/includes/AnalyticsConfig.php` - Utokad v3.0
  - `analytics/includes/SVGChartRenderer.php`
  - `analytics/includes/ExportLogger.php`
  - `analytics/migrations/006_production_readiness.sql`
  - `admin/analytics-data-quality.php`
  - `api/analytics/save-quality-metrics.php`

- **Backup:**
  - Full backup av analytics skapad i `backups/analytics-2026-01-16/`

### 2026-01-16 (Förbundets Stavningskontroll)
- **Nytt verktyg: club-rf-spelling.php**
  - Jämför klubbnamn mot officiella SCF/NCF/DCU-register
  - Visar förbundsbadges (SCF blå, NCF röd, DCU gul)
  - Bulk-uppdatering av stavningar till officiell version
  - Låsning av namn för att förhindra automatiska ändringar
  - Statistik: korrekt stavning, avvikande, ej i registret

- **Nya migrationer:**
  - Migration 112: Återställ 706 klubbnamn från backup
  - Migration 113: Lägg till name_locked och federation kolumner

- **Nya filer:**
  - `admin/club-rf-spelling.php` - Stavningskontroll
  - `admin/includes/federation-clubs-data.php` - Delad data för förbund
  - `admin/migrations/112_restore_club_names.php`
  - `admin/migrations/113_add_name_locked.php`

- **Bugfix:**
  - Striktare matching-algoritm (längdkvot > 0.7) för att undvika felaktiga namnändringar

### 2026-01-14 (RF-registrering)
- **Ny funktion: RF-klubbregistrering**
  - Skapade admin/club-rf-registration.php
  - Alla 20 SCF-distrikt med ~400 klubbar
  - RF-badge pa klubbprofiler for registrerade klubbar
  - Distriktsbadge visar SCF-tillhorighet
  - Manuell koppling for omatchade klubbar

### 2026-01-14 (Phase 2 - Fortsattning)
- **Aldersfordelning uppdelad i finare grupper:**
  - Tidigare: Under 18, 18-25, 26-35, 36-45, 46-55, Over 55
  - Nu: 5-12, 13-14, 15-16, 17-18, 19-30, 31-35, 36-45, 46-50, 50+
  - Uppdaterat i getAgeDistribution(), getRookieAgeDistribution(), getChurnBySegment()

- **Serie-filter fungerar nu for ALL rookie-data:**
  - Tidigare: Serie-filter paverkade bara listan, inte statistiken
  - Nu: Seriefilter appliceras pa alla metoder:
    - getRookieAgeDistribution(), getRookieClassDistribution()
    - getRookieGenderDistribution(), getRookieAverageAge()
    - getRookieDisciplineParticipation()
    - getEventsWithMostRookies(), getClubsWithMostRookies()

- **Skapade analytics-clubs.php:**
  - Saknades (404) trots lankar i navigation
  - Visar: Top klubbar, aktiva riders, rookies per klubb

- **Nya KPICalculator-metoder for karriarvagar:**
  - getFeederPipeline() - Regionala starters som gar nationellt
  - getNationalToRegionalFlow() - Nationella som provar regionalt
  - getCareerPathsAnalysis() - Karriarvag-kategorisering
  - getFirstRaceEntryPoints() - Forsta tilling instegspunkter
  - getGraduationTrend() - Arlig "graduation" till nationella serier

- **Fixade PDO-parameterproblem:**
  - Konverterade alla duplicerade named params till positional (?)
  - Paverkade: getComebackRiders(), getInactiveByDuration(), getChurnBySegment()

- **Fixade analytics-geography.php:**
  - max() med tom array kraschade sidan
  - Anvander nu senaste ar med DATA, inte kalendararet
  - Region-data kommer nu fran klubb/rider istallet for serie

- **Fixade cohort-analys 0% aktiva:**
  - Jamforde mot 2026 nar ingen 2026-data finns
  - Nu anvands getLatestSeasonYear() for korrekt jamforelse

- **Andrade analytics-ikon:**
  - Fran bar-chart-3 till chart-line for tydligare distinktion

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
