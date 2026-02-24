# GravityTiming API – Integrationsdokumentation

> **Version:** 1.0
> **API Base URL:** `https://thehub.gravityseries.se/api/v1`
> **Senast uppdaterad:** 2026-02-23

---

## Översikt

GravityTiming-appen kommunicerar med TheHUB via ett REST API för att:

1. **Hämta startlistor** – Vilka åkare som är anmälda, deras startnummer, klass och klubb
2. **Ladda upp resultat** – Batch-import av kompletta resultat efter tävling
3. **Skicka live split times** – Realtidsuppdateringar, en sträcka åt gången
4. **Kontrollera status** – Polla efter senaste resultat och se vilka sträckor som är klara

---

## Autentisering

Alla anrop kräver två HTTP-headers:

| Header | Beskrivning |
|--------|-------------|
| `X-API-Key` | API-nyckel (börjar med `gt_`) |
| `X-API-Secret` | Hemlig nyckel (visas bara vid skapande) |

API-nycklar skapas av admin i TheHUB under **Admin → Verktyg → API-nycklar**.

### Behörighetsnivåer (scope)

| Scope | Rättigheter |
|-------|-------------|
| `readonly` | Kan hämta events, startlistor, klasser och status |
| `timing` | Allt i readonly + kan ladda upp/ändra/radera resultat |
| `admin` | Full åtkomst |

En nyckel med scope `timing` räcker för normal tidtagning.

### Begränsningar

- **Rate limit:** Max 60 anrop per minut
- **Event-begränsning:** Nycklar kan begränsas till specifika event
- **Utgångsdatum:** Nycklar kan ha ett utgångsdatum

### Felkoder vid autentisering

| HTTP-kod | Betydelse |
|----------|-----------|
| 401 | Felaktig eller saknad API-nyckel/hemlighet |
| 403 | Nyckeln har inte behörighet (scope/event) |
| 429 | Rate limit överskriden – vänta en minut |

---

## Svarsformat

Alla svar är JSON. Lyckade anrop:

```json
{
  "success": true,
  ...
}
```

Misslyckade anrop:

```json
{
  "success": false,
  "error": "Beskrivning av felet"
}
```

---

## Endpoints

### 1. Lista events

Hämta alla events som nyckeln har tillgång till.

```
GET /api/v1/events
GET /api/v1/events?year=2026
GET /api/v1/events?year=2026&status=upcoming
```

**Query-parametrar:**

| Parameter | Typ | Default | Beskrivning |
|-----------|-----|---------|-------------|
| `year` | Heltal | Innevarande år | Filtrera på år |
| `status` | Sträng | Alla | `upcoming`, `past` eller `all` |

**Scope:** `readonly`

**Svar:**

```json
{
  "success": true,
  "events": [
    {
      "id": 42,
      "name": "Tranås MTB 2026",
      "date": "2026-03-15",
      "location": "Tranås, Sverige",
      "discipline": "MTB",
      "event_format": "Multi-stage",
      "series_name": "Gravity Series Enduro",
      "max_participants": 150,
      "registered_count": 87,
      "timing_live": false,
      "classes": [
        { "id": 5, "name": "Elite", "display_name": "Elite Herr" },
        { "id": 12, "name": "Junior", "display_name": "Junior (15-17)" }
      ],
      "stage_names": ["SS1: Long Ridge", "SS2: Tech Garden", "SS3: Flow Trail"],
      "stage_count": 3
    }
  ],
  "total_count": 1
}
```

**Viktiga fält:**

| Fält | Beskrivning |
|------|-------------|
| `id` | Event-ID – används i alla andra anrop |
| `stage_names` | Array med namn på varje sträcka (SS), eller `null` om inga definierats |
| `stage_count` | Antal sträckor |
| `timing_live` | `true` om live-tidtagning pågår just nu |
| `classes` | Lista över tävlingsklasser med ID och namn |

---

### 2. Hämta startlista

Hämta alla anmälda åkare för ett event.

```
GET /api/v1/events/{event_id}/startlist
```

**Scope:** `readonly`

**Svar:**

```json
{
  "success": true,
  "event": {
    "id": 42,
    "name": "Tranås MTB 2026",
    "date": "2026-03-15",
    "location": "Tranås, Sverige",
    "discipline": "MTB",
    "event_format": "Multi-stage",
    "stage_names": ["SS1: Long Ridge", "SS2: Tech Garden", "SS3: Flow Trail"],
    "stage_count": 3
  },
  "participants": [
    {
      "registration_id": 501,
      "rider_id": 123,
      "bib_number": 42,
      "first_name": "Erik",
      "last_name": "Svensson",
      "birth_year": 1995,
      "gender": "M",
      "nationality": "SWE",
      "club_name": "Linköpings CK",
      "club_id": 8,
      "class_name": "Elite",
      "class_id": 5,
      "category": "Elite Herr",
      "license_number": "10012345678",
      "license_type": "Elite"
    }
  ],
  "total_count": 87
}
```

