# Plan: Externa rabattkoder för winback-kampanjer

## Sammanfattning
Utöka winback-systemet med stöd för "externa rabattkoder" — koder som delas ut till deltagare efter enkätsvar, men som används på extern anmälningsplattform (t.ex. EQ Timing för Swecup). Max 10 unika koder per kampanj, där varje kod representerar en deltagarkategori baserad på erfarenhet, tid sedan senaste tävling och ålder. Alla inom samma kategori får samma kod → möjliggör spårning av vilken deltagartyp som konverterar.

## Databasändringar

### Migration 081: winback_external_codes.sql

**Ny tabell `winback_external_codes`:**
```sql
CREATE TABLE IF NOT EXISTS winback_external_codes (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    campaign_id INT UNSIGNED NOT NULL,
    code VARCHAR(50) NOT NULL,
    category_key VARCHAR(50) NOT NULL,     -- t.ex. 'veteran_recent'
    category_label VARCHAR(100) NOT NULL,  -- t.ex. 'Veteran (6+) · Churnad 1-2 år'
    experience_min INT DEFAULT NULL,       -- min antal starter
    experience_max INT DEFAULT NULL,       -- max antal starter (NULL = obegränsat)
    churn_years_min INT DEFAULT NULL,      -- min år sedan senast
    churn_years_max INT DEFAULT NULL,      -- max år sedan senast (NULL = aktiv)
    age_min INT DEFAULT NULL,
    age_max INT DEFAULT NULL,
    rider_count INT DEFAULT 0,             -- antal riders i kategorin (cachat vid generering)
    usage_count INT DEFAULT 0,             -- manuellt rapporterat antal användningar
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_campaign (campaign_id),
    UNIQUE KEY uk_campaign_code (campaign_id, code)
);
```

**Nya kolumner i `winback_campaigns`:**
```sql
ALTER TABLE winback_campaigns
    ADD COLUMN external_codes_enabled TINYINT(1) DEFAULT 0 AFTER allow_promotor_access,
    ADD COLUMN external_code_prefix VARCHAR(20) DEFAULT NULL AFTER external_codes_enabled,
    ADD COLUMN external_event_name VARCHAR(255) DEFAULT NULL AFTER external_code_prefix;
```

## Kategorisering (automatisk, max 10 koder)

Dimensioner:
- **Erfarenhet:** Antal starter totalt inom valda varumärken
  - Nybörjare: 1 start
  - Medel: 2-5 starter
  - Veteran: 6+ starter
- **Churn:** År sedan senaste start
  - Nyligen: 1-2 år
  - Länge sedan: 3+ år
  - Aktiv: 0 (startade innevarande/senaste år)
- **Ålder:**
  - Ung: <30 år
  - Äldre: 30+ år

3 × 3 × 2 = 18 möjliga kategorier → slås ihop till max 10 genom att:
1. Generera alla kategorier med rider-count
2. Sortera efter rider-count fallande
3. Behåll de 9 största + slå ihop resten till "Övriga"
4. Hoppa över kategorier med 0 riders

Kod-format: `{PREFIX}{SUFFIX}` t.ex. `SWECUP-V2` (admin anger prefix, suffix A-J genereras automatiskt)

## Ändringar i befintliga filer

### 1. `admin/winback-campaigns.php`
- **Ny checkbox i kampanjformuläret:** "Externa rabattkoder" — togglar synlighet av:
  - Prefix-fält (text input)
  - Externt eventnamn (text input)
  - Information om att koder genereras automatiskt vid skapande
- **Vid create_campaign/update_campaign:** Om external_codes_enabled:
  - Spara prefix och event-namn
  - Generera kategorier via ny funktion `generateExternalCodes()`
  - Sparar max 10 rader i `winback_external_codes`
- **Ny sektion i kampanjkortet:** Visar lista med koder, kategori, antal riders, användningar
- **Ny POST-action `update_usage`:** Admin kan rapportera antal använda koder per kategori

### 2. `pages/profile/winback-survey.php`
- **Vid survey-submit med external_codes_enabled:**
  - Beräkna deltagarens kategori (erfarenhet + churn + ålder)
  - Slå upp matching extern kod i `winback_external_codes`
  - Spara koden i `winback_responses.discount_code_given` (befintlig kolumn)
  - Visa koden tydligt med instruktion "Använd denna kod vid anmälan på [extern plattform]"

### 3. `pages/profile/winback.php`
- **Visa extern kod** med instruktion om att den gäller för externt event + eventnamn

### 4. `admin/migrations.php`
- Registrera migration 081 i `$migrationChecks`

## Ny funktion: `generateExternalCodes()`

```php
function generateExternalCodes($pdo, $campaignId, $prefix) {
    // 1. Hämta kampanjdata (brand_ids, start_year, end_year, target_year, audience_type)
    // 2. Hämta alla kvalificerade riders med deras stats:
    //    - total_starts: antal starter inom varumärkena
    //    - last_race_year: senaste tävlingsår
    //    - age: nuvarande ålder
    // 3. Kategorisera varje rider → category_key
    // 4. Räkna riders per kategori
    // 5. Behåll max 9 kategorier + "other", skippa tomma
    // 6. Generera koder: {PREFIX}A, {PREFIX}B, ... {PREFIX}J
    // 7. INSERT INTO winback_external_codes
}
```

## Filer som skapas/ändras

| Fil | Ändring |
|-----|---------|
| `Tools/migrations/081_winback_external_codes.sql` | Ny migration |
| `admin/winback-campaigns.php` | Formulär + kodvisning + usage-rapportering |
| `pages/profile/winback-survey.php` | Kategorisera rider → tilldela extern kod |
| `pages/profile/winback.php` | Visa extern kod + eventnamn |
| `admin/migrations.php` | Registrera migration 081 |
| `config.php` | APP_BUILD uppdateras |
| `.claude/rules/memory.md` | Dokumentera ändringar |
| `ROADMAP.md` | Uppdatera status |
