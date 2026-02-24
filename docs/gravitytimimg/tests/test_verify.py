"""
test_verify.py — Verify timing engine against real test punches + new features.

Tests:
1. Original 8-rider enduro test (44 real punches)
2. Template loading (all 8 built-in templates)
3. Export/import round-trip
4. Multi-run downhill (best of 3)
5. Festival mode (best 2 of N attempts)
6. Dual slalom start grouping
7. Recalculate-all consistency
"""

import sys
import os
import json
import sqlite3
import tempfile

# Add project root to path
sys.path.insert(0, os.path.join(os.path.dirname(__file__), ".."))

from core import database
from core import timing_engine
from core import templates

TEST_DATA_DIR = os.path.join(os.path.dirname(__file__), "test_data")

ERRORS = 0


def check(condition, msg, detail=""):
    global ERRORS
    if condition:
        print(f"  ✓ {msg}")
    else:
        ERRORS += 1
        print(f"  ✗ {msg}")
        if detail:
            print(f"    → {detail}")


def make_db():
    """Create a fresh temp database."""
    db_path = os.path.join(tempfile.mkdtemp(), "test.db")
    conn = database.get_connection(db_path)
    database.init_db(conn)
    database.migrate_db(conn)
    return conn


# ======================================================================
# TEST 1: Original 8-rider enduro
# ======================================================================

def test_original_enduro():
    print("\n" + "=" * 70)
    print("TEST 1: Original 8-rider enduro (44 real punches)")
    print("=" * 70)

    conn = make_db()

    event_id = database.create_event(
        conn, "Test Capital Väsjön", "2026-02-20",
        location="Väsjön", fmt="enduro",
        time_precision="seconds", roc_competition_id="2256"
    )

    start_ctrl = database.create_control(conn, event_id, 1, "Start S1", "start")
    finish_ctrl = database.create_control(conn, event_id, 22, "Mål S1", "finish")
    stage_id = database.create_stage(conn, event_id, 1, "Stage 1", start_ctrl, finish_ctrl)

    startlist_path = os.path.join(TEST_DATA_DIR, "sample_startlist.csv")
    count, _ = timing_engine.import_startlist_csv(conn, event_id, startlist_path)
    check(count == 8, f"Startlista: {count} åkare", f"Förväntade 8")

    chip_path = os.path.join(TEST_DATA_DIR, "sample_chipmapping.csv")
    count, _ = timing_engine.import_chipmapping_csv(conn, event_id, chip_path)
    check(count == 8, f"Chipmapping: {count} mappningar", f"Förväntade 8")

    punch_path = os.path.join(TEST_DATA_DIR, "real_punches_2026-02-20.csv")
    total, new, _ = timing_engine.import_roc_punches(conn, event_id, punch_path)
    check(total == 44, f"ROC-data: {total} rader", f"Förväntade 44")

    timing_engine.calculate_overall_results(conn, event_id)

    expected = {1: 20, 2: 58, 3: 42, 4: 66, 5: 336, 6: 65, 7: 66, 8: 46}

    for bib, expected_time in expected.items():
        entry = conn.execute(
            "SELECT id, first_name, last_name FROM entries WHERE event_id=? AND bib=?",
            (event_id, bib)
        ).fetchone()
        sr = conn.execute(
            "SELECT elapsed_seconds FROM stage_results WHERE event_id=? AND entry_id=? AND stage_id=?",
            (event_id, entry["id"], stage_id)
        ).fetchone()
        actual = sr["elapsed_seconds"] if sr else None
        check(actual is not None and abs(actual - expected_time) < 0.01,
              f"BIB {bib} ({entry['first_name']} {entry['last_name']}): {actual}s = {expected_time}s")

    conn.close()


# ======================================================================
# TEST 2: Template loading
# ======================================================================

def test_templates():
    print("\n" + "=" * 70)
    print("TEST 2: Template loading (all built-in)")
    print("=" * 70)

    names = templates.get_template_names()
    check(len(names) == 8, f"Antal mallar: {len(names)}", "Förväntade 8")

    for name in names:
        tpl = templates.get_template(name)
        check(tpl is not None, f"Mall '{name}' laddad")
        check("controls" in tpl, f"  har controls")
        check("stages" in tpl, f"  har stages")
        check("courses" in tpl, f"  har courses")
        check("classes" in tpl, f"  har classes")


# ======================================================================
# TEST 3: Export/Import round-trip
# ======================================================================

def test_export_import():
    print("\n" + "=" * 70)
    print("TEST 3: Export/Import round-trip")
    print("=" * 70)

    conn = make_db()

    # Create event and load template
    event_id = database.create_event(conn, "Round-trip test", "2026-06-15", fmt="enduro")
    tpl = templates.get_template("Enduro - Tävling")
    check(tpl is not None, "Mall 'Enduro - Tävling' hittad")
    count, warnings = database.import_event_structure(conn, event_id, tpl)
    check(count > 0, f"Import: {count} objekt skapade")
    check(len(warnings) == 0, f"Import utan varningar", f"Varningar: {warnings}")

    # Export — Enduro - Tävling has 10 controls, 5 stages, 1 course, 5 classes
    exported = database.export_event_structure(conn, event_id)
    check(len(exported["controls"]) == 10, f"Export: {len(exported['controls'])} kontroller")
    check(len(exported["stages"]) == 5, f"Export: {len(exported['stages'])} stages")
    check(len(exported["courses"]) == 1, f"Export: {len(exported['courses'])} banor")
    check(len(exported["classes"]) == 5, f"Export: {len(exported['classes'])} klasser")

    # Re-import to new event
    event_id2 = database.create_event(conn, "Import test", "2026-06-16", fmt="enduro")
    count2, warnings2 = database.import_event_structure(conn, event_id2, exported)
    check(count2 == count, f"Re-import: {count2} objekt (samma som original: {count})")

    # Export again and compare
    exported2 = database.export_event_structure(conn, event_id2)
    check(len(exported2["controls"]) == len(exported["controls"]),
          "Round-trip: kontroller matchar")
    check(len(exported2["stages"]) == len(exported["stages"]),
          "Round-trip: stages matchar")
    check(len(exported2["courses"]) == len(exported["courses"]),
          "Round-trip: banor matchar")
    check(len(exported2["classes"]) == len(exported["classes"]),
          "Round-trip: klasser matchar")

    conn.close()


