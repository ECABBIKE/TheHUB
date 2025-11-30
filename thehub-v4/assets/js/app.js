// TheHUB V4 – Dashboard SPA wiring for /thehub-v4/
// Uses backend/public/api/*.php endpoints
// Updated for V3 design system compatibility

const BASE = "/thehub-v4";
const API_BASE = BASE + "/backend/public/api";

// Track which views have been loaded
const LOADED_VIEWS = new Set();

document.addEventListener("DOMContentLoaded", () => {
  setupNavigation();
  setupDbTabs();
  setupRankingControls();
  hydrateUI();

  // Initialize Lucide icons
  initLucideIcons();
});

// Initialize Lucide icons (call after DOM changes)
function initLucideIcons() {
  if (typeof lucide !== 'undefined') {
    lucide.createIcons();
  }
}

// Only load dashboard initially - other views load on-demand
function hydrateUI() {
  loadDashboard().catch(console.error);
  LOADED_VIEWS.add('dashboard');
}

// ---------------- TOAST NOTIFICATIONS ----------------

function showToast(message, type = 'info') {
  const toast = document.createElement('div');
  toast.className = `toast toast--${type}`;
  toast.textContent = message;
  toast.style.cssText = `
    position: fixed;
    bottom: calc(var(--mobile-nav-height) + var(--space-md));
    right: var(--space-md);
    background: var(--color-bg-elevated);
    color: var(--color-text-primary);
    padding: var(--space-md);
    border-radius: var(--radius-md);
    box-shadow: var(--shadow-lg);
    z-index: var(--z-toast);
    min-width: 200px;
    max-width: 320px;
    border-left: 3px solid ${type === 'error' ? 'var(--color-error)' : type === 'success' ? 'var(--color-success)' : 'var(--color-accent)'};
    transition: opacity 0.3s ease, transform 0.3s ease;
  `;

  document.body.appendChild(toast);

  setTimeout(() => {
    toast.style.opacity = '0';
    toast.style.transform = 'translateY(10px)';
    setTimeout(() => toast.remove(), 300);
  }, 3000);
}

// ---------------- LOADING STATES ----------------

function setLoading(elementId, isLoading) {
  const el = document.getElementById(elementId);
  if (!el) return;

  if (isLoading) {
    el.innerHTML = `
      <div style="display: flex; align-items: center; justify-content: center; padding: var(--space-xl); color: var(--color-text-tertiary);">
        <div class="spinner"></div>
        <span style="margin-left: var(--space-sm);">Laddar...</span>
      </div>
    `;
  }
}

// ---------------- SERIES CLASS HELPER ----------------

function getSeriesClass(seriesName) {
  if (!seriesName) return 'chip';
  const name = seriesName.toLowerCase();
  if (name.includes('enduro')) return 'chip chip--enduro';
  if (name.includes('downhill') || name.includes('dh')) return 'chip chip--downhill';
  if (name.includes('xc') || name.includes('cross')) return 'chip chip--xc';
  if (name.includes('ges')) return 'chip chip--ges';
  if (name.includes('ggs') || name.includes('götaland')) return 'chip chip--ggs';
  if (name.includes('gss') || name.includes('stockholm')) return 'chip';
  return 'chip';
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

    // Load data on-demand (lazy loading)
    if (!LOADED_VIEWS.has(viewName)) {
      LOADED_VIEWS.add(viewName);
      switch(viewName) {
        case 'dashboard':
          loadDashboard().catch(console.error);
          break;
        case 'calendar':
          loadCalendar().catch(console.error);
          break;
        case 'results':
          loadResults().catch(console.error);
          break;
        case 'series':
          loadSeries().catch(console.error);
          break;
        case 'database':
          loadDatabase().catch(console.error);
          break;
        case 'ranking':
          loadRanking().catch(console.error);
          break;
      }
    }

    // Re-initialize Lucide icons after view change
    setTimeout(() => initLucideIcons(), 50);
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

    // Re-initialize icons after dynamic content
    initLucideIcons();
  } catch (e) {
    console.error("Dashboard error", e);
  }
}

