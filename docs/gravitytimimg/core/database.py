"""
database.py — SQLite schema init, migration, and CRUD operations.

Single-file database with WAL mode for concurrent reads.
Schema from MEMORY.md §7, extended with multi-run, dual slalom, templates.
"""

from __future__ import annotations

import json
import sqlite3
from pathlib import Path
from typing import Optional

DB_DIR = Path(__file__).parent.parent / "data"
DB_NAME = "gravitytiming.db"


def get_db_path() -> Path:
    DB_DIR.mkdir(parents=True, exist_ok=True)
    return DB_DIR / DB_NAME


def get_connection(db_path: Optional[Path] = None) -> sqlite3.Connection:
    """Return a new connection with WAL mode and foreign keys enabled."""
    if db_path is None:
        db_path = get_db_path()
    conn = sqlite3.connect(str(db_path), timeout=10)
    conn.row_factory = sqlite3.Row
    conn.execute("PRAGMA journal_mode=WAL")
    conn.execute("PRAGMA foreign_keys=ON")
    return conn


SCHEMA_SQL = """
CREATE TABLE IF NOT EXISTS events (
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
    dual_slalom_window  REAL,
    created_at          TEXT DEFAULT (datetime('now')),
    updated_at          TEXT DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS controls (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    event_id    INTEGER NOT NULL REFERENCES events(id),
    code        INTEGER NOT NULL,
    name        TEXT NOT NULL,
    type        TEXT NOT NULL,
    UNIQUE(event_id, code)
);

CREATE TABLE IF NOT EXISTS stages (
    id                  INTEGER PRIMARY KEY AUTOINCREMENT,
    event_id            INTEGER NOT NULL REFERENCES events(id),
    stage_number        INTEGER NOT NULL,
    name                TEXT NOT NULL,
    start_control_id    INTEGER NOT NULL REFERENCES controls(id),
    finish_control_id   INTEGER NOT NULL REFERENCES controls(id),
    is_timed            INTEGER NOT NULL DEFAULT 1,
    runs_to_count       INTEGER NOT NULL DEFAULT 1,
    max_runs            INTEGER,
    UNIQUE(event_id, stage_number)
);

CREATE TABLE IF NOT EXISTS stage_splits (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    stage_id    INTEGER NOT NULL REFERENCES stages(id),
    split_order INTEGER NOT NULL,
    control_id  INTEGER NOT NULL REFERENCES controls(id)
);

CREATE TABLE IF NOT EXISTS courses (
    id                INTEGER PRIMARY KEY AUTOINCREMENT,
    event_id          INTEGER NOT NULL REFERENCES events(id),
    name              TEXT NOT NULL,
    laps              INTEGER DEFAULT 1,
    stages_any_order  INTEGER NOT NULL DEFAULT 0,
    allow_repeat      INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE IF NOT EXISTS course_stages (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    course_id   INTEGER NOT NULL REFERENCES courses(id),
    stage_id    INTEGER NOT NULL REFERENCES stages(id),
    stage_order INTEGER NOT NULL
);

CREATE TABLE IF NOT EXISTS classes (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    event_id        INTEGER NOT NULL REFERENCES events(id),
    course_id       INTEGER NOT NULL REFERENCES courses(id),
    name            TEXT NOT NULL,
    mass_start_time TEXT
);

CREATE TABLE IF NOT EXISTS entries (
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

CREATE TABLE IF NOT EXISTS chip_mapping (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    event_id    INTEGER NOT NULL REFERENCES events(id),
    bib         INTEGER NOT NULL,
    siac        INTEGER NOT NULL,
    is_primary  INTEGER NOT NULL DEFAULT 1,
    UNIQUE(event_id, siac)
);

CREATE TABLE IF NOT EXISTS punches (
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

CREATE TABLE IF NOT EXISTS stage_results (
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
    run_state       TEXT NOT NULL DEFAULT 'valid',
    penalty_seconds REAL DEFAULT 0,
    UNIQUE(event_id, entry_id, stage_id, attempt)
);

CREATE TABLE IF NOT EXISTS overall_results (
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

CREATE TABLE IF NOT EXISTS sync_queue (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    event_id    INTEGER NOT NULL,
    data_type   TEXT NOT NULL,
    data_json   TEXT NOT NULL,
    synced      INTEGER NOT NULL DEFAULT 0,
    created_at  TEXT DEFAULT (datetime('now')),
    synced_at   TEXT
);

CREATE TABLE IF NOT EXISTS event_templates (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    name        TEXT NOT NULL UNIQUE,
    data_json   TEXT NOT NULL,
    created_at  TEXT DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS audit_log (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    event_id    INTEGER,
    action      TEXT NOT NULL,
    entity_type TEXT,
    entity_id   INTEGER,
    details     TEXT,
    before_val  TEXT,
    after_val   TEXT,
    source      TEXT DEFAULT 'admin',
    created_at  TEXT DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_audit_event ON audit_log(event_id, created_at);

CREATE INDEX IF NOT EXISTS idx_punches_event_siac ON punches(event_id, siac, control_code);
CREATE INDEX IF NOT EXISTS idx_punches_event_code ON punches(event_id, control_code);
CREATE INDEX IF NOT EXISTS idx_chip_siac ON chip_mapping(event_id, siac);
CREATE INDEX IF NOT EXISTS idx_chip_bib ON chip_mapping(event_id, bib);
CREATE INDEX IF NOT EXISTS idx_entries_bib ON entries(event_id, bib);
CREATE INDEX IF NOT EXISTS idx_stage_results_entry ON stage_results(event_id, entry_id);

CREATE TABLE IF NOT EXISTS settings (
    key   TEXT PRIMARY KEY,
    value TEXT NOT NULL
);
"""


