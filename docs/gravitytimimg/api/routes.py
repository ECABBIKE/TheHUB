"""
routes.py — REST API endpoints for GravityTiming.

All endpoints under /api/. Wraps existing CRUD from core/database.py
and timing logic from core/timing_engine.py.

MEMORY.md §7 defines the full API spec.
"""

from __future__ import annotations

import tempfile
import os
import logging
from typing import Optional

from fastapi import APIRouter, HTTPException, UploadFile, File, Query, Request
from pydantic import BaseModel

from core.database import (
    get_connection,
    create_event, get_all_events, get_event, update_event,
    create_control, get_controls, update_control, delete_control,
    create_stage, get_stages, update_stage, delete_stage,
    create_course, get_courses, get_course_stages, update_course,
    delete_course, link_course_stage, unlink_course_stage, reorder_course_stages,
    create_class, get_classes, update_class, delete_class,
    delete_event,
    create_entry, get_entries,
    create_chip_mapping, get_chip_mappings,
    get_punches, get_stage_results, get_overall_results,
    export_event_structure, import_event_structure, clear_event_structure,
    save_event_template, get_event_templates, delete_event_template,
    log_audit, get_audit_log, create_backup, list_backups, restore_backup,
    get_setting, set_setting,
)
from core.timing_engine import (
    ingest_punch, calculate_overall_results, recalculate_all,
    import_startlist_csv, import_chipmapping_csv, import_roc_punches,
    format_elapsed, format_time_behind,
)
from api.websocket import manager as ws_manager, generate_highlights

logger = logging.getLogger("gravitytiming.api")

router = APIRouter()


# ─── Race-day state (persisted in SQLite settings table) ─────────────
# Replaced in-memory RaceDayState with get_setting/set_setting from database.py.
# Settings survive server restarts.


# ─── Helper ──────────────────────────────────────────────────────────

def _row_to_dict(row) -> dict:
    """Convert sqlite3.Row to dict."""
    if row is None:
        return {}
    return dict(row)


def _rows_to_list(rows) -> list[dict]:
    """Convert list of sqlite3.Row to list of dicts."""
    return [dict(r) for r in rows]


def _get_conn():
    return get_connection()


# ─── Pydantic models ─────────────────────────────────────────────────

class EventCreate(BaseModel):
    name: str
    date: str
    location: str = ""
    format: str = "enduro"
    time_precision: str = "seconds"
    roc_competition_id: str = ""
    dual_slalom_window: Optional[float] = None

class EventUpdate(BaseModel):
    name: Optional[str] = None
    date: Optional[str] = None
    location: Optional[str] = None
    format: Optional[str] = None
    stage_order: Optional[str] = None
    time_precision: Optional[str] = None
    roc_competition_id: Optional[str] = None
    dual_slalom_window: Optional[float] = None

class ControlCreate(BaseModel):
    code: int
    name: str
    type: str

class ControlUpdate(BaseModel):
    code: Optional[int] = None
    name: Optional[str] = None
    type: Optional[str] = None

class StageCreate(BaseModel):
    stage_number: int
    name: str
    start_control_id: int
    finish_control_id: int
    is_timed: int = 1
    runs_to_count: int = 1
    max_runs: Optional[int] = None

class StageUpdate(BaseModel):
    stage_number: Optional[int] = None
    name: Optional[str] = None
    start_control_id: Optional[int] = None
    finish_control_id: Optional[int] = None
    is_timed: Optional[int] = None
    runs_to_count: Optional[int] = None
    max_runs: Optional[int] = None

class CourseCreate(BaseModel):
    name: str
    laps: int = 1
    stages_any_order: int = 0
    allow_repeat: int = 0

class CourseUpdate(BaseModel):
    name: Optional[str] = None
    laps: Optional[int] = None
    stages_any_order: Optional[int] = None
    allow_repeat: Optional[int] = None

class CourseStageLinkBody(BaseModel):
    stage_id: int
    stage_order: int

class ClassCreate(BaseModel):
    name: str
    course_id: int
    mass_start_time: Optional[str] = None

class ClassUpdate(BaseModel):
    name: Optional[str] = None
    course_id: Optional[int] = None
    mass_start_time: Optional[str] = None

class EntryCreate(BaseModel):
    bib: int
    first_name: str
    last_name: str
    club: str = ""
    class_id: int

class ChipCreate(BaseModel):
    bib: int
    siac: int
    is_primary: int = 1

class PunchCreate(BaseModel):
    siac: int
    control_code: int
    punch_time: str
    source: str = "manual"

class TemplateCreate(BaseModel):
    name: str
    data_json: str

class RocConfig(BaseModel):
    competition_id: str

class TheHubImport(BaseModel):
    competition_id: str
    base_url: str = "https://thehub.gravityseries.se"


# ═══════════════════════════════════════════════════════════════════════
# EVENTS
# ═══════════════════════════════════════════════════════════════════════

@router.get("/events")
async def list_events(include_finished: bool = True):
    conn = _get_conn()
    try:
        return _rows_to_list(get_all_events(conn, include_finished))
    finally:
        conn.close()


@router.post("/events")
async def create_event_endpoint(body: EventCreate):
    conn = _get_conn()
    try:
        event_id = create_event(
            conn, body.name, body.date, body.location,
            body.format, body.time_precision, body.roc_competition_id,
            body.dual_slalom_window,
        )
        return {"id": event_id}
    finally:
        conn.close()


