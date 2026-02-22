"""
hub_client.py — TheHUB REST API client.

Fetches startlists and (future) posts results to TheHUB.
TheHUB is the GravitySeries competition management platform.

Default base URL: https://thehub.gravityseries.se
"""

from __future__ import annotations

import logging
from typing import Optional

import httpx

logger = logging.getLogger("gravitytiming.hub")

DEFAULT_BASE_URL = "https://thehub.gravityseries.se"


async def fetch_startlist(base_url: str = DEFAULT_BASE_URL,
                          competition_id: str = "") -> list[dict]:
    """Fetch startlist entries from TheHUB API.

    Expected API response format:
    [
        {"bib": 1, "first_name": "Erik", "last_name": "Johansson",
         "club": "Kungsholmen CK", "class_name": "Herr Elite"},
        ...
    ]

    Returns list of entry dicts with keys:
        bib, first_name, last_name, club, class_name
    """
    url = f"{base_url.rstrip('/')}/api/competitions/{competition_id}/entries"
    logger.info("Fetching startlist from TheHUB: %s", url)

    async with httpx.AsyncClient(
        timeout=15.0,
        headers={"User-Agent": "GravityTiming/2.0"},
    ) as client:
        resp = await client.get(url)
        resp.raise_for_status()
        data = resp.json()

    # Validate and normalize entries
    entries = []
    for item in data:
        entry = {
            "bib": item.get("bib"),
            "first_name": str(item.get("first_name", "")).strip(),
            "last_name": str(item.get("last_name", "")).strip(),
            "club": str(item.get("club", "")).strip(),
            "class_name": str(item.get("class_name", "Open")).strip(),
        }
        # Convert bib to int if possible
        try:
            entry["bib"] = int(entry["bib"])
        except (TypeError, ValueError):
            logger.warning("Skipping entry with invalid bib: %s", item)
            continue
        entries.append(entry)

    logger.info("Fetched %d entries from TheHUB", len(entries))
    return entries