def init_db(conn: sqlite3.Connection) -> None:
    """Create all tables and indexes if they don't exist."""
    conn.executescript(SCHEMA_SQL)


def migrate_db(conn: sqlite3.Connection) -> None:
    """Add new columns to existing tables (idempotent for upgrades)."""
    def _has_column(table: str, column: str) -> bool:
        cols = conn.execute(f"PRAGMA table_info({table})").fetchall()
        return any(c["name"] == column for c in cols)

    # stages: multi-run support
    if not _has_column("stages", "runs_to_count"):
        conn.execute("ALTER TABLE stages ADD COLUMN runs_to_count INTEGER NOT NULL DEFAULT 1")
    if not _has_column("stages", "max_runs"):
        conn.execute("ALTER TABLE stages ADD COLUMN max_runs INTEGER")

    # events: dual slalom
    if not _has_column("events", "dual_slalom_window"):
        conn.execute("ALTER TABLE events ADD COLUMN dual_slalom_window REAL")

    # courses: stage ordering
    if not _has_column("courses", "stages_any_order"):
        conn.execute("ALTER TABLE courses ADD COLUMN stages_any_order INTEGER NOT NULL DEFAULT 0")
    if not _has_column("courses", "allow_repeat"):
        conn.execute("ALTER TABLE courses ADD COLUMN allow_repeat INTEGER NOT NULL DEFAULT 0")

    # event_templates table
    conn.execute("""
        CREATE TABLE IF NOT EXISTS event_templates (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            name        TEXT NOT NULL UNIQUE,
            data_json   TEXT NOT NULL,
            created_at  TEXT DEFAULT (datetime('now'))
        )
    """)

    # audit_log table
    conn.execute("""
        CREATE TABLE IF NOT EXISTS audit_log (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            event_id    INTEGER,
            action      TEXT NOT NULL,
            entity_type TEXT,
            entity_id   INTEGER,
            details     TEXT,
            before_val  TEXT,
            after_val   TEXT,
            source      TEXT DEFAULT 'admin',
            created_at  TEXT DEFAULT (datetime('now'))
        )
    """)

    # settings table (for RaceDayState persistence)
    conn.execute("""
        CREATE TABLE IF NOT EXISTS settings (
            key   TEXT PRIMARY KEY,
            value TEXT NOT NULL
        )
    """)

    # stage_results: run_state for pending/valid/superseded flow
    if not _has_column("stage_results", "run_state"):
        conn.execute("ALTER TABLE stage_results ADD COLUMN run_state TEXT NOT NULL DEFAULT 'valid'")

    conn.commit()


# ======================================================================
# SETTINGS (key-value store for RaceDayState etc.)
# ======================================================================

def get_setting(conn: sqlite3.Connection, key: str, default: str = "") -> str:
    """Read a setting value from the database."""
    row = conn.execute("SELECT value FROM settings WHERE key=?", (key,)).fetchone()
    return row["value"] if row else default


def set_setting(conn: sqlite3.Connection, key: str, value: str) -> None:
    """Write a setting value to the database."""
    conn.execute(
        "INSERT OR REPLACE INTO settings (key, value) VALUES (?, ?)",
        (key, value)
    )
    conn.commit()


# ======================================================================
# SYNC JOURNAL (outbound event log for TheHUB sync)
# ======================================================================

