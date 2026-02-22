"""
GravityTiming — Server entry point.

Starts the FastAPI server with all routes, WebSocket, static files, and HTML templates.
Usage:
    python server.py
    # or: uvicorn server:app --host 0.0.0.0 --port 8080 --reload
"""

import logging
from contextlib import asynccontextmanager
from pathlib import Path

from fastapi import FastAPI, Request
from fastapi.staticfiles import StaticFiles
from fastapi.templating import Jinja2Templates
from fastapi.responses import HTMLResponse, JSONResponse

import time as _time

from core.database import get_connection, init_db, migrate_db, create_backup
from api.routes import router as api_router
from api.websocket import router as ws_router, manager as ws_manager

# Cache-bust value: set once at server start so all pages get fresh JS/CSS
_CACHE_BUST = str(int(_time.time()))

logger = logging.getLogger("gravitytiming")

BASE_DIR = Path(__file__).parent


# ─── Admin Token Middleware (pure ASGI — safe for WebSocket) ──────────

def _load_admin_token() -> str:
    """Load admin token from data/admin_token.txt (if exists)."""
    token_path = BASE_DIR / "data" / "admin_token.txt"
    if token_path.exists():
        token = token_path.read_text().strip()
        if token:
            return token
    return ""


class AdminTokenMiddleware:
    """Pure ASGI middleware — safe for both HTTP and WebSocket.

    If admin_token.txt exists, write endpoints (POST/PUT/DELETE on /api/*)
    require X-Admin-Token header. GET/WebSocket/static always allowed.
    """
    EXEMPT_PATHS = {"/api/status", "/api/race/state"}

    def __init__(self, app):
        self.app = app

    async def __call__(self, scope, receive, send):
        # WebSocket and non-HTTP — always pass through
        if scope["type"] != "http":
            await self.app(scope, receive, send)
            return

        # HTTP: check token for write methods on /api/*
        token = _load_admin_token()
        if not token:
            await self.app(scope, receive, send)
            return

        path = scope.get("path", "")
        method = scope.get("method", "GET")

        # Allow non-API, safe methods, and exempt paths
        if not path.startswith("/api"):
            await self.app(scope, receive, send)
            return
        if method in ("GET", "HEAD", "OPTIONS"):
            await self.app(scope, receive, send)
            return
        if path in self.EXEMPT_PATHS:
            await self.app(scope, receive, send)
            return

        # Check X-Admin-Token header
        headers = dict(scope.get("headers", []))
        req_token = headers.get(b"x-admin-token", b"").decode()
        if req_token != token:
            response = JSONResponse(
                status_code=403,
                content={"detail": "Admin token required"}
            )
            await response(scope, receive, send)
            return

        await self.app(scope, receive, send)


# ─── Auto-backup scheduler ───────────────────────────────────────────

async def _auto_backup_loop():
    """Create automatic backup every 10 minutes."""
    import asyncio
    while True:
        await asyncio.sleep(600)  # 10 min
        try:
            create_backup("auto")
            logger.info("Auto-backup created")
        except Exception as e:
            logger.error("Auto-backup failed: %s", e)


@asynccontextmanager
async def lifespan(app: FastAPI):
    """Startup: init database + auto-backup. Shutdown: clean up."""
    import asyncio

    conn = get_connection()
    init_db(conn)
    migrate_db(conn)
    conn.close()

    # Start auto-backup task
    backup_task = asyncio.create_task(_auto_backup_loop())

    # Initialize connection state slots
    app.state.roc_poller = None
    app.state.usb_reader = None

    yield

    # Shutdown
    backup_task.cancel()
    if app.state.roc_poller:
        await app.state.roc_poller.stop()


app = FastAPI(title="GravityTiming", lifespan=lifespan)

# Add admin token middleware
app.add_middleware(AdminTokenMiddleware)

# Static files — wrapped with no-cache headers so browser always gets fresh files
class NoCacheStaticFiles(StaticFiles):
    """StaticFiles with Cache-Control: no-cache on every response."""
    async def __call__(self, scope, receive, send):
        async def send_no_cache(message):
            if message["type"] == "http.response.start":
                headers = list(message.get("headers", []))
                headers.append((b"cache-control", b"no-cache, must-revalidate"))
                message = {**message, "headers": headers}
            await send(message)
        await super().__call__(scope, receive, send_no_cache)

app.mount("/static", NoCacheStaticFiles(directory=str(BASE_DIR / "web" / "static")), name="static")
templates = Jinja2Templates(directory=str(BASE_DIR / "web" / "templates"))

# API + WebSocket routers
app.include_router(api_router, prefix="/api")
app.include_router(ws_router)


# ─── HTML page routes ───────────────────────────────────────────────

@app.get("/", response_class=HTMLResponse)
@app.get("/admin", response_class=HTMLResponse)
async def admin_page(request: Request):
    return templates.TemplateResponse("admin.html", {
        "request": request, "cache_bust": _CACHE_BUST,
    })


@app.get("/finish", response_class=HTMLResponse)
async def finish_page(request: Request):
    return templates.TemplateResponse("finish.html", {"request": request})


@app.get("/speaker", response_class=HTMLResponse)
async def speaker_page(request: Request):
    return templates.TemplateResponse("speaker.html", {"request": request})


@app.get("/overlay", response_class=HTMLResponse)
async def overlay_page(request: Request):
    return templates.TemplateResponse("overlay.html", {"request": request})


