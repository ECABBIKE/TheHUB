# TheHUB - Development Roadmap

> Senast uppdaterad: 2026-02-14
>
> **Se:** `/admin/roadmap.php` for interaktiv vy

---

## PROJEKTOMRADEN

| Omrade | Status | Beskrivning | Progress |
|--------|--------|-------------|----------|
| Analytics Platform | KLAR | Statistik, KPI:er, trender, rapporter | 100% |
| Betalningssystem | KLAR | Stripe (single account, kort), ordrar, checkout. Swedbank Pay planerat. | 100% |
| Event Ratings | KLAR | Deltagarfeedback pa events | 100% |
| Win-Back System | KLAR | Aterengagera churnade deltagare | 100% |
| Klubb RF-Registrering | KLAR | SCF/NCF/DCU-synk och stavningskontroll | 100% |
| Startlistor | KLAR | Admin/promotor startliste-vy med startnr, export, mobilvy | 100% |
| Bildbanken | PAGAENDE | AI-analyserade bilder kopplade till profiler | 10% |
| Ridercard Share | PAGAENDE | Statistikkort for Instagram-delning | 5% |
| CSS/UI Standardisering | PLANERAD | Enhetlig radius och nya tabeller pa alla sidor | 0% |

---

# CHANGELOG

### 2026-02-14 (Stripe Webhook Fix)
- **Branch:** claude/fix-mobile-payment-layout-Kh2Gg

- **Buggfix: Stripe webhook returnerade 404**
  - Stripe Dashboard konfigurerad att skicka till `/api/stripe-webhook.php`
  - Riktig webhook-hanterare lag pa `/api/webhooks/stripe-webhook.php`
  - Skapad proxy-fil `/api/stripe-webhook.php` som inkluderar den riktiga filen
  - Lagt till HTTPS-redirect-undantag i .htaccess for proxy-sökvägen

- **Nya filer:**
  - `api/stripe-webhook.php` - Proxy till riktiga webhook-hanteraren

- **Andrade filer:**
  - `.htaccess` - HTTPS-undantag for webhook proxy
  - `config.php` - APP_BUILD uppdaterad

---

### 2026-02-13 (Event-flikar: Generell info + Faciliteter-flik)
- **Branch:** claude/fix-mobile-payment-layout-Kh2Gg

- **Ny funktion: Generell tavlingsinformation**
  - Ny textruta pa Inbjudan-fliken, visas under inbjudningstexten
  - Nytt databasfalt `general_competition_info` med global/hidden-flaggor
  - Redigerbar i admin event-edit under "Inbjudan"-sektionen

- **Omstrukturering: Faciliteter & Logistik flyttad till egen flik**
  - Alla 12 facility-kategorier (parkering, mat, boende, etc.) har nu en egen "Faciliteter"-flik
  - Fliken visas bara nar nagon kategori har data
  - Inbjudan-fliken visar nu bara inbjudningstext + generell tavlingsinformation

- **Nya filer:**
  - `Tools/migrations/044_event_general_competition_info.sql` - Databasmigration

- **Andrade filer:**
  - `pages/event.php` - Ny flik, ny inforuta, omstrukturerad Inbjudan-flik
  - `admin/event-edit.php` - Nytt textfalt for generell tavlingsinformation
  - `admin/migrations.php` - Registrerad migration 044

---

### 2026-02-13 (Startlistor + forms.css)
- **Branch:** claude/fix-mobile-payment-layout-Kh2Gg

- **Ny funktion: Startliste-sida for admin och promotor**
  - Event-valjare, filtrering per klass/status/sok
  - Basisk vy (kompakt) och utokad vy (alla falt, sidscrollbar)
  - Startnummerhantering: auto-tilldelning per klass + manuell inline-redigering
  - CSV-export i startlisteformat
  - Grupperad per klass med deltagarantal
  - Mobile-first: kortvy pa mobil portrait, tabell pa landscape/desktop
  - Lankad fran admin dashboard, promotor dashboard och admin-tabs

- **Forbattring: Promotor-dashboard mobildesign**
  - Event-knappar i 2x2 grid pa sma skarmar
  - Battre touch-targets (44px min-height)
  - Kompaktare padding och typografi pa mobil