@router.get("/events/{event_id}")
async def get_event_endpoint(event_id: int):
    conn = _get_conn()
    try:
        event = get_event(conn, event_id)
        if not event:
            raise HTTPException(404, "Event not found")
        return _row_to_dict(event)
    finally:
        conn.close()


@router.delete("/events/{event_id}")
async def delete_event_endpoint(event_id: int):
    conn = _get_conn()
    try:
        event = get_event(conn, event_id)
        if not event:
            raise HTTPException(404, "Event not found")
        delete_event(conn, event_id)
        return {"ok": True}
    finally:
        conn.close()


@router.put("/events/{event_id}")
async def update_event_endpoint(event_id: int, body: EventUpdate):
    conn = _get_conn()
    try:
        fields = {k: v for k, v in body.model_dump().items() if v is not None}
        if fields:
            update_event(conn, event_id, **fields)
        return {"ok": True}
    finally:
        conn.close()


@router.post("/events/{event_id}/activate")
async def activate_event(event_id: int):
    conn = _get_conn()
    try:
        event = get_event(conn, event_id)
        if not event:
            raise HTTPException(404, "Event not found")
        if event["status"] != "setup":
            raise HTTPException(400, "Event is not in setup status")

        # Validate: at least 1 control, 1 stage, 1 class
        controls = get_controls(conn, event_id)
        stages = get_stages(conn, event_id)
        classes = get_classes(conn, event_id)
        if not controls:
            raise HTTPException(400, "Minst 1 kontroll krävs")
        if not stages:
            raise HTTPException(400, "Minst 1 stage krävs")
        if not classes:
            raise HTTPException(400, "Minst 1 klass krävs")

        update_event(conn, event_id, status="active")
        log_audit(conn, event_id, "activate_event", "event", event_id)

        # Start ROC polling if competition ID is set
        if event["roc_competition_id"]:
            from core.roc_poller import RocPoller
            from fastapi import Request
            # ROC poller will be started by the caller if needed
            pass

        return {"ok": True, "status": "active"}
    finally:
        conn.close()


@router.post("/events/{event_id}/finish")
async def finish_event(event_id: int):
    conn = _get_conn()
    try:
        event = get_event(conn, event_id)
        if not event:
            raise HTTPException(404, "Event not found")
        update_event(conn, event_id, status="finished")
        log_audit(conn, event_id, "finish_event", "event", event_id)
        return {"ok": True, "status": "finished"}
    finally:
        conn.close()


# ═══════════════════════════════════════════════════════════════════════
# CONTROLS
# ═══════════════════════════════════════════════════════════════════════

@router.get("/events/{event_id}/controls")
async def list_controls(event_id: int):
    conn = _get_conn()
    try:
        return _rows_to_list(get_controls(conn, event_id))
    finally:
        conn.close()


@router.post("/events/{event_id}/controls")
async def create_control_endpoint(event_id: int, body: ControlCreate):
    conn = _get_conn()
    try:
        cid = create_control(conn, event_id, body.code, body.name, body.type)
        return {"id": cid}
    except Exception as e:
        raise HTTPException(400, str(e))
    finally:
        conn.close()


@router.put("/events/{event_id}/controls/{control_id}")
async def update_control_endpoint(event_id: int, control_id: int, body: ControlUpdate):
    conn = _get_conn()
    try:
        fields = {k: v for k, v in body.model_dump().items() if v is not None}
        if fields:
            update_control(conn, control_id, **fields)
        return {"ok": True}
    finally:
        conn.close()


@router.delete("/events/{event_id}/controls/{control_id}")
async def delete_control_endpoint(event_id: int, control_id: int):
    conn = _get_conn()
    try:
        ok, msg = delete_control(conn, control_id)
        if not ok:
            raise HTTPException(400, msg)
        return {"ok": True}
    finally:
        conn.close()


# ═══════════════════════════════════════════════════════════════════════
# STAGES
# ═══════════════════════════════════════════════════════════════════════

@router.get("/events/{event_id}/stages")
async def list_stages(event_id: int):
    conn = _get_conn()
    try:
        stages = _rows_to_list(get_stages(conn, event_id))
        # Enrich with control codes (not just IDs)
        controls = get_controls(conn, event_id)
        ctrl_id_to_code = {c["id"]: c["code"] for c in controls}
        ctrl_id_to_name = {c["id"]: c["name"] for c in controls}
        for s in stages:
            s["start_control_code"] = ctrl_id_to_code.get(s["start_control_id"])
            s["start_control_name"] = ctrl_id_to_name.get(s["start_control_id"], "?")
            s["finish_control_code"] = ctrl_id_to_code.get(s["finish_control_id"])
            s["finish_control_name"] = ctrl_id_to_name.get(s["finish_control_id"], "?")
        return stages
    finally:
        conn.close()


@router.post("/events/{event_id}/stages")
async def create_stage_endpoint(event_id: int, body: StageCreate):
    conn = _get_conn()
    try:
        sid = create_stage(
            conn, event_id, body.stage_number, body.name,
            body.start_control_id, body.finish_control_id,
            body.is_timed, body.runs_to_count, body.max_runs,
        )
        return {"id": sid}
    except Exception as e:
        raise HTTPException(400, str(e))
    finally:
        conn.close()


@router.put("/events/{event_id}/stages/{stage_id}")
async def update_stage_endpoint(event_id: int, stage_id: int, body: StageUpdate):
    conn = _get_conn()
    try:
        fields = {k: v for k, v in body.model_dump().items() if v is not None}
        if fields:
            update_stage(conn, stage_id, **fields)
        return {"ok": True}
    finally:
        conn.close()