@app.get("/start", response_class=HTMLResponse)
async def start_page(request: Request):
    return templates.TemplateResponse("start.html", {"request": request})


@app.get("/standings", response_class=HTMLResponse)
async def standings_page(request: Request):
    return templates.TemplateResponse("standings.html", {"request": request})


# ─── Main ────────────────────────────────────────────────────────────

PORT = 8080


def _is_port_in_use(port: int) -> bool:
    """Check if a TCP port is already in use."""
    import socket
    with socket.socket(socket.AF_INET, socket.SOCK_STREAM) as s:
        try:
            s.bind(("127.0.0.1", port))
            return False
        except OSError:
            return True


def _kill_stale_server(port: int) -> bool:
    """Try to kill a stale GravityTiming process on the given port.
    Returns True if port was freed."""
    import subprocess, time
    try:
        # Find PID using port
        result = subprocess.run(
            ["lsof", "-ti", f"tcp:{port}"],
            capture_output=True, text=True, timeout=5
        )
        pids = result.stdout.strip().split()
        if not pids:
            return False

        for pid in pids:
            pid = pid.strip()
            if not pid:
                continue
            try:
                import os, signal
                os.kill(int(pid), signal.SIGTERM)
            except (ProcessLookupError, ValueError):
                pass

        # Wait for port to free up
        for _ in range(20):
            time.sleep(0.25)
            if not _is_port_in_use(port):
                return True
        return False
    except Exception:
        return False


def _wait_for_server(port: int = PORT, timeout: float = 15.0) -> bool:
    """Block until the server responds or timeout."""
    import time, httpx
    url = f"http://localhost:{port}/api/status"
    deadline = time.time() + timeout
    while time.time() < deadline:
        try:
            r = httpx.get(url, timeout=1.0)
            if r.status_code == 200:
                return True
        except Exception:
            pass
        time.sleep(0.3)
    return False


# Global reference so we can shut down cleanly
_uvicorn_server = None


def _run_server(host: str = "0.0.0.0", port: int = PORT):
    """Run uvicorn with a proper Server object so we can shut it down."""
    import uvicorn
    global _uvicorn_server
    config = uvicorn.Config(
        "server:app", host=host, port=port,
        log_level="warning",
    )
    _uvicorn_server = uvicorn.Server(config)
    _uvicorn_server.run()


def _shutdown_server():
    """Signal uvicorn to shut down gracefully."""
    global _uvicorn_server
    if _uvicorn_server:
        _uvicorn_server.should_exit = True


def _open_browser(port: int = PORT):
    """Fallback: open admin page in default browser."""
    import time, webbrowser
    time.sleep(1.5)
    webbrowser.open(f"http://localhost:{port}/admin")


if __name__ == "__main__":
    import sys, threading, os, signal

    dev_mode = "--dev" in sys.argv
    headless = "--headless" in sys.argv or "--no-browser" in sys.argv

    # ── Pre-flight: check for stale process on our port ──
    if _is_port_in_use(PORT):
        print(f"Port {PORT} är upptagen — försöker stänga gammal process...")
        if _kill_stale_server(PORT):
            print(f"  ✓ Port {PORT} frigjord")
        else:
            print(f"  ✗ Kunde inte frigöra port {PORT}")
            print(f"  Stäng processen manuellt: lsof -ti tcp:{PORT} | xargs kill")
            sys.exit(1)

    # ── Handle Ctrl+C / SIGTERM cleanly ──
    def _handle_signal(sig, frame):
        print("\nStänger ner GravityTiming...")
        _shutdown_server()
        sys.exit(0)
    signal.signal(signal.SIGINT, _handle_signal)
    signal.signal(signal.SIGTERM, _handle_signal)

    if dev_mode:
        # Dev mode: hot-reload, browser only
        import uvicorn
        threading.Thread(target=_open_browser, daemon=True).start()
        uvicorn.run("server:app", host="0.0.0.0", port=PORT, reload=True)

    elif headless:
        # Headless: server only, no GUI (used by GravityTiming.app)
        print(f"GravityTiming server — http://localhost:{PORT}/admin")
        _run_server()

    else:
        # Default: pywebview native window
        # Server starts in background, window = the app.
        # Closing the window = closing the app.
        try:
            import webview
        except ImportError:
            print("pywebview inte installerat — kör: pip install pywebview")
            sys.exit(1)

        server_thread = threading.Thread(target=_run_server, daemon=True)
        server_thread.start()

        print("Startar GravityTiming...")
        if not _wait_for_server():
            print("VARNING: Servern svarar inte — kontrollera loggen")
            sys.exit(1)

        print("GravityTiming redo — öppnar fönster...")

        try:
            window = webview.create_window(
                title="GravityTiming",
                url=f"http://localhost:{PORT}/admin",
                width=1400,
                height=900,
                min_size=(1024, 600),
                text_select=True,
                confirm_close=True,          # "Vill du stänga?" dialog
            )
            webview.start(
                debug=("--debug" in sys.argv),
                localization={
                    "global.quitConfirmation": "Vill du avsluta GravityTiming?",
                },
            )
        except Exception as e:
            print(f"Fönster-fel: {e}")
        finally:
            # Window closed (user confirmed) — shut down everything
            print("Stänger ner GravityTiming...")
            _shutdown_server()
            server_thread.join(timeout=5.0)
            if server_thread.is_alive():
                os._exit(0)

        print("GravityTiming avslutat.")
        sys.exit(0)