def journal_event(conn: sqlite3.Connection, event_id: int,
                  data_type: str, data: dict) -> int:
    """Record an event in the sync journal (sync_queue).

    Each mutation creates a journal entry. TheHUB sync (Phase 3) reads:
        SELECT * FROM sync_queue WHERE synced=0 ORDER BY id ASC

    data_type values:
        run_created, run_superseded, chip_changed,
        status_changed, penalty_added, manual_punch

    Returns the journal entry id.
    """
    cur = conn.execute(
        "INSERT INTO sync_queue (event_id, data_type, data_json) VALUES (?, ?, ?)",
        (event_id, data_type, json.dumps(data))
    )
    conn.commit()
    return cur.lastrowid


def get_journal_events(conn: sqlite3.Connection, event_id: int,
                       unsynced_only: bool = True) -> list[dict]:
    """Read journal events for an event."""
    if unsynced_only:
        rows = conn.execute(
            "SELECT * FROM sync_queue WHERE event_id=? AND synced=0 ORDER BY id ASC",
            (event_id,)
        ).fetchall()
    else:
        rows = conn.execute(
            "SELECT * FROM sync_queue WHERE event_id=? ORDER BY id ASC",
            (event_id,)
        ).fetchall()
    return [dict(r) for r in rows]


# ======================================================================
# CREATE
# ======================================================================

def create_event(conn: sqlite3.Connection, name: str, date: str,
                 location: str = "", fmt: str = "enduro",
                 time_precision: str = "seconds",
                 roc_competition_id: str = "",
                 dual_slalom_window: Optional[float] = None) -> int:
    """Insert a new event and return its id."""
    cur = conn.execute(
        """INSERT INTO events (name, date, location, format, time_precision,
           roc_competition_id, dual_slalom_window)
           VALUES (?, ?, ?, ?, ?, ?, ?)""",
        (name, date, location, fmt, time_precision,
         roc_competition_id, dual_slalom_window)
    )
    conn.commit()
    return cur.lastrowid


def create_control(conn: sqlite3.Connection, event_id: int, code: int,
                   name: str, ctrl_type: str) -> int:
    cur = conn.execute(
        "INSERT INTO controls (event_id, code, name, type) VALUES (?, ?, ?, ?)",
        (event_id, code, name, ctrl_type)
    )
    conn.commit()
    return cur.lastrowid


def create_stage(conn: sqlite3.Connection, event_id: int, stage_number: int,
                 name: str, start_control_id: int, finish_control_id: int,
                 is_timed: int = 1, runs_to_count: int = 1,
                 max_runs: Optional[int] = None) -> int:
    cur = conn.execute(
        """INSERT INTO stages (event_id, stage_number, name, start_control_id,
           finish_control_id, is_timed, runs_to_count, max_runs)
           VALUES (?, ?, ?, ?, ?, ?, ?, ?)""",
        (event_id, stage_number, name, start_control_id, finish_control_id,
         is_timed, runs_to_count, max_runs)
    )
    conn.commit()
    return cur.lastrowid


def create_course(conn: sqlite3.Connection, event_id: int, name: str,
                  laps: int = 1, stages_any_order: int = 0,
                  allow_repeat: int = 0) -> int:
    cur = conn.execute(
        """INSERT INTO courses (event_id, name, laps, stages_any_order, allow_repeat)
           VALUES (?, ?, ?, ?, ?)""",
        (event_id, name, laps, stages_any_order, allow_repeat)
    )
    conn.commit()
    return cur.lastrowid


def link_course_stage(conn: sqlite3.Connection, course_id: int,
                      stage_id: int, stage_order: int) -> None:
    conn.execute(
        "INSERT INTO course_stages (course_id, stage_id, stage_order) VALUES (?, ?, ?)",
        (course_id, stage_id, stage_order)
    )
    conn.commit()


def unlink_course_stage(conn: sqlite3.Connection, course_id: int,
                        stage_id: int) -> None:
    conn.execute(
        "DELETE FROM course_stages WHERE course_id=? AND stage_id=?",
        (course_id, stage_id)
    )
    conn.commit()


def reorder_course_stages(conn: sqlite3.Connection, course_id: int,
                          stage_ids_in_order: list[int]) -> None:
    """Replace all course_stages for course_id with new ordering."""
    conn.execute("DELETE FROM course_stages WHERE course_id=?", (course_id,))
    for i, sid in enumerate(stage_ids_in_order, 1):
        conn.execute(
            "INSERT INTO course_stages (course_id, stage_id, stage_order) VALUES (?, ?, ?)",
            (course_id, sid, i)
        )
    conn.commit()


def create_class(conn: sqlite3.Connection, event_id: int, course_id: int,
                 name: str, mass_start_time: Optional[str] = None) -> int:
    cur = conn.execute(
        "INSERT INTO classes (event_id, course_id, name, mass_start_time) VALUES (?, ?, ?, ?)",
        (event_id, course_id, name, mass_start_time)
    )
    conn.commit()
    return cur.lastrowid


