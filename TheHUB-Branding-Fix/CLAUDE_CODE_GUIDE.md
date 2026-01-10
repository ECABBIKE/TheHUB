# Implementation Guide för Claude Code - TheHUB Branding System

## Quick Reference: Ändra färger i TheHUB

### För Claude Code - GÖR SÅ HÄR:

**1. LÄS färgerna först:**
```bash
cat uploads/branding.json
```

**2. ÄNDRA färgerna:**
```bash
# Redigera uploads/branding.json direkt
# Eller använd jq för programmatisk ändring
```

**3. Exempel: Byt till blått tema**
```bash
cat > uploads/branding.json << 'EOF'
{
  "colors": {
    "light": {
      "--color-bg-page": "#eff6ff",
      "--color-bg-surface": "#dbeafe",
      "--color-bg-card": "#ffffff",
      "--color-accent": "#3b82f6",
      "--color-border": "rgba(59, 130, 246, 0.2)"
    },
    "dark": {
      "--color-bg-page": "#0c1e3e",
      "--color-bg-surface": "#112446",
      "--color-bg-card": "#1a3257",
      "--color-accent": "#60a5fa",
      "--color-border": "rgba(96, 165, 250, 0.3)"
    }
  },
  "responsive": {
    "mobile_portrait": {"padding": "12", "radius": "0"},
    "mobile_landscape": {"sidebar_gap": "4"},
    "tablet": {"padding": "24", "radius": "8"},
    "desktop": {"padding": "32", "radius": "12"}
  },
  "layout": {
    "content_max_width": "1400",
    "sidebar_width": "72",
    "header_height": "60"
  },
  "logos": {
    "sidebar": "",
    "homepage": "",
    "favicon": "/assets/favicon.svg"
  }
}
EOF
```

**4. Verifiera:**
```bash
# Kontrollera att filen är valid JSON
cat uploads/branding.json | jq .
```

**5. Ladda om webbläsaren** - färgerna appliceras automatiskt!

---

## ⚠️ VIKTIGT för Claude Code:

### ✅ GÖR:
- Ändra **BARA** `uploads/branding.json`
- Läs filen först för att se struktur
- Behåll samma JSON-struktur
- Använd valid CSS färger (#hex eller rgba())

### ❌ REDIGERA INTE:
- ❌ `assets/css/theme.css` (default-värden)
- ❌ `includes/layout-header.php` (laddar branding)
- ❌ `includes/helpers.php` (genererar CSS)

---

## Färgmallar för olika teman

### 1. Cyan (Standard GravitySeries)
```json
"light": {
  "--color-bg-page": "#f8f9fa",
  "--color-bg-surface": "#ffffff",
  "--color-accent": "#2bc4c6"
}
```

### 2. Röd (Test - redan aktiv)
```json
"light": {
  "--color-bg-page": "#ffe0e0",
  "--color-bg-surface": "#fff5f5",
  "--color-accent": "#e63946"
}
```

### 3. Grön (Miljövänlig)
```json
"light": {
  "--color-bg-page": "#f0fdf4",
  "--color-bg-surface": "#dcfce7",
  "--color-accent": "#22c55e"
}
```

### 4. Blå (Professionell)
```json
"light": {
  "--color-bg-page": "#eff6ff",
  "--color-bg-surface": "#dbeafe",
  "--color-accent": "#3b82f6"
}
```

### 5. Lila (Kreativ)
```json
"light": {
  "--color-bg-page": "#faf5ff",
  "--color-bg-surface": "#f3e8ff",
  "--color-accent": "#a855f7"
}
```

### 6. Grå (Minimal)
```json
"light": {
  "--color-bg-page": "#f8f9fa",
  "--color-bg-surface": "#ffffff",
  "--color-accent": "#64748b"
}
```

---

## Debug: Om färger inte ändras

**1. Kontrollera att JSON är valid:**
```bash
cat uploads/branding.json | jq .
```

**2. Kontrollera att colors-objektet finns:**
```bash
cat uploads/branding.json | jq '.colors'
```

**3. Kontrollera att light/dark keys finns:**
```bash
cat uploads/branding.json | jq '.colors.light'
cat uploads/branding.json | jq '.colors.dark'
```

**4. Kontrollera att CSS-variabler börjar med --:**
```bash
cat uploads/branding.json | jq '.colors.light | keys[]'
# Ska ge: --color-bg-page, --color-bg-surface, etc
```

**5. Hard refresh i webbläsaren:**
- Chrome/Firefox: Ctrl+Shift+R (Windows) eller Cmd+Shift+R (Mac)
- Safari: Cmd+Option+R

---

## Teknisk förklaring (för förståelse)

### Hur systemet fungerar:

1. **PHP läser JSON:**
   ```php
   function generateBrandingCSS() {
       $branding = getBranding();
       if (empty($branding['colors'])) return '';
       // Genererar inline <style> tag
   }
   ```

2. **Genererar inline CSS:**
   ```html
   <style id="branding-overrides">
   html[data-theme="light"] {
     --color-bg-page: #ffe0e0;
     --color-bg-surface: #fff5f5;
   }
   </style>
   ```

3. **Overridar theme.css:**
   - Inline styles har högre specificitet
   - CSS-variabler uppdateras
   - Alla komponenter använder nya färger

### CSS Cascade:
```
theme.css (defaults)
    ↓ overridas av
branding-overrides (inline style från JSON)
    ↓ används av
Alla komponenter (var(--color-bg-page))
```

---

## Exempel på fullständig färguppsättning

För ett komplett tema, inkludera alla dessa i både light och dark:

```json
{
  "colors": {
    "light": {
      "--color-bg-page": "#f8f9fa",
      "--color-bg-surface": "#ffffff",
      "--color-bg-card": "#ffffff",
      "--color-bg-sunken": "#f0f2f5",
      
      "--color-text-primary": "#0b131e",
      "--color-text-secondary": "#495057",
      "--color-text-tertiary": "#6c757d",
      "--color-text-muted": "#868e96",
      
      "--color-accent": "#2bc4c6",
      "--color-accent-hover": "#37d4d6",
      "--color-accent-light": "rgba(43, 196, 198, 0.1)",
      
      "--color-border": "rgba(55, 212, 214, 0.15)",
      "--color-border-strong": "rgba(55, 212, 214, 0.25)"
    },
    "dark": {
      "--color-bg-page": "#0b131e",
      "--color-bg-surface": "#0d1520",
      "--color-bg-card": "#0e1621",
      "--color-bg-sunken": "#06080e",
      
      "--color-text-primary": "#f8f2f0",
      "--color-text-secondary": "#c7cfdd",
      "--color-text-tertiary": "#9ca3af",
      "--color-text-muted": "#868fa2",
      
      "--color-accent": "#37d4d6",
      "--color-accent-hover": "#4ae0e2",
      "--color-accent-light": "rgba(55, 212, 214, 0.15)",
      
      "--color-border": "rgba(55, 212, 214, 0.2)",
      "--color-border-strong": "rgba(55, 212, 214, 0.3)"
    }
  }
}
```

---

## Snabb-test kommandon

```bash
# Test 1: Röd bakgrund (nuvarande)
echo "Redan aktiv - ladda om sidan"

# Test 2: Blå bakgrund
cat > uploads/branding.json << 'EOF'
{"colors":{"light":{"--color-bg-page":"#eff6ff","--color-bg-surface":"#dbeafe"}}}
EOF

# Test 3: Återställ till standard (ingen custom branding)
cat > uploads/branding.json << 'EOF'
{"colors":{}}
EOF

# Test 4: Full cyan GravitySeries tema
cat > uploads/branding.json << 'EOF'
{"colors":{"light":{"--color-bg-page":"#f8f9fa","--color-bg-surface":"#ffffff","--color-accent":"#2bc4c6"}}}
EOF
```

---

## Slutsats för Claude Code

**Den ENDA filen du behöver ändra är:**
```
uploads/branding.json
```

**Strukturen är:**
```json
{
  "colors": {
    "light": { /* CSS-variabler för ljust tema */ },
    "dark": { /* CSS-variabler för mörkt tema */ }
  }
}
```

**Så enkelt är det!** 🎨✨