@router.delete("/events/{event_id}/stages/{stage_id}")
async def delete_stage_endpoint(event_id: int, stage_id: int):
    conn = _get_conn()
    try:
        ok, msg = delete_stage(conn, stage_id)
        if not ok:
            raise HTTPException(400, msg)
        return {"ok": True}
    finally:
        conn.close()


# ═══════════════════════════════════════════════════════════════════════
# COURSES
# ═══════════════════════════════════════════════════════════════════════

@router.get("/events/{event_id}/courses")
async def list_courses(event_id: int):
    conn = _get_conn()
    try:
        courses = get_courses(conn, event_id)
        result = []
        for c in courses:
            d = dict(c)
            d["stages"] = _rows_to_list(get_course_stages(conn, c["id"]))
            result.append(d)
        return result
    finally:
        conn.close()


@router.post("/events/{event_id}/courses")
async def create_course_endpoint(event_id: int, body: CourseCreate):
    conn = _get_conn()
    try:
        cid = create_course(
            conn, event_id, body.name, body.laps,
            body.stages_any_order, body.allow_repeat,
        )
        return {"id": cid}
    except Exception as e:
        raise HTTPException(400, str(e))
    finally:
        conn.close()


@router.put("/events/{event_id}/courses/{course_id}")
async def update_course_endpoint(event_id: int, course_id: int, body: CourseUpdate):
    conn = _get_conn()
    try:
        fields = {k: v for k, v in body.model_dump().items() if v is not None}
        if fields:
            update_course(conn, course_id, **fields)
        return {"ok": True}
    finally:
        conn.close()


@router.delete("/events/{event_id}/courses/{course_id}")
async def delete_course_endpoint(event_id: int, course_id: int):
    conn = _get_conn()
    try:
        ok, msg = delete_course(conn, course_id)
        if not ok:
            raise HTTPException(400, msg)
        return {"ok": True}
    finally:
        conn.close()


@router.post("/events/{event_id}/courses/{course_id}/stages")
async def link_course_stage_endpoint(event_id: int, course_id: int,
                                     body: CourseStageLinkBody):
    conn = _get_conn()
    try:
        link_course_stage(conn, course_id, body.stage_id, body.stage_order)
        return {"ok": True}
    except Exception as e:
        raise HTTPException(400, str(e))
    finally:
        conn.close()


@router.delete("/events/{event_id}/courses/{course_id}/stages/{stage_id}")
async def unlink_course_stage_endpoint(event_id: int, course_id: int,
                                       stage_id: int):
    conn = _get_conn()
    try:
        unlink_course_stage(conn, course_id, stage_id)
        return {"ok": True}
    finally:
        conn.close()


# ═══════════════════════════════════════════════════════════════════════
# CLASSES
# ═══════════════════════════════════════════════════════════════════════

@router.get("/events/{event_id}/classes")
async def list_classes(event_id: int):
    conn = _get_conn()
    try:
        return _rows_to_list(get_classes(conn, event_id))
    finally:
        conn.close()


@router.post("/events/{event_id}/classes")
async def create_class_endpoint(event_id: int, body: ClassCreate):
    conn = _get_conn()
    try:
        cid = create_class(
            conn, event_id, body.course_id, body.name, body.mass_start_time,
        )
        return {"id": cid}
    except Exception as e:
        raise HTTPException(400, str(e))
    finally:
        conn.close()


@router.put("/events/{event_id}/classes/{class_id}")
async def update_class_endpoint(event_id: int, class_id: int, body: ClassUpdate):
    conn = _get_conn()
    try:
        fields = {k: v for k, v in body.model_dump().items() if v is not None}
        if fields:
            update_class(conn, class_id, **fields)
        return {"ok": True}
    finally:
        conn.close()


@router.delete("/events/{event_id}/classes/{class_id}")
async def delete_class_endpoint(event_id: int, class_id: int):
    conn = _get_conn()
    try:
        ok, msg = delete_class(conn, class_id)
        if not ok:
            raise HTTPException(400, msg)
        return {"ok": True}
    finally:
        conn.close()


# ═══════════════════════════════════════════════════════════════════════
# ENTRIES
# ═══════════════════════════════════════════════════════════════════════

@router.get("/events/{event_id}/entries")
async def list_entries(event_id: int):
    conn = _get_conn()
    try:
        return _rows_to_list(get_entries(conn, event_id))
    finally:
        conn.close()


@router.post("/events/{event_id}/entries")
async def create_entry_endpoint(event_id: int, body: EntryCreate):
    conn = _get_conn()
    try:
        eid = create_entry(
            conn, event_id, body.bib, body.first_name,
            body.last_name, body.club, body.class_id,
        )
        return {"id": eid}
    except Exception as e:
        raise HTTPException(400, str(e))
    finally:
        conn.close()


@router.delete("/events/{event_id}/entries/{entry_id}")
async def delete_entry_endpoint(event_id: int, entry_id: int):
    conn = _get_conn()
    try:
        conn.execute("DELETE FROM entries WHERE id=? AND event_id=?", (entry_id, event_id))
        conn.commit()
        return {"ok": True}
    finally:
        conn.close()