# ======================================================================
# TEST 4: Multi-run downhill (best of 3)
# ======================================================================

def test_multirun_downhill():
    print("\n" + "=" * 70)
    print("TEST 4: Multi-run downhill (best of 3 attempts)")
    print("=" * 70)

    conn = make_db()

    event_id = database.create_event(
        conn, "DH Test", "2026-06-15", fmt="downhill",
        time_precision="hundredths"
    )

    # Create controls and stage
    start_id = database.create_control(conn, event_id, 111, "Start", "start")
    finish_id = database.create_control(conn, event_id, 112, "Mål", "finish")
    stage_id = database.create_stage(
        conn, event_id, 1, "Downhill", start_id, finish_id,
        runs_to_count=1, max_runs=3
    )

    # Create course and class
    course_id = database.create_course(conn, event_id, "DH Course")
    database.link_course_stage(conn, course_id, stage_id, 1)
    class_id = database.create_class(conn, event_id, course_id, "Herr")

    # Create rider
    entry_id = database.create_entry(conn, event_id, 1, "Test", "Rider", "Club", class_id)
    database.create_chip_mapping(conn, event_id, 1, 9999001)

    # Attempt 1: 45.50s
    timing_engine.ingest_punch(conn, event_id, 9999001, 111, "2026-06-15 10:00:00")
    timing_engine.ingest_punch(conn, event_id, 9999001, 112, "2026-06-15 10:00:45")

    # Check attempt 1 created
    sr1 = conn.execute(
        "SELECT * FROM stage_results WHERE event_id=? AND entry_id=? AND attempt=1",
        (event_id, entry_id)
    ).fetchone()
    check(sr1 is not None and sr1["status"] == "ok", "Åk 1: OK (45s)")
    check(sr1 is not None and abs(sr1["elapsed_seconds"] - 45.0) < 0.01,
          f"Åk 1 tid: {sr1['elapsed_seconds'] if sr1 else 'N/A'}s")

    # Attempt 2: 42.30s (better!)
    timing_engine.ingest_punch(conn, event_id, 9999001, 111, "2026-06-15 10:05:00")
    timing_engine.ingest_punch(conn, event_id, 9999001, 112, "2026-06-15 10:05:42")

    sr2 = conn.execute(
        "SELECT * FROM stage_results WHERE event_id=? AND entry_id=? AND attempt=2",
        (event_id, entry_id)
    ).fetchone()
    check(sr2 is not None and sr2["status"] == "ok", "Åk 2: OK (42s)")
    check(sr2 is not None and abs(sr2["elapsed_seconds"] - 42.0) < 0.01,
          f"Åk 2 tid: {sr2['elapsed_seconds'] if sr2 else 'N/A'}s")

    # Attempt 3: 50.00s (worse)
    timing_engine.ingest_punch(conn, event_id, 9999001, 111, "2026-06-15 10:10:00")
    timing_engine.ingest_punch(conn, event_id, 9999001, 112, "2026-06-15 10:10:50")

    sr3 = conn.execute(
        "SELECT * FROM stage_results WHERE event_id=? AND entry_id=? AND attempt=3",
        (event_id, entry_id)
    ).fetchone()
    check(sr3 is not None and sr3["status"] == "ok", "Åk 3: OK (50s)")

    # Attempt 4 should NOT be created (max_runs=3)
    timing_engine.ingest_punch(conn, event_id, 9999001, 111, "2026-06-15 10:15:00")
    sr4 = conn.execute(
        "SELECT * FROM stage_results WHERE event_id=? AND entry_id=? AND attempt=4",
        (event_id, entry_id)
    ).fetchone()
    check(sr4 is None, "Åk 4: Ej skapad (max_runs=3)")

    # Overall result should be best time (42s)
    timing_engine.calculate_overall_results(conn, event_id)
    overall = conn.execute(
        "SELECT * FROM overall_results WHERE event_id=? AND entry_id=?",
        (event_id, entry_id)
    ).fetchone()
    check(overall is not None and overall["status"] == "ok", "Totalresultat: OK")
    check(overall is not None and abs(overall["total_seconds"] - 42.0) < 0.01,
          f"Total (bästa): {overall['total_seconds'] if overall else 'N/A'}s = 42s")

    conn.close()


# ======================================================================
# TEST 5: Festival mode (best 2 of N)
# ======================================================================

