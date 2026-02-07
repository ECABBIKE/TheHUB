# TheHUB Betalningssystem

> Dokumentation for betalningsintegration och orderhantering

**Senast uppdaterad:** 2026-02-07

---

## Oversikt

TheHUB anvander **Stripe** som enda betalningsgateway:
- **Stripe Checkout** - Kortbetalning, Apple Pay, Google Pay, Klarna, Swish (via Stripe)
- **Stripe Connect** - Varje arrangor kopplar sitt eget Stripe-konto for direkta utbetalningar

> **Obs:** Manuell Swish (QR-kod/djuplank) ar borttagen sedan 2026-02-07.
> Alla betalningar gar nu via Stripe Checkout som stodjer Swish som betalmetod.

---

## Arkitektur

```
includes/
├── payment.php                    # Order CRUD, priskalkylering, rabatter
├── order-manager.php              # Multi-rider order management
└── payment/
    ├── GatewayInterface.php       # Interface for gateways
    ├── PaymentManager.php         # Central payment hub
    ├── StripeClient.php           # Stripe API client (Connect, Checkout, Transfers)
    └── gateways/
        └── StripeGateway.php      # Stripe Connect gateway

admin/
├── orders.php                     # Orderhantering och betalningsbekraftelse
├── ekonomi.php                    # Ekonomi-dashboard
├── payment-recipients.php         # Betalningsmottagare (Stripe Connect)
├── promotor-stripe.php            # Arrangors Stripe-onboarding
└── discount-codes.php             # Rabattkodshantering

api/
├── create-checkout-session.php    # Skapa Stripe Checkout Session
├── stripe-connect.php             # Stripe Connect API (login link, etc)
├── apply-discount.php             # Tillamp rabattkod pa order
├── validate-discount.php          # Validera rabattkod
└── webhooks/
    └── stripe-webhook.php         # Stripe webhook (betalning, prenumeration, etc)

pages/
└── checkout.php                   # Checkout-sida for anvandare (Stripe Checkout)
```

---

## Konfiguration (.env)

```env
# Stripe API-nycklar (obligatoriskt)
STRIPE_SECRET_KEY=sk_test_...          # eller sk_live_...
STRIPE_PUBLISHABLE_KEY=pk_test_...     # eller pk_live_...
STRIPE_WEBHOOK_SECRET=whsec_...        # Webhook-signering
STRIPE_PLATFORM_FEE_PERCENT=2          # Plattformsavgift i procent
```

Skapa nycklar pa: https://dashboard.stripe.com/apikeys
Skapa webhook pa: https://dashboard.stripe.com/webhooks
- Endpoint URL: `https://yourdomain.se/api/webhooks/stripe-webhook.php`
- Events att lyssna pa: `checkout.session.completed`, `payment_intent.succeeded`, `charge.refunded`, `account.updated`, `customer.subscription.*`, `invoice.*`

---

## Betalningsflode (Stripe Checkout)

```
1. Anvandare valjer anmalningar
   |
2. Systemet beraknar pris (bas + early bird/late fee)
   |
3. Anvandare anger rabattkod (valfritt)
   |
4. Order skapas i databasen (payment_status = 'pending')
   |
5. Anvandare klickar "Betala X kr"
   |
6. /api/create-checkout-session.php:
   - Skapar Stripe Checkout Session
   - Satter gateway_code = 'stripe', gateway_transaction_id = session_id
   - Om Connected Account finns: destination charge med platform fee
   |
7. Anvandare redirectas till Stripe Checkout (hosted page)
   - Valjer betalmetod: kort, Apple Pay, Google Pay, Klarna, Swish
   |
8. Stripe skickar webhook (checkout.session.completed)
   |
9. stripe-webhook.php:
   - Hittar order via gateway_transaction_id
   - Markerar order som betald
   - Uppdaterar event_registrations/series_registrations
   - Genererar kvitto
   - Skapar transfers till Connected Accounts
   |
10. Anvandare redirectas tillbaka till checkout-sida
    - Visar "Betalning bearbetas" + auto-refresh
    - Nar webhook processats: visar "Betalning genomford!"
```

---

## Stripe Connect (Betalningsmottagare)

### Flode for arrangor

