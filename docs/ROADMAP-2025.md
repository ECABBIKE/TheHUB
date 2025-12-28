# TheHUB - Komplett Projektsammanställning
**Senast uppdaterad: 2025-12-28**
**Projektägare: JALLE**
**Status: Aktiv utveckling**
**Version:** v3.5

---

## 📋 PROJEKTÖVERSIKT

**Vad är TheHUB?**
En komplett plattform för svenska cykeltävlingar, specifikt GravitySeries. Hanterar resultat, serieställningar, ranking, ryttarprofiler, klubbar och event-administration.

**Tech Stack:** PHP/MySQL (Uppsala WebHotell)
**Användarbas:** ~3000 licensierade cyklister
**Discipliner:** Enduro, Downhill, Cross Country, Dual Slalom, Gravel

---

## 🎯 IMPLEMENTERADE FEATURES (Klart)

### ✅ Core Platform
- [x] Resultatvisning (alla serier)
- [x] Serieställningar (Capital, Götaland, Total, SweCup m.fl.)
- [x] Ryttarprofiler (rider cards)
- [x] Klubbsidor
- [x] Event-sidor
- [x] Admin-panel (CRUD för allt)
- [x] Resultatimport (flexibel CSV med SS1-SSX stöd)
- [x] Licensregister (UCI-nummer)
- [x] PWA-stöd (Progressive Web App)

### ✅ Design System (V3)
- [x] CSS-tokens och variabler
- [x] Mobile-first responsiv design
- [x] Light/Dark theme
- [x] GravitySeries färgpalett
- [x] Lucide-ikoner (ersatt emojis)
- [x] Komponent-bibliotek
- [x] Utility-klasser (Tailwind-liknande)

### ✅ Säkerhet (Fixat 2025-11)
- [x] ~~Backdoor borttagen~~
- [x] ~~Debug mode avstängd~~
- [x] CSRF-skydd
- [x] Rate limiting
- [x] Prepared statements (SQL injection)
- [x] XSS-skydd
- [x] Session-hantering
- [x] HTTPS enforcement
- [x] Security headers

### ✅ Tidigare bugfixar (Nov 2025)
- [x] Database method bug (`getOne()` → `getRow()`)
- [x] Public clubs page skapad
- [x] Import history med rollback
- [x] Sidebar permanent på desktop

### ✅ Elimination / Dual Slalom (Dec 2025)
- [x] Databastabeller för elimination brackets
  - `elimination_qualifying` - Kvalresultat (2 åk, bästa tid för seedning)
  - `elimination_brackets` - Head-to-head matchningar
  - `elimination_results` - Slutresultat
- [x] Admin-sidor för hantering
  - `/admin/elimination.php` - Översikt
  - `/admin/elimination-manage.php` - Hantera brackets per event
  - `/admin/elimination-import-qualifying.php` - CSV-import av kvalresultat
- [x] Publik visning på eventsida (elimination-flik)
- [x] Stöd för 8, 16 eller 32 åkare per bracket
- [x] B-final struktur förberedd

**Flöde:**
```
KVAL (2 åk, bästa tid) → Seedning (1-32) → BRACKET → FINAL + 3-4:e plats
```

**Användning:**
1. Kör migration via `/admin/run-migrations.php`
2. Importera kvalresultat (CSV med Startnr, Namn, Kval 1, Kval 2)
3. Generera bracket (välj storlek 8/16/32)
4. Mata in heat-resultat

---

## 💳 BETALNINGS- & ANMÄLNINGSSYSTEM (Planerat)

### Payment Gateway Arkitektur
```
┌─────────────────────────────────────────────────────────────────┐
│                      PaymentManager                             │
│                           │                                     │
│         ┌─────────────────┼─────────────────┐                   │
│         ▼                 ▼                 ▼                   │
│   ┌──────────┐     ┌──────────┐     ┌──────────────┐            │
│   │  Swish   │     │  Stripe  │     │   Klarna     │            │
│   │ Gateway  │     │ Gateway  │     │   Gateway    │            │
│   └──────────┘     └──────────┘     └──────────────┘            │
└─────────────────────────────────────────────────────────────────┘
```