def test_festival_mode():
    print("\n" + "=" * 70)
    print("TEST 5: Festival mode (best 2 of unlimited attempts)")
    print("=" * 70)

    conn = make_db()

    event_id = database.create_event(
        conn, "Festival Test", "2026-06-15", fmt="enduro",
        time_precision="seconds"
    )

    # Create 1 stage with runs_to_count=2, max_runs=None (unlimited)
    start_id = database.create_control(conn, event_id, 111, "Start", "start")
    finish_id = database.create_control(conn, event_id, 112, "Mål", "finish")
    stage_id = database.create_stage(
        conn, event_id, 1, "Fun Stage", start_id, finish_id,
        runs_to_count=2, max_runs=None  # unlimited attempts, best 2 count
    )

    course_id = database.create_course(conn, event_id, "Fun", allow_repeat=1)
    database.link_course_stage(conn, course_id, stage_id, 1)
    class_id = database.create_class(conn, event_id, course_id, "Open")

    entry_id = database.create_entry(conn, event_id, 1, "Fun", "Rider", "Club", class_id)
    database.create_chip_mapping(conn, event_id, 1, 8888001)

    # Attempt 1: 60s
    timing_engine.ingest_punch(conn, event_id, 8888001, 111, "2026-06-15 10:00:00")
    timing_engine.ingest_punch(conn, event_id, 8888001, 112, "2026-06-15 10:01:00")

    # Attempt 2: 55s
    timing_engine.ingest_punch(conn, event_id, 8888001, 111, "2026-06-15 10:05:00")
    timing_engine.ingest_punch(conn, event_id, 8888001, 112, "2026-06-15 10:05:55")

    # Attempt 3: 50s
    timing_engine.ingest_punch(conn, event_id, 8888001, 111, "2026-06-15 10:10:00")
    timing_engine.ingest_punch(conn, event_id, 8888001, 112, "2026-06-15 10:10:50")

    # Attempt 4: 45s (best!)
    timing_engine.ingest_punch(conn, event_id, 8888001, 111, "2026-06-15 10:15:00")
    timing_engine.ingest_punch(conn, event_id, 8888001, 112, "2026-06-15 10:15:45")

    # Attempt 5: 52s
    timing_engine.ingest_punch(conn, event_id, 8888001, 111, "2026-06-15 10:20:00")
    timing_engine.ingest_punch(conn, event_id, 8888001, 112, "2026-06-15 10:20:52")

    # Check all 5 attempts created (unlimited)
    attempt_count = conn.execute(
        "SELECT COUNT(*) as cnt FROM stage_results WHERE event_id=? AND entry_id=? AND status='ok'",
        (event_id, entry_id)
    ).fetchone()["cnt"]
    check(attempt_count == 5, f"5 åk skapade (obegränsat): {attempt_count}")

    # Overall: best 2 = 45s + 50s = 95s
    timing_engine.calculate_overall_results(conn, event_id)
    overall = conn.execute(
        "SELECT * FROM overall_results WHERE event_id=? AND entry_id=?",
        (event_id, entry_id)
    ).fetchone()
    check(overall is not None and overall["status"] == "ok", "Totalresultat: OK")
    expected_total = 45.0 + 50.0  # best 2 of [60, 55, 50, 45, 52]
    check(overall is not None and abs(overall["total_seconds"] - expected_total) < 0.01,
          f"Total (bästa 2): {overall['total_seconds'] if overall else 'N/A'}s = {expected_total}s")

    conn.close()


# ======================================================================
# TEST 6: Dual slalom start grouping
# ======================================================================

def test_dual_slalom():
    print("\n" + "=" * 70)
    print("TEST 6: Dual slalom start grouping")
    print("=" * 70)

    conn = make_db()

    event_id = database.create_event(
        conn, "Dual Test", "2026-06-15", fmt="dual_slalom",
        time_precision="hundredths", dual_slalom_window=5.0
    )

    start_id = database.create_control(conn, event_id, 111, "Start", "start")
    finish_id = database.create_control(conn, event_id, 112, "Mål", "finish")
    stage_id = database.create_stage(conn, event_id, 1, "Slalom", start_id, finish_id)

    course_id = database.create_course(conn, event_id, "Dual", allow_repeat=1)
    database.link_course_stage(conn, course_id, stage_id, 1)
    class_id = database.create_class(conn, event_id, course_id, "Herr")

    # Two riders
    e1 = database.create_entry(conn, event_id, 1, "Rider", "A", "Club", class_id)
    e2 = database.create_entry(conn, event_id, 2, "Rider", "B", "Club", class_id)
    database.create_chip_mapping(conn, event_id, 1, 7777001)
    database.create_chip_mapping(conn, event_id, 2, 7777002)

    # Both start within 3 seconds (inside 5s window)
    timing_engine.ingest_punch(conn, event_id, 7777001, 111, "2026-06-15 12:00:00")
    timing_engine.ingest_punch(conn, event_id, 7777002, 111, "2026-06-15 12:00:03")

    # Rider A finishes in 30s from their actual start, Rider B in 28s
    timing_engine.ingest_punch(conn, event_id, 7777001, 112, "2026-06-15 12:00:30")
    timing_engine.ingest_punch(conn, event_id, 7777002, 112, "2026-06-15 12:00:31")

    # Before grouping: A=30s, B=28s (from their individual starts)
    sr_a_before = conn.execute(
        "SELECT elapsed_seconds FROM stage_results WHERE entry_id=? AND attempt=1",
        (e1,)
    ).fetchone()
    sr_b_before = conn.execute(
        "SELECT elapsed_seconds FROM stage_results WHERE entry_id=? AND attempt=1",
        (e2,)
    ).fetchone()
    check(abs(sr_a_before["elapsed_seconds"] - 30.0) < 0.01,
          f"Före gruppering: A = {sr_a_before['elapsed_seconds']}s (individuell)")
    check(abs(sr_b_before["elapsed_seconds"] - 28.0) < 0.01,
          f"Före gruppering: B = {sr_b_before['elapsed_seconds']}s (individuell)")

    # Apply grouping
    groups = timing_engine.group_dual_slalom_starts(conn, event_id, 5.0)
    check(groups >= 1, f"Gruppering: {groups} grupp(er) skapade")

    # After grouping: both get start time 12:00:00 (earliest)
    # A: 12:00:30 - 12:00:00 = 30s
    # B: 12:00:31 - 12:00:00 = 31s
    sr_a_after = conn.execute(
        "SELECT start_time, elapsed_seconds FROM stage_results WHERE entry_id=? AND attempt=1",
        (e1,)
    ).fetchone()
    sr_b_after = conn.execute(
        "SELECT start_time, elapsed_seconds FROM stage_results WHERE entry_id=? AND attempt=1",
        (e2,)
    ).fetchone()

    check(sr_a_after["start_time"] == "2026-06-15 12:00:00",
          f"A starttid: {sr_a_after['start_time']} (grupperad)")
    check(sr_b_after["start_time"] == "2026-06-15 12:00:00",
          f"B starttid: {sr_b_after['start_time']} (grupperad)")
    check(abs(sr_a_after["elapsed_seconds"] - 30.0) < 0.01,
          f"A tid efter gruppering: {sr_a_after['elapsed_seconds']}s")
    check(abs(sr_b_after["elapsed_seconds"] - 31.0) < 0.01,
          f"B tid efter gruppering: {sr_b_after['elapsed_seconds']}s")

    conn.close()


