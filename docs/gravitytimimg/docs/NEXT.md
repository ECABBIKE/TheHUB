# NEXT.md — Kritiska ändringar innan första skarpa tävling

> **Claude Code: Läs hela denna fil. Implementera i ordning. Testa varje steg.**
> Dessa ändringar är datamodell-regler, inte ny funktionalitet.
> Befintlig arkitektur är korrekt — ändra inget i server.py, routes, websocket, klienter.
> Allt arbete sker i core/timing_engine.py, core/database.py, och tests/.

---

## 1. RUNS: pending → valid → superseded

### Problemet
`_update_stage_result` går direkt från punch till `status='ok'`.
I verkligheten med 20,000 stämplingar, chipbyten och ROC-resends ger det spöktider.

### Gör detta

Lägg till kolumn i stage_results:
```sql
ALTER TABLE stage_results ADD COLUMN run_state TEXT NOT NULL DEFAULT 'valid';
-- Möjliga värden: 'pending', 'valid', 'superseded'
```

Ändra flödet:
```
Punch arrives
  → ingest_punch() (oförändrad — dedup, spara)
  → _process_punch() skapar stage_result med run_state='pending'
  → _finalize_result() sätter run_state='valid' NÄR start+finish finns och elapsed >= 0
  → Om USB-data senare ger bättre punch → gammal run sätts till 'superseded', ny skapas
```

Resultatberäkning (calculate_overall_results) filtrerar: `WHERE run_state='valid' AND status='ok'`

### Testa
- Skapa punch med bara start → run_state='pending'
- Lägg till finish → run_state='valid'
- Skicka USB-punch för samma kontroll → gammal run='superseded', ny='valid'
- `recalculate_all()` ger identiskt resultat före och efter

---

## 2. RaceDayState → SQLite

### Problemet
`ingest_paused` och `standings_frozen` i routes.py lever i minne. Server omstart = borta.

### Gör detta

Lägg till tabell:
```sql
CREATE TABLE IF NOT EXISTS settings (
    key   TEXT PRIMARY KEY,
    value TEXT NOT NULL
);
```

Flytta all RaceDayState-logik till database.py:
```python
def get_setting(conn, key, default=""):
    row = conn.execute("SELECT value FROM settings WHERE key=?", (key,)).fetchone()
    return row["value"] if row else default

def set_setting(conn, key, value):
    conn.execute("INSERT OR REPLACE INTO settings (key, value) VALUES (?, ?)", (key, value))
    conn.commit()
```

Ersätt `race_state.ingest_paused` med `get_setting(conn, "ingest_paused", "false") == "true"`.
Ersätt `race_state.standings_frozen` med `get_setting(conn, "standings_frozen", "false") == "true"`.

Ta bort `RaceDayState`-klassen.

### Testa
- Sätt ingest_paused=true, starta om servern, verifiera att den fortfarande är pausad

---

## 3. Source priority

### Problemet
Dedup hanterar duplikater men inte source-override. USB-data post-race kan ha andra tider än ROC.

### Gör detta

Definiera prioritet (lägg till i punches-logik):
```
usb = 1 (högst — chip memory är facit)
sirap = 2
roc = 3
manual = 4 (lägst)
```

Lägg till i `_update_stage_result`:
```python
SOURCE_PRIORITY = {"usb": 1, "sirap": 2, "roc": 3, "manual": 4}

# Innan en stage_result uppdateras, kolla om befintlig punch har högre prio
existing_source = conn.execute(
    "SELECT source FROM punches WHERE id=?", (existing_punch_id,)
).fetchone()

new_source = conn.execute(
    "SELECT source FROM punches WHERE id=?", (new_punch_id,)
).fetchone()

# Lägre siffra = högre prio. Högre prio vinner.
if SOURCE_PRIORITY.get(existing_source, 99) < SOURCE_PRIORITY.get(new_source, 99):
    return  # Befintlig punch har högre prio, behåll den
```