def create_entry(conn: sqlite3.Connection, event_id: int, bib: int,
                 first_name: str, last_name: str, club: str,
                 class_id: int) -> int:
    cur = conn.execute(
        """INSERT INTO entries (event_id, bib, first_name, last_name, club, class_id)
           VALUES (?, ?, ?, ?, ?, ?)""",
        (event_id, bib, first_name, last_name, club, class_id)
    )
    conn.commit()
    return cur.lastrowid


def create_chip_mapping(conn: sqlite3.Connection, event_id: int, bib: int,
                        siac: int, is_primary: int = 1) -> int:
    cur = conn.execute(
        "INSERT INTO chip_mapping (event_id, bib, siac, is_primary) VALUES (?, ?, ?, ?)",
        (event_id, bib, siac, is_primary)
    )
    conn.commit()
    return cur.lastrowid


# ======================================================================
# READ
# ======================================================================

def get_active_event(conn: sqlite3.Connection) -> Optional[sqlite3.Row]:
    """Return the most recent non-finished event, or None."""
    return conn.execute(
        "SELECT * FROM events WHERE status != 'finished' ORDER BY id DESC LIMIT 1"
    ).fetchone()


def get_all_events(conn: sqlite3.Connection,
                   include_finished: bool = True) -> list[sqlite3.Row]:
    if include_finished:
        return conn.execute("SELECT * FROM events ORDER BY id DESC").fetchall()
    return conn.execute(
        "SELECT * FROM events WHERE status != 'finished' ORDER BY id DESC"
    ).fetchall()


def get_event(conn: sqlite3.Connection, event_id: int) -> Optional[sqlite3.Row]:
    return conn.execute("SELECT * FROM events WHERE id=?", (event_id,)).fetchone()


def delete_event(conn: sqlite3.Connection, event_id: int) -> None:
    """Delete an event and ALL related data (controls, stages, courses, classes,
    entries, chips, punches, results, audit log, sync journal).

    Deletion order respects foreign-key constraints (children first):
    1. overall_results, stage_results  (→ entries, stages, punches)
    2. sync_queue, audit_log           (→ events only)
    3. punches                         (→ events)
    4. chip_mapping                    (→ events)
    5. entries                         (→ classes)
    6. classes                         (→ courses)
    7. course_stages                   (→ courses, stages — no event_id)
    8. stage_splits                     (→ stages, controls — no event_id)
    9. stages                          (→ controls)
    10. courses                        (→ events)
    11. controls                       (→ events)
    12. events
    """
    # 1. Results (depend on entries, stages, punches)
    conn.execute("DELETE FROM overall_results WHERE event_id=?", (event_id,))
    conn.execute("DELETE FROM stage_results WHERE event_id=?", (event_id,))

    # 2. Auxiliary tables
    conn.execute("DELETE FROM sync_queue WHERE event_id=?", (event_id,))
    conn.execute("DELETE FROM audit_log WHERE event_id=?", (event_id,))

    # 3-4. Punches & chip mapping
    conn.execute("DELETE FROM punches WHERE event_id=?", (event_id,))
    conn.execute("DELETE FROM chip_mapping WHERE event_id=?", (event_id,))

    # 5. Entries (depend on classes)
    conn.execute("DELETE FROM entries WHERE event_id=?", (event_id,))

    # 6. Classes (depend on courses)
    conn.execute("DELETE FROM classes WHERE event_id=?", (event_id,))

    # 7. course_stages (no event_id — subquery via courses)
    conn.execute(
        "DELETE FROM course_stages WHERE course_id IN "
        "(SELECT id FROM courses WHERE event_id=?)", (event_id,)
    )

    # 8. stage_splits (no event_id — subquery via stages)
    conn.execute(
        "DELETE FROM stage_splits WHERE stage_id IN "
        "(SELECT id FROM stages WHERE event_id=?)", (event_id,)
    )

    # 9-11. Stages, courses, controls
    conn.execute("DELETE FROM stages WHERE event_id=?", (event_id,))
    conn.execute("DELETE FROM courses WHERE event_id=?", (event_id,))
    conn.execute("DELETE FROM controls WHERE event_id=?", (event_id,))

    # 12. Event itself
    conn.execute("DELETE FROM events WHERE id=?", (event_id,))
    conn.commit()


def get_stages(conn: sqlite3.Connection, event_id: int) -> list[sqlite3.Row]:
    return conn.execute(
        "SELECT * FROM stages WHERE event_id=? ORDER BY stage_number", (event_id,)
    ).fetchall()


def get_classes(conn: sqlite3.Connection, event_id: int) -> list[sqlite3.Row]:
    return conn.execute(
        "SELECT * FROM classes WHERE event_id=? ORDER BY name", (event_id,)
    ).fetchall()


