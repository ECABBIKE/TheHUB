
// TheHUB V4 – UI shell JS with real data wiring for /thehub-v4/
//
// All paths are hard-wired to /thehub-v4 and /thehub-v4/backend/public/api/*
// so you can just drop the `thehub-v4` folder in /public_html and browse to:
//   https://thehub.gravityseries.se/thehub-v4/

const BASE = "/thehub-v4";
const API_BASE = BASE + "/backend/public/api";

document.addEventListener("DOMContentLoaded", () => {
  setupNavigation();
  setupTheme();
  hydrateUI();
});

function hydrateUI() {
  loadDashboard();
  loadResults();
  loadEvents();
  loadRiders();
  loadRanking();
}

// ---------------- NAVIGATION ----------------

function setupNavigation() {
  const links = document.querySelectorAll(".sidebar-link");
  const views = document.querySelectorAll(".view");
  const titleEl = document.getElementById("topbar-title");
  const eyebrowEl = document.getElementById("topbar-eyebrow");

  links.forEach((btn) => {
    btn.addEventListener("click", () => {
      const viewId = btn.dataset.view;

      links.forEach((b) => b.classList.remove("is-active"));
      btn.classList.add("is-active");

      views.forEach((v) => v.classList.remove("view-active"));
      const view = document.getElementById(`view-${viewId}`);
      if (view) {
        view.classList.add("view-active");
        if (titleEl && view.dataset.title) titleEl.textContent = view.dataset.title;
        if (eyebrowEl && view.dataset.eyebrow) eyebrowEl.textContent = view.dataset.eyebrow;
      }

      // lazy-load per vy om vi vill:
      if (viewId === "results") loadResults();
      if (viewId === "events") loadEvents();
      if (viewId === "riders") loadRiders();
      if (viewId === "ranking") loadRanking();
    });
  });

  // snabblänkar på dashboard
  document.querySelectorAll("[data-goto]").forEach((el) => {
    el.addEventListener("click", () => {
      const target = el.getAttribute("data-goto");
      const sidebarBtn = document.querySelector(
        `.sidebar-link[data-view="${target}"]`
      );
      if (sidebarBtn) sidebarBtn.click();
    });
  });
}

// ---------------- THEME ----------------

function setupTheme() {
  const root = document.documentElement;
  const buttons = document.querySelectorAll(".theme-btn");
  const mql = window.matchMedia("(prefers-color-scheme: dark)");

  function apply(mode, save = true) {
    let effective = mode;
    if (mode === "auto") {
      effective = mql.matches ? "dark" : "light";
    }
    root.dataset.theme = effective;
    if (save) localStorage.setItem("thehub-theme", mode);

    buttons.forEach((b) => b.classList.remove("is-active"));
    const activeBtn = document.querySelector(`.theme-btn[data-theme="${mode}"]`);
    if (activeBtn) activeBtn.classList.add("is-active");
  }

  const stored = localStorage.getItem("thehub-theme") || "auto";
  apply(stored, false);

  mql.addEventListener("change", () => {
    const current = localStorage.getItem("thehub-theme") || "auto";
    if (current === "auto") apply("auto", false);
  });

  buttons.forEach((btn) => {
    btn.addEventListener("click", () => {
      const mode = btn.dataset.theme || "auto";
      apply(mode, true);
    });
  });
}

// ---------------- HELPERS ----------------

async function apiGet(endpoint, params = {}) {
  const url = new URL(API_BASE + "/" + endpoint, window.location.origin);
  Object.entries(params).forEach(([k, v]) => {
    if (v !== null && v !== undefined && v !== "") url.searchParams.set(k, v);
  });

  const res = await fetch(url.toString(), {
    headers: { "Accept": "application/json" },
    cache: "no-store",
  });

  if (!res.ok) {
    throw new Error(`HTTP ${res.status}`);
  }

  const json = await res.json();
  if (json && json.ok === false) {
    throw new Error(json.error || "API error");
  }
  return json;
}

function setText(id, value) {
  const el = document.getElementById(id);
  if (el) el.textContent = value;
}

function safeDate(str) {
  if (!str) return "";
  const d = new Date(str);
  if (isNaN(d.getTime())) return str;
  return d.toLocaleDateString("sv-SE", {
    year: "numeric",
    month: "short",
    day: "numeric",
  });
}

function safeDay(str) {
  if (!str) return "";
  const d = new Date(str);
  if (isNaN(d.getTime())) return "";
  return String(d.getDate()).padStart(2, "0");
}

function safeMonth(str) {
  if (!str) return "";
  const d = new Date(str);
  if (isNaN(d.getTime())) return "";
  return d.toLocaleDateString("sv-SE", { month: "short" });
}

// ---------------- DASHBOARD ----------------

