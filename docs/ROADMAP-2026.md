# TheHUB - Betalningssystem Roadmap 2026

**Senast uppdaterad:** 2026-01-12
**Baserat pa:** Inventering av befintlig kod
**Status:** Redo for implementation
**Mal:** Lansera biljettforsaljning inom 3 veckor

---

## AKTUELL STATUS: VAD SOM FINNS

### Orderhantering
| Komponent | Fil | Status |
|-----------|-----|--------|
| Order CRUD | `includes/payment.php` | Fungerar |
| Order items | `includes/payment.php` | Fungerar |
| Multi-rider ordrar | `includes/order-manager.php` | Fungerar |
| Ordernummer-generering | Tva system (behover konsolideras) | Delvis |
| Admin orderlista | `admin/orders.php` | Fungerar |

### Gateway-arkitektur
| Gateway | Implementation | Status |
|---------|----------------|--------|
| Manuell Swish | `gateways/ManualGateway.php` | **REDO ATT ANVANDA** |
| Swish Handel | `gateways/SwishGateway.php` + `SwishClient.php` | Implementerad, saknar certifikat |
| Stripe Connect | `gateways/StripeGateway.php` + `StripeClient.php` | Implementerad, saknar API-nycklar |
| Gateway interface | `GatewayInterface.php` | Fungerar |
| PaymentManager | `PaymentManager.php` | Fungerar |

### Webhooks
| Webhook | Fil | Status |
|---------|-----|--------|
| Swish callback | `api/webhooks/swish-callback.php` | **BUGG:** Fel databas-wrapper |
| Stripe webhook | `api/webhooks/stripe-webhook.php` | **BUGG:** Fel databas-wrapper |

### Databastabeller
| Tabell | Status | Kommentar |
|--------|--------|-----------|
| `orders` | Finns | Fullstandig struktur |
| `order_items` | Finns | Kopplad till orders |
| `payment_recipients` | Finns | Centrala betalningsmottagare |
| `payment_transactions` | Finns | Gateway-transaktioner |
| `gateway_certificates` | Finns | For Swish Handel |
| `webhook_logs` | Finns | Loggning av callbacks |
| `event_registrations` | Finns | Event-anmalningar |
| `series_registrations` | Finns | Serie-pass |
| `pricing_templates` | Finns | Prismallar |
| `discount_codes` | **OKANT** | Refereras men migration saknas |

### Frontend
| Sida | Fil | Status |
|------|-----|--------|
| Checkout | `pages/checkout.php` | Fungerar (Swish QR + deeplink) |
| Mina biljetter | `pages/profile/tickets.php` | Finns (502 rader) |
| Mina anmalningar | `pages/profile/registrations.php` | Finns (141 rader) |

---

## KRITISKA BUGGAR ATT FIXA

### 1. Webhooks anvander fel databas-wrapper
**Filer:**
- `api/webhooks/swish-callback.php`
- `api/webhooks/stripe-webhook.php`

**Problem:** Anvander `$GLOBALS['pdo']` istallet for `hub_db()`

**Fix:** Byt ut databas-anrop i bada filerna

**Prioritet:** HOG (kraschar vid callback)

---

### 2. Discount_codes-tabeller saknas
**Problem:** Kod refererar till `discount_codes` och `discount_code_usage` men ingen migration hittas

**Fix:** Skapa migration eller ta bort rabattkod-funktionalitet tillfälligt

**Prioritet:** MEDIUM (checkout fungerar utan rabattkoder)

---

### 3. Duplicerade order-system
**Problem:**
- `includes/payment.php` genererar `ORD-YYYY-NNNNNN`
- `includes/order-manager.php` genererar `A5F2J0112`

**Fix:** Bestam ett format, ta bort det andra

**Prioritet:** LAG (fungerar, bara forvirrande)

---

### 4. Email-notifieringar saknas
**Problem:** Ingen kod for att skicka bekraftelse-email vid betalning

