# Jämförelse: Stripe vs Swedbank Pay vs ePay

> Analys för TheHUB - Betalningssystem för eventregistrering
> Datum: 2026-03-01

---

## Kontext

TheHUB använder idag **Stripe** (single account) för kortbetalningar och **manuell Swish** (användaren swishar och admin bekräftar). En `SwebankPayClient.php` finns förberedd men är inte implementerad. ePay.se utvärderades som ett tredje alternativ.

### TheHUBs betalningsprofil
- **Typisk order:** 300-800 kr (eventregistrering)
- **Genomsnittlig order:** ~500 kr
- **Betalsätt:** Kort (Visa/Mastercard) + Swish (manuellt)
- **Moms:** 6% inkl. (svensk sportmoms)
- **Volymer:** Uppskattningsvis 50-200 ordrar/månad (säsongsvariation, sommar = högsäsong)
- **Valuta:** Enbart SEK, svenska kunder dominerar

---

## 1. STRIPE (Nuvarande system)

### Prismodell
| Komponent | Kostnad |
|-----------|---------|
| Månadsavgift | **0 kr** |
| Uppläggningsavgift | **0 kr** |
| Europeiska kort (Visa/MC) | **1,4% + 1,80 kr** per transaktion |
| Icke-europeiska kort | **2,9% + 1,80 kr** per transaktion |
| Valutaväxling | +1-2% (ej relevant, bara SEK) |
| Chargebacks | ~200-300 kr per fall |
| Swish | **Ej tillgängligt via Stripe** |

### Kostnad per 500 kr order (europeiskt kort)
```
500 × 1,4% + 1,80 = 7,00 + 1,80 = 8,80 kr
Effektiv avgift: 1,76%
```

### Vad Stripe inkluderar
- **Gateway + Inlösen i ett** (all-in-one)
- Stripe Checkout (hosted betalningssida)
- Webhooks med signaturverifiering
- Automatisk fee-rapportering (balance_transaction)
- Apple Pay + Google Pay
- Subscriptions/prenumerationer
- Refunds (full + partial)
- Stripe Dashboard
- PCI DSS Level 1 (du hanterar aldrig kortdata)
- Ingen bindningstid

### Styrkor (för TheHUB)
- **Redan fullt integrerat** - StripeClient, StripeGateway, webhooks, fee-tracking, checkout allt klart
- Noll fast kostnad - bra för låga volymer/lågsäsong
- Extremt bra API och dokumentation
- Faktiska avgifter hämtas via webhook (`orders.stripe_fee`)
- Stabil och beprövad infrastruktur

### Svagheter
- **Ingen Swish-integration** - kräver separat manuell hantering
- Relativt hög avgift per transaktion (1,4% + fast)
- Utlandskt bolag (irländskt) - utbetalningar till svenskt konto tar 2-7 dagar
- Support på engelska
- Ingen faktura/delbetalning

---

## 2. SWEDBANK PAY (Förberett)

### Prismodell
| Komponent | Kostnad |
|-----------|---------|
| Månadsavgift (online) | **Ej publicerat** (kräver offert) |
| Kortinlösen | **Från 0,79%** per transaktion |
| Swish Handel | **3 kr** per betalning |
| Butiksterminal (Pay Classic) | Från 199 kr/mån |

> **OBS:** Swedbank Pay publicerar INTE offentliga priser för sin e-handelslösning (Pay Online/Checkout). Man måste kontakta dem för offert. Butikspriset (från 0,79%) är för fysisk terminal - online-priset är troligen högre.

### Kostnad per 500 kr order (uppskattat)
```
Kort: 500 × ~1,2% (uppskattad online) = ~6,00 kr + ev. fast avgift
Swish: 3 kr fast
+ Okänd månadsavgift
```

### Vad Swedbank Pay inkluderar
- **Gateway + Inlösen i ett** (som Stripe)
- Swedbank Pay Checkout (hosted sida med alla betalsätt)
- **Swish integrerat i checkout** (stor fördel!)
- Kort (Visa, Mastercard, Amex)
- Vipps, MobilePay
- Faktura (via tredje part)
- Callback/webhook-system
- PCI DSS-certifiering och 3D Secure 2.0 ingår
- Svenskt bolag, svensk support

### Status i TheHUB
- `includes/payment/SwebankPayClient.php` existerar med grundstruktur
- `createPaymentOrder()` och `getPaymentOrder()` implementerade
- `capturePayment()`, `cancelPayment()`, `refundPayment()` = TODO/stub
- `verifyCallback()` = TODO
- Behöver konto-aktivering + konfiguration

### Styrkor
- **Swish integrerat i checkout-flödet** - användaren väljer Swish eller kort i samma vy
- Lägre kortavgift (från 0,79% butik, troligen ~1,0-1,2% online)
- Svenskt bolag - snabbare utbetalningar, svensk support
- Vipps/MobilePay för nordiska kunder
- Stort förtroende hos svenska konsumenter
- `SwebankPayClient.php` redan påbörjad