- **Ny CSS: forms.css (ej aktiverad)**
  - Komplett formularstyling klar att aktiveras
  - Inkluderar: labels, inputs, selects, textareas, validering, mobiloptimering
  - Aktiveras genom att lagga till i layout-header.php

- **Nya filer:**
  - `admin/event-startlist.php` - Startliste-sida
  - `assets/css/forms.css` - Global form-styling (ej aktiverad)

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

## CSS/UI Standardisering

**Mal:** Enhetlig radius, tabelldesign och komponentstil pa bade publika och admin-sidor
**Status:** PLANERAD (0%)
**Identifierat:** 2026-02-13

### Problem idag

- **Radius-skillnad:** Publika sidor anvander `--radius-lg` (14px) for `.card`, admin anvander `--radius-md` (10px) for `.admin-card`
- **Pa mobil:** Publika sidor ar redan edge-to-edge (radius: 0) - detta ar korrekt
- **Tabeller:** Behover infora nya/enhetliga tabeller pa alla sidor

### Steg

- [ ] Bestam vilken radius som ska galla overallt (lg eller md)
- [ ] Standardisera `.card` och `.admin-card` till samma radius
- [ ] Infora nya tabeller pa alla publika sidor
- [ ] Infora nya tabeller pa alla admin-sidor
- [ ] Granska och enhetliggora knappar, badges, alerts mellan publik/admin
- [ ] Testa pa mobil (320px) och desktop

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
- Stripe single account (inga Connected Accounts) + manuell Swish QR
- Swedbank Pay forberett (SwebankPayClient.php), vanter pa konto

### Visualisering
- Chart.js for grafer
- SVGChartRenderer for PDF-export

---

# CHANGELOG

### 2026-02-12 (Profilfix, Swish QR, Mobilfix)
- **Branch:** claude/fix-mobile-payment-layout-Kh2Gg

- **Fix: Profil-redirect vid inloggning**
  - Login-queryn saknade profilfalt (birth_year, gender, phone, ice_name, ice_phone)
  - Alla inloggade anvandare redirectades till /profile/edit trots komplett profil
  - Fixat i hub-config.php: lagt till saknade kolumner i rider SELECT

- **Fix: UCI ID i profil-redigering**
  - Anvande felaktigt kolumnnamn `uci_id` istallet for `license_number`
  - Fixat i pages/profile/edit.php

- **Fix: Lankade profiler visade "Aktivera konto"**
  - Sekundara profiler har alltid password=NULL (by design)
  - Kollar nu om linked_to_rider_id pekar pa ett aktiverat konto
  - Fixat i pages/rider.php

- **Fix: Sokmodal doldes bakom header pa mobil**
  - .card har overflow:hidden som klippte position:fixed-modalen
  - Flyttar modalen till document.body vid oppning
  - Fixat for bade enkel- och serieanmalan i pages/event.php

- **Ny funktion: Swish QR-kod pa checkout**
  - Genererar QR-kod fran Swish-djuplanken med chillerlan/php-qrcode
  - Visas ovanfor "Oppna Swish"-knappen for desktop-anvandare
  - SVG-format for skarpt rendering

- **Nya/andrade filer:**
  - `hub-config.php` - Lagt till profilfalt i login-query
  - `pages/profile/edit.php` - Fixat UCI ID kolumnnamn
  - `pages/rider.php` - Fixat aktiveringstatus for lankade profiler
  - `pages/event.php` - Flyttar sokmodal till body pa mobil
  - `pages/checkout.php` - Swish QR-kod med php-qrcode

### 2026-02-12 (Enkel Swish + Buggfix: undefined klass)
- **Branch:** claude/fix-mobile-payment-layout-Kh2Gg

- **Ny funktion: Enkel Swish-betalning**
  - Nytt betalningsalternativ pa checkout-sidan: Swish (paralellt med kort)
  - Fast Swish-nummer (konfigureras via SWISH_NUMBER i .env)
  - Visar betalningsdetaljer: nummer, belopp, ordernummer som meddelande
  - Mobilanpassad djuplank till Swish-appen
  - "Jag har Swishat"-knapp markerar order for manuell bekraftelse
  - Admin bekraftar Swish-betalningar i befintlig orderhantering
  - Temporart - tas bort nar Swedbank Pay ar aktivt

