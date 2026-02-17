# TheHUB - Memory / Session Knowledge

> Senast uppdaterad: 2026-02-18

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
- **Webhook-fil:** `/api/webhooks/stripe-webhook.php` (riktig fil)
- **Proxy:** `/api/stripe-webhook.php` (inkluderar riktig fil - Stripe Dashboard pekar hit)
- **HTTPS-undantag:** .htaccess skippar HTTPS-redirect for bade `/api/webhooks/` och `/api/stripe-webhook.php`
- `STRIPE_MODE=test|live` i .env styr vilka nycklar som anvands (via `env()` i config.php)

### Faktiska Stripe-avgifter (migration 049)
- `orders.stripe_fee` - Faktisk avgift i SEK fran Stripes `balance_transaction`
- `orders.stripe_balance_transaction_id` - ID for fee-lookup
- Hamtas automatiskt av webhook vid betalning (`getPaymentFee()` i StripeClient)
- Backfill-verktyg: `/admin/tools/backfill-stripe-fees.php` for befintliga ordrar
- Promotor-sidan anvander faktiska fees nar de finns, uppskattar for resten

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

### Radius-skillnad publik vs admin (desktop)
- **Publika sidor:** `.card` anvander `--radius-lg` (14px) i components.css - men pa mobil ar nastan allt edge-to-edge med `border-radius: 0`
- **Admin:** `.admin-card` anvander `--radius-md` (10px) i admin.css
- Skillnaden syns framst pa desktop
- **TODO:** Standardisera radius och infora nya tabeller pa alla sidor (se ROADMAP)

### theme-base.css ar en DOD fil
- `assets/css/theme-base.css` laddas ALDRIG av nagon PHP-fil
- Alla `.nav-bottom` bas-stilar som lag dar har flyttats till `pwa.css`
- Skriv INTE nya stilar i theme-base.css - anvand pwa.css for nav-bottom och mobil-relaterat

### Z-index-skala
- `--z-dropdown: 100`
- `--z-sticky: 200`
- `--z-fixed: 300` (header)
- `--z-modal: 500`
- `--z-toast: 600`
- Modaler i event.php: `9999`
- Mobile nav: `9999` (nav-bottom i pwa.css)

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
- Nationalitet-dropdown: SWE, NOR, DNK, FIN, DEU, GBR, USA, Annan (tom strang)
- Klubb-sokfalt (valfritt): typeahead mot `/api/search.php?type=clubs`, sparar `club_id`
- UCI ID-lookup auto-fyller klubbnamnet fran SCF

### Automatisk kontoaktivering vid registrering
- Nar en order betalas (`markOrderPaid`) kollas om nagon rider i ordern saknar losenord
- Om ja: genereras en aktiveringstoken och lank laggs till i kvitto/bekraftelsemailet
- Lanken visas i en gron ruta "Aktivera ditt konto" i bade `payment_confirmation` och `receipt` templates
- Token giltig 24 timmar, leder till `/reset-password?token=X&activate=1`
- Bara for riders med `password IS NULL` (= nyskapade, aldrig aktiverade)
- Riders som redan har konto (password != NULL) far INTE aktiveringslank

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

## EVENT-SIDA FLIKSTRUKTUR (2026-02-13)

### Inbjudan-fliken (tab=info)
- Visar inbjudningstext (`invitation`) overst
- Under den visas "Generell tavlingsinformation" (`general_competition_info`) - ny ruta tillagd 2026-02-13
- Faciliteter & Logistik ar BORTTAGEN fran denna flik

### Faciliteter-fliken (tab=faciliteter) - NY 2026-02-13
- Egen flik med alla facility-kategorier (vatskekontroller, toaletter, cykeltvatt, mat, etc.)
- Visas bara i tab-nav om nagon facility har data
- 12 kategorier med ikoner i info-grid layout

### general_competition_info (migration 044)
- `events.general_competition_info` - TEXT, nullable
- `events.general_competition_use_global` - Global-flagga
- `events.general_competition_hidden` - Dolj-flagga
- Redigeras i admin event-edit under "Inbjudan"-sektionen

---

## SENASTE FIXAR (2026-02-19)

