// TheHUB V4 – Dashboard SPA wiring for /thehub-v4/
// Uses backend/public/api/*.php endpoints
// Updated for V3 design system compatibility

const BASE = "/thehub-v4";
const API_BASE = BASE + "/backend/public/api";

document.addEventListener("DOMContentLoaded", () => {
  setupNavigation();
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
  const sidebarLinks = document.querySelectorAll('.sidebar-link');
  const mobileLinks = document.querySelectorAll('.mobile-nav-link');
  const views = document.querySelectorAll('.page-content');

  function switchView(viewName) {
    // Hide all views, show the selected one
    views.forEach(view => {
      if (view.dataset.view === viewName) {
        view.style.display = 'block';
      } else {
        view.style.display = 'none';
      }
    });

    // Update sidebar active state
    sidebarLinks.forEach(link => {
      if (link.dataset.view === viewName) {
        link.setAttribute('aria-current', 'page');
      } else {
        link.removeAttribute('aria-current');
      }
    });

    // Update mobile nav active state
    mobileLinks.forEach(link => {
      if (link.dataset.view === viewName) {
        link.classList.add('active');
      } else {
        link.classList.remove('active');
      }
    });
  }

  // Attach click handlers to sidebar links
  sidebarLinks.forEach(link => {
    link.addEventListener('click', (e) => {
      e.preventDefault();
      const view = link.dataset.view;
      if (view) switchView(view);
    });
  });

  // Attach click handlers to mobile nav links
  mobileLinks.forEach(link => {
    link.addEventListener('click', (e) => {
      e.preventDefault();
      const view = link.dataset.view;
      if (view) switchView(view);
    });
  });

  // Quick links (jump to view)
  document.querySelectorAll("[data-jump-view]").forEach((btn) => {
    btn.addEventListener("click", () => {
      const view = btn.dataset.jumpView;
      if (view) switchView(view);
    });
  });

  // Initial view
  switchView("dashboard");
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
      row.className = "flex justify-between items-center p-sm card mb-sm";
      row.innerHTML = `
        <div>
          <div class="font-medium">#${idx + 1} ${(r.firstname || "")} ${(r.lastname || "")}</div>
          <div class="text-sm text-secondary">${r.club_name || "–"} · ${r.gravity_id || ""} · ${r.events_count || 0} event</div>
        </div>
        <div class="chip">${r.total_points || 0} p</div>
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
      row.className = "flex gap-md items-center p-sm card mb-sm";
      const parts = monthDayParts(ev.date);
      row.innerHTML = `
        <div class="text-center" style="min-width:50px;">
          <div class="text-xs text-muted">${parts.month.toUpperCase()}</div>
          <div class="text-lg font-bold">${parts.day}</div>
        </div>
        <div class="flex-1">
          <div class="font-medium">${ev.name || "Okänt event"}</div>
          <div class="text-sm text-secondary">${ev.location || ""} · ${ev.discipline || ""}</div>
        </div>
        <div class="chip">${ev.status || ""}</div>
      `;
      container.appendChild(row);
    });

  if (!filtered.length) {
    const empty = document.createElement("div");
    empty.className = "text-muted text-sm";
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
        row.className = "flex gap-md items-center p-sm card mb-sm";
        const parts = monthDayParts(ev.date);
        row.innerHTML = `
          <div class="text-center" style="min-width:50px;">
            <div class="text-xs text-muted">${parts.month.toUpperCase()}</div>
            <div class="text-lg font-bold">${parts.day}</div>
          </div>
          <div class="flex-1">
            <div class="font-medium">${ev.name || "Okänt event"}</div>
            <div class="text-sm text-secondary">${ev.location || ""} · ${ev.discipline || ""}</div>
          </div>
          <div class="chip">${(ev.participants || 0)} starter</div>
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
  const buttons = document.querySelectorAll("[data-db-tab]");
  const ridersCol = document.getElementById("db-riders-column");
  const clubsCol = document.getElementById("db-clubs-column");
  if (!buttons.length || !ridersCol || !clubsCol) return;

  function setTab(tab) {
    buttons.forEach((b) => {
      if (b.dataset.dbTab === tab) {
        b.classList.remove('btn--ghost');
        b.classList.add('btn--secondary', 'db-tab-active');
      } else {
        b.classList.remove('btn--secondary', 'db-tab-active');
        b.classList.add('btn--ghost');
      }
    });
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
  const ridersList = document.getElementById("db-riders-list");
  const clubsList = document.getElementById("db-clubs-list");
  const searchInput = document.getElementById("db-search-input");

  if (!ridersList || !clubsList) return;

  try {
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
  }
}

function renderDbRiders(rows, container) {
  container.innerHTML = "";
  rows.slice(0, 50).forEach((r) => {
    const row = document.createElement("div");
    row.className = "flex justify-between items-center p-sm card mb-sm";
    row.innerHTML = `
      <div>
        <div class="font-medium">${(r.firstname || "")} ${(r.lastname || "")}</div>
        <div class="text-sm text-secondary">${r.club_name || "–"} · ${r.gravity_id || ""}</div>
      </div>
      <div class="chip">${r.license_number || ""}</div>
    `;
    container.appendChild(row);
  });
}

function renderDbClubs(rows, container) {
  container.innerHTML = "";
  rows.slice(0, 30).forEach((c, idx) => {
    const row = document.createElement("div");
    row.className = "flex justify-between items-center p-sm card mb-sm";
    row.innerHTML = `
      <div>
        <div class="font-medium">${idx + 1}. ${c.name}</div>
        <div class="text-sm text-secondary">${c.count} registrerade åkare</div>
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
        discBtns.forEach((b) => {
          if (b === btn) {
            b.classList.remove('btn--ghost');
            b.classList.add('btn--secondary', 'rank-disc-active');
          } else {
            b.classList.remove('btn--secondary', 'rank-disc-active');
            b.classList.add('btn--ghost');
          }
        });
        RANK_STATE.discipline = btn.dataset.rankDiscipline || "gravity";
        loadRanking().catch(console.error);
      });
    });
  }

  if (modeBtns.length) {
    modeBtns.forEach((btn) => {
      btn.addEventListener("click", () => {
        modeBtns.forEach((b) => {
          if (b === btn) {
            b.classList.remove('btn--ghost');
            b.classList.add('btn--secondary', 'rank-mode-active');
          } else {
            b.classList.remove('btn--secondary', 'rank-mode-active');
            b.classList.add('btn--ghost');
          }
        });
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
    empty.className = "text-muted text-sm";
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
    table.className = "table";
    table.innerHTML = `
      <thead>
        <tr>
          <th>#</th>
          <th>Klubb</th>
          <th>Riders</th>
          <th class="col-points">Poäng</th>
        </tr>
      </thead>
      <tbody>
        ${clubs
          .map(
            (c, idx) => `
          <tr>
            <td class="col-place">${idx + 1}</td>
            <td>${c.name}</td>
            <td>${c.riders}</td>
            <td class="col-points">${c.total_points}</td>
          </tr>`
          )
          .join("")}
      </tbody>
    `;
    wrap.appendChild(table);
  } else {
    // rider mode
    const table = document.createElement("table");
    table.className = "table";
    table.innerHTML = `
      <thead>
        <tr>
          <th>#</th>
          <th>Åkare</th>
          <th>Klubb</th>
          <th>Event</th>
          <th class="col-points">Poäng</th>
        </tr>
      </thead>
      <tbody>
        ${rows
          .map(
            (r, idx) => `
          <tr>
            <td class="col-place">${idx + 1}</td>
            <td class="col-rider">${(r.firstname || "")} ${(r.lastname || "")}</td>
            <td class="col-club">${r.club_name || "–"}</td>
            <td>${r.events_count || 0}</td>
            <td class="col-points">${r.total_points || 0}</td>
          </tr>`
          )
          .join("")}
      </tbody>
    `;
    wrap.appendChild(table);
  }
}
