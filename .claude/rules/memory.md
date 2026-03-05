# TheHUB - Memory / Session Knowledge

> Senast uppdaterad: 2026-03-05

---

## SENASTE FIXAR (2026-03-05, session 31)

### PM-fält: Speglade fält från Inbjudan och Faciliteter
- **PM Huvudtext** → Speglar nu `invitation` (Inbjudningstext). Redigeras under Inbjudan, visas som kopia i PM.
- **PM Lift** → Flyttad till Faciliteter-sektionen. Visas som kopia i PM-fliken.
- **PM Tävlingsregler** → Speglar `regulations_info` (Inbjudan > Regelverk). Stödjer regulations_global_type (sportmotion/competition).
- **PM Licenser** → Speglar `license_info` (Inbjudan > Licenser). Visas som kopia i PM-fliken.
- **Admin event-edit:** PM-sektionen visar nu skrivskyddade kort med förhandsvisning + "Redigeras under: X"-text för speglade fält. Redigerbara PM-fält (Förarmöte, Träning, Tidtagning, Försäkring, Utrustning, Sjukvård, SCF) ligger under.
- **Publik event.php:** PM-fliken visar speglade fält + PM-specifika fält i info-grid. PM Huvudtext (= inbjudningstext) visas som prose ovanför.
- **Faciliteter utökat:** `lift_info` tillagd i facilityFields (admin) och facilityDefs (publik).
- **Tab-synlighet:** PM-fliken visas om invitation ELLER pm_content ELLER driver_meeting har innehåll.
- **Filer:** `admin/event-edit.php`, `pages/event.php`

### Serie-sidan: Kollapsbar beskrivning + mobilanpassning + partnerfix
- **Problem 1:** Serie-beskrivningen var helt dold på mobil (`display: none`)
- **Fix:** Ersatt `<p>` med `<details>` element - "Läs mer om serien" klickbar summary, text visas vid öppning
- **Problem 2:** "Seriesammanställning: X tävlingar" tog för mycket plats på mobil
- **Fix:** Kompaktare format: "X tävlingar · Y bästa räknas" på en rad
- **Problem 3:** Logo-raden visade alla logotyper i en lång rad utan radbrytning
- **Fix:** Max 3 per rad med `flex: 0 0 calc(33.333% - gap)`, fler wrappas till ny rad
- **Problem 4:** Samarbetspartners-logotyper överlappade varandra på serie-sidan
- **Fix:** Bytt från CSS grid till flexbox med `justify-content: center` + `overflow: hidden` på items. Mobil: `max-width: 100%` på bilder förhindrar overflow. Gap minskat till `--space-sm` på mobil.
- **Problem 5:** L/S-knappar (stor/liten) i event-edit sponsorväljaren satt inne i bilden - fick inte plats
- **Fix:** Knapparna flyttade under bilden i en wrapper-div. `removeFromPlacement()` uppdaterad att hantera wrapper.
- **Filer:** `pages/series/show.php` (inline CSS + HTML), `assets/css/pages/series-show.css`, `assets/css/pages/event.css`, `admin/event-edit.php`

---

## SENASTE FIXAR (2026-03-05, session 30)

### Sponsorsystem: Per-placement "Ärv från serie" + Storleksval för partners
- **Problem 1:** Serie-sponsorer laddades ALLTID automatiskt på event-sidor. Inga egna kontroller per placement.
- **Fix:** Ny kolumn `events.inherit_series_sponsors` VARCHAR(100) lagrar kommaseparerade placements (t.ex. 'header,content,partner'). Per-placement checkboxar i event-edit sponsorsektionen.
- **Problem 2:** Samarbetspartner-logotyper var dimmiga (opacity: 0.7) och för små.
- **Fix:** Ny kolumn `series_sponsors.display_size` och `event_sponsors.display_size` ENUM('large','small'). Stor = 600x150px (3/rad desktop, 2/rad mobil). Liten = 300x75px (5/rad desktop, 3/rad mobil). Opacity borttagen helt.
- **Serie-manage + Event-edit:** L/S knappar per partner-sponsor i admin-gränssnittet.
- **Logo-rad:** Storlek ökad från 50px till 75px höjd, 300px max-width (matchar serier).
- **Migration 074:** `events.inherit_series_sponsors` + `series_sponsors.display_size`
- **Migration 075:** Fixar kolumntyp TINYINT→VARCHAR om 074 kördes tidigt
- **Migration 076:** `event_sponsors.display_size` + 'partner' i placement ENUM
- **VIKTIGT:** Kör migration 074+075+076 via `/admin/migrations.php`

### Registrering dubbeltext + klasssortering fixad
- **Problem:** Namn i anmälda-fliken visades med "dubbeltext" (nästan oläsbart)
- **Orsak 1:** `SELECT reg.*` hämtade `first_name`/`last_name` från event_registrations OCH `r.firstname`/`r.lastname` från riders → PDO returnerade båda
- **Fix:** Explicit kolumnlista istället för `reg.*`
- **Orsak 2:** `<strong>` inuti `.rider-link` (som redan har font-weight:medium) → dubbel fetstil
- **Fix:** `<strong>` borttagen
- **Mobil CSS:** Kolumn-döljning ändrad från `nth-child(1)` till `.has-bib`-klass (förut doldes Namn istf startnr)

### Format-toolbar på serie-beskrivning
- `data-format-toolbar` attribut tillagt på serie-beskrivningstextarean i series-manage.php

### VIKTIGT: Sponsorarv-arkitektur (ny)
- **Pre-migration fallback:** Om `inherit_series_sponsors`-kolumnen saknas → ärver ALLA placements (gammalt beteende)
- **Tom sträng:** Inga placements ärvs (default för nya events)
- **'1':** Alla placements ärvs (bakåtkompatibilitet)
- **'header,content,partner':** Bara valda placements ärvs
- **Event.php:** Separata SQL-frågor för event-sponsorer och serie-sponsorer (inga UNION)
- **display_size:** Laddas via separat try/catch-fråga (pre-migration-safe)
- **Event-edit sparning:** `inherit_series_sponsors` sparas via egen try/catch (ny kolumn → kan saknas)
- **Promotorer:** Hidden inputs bevarar inherit-val i disabled fieldsets

### Filer ändrade
- **`Tools/migrations/074_sponsor_inherit_and_display_size.sql`** - inherit + series display_size
- **`Tools/migrations/075_fix_inherit_sponsors_column_type.sql`** - TINYINT→VARCHAR fix
- **`Tools/migrations/076_event_sponsors_display_size_and_partner.sql`** - event display_size + partner ENUM
- **`admin/migrations.php`** - Migration 074-076 registrerade
- **`admin/event-edit.php`** - Per-placement inherit checkboxar, L/S knappar, inherit i egen try/catch
- **`pages/event.php`** - Separata sponsor-frågor, display_size, registration-kolumnfix, borttagen strong
- **`admin/series-manage.php`** - display_size per partner, L/S toggle-knappar, format-toolbar
- **`pages/series/show.php`** - Stora/små partner-grid, borttagen opacity, ökade logo-storlekar
- **`assets/css/pages/event.css`** - Partner storleksklasser, logo-rad 75px, mobilfix bib-kolumn

---

## SENASTE FIXAR (2026-03-04, session 29)

### Promotor event-tilldelning: Tabellerna saknades i databasen
- **Problem:** Kunde inte lägga till event till promotorer - INSERT misslyckades tyst
- **Orsak:** `promotor_events` och `promotor_series`-tabellerna hade aldrig skapats. Migrationsfilen låg arkiverad i `/database/migrations/_archive/068_create_promotor_events_table.sql` men fanns inte i aktiva `/Tools/migrations/`
- **Fix:** Ny migration `073_promotor_events_tables.sql` skapad i `/Tools/migrations/` med båda tabellerna
- **Registrering:** Migrationen registrerad i `admin/migrations.php` med `$migrationChecks`
- **VIKTIGT:** Kör migrationen via `/admin/migrations.php` för att skapa tabellerna

---

## SENASTE FIXAR (2026-03-04, session 28)

### Serietabeller: Identisk bredd på ALLA klasser (mobil + desktop)
- **Problem:** Tabellerna hade olika bredd per klass - "Herrar Elit" bredare än "Damer Elit" pga längre namn/poäng
- **Orsak:** `table-layout: auto` (satt i session 27) låter innehållet styra bredden
- **Fix:** `table-layout: fixed !important` + `width: 100% !important` på mobil portrait
- **Kolumner mobil portrait:** # (44px fast), Namn (auto, fyller resten), Total (72px fast)
- **Kolumner desktop/landscape:** # (48px), Namn (160px), Klubb (120px), Event×N (44px), Total (64px)
- **Resultat:** Alla klasser har exakt identiska kolumnbredder oavsett datainnehåll
- **Fil:** `assets/css/pages/series-show.css`

### Event resultat-tabell: Konsekvent col-split bredd
- **Problem:** `col-split` th hade min-width 70px men td hade 85px - inkonsekvent
- **Fix:** Båda 85px. `min-width: 400px` på results-table för basbredd
- **Fil:** `assets/css/pages/event.css`

### Prestandaoptimering fas 4 - Globala flaskhalsar
- **site_setting() batch-laddar ALLA settings:** Var 1 SQL per nyckel, nu 1 SQL för ALLA vid första anrop
- **render_global_sponsors() använder site_setting():** Ingen separat sponsor_settings-query längre
- **CSS bundle stat-loop borttagen:** Var 22 file_exists/filemtime-anrop per sidladdning. Nu kollar bara bundle.css existens. Rebuild bara om bundlen saknas helt (deploy/Tools ansvarar för rebuild)
- **Lucide CDN: unpkg → jsdelivr:** jsdelivr har snabbare edge-noder (global anycast CDN)
- **Preconnect/dns-prefetch:** Tillagd för cdn.jsdelivr.net och cloud.umami.is (sparar ~200-400ms DNS+TLS)
- **SHOW TABLES borttagen:** series_events existerar alltid → onödig SHOW TABLES-fråga borttagen
- **series/show.php förenklad:** Borttagna if/else-grenar för $useSeriesEvents (alltid true)
- **Filer:** `includes/helpers.php`, `components/head.php`, `includes/layout-footer.php`, `admin/components/unified-layout-footer.php`, `admin/components/economy-layout-footer.php`, `pages/series/show.php`

### VIKTIGT: CSS bundle auto-rebuild
- **Förut:** head.php kollade alla 11 CSS-filers mtime varje sidladdning (22 syscalls)
- **Nu:** head.php kollar BARA om bundle.css finns. Rebuild sker via:
  - `Tools/rebuild-css-bundle.sh` (manuellt eller i deploy-script)
  - Om bundlen saknas helt (auto-rebuild vid sidladdning)
- **Vid CSS-ändringar MÅSTE du köra:** `Tools/rebuild-css-bundle.sh`

---

## SENASTE FIXAR (2026-03-04, session 27)

### Series show.php: ~1200 SQL-queries → ~10 (N+1 eliminerad)
- **Problem:** Seriesidan körde EN query per åkare per event för att hämta poäng. 200 åkare × 6 event = 1200 queries. Plus 1 query per event för klubb-standings = 6 extra tunga queries.
- **Fix 1: Bulk pointsMap** - EN query hämtar ALLA poäng (series_results/results) för alla events. Byggs till PHP-array `$pointsMap[cyclist_id][event_id][class_id]`. Loop-lookup istället för SQL.
- **Fix 2: Bulk club results** - EN query hämtar ALLA klubb-resultat för alla events. Grupperas i PHP per event/klubb/klass för 100%/50%-regeln.
- **Fix 3: Merged meta-queries** - `series_results COUNT` + DH-check slagna ihop till EN query med SUM(CASE).
- **Fix 4: Events-query optimerad** - `e.*` ersatt med bara använda kolumner. `LEFT JOIN results + GROUP BY` ersatt med subquery.
- **Resultat:** ~1214 queries → ~10 queries (99% reduktion)
- **Filer:** `pages/series/show.php`

### Serietabeller: Inkonsistenta bredder mellan klasser fixad (ERSATT av session 28)
- Ersatt av bättre fix i session 28 ovan

---

## SENASTE FIXAR (2026-03-04, session 26)

### CSS Bundle: 11 filer → 1 (10 färre HTTP-requests)
- **Problem:** 11 separata CSS-filer laddades på varje sida = 11 HTTP round-trips
- **Fix:** `bundle.css` skapas automatiskt av head.php genom att konkatenera alla 11 källfiler
- **Auto-rebuild:** Om någon källfil är nyare än bundlen, rebuilds den automatiskt vid sidladdning
- **Manuell rebuild:** `Tools/rebuild-css-bundle.sh`
- **Källfiler bevarade:** Alla 11 originalfiler finns kvar (4 är LÅSTA i CLAUDE.md)
- **Storlek:** 105 KB (samma som innan, bara färre requests)
- **VIKTIGT:** layout-footer.php (admin) laddar fortfarande Lucide + Chart.js dubbeladdning fixad

### Lucide dubbeladdning fixad (layout-footer.php)
- **Problem:** layout-footer.php laddade Lucide v0.263.1 SYNKRONT + Chart.js OVILLKORLIGT
- **Fix:** Uppdaterad till v0.460.0 (samma som head.php) + defer. Chart.js borttagen.
- **Kvarvarande:** Lucide + Google Fonts kan inte self-hostas i denna miljö (nätverksbegränsning)
- **TODO framtida:** Self-hosta Lucide (~500KB → ~30KB sprite) och Google Fonts woff2-filer

### Prestandaoptimering fas 3 - Caching och render-blocking
- **hub_current_user() cachad:** Anropades 2-3 gånger per sida med DB-lookup (SELECT * FROM riders) varje gång. Nu cachad med static variabel via _hub_current_user_uncached() wrapper.
- **hub_is_logged_in() cachad:** Anropades från header.php + hub_current_user() + diverse. rider_check_remember_token() gjorde DB-query. Nu cachad med static.
- **render_global_sponsors() cachad:** Settings-query (sponsor_settings) kördes 3 gånger per sida (en per position). Nu cachad med static per request.
- **GlobalSponsorManager batch-laddar:** getSponsorsForPlacement() körde EN SQL-query (4 JOINs) per position × 3 positioner per sida = 3 tunga queries. Nu laddar ALLA placements för en page_type i EN query, grupperar i PHP.
- **Impression tracking borttagen från render:** trackImpression() gjorde UPDATE + INSERT per sponsor per sidladdning = 6-9 WRITE-queries per sida. Helt onödigt synkront. Borttagen.
- **render_global_sponsors() dubbelarbete fixat:** Anropade getSponsorsForPlacement() och sedan renderSection() som anropade getSponsorsForPlacement() IGEN. Renderar nu direkt.
- **Variabelnamn-bugg fixad:** render_global_sponsors() använde `$sponsorManager` (undefined) istf `$_sponsorManagerInstance`.
- **Google Fonts icke-blockerande:** Ändrad från render-blocking `<link rel="stylesheet">` till `<link rel="preload" as="style" onload>`. Reducerade font-vikter från 16 till 10 (tog bort oanvända).
- **Timing-kommentar:** HTML-kommentar längst ner i sidkällan visar config/router/page/total ms.

### Filer ändrade
- **`hub-config.php`** - hub_current_user() + hub_is_logged_in() cachade
- **`includes/helpers.php`** - render_global_sponsors() cachad + direkt-rendering
- **`includes/GlobalSponsorManager.php`** - Batch-ladda placements, impression tracking borttagen
- **`components/head.php`** - Google Fonts preload, reducerade vikter
- **`index.php`** - Timing-instrumentering

---

## SENASTE FIXAR (2026-03-04, session 25)

