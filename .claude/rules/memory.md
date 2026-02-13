# TheHUB - Memory / Session Knowledge

> Senast uppdaterad: 2026-02-13

---

## VIKTIGA KOLUMNNAMN (latt att blanda ihop)

### riders-tabellen
- **UCI ID** lagras i kolumnen `license_number` (INTE `uci_id` - den kolumnen finns inte)
- **Losenord** for sekundara/lankade profiler ar ALLTID `NULL` (by design)
- **linked_to_rider_id** - pekar pa primart konto for familjemedlemmar
- **ice_name** / **ice_phone** - nodkontakt (In Case of Emergency)
- **address** / **postal_code** / **postal_city** - adressfalt (migration 043)
- **phone** - telefonnummer (migration 2025-12-25)

### Familjesystem (lankade profiler)
- En anvandare loggar in med sitt primarkonto (har password)
- Sekundara profiler har `password = NULL` och `linked_to_rider_id = [primart konto ID]`
- Alla profiler med samma email kopplas vid kontoaktivering
- For att kolla om en profil ar "aktiverad": kontrollera BADE `password IS NOT NULL` OCH om `linked_to_rider_id` pekar pa ett konto med losenord

---

## KANDA DESIGNBESLUT (inte buggar)

### Varukorgen (GlobalCart)
- Items i localStorage persisterar medvetet over sidladdningar
- `pendingItems[]` ar in-memory staging, `GlobalCart` ar localStorage-persistence
- Items forsvinner forst vid genomford betalning

### Avbrutna ordrar
- Skapas nar checkout initieras men inte slutfors
- Auto-rensas efter 24 timmar via `cleanupExpiredOrders()`
- Det ar normalt att se 2-3 cancelled orders per genomford betalning

### Multi-seller kvitton
- Ett kvitto per `payment_recipient` per order
- Om en order har items fran 3 saljare = 3 kvitton
- Tabeller: `receipts` + `receipt_items`

---

## BETALNINGAR

### Swish
- Manuell bekraftelse (inte automatisk API-integration)
- Konfigureras via `SWISH_NUMBER` och `SWISH_PAYEE_NAME` i .env
- Djuplank: `https://app.swish.nu/1/p/sw/?sw=NUMMER&amt=BELOPP&msg=ORDERNR&cur=SEK`
- QR-kod genereras med extern API: `https://api.qrserver.com/v1/create-qr-code/` (SVG img-tagg)
- Admin bekraftar manuellt i orderhanteringen

### Stripe
- Single account (inte Connect)
- Hanterar kortbetalningar
- Webhook for automatisk bekraftelse

### Moms
- 6% moms pa eventregistreringar (svensk sport-moms)
- Beraknas som: `total * 6 / 106`

---

## ROUTING (viktigt!)

- Events kopplas till serier via `series_events`-tabellen (many-to-many), INTE via `events.series_id`
- `/series/9` -> `/pages/series/show.php` (INTE `/pages/series-single.php`)
- Se `/.claude/rules/page-routing.md` for komplett routinglista

---

## KONTOAKTIVERING

### Tva aktiveringsfloden:
1. **activate-account-sidan** (`/activate-account`) - for helt nya konton utan email
2. **Riderprofil "Aktivera konto"** (`/api/rider-activate.php`) - for profiler som har email men inget losenord

### Bada flodena:
- Genererar en token och skickar email
- Lankar till `/reset-password?token=X&activate=1` (VIKTIGT: `&activate=1` maste finnas!)
- Vid aktivering maste anvandaren fylla i: land, fodelsear, (klubb om ingen licens)
- Klubblistan filtreras: `active = 1 AND rf_registered = 1`

---

## CSS / MOBIL

### overflow:hidden-problemet
- `.card` har `overflow: hidden` i components.css
- Modaler med `position: fixed` inne i .card klipps pa mobil
- Losning: flytta modalen till `document.body` vid oppning med `document.body.appendChild(modal)`

### Edge-to-edge pa mobil
- Alla kort/tabeller gar kant-till-kant pa mobil (max-width: 767px)
- Anvander negativa marginaler: `-16px` (matchar main-content padding)

### Z-index-skala
- `--z-dropdown: 100`
- `--z-sticky: 200`
- `--z-fixed: 300` (header)
- `--z-modal: 500`
- `--z-toast: 600`
- Modaler i event.php: `9999`
- Mobile nav: `999`

---

## REGISTRERING / SKAPA DELTAGARE

### Obligatoriska falt for eventregistrering
- `gender` - kon (for klassbestamning)
- `birth_year` - fodelsear (for aldersklasser)
- `phone` - telefonnummer
- `email` - e-post
- `ice_name` - nodkontakt namn
- `ice_phone` - nodkontakt telefon
- Valideras i `getEligibleClassesForEvent()` i order-manager.php

