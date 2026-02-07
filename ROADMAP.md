# TheHUB - Development Roadmap

> Senast uppdaterad: 2026-02-05
>
> **Se:** `/admin/roadmap.php` for interaktiv vy

---

## PROJEKTOMRADEN

| Omrade | Status | Beskrivning | Progress |
|--------|--------|-------------|----------|
| Analytics Platform | KLAR | Statistik, KPI:er, trender, rapporter | 100% |
| Betalningssystem | KLAR | Swish, Stripe, ordrar, checkout, email | 100% |
| Event Ratings | KLAR | Deltagarfeedback pa events | 100% |
| Win-Back System | KLAR | Aterengagera churnade deltagare | 100% |
| Klubb RF-Registrering | KLAR | SCF/NCF/DCU-synk och stavningskontroll | 100% |
| Bildbanken | PAGAENDE | AI-analyserade bilder kopplade till profiler | 10% |
| Ridercard Share | PAGAENDE | Statistikkort for Instagram-delning | 5% |

---

# PAGAENDE PROJEKT

## Bildbanken

**Mal:** AI-analyserade bilder kopplade till anvandarprofiler
**Status:** PAGAENDE (10%)
**Startad:** 2026-01

### Koncept

Bildbank dar bilder fran tavlingar automatiskt kopplas till ratt deltagare via:
- Manuell taggning av anvandare
- Automatisk identifiering via nummerplattar (AI/OCR)
- Ansiktsigenkanning (framtida mojlighet)

### Teknisk losning

- **Lagring:** Google Foto (tillfallig losning)
- **Framtida:** Egen lagring eller CDN (Cloudflare R2, AWS S3)
- **AI:** Google Vision API eller liknande for nummerplattsigenkanning

### Steg

- [ ] Definiera databasschema for bildmetadata
- [ ] Skapa admin-granssnitt for bilduppladdning
- [ ] Implementera manuell taggning av bilder
- [ ] Google Foto-integration (lasa bilder)
- [ ] AI/OCR for nummerplattsigenkanning
- [ ] Visa bilder pa anvandarprofiler
- [ ] Galleri-vy per event
- [ ] Sokfunktion for bilder

### Databastabeller (planerade)

```sql
image_library         -- Bildmetadata (url, event_id, photographer)
image_tags            -- Koppling bild <-> rider (rider_id, confidence)
image_bib_detections  -- AI-detekterade nummerplattar
```

---

## Ridercard Share

**Mal:** Generera snygga statistikkort som kan delas pa Instagram
**Status:** PAGAENDE (5%)
**Startad:** 2026-01

### Koncept

Automatiskt genererade "trading cards" med deltagarstatistik:
- Profilbild eller silhuett
- Namn, klubb, alder
- Statistik: starter, segrar, podiums, poang
- Serie-badges
- QR-kod till profil

### Teknisk losning

- **Rendering:** Server-side med PHP GD eller Imagick
- **Alternativ:** HTML-to-image via Puppeteer
- **Format:** 1080x1920 (Instagram Stories), 1080x1080 (Feed)

### Steg

- [ ] Designa kortmall (Figma/sketch)
- [ ] Implementera PHP-baserad bildgenerering
- [ ] Skapa API-endpoint for kortgenerering
- [ ] Lagg till "Dela"-knapp pa profil
- [ ] Stod for olika mallar/teman
- [ ] Sasongskort med hogtryckssiffror
- [ ] Event-specifika kort

### Exempel-data

```json
{
  "rider_name": "Erik Andersson",
  "club": "Lidingo SK",
  "age": 28,
  "stats": {
    "starts": 42,
    "wins": 3,
    "podiums": 12,
    "points": 1847
  },
  "series": ["GES", "GGS"],
  "profile_url": "thehub.gravityseries.se/rider/123"
}
```

---

# AVSLUTADE PROJEKT

## DEL 1: Analytics Platform

**Status:** KLAR (100%)

16 steg implementerade:
- [x] Governance & Identity Foundation
- [x] Databas & Analytics-tabeller
- [x] Analytics Engine
- [x] KPI Dashboard
- [x] Serieflodeanalys
- [x] Rapportgenerator (6 typer)
- [x] Publika Insikter (GDPR)
- [x] Automatisering & Cron
- [x] Admin-verktyg
- [x] Rookie-analys
- [x] Retention & Churn
- [x] Avancerade moduler (Cohort, At-Risk, Geography)
- [x] Production Readiness
- [x] First Season Journey
- [x] Longitudinal Journey
- [x] Event Participation

