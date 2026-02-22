# GravityTiming

Server-based timing system for GravitySeries cycling competitions.
Replaces Neptron/SiTiming. Uses existing SPORTident AIR+ hardware.

**Architecture**: Python server + browser clients. No desktop app. No build step.

```bash
python -m venv venv && source venv/bin/activate
pip install -r requirements.txt
python server.py
# Open http://localhost:8080/admin
```

See `MEMORY.md` for complete spec. See `ROADMAP.md` for dev plan.

Separate from TheHUB. Optional sync only.
