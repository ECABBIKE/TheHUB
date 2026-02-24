# MEMORY.md — GravityTiming

> **Claude Code: Read this entire file before writing any code.**
> Single source of truth. All decisions, architecture, schema, protocols, UI specs.
> Do NOT deviate from decisions marked LOCKED.
> This project has ZERO connection to TheHUB codebase.

---

## 1. WHAT IS THIS

**GravityTiming** — Server-based timing system for Swedish gravity cycling competitions.

- **Owner**: JALLE (GravitySeries project lead)
- **Users**: Race organizers, funktionärer, speakers, riders, spectators
- **Scale**: 10–20 events/year, up to 500 riders/event, 15,000–36,000 punches/event
- **Replaces**: Neptron (cloud-locked) and SiTiming (Windows-only, license-locked)
- **Keeps**: All existing SPORTident AIR+ hardware

### NOT
- Not a desktop app. **Server + browser clients.**
- Not part of TheHUB. Completely separate project, repo, database.
- Not internet-dependent. Runs an entire race day on local WiFi only.

---

## 2. ARCHITECTURE — LOCKED

```
SPORTIDENT / ROC
         │
         ▼
  Timing Core (Python server)
 ───────────────────────────────
 Event log + Result engine + Sync
 ───────────────────────────────
    │           │            │
    ▼           ▼            ▼
 Admin UI    Displays     TheHUB/API
  (web)       (web)         (api)
```

### The core insight
GravityTiming is a **server with clients**, not a desktop app. Every screen — admin, finish line, speaker, OBS overlay, startstation — is a browser connecting to the same server. The server owns all state. Clients are disposable. Server can restart without losing data. Clients auto-reconnect.

### What the server does
- Receives punches from all sources (ROC HTTP, SIRAP TCP, USB serial, manual API)
- Deduplicates, resolves chip→BIB, calculates stage times and overall results
- Stores everything in SQLite (WAL mode, single file, never loses data)
- Exposes REST API for reads/writes
- Broadcasts live updates via WebSocket
- Serves all client HTML/JS/CSS

### What the clients do
- **Admin UI** (browser on master laptop): Import startlist, manage chips, create events, manual overrides. Connects via REST + WebSocket. Has write access.
- **Finish screens** (browser on tablets at each stage): Show results to riders. Read-only WebSocket.
- **Speaker dashboard** (browser on speaker laptop): Multi-stage overview + highlights. Read-only WebSocket.
- **OBS overlay** (browser source in OBS): Transparent HTML with animations. Read-only WebSocket.
- **Start station** (browser on tablet at start): Show start order, countdown. Read-only WebSocket.
- **TheHUB sync** (background process in server): Push results when internet available. Server-side only.

### Why this is right
| Problem with desktop-app model | How server-first solves it |
|---|---|
| GUI and backend share state → race conditions | All state in server + SQLite. Clients ask, server answers. |
| GUI is one of many clients but code treats it as center | All clients equal. Admin is just a client with write access. |
| Can't restart master during race | Server restarts → clients reconnect → SQLite has all data. |
| PyInstaller builds break across platforms | No builds. Just `python server.py`. Browser is the UI. |
| CustomTkinter dependency + platform quirks | Zero GUI dependencies. Standard HTML/CSS/JS. |

---

## 3. TECH STACK — LOCKED

| Component | Choice | Why |
|---|---|---|
| Language | **Python 3.11+** | Cross-platform, sportident-python lib, rapid dev |
| Web framework | **FastAPI** | Async, WebSocket native, auto-docs, modern |
| Database | **SQLite + WAL** | Single file, offline, no server process, concurrent reads |
| WebSocket | **FastAPI WebSocket** | Built-in, no extra dependency |
| Template/static | **Jinja2 + vanilla JS** | Server-rendered HTML, no build step, no npm |
| USB reader | **sportident-python** | Open source BSM8-USB serial protocol |
| HTTP client | **httpx** (async) | Async ROC polling, connection pooling |
| Packaging | **Single directory** | `python server.py` — no PyInstaller needed |