- **Buggfix: "Undefined" klassnamn i serieanmalan**
  - Serieanmalningsflodets JS-kod saknade felhantering for API-svar
  - Om rider inte matchade nagon klass visades "undefined" istallet for felmeddelande
  - Lagt till error handling i seriesLoadEligibleClasses() (inkomplett profil, inga klasser)
  - Fallback-namn ("Klass X") om klassnamn saknas i databasen
  - Fixat i bade PHP-backend och JavaScript-rendering

- **Nya/andrade filer:**
  - `pages/checkout.php` - Swish betalningssektion tillagd
  - `api/orders.php` - Ny action: claim_swish
  - `.env.example` - SWISH_NUMBER, SWISH_PAYEE_NAME
  - `includes/mail.php` - Swish i betalningsmetod-namn
  - `pages/profile/receipts.php` - Swish-ikon i kvittovisning
  - `pages/event.php` - Felhantering for klasser i serieanmalan
  - `includes/order-manager.php` - Fallback-namn for klasser
  - `includes/series-registration.php` - Fallback-namn for klasser

### 2026-02-12 (Rensning: Swish, Promotor-Stripe, Betalningsmottagare)
- **Branch:** claude/fix-mobile-payment-layout-Kh2Gg

- **Borttaget: Swish-betalning (manuell QR/djuplank)**
  - Swish-UI borttagen fran checkout
  - SwishClient.php, SwishGateway.php, ManualGateway.php arkiverade
  - swish-callback.php arkiverad
  - Swish-falt borttagna fran promotor-panel och serie-redigering

- **Borttaget: Stripe Connect (promotor-kopplat)**
  - payment-recipients.php, promotor-stripe.php, stripe-connect.php arkiverade
  - Connected account-metoder borttagna fran StripeClient.php
  - Destination charges och multi-seller transfers borttagna
  - debug-swish.php, gateway-settings.php, payment-settings.php, event-payment.php arkiverade

- **Borttaget: Betalningsmottagare per event/serie**
  - Betalningsmottagare-dropdown borttagen fran event-edit och series-manage
  - Payment recipient-filter borttagen fran ordrar
  - Ekonomi-dashboard rensat fran Stripe Connect-statistik

- **Kvar: Stripe single account (kortbetalning)**
  - Alla betalningar gar via plattformens enda Stripe-konto
  - Checkout, ordrar, rabattkoder, prenumerationer fungerar som vanligt

- **Arkiverade filer:**
  - `admin/_archived/payment-cleanup-2026/` - Admin-sidor
  - `includes/payment/_archived/` - Swish gateways och klient
  - `api/_archived/` - API-endpoints

- **Uppdaterad dokumentation:**
  - `docs/PAYMENT.md` - Helt omskriven for kort-only
  - `ROADMAP.md` - Uppdaterad med rensnings-changelog
  - `CLAUDE.md` - Uppdaterat databas-schema
  - Navigations-konfiguration rensad (admin-tabs, economy-tabs, .htaccess)

### 2026-02-10 (Betalningssystem 2.0 - Forenkling)
- **Branch:** claude/review-payment-system-vUJyF

- **Stripe: Single Account (bort med Connected Accounts)**
  - Borttagen all Stripe Connect-logik (destination charges, transfers, on_behalf_of)
  - `create-checkout-session.php` skapar nu enkel direkt charge till plattformskontot
  - `stripe-webhook.php` skapar inte langre transfers efter betalning
  - `StripeGateway.php` forenkad - kollar bara om STRIPE_SECRET_KEY finns
  - `PaymentManager.php` registrerar inte langre SwishGateway (API-baserad)

- **Manuell Swish QR-kod aterinfors**
  - Checkout-sidan visar nu BADE kortbetalning och Swish QR-kod (inte elseif)
  - QR-kod genereras automatiskt fran payment_recipients.swish_number
  - Swish deep-link for mobil ("Oppna Swish-appen")
  - Swish-nummer, belopp och orderreferens visas tydligt
  - Manuell avstamning av arrangoren i admin/orders.php