// ---------------- CALENDAR ----------------

let calendarEvents = [];
let calendarFilters = { series: '', year: '', discipline: '' };
let calendarFiltersInitialized = false;

async function loadCalendar() {
  const statusEl = document.getElementById("calendar-status");
  const listEl = document.getElementById("calendar-list");
  const badgeEl = document.getElementById("calendar-count-badge");
  if (!statusEl || !listEl || !badgeEl) return;

  try {
    statusEl.textContent = "Laddar event…";
    setLoading("calendar-list", true);

    const events = await apiGet("events.php");
    calendarEvents = events || [];
    statusEl.textContent = "";

    if (!calendarEvents.length) {
      listEl.innerHTML = '<div class="placeholder">Inga event hittades</div>';
      badgeEl.textContent = "0 event";
      return;
    }

    // Populate all filter dropdowns (only once)
    if (!calendarFiltersInitialized) {
      populateCalendarFilters();
      setupCalendarFilterListeners();
      calendarFiltersInitialized = true;
    }

    renderCalendarEvents();
  } catch (e) {
    console.error("Calendar error", e);
    listEl.innerHTML = '<div class="placeholder" style="color: var(--color-error);">Kunde inte ladda kalender</div>';
    statusEl.textContent = "";
  }
}

function populateCalendarFilters() {
  // Series filter
  const series = [...new Set(calendarEvents.map(e => e.series).filter(Boolean))].sort();
  const seriesSelect = document.getElementById("cal-series-filter");
  if (seriesSelect) {
    seriesSelect.innerHTML = '<option value="">Alla serier</option>' +
      series.map(s => `<option value="${s}">${s}</option>`).join("");
  }

  // Year filter
  const years = [...new Set(calendarEvents.map(e => (e.date || "").slice(0, 4)).filter(Boolean))].sort().reverse();
  const yearSelect = document.getElementById("cal-year-filter");
  if (yearSelect) {
    yearSelect.innerHTML = '<option value="">Alla år</option>' +
      years.map(y => `<option value="${y}">${y}</option>`).join("");
  }

  // Discipline filter
  const disciplines = [...new Set(calendarEvents.map(e => e.discipline).filter(Boolean))].sort();
  const discSelect = document.getElementById("cal-discipline-filter");
  if (discSelect) {
    discSelect.innerHTML = '<option value="">Alla discipliner</option>' +
      disciplines.map(d => `<option value="${d}">${d}</option>`).join("");
  }
}

function setupCalendarFilterListeners() {
  const seriesSelect = document.getElementById("cal-series-filter");
  const yearSelect = document.getElementById("cal-year-filter");
  const discSelect = document.getElementById("cal-discipline-filter");

  if (seriesSelect) {
    seriesSelect.addEventListener("change", (e) => {
      calendarFilters.series = e.target.value;
      renderCalendarEvents();
    });
  }
  if (yearSelect) {
    yearSelect.addEventListener("change", (e) => {
      calendarFilters.year = e.target.value;
      renderCalendarEvents();
    });
  }
  if (discSelect) {
    discSelect.addEventListener("change", (e) => {
      calendarFilters.discipline = e.target.value;
      renderCalendarEvents();
    });
  }
}

