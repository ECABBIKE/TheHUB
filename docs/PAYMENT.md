# TheHUB Betalningssystem

> Dokumentation for betalningsintegration och orderhantering

**Senast uppdaterad:** 2026-01-25

---

## Oversikt

TheHUB anvander ett multi-gateway betalningssystem med stod for:
- **Manuell Swish** - QR-kod och djuplank (aktiv)
- **Swish Handel** - Automatiserad betalning via Swish Commerce API (konfigureras)
- **Stripe Connect** - Kortbetalning, Apple Pay, Google Pay (konfigureras)

---

## Arkitektur

```
includes/
├── payment.php                    # Legacy payment functions (Order CRUD, Swish links)
├── order-manager.php              # Multi-rider order management
└── payment/
    ├── GatewayInterface.php       # Interface for alla gateways
    ├── PaymentManager.php         # Central payment hub
    ├── SwishClient.php            # Swish Commerce API client
    ├── StripeClient.php           # Stripe API client
    └── gateways/
        ├── ManualGateway.php      # Manuell Swish (QR + deeplink)
        ├── SwishGateway.php       # Swish Handel (automatiserad)
        └── StripeGateway.php      # Stripe Connect

admin/
├── orders.php                     # Orderhantering och betalningsbekraftelse
├── payment-recipients.php         # Centrala betalningsmottagare
├── payment-settings.php           # Legacy betalningsinstallningar
├── gateway-settings.php           # Gateway-konfiguration per mottagare
├── certificates.php               # Swish certifikathantering
└── discount-codes.php             # Rabattkodshantering

api/
├── orders.php                     # Order-API (skapa, hamta, lista riders)
├── apply-discount.php             # Tillamp rabattkod pa order
├── validate-discount.php          # Validera rabattkod
└── webhooks/
    ├── swish-callback.php         # Swish Handel callback
    └── stripe-webhook.php         # Stripe webhook

pages/
└── checkout.php                   # Checkout-sida for anvandare
```

---

## Databastabeller

### orders
Huvudtabell for ordrar.

| Kolumn | Typ | Beskrivning |
|--------|-----|-------------|
| id | INT | Primary key |
| order_number | VARCHAR(20) | Format: ORD-YYYY-NNNNNN |
| rider_id | INT | Koppling till riders |
| customer_email | VARCHAR(255) | Kundens email |
| customer_name | VARCHAR(255) | Kundens namn |
| event_id | INT | Event-koppling (nullable) |
| series_id | INT | Serie-koppling (nullable) |
| subtotal | DECIMAL(10,2) | Summa fore rabatt |
| discount | DECIMAL(10,2) | Total rabatt |
| total_amount | DECIMAL(10,2) | Slutsumma |
| currency | VARCHAR(3) | Valuta (SEK) |
| payment_method | ENUM | swish, card, manual |
| payment_status | ENUM | pending, paid, failed, refunded, cancelled |
| payment_reference | VARCHAR(255) | Extern betalningsreferens |
| paid_at | DATETIME | Tidpunkt for betalning |
| expires_at | DATETIME | Order utgar |
| swish_number | VARCHAR(20) | Swish-nummer for betalning |
| swish_message | VARCHAR(50) | Swish-meddelande |
| gateway_code | VARCHAR(50) | Vilken gateway som anvands |
| gateway_transaction_id | VARCHAR(255) | Gateway transaktions-ID |
| gateway_metadata | JSON | Extra data fran gateway |
| discount_code_id | INT | Koppling till discount_codes |
| gravity_id_discount | DECIMAL(10,2) | Gravity ID-rabatt |
| callback_received_at | DATETIME | Nar webhook mottogs |

### order_items
Orderrader.