**Fix:** Implementera PHPMailer eller SMTP-integration

**Prioritet:** HOG (anvandare forväntar sig bekraftelse)

---

### 5. Promotor kan inte ladda upp event-banners
**Problem:** I sponsor-uppladdningen saknas möjlighet att ladda upp banners till specifika tävlingar. När en banner laddas upp hamnar den som "logo" i media-biblioteket istället för att kopplas till eventet.

**Påverkade roller:** Promotor

**Förväntat beteende:**
- Promotor ska kunna ladda upp banner (1200x150px) för sina tilldelade events
- Banner ska visas på event-sidan och i anmälningsflödet
- Ska kunna ändras per event, inte bara per serie

**Nuvarande status:**
- `admin/promotor.php` har "Redigera event" som leder till `event-edit.php`
- `event-edit.php` har banner-uppladdning men promotor kanske inte kan nå alla fält

**Fix:**
1. Verifiera att promotor kan redigera banner i event-edit.php
2. Alternativt: Skapa enkel banner-uppladdning i promotor.php

**Prioritet:** HOG (promotors behöver kunna branda sina events)

---

## 3-VECKORS IMPLEMENTATIONSPLAN

### VECKA 1: Stabilisering (5-8 timmar)

| Dag | Uppgift | Tid |
|-----|---------|-----|
| Man | Fixa webhook databas-wrapper (swish + stripe) | 1h |
| Man | Verifiera/skapa discount_codes-tabeller | 1h |
| Tis | Konfigurera Manuell Swish for test-event | 1h |
| Tis | Testa anmalan -> order -> checkout -> bekrafta | 2h |
| Ons | Bugfixar fran testning | 2h |
| Tor | Dokumentera admin-flode | 1h |

**Leverabel:** Fungerande Manuell Swish for ett event

---

### VECKA 2: Email & UX (8-12 timmar)

| Dag | Uppgift | Tid |
|-----|---------|-----|
| Man | Installera PHPMailer / konfigurera SMTP | 2h |
| Man | Skapa email-template for bekraftelse | 2h |
| Tis | Koppla email till order-bekraftelse | 2h |
| Ons | Forbattra checkout-sidan (tydligare instruktioner) | 2h |
| Tor | Skapa QR-kod pa "Mina biljetter" | 2h |
| Fre | Testa email-flode end-to-end | 2h |

**Leverabel:** Automatisk bekraftelse-email med QR-kod

---

### VECKA 3: Test & Lansering (5-8 timmar)

| Dag | Uppgift | Tid |
|-----|---------|-----|
| Man | Pilot-test med 3-5 riktiga anvandare | 2h |
| Tis | Fixa buggar fran pilot | 2h |
| Ons | Konfigurera production Swish-nummer | 1h |
| Tor | Skapa admin-dokumentation | 1h |
| Fre | **LANSERING** for forsta eventet | 1h |

**Leverabel:** Live biljettforsaljning

---

## FRAGOR TILL JALLE (Besvaras innan arbete)

### 1. Betalmetod-prioritet
**Fraga:** Vilken betalmetod ska prioriteras?

| Alternativ | Krav | Tid till lansering |
|------------|------|-------------------|
| A) Manuell Swish | Inget extra | 1 vecka |
| B) Swish Handel | Commerce-avtal + certifikat | 2-3 veckor |
| C) Stripe | Stripe-konto + API-nycklar | 2-3 veckor |

**Rekommendation:** Borja med A, lagg till B/C senare

---

### 2. Swish Commerce-certifikat
**Fraga:** Finns avtal med Swish for Swish Handel (Commerce API)?

- [ ] Ja, certifikat finns
- [ ] Ja, avtal finns men certifikat behover skapas
- [ ] Nej, anvand Manuell Swish

**Om ja:** Var finns certifikatet (.p12-fil)?

---

### 3. Stripe-konto
**Fraga:** Finns Stripe-konto for TheHUB/GravitySeries?