### Dependencies (requirements.txt)
```
fastapi>=0.110
uvicorn[standard]>=0.27
jinja2>=3.1
httpx>=0.27
```

Phase 3 additions:
```
sportident>=1.0     # USB chip readout
```

### No build step
Server runs directly: `python server.py` or `uvicorn server:app --host 0.0.0.0 --port 8080`
All clients open `http://{server-ip}:8080/{page}` in browser.
For "app-like" distribution: zip the directory, extract, run.

---

## 4. HARDWARE CONTEXT

GravitySeries owns all equipment. We build software to talk to it.

| Hardware | Function | Interface |
|---|---|---|
| **SIAC** | Timing chip worn by rider (wristband) | Contactless 50ms, USB readout |
| **BS11-BS** | Start beacon, 1.8m range | Config+ programming |
| **BS11-BL** | Finish beacon, 3.0m range | Config+ programming |
| **BSM8-USB** | USB chip reader (post-race) | Serial via sportident-python |
| **SRR Dongle** | Short-range radio receiver | Receives wireless SIAC punches |
| **ROC** | Raspberry Pi + 4G + SRR | Relays punches to roc.olresultat.se |

### Beacon control codes
```
Enduro:
  SS1: 11 (start), 12 (mål)
  SS2: 21 (start), 22 (mål)
  SSn: n*10+1 (start), n*10+2 (mål)

Downhill / XC / Dual Slalom:
  Start: 12
  Mellantid 1: 22
  Mellantid 2: 32
  Mellantid 3: 42
  Mål: 52
```
Any codes can be used — organizer sets them in Setup. Templates use the conventions above.

---

## 5. DATA FLOW

```
Rider passes beacon
├── SRR radio → ROC (RPi) ─┬→ roc.olresultat.se → Server polls HTTP
│                           └→ SIRAP TCP (local WiFi) → Server listens
└── Stored in SIAC memory → BSM8-USB → Server reads post-race
```

### Source priority
1. **USB readout** — Ground truth (facit). Chip memory is always correct.
2. **SIRAP** — Local WiFi TCP from ROC, ~instant, no internet.
3. **ROC API** — HTTP poll over internet, 1–5s latency.
4. **Manual** — REST API call from Admin UI.

### Offline guarantee
```
Internet dies → SIRAP still works, local screens still work ✓
WiFi also dies → ROC stores. SIAC stores. ✓
Post-race → USB reads all punches from SIAC → complete results ✓
Server restarts → SQLite has everything, clients reconnect ✓
```

### Clock Truth Policy — LOCKED

**Chip time is always truth.** The SIAC chip records punch_time at beacon contact. This time is the official race time.

| Source | Time origin | Accuracy | Role |
|--------|------------|----------|------|
| **SIAC chip** | Beacon timestamp (1/256s) | ±1ms | **Official time** |
| **ROC API** | Relayed SIAC time | Same as chip | Transport only |
| **SIRAP** | Relayed SIAC time | Same as chip | Transport only |
| **USB readout** | Stored SIAC time | Same as chip | Post-race facit |
| **Server received_at** | Server clock | Varies | Logging only, never used for results |

