# GravityTiming API - Integrationsplan

> Datum: 2026-02-22
> Status: PLANERING

---

## Sammanfattning

GravityTiming ar en lokal tidtagningsapplikation som kors pa en stationer dator vid tavlingsplatsen. Denna plan beskriver ett API i TheHUB som later GravityTiming:

1. **Hamta startlistor** - Akare, startnummer, klasser, klubbar, UCI ID mm fran TheHUB
2. **Ladda upp resultat live** - Skicka tider (split times, sluttider, status) tillbaka till TheHUB under tavlingens gang
3. **Visa resultat i realtid** - Resultaten publiceras direkt pa event-sidan under resultat-fliken

---

## DEL 1: Autentisering & Sakerhet

### API-nyckelbaserad autentisering

Tidtagningsappen kors lokalt utan anvandarsession. Darfor anvands **API-nycklar** istallet for session-baserad auth.

#### Ny tabell: `api_keys`

```sql
CREATE TABLE api_keys (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,           -- T.ex. "GravityTiming Kungsbacka"
    api_key VARCHAR(64) NOT NULL UNIQUE,  -- SHA-256 hash av nyckeln
    api_secret_hash VARCHAR(255) NOT NULL, -- bcrypt hash av secret
    scope ENUM('timing', 'readonly', 'admin') DEFAULT 'timing',
    event_ids TEXT NULL,                   -- JSON array med tillåtna event-ID, NULL = alla
    series_ids TEXT NULL,                  -- JSON array med tillåtna serie-ID, NULL = alla
    created_by INT NULL,                   -- Admin som skapade nyckeln
    last_used_at DATETIME NULL,
    expires_at DATETIME NULL,              -- NULL = aldrig
    active TINYINT(1) DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
```

#### Autentiseringsflode

```
GravityTiming -> TheHUB API:
  Header: X-API-Key: gt_xxxxxxxxxxxx
  Header: X-API-Secret: hemlig_nyckel_har
```

- `X-API-Key` ar en public identifierare (prefix `gt_`)
- `X-API-Secret` valideras mot `api_secret_hash` (bcrypt)
- Rate limiting: 60 anrop/minut per nyckel
- Scope `timing` ger tillgang till startlistor + resultatuppladdning
- `event_ids` begrenser vilka event nyckeln kan komma at

#### Ny fil: `/api/v1/auth-middleware.php`

Validerar varje API-anrop:
1. Laser `X-API-Key` och `X-API-Secret` fran headers
2. Slar upp nyckeln i `api_keys`
3. Verifierar bcrypt-hash
4. Kollar scope, event-begransning, expires_at
5. Uppdaterar `last_used_at`
6. Returnerar nyckelns metadata (scope, tillagna events)

#### Admin-verktyg: API-nyckelhantering

Ny sida `/admin/api-keys.php`:
- Skapa/radera nycklar
- Begrana per event eller serie
- Se senaste anvandning
- Lankas fran `/admin/tools.php` under System-sektionen

---

## DEL 2: Startliste-API (GravityTiming hamtar data)

### Endpoint: `GET /api/v1/events`

Lista tillgangliga events (baserat pa API-nyckelns scope).

**Request:**
```
GET /api/v1/events?year=2026&status=upcoming
Headers: X-API-Key, X-API-Secret
```

**Response:**
```json
{
  "success": true,
  "events": [
    {
      "id": 42,
      "name": "Kungsbacka Enduro",
      "date": "2026-05-15",
      "location": "Fjärås",
      "discipline": "ENDURO",
      "event_format": "ENDURO",
      "series_name": "Gravity Enduro Series",
      "max_participants": 100,
      "registered_count": 67,
      "classes": [
        {"id": 1, "name": "Herrar Elit", "display_name": "Men Elite"},
        {"id": 2, "name": "Damer Elit", "display_name": "Women Elite"}
      ],
      "stage_names": {"1": "SS1 Berget", "2": "SS2 Skogen", "3": "SS3 Ravinen"},
      "stage_count": 3
    }
  ]
}
```

