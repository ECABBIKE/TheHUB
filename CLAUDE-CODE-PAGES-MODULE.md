# Claude Code — Uppdrag: Pages-modul i TheHUB

## Bakgrund

GravitySeries driver idag två separata sajter:
- **thehub.gravityseries.se** — tävlingsplattformen (PHP/MySQL, vår kodbas)
- **gravityseries.se** — organisationssajten (WordPress, ska läggas ned)

Uppdraget är att flytta allt innehåll från WordPress in i TheHUB genom att bygga en **Pages-modul** — ett enkelt CMS för statiska informationssidor direkt i TheHUB.

gravityseries.se-domänen pekas sedan om till thehub.gravityseries.se. WordPress försvinner.

---

## Vad som ska byggas

### 1. Databas — `pages`-tabell

```sql
CREATE TABLE pages (
  id INT AUTO_INCREMENT PRIMARY KEY,
  slug VARCHAR(100) NOT NULL UNIQUE,
  title VARCHAR(255) NOT NULL,
  meta_description VARCHAR(300) DEFAULT NULL,
  content LONGTEXT NOT NULL,
  template ENUM('default','full-width','landing') DEFAULT 'default',
  status ENUM('published','draft') DEFAULT 'draft',
  show_in_nav TINYINT(1) DEFAULT 0,
  nav_order INT DEFAULT 99,
  nav_label VARCHAR(60) DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  created_by INT DEFAULT NULL
);
```

Lägg migreringsskriptet i `/admin/migrations/create_pages_table.sql`.

### 2. Publika sidor

**Router:** Lägg till i befintlig routing (index.php eller router.php):

```
/sida/{slug}  →  pages/show.php
/om-oss       →  alias för /sida/om-oss (redirect)
/arrangor     →  alias för /sida/arrangor-info
/licenser     →  alias för /sida/licenser
/gravity-id   →  alias för /sida/gravity-id
/kontakt      →  alias för /sida/kontakt
```

**Fil:** `pages/show.php`
- Hämtar sida via slug från databasen
- Returnerar 404 om slug inte finns eller status = draft
- Renderar med befintlig header/footer från TheHUB
- Innehållet är lagrad HTML (WYSIWYG-output) — rendera med `echo $page['content']`
- Meta title = `{page title} — GravitySeries`
- Meta description från `pages.meta_description`

### 3. Admin — Sidhantering

**Fil:** `admin/pages/index.php`
- Lista alla sidor (tabell med kolumner: Titel, Slug, Status, Visas i nav, Senast ändrad, Åtgärder)
- Knappar: Redigera, Förhandsgranska (öppnar `/sida/{slug}` i nytt fönster), Ta bort
- Knapp "Skapa ny sida" längst upp till höger
- Filtrera på status (Alla / Publicerade / Utkast)

