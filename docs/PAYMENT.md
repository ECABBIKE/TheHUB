# TheHUB Betalningssystem

> Dokumentation for betalningsintegration och orderhantering

**Senast uppdaterad:** 2026-02-12

---

## Oversikt

TheHUB har en aktiv betalningsmetod:

1. **Stripe Checkout** - Kortbetalning (Visa, Mastercard, Apple Pay, Google Pay)
   - Alla betalningar gar direkt till plattformens enda Stripe-konto
   - Inga Connected Accounts / destination charges

> **Planerat:** Swedbank Pay (stodjer kort, Swish, faktura, Vipps, MobilePay).
> SwebankPayClient.php finns redan forberedd.

> **Borttaget 2026-02:** Manuell Swish (QR/djuplank), Stripe Connect (promotor-kopplat),
> betalningsmottagare per event/serie. Arkiverade filer finns i `_archived/`.

---

## Arkitektur

```
includes/
├── payment.php                    # Order CRUD, priskalkylering, rabatter
├── order-manager.php              # Multi-rider order management
└── payment/
    ├── GatewayInterface.php       # Interface for gateways
    ├── PaymentManager.php         # Central payment hub (stripe only)
    ├── StripeClient.php           # Stripe API client (single account)
    ├── SwebankPayClient.php       # Swedbank Pay client (forberedd)
    └── gateways/
        └── StripeGateway.php      # Stripe kortbetalning (single account)

admin/
├── orders.php                     # Orderhantering och betalningsbekraftelse
├── ekonomi.php                    # Ekonomi-dashboard
└── discount-codes.php             # Rabattkodshantering

api/
├── create-checkout-session.php    # Skapa Stripe Checkout Session (direkt charge)
├── apply-discount.php             # Tillamp rabattkod pa order
├── validate-discount.php          # Validera rabattkod
└── webhooks/
    └── stripe-webhook.php         # Stripe webhook (betalning, refund, etc)

pages/
└── checkout.php                   # Checkout-sida (kortbetalning)
```

---

## Konfiguration (.env)

```env
# Stripe API-nycklar (obligatoriskt for kortbetalning)
STRIPE_SECRET_KEY=sk_test_...          # eller sk_live_...
STRIPE_PUBLISHABLE_KEY=pk_test_...     # eller pk_live_...
STRIPE_WEBHOOK_SECRET=whsec_...        # Webhook-signering
```

Skapa nycklar pa: https://dashboard.stripe.com/apikeys
Skapa webhook pa: https://dashboard.stripe.com/webhooks
- Endpoint URL: `https://yourdomain.se/api/webhooks/stripe-webhook.php`
- Events att lyssna pa: `checkout.session.completed`, `payment_intent.succeeded`, `charge.refunded`, `customer.subscription.*`, `invoice.*`

---

## Betalningsflode

### Stripe Checkout (kort)

```
1. Anvandare valjer anmalningar
2. Systemet beraknar pris (bas + early bird/late fee)
3. Anvandare anger rabattkod (valfritt)
4. Order skapas (payment_status = 'pending', payment_method = 'card')
5. Klick "Betala X kr" -> POST /api/create-checkout-session.php
6. Redirect till Stripe Checkout (hosted page)
7. Stripe webhook (checkout.session.completed)
   -> Markerar order som betald
   -> Uppdaterar registreringar
   -> Genererar kvitto
   -> Skickar bekraftelsemail
8. Anvandare redirectas tillbaka -> "Betalning genomford!"
```

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
| gateway_code | VARCHAR(50) | 'stripe' |
| gateway_transaction_id | VARCHAR(255) | Stripe Checkout Session ID |
| stripe_session_id | VARCHAR(255) | Stripe Session ID |
| stripe_payment_intent_id | VARCHAR(255) | Payment Intent ID |
| paid_at | DATETIME | Tidpunkt for betalning |
| expires_at | DATETIME | Order utgar |
| discount_code_id | INT | FK till discount_codes |
| gravity_id_discount | DECIMAL(10,2) | Gravity ID-rabatt |

### order_items

| Kolumn | Typ | Beskrivning |
|--------|-----|-------------|
| id | INT | Primary key |
| order_id | INT | FK till orders |
| item_type | ENUM | registration, series_registration, ticket, merchandise |
| registration_id | INT | FK till event_registrations (nullable) |
| description | VARCHAR(500) | Beskrivning |
| unit_price | DECIMAL(10,2) | Styckpris |
| quantity | INT | Antal |
| total_price | DECIMAL(10,2) | Totalt pris |

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

Skapar Stripe Checkout Session for en order (direkt charge till plattformskontot).

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

### Webhook: POST /api/webhooks/stripe-webhook.php

Hanterar Stripe events:
- `checkout.session.completed` - Betalning klar via Checkout
- `payment_intent.succeeded` - Payment Intent succeeded
- `charge.refunded` - Aterbetalning
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
3. **Season Pricing** - Sasongspriser per klass i prismallen

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
| Rabattkoder | `/admin/discount-codes.php` | Rabattkodshantering |
| Prismallar | `/admin/pricing-templates.php` | Prissattning per klass |

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

### Vanliga problem

**Betalningsknapp saknas:**
- Kontrollera att `STRIPE_SECRET_KEY` finns i `.env`

**Webhook tar inte emot callbacks:**
- Verifiera att webhook-URL ar tillganglig fran internet
- Kontrollera `webhook_logs` for fel
- Verifiera `STRIPE_WEBHOOK_SECRET`

---

## Framtida utveckling

- **Swedbank Pay** - `SwebankPayClient.php` ar forberedd, invandtar konto-aktivering
  - Stodjer: kort, Swish, faktura, Vipps, MobilePay
  - Ersatter nuvarande Stripe-integration

---

## Medlemskap & Prenumerationer (Stripe Billing)

Se separat sektion for Stripe Billing-integration
med `membership_plans`, `member_subscriptions`, etc.

Hantering: `/admin/memberships.php`
Publik sida: `/membership`

---

**Version:** 5.0.0 (Single Account, Card Only)
**Senast uppdaterad:** 2026-02-12