- **Migration 050 visade alltid rod i migrations.php**: Andrad fran data-check (`order_items.payment_recipient_id IS NOT NULL`) till kolumn-check (`columns => ['order_items.payment_recipient_id']`). Data-checken var for strikt - events utan konfigurerad recipient gor att vissa items aldrig kan backfillas.
- **Backfill Stripe-avgifter visade 0 ordrar (TREDJE GANGEN)**: `getOne()` i DatabaseWrapper (helpers.php) anropar `getValue()` → `fetchColumn()` som returnerar en SKALARVARDE, inte en rad/array. Koden behandlade resultatet som en associativ array (`$row['total']`). Fixat: andrat till `getRow()` som returnerar en hel rad. **VIKTIGT: `getOne()` = skalarvarde, `getRow()` = en rad som array, `getAll()` = alla rader.**

## TIDIGARE FIXAR (2026-02-18)

- **Ekonomi/utbetalningsvy visade noll betalningar**: Promotor.php-fragan JOINade via `order_items.payment_recipient_id` som var NULL for alla order-items (createMultiRiderOrder satte aldrig detta falt). Fixat: fragan joinar nu via `orders.event_id → events → payment_recipients` istallet. Anvander `o.total_amount` istallet for `oi.total_price`.
- **order_items.payment_recipient_id sätts nu korrekt**: `createMultiRiderOrder()` i order-manager.php slår nu upp `payment_recipient_id` via events/series och sätter det vid INSERT för både event- och serieregistreringar.
- **Backfill migration 050**: Uppdaterar befintliga order_items med NULL payment_recipient_id via events och series-tabellerna.
- **Bottennavigation (nav-bottom) trasig i webbläsare**: `theme-base.css` som innehöll alla `.nav-bottom`-stilar laddades ALDRIG av någon PHP-fil. Fixat: alla bas-stilar för `.nav-bottom` flyttade till `pwa.css` (som faktiskt laddas).
- **Backfill Stripe-avgifter visade noll ordrar**: Verktyget sökte bara i `stripe_payment_intent_id`-kolumnen. Omskrivet med 5 strategier: stripe_payment_intent_id, payment_reference, gateway_transaction_id (inkl cs_-sessionslookup), gateway_metadata JSON.
- **Faktiska Stripe-avgifter fran webhook**: Stripe webhook hamtar nu riktiga avgifter fran `balance_transaction` via API-anrop efter betalning. Lagras i `orders.stripe_fee` och `orders.stripe_balance_transaction_id` (migration 049). Promotor-sidan anvander faktiska avgifter nar de finns, faller tillbaka pa uppskattningar (1,5%+2kr) for aldre ordrar.
- **Backfill-verktyg for Stripe-avgifter**: `/admin/tools/backfill-stripe-fees.php` hamtar faktiska avgifter for redan betalda ordrar via Stripe API. Kor i batchar om 10 med rate limiting. Lankad fran tools.php.
- **Plattformsavgift redigerbar**: Admin kan nu andra plattformsavgift per betalningsmottagare direkt i utbetalningsvyn (`/admin/promotor.php`). Klicka pennan vid "Plattformsavgift" for inline-redigering. Sparas via AJAX.
- **Avgiftsindikatorer**: Stripe-avgifter i utbetalningsvyn visar nu badges "Faktiska" (gron), "Delvis faktiska" (gul) eller "Uppskattade" (gra) beroende pa om faktisk data finns.
- **Sponsorplacering: Custom images fran mediaarkivet**: Migration 048 lagger till `custom_media_id` i `sponsor_placements`. Admin kan nu valja fritt fran alla mappar i mediaarkivet (sponsors, annonser, branding, etc.) nar bilder tilldelas reklamplatser.
- **Annonsrotation**: Bannerpositioner (`header_banner`, `header_inline`) roterar nu mellan sponsorer per besok via `ORDER BY RAND() LIMIT 1`.
- **Resultat-sponsorlogga fixad**: `.class-sponsor-logo` har nu `max-width: 200px` for att forhindra att 1200x150-bannern stracker sig for bred i resultatheadern.
- **Logo-fallback ordning forbattrad**: `get_sponsor_logo_for_placement()` provar nu `legacy_logo_url` och `logo_url` FORE banner, sa standardloggan anvands istallet for den breda bannern nar bada finns.
- **Promotor utbetalningsvy (admin)**: `/admin/promotor.php` visar nu ekonomisk sammanstallning for admins. Per betalningsmottagare: bruttointakter, moms (6%), Stripe-avgifter (faktiska eller uppskattade), Swish-avgifter (~2kr), plattformsavgift (konfigurerbar per mottagare), nettoutbetalning. Filtrering per ar och mottagare. Promotor-rollen ser fortfarande sin vanliga vy.
- **custom_media_id graceful fallback**: GlobalSponsorManager kollar nu om kolumnen finns innan den anvands. Forhindrar krasch om migration 048 inte korts annu.
- **Sponsors API fixad**: `/api/sponsors.php` hade ersatts med en debug-version som returnerade HTML istallet for JSON. Alla CRUD-operationer (get, list, create, update, delete) fungerade inte. Aterskriven till riktig JSON API.
- **Forhandsvisning av reklamplatser**: Ny sida `/admin/sponsor-placements-preview.php` som visuellt visar hur varje placement-position (header_inline, header_banner, content_top, content_bottom, footer) ser ut pa en simulerad sida. Inkluderar responsiv demo och specifikationstabeller.
- **Navigation synkad over alla plattformar (desktop, mobil, PWA)**:
  - `admin-mobile-nav.php` omskriven: Admin-menyn laser nu fran `$ADMIN_TABS` (samma kalla som desktop sidebar) istallet for hardkodade lankar
  - Promotor-menyn identisk pa alla plattformar: Tavlingar, Serier, Media, Sponsorer, Direktanmalan
  - Media-lanken for promotor fixad: pekade pa `/admin/sponsors.php`, nu korrekt `/admin/media.php`
  - Sponsorer tillagd som separat menyval for promotor (var tidigare dold bakom "Media")
  - Admin PWA-manifest (`admin/manifest.json`): Borttagen dubbel "Media", lagt till Serier och Sponsorer som separata genvagar
  - Publikt PWA-manifest (`manifest.json`): Lagt till Serier och Ranking som genvagar
  - `sidebar.php` promotor-nav fixad: Media → media.php, Sponsorer tillagd