När USB-import sker:
- Alla USB-punches har `source='usb'`
- Om en USB-punch matchar samma BIB+kontroll som en befintlig ROC-punch:
  - USB-punchen ersätter ROC-punchen i stage_result
  - Gammal stage_result sätts till `run_state='superseded'`
  - Ny stage_result skapas med USB-data, `run_state='valid'`

### Testa
- Importera ROC-data → stage_result skapas
- Importera USB-data för samma åkare/stage → stage_result uppdateras med USB-tid
- Verifiera att USB-tid är den som gäller i overall

---

## 4. Recompute-garanti

### Problemet
`recalculate_all()` finns men det finns ingen garanti att den ger identiskt resultat varje gång.

### Gör detta

Lägg till endpoint (redan finns `POST /api/admin/recompute` — verifiera att den fungerar korrekt):

```python
# I recalculate_all():
# 1. Spara snapshot av nuvarande overall_results
# 2. Radera stage_results + overall_results
# 3. Replaya alla punches (WHERE is_duplicate=0) i tidsordning
# 4. Jämför med snapshot
# 5. Logga diff om det finns en
```

Lägg till test:
```python
def test_recompute_idempotent():
    """Kör recalculate_all() två gånger. Resultat ska vara identiska."""
    # Setup: importera punches, beräkna
    # Spara resultat
    # Kör recalculate_all()
    # Jämför — EXAKT samma positioner, tider, status
    # Kör recalculate_all() IGEN
    # Jämför — fortfarande identiskt
```

### Testa
- Importera 44 test-punches
- Kör recalculate_all() 3 gånger
- Varje gång: identiska stage_results och overall_results

---

## 5. Sync journal

### Problemet
`sync_queue` finns i schemat men har ingen logik. Utan event-journal divergerar lokal ≠ TheHUB.

### Gör detta

Ändra sync_queue till en event journal:
```sql
-- Behåll sync_queue men använd den som outbound event log
-- Varje mutation skapar en journal-entry:

INSERT INTO sync_queue (event_id, data_type, data_json)
VALUES (?, 'run_created', '{"entry_id": 47, "stage_id": 3, "elapsed": 204.7}');

INSERT INTO sync_queue (event_id, data_type, data_json)
VALUES (?, 'run_superseded', '{"old_run_id": 12, "reason": "usb_override"}');

INSERT INTO sync_queue (event_id, data_type, data_json)
VALUES (?, 'chip_changed', '{"bib": 47, "old_siac": 8003097, "new_siac": 9001234}');

INSERT INTO sync_queue (event_id, data_type, data_json)
VALUES (?, 'status_changed', '{"bib": 47, "new_status": "dsq", "reason": "course cut"}');

INSERT INTO sync_queue (event_id, data_type, data_json)
VALUES (?, 'penalty_added', '{"bib": 47, "stage_id": 3, "seconds": 30, "reason": "missed gate"}');
```

Skapa helper:
```python
def journal_event(conn, event_id, data_type, data):
    conn.execute(
        "INSERT INTO sync_queue (event_id, data_type, data_json) VALUES (?, ?, ?)",
        (event_id, data_type, json.dumps(data))
    )
    conn.commit()
```

Anropa `journal_event()` från:
- `_finalize_result()` → `run_created`
- Source override → `run_superseded`
- Chip mapping ändring → `chip_changed`
- Status-ändring (DNS/DNF/DSQ) → `status_changed`
- Penalty → `penalty_added`
- Manuell punch → `manual_punch`

TheHUB-sync (Phase 3) läser sedan journalen: `WHERE synced=0 ORDER BY id ASC`

### Testa
- Kör ett komplett event med 44 punches
- Verifiera att sync_queue innehåller alla relevanta events
- Varje run_created har korrekt data
- Varje DSQ/DNF har audit trail

---

## ORDNING

Implementera i exakt denna ordning:
1. Settings-tabell + flytta RaceDayState (enklast, bryter minst)
2. Source priority-konstanter (definition, ingen logik-ändring ännu)
3. run_state-kolumn + pending/valid/superseded-flöde (största ändringen)
4. Source priority-logik kopplad till run_state
5. Recompute-idempotens-test
6. Sync journal

Kör ALLA befintliga tester efter varje steg. Inget får gå sönder.
