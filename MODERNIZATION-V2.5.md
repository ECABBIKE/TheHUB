# TheHUB V2 → V2.5 Modernisering - Implementation Guide

## ✅ Vad som har implementerats

### 1. CSS Tema-System
- ✅ `/assets/css/tokens.css` - Design tokens (färger, spacing, typografi)
- ✅ `/assets/css/theme-base.css` - Bas-komponenter med light/dark tema
- ✅ Automatisk dark mode baserat på system eller manuellt val

### 2. JavaScript
- ✅ `/assets/js/theme.js` - Tema-switcher logik
- ✅ `/assets/js/dropdown.js` - Dropdown-funktionalitet för användarmeny

### 3. Komponenter
- ✅ `/includes/nav-bottom.php` - Bottom navigation (mobil)
- ✅ `/includes/header-modern.php` - Modern header med tema-switcher och profilmeny

### 4. PWA Support
- ✅ `/manifest.json` - PWA manifest
- ✅ Meta-taggar för iOS och Android

### 5. Integration
- ✅ `/includes/layout-header.php` - Uppdaterad med nya CSS och PWA meta-taggar
- ✅ `/includes/layout-footer.php` - Uppdaterad med bottom nav och tema-scripts

---

## 🚀 Hur du använder det

### AUTOMATISK INTEGRATION

**Alla sidor som redan använder `layout-header.php` och `layout-footer.php` får automatiskt:**

1. ✅ Tema-system (light/dark/auto)
2. ✅ Bottom navigation på mobil
3. ✅ PWA meta-taggar
4. ✅ Nya CSS-variabler

**Ingen ändring krävs i befintliga sidor!**

### Exempel - Befintlig sida

```php
<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/helpers.php';

$pageTitle = 'Kalender';
$pageType = 'public';
include __DIR__ . '/includes/layout-header.php';
?>

<main class="gs-main-content">
    <div class="gs-container">
        <h1>Min sida</h1>
        <!-- Din content här -->
    </div>
</main>

<?php include __DIR__ . '/includes/layout-footer.php'; ?>
```

**Detta fungerar direkt med alla nya funktioner! 🎉**

---

## 🎨 Använda Modern Header (med tema-switcher)

Om du vill lägga till den nya moderna headern med tema-switcher:

### Option 1: Lägg till i befintlig sidebar/header

Lägg till denna kod där du vill ha tema-switchern:

```php
<!-- Tema-switcher -->
<div class="theme-switcher">
    <button data-theme-set="light" class="theme-btn" title="Ljust tema">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <circle cx="12" cy="12" r="4"/>
            <path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M6.34 17.66l-1.41 1.41M19.07 4.93l-1.41 1.41"/>
        </svg>
    </button>
    <button data-theme-set="auto" class="theme-btn" title="Auto">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <rect width="20" height="14" x="2" y="3" rx="2"/>
            <line x1="8" x2="16" y1="21" y2="21"/>
            <line x1="12" x2="12" y1="17" y2="21"/>
        </svg>
    </button>
    <button data-theme-set="dark" class="theme-btn" title="Mörkt tema">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9Z"/>
        </svg>
    </button>
</div>
```

### Option 2: Använd den färdiga moderna headern

```php
<?php include __DIR__ . '/includes/header-modern.php'; ?>
```

---

## 📱 Bottom Navigation

Bottom nav visas **automatiskt på mobil/tablet** (`< 1024px`) och **ersätter sidebaren**.

**Beteende:**
- **Desktop (≥1024px):** Sidebar visas permanent
- **Mobil/Tablet (<1024px):** Bottom nav visas, sidebar och hamburger-meny döljs

**Navigation items finns i:** `/includes/nav-bottom.php`

**Standardnavigation:**
- 🏠 Hem
- 📅 Kalender (events.php)
- 🏆 Resultat (results.php)
- 🥇 Serier (series.php)
- 📈 Ranking (ranking/)

**Anpassa navigationen:**

```php
$navItems = [
    ['id' => 'index', 'label' => 'Hem', 'url' => '/', 'icon' => 'home'],
    ['id' => 'events', 'label' => 'Kalender', 'url' => '/events.php', 'icon' => 'calendar'],
    ['id' => 'results', 'label' => 'Resultat', 'url' => '/results.php', 'icon' => 'trophy'],
    ['id' => 'series', 'label' => 'Serier', 'url' => '/series.php', 'icon' => 'award'],
    ['id' => 'ranking', 'label' => 'Ranking', 'url' => '/ranking/', 'icon' => 'trending-up'],
    // Lägg till fler här...
];
```

---

## 🎨 Använda nya CSS-variabler

### Färger

```css
.my-component {
    background: var(--color-bg-surface);
    color: var(--color-text-primary);
    border: 1px solid var(--color-border);
}

.my-button {
    background: var(--color-accent);
    color: white;
}

.my-button:hover {
    background: var(--color-accent-hover);
}
```

### Spacing

```css
.my-card {
    padding: var(--space-lg);
    margin-bottom: var(--space-md);
    gap: var(--space-sm);
}
```

### Komponenter

Använd färdiga CSS-klasser:

```html
<!-- Knappar -->
<button class="btn btn--primary">Primary</button>
<button class="btn btn--secondary">Secondary</button>
<button class="btn btn--ghost">Ghost</button>

<!-- Badges -->
<span class="badge badge--success">Aktiv</span>
<span class="badge badge--error">Fel</span>
<span class="badge badge--gold">1:a plats</span>

<!-- Cards -->
<div class="card">
    <h3>Card title</h3>
    <p>Card content</p>
</div>

<!-- Alerts -->
<div class="alert alert--success">Sparad!</div>
<div class="alert alert--error">Ett fel uppstod</div>
```