# ======================================================================
# TEST 7: Recalculate-all consistency
# ======================================================================

def test_recalculate_all():
    print("\n" + "=" * 70)
    print("TEST 7: Recalculate-all consistency")
    print("=" * 70)

    conn = make_db()

    event_id = database.create_event(
        conn, "Recalc Test", "2026-02-20",
        location="Väsjön", fmt="enduro",
        time_precision="seconds", roc_competition_id="2256"
    )

    start_ctrl = database.create_control(conn, event_id, 1, "Start S1", "start")
    finish_ctrl = database.create_control(conn, event_id, 22, "Mål S1", "finish")
    database.create_stage(conn, event_id, 1, "Stage 1", start_ctrl, finish_ctrl)

    startlist_path = os.path.join(TEST_DATA_DIR, "sample_startlist.csv")
    timing_engine.import_startlist_csv(conn, event_id, startlist_path)

    chip_path = os.path.join(TEST_DATA_DIR, "sample_chipmapping.csv")
    timing_engine.import_chipmapping_csv(conn, event_id, chip_path)

    punch_path = os.path.join(TEST_DATA_DIR, "real_punches_2026-02-20.csv")
    timing_engine.import_roc_punches(conn, event_id, punch_path)
    timing_engine.calculate_overall_results(conn, event_id)

    # Get results before recalc
    results_before = {}
    for bib in range(1, 9):
        entry = conn.execute(
            "SELECT id FROM entries WHERE event_id=? AND bib=?", (event_id, bib)
        ).fetchone()
        sr = conn.execute(
            "SELECT elapsed_seconds FROM stage_results WHERE event_id=? AND entry_id=?",
            (event_id, entry["id"])
        ).fetchone()
        results_before[bib] = sr["elapsed_seconds"]

    # Full recalculate
    timing_engine.recalculate_all(conn, event_id)

    # Compare
    all_match = True
    for bib in range(1, 9):
        entry = conn.execute(
            "SELECT id FROM entries WHERE event_id=? AND bib=?", (event_id, bib)
        ).fetchone()
        sr = conn.execute(
            "SELECT elapsed_seconds FROM stage_results WHERE event_id=? AND entry_id=?",
            (event_id, entry["id"])
        ).fetchone()
        after = sr["elapsed_seconds"]
        before = results_before[bib]
        if before is not None and after is not None and abs(before - after) > 0.01:
            all_match = False
            check(False, f"BIB {bib}: {before}s → {after}s (ÄNDRAT!)")

    check(all_match, "Recalculate-all ger identiska resultat")

    conn.close()


# ======================================================================
# TEST 8: Safe delete checks
# ======================================================================

def test_safe_deletes():
    print("\n" + "=" * 70)
    print("TEST 8: Safe delete checks")
    print("=" * 70)

    conn = make_db()

    event_id = database.create_event(conn, "Delete Test", "2026-06-15", fmt="enduro")
    ctrl_id = database.create_control(conn, event_id, 111, "Start", "start")
    ctrl_id2 = database.create_control(conn, event_id, 112, "Mål", "finish")

    # Control not referenced → can delete
    ok, msg = database.delete_control(conn, ctrl_id2)
    check(ok, "Ta bort oanvänd kontroll: OK")

    # Re-create and reference in stage
    ctrl_id2 = database.create_control(conn, event_id, 112, "Mål", "finish")
    stage_id = database.create_stage(conn, event_id, 1, "Stage 1", ctrl_id, ctrl_id2)

    ok, msg = database.delete_control(conn, ctrl_id)
    check(not ok, f"Ta bort använd kontroll: vägrad ({msg})")

    # Stage not referenced by results → can delete
    ok, msg = database.delete_stage(conn, stage_id)
    check(ok, "Ta bort stage utan resultat: OK")

    # Course without classes → can delete
    course_id = database.create_course(conn, event_id, "Testbana")
    ok, msg = database.delete_course(conn, course_id)
    check(ok, "Ta bort bana utan klasser: OK")

    # Course with class → refused
    course_id = database.create_course(conn, event_id, "Testbana2")
    class_id = database.create_class(conn, event_id, course_id, "TestKlass")
    ok, msg = database.delete_course(conn, course_id)
    check(not ok, f"Ta bort bana med klass: vägrad ({msg})")

    # Class without entries → can delete
    ok, msg = database.delete_class(conn, class_id)
    check(ok, "Ta bort klass utan åkare: OK")

    conn.close()


# ======================================================================
# TEST 9: BIB-level dedup (dual chip)
# ======================================================================