### Huvudfiler

- `admin/analytics-dashboard.php` - KPI-oversikt
- `admin/analytics-trends.php` - Historiska trender
- `admin/analytics-cohorts.php` - Kohortanalys
- `admin/analytics-atrisk.php` - Riskprediktion
- `admin/analytics-geography.php` - Regional statistik
- `analytics/includes/KPICalculator.php` - 100+ KPI-metoder

---

## DEL 2: Betalningssystem

**Status:** KLAR (100%)

### Implementerat

- [x] Order CRUD och multi-rider ordrar
- [x] Manuell Swish (QR + deeplink)
- [x] Gateway-arkitektur (interface + PaymentManager)
- [x] Checkout-flode
- [x] Mina biljetter/anmalningar
- [x] Webhook databas-hantering (fungerar korrekt)
- [x] Email-bekraftelser (via Resend/SMTP/PHP mail)
- [x] Rabattkoder (discount_codes med full CRUD)
- [x] Gravity ID-rabatter
- [x] Dokumentation (docs/PAYMENT.md)

### Konfigureras vid behov

- [ ] Swish Handel (kraver avtal + certifikat fran bank)
- [ ] Stripe Connect (kraver Stripe-konto + API-nycklar)

### Huvudfiler

- `includes/payment.php` - Order CRUD, rabattkoder
- `includes/payment/PaymentManager.php` - Central payment hub
- `includes/payment/gateways/*.php` - Gateway-implementationer
- `includes/mail.php` - Email med payment_confirmation-mall
- `admin/orders.php` - Orderhantering
- `admin/discount-codes.php` - Rabattkodshantering
- `pages/checkout.php` - Checkout UI
- `api/webhooks/*.php` - Swish/Stripe webhooks
- `docs/PAYMENT.md` - Full dokumentation

---

## DEL 3: Event Ratings

**Status:** KLAR (100%)

- [x] 10 standardfragor + overgripande betyg
- [x] Anonym feedback
- [x] Tidsfonstrer (30 dagar efter event)
- [x] Profil-integration
- [x] Admin-rapport med aggregerade betyg

### Huvudfiler

- `pages/profile/event-ratings.php` - Betygsformular
- `admin/analytics-event-ratings.php` - Rapport

---

## DEL 4: Win-Back System

**Status:** KLAR (100%)

- [x] Identifiera churnade deltagare
- [x] Kampanjhantering med varumarkesfilter
- [x] Enkatsystem (flerval, skala, fritext)
- [x] Automatisk rabattkod vid avslutat
- [x] Email-inbjudningar
- [x] Promotor access control
- [x] Win-Back Analytics

### Huvudfiler

- `admin/winback-campaigns.php` - Kampanjhantering
- `admin/winback-analytics.php` - Dataanalys
- `pages/profile/winback-survey.php` - Enkat

---

## DEL 5: Klubb RF-Registrering

**Status:** KLAR (100%)

- [x] 400+ SCF-klubbar
- [x] 385 NCF-klubbar (Norge)
- [x] 290 DCU-klubbar (Danmark)
- [x] Automatisk matchning
- [x] Stavningskontroll mot register
- [x] RF-badges pa profiler

### Huvudfiler

- `admin/club-rf-registration.php` - Synkverktyg
- `admin/club-rf-spelling.php` - Stavningskontroll

---

# TEKNISKA BESLUT

### Databas
- `results` + `events` ar source of truth
- `rider_merge_map` hanterar dubbletter
- Pre-beraknad statistik i `rider_yearly_stats`

### GDPR
- Publika insikter visar EJ segment < 10 deltagare
- Export-loggning med `analytics_exports`

### Betalning
- PaymentManager + GatewayInterface-arkitektur
- Manuell Swish forst, Swish Handel/Stripe senare

### Visualisering
- Chart.js for grafer
- SVGChartRenderer for PDF-export

---

# CHANGELOG

### 2026-02-05 (User Accounts System)
- **Branch:** claude/create-event-import-tool-MHtMW