1. Admin skapar betalningsmottagare i `/admin/payment-recipients.php`
2. Kopplar mottagare till serie via `series.payment_recipient_id`
3. Arrangor gar till `/admin/promotor-stripe.php`
4. Klickar "Anslut till Stripe" -> Stripe onboarding
5. Fyller i foretags/foreningsuppgifter, bankinfo, ID-verifiering
6. Stripe granskar (1-2 vardagar)
7. Nar `stripe_account_status = 'active'` kan mottagaren ta emot betalningar

### Destination Charges

Betalningar gar direkt till plattformens Stripe-konto. Transfers skapas till Connected Accounts:
- `application_fee_amount` = plattformsavgift (default 2%)
- `transfer_data.destination` = arrangors Stripe account ID

---

## Databastabeller

### orders

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
| payment_method | VARCHAR(20) | 'card' |
| payment_status | ENUM | pending, paid, failed, refunded, cancelled |
| payment_reference | VARCHAR(255) | Stripe payment_intent ID |
| gateway_code | VARCHAR(50) | 'stripe' |
| gateway_transaction_id | VARCHAR(255) | Stripe Checkout Session ID |
| gateway_metadata | JSON | Extra data fran Stripe |
| stripe_session_id | VARCHAR(255) | Stripe Session ID (migration 038) |
| stripe_payment_intent_id | VARCHAR(255) | Payment Intent ID (migration 038) |
| paid_at | DATETIME | Tidpunkt for betalning |
| expires_at | DATETIME | Order utgar |
| discount_code_id | INT | FK till discount_codes |
| gravity_id_discount | DECIMAL(10,2) | Gravity ID-rabatt |
| transfers_status | VARCHAR(20) | pending, processing, completed, failed |

### order_items

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
| payment_recipient_id | INT | FK till payment_recipients (for transfers) |
| seller_amount | DECIMAL(10,2) | Belopp till saljaren (efter avgifter) |

### payment_recipients

| Kolumn | Typ | Beskrivning |
|--------|-----|-------------|
| id | INT | Primary key |
| name | VARCHAR(255) | Namn (t.ex. "GravitySeries AB") |
| description | TEXT | Beskrivning |
| active | TINYINT | 1 = aktiv |
| gateway_type | VARCHAR(20) | 'stripe' |
| stripe_account_id | VARCHAR(255) | Stripe Connected Account ID |
| stripe_account_status | VARCHAR(50) | pending, active, restricted |
| contact_email | VARCHAR(255) | Kontakt-epost |
| contact_phone | VARCHAR(50) | Kontakttelefon |
| org_number | VARCHAR(20) | Organisationsnummer |

### order_transfers

| Kolumn | Typ | Beskrivning |
|--------|-----|-------------|
| id | INT | Primary key |
| order_id | INT | FK till orders |
| payment_recipient_id | INT | FK till payment_recipients |
| stripe_account_id | VARCHAR(255) | Stripe Connected Account |
| amount | DECIMAL(10,2) | Belopp att overfora |
| stripe_transfer_id | VARCHAR(255) | Stripe Transfer ID |
| stripe_charge_id | VARCHAR(255) | Kopplad charge |
| transfer_group | VARCHAR(255) | For grupperade transfers |
| status | VARCHAR(20) | pending, completed, failed |

### discount_codes

| Kolumn | Typ | Beskrivning |
|--------|-----|-------------|
| id | INT | Primary key |
| code | VARCHAR(50) | Rabattkod (unik, versaler) |
| discount_type | ENUM | fixed, percentage |
| discount_value | DECIMAL(10,2) | Varde (SEK eller %) |
| max_uses | INT | Max anvandningar totalt |
| max_uses_per_user | INT | Max per anvandare |
| valid_from / valid_until | DATETIME | Giltighetstid |
| min_order_amount | DECIMAL(10,2) | Minsta orderbelopp |
| applicable_to | ENUM | all, event, series |

---

## API-endpoints

### POST /api/create-checkout-session.php

Skapar Stripe Checkout Session for en order.

**Request (POST form data):**
```
order_id=456
```