def test_bib_level_dedup():
    print("\n" + "=" * 70)
    print("TEST 9: BIB-level dedup (dual chip)")
    print("=" * 70)

    conn = make_db()

    event_id = database.create_event(
        conn, "Dedup Test", "2026-06-15", fmt="enduro",
        time_precision="seconds"
    )

    start_id = database.create_control(conn, event_id, 111, "Start", "start")
    finish_id = database.create_control(conn, event_id, 112, "Mål", "finish")
    database.create_stage(conn, event_id, 1, "Stage 1", start_id, finish_id)

    course_id = database.create_course(conn, event_id, "Bana")
    database.link_course_stage(conn, course_id, 1, 1)
    class_id = database.create_class(conn, event_id, course_id, "Herr")
    database.create_entry(conn, event_id, 1, "Test", "Rider", "Club", class_id)

    # Dual chip: SIAC 9001 (primary) and SIAC 9002 (secondary) → BIB 1
    database.create_chip_mapping(conn, event_id, 1, 9001, is_primary=1)
    database.create_chip_mapping(conn, event_id, 1, 9002, is_primary=0)

    # Primary chip punches start
    timing_engine.ingest_punch(conn, event_id, 9001, 111, "2026-06-15 10:00:00")

    # Secondary chip punches same start within 2s → should be BIB-level duplicate
    timing_engine.ingest_punch(conn, event_id, 9002, 111, "2026-06-15 10:00:01")

    # Count non-duplicate punches for control 111
    non_dup = conn.execute(
        "SELECT COUNT(*) as cnt FROM punches WHERE event_id=? AND control_code=111 AND is_duplicate=0",
        (event_id,)
    ).fetchone()["cnt"]
    check(non_dup == 1, f"BIB-dedup: 1 non-dup start (got {non_dup})")

    # Primary finishes
    timing_engine.ingest_punch(conn, event_id, 9001, 112, "2026-06-15 10:01:00")

    # Check result
    sr = conn.execute(
        "SELECT * FROM stage_results WHERE event_id=? AND status='ok'",
        (event_id,)
    ).fetchone()
    check(sr is not None and abs(sr["elapsed_seconds"] - 60.0) < 0.01,
          f"Resultat med dual-chip: {sr['elapsed_seconds'] if sr else 'N/A'}s = 60s")

    conn.close()


# ======================================================================
# TEST 10: Cross-chip resolution
# ======================================================================

def test_cross_chip():
    print("\n" + "=" * 70)
    print("TEST 10: Cross-chip resolution (primary start + secondary finish)")
    print("=" * 70)

    conn = make_db()

    event_id = database.create_event(
        conn, "Cross-chip Test", "2026-06-15", fmt="enduro",
        time_precision="seconds"
    )

    start_id = database.create_control(conn, event_id, 111, "Start", "start")
    finish_id = database.create_control(conn, event_id, 112, "Mål", "finish")
    stage_id = database.create_stage(conn, event_id, 1, "Stage 1", start_id, finish_id)

    course_id = database.create_course(conn, event_id, "Bana")
    database.link_course_stage(conn, course_id, stage_id, 1)
    class_id = database.create_class(conn, event_id, course_id, "Herr")
    entry_id = database.create_entry(conn, event_id, 1, "Test", "Rider", "Club", class_id)

    # Dual chip
    database.create_chip_mapping(conn, event_id, 1, 9001, is_primary=1)
    database.create_chip_mapping(conn, event_id, 1, 9002, is_primary=0)

    # Primary chip starts (BIB 1 gets start)
    timing_engine.ingest_punch(conn, event_id, 9001, 111, "2026-06-15 10:00:00")

    # Check pending result
    sr = conn.execute(
        "SELECT * FROM stage_results WHERE event_id=? AND entry_id=?",
        (event_id, entry_id)
    ).fetchone()
    check(sr is not None and sr["status"] == "pending",
          "Start registrerad (pending)")
    check(sr is not None and sr["start_time"] == "2026-06-15 10:00:00",
          "Starttid korrekt")

    # Primary chip dies mid-stage... secondary chip finishes!
    timing_engine.ingest_punch(conn, event_id, 9002, 112, "2026-06-15 10:00:45")

    # Cross-chip resolution should combine: primary start + secondary finish = 45s
    sr = conn.execute(
        "SELECT * FROM stage_results WHERE event_id=? AND entry_id=? AND status='ok'",
        (event_id, entry_id)
    ).fetchone()
    check(sr is not None, "Cross-chip: resultat skapat")
    check(sr is not None and abs(sr["elapsed_seconds"] - 45.0) < 0.01,
          f"Cross-chip tid: {sr['elapsed_seconds'] if sr else 'N/A'}s = 45s")

    conn.close()


# ======================================================================
# TEST 11: Highlight generation
# ======================================================================