- **Ny funktion: Anvandarkonton (user_accounts)**
  - Separerar anvandaridentitet fran rider-profiler
  - Ny `user_accounts` tabell med e-post, losenord, tokens, status
  - Ny `user_account_id` FK-kolumn pa riders-tabellen
  - Explicit koppling istallet for implicit e-post-baserad gruppering

- **Admin-verktyg: Anvandarkonton** (`admin/tools/user-accounts.php`)
  - Oversikt med statistik: konton, kopplade profiler, legacy-grupper
  - Migrera befintlig data: skapar user_accounts fran befintliga e-postgrupper
  - Splittra profiler: bryt ut en rider till eget konto
  - Flytta profiler: flytta en rider till annat konto
  - Sammanfoga konton: kombinera tva konton till ett
  - Konsolidera legacy: fixa linked_to_rider_id for aldre konton
  - Filtrera och sok bland alla konton
  - Detaljvy med alla kopplade profiler per konto

- **Nya filer:**
  - `Tools/migrations/035_user_accounts.sql` - Databasmigration
  - `admin/tools/user-accounts.php` - Hanteringsverktyg

- **Uppdaterade filer:**
  - `admin/tools.php` - Lank till nya verktyget under Klubbar & Akare
  - `admin/migrations.php` - Registrerad migration 035
  - `config.php` - APP_BUILD uppdaterad till 2026-02-05

### 2026-02-03 (Event Creation Tool)
- **Branch:** claude/create-event-import-tool-MHtMW

- **Ny funktion: Multi-row Event Creation Tool**
  - Skapa upp till 10 events samtidigt med snabb formulär
  - Dropdowns för bana, serie, disciplin och arrangör
  - Möjlighet att skapa nya banor direkt i verktyget
  - Automatisk generering av advent_id
  - Stöd för att koppla events till series_events junction table
  - Prioritering av RF-registrerade klubbar i arrangörslistan

- **Nya filer:**
  - `admin/create-events.php` - Multi-row event creation tool

- **Uppdaterade filer:**
  - `admin/tools.php` - Länk till nya verktyget under Import & Resultat

### 2026-02-03 (Event Extended Fields)
- **Branch:** claude/create-event-import-tool-MHtMW

- **Ny funktion: Utökade eventfält**
  - **Event-logga:** Events utan serie kan nu ha egen logga från mediaarkivet
  - **Flerdagars-event:** Stöd för slutdatum (end_date) för festivaler och etapplopp
  - **Multi-format:** Events kan innehålla flera discipliner (ENDURO, DH, XC, etc.)
  - **Eventtyp:** Ny typ-klassificering (single, festival, stage_race, multi_event)

- **Kalenderförbättringar:**
  - Visar event-logga om ingen serie-logga finns
  - Visar datumintervall för flerdagars-event (t.ex. "5-7 Jun")
  - Festival-badge för multi-format events

- **Nya filer:**
  - `Tools/migrations/034_event_extended_fields.sql` - Databasmigration

- **Uppdaterade filer:**
  - `admin/event-edit.php` - Nya formulärfält för logo, slutdatum, multi-format
  - `pages/calendar/index.php` - Visar event-logga och datumintervall
  - `assets/css/pages/calendar-index.css` - Festival badge styling
  - `admin/migrations.php` - Registrerat migration 034

### 2026-02-01 (Multi-Seller Betalningssystem & Aterbetalningar)
- **Branch:** claude/complete-payment-system-VH54k

- **Ny funktion: Multi-Seller Payment System (Stripe Connect Recipient Model)**
  - Alla betalningar gar till plattformens Stripe-konto
  - Automatiska transfers till saljare efter lyckad betalning
  - Stod for orders med flera saljare (split payments)
  - Transfer tracking i databasen
  - Veckorapporter till saljare

- **Ny funktion: Aterbetalningssystem med Transfer-aterforing**
  - Automatisk aterforing av saljartransfers vid refund
  - Stod for full och partial refunds
  - Admin-granssnitt for aterbetalningar
  - Webhook-hantering for Stripe refunds (aven fran Dashboard)
  - Retry-funktion for misslyckade transfer-aterforingar

- **Aterbetalningspolicy (baserat pa Allmanna Villkor):**
  - Ingen angerratt for idrottsevenemang (lag om distansavtal)
  - Aterbetalning sker endast om Arrangoren godkanner
  - Plattformen processar aterbetalningen tekniskt
  - Plattformen bar risken for chargebacks (negativ balans)