@router.post("/events/{event_id}/entries/import")
async def import_entries_csv(event_id: int, file: UploadFile = File(...)):
    """Import startlist from CSV (BIB;FirstName;LastName;Club;Class)."""
    conn = _get_conn()
    try:
        # Save uploaded file to temp
        with tempfile.NamedTemporaryFile(mode="wb", suffix=".csv", delete=False) as tmp:
            content = await file.read()
            tmp.write(content)
            tmp_path = tmp.name

        count, warnings = import_startlist_csv(conn, event_id, tmp_path)
        os.unlink(tmp_path)
        return {"count": count, "warnings": warnings}
    except Exception as e:
        raise HTTPException(400, str(e))
    finally:
        conn.close()


# ═══════════════════════════════════════════════════════════════════════
# CHIP MAPPING
# ═══════════════════════════════════════════════════════════════════════

@router.get("/events/{event_id}/chips")
async def list_chips(event_id: int):
    conn = _get_conn()
    try:
        return _rows_to_list(get_chip_mappings(conn, event_id))
    finally:
        conn.close()


@router.post("/events/{event_id}/chips")
async def create_chip_endpoint(event_id: int, body: ChipCreate):
    conn = _get_conn()
    try:
        cid = create_chip_mapping(conn, event_id, body.bib, body.siac, body.is_primary)
        return {"id": cid}
    except Exception as e:
        raise HTTPException(400, str(e))
    finally:
        conn.close()


@router.delete("/events/{event_id}/chips/{chip_id}")
async def delete_chip_endpoint(event_id: int, chip_id: int):
    conn = _get_conn()
    try:
        conn.execute("DELETE FROM chip_mapping WHERE id=? AND event_id=?", (chip_id, event_id))
        conn.commit()
        return {"ok": True}
    finally:
        conn.close()


@router.post("/events/{event_id}/chips/import")
async def import_chips_csv(event_id: int, file: UploadFile = File(...)):
    """Import chip mapping from CSV (BIB;SIAC1;SIAC2)."""
    conn = _get_conn()
    try:
        with tempfile.NamedTemporaryFile(mode="wb", suffix=".csv", delete=False) as tmp:
            content = await file.read()
            tmp.write(content)
            tmp_path = tmp.name

        count, warnings = import_chipmapping_csv(conn, event_id, tmp_path)
        os.unlink(tmp_path)
        return {"count": count, "warnings": warnings}
    except Exception as e:
        raise HTTPException(400, str(e))
    finally:
        conn.close()


# ═══════════════════════════════════════════════════════════════════════
# PUNCHES
# ═══════════════════════════════════════════════════════════════════════

@router.get("/events/{event_id}/punches")
async def list_punches(event_id: int,
                       source: Optional[str] = None,
                       siac: Optional[int] = None,
                       control: Optional[int] = None,
                       dup: Optional[bool] = None):
    conn = _get_conn()
    try:
        query = "SELECT * FROM punches WHERE event_id=?"
        params: list = [event_id]

        if source:
            query += " AND source=?"
            params.append(source)
        if siac:
            query += " AND siac=?"
            params.append(siac)
        if control:
            query += " AND control_code=?"
            params.append(control)
        if dup is not None:
            query += " AND is_duplicate=?"
            params.append(int(dup))

        query += " ORDER BY punch_time DESC, id DESC"
        rows = conn.execute(query, params).fetchall()
        return _rows_to_list(rows)
    finally:
        conn.close()


@router.post("/events/{event_id}/punches")
async def create_punch_endpoint(event_id: int, body: PunchCreate):
    """Manual punch entry. Processes punch through timing engine."""
    conn = _get_conn()
    try:
        if get_setting(conn, "ingest_paused", "false") == "true":
            raise HTTPException(503, "Ingest is paused")
        punch_id = ingest_punch(
            conn, event_id, body.siac, body.control_code,
            body.punch_time, body.source,
        )
        if punch_id:
            calculate_overall_results(conn, event_id)

            # Build broadcast data
            punch_data = _build_punch_broadcast(conn, event_id, punch_id)
            if punch_data:
                await ws_manager.broadcast_punch(event_id, punch_data)

                # Generate and broadcast highlights
                if punch_data.get("entry_id"):
                    stage_id = punch_data.get("stage_id")
                    if stage_id:
                        highlights = generate_highlights(
                            conn, event_id, punch_data["entry_id"], stage_id
                        )
                        for h in highlights:
                            await ws_manager.broadcast_highlight(
                                event_id, h["category"], h["text"],
                                h["bib"], h.get("stage_number"), h.get("priority", "normal"),
                            )

        return {"id": punch_id}
    finally:
        conn.close()