| Kolumn | Typ | Beskrivning |
|--------|-----|-------------|
| id | INT | Primary key |
| order_id | INT | FK till orders |
| item_type | ENUM | registration, series_registration, ticket, merchandise |
| registration_id | INT | FK till event_registrations (nullable) |
| series_registration_id | INT | FK till series_registrations (nullable) |
| description | VARCHAR(500) | Beskrivning |
| unit_price | DECIMAL(10,2) | Styckpris |
| quantity | INT | Antal |
| total_price | DECIMAL(10,2) | Totalt pris |

### payment_recipients
Centrala betalningsmottagare (ersatter legacy payment_configs).

| Kolumn | Typ | Beskrivning |
|--------|-----|-------------|
| id | INT | Primary key |
| name | VARCHAR(255) | Namn (t.ex. "GravitySeries AB") |
| description | TEXT | Beskrivning |
| swish_number | VARCHAR(20) | Swish-nummer |
| swish_name | VARCHAR(255) | Namn som visas i Swish |
| active | TINYINT | 1 = aktiv |
| gateway_type | ENUM | manual, swish_handel, stripe |
| gateway_enabled | TINYINT | 1 = gateway aktiverad |
| gateway_config | JSON | Gateway-specifik konfiguration |
| stripe_account_id | VARCHAR(255) | Stripe Connected Account ID |
| stripe_account_status | VARCHAR(50) | Stripe kontostatus |

### payment_transactions
Transaktionslogg.

| Kolumn | Typ | Beskrivning |
|--------|-----|-------------|
| id | INT | Primary key |
| order_id | INT | FK till orders |
| gateway_code | VARCHAR(50) | Gateway |
| transaction_type | ENUM | payment, refund, cancel, status_check |
| request_data | JSON | Forfragan |
| response_data | JSON | Svar |
| status | ENUM | pending, success, failed, cancelled |
| error_code | VARCHAR(50) | Felkod |
| error_message | TEXT | Felmeddelande |
| created_at | DATETIME | Skapad |
| completed_at | DATETIME | Slutford |

### gateway_certificates
Swish-certifikat.

| Kolumn | Typ | Beskrivning |
|--------|-----|-------------|
| id | INT | Primary key |
| payment_recipient_id | INT | FK till payment_recipients |
| cert_type | ENUM | swish_test, swish_production |
| cert_data | MEDIUMBLOB | Certifikatdata (P12) |
| cert_password | VARCHAR(255) | Certifikatlösenord |
| uploaded_by | INT | Uppladdad av |
| uploaded_at | DATETIME | Uppladdad |
| expires_at | DATE | Gar ut |
| active | TINYINT | 1 = aktiv |

### webhook_logs
Webhook-logg.

| Kolumn | Typ | Beskrivning |
|--------|-----|-------------|
| id | INT | Primary key |
| gateway_code | VARCHAR(50) | Gateway |
| webhook_type | VARCHAR(100) | Typ (callback, event, etc) |
| payload | TEXT | Radata |
| headers | JSON | Request headers |
| signature | VARCHAR(255) | Signatur for verifiering |
| processed | TINYINT | 1 = behandlad |
| order_id | INT | Kopplad order |
| error_message | TEXT | Fel vid behandling |
| received_at | DATETIME | Mottagen |
| processed_at | DATETIME | Behandlad |

### discount_codes
Rabattkoder.

| Kolumn | Typ | Beskrivning |
|--------|-----|-------------|
| id | INT | Primary key |
| code | VARCHAR(50) | Rabattkod (unik, versaler) |
| description | TEXT | Intern beskrivning |
| discount_type | ENUM | fixed, percentage |
| discount_value | DECIMAL(10,2) | Varde (SEK eller %) |
| max_uses | INT | Max anvandningar totalt (NULL = obegransat) |
| max_uses_per_user | INT | Max per anvandare |
| current_uses | INT | Antal anvandningar |
| valid_from | DATETIME | Giltig fran |
| valid_until | DATETIME | Giltig till |
| min_order_amount | DECIMAL(10,2) | Minsta orderbelopp |
| applicable_to | ENUM | all, event, series |
| event_id | INT | Specifikt event (nullable) |
| series_id | INT | Specifik serie (nullable) |
| is_active | TINYINT | 1 = aktiv |
| created_by | INT | Skapad av |
| created_at | DATETIME | Skapad |