- **Nya filer:**
  - `Tools/migrations/031_order_transfers.sql` - Transfers, refunds, reversals
  - `includes/refund-manager.php` - RefundManager med transfer-aterforing
  - `admin/process-refunds.php` - Admin-sida for aterbetalningar

- **Nya databastabeller (migration 031):**
  - `order_transfers` - Sparar transfers till saljare
  - `seller_reports` - Vecko/manadsrapporter per saljare
  - `seller_report_items` - Detaljerade rader i rapporter
  - `order_refunds` - Sparar aterbetalningar
  - `transfer_reversals` - Sparar aterforingar fran saljare

- **Uppdaterade filer:**
  - `includes/payment/StripeClient.php` - createTransfer(), createTransferReversal(), listTransfers()
  - `api/webhooks/stripe-webhook.php` - Auto-transfers, refund med transfer-aterforing
  - `includes/receipt-manager.php` - Multi-seller kvitton, veckorapporter
  - `admin/migrations.php` - Migration check for 031
  - `admin/tools.php` - Lank till process-refunds.php

### 2026-01-29 (Mina Kop & Kvittosystem)
- **Branch: claude/complete-payment-system-VH54k**

- **Ny funktion: Kombinerad Mina Kop-sida**
  - Slog ihop /profile/registrations och /profile/receipts till en sida
  - Tab-baserat UI: Kommande anmalningar + Kophistorik
  - Varje kop visar saljarens namn och org.nr
  - Detaljerad kvittovisning med momsspecifikation
  - Uppdaterade alla lankar i navigationen

- **Ny funktion: Automatisk kvittogenerering**
  - Kvitton skapas automatiskt vid betalning (Stripe webhook)
  - Kvitton skapas vid manuell betalningsbekraftelse
  - Momsberakning: 6% (sport), 12% (mat), 25% (ovrigt)
  - Saljarens uppgifter hamtas fran payment_recipients

- **Nya filer:**
  - `Tools/migrations/028_vat_receipts_multi_recipient.sql` - Moms och kvitto-tabeller
  - `includes/receipt-manager.php` - Kvittoskapande och momsberakning

- **Nya databastabeller (migration 028):**
  - `product_types` - Produkttyper med momssatser
  - `receipts` - Kvittoheader
  - `receipt_items` - Kvittorader med moms
  - `receipt_sequences` - Lopnummer per ar

- **Uppdaterade filer:**
  - `pages/profile/receipts.php` - Helt omskriven till Mina Kop
  - `pages/profile/registrations.php` - Redirect till receipts.php
  - `api/webhooks/stripe-webhook.php` - Kvittogenerering vid betalning
  - `includes/payment.php` - Kvittogenerering i markOrderPaid()
  - `components/header.php` - Uppdaterad lank till Mina Kop
  - `pages/profile/index.php` - Uppdaterade lankar
  - `pages/checkout.php` - Uppdaterad success-lank
  - `admin/migrations.php` - Lagt till migration 028 check

### 2026-01-25 (Medlemskap & Prenumerationer)
- **Branch: claude/complete-payment-system-VH54k**

- **Ny funktion: Stripe Billing for medlemskap**
  - Medlemsplaner med atervommande betalningar
  - Stripe v2 API for subscriptions
  - Customer portal for medlemshantering
  - Prenumerations-webhooks (created, updated, deleted, trial_will_end)
  - Invoice-webhooks (paid, payment_failed)

- **Nya databastabeller:**
  - `membership_plans` - Medlemsplaner
  - `member_subscriptions` - Aktiva prenumerationer
  - `subscription_invoices` - Betalningshistorik
  - `stripe_customers` - Kundkoppling

- **Nya filer:**
  - `Tools/migrations/025_memberships_subscriptions.sql` - Migrering
  - `admin/memberships.php` - Admin-hantering av planer och prenumerationer
  - `api/memberships.php` - API for checkout och portalhantering
  - `pages/membership.php` - Publik medlemssida

