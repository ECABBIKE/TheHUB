# TheHUB Status

> Senast uppdaterad: 2026-01-12

---

## Session 2026-01-12

### Vad som hände (PROBLEM)

1. **Raderade series-manage.php av misstag**
   - Tidigare i sessionen skapades `admin/series-manage.php` (1087 rader) med fliksystem
   - Innehöll: Info, Events, Anmälan, Betalning, Resultat - allt på en sida
   - Jag blev förvirrad när användaren frågade om dubbletter
   - Raderade den NYA bättre sidan och behöll den GAMLA `series-edit.php`
   - **Kan återställas:** `git checkout eba3793 -- admin/series-manage.php`

2. **Swish-accounts.php finns kvar**
   - `/admin/swish-accounts.php` (464 rader) - fungerar
   - Hanterar Swish-konton för betalningar

3. **CSS med cyan borders**
   - Användaren rapporterade cyan borders överallt
   - Detta är del av dark theme designen (--color-border: rgba(55, 212, 214, 0.2))
   - Har funnits sedan commit 0f0bea4 (dark theme)
   - Behöver undersökas om något ändrats

### Commits denna session

```
45e6421 fix: Remove duplicate series-manage.php page  <-- DETTA VAR FEL
```

### Att återställa

```bash
# Återställ series-manage.php
git checkout eba3793 -- admin/series-manage.php

# Lägg till routing igen i .htaccess
# RewriteRule ^admin/series/manage/([0-9]+)/?$ admin/series-manage.php?id=$1 [QSA,L]
```

---

## Betalningsintegrationer - STATUS

### Finns och är KLARA (kod finns)
- [x] `includes/payment/SwishClient.php` - Swish Handel API
- [x] `includes/payment/StripeClient.php` - Stripe Connect
- [x] `includes/payment/PaymentManager.php` - Hanterar betalningar
- [x] `api/webhooks/swish-callback.php` - Tar emot Swish callbacks
- [x] `api/webhooks/stripe-webhook.php` - Tar emot Stripe webhooks

### Saknas / Ej kopplat
- [ ] Checkout-flöde som använder integrationerna
- [ ] Certifikatuppladdning för Swish Handel
- [ ] Stripe API-nycklar konfigurerade
- [ ] Frontend betalningsformulär

---

## Admin-sidor - STATUS

### Serie-hantering
| Fil | Status | Kommentar |
|-----|--------|-----------|
| `/admin/series.php` | Fungerar | Lista alla serier |
| `/admin/series-edit.php` | Fungerar | Gammal enkel redigering |
| `/admin/series-manage.php` | RADERAD | Behöver återställas! |
| `/admin/series-events.php` | Fungerar | Hantera events i serie |
| `/admin/series-pricing.php` | Finns | Prissättning |

### Ekonomi
| Fil | Status | Kommentar |
|-----|--------|-----------|
| `/admin/ekonomi.php` | Fungerar | Dashboard |
| `/admin/swish-accounts.php` | Fungerar | Swish-konton |
| `/admin/gateway-settings.php` | Finns | Gateway-konfiguration |
| `/admin/payment-recipients.php` | Finns | Betalningsmottagare |
| `/admin/orders.php` | ? | Behöver verifieras |

---

## SKAPA INTE DESSA IGEN

Dessa filer/funktioner finns redan - skapa inte dubbletter:

- `SwishClient.php` - Swish integration FINNS
- `StripeClient.php` - Stripe integration FINNS
- `series-edit.php` - Serie-redigering FINNS
- `swish-accounts.php` - Swish-konton FINNS

---

## Nästa session - ATT GÖRA

1. [ ] Återställ `series-manage.php` från git
2. [ ] Undersök CSS cyan borders - vad är fel?
3. [ ] Koppla betalningsintegrationer till checkout
4. [ ] Verifiera att alla admin-länkar fungerar

---

## Arbetssätt framöver

1. **Läs denna fil** i början av varje session
2. **Commit efter varje klar del** - inte i slutet
3. **Uppdatera denna fil** efter varje commit
4. **Fråga innan radering** - om osäker på om något är dubblett