### Svagheter
- **Ej offentliga priser** - svårt att jämföra utan offert
- Troligen månadsavgift + uppläggningsavgift
- Längre onboarding-process (~14 dagar uppskattad)
- Sämre API-dokumentation jämfört med Stripe
- Capture/Refund/Callback ej implementerat ännu i TheHUB
- Potentiell bindningstid

---

## 3. ePAY (Ny kandidat)

### VIKTIG DISTINKTION: ePay är ENBART en gateway

**ePay är INTE en inlösare (acquirer).** De hanterar betalningsflödet men du behöver ett separat inlösenavtal med t.ex. Swedbank Pay, Nets eller Worldline för att faktiskt ta emot kortpengar.

### Prismodell (från epay.dk/Advisoa, 2026-03)
| Paket | Månadsavgift | Per transaktion | Inlösenavgift (separat) |
|-------|-------------|-----------------|------------------------|
| **Light** | **99 kr/mån** | **0,25 kr** | Från 1,25% (extern inlösare) |
| **Pro** | **149 kr/mån** | **0,25 kr** | Från 1,25% (extern inlösare) |
| **Business** | **249 kr/mån** | **0,25 kr** | Från 1,25% (extern inlösare) |

- Ingen bindningstid
- Alla priser exkl. moms

### Skillnader mellan paketen
- **Light:** Grundläggande, svenska kort
- **Pro:** + Internationella kort
- **Business:** + Prenumerationer/recurring payments

### TOTAL kostnad per 500 kr order (Light + extern inlösare)
```
ePay gateway:       0,25 kr
Inlösen (1,25%):    6,25 kr
Totalt:             6,50 kr per transaktion
+ 99 kr/mån fast

Effektiv avgift per transaktion: 1,30% + 0,25 kr
(plus 99 kr/mån att fördela)
```

### Vad ePay inkluderar
- Betalningsgateway (dirigerar transaktionen till inlösare)
- Checkout-sida (hosted eller inbäddad)
- Apple Pay, Google Pay, MobilePay
- Backoffice-portal med realtidsöversikt
- Subscription/recurring (Business-paket)
- API med dokumentation (docs.epay.eu)
- Plug-and-play integrationer (WooCommerce, Shopify, etc.)

### Vad ePay INTE inkluderar
- **Ingen inlösen** - kräver separat avtal med Swedbank Pay, Nets, Worldline etc.
- **Ingen Swish** - kräver separat bankavtal
- **Ingen faktura** - kräver extern partner

### Styrkor
- **Lägst transaktionskostnad** (0,25 kr + inlösen ~ 1,30% totalt)
- Transparent prismodell
- Ingen bindningstid
- Bra backoffice-verktyg
- Svenskt/danskt bolag med lokal support

### Svagheter
- **Kräver SEPARAT inlösenavtal** (extra administration, extra avtal)
- **Ingen Swish** - måste fortfarande hanteras separat
- Kräver ny integration från grunden (ingen befintlig kod i TheHUB)
- Månadsavgift oavsett volym
- Dubbla avtal att administrera (gateway + inlösare)

---

## DETALJERAD KOSTNADSJÄMFÖRELSE

### Scenario: 100 ordrar/månad, snitt 500 kr

| | **Stripe** | **Swedbank Pay** | **ePay Light** |
|--|-----------|-----------------|---------------|
| Fast månadsavgift | 0 kr | ~200-500 kr? | 99 kr |
| Transaktionsavgift | 8,80 kr x 100 = **880 kr** | ~6 kr x 100 = **~600 kr** | 6,50 kr x 100 = **650 kr** |
| **Total/mån** | **880 kr** | **~800-1100 kr** | **749 kr** |
| Effektiv % | 1,76% | ~1,6-2,2%? | 1,50% |
| Swish-kostnad | Manuell (0 kr avgift) | 3 kr/st integrerat | Separat avtal |

### Scenario: 50 ordrar/månad (lågsäsong)

| | **Stripe** | **Swedbank Pay** | **ePay Light** |
|--|-----------|-----------------|---------------|
| Fast månadsavgift | 0 kr | ~200-500 kr? | 99 kr |
| Transaktionsavgift | 8,80 x 50 = **440 kr** | ~6 x 50 = **~300 kr** | 6,50 x 50 = **325 kr** |
| **Total/mån** | **440 kr** | **~500-800 kr** | **424 kr** |

### Scenario: 200 ordrar/månad (högsäsong)

| | **Stripe** | **Swedbank Pay** | **ePay Light** |
|--|-----------|-----------------|---------------|
| Fast månadsavgift | 0 kr | ~200-500 kr? | 99 kr |
| Transaktionsavgift | 8,80 x 200 = **1760 kr** | ~6 x 200 = **~1200 kr** | 6,50 x 200 = **1300 kr** |
| **Total/mån** | **1760 kr** | **~1400-1700 kr** | **1399 kr** |

### Break-even: ePay vs Stripe
```
Stripe per transaktion: 8,80 kr
ePay per transaktion:   6,50 kr
Besparing per order:    2,30 kr

Månadsavgift att täcka: 99 kr
Break-even: 99 / 2,30 = 43 ordrar/månad

Med 100 ordrar/mån sparar ePay: (2,30 x 100) - 99 = 131 kr/mån
Med 200 ordrar/mån sparar ePay: (2,30 x 200) - 99 = 361 kr/mån
```