- **Uppdaterade filer:**
  - `includes/payment/StripeClient.php` - Subscription-metoder (createCustomer, createSubscription, createBillingPortalSession, etc.)
  - `api/webhooks/stripe-webhook.php` - Prenumerations-webhooks
  - `router.php` - /membership route
  - `admin/tools.php` - Ny sektion "Medlemskap & Betalningar"

### 2026-01-25 (Betalningssystem Komplett)
- **Branch: claude/complete-payment-system-VH54k**

- **Betalningssystem nu 100% klart**
  - Email-bekraftelser vid betalning implementerat
  - Payment confirmation mall i mail.php
  - Automatisk email vid webhook-callback (Swish/Stripe)
  - Automatisk email vid manuell betalningsbekraftelse
  - Verifierat att rabattkodssystem fungerar (discount_codes)
  - Verifierat att webhook-hantering fungerar korrekt

- **Ny dokumentation:**
  - `docs/PAYMENT.md` - Komplett betalningsdokumentation
    - Arkitekturoversikt
    - Databastabeller
    - Gateway-interface
    - API-endpoints
    - Felsokning

- **Uppdaterade filer:**
  - `includes/mail.php` - Ny payment_confirmation mall + hub_send_order_confirmation()
  - `includes/payment.php` - Email-utskick i markOrderPaid()
  - `includes/payment/PaymentManager.php` - Email-utskick i markOrderPaid()
  - `api/webhooks/swish-callback.php` - Email-utskick vid PAID
  - `api/webhooks/stripe-webhook.php` - Email-utskick vid payment_intent.succeeded

### 2026-01-23 (Klassanalys & Events)
- **Branch: claude/participant-analysis-tool-v8luL**

- **Forbattrad klassanalys**
  - Anvander display_name fran classes-tabellen
  - Sorterar klasser enligt sort_order (samma som classes.php)
  - Flyttat klasser-lank fran Konfiguration till Tavlingar

- **Events admin forbattringar**
  - Fixat venue_id i update-event-field.php
  - Fixat event_format validation i bulk-update-events.php
  - Lagt till point_scale_id och venue_id i bulk-update
  - Lagt till e.venue_id i SQL SELECT
  - Andrat platshistorik till att anvanda venues-tabellen

### 2026-01-22 (Roadmap Forbattring)
- **Branch: claude/participant-analysis-tool-v8luL**

- **Forbattrad roadmap-struktur**
  - Omorganiserad ROADMAP.md for battre lasbarhet
  - Lagt till progress-procent per projekt
  - Klickbara projektområden i roadmap.php

- **Nya projekt tillagda:**
  - Bildbanken - AI-analyserade bilder
  - Ridercard Share - Statistikkort for Instagram

### 2026-01-21 (Win-Back Survey System)
- **Branch: claude/participant-analysis-tool-v8luL**

- **Ny funktion: Win-Back Survey System**
  - Identifiering av churnade deltagare
  - Kampanjhantering med varumarkesfilter
  - Enkatsystem med fragor
  - Automatisk rabattkod
  - Email-inbjudningar
  - Svarsstatistik

- **Bugfixar:**
  - Fixat brand-tabell (series_brands istallet for brands)
  - Fixat databasanslutning (KPICalculator)
  - Mobilanpassning edge-to-edge

### 2026-01-19 (PHP 7 Kompatibilitet)
- **Branch: claude/add-event-ratings-dj5ED**

- **KRITISK FIX: PHP 7 Kompatibilitet**
  - Ersatte match() (PHP 8.0+) med array lookups
  - Ersatte arrow functions fn() med traditional functions

### 2026-01-18 (Event Ratings System)
- **Ny funktion: Event Ratings**
  - Deltagare kan betygsatta events
  - 10 standardfragor + overgripande betyg
  - Anonym feedback

### 2026-01-16 (Analytics v3.2)
- **Steg 16 KLAR: Event Participation Analysis**
- **Unified Migration Tool**
- **Nya KPICalculator-metoder**

### 2026-01-14 (Analytics Phase 2)
- **Steg 11 KLAR: Avancerade moduler**
  - Cohort Analysis
  - At-Risk Prediction
  - Geographic Analysis
  - Feeder Trends

### 2026-01-13
- **Analytics Platform startat**
- **Steg 0-7 KLAR pa en dag**

---

**ANALYTICS PLATFORM KOMPLETT!**
**BETALNINGSSYSTEM REDO FOR LANSERING**