**Fil:** `admin/pages/edit.php` (används för både skapa och redigera)
- Fält: Titel, Slug (auto-genereras från titel, kan editeras manuellt), Meta-beskrivning
- **Editor:** Använd [TinyMCE](https://www.tiny.cloud/docs/tinymce/latest/) via CDN
  ```html
  <script src="https://cdn.tiny.cloud/1/no-api-key/tinymce/7/tinymce.min.js"></script>
  ```
  Konfigurera med: bold, italic, underline, h2, h3, listor, länk, bild-URL, blockquote
- Fält: Template (default / full-width / landing)
- Fält: Status (Publicerad / Utkast)
- Checkbox: Visa i navigation
- Fält: Navigationsordning (nummer)
- Fält: Navigationslabel (om tom används titel)
- Knapp "Spara" + "Spara och förhandsgranska"

**Fil:** `admin/pages/delete.php`
- POST-endpoint, kräver CSRF-token
- Redirectar tillbaka till index med bekräftelsemeddelande

### 4. Navigationsintegration

I befintlig publik header (`includes/header.php` eller motsvarande):

Hämta sidor med `show_in_nav = 1` sorterade på `nav_order ASC`:

```php
$nav_pages = $pdo->query("
    SELECT slug, nav_label, title 
    FROM pages 
    WHERE show_in_nav = 1 AND status = 'published' 
    ORDER BY nav_order ASC
")->fetchAll();
```

Rendera som nav-items bredvid befintliga nav-items (Kalender, Resultat, Serier etc).

### 5. Startinnehåll — migrera från WordPress

Skapa ett seed-skript `admin/migrations/seed_pages.php` som infogar dessa sidor med status='draft' så de kan redigeras innan publicering:

| slug | title | nav_label | nav_order |
|------|-------|-----------|-----------|
| om-oss | Om GravitySeries | Om oss | 10 |
| arrangor-info | Arrangörsinformation | Arrangera | 20 |
| licenser | Licenser & SCF | Licenser | 30 |
| gravity-id | Gravity-ID | Gravity-ID | 40 |
| kontakt | Kontakt | Kontakt | 50 |
| allmanna-villkor | Allmänna villkor | Villkor | 60 |

Innehållet i seed-skriptet kan vara tomt (`<p>Innehåll kommer snart.</p>`) — det fylls i via editorn.

---

## Design — KRITISKT

Designen ska matcha den nya GravitySeries-estetiken som tagits fram i designreferenserna. Läs `/docs/design-references/` om den mappen finns.

### Publik sidmall (`pages/show.php`)

Layouten för en statisk informationssida:

```
[HEADER — samma som alla andra sidor i TheHUB]

[PAGE HERO — mörk bakgrund #0a0f0d]
  5px serie-stripe längst upp (accent-grön #61CE70)
  Sidtitel i Bebas Neue, stor
  Eventuell ingress

[PAGE CONTENT — ljus bakgrund #f5f3ef]
  Max-width: 760px, centrerad
  Typografi: Barlow 18px, radavstånd 1.7
  H2: Bebas Neue 36px
  H3: Barlow Condensed Bold 20px uppercase
  Länkar: accent-grön #61CE70

[FOOTER — samma som alla andra sidor]

## Page Hero — bildstöd

Lägg till ett valfritt hero-bildfält på varje sida i pages-tabellen:

ALTER TABLE pages ADD COLUMN hero_image VARCHAR(255) DEFAULT NULL;
ALTER TABLE pages ADD COLUMN hero_image_position ENUM('center','top','bottom') DEFAULT 'center';
ALTER TABLE pages ADD COLUMN hero_overlay_opacity TINYINT DEFAULT 50;

I admin/pages/edit.php:
- Filuppladdning för hero-bild (jpg/png/webp, max 2MB)
- Dropdown för bildposition (mitten/topp/botten)
- Slider för overlay-mörkhet (0–80%)

I pages/show.php — om hero_image finns:
  Bilden som bakgrund på page-hero-sektionen
  Mörkt overlay ovanpå (opacity styrs av hero_overlay_opacity)
  Titel och ingress renderas ovanpå som vanligt

CSS-mönstret:
.page-hero {
  background-image: linear-gradient(rgba(0,0,0,0.5), rgba(0,0,0,0.7)), url('{hero_image}');
  background-size: cover;
  background-position: center;
}

Om ingen bild → fallback till solid mörk bakgrund #0a0f0d (befintligt beteende).
```

### CSS-variabler att använda (redan definierade i TheHUB):
```css
--color-accent: #61CE70;
--color-gs-blue: #004a98;
--color-primary: #171717;
```

Lägg page-specifik CSS i `/assets/css/pages.css` och inkludera den via header när template = page.

### Admin-gränssnittet

Följ befintlig TheHUB admin-design (mörk sidebar, vita content-ytor, blå accent-knappar).
Inga nya design-mönster — håll det konsekvent med resten av admin.

---

## Säkerhet

- Alla admin-sidor: `requireAdmin()` i toppen
- CSRF-token på alla formulär och delete-actions
- Slug: validera med regex `^[a-z0-9-]+$`, max 100 tecken
- Content: lagras rå HTML (TinyMCE-output), renderas med `echo` — det är admin-only input, inte användarinput
- Meta-description: `htmlspecialchars()` vid rendering

---

## Filstruktur att skapa

```
pages/
  show.php                    ← Publik sidvisning

admin/pages/
  index.php                   ← Lista sidor
  edit.php                    ← Skapa/redigera
  delete.php                  ← Ta bort (POST)

admin/migrations/
  create_pages_table.sql      ← SQL för tabellen
  seed_pages.php              ← Startinnehåll

assets/css/
  pages.css                   ← Publik sid-CSS
```

---

## Definition of Done

- [ ] `pages`-tabellen skapas via migrations-SQL utan fel
- [ ] `/sida/om-oss` returnerar rätt innehåll med TheHUB header/footer
- [ ] `/sida/ej-existerande` returnerar korrekt 404
- [ ] Admin kan lista, skapa, redigera och ta bort sidor
- [ ] TinyMCE fungerar i editorn
- [ ] Slug auto-genereras från titel (JavaScript)
- [ ] Publicerade sidor med `show_in_nav=1` visas i publik navigation
- [ ] Seed-skript skapar de 6 grundsidorna
- [ ] Inga PHP-varningar eller notices
- [ ] CSRF-skydd på alla formulär

---

## Starta så här

```
Läs CLAUDE.md och /docs/design-references/ om den finns.
Börja med admin/migrations/create_pages_table.sql.
Kör sedan seed_pages.php för att skapa grundsidorna.
Bygg admin/pages/index.php och edit.php.
Bygg till sist pages/show.php med korrekt routing.
Testa hela flödet: skapa sida i admin → publicera → besök /sida/{slug}.
```