function renderCalendarEvents() {
  const listEl = document.getElementById("calendar-list");
  const badgeEl = document.getElementById("calendar-count-badge");
  if (!listEl) return;

  // Apply filters
  let filtered = calendarEvents;
  if (calendarFilters.series) {
    filtered = filtered.filter(e => e.series === calendarFilters.series);
  }
  if (calendarFilters.year) {
    filtered = filtered.filter(e => (e.date || "").startsWith(calendarFilters.year));
  }
  if (calendarFilters.discipline) {
    filtered = filtered.filter(e => e.discipline === calendarFilters.discipline);
  }

  // Sort by date (upcoming first)
  const sorted = filtered.sort((a, b) => (a.date || "").localeCompare(b.date || ""));

  badgeEl.textContent = `${sorted.length} event`;

  if (!sorted.length) {
    listEl.innerHTML = '<div class="placeholder">Inga event matchar dina filter</div>';
    return;
  }

  listEl.innerHTML = sorted.map(ev => {
    const parts = monthDayParts(ev.date);
    const seriesClass = getSeriesClass(ev.series || ev.discipline || "");
    return `
      <div class="card mb-sm" style="cursor: pointer; transition: transform var(--transition-fast);"
           onmouseover="this.style.transform='translateX(4px)'"
           onmouseout="this.style.transform='translateX(0)'">
        <div class="flex gap-md items-start">
          <div class="text-center" style="min-width: 50px; background: var(--color-accent); color: white; border-radius: var(--radius-md); padding: var(--space-xs);">
            <div class="text-xs" style="opacity: 0.9;">${parts.month.toUpperCase()}</div>
            <div class="text-lg font-bold">${parts.day}</div>
          </div>
          <div class="flex-1">
            <div class="font-medium">${ev.name || "Okänt event"}</div>
            <div class="flex flex-wrap gap-xs mt-xs">
              <span class="${seriesClass}">${ev.series || ev.discipline || "Event"}</span>
              ${ev.discipline && ev.series ? `<span class="chip">${ev.discipline}</span>` : ""}
              ${ev.location ? `<span class="text-xs text-secondary">${ev.location}</span>` : ""}
            </div>
          </div>
          ${ev.status ? `<div class="chip chip--success">${ev.status}</div>` : ""}
        </div>
      </div>
    `;
  }).join("");

  initLucideIcons();
}

// Legacy renderCalendar for backwards compatibility
function renderCalendar(events, container, yearFilter) {
  calendarEvents = events;
  calendarFilters.year = yearFilter;
  renderCalendarEvents();
}

