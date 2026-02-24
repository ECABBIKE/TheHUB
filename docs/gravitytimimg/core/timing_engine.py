"""
timing_engine.py — Punch processing, dedup, chip→BIB resolution,
stage result calculation (multi-run, dual slalom), overall results, and rankings.

All logic from MEMORY.md §6, §8, extended for multi-attempt and dual slalom.
"""

from __future__ import annotations

import sqlite3
import csv
from datetime import datetime, timedelta
from pathlib import Path
from typing import Optional, Tuple, List

from core.database import get_connection, get_db_path, journal_event

DEDUP_WINDOW_SECONDS = 2

# Source priority: lower number = higher priority.
# USB chip memory is ground truth; manual entry is lowest.
SOURCE_PRIORITY = {"usb": 1, "sirap": 2, "roc": 3, "manual": 4}


def format_elapsed(seconds: float | None, precision: str = "seconds") -> str:
    """Format elapsed seconds to human-readable string.

    precision: 'seconds' -> MM:SS, 'tenths' -> MM:SS.t, 'hundredths' -> MM:SS.cc
    """
    if seconds is None:
        return ""
    neg = seconds < 0
    s = abs(seconds)
    minutes = int(s // 60)
    remainder = s - minutes * 60

    if precision == "hundredths":
        text = f"{minutes}:{remainder:05.2f}"
    elif precision == "tenths":
        text = f"{minutes}:{remainder:04.1f}"
    else:
        whole = int(remainder)
        text = f"{minutes}:{whole:02d}"

    return f"-{text}" if neg else text


def format_time_behind(seconds: float | None, precision: str = "seconds") -> str:
    if seconds is None or seconds == 0:
        return ""
    return f"+{format_elapsed(seconds, precision)}"


def parse_timestamp(ts: str) -> datetime:
    """Parse 'YYYY-MM-DD HH:MM:SS' to datetime."""
    return datetime.strptime(ts, "%Y-%m-%d %H:%M:%S")


# ---------------------------------------------------------------------------
# Punch ingestion
# ---------------------------------------------------------------------------

def ingest_punch(conn: sqlite3.Connection, event_id: int, siac: int,
                 control_code: int, punch_time: str, source: str = "roc",
                 roc_punch_id: int | None = None) -> int | None:
    """Insert a punch, marking duplicates. Returns punch id or None if skipped."""
    is_dup = _check_duplicate(conn, event_id, siac, control_code, punch_time, source)

    cur = conn.execute(
        """INSERT INTO punches (event_id, siac, control_code, punch_time, source, roc_punch_id, is_duplicate)
           VALUES (?, ?, ?, ?, ?, ?, ?)""",
        (event_id, siac, control_code, punch_time, source, roc_punch_id, int(is_dup))
    )
    conn.commit()
    punch_id = cur.lastrowid

    if not is_dup:
        _process_punch(conn, event_id, punch_id, siac, control_code, punch_time)

    return punch_id


def _check_duplicate(conn: sqlite3.Connection, event_id: int, siac: int,
                     control_code: int, punch_time: str,
                     source: str = "roc") -> bool:
    """BIB-level dedup: same BIB + same control + within 2s = duplicate.

    If chip_mapping exists, dedup is per BIB (so dual-chip riders don't get
    double punches). Falls back to SIAC-level if no mapping found.

    Source priority override: if the new punch has higher priority (lower number)
    than all existing punches in the window, it is NOT a duplicate — the source
    override logic in _update_stage_result will handle superseding.
    """
    ts = parse_timestamp(punch_time)
    window_start = (ts - timedelta(seconds=DEDUP_WINDOW_SECONDS)).strftime("%Y-%m-%d %H:%M:%S")
    window_end = (ts + timedelta(seconds=DEDUP_WINDOW_SECONDS)).strftime("%Y-%m-%d %H:%M:%S")

    # Try BIB-level dedup first
    bib_row = conn.execute(
        "SELECT bib FROM chip_mapping WHERE event_id=? AND siac=?",
        (event_id, siac)
    ).fetchone()

    if bib_row:
        # Get all SIACs for this BIB
        all_siacs = conn.execute(
            "SELECT siac FROM chip_mapping WHERE event_id=? AND bib=?",
            (event_id, bib_row["bib"])
        ).fetchall()
        siac_list = [r["siac"] for r in all_siacs]

        placeholders = ",".join("?" for _ in siac_list)
        existing = conn.execute(
            f"""SELECT id, source FROM punches
               WHERE event_id=? AND siac IN ({placeholders}) AND control_code=?
               AND punch_time BETWEEN ? AND ?
               AND is_duplicate=0
               LIMIT 1""",
            (event_id, *siac_list, control_code, window_start, window_end)
        ).fetchone()
    else:
        # Fallback: SIAC-level dedup (no chip mapping yet)
        existing = conn.execute(
            """SELECT id, source FROM punches
               WHERE event_id=? AND siac=? AND control_code=?
               AND punch_time BETWEEN ? AND ?
               AND is_duplicate=0
               LIMIT 1""",
            (event_id, siac, control_code, window_start, window_end)
        ).fetchone()

    if existing is None:
        return False  # No duplicate found

    # Check source priority: if new source is higher priority, allow through
    new_prio = SOURCE_PRIORITY.get(source, 99)
    existing_prio = SOURCE_PRIORITY.get(existing["source"], 99)
    if new_prio < existing_prio:
        return False  # Higher priority source — let it through for override processing

    return True  # Same or lower priority — it's a duplicate


def _process_punch(conn: sqlite3.Connection, event_id: int, punch_id: int,
                   siac: int, control_code: int, punch_time: str) -> None:
    """After a non-duplicate punch: resolve BIB, match to stage, calc result.

    Includes cross-chip resolution: if primary chip has start and secondary
    chip has finish (or vice versa), both are used for the same stage result.
    """
    bib = resolve_bib(conn, event_id, siac)
    if bib is None:
        return

    entry = conn.execute(
        "SELECT id FROM entries WHERE event_id=? AND bib=?",
        (event_id, bib)
    ).fetchone()
    if entry is None:
        return
    entry_id = entry["id"]

    stage = _find_stage_for_control(conn, event_id, control_code)
    if stage is None:
        return

    _update_stage_result(conn, event_id, entry_id, stage, punch_id,
                         control_code, punch_time)

    # Cross-chip resolution: check if we can fill missing start/finish
    # from other SIAC belonging to same BIB
    _try_cross_chip_fill(conn, event_id, entry_id, stage, bib)


def _try_cross_chip_fill(conn: sqlite3.Connection, event_id: int,
                         entry_id: int, stage: sqlite3.Row, bib: int) -> None:
    """Cross-chip resolution: fill missing start or finish from other SIAC.

    Rule from MEMORY.md §10:
    1. Both chips have start+finish → use primary chip timestamps
    2. Primary missing start OR finish → fill from secondary
    3. Only secondary exists → already handled by normal flow
    """
    # Get all SIACs for this BIB
    chips = conn.execute(
        "SELECT siac, is_primary FROM chip_mapping WHERE event_id=? AND bib=? ORDER BY is_primary DESC",
        (event_id, bib)
    ).fetchall()

    if len(chips) < 2:
        return  # No dual-chip, nothing to fill

    # Get latest pending result (missing start or finish), skip superseded
    latest = conn.execute(
        """SELECT * FROM stage_results
           WHERE event_id=? AND entry_id=? AND stage_id=? AND run_state != 'superseded'
           ORDER BY attempt DESC LIMIT 1""",
        (event_id, entry_id, stage["id"])
    ).fetchone()

    if latest is None or latest["status"] == "ok":
        return  # Already complete

    # Get start and finish control codes
    start_code = conn.execute(
        "SELECT code FROM controls WHERE id=?", (stage["start_control_id"],)
    ).fetchone()["code"]
    finish_code = conn.execute(
        "SELECT code FROM controls WHERE id=?", (stage["finish_control_id"],)
    ).fetchone()["code"]

    all_siac_ids = [c["siac"] for c in chips]

    if latest["start_time"] and not latest["finish_time"]:
        # Missing finish — look for finish punch from any SIAC of this BIB
        placeholders = ",".join("?" for _ in all_siac_ids)
        finish_punch = conn.execute(
            f"""SELECT id, punch_time FROM punches
               WHERE event_id=? AND siac IN ({placeholders}) AND control_code=?
               AND is_duplicate=0 AND punch_time > ?
               ORDER BY punch_time ASC LIMIT 1""",
            (event_id, *all_siac_ids, finish_code, latest["start_time"])
        ).fetchone()

        if finish_punch:
            start_dt = parse_timestamp(latest["start_time"])
            finish_dt = parse_timestamp(finish_punch["punch_time"])
            elapsed = (finish_dt - start_dt).total_seconds()
            if elapsed >= 0:
                conn.execute(
                    """UPDATE stage_results SET finish_punch_id=?, finish_time=?,
                       elapsed_seconds=?, status='ok', run_state='valid' WHERE id=?""",
                    (finish_punch["id"], finish_punch["punch_time"], elapsed, latest["id"])
                )
                conn.commit()
                journal_event(conn, event_id, "run_created", {
                    "entry_id": entry_id, "stage_id": stage["id"],
                    "attempt": latest["attempt"], "elapsed": elapsed,
                    "source": "cross_chip_fill",
                })

    elif latest["finish_time"] and not latest["start_time"]:
        # Missing start — look for start punch from any SIAC of this BIB
        placeholders = ",".join("?" for _ in all_siac_ids)
        start_punch = conn.execute(
            f"""SELECT id, punch_time FROM punches
               WHERE event_id=? AND siac IN ({placeholders}) AND control_code=?
               AND is_duplicate=0 AND punch_time < ?
               ORDER BY punch_time DESC LIMIT 1""",
            (event_id, *all_siac_ids, start_code, latest["finish_time"])
        ).fetchone()

        if start_punch:
            start_dt = parse_timestamp(start_punch["punch_time"])
            finish_dt = parse_timestamp(latest["finish_time"])
            elapsed = (finish_dt - start_dt).total_seconds()
            if elapsed >= 0:
                conn.execute(
                    """UPDATE stage_results SET start_punch_id=?, start_time=?,
                       elapsed_seconds=?, status='ok', run_state='valid' WHERE id=?""",
                    (start_punch["id"], start_punch["punch_time"], elapsed, latest["id"])
                )
                conn.commit()
                journal_event(conn, event_id, "run_created", {
                    "entry_id": entry_id, "stage_id": stage["id"],
                    "attempt": latest["attempt"], "elapsed": elapsed,
                    "source": "cross_chip_fill",
                })


def resolve_bib(conn: sqlite3.Connection, event_id: int, siac: int) -> int | None:
    """Lookup SIAC → BIB via chip_mapping table."""
    row = conn.execute(
        "SELECT bib FROM chip_mapping WHERE event_id=? AND siac=?",
        (event_id, siac)
    ).fetchone()
    return row["bib"] if row else None


def _find_stage_for_control(conn: sqlite3.Connection, event_id: int,
                            control_code: int) -> sqlite3.Row | None:
    """Find a stage where this control code is used as start or finish."""
    row = conn.execute(
        """SELECT s.* FROM stages s
           JOIN controls c ON (c.id = s.start_control_id OR c.id = s.finish_control_id)
           WHERE s.event_id=? AND c.code=?
           LIMIT 1""",
        (event_id, control_code)
    ).fetchone()
    return row


def _get_next_attempt(conn: sqlite3.Connection, event_id: int, entry_id: int,
                      stage_id: int) -> int:
    """Get the next available attempt number for a stage result."""
    row = conn.execute(
        """SELECT MAX(attempt) as max_attempt FROM stage_results
           WHERE event_id=? AND entry_id=? AND stage_id=?""",
        (event_id, entry_id, stage_id)
    ).fetchone()
    if row and row["max_attempt"] is not None:
        return row["max_attempt"] + 1
    return 1


def _check_source_override(conn: sqlite3.Connection, event_id: int,
                            entry_id: int, stage: sqlite3.Row,
                            punch_id: int, control_code: int,
                            punch_time: str) -> bool:
    """Check if a new punch should override an existing completed result via source priority.

    If the new punch's source has higher priority (lower number) than the existing
    punch for the same control in a completed (ok + valid) stage_result:
    - Mark old stage_result as run_state='superseded'
    - Create a new stage_result with the higher-priority punch
    - Returns True if override happened (caller should skip normal processing)
    """
    # Get new punch source
    new_punch = conn.execute(
        "SELECT source FROM punches WHERE id=?", (punch_id,)
    ).fetchone()
    if not new_punch:
        return False
    new_source = new_punch["source"]
    new_prio = SOURCE_PRIORITY.get(new_source, 99)

    start_code = conn.execute(
        "SELECT code FROM controls WHERE id=?", (stage["start_control_id"],)
    ).fetchone()["code"]
    finish_code = conn.execute(
        "SELECT code FROM controls WHERE id=?", (stage["finish_control_id"],)
    ).fetchone()["code"]

    is_start = (control_code == start_code)
    is_finish = (control_code == finish_code)

    if not is_start and not is_finish:
        return False

    # Find existing completed (ok + valid) result for this entry+stage
    existing = conn.execute(
        """SELECT * FROM stage_results
           WHERE event_id=? AND entry_id=? AND stage_id=?
             AND status='ok' AND run_state='valid'
           ORDER BY attempt DESC LIMIT 1""",
        (event_id, entry_id, stage["id"])
    ).fetchone()

    if not existing:
        return False  # No completed result to override

    # Check the source of the relevant punch in the existing result
    if is_start and existing["start_punch_id"]:
        existing_punch = conn.execute(
            "SELECT source FROM punches WHERE id=?", (existing["start_punch_id"],)
        ).fetchone()
    elif is_finish and existing["finish_punch_id"]:
        existing_punch = conn.execute(
            "SELECT source FROM punches WHERE id=?", (existing["finish_punch_id"],)
        ).fetchone()
    else:
        return False

    if not existing_punch:
        return False

    existing_prio = SOURCE_PRIORITY.get(existing_punch["source"], 99)

    # Higher priority (lower number) wins
    if new_prio >= existing_prio:
        return False  # New source is same or lower priority, no override

    # Override! Supersede old result and create new one with the better punch
    conn.execute(
        "UPDATE stage_results SET run_state='superseded' WHERE id=?",
        (existing["id"],)
    )
    # Journal: run superseded
    journal_event(conn, event_id, "run_superseded", {
        "old_run_id": existing["id"],
        "entry_id": entry_id,
        "stage_id": stage["id"],
        "reason": f"{new_source}_override",
    })

    # Create new result, keeping the other punch from the old result
    new_attempt = existing["attempt"]  # Reuse same attempt number
    # Actually, we need a new unique attempt number since UNIQUE(event_id, entry_id, stage_id, attempt)
    next_attempt = _get_next_attempt(conn, event_id, entry_id, stage["id"])

    if is_start:
        # New start punch, keep old finish
        conn.execute(
            """INSERT INTO stage_results
               (event_id, entry_id, stage_id, start_punch_id, start_time,
                finish_punch_id, finish_time, attempt, status, run_state)
               VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending', 'pending')""",
            (event_id, entry_id, stage["id"], punch_id, punch_time,
             existing["finish_punch_id"], existing["finish_time"], next_attempt)
        )
    else:
        # New finish punch, keep old start
        conn.execute(
            """INSERT INTO stage_results
               (event_id, entry_id, stage_id, start_punch_id, start_time,
                finish_punch_id, finish_time, attempt, status, run_state)
               VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending', 'pending')""",
            (event_id, entry_id, stage["id"],
             existing["start_punch_id"], existing["start_time"],
             punch_id, punch_time, next_attempt)
        )

    conn.commit()
    _finalize_result(conn, event_id, entry_id, stage["id"], next_attempt)
    return True


def _update_stage_result(conn: sqlite3.Connection, event_id: int, entry_id: int,
                         stage: sqlite3.Row, punch_id: int,
                         control_code: int, punch_time: str) -> None:
    """Create or update stage_result when start/finish punch arrives.

    Multi-attempt logic:
    - When the current attempt is 'ok' (completed) and a new START arrives,
      create a new attempt (if max_runs not reached).
    - Finish punches always update the current (latest non-ok or latest) attempt.

    Source priority override:
    - If a completed result exists and the new punch has higher-priority source,
      the old result is superseded and a new one created with the better source.

    run_state flow:
    - New result starts with run_state='pending'
    - _finalize_result sets run_state='valid' when start+finish exist and elapsed >= 0
    - Source override sets old run to 'superseded'

    Stale punch handling:
    - If start + finish yields negative time, the earlier punch is stale.
      Discard the stale one and keep the newer punch.
    - If a result is already 'ok', a new finish is ignored (go to next attempt via start).
    """
    # Check source priority override first
    if _check_source_override(conn, event_id, entry_id, stage, punch_id,
                               control_code, punch_time):
        return  # Override handled, skip normal processing

    start_code = conn.execute(
        "SELECT code FROM controls WHERE id=?", (stage["start_control_id"],)
    ).fetchone()["code"]
    finish_code = conn.execute(
        "SELECT code FROM controls WHERE id=?", (stage["finish_control_id"],)
    ).fetchone()["code"]

    is_start = (control_code == start_code)
    is_finish = (control_code == finish_code)

    if not is_start and not is_finish:
        return

    max_runs = stage["max_runs"]  # None = unlimited

    # Find the latest non-superseded attempt for this entry+stage
    latest = conn.execute(
        """SELECT * FROM stage_results
           WHERE event_id=? AND entry_id=? AND stage_id=? AND run_state != 'superseded'
           ORDER BY attempt DESC LIMIT 1""",
        (event_id, entry_id, stage["id"])
    ).fetchone()

    if latest is None:
        # No result yet — create attempt 1 with run_state='pending'
        if is_start:
            conn.execute(
                """INSERT INTO stage_results
                   (event_id, entry_id, stage_id, start_punch_id, start_time, attempt, status, run_state)
                   VALUES (?, ?, ?, ?, ?, 1, 'pending', 'pending')""",
                (event_id, entry_id, stage["id"], punch_id, punch_time)
            )
        else:
            conn.execute(
                """INSERT INTO stage_results
                   (event_id, entry_id, stage_id, finish_punch_id, finish_time, attempt, status, run_state)
                   VALUES (?, ?, ?, ?, ?, 1, 'pending', 'pending')""",
                (event_id, entry_id, stage["id"], punch_id, punch_time)
            )
        conn.commit()
        _finalize_result(conn, event_id, entry_id, stage["id"], 1)
        return

    # We have an existing result
    current_attempt = latest["attempt"]

    if is_start:
        if latest["status"] == "ok":
            # Completed attempt — start a new one (multi-run)
            if max_runs is not None and current_attempt >= max_runs:
                # Max runs reached, ignore
                return
            new_attempt = current_attempt + 1
            conn.execute(
                """INSERT INTO stage_results
                   (event_id, entry_id, stage_id, start_punch_id, start_time, attempt, status, run_state)
                   VALUES (?, ?, ?, ?, ?, ?, 'pending', 'pending')""",
                (event_id, entry_id, stage["id"], punch_id, punch_time, new_attempt)
            )
            conn.commit()
            _finalize_result(conn, event_id, entry_id, stage["id"], new_attempt)
            return

        # Current attempt not completed — update start time
        if latest["start_time"]:
            # Already have a start. Keep the LATER start (more likely correct).
            old_dt = parse_timestamp(latest["start_time"])
            new_dt = parse_timestamp(punch_time)
            if new_dt <= old_dt:
                return  # Older start, skip
        conn.execute(
            """UPDATE stage_results SET start_punch_id=?, start_time=?,
               elapsed_seconds=NULL, status='pending', run_state='pending' WHERE id=?""",
            (punch_id, punch_time, latest["id"])
        )
        conn.commit()
        _finalize_result(conn, event_id, entry_id, stage["id"], current_attempt)

    else:
        # Finish punch
        if latest["status"] == "ok":
            # Already completed — ignore finish (rider needs to start a new attempt)
            return

        if latest["start_time"]:
            start_dt = parse_timestamp(latest["start_time"])
            new_finish_dt = parse_timestamp(punch_time)
            new_elapsed = (new_finish_dt - start_dt).total_seconds()

            if new_elapsed < 0:
                # Negative = this finish is stale (before start). Skip it.
                return

            # Valid positive time — update; set status='ok' and run_state='valid'
            conn.execute(
                """UPDATE stage_results SET finish_punch_id=?, finish_time=?,
                   elapsed_seconds=?, status='ok', run_state='valid' WHERE id=?""",
                (punch_id, punch_time, new_elapsed, latest["id"])
            )
            conn.commit()
            journal_event(conn, event_id, "run_created", {
                "entry_id": entry_id, "stage_id": stage["id"],
                "attempt": current_attempt, "elapsed": new_elapsed,
            })
        else:
            # No start yet. Keep the latest finish.
            if latest["finish_time"]:
                old_dt = parse_timestamp(latest["finish_time"])
                new_dt = parse_timestamp(punch_time)
                if new_dt <= old_dt:
                    return
            conn.execute(
                "UPDATE stage_results SET finish_punch_id=?, finish_time=? WHERE id=?",
                (punch_id, punch_time, latest["id"])
            )
            conn.commit()
            _finalize_result(conn, event_id, entry_id, stage["id"], current_attempt)


def _finalize_result(conn: sqlite3.Connection, event_id: int, entry_id: int,
                     stage_id: int, attempt: int) -> None:
    """Compute elapsed if both start and finish present and not yet ok.

    Sets run_state='valid' when the result is complete (start+finish, elapsed >= 0).
    """
    result = conn.execute(
        """SELECT * FROM stage_results
           WHERE event_id=? AND entry_id=? AND stage_id=? AND attempt=?""",
        (event_id, entry_id, stage_id, attempt)
    ).fetchone()

    if result and result["start_time"] and result["finish_time"] and result["status"] != "ok":
        start_dt = parse_timestamp(result["start_time"])
        finish_dt = parse_timestamp(result["finish_time"])
        elapsed = (finish_dt - start_dt).total_seconds()
        if elapsed >= 0:
            conn.execute(
                "UPDATE stage_results SET elapsed_seconds=?, status='ok', run_state='valid' WHERE id=?",
                (elapsed, result["id"])
            )
            conn.commit()
            # Journal: run created
            journal_event(conn, event_id, "run_created", {
                "entry_id": entry_id,
                "stage_id": stage_id,
                "attempt": attempt,
                "elapsed": elapsed,
            })


# ---------------------------------------------------------------------------
# Dual Slalom — start grouping
# ---------------------------------------------------------------------------

def group_dual_slalom_starts(conn: sqlite3.Connection, event_id: int,
                             window_seconds: float = 5.0) -> int:
    """Group start punches within time window for dual slalom mass-start.

    Riders who start within `window_seconds` of each other get the same
    start_time (earliest in the group). Updates stage_results accordingly.

    Returns: number of groups created.
    """
    # Get all start-type controls
    start_controls = conn.execute(
        """SELECT c.code FROM controls c
           WHERE c.event_id=? AND c.type='start'""",
        (event_id,)
    ).fetchall()
    start_codes = {c["code"] for c in start_controls}

    if not start_codes:
        return 0

    # Get all non-duplicate start punches in chronological order
    placeholders = ",".join("?" for _ in start_codes)
    punches = conn.execute(
        f"""SELECT p.id, p.siac, p.control_code, p.punch_time
            FROM punches p
            WHERE p.event_id=? AND p.is_duplicate=0
              AND p.control_code IN ({placeholders})
            ORDER BY p.punch_time ASC, p.id ASC""",
        (event_id, *start_codes)
    ).fetchall()

    if not punches:
        return 0

    # Group punches within window
    groups: list[list[dict]] = []
    current_group: list[dict] = []
    group_start_time: datetime | None = None

    for p in punches:
        pt = parse_timestamp(p["punch_time"])
        if group_start_time is None or (pt - group_start_time).total_seconds() > window_seconds:
            if current_group:
                groups.append(current_group)
            current_group = [dict(p)]
            group_start_time = pt
        else:
            current_group.append(dict(p))

    if current_group:
        groups.append(current_group)

    # For each group with 2+ riders, set all start times to earliest
    group_count = 0
    for group in groups:
        if len(group) < 2:
            continue

        earliest_time = group[0]["punch_time"]  # Already sorted by time
        group_count += 1

        for p in group:
            # Update start_time for all stage_results referencing this punch
            affected = conn.execute(
                """SELECT id, finish_time, status FROM stage_results
                   WHERE start_punch_id=?""",
                (p["id"],)
            ).fetchall()

            for sr in affected:
                if sr["finish_time"] and sr["status"] == "ok":
                    # Recalculate elapsed with grouped start time
                    start_dt = parse_timestamp(earliest_time)
                    finish_dt = parse_timestamp(sr["finish_time"])
                    new_elapsed = (finish_dt - start_dt).total_seconds()
                    conn.execute(
                        """UPDATE stage_results SET start_time=?, elapsed_seconds=?
                           WHERE id=?""",
                        (earliest_time, new_elapsed, sr["id"])
                    )
                else:
                    conn.execute(
                        "UPDATE stage_results SET start_time=? WHERE id=?",
                        (earliest_time, sr["id"])
                    )

    conn.commit()
    return group_count


# ---------------------------------------------------------------------------
# Overall results
# ---------------------------------------------------------------------------

def calculate_overall_results(conn: sqlite3.Connection, event_id: int) -> None:
    """Recalculate overall results for all entries in an event."""
    event = conn.execute("SELECT * FROM events WHERE id=?", (event_id,)).fetchone()
    if not event:
        return

    entries = conn.execute(
        "SELECT * FROM entries WHERE event_id=?", (event_id,)
    ).fetchall()

    # Get stages relevant to each entry via their class→course→course_stages
    for entry in entries:
        timed_stages = _get_entry_timed_stages(conn, event_id, entry)
        total, status = _calc_entry_total(conn, event, entry, timed_stages)

        existing = conn.execute(
            "SELECT id FROM overall_results WHERE event_id=? AND entry_id=?",
            (event_id, entry["id"])
        ).fetchone()

        if existing:
            conn.execute(
                """UPDATE overall_results
                   SET total_seconds=?, status=?, updated_at=datetime('now')
                   WHERE id=?""",
                (total, status, existing["id"])
            )
        else:
            conn.execute(
                """INSERT INTO overall_results (event_id, entry_id, total_seconds, status)
                   VALUES (?, ?, ?, ?)""",
                (event_id, entry["id"], total, status)
            )

    conn.commit()

    # Now calculate rankings per class
    _calculate_rankings(conn, event_id)


def _get_entry_timed_stages(conn: sqlite3.Connection, event_id: int,
                            entry: sqlite3.Row) -> list[sqlite3.Row]:
    """Get the timed stages for an entry based on their class→course→course_stages.

    Falls back to all timed stages for the event if no course linkage exists.
    """
    # Get course for this entry's class
    cls = conn.execute(
        "SELECT course_id FROM classes WHERE id=?", (entry["class_id"],)
    ).fetchone()

    if cls and cls["course_id"]:
        # Get stages linked to this course
        course_stages = conn.execute(
            """SELECT s.* FROM stages s
               JOIN course_stages cs ON cs.stage_id = s.id
               WHERE cs.course_id=? AND s.is_timed=1
               ORDER BY cs.stage_order""",
            (cls["course_id"],)
        ).fetchall()
        if course_stages:
            return course_stages

    # Fallback: all timed stages for the event
    return conn.execute(
        "SELECT * FROM stages WHERE event_id=? AND is_timed=1 ORDER BY stage_number",
        (event_id,)
    ).fetchall()


def _calc_entry_total(conn: sqlite3.Connection, event: sqlite3.Row,
                      entry: sqlite3.Row,
                      timed_stages: list[sqlite3.Row]) -> tuple[float | None, str]:
    """Calculate total time for one entry. Returns (total_seconds, status)."""
    fmt = event["format"]

    if fmt == "enduro":
        return _calc_enduro(conn, event["id"], entry["id"], timed_stages)
    elif fmt == "downhill":
        return _calc_downhill(conn, event["id"], entry["id"], timed_stages)
    elif fmt == "xc":
        return _calc_xc(conn, event["id"], entry["id"], timed_stages)
    elif fmt == "dual_slalom":
        return _calc_downhill(conn, event["id"], entry["id"], timed_stages)
    else:
        # Default (custom, festival, etc.) — use multi-run aware enduro calc
        return _calc_enduro(conn, event["id"], entry["id"], timed_stages)


def _calc_enduro(conn: sqlite3.Connection, event_id: int, entry_id: int,
                 timed_stages: list[sqlite3.Row]) -> tuple[float | None, str]:
    """Sum of timed stage elapsed times, multi-run aware.

    For each stage:
    - If runs_to_count > 1: take best N attempts, sum them
    - If runs_to_count == 1: take best single attempt
    - Then sum across all stages for overall total
    """
    total = 0.0
    all_ok = True
    any_result = False

    for stage in timed_stages:
        runs_to_count = stage["runs_to_count"] if stage["runs_to_count"] else 1
        stage_time = _get_stage_counting_time(conn, event_id, entry_id,
                                               stage["id"], runs_to_count)

        if stage_time is None:
            # Check for DNS/DNF/DSQ (only non-superseded runs)
            status_check = conn.execute(
                """SELECT status FROM stage_results
                   WHERE event_id=? AND entry_id=? AND stage_id=?
                     AND run_state != 'superseded'
                   ORDER BY attempt ASC LIMIT 1""",
                (event_id, entry_id, stage["id"])
            ).fetchone()

            if status_check:
                if status_check["status"] == "dns":
                    return None, "dns"
                if status_check["status"] == "dnf":
                    return None, "dnf"
                if status_check["status"] == "dsq":
                    return None, "dsq"

            all_ok = False
            continue

        total += stage_time
        any_result = True

    if not any_result:
        return None, "pending"

    return total, "ok" if all_ok else "pending"


def _get_stage_counting_time(conn: sqlite3.Connection, event_id: int,
                              entry_id: int, stage_id: int,
                              runs_to_count: int) -> float | None:
    """Get the 'counting' time for a stage.

    - Gets all OK + valid (not superseded) attempts, sorted by elapsed_seconds
    - Takes best `runs_to_count` attempts
    - Returns sum of those best times (with penalties)
    - Returns None if not enough OK attempts yet
    """
    results = conn.execute(
        """SELECT elapsed_seconds, penalty_seconds FROM stage_results
           WHERE event_id=? AND entry_id=? AND stage_id=?
             AND status='ok' AND run_state='valid'
           ORDER BY elapsed_seconds ASC""",
        (event_id, entry_id, stage_id)
    ).fetchall()

    if not results:
        return None

    if runs_to_count <= 1:
        # Best single attempt
        r = results[0]
        return r["elapsed_seconds"] + (r["penalty_seconds"] or 0)

    # Multi-run: need at least runs_to_count OK results
    if len(results) < runs_to_count:
        # Not enough attempts yet — use what we have as partial
        # (return None to mark as pending)
        return None

    # Sum best N times
    total = 0.0
    for r in results[:runs_to_count]:
        total += r["elapsed_seconds"] + (r["penalty_seconds"] or 0)
    return total


def _calc_downhill(conn: sqlite3.Connection, event_id: int, entry_id: int,
                   timed_stages: list[sqlite3.Row]) -> tuple[float | None, str]:
    """Best time from multiple attempts (for downhill/dual slalom)."""
    if not timed_stages:
        return None, "pending"
    stage = timed_stages[0]

    results = conn.execute(
        """SELECT * FROM stage_results
           WHERE event_id=? AND entry_id=? AND stage_id=?
             AND status='ok' AND run_state='valid'
           ORDER BY elapsed_seconds ASC""",
        (event_id, entry_id, stage["id"])
    ).fetchall()

    if not results:
        return None, "pending"

    best = results[0]["elapsed_seconds"] + (results[0]["penalty_seconds"] or 0)
    return best, "ok"


def _calc_xc(conn: sqlite3.Connection, event_id: int, entry_id: int,
             timed_stages: list[sqlite3.Row]) -> tuple[float | None, str]:
    """XC: sum all lap times (same as enduro, multi-run aware)."""
    return _calc_enduro(conn, event_id, entry_id, timed_stages)


def _calculate_rankings(conn: sqlite3.Connection, event_id: int) -> None:
    """Assign position and time_behind per class."""
    classes = conn.execute(
        "SELECT DISTINCT cl.id, cl.name FROM classes cl "
        "JOIN entries e ON e.class_id = cl.id WHERE e.event_id=?",
        (event_id,)
    ).fetchall()

    for cls in classes:
        results = conn.execute(
            """SELECT o.id, o.total_seconds, o.status
               FROM overall_results o
               JOIN entries e ON o.entry_id = e.id
               WHERE o.event_id=? AND e.class_id=?
               ORDER BY
                 CASE WHEN o.status='ok' THEN 0
                      WHEN o.status='pending' THEN 1
                      ELSE 2 END,
                 o.total_seconds ASC""",
            (event_id, cls["id"])
        ).fetchall()

        leader_time = None
        pos = 0
        for r in results:
            if r["status"] == "ok" and r["total_seconds"] is not None:
                pos += 1
                if leader_time is None:
                    leader_time = r["total_seconds"]
                behind = r["total_seconds"] - leader_time
                conn.execute(
                    "UPDATE overall_results SET position=?, time_behind=? WHERE id=?",
                    (pos, behind, r["id"])
                )
            else:
                conn.execute(
                    "UPDATE overall_results SET position=NULL, time_behind=NULL WHERE id=?",
                    (r["id"],)
                )

    conn.commit()


# ---------------------------------------------------------------------------
# CSV import
# ---------------------------------------------------------------------------

def import_startlist_csv(conn: sqlite3.Connection, event_id: int,
                         filepath: str) -> tuple[int, list[str]]:
    """Import startlist CSV. Returns (count_imported, list of warnings).

    Expected format: BIB;FirstName;LastName;Club;Class
    Creates classes and a default course automatically if needed.
    """
    warnings = []
    count = 0
    class_cache = {}

    # Ensure a default course exists
    default_course = conn.execute(
        "SELECT id FROM courses WHERE event_id=? LIMIT 1", (event_id,)
    ).fetchone()
    if default_course is None:
        cur = conn.execute(
            "INSERT INTO courses (event_id, name) VALUES (?, ?)",
            (event_id, "Huvudbana")
        )
        conn.commit()
        default_course_id = cur.lastrowid
    else:
        default_course_id = default_course["id"]

    # Link all stages to default course if not already linked
    stages = conn.execute(
        "SELECT id FROM stages WHERE event_id=?", (event_id,)
    ).fetchall()
    for stg in stages:
        exists = conn.execute(
            "SELECT id FROM course_stages WHERE course_id=? AND stage_id=?",
            (default_course_id, stg["id"])
        ).fetchone()
        if not exists:
            conn.execute(
                "INSERT INTO course_stages (course_id, stage_id, stage_order) VALUES (?, ?, ?)",
                (default_course_id, stg["id"], stg["id"])
            )
    conn.commit()

    with open(filepath, "r", encoding="utf-8-sig") as f:
        reader = csv.reader(f, delimiter=";")
        for i, row in enumerate(reader):
            if not row or len(row) < 5:
                continue
            if row[0].strip().upper() == "BIB":
                continue

            try:
                bib = int(row[0].strip())
            except ValueError:
                warnings.append(f"Rad {i+1}: Ogiltigt startnummer '{row[0]}'")
                continue

            first_name = row[1].strip()
            last_name = row[2].strip()
            club = row[3].strip()
            class_name = row[4].strip()

            if class_name not in class_cache:
                existing_class = conn.execute(
                    "SELECT id FROM classes WHERE event_id=? AND name=?",
                    (event_id, class_name)
                ).fetchone()
                if existing_class:
                    class_cache[class_name] = existing_class["id"]
                else:
                    cur = conn.execute(
                        "INSERT INTO classes (event_id, course_id, name) VALUES (?, ?, ?)",
                        (event_id, default_course_id, class_name)
                    )
                    conn.commit()
                    class_cache[class_name] = cur.lastrowid

            class_id = class_cache[class_name]

            existing = conn.execute(
                "SELECT id FROM entries WHERE event_id=? AND bib=?",
                (event_id, bib)
            ).fetchone()
            if existing:
                conn.execute(
                    """UPDATE entries SET first_name=?, last_name=?, club=?, class_id=?
                       WHERE id=?""",
                    (first_name, last_name, club, class_id, existing["id"])
                )
            else:
                conn.execute(
                    """INSERT INTO entries (event_id, bib, first_name, last_name, club, class_id)
                       VALUES (?, ?, ?, ?, ?, ?)""",
                    (event_id, bib, first_name, last_name, club, class_id)
                )
            count += 1

    conn.commit()
    return count, warnings


def import_chipmapping_csv(conn: sqlite3.Connection, event_id: int,
                           filepath: str) -> tuple[int, list[str]]:
    """Import chip mapping CSV. Returns (count, warnings).

    Expected format: BIB;SIAC1;SIAC2
    """
    warnings = []
    count = 0

    with open(filepath, "r", encoding="utf-8-sig") as f:
        reader = csv.reader(f, delimiter=";")
        for i, row in enumerate(reader):
            if not row or len(row) < 2:
                continue
            if row[0].strip().upper() == "BIB":
                continue

            try:
                bib = int(row[0].strip())
            except ValueError:
                warnings.append(f"Rad {i+1}: Ogiltigt startnummer '{row[0]}'")
                continue

            siac1 = row[1].strip()
            if siac1:
                try:
                    siac1_int = int(siac1)
                    existing = conn.execute(
                        "SELECT id FROM chip_mapping WHERE event_id=? AND siac=?",
                        (event_id, siac1_int)
                    ).fetchone()
                    if existing:
                        conn.execute(
                            "UPDATE chip_mapping SET bib=?, is_primary=1 WHERE id=?",
                            (bib, existing["id"])
                        )
                    else:
                        conn.execute(
                            """INSERT INTO chip_mapping (event_id, bib, siac, is_primary)
                               VALUES (?, ?, ?, 1)""",
                            (event_id, bib, siac1_int)
                        )
                    count += 1
                except ValueError:
                    warnings.append(f"Rad {i+1}: Ogiltigt SIAC1 '{siac1}'")

            if len(row) > 2:
                siac2 = row[2].strip()
                if siac2:
                    try:
                        siac2_int = int(siac2)
                        existing = conn.execute(
                            "SELECT id FROM chip_mapping WHERE event_id=? AND siac=?",
                            (event_id, siac2_int)
                        ).fetchone()
                        if existing:
                            conn.execute(
                                "UPDATE chip_mapping SET bib=?, is_primary=0 WHERE id=?",
                                (bib, existing["id"])
                            )
                        else:
                            conn.execute(
                                """INSERT INTO chip_mapping (event_id, bib, siac, is_primary)
                                   VALUES (?, ?, ?, 0)""",
                                (event_id, bib, siac2_int)
                            )
                        count += 1
                    except ValueError:
                        warnings.append(f"Rad {i+1}: Ogiltigt SIAC2 '{siac2}'")

    conn.commit()
    return count, warnings


def import_roc_punches(conn: sqlite3.Connection, event_id: int,
                       filepath: str) -> tuple[int, int, list[str]]:
    """Import ROC punch data from CSV file. Returns (total, new, warnings).

    Format: PunchID;ControlCode;SIAC;Timestamp
    Lines starting with # are comments.
    """
    warnings = []
    total = 0
    new = 0

    with open(filepath, "r", encoding="utf-8-sig") as f:
        for line in f:
            line = line.strip()
            if not line or line.startswith("#"):
                continue

            parts = line.split(";")
            if len(parts) < 4:
                warnings.append(f"Ogiltig rad: {line}")
                continue

            try:
                roc_id = int(parts[0])
                control_code = int(parts[1])
                siac = int(parts[2])
                punch_time = parts[3].strip()
            except (ValueError, IndexError) as e:
                warnings.append(f"Tolkningsfel: {line} ({e})")
                continue

            total += 1

            existing = conn.execute(
                "SELECT id FROM punches WHERE event_id=? AND roc_punch_id=?",
                (event_id, roc_id)
            ).fetchone()
            if existing:
                continue

            punch_id = ingest_punch(conn, event_id, siac, control_code,
                                    punch_time, source="roc",
                                    roc_punch_id=roc_id)
            if punch_id:
                new += 1

    return total, new, warnings


# ---------------------------------------------------------------------------
# Export
# ---------------------------------------------------------------------------

def export_stage_results_csv(conn: sqlite3.Connection, event_id: int,
                             stage_id: int, filepath: str,
                             precision: str = "seconds",
                             attempt_filter: int | None = None) -> int:
    """Export stage results to CSV. Returns row count.

    attempt_filter: None = best attempt only, 0 = all attempts, N = specific attempt
    """
    if attempt_filter is None or attempt_filter == 0:
        # Get all attempts
        results = conn.execute(
            """SELECT sr.*, e.bib, e.first_name, e.last_name, e.club, cl.name as class_name
               FROM stage_results sr
               JOIN entries e ON sr.entry_id = e.id
               JOIN classes cl ON e.class_id = cl.id
               WHERE sr.event_id=? AND sr.stage_id=?
               ORDER BY sr.attempt ASC,
                 CASE WHEN sr.status='ok' THEN 0 ELSE 1 END,
                 sr.elapsed_seconds ASC""",
            (event_id, stage_id)
        ).fetchall()
    else:
        results = conn.execute(
            """SELECT sr.*, e.bib, e.first_name, e.last_name, e.club, cl.name as class_name
               FROM stage_results sr
               JOIN entries e ON sr.entry_id = e.id
               JOIN classes cl ON e.class_id = cl.id
               WHERE sr.event_id=? AND sr.stage_id=? AND sr.attempt=?
               ORDER BY
                 CASE WHEN sr.status='ok' THEN 0 ELSE 1 END,
                 sr.elapsed_seconds ASC""",
            (event_id, stage_id, attempt_filter)
        ).fetchall()

    count = 0
    with open(filepath, "w", encoding="utf-8", newline="") as f:
        writer = csv.writer(f, delimiter=";")
        writer.writerow(["Pos", "BIB", "Namn", "Klubb", "Klass", "Åk", "Tid", "Diff", "Status"])

        pos = 0
        leader_time = None
        for r in results:
            if r["status"] == "ok":
                pos += 1
                if leader_time is None:
                    leader_time = r["elapsed_seconds"]
                diff = format_time_behind(r["elapsed_seconds"] - leader_time, precision)
                time_str = format_elapsed(r["elapsed_seconds"], precision)
                writer.writerow([pos, r["bib"],
                                 f"{r['first_name']} {r['last_name']}",
                                 r["club"], r["class_name"],
                                 r["attempt"],
                                 time_str, diff,
                                 r["status"]])
            else:
                writer.writerow(["", r["bib"],
                                 f"{r['first_name']} {r['last_name']}",
                                 r["club"], r["class_name"],
                                 r["attempt"],
                                 "", "",
                                 r["status"]])
            count += 1

    return count


def export_overall_results_csv(conn: sqlite3.Connection, event_id: int,
                               filepath: str,
                               precision: str = "seconds") -> int:
    """Export overall results to CSV. Returns row count."""
    results = conn.execute(
        """SELECT o.*, e.bib, e.first_name, e.last_name, e.club, cl.name as class_name
           FROM overall_results o
           JOIN entries e ON o.entry_id = e.id
           JOIN classes cl ON e.class_id = cl.id
           WHERE o.event_id=?
           ORDER BY cl.name,
             CASE WHEN o.status='ok' THEN 0 ELSE 1 END,
             o.total_seconds ASC""",
        (event_id,)
    ).fetchall()

    # Also get stage results for breakdown
    stages = conn.execute(
        "SELECT * FROM stages WHERE event_id=? AND is_timed=1 ORDER BY stage_number",
        (event_id,)
    ).fetchall()

    count = 0
    with open(filepath, "w", encoding="utf-8", newline="") as f:
        writer = csv.writer(f, delimiter=";")
        header = ["Pos", "BIB", "Namn", "Klubb", "Klass", "Total", "Diff", "Status"]
        for s in stages:
            runs_to_count = s["runs_to_count"] if s["runs_to_count"] else 1
            if runs_to_count > 1:
                header.append(f"Stage {s['stage_number']} (bästa {runs_to_count})")
            else:
                header.append(f"Stage {s['stage_number']}")
        writer.writerow(header)

        current_class = None
        pos = 0
        leader_time = None

        for r in results:
            if r["class_name"] != current_class:
                current_class = r["class_name"]
                pos = 0
                leader_time = None

            row = []
            if r["status"] == "ok":
                pos += 1
                if leader_time is None:
                    leader_time = r["total_seconds"]
                diff = format_time_behind(r["total_seconds"] - leader_time, precision)
                row = [pos, r["bib"],
                       f"{r['first_name']} {r['last_name']}",
                       r["club"], r["class_name"],
                       format_elapsed(r["total_seconds"], precision),
                       diff, r["status"]]
            else:
                row = ["", r["bib"],
                       f"{r['first_name']} {r['last_name']}",
                       r["club"], r["class_name"], "", "", r["status"]]

            # Add per-stage times (counting time, multi-run aware)
            for s in stages:
                runs_to_count = s["runs_to_count"] if s["runs_to_count"] else 1
                stage_time = _get_stage_counting_time(
                    conn, event_id, r["entry_id"], s["id"], runs_to_count
                )
                if stage_time is not None:
                    row.append(format_elapsed(stage_time, precision))
                else:
                    # Check for any result
                    sr = conn.execute(
                        """SELECT status FROM stage_results
                           WHERE event_id=? AND entry_id=? AND stage_id=?
                           ORDER BY attempt ASC LIMIT 1""",
                        (event_id, r["entry_id"], s["id"])
                    ).fetchone()
                    row.append(sr["status"] if sr else "")

            writer.writerow(row)
            count += 1

    return count


# ---------------------------------------------------------------------------
# Recalculate all
# ---------------------------------------------------------------------------

def recalculate_all(conn: sqlite3.Connection, event_id: int) -> list[str]:
    """Full recalc: clear stage_results + overall_results, replay all non-dup punches.

    Returns list of diff messages (empty = idempotent, everything matches).
    """
    import logging
    logger = logging.getLogger("gravitytiming.recompute")

    # 1. Snapshot current results
    old_stage = {
        (r["entry_id"], r["stage_id"], r["attempt"]): {
            "elapsed_seconds": r["elapsed_seconds"],
            "status": r["status"],
            "run_state": r["run_state"] if "run_state" in r.keys() else "valid",
        }
        for r in conn.execute(
            "SELECT * FROM stage_results WHERE event_id=? AND run_state='valid'",
            (event_id,)
        ).fetchall()
    }
    old_overall = {
        r["entry_id"]: {
            "total_seconds": r["total_seconds"],
            "position": r["position"],
            "status": r["status"],
        }
        for r in conn.execute(
            "SELECT * FROM overall_results WHERE event_id=?", (event_id,)
        ).fetchall()
    }

    # 2. Delete and replay
    conn.execute("DELETE FROM stage_results WHERE event_id=?", (event_id,))
    conn.execute("DELETE FROM overall_results WHERE event_id=?", (event_id,))
    conn.commit()

    punches = conn.execute(
        """SELECT * FROM punches
           WHERE event_id=? AND is_duplicate=0
           ORDER BY punch_time ASC, id ASC""",
        (event_id,)
    ).fetchall()

    for p in punches:
        _process_punch(conn, event_id, p["id"], p["siac"],
                       p["control_code"], p["punch_time"])

    # Apply dual slalom grouping if applicable
    event = conn.execute("SELECT * FROM events WHERE id=?", (event_id,)).fetchone()
    if event and event["format"] == "dual_slalom" and event["dual_slalom_window"]:
        group_dual_slalom_starts(conn, event_id, event["dual_slalom_window"])

    calculate_overall_results(conn, event_id)

    # 3. Compare with snapshot
    diffs = []

    new_stage = {
        (r["entry_id"], r["stage_id"], r["attempt"]): {
            "elapsed_seconds": r["elapsed_seconds"],
            "status": r["status"],
            "run_state": r["run_state"] if "run_state" in r.keys() else "valid",
        }
        for r in conn.execute(
            "SELECT * FROM stage_results WHERE event_id=? AND run_state='valid'",
            (event_id,)
        ).fetchall()
    }
    new_overall = {
        r["entry_id"]: {
            "total_seconds": r["total_seconds"],
            "position": r["position"],
            "status": r["status"],
        }
        for r in conn.execute(
            "SELECT * FROM overall_results WHERE event_id=?", (event_id,)
        ).fetchall()
    }

    # Stage result diffs
    all_keys = set(old_stage.keys()) | set(new_stage.keys())
    for key in all_keys:
        old_val = old_stage.get(key)
        new_val = new_stage.get(key)
        if old_val is None:
            diffs.append(f"stage_result NEW: entry={key[0]} stage={key[1]} attempt={key[2]}")
        elif new_val is None:
            diffs.append(f"stage_result MISSING: entry={key[0]} stage={key[1]} attempt={key[2]}")
        else:
            old_e = old_val["elapsed_seconds"]
            new_e = new_val["elapsed_seconds"]
            if old_e is not None and new_e is not None and abs(old_e - new_e) > 0.01:
                diffs.append(
                    f"stage_result DIFF: entry={key[0]} stage={key[1]} "
                    f"attempt={key[2]} elapsed {old_e} → {new_e}"
                )
            if old_val["status"] != new_val["status"]:
                diffs.append(
                    f"stage_result STATUS: entry={key[0]} stage={key[1]} "
                    f"{old_val['status']} → {new_val['status']}"
                )

    # Overall result diffs
    all_entries = set(old_overall.keys()) | set(new_overall.keys())
    for eid in all_entries:
        old_val = old_overall.get(eid)
        new_val = new_overall.get(eid)
        if old_val is None:
            diffs.append(f"overall_result NEW: entry={eid}")
        elif new_val is None:
            diffs.append(f"overall_result MISSING: entry={eid}")
        else:
            old_t = old_val["total_seconds"]
            new_t = new_val["total_seconds"]
            if old_t is not None and new_t is not None and abs(old_t - new_t) > 0.01:
                diffs.append(f"overall DIFF: entry={eid} total {old_t} → {new_t}")
            if old_val["position"] != new_val["position"]:
                diffs.append(f"overall POS: entry={eid} pos {old_val['position']} → {new_val['position']}")

    if diffs:
        for d in diffs:
            logger.warning("Recompute diff: %s", d)

    return diffs
