"""
roc_poller.py — Async ROC HTTP polling background task.

Polls https://roc.olresultat.se/getpunches.asp for new punches.
Uses pagination via lastId. Runs as asyncio task within FastAPI.

Replaces the old threading+urllib version with httpx+asyncio.
"""

from __future__ import annotations

import asyncio
import logging
from datetime import datetime
from typing import Optional, Callable, Awaitable

import httpx

logger = logging.getLogger("gravitytiming.roc")

ROC_BASE_URL = "https://roc.olresultat.se/getpunches.asp"
DEFAULT_INTERVAL = 1.0  # seconds between polls


class RocPoller:
    """Async background task that polls ROC API and calls a handler for each punch."""

    def __init__(self, competition_id: str,
                 on_punch: Callable[[dict], Awaitable[None]],
                 interval: float = DEFAULT_INTERVAL):
        self.competition_id = competition_id
        self.on_punch = on_punch
        self.interval = interval
        self.last_id = 0
        self._running = False
        self._task: Optional[asyncio.Task] = None
        self._status = "Stoppad"
        self._error_count = 0
        self._punch_count = 0
        self._last_poll: Optional[str] = None
        self._client: Optional[httpx.AsyncClient] = None

    @property
    def status(self) -> str:
        return self._status

    @property
    def punch_count(self) -> int:
        return self._punch_count

    @property
    def is_running(self) -> bool:
        return self._running

    def get_status(self) -> dict:
        return {
            "is_running": self._running,
            "status": self._status,
            "punch_count": self._punch_count,
            "error_count": self._error_count,
            "last_poll": self._last_poll,
            "last_id": self.last_id,
            "competition_id": self.competition_id,
        }

    async def start(self) -> None:
        if self._running:
            return
        self._running = True
        self._status = "Startar..."
        self._client = httpx.AsyncClient(
            timeout=10.0,
            headers={"User-Agent": "GravityTiming/2.0"},
        )
        self._task = asyncio.create_task(self._poll_loop())

    async def stop(self) -> None:
        self._running = False
        if self._task:
            self._task.cancel()
            try:
                await self._task
            except asyncio.CancelledError:
                pass
        if self._client:
            await self._client.aclose()
        self._status = "Stoppad"

    def set_last_id(self, last_id: int) -> None:
        """Resume polling from a known position."""
        self.last_id = last_id

    async def _poll_loop(self) -> None:
        while self._running:
            try:
                punches = await self._fetch()
                self._last_poll = datetime.now().strftime("%Y-%m-%d %H:%M:%S")

                if punches:
                    for punch in punches:
                        try:
                            await self.on_punch(punch)
                            self._punch_count += 1
                        except Exception as e:
                            logger.error("Error processing ROC punch: %s", e)

                self._status = "Online"
                self._error_count = 0

            except asyncio.CancelledError:
                break
            except Exception as e:
                self._error_count += 1
                self._status = f"Fel ({self._error_count})"
                logger.warning("ROC poll error: %s", e)

                # Back off on repeated errors
                if self._error_count >= 10:
                    await asyncio.sleep(min(self.interval * self._error_count, 30))

            await asyncio.sleep(self.interval)

        self._status = "Stoppad"

    async def _fetch(self) -> list[dict]:
        """Fetch new punches from ROC API. Returns list of punch dicts."""
        url = f"{ROC_BASE_URL}?unitId={self.competition_id}&lastId={self.last_id}"
        resp = await self._client.get(url)
        resp.raise_for_status()

        data = resp.text.strip()
        if not data:
            return []

        punches = []
        for line in data.split("\n"):
            line = line.strip()
            if not line:
                continue
            parts = line.split(";")
            if len(parts) < 4:
                continue
            try:
                punch = {
                    "roc_punch_id": int(parts[0]),
                    "control_code": int(parts[1]),
                    "siac": int(parts[2]),
                    "punch_time": parts[3].strip(),
                }
                punches.append(punch)
                if punch["roc_punch_id"] > self.last_id:
                    self.last_id = punch["roc_punch_id"]
            except (ValueError, IndexError):
                continue

        return punches