def test_highlights():
    print("\n" + "=" * 70)
    print("TEST 11: Highlight generation")
    print("=" * 70)

    conn = make_db()

    event_id = database.create_event(
        conn, "Highlight Test", "2026-06-15", fmt="enduro",
        time_precision="seconds"
    )

    start_id = database.create_control(conn, event_id, 111, "Start", "start")
    finish_id = database.create_control(conn, event_id, 112, "Mål", "finish")
    stage_id = database.create_stage(conn, event_id, 1, "Stage 1", start_id, finish_id)

    course_id = database.create_course(conn, event_id, "Bana")
    database.link_course_stage(conn, course_id, stage_id, 1)
    class_id = database.create_class(conn, event_id, course_id, "Herr")

    e1 = database.create_entry(conn, event_id, 1, "Fast", "Rider", "Club", class_id)
    e2 = database.create_entry(conn, event_id, 2, "Slow", "Rider", "Club", class_id)
    e3 = database.create_entry(conn, event_id, 3, "Close", "Rider", "Club", class_id)
    database.create_chip_mapping(conn, event_id, 1, 5001)
    database.create_chip_mapping(conn, event_id, 2, 5002)
    database.create_chip_mapping(conn, event_id, 3, 5003)

    # Rider 1: 30s (leader)
    timing_engine.ingest_punch(conn, event_id, 5001, 111, "2026-06-15 10:00:00")
    timing_engine.ingest_punch(conn, event_id, 5001, 112, "2026-06-15 10:00:30")
    timing_engine.calculate_overall_results(conn, event_id)

    # Rider 2: 50s (far behind)
    timing_engine.ingest_punch(conn, event_id, 5002, 111, "2026-06-15 10:05:00")
    timing_engine.ingest_punch(conn, event_id, 5002, 112, "2026-06-15 10:05:50")
    timing_engine.calculate_overall_results(conn, event_id)

    # Rider 3: 31s (close finish — within 2s of leader!)
    timing_engine.ingest_punch(conn, event_id, 5003, 111, "2026-06-15 10:10:00")
    timing_engine.ingest_punch(conn, event_id, 5003, 112, "2026-06-15 10:10:31")
    timing_engine.calculate_overall_results(conn, event_id)

    # Import generate_highlights
    sys.path.insert(0, os.path.join(os.path.dirname(__file__), ".."))
    from api.websocket import generate_highlights

    # Check highlights for rider 3 (close finish)
    highlights = generate_highlights(conn, event_id, e3, stage_id)
    categories = [h["category"] for h in highlights]
    check("close_finish" in categories,
          f"Close finish highlight genererad: {categories}")

    # Check highlights for rider 1 (new leader after rider 2)
    # Rider 1 was first, so at time of their finish there were no others
    # But we can test that leader check works
    highlights_r1 = generate_highlights(conn, event_id, e1, stage_id)
    check(any(h["category"] == "new_leader" for h in highlights_r1),
          f"New leader highlight: {[h['category'] for h in highlights_r1]}")

    conn.close()


# ======================================================================
# TEST 12: API imports (server module)
# ======================================================================

def test_server_imports():
    print("\n" + "=" * 70)
    print("TEST 12: Server + API module imports")
    print("=" * 70)

    try:
        from api.routes import router
        check(len(router.routes) > 30, f"API router: {len(router.routes)} routes")
    except Exception as e:
        check(False, f"API router import: {e}")

    try:
        from api.websocket import manager, generate_highlights
        check(manager is not None, "WebSocket manager importerad")
        check(callable(generate_highlights), "generate_highlights importerbar")
    except Exception as e:
        check(False, f"WebSocket import: {e}")

    try:
        from core.roc_poller import RocPoller
        check(callable(RocPoller), "RocPoller importerbar")
    except Exception as e:
        check(False, f"RocPoller import: {e}")


# ======================================================================
# TEST 13: run_state pending → valid flow
# ======================================================================

def test_run_state_flow():
    print("\n" + "=" * 70)
    print("TEST 13: run_state pending → valid flow")
    print("=" * 70)

    conn = make_db()

    event_id = database.create_event(
        conn, "RunState Test", "2026-06-15", fmt="enduro",
        time_precision="seconds"
    )

    start_id = database.create_control(conn, event_id, 11, "Start SS1", "start")
    finish_id = database.create_control(conn, event_id, 12, "Mål SS1", "finish")
    stage_id = database.create_stage(conn, event_id, 1, "SS1", start_id, finish_id)

    course_id = database.create_course(conn, event_id, "Bana")
    database.link_course_stage(conn, course_id, stage_id, 1)
    class_id = database.create_class(conn, event_id, course_id, "Herr")
    entry_id = database.create_entry(conn, event_id, 1, "Test", "Rider", "Club", class_id)
    database.create_chip_mapping(conn, event_id, 1, 8001001)

    # Punch only start → run_state should be 'pending'
    timing_engine.ingest_punch(conn, event_id, 8001001, 11, "2026-06-15 10:00:00")
    sr = conn.execute(
        "SELECT run_state, status FROM stage_results WHERE event_id=? AND entry_id=?",
        (event_id, entry_id)
    ).fetchone()
    check(sr is not None and sr["run_state"] == "pending",
          f"Start only → run_state='pending' (got {sr['run_state'] if sr else 'N/A'})")
    check(sr is not None and sr["status"] == "pending",
          f"Start only → status='pending' (got {sr['status'] if sr else 'N/A'})")

    # Add finish → run_state should become 'valid'
    timing_engine.ingest_punch(conn, event_id, 8001001, 12, "2026-06-15 10:00:30")
    sr = conn.execute(
        "SELECT run_state, status, elapsed_seconds FROM stage_results WHERE event_id=? AND entry_id=?",
        (event_id, entry_id)
    ).fetchone()
    check(sr is not None and sr["run_state"] == "valid",
          f"Start+finish → run_state='valid' (got {sr['run_state'] if sr else 'N/A'})")
    check(sr is not None and sr["status"] == "ok",
          f"Start+finish → status='ok' (got {sr['status'] if sr else 'N/A'})")
    check(sr is not None and abs(sr["elapsed_seconds"] - 30.0) < 0.01,
          f"Elapsed: {sr['elapsed_seconds'] if sr else 'N/A'}s = 30s")

    conn.close()


# ======================================================================
# TEST 14: Source priority override (USB > ROC)
# ======================================================================