def _build_punch_broadcast(conn, event_id: int, punch_id: int) -> dict:
    """Build full punch broadcast message with stage result and overall."""
    punch = conn.execute("SELECT * FROM punches WHERE id=?", (punch_id,)).fetchone()
    if not punch:
        return {}

    # Resolve BIB
    chip = conn.execute(
        "SELECT bib, is_primary FROM chip_mapping WHERE event_id=? AND siac=?",
        (event_id, punch["siac"])
    ).fetchone()
    if not chip:
        return {"siac": punch["siac"], "control_code": punch["control_code"],
                "punch_time": punch["punch_time"], "source": punch["source"]}

    bib = chip["bib"]
    entry = conn.execute(
        """SELECT e.*, c.name as class_name FROM entries e
           JOIN classes c ON e.class_id = c.id
           WHERE e.event_id=? AND e.bib=?""",
        (event_id, bib)
    ).fetchone()
    if not entry:
        return {"bib": bib, "siac": punch["siac"],
                "control_code": punch["control_code"],
                "punch_time": punch["punch_time"], "source": punch["source"]}

    # Get event precision
    event = conn.execute("SELECT time_precision FROM events WHERE id=?", (event_id,)).fetchone()
    precision = event["time_precision"] if event else "seconds"

    result = {
        "bib": bib,
        "name": f"{entry['first_name']} {entry['last_name']}",
        "class": entry["class_name"],
        "club": entry["club"] or "",
        "control_code": punch["control_code"],
        "punch_time": punch["punch_time"],
        "source": punch["source"],
        "entry_id": entry["id"],
    }

    # Find control type
    ctrl = conn.execute(
        "SELECT type FROM controls WHERE event_id=? AND code=?",
        (event_id, punch["control_code"])
    ).fetchone()
    result["control_type"] = ctrl["type"] if ctrl else "unknown"

    # Find stage result
    stage = conn.execute(
        """SELECT s.* FROM stages s
           JOIN controls c ON (c.id = s.start_control_id OR c.id = s.finish_control_id)
           WHERE s.event_id=? AND c.code=? LIMIT 1""",
        (event_id, punch["control_code"])
    ).fetchone()

    if stage:
        result["stage_id"] = stage["id"]
        sr = conn.execute(
            """SELECT * FROM stage_results
               WHERE event_id=? AND entry_id=? AND stage_id=?
               ORDER BY attempt DESC LIMIT 1""",
            (event_id, entry["id"], stage["id"])
        ).fetchone()

        if sr and sr["status"] == "ok":
            # Get position for this stage
            all_ok = conn.execute(
                """SELECT sr.entry_id, sr.elapsed_seconds FROM stage_results sr
                   WHERE sr.event_id=? AND sr.stage_id=? AND sr.status='ok'
                   ORDER BY sr.elapsed_seconds ASC""",
                (event_id, stage["id"])
            ).fetchall()

            position = 1
            leader_time = all_ok[0]["elapsed_seconds"] if all_ok else sr["elapsed_seconds"]
            for r in all_ok:
                if r["entry_id"] == entry["id"]:
                    break
                position += 1

            behind = sr["elapsed_seconds"] - leader_time

            result["stage_result"] = {
                "stage_id": stage["id"],
                "stage_name": stage["name"],
                "stage_number": stage["stage_number"],
                "elapsed": format_elapsed(sr["elapsed_seconds"], precision),
                "elapsed_seconds": sr["elapsed_seconds"],
                "position": position,
                "behind": format_time_behind(behind, precision),
                "is_leader": position == 1,
                "is_new_leader": position == 1,
            }

    # Overall
    overall = conn.execute(
        "SELECT * FROM overall_results WHERE event_id=? AND entry_id=?",
        (event_id, entry["id"])
    ).fetchone()

    if overall and overall["total_seconds"] is not None:
        stages_total = len(get_stages(conn, event_id))
        stages_done = conn.execute(
            """SELECT COUNT(DISTINCT stage_id) as cnt FROM stage_results
               WHERE event_id=? AND entry_id=? AND status='ok'""",
            (event_id, entry["id"])
        ).fetchone()["cnt"]

        result["overall"] = {
            "total": format_elapsed(overall["total_seconds"], precision),
            "total_seconds": overall["total_seconds"],
            "position": overall["position"],
            "behind": format_time_behind(overall["time_behind"], precision),
            "stages_completed": stages_done,
            "stages_total": stages_total,
        }

    return result


# ═══════════════════════════════════════════════════════════════════════
# RESULTS
# ═══════════════════════════════════════════════════════════════════════

@router.get("/events/{event_id}/stages/{stage_id}/results")
async def get_stage_results_endpoint(event_id: int, stage_id: int,
                                     class_name: Optional[str] = Query(None, alias="class")):
    conn = _get_conn()
    try:
        results = get_stage_results(conn, event_id, stage_id)
        rows = _rows_to_list(results)
        if class_name:
            rows = [r for r in rows if r.get("class_name") == class_name]
        return rows
    finally:
        conn.close()


@router.get("/events/{event_id}/overall")
async def get_overall_endpoint(event_id: int,
                               class_name: Optional[str] = Query(None, alias="class")):
    conn = _get_conn()
    try:
        results = get_overall_results(conn, event_id)
        rows = _rows_to_list(results)
        if class_name:
            rows = [r for r in rows if r.get("class_name") == class_name]
        return rows
    finally:
        conn.close()


@router.post("/events/{event_id}/recalculate")
async def recalculate_endpoint(event_id: int):
    conn = _get_conn()
    try:
        recalculate_all(conn, event_id)
        log_audit(conn, event_id, "recalculate_all", "event", event_id)
        return {"ok": True}
    finally:
        conn.close()


@router.get("/events/{event_id}/export/csv")
async def export_csv_endpoint(event_id: int):
    """Export overall results as CSV download."""
    from fastapi.responses import StreamingResponse
    import io

    conn = _get_conn()
    try:
        event = get_event(conn, event_id)
        if not event:
            raise HTTPException(404, "Event not found")

        precision = event["time_precision"]

        with tempfile.NamedTemporaryFile(mode="w", suffix=".csv", delete=False,
                                         encoding="utf-8") as tmp:
            tmp_path = tmp.name

        from core.timing_engine import export_overall_results_csv
        export_overall_results_csv(conn, event_id, tmp_path, precision)

        with open(tmp_path, "r", encoding="utf-8") as f:
            content = f.read()
        os.unlink(tmp_path)

        return StreamingResponse(
            io.StringIO(content),
            media_type="text/csv",
            headers={"Content-Disposition": f"attachment; filename=results_{event_id}.csv"},
        )
    finally:
        conn.close()


