# 🚀 Deployment-instruktioner för TheHUB till InfinityFree

## ⚠️ Viktigt: FTP från Claude Code fungerar inte

På grund av nätverksbegränsningar i Claude Code-miljön kan vi inte deploya direkt via FTP.
Istället har vi skapat en deployment-paket som du kan ladda upp manuellt.

---

## 📦 Metod 1: Manuell FTP-upload (SNABBAST) ⭐

### Steg 1: Ladda ner FTP-klient
- **FileZilla**: https://filezilla-project.org/download.php
- **Cyberduck**: https://cyberduck.io/download/

### Steg 2: Anslut till InfinityFree FTP
```
Host: ftpupload.net
Port: 21
Username: if0_40400950
Password: qv19oAyv44J2xX
Protocol: FTP (inte SFTP)
```

### Steg 3: Navigera till /htdocs/
- I remote-panelen, gå till mappen `/htdocs/`
- Radera alla befintliga filer i `/htdocs/` om det finns några

### Steg 4: Ladda upp alla filer
- I local-panelen, navigera till TheHUB-projektmappen:
  ```
  /home/user/TheHUB/
  ```
- Markera ALLA filer och mappar (utom `.git`)
- Dra och släpp till `/htdocs/` i remote-panelen
- Vänta tills alla filer är uppladdade (kan ta 5-10 minuter)

### Steg 5: Verifiera .env-filen
- Kontrollera att `.env` finns i `/htdocs/`
- Om inte, ladda upp den manuellt

---

## 📦 Metod 2: File Manager Upload (via webbläsare)

### Steg 1: Logga in på InfinityFree Control Panel
```
https://www.infinityfree.com/
```

### Steg 2: Öppna File Manager
- Gå till "Online File Manager"
- Eller: "Control Panel" → "File Manager"

### Steg 3: Ladda upp ZIP-filen
1. Navigera till `/htdocs/`
2. Radera alla befintliga filer
3. Klicka "Upload"
4. Välj filen: `/home/user/TheHUB/thehub-deployment.zip` (85 KB)
5. Vänta tills uppladdningen är klar

### Steg 4: Packa upp ZIP-filen
1. Högerklicka på `thehub-deployment.zip`
2. Välj "Extract" eller "Unzip"
3. Extrahera till `/htdocs/`
4. Radera ZIP-filen efter uppackning

### Steg 5: Verifiera struktur
Din `/htdocs/` mapp ska nu innehålla:
```
/htdocs/
├── .env
├── .env.example
├── config.php
├── composer.json
├── admin/
├── assets/
├── config/
├── database/
├── imports/
├── includes/
├── public/
├── templates/
└── uploads/
```

---

## 📦 Metod 3: GitHub Actions (Automatisk) 🤖

### Setup (Engångsåtgärd)

1. **Sätt GitHub Secrets:**
   ```
   Repository → Settings → Secrets and variables → Actions
   ```

   Lägg till:
   | Secret Name | Value |
   |-------------|-------|
   | `FTP_USERNAME` | `if0_40400950` |
   | `FTP_PASSWORD` | `qv19oAyv44J2xX` |
   | `ADMIN_PASSWORD` | `qv19oAyv44J2xX` |
   | `DB_PASSWORD` | `qv19oAyv44J2xX` |

2. **Vänta på push till main:**
   - GitHub Actions kommer automatiskt deploya vid nästa push till `main`
   - Övervaka: GitHub → Actions → "Deploy to InfinityFree"

3. **Eller kör manuellt:**
   - GitHub → Actions → "Deploy to InfinityFree"
   - "Run workflow" → Välj branch `main` → "Run workflow"

---

## 🗄️ Databas-setup (VIKTIGT!)

Efter att filerna är uppladdade måste du skapa databasen.

### ⭐ Metod 1: Web-baserad setup (REKOMMENDERAS)

Detta är det enklaste sättet - allt sker via webbläsaren!

#### Steg 1: Kontrollera .env-filen
Säkerställ att `/htdocs/.env` innehåller rätt databas-uppgifter:
```bash
DB_HOST=sql100.infinityfree.com
DB_NAME=if0_40400950_THEHUB
DB_USER=if0_40400950
DB_PASS=qv19oAyv44J2xX
```

#### Steg 2: Kör databas-setup via webbläsare
1. Gå till: `https://thehub.infinityfreeapp.com/admin/login.php`
2. Logga in med:
   - Användarnamn: `admin`
   - Lösenord: `changeme_immediately!` (standard från schema)