### Swish-integration
| Läge | Beskrivning | Status |
|------|-------------|--------|
| **Manuell** | Visa Swish-nummer, admin markerar betalda | ✅ Klar |
| **Swish Handel** | QR-kod, automatisk callback | 📋 Planerad |
| **Multi-förening** | Varje klubb eget certifikat | 📋 Planerad |

### Stripe-integration
- Stripe Connect för multi-förening (platform-modell)
- Apple Pay / Google Pay stöd
- Kortbetalning
- Webhook-integration

---

## 🎫 CHECK-IN & STARTNUMMERSYSTEM (Planerat)

### Check-in Flöde
```
ANMÄLAN → Betalar → Får QR-kod (email + "Min Sida")
TÄVLINGSDAG → Visar QR → Scanner → ✅ Incheckad → Får startnummer
```

### Startnummer-tilldelning
**Ranking-källor:**
- Nationell ranking
- Serie-ranking (välj serie)
- UCI-ranking
- Ingen (anmälningsordning)

**Guld-plåt #1:** Alltid till bäst rankade oavsett klass

---

## 🚀 ROADMAP - PLANERADE FEATURES

### 📌 FAS 1: CORE FÖRBÄTTRINGAR (Pågående)

#### 1.1 Ryttarprofiler (Rider Cards)
- [ ] Sociala profiler - Instagram, Facebook, Strava-länkar
- [ ] Fysiskt licenskort-design
- [ ] Serie-flikar - Visa standings per serie
- [ ] Progress-bar för serieposition

#### 1.2 Badge/Achievement System 🏆
**Placeringar:** Guld/Silver/Brons med räknare
**Prestationer:** Pallserie, Fullföljare, Serieledare, Seriemästare, SM
**Experience-nivåer:** 1:a året → Legend (5+ säsonger + serieseger)
**Lojalitet:** Järnman, Comeback, Klubbhjälte, Trogen
**Design:** Hexagonala badges, Rarity-system (Common → Legendary)

#### 1.3 CSS Cleanup (Pågående)
- [x] Utility-klasser utökade (+100 nya)
- [x] Auto-fix script skapat
- [ ] Inline styles: 1426 → 774 (-46%) - fortsätt till <300

---

### 📌 FAS 2: TÄVLINGSRELATERAT

#### 2.1 Live-uppdateringar 📡
- [ ] Real-time leaderboard under race
- [ ] Push-notiser för favoritåkare
- [ ] "Följ åkare" - prenumerera på resultat
- [ ] Live-timing integration (SiTiming)

#### 2.2 Head-to-Head Jämförelser
- [ ] Jämför två åkares historik
- [ ] Gemensamma event och tidsskillnader

#### 2.3 Event-kartsystem 🗺️
- [ ] GPX-uppladdning
- [ ] Stage/Liaison klassificering
- [ ] POI-system (12 typer)
- [ ] Höjdprofil med färgkodning
- [ ] Offline-stöd

---

### 📌 FAS 3: GAMIFICATION & COMMUNITY

#### 3.1 Predictions / Fantasy League
- [ ] Tippa resultat inför varje race
- [ ] Poängsystem för korrekta tippningar
- [ ] Säsongsliga

#### 3.2 Community Features
- [ ] Hitta träningspartners
- [ ] Spårstatusrapporter
- [ ] Gemensam kalender

---

### 📌 FAS 4: E-HANDEL & BETALNINGAR

#### 4.1 Biljettförsäljning
- [ ] Varukorg i TheHUB
- [ ] Prismatris per klass/ålder/licenstyp
- [ ] Early bird / Late fee
- [ ] Familjeanmälan