### discount_code_usage
Rabattkod-anvandning.

| Kolumn | Typ | Beskrivning |
|--------|-----|-------------|
| id | INT | Primary key |
| discount_code_id | INT | FK till discount_codes |
| order_id | INT | FK till orders |
| rider_id | INT | FK till riders |
| discount_amount | DECIMAL(10,2) | Applicerad rabatt |
| created_at | DATETIME | Skapad |

---

## Betalningshierarki

Betalningskonfiguration hamtas i foljande ordning:

1. **Event's payment_recipient_id** (ny system)
2. **Series' payment_recipient_id** (ny system)
3. **Event-specifik payment_configs** (legacy)
4. **Serie payment_configs** (legacy)
5. **Promotor config** (legacy)
6. **WooCommerce fallback**

---

## Gateway Interface

Alla gateways implementerar `GatewayInterface`:

```php
interface GatewayInterface {
    public function getCode(): string;
    public function getName(): string;
    public function isAvailable(int $paymentRecipientId): bool;
    public function initiatePayment(array $orderData): array;
    public function checkStatus(string $transactionId): array;
    public function refund(string $transactionId, float $amount): array;
    public function cancel(string $transactionId): array;
}
```

---

## Manual Gateway (Manuell Swish)

Anvands som standard nar ingen annan gateway ar konfigurerad.

### Flode

1. Order skapas med Swish-nummer och meddelande
2. Kund far Swish-lank och QR-kod
3. Kund oppnar Swish-appen och betalar
4. Admin bekraftar betalning manuellt i `/admin/orders.php`
5. Order och registreringar markeras som betalda

### Swish URL-format

```
https://app.swish.nu/1/p/sw/?sw=TELEFON&amt=BELOPP&msg=MEDDELANDE
```

### QR-kod format (Swish C-format)

```
C{telefonnummer};{belopp_i_ore};{meddelande}
```

---

## Swish Handel Gateway

Automatiserad betalning via Swish Commerce API.

### Krav

- Avtal med Swish Handel (via bank)
- P12-certifikat (test eller produktion)
- Payee alias (bankgirokonto)

### Konfiguration

1. Skapa payment recipient i `/admin/payment-recipients.php`
2. Ladda upp certifikat i `/admin/certificates.php`
3. Konfigurera gateway i `/admin/gateway-settings.php`

### Webhook

Endpoint: `/api/webhooks/swish-callback.php`

Hanterar statuskoder:
- `PAID` - Betalning genomford
- `DECLINED` - Betalning nekad
- `ERROR` - Fel vid betalning
- `CANCELLED` - Betalning avbruten
- `CREATED` / `PENDING` - Vantar pa betalning

---

## Stripe Gateway

Kortbetalning, Apple Pay, Google Pay via Stripe Connect.

### Krav

- Stripe-konto
- Connected Account for varje betalningsmottagare
- Webhook secret

### Konfiguration

1. Skapa payment recipient
2. Anslut Stripe Connected Account
3. Konfigurera platform fee (standard 2%)

### Webhook

Endpoint: `/api/webhooks/stripe-webhook.php`

Hanterar events:
- `payment_intent.succeeded`
- `payment_intent.payment_failed`
- `charge.refunded`
- `account.updated`

---

## Rabattkoder

### Typer

- **Fixed (fast belopp)** - T.ex. -50 kr
- **Percentage (procent)** - T.ex. -10%

### Begransningar

- Max anvandningar totalt
- Max per anvandare
- Giltighetstid (fran/till)
- Minsta orderbelopp
- Specifikt event eller serie

### Gravity ID-rabatt