- [ ] Ja, konto finns med API-nycklar
- [ ] Ja, konto finns men behover konfigureras
- [ ] Nej, skapa senare

**Om ja:** Secret key och Publishable key?

---

### 4. Email-leverantor
**Fraga:** Hur ska bekraftelse-email skickas?

| Alternativ | Kostnad | Konfiguration |
|------------|---------|---------------|
| A) WordPress SMTP (om tillganglig) | Gratis | Enkel |
| B) Webbhotell SMTP | Gratis | Enkel |
| C) SendGrid | ~100kr/man | Pålitlig |
| D) Mailgun | ~100kr/man | Pålitlig |

**Rekommendation:** Borja med B, uppgradera vid behov

---

### 5. Betalningsmottagare per serie
**Fraga:** Vem tar emot pengar for respektive serie?

| Serie | Mottagare | Swish-nummer |
|-------|-----------|--------------|
| Capital Enduro | ? | ? |
| Gotaland Enduro | ? | ? |
| SweCup DH | ? | ? |
| (Andra serier) | ? | ? |

**Alt:** Central mottagare (GravitySeries) som fordelar senare?

---

### 6. Check-in/QR-kod prioritet
**Fraga:** Ska check-in med QR-kod finnas fran start?

- [ ] Ja, kritiskt for lansering
- [ ] Nej, kan vanta (enkel namnlista racker)

**Rekommendation:** Vanta, lagg till i v1.1

---

### 7. Prissattning
**Fraga:** Vilka prisnivaer ska stodjas fran start?

| Feature | Inkludera nu? |
|---------|---------------|
| Grundpris per klass | [ ] Ja [ ] Nej |
| Early bird-rabatt | [ ] Ja [ ] Nej |
| Late fee | [ ] Ja [ ] Nej |
| Licenserad vs olicienserad | [ ] Ja [ ] Nej |
| Familjeanmalan | [ ] Ja [ ] Nej |

**Rekommendation:** Bara grundpris nu, resten i v1.1

---

## EFTER LANSERING: FRAMTIDA FEATURES

### v1.1 (Februari 2026)
- [ ] Early bird / Late fee automatik
- [ ] Check-in med QR-kod
- [ ] Swish Handel (om certifikat finns)

### v1.2 (Mars 2026)
- [ ] Stripe kortbetalning
- [ ] Familjeanmalan (multi-rider checkout UI)
- [ ] Automatisk startnummer-tilldelning

### v2.0 (Framtida)
- [ ] Klarna-integration
- [ ] Varukorg for flera events
- [ ] Marknadsplats (begagnat)

---

## TEKNISK SAMMANFATTNING

### Arkitektur (redan implementerad)
```
PaymentManager
    |
    +-- GatewayInterface
         |
         +-- ManualGateway (Swish deeplink + QR)
         +-- SwishGateway (Swish Handel API)
         +-- StripeGateway (Stripe Connect)
```

### Databas (redan implementerad)
```
orders
    |
    +-- order_items
    +-- payment_transactions
    +-- (discount_code_usage)

payment_recipients
    |
    +-- gateway_certificates
```

### Webhooks (behover fix)
```
/api/webhooks/swish-callback.php  -> Uppdaterar order vid Swish callback
/api/webhooks/stripe-webhook.php  -> Uppdaterar order vid Stripe event
```

---

## SAMMANFATTNING

| Kategori | Status |
|----------|--------|
| Orderhantering | Fungerar |
| Manuell Swish | **REDO** |
| Swish Handel | Implementerad, saknar certifikat |
| Stripe | Implementerad, saknar nycklar |
| Webhooks | Bugg (enkel fix) |
| Email | Saknas (behover implementeras) |
| Check-in QR | Saknas (kan vanta) |

**Slutsats:** Systemet ar 80% klart. Med 3 veckors fokuserat arbete kan biljettforsaljning lanseras.

---

**Nasta steg:** Jalle besvarar fragorna ovan, sedan borjar implementation.