#### 4.2 Marknadsplats
- [ ] Begagnad utrustning
- [ ] Kopplat till verifierad profil
- [ ] Tjänster (mekaniker, coaching)

---

### 📌 FAS 5: MEDIA & GALLERI

#### 5.1 Eventgallerier
- [ ] Fotografer laddar upp per event
- [ ] Automatisk taggning av åkare
- [ ] Köp högupplösta bilder

#### 5.2 Race Reports (Instagram)
- [ ] Hashtag #GravitySeriesReport
- [ ] Automatisk hämtning via API
- [ ] Koppling till ryttarprofil

---

### 📌 FAS 6: AVANCERADE FEATURES

#### 6.1 Statistik & Analytics
- [ ] Dashboard med KPI:er
- [ ] Event-statistik
- [ ] Geografisk fördelning

#### 6.2 PWA & Offline
- [ ] Fullständig PWA
- [ ] Offline-resultat
- [ ] Push-notifikationer

#### 6.3 API
- [ ] Public API för tredjepartsintegrationer
- [ ] Timing-system API

---

## 🔗 THEHUB + GRAVITYSERIES INTEGRATION

### Nuvarande struktur
```
gravityseries.se (WordPress) → Info, nyheter, licenser
thehub.gravityseries.se (PHP) → Data, resultat, anmälan
```

### Långsiktig plan
```
Fas 1 (Nu): Hybrid - gemensam design, länka mellan systemen
Fas 2: Bygg enkel CMS i TheHUB
Fas 3: Eventuellt fasa ut WordPress helt
```

---

## 🗃️ POÄNGSYSTEM

### 1. Serie-poäng
- Poäng baserat på placering
- Strykmatch-system (bästa X av Y)
- Automatisk beräkning vid resultatimport

### 2. Ranking (24-månaders rolling)
- Tidsdecay (äldre resultat väger mindre)
- Fältstorlek-viktning

### 3. Klubbpoäng
- Topp X åkare per klubb räknas

---

## 🎨 DESIGN TOKENS (Quick Reference)

```css
/* Färger */
--color-primary: #171717
--color-accent: #61CE70
--color-gs-blue: #004a98
--color-ges-orange: #EF761F

/* Typografi */
--font-heading: 'Oswald'
--font-body: 'Manrope'

/* Spacing */
--space-md: 16px
--space-lg: 24px
```

---

## 📦 IDÉBANK (Framtida)

- [ ] Sponsorintegration
- [ ] Premium-medlemskap
- [ ] Coaching-plattform
- [ ] Livestreaming
- [ ] Virtuella tävlingar
- [ ] UCI-poäng integration
- [ ] Multi-language

### Nekade idéer
- ~~Forum~~ (finns Facebook-grupper)
- ~~Chat~~ (finns Messenger)
- ~~Intern betting~~ (juridiska problem)

---

## 📝 CHANGE LOG

| Datum | Uppdatering |
|-------|-------------|
| 2025-12-28 | Elimination/Dual Slalom system implementerat |
| 2025-12-23 | CSS cleanup: 1426→774 inline styles (-46%) |
| 2025-12-23 | Roadmap sammanslagen och uppdaterad |
| 2025-12-18 | Komplett projektsammanställning |
| 2025-12-14 | CSS/Design system cleanup påbörjad |
| 2025-11-14 | Säkerhetsfixar genomförda (backdoor, debug, rate limiting) |
| 2025-11-14 | Database method bug fixad |

---

## 🎯 ANVÄNDNING FÖR CLAUDE CODE

**Innan du börjar utveckla:**
1. Läs denna fil för projektöversikt
2. Läs `CLAUDE.md` för tekniska krav och kodstandard
3. Följ etablerade mönster och CSS-tokens
4. Uppdatera denna fil när features är klara

---

**Dokumentägare:** JALLE
**Status:** AKTIV UTVECKLING