### Endpoint: `GET /api/v1/events/{id}/startlist`

Hamta komplett startlista for ett event.

**Request:**
```
GET /api/v1/events/42/startlist
Headers: X-API-Key, X-API-Secret
```

**Response:**
```json
{
  "success": true,
  "event": {
    "id": 42,
    "name": "Kungsbacka Enduro",
    "date": "2026-05-15",
    "discipline": "ENDURO",
    "event_format": "ENDURO",
    "stage_names": {"1": "SS1", "2": "SS2", "3": "SS3"},
    "stage_count": 3
  },
  "participants": [
    {
      "registration_id": 1001,
      "rider_id": 234,
      "bib_number": 101,
      "first_name": "Erik",
      "last_name": "Svensson",
      "birth_year": 1994,
      "gender": "M",
      "nationality": "SWE",
      "club_name": "Lidingo SK",
      "club_id": 15,
      "class_name": "Herrar Elit",
      "class_id": 1,
      "category": "Herrar Elit",
      "license_number": "10012345678",
      "license_type": "Elite"
    }
  ],
  "total_count": 67
}
```

**Data kommer fran:**
- `event_registrations` (bib_number, category, class_id, status)
- `riders` (firstname, lastname, birth_year, gender, nationality, license_number, license_type)
- `clubs` (name)
- `events` (stage_names, discipline, event_format)
- Filtreras pa `status != 'cancelled'` och `payment_status = 'paid'` (via order)

### Endpoint: `GET /api/v1/events/{id}/classes`

Hamta klasser for ett event.

**Response:**
```json
{
  "success": true,
  "classes": [
    {
      "id": 1,
      "name": "Herrar Elit",
      "display_name": "Men Elite",
      "participant_count": 23,
      "bib_range": {"min": 1, "max": 50}
    }
  ]
}
```

---

## DEL 3: Resultat-API (GravityTiming skickar data)

### Endpoint: `POST /api/v1/events/{id}/results`

Ladda upp/uppdatera resultat for ett event. Stodjer bade enstaka och batch.

**Request (batch):**
```json
{
  "results": [
    {
      "bib_number": 101,
      "class_name": "Herrar Elit",
      "position": 1,
      "finish_time": "15:42.33",
      "status": "FIN",
      "split_times": {
        "ss1": "2:15.44",
        "ss2": "1:52.11",
        "ss3": "2:33.55"
      }
    },
    {
      "bib_number": 102,
      "class_name": "Herrar Elit",
      "position": 2,
      "finish_time": "16:01.22",
      "status": "FIN",
      "split_times": {
        "ss1": "2:18.00",
        "ss2": "1:55.33",
        "ss3": "2:41.89"
      }
    }
  ],
  "mode": "upsert"
}
```

**Matching-logik:**
1. **Primar:** `bib_number` + `event_id` -> slar upp `rider_id` via `event_registrations`
2. **Fallback:** Om `rider_id` skickas direkt
3. **Klassmatching:** `class_name` matchas mot `event_registrations.category` eller `classes.name`

**Response:**
```json
{
  "success": true,
  "imported": 2,
  "updated": 0,
  "errors": [],
  "results": [
    {"bib_number": 101, "status": "created", "result_id": 5001},
    {"bib_number": 102, "status": "created", "result_id": 5002}
  ]
}
```

**Mode-alternativ:**
- `upsert` (default) - Skapa nya, uppdatera befintliga (matchar pa bib_number + event_id)
- `replace` - Radera alla resultat for eventet och ersatt med nya
- `append` - Skapa bara nya, skippa befintliga

**Databasoperationer:**
- INSERT/UPDATE i `results`-tabellen
- Kolumner: `event_id`, `cyclist_id` (= rider_id), `class_id`, `position`, `finish_time`, `status`, `bib_number`, `ss1`-`ss15`
- Poangberakning triggas automatiskt (via `recalculateEventPoints()` i point-calculations.php)

