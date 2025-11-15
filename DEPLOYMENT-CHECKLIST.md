# Deployment Checklist - Class System Implementation

**Datum:** 2025-11-15
**Feature:** Comprehensive Class/Category System

## Filer som ska laddas upp till InfinityFree

### ✅ Nya filer (skapa dessa på servern)

1. **`/admin/system-settings.php`** (48 KB)
   - Huvudfil för systeminställningar
   - Innehåller: Poängmallar, Klasser, Migrationer
   - Ladda upp till: `htdocs/admin/system-settings.php`

2. **`/includes/class-calculations.php`** (11 KB)
   - Klassberäkningslogik
   - Automatisk klasstilldelning baserat på ålder/kön
   - Ladda upp till: `htdocs/includes/class-calculations.php`

3. **`/database/migrations/008_classes_system.sql`** (4 KB)
   - Databasmigrering för klasssystem
   - Skapar classes-tabell och tillhörande kolumner
   - Ladda upp till: `htdocs/database/migrations/008_classes_system.sql`

### 🔄 Filer som ska ersättas (uppdatera befintliga)

4. **`/includes/navigation.php`** (3 KB)
   - Uppdaterad med "Systeminställningar" i menyn
   - Ersätt: `htdocs/includes/navigation.php`

5. **`/admin/import-results-preview.php`** (15 KB)
   - Uppdaterad med klassfördelningsförhandsvisning
   - Ersätt: `htdocs/admin/import-results-preview.php`

## Steg-för-steg deployment

### Steg 1: Ladda upp via FTP (FileZilla/cPanel File Manager)

**Anslut till InfinityFree FTP:**
- Host: `ftpupload.net` (eller din FTP-server)
- Användare: ditt InfinityFree FTP-användarnamn
- Lösenord: ditt FTP-lösenord

**Ladda upp i denna ordning:**

```
1. htdocs/includes/class-calculations.php          [NY FIL]
2. htdocs/includes/navigation.php                  [ERSÄTT]
3. htdocs/admin/import-results-preview.php         [ERSÄTT]
4. htdocs/admin/system-settings.php                [NY FIL]
5. htdocs/database/migrations/008_classes_system.sql [NY FIL]
```

### Steg 2: Kör databasmigrering

Efter uppladdning:

1. Gå till: `https://din-domän.com/admin/system-settings.php?tab=migrations`
2. Hitta migration: **008_classes_system.sql**
3. Klicka "Kör migration"
4. Verifiera att migreringen lyckades

### Steg 3: Verifiera installation

Kontrollera att allt fungerar:

- [ ] **Navigation:** "Systeminställningar" syns i admin-menyn
- [ ] **Klasser:** Gå till Systeminställningar → Klasser-fliken
- [ ] **Förinstallerade klasser:** 15 Road + 8 MTB klasser ska finnas
- [ ] **Redigering:** Testa att redigera en klass
- [ ] **Import:** Testa importförhandsvisning med klassfördelning

## Snabbkommando (om du har SSH/terminal-åtkomst)

```bash
# Om du har git-åtkomst på servern
cd /path/to/htdocs
git pull origin claude/add-advent-id-fix-csrf-019285vqFgHsjJuxXydM22fN

# Kör sedan migrering via web-UI
```

## Felsökning

### Problem: 404 Not Found
**Lösning:** Filen är inte uppladdad än. Ladda upp via FTP.

### Problem: "Class not found" fel
**Lösning:** `class-calculations.php` saknas. Ladda upp till `/includes/`

### Problem: Migration 008 visar fel
**Lösning:**
1. Kontrollera att SQL-filen är korrekt uppladdad
2. Kör migration 007 först (om du vill ha poängmallar för serier)
3. Migration 008 fungerar dock oavsett om 007 körs först

### Problem: Menyn visar inte "Systeminställningar"
**Lösning:** `navigation.php` inte uppdaterad. Ersätt filen.

## Backup innan deployment

**VIKTIGT:** Ta backup av databasen innan du kör migration 008!

Via **phpMyAdmin**:
1. Logga in på cPanel → phpMyAdmin
2. Välj din databas
3. Klicka "Export" → "Go"
4. Spara SQL-filen lokalt

## Förväntade ändringar i databasen

Migration 008 kommer att:
- Skapa tabell: `classes`
- Lägga till kolumner i `results`: `class_id`, `class_position`, `class_points`
- Lägga till kolumner i `events`: `enable_classes`
- Lägga till kolumner i `series`: `enable_classes`
- Infoga 23 förinstallerade klasser (15 Road + 8 MTB)

## Support

Om något går fel:
1. Kolla felloggar i cPanel
2. Verifiera filrättigheter (755 för mappar, 644 för filer)
3. Kontrollera att PHP-versionen är 7.4+ (rekommenderat 8.0+)

---

**Status:** Alla filer committade till branch `claude/add-advent-id-fix-csrf-019285vqFgHsjJuxXydM22fN`
**Nästa steg:** Ladda upp filerna enligt checklistan ovan
