# Claude Code — Förbättra gravityseries/-sajten

## Bakgrund

Sajten på `/gravityseries/` är redan byggd och live på thehub.gravityseries.se/gravityseries/  
Struktur, routing och innehåll fungerar. Det är **designen och serie-korten** som ska förbättras.

**Rör inte:**

- Routing och URL-struktur
- PHP-logik och databasanrop
- Sidornas innehåll (texter, namn, länkar)
- Befintliga sidor: om-oss, arrangor-info, licenser, gravity-id, kontakt

**Förbättra:**

- Temabyte mörkt/ljust (toggle i headern)
- Serie-korten på startsidan
- Övergripande sports-känsla

-----

## 1. Dubbelt tema — mörkt/ljust

Lägg till CSS-variabler för båda teman i `gravityseries/assets/css/gs-site.css`:

```css
:root {
  --accent:   #61CE70;
  --accent-d: #3fa84d;
  --gs-blue:  #004a98;
  --ggs:      #87c442;
  --ges:      #ff7a18;
  --cgs:      #28a8ff;
  --gsdh:     #1d63ff;
  --jgs:      #c084fc;
  --font-display: 'Bebas Neue', sans-serif;
  --font-cond:    'Barlow Condensed', sans-serif;
  --font-body:    'Barlow', sans-serif;
}

[data-theme="dark"] {
  --bg:        #0a0d12;
  --bg-2:      #111620;
  --surface:   rgba(17,23,33,.92);
  --surface-2: rgba(255,255,255,.04);
  --border:    rgba(255,255,255,.08);
  --border-s:  rgba(255,255,255,.16);
  --text:      #edf2f7;
  --text-2:    #a9b4c4;
  --text-3:    #6a7a90;
  --header-bg: rgba(8,11,16,.86);
}

[data-theme="light"] {
  --bg:        #f5f3ef;
  --bg-2:      #ffffff;
  --surface:   #ffffff;
  --surface-2: rgba(0,0,0,.03);
  --border:    #e0ddd8;
  --border-s:  #c8c4be;
  --text:      #0a0f0d;
  --text-2:    #4a5550;
  --text-3:    #8a9490;
  --header-bg: rgba(10,15,13,.92);
}
```

Lägg `data-theme="dark"` på `<html>`-elementet som default.

**Toggle-knapp i headern** — en enkel sol/måne-knapp längst till höger i nav, innan TheHUB-knappen:

```html
<button class="theme-toggle" id="themeToggle" aria-label="Byt tema">
  <!-- Måne-ikon (visas i dark mode) -->
  <svg class="icon-moon" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
    <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/>
  </svg>
  <!-- Sol-ikon (visas i light mode) -->
  <svg class="icon-sun" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
    <circle cx="12" cy="12" r="5"/>
    <line x1="12" y1="1" x2="12" y2="3"/>
    <line x1="12" y1="21" x2="12" y2="23"/>
    <line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/>
    <line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/>
    <line x1="1" y1="12" x2="3" y2="12"/>
    <line x1="21" y1="12" x2="23" y2="12"/>
    <line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/>
    <line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/>
  </svg>
</button>
```

CSS för toggle:

```css
[data-theme="dark"] .icon-sun  { display: block; }
[data-theme="dark"] .icon-moon { display: none; }
[data-theme="light"] .icon-sun  { display: none; }
[data-theme="light"] .icon-moon { display: block; }

.theme-toggle {
  background: var(--surface-2);
  border: 1px solid var(--border);
  border-radius: 8px;
  color: var(--text-2);
  width: 36px; height: 36px;
  display: flex; align-items: center; justify-content: center;
  cursor: pointer;
  transition: background .15s, color .15s;
}
.theme-toggle:hover { color: var(--text); background: var(--surface); }
```

JavaScript (inline i footer eller separat gs-theme.js):