def get_entries(conn: sqlite3.Connection, event_id: int) -> list[sqlite3.Row]:
    return conn.execute(
        """SELECT e.*, c.name as class_name
           FROM entries e JOIN classes c ON e.class_id = c.id
           WHERE e.event_id=? ORDER BY e.bib""",
        (event_id,)
    ).fetchall()


def get_chip_mappings(conn: sqlite3.Connection, event_id: int) -> list[sqlite3.Row]:
    return conn.execute(
        "SELECT * FROM chip_mapping WHERE event_id=? ORDER BY bib", (event_id,)
    ).fetchall()


def get_punches(conn: sqlite3.Connection, event_id: int) -> list[sqlite3.Row]:
    return conn.execute(
        "SELECT * FROM punches WHERE event_id=? ORDER BY punch_time, id", (event_id,)
    ).fetchall()


def get_stage_results(conn: sqlite3.Connection, event_id: int,
                      stage_id: Optional[int] = None) -> list:
    if stage_id:
        return conn.execute(
            """SELECT sr.*, e.bib, e.first_name, e.last_name, e.club, cl.name as class_name
               FROM stage_results sr
               JOIN entries e ON sr.entry_id = e.id
               JOIN classes cl ON e.class_id = cl.id
               WHERE sr.event_id=? AND sr.stage_id=?
               ORDER BY sr.status ASC, sr.elapsed_seconds ASC""",
            (event_id, stage_id)
        ).fetchall()
    return conn.execute(
        """SELECT sr.*, e.bib, e.first_name, e.last_name, e.club, cl.name as class_name
           FROM stage_results sr
           JOIN entries e ON sr.entry_id = e.id
           JOIN classes cl ON e.class_id = cl.id
           WHERE sr.event_id=?
           ORDER BY sr.stage_id, sr.status ASC, sr.elapsed_seconds ASC""",
        (event_id,)
    ).fetchall()


def get_overall_results(conn: sqlite3.Connection,
                        event_id: int) -> list[sqlite3.Row]:
    return conn.execute(
        """SELECT o.*, e.bib, e.first_name, e.last_name, e.club, cl.name as class_name
           FROM overall_results o
           JOIN entries e ON o.entry_id = e.id
           JOIN classes cl ON e.class_id = cl.id
           WHERE o.event_id=?
           ORDER BY cl.name, o.status ASC, o.total_seconds ASC""",
        (event_id,)
    ).fetchall()


def get_controls(conn: sqlite3.Connection, event_id: int) -> list[sqlite3.Row]:
    return conn.execute(
        "SELECT * FROM controls WHERE event_id=? ORDER BY code", (event_id,)
    ).fetchall()


def get_courses(conn: sqlite3.Connection, event_id: int) -> list:
    return conn.execute(
        "SELECT * FROM courses WHERE event_id=? ORDER BY name", (event_id,)
    ).fetchall()


def get_course_stages(conn: sqlite3.Connection, course_id: int) -> list:
    return conn.execute(
        """SELECT cs.*, s.stage_number, s.name as stage_name
           FROM course_stages cs
           JOIN stages s ON cs.stage_id = s.id
           WHERE cs.course_id=?
           ORDER BY cs.stage_order""",
        (course_id,)
    ).fetchall()


# ======================================================================
# UPDATE
# ======================================================================

def update_event(conn: sqlite3.Connection, event_id: int, **kwargs) -> None:
    """Update event fields. Pass field=value pairs."""
    if not kwargs:
        return
    sets = ", ".join(f"{k}=?" for k in kwargs)
    vals = list(kwargs.values()) + [event_id]
    conn.execute(f"UPDATE events SET {sets}, updated_at=datetime('now') WHERE id=?", vals)
    conn.commit()


def update_control(conn: sqlite3.Connection, control_id: int, **kwargs) -> None:
    if not kwargs:
        return
    sets = ", ".join(f"{k}=?" for k in kwargs)
    vals = list(kwargs.values()) + [control_id]
    conn.execute(f"UPDATE controls SET {sets} WHERE id=?", vals)
    conn.commit()


def update_stage(conn: sqlite3.Connection, stage_id: int, **kwargs) -> None:
    if not kwargs:
        return
    sets = ", ".join(f"{k}=?" for k in kwargs)
    vals = list(kwargs.values()) + [stage_id]
    conn.execute(f"UPDATE stages SET {sets} WHERE id=?", vals)
    conn.commit()


def update_course(conn: sqlite3.Connection, course_id: int, **kwargs) -> None:
    if not kwargs:
        return
    sets = ", ".join(f"{k}=?" for k in kwargs)
    vals = list(kwargs.values()) + [course_id]
    conn.execute(f"UPDATE courses SET {sets} WHERE id=?", vals)
    conn.commit()


