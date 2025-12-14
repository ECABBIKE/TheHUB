# CLAUDE CODE PROMPT - MOBILE EDGE-TO-EDGE FIX

## 🎯 PROBLEM
Result cards, event cards och andra kort är **INTE** edge-to-edge på mobil trots CSS-fix. Användaren har testat att lägga till debug CSS men **inga ändringar syns**.

## 📱 VEM HAR PROBLEM
iPhone-användare på TheHUB ser att kort har margins på sidorna istället för att gå kant-till-kant.

## 🔍 ROOT CAUSE ANALYS BEHÖVS

Du behöver undersöka VARFÖR CSS inte appliceras:

### Möjliga orsaker:
1. **CSS cachas** - Browser eller server cache
2. **Inline styles overstyrer** - `<style>` taggar i PHP-filer
3. **Högre specificitet** - Andra CSS-regler vinner
4. **Fel selektorer** - CSS matchar inte faktiska klasser
5. **Fel fil laddas** - components.css vs annan CSS-fil

## 📋 STEG-FÖR-STEG FIX

### STEG 1: IDENTIFIERA FAKTISKA CSS-KLASSER

Analysera dessa filer för att hitta exakta klassnamn:

```bash
# Result cards
v2/results.php

# Event cards  
v2/events.php

# Container struktur
includes/layout-header.php
components/head.php
```

**Hittade klasser:**
- Result cards: `.card`, `.gs-result-card`, `.gs-event-card-transition`
- Event cards: `.card`, `.card-hover`, `.event-card-horizontal`, `.gs-event-card-transition`
- Filter: `.card`
- Container: Okänd - HIT TA REDA PÅ!

### STEG 2: HITTA VAR INLINE STYLES FINNS

Sök efter `<style>` taggar i PHP-filer:

```bash
grep -r "<style>" v2/*.php
```

**Känt problem:**
- `v2/events.php` har inline styles som definierar `.event-card-horizontal` grid
- `v2/results.php` har liknande inline styles
- Dessa kan overrida extern CSS!

### STEG 3: CACHE-BUSTING

Lägg till versionsnummer på CSS:

**I components/head.php rad 64-72:**

```php
<!-- Före -->
<link rel="stylesheet" href="<?= hub_asset('css/components.css') ?>">

<!-- Efter -->
<link rel="stylesheet" href="<?= hub_asset('css/components.css?v=' . time()) ?>">
```

ELLER ändra alla till:

```php
<link rel="stylesheet" href="/assets/css/components.css?v=<?= filemtime(__DIR__ . '/../assets/css/components.css') ?>">
```

### STEG 4: APPLICERA EDGE-TO-EDGE CSS

**A) I assets/css/tokens.css** - Lägg till efter rad 26:

```css
:root {
  /* Container padding för mobile-first */
  --container-padding: 16px;
}

/* Tablet får mer luft */
@media (min-width: 768px) {
  :root {
    --container-padding: 24px;
  }
}

/* Desktop får max padding */
@media (min-width: 1024px) {
  :root {
    --container-padding: 32px;
  }
}
```

**B) I assets/css/components.css** - Ersätt rad 56-102:

```css
/* MOBILE EDGE-TO-EDGE SYSTEM - SPECIFIKT FÖR THEHUB */
@media (max-width: 767px) {
  /* Säkerställ container har rätt padding */
  .container,
  .page-content,
  main {
    padding-left: var(--container-padding);
    padding-right: var(--container-padding);
  }

  /* ALLA KORT: Edge-to-edge */
  .card,
  .gs-result-card,
  .event-card-horizontal,
  .filter-row,
  .filters-bar,
  .alert {
    margin-left: calc(-1 * var(--container-padding)) !important;
    margin-right: calc(-1 * var(--container-padding)) !important;
    border-radius: 0 !important;
    border-left: none !important;
    border-right: none !important;
    width: auto !important;
    max-width: none !important;
  }

  /* Återställ padding inuti kort */
  .card-header,
  .card-body,
  .gs-result-info,
  .event-info-right {
    padding-left: var(--container-padding);
    padding-right: var(--container-padding);
  }

  /* Специальные случаи */
  .event-card-horizontal {
    padding: var(--container-padding);
  }

  /* Listor och tabeller */
  .table-responsive,
  .table-wrapper,
  .result-list,
  .event-list {
    margin-left: calc(-1 * var(--container-padding)) !important;
    margin-right: calc(-1 * var(--container-padding)) !important;
  }
}
```

**C) FIXA INLINE STYLES**

I **v2/events.php** och **v2/results.php**, hitta `<style>` taggar och lägg till:

```css
/* Lägg till i befintlig <style> block */
@media (max-width: 767px) {
  .event-card-horizontal,
  .gs-result-card {
    margin-left: -16px !important;
    margin-right: -16px !important;
    border-radius: 0 !important;
    border-left: none !important;
    border-right: none !important;
    width: calc(100% + 32px) !important;
    max-width: none !important;
  }
  
  .event-card-horizontal {
    padding: 16px !important;
    grid-template-columns: 100px 1fr !important;
    gap: 12px !important;
  }
}
```

### STEG 5: VERIFIERA CONTAINER-STRUKTUR