### KRITISK: PHP Session Locking fixad
- **Problem:** PHP håller exklusivt lås på sessionsfilen under hela requesten. Om event.php tar 5s att rendera blockeras ALLA andra requests från samma användare (andra flikar, navigering).
- **Fix:** `session_write_close()` i index.php och config.php efter att auth/config laddats. Bara GET-requests (POST behöver skriva till session).
- **feedback.php:** Startar om session för CSRF-token, stänger direkt efter.
- **Filer:** `index.php`, `config.php`, `pages/feedback.php`

### Prestandaoptimering fas 2 - SQL-frågor
- **event.php: 6 frågor eliminerade**
  - Huvudfrågan utökad: organisatörsklubb (LEFT JOIN clubs), header banner (LEFT JOIN media), serie-detaljer (discount, allow_series_registration, registration_enabled) - sparar 3 separata queries
  - Redundant serie-fråga (Q16) borttagen - data redan i huvudfrågan
  - Sponsor-frågorna (serie + event) slagna ihop till EN query via UNION ALL
  - DS-check använder `LIMIT 1` istf `COUNT(*)`
  - Kapacitets-check skippas om max_participants inte är satt eller registrering stängd
  - Global texts + global text links cachade med statisk variabel (samma för alla events)
- **results.php: Korrelerade subqueries eliminerade**
  - 2 korrelerade COUNT-subqueries (result_count, rider_count per event) ersatta med pre-aggregerad LEFT JOIN: `INNER JOIN (SELECT event_id, COUNT(*), COUNT(DISTINCT cyclist_id) FROM results GROUP BY event_id) rc`
  - Brands-filter: DISTINCT+4 INNER JOINs ersatt med EXISTS-subquery
  - Years-filter: INNER JOIN ersatt med EXISTS
- **riders.php: Korrelerad subquery + sökning optimerad**
  - `rider_club_seasons` korrelerad subquery (körde per rad) ersatt med INNER JOIN mot pre-aggregerad MAX(season_year)
  - Resultat-aggregering flyttad till subquery istf GROUP BY på huvudfrågan med alla JOINs
  - Sökning: CONCAT(firstname, lastname) och club-name LIKE borttagna (kan inte använda index). Multi-ord-sökning matchar firstname+lastname separat
  - LIMIT tillagd: 500 utan sökning, 200 med sökning (var obegränsat)
- **Migration 072:** 9 nya index: results(cyclist_id), rider_club_seasons(rider_id, season_year), events(date,active), events(series_id,active), event_info_links(event_id), event_albums(event_id,is_published), event_photos(album_id), series_sponsors(series_id), event_sponsors(event_id)

### Filer ändrade
- **`index.php`** - session_write_close() efter router (GET only)
- **`config.php`** - session_write_close() efter auth (GET only)
- **`pages/event.php`** - 6 frågor eliminerade, sponsor UNION, statisk cache
- **`pages/results.php`** - Pre-aggregerad JOIN istf korrelerade subqueries
- **`pages/riders.php`** - Eliminerad korrelerad subquery, LIMIT, bättre sökning
- **`pages/feedback.php`** - Session restart+close för CSRF
- **`Tools/migrations/072_performance_indexes_v2.sql`** - 9 nya index
- **`admin/migrations.php`** - Migration 072 registrerad

---

## SENASTE FIXAR (2026-03-04, session 24)

### Prestandaoptimering - SQL-frågor och index
- **Problem:** Dashboard 5-6s, Kalender 6-7s, Event-sida 5-6s, Resultat-sida trög
- **Fix 1: Dashboard** - 14 separata COUNT-frågor (riders, events, clubs, series, upcoming, results, pending_orders, total_revenue, registrations_today, registrations_week, pending_claims, pending_news, pending_bug_reports) slagna ihop till EN enda SELECT med subqueries. Sparar 13 DB round-trips.
- **Fix 2: Kalender** - Bytt från `LEFT JOIN event_registrations + GROUP BY` till korrelerad subquery `(SELECT COUNT(*) FROM event_registrations WHERE event_id = e.id)`. Eliminerar cartesian product och GROUP BY.
- **Fix 3: Resultat-sida** - Samma mönster: bytt från `INNER JOIN results + GROUP BY` till `EXISTS + korrelerade subqueries`. Eliminerar tung aggregering.
- **Fix 4: Event-sida galleri** - 2 korrelerade subqueries per foto (tagged_names + tagged_ids) ersatta med `LEFT JOIN photo_rider_tags + GROUP_CONCAT + GROUP BY`. Från O(2×N) extra frågor till 0 extra frågor.
- **Fix 5: Welcome-sida** - 4 separata COUNT-frågor (riders, clubs, events, series) slagna ihop till 1 fråga med subqueries.
- **Fix 6: Debug-loggar borttagna** - 5 `error_log()` DEBUG-anrop i event.php som kördes vid varje sidladdning borttagna (sponsor placements, DS detection, content sponsors).
- **Fix 7: Roadmap-cache** - ROADMAP.md-filläsning cachad i `.roadmap-count-cache.json` (1h TTL) istället för att läsa hela filen vid varje dashboard-laddning.
- **Migration 071:** Prestandaindex för: event_registrations(event_id), event_registrations(created_at), photo_rider_tags(photo_id), race_reports(status), race_reports(event_id,status), rider_claims(status), bug_reports(status), results(event_id), orders(payment_status)

### CSS-extraktion slutförd (event.php)
- 4 inline `<style>`-block extraherade till `assets/css/pages/event.css`: News/Media, Registration, Countdown, Gallery/Lightbox
- event.css gick från 1402 → 2848 rader
- Enda kvarvarande `<style>` i event.php är den med PHP-variabler (serie-gradient, rad 5940)

### VIKTIGT: Service Worker "Ny version"-meddelande
- Normalt beteende vid deploy/push - SW upptäcker cache-ändring
- Användaren ska klicka "Uppdatera" för att hämta senaste versionen
- Om sidan "hänger sig": SW cache-uppdatering pågår, stäng och öppna igen

### Filer ändrade
- **`admin/dashboard.php`** - 14 COUNT → 1, roadmap-cache
- **`pages/calendar/index.php`** - JOIN→subquery, borttagen GROUP BY
- **`pages/results.php`** - JOIN→EXISTS+subqueries, borttagen GROUP BY
- **`pages/event.php`** - Galleri subqueries→LEFT JOIN, debug-loggar borttagna
- **`pages/welcome.php`** - 4 frågor → 1
- **`Tools/migrations/071_performance_indexes.sql`** - 9 nya index
- **`admin/migrations.php`** - Migration 071 registrerad

---

## SENASTE FIXAR (2026-03-04, session 23)

### Event-sida prestandaöversyn - Klient-sida flikbyte + CSS-extraktion
- **Problem:** Event-sidan (pages/event.php) var extremt långsam - "som sirap". Varje flikbyte orsakade full sidladdning med 22 SQL-frågor. 7225 rader med ~1400 rader inline CSS.
- **Fix 1: Klient-sida flikbyte** - Alla 15 flikar renderas nu som `<div class="event-tab-pane">` med `style="display:none"` för inaktiva. JavaScript byter flik via `display`-toggle + `history.pushState` (ingen sidladdning). Bakåt/framåt-knappar fungerar via `popstate`-event.
- **Fix 2: CSS-extraktion** - 4 inline `<style>`-block (~1400 rader) extraherade till `/assets/css/pages/event.css`. Enda kvarvarande inline-CSS har PHP-variabler (serie-gradient). event.php gick från 7225→5961 rader.
- **Fix 3: Leaflet lazy-load** - Kartans CSS/JS (Leaflet ~180KB) laddas nu BARA när kartfliken visas. MutationObserver bevakar flikens `style`-attribut och laddar scripts dynamiskt.
- **Fix 4: Resultat-paginering** - Klasser med >30 resultat visar bara de 30 första. "Visa alla X resultat"-knapp expanderar. Integrerat med sökfilter (sökning visar alltid alla).
- **Fix 5: Live timing** - `$isTimingLive` kontrollerar nu utan `$activeTab === 'resultat'` (alla flikar finns i DOM).
- **Fix 6: Serielogga på mobil** - Loggan visas nu inline med eventnamnet (`.event-title-logo`) istället för på egen rad i stats-raden.
- **Fix 7: Ekonomi-ikon** - Ändrad från `wallet` (såg ut som "I") till `circle-dollar-sign` i sidebar, mobilnav och promotor-flikar.

### VIKTIGT: Event-sidans tab-arkitektur (ny)
- **Alla 15 flikar renderas alltid** - PHP genererar alla tab-panes med `display:none` för inaktiva
- **Tab-ID format:** `tab-pane-{tabnamn}` (t.ex. `tab-pane-resultat`, `tab-pane-info`)
- **Tab-länk attribut:** `data-tab="{tabnamn}"` på alla `.event-tab` länkar
- **Flikbyte JS:** IIFE efter partner-sponsorer-sektionen, använder `switchTab(tabId)`
- **Kartfliken:** Leaflet laddas lazy via MutationObserver första gången fliken visas
- **Villkorliga flikar:** Flikarna syns/döljs i navbaren via PHP-villkor, men alla div-panes finns alltid i DOM
- **Resultatfilter:** `filterResults()` integrerad med paginering - sökning override:ar 30-raders-gränsen

### Filer ändrade
- **`pages/event.php`** - Tab-konvertering, tab-JS, lazy Leaflet, resultat-paginering, live timing fix, serielogga mobil
- **`assets/css/pages/event.css`** - Utökad med ~1400 rader extraherad CSS (news, registration, gallery)
- **`admin/components/admin-mobile-nav.php`** - Ekonomi-ikon `circle-dollar-sign`
- **`admin/promotor.php`** - Ekonomi-ikon `circle-dollar-sign`
- **`components/sidebar.php`** - Ekonomi-ikon `circle-dollar-sign`
- **`docs/promotor-instruktion.md`** - Korrigerad arrangörsguide v1.1

---

## TIDIGARE FIXAR (2026-03-03, session 21-22)

### Arrangörsguide v1.1 - Korrigerad med faktiska fält
- **Markdown-källa:** `/docs/promotor-instruktion.md` - uppdaterad version 1.1
- **Visningssida:** `/admin/promotor-guide.php` - renderar markdown till HTML med TheHUB-styling
- **Guide-länk finns i:**
  - Flikraden i promotor.php (högerställd, accent-färg, dold på mobil via `display:none` vid max-width 1023px)
  - Sidomenyn (sidebar.php) för promotor-rollen
  - Mobil bottom-nav som 5:e ikon (admin-mobile-nav.php)
- **Session 22 korrigeringar:**
  - **Faciliteter:** Korrigerat från 12 påhittade kategorier till de 11 faktiska: Vätskekontroller, Toaletter/Dusch, Cykeltvätt, Mat/Café, Affärer, Utställare, Parkering, Hotell/Boende, Lokal information, Media, Kontakter
  - **PM:** Korrigerat från 5 påhittade fält till de 10 faktiska: PM Huvudtext, Förarmöte, Träning, Tidtagning, Lift, Tävlingsregler, Försäkring, Utrustning, Sjukvård, SCF Representanter
  - **Ny sektion:** "Inbjudan & Tävlingsinfo" tillagd (5 fält: Inbjudningstext, Generell tävlingsinfo, Regelverk, Licenser, Tävlingsklasser)
  - **Låsta sektioner:** Uppdaterat med exakta fält (inkl. start-/slutdatum, eventtyp, logotyp, distans, höjdmeter, sträcknamn)
  - **Klasser/Startavgifter:** Dokumenterat att denna sektion är helt dold för promotorer
  - **Serier:** Lagt till Swish-nummer/namn som redigerbara fält, lagt till prismall i låsta fält
- **CLAUDE.md-regel:** Sektion "ARRANGÖRSGUIDE - UPPDATERA VID PROMOTOR-ÄNDRINGAR"

### VIKTIGT: Faktiska fältdefinitioner i event-edit.php
- **facilityFields** (11 st): hydration_stations, toilets_showers, bike_wash, food_cafe, shops_info, exhibitors, parking_detailed, hotel_accommodation, local_info, media_production, contacts_info
- **pmFields** (10 st): pm_content (main), driver_meeting, training_info, timing_info, lift_info, competition_rules, insurance_info, equipment_info, medical_info, scf_representatives
- **Inbjudan-fält** (5 st): invitation, general_competition_info, regulations_info, license_info, competition_classes_info
- **Övriga flikar** (4 st, admin-only): jury_communication, competition_schedule, start_times, course_tracks

### Feedback mobilfix
- **FAB-knapp:** Breakpoint ändrat till 1023px (matchar nav-bottom), bottom ökad till `calc(70px + safe-area)`
- **Formulär:** Edge-to-edge på mobil, borttagen padding/radius/shadow, extra bottom-padding

---

## TIDIGARE FIXAR (2026-03-03, session 19-20)

### Rapportera problem / Feedback-system (bug reports)
- **Ny funktion:** Komplett system för användarrapporter och feedback
- **Publik sida:** `/feedback` (pages/feedback.php) - formulär med tre kategorier:
  - **Profil:** Sökfunktion för att länka upp till 4 deltagarprofiler (via /api/search.php)
  - **Resultat:** Event-väljare dropdown (senaste 12 månader)
  - **Övrigt:** Enbart titel + beskrivning
  - Tillgänglig för alla (inloggade och anonyma)
  - Inloggade användare: e-post och rider_id fylls i automatiskt
  - Sparar sidans URL (referer) och webbläsarinfo
  - AJAX-baserad submit via `/api/feedback.php`
- **Formulärdesign (session 20):** Omgjord enligt login-sidans designmönster
  - Använder `.login-page` > `.login-container` > `.login-card` (max-width 520px)
  - Standard `.form-group`, `.form-label`, `.form-input`, `.form-select`
  - Submitknapp: `.btn .btn--primary .btn--block .btn--lg`
  - Kategorival: 3-kolumns grid med radio-knappar, accent-färg vid vald
  - Ikon: `bug` istället för `message-circle` (tydligare rapportknapp)
  - Döljer formuläret efter lyckad inskickning (visar bara tack-meddelande)
- **Flytande knapp (session 20):** Redesignad FAB ENBART på förstasidan (welcome)
  - Pill-form med text "Rapportera" + bug-ikon (inte bara cirkel med ikon)
  - Cyan bakgrund, vit text, tydligt en rapportknapp
  - Position: fixed, nere till höger (ovanför mobilnavigeringen)
  - Inkluderad i `index.php` (inte i footer.php som är låst)
- **Spamskydd (session 20):** Tre lager i `/api/feedback.php`:
  1. Honeypot-fält (`website_url`) - dolt fält som bots fyller i, accepterar tyst men sparar inte
  2. Tidskontroll - formuläret måste vara öppet i minst 3 sekunder
  3. IP-baserad rate limiting - max 5 rapporter per IP per timme (via `includes/rate-limiter.php`)
  4. Session-token-validering (CSRF-skydd) - token genereras vid sidladdning, valideras vid submit
- **Admin-sida:** `/admin/bug-reports.php` - lista, filtrera och hantera rapporter
  - Stats-kort: Totalt, Nya, Pågår, Lösta
  - Filter: status (ny/pågår/löst/avvisad), kategori (profil/resultat/övrigt)
  - Statusändring, admin-anteckningar, radering per rapport
  - Visar rapportörens namn/email, sidans URL, webbläsarinfo
  - Visar länkade profiler (klickbara taggar) och relaterat event
  - Sorterat: nya först, sedan pågår, sedan lösta