async function loadDashboard() {
  try {
    const stats = await apiGet("stats.php");
    const s = stats || {};

    if (s.total_riders != null) setText("kpi-riders", s.total_riders);
    if (s.total_clubs != null) setText("kpi-clubs", s.total_clubs);
    if (s.total_events != null) {
      setText("kpi-events", s.total_events);
      setText("db-results", s.total_events);
    }
    setText("kpi-results", "–");

    if (s.total_riders != null) setText("db-riders", s.total_riders);
    if (s.total_clubs != null) setText("db-clubs", s.total_clubs);

  } catch (e) {
    console.error("Dashboard stats error", e);
  }

  // Dashboard eventlista
  try {
    const res = await apiGet("results.php");
    const list = document.getElementById("dashboard-series-list");
    if (!list || !res || !Array.isArray(res.data)) return;

    list.innerHTML = "";
    res.data.slice(0, 6).forEach((ev) => {
      const row = document.createElement("div");
      row.className = "table-row";
      const d = safeDate(ev.date);
      const part = ev.participants ?? "";
      row.innerHTML = `
        <div class="table-label-soft">${d}</div>
        <div>${ev.name || ""}</div>
        <div>${part ? part + " åkare" : ""}</div>
      `;
      list.appendChild(row);
    });
  } catch (e) {
    console.error("Dashboard events error", e);
  }

  // Dashboard riders via ranking
  try {
    const res = await apiGet("ranking.php");
    const list = document.getElementById("dashboard-riders-list");
    if (!list || !res || !Array.isArray(res.data)) return;
    list.innerHTML = "";
    res.data.slice(0, 6).forEach((r, idx) => {
      const row = document.createElement("div");
      row.className = "table-row";
      row.innerHTML = `
        <div class="table-label-soft">${idx + 1}</div>
        <div>${(r.firstname || "")} ${(r.lastname || "")}</div>
        <div>${r.total_points ?? 0} p</div>
      `;
      list.appendChild(row);
    });
  } catch (e) {
    console.error("Dashboard ranking error", e);
  }
}

// ---------------- RESULTAT ----------------

let _resultsCache = null;

async function loadResults() {
  const statusEl = document.getElementById("results-status");
  const listEl = document.getElementById("results-list");
  if (!statusEl || !listEl) return;

  statusEl.textContent = "Laddar…";

  try {
    if (!_resultsCache) {
      const res = await apiGet("results.php");
      _resultsCache = Array.isArray(res.data) ? res.data : [];
    }

    const events = _resultsCache;
    setText("results-count-badge", events.length + " tävlingar");

    if (!events.length) {
      statusEl.textContent = "Inga resultat hittades.";
      listEl.innerHTML = "";
      return;
    }

    statusEl.textContent = "";
    listEl.innerHTML = "";

    events.forEach((ev) => {
      const card = document.createElement("article");
      card.className = "event-card";

      const date = safeDate(ev.date);
      const day = safeDay(ev.date);
      const month = safeMonth(ev.date);
      const participants = ev.participants ?? "";

      card.innerHTML = `
        <div class="event-card-main">
          <div class="event-badge">
            <div class="event-badge-day">${day}</div>
            <div>${month}</div>
          </div>
          <div class="event-meta">
            <div class="event-title">${ev.name || ""}</div>
            <div class="event-sub">${date} · ${ev.location || ""}</div>
            <div class="event-sub">${ev.discipline || ""}</div>
          </div>
        </div>
        <div class="event-card-right">
          <div class="event-count">${participants ? participants + " åkare" : ""}</div>
          <div class="event-arrow">Visa »</div>
        </div>
      `;

      listEl.appendChild(card);
    });
  } catch (e) {
    console.error("Results error", e);
    statusEl.textContent = "Kunde inte ladda resultat.";
  }
}

// ---------------- EVENTS ----------------

let _eventsCache = null;

async function loadEvents() {
  const statusEl = document.getElementById("events-status");
  const listEl = document.getElementById("events-list");
  if (!statusEl || !listEl) return;

  statusEl.textContent = "Laddar…";

  try {
    if (!_eventsCache) {
      const res = await apiGet("events.php");
      _eventsCache = Array.isArray(res.data) ? res.data : [];
    }

    const events = _eventsCache;
    setText("events-count-badge", events.length + " st");

    if (!events.length) {
      statusEl.textContent = "Inga event hittades.";
      listEl.innerHTML = "";
      return;
    }

    statusEl.textContent = "";
    listEl.innerHTML = "";

    events.forEach((ev) => {
      const card = document.createElement("article");
      card.className = "event-card";

      const date = safeDate(ev.date);
      const day = safeDay(ev.date);
      const month = safeMonth(ev.date);

      card.innerHTML = `
        <div class="event-card-main">
          <div class="event-badge">
            <div class="event-badge-day">${day}</div>
            <div>${month}</div>
          </div>
          <div class="event-meta">
            <div class="event-title">${ev.name || ""}</div>
            <div class="event-sub">${date} · ${ev.location || ""}</div>
            <div class="event-sub">${ev.discipline || ""} · ${ev.type || ""}</div>
          </div>
        </div>
        <div class="event-card-right">
          <div class="event-arrow">Detaljer »</div>
        </div>
      `;

      listEl.appendChild(card);
    });
  } catch (e) {
    console.error("Events error", e);
    statusEl.textContent = "Kunde inte ladda events.";
  }
}