def test_source_priority():
    print("\n" + "=" * 70)
    print("TEST 14: Source priority override (USB > ROC)")
    print("=" * 70)

    conn = make_db()

    event_id = database.create_event(
        conn, "SourcePrio Test", "2026-06-15", fmt="enduro",
        time_precision="seconds"
    )

    start_id = database.create_control(conn, event_id, 11, "Start SS1", "start")
    finish_id = database.create_control(conn, event_id, 12, "Mål SS1", "finish")
    stage_id = database.create_stage(conn, event_id, 1, "SS1", start_id, finish_id)

    course_id = database.create_course(conn, event_id, "Bana")
    database.link_course_stage(conn, course_id, stage_id, 1)
    class_id = database.create_class(conn, event_id, course_id, "Herr")
    entry_id = database.create_entry(conn, event_id, 1, "Test", "Rider", "Club", class_id)
    database.create_chip_mapping(conn, event_id, 1, 8002001)

    # Import ROC data → stage_result created (30s)
    timing_engine.ingest_punch(conn, event_id, 8002001, 11, "2026-06-15 10:00:00", source="roc")
    timing_engine.ingest_punch(conn, event_id, 8002001, 12, "2026-06-15 10:00:30", source="roc")

    sr_roc = conn.execute(
        "SELECT * FROM stage_results WHERE event_id=? AND entry_id=? AND run_state='valid'",
        (event_id, entry_id)
    ).fetchone()
    check(sr_roc is not None and abs(sr_roc["elapsed_seconds"] - 30.0) < 0.01,
          f"ROC result: {sr_roc['elapsed_seconds'] if sr_roc else 'N/A'}s = 30s")

    # Import USB data for same rider+stage (finish time differs: 28s)
    timing_engine.ingest_punch(conn, event_id, 8002001, 12, "2026-06-15 10:00:28", source="usb")

    # Old ROC result should be superseded
    old = conn.execute(
        "SELECT run_state FROM stage_results WHERE id=?", (sr_roc["id"],)
    ).fetchone()
    check(old is not None and old["run_state"] == "superseded",
          f"Old ROC run → superseded (got {old['run_state'] if old else 'N/A'})")

    # New USB result should be valid with 28s
    sr_usb = conn.execute(
        "SELECT * FROM stage_results WHERE event_id=? AND entry_id=? AND run_state='valid'",
        (event_id, entry_id)
    ).fetchone()
    check(sr_usb is not None and abs(sr_usb["elapsed_seconds"] - 28.0) < 0.01,
          f"USB result: {sr_usb['elapsed_seconds'] if sr_usb else 'N/A'}s = 28s")

    # Overall result should use USB time
    timing_engine.calculate_overall_results(conn, event_id)
    overall = conn.execute(
        "SELECT * FROM overall_results WHERE event_id=? AND entry_id=?",
        (event_id, entry_id)
    ).fetchone()
    check(overall is not None and abs(overall["total_seconds"] - 28.0) < 0.01,
          f"Overall uses USB tid: {overall['total_seconds'] if overall else 'N/A'}s = 28s")

    # Verify: manual punch (lower prio) should NOT override USB
    timing_engine.ingest_punch(conn, event_id, 8002001, 12, "2026-06-15 10:00:25", source="manual")
    sr_after_manual = conn.execute(
        "SELECT * FROM stage_results WHERE event_id=? AND entry_id=? AND run_state='valid'",
        (event_id, entry_id)
    ).fetchone()
    check(sr_after_manual is not None and abs(sr_after_manual["elapsed_seconds"] - 28.0) < 0.01,
          f"Manual did not override USB: still {sr_after_manual['elapsed_seconds'] if sr_after_manual else 'N/A'}s")

    conn.close()


# ======================================================================
# TEST 15: Settings table persistence
# ======================================================================

def test_settings():
    print("\n" + "=" * 70)
    print("TEST 15: Settings table persistence")
    print("=" * 70)

    conn = make_db()

    # Default value
    val = database.get_setting(conn, "ingest_paused", "false")
    check(val == "false", f"Default setting: '{val}' = 'false'")

    # Set value
    database.set_setting(conn, "ingest_paused", "true")
    val = database.get_setting(conn, "ingest_paused", "false")
    check(val == "true", f"Set setting: '{val}' = 'true'")

    # Overwrite
    database.set_setting(conn, "ingest_paused", "false")
    val = database.get_setting(conn, "ingest_paused", "false")
    check(val == "false", f"Overwrite setting: '{val}' = 'false'")

    # Multiple keys
    database.set_setting(conn, "standings_frozen", "true")
    check(database.get_setting(conn, "standings_frozen") == "true", "Separate key works")
    check(database.get_setting(conn, "ingest_paused") == "false", "Other key unchanged")

    conn.close()


# ======================================================================
# TEST 16: Recompute idempotency (NEXT.md §4)
# ======================================================================

