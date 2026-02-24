# ROADMAP.md — GravityTiming

> Server-first architecture. Each phase = deployable system.

## Phase 1 — Timing Core + All Clients (KLAR)
**Goal**: Complete server with ALL browser clients from day one.

### Server
- [x] FastAPI + uvicorn entry point
- [x] SQLite schema init (MEMORY.md §11)
- [x] REST API: full CRUD for events, entries, chips, punches, results (56 endpoints)
- [x] Timing engine: dedup (BIB-level), chip→BIB, dual-chip resolve, stage calc, overall, rankings
- [x] ROC poller: async background task, pagination, auto-reconnect
- [x] WebSocket manager: broadcast punch, standings, highlights, stage_status
- [x] Highlight generator: new leader, close finish, podium
- [x] CSV import/export via API endpoints
- [x] 8 built-in event templates (Enduro 3/5, Downhill 2/3, Dual Slalom, Festival, XC)

### All Clients
- [x] Admin UI (`/admin`): Welcome screen, Live feed, Stages, Overall, Entries, Punches, Race Day, Setup
- [x] Finish screen (`/finish?stage=N`): big time, position, pop-up animation
- [x] Speaker dashboard (`/speaker`): multi-stage overview, highlights, riders on course
- [x] OBS overlay (`/overlay`): transparent popup, running clock, ticker
- [x] Start station (`/start?stage=N`): start order, countdown
- [x] Public standings (`/standings`): mobile-friendly, class filter
- [x] Shared `ws-client.js`: auto-reconnect, channel subscribe
- [x] GravitySeries dark theme on all pages

### Race Day Robustness
- [x] Kill switches: pause ingest, freeze standings, recompute all
- [x] Audit log: all admin actions logged with timestamp, before/after
- [x] Backup/restore: manual + auto-backup every 10 min, download, restore
- [x] Admin token middleware: optional token file protects write endpoints
- [x] Clock truth policy documented (chip time = truth, MEMORY.md §5)

### Validation
- [x] 12 automated tests (44 real punches, templates, round-trip, multi-run, festival, dual slalom, dedup, cross-chip, highlights, imports)
- [x] macOS `.app` launcher (no terminal), Windows `.bat` launcher

**Status**: `python server.py` → alla skärmar fungerar. Admin skapar event, importerar data, resultat visas på alla klienter via WebSocket.

---

## Phase 2 — Full Data Pipeline (NÄSTA)
**Goal**: All hardware sources connected. USB as ground truth.

- [ ] USB readout via sportident-python (BSM8-USB)
- [ ] SIRAP TCP listener (ROC local WiFi output)
- [ ] Multi-source merge engine (USB > SIRAP > ROC > Manual)
- [ ] Source reconcile view (Compare Sources: ROC vs USB, accept/replace)
- [ ] Full Enduro support: fixed order, free order, best-of-N
- [ ] Full Downhill support: multi-run, splits, hundredths
- [ ] Full XC support: laps, mass/wave start
- [ ] Cross-chip resolution edge cases
- [ ] Load test: 300 punches/min, WS broadcast, memory profiling

**Done**: USB imports complete chip data. SIRAP works without internet. All race formats calculate correctly.

---

## Phase 3 — TheHUB + Production
**Goal**: Sync with TheHUB. Ready for 2026 race season.

- [ ] TheHUB REST API: download startlist, upload live results, push final
- [ ] Sync queue in SQLite: offline queueing, burst-send on reconnect
- [ ] Tailscale VPN setup guide for distributed Enduro
- [ ] PDF result export (printable)
- [ ] JSON export for external systems
- [ ] Penalty system UI (DSQ, DNF, time penalties with audit trail)
- [ ] Swedish race day manual + checklist

**Done**: Full event: TheHUB startlist → race day on local WiFi → live to TheHUB → final results uploaded.

---

## Phase 4 — Hardening + Scale (ongoing)
- [ ] Load test with 36,000 punches
- [ ] Multi-event support (season archive)
- [ ] Monitoring dashboard
- [ ] Crash recovery automated test
- [ ] Structured logging + log viewer in admin

---

## Phase 5 — Native tablet app (future)
Not started until server has run ≥3 real events successfully.

---

## Rules
1. Server owns all state. Clients are disposable.
2. Offline > Online
3. Correct > Fast
4. Ship > Perfect