- **Bevarade filer (ej borttagna)**
  - `SwebankPayClient.php` - Forberedd for Swedbank Pay (vanter pa konto, ~14 dagar)
  - `SwishClient.php` - Swish API-klient (sparad for framtida bruk)
  - `SwishGateway.php` - Swish Handel-gateway (ej aktiv men bevarad)

- **Forenklade refunds**
  - Webhook charge.refunded hanterar nu bara order-uppdatering
  - Borttagen transfer reversal-logik (inga transfers att reversera)

- **Uppdaterad dokumentation**
  - `docs/PAYMENT.md` v4.0.0 - Nytt flode med dual payment options
  - `ROADMAP.md` - Changelog och statusuppdatering

- **Andrade filer:**
  - `api/create-checkout-session.php` - Borttagen Connected Account/destination charge
  - `api/webhooks/stripe-webhook.php` - Borttagen createOrderTransfers + transfer reversal
  - `includes/payment/PaymentManager.php` - Forenkad gateway-registrering
  - `includes/payment/gateways/StripeGateway.php` - Forenkad for single account
  - `includes/payment.php` - getOrder() inkluderar nu swish_number
  - `pages/checkout.php` - Dual payment: kort + Swish QR
  - `docs/PAYMENT.md` - v4.0.0
  - `config.php` - APP_BUILD 2026-02-10

- **Kritiska bugfixar (Session 2)**
  - Webhook 302 redirect: Lade till HUB_API_REQUEST bypass i config.php (skip HTTPS redirect, session, headers for webhooks)
  - Admin orders blank page: Andrade catch(Exception) till catch(Throwable) i markOrderPaid() och admin/orders.php
  - Webhook crash: Wrappade series_registrations update i egen try/catch (tabell kanske inte finns)
  - Checkout-loop: Fixat oandlig redirect vid stripe_success

- **SCF Licenskontroll: Namnbaserad fallback**
  - verifyRiderLicenseIfNeeded() provar nu forst UCI ID-sokning, sedan namnbaserad sokning via SCF API
  - Stodjer lookupByName() med fornamn, efternamn, kon, fodelsear

- **Serieanmalan: Fixat sokning**
  - Rättade felaktig API action (get_eligible_classes -> event_classes)
  - Fixade typjamforelse vid rider-val (=== -> == for string/number)

- **Kvitto-mail med momsredovisning**
  - Nytt receipt email-template i mail.php med fullstandig momsspecifikation
  - hub_send_receipt_email() skickar kvitto med rader, moms per sats, saljare och org.nr
  - Ersatter generisk betalningsbekraftelse nar kvitto skapats
  - Webhook och markOrderPaid() skickar nu kvitto-mail

- **Kolumnjustering pa event-sidan**
  - Anmalda-tabellerna anvander nu table-layout:fixed med colgroup for identiska kolumnbredder
  - Overflow-hantering pa namn- och klubb-kolumner

- **Andrade filer (Session 2):**
  - `api/webhooks/stripe-webhook.php` - HUB_API_REQUEST + receipt email + Throwable
  - `config.php` - Bypass redirect/session for API requests
  - `includes/payment.php` - Throwable catches + receipt email
  - `includes/mail.php` - receipt template + hub_send_receipt_email()
  - `includes/registration-validator.php` - Name-based SCF license lookup
  - `admin/orders.php` - Throwable catch
  - `pages/event.php` - Table alignment + series search fixes

### 2026-02-07 (Events/Series Sync & Betalningsfix)
- **Branch:** claude/fix-events-payments-rAxgE

- **Buggfix: Events hamnar nu i ratt serier**
  - Fixat `admin/api/update-event-series.php` - synkar nu bade `events.series_id` OCH `series_events` junction-tabellen
  - Events som tilldelas serie via admin-tabellen syns nu korrekt i serievyer och publika sidor

- **Buggfix: Betalningar kraschar inte langre**
  - Fixat `includes/payment.php` `getPaymentConfig()` - legacy fallback-queries (payment_configs) ar nu wrappade i try/catch
  - Lagt till sok via `series_events` junction-tabellen for betalningsmottagare (inte bara events.series_id)
  - `createSeriesOrder()` kontrollerar nu korrekt om Stripe/kort finns tillgangligt
  - Checkout-sidan visar nu tydligt meddelande om ingen betalningsmetod ar konfigurerad