def test_recompute_idempotent():
    print("\n" + "=" * 70)
    print("TEST 16: Recompute idempotency (run 3 times, identical results)")
    print("=" * 70)

    conn = make_db()

    event_id = database.create_event(
        conn, "Idempotent Test", "2026-02-20",
        location="Väsjön", fmt="enduro",
        time_precision="seconds", roc_competition_id="2256"
    )

    start_ctrl = database.create_control(conn, event_id, 1, "Start S1", "start")
    finish_ctrl = database.create_control(conn, event_id, 22, "Mål S1", "finish")
    database.create_stage(conn, event_id, 1, "Stage 1", start_ctrl, finish_ctrl)

    startlist_path = os.path.join(TEST_DATA_DIR, "sample_startlist.csv")
    timing_engine.import_startlist_csv(conn, event_id, startlist_path)

    chip_path = os.path.join(TEST_DATA_DIR, "sample_chipmapping.csv")
    timing_engine.import_chipmapping_csv(conn, event_id, chip_path)

    punch_path = os.path.join(TEST_DATA_DIR, "real_punches_2026-02-20.csv")
    timing_engine.import_roc_punches(conn, event_id, punch_path)
    timing_engine.calculate_overall_results(conn, event_id)

    # Helper to snapshot results
    def snapshot():
        stage_results = {}
        overall_results = {}
        for bib in range(1, 9):
            entry = conn.execute(
                "SELECT id FROM entries WHERE event_id=? AND bib=?", (event_id, bib)
            ).fetchone()
            sr = conn.execute(
                "SELECT elapsed_seconds, status, run_state FROM stage_results "
                "WHERE event_id=? AND entry_id=? AND run_state='valid'",
                (event_id, entry["id"])
            ).fetchone()
            stage_results[bib] = (
                sr["elapsed_seconds"] if sr else None,
                sr["status"] if sr else None,
            )
            overall = conn.execute(
                "SELECT total_seconds, position, status FROM overall_results "
                "WHERE event_id=? AND entry_id=?",
                (event_id, entry["id"])
            ).fetchone()
            overall_results[bib] = (
                overall["total_seconds"] if overall else None,
                overall["position"] if overall else None,
                overall["status"] if overall else None,
            )
        return stage_results, overall_results

    # Take initial snapshot
    snap0 = snapshot()

    # Run recalculate_all 3 times
    all_identical = True
    for i in range(1, 4):
        diffs = timing_engine.recalculate_all(conn, event_id)
        snap = snapshot()

        if diffs:
            all_identical = False
            check(False, f"Recompute #{i}: {len(diffs)} diff(er)", str(diffs[:5]))
        else:
            check(True, f"Recompute #{i}: inga diff")

        if snap != snap0:
            all_identical = False
            # Find which BIBs differ
            for bib in range(1, 9):
                if snap[0][bib] != snap0[0][bib]:
                    check(False, f"  BIB {bib} stage: {snap0[0][bib]} → {snap[0][bib]}")
                if snap[1][bib] != snap0[1][bib]:
                    check(False, f"  BIB {bib} overall: {snap0[1][bib]} → {snap[1][bib]}")
        else:
            check(True, f"Recompute #{i}: snapshot identisk")

    check(all_identical, "Alla 3 recomputes ger identiskt resultat")

    conn.close()


# ======================================================================
# TEST 17: Sync journal (event logging)
# ======================================================================

def test_sync_journal():
    print("\n" + "=" * 70)
    print("TEST 17: Sync journal (event logging)")
    print("=" * 70)

    conn = make_db()

    event_id = database.create_event(
        conn, "Journal Test", "2026-06-15", fmt="enduro",
        time_precision="seconds"
    )

    start_id = database.create_control(conn, event_id, 11, "Start SS1", "start")
    finish_id = database.create_control(conn, event_id, 12, "Mål SS1", "finish")
    stage_id = database.create_stage(conn, event_id, 1, "SS1", start_id, finish_id)

    course_id = database.create_course(conn, event_id, "Bana")
    database.link_course_stage(conn, course_id, stage_id, 1)
    class_id = database.create_class(conn, event_id, course_id, "Herr")
    entry_id = database.create_entry(conn, event_id, 1, "Test", "Rider", "Club", class_id)
    database.create_chip_mapping(conn, event_id, 1, 8003001)

    # Clear any journal entries from setup
    conn.execute("DELETE FROM sync_queue")
    conn.commit()

    # Ingest start + finish → should create run_created journal entry
    timing_engine.ingest_punch(conn, event_id, 8003001, 11, "2026-06-15 10:00:00", source="roc")
    timing_engine.ingest_punch(conn, event_id, 8003001, 12, "2026-06-15 10:00:30", source="roc")

    events = database.get_journal_events(conn, event_id, unsynced_only=False)
    run_created = [e for e in events if e["data_type"] == "run_created"]
    check(len(run_created) >= 1,
          f"run_created event i journalen: {len(run_created)}")
    if run_created:
        data = json.loads(run_created[0]["data_json"])
        check(data.get("entry_id") == entry_id,
              f"run_created.entry_id = {data.get('entry_id')}")
        check(data.get("elapsed") is not None and abs(data["elapsed"] - 30.0) < 0.01,
              f"run_created.elapsed = {data.get('elapsed')}s")

    # Source override (USB) → should create run_superseded
    timing_engine.ingest_punch(conn, event_id, 8003001, 12, "2026-06-15 10:00:28", source="usb")

    events = database.get_journal_events(conn, event_id, unsynced_only=False)
    run_superseded = [e for e in events if e["data_type"] == "run_superseded"]
    check(len(run_superseded) >= 1,
          f"run_superseded event i journalen: {len(run_superseded)}")
    if run_superseded:
        data = json.loads(run_superseded[0]["data_json"])
        check("usb_override" in data.get("reason", ""),
              f"run_superseded.reason: {data.get('reason')}")

    # All events should be unsynced
    unsynced = database.get_journal_events(conn, event_id, unsynced_only=True)
    total = database.get_journal_events(conn, event_id, unsynced_only=False)
    check(len(unsynced) == len(total),
          f"Alla {len(total)} events osynkade")

    conn.close()


# ======================================================================
# MAIN
# ======================================================================

def main():
    global ERRORS

    test_original_enduro()
    test_templates()
    test_export_import()
    test_multirun_downhill()
    test_festival_mode()
    test_dual_slalom()
    test_recalculate_all()
    test_safe_deletes()
    test_bib_level_dedup()
    test_cross_chip()
    test_highlights()
    test_server_imports()
    test_run_state_flow()
    test_source_priority()
    test_settings()
    test_recompute_idempotent()
    test_sync_journal()

    print("\n" + "=" * 70)
    if ERRORS == 0:
        print("ALLA TESTER PASSERADE! ✓")
    else:
        print(f"FEL: {ERRORS} test(er) misslyckades!")
    print("=" * 70)

    return ERRORS == 0


if __name__ == "__main__":
    success = main()
    sys.exit(0 if success else 1)