# ═══════════════════════════════════════════════════════════════════════
# TEMPLATES
# ═══════════════════════════════════════════════════════════════════════

@router.get("/templates")
async def list_templates():
    """List built-in + user-saved templates."""
    from core.templates import get_template_names, get_template
    import json

    builtin = []
    for name in get_template_names():
        tpl = get_template(name)
        builtin.append({
            "name": name,
            "type": "builtin",
            "format": tpl.get("format", "enduro") if tpl else "enduro",
        })

    conn = _get_conn()
    try:
        user_templates = get_event_templates(conn)
        user = [{"id": t["id"], "name": t["name"], "type": "user"} for t in user_templates]
        return {"builtin": builtin, "user": user}
    finally:
        conn.close()


@router.post("/templates")
async def save_template_endpoint(body: TemplateCreate):
    """Save a user template."""
    conn = _get_conn()
    try:
        tid = save_event_template(conn, body.name, body.data_json)
        return {"id": tid}
    finally:
        conn.close()


@router.delete("/templates/{template_id}")
async def delete_template_endpoint(template_id: int):
    conn = _get_conn()
    try:
        delete_event_template(conn, template_id)
        return {"ok": True}
    finally:
        conn.close()


@router.post("/events/{event_id}/apply-template")
async def apply_template(event_id: int, name: str = Query(...)):
    """Apply a template to an event. Clears existing structure first."""
    import json
    from core.templates import get_template

    conn = _get_conn()
    try:
        # Try built-in first
        tpl = get_template(name)
        if tpl is None:
            # Try user template
            user_tpl = conn.execute(
                "SELECT data_json FROM event_templates WHERE name=?", (name,)
            ).fetchone()
            if user_tpl:
                tpl = json.loads(user_tpl["data_json"])
            else:
                raise HTTPException(404, f"Template '{name}' not found")

        # Clear existing structure before importing new template
        clear_event_structure(conn, event_id)
        count, warnings = import_event_structure(conn, event_id, tpl)
        return {"count": count, "warnings": warnings}
    finally:
        conn.close()


@router.get("/events/{event_id}/structure")
async def get_event_structure(event_id: int):
    """Export event structure as JSON (portable format)."""
    conn = _get_conn()
    try:
        structure = export_event_structure(conn, event_id)
        if not structure:
            raise HTTPException(404, "Event not found")
        return structure
    finally:
        conn.close()


@router.post("/events/{event_id}/structure")
async def import_event_structure_endpoint(event_id: int, structure: dict):
    """Import event structure from JSON."""
    conn = _get_conn()
    try:
        count, warnings = import_event_structure(conn, event_id, structure)
        return {"count": count, "warnings": warnings}
    finally:
        conn.close()


# ═══════════════════════════════════════════════════════════════════════
# RACE-DAY CONTROLS
# ═══════════════════════════════════════════════════════════════════════

@router.post("/race/pause-ingest")
async def pause_ingest():
    """Pause all punch ingestion (ROC, USB, manual)."""
    conn = _get_conn()
    try:
        set_setting(conn, "ingest_paused", "true")
        log_audit(conn, None, "pause_ingest", details="Ingest paused")
    finally:
        conn.close()
    await ws_manager.broadcast({"type": "race_control", "action": "ingest_paused"})
    return {"ok": True, "ingest_paused": True}


@router.post("/race/resume-ingest")
async def resume_ingest():
    """Resume punch ingestion."""
    conn = _get_conn()
    try:
        set_setting(conn, "ingest_paused", "false")
        log_audit(conn, None, "resume_ingest", details="Ingest resumed")
    finally:
        conn.close()
    await ws_manager.broadcast({"type": "race_control", "action": "ingest_resumed"})
    return {"ok": True, "ingest_paused": False}


@router.post("/race/freeze-standings")
async def freeze_standings():
    """Freeze public standings (displays stop updating, punches still logged)."""
    conn = _get_conn()
    try:
        set_setting(conn, "standings_frozen", "true")
        log_audit(conn, None, "freeze_standings", details="Standings frozen")
    finally:
        conn.close()
    await ws_manager.broadcast({"type": "race_control", "action": "standings_frozen"})
    return {"ok": True, "standings_frozen": True}


@router.post("/race/unfreeze-standings")
async def unfreeze_standings():
    """Unfreeze public standings."""
    conn = _get_conn()
    try:
        set_setting(conn, "standings_frozen", "false")
        log_audit(conn, None, "unfreeze_standings", details="Standings unfrozen")
    finally:
        conn.close()
    await ws_manager.broadcast({"type": "race_control", "action": "standings_unfrozen"})
    return {"ok": True, "standings_frozen": False}


@router.get("/race/state")
async def get_race_state():
    """Get current race-day control state."""
    conn = _get_conn()
    try:
        return {
            "ingest_paused": get_setting(conn, "ingest_paused", "false") == "true",
            "standings_frozen": get_setting(conn, "standings_frozen", "false") == "true",
        }
    finally:
        conn.close()


# ═══════════════════════════════════════════════════════════════════════
# AUDIT LOG
# ═══════════════════════════════════════════════════════════════════════