### Sponsorsystem-arkitektur
- **sponsor_placements.custom_media_id**: Override per placement, JOIN mot media-tabellen
- **Bildprioritet vid rendering**: custom_image → banner (breda positioner) → logo → text
- **Rotation**: `header_banner` och `header_inline` visar 1 sponsor at gangen, roterar via RAND()
- **Logo-fallback**: sidebar/small → legacy_logo_url → logo_url → standard → small → banner (sista utvag)
- **event.php sponsorer**: Laddas fran series_sponsors + event_sponsors, alla logo-storlekar JOINas

### Navigationsarkitektur (efter fix)
- **Kalla for sanning (admin):** `$ADMIN_TABS` i `/includes/config/admin-tabs-config.php`
- **Desktop sidebar:** Laser fran `$ADMIN_TABS` (redan korrekt)
- **Mobilmeny:** Laser fran `$ADMIN_TABS` (fixat 2026-02-18)
- **PWA-genvagar:** Manuella men synkade med nav-strukturen
- **Promotor:** Unik meny definierad pa TVA stallen (admin-mobile-nav.php + sidebar.php) - maste hallas synkade manuellt

## TIDIGARE FIXAR (2026-02-17)

- **Sponsorlogo i resultatheader fixad**: Borttagen ful gra bakgrundsruta (`color-bg-sunken`) och `align-items: stretch` fran `.class-sponsor`. Loggan ar nu 40px hojd utan bakgrund, ren och enkel.
- **Bildformat-guide i mediabiblioteket**: Ny inforuta i sidofaltet pa `/admin/media.php` med knapp som oppnar detaljerad popup. Beskriver alla bildformat (Banner 1200x150, Logo 600x150, Resultatheader 40px hojd), generella riktlinjer, mappstruktur och var bilder visas pa sajten. Anpassad for bade admin (ser alla mappar + promotor-info) och promotor (ser bara sin mapp + tips).

## TIDIGARE FIXAR (2026-02-16)