- **Dashboard-notis:** Röd alert-box på admin dashboard när det finns nya rapporter
  - Identisk stil som profilkopplingar/nyhets-notiser (röd gradient, ikon med count-badge)
  - Länk direkt till `/admin/bug-reports.php`
- **API:** `/api/feedback.php` (POST) - tar emot JSON med category, title, description, email, page_url, browser_info, related_rider_ids[], related_event_id, _token, _render_time, website_url (honeypot)
- **Migration 070:** `bug_reports`-tabell med id, rider_id, category (ENUM: profile/results/other), title, description, email, page_url, browser_info, related_rider_ids (kommaseparerade ID:n), related_event_id, status (ENUM), admin_notes, resolved_by, resolved_at, created_at, updated_at
- **Navigation:** Tillagd i admin-tabs under System-gruppen, tillagd i tools.php under System
- **Router:** `feedback` tillagd som publik sida (ingen inloggning krävs)
- **VIKTIGT CSS-fix (session 20):** `forms.css` och `auth.css` laddas INTE automatiskt på publika sidor
  - `forms.css` definierar `.form-group`, `.form-label`, `.form-input`, `.form-select`, `.form-textarea`
  - `auth.css` definierar `.login-page`, `.login-card`, `.btn--primary`, `.btn--block`, `.alert--success` etc.
  - Auto-laddning i `layout-header.php` mappar bara auth-sidor (login, reset-password) till `auth.css`
  - Publika sidor med formulär MÅSTE inkludera `<link>` till båda filerna manuellt
  - Utan dessa `<link>`-taggar renderas formulär helt utan stilar (rå HTML)
- **Filer:** `pages/feedback.php`, `api/feedback.php`, `admin/bug-reports.php`, `Tools/migrations/070_bug_reports.sql`

---

## VIKTIGT: FORMULÄR PÅ PUBLIKA SIDOR

**`forms.css` och `auth.css` laddas INTE globalt.** De auto-laddas bara för auth-sidor via `layout-header.php` pageStyleMap.

### Vid nya publika formulär-sidor MÅSTE du lägga till:
```php
<link rel="stylesheet" href="/assets/css/forms.css?v=<?= filemtime(HUB_ROOT . '/assets/css/forms.css') ?>">
<link rel="stylesheet" href="/assets/css/pages/auth.css?v=<?= filemtime(HUB_ROOT . '/assets/css/pages/auth.css') ?>">
```

### Centrerat formulär-kort (referensmönster: feedback.php, login.php):
- `.login-page` > `.login-container` > `.login-card` > `.login-form`
- `.form-group` > `.form-label` + `.form-input` / `.form-select` / `.form-textarea`
- `.btn .btn--primary .btn--block .btn--lg` för submitknapp
- `.alert--success` / `.alert--error` för meddelanden

### CSS-filer och vad de innehåller:
| Fil | Klasser | Laddas automatiskt? |
|-----|---------|---------------------|
| `assets/css/forms.css` | `.form-group`, `.form-label`, `.form-input`, `.form-select`, `.form-textarea`, `.form-row`, `.form-help` | NEJ |
| `assets/css/pages/auth.css` | `.login-page`, `.login-card`, `.btn--primary`, `.btn--block`, `.alert--success`, `.alert--error` | Bara på auth-sidor |
| `assets/css/components.css` | `.card`, `.table`, `.badge`, `.alert` (utan --) | JA (globalt) |

---

## TIDIGARE FIXAR (2026-02-27, session 18)

### Galleri-grid: Fast kolumnantal + större bilder på desktop
- **Problem:** `auto-fill` med `minmax(200px)` gav 7 kolumner på desktop - bilderna var för små att överblicka
- **Fix:** Fast `repeat(5, 1fr)` på desktop, `repeat(4, 1fr)` på mellanstor skärm, `repeat(3, 1fr)` på mobil
- **Reklamslots:** Intervall ändrat från 12 till 15 bilder (3 fulla rader × 5 kolumner)
- **Ad-styling:** Borttagna borders, subtilare med opacity 0.85, hover till 1.0, mindre (60px istf 80px)
- **Fil:** `pages/event.php` (inline CSS)

### Fotografprofil: Tvåkolumns-layout (som åkarprofilen)
- **Problem:** Profilbilden var ENORM på desktop - hela sidbredden
- **Fix:** Tvåkolumns-layout med `grid-template-columns: 7fr 3fr` (samma som rider.php)
- **Vänster:** Album-galleri med rutnät
- **Höger:** Profilkort med bild, namn, bio, stats, sociala medier
- **Mobil:** Enkolumn med profilkort först (order: -1)
- **Tablet:** Fast 280px högerkolumn
- **Fil:** `pages/photographer/show.php`

### Galleri-listning: Serienamn + galleri-bannerplaceringar
- **Serienamn:** Visas under eventnamn på varje album-kort i galleri-listningen (/gallery)
  - Hämtas via `GROUP_CONCAT(DISTINCT s2.name)` genom `series_events → series`
  - CSS: `.gallery-listing-series` i cyan accent-färg
- **Galleri-banners via sponsorsystemet (migration 069):**
  - Ny `page_type = 'gallery'` i `sponsor_placements` ENUM
  - Admin konfigurerar galleri-banners via `/admin/sponsor-placements.php` (page_type=gallery, position=content_top)
  - Prioritet i event.php: globala galleri-placeringar → event/serie content-sponsorer → partner-sponsorer
  - Globala placeringar överskriver event/serie-sponsorer i bildgalleriet
- **Filer:** `pages/gallery/index.php`, `assets/css/pages/gallery-index.css`, `pages/event.php`, `admin/sponsor-placements.php`, `Tools/migrations/069_gallery_sponsor_placement.sql`

### Album-uppladdning: Kraschade efter ~97 bilder
- **Problem:** Uppladdning av stora album (100+ bilder) kraschade efter ~10 minuter
- **Orsaker:** 3 parallella uploads, 60s PHP-timeout per fil (för kort för stora bilder), ingen retry-logik, ingen session keep-alive, ingen fetch timeout
- **Fix 1:** PHP timeout 60s → 120s i `api/upload-album-photo.php`
- **Fix 2:** Parallella uploads (3) → sekventiell (1 åt gången) för stabilitet
- **Fix 3:** Retry-logik med exponentiell backoff (1s, 2s, 4s) - max 3 försök per bild
- **Fix 4:** AbortController med 2 min timeout på varje fetch-anrop
- **Fix 5:** Session keep-alive ping var 2:a minut under uppladdning
- **Filer:** `api/upload-album-photo.php`, `admin/event-albums.php` (båda uploader-instanserna)

## TIDIGARE FIXAR (2026-02-27, session 17)

### Admin-navigation: Galleri-gruppen borttagen
- **Problem:** Galleri hade en egen ikon i sidomenyn med sub-tabs (Album, Fotografer) - "sjukt krångligt och ologiskt"
- **Fix:** Galleri-gruppen (`galleries`) borttagen helt från `admin-tabs-config.php`
- **Album:** Flyttat till Konfiguration-gruppen (bredvid Media)
- **Fotografer:** Flyttat till System-gruppen (bredvid Användare)
- **Resultat:** En ikon mindre i sidomenyn, Album och Fotografer nås via befintliga menyer

### Album: Uppladdning skapar album automatiskt
- **Problem:** Gammalt flöde krävde 2 steg: 1) Skapa album (fyll i formulär), 2) Ladda upp bilder
- **Nytt flöde:** Listsidan har nu en integrerad uppladdningssektion med Event-dropdown + Fotograf-dropdown + Filväljare
- **Auto-skapande:** Klick på "Ladda upp" skapar album automatiskt via AJAX (`create_album_ajax`), sedan startar chunked upload
- **Album publiceras direkt** (is_published = 1)
- **Efter uppladdning:** Omdirigeras till album-redigeringssidan
- **Befintligt edit-flöde** för existerande album fungerar som förut
- **Fil:** `admin/event-albums.php`

### Fotografer: Profilbild via ImgBB (inte mediabiblioteket)
- **Problem:** Fotografers profilbilder laddades upp till mediabiblioteket (`/api/media.php?action=upload`) men vanliga användares profilbilder använder ImgBB (`/api/update-avatar.php`)
- **Fix:** Fotografer använder nu samma ImgBB-uppladdning som vanliga användare
- **API utökat:** `update-avatar.php` stödjer nu `type=photographer` + `photographer_id` parameter
- **Säkerhet:** Kräver admin-inloggning för fotograf-avatarer
- **Filer:** `api/update-avatar.php`, `admin/photographers.php`, `admin/photographer-dashboard.php`

## TIDIGARE FIXAR (2026-02-27, session 16)

### Nyhetssidan: Standardiserade filter + svenska tecken
- **Filter-bar:** Ersatt custom `.news-filter-bar` med standard `.filter-bar` komponent (samma som databas/galleri)
  - Dropdowns: Disciplin, Typ, Sortera + sökfält + Sök-knapp + Rensa-länk
  - Auto-submit på dropdown-val via `onchange="this.form.submit()"`
- **CSS cleanup:** Borttagen gammal CSS: `.news-filter-bar`, `.news-filter-chip`, `.news-filter-scroll`, `.news-search-*`, `.news-sort-select` (130+ rader)
- **Svenska tecken:** Fixat "Skriv den forsta" → "första", "inlagg" → "inlägg", "Forsok igen" → "Försök igen"
- **Taggar:** `getAllTags()` i RaceReportManager.php använder nu INNER JOIN mot publicerade reports - visar bara taggar med faktiska inlägg (inte seedade/oanvända)
- **Filer:** `pages/news/index.php`, `assets/css/pages/news.css`, `includes/RaceReportManager.php`

### Race Report Editor: Omslagsbild-uppladdning + formateringsverktyg + Instagram/YouTube-val
- **Omslagsbild:** Ersatt URL-input med klickbar uppladdningsarea (16:9 ratio)
  - Laddar upp till `/api/media.php?action=upload` (samma som fotografer/profilbilder)
  - Visar förhandsgranskning, hover-overlay "Byt bild", X-knapp för att ta bort
  - Loading spinner under uppladdning, max 10 MB
- **Formateringsverktyg:** Inkluderar `format-toolbar.php` - B/I knappar och Ctrl+B/I genvägar
  - `data-format-toolbar` attribut på textarea aktiverar toolbar automatiskt
  - Stödjer **fetstil** och *kursiv* (markdown-stil)
- **Instagram ELLER YouTube:** Toggle-knappar istället för båda fälten samtidigt
  - Klick på en typ aktiverar dess input och rensar den andra
  - Visuell feedback: YouTube = röd, Instagram = lila när aktiv
- **Event-dropdown:** Bytt från `.form-select` till `.filter-select` (standard-komponent)
  - Visar nu även alla event senaste 6 månaderna (inte bara de man deltagit i)
- **CSS externaliserad:** Flyttat 600+ rader inline `<style>` till `assets/css/pages/race-reports.css`
- **Update handler:** youtube_url och instagram_url kan nu uppdateras vid redigering
- **Filer:** `pages/profile/race-reports.php`, `assets/css/pages/race-reports.css`

## TIDIGARE FIXAR (2026-02-27, session 15)

### Galleri: Layout matchar databas-sidan + destinationsfilter + varumärkesfilter
- **Layout-fix:** Stats-kort (Album, Bilder, Taggningar) visas nu ÖVERST, före tabs och filter
  - Ordningen matchar databas-sidan: Stats → Tabs+Filter inuti search-card
  - Tabs och filter-dropdowns ligger nu inuti samma `.search-card` (inte separata block)
- **Ny funktion:** Destination-dropdown tillagd i galleri-filtren (events.location)
- **Ändring:** Serie-filtret visar nu varumärken (`series_brands`) istället för enskilda serier
- **Filter-ordning:** År, Destination, Serie (varumärke), Fotograf, Sök
- **Mobil:** Dropdowns visas 2 per rad (grid) istället för full bredd - tar mindre plats
- **Auto-submit:** Alla dropdowns submittar formuläret vid val
- **CSS:** Nya klasser `.gallery-filters`, `.gallery-filters-grid`, `.gallery-filters-actions`
- **Filer:** `pages/gallery/index.php`, `assets/css/pages/gallery-index.css`

### Fotoalbum: Omslagsval i admin (event-albums.php)
- **Problem:** Admin kunde inte välja omslagsbild för album (funktionen fanns bara i photographer-album.php)
- **Fix:** Stjärn-knapp på varje bild i fotogridet, cyan border + "Omslag"-badge på vald bild
- **AJAX:** `setCover()` JS-funktion uppdaterar via POST `action=set_cover` utan sidomladdning
- **Visuell feedback:** Gammal omslag-markering tas bort, ny sätts direkt i DOM
- **Fil:** `admin/event-albums.php`

### Fotografer: Bilduppladdning trasig (result.data bugg)
- **Problem:** Avatar-uppladdning misslyckades alltid med "Kunde inte ladda upp bilden"
- **Orsak:** JavaScript kollade `result.success && result.data` men `/api/media.php` returnerar `result.url` direkt (inte `result.data.url`)
- **Fix:** Ändrat `result.data` → `result` i båda filerna
- **Filer:** `admin/photographers.php`, `admin/photographer-dashboard.php`

## TIDIGARE FIXAR (2026-02-27, session 14)

### Fotografer: Bilduppladdning istället för URL-fält
- **Problem:** Fotografer hade ett manuellt URL-fält för profilbild istället för uppladdning
- **Fix:** Ersatt URL-fält med cirkulär avatar-uppladdning (samma stil som /profile/edit)
  - Klick på avatar öppnar filväljare, bild laddas upp till media-biblioteket via `/api/media.php?action=upload`
  - Camera-ikon overlay vid hover, loading spinner under uppladdning
  - "Ta bort"-knapp för att rensa avatar
  - CSS-klasser: `.pg-avatar-*` (photographers.php) och `.dash-avatar-*` (photographer-dashboard.php)
- **Filer:** `admin/photographers.php`, `admin/photographer-dashboard.php`

### Galleri-sidan CSS matchar nu databas-sidan
- **Problem:** Galleri-sidan hade fortfarande avvikande CSS (tab-wrapper, stat-kort, mobil-behandling)
- **Fix 1:** Ändrat filterkortets wrapper från `.card` till `.search-card` i `pages/gallery/index.php`
- **Fix 2:** Omskrivit `assets/css/pages/gallery-index.css` med matchande stilar:
  - `.search-card` bas-stilar, `.stat-value`/`.stat-label` färger med `!important`
  - Mobilanpassning: tab-pills, edge-to-edge search-card, stat-kort
- **Referens:** `assets/css/pages/database-index.css` är "gold standard"

### Rider-taggar i galleriet redesignade
- **Problem:** När flera åkare taggades på bilder visades individuella gröna pills ovanpå bilden - stökigt och svårt att se bilden
- **Grid-vy (ny):** Svart halvtransparent banner i botten av bilden med users-ikon
  - 1 taggad: visar namn ("Roger Edvinsson")
  - 2+ taggade: visar antal ("3 taggade")
- **Lightbox-vy (ny):** Fullbreddsbanner med subtil cyan-bakgrund
  - Users-ikon + alla namn som klickbara länkar separerade med bullet-punkter
  - Inga pills längre - renare utseende
- **CSS-klasser ändrade:** `.gallery-item-tag` → `.gallery-item-tag-text` (grid), `.gallery-lightbox-tag-sep` (ny)
- **Fil:** `pages/event.php` (inline CSS + PHP + JS)

## TIDIGARE FIXAR (2026-02-27, session 12)