@router.get("/events/{event_id}/audit")
async def get_event_audit(event_id: int, limit: int = 100):
    """Get audit log for an event."""
    conn = _get_conn()
    try:
        return _rows_to_list(get_audit_log(conn, event_id, limit))
    finally:
        conn.close()


@router.get("/audit")
async def get_all_audit(limit: int = 100):
    """Get full audit log."""
    conn = _get_conn()
    try:
        return _rows_to_list(get_audit_log(conn, limit=limit))
    finally:
        conn.close()


# ═══════════════════════════════════════════════════════════════════════
# BACKUP / RESTORE
# ═══════════════════════════════════════════════════════════════════════

@router.post("/backup")
async def create_backup_endpoint(label: str = ""):
    """Create a database backup."""
    path = create_backup(label)
    conn = _get_conn()
    try:
        log_audit(conn, None, "backup_created", details=f"Backup: {path.name}")
    finally:
        conn.close()
    return {"ok": True, "filename": path.name, "path": str(path)}


@router.get("/backups")
async def list_backups_endpoint():
    """List all available backups."""
    return list_backups()


@router.post("/restore/{filename}")
async def restore_backup_endpoint(filename: str):
    """Restore database from a backup. WARNING: replaces current data."""
    conn = _get_conn()
    try:
        log_audit(conn, None, "restore_started", details=f"Restoring from: {filename}")
    finally:
        conn.close()

    ok = restore_backup(filename)
    if not ok:
        raise HTTPException(404, f"Backup '{filename}' not found")
    return {"ok": True, "restored_from": filename}


@router.get("/backup/download")
async def download_backup():
    """Download current database as a backup file."""
    from fastapi.responses import FileResponse

    path = create_backup("download")
    return FileResponse(
        str(path),
        media_type="application/octet-stream",
        filename=path.name,
    )


# ═══════════════════════════════════════════════════════════════════════
# CONNECTIONS — ROC
# ═══════════════════════════════════════════════════════════════════════

@router.post("/roc/start")
async def roc_start(request: Request):
    """Start ROC polling for the active event."""
    conn = _get_conn()
    try:
        active = conn.execute(
            "SELECT * FROM events WHERE status='active' ORDER BY id DESC LIMIT 1"
        ).fetchone()
        if not active:
            raise HTTPException(400, "Inget aktivt event")
        if not active["roc_competition_id"]:
            raise HTTPException(400, "Inget ROC tävlings-ID konfigurerat")

        # Stop existing poller if running
        if request.app.state.roc_poller and request.app.state.roc_poller.is_running:
            await request.app.state.roc_poller.stop()

        event_id = active["id"]

        async def handle_roc_punch(punch: dict):
            c = _get_conn()
            try:
                if get_setting(c, "ingest_paused", "false") == "true":
                    return
                punch_id = ingest_punch(
                    c, event_id, punch["siac"], punch["control_code"],
                    punch["punch_time"], source="roc",
                    roc_punch_id=punch.get("roc_punch_id"),
                )
                if punch_id:
                    calculate_overall_results(c, event_id)
                    punch_data = _build_punch_broadcast(c, event_id, punch_id)
                    if punch_data:
                        await ws_manager.broadcast_punch(event_id, punch_data)
                        if punch_data.get("entry_id"):
                            stage_id = punch_data.get("stage_id")
                            if stage_id:
                                highlights = generate_highlights(
                                    c, event_id, punch_data["entry_id"], stage_id
                                )
                                for h in highlights:
                                    await ws_manager.broadcast_highlight(
                                        event_id, h["category"], h["text"],
                                        h["bib"], h.get("stage_number"),
                                        h.get("priority", "normal"),
                                    )
            finally:
                c.close()

        from core.roc_poller import RocPoller
        poller = RocPoller(
            competition_id=active["roc_competition_id"],
            on_punch=handle_roc_punch,
        )
        request.app.state.roc_poller = poller
        await poller.start()

        log_audit(conn, event_id, "roc_start", "connection",
                  f"ROC polling started, ID={active['roc_competition_id']}")
        return {"ok": True, "competition_id": active["roc_competition_id"]}
    finally:
        conn.close()


@router.post("/roc/stop")
async def roc_stop(request: Request):
    """Stop ROC polling."""
    poller = request.app.state.roc_poller
    if poller and poller.is_running:
        await poller.stop()
    return {"ok": True}


@router.get("/roc/status")
async def roc_status(request: Request):
    """Get ROC poller status."""
    poller = request.app.state.roc_poller
    if poller:
        return poller.get_status()
    return {
        "is_running": False,
        "status": "Stoppad",
        "punch_count": 0,
        "error_count": 0,
        "last_poll": None,
        "last_id": 0,
        "competition_id": None,
    }


@router.put("/roc/config")
async def roc_update_config(body: RocConfig):
    """Update ROC competition ID on the active event."""
    conn = _get_conn()
    try:
        active = conn.execute(
            "SELECT * FROM events WHERE status='active' ORDER BY id DESC LIMIT 1"
        ).fetchone()
        if not active:
            raise HTTPException(400, "Inget aktivt event")
        update_event(conn, active["id"], roc_competition_id=body.competition_id)
        return {"ok": True, "competition_id": body.competition_id}
    finally:
        conn.close()


# ═══════════════════════════════════════════════════════════════════════
# CONNECTIONS — USB
# ═══════════════════════════════════════════════════════════════════════