---

## JÄMFÖRELSETABELL: FUNKTIONALITET

| Funktion | Stripe | Swedbank Pay | ePay |
|----------|--------|-------------|------|
| Kort (Visa/MC) | Ja | Ja | Ja (via inlösare) |
| Apple Pay | Ja | Ja | Ja |
| Google Pay | Ja | Ja | Ja |
| **Swish** | **Nej** | **Ja (integrerat)** | **Nej** |
| MobilePay | Nej | Ja | Ja |
| Vipps | Nej | Ja | Nej |
| Faktura | Nej | Ja (tredje part) | Nej |
| Prenumerationer | Ja | Ja | Ja (Business) |
| Refunds | Ja (full+partial) | Ja | Ja |
| Webhooks | Ja | Ja (callbacks) | Ja |
| Hosted checkout | Ja | Ja | Ja |
| API-kvalitet | Utmärkt | Bra | Bra |
| PCI DSS | Ja (Level 1) | Ja | Ja |
| Bindningstid | Ingen | Möjlig | Ingen |
| **Gateway + Inlösen** | **Allt-i-ett** | **Allt-i-ett** | **Bara gateway** |
| Befintlig kod i TheHUB | **Komplett** | **Påbörjad** | **Ingen** |
| Utbetalning till konto | 2-7 dagar | 1-2 dagar | Via inlösare |
| Support-språk | Engelska | Svenska | Svenska/Danska |

---

## SAMMANFATTNING OCH REKOMMENDATION

### ePay - Är det billigare?

**Ja, per transaktion är ePay billigare** - men med viktiga förbehåll:

1. **ePay är bara halva lösningen** - du behöver OCKSÅ ett inlösenavtal (Swedbank Pay, Nets etc.), vilket ger dubbla avtal och dubbel administration
2. **Ingen Swish** - TheHUBs manuella Swish-problem löses inte
3. **99 kr/mån oavsett** - under lågsäsong med < 43 ordrar/mån är Stripe billigare
4. **Ny integration** - kräver helt ny kod, inget förberett

### Rankning efter TheHUBs behov

| Prio | Leverantör | Varför |
|------|-----------|--------|
| **1** | **Swedbank Pay** | Integrerad Swish + kort i ett checkout-flöde, lägre kortavgift, svenskt bolag, redan påbörjad integration. Löser det STÖRSTA problemet: manuell Swish |
| **2** | **Stripe** (behåll) | Redan fullt integrerat, noll fast kostnad, utmärkt API. Funkar bra som fallback/komplement |
| **3** | **ePay** | Billigast per transaktion men kräver separat inlösare, löser inte Swish, kräver ny integration |

### Det stora argumentet: Swish

TheHUBs Swish-hantering är idag **manuell** (användaren swishar och admin bekräftar). Detta är:
- Tidskrävande för admin
- Osäkert (risk att missa betalningar)
- Dålig UX för kunden

**Bara Swedbank Pay löser detta** genom att integrera Swish direkt i checkout. Varken Stripe eller ePay erbjuder Swish.

### Kostnadsbild med Swish inräknat

Om 40% av kunderna väljer Swish (vanligt i Sverige):
- **Stripe:** 8,80 kr kort x 60 + 0 kr Swish x 40 = 528 kr / 100 ordrar (**men Swish-admin tar tid**)
- **Swedbank Pay:** 6 kr kort x 60 + 3 kr Swish x 40 = 480 kr / 100 ordrar (+ okänd månadsavgift, **men helautomatiskt**)
- **ePay:** 6,50 kr kort x 60 + 0 kr x 40 = 390 kr + 99 kr/mån / 100 ordrar (**Swish fortfarande manuell**)

---

## NÄSTA STEG

1. **Kontakta Swedbank Pay** för offert på Pay Online/Checkout (e-handel)
2. **Kontakta ePay** på epay.se för exakta svenska priser
3. Jämför offerternas faktiska siffror med denna analys
4. Fatta beslut baserat på faktiska priser + Swish-behovet

---

## Källor

- [Stripe Pricing](https://stripe.com/pricing)
- [Stripe Fee Calculator Sweden](https://www.feecalculator.io/stripe-fees-sweden)
- [Swedbank Pay - Ta betalt online](https://www.swedbankpay.se/vara-losningar/ta-betalt-online)
- [Swedbank Pay Checkout](https://www.swedbankpay.se/vara-losningar/ta-betalt-online/checkout)
- [ePay.se](https://epay.se/)
- [ePay.dk Priser](https://epay.dk/priser)
- [ePay Betalningsmetoder](https://epay.dk/betalingsmetoder)
- [ePay API Docs](https://docs.epay.eu/get-started/set-up-epay/payment-methods)
- [Advisoa - ePay Betalningslösning](https://www.advisoa.dk/blog/epay-betalningslosning-en-gennemprovet-gateway-til-mindre-og-mellemstore-webshops)
- [Swish kostnader - Octany](https://www.octany.se/swish-kostnader-och-avgifter)