**Viktiga fält per deltagare:**

| Fält | Beskrivning |
|------|-------------|
| `bib_number` | Startnummer – **detta är nyckeln för att matcha resultat** |
| `rider_id` | Åkar-ID i TheHUB (alternativ nyckel vid resultat-upload) |
| `class_id` | Klass-ID – behövs om du vill filtrera per klass |
| `class_name` | Klassnamn (t.ex. "Elite", "Junior") |
| `license_number` | UCI ID (11 siffror) |

**Sortering:** Grupperat per klass, sedan startnummer, sedan efternamn.

---

### 3. Hämta klasser

Hämta alla klasser för ett event med deltagarantal.

```
GET /api/v1/events/{event_id}/classes
```

**Scope:** `readonly`

**Svar:**

```json
{
  "success": true,
  "classes": [
    {
      "id": 5,
      "name": "Elite",
      "display_name": "Elite Herr",
      "participant_count": 34,
      "bib_range": { "min": 1, "max": 42 }
    },
    {
      "id": 12,
      "name": "Junior",
      "display_name": "Junior (15-17)",
      "participant_count": 28,
      "bib_range": { "min": 100, "max": 128 }
    }
  ]
}
```

| Fält | Beskrivning |
|------|-------------|
| `participant_count` | Antal anmälda (ej avbokade) |
| `bib_range` | Lägsta och högsta startnummer i klassen |

---

### 4. Ladda upp resultat (batch)

Skicka in kompletta resultat efter att tävlingen är klar, eller en hel klass åt gången.

```
POST /api/v1/events/{event_id}/results
Content-Type: application/json
```

**Scope:** `timing`

**Request body:**

```json
{
  "mode": "upsert",
  "results": [
    {
      "bib_number": 42,
      "position": 1,
      "finish_time": "00:45:32",
      "status": "FIN",
      "split_times": {
        "ss1": "00:15:32",
        "ss2": "00:14:28",
        "ss3": "00:15:32"
      }
    },
    {
      "bib_number": 43,
      "position": 2,
      "finish_time": "00:46:10",
      "status": "FIN",
      "split_times": {
        "ss1": "00:15:50",
        "ss2": "00:14:45",
        "ss3": "00:15:35"
      }
    },
    {
      "bib_number": 99,
      "position": null,
      "finish_time": null,
      "status": "DNF",
      "split_times": {
        "ss1": "00:16:20"
      }
    }
  ]
}
```

#### Fält i varje resultat-objekt

| Fält | Typ | Krävs | Beskrivning |
|------|-----|-------|-------------|
| `bib_number` | Heltal | Ja* | Startnummer (matchar mot startlistan) |
| `rider_id` | Heltal | Ja* | Alternativ: åkar-ID direkt |
| `class_name` | Sträng | Nej | Klassnamn (fallback om bib saknar registrering) |
| `position` | Heltal | Nej | Placering (1, 2, 3...) |
| `finish_time` | Sträng | Nej | Total tid (format: `HH:MM:SS`) |
| `status` | Sträng | Nej | `FIN`, `DNF`, `DNS` eller `DQ` |
| `split_times` | Objekt | Nej | Sträcktider: `{ "ss1": "tid", "ss2": "tid", ... }` |

*Antingen `bib_number` eller `rider_id` måste anges.

#### Upload-lägen (mode)

| Läge | Beteende |
|------|----------|
| `upsert` | **Standard.** Skapar nya resultat, uppdaterar befintliga |
| `replace` | Raderar ALLA befintliga resultat först, lägger in nya |
| `append` | Skapar bara nya, hoppar över om resultat redan finns |

#### Svar

```json
{
  "success": true,
  "imported": 40,
  "updated": 2,
  "errors": [
    {
      "index": 15,
      "bib_number": 999,
      "error": "Kunde inte matcha åkare (bib_number ej registrerat)"
    }
  ],
  "results": [
    { "bib_number": 42, "status": "created", "result_id": 5001 },
    { "bib_number": 43, "status": "updated", "result_id": 4998 },
    { "bib_number": 999, "status": "skipped", "result_id": null }
  ]
}
```

| Fält | Beskrivning |
|------|-------------|
| `imported` | Antal nya resultat |
| `updated` | Antal uppdaterade resultat |
| `errors` | Lista med fel (åkare som inte kunde matchas etc.) |
| `results` | Detaljerad status per resultat (`created`, `updated`, `skipped`) |

**Sidoeffekter:**
- Poäng räknas om automatiskt efter upload
- `timing_live` sätts till `1` på eventet

---

### 5. Skicka live split time

