# 🚀 Quick Deploy to InfinityFree

## Enkel deployment från git (enbart för uppdateringar)

När du har gjort setup en gång kan du uppdatera siten genom att köra i SSH eller File Manager:

```bash
cd /htdocs
git pull origin claude/thehub-comprehensive-audit-01Sf5tTNHBQtMzEgsXZUmLP9
```

---

## 📦 Initial Setup (gör EN gång)

### Steg 1: Pusha från din dator

InfinityFree File Manager eller FTP:
```bash
cd htdocs
git pull origin claude/thehub-comprehensive-audit-01Sf5tTNHBQtMzEgsXZUmLP9
```

### Steg 2: Kör setup-scriptet

**ENKLASTE SÄTTET (från mobil):**

Besök denna URL i din webbläsare:
```
https://thehub.infinityfree.me/setup-production.php?password=qv19oAyv44J2xX
```

Detta skapar `.env` filen automatiskt med rätt inställningar!

**ALTERNATIV (via File Manager):**
1. Öppna `setup-production.php` i File Manager
2. Lägg till lösenordet på rad 17: `$db_password = 'qv19oAyv44J2xX';`
3. Besök: `https://thehub.infinityfree.me/setup-production.php`

### Steg 3: RADERA setup-production.php

**VIKTIGT FÖR SÄKERHET!**

Gå till File Manager och radera `setup-production.php` direkt efter att den körts!

### Steg 4: Kör SQL migrations

Gå till phpMyAdmin (InfinityFree cPanel → phpMyAdmin)

**Kör dessa två filer:**

1. Kopiera innehållet från `database/migrations/003_import_history.sql`
2. Klistra in i phpMyAdmin SQL tab → Kör
3. Kopiera innehållet från `database/migrations/004_point_scales.sql`
4. Klistra in i phpMyAdmin SQL tab → Kör

### Steg 5: Verifiera

Besök: `https://thehub.infinityfree.me/admin/test-database-connection.php`

Du ska se:
- ✅ Config files exist
- ✅ Database constants defined
- ✅ NOT in demo mode
- ✅ Connection successful
- ✅ All tables exist
- ✅ 2598 riders in database

---

## 🔄 Framtida uppdateringar

Efter initial setup behöver du bara:

```bash
cd /htdocs
git pull
```

Inga fler setup-script behövs! `.env` ligger kvar på servern.

---

## 📋 Filer som skapas på servern (gitignored)

Dessa filer finns BARA på servern, inte i git:

- `.env` - Databas credentials
- `uploads/*` - Uppladdade filer
- `*.log` - Log-filer

---

## ❓ Troubleshooting

**Problem:** "Connection failed"
- Kolla att `.env` finns i `/htdocs/`
- Verifiera DB credentials i `.env`

**Problem:** "Import tables missing"
- Kör migrations i phpMyAdmin (steg 4 ovan)

**Problem:** "Demo mode active"
- Kör setup-scriptet igen
- Kolla att `config/database.php` finns

---

## 🔐 Säkerhet

- ✅ `.env` är gitignored (credentials ej i git)
- ✅ `setup-production.php` ska raderas efter användning
- ✅ `config/database.php` innehåller INTE credentials (safe att commita)