Automatisk rabatt for riders med Gravity ID. Konfigureras per:
- Event (`events.gravity_id_discount`)
- Serie (`series.gravity_id_discount`)
- Globalt (`gravity_id_settings`)

---

## Order-flode

```
1. Anvandare valjer anmalningar
   ↓
2. Systemet beraknar pris (bas + tidiga/sena avgifter)
   ↓
3. Anvandare anger rabattkod (valfritt)
   ↓
4. Systemet validerar och tillampard rabatter
   ↓
5. Order skapas i databasen
   ↓
6. Gateway initieras baserat pa konfiguration
   ↓
7. Anvandare betalar via Swish/Stripe
   ↓
8. Webhook tar emot betalningsbekraftelse
   ↓
9. Order markeras som betald
   ↓
10. Registreringar bekraftas
   ↓
11. Bekraftelsemail skickas (TODO)
```

---

## API-endpoints

### POST /api/orders.php

Skapa order med flera riders.

**Request:**
```json
{
  "event_id": 123,
  "registrations": [
    {"rider_id": 1, "class_id": 5},
    {"rider_id": 2, "class_id": 5}
  ],
  "discount_code": "SUMMER2026"
}
```

**Response:**
```json
{
  "success": true,
  "order_id": 456,
  "order_number": "ORD-2026-000001",
  "total": 500,
  "swish_url": "https://app.swish.nu/...",
  "swish_qr": "https://chart.googleapis.com/..."
}
```

### POST /api/apply-discount.php

Tillamp rabattkod pa befintlig order.

**Request:**
```json
{
  "order_id": 456,
  "code": "SUMMER2026"
}
```

### POST /api/validate-discount.php

Validera rabattkod utan att tillampa.

**Request:**
```json
{
  "code": "SUMMER2026",
  "event_id": 123,
  "amount": 500
}
```

---

## Email-bekraftelser

Email skickas via `includes/mail.php` med stod for:
- **Resend API** (rekommenderat)
- **SMTP**
- **PHP mail()**

### Mallar

Befintliga mallar i `hub_email_template()`:
- `password_reset` - Aterställ losenord
- `welcome` - Valkommen
- `account_activation` - Aktivera konto
- `claim_approved` - Profilaktivering godkand
- `winback_invitation` - Win-back enkatinbjudan
- `payment_confirmation` - Betalningsbekraftelse (TODO)

---

## Admin-verktyg

### /admin/orders.php
- Lista ordrar (filtrera pa status, event)
- Sok pa ordernummer, kundnamn, Swish-meddelande
- Manuell betalningsbekraftelse
- Avbryt ordrar

### /admin/payment-recipients.php
- Skapa/redigera betalningsmottagare
- Koppla till serier/events
- Visa anvandningsstatistik

### /admin/gateway-settings.php
- Valj gateway-typ (manual, swish_handel, stripe)
- Konfigurera gateway-specifika installningar

### /admin/certificates.php
- Ladda upp Swish P12-certifikat
- Hantera test/produktion
- Avaktivera gamla certifikat

### /admin/discount-codes.php
- Skapa/redigera rabattkoder
- Visa anvandningsstatistik
- Aktivera/inaktivera koder

---

## Sakerhet

### Webhook-verifiering
- Stripe: HMAC signaturverifiering
- Swish: Certifikatbaserad autentisering

### CSRF-skydd
Alla admin-formular anvander CSRF-tokens.

### Behorigheter
- Admin: Full atkomst
- Promotor: Endast egna events/serier

---

## Felsökning

### Loggar
- `/logs/error.log` - PHP-fel
- `webhook_logs`-tabell - Webhook-aktivitet
- `payment_transactions`-tabell - Betalningshistorik

### Vanliga problem

**Order skapas inte:**
- Kontrollera att payment_recipient ar konfigurerad for eventet
- Verifiera att event har aktiva klasser med prissattning

**Swish-lank fungerar inte:**
- Kontrollera att Swish-numret ar korrekt formaterat
- Testa med ett giltigt svenskt mobilnummer