def update_class(conn: sqlite3.Connection, class_id: int, **kwargs) -> None:
    if not kwargs:
        return
    sets = ", ".join(f"{k}=?" for k in kwargs)
    vals = list(kwargs.values()) + [class_id]
    conn.execute(f"UPDATE classes SET {sets} WHERE id=?", vals)
    conn.commit()


# ======================================================================
# DELETE (individual, with safety checks)
# ======================================================================

def delete_control(conn: sqlite3.Connection, control_id: int) -> tuple[bool, str]:
    """Delete a single control. Refuses if referenced by a stage."""
    ref = conn.execute(
        "SELECT id FROM stages WHERE start_control_id=? OR finish_control_id=?",
        (control_id, control_id)
    ).fetchone()
    if ref:
        return False, "Kontrollen används av en stage — ta bort stagen först"
    conn.execute("DELETE FROM controls WHERE id=?", (control_id,))
    conn.commit()
    return True, ""


def delete_stage(conn: sqlite3.Connection, stage_id: int) -> tuple[bool, str]:
    """Delete a single stage. Refuses if stage_results exist."""
    ref = conn.execute(
        "SELECT id FROM stage_results WHERE stage_id=? LIMIT 1", (stage_id,)
    ).fetchone()
    if ref:
        return False, "Stagen har resultat — kan inte tas bort under aktiv tävling"
    # Remove from course_stages
    conn.execute("DELETE FROM course_stages WHERE stage_id=?", (stage_id,))
    conn.execute("DELETE FROM stages WHERE id=?", (stage_id,))
    conn.commit()
    return True, ""


def delete_course(conn: sqlite3.Connection, course_id: int) -> tuple[bool, str]:
    """Delete a single course. Refuses if classes reference it."""
    ref = conn.execute(
        "SELECT id FROM classes WHERE course_id=? LIMIT 1", (course_id,)
    ).fetchone()
    if ref:
        return False, "Banan har klasser kopplade — ta bort klasserna först"
    conn.execute("DELETE FROM course_stages WHERE course_id=?", (course_id,))
    conn.execute("DELETE FROM courses WHERE id=?", (course_id,))
    conn.commit()
    return True, ""


def delete_class(conn: sqlite3.Connection, class_id: int) -> tuple[bool, str]:
    """Delete a single class. Refuses if entries exist."""
    ref = conn.execute(
        "SELECT id FROM entries WHERE class_id=? LIMIT 1", (class_id,)
    ).fetchone()
    if ref:
        return False, "Klassen har anmälda åkare — kan inte tas bort"
    conn.execute("DELETE FROM classes WHERE id=?", (class_id,))
    conn.commit()
    return True, ""


# Bulk deletes (for clear_event_structure)

def delete_controls_for_event(conn: sqlite3.Connection, event_id: int) -> None:
    conn.execute("DELETE FROM controls WHERE event_id=?", (event_id,))
    conn.commit()


def delete_stages_for_event(conn: sqlite3.Connection, event_id: int) -> None:
    conn.execute("DELETE FROM stages WHERE event_id=?", (event_id,))
    conn.commit()


def delete_courses_for_event(conn: sqlite3.Connection, event_id: int) -> None:
    course_ids = [r["id"] for r in conn.execute(
        "SELECT id FROM courses WHERE event_id=?", (event_id,)
    ).fetchall()]
    for cid in course_ids:
        conn.execute("DELETE FROM course_stages WHERE course_id=?", (cid,))
    conn.execute("DELETE FROM courses WHERE event_id=?", (event_id,))
    conn.commit()


def delete_classes_for_event(conn: sqlite3.Connection, event_id: int) -> None:
    conn.execute("DELETE FROM classes WHERE event_id=?", (event_id,))
    conn.commit()


def clear_event_structure(conn: sqlite3.Connection, event_id: int) -> None:
    """Delete all controls, stages, courses, classes for an event."""
    delete_classes_for_event(conn, event_id)
    delete_courses_for_event(conn, event_id)
    delete_stages_for_event(conn, event_id)
    delete_controls_for_event(conn, event_id)


# ======================================================================
# TEMPLATES
# ======================================================================

def save_event_template(conn: sqlite3.Connection, name: str,
                        data_json: str) -> int:
    """Save or update a user template. Returns template id."""
    existing = conn.execute(
        "SELECT id FROM event_templates WHERE name=?", (name,)
    ).fetchone()
    if existing:
        conn.execute(
            "UPDATE event_templates SET data_json=?, created_at=datetime('now') WHERE id=?",
            (data_json, existing["id"])
        )
        conn.commit()
        return existing["id"]
    cur = conn.execute(
        "INSERT INTO event_templates (name, data_json) VALUES (?, ?)",
        (name, data_json)
    )
    conn.commit()
    return cur.lastrowid