---

## 🌓 Tema-funktioner i JavaScript

```javascript
// Byt tema programmatiskt
Theme.setTheme('dark');  // 'light', 'dark', 'auto'

// Hämta nuvarande tema
const current = Theme.getCurrent();  // 'light', 'dark', 'auto'

// Hämta effektivt tema (vad som faktiskt visas)
const effective = Theme.getEffective();  // 'light' eller 'dark'

// Toggle mellan light/dark
Theme.toggle();

// Lyssna på tema-ändringar
window.addEventListener('themechange', (e) => {
    console.log('New theme:', e.detail.theme);
});
```

---

## 📦 PWA - Progressive Web App

### Vad fungerar redan:

✅ Manifest.json skapad
✅ Meta-taggar för iOS/Android
✅ Theme color
✅ Installera på hemskärmen-stöd

### Vad behöver göras:

1. **Skapa ikoner:**
   - Placera `icon-192.png` och `icon-512.png` i `/assets/icons/`
   - Se `/assets/icons/README.md` för instruktioner

2. **Service Worker (optional för offline):**
   - Lägg till `/service-worker.js` om ni vill ha offline-stöd
   - Inte nödvändigt för grundläggande PWA-funktionalitet

---

## 🧪 Testa

### Tema-switcher
1. Öppna webbplatsen
2. Testa knapparna: ☀️ (light), 💻 (auto), 🌙 (dark)
3. Kontrollera att temat sparas vid omladdning

### Bottom Nav
1. Öppna på mobil eller minska fönstret till < 1024px
2. Bottom navigation ska synas längst ner
3. Sidebar och hamburger-meny ska vara dolda
4. Aktiv sida ska markeras med accent-färg
5. Testa att navigera mellan sidorna - länkarna ska fungera

### PWA
1. **Chrome Desktop:** DevTools → Application → Manifest
2. **Chrome Mobile:** Settings → "Lägg till på hemskärmen"
3. **Safari iOS:** Share → "Lägg till på hemskärmen"

### Dark Mode
1. Testa med system dark mode (OS-inställningar)
2. Testa manuell switch
3. Kontrollera att alla färger ser bra ut

---

## 🔧 Felsökning

### Tema fungerar inte
- Kontrollera att `/assets/js/theme.js` laddas
- Öppna Console och se efter JavaScript-fel
- Kolla att localStorage inte är blockerat

### CSS ser konstigt ut
- Kontrollera att `tokens.css` och `theme-base.css` laddas FÖRE andra CSS-filer
- Cache-problem? Lägg till `?v=2` i URL:en eller force-refresh (Ctrl+Shift+R)

### Bottom nav syns inte på mobil
- Kontrollera att `/includes/nav-bottom.php` inkluderas i footer
- Kolla att `theme-base.css` har laddats
- Öppna DevTools och se om elementet finns men är dolt

### Dubbla menyer på mobil
- Detta borde vara fixat automatiskt
- Bottom nav ersätter sidebar/hamburger på mobil (<1024px)
- Om du ser både sidebar och bottom nav, kontrollera att `theme-base.css` laddas efter `gravityseries-main.css`
- Force-refresh (Ctrl+Shift+R) för att rensa cache

### PWA fungerar inte
- Kontrollera att `/manifest.json` är tillgänglig
- Se till att ikonerna finns (192x192 och 512x512)
- PWA kräver HTTPS i produktion

---

## 📋 Checklista - Full Implementation

- [x] CSS tokens skapade
- [x] Tema-system implementerat
- [x] Bottom navigation skapad
- [x] Modern header skapad
- [x] JavaScript för tema och dropdown
- [x] PWA manifest
- [x] layout-header.php uppdaterad
- [x] layout-footer.php uppdaterad
- [ ] Skapa PWA ikoner (192x192, 512x512)
- [ ] Testa på olika enheter
- [ ] Testa light/dark mode
- [ ] Verifiera att gamla sidor fortfarande fungerar

---

## 🎯 Nästa steg (rekommenderat)

1. **Skapa PWA ikoner** - Se `/assets/icons/README.md`
2. **Testa på olika enheter** - Desktop, mobil, tablet
3. **Anpassa bottom navigation** - Lägg till/ta bort items efter behov
4. **Migrera befintliga komponenter** - Byt ut hårdkodade färger mot CSS-variabler
5. **Lägg till tema-switcher i sidebar** - För bättre synlighet på desktop

---

## 📄 Filer som skapats/uppdaterats

### Nya filer:
```
/assets/css/tokens.css
/assets/css/theme-base.css
/assets/js/theme.js
/assets/js/dropdown.js
/includes/nav-bottom.php
/includes/header-modern.php
/manifest.json
/assets/icons/README.md
```

### Uppdaterade filer:
```
/includes/layout-header.php  (nya CSS + PWA meta)
/includes/layout-footer.php  (bottom nav + tema scripts)
```

---

## 💡 Tips

- **Befintlig CSS:** Gamla CSS-filer fortsätter fungera. Tokens-systemet lägger bara till nya möjligheter.
- **Stegvis migration:** Du kan gradvis byta ut hårdkodade värden mot CSS-variabler.
- **Backwards compatible:** Allt som fungerade i V2 fungerar fortfarande.
- **Testa i mörkt läge:** Se till att alla komponenter ser bra ut i både light och dark mode.

---

## 🤝 Support

Om något inte fungerar:
1. Kolla Console för JavaScript-fel
2. Verifiera att alla filer har laddats korrekt (Network-tab i DevTools)
3. Se till att `layout-header.php` och `layout-footer.php` används korrekt
4. Kontrollera att file paths är korrekta

---

**TheHUB V2.5 - Modern, snabb, PWA-ready! 🚀**