**Webhook tar inte emot callbacks:**
- Verifiera att webhook-URL ar tillganglig fran internet
- Kontrollera `webhook_logs` for fel
- Verifiera certifikat (Swish Handel)

---

## Medlemskap & Prenumerationer (Stripe Billing)

TheHUB stodjer atervommande medlemskap via Stripe Billing v2 API.

### Databastabeller

#### membership_plans
Medlemsplaner som kan kopas.

| Kolumn | Typ | Beskrivning |
|--------|-----|-------------|
| id | INT | Primary key |
| name | VARCHAR(100) | Plannamn |
| description | TEXT | Beskrivning |
| price_amount | INT | Pris i ore |
| billing_interval | ENUM | day, week, month, year |
| stripe_product_id | VARCHAR(100) | Stripe Product ID |
| stripe_price_id | VARCHAR(100) | Stripe Price ID |
| benefits | JSON | Array av formaner |
| discount_percent | INT | Rabatt pa anmalningar |
| active | TINYINT | 1 = aktiv |

#### member_subscriptions
Aktiva prenumerationer.

| Kolumn | Typ | Beskrivning |
|--------|-----|-------------|
| id | INT | Primary key |
| rider_id | INT | Koppling till riders |
| user_id | INT | Koppling till users |
| email | VARCHAR(255) | E-post |
| plan_id | INT | FK till membership_plans |
| stripe_customer_id | VARCHAR(100) | Stripe Customer ID |
| stripe_subscription_id | VARCHAR(100) | Stripe Subscription ID |
| stripe_subscription_status | VARCHAR(50) | active, trialing, canceled, etc |
| current_period_end | DATETIME | Nar perioden gar ut |
| cancel_at_period_end | TINYINT | Om prenumerationen avslutas |

#### subscription_invoices
Betalningshistorik for prenumerationer.

#### stripe_customers
Koppling mellan anvandare och Stripe-kunder.

### API-endpoints

#### GET /api/memberships.php?action=get_plans
Hamta alla aktiva medlemsplaner.

#### POST /api/memberships.php?action=create_checkout
Skapa Stripe Checkout-session for prenumeration.

```json
{
  "plan_id": 1,
  "email": "user@example.com",
  "name": "Johan Andersson"
}
```

#### POST /api/memberships.php?action=create_portal
Oppna Stripe Billing Portal for att hantera prenumeration.

```json
{
  "email": "user@example.com"
}
```

### Webhooks

Subscription-relaterade webhooks i `/api/webhooks/stripe-webhook.php`:

- `customer.subscription.created` - Ny prenumeration
- `customer.subscription.updated` - Uppdaterad prenumeration
- `customer.subscription.deleted` - Avslutad prenumeration
- `customer.subscription.trial_will_end` - Trial avslutas snart
- `invoice.paid` - Faktura betald
- `invoice.payment_failed` - Betalning misslyckades

### Admin-hantering

- `/admin/memberships.php` - Hantera planer och prenumerationer
- Flikarna: Planer, Prenumerationer, Statistik

### Publik sida

- `/membership` - Visa planer och registrering
- `/membership?session_id=xxx` - Bekraftelsesida efter betalning

### Flode

1. Besokare valjer plan pa `/membership`
2. Fyller i namn och email
3. Redirectas till Stripe Checkout
4. Efter betalning -> webhook -> prenumeration skapas
5. Bekraftelsemail skickas
6. Medlem kan hantera prenumeration via Stripe Portal

---

## TODO

- [x] Implementera email-bekraftelse vid betalning
- [x] Stripe Connect onboarding-flode
- [x] Medlemskap och prenumerationer (Stripe Billing)
- [ ] Swish Handel certifikatfornyelse-paminelser
- [ ] Automatisk orderrensning (utgangna ordrar)
- [ ] Rapportering och statistik

---

**Version:** 2.0.0
**Senast uppdaterad:** 2026-01-25
