# TheHUB - Development Roadmap 2026

> Senast uppdaterad: 2026-01-14

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

## POTENTIELLA FORBATTRINGAR (ANALYTICS)

### Datakvalitet
- [ ] Verifiera rookie-berakningar mot manuella kontroller
- [ ] Jamfor med forsta-start i results-tabellen
- [ ] Forbattra is_rookie logik for edge-cases

### Funktionalitet
- [x] Lagg till filtrering per serie i rookie-rapporten
- [ ] Lagg till disciplin-specifik rookie-analys
- [ ] Lagg till geografisk analys (var kommer rookies ifran?)
- [x] Lagg till trendanalys for rookies over tid (5 ar med graf)

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

### 2026-01-14
- Steg 8 KLAR: Admin-verktyg
  - Skapade admin/analytics-populate.php (interaktiv AJAX-baserad populate)
  - Skapade admin/analytics-reset.php (rensa analytics-data)
  - Skapade admin/analytics-diagnose.php (jamfor faktisk vs analytics)
  - Skapade admin/analytics-trends.php (trender over flera sasonger med Chart.js)
  - La till Analytics-sektion i admin/tools.php
  - Optimerade calculateYearlyStatsBulk() - en SQL-query istallet for tusentals
  - Fixade refreshAllStatsFast() att rensa cron-runs fore berakning
- Steg 9 KLAR: Rookie-analys
  - La till 7 nya metoder i KPICalculator.php for rookie-statistik
  - La till "Nya Deltagare" rapporttyp i analytics-reports.php
  - CSV-export med profillankningar for alla rookies
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