def get_event_templates(conn: sqlite3.Connection) -> list[sqlite3.Row]:
    return conn.execute(
        "SELECT * FROM event_templates ORDER BY name"
    ).fetchall()


def delete_event_template(conn: sqlite3.Connection, template_id: int) -> None:
    conn.execute("DELETE FROM event_templates WHERE id=?", (template_id,))
    conn.commit()


# ======================================================================
# AUDIT LOG
# ======================================================================

def log_audit(conn: sqlite3.Connection, event_id: Optional[int],
              action: str, entity_type: str = "",
              entity_id: Optional[int] = None,
              details: str = "",
              before_val: str = "", after_val: str = "",
              source: str = "admin") -> int:
    """Log an admin action for audit trail."""
    cur = conn.execute(
        """INSERT INTO audit_log (event_id, action, entity_type, entity_id,
           details, before_val, after_val, source)
           VALUES (?, ?, ?, ?, ?, ?, ?, ?)""",
        (event_id, action, entity_type, entity_id,
         details, before_val, after_val, source)
    )
    conn.commit()
    return cur.lastrowid


def get_audit_log(conn: sqlite3.Connection, event_id: Optional[int] = None,
                  limit: int = 100) -> list[sqlite3.Row]:
    """Get audit log entries, newest first."""
    if event_id:
        return conn.execute(
            "SELECT * FROM audit_log WHERE event_id=? ORDER BY id DESC LIMIT ?",
            (event_id, limit)
        ).fetchall()
    return conn.execute(
        "SELECT * FROM audit_log ORDER BY id DESC LIMIT ?", (limit,)
    ).fetchall()


# ======================================================================
# BACKUP / RESTORE
# ======================================================================

def create_backup(label: str = "") -> Path:
    """Create a backup copy of the database. Returns path to backup file."""
    import shutil
    from datetime import datetime

    backup_dir = DB_DIR / "backups"
    backup_dir.mkdir(parents=True, exist_ok=True)

    timestamp = datetime.now().strftime("%Y%m%d_%H%M%S")
    suffix = f"_{label}" if label else ""
    backup_name = f"gravitytiming_{timestamp}{suffix}.db"
    backup_path = backup_dir / backup_name

    src = get_db_path()
    if src.exists():
        # Use SQLite backup API for consistent copy
        src_conn = sqlite3.connect(str(src))
        dst_conn = sqlite3.connect(str(backup_path))
        src_conn.backup(dst_conn)
        dst_conn.close()
        src_conn.close()

    return backup_path


def list_backups() -> list[dict]:
    """List all backup files, newest first."""
    backup_dir = DB_DIR / "backups"
    if not backup_dir.exists():
        return []

    backups = []
    for f in sorted(backup_dir.glob("gravitytiming_*.db"), reverse=True):
        stat = f.stat()
        backups.append({
            "filename": f.name,
            "path": str(f),
            "size_mb": round(stat.st_size / 1024 / 1024, 2),
            "created": f.name.split("_")[1] + "_" + f.name.split("_")[2].split(".")[0] if "_" in f.name else "",
        })
    return backups


def restore_backup(backup_filename: str) -> bool:
    """Restore database from a backup file. Returns True on success."""
    import shutil

    backup_dir = DB_DIR / "backups"
    backup_path = backup_dir / backup_filename
    if not backup_path.exists():
        return False

    dst = get_db_path()

    # First backup current state
    create_backup("pre_restore")

    # Copy backup over current db
    src_conn = sqlite3.connect(str(backup_path))
    dst_conn = sqlite3.connect(str(dst))
    src_conn.backup(dst_conn)
    dst_conn.close()
    src_conn.close()

    return True


# ======================================================================
# EVENT STRUCTURE EXPORT / IMPORT
# ======================================================================

