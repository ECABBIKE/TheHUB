"""
templates.py — Built-in event structure templates.

Each template is a dict matching the export_event_structure / import_event_structure
format in database.py. Templates use control codes and stage numbers as references
(not database IDs) so they are portable.

Control code convention (matching beacon programming):
  Enduro:   SS1 = 11/12, SS2 = 21/22, SS3 = 31/32, ... SSn = n1/n2
  Downhill: Start=12, Mellantid1=22, Mellantid2=32, Mellantid3=42, Mål=52

Templates based on real GravitySeries race formats:
  - Downhill - Kval/Final    (qualifying sets start order, final counts)
  - Downhill - 2 åk          (best of 2 runs)
  - Enduro - SportMotion      (2-5 stages, 2 laps in fixed order)
  - Enduro - Tävling          (4-12 stages, fixed order, 1 run each)
  - Enduro - Festival         (1-4 stages, free runs, best 1-2 count)
  - Dual Slalom               (2 qualifying runs + head-to-head bracket)
  - XCM                       (point-to-point with splits)
  - XCO                       (lap course with optional splits)
"""

from __future__ import annotations


# ─── Helpers ──────────────────────────────────────────────────────────

def _enduro_controls(n: int) -> list[dict]:
    """Generate n stages of start/finish controls.

    SS1: Start=11, Mål=12
    SS2: Start=21, Mål=22
    SSn: Start=n*10+1, Mål=n*10+2
    """
    controls = []
    for i in range(1, n + 1):
        controls.append({"code": i * 10 + 1, "name": f"Start SS{i}", "type": "start"})
        controls.append({"code": i * 10 + 2, "name": f"Mål SS{i}", "type": "finish"})
    return controls


def _enduro_stages(n: int, runs_to_count: int = 1,
                   max_runs: int | None = None) -> list[dict]:
    """Generate n stage definitions with standard enduro control codes."""
    return [
        {
            "stage_number": i,
            "name": f"SS{i}",
            "start_control_code": i * 10 + 1,
            "finish_control_code": i * 10 + 2,
            "is_timed": 1,
            "runs_to_count": runs_to_count,
            "max_runs": max_runs,
        }
        for i in range(1, n + 1)
    ]


# ─── Standard class sets ─────────────────────────────────────────────

STANDARD_CLASSES_5 = [
    "Herr Elite", "Dam Elite", "Herr Hobby", "Dam Hobby", "Ungdom"
]

STANDARD_CLASSES_3 = ["Herr Elite", "Dam Elite", "Open"]

STANDARD_CLASSES_2 = ["Herr", "Dam"]


# ─── Templates ────────────────────────────────────────────────────────

