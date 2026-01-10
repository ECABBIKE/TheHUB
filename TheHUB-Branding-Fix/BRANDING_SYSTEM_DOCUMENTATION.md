# TheHUB Branding System - Lösning 2025-01-10

## Problem (innan fix)
Branding-systemet fungerade inte eftersom:
1. ❌ `branding.php` sparade färger till `uploads/branding.json` 
2. ❌ Men dessa färger applicerades **ALDRIG** på sidan
3. ❌ `layout-header.php` hade hårdkodad inline CSS (#ebeced, #FFFFFF)
4. ❌ `theme.css` hade hårdkodade värden som aldrig uppdaterades från branding.json

**Det saknades en "brygga" mellan branding.json och CSS-variablerna**

## Lösning
Jag har skapat ett komplett fungerande system i 3 steg:

### 1. Ny funktion i `includes/helpers.php`
```php
/**
 * Generate inline CSS from branding.json that overrides theme.css defaults
 * This is the BRIDGE between branding.json and the visual theme
 */
function generateBrandingCSS() {
    $branding = getBranding();
    
    // If no custom branding, return empty string (use theme.css defaults)
    if (empty($branding['colors'])) {
        return '';
    }

    $css = '<style id="branding-overrides">';
    
    // Generate CSS for dark theme
    if (!empty($branding['colors']['dark'])) {
        $css .= "\n:root, html[data-theme=\"dark\"] {\n";
        foreach ($branding['colors']['dark'] as $varName => $value) {
            $css .= "  {$varName}: {$value};\n";
        }
        $css .= "}\n";
    }
    
    // Generate CSS for light theme
    if (!empty($branding['colors']['light'])) {
        $css .= "\nhtml[data-theme=\"light\"] {\n";
        foreach ($branding['colors']['light'] as $varName => $value) {
            $css .= "  {$varName}: {$value};\n";
        }
        $css .= "}\n";
    }
    
    $css .= '</style>';
    return $css;
}
```

### 2. Uppdatering i `includes/layout-header.php`
- ✅ Lagt till `<?= generateBrandingCSS() ?>` direkt efter theme.css
- ✅ Tagit bort alla hårdkodade färger (#ebeced, #FFFFFF, etc)
- ✅ Ändrat inline CSS till att använda `var(--color-bg-surface)` istället

### 3. Struktur för `uploads/branding.json`
```json
{
  "colors": {
    "dark": {
      "--color-bg-page": "#1a0000",
      "--color-bg-surface": "#2d0000",
      "--color-bg-card": "#3d0000",
      "--color-accent": "#ff6b6b",
      "--color-border": "rgba(255, 107, 107, 0.3)"
    },
    "light": {
      "--color-bg-page": "#ffe0e0",
      "--color-bg-surface": "#fff5f5",
      "--color-bg-card": "#ffffff",
      "--color-accent": "#e63946",
      "--color-border": "rgba(230, 57, 70, 0.2)"
    }
  }
}
```

## Hur det fungerar

### CSS Cascade Order (viktig!)
1. `theme.css` laddar med **default-färger** (cyan dark theme)
2. `generateBrandingCSS()` genererar **inline `<style>` tag** med branding.json färger
3. Inline styles har **högre specificitet** än external stylesheets
4. Därför overridar branding.json-färgerna theme.css defaults! ✨

### Flödet
```
branding.php (admin UI)
    ↓ sparar
uploads/branding.json
    ↓ läses av
includes/helpers.php → generateBrandingCSS()
    ↓ genererar
<style id="branding-overrides">
  html[data-theme="light"] {
    --color-bg-page: #ffe0e0;
    --color-bg-surface: #fff5f5;
  }
</style>
    ↓ overridar
theme.css defaults
    ↓ används av
Alla komponenter via var(--color-bg-page)
```

## Testa systemet

### Test 1: Röd bakgrund (redan aktiverad)
Den nuvarande `branding.json` har röda färger för test.
Ladda om sidan → bakgrunden ska bli ljusröd (#ffe0e0)

### Test 2: Blå bakgrund
Ändra i `branding.php` admin eller manuellt i `branding.json`:
```json
"light": {
  "--color-bg-page": "#e0f2ff",
  "--color-bg-surface": "#f0f9ff"
}
```

### Test 3: Återställ till standard
1. Gå till `/admin/branding.php`
2. Klicka "Återställ till standard"
3. Eller ta bort "colors" helt från branding.json:
```json
{
  "colors": {}
}
```

## Alla CSS-variabler som kan ändras

### Bakgrunder
- `--color-bg-page` - Sidbakgrund
- `--color-bg-surface` - Ytor (kort, modals)
- `--color-bg-card` - Kort
- `--color-bg-sunken` - Nedsänkta ytor

### Text
- `--color-text-primary` - Primär text
- `--color-text-secondary` - Sekundär text
- `--color-text-tertiary` - Tertiär text
- `--color-text-muted` - Dämpad text

### Accent & Knappar
- `--color-accent` - Accentfärg
- `--color-accent-hover` - Accent hover
- `--color-accent-light` - Accent ljus

### Status
- `--color-success` - Framgång
- `--color-warning` - Varning
- `--color-error` - Fel
- `--color-info` - Info

### Kanter
- `--color-border` - Kant
- `--color-border-strong` - Stark kant

## Tips för Claude Code

När du ändrar färger via Code, gör så här:

1. **LÄS** först `uploads/branding.json`
2. **ÄNDRA** färgvärdena i JSON-filen
3. **SPARA** filen
4. Ladda om sidan - färgerna appliceras automatiskt!

**Ändra INTE:**
- ❌ `theme.css` (default-värden)
- ❌ `layout-header.php` inline CSS (använder variabler)

**Ändra BARA:**
- ✅ `uploads/branding.json` (custom färger)

## Exempel: Byta till grönt tema

```json
{
  "colors": {
    "light": {
      "--color-bg-page": "#f0fdf4",
      "--color-bg-surface": "#f7fee7",
      "--color-accent": "#22c55e",
      "--color-border": "rgba(34, 197, 94, 0.2)"
    },
    "dark": {
      "--color-bg-page": "#0a2e1a",
      "--color-bg-surface": "#0d3d20",
      "--color-accent": "#4ade80",
      "--color-border": "rgba(74, 222, 128, 0.3)"
    }
  }
}
```

Spara → Ladda om → Grönt tema! 🎉