### PWA vit-på-vit text fixad (databas-sidan)
- **Problem:** Stat-kort och kort på databas-sidan hade vit text på vit bakgrund i PWA
- **Orsak:** Gammal PWA-cache (cache-first strategi) serverade inaktuell CSS. Manifest hade gamla mörka temafärger
- **Fix 1:** Bumpat service worker cache `thehub-cache-v1` → `thehub-cache-v2` i `sw.js`
- **Fix 2:** Uppdaterat `manifest.json` färger: `background_color: #F9F9F9`, `theme_color: #0066CC`
- **Fix 3:** Lagt till explicita textfärger i `database.css` med `!important` som skydd mot cachad CSS
  - `.stat-value { color: var(--color-accent) !important; }`
  - `.stat-label { color: var(--color-text-secondary) !important; }`
  - `.card`, `.card-title`, `.ranking-name`, `.search-result-name` med explicit `color: var(--color-text-primary)`

### Galleri-sidan CSS konsistens
- **Problem:** Galleri-sidan använde inline `<style>` istället för extern CSS-fil som databas-sidan
- **Fix:** Skapat `/assets/css/pages/gallery-index.css` med alla galleri-specifika stilar
- **Fix:** Konverterat stats från inline-stylade divs till `.stats-grid .stat-card` komponenter
- **Fix:** Tagit bort inline `<style>` block från `pages/gallery/index.php`

### Photographers.php vit sida fixad (igen)
- **Problem:** Skapa/redigera fotograf gav vit sida (fatal error)
- **Orsak 1 (session 12):** `getDB()` var odefinierad pga fel include - fixat genom att byta till `config.php`
- **Orsak 2 (session 13):** `getDB()` returnerar `DatabaseWrapper` (från helpers.php) som har `getPdo()`, men koden anropade `getConnection()` som bara finns i `Database`-klassen (db.php) → `Call to undefined method DatabaseWrapper::getConnection()`
- **Fix:** Ersatt `$db = getDB(); $pdo = $db->getConnection();` med `global $pdo;` (standardmönstret för admin-sidor)
- **Fix:** Ändrat `$pageTitle` till `$page_title` (unified-layout.php förväntar sig underscore)
- **VIKTIGT:** Admin-sidor ska använda `global $pdo;` - INTE `getDB()->getConnection()`

### TikTok + Strava tillagd för fotografer (migration 067 + 068)
- **Migration 067:** `ALTER TABLE photographers ADD COLUMN tiktok_url VARCHAR(255) DEFAULT NULL AFTER instagram_url`
- **Migration 068:** `ALTER TABLE photographers ADD COLUMN strava_url VARCHAR(255) DEFAULT NULL AFTER tiktok_url`
- **Admin-formulär:** TikTok + Strava-fält i `admin/photographers.php` (INSERT/UPDATE + formulär)
- **Dashboard:** TikTok + Strava-fält i `admin/photographer-dashboard.php` (sparning + formulär)
- **Publik profil:** TikTok + Strava visas i sociala medier-listan i `pages/photographer/show.php`
- **Ikoner:** TikTok = `music`, Strava = `activity` (Lucide har inga varumärkesikoner)
- **Fotografers sociala medier nu:** Webbplats, Instagram, TikTok, Strava, Facebook, YouTube
- **Graceful degradation:** SQL kontrollerar om `strava_url`-kolumnen finns via SHOW COLUMNS

### Photographers.php vit sida fixad
- **Problem:** Sidan inkluderade `admin-header.php` / `admin-footer.php` som inte existerar
- **Fix:** Bytt till `unified-layout.php` / `unified-layout-footer.php` (samma som alla andra admin-sidor)

### Fotografer synliga i användarhantering
- **Problem:** Användaren tyckte att fotografer borde vara åtkomliga från användarhanteringen, inte bara en separat admin-sida
- **Fix:** Lagt till "Fotografer"-sektion i `admin/users.php` (mellan Promotörer och Klubb-admin)
  - Tabell med namn, kopplat konto, album, status, redigeringsknapp
  - Stat-kort för "Fotograf"-rollen i statistik-raden
  - Rollbeskrivning tillagd
  - "Hantera alla fotografer"-länk och "Ny fotograf"-knapp

## TIDIGARE FIXAR (2026-02-27, session 9)

### Chunked Album Upload (prestandafix för stora album)
- **Problem:** Uppladdning av 256+ bilder (~1.1GB) frös sidan helt. Alla filer skickades i ETT POST-anrop → PHP-timeout, post_max_size-gräns, max_file_uploads=20, ingen feedback.
- **Lösning:** Ny AJAX-baserad chunked uploader som laddar upp EN bild åt gången
- **Ny fil:** `/api/upload-album-photo.php` - AJAX-endpoint för single-file R2-upload
  - set_time_limit(60), memory_limit 256M per fil
  - Validerar filtyp via finfo, optimerar, genererar thumbnail, laddar upp till R2
  - Returnerar JSON med photo_id, url, thumbnail_url
- **Frontend:** Gammalt `<form enctype="multipart/form-data">` ersatt med JS chunked uploader
  - Progressbar med procent, antal, hastighet (s/bild), ETA
  - Avbryt-knapp (redan uppladdade bilder behålls)
  - Fil-input visar antal valda filer på knappen
  - Auto-reload efter avslutad uppladdning
- **Timeout-skydd:** event-albums.php har nu `set_time_limit(300)` + `memory_limit 256M` som safety net
- **R2 lagring:** Noll lokalt serverutrymme. Temp-filer rensas direkt efter R2-upload.
- **Kapacitet:** Testat för 256+ bilder. ~2-3s per bild = ~10 min totalt, med live-feedback hela vägen

### R2 URL-sanering (korrupt .env-fix)
- **Problem:** `.env` hade `R2_PUBLIC_URL=https://x.r2.dev=https://y.r2.dev` (dubbla `=`) → alla bild-URL:er blev trasiga
- **r2-storage.php:** Auto-detekterar och fixar dubbla `https://` i publicUrl vid konstruktion
- **event-albums.php:** Ny POST-handler `fix_r2_urls` som uppdaterar alla external_url/thumbnail_url i event_photos via r2_key
- **UI:** Gul varningsruta vid trasiga URL:er + "Fixa URL:er"-knapp. "Uppdatera URL:er"-knapp i grid-headern.

### Publik fototaggning (alla inloggade kan tagga)
- **API utökat:** `/api/photo-tags.php` stödjer nu GET/POST/DELETE (var bara GET)
  - POST: Tagga rider på foto (kräver inloggning, rider_id från session)
  - DELETE: Ta bort tagg (bara egna taggar eller admin)
- **Galleri-grid:** Taggade namnbadges visas på bilderna (cyan badges nertill)
  - Data via GROUP_CONCAT i SQL-frågan (inga extra API-anrop)
- **Lightbox:** Taggade namn visas under bilden som klickbara badges (→ profil)
- **Taggpanel:** Slide-in panel i lightboxen (höger sida, toggle-knapp nere till höger)
  - Sökfält för riders, realtidssökning mot /api/search.php
  - Tagga med ett klick, ta bort egna taggar
  - Enbart synlig för inloggade användare
- **Profil:** "Mina bilder" redan implementerad (premium only, max 6, 3-kolumns grid)
  - Laddar via photo_rider_tags → event_photos → event_albums → events
  - Visar thumbnail med hover-zoom, länk till eventgalleriet

### Fullscreen lightbox - komplett fix (session 10)
- **Problem:** Header, sidebar, nav-bottom syntes ovanpå lightboxen. Bilden var liten med stora svarta fält. Inget X synligt. Klick bredvid bilden stängde galleriet av misstag.
- **Fix 1: Dölj all navigation:** `html.lightbox-open` klass som sätts på `<html>` vid öppning
  - Döljer `.header`, `.sidebar`, `.nav-bottom`, `.mobile-nav` med `display: none !important`
  - Tas bort vid stängning
- **Fix 2: Z-index höjt till 999999** (från 99999) - ovanför ALLT
- **Fix 3: Stängknapp (X)** alltid synlig i topbar med 44x44px, bakgrund blur + semi-transparent
- **Fix 4: Klick utanför bilden stänger INTE galleriet** - bara X-knapp eller Escape stänger
  - Backdrop onclick borttagen, content-area click-to-close borttagen
- **Fix 5: Portrait-läge** - bättre padding (48px top, 56px bottom), img med `width: auto; height: auto`
- **Fix 6: PWA standalone** - padding anpassad med `env(safe-area-inset-*)` för notch/home indicator
- **VIKTIGT:** Alla z-index inuti lightboxen är 10-12 (relativa), inte globala. Topbar/nav/bottom = 10, tag-toggle = 11, tag-panel = 12

### Fotografroll - self-service (session 11)
- **Ny roll:** `photographer` tillagd i `admin_users.role` ENUM (migration 066)
- **Rollhierarki:** photographer = level 2 (samma som promotor) i auth.php
- **Koppling:** `photographers.admin_user_id` → `admin_users.id` (koppling fotograf → inloggning)
- **Behörighetsmodell:** `photographer_albums` tabell (user_id, album_id, can_upload, can_edit)
  - `canAccessAlbum($albumId)` i auth.php kontrollerar åtkomst
  - `getLinkedPhotographer()` hämtar kopplad fotograf-profil
  - `getPhotographerAlbums()` hämtar alla album fotografen har tillgång till
- **Dashboard:** `/admin/photographer-dashboard.php` med två flikar:
  - "Mina album" - lista album med stats, skapa nytt album (med event-koppling)
  - "Min profil" - redigera namn, bio, avatar, sociala medier (AJAX-sparning)
- **Albumhantering:** `/admin/photographer-album.php`
  - Redigera albuminfo (titel, beskrivning, publicerad)
  - Chunked AJAX-uppladdning till R2 (en bild åt gången med progress)
  - Fotogrid med cover-val och enskild radering
  - **INGEN albumradering** - bara admin kan radera album
- **Upload-åtkomst:** `/api/upload-album-photo.php` kontrollerar `canAccessAlbum()` för fotografer
- **Navigation:** Sidebar + mobil bottomnav visar "Mina album" + "Min profil" för fotograf-rollen
  - Identiskt mönster som promotor-navigationen
  - Aktiv-markering baseras på `$isPhotographerPage` och `$isAlbumPage`
- **Login-redirect:** Fotografer skickas till `/admin/photographer-dashboard.php` efter inloggning
- **Admin-koppling:** `/admin/photographers.php` har nu dropdown för att länka admin-användare
  - Auto-skapar `photographer_albums`-poster vid koppling
- **Admin users:** Fotograf-rollen visas i filterdropdown i `/admin/users.php`