function _legacyRenderCalendar(events, container, yearFilter) {
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

let resultsEvents = [];
let resultsFilters = { series: '', year: '' };
let resultsFiltersInitialized = false;

async function loadResults() {
  const statusEl = document.getElementById("results-status");
  const listEl = document.getElementById("results-list");
  const badgeEl = document.getElementById("results-count-badge");
  if (!statusEl || !listEl || !badgeEl) return;

  try {
    statusEl.textContent = "Laddar resultat…";
    setLoading("results-list", true);

    // Try results.php first, fallback to events.php
    let rows;
    try {
      rows = await apiGet("results.php");
    } catch {
      // Fallback: use events and filter to past events
      const events = await apiGet("events.php");
      rows = (events || []).filter(e => new Date(e.date) < new Date());
    }

    resultsEvents = rows || [];
    statusEl.textContent = "";

    if (!resultsEvents.length) {
      listEl.innerHTML = '<div class="placeholder">Inga resultat hittades</div>';
      badgeEl.textContent = "0 tävlingar";
      return;
    }

    // Populate filters (only once)
    if (!resultsFiltersInitialized) {
      populateResultsFilters();
      setupResultsFilterListeners();
      resultsFiltersInitialized = true;
    }

    renderResultsEvents();
  } catch (e) {
    console.error("Results error", e);
    listEl.innerHTML = '<div class="placeholder" style="color: var(--color-error);">Kunde inte ladda resultat</div>';
    statusEl.textContent = "";
  }
}

function populateResultsFilters() {
  // Series filter
  const series = [...new Set(resultsEvents.map(e => e.series).filter(Boolean))].sort();
  const seriesSelect = document.getElementById("res-series-filter");
  if (seriesSelect) {
    seriesSelect.innerHTML = '<option value="">Alla serier</option>' +
      series.map(s => `<option value="${s}">${s}</option>`).join("");
  }

  // Year filter
  const years = [...new Set(resultsEvents.map(e => (e.date || "").slice(0, 4)).filter(Boolean))].sort().reverse();
  const yearSelect = document.getElementById("res-year-filter");
  if (yearSelect) {
    yearSelect.innerHTML = '<option value="">Alla år</option>' +
      years.map(y => `<option value="${y}">${y}</option>`).join("");
  }
}

function setupResultsFilterListeners() {
  const seriesSelect = document.getElementById("res-series-filter");
  const yearSelect = document.getElementById("res-year-filter");

  if (seriesSelect) {
    seriesSelect.addEventListener("change", (e) => {
      resultsFilters.series = e.target.value;
      renderResultsEvents();
    });
  }
  if (yearSelect) {
    yearSelect.addEventListener("change", (e) => {
      resultsFilters.year = e.target.value;
      renderResultsEvents();
    });
  }
}

function renderResultsEvents() {
  const listEl = document.getElementById("results-list");
  const badgeEl = document.getElementById("results-count-badge");
  if (!listEl) return;

  // Apply filters
  let filtered = resultsEvents;
  if (resultsFilters.series) {
    filtered = filtered.filter(e => e.series === resultsFilters.series);
  }
  if (resultsFilters.year) {
    filtered = filtered.filter(e => (e.date || "").startsWith(resultsFilters.year));
  }

  // Sort by date (newest first)
  const sorted = filtered.sort((a, b) => (b.date || "").localeCompare(a.date || ""));

  badgeEl.textContent = `${sorted.length} tävlingar`;

  if (!sorted.length) {
    listEl.innerHTML = '<div class="placeholder">Inga resultat matchar dina filter</div>';
    return;
  }

  listEl.innerHTML = sorted.map(ev => {
    const parts = monthDayParts(ev.date);
    const seriesClass = getSeriesClass(ev.series || ev.discipline || "");
    const participants = ev.participants || ev.participants_count || 0;
    return `
      <div class="card mb-sm" style="cursor: pointer; transition: transform var(--transition-fast);"
           onmouseover="this.style.transform='translateX(4px)'"
           onmouseout="this.style.transform='translateX(0)'">
        <div class="flex gap-md items-start justify-between">
          <div class="flex gap-md items-start flex-1">
            <div class="text-center" style="min-width: 50px; background: var(--color-accent); color: white; border-radius: var(--radius-md); padding: var(--space-xs);">
              <div class="text-xs" style="opacity: 0.9;">${parts.month.toUpperCase()}</div>
              <div class="text-lg font-bold">${parts.day}</div>
            </div>
            <div class="flex-1">
              <div class="font-medium">${ev.name || "Okänt event"}</div>
              <div class="flex flex-wrap gap-xs mt-xs">
                <span class="${seriesClass}">${ev.series || ev.discipline || "Event"}</span>
                ${ev.discipline && ev.series ? `<span class="chip">${ev.discipline}</span>` : ""}
              </div>
            </div>
          </div>
          <div class="text-right">
            <div class="text-xl font-bold text-accent">${participants}</div>
            <div class="text-xs text-secondary">deltagare</div>
          </div>
        </div>
      </div>
    `;
  }).join("");

  initLucideIcons();
}

// ---------------- SERIES ----------------

async function loadSeries() {
  const gridEl = document.getElementById("series-grid");
  const emptyEl = document.getElementById("series-empty");

  if (!gridEl) return;

  try {
    if (emptyEl) emptyEl.textContent = "Laddar serier...";
    setLoading("series-grid", true);

    const events = await apiGet("events.php");

    if (!events || !events.length) {
      gridEl.innerHTML = "";
      if (emptyEl) emptyEl.textContent = "Inga serier hittades.";
      return;
    }

    // Group events by series
    const seriesMap = {};
    events.forEach(event => {
      const series = event.series || event.discipline || "Övriga";
      if (!seriesMap[series]) {
        seriesMap[series] = {
          name: series,
          events: [],
          participants: 0
        };
      }
      seriesMap[series].events.push(event);
      seriesMap[series].participants += parseInt(event.participants || event.participants_count || 0);
    });

    const seriesList = Object.values(seriesMap).sort((a, b) => b.events.length - a.events.length);
    if (emptyEl) emptyEl.textContent = "";

    // Render series grid
    gridEl.innerHTML = seriesList.map(series => {
      const seriesClass = getSeriesClass(series.name);

      return `
        <div class="card card--clickable" style="cursor: pointer;">
          <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: var(--space-md);">
            <span class="${seriesClass}">${series.name}</span>
            <span style="font-size: var(--text-xs); color: var(--color-text-tertiary); background: var(--color-bg-sunken); padding: var(--space-2xs) var(--space-xs); border-radius: var(--radius-sm);">2025</span>
          </div>
          <h3 style="margin: 0 0 var(--space-xs) 0; font-size: var(--text-md);">${series.name}</h3>
          <p style="font-size: var(--text-sm); color: var(--color-text-secondary); margin: 0;">${series.events.length} tävling${series.events.length !== 1 ? 'ar' : ''}</p>
          <div class="stats-row" style="margin-top: var(--space-md);">
            <div class="stat-block">
              <div class="stat-value" style="font-size: var(--text-xl);">${series.events.length}</div>
              <div class="stat-label">Tävlingar</div>
            </div>
            <div class="stat-block">
              <div class="stat-value" style="font-size: var(--text-xl);">${series.participants || "–"}</div>
              <div class="stat-label">Deltagare</div>
            </div>
          </div>
        </div>
      `;
    }).join("");

  } catch (e) {
    console.error("Series error", e);
    gridEl.innerHTML = "";
    if (emptyEl) emptyEl.textContent = "Kunde inte ladda serier.";
    showToast("Kunde inte ladda serier", "error");
  }
}

// ---------------- DATABASE (Riders + Clubs) ----------------

let DB_STATE = {
  riders: [],
  clubs: [],
  mode: 'riders', // 'riders' or 'clubs'
  searchInitialized: false
};

function setupDbTabs() {
  const buttons = document.querySelectorAll("[data-db-tab]");
  const ridersCol = document.getElementById("db-riders-column");
  const clubsCol = document.getElementById("db-clubs-column");
  const searchInput = document.getElementById("db-search-input");
  if (!buttons.length || !ridersCol || !clubsCol) return;

  function setTab(tab) {
    DB_STATE.mode = tab;
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

    // Update search placeholder
    if (searchInput) {
      searchInput.placeholder = tab === "riders"
        ? "Skriv namn, klubb eller Gravity ID…"
        : "Skriv klubbnamn…";
      // Re-run search with current query
      const q = searchInput.value.trim().toLowerCase();
      if (q.length >= 2) {
        performDbSearch(q);
      } else {
        // Show top items
        if (tab === "riders") {
          renderDbRiders(DB_STATE.riders.slice(0, 30), document.getElementById("db-riders-list"));
        } else {
          renderDbClubs(DB_STATE.clubs.slice(0, 30), document.getElementById("db-clubs-list"));
        }
      }
    }
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
    setLoading("db-riders-list", true);

    const riders = await apiGet("riders.php");
    DB_STATE.riders = riders || [];

    // derive clubs from riders
    const clubMap = new Map();
    DB_STATE.riders.forEach((r) => {
      const name = r.club_name || r.club || "Okänd klubb";
      if (!clubMap.has(name)) clubMap.set(name, { count: 0, points: 0 });
      const club = clubMap.get(name);
      club.count++;
      club.points += parseInt(r.total_points || 0);
    });
    DB_STATE.clubs = Array.from(clubMap.entries())
      .map(([name, data]) => ({ name, count: data.count, total_points: data.points }))
      .sort((a, b) => b.count - a.count);

    renderDbRiders(DB_STATE.riders.slice(0, 30), ridersList);
    renderDbClubs(DB_STATE.clubs.slice(0, 30), clubsList);

    // counters
    setText("db-riders-total", DB_STATE.riders.length);
    setText("db-clubs-total", DB_STATE.clubs.length);

    // Setup search with debounce (only once)
    if (searchInput && !DB_STATE.searchInitialized) {
      let debounceTimer;
      searchInput.addEventListener("input", () => {
        clearTimeout(debounceTimer);
        const q = searchInput.value.trim().toLowerCase();

        debounceTimer = setTimeout(() => {
          if (q.length < 2) {
            // Show top items
            if (DB_STATE.mode === "riders") {
              renderDbRiders(DB_STATE.riders.slice(0, 30), ridersList);
            } else {
              renderDbClubs(DB_STATE.clubs.slice(0, 30), clubsList);
            }
            return;
          }
          performDbSearch(q);
        }, 300);
      });
      DB_STATE.searchInitialized = true;
    }
  } catch (e) {
    console.error("DB error", e);
    showToast("Kunde inte ladda databas", "error");
  }
}

function performDbSearch(query) {
  const ridersList = document.getElementById("db-riders-list");
  const clubsList = document.getElementById("db-clubs-list");

  if (DB_STATE.mode === "riders") {
    const filtered = DB_STATE.riders.filter((r) => {
      const full = `${r.firstname || r.first_name || ""} ${r.lastname || r.last_name || ""} ${r.gravity_id || ""} ${r.club_name || r.club || ""}`.toLowerCase();
      return full.includes(query);
    });
    renderDbRiders(filtered, ridersList);
  } else {
    const filtered = DB_STATE.clubs.filter((c) => {
      return (c.name || "").toLowerCase().includes(query);
    });
    renderDbClubs(filtered, clubsList);
  }
}

function renderDbRiders(rows, container) {
  if (!rows || rows.length === 0) {
    container.innerHTML = '<div class="placeholder">Inga åkare hittades</div>';
    return;
  }

  container.innerHTML = rows.slice(0, 50).map((r, idx) => {
    const firstName = r.firstname || r.first_name || "";
    const lastName = r.lastname || r.last_name || "";
    const club = r.club_name || r.club || "–";
    const gravityId = r.gravity_id || "";

    return `
      <div class="card mb-sm" style="cursor: pointer; transition: transform var(--transition-fast);"
           onmouseover="this.style.transform='translateX(4px)'"
           onmouseout="this.style.transform='translateX(0)'">
        <div class="flex justify-between items-center">
          <div class="flex items-center gap-md">
            <div style="width: 32px; height: 32px; border-radius: 50%; background: var(--color-accent-light); color: var(--color-accent-text); display: flex; align-items: center; justify-content: center; font-weight: var(--weight-bold); font-size: var(--text-sm); flex-shrink: 0;">
              ${idx + 1}
            </div>
            <div>
              <div class="font-medium">${firstName} ${lastName}</div>
              <div class="text-sm text-secondary">${club} ${gravityId ? '· ' + gravityId : ''}</div>
            </div>
          </div>
          ${r.total_points ? `<div class="chip">${r.total_points} p</div>` : ''}
        </div>
      </div>
    `;
  }).join("");
}

function renderDbClubs(rows, container) {
  if (!rows || rows.length === 0) {
    container.innerHTML = '<div class="placeholder">Inga klubbar hittades</div>';
    return;
  }

  container.innerHTML = rows.slice(0, 30).map((c, idx) => `
    <div class="card mb-sm" style="cursor: pointer; transition: transform var(--transition-fast);"
         onmouseover="this.style.transform='translateX(4px)'"
         onmouseout="this.style.transform='translateX(0)'">
      <div class="flex justify-between items-center">
        <div class="flex items-center gap-md">
          <div style="width: 32px; height: 32px; border-radius: 50%; background: var(--color-success-light); color: var(--color-success); display: flex; align-items: center; justify-content: center; font-weight: var(--weight-bold); font-size: var(--text-sm); flex-shrink: 0;">
            ${idx + 1}
          </div>
          <div>
            <div class="font-medium">${c.name}</div>
            <div class="text-sm text-secondary">${c.count} registrerade åkare</div>
          </div>
        </div>
        ${c.total_points ? `
          <div class="text-right">
            <div class="text-lg font-bold text-accent">${c.total_points}</div>
            <div class="text-xs text-secondary">poäng</div>
          </div>
        ` : ''}
      </div>
    </div>
  `).join("");
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
