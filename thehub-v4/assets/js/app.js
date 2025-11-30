
// TheHUB V4 – Dashboard SPA wiring for /thehub-v4/
// Uses backend/public/api/*.php endpoints

const BASE = "/thehub-v4";
const API_BASE = BASE + "/backend/public/api";

document.addEventListener("DOMContentLoaded", () => {
  setupNavigation();
  setupTheme();
  setupDbTabs();
  setupRankingControls();
  hydrateUI();
});

function hydrateUI() {
  loadDashboard().catch(console.error);
  loadCalendar().catch(console.error);
  loadResults().catch(console.error);
  loadDatabase().catch(console.error);
  loadRanking().catch(console.error);
}

// ---------------- NAVIGATION ----------------

function setupNavigation() {
  const navItems = document.querySelectorAll(".hub-nav-item");
  const views = document.querySelectorAll(".hub-view");
  const titleEl = document.getElementById("hub-page-title");

  function setActive(view) {
    const id = "view-" + view;
    views.forEach((v) => {
      v.classList.toggle("hub-view-active", v.id === id);
    });
    navItems.forEach((btn) => {
      btn.classList.toggle("is-active", btn.dataset.viewTarget === view);
    });
    const mapping = {
      dashboard: "Dashboard",
      calendar: "Kalender",
      results: "Resultat",
      series: "Serier",
      database: "Databas",
      ranking: "Ranking & poäng",
    };
    if (titleEl && mapping[view]) {
      titleEl.textContent = mapping[view];
    }
  }

  navItems.forEach((btn) => {
    btn.addEventListener("click", () => {
      const target = btn.dataset.viewTarget;
      if (target) setActive(target);
    });
  });

  // quick links
  document.querySelectorAll("[data-jump-view]").forEach((btn) => {
    btn.addEventListener("click", () => {
      const view = btn.dataset.jumpView;
      if (view) setActive(view);
    });
  });

  // initial
  setActive("dashboard");
}

// ---------------- THEME ----------------

function setupTheme() {
  const root = document.documentElement;
  const toggle = document.getElementById("theme-toggle");
  if (!toggle) return;

  const icons = toggle.querySelectorAll(".hub-theme-icon");
  const mql = window.matchMedia("(prefers-color-scheme: dark)");

  function setIcon(mode) {
    icons.forEach((el) => {
      const t = el.dataset.theme;
      el.classList.toggle("is-active", t === mode || (mode === "auto" && t === (mql.matches ? "dark" : "light")));
    });
  }

  function apply(mode, save = true) {
    let eff = mode;
    if (mode === "auto") {
      eff = mql.matches ? "dark" : "light";
    }
    root.dataset.theme = eff;
    if (save) localStorage.setItem("thehub-v4-theme", mode);
    setIcon(mode);
  }

  const stored = localStorage.getItem("thehub-v4-theme") || "dark";
  apply(stored, false);

  toggle.addEventListener("click", () => {
    const current = localStorage.getItem("thehub-v4-theme") || "dark";
    const next = current === "dark" ? "light" : "dark";
    apply(next, true);
  });
}

// ---------------- HELPERS ----------------

async function apiGet(endpoint, params = {}) {
  const url = new URL(API_BASE + "/" + endpoint, window.location.origin);
  Object.entries(params).forEach(([k, v]) => {
    if (v !== undefined && v !== null && v !== "") {
      url.searchParams.set(k, v);
    }
  });
  const res = await fetch(url.toString(), { headers: { "Accept": "application/json" } });
  if (!res.ok) throw new Error("API " + endpoint + " failed: " + res.status);
  const json = await res.json();
  if (json && typeof json === "object" && "ok" in json && "data" in json) {
    if (!json.ok) throw new Error(json.error || "API error");
    return json.data;
  }
  return json;
}

function setText(id, value) {
  const el = document.getElementById(id);
  if (el) el.textContent = value;
}

function fmtDate(iso) {
  if (!iso) return "";
  const d = new Date(iso);
  if (isNaN(d.getTime())) return iso;
  return d.toLocaleDateString("sv-SE", { year: "numeric", month: "short", day: "numeric" });
}

function monthDayParts(iso) {
  const d = new Date(iso);
  if (isNaN(d.getTime())) return { month: "", day: "" };
  return {
    month: d.toLocaleDateString("sv-SE", { month: "short" }).replace(".", ""),
    day: d.getDate().toString().padStart(2, "0"),
  };
}

// ---------------- DASHBOARD ----------------