Skicka en enskild sträcktid i realtid, t.ex. när en åkare passerar mål på en SS.

```
POST /api/v1/events/{event_id}/results/live
Content-Type: application/json
```

**Scope:** `timing`

**Request body:**

```json
{
  "bib_number": 42,
  "stage": "ss1",
  "time": "00:15:32"
}
```

| Fält | Typ | Krävs | Beskrivning |
|------|-----|-------|-------------|
| `bib_number` | Heltal | Ja | Startnummer |
| `stage` | Sträng | Ja | Sträcka: `ss1` till `ss15` |
| `time` | Sträng | Ja | Tid (format: `HH:MM:SS` eller minuter) |

**Svar:**

```json
{
  "success": true,
  "result_id": 5001,
  "rider": "Erik Svensson",
  "bib_number": 42,
  "stage": "ss1",
  "time": "00:15:32",
  "stage_position": 3
}
```

| Fält | Beskrivning |
|------|-------------|
| `result_id` | Resultat-ID (skapas automatiskt om det inte finns) |
| `rider` | Åkarens namn (för bekräftelse) |
| `stage_position` | Placering på denna sträcka jämfört med andra i samma klass |

**Beteende:**
- Om åkaren inte har något resultat ännu skapas ett automatiskt
- Om åkaren redan har resultat uppdateras bara den angivna sträckan
- `timing_live` sätts till `1` på eventet

---

### 6. Uppdatera enskilt resultat

Korrigera ett specifikt resultat (t.ex. ändra tid eller status).

```
PATCH /api/v1/events/{event_id}/results?result_id=5001
Content-Type: application/json
```

**Scope:** `timing`

**Request body** (skicka bara de fält du vill ändra):

```json
{
  "position": 2,
  "finish_time": "00:46:10",
  "status": "FIN",
  "ss3": "00:15:45"
}
```

**Tillåtna fält:** `position`, `finish_time`, `status`, `bib_number`, `ss1`–`ss15`

**Svar:**

```json
{
  "success": true,
  "result_id": 5001,
  "updated_fields": ["position", "finish_time", "ss3"]
}
```

---

### 7. Radera alla resultat

Rensa alla resultat för ett event (t.ex. för att börja om).

```
DELETE /api/v1/events/{event_id}/results?mode=all
```

**Scope:** `timing`

**Viktigt:** Parametern `mode=all` är en säkerhetsspärr – anropet misslyckas utan den.

**Svar:**

```json
{
  "success": true,
  "deleted": 87,
  "message": "Alla 87 resultat raderade för event 42"
}
```

**Sidoeffekter:**
- `timing_live` återställs till `0`

---

### 8. Kontrollera status (polling)

Kontrollera om det finns nya resultat. Används av frontend för att visa live-uppdateringar.

```
GET /api/v1/events/{event_id}/results/status
GET /api/v1/events/{event_id}/results/status?since=2026-03-15T14:30:00
```

**Scope:** `readonly`

**Query-parametrar:**

| Parameter | Typ | Beskrivning |
|-----------|-----|-------------|
| `since` | ISO-datum | Returnera bara resultat uppdaterade efter detta klockslag |

**Svar (utan `since`):**

```json
{
  "success": true,
  "event_id": 42,
  "last_updated": "2026-03-15T14:35:22",
  "result_count": 87,
  "stages_completed": ["ss1", "ss2"],
  "stages_in_progress": ["ss3"],
  "is_live": true
}
```

**Svar (med `since`):**

```json
{
  "success": true,
  "event_id": 42,
  "last_updated": "2026-03-15T14:35:22",
  "result_count": 87,
  "stages_completed": ["ss1", "ss2"],
  "stages_in_progress": ["ss3"],
  "is_live": true,
  "results": [
    {
      "id": 5001,
      "cyclist_id": 123,
      "bib_number": 42,
      "class_id": 5,
      "position": 1,
      "finish_time": "00:45:32",
      "status": "FIN",
      "ss1": "00:15:32",
      "ss2": "00:14:28",
      "ss3": "00:15:32",
      "created_at": "2026-03-15T14:30:00",
      "firstname": "Erik",
      "lastname": "Svensson",
      "class_name": "Elite"
    }
  ]
}
```

| Fält | Beskrivning |
|------|-------------|
| `last_updated` | Senaste resultatändringen (ISO-datum) |
| `result_count` | Totalt antal resultat |
| `stages_completed` | Sträckor där ≥90% av åkarna har tid |
| `stages_in_progress` | Sträckor med 10–90% av åkarna |
| `is_live` | Om live-tidtagning är aktivt |
| `results` | Detaljerade resultat (bara med `since`-parameter) |

---

## Typiskt arbetsflöde

### Före tävling