BUILTIN_TEMPLATES: dict[str, dict] = {

    # ==================================================================
    # DOWNHILL — KVAL/FINAL
    # Qualifying run determines start order for final.
    # Final run determines placement.
    # Two separate stages: Kval (stage 1) and Final (stage 2).
    # Same physical course, same beacons, but two separate timed runs.
    # Controls: Start=12, Mellantid 1=22, Mellantid 2=32, Mål=52
    # ==================================================================
    "Downhill - Kval/Final": {
        "format": "downhill",
        "stage_order": "fixed",
        "time_precision": "hundredths",
        "dual_slalom_window": None,
        "controls": [
            {"code": 12, "name": "Start", "type": "start"},
            {"code": 22, "name": "Mellantid 1", "type": "split"},
            {"code": 32, "name": "Mellantid 2", "type": "split"},
            {"code": 52, "name": "Mål", "type": "finish"},
        ],
        "stages": [
            {
                "stage_number": 1,
                "name": "Kval",
                "start_control_code": 12,
                "finish_control_code": 52,
                "is_timed": 1,
                "runs_to_count": 1,
                "max_runs": 1,
            },
            {
                "stage_number": 2,
                "name": "Final",
                "start_control_code": 12,
                "finish_control_code": 52,
                "is_timed": 1,
                "runs_to_count": 1,
                "max_runs": 1,
            },
        ],
        "courses": [
            {
                "name": "Downhill KF",
                "laps": 1,
                "stages_any_order": 0,
                "allow_repeat": 0,
                "stage_numbers": [1, 2],
            }
        ],
        "classes": [
            {"name": c, "course_name": "Downhill KF"} for c in STANDARD_CLASSES_5
        ],
    },

    # ==================================================================
    # DOWNHILL — 2 ÅK
    # Two runs on same course, best time counts.
    # Single stage with max_runs=2, runs_to_count=1 (best of 2).
    # Controls: Start=12, Mellantid 1=22, Mellantid 2=32, Mål=52
    # ==================================================================
    "Downhill - 2 åk": {
        "format": "downhill",
        "stage_order": "fixed",
        "time_precision": "hundredths",
        "dual_slalom_window": None,
        "controls": [
            {"code": 12, "name": "Start", "type": "start"},
            {"code": 22, "name": "Mellantid 1", "type": "split"},
            {"code": 32, "name": "Mellantid 2", "type": "split"},
            {"code": 52, "name": "Mål", "type": "finish"},
        ],
        "stages": [
            {
                "stage_number": 1,
                "name": "Downhill",
                "start_control_code": 12,
                "finish_control_code": 52,
                "is_timed": 1,
                "runs_to_count": 1,
                "max_runs": 2,
            }
        ],
        "courses": [
            {
                "name": "Downhill",
                "laps": 1,
                "stages_any_order": 0,
                "allow_repeat": 1,
                "stage_numbers": [1],
            }
        ],
        "classes": [
            {"name": c, "course_name": "Downhill"} for c in STANDARD_CLASSES_5
        ],
    },

    # ==================================================================
    # ENDURO — SPORTMOTION
    # 2-5 stages, all run twice (2 laps in fixed order).
    # Default: 3 stages. Organizer adjusts stage count in Setup.
    # Controls: SS1=11/12, SS2=21/22, SS3=31/32
    # ==================================================================
    "Enduro - SportMotion": {
        "format": "enduro",
        "stage_order": "fixed",
        "time_precision": "seconds",
        "dual_slalom_window": None,
        "controls": _enduro_controls(3),
        "stages": _enduro_stages(3, runs_to_count=2, max_runs=2),
        "courses": [
            {
                "name": "SportMotion",
                "laps": 2,
                "stages_any_order": 0,
                "allow_repeat": 1,
                "stage_numbers": [1, 2, 3],
            }
        ],
        "classes": [
            {"name": c, "course_name": "SportMotion"} for c in STANDARD_CLASSES_5
        ],
    },

    # ==================================================================
    # ENDURO — TÄVLING
    # 4-12 stages in fixed order, 1 run each.
    # Default: 5 stages. Organizer adds/removes stages in Setup.
    # Controls: SS1=11/12, SS2=21/22, SS3=31/32, SS4=41/42, SS5=51/52
    # ==================================================================
    "Enduro - Tävling": {
        "format": "enduro",
        "stage_order": "fixed",
        "time_precision": "seconds",
        "dual_slalom_window": None,
        "controls": _enduro_controls(5),
        "stages": _enduro_stages(5),
        "courses": [
            {
                "name": "Huvudbana",
                "laps": 1,
                "stages_any_order": 0,
                "allow_repeat": 0,
                "stage_numbers": [1, 2, 3, 4, 5],
            }
        ],
        "classes": [
            {"name": c, "course_name": "Huvudbana"} for c in STANDARD_CLASSES_5
        ],
    },

    # ==================================================================
    # ENDURO — FESTIVAL
    # 1-4 stages, free order, unlimited runs, best 1-2 count.
    # Can run as 1-5 day event.
    # Controls: SS1=11/12, SS2=21/22, SS3=31/32
    # ==================================================================
    "Enduro - Festival": {
        "format": "enduro",
        "stage_order": "free",
        "time_precision": "seconds",
        "dual_slalom_window": None,
        "controls": _enduro_controls(3),
        "stages": _enduro_stages(3, runs_to_count=1, max_runs=None),
        "courses": [
            {
                "name": "Festival",
                "laps": 1,
                "stages_any_order": 1,
                "allow_repeat": 1,
                "stage_numbers": [1, 2, 3],
            }
        ],
        "classes": [
            {"name": "Open", "course_name": "Festival"},
        ],
    },

    # ==================================================================
    # DUAL SLALOM
    # 2 qualifying runs (timed), then head-to-head elimination bracket.
    # Same control codes as Downhill (Start=12, Mål=52).
    # ==================================================================
    "Dual Slalom": {
        "format": "dual_slalom",
        "stage_order": "fixed",
        "time_precision": "hundredths",
        "dual_slalom_window": 5.0,
        "controls": [
            {"code": 12, "name": "Start", "type": "start"},
            {"code": 52, "name": "Mål", "type": "finish"},
        ],
        "stages": [
            {
                "stage_number": 1,
                "name": "Slalom",
                "start_control_code": 12,
                "finish_control_code": 52,
                "is_timed": 1,
                "runs_to_count": 1,
                "max_runs": None,
            }
        ],
        "courses": [
            {
                "name": "Dual Slalom",
                "laps": 1,
                "stages_any_order": 0,
                "allow_repeat": 1,
                "stage_numbers": [1],
            }
        ],
        "classes": [
            {"name": "Herr", "course_name": "Dual Slalom"},
            {"name": "Dam", "course_name": "Dual Slalom"},
        ],
    },

    # ==================================================================
    # XCM — CROSS-COUNTRY MARATHON
    # Point-to-point or large loop. Start, splits, finish.
    # Same control code pattern as Downhill: Start=12, splits=22/32, Mål=52
    # ==================================================================
    "XCM": {
        "format": "xc",
        "stage_order": "fixed",
        "time_precision": "seconds",
        "dual_slalom_window": None,
        "controls": [
            {"code": 12, "name": "Start", "type": "start"},
            {"code": 22, "name": "Mellantid 1", "type": "split"},
            {"code": 32, "name": "Mellantid 2", "type": "split"},
            {"code": 52, "name": "Mål", "type": "finish"},
        ],
        "stages": [
            {
                "stage_number": 1,
                "name": "XCM",
                "start_control_code": 12,
                "finish_control_code": 52,
                "is_timed": 1,
                "runs_to_count": 1,
                "max_runs": 1,
            }
        ],
        "courses": [
            {
                "name": "XCM",
                "laps": 1,
                "stages_any_order": 0,
                "allow_repeat": 0,
                "stage_numbers": [1],
            }
        ],
        "classes": [
            {"name": c, "course_name": "XCM"} for c in STANDARD_CLASSES_5
        ],
    },

    # ==================================================================
    # XCO — CROSS-COUNTRY OLYMPIC
    # Lap course. Multiple laps per class. Optional split controls.
    # Controls: Start=12, Mellantid=22, Mål/Varv=52
    # ==================================================================
    "XCO": {
        "format": "xc",
        "stage_order": "fixed",
        "time_precision": "seconds",
        "dual_slalom_window": None,
        "controls": [
            {"code": 12, "name": "Start", "type": "start"},
            {"code": 22, "name": "Mellantid", "type": "split"},
            {"code": 52, "name": "Mål/Varv", "type": "finish"},
        ],
        "stages": [
            {
                "stage_number": 1,
                "name": "Varv",
                "start_control_code": 12,
                "finish_control_code": 52,
                "is_timed": 1,
                "runs_to_count": 1,
                "max_runs": None,
            }
        ],
        "courses": [
            {
                "name": "XCO",
                "laps": 4,
                "stages_any_order": 0,
                "allow_repeat": 0,
                "stage_numbers": [1],
            }
        ],
        "classes": [
            {"name": "Herr Elite", "course_name": "XCO"},
            {"name": "Dam Elite", "course_name": "XCO"},
            {"name": "Herr Hobby", "course_name": "XCO"},
            {"name": "Dam Hobby", "course_name": "XCO"},
        ],
    },
}


TEMPLATE_ORDER = [
    "Enduro - Tävling",
    "Enduro - SportMotion",
    "Enduro - Festival",
    "Downhill - Kval/Final",
    "Downhill - 2 åk",
    "Dual Slalom",
    "XCO",
    "XCM",
]


def get_template_names() -> list[str]:
    """Return template names in preferred display order."""
    ordered = [n for n in TEMPLATE_ORDER if n in BUILTIN_TEMPLATES]
    # Append any new templates not yet in TEMPLATE_ORDER
    for n in sorted(BUILTIN_TEMPLATES.keys()):
        if n not in ordered:
            ordered.append(n)
    return ordered


def get_template(name: str) -> dict | None:
    """Return a copy of the named template, or None."""
    tpl = BUILTIN_TEMPLATES.get(name)
    if tpl is None:
        return None
    # Deep copy via dict comprehension to avoid mutation
    import copy
    return copy.deepcopy(tpl)