async function loadDashboard() {
  try {
    const stats = await apiGet("stats.php");
    if (stats.total_riders != null) {
      setText("stat-riders-total", stats.total_riders);
      setText("db-riders-total", stats.total_riders);
    }
    if (stats.total_clubs != null) {
      setText("stat-clubs-total", stats.total_clubs);
      setText("db-clubs-total", stats.total_clubs);
    }
    if (stats.total_events != null) {
      setText("stat-events-total", stats.total_events);
      setText("db-results-total", stats.total_events);
    }
    if (stats.total_results != null) {
      setText("stat-results-total", stats.total_results);
    }

    // dashboard riders list – fallback: use ranking API for "mest aktiva"
    const ranking = await apiGet("ranking.php", { discipline: "gravity" });
    const listEl = document.getElementById("dash-riders-list");
    const emptyEl = document.getElementById("dash-riders-empty");
    if (!listEl || !emptyEl) return;

    listEl.innerHTML = "";
    if (!ranking || !ranking.length) {
      emptyEl.textContent = "Ingen ranking hittades ännu.";
      return;
    }
    emptyEl.textContent = "";
    ranking.slice(0, 5).forEach((r, idx) => {
      const row = document.createElement("div");
      row.className = "hub-list-item";
      row.innerHTML = `
        <div class="hub-list-main">
          <div class="hub-list-title">#${idx + 1} ${(r.firstname || "")} ${(r.lastname || "")}</div>
          <div class="hub-list-sub">${r.club_name || "–"} · ${r.gravity_id || ""} · ${r.events_count || 0} event</div>
        </div>
        <div class="hub-pill">${r.total_points || 0} p</div>
      `;
      listEl.appendChild(row);
    });
  } catch (e) {
    console.error("Dashboard error", e);
  }
}

// ---------------- CALENDAR ----------------

async function loadCalendar() {
  const statusEl = document.getElementById("calendar-status");
  const listEl = document.getElementById("calendar-list");
  const badgeEl = document.getElementById("calendar-count-badge");
  if (!statusEl || !listEl || !badgeEl) return;

  try {
    statusEl.textContent = "Laddar event…";
    const events = await apiGet("events.php");
    statusEl.textContent = "";
    listEl.innerHTML = "";

    if (!events || !events.length) {
      statusEl.textContent = "Inga event hittades.";
      badgeEl.textContent = "0 event";
      return;
    }

    badgeEl.textContent = events.length + " event";

    // Populate year filter
    const yearSelect = document.getElementById("cal-year-filter");
    if (yearSelect) {
      const years = Array.from(new Set(events.map((e) => (e.date || "").slice(0, 4)).filter(Boolean))).sort();
      yearSelect.innerHTML = '<option value="">Alla år</option>' + years.map((y) => `<option value="${y}">${y}</option>`).join("");
      yearSelect.addEventListener("change", () => {
        renderCalendar(events, listEl, yearSelect.value);
      });
    }

    renderCalendar(events, listEl, "");
  } catch (e) {
    console.error("Calendar error", e);
    statusEl.textContent = "Kunde inte ladda kalender.";
  }
}

function renderCalendar(events, container, yearFilter) {
  container.innerHTML = "";
  const filtered = events.filter((e) => {
    if (!yearFilter) return true;
    return (e.date || "").startsWith(yearFilter);
  });

  filtered
    .sort((a, b) => (a.date || "").localeCompare(b.date || ""))
    .forEach((ev) => {
      const row = document.createElement("div");
      row.className = "hub-event-row";
      const parts = monthDayParts(ev.date);
      row.innerHTML = `
        <div class="hub-event-date">
          <div class="hub-event-date-month">${parts.month}</div>
          <div class="hub-event-date-day">${parts.day}</div>
        </div>
        <div class="hub-event-main">
          <div class="hub-event-title">${ev.name || "Okänt event"}</div>
          <div class="hub-event-meta">${ev.location || ""} · ${ev.discipline || ""}</div>
        </div>
        <div class="hub-event-right">
          ${ev.status || ""}
        </div>
      `;
      container.appendChild(row);
    });

  if (!filtered.length) {
    const empty = document.createElement("div");
    empty.className = "hub-empty";
    empty.textContent = "Inga event för valt filter.";
    container.appendChild(empty);
  }
}

// ---------------- RESULTS ----------------