```
1. GET /api/v1/events?status=upcoming
   → Välj event i appen

2. GET /api/v1/events/{id}/startlist
   → Ladda ner alla deltagare med startnummer

3. GET /api/v1/events/{id}/classes
   → Hämta klasser och startnummerintervall
```

### Under tävling (live)

```
4. POST /api/v1/events/{id}/results/live
   → Skicka varje split time direkt när åkare passerar mål
   → { "bib_number": 42, "stage": "ss1", "time": "00:15:32" }

   Upprepa för varje åkare och sträcka.
```

### Efter tävling (batch)

```
5. POST /api/v1/events/{id}/results
   → Ladda upp alla resultat med slutpositioner och total tid
   → mode: "upsert" om live-tider redan skickats (uppdaterar befintliga)
   → mode: "replace" om du vill börja om helt
```

### Korrigeringar

```
6. PATCH /api/v1/events/{id}/results?result_id=X
   → Korrigera enskild tid eller status

7. DELETE /api/v1/events/{id}/results?mode=all
   → Rensa allt och börja om (nödlösning)
```

---

## Status-värden för resultat

| Status | Betydelse |
|--------|-----------|
| `FIN` | Fullföljt (Finished) |
| `DNF` | Bröt (Did Not Finish) |
| `DNS` | Startade inte (Did Not Start) |
| `DQ` | Diskvalificerad (Disqualified) |

---

## Sträckor (stages)

Split times lagras i fält `ss1` till `ss15`. Namn på sträckorna finns i eventets `stage_names`-array.

**Exempel:**

```
stage_names: ["SS1: Long Ridge", "SS2: Tech Garden", "SS3: Flow Trail"]
```

| API-fält | Sträcknamn |
|----------|------------|
| `ss1` | SS1: Long Ridge |
| `ss2` | SS2: Tech Garden |
| `ss3` | SS3: Flow Trail |

Sträckor utan definierat namn visas som "SS1", "SS2" etc. på hemsidan.

---

## Tidsformat

Tider skickas och returneras som strängar:

| Format | Exempel | Beskrivning |
|--------|---------|-------------|
| `HH:MM:SS` | `01:23:45` | Timmar, minuter, sekunder |
| `MM:SS.ss` | `15:32.45` | Minuter med decimaler |

Båda formaten accepteras av API:t. Använd det format som tidtagningssystemet genererar.

---

## Felhantering

### HTTP-statuskoder

| Kod | Betydelse | Åtgärd |
|-----|-----------|--------|
| 200 | OK | Allt fungerade |
| 400 | Ogiltig begäran | Kontrollera request body / parametrar |
| 401 | Ej autentiserad | Kontrollera API-nyckel och hemlighet |
| 403 | Åtkomst nekad | Nyckeln saknar behörighet för detta event/scope |
| 404 | Ej funnet | Event eller resultat finns inte |
| 429 | För många anrop | Vänta 60 sekunder, försök igen |
| 500 | Serverfel | Kontakta admin |

### Retry-strategi

Vid nätverksfel eller HTTP 429/500:

1. Vänta 2 sekunder, försök igen
2. Vänta 4 sekunder, försök igen
3. Vänta 8 sekunder, försök igen
4. Vänta 16 sekunder, sista försöket
5. Ge upp och visa felmeddelande

**Skicka INTE om vid 400/401/403** – dessa är permanenta fel.

---

## Exempel med cURL

### Hämta startlista

```bash
curl -H "X-API-Key: gt_abc123..." \
     -H "X-API-Secret: def456..." \
     https://thehub.gravityseries.se/api/v1/events/42/startlist
```

### Skicka live split time

```bash
curl -X POST \
     -H "X-API-Key: gt_abc123..." \
     -H "X-API-Secret: def456..." \
     -H "Content-Type: application/json" \
     -d '{"bib_number": 42, "stage": "ss1", "time": "00:15:32"}' \
     https://thehub.gravityseries.se/api/v1/events/42/results/live
```

### Ladda upp resultat (batch)

```bash
curl -X POST \
     -H "X-API-Key: gt_abc123..." \
     -H "X-API-Secret: def456..." \
     -H "Content-Type: application/json" \
     -d '{
       "mode": "upsert",
       "results": [
         {"bib_number": 42, "position": 1, "finish_time": "00:45:32", "status": "FIN",
          "split_times": {"ss1": "00:15:32", "ss2": "00:14:28", "ss3": "00:15:32"}}
       ]
     }' \
     https://thehub.gravityseries.se/api/v1/events/42/results
```

---

## HTTPS

API:t fungerar över både HTTP och HTTPS. HTTPS rekommenderas men inte strikt krav – detta för att tidtagningsutrustning i fält inte alltid hanterar HTTPS-certifikat korrekt.

---

## Kontakt

Vid problem med API-nycklar eller åtkomst, kontakta administratören via TheHUB.