**Response:**
```json
{
  "success": true,
  "url": "https://checkout.stripe.com/c/pay/...",
  "session_id": "cs_test_..."
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

**Response:**
```json
{
  "success": true,
  "discount_amount": 50,
  "new_total": 450,
  "message": "Rabattkod tillampad!"
}
```

### Webhook: POST /api/webhooks/stripe-webhook.php

Hanterar Stripe events:
- `checkout.session.completed` - Betalning klar via Checkout
- `payment_intent.succeeded` - Payment Intent succeeded
- `charge.refunded` - Aterbetalning
- `account.updated` - Connected Account uppdaterat
- `checkout.session.async_payment_failed` - Asynkron betalning misslyckades
- `customer.subscription.*` - Prenumerationsandringar
- `invoice.*` - Fakturahandringar

---

## Rabattkoder

### Typer
- **Fixed (fast belopp)** - T.ex. -50 kr
- **Percentage (procent)** - T.ex. -10%

### Begransningar
- Max anvandningar totalt / per anvandare
- Giltighetstid
- Minsta orderbelopp
- Specifikt event eller serie

### Gravity ID-rabatt
Automatisk rabatt for riders med Gravity ID:
- Per event: `events.gravity_id_discount`
- Per serie: `series.gravity_id_discount`
- Globalt: `gravity_id_settings.default_discount`

---

## Prissattning

### Flode
1. **Pricing Templates** - Grundpriser per klass + early bird/late fee-procent
2. **Event Pricing Rules** - Eventspecifika prisoverskridanden
3. **Season Pricing** - Sasongspriser per klass i prismallen (`pricing_template_rules.season_price`)

### Tabeller
- `pricing_templates` - Prismall med early bird/late fee-installningar
- `pricing_template_rules` - Grundpris + sasongspris per klass och mall
- `event_pricing_rules` - Eventspecifika prisoverskridanden

---

## Admin-verktyg

| Verktyg | URL | Beskrivning |
|---------|-----|-------------|
| Ekonomi | `/admin/ekonomi.php` | Dashboard med oversikt |
| Ordrar | `/admin/orders.php` | Orderhantering och bekraftelse |
| Mottagare | `/admin/payment-recipients.php` | Betalningsmottagare + Stripe Connect |
| Arrangor-Stripe | `/admin/promotor-stripe.php` | Arrangors onboarding-flode |
| Rabattkoder | `/admin/discount-codes.php` | Rabattkodshantering |
| Prismallar | `/admin/pricing-templates.php` | Prissattning per klass |
| Testevent | `/admin/tools/create-test-event.php` | Skapa testevent for betalningstest |

---

## Migrationer

| # | Fil | Innehall |
|---|-----|----------|
| 031 | order_transfers.sql | Transfer-tabell, payment_recipient_id pa order_items |
| 037 | pricing_template_season_price.sql | season_price pa pricing_template_rules |
| 038 | orders_stripe_session.sql | stripe_session_id, stripe_payment_intent_id pa orders |

---

## Sakerhet

- **Webhook-verifiering**: Stripe HMAC signaturverifiering (`STRIPE_WEBHOOK_SECRET`)
- **CSRF-skydd**: Alla admin-formular anvander CSRF-tokens
- **Behorighetskontroll**: Admin = full atkomst, Promotor = egna events/serier
- **Inga kortuppgifter**: Lagras aldrig - allt hanteras av Stripe (PCI DSS Level 1)

---

## Felsökning

### Loggar
- `/logs/error.log` - PHP-fel
- `webhook_logs`-tabell - Webhook-aktivitet
- `payment_transactions`-tabell - Betalningshistorik

### Vanliga problem

**Betalningsknapp saknas:**
- Kontrollera att `STRIPE_SECRET_KEY` finns i `.env`

**Webhook tar inte emot callbacks:**
- Verifiera att webhook-URL ar tillganglig fran internet
- Kontrollera `webhook_logs` for fel
- Verifiera `STRIPE_WEBHOOK_SECRET`

**Transfers misslyckas:**
- Kontrollera att Connected Account har `stripe_account_status = 'active'`
- Verifiera att `charges_enabled` och `payouts_enabled` ar true

---

## Medlemskap & Prenumerationer (Stripe Billing)

Se separat sektion i slutet av denna fil for Stripe Billing-integration
med `membership_plans`, `member_subscriptions`, etc.

Hantering: `/admin/memberships.php`
Publik sida: `/membership`

---

**Version:** 3.0.0 (Stripe-only)
**Senast uppdaterad:** 2026-02-07
