"""
websocket.py — WebSocket manager and broadcast for GravityTiming.

Protocol from MEMORY.md §8:
- Server → Client: punch, standings, highlight, stage_status
- Client → Server: subscribe (channel selection)

Single endpoint: ws://{host}:8080/ws
"""

from __future__ import annotations

import asyncio
import json
import logging
from typing import Optional

from fastapi import APIRouter, WebSocket, WebSocketDisconnect

logger = logging.getLogger("gravitytiming.ws")

router = APIRouter()


class ConnectionManager:
    """Manages WebSocket connections and message broadcasting."""

    def __init__(self):
        self.active: list[WebSocket] = []
        self._standings_task: Optional[asyncio.Task] = None

    async def connect(self, ws: WebSocket):
        await ws.accept()
        self.active.append(ws)
        logger.info("WS connected (%d total)", len(self.active))

    def disconnect(self, ws: WebSocket):
        if ws in self.active:
            self.active.remove(ws)
        logger.info("WS disconnected (%d total)", len(self.active))

    async def broadcast(self, message: dict):
        """Send message to all connected clients."""
        if not self.active:
            return
        data = json.dumps(message, ensure_ascii=False)
        disconnected = []
        for ws in self.active:
            try:
                await ws.send_text(data)
            except Exception:
                disconnected.append(ws)
        for ws in disconnected:
            self.disconnect(ws)

    async def broadcast_punch(self, event_id: int, punch_data: dict):
        """Broadcast a processed punch with stage result and overall."""
        msg = {"type": "punch", "event_id": event_id, **punch_data}
        await self.broadcast(msg)

    async def broadcast_standings(self, event_id: int, class_name: str,
                                  standings: list[dict]):
        """Broadcast standings update for a class."""
        msg = {
            "type": "standings",
            "event_id": event_id,
            "class": class_name,
            "standings": standings,
        }
        await self.broadcast(msg)

    async def broadcast_highlight(self, event_id: int, category: str,
                                  text: str, bib: int,
                                  stage_number: Optional[int] = None,
                                  priority: str = "normal"):
        """Broadcast auto-generated speaker highlight."""
        msg = {
            "type": "highlight",
            "event_id": event_id,
            "category": category,
            "text": text,
            "bib": bib,
            "stage_number": stage_number,
            "priority": priority,
        }
        await self.broadcast(msg)

    async def broadcast_stage_status(self, event_id: int, stage_id: int,
                                     stage_name: str, status: str,
                                     riders_on_course: int = 0,
                                     riders_finished: int = 0,
                                     leader: Optional[dict] = None):
        """Broadcast stage status change."""
        msg = {
            "type": "stage_status",
            "event_id": event_id,
            "stage_id": stage_id,
            "stage_name": stage_name,
            "status": status,
            "riders_on_course": riders_on_course,
            "riders_finished": riders_finished,
            "leader": leader,
        }
        await self.broadcast(msg)

    @property
    def connection_count(self) -> int:
        return len(self.active)


# Singleton manager
manager = ConnectionManager()


# ─── Highlight generation ─────────────────────────────────────────────

def generate_highlights(conn, event_id: int, entry_id: int,
                        stage_id: int) -> list[dict]:
    """Generate auto-highlights after a punch is processed.

    Returns list of highlight dicts (category, text, bib, stage_number, priority).
    """
    highlights = []

    entry = conn.execute(
        "SELECT e.bib, e.first_name, e.last_name FROM entries e WHERE e.id=?",
        (entry_id,)
    ).fetchone()
    if not entry:
        return highlights

    bib = entry["bib"]
    name = f"{entry['first_name'][0]}.{entry['last_name']}"
    stage = conn.execute("SELECT * FROM stages WHERE id=?", (stage_id,)).fetchone()
    if not stage:
        return highlights

    stage_num = stage["stage_number"]

    # Get this entry's latest OK result for this stage
    result = conn.execute(
        """SELECT * FROM stage_results
           WHERE event_id=? AND entry_id=? AND stage_id=? AND status='ok'
           ORDER BY elapsed_seconds ASC LIMIT 1""",
        (event_id, entry_id, stage_id)
    ).fetchone()

    if not result:
        return highlights

    elapsed = result["elapsed_seconds"]

    # Find leader time for this stage (best OK result across all entries)
    leader = conn.execute(
        """SELECT sr.elapsed_seconds, sr.entry_id, e.bib as leader_bib
           FROM stage_results sr
           JOIN entries e ON sr.entry_id = e.id
           WHERE sr.event_id=? AND sr.stage_id=? AND sr.status='ok'
           ORDER BY sr.elapsed_seconds ASC LIMIT 1""",
        (event_id, stage_id)
    ).fetchone()

    if leader:
        leader_time = leader["elapsed_seconds"]

        # New leader
        if leader["entry_id"] == entry_id:
            # Check if there are other OK results (meaning we beat someone)
            others = conn.execute(
                """SELECT COUNT(*) as cnt FROM stage_results
                   WHERE event_id=? AND stage_id=? AND status='ok'
                   AND entry_id != ?""",
                (event_id, stage_id, entry_id)
            ).fetchone()
            if others["cnt"] > 0:
                highlights.append({
                    "category": "new_leader",
                    "text": f"🏆 #{bib} {name} tar ledningen på Stage {stage_num}!",
                    "bib": bib,
                    "stage_number": stage_num,
                    "priority": "high",
                })
        else:
            # Close finish (within 2 seconds of leader)
            diff = elapsed - leader_time
            if 0 < diff <= 2.0:
                highlights.append({
                    "category": "close_finish",
                    "text": f"⚡ #{bib} {name} {diff:.1f}s från ledaren på Stage {stage_num}!",
                    "bib": bib,
                    "stage_number": stage_num,
                    "priority": "high",
                })

    # Check for overall position improvement
    overall = conn.execute(
        """SELECT position FROM overall_results
           WHERE event_id=? AND entry_id=?""",
        (event_id, entry_id)
    ).fetchone()

    if overall and overall["position"] and overall["position"] <= 3:
        highlights.append({
            "category": "podium",
            "text": f"🏅 #{bib} {name} ligger {overall['position']}:a totalt!",
            "bib": bib,
            "stage_number": stage_num,
            "priority": "normal",
        })

    return highlights


# ─── WebSocket endpoint ───────────────────────────────────────────────

@router.websocket("/ws")
async def websocket_endpoint(ws: WebSocket):
    await manager.connect(ws)
    try:
        while True:
            data = await ws.receive_text()
            # Client can send subscribe messages (ignored for now — all get everything)
            try:
                msg = json.loads(data)
                if msg.get("type") == "subscribe":
                    logger.debug("WS subscribe: %s", msg.get("channels"))
            except json.JSONDecodeError:
                pass
    except WebSocketDisconnect:
        manager.disconnect(ws)