### Endpoint: `POST /api/v1/events/{id}/results/live`

Skicka EN split time at gangen for live-uppdatering under tavling.

**Request:**
```json
{
  "bib_number": 101,
  "stage": "ss2",
  "time": "1:52.11",
  "timestamp": "2026-05-15T11:23:45+02:00"
}
```

**Response:**
```json
{
  "success": true,
  "rider": "Erik Svensson",
  "stage": "ss2",
  "time": "1:52.11",
  "stage_position": 3
}
```

Denna endpoint:
1. Slar upp rider via bib_number i event_registrations
2. Skapar/uppdaterar rad i results (om inte finns)
3. Satter ratt `ssN`-kolumn
4. Triggar **live-event** (se Del 5)

### Endpoint: `DELETE /api/v1/events/{id}/results`

Rensa alla resultat for ett event (krav: `mode=all` som query-param for sakerhet).

**Request:**
```
DELETE /api/v1/events/42/results?mode=all
Headers: X-API-Key, X-API-Secret
```

### Endpoint: `PATCH /api/v1/events/{id}/results/{result_id}`

Uppdatera ett enskilt resultat (t.ex. andrat status fran FIN till DNF).

---

## DEL 4: Live-resultat pa event-sidan

### Ny flik/vy: "Live-resultat" pa event-sidan

Nar resultat borjar stramma in fran GravityTiming visas de i realtid pa event-sidan (`/pages/event.php`).

#### Polling-baserad losning (enklare, robustare)

Event-sidan pollar ett lightweight API-endpoint var 10:e sekund:

```
GET /api/v1/events/{id}/results/status
```

**Response:**
```json
{
  "last_updated": "2026-05-15T11:23:45+02:00",
  "result_count": 34,
  "stages_completed": ["ss1", "ss2"],
  "stages_in_progress": ["ss3"],
  "is_live": true
}
```

Om `last_updated` andrats sedan senaste poll -> hamta nya resultat och uppdatera DOM.

#### Frontend-implementation

1. JavaScript-modul som pollar `/api/v1/events/{id}/results/latest?since={timestamp}`
2. Returnerar bara resultat som andrats sedan `since`
3. Uppdaterar tabellen inkrementellt (inga full page reloads)
4. Visar "LIVE"-badge i resultat-fliken nar is_live = true
5. Auto-sorterar efter position inom varje klass

#### Events-flagga: `is_live`

Ny kolumn `events.timing_live` (TINYINT, default 0):
- Satts till 1 automatiskt nar forsta live-resultatet kommer in
- Satts till 0 nar arrangoren markerar tavlingen som avslutad
- Anvands for att visa "LIVE"-indikator pa event-sidan och kalendern

---

## DEL 5: Databasandringar (Migration)

### Migration: `053_gravitytiming_api.sql`

```sql
-- API-nycklar for extern autentisering
CREATE TABLE IF NOT EXISTS api_keys (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    api_key VARCHAR(64) NOT NULL UNIQUE,
    api_secret_hash VARCHAR(255) NOT NULL,
    scope ENUM('timing', 'readonly', 'admin') DEFAULT 'timing',
    event_ids TEXT NULL,
    series_ids TEXT NULL,
    created_by INT NULL,
    last_used_at DATETIME NULL,
    expires_at DATETIME NULL,
    active TINYINT(1) DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_api_key (api_key),
    INDEX idx_active (active)
);

-- Live-timing flagga pa events
ALTER TABLE events ADD COLUMN timing_live TINYINT(1) DEFAULT 0 AFTER active;

-- Logg for API-anrop (valfritt, for debug)
CREATE TABLE IF NOT EXISTS api_request_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    api_key_id INT NOT NULL,
    endpoint VARCHAR(200) NOT NULL,
    method VARCHAR(10) NOT NULL,
    event_id INT NULL,
    response_code INT NOT NULL,
    request_body_size INT DEFAULT 0,
    response_time_ms INT DEFAULT 0,
    ip_address VARCHAR(45) NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_api_key_id (api_key_id),
    INDEX idx_created (created_at)
);
```