async function loadResults() {
  const statusEl = document.getElementById("results-status");
  const listEl = document.getElementById("results-list");
  const badgeEl = document.getElementById("results-count-badge");
  if (!statusEl || !listEl || !badgeEl) return;

  try {
    statusEl.textContent = "Laddar resultat…";
    const rows = await apiGet("results.php");
    statusEl.textContent = "";
    listEl.innerHTML = "";

    if (!rows || !rows.length) {
      statusEl.textContent = "Inga resultat hittades.";
      badgeEl.textContent = "0 tävlingar";
      return;
    }

    badgeEl.textContent = rows.length + " tävlingar";

    rows
      .sort((a, b) => (b.date || "").localeCompare(a.date || ""))
      .forEach((ev) => {
        const row = document.createElement("div");
        row.className = "hub-event-row";
        const parts = monthDayParts(ev.date);
        row.innerHTML = `
          <div class="hub-event-date">
            <div class="hub-event-date-month">${parts.month}</div>
            <div class="hub-event-date-day">${parts.day}</div>
          </div>
          <div class="hub-event-main">
            <div class="hub-event-title">${ev.name || "Okänt event"}</div>
            <div class="hub-event-meta">${ev.location || ""} · ${ev.discipline || ""}</div>
          </div>
          <div class="hub-event-right">
            ${(ev.participants || 0)} starter
          </div>
        `;
        listEl.appendChild(row);
      });
  } catch (e) {
    console.error("Results error", e);
    statusEl.textContent = "Kunde inte ladda resultat.";
  }
}

// ---------------- DATABASE (Riders + Clubs) ----------------

let DB_STATE = {
  riders: [],
  clubs: [],
};

function setupDbTabs() {
  const buttons = document.querySelectorAll(".hub-tab-button[data-db-tab]");
  const ridersCol = document.getElementById("db-riders-column");
  const clubsCol = document.getElementById("db-clubs-column");
  if (!buttons.length || !ridersCol || !clubsCol) return;

  function setTab(tab) {
    buttons.forEach((b) => b.classList.toggle("hub-tab-active", b.dataset.dbTab === tab));
    ridersCol.style.display = tab === "riders" ? "" : "none";
    clubsCol.style.display = tab === "clubs" ? "" : "none";
  }

  buttons.forEach((btn) => {
    btn.addEventListener("click", () => {
      setTab(btn.dataset.dbTab);
    });
  });

  setTab("riders");
}

async function loadDatabase() {
  const statusEl = document.getElementById("db-status");
  const ridersList = document.getElementById("db-riders-list");
  const clubsList = document.getElementById("db-clubs-list");
  const searchInput = document.getElementById("db-search-input");

  if (!ridersList || !clubsList) return;

  try {
    if (statusEl) statusEl.textContent = "Laddar databas…";
    const riders = await apiGet("riders.php");
    DB_STATE.riders = riders || [];

    // derive clubs
    const clubMap = new Map();
    DB_STATE.riders.forEach((r) => {
      const name = r.club_name || "Okänd klubb";
      if (!clubMap.has(name)) clubMap.set(name, 0);
      clubMap.set(name, clubMap.get(name) + 1);
    });
    DB_STATE.clubs = Array.from(clubMap.entries())
      .map(([name, count]) => ({ name, count }))
      .sort((a, b) => b.count - a.count);

    renderDbRiders(DB_STATE.riders, ridersList);
    renderDbClubs(DB_STATE.clubs, clubsList);

    if (statusEl) statusEl.textContent = "";

    // counters
    setText("db-riders-total", DB_STATE.riders.length);
    setText("db-clubs-total", DB_STATE.clubs.length);

    if (searchInput) {
      searchInput.addEventListener("input", () => {
        const q = searchInput.value.toLowerCase();
        const filtered = DB_STATE.riders.filter((r) => {
          const full = `${r.firstname || ""} ${r.lastname || ""} ${r.gravity_id || ""} ${r.club_name || ""}`.toLowerCase();
          return full.includes(q);
        });
        renderDbRiders(filtered, ridersList);
      });
    }
  } catch (e) {
    console.error("DB error", e);
    if (statusEl) statusEl.textContent = "Kunde inte ladda databasen.";
  }
}

function renderDbRiders(rows, container) {
  container.innerHTML = "";
  rows.forEach((r) => {
    const row = document.createElement("div");
    row.className = "hub-list-item";
    row.innerHTML = `
      <div class="hub-list-main">
        <div class="hub-list-title">${(r.firstname || "")} ${(r.lastname || "")}</div>
        <div class="hub-list-sub">${r.club_name || "–"} · ${r.gravity_id || ""}</div>
      </div>
      <div class="hub-pill">${r.license_number || ""}</div>
    `;
    container.appendChild(row);
  });
}