def export_event_structure(conn: sqlite3.Connection, event_id: int) -> dict:
    """Export all controls, stages, courses, classes as a portable dict."""
    event = get_event(conn, event_id)
    if not event:
        return {}

    controls = get_controls(conn, event_id)
    stages = get_stages(conn, event_id)
    courses = get_courses(conn, event_id)
    classes = get_classes(conn, event_id)

    # Build control id→code lookup
    ctrl_id_to_code = {c["id"]: c["code"] for c in controls}
    # Build course id→name lookup
    course_id_to_name = {c["id"]: c["name"] for c in courses}

    structure = {
        "format": event["format"],
        "stage_order": event["stage_order"],
        "time_precision": event["time_precision"],
        "dual_slalom_window": event["dual_slalom_window"],
        "controls": [
            {"code": c["code"], "name": c["name"], "type": c["type"]}
            for c in controls
        ],
        "stages": [
            {
                "stage_number": s["stage_number"],
                "name": s["name"],
                "start_control_code": ctrl_id_to_code.get(s["start_control_id"]),
                "finish_control_code": ctrl_id_to_code.get(s["finish_control_id"]),
                "is_timed": s["is_timed"],
                "runs_to_count": s["runs_to_count"],
                "max_runs": s["max_runs"],
            }
            for s in stages
        ],
        "courses": [],
        "classes": [
            {
                "name": cl["name"],
                "course_name": course_id_to_name.get(cl["course_id"], ""),
                "mass_start_time": cl["mass_start_time"],
            }
            for cl in classes
        ],
    }

    for course in courses:
        cs = get_course_stages(conn, course["id"])
        structure["courses"].append({
            "name": course["name"],
            "laps": course["laps"],
            "stages_any_order": course["stages_any_order"],
            "allow_repeat": course["allow_repeat"],
            "stage_numbers": [s["stage_number"] for s in cs],
        })

    return structure


def import_event_structure(conn: sqlite3.Connection, event_id: int,
                           structure: dict) -> tuple[int, list[str]]:
    """Import structure into an event (must be in setup status).

    Clears existing structure first. Returns (items_created, warnings).
    """
    warnings: list[str] = []
    count = 0

    # Update event format settings
    fmt_fields = {}
    for key in ("format", "stage_order", "time_precision", "dual_slalom_window"):
        if key in structure and structure[key] is not None:
            fmt_fields[key] = structure[key]
    if fmt_fields:
        update_event(conn, event_id, **fmt_fields)

    # Clear existing
    clear_event_structure(conn, event_id)

    # Create controls
    code_to_id: dict[int, int] = {}
    for ctrl in structure.get("controls", []):
        try:
            cid = create_control(conn, event_id, ctrl["code"],
                                 ctrl["name"], ctrl["type"])
            code_to_id[ctrl["code"]] = cid
            count += 1
        except sqlite3.IntegrityError:
            warnings.append(f"Dubblett kontrollkod: {ctrl['code']}")

    # Create stages
    stage_num_to_id: dict[int, int] = {}
    for stg in structure.get("stages", []):
        start_code = stg["start_control_code"]
        finish_code = stg["finish_control_code"]
        if start_code not in code_to_id:
            warnings.append(f"Stage {stg['stage_number']}: startkontroll {start_code} saknas")
            continue
        if finish_code not in code_to_id:
            warnings.append(f"Stage {stg['stage_number']}: målkontroll {finish_code} saknas")
            continue
        try:
            sid = create_stage(
                conn, event_id, stg["stage_number"], stg["name"],
                code_to_id[start_code], code_to_id[finish_code],
                stg.get("is_timed", 1),
                stg.get("runs_to_count", 1),
                stg.get("max_runs")
            )
            stage_num_to_id[stg["stage_number"]] = sid
            count += 1
        except sqlite3.IntegrityError:
            warnings.append(f"Dubblett stage-nummer: {stg['stage_number']}")

    # Create courses and link stages
    course_name_to_id: dict[str, int] = {}
    for crs in structure.get("courses", []):
        try:
            cid = create_course(
                conn, event_id, crs["name"],
                crs.get("laps", 1),
                crs.get("stages_any_order", 0),
                crs.get("allow_repeat", 0)
            )
            course_name_to_id[crs["name"]] = cid
            count += 1

            for i, sn in enumerate(crs.get("stage_numbers", []), 1):
                if sn in stage_num_to_id:
                    link_course_stage(conn, cid, stage_num_to_id[sn], i)
                else:
                    warnings.append(f"Bana '{crs['name']}': stage {sn} saknas")
        except sqlite3.IntegrityError:
            warnings.append(f"Dubblett bana: {crs['name']}")

    # Create classes
    for cls in structure.get("classes", []):
        course_name = cls.get("course_name", "")
        if course_name not in course_name_to_id:
            # Try first course as fallback
            if course_name_to_id:
                first_name = list(course_name_to_id.keys())[0]
                warnings.append(
                    f"Klass '{cls['name']}': bana '{course_name}' saknas, "
                    f"använder '{first_name}'"
                )
                course_name = first_name
            else:
                warnings.append(f"Klass '{cls['name']}': ingen bana finns")
                continue
        try:
            create_class(conn, event_id, course_name_to_id[course_name],
                         cls["name"], cls.get("mass_start_time"))
            count += 1
        except sqlite3.IntegrityError:
            warnings.append(f"Dubblett klass: {cls['name']}")

    return count, warnings