---

## DEL 6: Filstruktur

```
api/
└── v1/
    ├── auth-middleware.php        -- API-nyckel validering
    ├── events.php                -- GET /events (lista)
    ├── event-startlist.php       -- GET /events/{id}/startlist
    ├── event-classes.php         -- GET /events/{id}/classes
    ├── event-results.php         -- POST/DELETE /events/{id}/results
    ├── event-results-live.php    -- POST /events/{id}/results/live
    └── event-results-status.php  -- GET /events/{id}/results/status

admin/
├── api-keys.php                  -- Admin: hantera API-nycklar

Tools/
└── migrations/
    └── 053_gravitytiming_api.sql
```

---

## DEL 7: GravityTiming-sidans ansvar (referens)

GravityTiming-appen (den lokala Windows/Mac-appen) behover:

1. **Konfigurationssida** - Ange TheHUB URL + API-nyckel
2. **Event-valjare** - Lista events fran `GET /api/v1/events`
3. **Synka startlista** - Hamta fran `GET /api/v1/events/{id}/startlist`
4. **Resultat-export** - Skicka resultat via `POST /api/v1/events/{id}/results`
5. **Live-mode** - Skicka enstaka split times via `POST /api/v1/events/{id}/results/live`
6. **Statusvisning** - Visa synk-status (senaste upload, antal skickade, eventuella fel)

---

## DEL 8: Implementeringsordning

### Steg 1: Grundlaggande infrastruktur
- [ ] Migration 053 (api_keys tabell + events.timing_live)
- [ ] Auth-middleware (`/api/v1/auth-middleware.php`)
- [ ] Admin API-nyckelhantering (`/admin/api-keys.php`)
- [ ] Lank i tools.php

### Steg 2: Lasande endpoints (startlistor)
- [ ] `GET /api/v1/events` - Lista events
- [ ] `GET /api/v1/events/{id}/startlist` - Hamta startlista
- [ ] `GET /api/v1/events/{id}/classes` - Hamta klasser

### Steg 3: Skrivande endpoints (resultat)
- [ ] `POST /api/v1/events/{id}/results` - Batch-upload resultat
- [ ] `POST /api/v1/events/{id}/results/live` - Live split time
- [ ] `DELETE /api/v1/events/{id}/results` - Rensa resultat
- [ ] `PATCH /api/v1/events/{id}/results/{id}` - Uppdatera enstaka
- [ ] Poangberakning efter resultatuppladdning

### Steg 4: Live-resultat pa event-sidan
- [ ] `GET /api/v1/events/{id}/results/status` - Status-endpoint
- [ ] JavaScript polling-modul for event.php
- [ ] "LIVE"-badge i resultat-fliken
- [ ] Inkrementell uppdatering av resultattabellen

### Steg 5: Test & Dokumentation
- [ ] Testverktyg i admin (`/admin/tools/test-timing-api.php`)
- [ ] API-dokumentation (inline i admin)
- [ ] .htaccess routing for `/api/v1/*`
- [ ] Rate limiting

---

## FRAGOR ATT KLARGORA

1. **GravityTiming-format:** Skicka zip sa jag kan se exakt vilka dataformat appen arbetar med (CSV? JSON? XML?)
2. **DH-stod:** Ska API:t stodja DH med run_1_time/run_2_time utover enduro split times?
3. **Offline-stod:** Behover appen kunna buffra och skicka resultat nar natet ater ar tillgangligt?
4. **Flera tidtagare:** Kan flera GravityTiming-instanser rapportera till samma event samtidigt (t.ex. en per SS)?
5. **Vilken granularitet?** Ska varje klockning skickas direkt (per akare per SS) eller batchar man per SS?