- **Svenska tecken fixade**: Alla UI-texter i event.php, checkout.php och admin/event-startlist.php anvander nu korrekta a, a, o (var felaktigt utan diakritiska tecken). Krav tillagt i CLAUDE.md.
- **Sponsorlogga forstorad i resultatkortheader**: `.class-sponsor-logo` okad fran max-height 36px till height 100% / max-height 48px, max-width 200px. Card-header i `.class-section` anvander nu `align-items: stretch` sa loggan fyller hela radens hojd.
- **Rabattkod mobilfix**: Formularet i checkout anvander nu `flex-wrap: wrap` sa input och knapp staplas pa smala skarmar istallet for att klippas.
- **Admin startlista club-bugg**: `Undefined array key 'club'` pa rad 713 fixad med `?? ''`.
- **Session-utloggning fixad (3 kritiska buggar)**:
  1. `session.gc_maxlifetime` sattes ALDRIG → PHP default 24 min raderade sessionsdata pa servern trots att cookie levde 7-30 dagar. Fixat: satter `ini_set('session.gc_maxlifetime', 2592000)` (30 dagar) i index.php, config.php och auth.php
  2. `rider-auth.php` laddades INTE pa publika sidor → `rider_check_remember_token()` var otillganglig → remember-me auto-login fungerade aldrig. Fixat: laddas nu fran hub-config.php
  3. `hub_set_user_session()` skapade ALDRIG en remember-token i databasen → aven om remember-check fungerade fanns ingen token att kolla. Fixat: anropar nu `rider_set_remember_token()` vid remember_me
  4. `rider_check_remember_token()` aterställde bara `rider_*` sessionsvariabler, INTE `hub_*` → auto-login satte rider_id men inte hub_user_id → publika sidor sag anvandaren som utloggad. Fixat: satter nu alla hub_* variabler + lankar profiler
  5. Session-cookie fornyades inte vid varje sidladdning for remember-me-anvandare → 30-dagars-fonstret borjade vid login, inte senaste aktivitet. Fixat: cookie fornyas pa varje sidladdning i hub-config.php
- **Session-cookie lifetime**: Alla session-cookiepar satta till 30 dagar (var 7 dagar / 1 dag pa olika stallen)
- **"Aktivera profil" visades for lankade profiler (Vidar-buggen)**: `linked_to_rider_id` saknades i SELECT-fragan i `pages/rider.php`. Logiken som kollar om lankad primarkontot har losenord existerade men kunde aldrig koras for att kolumnen inte hamtades. Fixat genom att lagga till `r.linked_to_rider_id` i bade huvud- och fallback-queryn.
- **Mobilmeny satt inte fast i botten (webblasare)**: `.nav-bottom` hade lagre z-index och saknade GPU-acceleration i browser-mode (bara PWA hade `!important` + `translate3d`). Fixat: z-index hogt till 9999, lagt till `transform: translate3d(0,0,0)` i theme-base.css
- **Checkout: Swish ovanfor kort**: Swish visas nu forst med meddelande "hjalp oss halla nere bankavgifterna". Kort (Stripe) visas under.
- **Anmalda-listan pa event**: Visar nu BARA betalda deltagare (`payment_status = 'paid'`). Statuskolumnen borttagen. Nar startnummer ar tilldelade byter fliken namn till "Startlista" och visar startnr-kolumn.
- **Mobil formularlayout (skapa deltagare)**: Bytt fran table-layout till staplad div-layout. Fodelsear+kon pa samma rad (grid 2-kolumn). Funkar battre pa smala skarmar.
- **Cart-sidan mobilfix**: Borttagen hardkodad `max-width: 800px`, anvander nu `container--sm` (500px desktop, 100% mobil)

### Session-arkitektur (efter fix)
- **gc_maxlifetime**: 30 dagar (satt i index.php, config.php, auth.php)
- **Cookie lifetime**: 30 dagar (alla stallen)
- **Remember token**: DB-backed, 30 dagar, roteras vid varje auto-login
- **Cookie refresh**: Session-cookie fornyas varje sidladdning for remember_me-anvandare
- **Fallback-kedja**: Session → remember_token (cookie+DB) → utloggad
- **rider-auth.php**: Laddas globalt via hub-config.php (behover inte inkluderas manuellt langre)

---