- **UI-fix: Admin serie-hantering**
  - Knappar pa `/admin/series` - "Hantera" och "Radera" ar nu proportionerliga med Lucide-ikoner + text
  - Events-tab: Poangmall sparas nu automatiskt vid val (onchange auto-submit)
  - Events-tab: Spara- och ta bort-knappar har nu synliga text-etiketter
  - Registration-tab: Sparfunktion for anmalningsdatum fungerar nu (hanterar saknade kolumner)

- **Ny migration: 036_event_registration_opens.sql**
  - Lagger till `registration_opens` (DATETIME) pa events-tabellen
  - Lagger till `registration_deadline_time` (TIME) for tidkomponent

- **Buggfix: Anmalningsdatum sparas nu korrekt**
  - Tagit bort PRG-redirect som orsakade att data forsvann vid sparning
  - Fixat kolumndetektion: anvander fetchAll() istallet for opaalitligt rowCount()
  - Hanterare fungerar nu som ovriga tabs (save_info, save_payment) - inline meddelande
  - Diagnostisk loggning tillagd for felsokning

- **Ny funktion: Sasongspriser per klass i prismallar**
  - Ny kolumn `season_price` i pricing_template_rules
  - Admins kan nu satta ett fast sasongspris for varje klass i prismallen
  - Sasongskolumn visas automatiskt nar migration 037 ar kord
  - Sparas tillsammans med eventpriser i samma formular

- **Ny migration: 037_pricing_template_season_price.sql**
  - Lagger till `season_price` (DECIMAL) pa pricing_template_rules

- **Stripe-only betalningssystem**
  - Alla betalningar gar nu via Stripe Checkout (manuell Swish borttagen)
  - Ny API-endpoint: `/api/create-checkout-session.php` - skapar Stripe Checkout Session
  - Checkout-sidan (`/pages/checkout.php`) visar "Betala X kr"-knapp som redirectar till Stripe
  - Automatisk statusuppdatering efter betalning (webhook + auto-refresh)
  - `includes/payment.php` - orders skapas med payment_method='card'
  - payment_recipient_id lagras pa order_items for Stripe Connect-transfers

- **Admin: Ekonomi-sida rensad**
  - Borttagna dubblettlankar (Stripe-installningar redirectade till samma sida)
  - Visar varning om STRIPE_SECRET_KEY saknas
  - Betalningsmottagare-modal: Swish/bankfalt borttagna, kontaktinfo kvar
  - Kort visar Stripe Connect-status (aktiv/vantar/ej kopplad)

- **Ny migration: 038_orders_stripe_session.sql**
  - Lagger till `stripe_session_id` och `stripe_payment_intent_id` pa orders

- **Testevent-verktyg**
  - Nytt adminverktyg: `/admin/tools/create-test-event.php`
  - Skapar "TEST 2026" med prissattning, seriekoppling och checklista
  - Lankat fran System-sektionen i `/admin/tools.php`
  - Visar Stripe-konfigurationsstatus och betalningschecklista

- **Nya/andrade filer:**
  - `admin/api/update-event-series.php` - Fixat series_events-synk
  - `admin/series.php` - Snyggare knappar
  - `admin/series-manage.php` - Fixade knappar, events-query, registration save, PRG borttaget
  - `admin/pricing-templates.php` - Sasongspris-kolumn i redigera-vy
  - `admin/ekonomi.php` - Rensade lankar, Stripe-varning
  - `admin/payment-recipients.php` - Rensat modal, Stripe-fokus
  - `admin/tools/create-test-event.php` - Nytt testverktyg
  - `admin/tools.php` - Lagt till testevent-lanken
  - `api/create-checkout-session.php` - Stripe Checkout API
  - `api/apply-discount.php` - Borttagen Swish-regenerering
  - `includes/payment.php` - Stripe-only, borttagen Swish-kod
  - `pages/checkout.php` - Stripe Checkout UI
  - `.env.example` - Stripe-nycklar dokumenterade
  - `Tools/migrations/036_event_registration_opens.sql` - Registration dates
  - `Tools/migrations/037_pricing_template_season_price.sql` - Season pricing
  - `Tools/migrations/038_orders_stripe_session.sql` - Stripe session tracking
  - `admin/migrations.php` - Registrerat migration 036, 037, 038

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