3. Gå till: `https://thehub.infinityfreeapp.com/admin/setup-database.php`
4. Klicka på knappen "Run Database Setup"
5. Vänta tills meddelandet "Database schema setup complete!" visas

✅ **Klart!** Alla tabeller är nu skapade och databasen är redo att användas.

#### Steg 3: Byt admin-lösenord (VIKTIGT!)
Efter första inloggningen, uppdatera admin-lösenordet i databasen.

---

### Metod 2: Manuell setup via phpMyAdmin (Backup-metod)

Om den web-baserade setupen inte fungerar:

#### Steg 1: Logga in på phpMyAdmin
Via InfinityFree Control Panel:
```
Tools → MySQL Databases → phpMyAdmin
```

#### Steg 2: Välj databas
```sql
USE if0_40400950_THEHUB;
```

#### Steg 3: Importera schema
1. Klicka på "Import" i phpMyAdmin
2. Välj filen `/htdocs/database/schema.sql`
3. Klicka "Go"

Eller kopiera hela innehållet från `schema.sql` och kör det i SQL-fältet.

---

### Metod 3: Via SSH/Terminal

```bash
mysql -h sql100.infinityfree.com -u if0_40400950 -p if0_40400950_THEHUB < /htdocs/database/schema.sql
# Password: qv19oAyv44J2xX
```

---

## ✅ Verifiering

### 1. Testa hemsidan:
```
https://thehub.infinityfreeapp.com/
eller
https://thehub.infinityfreeapp.com/public/
```

### 2. Testa admin-panelen:
```
https://thehub.infinityfreeapp.com/admin/login.php
```

**Inloggning:**
- Användarnamn: `admin`
- Lösenord: `qv19oAyv44J2xX`

### 3. Kontrollera databas-anslutning:
- Om dashboard visar statistik → Databas fungerar! ✅
- Om fel-meddelande → Kontrollera .env och databas-setup

---

## 🔧 Felsökning

### Problem: 500 Internal Server Error
**Lösning:** Kontrollera fil-rättigheter
```
Alla .php filer: 644
Alla mappar: 755
```

### Problem: Databas-anslutning misslyckades
**Lösning:** Verifiera .env-filen
```bash
# Kontrollera att dessa värden stämmer i /htdocs/.env:
DB_HOST=sql100.infinityfree.com
DB_NAME=if0_40400950_THEHUB
DB_USER=if0_40400950
DB_PASS=qv19oAyv44J2xX
```

**Testa anslutningen:**
1. Gå till `https://thehub.infinityfreeapp.com/admin/setup-database.php`
2. Kontrollera "Database Status" sektionen
3. Om "✅ Connected" visas → Anslutningen fungerar!
4. Om "❌ Database not connected" visas → Kontrollera .env-värdena

### Problem: Sidan är tom
**Lösning:** Kontrollera index-filen
```
InfinityFree letar efter: index.php, index.html
Antingen:
- Besök /public/index.php direkt
- Eller flytta /public/* till root /htdocs/
```

### Problem: Kan inte logga in på admin
**Lösning:** Kontrollera .env ADMIN_PASSWORD
```bash
# I .env:
ADMIN_PASSWORD=qv19oAyv44J2xX
```

---

## 📞 Support

**InfinityFree Forum:** https://forum.infinityfree.com/
**TheHUB Issues:** https://github.com/ECABBIKE/TheHUB/issues

---

## 📋 Deployment Checklist

- [ ] Filer uppladdade till `/htdocs/`
- [ ] `.env` fil finns på servern
- [ ] Databas-schema körts (`schema.sql`)
- [ ] Hemsida fungerar (test public/index.php)
- [ ] Admin-login fungerar
- [ ] Dashboard visar statistik
- [ ] Import-funktioner fungerar
- [ ] GitHub Secrets konfigurerade (för framtida auto-deploy)

---

## 🎉 När allt fungerar

TheHUB är nu live på InfinityFree! 🚀

**Din site:**
- Frontend: https://thehub.infinityfreeapp.com/public/
- Admin: https://thehub.infinityfreeapp.com/admin/

**Nästa steg:**
1. Konfigurera egen domän (om du har en)
2. Aktivera HTTPS (sätt `FORCE_HTTPS=true` i .env)
3. Importera riktiga data via `/admin/import-riders.php`
4. Konfigurera backup-rutin

Lycka till! 🎯