**Rules:**
1. `punch_time` in database = chip timestamp from SIAC. Never server clock.
2. ROC, SIRAP, USB all relay the same chip time — if a punch arrives from multiple sources, the **chip time is identical**.
3. `received_at` is server clock for audit/debug. Never affects results.
4. If two sources deliver different chip times for the same event (shouldn't happen), USB wins — it reads directly from SIAC memory.
5. No drift correction needed: all SIAC chips are time-synced at beacon programming. Beacons share the same SI time master.

**Multi-source reconciliation (Phase 2):**
When USB readout is available, compare with ROC/SIRAP data. USB is facit — replace any mismatched punch_time with USB value. Log discrepancies in audit_log.

---

## 6. SERVER STRUCTURE

```
gravity-timing/
├── MEMORY.md
├── ROADMAP.md
├── README.md
├── requirements.txt
├── server.py                 ← entry point (uvicorn)
├── core/
│   ├── __init__.py
│   ├── database.py           ← SQLite schema init + connection
│   ├── timing_engine.py      ← punch processing, dedup, calc, rankings
│   ├── roc_poller.py         ← async ROC HTTP polling
│   ├── sirap_listener.py     ← SIRAP TCP server (Phase 3)
│   ├── usb_reader.py         ← sportident-python (Phase 3)
│   └── hub_sync.py           ← TheHUB push (Phase 4)
├── api/
│   ├── __init__.py
│   ├── routes.py             ← REST endpoints
│   └── websocket.py          ← WebSocket manager + broadcast
├── web/
│   ├── templates/
│   │   ├── admin.html        ← full admin UI
│   │   ├── finish.html       ← finish line display
│   │   ├── speaker.html      ← speaker dashboard
│   │   ├── overlay.html      ← OBS transparent overlay
│   │   ├── start.html        ← start station display
│   │   └── standings.html    ← public standings
│   └── static/
│       ├── css/
│       ├── js/
│       │   ├── admin.js
│       │   ├── finish.js
│       │   ├── speaker.js
│       │   ├── overlay.js
│       │   └── ws-client.js  ← shared WebSocket reconnect logic
│       └── img/
├── tests/
│   └── test_data/
│       ├── real_punches_2026-02-20.csv
│       ├── sample_startlist.csv
│       └── sample_chipmapping.csv
└── data/                     ← SQLite DB lives here (gitignored)
```

---

## 7. REST API

All endpoints prefixed `/api/`. Admin endpoints require auth header (simple token, configured at server start).

### Events
```
GET    /api/events                    → list events
POST   /api/events                    → create event
GET    /api/events/{id}               → get event details
PUT    /api/events/{id}               → update event
POST   /api/events/{id}/activate      → set status=active, start ROC polling
POST   /api/events/{id}/finish        → set status=finished
```

### Entries (startlist)
```
GET    /api/events/{id}/entries       → list entries
POST   /api/events/{id}/entries       → add single entry
POST   /api/events/{id}/entries/import → import CSV (multipart)
DELETE /api/events/{id}/entries/{eid}  → remove entry
```

### Chip mapping
```
GET    /api/events/{id}/chips         → list mappings
POST   /api/events/{id}/chips         → add/update single mapping
POST   /api/events/{id}/chips/import  → import CSV (multipart)
DELETE /api/events/{id}/chips/{cid}   → remove mapping
```

### Punches
```
GET    /api/events/{id}/punches       → list (with filters: source, siac, control, dup)
POST   /api/events/{id}/punches       → manual punch entry
```

### Results
```
GET    /api/events/{id}/stages/{sid}/results  → stage results for a stage
GET    /api/events/{id}/overall               → overall results (with class filter)
GET    /api/events/{id}/export/csv            → download results CSV
```

### Event configuration (controls, stages, courses, classes)
```
GET/POST/PUT/DELETE  /api/events/{id}/controls
GET/POST/PUT/DELETE  /api/events/{id}/stages
GET/POST/PUT/DELETE  /api/events/{id}/courses
GET/POST/PUT/DELETE  /api/events/{id}/classes
```

### System
```
GET    /api/status                    → server health, active event, ROC status, punch count
```

---

## 8. WEBSOCKET PROTOCOL

Single WebSocket endpoint: `ws://{host}:8080/ws`

### Server → Client messages

**New punch processed:**
```json
{
    "type": "punch",
    "event_id": 1,
    "bib": 47,
    "name": "Erik Johansson",
    "class": "Herr Elite",
    "club": "Kungsholmen CK",
    "control_code": 132,
    "control_type": "finish",
    "punch_time": "2026-06-15 14:23:47",
    "source": "roc",
    "stage_result": {
        "stage_id": 3,
        "stage_name": "Mossvägen",
        "stage_number": 3,
        "elapsed": "03:24.7",
        "elapsed_seconds": 204.7,
        "position": 3,
        "behind": "+6.5",
        "is_leader": false,
        "is_new_leader": false
    },
    "overall": {
        "total": "12:47.3",
        "total_seconds": 767.3,
        "position": 2,
        "behind": "+6.5",
        "stages_completed": 3,
        "stages_total": 5
    }
}
```

**Standings update (periodic, every 5s when active):**
```json
{
    "type": "standings",
    "event_id": 1,
    "class": "Herr Elite",
    "standings": [
        {"position": 1, "bib": 12, "name": "M. Svensson", "total": "09:22.4", "behind": ""},
        {"position": 2, "bib": 47, "name": "E. Johansson", "total": "09:28.9", "behind": "+6.5"}
    ]
}
```

**Highlight (auto-generated for speaker):**
```json
{
    "type": "highlight",
    "event_id": 1,
    "category": "close_finish",
    "text": "#89 Lindberg 0.9s från ledaren på Stage 3!",
    "bib": 89,
    "stage_number": 3,
    "priority": "high"
}
```

**Stage status change:**
```json
{
    "type": "stage_status",
    "event_id": 1,
    "stage_id": 3,
    "stage_name": "Mossvägen",
    "status": "live",
    "riders_on_course": 12,
    "riders_finished": 47,
    "leader": {"bib": 12, "name": "M. Svensson", "elapsed": "03:18.2"}
}
```

### Client → Server messages (admin only)
```json
{"type": "subscribe", "channels": ["finish", "speaker", "overlay", "all"]}
```

---

## 9. ROC API — VERIFIED

### Endpoint
```
GET https://roc.olresultat.se/getpunches.asp?unitId={competition_id}&lastId={last_punch_id}
```

### Response
Plaintext, semicolon-separated, one line per punch, no header.
```
90831;1;8003097;2026-02-20 19:00:39
```
Fields: PunchID; ControlCode; SIAC; Timestamp (YYYY-MM-DD HH:MM:SS)

- Empty response (0 bytes, HTTP 200) = no new punches
- `lastId=0` = all, then use highest ID for pagination
- Data retention: 6 months
- Encoding: UTF-8, `\r\n`
- Test competition: **2256**

### ROC also supports
- **SIRAP**: TCP to local IP:port (MeOS protocol)
- **Webhook**: HTTP POST per punch
- **WiFi per competition**: Configurable SSID

---

## 10. CHIP-TO-BIB MAPPING

**SIAC numbers are NEVER in startlists.** Separate translation file, managed on-site.

### CSV format
```
BIB;SIAC1;SIAC2
1;8003097;8003098
2;8506238;
```

### Dual chip logic
Many riders have TWO SIACs (primary wrist + backup on bike). Both map to same BIB.

**Rule: Primary wins, secondary is backup.**
```
1. Both chips have start+finish → use primary chip timestamps
2. Primary missing start OR finish → fill from secondary (cross-chip OK)
3. Only secondary exists → use secondary
```

**Cross-chip scenario (chip dies mid-stage):**
```
Start: SIAC 8003097 (primary) stamps → OK
... primary battery dies ...
Finish: SIAC 8003098 (secondary) stamps → OK
Result: Primary start + Secondary finish = valid stage time
```

### Dedup (updated for dual chips)
```
Same BIB (via chip_mapping) + same control_code + within 2 seconds = duplicate
```
Store duplicates but mark `is_duplicate=1`. First arrival wins. USB overrides ROC/SIRAP.

---

## 11. SQLITE SCHEMA

```sql
PRAGMA journal_mode=WAL;
PRAGMA foreign_keys=ON;

CREATE TABLE events (
    id                  INTEGER PRIMARY KEY AUTOINCREMENT,
    name                TEXT NOT NULL,
    date                TEXT NOT NULL,
    location            TEXT,
    format              TEXT NOT NULL DEFAULT 'enduro',
    stage_order         TEXT NOT NULL DEFAULT 'fixed',
    stage_repeats       INTEGER NOT NULL DEFAULT 1,
    best_of             INTEGER,
    time_precision      TEXT NOT NULL DEFAULT 'seconds',
    status              TEXT NOT NULL DEFAULT 'setup',
    roc_competition_id  TEXT,
    dual_slalom_window  REAL,          -- seconds, for dual slalom start grouping
    created_at          TEXT DEFAULT (datetime('now')),
    updated_at          TEXT DEFAULT (datetime('now'))
);

CREATE TABLE controls (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    event_id    INTEGER NOT NULL REFERENCES events(id),
    code        INTEGER NOT NULL,
    name        TEXT NOT NULL,
    type        TEXT NOT NULL,
    UNIQUE(event_id, code)
);

CREATE TABLE stages (
    id                  INTEGER PRIMARY KEY AUTOINCREMENT,
    event_id            INTEGER NOT NULL REFERENCES events(id),
    stage_number        INTEGER NOT NULL,
    name                TEXT NOT NULL,
    start_control_id    INTEGER NOT NULL REFERENCES controls(id),
    finish_control_id   INTEGER NOT NULL REFERENCES controls(id),
    is_timed            INTEGER NOT NULL DEFAULT 1,
    runs_to_count       INTEGER NOT NULL DEFAULT 1,   -- best N attempts count
    max_runs            INTEGER,                       -- NULL = unlimited
    UNIQUE(event_id, stage_number)
);

CREATE TABLE stage_splits (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    stage_id    INTEGER NOT NULL REFERENCES stages(id),
    split_order INTEGER NOT NULL,
    control_id  INTEGER NOT NULL REFERENCES controls(id)
);

CREATE TABLE courses (
    id                INTEGER PRIMARY KEY AUTOINCREMENT,
    event_id          INTEGER NOT NULL REFERENCES events(id),
    name              TEXT NOT NULL,
    laps              INTEGER DEFAULT 1,
    stages_any_order  INTEGER NOT NULL DEFAULT 0,  -- 1 = free order enduro
    allow_repeat      INTEGER NOT NULL DEFAULT 0   -- 1 = stages can be repeated
);

CREATE TABLE course_stages (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    course_id   INTEGER NOT NULL REFERENCES courses(id),
    stage_id    INTEGER NOT NULL REFERENCES stages(id),
    stage_order INTEGER NOT NULL
);

CREATE TABLE classes (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    event_id        INTEGER NOT NULL REFERENCES events(id),
    course_id       INTEGER NOT NULL REFERENCES courses(id),
    name            TEXT NOT NULL,
    mass_start_time TEXT
);

CREATE TABLE entries (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    event_id    INTEGER NOT NULL REFERENCES events(id),
    bib         INTEGER NOT NULL,
    first_name  TEXT NOT NULL,
    last_name   TEXT NOT NULL,
    club        TEXT,
    class_id    INTEGER NOT NULL REFERENCES classes(id),
    status      TEXT NOT NULL DEFAULT 'registered',
    UNIQUE(event_id, bib)
);

CREATE TABLE chip_mapping (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    event_id    INTEGER NOT NULL REFERENCES events(id),
    bib         INTEGER NOT NULL,
    siac        INTEGER NOT NULL,
    is_primary  INTEGER NOT NULL DEFAULT 1,
    UNIQUE(event_id, siac)
);

CREATE TABLE punches (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    event_id        INTEGER NOT NULL REFERENCES events(id),
    siac            INTEGER NOT NULL,
    control_code    INTEGER NOT NULL,
    punch_time      TEXT NOT NULL,
    source          TEXT NOT NULL DEFAULT 'roc',
    roc_punch_id    INTEGER,
    is_duplicate    INTEGER NOT NULL DEFAULT 0,
    received_at     TEXT DEFAULT (datetime('now'))
);

CREATE TABLE stage_results (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    event_id        INTEGER NOT NULL REFERENCES events(id),
    entry_id        INTEGER NOT NULL REFERENCES entries(id),
    stage_id        INTEGER NOT NULL REFERENCES stages(id),
    start_punch_id  INTEGER REFERENCES punches(id),
    finish_punch_id INTEGER REFERENCES punches(id),
    start_time      TEXT,
    finish_time     TEXT,
    elapsed_seconds REAL,
    attempt         INTEGER NOT NULL DEFAULT 1,
    status          TEXT NOT NULL DEFAULT 'pending',
    penalty_seconds REAL DEFAULT 0,
    UNIQUE(event_id, entry_id, stage_id, attempt)
);

CREATE TABLE overall_results (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    event_id        INTEGER NOT NULL REFERENCES events(id),
    entry_id        INTEGER NOT NULL REFERENCES entries(id),
    total_seconds   REAL,
    position        INTEGER,
    time_behind     REAL,
    status          TEXT NOT NULL DEFAULT 'pending',
    updated_at      TEXT DEFAULT (datetime('now')),
    UNIQUE(event_id, entry_id)
);

CREATE TABLE sync_queue (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    event_id    INTEGER NOT NULL,
    data_type   TEXT NOT NULL,
    data_json   TEXT NOT NULL,
    synced      INTEGER NOT NULL DEFAULT 0,
    created_at  TEXT DEFAULT (datetime('now')),
    synced_at   TEXT
);

CREATE TABLE event_templates (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    name        TEXT NOT NULL UNIQUE,
    data_json   TEXT NOT NULL,
    created_at  TEXT DEFAULT (datetime('now'))
);

CREATE INDEX idx_punches_event_siac ON punches(event_id, siac, control_code);
CREATE INDEX idx_punches_event_code ON punches(event_id, control_code);
CREATE INDEX idx_chip_siac ON chip_mapping(event_id, siac);
CREATE INDEX idx_chip_bib ON chip_mapping(event_id, bib);
CREATE INDEX idx_entries_bib ON entries(event_id, bib);
CREATE INDEX idx_stage_results_entry ON stage_results(event_id, entry_id);
```

---

## 12. RESULT CALCULATION

### Enduro
```python
# Per timed stage: get best `runs_to_count` attempts (sorted by elapsed ASC)
# Sum across all timed stages for overall total
total = sum(stage_counting_time for stage in timed_stages)
```
Transport NOT timed. Only `is_timed=1` stages count. Multi-run aware via `runs_to_count`.

### Downhill
```python
total = min(attempt.elapsed_seconds for attempt in attempts if attempt.status == 'ok')
```
Best time from `max_runs` attempts.

### XC
```python
total = finish_time - start_time  # wall clock, mass/wave start
```

### Festival / Free Runs
```python
# Per stage: unlimited attempts, best `runs_to_count` count
# stage_order = 'free', allow_repeat = 1
total = sum(best_N_per_stage)
```

### Dual Slalom
```python
# Start grouping: riders within `dual_slalom_window` seconds get same start_time
# Then: best time wins (same as downhill)
total = min(attempt.elapsed_seconds for attempt in attempts)
```

### Multi-run logic
- `runs_to_count=1`: best single attempt per stage (default)
- `runs_to_count=N`: best N attempts per stage, summed
- `max_runs=NULL`: unlimited attempts
- `max_runs=N`: maximum N attempts allowed

### Ranking
Per class. `status='ok'` ranked by total ASC. DNS/DNF/DSQ unranked, listed after.

### Event formats
- **Enduro**: 3–8 stages, fixed/free order, individual/wave start, best-of-N variant
- **Downhill**: 1 stage + splits, 1–N runs, hundredths precision
- **XC**: Lap course, N laps per class, mass/wave start
- **Dual Slalom**: Head-to-head, beacon mini-mass start with grouping window
- **Festival**: Free order, unlimited runs, best N count

---

## 13. VOLUME & PERFORMANCE

### Realistic punch volumes
```
Enduro 300 riders × 2 chips × 5 stages × 2 controls × 2-3 receivers = 15,000-20,000
Free order + best-of = up to 36,000
```

### Performance requirements
| Operation | Target |
|---|---|
| Dedup lookup per punch | <10ms |
| Stage result calc per rider | <50ms |
| Overall calc per class | <100ms |
| WebSocket broadcast | <200ms after calc |
| End-to-end beacon→screen (SIRAP) | <2 seconds |
| REST API response | <100ms |

---

## 14. CLIENT PAGES — DESIGN

### Theme: GravitySeries dark
```
bg_primary:     #171717
bg_surface:     #1e1e1e / #262626
text_primary:   #F9F9F9
text_muted:     #7A7A7A
accent_green:   #61CE70
danger_red:     #ef4444
warning_yellow: #FFE009
info_blue:      #004a98
font:           system-ui, -apple-system, sans-serif
mono:           ui-monospace, monospace
```

### Admin UI (`/admin`)
Full event management in browser. Tabs or sidebar navigation:
- **Live**: Big last-punch display + scrolling feed
- **Stages**: Select stage + class → results table
- **Overall**: Class filter → total standings
- **Entries**: Import/manage startlist + chip mapping
- **Punches**: Raw punch log with filters
- **Setup**: Event config (controls, stages, courses, classes)
- **Status bar**: Active event, punch count, ROC status indicator, connection status

### Finish screen (`/finish?stage={n}`)
Tablet at each stage finish, fullscreen Chrome.
```
┌─────────────────────────────────────────┐
│          🏁 STAGE 3 — MOSSVÄGEN         │
│                                          │
│     #47  ERIK JOHANSSON                  │
│          03:24.7                         │
│       🥉 3:e plats  (+6.5s)            │
│                                          │
│     Totalt: 12:47.3 — 2:a totalt       │
│                                          │
│  Senaste:                                │
│  #89 K.Lindberg  03:19.1  2:a  +0.9s   │
│  #12 A.Nilsson   03:41.2  8:a  +23.0s  │
└─────────────────────────────────────────┘
```
Pop-up animation on new finish. Auto-scroll recent results. Large readable text.

### Speaker dashboard (`/speaker`)
Multi-panel overview:
```
┌─ 🎤 SPEAKER ─────────────────────────────────────────┐
│  STAGE 1 ✅  STAGE 2 ✅  STAGE 3 🔴 LIVE  STAGE 4 ⏳ │
│                                                        │
│  SENASTE MÅLGÅNGAR (Stage 3):                          │
│  #47 E.Johansson  3:24.7   3:a  +6.5s                │
│  #89 K.Lindberg   3:19.1   2:a  +0.9s  ← TÄTT!      │
│                                                        │
│  ⚡ HIGHLIGHT: Lindberg 0.9s från ledaren!            │
│                                                        │
│  📊 TOTALT:                                           │
│  1. M.Svensson  9:22.4                                │
│  2. E.Johansson 9:28.9  (+6.5s)                      │
│                                                        │
│  🚴 PÅ BANAN: 12 åkare  Nästa: #23 F.Berg 14:32     │
└────────────────────────────────────────────────────────┘
```

Auto-generated highlights: new leader, close finish (<2s), big position gain, fastest stage time.
Riders on course with running time since start.

### OBS overlay (`/overlay`)
Transparent background. Browser Source in OBS/vMix.
- Pop-up on finish (slide in, hold 5s, fade out)
- Running clock for rider on course
- Ticker bar at bottom
- GravitySeries branding

### Start station (`/start?stage={n}`)
Tablet at stage start:
- Current start order
- Countdown to next rider
- Previous stage results for waiting riders

### Public standings (`/standings`)
Mobile-friendly responsive page. Class filter, search, auto-refresh.

---

## 15. NETWORK SETUP

### Single venue (DH, small Enduro)
```
WiFi router "Gravity5G"
├── Server laptop: 192.168.1.100:8080
├── Finish tablets: /finish?stage=N (fullscreen Chrome)
├── Speaker laptop: /speaker
├── OBS laptop: /overlay (Browser Source)
└── 4G router → internet (optional, for TheHUB sync)
```

### Distributed Enduro (stages km apart)
```
Tailscale VPN mesh (free, zero config, works behind any NAT)

Stage 1 (mountain top)     Stage 2 (forest)      Stage 3 (valley)
├── Laptop or tablet       ├── Laptop or tablet   ├── Laptop or tablet
├── BSM8/ROC              ├── BSM8/ROC           ├── BSM8/ROC
├── 4G modem              ├── 4G modem           ├── 4G modem
├── /finish?stage=1        ├── /finish?stage=2    ├── /finish?stage=3
│                          │                       │
└── All VPN'd ─────────────┴───────────────────────┘
                           │
                    Server (race center or any node)
                    Accessible to all via VPN
```

Two modes:
- **Good signal**: All real-time, <2s latency
- **Bad signal (Swedish forest reality)**: Each station shows local results. Server queues missed punches. Burst-sync on reconnect. No data lost.

---

## 16. THEHUB SYNC (Optional, Phase 4)

Background task in server. Queue-based. Offline → burst on reconnect.
- Before: GET startlist from TheHUB
- During: POST live punches/results
- After: POST final results

NOT required for operation.

---

## 17. SPORTIDENT DETAILS

### Config+ beacon programming
- Operating Mode: Beacon Control → Timing Mode
- Operating Time: 12h (set race morning)
- Clock sync: Night before race (CRITICAL for timing accuracy)

### SIAC registration flow (race day)
1. Battery Test → 2. Clear → 3. Check (activates contactless) → 4. Give to rider, right wrist
5. Verify: no GPS watch on same arm (RF interference)

### O-Lynx metal box trick
Place beacon in metal box at registration → limits range → rider swipes SIAC → verifies contactless works AND shows name/class on screen.

---

## 18. TEST DATA

44 real punches from competition 2256 (2026-02-20):
- 8 SIACs: 8003092, 8003097, 8307818, 8307870, 8503159, 8503164, 8504104, 8506238
- 2 controls: 1 (start), 22 (finish)
- 6 duplicates, 8 stale (~18:12 timestamps = old chip memory)
- Valid race times: 20s to 5:36

Files in `tests/test_data/`.

---

## 19. CONVENTIONS

- **Code**: English
- **UI text**: Swedish
- **CSV separator**: `;`
- **Encoding**: UTF-8
- **DB timestamps**: `YYYY-MM-DD HH:MM:SS`

| English | Swedish (UI) |
|---|---|
| Event | Tävling |
| Stage | Stage |
| Rider | Åkare |
| Class | Klass |
| BIB | Startnummer |
| Punch | Stämpling |
| Result | Resultat |
| Standings | Ställning |
| Course | Bana |
| Control | Kontroll |
| Club | Klubb |
| DNS | Ej start |
| DNF | Ej mål |
| DSQ | Diskvalificerad |

---

## 20. EDGE CASES

### Chip dies mid-race
Funktionär adds new SIAC as secondary in Admin UI → all future punches map to same BIB.

### Chip not cleared
Timestamps before event start filtered automatically.

### USB vs ROC conflict
USB (chip memory) always wins. ROC punch not in chip memory → flag as uncertain.

### Free order Enduro
Punches recorded regardless of order. Results calculated per stage independently.

### Mass/wave start
`mass_start_time` per class. Finish - mass_start = result.

### Server restart mid-race
SQLite has all data. ROC poller resumes from last punch ID. Clients reconnect. Zero data loss.