function renderDbClubs(rows, container) {
  container.innerHTML = "";
  rows.forEach((c, idx) => {
    const row = document.createElement("div");
    row.className = "hub-list-item";
    row.innerHTML = `
      <div class="hub-list-main">
        <div class="hub-list-title">${idx + 1}. ${c.name}</div>
        <div class="hub-list-sub">${c.count} registrerade åkare</div>
      </div>
    `;
    container.appendChild(row);
  });
}

// ---------------- RANKING ----------------

let RANK_STATE = {
  discipline: "gravity",
  mode: "riders",
  rows: [],
};

function setupRankingControls() {
  const discBtns = document.querySelectorAll("[data-rank-discipline]");
  const modeBtns = document.querySelectorAll("[data-rank-mode]");
  if (discBtns.length) {
    discBtns.forEach((btn) => {
      btn.addEventListener("click", () => {
        discBtns.forEach((b) => b.classList.toggle("hub-tab-active", b === btn));
        RANK_STATE.discipline = btn.dataset.rankDiscipline || "gravity";
        loadRanking().catch(console.error);
      });
    });
  }
  if (modeBtns.length) {
    modeBtns.forEach((btn) => {
      btn.addEventListener("click", () => {
        modeBtns.forEach((b) => b.classList.toggle("hub-pill-active", b === btn));
        RANK_STATE.mode = btn.dataset.rankMode || "riders";
        renderRankingTable();
      });
    });
  }
}

async function loadRanking() {
  const statusEl = document.getElementById("ranking-status");
  if (statusEl) statusEl.textContent = "Laddar ranking…";
  try {
    const rows = await apiGet("ranking.php", { discipline: RANK_STATE.discipline });
    RANK_STATE.rows = rows || [];
    if (statusEl) statusEl.textContent = "";
    renderRankingTable();
  } catch (e) {
    console.error("Ranking error", e);
    if (statusEl) statusEl.textContent = "Kunde inte ladda ranking.";
  }
}

function renderRankingTable() {
  const wrap = document.getElementById("ranking-table-wrapper");
  if (!wrap) return;
  wrap.innerHTML = "";

  const rows = RANK_STATE.rows || [];
  if (!rows.length) {
    const empty = document.createElement("div");
    empty.className = "hub-empty";
    empty.textContent = "Ingen rankingdata ännu.";
    wrap.appendChild(empty);
    return;
  }

  if (RANK_STATE.mode === "clubs") {
    // aggregate by club
    const clubMap = new Map();
    rows.forEach((r) => {
      const name = r.club_name || "Okänd klubb";
      if (!clubMap.has(name)) {
        clubMap.set(name, { name, total_points: 0, riders: 0 });
      }
      const obj = clubMap.get(name);
      obj.total_points += Number(r.total_points || 0);
      obj.riders += 1;
    });
    const clubs = Array.from(clubMap.values()).sort((a, b) => b.total_points - a.total_points);

    const table = document.createElement("table");
    table.className = "hub-ranking-table";
    table.innerHTML = `
      <thead>
        <tr>
          <th>#</th>
          <th>Klubb</th>
          <th>Riders</th>
          <th>Poäng</th>
        </tr>
      </thead>
      <tbody>
        ${clubs
          .map(
            (c, idx) => `
          <tr>
            <td>${idx + 1}</td>
            <td>${c.name}</td>
            <td>${c.riders}</td>
            <td>${c.total_points}</td>
          </tr>`
          )
          .join("")}
      </tbody>
    `;
    wrap.appendChild(table);
  } else {
    // rider mode
    const table = document.createElement("table");
    table.className = "hub-ranking-table";
    table.innerHTML = `
      <thead>
        <tr>
          <th>#</th>
          <th>Åkare</th>
          <th>Klubb</th>
          <th>Event</th>
          <th>Poäng</th>
        </tr>
      </thead>
      <tbody>
        ${rows
          .map(
            (r, idx) => `
          <tr>
            <td>${idx + 1}</td>
            <td>${(r.firstname || "")} ${(r.lastname || "")}</td>
            <td>${r.club_name || "–"}</td>
            <td>${r.events_count || 0}</td>
            <td>${r.total_points || 0}</td>
          </tr>`
          )
          .join("")}
      </tbody>
    `;
    wrap.appendChild(table);
  }
}