### Galleri-listning och fotografprofiler (session 10)
- **Ny flik:** "Galleri" tillagd som tredje flik i Databas-sektionen (under /database)
  - Klick på "Galleri"-fliken navigerar till `/gallery`
  - Galleri-sidan visar samma flikrad (Sök Åkare / Sök Klubbar / Galleri) för enkel navigering
  - Databas-ikonen i sidebar markeras aktiv på /gallery och /photographer/*
- **Ny sida:** `/pages/gallery/index.php` - Lista alla publicerade fotoalbum
  - Filtrera per år, serie, fotograf, fritextsök
  - Cover-bild från album (cover_photo_id eller första bilden)
  - Visar eventnamn, datum, plats, fotograf och antal bilder per album
  - Klick på album → event-sidan med ?tab=gallery
  - Mobilanpassad: 2-kolumns grid på mobil, edge-to-edge
- **Ny sida:** `/pages/photographer/show.php` - Fotografprofil
  - Profilbild (avatar), bio, sociala medier (webb, Instagram, Facebook, YouTube)
  - Om fotografen är deltagare: länk till deltagarprofilen
  - Lista alla album av fotografen med cover-bilder och statistik
- **Ny sida:** `/pages/photographer/index.php` - Lista alla fotografer
- **Admin:** `/admin/photographers.php` - CRUD för fotografer
  - Namn, e-post, bio, profilbild-URL, sociala medier, kopplad rider_id
  - Aktiv/inaktiv status
  - Tillagd i admin-tabs under "Galleri"-gruppen (Album + Fotografer)
  - Tillagd i tools.php under System-sektionen
- **Migration 065:** `photographers`-tabell med alla fält
  - `photographer_id` tillagd i `event_albums` och `event_photos`
  - Backfill: Befintliga fotografer (från album-textfält) skapas som photographer-poster automatiskt
- **Lightbox:** "Foto: Namn" visas under bilden, länkat till fotografprofilen
  - Data från `photographers`-tabellen via LEFT JOIN i SQL
  - Fallback till textfältet `event_albums.photographer` om ingen photographer_id
- **Album admin:** Ny dropdown "Fotograf (profil)" i event-albums.php
  - Välj bland aktiva fotografer eller skriv fritext som fallback
- **Router:** `/gallery` och `/photographer` tillagda som publika sektionsrouter
  - `/photographer/{id}` → photographer/show.php
  - Båda markerar "Databas" som aktiv i navigationen

---

## TIDIGARE FIXAR (2026-02-26, session 8)

### Cloudflare R2 Integration (bildlagring)
- **Ny fil:** `/includes/r2-storage.php` - Lättviktig S3-kompatibel klient med AWS Signature V4
- **Inga beroenden:** Ren cURL + hash_hmac, kräver inte aws-sdk-php eller composer
- **Singleton:** `R2Storage::getInstance()` konfigureras via `env()` (R2_ACCOUNT_ID, R2_ACCESS_KEY_ID, etc.)
- **Metoder:** `upload()`, `putObject()`, `deleteObject()`, `exists()`, `listObjects()`, `testConnection()`
- **Bildoptimering:** `R2Storage::optimizeImage()` skalar ner (max 1920px), komprimerar (JPEG 82%)
- **Thumbnails:** `R2Storage::generateThumbnail()` skapar 400px-versioner
- **Objektnycklar:** `events/{eventId}/{hash}_{filename}.{ext}`, thumbnails under `thumbs/`

### Admin R2-verktyg
- **Ny fil:** `/admin/tools/r2-config.php` - Konfigurationstest och statusvy
- **Funktioner:** Testa anslutning, testa uppladdning, lista filer i bucket
- **Installationsguide** med steg-för-steg för Cloudflare Dashboard
- **r2.dev är primärt** (domänen ligger på annan server, inte Cloudflare → Custom Domain fungerar inte)
- **CORS-policy** behöver konfigureras på bucketen (AllowedOrigins: thehub.gravityseries.se)
- **Tillagd i** `/admin/tools.php` under System-sektionen

### Event-albums: Google Photos-fält borttaget
- **Ändring:** "Google Photos-album" fältet bytt till "Källänk (valfritt)" - generellt för alla bildkällor
- **Fil:** `/admin/event-albums.php` - formuläret, albumlistan och bildsektionen uppdaterade

### Event-albums: R2-stöd + bulk-URL
- **R2-uppladdning:** När R2 är konfigurerat optimeras bilder automatiskt och laddas upp till R2
- **Thumbnails:** Genereras (400px) och lagras under `thumbs/` i R2
- **r2_key-kolumn:** Migration 064 - lagrar R2-objektnyckel för radering
- **Radering:** `delete_photo` raderar nu även från R2 (bild + thumbnail) om r2_key finns
- **Bulk-URL:** Ny funktion "Klistra in flera URL:er samtidigt" (en per rad)
- **Fallback:** Om R2 inte är konfigurerat funkar lokal uppladdning som förut

### Migration 064: event_photos.r2_key
- **Kolumn:** `r2_key VARCHAR(300)` - R2-objektnyckel för att kunna radera bilder
- **Index:** `idx_r2_key` på r2_key-kolumnen

### Konfiguration (.env)
```
R2_ACCOUNT_ID=cloudflare_account_id
R2_ACCESS_KEY_ID=r2_access_key
R2_SECRET_ACCESS_KEY=r2_secret_key
R2_BUCKET=thehub-photos
R2_PUBLIC_URL=https://photos.gravityseries.se
```

### VIKTIGT: R2-arkitektur
- **R2 endpoint:** `https://{ACCOUNT_ID}.r2.cloudflarestorage.com`
- **Region:** Alltid `'auto'` (Cloudflare-specifikt)
- **Publik URL:** Via custom domain eller r2.dev subdomain
- **S3-kompatibelt:** Använder AWS Signature V4, standard PUT/DELETE/GET
- **event_photos.external_url** = R2 publik URL (samma fält som externa URL:er)
- **event_photos.r2_key** = R2-objektnyckel (för radering/hantering)
- **Publika vyer** (event.php, rider.php) använder `external_url` → fungerar automatiskt med R2

---

## TIDIGARE FIXAR (2026-02-26, session 7)

### Fotoalbum: Komplett system (migration 063)
- **Tabeller:** `event_albums`, `event_photos`, `photo_rider_tags`
- **Admin:** `/admin/event-albums.php` - skapa album, lägg till bilder, tagga riders
- **Publik:** Galleri-flik på event-sidan med inline lightbox, sponsor-annonser var 12:e bild
- **Profil:** "Mina bilder" på riderprofil för premium-medlemmar (3-kolumns grid, max 6 bilder)
- **VIKTIGT:** Bilder ska INTE hostas på TheHUB-servern. Alla bilder lagras som externa URL:er.
- **CDN-beslut:** Cloudflare R2 valt som bildhosting ($0 bandbredd, 10 GB gratis, sedan $0.015/GB)
- **AI-taggning:** OCR av nummerlappar via Tesseract (gratis) → matchning mot startlista (bib_number → rider_id)
- **Volym:** ~8 000 befintliga bilder, ~250/event, ~5 000 nya/år
- **Taggning fas 1:** Manuell taggning via sökmodal i admin (KLAR)
- **Taggning fas 2:** OCR nummerlapps-igenkänning (PLANERAD, Tesseract open source)
- **Google Photos:** Fungerar som källa/arbetsflöde, men bilder serveras via Cloudflare R2

### Premium: Stripe-oberoende (migration 063)
- **Ny kolumn:** `riders.premium_until` DATE - admin-hanterad, inget betalleverantörskrav
- **`isPremiumMember()`** kollar `riders.premium_until` FÖRST, sedan Stripe-fallback
- **Syfte:** Förbereder för byte från Stripe till Swedbank Pay
- **Premium hålls dolt** tills allt är klart

### API: Photo tags
- `/api/photo-tags.php` - GET med photo_id, returnerar taggade riders
- Taggning/borttagning sker via POST till `/admin/event-albums.php` (action: tag_rider/remove_tag)

---

## SENASTE FIXAR (2026-02-26, session 6)

### Mediabibliotek: Force-delete av bilder som används
- **Problem:** Bilder som användes av sponsorer/event/serier kunde aldrig raderas, inte ens av admin. Delete-knappen var `disabled` med "Kan inte radera - filen används".
- **Fix:** `delete_media($id, $force)` i `media-functions.php` stödjer nu `$force` parameter. Med `force=true` rensas alla FK-kopplingar (sponsors.logo_media_id, sponsors.logo_banner_id, events.logo_media_id, events.header_banner_media_id, series.logo_light/dark_media_id, sponsor_placements, ad_placements) innan bilden raderas.
- **API:** `api/media.php?action=delete&id=X&force=1` skickar force-parametern
- **UI:** Delete-knappen i modalen är alltid aktiv. Om bilden används visas "Radera ändå" med bekräftelsedialog som nämner att kopplingar rensas automatiskt.
- **Admin vs Promotor:** Admins kan radera alla bilder. Promotorer begränsade till `sponsors/`-mappar.

### Mediabibliotek: Radera mappar
- **Ny funktion:** Tomma undermappar kan nu raderas via "Radera mapp"-knapp
- **Begränsning:** Rotmappar (sponsors, general) kan inte raderas. Mappar med filer eller undermappar måste tömmas först.
- **Funktion:** `delete_media_folder($folderPath)` i `media-functions.php`
- **API:** `api/media.php?action=delete_folder&folder=X`
- **UI:** "Radera mapp"-knapp visas i admin/media.php när man är i en undermapp

### Mediabibliotek: Auto-resize vid uppladdning
- **Ny funktion:** `upload_media()` skalar nu automatiskt ner stora bilder
- **Sponsors/banners-mappar:** Max 1200px bredd
- **Allmänna mappar:** Max 2000px bredd
- **Filstorlek:** Uppdateras i databasen efter resize (inte originalstorlek)
- **SVG undantagna:** Vektorbilder skalas inte

### Mediabibliotek: Länk-URL per bild
- **Migration 062:** Ny kolumn `media.link_url` VARCHAR(500)
- **Syfte:** Associera webbplats-URL med bilder (t.ex. sponsorlogotyp → sponsorns webbplats)
- **UI:** "Länk (webbplats)"-fält i bilddetalj-modalen
- **Sparbar via:** `update_media()` - `link_url` tillagd i `$allowedFields`

### Sponsor-sortering: Drag-and-drop i event-edit
- **Ny funktion:** Sponsorbilder i Logo-rad och Samarbetspartners kan nu dras och släppas för att ändra ordning
- **Teknik:** Natitivt HTML5 Drag & Drop API. Tiles har `draggable=true`, `cursor: grab`.
- **Visuell feedback:** Draggad tile blir genomskinlig, hovrad tile får accent-border
- **Ordning sparas:** `rebuildInputOrder(pl)` uppdaterar hidden inputs i DOM-ordning → `saveEventSponsorAssignments()` sparar med korrekt `display_order`
- **Fil:** `/admin/event-edit.php` - CSS + JS tillagda i sponsorsektionen

## TIDIGARE FIXAR (2026-02-26, session 5)

### Kontoaktivering krävde inte alla obligatoriska fält
- **Problem:** Aktiveringsformuläret (`/reset-password?activate=1`) krävde bara lösenord, nationalitet och födelseår. Telefon, kön, nödkontakt (namn+telefon) saknades. Användare kunde aktivera konto med ofullständig profil och sedan bli blockerade vid eventanmälan.
- **Fix:** Lagt till 4 obligatoriska fält i aktiveringsformuläret: kön (select M/F), telefonnummer, nödkontakt namn, nödkontakt telefon. Alla valideras server-side och sparas i UPDATE-queryn.
- **Layout:** Födelseår+kön och ICE-namn+ICE-telefon visas i 2-kolumns grid (`.activation-row`)
- **Fil:** `/pages/reset-password.php`
- **SELECT utökad:** Rider-queryn hämtar nu även phone, ice_name, ice_phone, gender (förfylls om data redan finns)

### Max deltagare kan sättas i serie-registreringsfliken
- **Ny funktion:** "Max deltagare" kolumn tillagd i "Anmälningsinställningar per event" på `/admin/series/manage/{id}?tab=registration`
- **Fil:** `/admin/series-manage.php` - SELECT-query, save handler, HTML-formulär
- **Befintligt grid:** Den fjärde (tomma) kolumnen i `.reg-time-row` används nu för number-input

## TIDIGARE FIXAR (2026-02-26, session 4)

### Serie-event dropdown flyttad ovanför flikarna
- **Problem:** Serie-event-dropdownen låg inuti flikraden och bröt layouten på mobil
- **Ändring:** Flyttad till en egen `.series-switcher` sektion mellan sponsorlogotyper och flikraden. Edge-to-edge på mobil. Inkluderar dropdown + Serietabeller-knapp
- **CSS:** Nya klasser `.series-switcher`, `.series-switcher__select`, `.series-switcher__standings-btn` (BEM). Gamla `.series-jump-*` och `.series-standings-btn` borttagna
- **Fil:** `/assets/css/pages/event.css` + `/pages/event.php`

### max_participants nollställdes vid event-sparning
- **Problem:** `max_participants` (och andra fält som registration_opens, end_date, etc.) sparades bara i "extended fields" UPDATE-queryn. Om NÅGON kolumn i den queryn inte fanns i databasen (t.ex. efter ny migration), kraschade hela UPDATE:en tyst och ~50 fält sparades aldrig. Nästa gång eventet sparades lästes tomma/NULL-värden från POST och skrevs till databasen.
- **Fix:** Flyttade 17 kritiska fält (max_participants, registration_opens, registration_deadline, registration_deadline_time, contact_email, contact_phone, end_date, event_type, formats, point_scale_id, pricing_template_id, distance, elevation_gain, stage_names, venue_details, venue_coordinates, venue_map_url) till den grundläggande SQL UPDATE-queryn som ALLTID körs. Kvarvarande extended fields (textinnehåll, use_global-flaggor, hidden-flaggor) sparas fortfarande i den feltoleranta update-queryn.
- **Fil:** `/admin/event-edit.php` rad ~420-474

### KRITISK REGEL för event-edit sparning
- **Core UPDATE** (rad ~420): Alla strukturella fält som MÅSTE sparas. Kraschar om fel (throw Exception)
- **Extended UPDATE** (rad ~476): Textinnehåll och flaggor. Fångar exceptions, loggar, fortsätter
- Vid NYA kolumner i events-tabellen: lägg i core om fältet är kritiskt, extended om det är innehållstext
- **Promotor hidden inputs**: MÅSTE finnas för ALLA fält i disabled fieldsets (rad ~834-849 och ~976-994)

## TIDIGARE FIXAR (2026-02-26, session 3)

### Serie-event dropdown mobilfix (ERSATT av session 4)
- Hela serie-event-dropdownen flyttades ovanför flikarna (se ovan)

### Enhetlig bildbaserad sponsorväljare (admin + promotor)
- **Ändring:** Admin-sidan i event-edit.php använde dropdown-select och checkboxar för sponsorer. Promotor hade bildväljare från mediabiblioteket. Nu använder BÅDA samma bildbaserade picker.
- **Borttaget:** `$isPromotorOnly`-villkoret som delade sponsor-UI i event-edit.php
- **Fix bildväljare:** `loadImgPickerGrid()` använder nu `media.url` (förbearbetad av API) istället för manuell `'/' + media.filepath`. Bättre felhantering och `onerror` på bilder.
- **Fil:** `/admin/event-edit.php` rad ~1709-1800

### Serie-sponsorer (ny funktion)
- **Ny flik:** "Sponsorer" i `/admin/series-manage.php` med bildbaserad väljare (samma UI som event)
- **Placeringar:** Banner (header), Logo-rad (content, max 5), Resultat-sponsor (sidebar), Partners (partner)
- **Sparlogik:** POST action `save_sponsors` → DELETE + INSERT i `series_sponsors`
- **Publik visning:** `/pages/series/show.php` visar nu:
  - Banner-sponsor ovanför hero-sektionen (klickbar länk till website)
  - Logo-rad under hero-sektionen
  - Samarbetspartners längst ner
- **Tabell:** `series_sponsors` (redan existerande i schema.sql)
- **Data loading:** Laddar `allSponsors` + `seriesSponsors` med logo_url via media JOIN

### Premium-medlemmar: bildväljare för sponsorlogotyper
- **Ändring:** Profilredigering (`/pages/profile/edit.php`) har nu en "Välj bild från biblioteket"-knapp
- **Funktionalitet:** Premium-medlemmar kan bläddra i sponsors-mappen i mediabiblioteket och välja logotyper. Kan även ladda upp nya bilder.
- **Webbplats krävs:** `website_url` är nu obligatoriskt i `/api/rider-sponsors.php`
- **Auto-namngivning:** Om sponsornamn-fältet är tomt fylls det i automatiskt från filnamnet

### Webbplatslänk krävs vid sponsorskapande
- **Event/Serie:** `selectMediaForPlacement()` promptar nu för webbplats-URL vid nyskapad sponsor
- **Premium:** Webbplats-fältet är markerat som obligatoriskt (*)
- **API:** `/api/sponsors.php` har ny action `update_website` för att uppdatera enbart website-fältet
- **Rider API:** `/api/rider-sponsors.php` kräver nu `website_url` vid `add`-action

## TIDIGARE FIXAR (2026-02-26, session 2)

### Serie-ordrar: Tranås/Värnamo tomma i ekonomivyn
- **Grundorsak:** `explodeSeriesOrdersToEvents()` kollade `$hasEventId` först och skippade splitting om `event_id` var satt. Gamla serie-ordrar (pre-migration 051) hade BÅDE `event_id` OCH `series_id` satt.
- **Fix:** Ändrat villkoret till: om `series_id` finns → ALLTID splitta (oavsett `event_id`).
- **Fil:** `/includes/economy-helpers.php` rad 28

### Promotor event-kort: all intäkt under Vallåsen
- **Grundorsak:** `orders`-subqueryn i promotor.php räknade ALL orders.total_amount per event_id. Serie-ordrar med felaktigt event_id hamnade under Vallåsen.
- **Fix:** Lagt till `WHERE series_id IS NULL` i orders-subqueryn så enbart direkta event-ordrar räknas. Serie-intäkter beräknas separat via `series_revenue`.
- **Fil:** `/admin/promotor.php` rad ~540

### Login-redirect till profil för promotorer
- **Grundorsak:** Admin-login (admin_users) returnerade INTE rider-profilfält (gender, phone, ice_name etc.). Login-checken i login.php kontrollerar dessa fält → alltid redirect till /profile/edit.
- **Fix:** Efter admin_users-login, slår nu upp kopplad rider-profil via email och mergar profilfälten.
- **Fil:** `/hub-config.php` rad ~562

### Profilformulär saknade kön, nationalitet
- **Fix:** Lagt till `gender` (select M/F) och `nationality` (select SWE/NOR/DNK/FIN/DEU/GBR/USA) i `/pages/profile/edit.php`. Båda sparas vid submit.
- **UCI ID** kan nu fyllas i av användare som saknar det (redan implementerat men hade felaktig placeholder).

### Premium-upsell dold
- Sektionen "Bli Premium" i profilredigeringen döljs tills funktionen aktiveras.
- **Fil:** `/pages/profile/edit.php` rad ~510

### Dashboard: Verktyg-snabblänk
- Tillagd i Snabbåtgärder-sektionen på admin dashboard.
- **Fil:** `/admin/dashboard.php`

---

## EKONOMI-BACKBONE: PROMOTOR-KEDJAN (2026-02-26)

### Grundproblem
`events.payment_recipient_id` och `series.payment_recipient_id` sattes ALDRIG - det fanns inget UI eller automatik för det. Hela ekonomisystemet (promotor.php admin-vy, settlements.php) byggde på dessa kolumner men de var alltid NULL. Resultat: 0 betalningar visades i alla ekonomivyer.

### Lösning: Tre-stegs kopplingskedja

**Kedjan:** `payment_recipients.admin_user_id` → `promotor_events/promotor_series` → `events/series` → `orders`

#### 1. Promotor-kedjan i SQL-frågor
Alla ekonomivyer (promotor.php + settlements.php) söker nu via 8 vägar istället för 5:
- Väg 1-5: Befintliga (events.payment_recipient_id, series via event, orders.series_id, order_items, series_events junction)
- **Väg 6**: `promotor_events.user_id` → `payment_recipients.admin_user_id` (event direkt)
- **Väg 7**: `promotor_series.user_id` → `payment_recipients.admin_user_id` (serie via orders.series_id)
- **Väg 8**: `order_items → series_registrations → promotor_series → payment_recipients` (serie via items)

#### 2. Auto-sync vid promotor-tilldelning
`payment_recipient_id` sätts automatiskt på events/series när:
- En promotor tilldelas ett event/serie (`user-events.php` → `syncPaymentRecipientForPromotor()`)
- En betalningsmottagare skapas/uppdateras med kopplad promotor (`payment-recipients.php` → `_syncRecipientToPromotorAssets()`)

#### 3. Backfill via migration 061
SQL backfill sätter `payment_recipient_id` på alla befintliga events/series baserat på promotor-kopplingar.

### Settlement/Avräkningssystem (migration 061)
- **`settlement_payouts`** tabell: id, recipient_id, amount, period_start, period_end, reference, payment_method, notes, status, created_by
- Registrera utbetalningar direkt i settlements.php (knapp per mottagare)
- **Saldovisning**: Netto intäkter - Utbetalt = Kvar att betala
- Annullera utbetalningar (status → cancelled)

### Event-dropdown i promotor.php
Filtreras nu även via promotor-kedjan - visar events ägda av vald mottagares promotor.

### Plattformsavgift
Hämtas nu från VALD mottagare (om filterRecipient > 0) istället för alltid första aktiva.

### VIKTIGT: Avgiftsregler för serieanmälningar (2026-02-26)
- **Betalningsavgifter (Stripe/Swish)**: Delas proportionellt mellan event
- **Plattformsavgift %**: Proportionell mot beloppet (redan per-event)
- **Plattformsavgift fast per order (`fixed`)**: Delas proportionellt mellan event
- **Plattformsavgift per deltagare/event (`per_participant`)**: Full avgift PER EVENT (5 kr × 4 event = 20 kr)
- **Plattformsavgift `both` (% + fast)**: Båda delarna delas proportionellt

### Multi-recipient serier (Swecup DH-problemet)
En serie kan ha event med OLIKA betalningsmottagare (t.ex. Swecup DH med 4 arrangörer).
Serieanmälningar skapar EN order → `explodeSeriesOrdersToEvents()` delar den i per-event-rader.
Varje split-rad taggas med `_event_recipient_id` från eventets `payment_recipient_id`.

**Två-stegs filtrering:**
1. **SQL-nivå** (promotor.php väg 9-11): Hitta serier som INNEHÅLLER events ägda av mottagaren
2. **Post-split filtrering** (`filterSplitRowsByRecipient()`): Efter uppdelning, behåll bara split-rader för mottagarens events

**Delade helpers i `/includes/economy-helpers.php`:**
- `getRecipientEventIds($db, $recipientId)` - alla event-ID:n via 3 vägar (direkt + promotor + serie)
- `filterSplitRowsByRecipient($rows, $recipientId, $recipientEventIds)` - filtrera split-rader

### KRITISK REGEL
- **ANVÄND ALLTID promotor-kedjan** vid ekonomifrågor (inte bara payment_recipient_id)
- Mönstret: `payment_recipients.admin_user_id → promotor_events/series.user_id`
- `payment_recipient_id` på events/series är en CACHE - promotor-kedjan är sanningskällan
- **Multi-recipient serier**: Serie-ordrar MÅSTE delas per event OCH filtreras per mottagare

### Filer ändrade
- **`/admin/promotor.php`** - 11-vägs mottagarfilter + post-split recipient-filtrering
- **`/admin/settlements.php`** - Omskriven med promotor-kedja + multi-recipient + settlement payouts + saldo
- **`/includes/economy-helpers.php`** - `explodeSeriesOrdersToEvents()` + `getRecipientEventIds()` + `filterSplitRowsByRecipient()`
- **`/admin/payment-recipients.php`** - Auto-sync vid create/update
- **`/admin/user-events.php`** - Auto-sync vid promotor-tilldelning
- **`/Tools/migrations/061_settlement_payouts_and_recipient_backfill.sql`** - Ny tabell + backfill

---

## SERIE-ORDRAR: PER-EVENT INTÄKTSFÖRDELNING (2026-02-26)

### Bakgrund
Serieanmälningar skapas som EN order med `event_id = NULL` och `series_id = X`.
Ekonomivyerna (promotor.php + settlements.php) visade dessa som EN rad med serie-namn.
Användaren vill se intäkter fördelade per event i serien.

### Lösning: `explodeSeriesOrdersToEvents()`
Ny delad helper i **`/includes/economy-helpers.php`** som:
1. Hittar alla event i serien (via `series_events` + `events.series_id` fallback)
2. Slår upp `series_registrations` → `class_id`, `discount_percent`, `final_price`
3. Hämtar per-event priser via `event_pricing_rules` för varje klass
4. Fördelar orderbeloppet proportionellt: `event_base_price * (1 - rabatt%) / summa_base_price * orderbelopp`
5. Fallback till jämn fördelning om pricing rules saknas

### Avgiftsfördelning för uppdelade rader
- **Betalningsavgift**: Proportionell via `_split_fraction` (Stripe %-del + fast del * fraction)
- **Plattformsavgift**: %-baserade proportionella, fasta proportionella via fraction
- **stripe_fee**: Redan proportionerad i helper-funktionen

### Visuell markering
- Uppdelade rader har `border-left: 3px solid var(--color-accent)` och "Serieanmälan"-badge
- Rabattkolumnen visar `X%` (andel av serien) istället för rabattkod
- Mobilvy: "Serie" label i metadata-raden

### Event-filter & uppdelade rader
- När event-filter är aktivt och serie-ordrar har delats upp, filtreras uppdelade rader
  så att BARA det valda eventets rad visas (andra event i serien döljs)

### VIKTIGT: Korrekt prisberäkning
```
Serie med 4 event, klass-priser: 500, 600, 500, 400 (totalt 2000)
Serie-rabatt: 15%
Totalt betalt: 2000 * 0.85 = 1700 kr

Per-event fördelning:
  Event 1: 500 * 0.85 = 425 kr (25%)
  Event 2: 600 * 0.85 = 510 kr (30%)
  Event 3: 500 * 0.85 = 425 kr (25%)
  Event 4: 400 * 0.85 = 340 kr (20%)
  Summa: 1700 kr ✓
```

### Filer
- **`/includes/economy-helpers.php`** - NY - Delad helper med `explodeSeriesOrdersToEvents()`
- **`/admin/promotor.php`** - Använder helper för båda admin och promotor ekonomivyn
- **`/admin/settlements.php`** - Använder helper för avräkningar per mottagare

---

## BETALNINGSMOTTAGARE & AVRÄKNINGAR (2026-02-25)

### Nya admin-sidor
- **`/admin/payment-recipients.php`** - CRUD för betalningsmottagare (Swish, bank, Stripe)
  - Lista med kort-layout, skapa/redigera/aktivera-inaktivera
  - Hanterar: namn, org.nr, kontakt, Swish, bank, plattformsavgift (procent/fast/båda)
  - Koppling till promotor-användare via `admin_user_id`
- **`/admin/settlements.php`** - Avräkningsvy per betalningsmottagare
  - Visar alla betalda ordrar kopplade till en mottagare via event/serie
  - Beräknar per order: brutto, betalningsavgift (Stripe/Swish), plattformsavgift, netto
  - Filter: år, månad, mottagare
  - Sammanfattningskort med totaler överst

### Migration 059
- `payment_recipients.admin_user_id` INT NULL - FK till `admin_users(id)` ON DELETE SET NULL
- Möjliggör koppling mellan betalningsmottagare och promotor-användare

### SQL-strategi (förenklad vs promotor.php)
Avräkningssidan (`settlements.php`) använder **enklare SQL** än den befintliga ekonomivyn i `promotor.php`:
1. Hitta alla event med `events.payment_recipient_id = ?`
2. Hitta alla serier med `series.payment_recipient_id = ?`
3. Hämta ordrar via `orders.event_id IN (events)` OR `orders.series_id IN (serier)`
4. Plus fallback via `order_items → series_registrations` för serie-ordrar utan `series_id`

### Navigation
- Tillagda som flikar i Konfiguration → Ekonomi-gruppen i `admin-tabs-config.php`
- Tillagda i `tools.php` under "Medlemskap & Betalningar"-sektionen
- `unified-layout.php` pageMap: `payment-recipients` och `settlements` → `economy`

---

## EKONOMI EVENT-FILTER: ROBUSTGJORT MED FYRA SÖKVÄGAR (2026-02-25)

### Grundorsak (iteration 2 - djupare)
Första fixen bytte från `events.series_id` till `series_events` men det räckte inte. Orsaken:
1. `events.series_id` är inte alltid satt (events kan vara kopplade enbart via `series_events`)
2. `series_events` kanske inte heller har rätt data (beror på hur events lades till)
3. `series_registration_events` skapades via `events WHERE series_id = ?` (order-manager.php) - samma bristfälliga källa
4. `orders.series_id` sätts vid skapande men kopplar inte vidare till specifika event

**Lösning:** Alla ekonomi-frågor använder nu FYRA parallella sökvägar:
1. `orders.event_id` - direkt event-order
2. `series_events` junction table - aktuell serie-medlemskap
3. `series_registration_events` - snapshot vid köptillfället
4. `events.series_id` / `orders.series_id` - legacy fallback

### Mottagarfilter (Götaland Gravity-buggen)
Serie-ordrar har `event_id = NULL` → alla JOINs via event-tabellen ger NULL.
**Fix:** Lagt till `LEFT JOIN series s_via_order ON o.series_id = s_via_order.id` som direkt koppling.
Fyra vägar att hitta mottagare: `e.payment_recipient_id`, `s_via_event`, `s_via_order`, `s_via_items`.

### order-manager.php fix
`createMultiRiderOrder()` skapade `series_registration_events` via `SELECT id FROM events WHERE series_id = ?`.
**Fix:** Använder nu `series_events` UNION `events.series_id` (fallback) för att hitta ALLA event i serien.

### KRITISK REGEL för framtida SQL
- **ANVÄND ALDRIG bara EN källa** för att hitta serie-event
- Mönstret är: `series_events` UNION/OR `events.series_id` UNION/OR `series_registration_events`
- För mottagare: JOIN via `orders.series_id → series` (direkt, ingen omväg via events)

---

## ADMIN MOBIL EDGE-TO-EDGE FIX (2026-02-25) - ITERATION 3 (GLOBAL)

### Grundorsaker som fixats
1. **Sektion 26** överskrev mobilregler (border-radius 14px) → Flyttat mobilregler till sektion 37 SIST i filen
2. **branding.json** satte `--container-padding: 32px` utan media query → unified-layout.php genererar nu media queries per breakpoint
3. **CSS-variabler** opålitliga på mobil → Sektion 37 använder HÅRDKODADE pixelvärden (12px/8px)
4. **economy-layout.php** laddade `admin.css` istf `admin-color-fix.css` → Fixat till samma CSS som unified-layout
5. **33 card bodies med `style="padding: 0"`** för tabeller överskrevs av sektion 37 → `:has(> table)` undantag

### Sektion 37: Fullständig mobil-arkitektur (admin-color-fix.css, SIST i filen)

**Edge-to-edge kort** (max-width: 767px):
- admin-main: 12px padding (hardkodat)
- Kort: -12px negativ margin, border-radius: 0, inga sidoborders
- Stat-kort: INTE edge-to-edge (behåller radius + border)
- Card-body med tabell: padding 0 (`:has(> table)` / `.p-0`)
- Card-body med formulär: padding 10px 12px

**Tabeller** (automatisk horisontell scroll):
- `.admin-card-body`, `.card-body`, `.admin-table-container`, `.table-responsive` → `overflow-x: auto`
- Tabeller inuti kort: `min-width: 500px` → tvingar scroll istället för squish
- Första kolumnen: `position: sticky; left: 0` → stannar kvar vid scroll
- Kompakta celler: 8px 10px padding, 13px font

**Övrigt mobil**:
- Flikar (tabs): `overflow-x: auto`, `white-space: nowrap` → horisontell scroll
- Modaler: fullscreen (100vw, 100vh)
- Filter bars: edge-to-edge
- Knappar: kompakta (13px, 8px 12px)
- Page header: kompakt (1.25rem)

**Extra litet** (max-width: 480px):
- admin-main: 8px padding
- Kort: -8px negativ margin
- Tabellceller: 6px 8px, 12px font

### VIKTIGT: Regler för framtida CSS-ändringar
1. Mobilregler MÅSTE ligga i sektion 37 (sist i admin-color-fix.css)
2. Använd ALDRIG `var(--container-padding)` i mobilregler - branding kan överskriva
3. Använd hardkodade px-värden: 12px (mobil), 8px (< 480px)
4. `!important` i stylesheet > inline styles utan `!important`
5. Card-body med tabell: använd `:has(> table)` eller `.p-0` klass för padding: 0
6. Nya tabellwrappers: `.admin-table-container` ELLER `.table-responsive`

### CSS-laddningskedja (alla admin-sidor)
- **unified-layout.php** → admin-layout-only.css + admin-color-fix.css (de flesta sidor)
- **economy-layout.php** → admin-layout-only.css + admin-color-fix.css (ekonomisidor, FIXAT)
- **branding.json** → inline `<style>` med media queries per breakpoint (FIXAT)

---

## ADMIN EVENT-EDIT MOBILANPASSNING & OMSTRUKTURERING (2026-02-25)

### Bugg: eventInfoLinks PHP warnings
- `$eventInfoLinks` initierades som tom `[]` utan default-nycklar
- `foreach ($eventInfoLinks['regulations'] as $link)` kraschade med "Undefined array key"
- **Fix:** Lagt till `?? []` på alla tre foreach-loopar (general, regulations, licenses)

### Omstrukturering av de första 5 sektionerna
- **Grundläggande information**: Uppdelad i 5 visuella sub-sektioner med `form-subsection`
  - Eventnamn (egen rad)
  - Datum & typ (startdatum, slutdatum, eventtyp, advent ID)
  - Plats (plats + bana/anläggning)
  - Logga (media-väljare)
  - Anmälan (öppnar, max deltagare, frist datum/tid)
- **Tävlingsinställningar**: Uppdelad i 3 sub-sektioner
  - Format & disciplin (huvudformat, event-format, alla format checkboxar)
  - Serie & ranking (serie, rankingklass, poängskala, prismall)
  - Bana (distans, höjdmeter, sträcknamn)
- **Arrangör + Gravity ID + Platsdetaljer**: Sammanslagna till EN sektion "Arrangör, plats & rabatt"
  - Arrangör & kontakt (klubb, webb, email, telefon)
  - Platsdetaljer (GPS, Maps URL, detaljer)
  - Gravity ID-rabatt (belopp + seriens rabatt)

### CSS-komponent: `.form-subsection`
- Ny CSS-klass för visuell gruppering inuti admin-cards
- Separeras med border-bottom mellan grupper
- Varje sub-sektion har en `.form-subsection-label` med ikon + uppercase text
- Sista subsection har ingen border-bottom

### Mobile edge-to-edge för admin event-edit
- `.admin-card.mb-lg` och `details.admin-card` går nu kant-till-kant på mobil (max-width: 767px)
- Negativa marginaler matchar `.admin-main` padding (var(--space-md) = 16px)
- `.alert.mb-lg` går också edge-to-edge
- Extra små skärmar (max-width: 374px) matchar --space-sm istället

### Mobila förbättringar
- Alla inputs har `min-height: 48px` på mobil (bättre touch targets)
- `font-size: 16px` på inputs förhindrar iOS auto-zoom
- Form grids kollapsar till 1 kolumn på mobil
- Floating save bar: knappar sida vid sida (inte staplat)
- Collapsible headers: min-height 52px för enklare tapp

---

## UNIVERSELLA LÄNKAR I ALLA EVENT-SEKTIONER (2026-02-25)

### Bakgrund
- Tidigare stödde bara 3 sektioner (general, regulations, licenses) länkar via `event_info_links`-tabellen
- Nu stödjer ALLA ~30 informationssektioner länkar (inbjudan, faciliteter, PM, jury, schema, etc.)

### Ändringar i admin/event-edit.php
- `$eventInfoLinks` laddas nu dynamiskt (inga hardkodade sektioner)
- Sparning använder regex-parsing av POST-nycklar: `preg_match('/^info_link_(.+)_url$/', ...)`
- Länk-UI (`.info-links-section`) tillagt i alla fält-loopar: facilityFields, pmFields, otherTabFields
- Även `invitation` och `competition_classes` har fått länk-UI

### Ändringar i pages/event.php
- Ny helper `renderSectionLinks()` - renderar länklista konsekvent med external-link-ikon
- Faciliteter-fliken refaktorerad från 12 manuella block till data-driven `$facilityDefs`-array
- PM-fliken refaktorerad från 10 manuella block till data-driven `$pmDefs`-array
- Jury, Schema, Starttider, Bansträckning använder nu `renderSectionLinks()` istället för manuell rendering
- Alla befintliga manuella länk-renderingar (general, regulations, licenses) ersatta med `renderSectionLinks()`

### Sektionsnycklar (section-kolumnen i event_info_links)
- `invitation`, `general`, `regulations`, `licenses`, `competition_classes`
- Faciliteter: `hydration_stations`, `toilets`, `bike_wash`, `food_options`, `camping`, `first_aid`, `transport_info`, `parking_info`, `spectator_info`, `environmental_info`, `accessibility_info`, `other_facilities`
- PM: `pm_general`, `pm_registration_info`, `pm_equipment`, `pm_safety`, `pm_other`
- Övriga: `jury_info`, `schedule`, `start_times`, `course_description`

### Tekniska detaljer
- `addInfoLink(section)` JS-funktion stödjer alla sektionsnamn dynamiskt
- Inga migrationsändringar behövdes - `event_info_links.section` VARCHAR(30) var redan flexibelt
- Fallback: sektioner utan länkar visas utan länk-sektion (ingen UI-påverkan)

---

## PROMOTOR-SPARNING NOLLSTÄLLDE FÄLT (2026-02-24)

### Bug: max_participants och andra fält försvann vid promotor-edit
- **Orsak:** Event-edit har två `<fieldset disabled>` sektioner för promotorer (Grundläggande info + Tävlingsinställningar). Disabled inputs skickas INTE med i POST. Hidden inputs som bevarar värdena saknades för flera fält.
- **Saknades i Grundläggande info:** end_date, event_type, logo_media_id, registration_opens, registration_deadline, registration_deadline_time, max_participants, contact_email, contact_phone
- **Saknades i Tävlingsinställningar:** formats[] (checkbox-array)
- **Fix:** Lade till hidden inputs för alla saknade fält i båda sektionerna
- **VIKTIGT:** Vid nya fält i en `<fieldset disabled>` MÅSTE motsvarande hidden input läggas till för promotorer

### Registreringsvalidering förstärkt
- `getEligibleClassesForSeries()` saknade helt `incomplete_profile`-kontroll (hade den bara i event-versionen)
- `createMultiRiderOrder()` validerade aldrig rider-profil innan anmälan skapades
- Nu valideras kön, födelseår, telefon, e-post, nödkontakt i alla tre nivåer: klasslistning, orderskapande, profilskapande

---

## EVENT-EDIT INBJUDAN REDESIGN (2026-02-24)

### Inbjudan-sektionen omstrukturerad
- Alla fält (Inbjudningstext, Generell tävlingsinformation, Regelverk, Licenser, Tävlingsklasser) använder nu samma `.facility-field`-kortstil som PM och Faciliteter
- Varje fält har en banner-header med ikon + Global-toggle till höger
- Ikoner: scroll (Inbjudan), info (Generell), book-open (Regelverk), id-card (Licenser), trophy (Klasser)

### Faciliteter utbruten till egen sektion
- Faciliteter & Logistik är nu en egen `<details class="admin-card">` - inte längre inuti Inbjudan
- Matchar att Faciliteter har en egen flik på publika event-sidan

### Länk-sektioner förbättrade
- Ny `.info-links-section` med egen bakgrund, header med länk-ikon och "LÄNKAR" rubrik
- Renare `.info-link-row` grid-layout utan inline styles
- `addInfoLink()` JS-funktion uppdaterad att appenda till `.info-links-list` istället för container-div
- Mobilanpassat: link-rows stackas på smala skärmar

### Regelverk radio-knappar
- Ny `.global-toggle-group` klass för att visa flera `.global-toggle` radio-knappar i rad (Egen text / sportMotion / Tävling)

---

## TEXTFORMATERING I EVENT-INFO (2026-02-24)

### Markdown-stil formatering i admin-textareas
- **`format_text()`** i `includes/helpers.php` - ersätter `nl2br(h())` på publika sidan
- Konverterar `**fetstil**` → `<strong>fetstil</strong>` och `*kursiv*` → `<em>kursiv</em>`
- Säker: HTML escapas med `h()` FÖRST, sedan konverteras markdown-mönster
- Regex kräver icke-mellanslag direkt innanför `*` (förhindrar falska matchningar typ `5 * 3 * 10`)

### Toolbar-komponent
- **`admin/components/format-toolbar.php`** - inkluderbar komponent med CSS + JS
- Lägger automatiskt till B/I-knappar ovanför alla `<textarea data-format-toolbar>`
- Knappar wrappar markerad text med `**` / `*`
- Stödjer Ctrl+B och Ctrl+I tangentbordsgenvägar
- Toggle: om text redan är wrapppad tas markörerna bort vid nytt klick
- Hint-text `**fet** *kursiv*` visas till höger i toolbaren

### Var toolbaren finns
- `admin/event-edit.php` - alla `event-textarea` och `facility-textarea` fält
- `admin/global-texts.php` - alla textareas (befintliga och skapa-ny)
- Toolbaren inkluderas före `unified-layout-footer.php`

### Var format_text() renderar
- `pages/event.php` - alla 31 textfält (invitation, general_competition_info, regulations, license, facilities, PM, jury, schedule etc.)

---

## LÄNKAR I GENERELL TÄVLINGSINFORMATION (2026-02-24)

### Migration 056 (enskild länk - ERSATT av 057)
- Lade till `events.general_competition_link_url` och `events.general_competition_link_text`
- Dessa kolumner används nu bara som fallback om migration 057 inte körts

### Migration 057 (fler-länk-tabell)
- Ny tabell `event_info_links` (id, event_id, link_url, link_text, sort_order, created_at)
- FK till events(id) med ON DELETE CASCADE
- Migrationen flyttar befintlig data från de gamla kolumnerna till nya tabellen
- Obegränsat antal länkar per event
- Arrangörer lägger till/tar bort länkar med +/x-knappar i admin event-edit
- Om länktext lämnas tomt visas URL:en som länktext
- Länkar visas under informationstexten i "Generell tävlingsinformation"-kortet
- Kortet visas nu även om det bara finns länkar men ingen informationstext
- Fallback till gamla kolumnerna om tabellen inte finns (try/catch i både admin och publik vy)

### Migration 058 (Regelverk + Licenser + globala text-länkar)
- `event_info_links.section` - VARCHAR(30), default 'general' - stödjer ALLA sektioner (se "UNIVERSELLA LÄNKAR" ovan)
- `events.regulations_info` TEXT - egen regelverkstext per event
- `events.regulations_global_type` VARCHAR(20) - 'sportmotion', 'competition' eller tom (egen text)
- `events.regulations_hidden` TINYINT - dölj regelverk-rutan
- `events.license_info` TEXT - egen licenstext per event
- `events.license_use_global` TINYINT - använd global licenstext
- `events.license_hidden` TINYINT - dölj licens-rutan
- Ny tabell `global_text_links` (id, field_key, link_url, link_text, sort_order) - länkar kopplade till globala texter
- Seedar tre globala texter: `regulations_sportmotion`, `regulations_competition`, `license_info`
- Regelverk har TVÅ globala val via radioknappar (sportMotion / Tävling) - inte en enkel checkbox
- Globala länkar mergas med eventspecifika vid visning (globala först, sedan event-egna)
- Globala texter admin (`/admin/global-texts.php`) har nu länk-UI under varje textfält

---

## DATABASBASERADE PUBLIKA INSTÄLLNINGAR (2026-02-24)

### Flytt från fil till databas
- **Tidigare:** `public_riders_display` lästes från `/config/public_settings.php` (filbaserat)
- **Nu:** Läses från `sponsor_settings`-tabellen via `site_setting()` helper
- **Migration 055:** Seedar default-värden (`public_riders_display = 'with_results'`, `min_results_to_show = 1`)

### Helper-funktioner (includes/helpers.php)
- **`site_setting($key, $default)`** - Läser en setting från `sponsor_settings` med statisk cache per request
- **`save_site_setting($key, $value, $description)`** - Sparar/uppdaterar setting i databasen

### Hur det fungerar
- `pages/riders.php` anropar `site_setting('public_riders_display', 'with_results')` vid varje request
- Admin ändrar via `/admin/public-settings.php` → `save_site_setting()` → omedelbar effekt
- Default: `'with_results'` = bara åkare med minst 1 resultat visas på publika deltagarsidan
- `'all'` = alla aktiva åkare visas (använd när alla funktioner är klara)

### Strava API-integration (UNDER UTREDNING)
- Strava Developer Program ansökningsformulär mottaget
- Tillåtna use-cases: visa enskild åkares Strava-stats på deras profil
- Förbjudet: cross-user leaderboards, virtuella tävlingar
- Kräver: OAuth 2.0, Brand Guidelines compliance, screenshots
- Status: Ej ansökt ännu

---

## PREMIUM-MEDLEMSKAP (2026-02-24)

### Ny funktion: Premium-prenumeration
- **Prisplaner:** 25 kr/mån eller 199 kr/år
- **Stripe-baserat:** Använder befintlig prenumerationsinfrastruktur (migration 025)
- **Migration 054:** Skapar `rider_sponsors`-tabell och uppdaterar planer i `membership_plans`

### Premium-funktioner
1. **Premium-badge på profilen** - Guld crown-ikon i badge-raden (Licens, Gravity ID, Premium)
2. **Personliga sponsorer** - Max 6 sponsorer med namn, logotyp-URL och webbplatslänk
3. **Sponsorsektion på profilsidan** - Visas i högerkolumnen under klubbtillhörighet
4. **Sponsorhantering i profilredigering** - Lägg till/ta bort sponsorer via `/api/rider-sponsors.php`
5. **Premium upsell** - Icke-premium-medlemmar ser "Bli Premium"-ruta i profilredigeringen

### Teknisk arkitektur
- **`includes/premium.php`** - Helper-funktioner: `isPremiumMember()`, `getPremiumSubscription()`, `getRiderSponsors()`
- **`api/rider-sponsors.php`** - CRUD API (add/remove/update/list), kräver inloggning + premium
- **`api/memberships.php`** - Uppdaterad: sparar `rider_id` i metadata vid checkout, länkar till stripe_customers
- **Webhook** (`stripe-webhook.php`) - Uppdaterad: sätter `rider_id` på `member_subscriptions` vid subscription.created
- **`isPremiumMember()`** har statisk cache per request, söker på rider_id + email-fallback

### rider_sponsors tabell
- `id, rider_id, name, logo_url, website_url, sort_order, active, created_at, updated_at`
- FK till riders(id) med ON DELETE CASCADE
- Max 6 aktiva sponsorer per rider (valideras i API)

### Premium-badge CSS
- Guld gradient: `linear-gradient(135deg, rgba(251, 191, 36, 0.2), rgba(245, 158, 11, 0.1))`
- Definierad i `assets/css/pages/rider.css` som `.badge-premium`

### Strava-integration AVVISAD
- Stravas API-avtal (nov 2024) förbjuder uttryckligen virtuella tävlingar och cross-user leaderboards
- Segment efforts kräver betald Strava-prenumeration
- Partnerskap möjligt men osäkert - kräver direkt kontakt med Strava Business

---

## KLASSANMÄLAN KÖN-BUGG FIXAD (2026-02-23)

### Problem
Kvinnliga åkare kunde inte anmäla sig till någon klass - varken dam-klasser eller mixade klasser. Felmeddelandet sa "Endast damer" för dam-klasser trots att åkaren var kvinna.

### Orsak
- `classes`-tabellen lagrar kön som `'K'` (Kvinna) för dam-klasser
- `riders`-tabellen lagrar kön som `'F'` (Female) för kvinnor
- `getEligibleClassesForEvent()` och `getEligibleClassesForSeries()` i `order-manager.php` jämförde `$class['gender'] !== $riderGender` direkt → `'K' !== 'F'` = alltid sant = ingen dam-klass matchade

### Fix
- Normaliserar class gender i jämförelsen: `'K'` mappas till `'F'` innan jämförelse
- Fixat i båda funktionerna: `getEligibleClassesForEvent()` (rad ~903) och `getEligibleClassesForSeries()` (rad ~1087)
- Ingen databasändring behövdes

---

## GRAVITYTIMING API-DOKUMENTATION (2026-02-23)

### Extern integrationsdokumentation
- `/docs/gravitytiming-api-guide.md` - Komplett guide for tidtagningsprogrammet
- Beskriver alla endpoints, autentisering, tidsformat, felhantering, retry-strategi
- Inkluderar typiskt arbetsflode (fore/under/efter tavling) och cURL-exempel
- Riktar sig till utvecklare av GravityTiming-appen

---

## GRAVITYTIMING API (2026-02-22)

### Ny integration: GravityTiming tidtagnings-API
GravityTiming ar en lokal tidtagningsapp som kors pa en stationer dator vid tavlingsplatsen. API:t later appen:
1. Hamta startlistor fran TheHUB
2. Ladda upp resultat (batch eller live split times)
3. Visa resultat i realtid pa event-sidan

### API-autentisering
- API-nyckel + hemlighet via HTTP-headers: `X-API-Key` + `X-API-Secret`
- Nycklar skapas i `/admin/api-keys.php`, prefix `gt_`
- Secret hashas med bcrypt, visas bara vid skapande
- Rate limiting: 60 anrop/minut per nyckel
- Scope-system: `readonly`, `timing`, `admin` (hierarkiskt)
- Nycklar kan begransas till specifika event_ids

### API-endpoints (alla under /api/v1/)
- `GET /api/v1/events` - Lista events (med klasser, stage_names)
- `GET /api/v1/events/{id}/startlist` - Hamta startlista (riders, bib, klass, klubb, licens)
- `GET /api/v1/events/{id}/classes` - Hamta klasser med deltagarantal
- `POST /api/v1/events/{id}/results` - Batch-upload resultat (mode: upsert/replace/append)
- `POST /api/v1/events/{id}/results/live` - Live split time (en SS at gangen)
- `GET /api/v1/events/{id}/results/status` - Polling-endpoint for live-resultat
- `PATCH /api/v1/events/{id}/results?result_id=X` - Uppdatera enstaka resultat
- `DELETE /api/v1/events/{id}/results?mode=all` - Rensa alla resultat

### Databasandringar (migration 053)
- `api_keys` - API-nyckeltabell med scope, event-begransning, utgangsdatum
- `api_request_log` - Logg for alla API-anrop (debug/rate limiting)
- `events.timing_live` - TINYINT flagga for live-tidtagning

### Live-resultat pa event-sidan
- Event-sidan pollar `/api/v1/events/{id}/results/status` var 10:e sekund nar `timing_live = 1`
- LIVE-badge (rod, pulserande) visas i resultat-fliken
- Sidan laddas om automatiskt nar nya resultat kommer in
- `timing_live` satts till 1 vid forsta live-resultatet, 0 nar resultat rensas

### Filstruktur
- `/api/v1/auth-middleware.php` - Autentisering, rate limiting, helpers
- `/api/v1/events.php` - Lista events
- `/api/v1/event-startlist.php` - Startlista
- `/api/v1/event-classes.php` - Klasser
- `/api/v1/event-results.php` - Resultat CRUD (POST/PATCH/DELETE)
- `/api/v1/event-results-live.php` - Live split times
- `/api/v1/event-results-status.php` - Polling/status
- `/admin/api-keys.php` - Admin API-nyckelhantering
- `/admin/tools/test-timing-api.php` - Testverktyg

### .htaccess routing
- Clean URLs: `/api/v1/events/42/startlist` → `api/v1/event-startlist.php?event_id=42`
- HTTPS-redirect undantag for `/api/v1/` (extern utrustning foljer inte redirects)

---

## PRESTANDAOPTIMERING (2026-02-21)

### Fas 1 - Snabba vinster (KLAR)
- **env() cachad**: Statisk variabel i config.php. .env lasas en gang per request istallet for 13+.
- **getVersionInfo() cachad**: Resultat sparas i `.version-cache.json` (1h TTL). Inga shell_exec per request.
- **global-cart.js**: Andrat fran `?v=time()` till `?v=filemtime()`. Webblasar-cache fungerar nu.
- **theme-enhancement.css borttagen**: Refererade till en fil som inte existerade (404 per sidladdning).
- **Chart.js villkorlig**: Laddas bara pa rider.php och club.php (inte alla sidor). Sparar ~80 KB.
- **Lucide + Chart.js defer**: Blockar inte langre initial rendering. Lucide init via DOMContentLoaded.
- **branding.json**: Lastes 2 ganger i head.php, nu 1 gang (ateranvander data fran ikon-sektionen).

### Fas 2 - Databasoptimering (KLAR)
- **Migration 052**: 15 index pa event_registrations, results, orders, events, riders
- **search.php CONCAT borttagen**: Anvander nu separata firstname/lastname-villkor. Multi-ord-sokning (t.ex. "Erik Svensson") matchar `firstname LIKE '%Erik%' AND lastname LIKE '%Svensson%'`. Index `idx_riders_name(lastname, firstname)` kan nu anvandas.
- **SHOW COLUMNS cachad**: Ny `_hub_column_exists()` och `_hub_table_columns()` i order-manager.php med statisk cache. Kolumnlistan for riders hamtas en gang per request istallet for 1-3 ganger.
- **GlobalSponsorManager cachad**: `hasCustomMediaColumn()` och `loadSettings()` anvander statisk cache (delade over instanser). Sponsor-settings laddas i en enda fraga istallet for 2 separata. SHOW COLUMNS ersatt med information_schema-fraga.

### Fas 3 - Frontend (PLANERAD)
- 12 CSS-filer = 12 HTTP-requests (~106 KB). Bor slas ihop.
- event.php: 5,893 rader med ~2,000 rader inline-JS
- Google Fonts laddar 4 fontfamiljer med 16 vikter

### Fas 4 - Arkitektur (PLANERAD)
- Ingen applikationscache (APCu/Redis)
- SCF API-anrop blockerar registrering i 2-5 sek (synkront)
- Resultat i event.php saknar paginering

### Tekniska detaljer
- `.version-cache.json` i rotmappen - auto-genereras, ignorera i git
- Chart.js-sidor definieras i `$chartPages` array i head.php (rad ~110)
- Lucide init anvander `_initLucideIcons()` + DOMContentLoaded i index.php

---

## PROMOTOR-GRANSSNITT (redesign 2026-02-17)

### Ny flikstruktur
- **Event** - Event-kort med anmalda (inkl serieanmalningar), brutto/netto-intakter, redigera-lank
  - Sorteras: kommande forst (datum ASC), sedan genomforda senast-forst (datum DESC)
  - Visar bade event-registreringar OCH serie-registreringar via `series_registration_events`
  - "Betalda" inkluderar bade event-registreringar och serieanmalningar
  - Serie-intakter fördelas jämnt: `final_price / antal event i serien`
- **Serier** - Serieanmalan oppen/stangd, serierabatt, prismall, banner
- **Ekonomi** - Detaljerad ordertabell med avgifter, rabattkoder. Samma layout som admin-ekonomivyn. Filter: ar, manad, event
  - Lank till rabattkodshantering (`/admin/discount-codes.php` stodjer promotor)
  - Ar-filter inkluderar bade event-ordrar och serieanmalningar (tre-vagssokning)
- **Media** - Lankar till mediabiblioteket med formatguide

### Borttaget fran promotor-nav
- Swish (all Swish-konfiguration borttagen fran promotor)
- Direktanmalan (ska byggas om som QR-baserad)
- Sponsorer (hanteras direkt i event-edit via bildväljare + Media-biblioteket)

### Navigation
- Desktop sidebar och mobil bottomnav uppdaterade till 4 lankar: Event, Serier, Ekonomi, Media
- Alla pekar pa `/admin/promotor.php?tab=X` (utom promotor-series som har egen sida)
- Aktiv-markering baseras pa `$_GET['tab']`

### Serieanmalningar i ekonomin (bugg-fix)
- **Problem:** `orders.event_id` sattes till forsta eventet i orderns items. Serieanmalningar hamnade under ETT event - ovriga (t.ex. Tranas, Varnamo) visade 0 ordrar
- **Fix:** Migration 051 lagger till `orders.series_id`. Order-manager satter nu `series_id` vid serieanmalningar. Ekonomi-vyn inkluderar ordrar med matchande `series_id` ELLER `event_id`
- **Backfill:** Migrationen uppdaterar befintliga ordrar via `order_items → series_registrations`

### Plattformsavgifter: stod for fast belopp (migration 051)
- `payment_recipients.platform_fee_fixed` - Fast avgift per anmalan i SEK
- `payment_recipients.platform_fee_type` - ENUM: `percent`, `fixed`, `both`
- Berakning: percent = `amount * pct / 100`, fixed = fast summa per order, both = bada
- Default: `percent` med 2.00% (bakatompatibelt)
- Admin kan andra typ och varde i promotor.php (admin-vyn)

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

## SENASTE FIXAR (2026-02-19, session 4-5)

- **Promotor event-kort: 0 kr intäkter för serie-event fixat**: Serieanmälningars intäkter beräknades via `series_registration_events` (snapshot vid köp). Event som lades till serien efter köp fick 0 kr. Omskrivet: beräknar nu dynamiskt via `events.series_id` - total serieintäkt / antal event i serien. Alla event i serien får sin andel oavsett när de lades till.
- **Ekonomi: Event-filter saknade serie-event (Tranås/Värnamo)**: Dropdown-listan visade bara event med direkta ordrar (`orders.event_id`). Serie-event som Tranås/Värnamo hade inga direkta ordrar och saknades helt. Fixat: filtret inkluderar nu även event som tillhör serier med betalda serieanmälningar. Vid val av serie-event visas även serie-ordrar (inte bara event-ordrar). Fixat i BÅDA admin- och promotor-vyn.
- **Mediabibliotek: Flytta bilder mellan mappar**: `update_media()` flyttar nu den fysiska filen (inte bara DB-metadata) när mappen ändras. Filepath uppdateras automatiskt. Mapp-dropdown i bilddetalj-modalen visar nu även undermappar (t.ex. `sponsors/husqvarna`). Bekräftelsemeddelande "Bilden flyttad till X" vid mappbyte.
- **Mediabibliotek: Mobilanpassad bilddetalj-modal**: Modalen tar nu hela skärmen på mobil (fullscreen), med sticky header och scrollbart innehåll. Extra padding i botten (70px) förhindrar att knappar hamnar bakom bottom-nav. Z-index höjt till 10000 för att ligga ovanför alla menyer.
- **Promotor: Bildbaserad sponsorväljare i event-edit**: Promotorer ser nu ett förenklat UI med fyra placeringsgrupper (Banner, Logo-rad, Resultat-sponsor, Partners) där de väljer bilder direkt från mediabiblioteket. Bakom kulisserna auto-skapas sponsors via `find_or_create_by_media` API-endpoint. Admins behåller det befintliga dropdown/checkbox-UIet. Ingen sponsor-entitetshantering synlig för promotorer.
- **API: find_or_create_by_media endpoint**: `/api/sponsors.php?action=find_or_create_by_media&media_id=X` - Kollar om en sponsor redan använder bilden (logo_media_id eller logo_banner_id), returnerar den i så fall. Annars skapas en ny sponsor automatiskt med filnamnet som namn.
- **Profilredigering tom - admin_email saknades i session**: `hub_set_user_session()` satte aldrig `$_SESSION['admin_email']` vid inloggning via publika sidan. `hub_current_user()` kunde darfor inte sla upp rider-profilen via email. Fixat: satter admin_email + fallback till hub_user_email.

### Promotor sponsorväljare - arkitektur
- **Villkorlig rendering**: `<?php if ($isPromotorOnly): ?>` styr vilken sponsor-UI som visas i event-edit.php
- **Placeringar**: header (1 bild), content/logo-rad (max 5), sidebar/resultat (1 bild), partner (obegransat)
- **Bildväljare modal**: Laddar bilder från `sponsors/` (inkl subfolders) via media API
- **Upload inline**: Möjlighet att ladda upp ny bild direkt i modalen (sparas i sponsors-mappen)
- **Auto-sponsor**: `selectMediaForPlacement()` → `find_or_create_by_media` → sponsor skapas/hittas → hidden input med sponsor_id
- **Form-fält**: Samma namn som admin-UIet (sponsor_header, sponsor_content[], sponsor_sidebar, sponsor_partner[]) → `saveEventSponsorAssignments()` fungerar identiskt

## TIDIGARE FIXAR (2026-02-19, session 3)

- **Promotor: Mappar synliga och skapbara i mediabiblioteket**: Tre buggar fixade:
  1. `get_media_subfolders()` sokte bara i DB - tomma mappar (skapade via filsystemet) syntes aldrig. Nu skannas aven filsystemet for undermappar.
  2. Promotors `promotorAllowedFolders` inneholl bara seriespecifika sokvagar (`sponsors/{seriesSlug}`). Mappar skapade med sponsornamn (t.ex. `sponsors/husqvarna`) matchade inte och filtrerades bort. Fixat: promotors far nu tillgang till alla `sponsors/`-undermappar (de ar redan begransade till enbart sponsors-mappen).
  3. Promotor som navigerade till en undermapp (`?folder=sponsors/husqvarna`) tvingades alltid tillbaka till `sponsors` av `$currentFolder = 'sponsors'`. Fixat: undermappar under sponsors tillats nu.

## TIDIGARE FIXAR (2026-02-18/19)

- **Mobil: Anmalda-raknare dold i event-headern**: Pa mobil visades antal anmalda som en egen sektion under eventinformationen (t.ex. bara "11"), vilket tog onodigt mycket plats. Nu dold pa mobil via CSS-klassen `.event-stat--registered` med `display: none`. Antalet visas istallet enbart i fliken "Anmalda" med formatet x/y (t.ex. "11/50"). Pa desktop visas statistiken fortfarande i headern.
- **Anmalda-listan: Akarnamn lankade till profilkort**: Namn i anmalda/startlista-fliken ar nu klickbara lankar till respektive akares profilsida (`/rider/{id}`). Anvander befintlig `.rider-link`-klass.
- **Admin session-timeout okat fran 30 min till 24 timmar**: Utan "Kom ihag mig" var timeout bara 30 minuter. Nu 24 timmar default, 30 dagar med remember_me.
- **Promotor: Sponsorbilder fran subfolders visas i media-pickern**: Media-pickern i sponsors.php sokte bara i exakt `sponsors/`-mappen. Nu inkluderas subfolders (`sponsors/serie-namn/` etc.) via `subfolders=1` parameter. Media API:t stodjer nu `subfolders=1` GET-parameter.
- **Promotor kan skapa/redigera sponsorer**: Flödet var redan tekniskt implementerat (knapp, formulär, API) men media-pickern visade inte promotorens uppladdade bilder. Nu fixat.
- **Admin profilredigering tom (hub_current_user-bugg)**: `hub_current_user()` returnerade hardkodad session-data for admin-anvandare (bara namn/email/role). Rider-profilen (phone, ICE, birth_year, club etc.) hamtades aldrig fran databasen. Fixat: slar nu upp rider-profil via email nar admin ar inloggad. Om kopplad rider finns returneras fullstandig rider-data med admin-flaggor.

## TIDIGARE FIXAR (2026-02-19, session 2)

- **Betalda-raknare inkluderar serieanmalningar**: Event-kort visade bara `paid_count` fran event_registrations. Nu laggs `series_registration_count` till (`paid_with_series`) - serier ar forbetalda
- **Tidigare event sorteras senast-forst**: Genomforda event sorteras nu med nyaste forst (DATE DESC) istallet for aldsta forst
- **Admin mottagarfilter fungerar**: Mottagare-dropdown filtrerar nu faktiskt orderlistan via `events.payment_recipient_id` och `series.payment_recipient_id`
- **Promotor arfilter inkluderar serieordrar**: Arsvaljaren i ekonomi-fliken soker nu bade event-ordrar och serieordrar (tre-vagssokning med event_id, order_items och orders.series_id)
- **Borttagen `e.registration_open`**: Kolumnen valdes i event-fragan men anvandes aldrig - borttagen for att undvika potentiellt SQL-fel

## FIXAR (2026-02-19, session 1)

- **Ekonomi-vy omskriven till per-order-tabell**: Hela admin-ekonomivyn (`/admin/promotor.php`) omskriven fran aggregerad sammanfattning per betalningsmottagare till en detaljerad per-order-tabell. Kolumner: Ordernr, Event, Belopp, Betalsatt, Avgift betalning, Plattformsavgift, Netto. Summarad i tfoot. Mobil portrait visar kort-vy med komprimerad info.
- **Swish-avgift andrad fran 2 kr till 3 kr**: `$SWISH_FEE = 3.00` (var 2.00). Bekraftat av anvandaren.
- **Debugpanel borttagen**: Mappning-diagnostik (7-path mapping) borttagen fran vyn. Mappningen behovs inte langre - fragan hamtar helt enkelt alla betalda ordrar for aret direkt.
- **Forenklad datahamtning**: Istallet for komplex 7-stegs event→recipient-mappning hamtar vyn nu alla betalda ordrar direkt med `SELECT FROM orders WHERE payment_status = 'paid' AND YEAR(created_at) = ?`. Plattformsavgift hamtas fran forsta aktiva payment_recipient.
- **Migration 050 visade alltid rod i migrations.php**: Andrad fran data-check till kolumn-check.
- **Backfill Stripe-avgifter visade 0 ordrar (TREDJE GANGEN)**: `getOne()` returnerar skalarvarde, inte array. Fixat med `getRow()`.

### Ekonomi-vyns arkitektur (efter omskrivning 2026-02-19)
- **Datakalla**: Alla betalda ordrar for valt ar hamtas direkt (ingen mappning behövs)
- **Per-order avgifter**: Stripe: faktisk fee fran `orders.stripe_fee` eller uppskattning (1,5%+2kr). Swish: alltid 3 kr. Manuell/gratis: 0 kr.
- **Plattformsavgift**: Hamtas fran `payment_recipients.platform_fee_percent` (forsta aktiva), redigerbar inline
- **Layout**: admin-table med 7 kolumner + summarad i tfoot
- **Mobil**: Alla telefoner (portrait + landscape, max 767px) visar kort-vy, desktop visar tabell
- **Stats-kort**: Forsaljning, Totala avgifter, Netto efter avgifter, Antal ordrar

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