Kolla vilken wrapper som faktiskt används:

```bash
grep -B 20 "events-two-column\|event-list" v2/events.php | grep -E "<main|<div class"
```

Om main-taggen INTE har padding → Lägg till:

```css
main {
  padding: 0 var(--container-padding);
}
```

### STEG 6: TESTA

1. Rensa browser cache (Cmd+Shift+R på iPhone i Safari)
2. Öppna v2/events.php på mobil
3. Öppna v2/results.php på mobil
4. Verifiera:
   - [ ] Event cards går kant-till-kant
   - [ ] Result cards går kant-till-kant
   - [ ] Filter-kort går kant-till-kant
   - [ ] Sponsor-kort går kant-till-kant

### STEG 7: DEBUG OM DET INTE FUNGERAR

**A) Inspect Element på mobil:**

Safari på iPhone:
1. Settings → Safari → Advanced → Web Inspector
2. Anslut iPhone till Mac
3. Safari på Mac → Develop → [iPhone] → TheHUB
4. Inspektera ett kort
5. Kolla Computed Styles för:
   - `margin-left`
   - `margin-right`
   - `width`
   - `border-radius`

**B) Se vilka CSS-filer som laddas:**

```javascript
// I console på mobil:
Array.from(document.styleSheets).map(s => s.href).filter(h => h && h.includes('css'))
```

**C) Testa om komponenter.css laddas:**

```javascript
// I console:
const styles = getComputedStyle(document.querySelector('.card'));
console.log('Card margin-left:', styles.marginLeft);
console.log('Card width:', styles.width);
```

**D) Force reload utan cache:**

- Safari iOS: Håll in reload-knappen → "Reload Without Content Blockers"
- Chrome iOS: Settings → Privacy → Clear Browsing Data → Cached Images

### STEG 8: NUKLEÄR OPTION

Om INGET annat fungerar, lägg till detta i `<head>` på problem-sidorna:

```php
<!-- FORCE MOBILE EDGE-TO-EDGE -->
<style>
@media (max-width: 767px) {
  .card,
  .gs-result-card,
  .event-card-horizontal {
    margin-left: -16px !important;
    margin-right: -16px !important;
    border-radius: 0 !important;
    border-left: none !important;
    border-right: none !important;
    width: calc(100% + 32px) !important;
    max-width: none !important;
  }
  
  .event-card-horizontal,
  .gs-result-card {
    padding: 16px !important;
  }
  
  body, main, .container {
    padding-left: 16px !important;
    padding-right: 16px !important;
  }
}
</style>
```

Detta går rakt i HTML och kan INTE cachas.

## 🎯 FRAMGÅNGSKRITERIER

- [ ] CSS-ändringar syns (ingen cache)
- [ ] Event cards är 100% bredd på mobil
- [ ] Result cards är 100% bredd på mobil  
- [ ] Filter-kort är 100% bredd på mobil
- [ ] Inga horisontella scrollbars
- [ ] Innehåll i kort har fortfarande padding (läsbart)

## 📊 OUTPUT FÖRVÄNTNINGAR

Efter du kört dessa fixes, ge mig:

1. **Lista av filer du ändrade** med rad-nummer
2. **Innan/Efter CSS-kod** för varje ändring
3. **Cache-busting metod** du valde
4. **Test-resultat** - fungerade det?
5. **Om det inte fungerade** - vad såg du i DevTools?

## 🚨 VIKTIGA NOTES

- Använd `!important` på mobil CSS - det är OK här eftersom det är edge case
- Testa på RIKTIGA mobiler, inte bara DevTools emulator
- Kolla att horisontell scroll inte uppstår
- Verifiera att desktop-layout inte påverkas
- Committa inte förrän du testat på iPhone

## 💡 DEBUG TIPS

Om kort fortfarande har margins:

1. **Kolla parent width:** Är `.container` eller `main` 100% bred?
2. **Kolla box-sizing:** Ska vara `border-box` i reset.css
3. **Kolla om annan CSS överstyrer:** Sök efter `.card` i alla CSS-filer
4. **Kolla inline styles:** `style="..."` attribut kan overrida allt
5. **Kolla JavaScript:** Någon JS kan sätta styles dynamiskt

## 🔍 FELSÖKNING

```bash
# Hitta alla .card definitioner
grep -r "\.card" assets/css/*.css

# Hitta inline styles
grep -r "style=" v2/*.php | grep -i card

# Kolla om JavaScript sätter styles
grep -r "\.style\." v2/*.php
grep -r "setAttribute.*style" v2/*.php

# Verifiera container padding
grep -r "container.*padding" assets/css/*.css
```

---

## BÖRJA HÄR

Kör detta först:

```bash
# Se nuvarande struktur
cat assets/css/components.css | grep -A 30 "max-width:767px"

# Se om cache-busting finns
grep "filemtime\|time()" components/head.php

# Lista inline styles
grep -n "<style>" v2/events.php v2/results.php
```

Sedan fortsätt med STEG 1-8 ovan!

---

**LYCKA TILL!** 🚀

Kom ihåg: Problemet är 99% troligt att det är cache eller inline styles som overstyrer.