### Skapa ny deltagare (event.php)
- Fast lank "Skapa ny deltagare" under sokinput i BADA modaler (event + serie)
- Formularet kraver ALLA falt som behovs for anmalan
- Om e-post redan finns: API returnerar `code: email_exists_active` eller `email_exists_inactive`
- Aktiva konton -> "Logga in", inaktiva -> "Sok pa namnet istallet"
- Delad funktion `getCreateRiderFormHtml(prefix)` hanterar bada modalerna
- `handleCreateRider(prefix)` ar delad API-anropsfunktion

### UCI ID-sokning via SCF (event.php + api/scf-lookup.php)
- Overst i "Skapa ny deltagare"-formularet finns "Sok din licens via UCI ID"
- Anvandaren skriver in UCI ID (9-11 siffror) och trycker Sok / Enter
- `/api/scf-lookup.php` kollar forst databasen, sedan SCF API via SCFLicenseService
- Om akare redan finns i databasen: visar varning "finns redan, sok pa namnet istallet"
- Om hittad via SCF: auto-fyller firstname, lastname, birth_year, gender, nationality
- Visar klubb och licenstyp i statusmeddelande
- Focus gar till email-faltet efter lyckad sokning
- SCF API: `SCFLicenseService::lookupByUciIds()` - anvander `licens.scf.se/api/1.0`

### Publika sidor (ej inloggningskrav)
- `cart` och `checkout` ar publika (`$publicPages` i router.php)
- checkout.php gor egen auth-check och redirectar till login med return-URL

---

## STARTLISTOR

### Admin/Promotor startliste-sida
- `/admin/event-startlist.php` - Komplett startliste-vy
- Tillganglig for bade admin och promotor (promotor ser bara sina events)
- Event-valjare dropdown, filtrering per klass/status/sok
- Tva vyer: **Basisk** (kompakt tabell) och **Utokad** (alla falt, sidscrollbar)
- Startnummerhantering: auto-tilldelning per klass + manuell redigering (admin only)
- CSV-export i startlisteformat
- Grupperad per klass
- Mobile-first: kortvy pa smal portrait, tabell pa landscape/desktop
- Lankad fran: admin dashboard (snabbatgard), promotor dashboard (per event), admin-tabs (Tavlingar > Startlistor)
- Publik startlista finns redan pa event-sidan (pages/event.php)

### event_registrations.bib_number
- Kolumnen `bib_number` finns redan i `event_registrations`
- Anvands for att lagra startnummer
- Kan tilldelas automatiskt (per klass, fran valfritt startnummer) eller manuellt

---

## MAX DELTAGARE (kapacitetsgrans)

### events.max_participants
- Kolumnen `max_participants` (INT, nullable) finns i `events`-tabellen
- NULL eller 0 = obegransat antal platser
- Konfigureras i admin event-edit under "Anmalan"-sektionen

### Var kapaciteten valideras:
1. **order-manager.php** (`createMultiRiderOrder`) - Blockerar nya registreringar nar fullt
2. **pages/event.php** - Visar "Fullbokat" och "X av Y platser kvar"
3. **api/create-checkout-session.php** - Re-validerar innan Stripe-betalning startar
4. **admin/event-startlist.php** - Visar anmalda/max i stats-baren

### Rakning:
- Rakning inkluderar ALLA icke-avbrutna registreringar (pending + confirmed)
- `status NOT IN ('cancelled')` anvands konsekvent

---

## SENASTE FIXAR (2026-02-12)

- **Login redirect-loop**: `hub_attempt_login()` saknade profilfalt i SELECT -> alla redirectades till /profile/edit. Fixat i hub-config.php
- **UCI ID tom i profil**: Anvande `uci_id` istallet for `license_number`. Fixat i pages/profile/edit.php
- **Lankade profiler "Aktivera konto"**: Sekundara profiler har password=NULL -> visade alltid aktiveringsknapp. Fixat i pages/rider.php
- **Sokmodal bakom header pa mobil**: .card overflow:hidden klippte modalen. Fixat genom att flytta till body
- **Swish QR-kod**: Tillagd pa checkout-sidan for desktop-anvandare (extern API for QR)
- **Tabellkolumner sneda**: Fixat med `table-layout: fixed` i event.php
- **Duplicerade kvitton**: GROUP BY saknades i receipts-query
- **Moms saknades i mail**: Lagt till VAT-berakning och saljarinfo i orderbekraftelse-mail
- **Varukorg kraver login**: cart/checkout tillagda i publicPages, checkout gor egen auth
- **Skapa ny deltagare**: Fast lank under sokrutan, alla falt kravs, email-duplett ger login/aktivera-forslag
