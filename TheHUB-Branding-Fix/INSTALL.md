# INSTALLATION - TheHUB Branding Fix

## 📦 Innehåll i detta paket

```
TheHUB-Branding-Fix/
├── README.md                              # Huvuddokumentation
├── BRANDING_SYSTEM_DOCUMENTATION.md       # Teknisk dokumentation
├── CLAUDE_CODE_GUIDE.md                   # Guide för Claude Code
├── INSTALL.md                             # Denna fil
├── switch-theme.sh                        # Tema-switcher script
├── includes/
│   ├── helpers.php                        # MODIFIERAD
│   └── layout-header.php                  # MODIFIERAD
└── uploads/
    └── branding.json                      # TEST-FÄRGER (röd)
```

---

## 🚀 Installation (3 minuter)

### Steg 1: Backup (VIKTIGT!)
```bash
# På servern, gör backup av original-filer
cd /path/to/TheHUB
cp includes/helpers.php includes/helpers.php.backup.$(date +%Y%m%d)
cp includes/layout-header.php includes/layout-header.php.backup.$(date +%Y%m%d)
cp uploads/branding.json uploads/branding.json.backup.$(date +%Y%m%d)
```

### Steg 2: Ladda upp filer
**Alternativ A: Via FTP/SFTP**
1. Öppna din FTP-klient (FileZilla, Cyberduck, etc)
2. Navigera till TheHUB root-mappen
3. Ladda upp filerna (ersätt befintliga):
   - `includes/helpers.php`
   - `includes/layout-header.php`
   - `uploads/branding.json`
   - `switch-theme.sh` (valfritt, för tema-byte)

**Alternativ B: Via SSH**
```bash
# Ladda upp ZIP till servern först
cd /path/to/TheHUB
unzip TheHUB-Branding-Fix.zip

# Kopiera filer (ersätt befintliga)
cp TheHUB-Branding-Fix/includes/helpers.php includes/
cp TheHUB-Branding-Fix/includes/layout-header.php includes/
cp TheHUB-Branding-Fix/uploads/branding.json uploads/
cp TheHUB-Branding-Fix/switch-theme.sh .
chmod +x switch-theme.sh
```

### Steg 3: Testa
1. **Öppna webbplatsen i webbläsare**
2. **Bakgrunden ska vara ljusröd/rosa** (#ffe0e0)
3. **Om du ser röd bakgrund = SUCCESS!** ✅

### Steg 4: Återställ till standard (valfritt)
```bash
# Via script
./switch-theme.sh reset

# Via admin
# Gå till https://din-site.se/admin/branding.php
# Klicka "Återställ till standard"
```

---

## ✅ Verifiering

### Visual check:
- [ ] Bakgrund är röd/rosa (test-färg)
- [ ] Sidebar matchar bakgrundsfärgen
- [ ] Header matchar bakgrundsfärgen
- [ ] Inga konsol-fel i DevTools

### Teknisk check:
```bash
# Kolla att generateBrandingCSS finns i helpers.php
grep -n "function generateBrandingCSS" includes/helpers.php

# Kolla att branding CSS laddas i layout-header.php
grep -n "generateBrandingCSS()" includes/layout-header.php

# Kolla att JSON är valid
cat uploads/branding.json | jq .
```

### DevTools check:
1. Öppna DevTools (F12)
2. Gå till Elements tab
3. Kolla `<html>` element
4. Du ska se: `<style id="branding-overrides">` med färger från JSON

---

## 🔄 Återställning (om något går fel)

### Återställ från backup:
```bash
cd /path/to/TheHUB
cp includes/helpers.php.backup.YYYYMMDD includes/helpers.php
cp includes/layout-header.php.backup.YYYYMMDD includes/layout-header.php
cp uploads/branding.json.backup.YYYYMMDD uploads/branding.json
```

---

## 📝 Vad har ändrats?

### includes/helpers.php
**Tillagt:**
- `generateBrandingCSS()` funktion (43 nya rader)
- Läser branding.json och genererar inline CSS

**Position:** Efter `getBranding()` funktion (ca rad 83)

### includes/layout-header.php
**Ändringar:**
1. Rad ~349: Tillagt `<?= generateBrandingCSS() ?>` efter theme.css
2. Rad ~133: Ändrat `background: #ebeced` → `background: var(--color-bg-page)`
3. Rad ~178: Ändrat `background: #FFFFFF` → `background: var(--color-bg-surface)`
4. Rad ~213: Ändrat `background: var(--color-bg-surface, #fff)` → `background: var(--color-bg-surface)`
5. Och fler liknande ändringar (alla hårdkodade färger → CSS-variabler)

### uploads/branding.json
**Tillagt:**
- Test-färger (röda) för att demonstrera att systemet fungerar
- Kan återställas till tom via `./switch-theme.sh reset`

---

## 🎨 Nästa steg efter installation

### 1. Återställ till ditt tema:
```bash
# Via script
./switch-theme.sh standard  # Cyan GravitySeries tema

# Eller via admin
# Gå till /admin/branding.php och välj färger
```

### 2. Aktivera mörkt tema (valfritt):
```php
// I includes/layout-header.php, ändra:
// Rad 111: $userTheme = 'light'; → $userTheme = 'dark';
// Rad 114: data-theme="light" → data-theme="dark"
```

### 3. Testa olika teman:
```bash
./switch-theme.sh blue    # Blått tema
./switch-theme.sh green   # Grönt tema
./switch-theme.sh gray    # Grått tema
```

---

## 🐛 Vanliga problem

### Problem: Ser inte röd bakgrund
**Lösning:**
1. Hard refresh: Ctrl+Shift+R (Windows) eller Cmd+Shift+R (Mac)
2. Kolla att filer laddades upp korrekt
3. Kolla PHP error log för fel

### Problem: Sidan ser trasig ut
**Lösning:**
1. Återställ från backup
2. Kolla att alla filer laddades upp
3. Kolla att JSON är valid: `cat uploads/branding.json | jq .`

### Problem: JSON-fel
**Lösning:**
```bash
# Återskapa med valid JSON
cat > uploads/branding.json << 'EOF'
{
  "colors": {
    "light": {
      "--color-bg-page": "#ffe0e0",
      "--color-bg-surface": "#fff5f5"
    }
  }
}
EOF
```

---

## 📞 Support

Om problem kvarstår:
1. Kolla PHP error log
2. Kolla webbläsarens DevTools console
3. Verifiera filrättigheter (ska vara readable av webserver)
4. Kontrollera att uploads/branding.json är writable (för admin-interface)

---

## ✨ Gratulerar!

Om du ser röd bakgrund så är installationen lyckad! 🎉

Nu kan du:
- ✅ Ändra färger via `/admin/branding.php`
- ✅ Låta Claude Code ändra färger via JSON
- ✅ Använda `./switch-theme.sh` för snabba tema-byten

**Återställ till ditt tema när du är klar med testet!**

```bash
./switch-theme.sh standard
# eller
./switch-theme.sh reset
```

---

**Happy theming!** 🎨✨