```javascript
const toggle = document.getElementById('themeToggle');
const html   = document.documentElement;

// Återställ sparat val
const saved = localStorage.getItem('gs-theme') || 'dark';
html.setAttribute('data-theme', saved);

toggle.addEventListener('click', () => {
  const next = html.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
  html.setAttribute('data-theme', next);
  localStorage.setItem('gs-theme', next);
});
```

-----

## 2. Serie-korten — bygg om

Ersätt befintliga serie-kort med denna komponent. Varje kort visar:

- Serie-badge + disciplin-tag
- Titel
- Stats: deltävlingar / avgjorda / åkare / kvar
- Event-pills (avgjorda gråa, nästa markerad)
- **Klubbmästerskap** — top 3 klubbar istället för individuell ledare

### HTML-struktur per kort:

```html
<article class="gs-serie-card {serie-klass}" data-serie="{abbr}">
  <div class="gsc-inner">

    <!-- TOP -->
    <div class="gsc-top">
      <div class="gsc-badge">
        <i class="gsc-dot"></i>
        {ABBR}
      </div>
      <span class="gsc-discipline">{Enduro / Downhill}</span>
    </div>

    <!-- TITEL -->
    <div class="gsc-title-wrap">
      <h3 class="gsc-title">{Serie-namn}</h3>
      <div class="gsc-meta">{Disciplin} · {Region}</div>
    </div>

    <!-- STATS -->
    <div class="gsc-stats">
      <div class="gsc-stat"><strong>{N}</strong><span>Deltävlingar</span></div>
      <div class="gsc-stat"><strong>{N}</strong><span>Avgjorda</span></div>
      <div class="gsc-stat"><strong>{N}</strong><span>Åkare</span></div>
      <div class="gsc-stat"><strong>{N}</strong><span>Kvar</span></div>
    </div>

    <!-- EVENT PILLS -->
    <div class="gsc-events">
      <div class="gsc-pill done"><i></i> {Event}</div>
      <div class="gsc-pill next"><i></i> {Nästa event}</div>
    </div>

    <!-- KLUBBMÄSTERSKAP -->
    <div class="gsc-clubs">
      <div class="gsc-clubs-label">Klubbmästerskap</div>
      <div class="gsc-club-row">
        <span class="gsc-club-pos">1</span>
        <span class="gsc-club-name">{Klubbnamn}</span>
        <span class="gsc-club-pts">{N} p</span>
      </div>
      <div class="gsc-club-row">
        <span class="gsc-club-pos">2</span>
        <span class="gsc-club-name">{Klubbnamn}</span>
        <span class="gsc-club-pts">{N} p</span>
      </div>
      <div class="gsc-club-row">
        <span class="gsc-club-pos">3</span>
        <span class="gsc-club-name">{Klubbnamn}</span>
        <span class="gsc-club-pts">{N} p</span>
      </div>
    </div>

  </div>
</article>
```

### CSS för serie-korten:

```css
/* Varje serie har en accent-färg via CSS-klass */
.gs-serie-card          { --c: var(--accent); }
.gs-serie-card.ggs      { --c: var(--ggs); }
.gs-serie-card.ges      { --c: var(--ges); }
.gs-serie-card.cgs      { --c: var(--cgs); }
.gs-serie-card.gsdh     { --c: var(--gsdh); }
.gs-serie-card.jgs      { --c: var(--jgs); }

.gs-serie-card {
  position: relative;
  border-radius: 24px;
  overflow: hidden;
  border: 1px solid var(--border);
  background: var(--surface);
  box-shadow: 0 20px 48px rgba(0,0,0,.22);
  transition: transform .2s, box-shadow .2s;
}
.gs-serie-card:hover {
  transform: translateY(-3px);
  box-shadow: 0 28px 56px rgba(0,0,0,.3);
}

/* Gradient-overlay baserad på serie-färg */
.gs-serie-card::before {
  content: '';
  position: absolute;
  inset: 0;
  background:
    radial-gradient(circle at 100% 0%, color-mix(in srgb, var(--c) 22%, transparent), transparent 40%),
    linear-gradient(160deg, color-mix(in srgb, var(--c) 14%, transparent), transparent 45%);
  pointer-events: none;
}

/* I light mode — subtilare gradient */
[data-theme="light"] .gs-serie-card::before {
  background:
    radial-gradient(circle at 100% 0%, color-mix(in srgb, var(--c) 10%, transparent), transparent 40%);
}

.gsc-inner {
  position: relative;
  z-index: 1;
  padding: 22px;
  display: flex;
  flex-direction: column;
  gap: 16px;
}

/* TOP ROW */
.gsc-top { display: flex; justify-content: space-between; align-items: center; }
.gsc-badge {
  display: inline-flex; align-items: center; gap: 8px;
  padding: 6px 12px;
  border-radius: 999px;
  background: var(--surface-2);
  border: 1px solid var(--border-s);
  font-family: var(--font-cond);
  font-size: .82rem; font-weight: 800; letter-spacing: .1em; text-transform: uppercase;
  color: var(--text);
}
.gsc-dot {
  width: 9px; height: 9px; border-radius: 50%;
  background: var(--c);
  box-shadow: 0 0 0 5px color-mix(in srgb, var(--c) 22%, transparent);
}
.gsc-discipline {
  font-family: var(--font-cond);
  font-size: .76rem; font-weight: 800; letter-spacing: .1em; text-transform: uppercase;
  padding: 5px 10px; border-radius: 10px;
  background: var(--surface-2); border: 1px solid var(--border);
  color: var(--text-2);
}

/* TITLE */
.gsc-title {
  font-family: var(--font-display);
  font-size: clamp(26px, 3vw, 36px);
  letter-spacing: .02em;
  line-height: .92;
  color: var(--text);
  margin-bottom: 4px;
}
.gsc-meta {
  font-family: var(--font-cond);
  font-size: .9rem; color: var(--text-3); font-weight: 500;
}

/* STATS */
.gsc-stats {
  display: grid; grid-template-columns: repeat(4,1fr); gap: 8px;
}
.gsc-stat {
  padding: 12px 10px;
  border-radius: 14px;
  background: var(--surface-2);
  border: 1px solid var(--border);
  text-align: center;
}
.gsc-stat strong {
  display: block;
  font-family: var(--font-display);
  font-size: 1.8rem; line-height: 1;
  color: var(--text);
}
.gsc-stat span {
  display: block; margin-top: 4px;
  font-family: var(--font-cond);
  font-size: .72rem; font-weight: 700; letter-spacing: .1em; text-transform: uppercase;
  color: var(--text-3);
}

/* EVENT PILLS */
.gsc-events { display: flex; flex-wrap: wrap; gap: 8px; }
.gsc-pill {
  display: inline-flex; align-items: center; gap: 7px;
  padding: 7px 12px; border-radius: 999px;
  background: var(--surface-2); border: 1px solid var(--border);
  font-family: var(--font-cond);
  font-size: .86rem; font-weight: 700; color: var(--text-2);
}
.gsc-pill i { width: 7px; height: 7px; border-radius: 50%; background: var(--text-3); flex-shrink: 0; }
.gsc-pill.done { opacity: .5; }
.gsc-pill.next {
  border-color: color-mix(in srgb, var(--c) 50%, var(--border));
  color: var(--text);
}
.gsc-pill.next i { background: var(--c); box-shadow: 0 0 0 4px color-mix(in srgb, var(--c) 20%, transparent); }

/* KLUBBMÄSTERSKAP */
.gsc-clubs {
  background: var(--surface-2);
  border: 1px solid var(--border);
  border-radius: 16px;
  overflow: hidden;
}
.gsc-clubs-label {
  font-family: var(--font-cond);
  font-size: .72rem; font-weight: 800; letter-spacing: .14em; text-transform: uppercase;
  color: var(--c);
  padding: 10px 14px 8px;
  border-bottom: 1px solid var(--border);
}
.gsc-club-row {
  display: grid; grid-template-columns: 28px 1fr auto;
  align-items: center; gap: 10px;
  padding: 10px 14px;
  border-bottom: 1px solid var(--border);
  transition: background .15s;
}
.gsc-club-row:last-child { border-bottom: none; }
.gsc-club-row:hover { background: var(--surface-2); }
.gsc-club-pos {
  font-family: var(--font-display);
  font-size: 1.1rem; color: var(--text-3);
  text-align: center;
}
.gsc-club-row:first-of-type .gsc-club-pos { color: var(--c); }
.gsc-club-name {
  font-family: var(--font-cond);
  font-size: .95rem; font-weight: 700; color: var(--text);
}
.gsc-club-pts {
  font-family: var(--font-display);
  font-size: 1.1rem; color: var(--text-2);
  white-space: nowrap;
}
```