@router.get("/usb/ports")
async def list_serial_ports():
    """List available serial ports."""
    try:
        from serial.tools.list_ports import comports
        ports = []
        for port in comports():
            ports.append({
                "device": port.device,
                "description": port.description,
                "hwid": port.hwid,
                "manufacturer": port.manufacturer or "",
            })
        return {"ports": ports}
    except ImportError:
        return {"ports": [], "error": "pyserial ej installerat"}


@router.get("/usb/status")
async def usb_status(request: Request):
    """Get USB reader status."""
    reader = getattr(request.app.state, "usb_reader", None)
    if reader:
        return reader.get_status()
    return {
        "is_running": False,
        "status": "Stoppad",
        "punch_count": 0,
        "port": None,
    }


@router.post("/usb/start")
async def usb_start(request: Request):
    """Start USB reader (not yet implemented)."""
    raise HTTPException(501, "USB-läsare är inte implementerad ännu")


@router.post("/usb/stop")
async def usb_stop(request: Request):
    """Stop USB reader (not yet implemented)."""
    raise HTTPException(501, "USB-läsare är inte implementerad ännu")


# ═══════════════════════════════════════════════════════════════════════
# CONNECTIONS — TheHUB
# ═══════════════════════════════════════════════════════════════════════

@router.post("/events/{event_id}/import-thehub")
async def import_from_thehub(event_id: int, body: TheHubImport):
    """Import startlist from TheHUB API."""
    conn = _get_conn()
    try:
        event = get_event(conn, event_id)
        if not event:
            raise HTTPException(404, "Event not found")

        from core.hub_client import fetch_startlist
        try:
            entries = await fetch_startlist(body.base_url, body.competition_id)
        except Exception as e:
            raise HTTPException(502, f"Kunde inte hämta från TheHUB: {e}")

        if not entries:
            return {"count": 0, "warnings": ["Inga deltagare hittades"]}

        # Get existing classes and courses
        classes = get_classes(conn, event_id)
        courses = get_courses(conn, event_id)

        # Create default course if none exists
        if not courses:
            from core.database import create_course
            create_course(conn, event_id, "Huvudbana")
            courses = get_courses(conn, event_id)

        default_course_id = courses[0]["id"]
        class_map = {c["name"]: c["id"] for c in classes}

        count = 0
        warnings = []
        for entry in entries:
            bib = entry.get("bib")
            first_name = entry.get("first_name", "").strip()
            last_name = entry.get("last_name", "").strip()
            club = entry.get("club", "").strip()
            class_name = entry.get("class_name", "Open").strip()

            if not bib or not first_name:
                warnings.append(f"Hoppar över rad utan BIB/namn: {entry}")
                continue

            # Create class if not exists
            if class_name not in class_map:
                from core.database import create_class
                cls_id = create_class(conn, event_id, class_name, default_course_id)
                class_map[class_name] = cls_id

            # Upsert entry
            existing = conn.execute(
                "SELECT id FROM entries WHERE event_id=? AND bib=?",
                (event_id, bib)
            ).fetchone()

            if existing:
                conn.execute(
                    """UPDATE entries SET first_name=?, last_name=?, club=?, class_id=?
                       WHERE id=?""",
                    (first_name, last_name, club, class_map[class_name], existing["id"])
                )
            else:
                create_entry(conn, event_id, bib, first_name, last_name,
                             club, class_map[class_name])
            count += 1

        conn.commit()
        log_audit(conn, event_id, "import_thehub", "entries",
                  f"Importerade {count} deltagare från TheHUB (competition {body.competition_id})")
        return {"count": count, "warnings": warnings}
    finally:
        conn.close()


@router.post("/events/{event_id}/preview-thehub")
async def preview_thehub(event_id: int, body: TheHubImport):
    """Preview startlist from TheHUB without importing."""
    conn = _get_conn()
    try:
        event = get_event(conn, event_id)
        if not event:
            raise HTTPException(404, "Event not found")

        from core.hub_client import fetch_startlist
        try:
            entries = await fetch_startlist(body.base_url, body.competition_id)
        except Exception as e:
            raise HTTPException(502, f"Kunde inte hämta från TheHUB: {e}")

        return {"entries": entries, "count": len(entries)}
    finally:
        conn.close()


# ═══════════════════════════════════════════════════════════════════════
# SYSTEM STATUS
# ═══════════════════════════════════════════════════════════════════════

@router.get("/status")
async def system_status(request: Request):
    conn = _get_conn()
    try:
        # Active event
        active = conn.execute(
            "SELECT * FROM events WHERE status='active' ORDER BY id DESC LIMIT 1"
        ).fetchone()

        punch_count = 0
        if active:
            punch_count = conn.execute(
                "SELECT COUNT(*) as cnt FROM punches WHERE event_id=?",
                (active["id"],)
            ).fetchone()["cnt"]

        roc_poller = request.app.state.roc_poller
        return {
            "server": "GravityTiming",
            "version": "2.0",
            "active_event": _row_to_dict(active) if active else None,
            "punch_count": punch_count,
            "ws_connections": ws_manager.connection_count,
            "race_state": {
                "ingest_paused": get_setting(conn, "ingest_paused", "false") == "true",
                "standings_frozen": get_setting(conn, "standings_frozen", "false") == "true",
            },
            "roc_status": roc_poller.get_status() if roc_poller else {
                "is_running": False, "status": "Stoppad"
            },
        }
    finally:
        conn.close()