## TIDIGARE FIXAR (2026-02-14)

- **SCF Namnsok birthdate-bugg**: Batch-sokningen skickade `YYYY-01-01` som birthdate till SCF API, vilket filterade bort alla som inte var fodda 1 januari (= 0% traffar). Fixat: skickar INTE birthdate alls vid namn-sokning (samma fix som redan fanns i order-manager.php). Birth year anvands bara for match scoring.
  - Riders utan kon soker nu bade M och F istallet for att anta M
  - "Aterstall ej hittade"-knappen visas nu dynamiskt via JS (inte bara vid sidladdning)
  - Debug-info fran forsta API-anropet visas i loggen for enklare felsökning
- **SCF Namnsok prestandafix**: Hela verktyget (`/admin/scf-name-search.php`) omskrivet fran synkrona form-submits till AJAX JSON API
  - Sidan laser inte langre upp under sokning - allt kor asynkront i bakgrunden
  - Riders som sokts utan traff sparas nu med status `not_found` i `scf_match_candidates` (migration 046)
  - Forhindrar omsokningar av redan sokta riders (sparar enormt med tid vid 2600+ riders)
  - Bekrafta/avvisa matchningar kor nu via AJAX utan sidomladdning
  - Progress-bar med ETA visas under sokning
  - UNIQUE KEY tillagd pa `rider_id` i `scf_match_candidates` sa ON DUPLICATE KEY UPDATE fungerar korrekt
  - Ny stat-ruta "Ej i SCF" visar hur manga riders som sokts utan traff
  - "Aterstall ej hittade"-knapp for att tillata omsokning
- **Stripe webhook 404**: Stripe skickade till `/api/stripe-webhook.php` men filen lag pa `/api/webhooks/stripe-webhook.php`. Fixat med proxy-fil och HTTPS-undantag i .htaccess
- **Automatisk licensvalidering vid anmalan**: Nar en rider valjs for anmalan kontrolleras nu licensen automatiskt mot SCFs register. Resultatet visas direkt under riderns namn (gron/gul/rod). Integrerat i befintligt `event_classes`-API-anrop (noll extra latens). Hanterar bade UCI ID-lookup och namn-lookup for SWE-ID riders.
- **SCF name search foreach-bugg**: `lookupByName()` returnerar EN assoc array men koden anvande `foreach` som itererade over nyckel-varden istallet for resultat. Allt sparades som NULL. Fixat i bade single search och batch search.
- **htmlspecialchars null-varningar**: Fixat i scf-name-search.php med `?? ''` for potentiellt null-varden
- **SCF API diagnostik**: SCFLicenseService trackar nu `lastError` och `lastHttpCode` per anrop
  - Batch-sokning gor en API-test forst och avbryter tidigt om API:t ar nere (HTTP != 200)
  - Auto-sokning stoppar om alla anrop i en batch misslyckas
  - Riders markeras INTE som `not_found` vid API-fel (forhindrar falska negativa)
  - Loggen visar HTTP-statuskod och felmeddelande for enklare felsökning
- **scf-match-review.php**: Fixat htmlspecialchars null-varning for `scf_uci_id`, doljer numeriska nationalitetskoder (t.ex. "161"), lade till saknad `unified-layout-footer.php`
- **Nationalitetskoder standardiserade** (migration 047): Alla filer anvander nu korrekt ISO 3166-1 alpha-3
  - DEN→DNK, GER→DEU, SUI→CHE, NED→NLD
  - Legacy-koder mappas vid visning i admin/rider-edit.php och riders.php
  - Flaggor i riderprofil (`pages/rider.php`) anvander `flagcdn.com` med alpha-3→alpha-2 mappning
  - "Annan" (tom strang) tillagd som alternativ i reset-password.php och rider-edit.php
  - DB-migration uppdaterar befintliga riders med felaktiga koder