### Grid-layout för korten:

```css
.gs-series-grid {
  display: grid;
  grid-template-columns: repeat(12, 1fr);
  gap: 16px;
  max-width: var(--max, 1240px);
  margin: 0 auto;
  padding: 0 24px;
}

/* 2 kort per rad på desktop */
.gs-serie-card { grid-column: span 6; }

/* 1 per rad på mobil */
@media (max-width: 768px) {
  .gs-serie-card { grid-column: span 12; }
}
```

-----

## 3. Data för klubbmästerskap

Om det inte finns klubbpoäng i databasen ännu — visa placeholder:

```php
// Hämta top 3 klubbar per serie om data finns
$clubs = $pdo->prepare("
    SELECT r.club, SUM(r.points) as total
    FROM results r
    JOIN events e ON r.event_id = e.id
    WHERE e.series_id = ?
    AND r.club IS NOT NULL AND r.club != ''
    GROUP BY r.club
    ORDER BY total DESC
    LIMIT 3
");
$clubs->execute([$serie['id']]);
$top_clubs = $clubs->fetchAll();

// Om inga klubbpoäng finns — visa "Säsongen pågår"
if (empty($top_clubs)) {
    // Visa placeholder-state i kortet
}
```

Placeholder-state i HTML:

```html
<div class="gsc-clubs-empty">
  <span>Säsongen pågår — ställningen uppdateras efter varje deltävling</span>
</div>
```

```css
.gsc-clubs-empty {
  padding: 14px;
  font-family: var(--font-cond);
  font-size: .9rem; color: var(--text-3); font-style: italic;
  text-align: center;
}
```

-----

## 4. Bakgrund på dark mode — sports-känsla

I `[data-theme="dark"] body`:

```css
[data-theme="dark"] body {
  background:
    radial-gradient(circle at 15% 0%, rgba(0,74,152,.22), transparent 30%),
    radial-gradient(circle at 88% 8%, rgba(97,206,112,.07), transparent 25%),
    linear-gradient(180deg, #0b0f15 0%, #0a0d12 100%);
}

/* Subtilt grid-mönster */
[data-theme="dark"] body::before {
  content: '';
  position: fixed; inset: 0; pointer-events: none;
  background:
    linear-gradient(rgba(255,255,255,.018) 1px, transparent 1px),
    linear-gradient(90deg, rgba(255,255,255,.018) 1px, transparent 1px);
  background-size: 40px 40px;
  mask-image: linear-gradient(to bottom, rgba(0,0,0,.4), rgba(0,0,0,.08) 60%, transparent);
}
```

-----

## Sammanfattning — vad Code ska göra

1. Lägg till CSS-variabler för dark/light i `gravityseries/assets/css/gs-site.css`
1. Lägg `data-theme="dark"` på `<html>` i `gravityseries/includes/gs-header.php`
1. Lägg till theme-toggle-knapp i headern med JavaScript
1. Ersätt befintliga serie-kort i `gravityseries/index.php` med ny komponent
1. Lägg till klubbmästerskap-query och rendering per kort
1. Uppdatera body-bakgrund för dark mode

**Rör inga andra filer.**