// ---------------- RIDERS ----------------

let _ridersCache = null;

async function loadRiders() {
  const statusEl = document.getElementById("riders-status");
  const listEl = document.getElementById("riders-list");
  const countBadge = document.getElementById("riders-count-badge");
  if (!statusEl || !listEl) return;

  statusEl.textContent = "Laddar…";

  try {
    if (!_ridersCache) {
      const res = await apiGet("riders.php");
      _ridersCache = Array.isArray(res.data) ? res.data : [];
    }

    const riders = _ridersCache;
    if (countBadge) countBadge.textContent = riders.length + " riders";

    if (!riders.length) {
      statusEl.textContent = "Inga riders hittades.";
      listEl.innerHTML = "";
      return;
    }

    statusEl.textContent = "";
    listEl.innerHTML = "";

    riders.slice(0, 200).forEach((r) => {
      const item = document.createElement("div");
      item.className = "list-item";
      item.innerHTML = `
        <div class="list-item-main">
          <div class="list-item-name">${(r.firstname || "")} ${(r.lastname || "")}</div>
          <div class="list-item-sub">
            ${r.club_name || "–"} · ${r.gravity_id || ""} ${r.license_number ? "· " + r.license_number : ""}
          </div>
        </div>
      `;
      listEl.appendChild(item);
    });

    setupRiderSearch();

  } catch (e) {
    console.error("Riders error", e);
    statusEl.textContent = "Kunde inte ladda riders.";
  }
}

function setupRiderSearch() {
  const input = document.getElementById("riders-search");
  const listEl = document.getElementById("riders-list");
  const statusEl = document.getElementById("riders-status");
  if (!input || !listEl) return;
  if (!Array.isArray(_ridersCache)) return;

  input.addEventListener("input", () => {
    const q = input.value.toLowerCase().trim();
    const riders = _ridersCache;
    listEl.innerHTML = "";

    let filtered = riders;
    if (q.length >= 2) {
      filtered = riders.filter((r) => {
        const s = `${r.firstname || ""} ${r.lastname || ""} ${r.club_name || ""} ${r.gravity_id || ""}`.toLowerCase();
        return s.includes(q);
      });
    }

    if (!filtered.length) {
      if (statusEl) statusEl.textContent = "Inga träffar.";
      return;
    } else {
      if (statusEl) statusEl.textContent = "";
    }

    filtered.slice(0, 200).forEach((r) => {
      const item = document.createElement("div");
      item.className = "list-item";
      item.innerHTML = `
        <div class="list-item-main">
          <div class="list-item-name">${(r.firstname || "")} ${(r.lastname || "")}</div>
          <div class="list-item-sub">
            ${r.club_name || "–"} · ${r.gravity_id || ""} ${r.license_number ? "· " + r.license_number : ""}
          </div>
        </div>
      `;
      listEl.appendChild(item);
    });
  });
}

// ---------------- RANKING ----------------

let _rankingCache = null;

async function loadRanking() {
  const statusEl = document.getElementById("ranking-status");
  const listEl = document.getElementById("ranking-list");
  if (!statusEl || !listEl) return;

  statusEl.textContent = "Laddar ranking…";

  try {
    if (!_rankingCache) {
      const res = await apiGet("ranking.php");
      _rankingCache = Array.isArray(res.data) ? res.data : [];
    }

    const rows = _rankingCache;
    if (!rows.length) {
      statusEl.textContent = "Ingen ranking hittades.";
      listEl.innerHTML = "";
      return;
    }

    statusEl.textContent = "";
    listEl.innerHTML = "";

    rows.slice(0, 50).forEach((r, idx) => {
      const item = document.createElement("div");
      item.className = "list-item";
      item.innerHTML = `
        <div class="list-item-main">
          <div class="list-item-name">
            #${idx + 1} ${(r.firstname || "")} ${(r.lastname || "")}
          </div>
          <div class="list-item-sub">
            ${r.club_name || "–"} · ${r.gravity_id || ""} · ${r.events_count || 0} event
          </div>
        </div>
        <div class="list-item-pill">
          ${r.total_points || 0} p
        </div>
      `;
      listEl.appendChild(item);
    });
  } catch (e) {
    console.error("Ranking error", e);
    statusEl.textContent = "Kunde inte ladda ranking.";
  }
}