- **Umami analytics pa publika sidor**: Tracking-skriptet saknades i `components/head.php` - bara admin (unified-layout.php) hade det
- **Rabattkoder redigeringsfunktion**: discount-codes.php saknade edit-funktionalitet helt (bara create/toggle/delete fanns). Lagt till update-handler, redigeringsknapp och modal
- **Rabattkoder berakningsbugg FIXAD**: Procentuella rabattkoder beraknades pa ORDINARIE pris istallet for priset EFTER andra rabatter (t.ex. Gravity ID). 90% rabattkod + 100kr Gravity ID pa 1000kr = 0kr (FEL) istallet for 90kr (RATT). Fixat i bade `createOrder()` och `applyDiscountToOrder()` i payment.php
- **Event startlista kolumnbredder**: Tabellen for anmalda deltagare hade obalanserade kolumnbredder (Namn tog nastan all plats). Fixat med procentbaserade bredder: Startnr 10%, Namn 35%, Fodd 10%, Klubb 30%, Status 15%
- **Besoksstatistik tom (Umami API URL)**: `site-analytics.php` anvande `https://api.umami.is` men Umami Cloud API kraver `/v1`-prefix: `https://api.umami.is/v1`. Alla API-anrop returnerade 404 darfor visades ingen data
- **Serieanmalan trasig (scope-bugg)**: `showLicenseLoading`, `showLicenseValidation`, `showCreateRiderForm`, `handleCreateRider` och `getCreateRiderFormHtml` var definierade inne i event-registreringens IIFE men anropades fran serieanmalans separata IIFE → `ReferenceError` som stoppade klassladdning. Fixat genom att exponera funktionerna via `window._*` och andra IIFE-lokala variabelreferenser till `document.getElementById()`
- **Admin orders betalmetod**: Ordersidan visar nu vilken betalmetod kunden valt (Swish/Kort/Ej paborjad)
  - `payment_method` kolumnen i `orders`-tabellen lagrar detta (swish/card/manual/free)
  - Default ar `card` vid orderskapande - uppdateras till `swish` om kunden klickar "Jag har Swishat"
  - `gateway_transaction_id` visar om en Stripe-session ens skapades
  - "Ej paborjad" visas for ordrar dar kunden aldrig valde betalmetod
  - Expanderad ordervy visar betalmetod, gateway, session-ID och referens

### Nationalitetskoder (ISO 3166-1 alpha-3 STANDARD)
- **riders.nationality** lagrar ISO 3166-1 alpha-3 koder (SWE, NOR, DNK, FIN, DEU, etc.)
- **clubs.country** lagrar FULLA landsnamn ("Sverige", "Norge", etc.) - ANNORLUNDA an riders!
- Korrekt kod for Danmark ar `DNK` (INTE `DEN`)
- Korrekt kod for Tyskland ar `DEU` (INTE `GER`)
- Korrekt kod for Schweiz ar `CHE` (INTE `SUI`)
- Korrekt kod for Nederlanderna ar `NLD` (INTE `NED`)
- Legacy-koder mappad i rider-edit.php och riders.php for bakatkompatibilitet
- Flaggor pa riderprofil: `flagcdn.com/24x18/{alpha-2}.png` med alpha-3→alpha-2 mappning
- UNDANTAG: Nationsflaggor i riderprofiler ar OK trots inga-emojis-regeln (anvander IMG-taggar, inte emojis)

### scf_match_candidates tabell
- Status-enum: `pending`, `confirmed`, `rejected`, `auto_confirmed`, `not_found` (migration 046)
- UNIQUE KEY pa `rider_id` - en entry per rider (migration 046)
- `not_found` = rider soktes i SCF men ingen matchning hittades (skippa vid omsokning)
- `rejected` = admin avvisade matchningen (rider kan sokas igen)

### Licensvalidering vid anmalan (getEligibleClassesForEvent)
- Licenskontroll sker inne i `getEligibleClassesForEvent()` i order-manager.php
- Returnerar `$licenseValidation` via referensparameter till orders.php
- **Strategi 1:** UCI ID-lookup (for riders med riktigt UCI ID, >= 9 siffror)
- **Strategi 2:** Namn-lookup (for SWE-ID riders eller om UCI-lookup misslyckades)
- Om SWE-ID rider hittas i SCF: `license_number` uppdateras automatiskt till riktigt UCI ID
- Skippar SCF-kontroll om rider redan ar verifierad for event-aret (`scf_license_year`)
- Frontend visar resultat i `licenseValidationResult`-div under riderns namn

## TIDIGARE FIXAR (2026-02-12)

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
