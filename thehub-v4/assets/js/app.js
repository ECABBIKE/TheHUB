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

  // Handle URL routing (replaces hydrateUI)
  handleURLRouting();

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
    const eventId = ev.id || ev.event_id || "";
    return `
      <div class="card mb-sm" style="cursor: pointer; transition: transform var(--transition-fast);"
           onclick="navigateTo('event', '${eventId}')"
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
          <div class="flex items-center gap-sm">
            ${ev.status ? `<div class="chip chip--success">${ev.status}</div>` : ""}
            <i data-lucide="chevron-right" style="color: var(--color-text-tertiary);"></i>
          </div>
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

let SERIES_STATE = {
  current: null,
  standings: [],
  events: []
};

async function loadSeries() {
  const gridEl = document.getElementById("series-grid");
  const emptyEl = document.getElementById("series-empty");

  if (!gridEl) return;

  try {
    if (emptyEl) emptyEl.textContent = "Laddar serier...";
    gridEl.innerHTML = "";

    const data = await apiGet("series.php");

    if (!data || !data.length) {
      if (emptyEl) emptyEl.textContent = "Inga serier hittades.";
      return;
    }

    if (emptyEl) emptyEl.textContent = "";

    // Render series grid
    gridEl.innerHTML = data.map(series => {
      const seriesClass = getSeriesClass(series.name);
      const initials = series.name.split(' ').map(w => w[0]).join('').substring(0, 2);
      const bestText = series.best_results_count
        ? `Räknar ${series.best_results_count} bästa`
        : 'Alla resultat räknas';

      return `
        <div class="card" style="cursor: pointer; transition: transform var(--transition-fast), box-shadow var(--transition-fast); position: relative;"
             onclick="navigateTo('series-detail', '${series.slug}')"
             onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='var(--shadow-lg)';"
             onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='none';">

          <!-- Year Badge -->
          <div style="position: absolute; top: var(--space-md); right: var(--space-md); background: var(--color-accent); color: white; padding: var(--space-2xs) var(--space-sm); border-radius: var(--radius-sm); font-size: var(--text-xs); font-weight: var(--weight-bold);">
            ${series.year}
          </div>

          <!-- Logo/Initials -->
          <div style="width: 64px; height: 64px; border-radius: var(--radius-md); background: var(--color-accent-light); color: var(--color-accent-text); display: flex; align-items: center; justify-content: center; font-size: var(--text-xl); font-weight: var(--weight-bold); margin-bottom: var(--space-md);">
            ${initials}
          </div>

          <!-- Name & Description -->
          <h3 style="margin: 0 0 var(--space-xs) 0; font-size: var(--text-md); font-weight: var(--weight-semibold);">${series.name}</h3>
          <p style="font-size: var(--text-sm); color: var(--color-text-secondary); margin: 0 0 var(--space-xs) 0; min-height: 40px;">${series.description || ''}</p>
          <p style="font-size: var(--text-xs); color: var(--color-text-tertiary); margin: 0 0 var(--space-md) 0;">
            <i data-lucide="calculator" style="width: 12px; height: 12px; vertical-align: middle;"></i> ${bestText}
          </p>

          <!-- Stats -->
          <div style="display: flex; gap: var(--space-lg); border-top: 1px solid var(--color-divider); padding-top: var(--space-md); margin-top: auto;">
            <div>
              <div style="font-size: var(--text-lg); font-weight: var(--weight-bold); color: var(--color-accent-text);">${series.event_count}</div>
              <div style="font-size: var(--text-xs); color: var(--color-text-tertiary); text-transform: uppercase;">tävlingar</div>
            </div>
            <div>
              <div style="font-size: var(--text-lg); font-weight: var(--weight-bold); color: var(--color-accent-text);">${series.participant_count || 0}</div>
              <div style="font-size: var(--text-xs); color: var(--color-text-tertiary); text-transform: uppercase;">deltagare</div>
            </div>
          </div>

          <!-- Arrow indicator -->
          <div style="position: absolute; bottom: var(--space-md); right: var(--space-md); color: var(--color-text-tertiary);">
            <i data-lucide="chevron-right"></i>
          </div>
        </div>
      `;
    }).join("");

    initLucideIcons();

  } catch (e) {
    console.error("Series error", e);
    gridEl.innerHTML = "";
    if (emptyEl) emptyEl.textContent = "Kunde inte ladda serier.";
    showToast("Kunde inte ladda serier", "error");
  }
}

// ============ SERIES DETAIL ============

async function loadSeriesDetail(seriesId) {
  try {
    // Load series info and events
    const data = await apiGet(`series-detail.php?id=${seriesId}`);

    if (!data) {
      showToast('Serie hittades inte', 'error');
      return;
    }

    SERIES_STATE.current = data;
    SERIES_STATE.events = data.events || [];

    // Populate header
    document.getElementById('series-detail-name').textContent = data.name;
    document.getElementById('series-detail-description').textContent = data.description || '';
    document.getElementById('series-detail-year').textContent = data.year;

    // Logo initials
    const initials = data.name.split(' ').map(w => w[0]).join('').substring(0, 2);
    document.getElementById('series-detail-logo').textContent = initials;

    // Stats
    document.getElementById('series-detail-events').textContent = data.event_count;
    document.getElementById('series-detail-participants').textContent = data.participant_count;
    document.getElementById('series-detail-best-count').textContent = data.best_results_count
      ? `${data.best_results_count} bästa`
      : 'Alla';

    // Meta info
    const meta = [];
    meta.push(`<i data-lucide="tag" style="width: 14px; height: 14px; vertical-align: middle;"></i> ${data.discipline || 'Mixed'}`);
    if (data.best_results_count) {
      meta.push(`<i data-lucide="calculator" style="width: 14px; height: 14px; vertical-align: middle;"></i> Räknar ${data.best_results_count} bästa resultat`);
    }
    document.getElementById('series-detail-meta').innerHTML = meta.join(' &nbsp;•&nbsp; ');

    // Render events list
    renderSeriesEvents(data.events || []);

    // Populate category filter
    const categoryFilter = document.getElementById('series-category-filter');
    categoryFilter.innerHTML = '<option value="">Alla klasser</option>' +
      (data.categories || []).map(cat => `<option value="${cat}">${cat}</option>`).join('');

    // Setup category filter change handler
    categoryFilter.onchange = () => {
      const category = categoryFilter.value;
      loadSeriesStandings(seriesId, category || null);
    };

    // Load standings
    loadSeriesStandings(seriesId, null);

    // Re-init icons
    initLucideIcons();

  } catch (error) {
    console.error('Series detail error:', error);
    showToast('Kunde inte ladda serie', 'error');
  }
}

function renderSeriesEvents(events) {
  const listEl = document.getElementById('series-events-list');

  if (!events || events.length === 0) {
    listEl.innerHTML = '<div class="placeholder" style="padding: var(--space-lg); text-align: center;">Inga tävlingar i serien än</div>';
    return;
  }

  listEl.innerHTML = events.map((event, index) => {
    const date = new Date(event.date);
    const dateStr = date.toLocaleDateString('sv-SE', { day: 'numeric', month: 'short' });
    const hasResults = (event.participant_count || 0) > 0;
    const isPast = date < new Date();

    return `
      <div style="display: flex; justify-content: space-between; align-items: center; padding: var(--space-md); border-bottom: 1px solid var(--color-divider); cursor: pointer; transition: background var(--transition-fast);"
           onclick="navigateTo('event', '${event.id}')"
           onmouseover="this.style.background='var(--color-bg-hover)';"
           onmouseout="this.style.background='transparent';">
        <div style="display: flex; align-items: center; gap: var(--space-md); flex: 1;">
          <div style="width: 32px; text-align: center; color: var(--color-accent-text); font-weight: var(--weight-bold); font-size: var(--text-lg);">
            ${index + 1}
          </div>
          <div style="min-width: 80px;">
            <div style="font-size: var(--text-sm); color: var(--color-text-secondary);">${dateStr}</div>
          </div>
          <div style="flex: 1;">
            <div style="font-weight: var(--weight-semibold);">${event.name}</div>
            ${event.location ? `<div style="font-size: var(--text-sm); color: var(--color-text-tertiary);">${event.location}</div>` : ''}
          </div>
        </div>
        <div style="display: flex; align-items: center; gap: var(--space-sm);">
          ${hasResults ?
            `<span class="chip chip--success">${event.participant_count} deltagare</span>` :
            isPast ?
              `<span class="chip">Inväntar resultat</span>` :
              `<span style="color: var(--color-text-tertiary); font-size: var(--text-sm);">Kommande</span>`
          }
          <i data-lucide="chevron-right" style="color: var(--color-text-tertiary); width: 16px; height: 16px;"></i>
        </div>
      </div>
    `;
  }).join('');

  initLucideIcons();
}

async function loadSeriesStandings(seriesId, category) {
  const containerEl = document.getElementById('series-standings-container');
  const infoEl = document.getElementById('series-standings-info');

  try {
    containerEl.innerHTML = '<div style="text-align: center; padding: var(--space-xl); color: var(--color-text-tertiary);">Laddar ställning...</div>';

    const endpoint = category
      ? `series-standings.php?id=${seriesId}&category=${encodeURIComponent(category)}`
      : `series-standings.php?id=${seriesId}`;

    const data = await apiGet(endpoint);

    if (!data || !data.standings || data.standings.length === 0) {
      containerEl.innerHTML = '<div class="placeholder" style="padding: var(--space-lg); text-align: center;">Ingen ställning ännu - inga resultat registrerade.</div>';
      infoEl.textContent = '';
      return;
    }

    SERIES_STATE.standings = data.standings;
    const events = data.events || [];

    // Info text
    const bestCount = data.series.best_results_count;
    if (bestCount) {
      infoEl.textContent = `Räknar de ${bestCount} bästa resultaten per åkare`;
    } else {
      infoEl.textContent = 'Alla resultat räknas';
    }

    // Group standings by category
    const categories = {};
    data.standings.forEach(rider => {
      const cat = rider.category || 'Övriga';
      if (!categories[cat]) categories[cat] = [];
      categories[cat].push(rider);
    });

    // Render standings table per category
    containerEl.innerHTML = Object.entries(categories).map(([catName, riders]) => `
      <div style="margin-bottom: var(--space-xl);">
        <h3 style="color: var(--color-accent-text); margin-bottom: var(--space-md); font-size: var(--text-lg);">
          <i data-lucide="users" style="width: 18px; height: 18px; vertical-align: middle;"></i> ${catName}
          <span style="font-size: var(--text-sm); font-weight: normal; color: var(--color-text-tertiary);">(${riders.length} åkare)</span>
        </h3>
        <div style="overflow-x: auto;">
          <table style="width: 100%; border-collapse: collapse; font-size: var(--text-sm); min-width: 600px;">
            <thead>
              <tr style="border-bottom: 2px solid var(--color-border); background: var(--color-bg-sunken);">
                <th style="padding: var(--space-sm); text-align: left; width: 50px;">#</th>
                <th style="padding: var(--space-sm); text-align: left;">Namn</th>
                <th style="padding: var(--space-sm); text-align: left;">Klubb</th>
                ${events.map((ev, i) => `<th style="padding: var(--space-sm); text-align: center; min-width: 50px;" title="${ev.name}">#${i + 1}</th>`).join('')}
                <th style="padding: var(--space-sm); text-align: right; font-weight: var(--weight-bold);">Total</th>
              </tr>
            </thead>
            <tbody>
              ${riders.map(rider => {
                const pos = rider.position;
                const medal = pos === 1 ? '🥇' : pos === 2 ? '🥈' : pos === 3 ? '🥉' : pos;

                return `
                  <tr style="border-bottom: 1px solid var(--color-divider); cursor: pointer; transition: background var(--transition-fast);"
                      onclick="navigateTo('rider', '${rider.rider_id}')"
                      onmouseover="this.style.background='var(--color-bg-hover)';"
                      onmouseout="this.style.background='transparent';">
                    <td style="padding: var(--space-md) var(--space-sm); font-weight: var(--weight-bold);">
                      ${medal}
                    </td>
                    <td style="padding: var(--space-md) var(--space-sm);">
                      <div style="font-weight: var(--weight-medium);">${rider.rider_name}</div>
                      ${rider.gravity_id ? `<div style="font-size: var(--text-xs); color: var(--color-text-tertiary);">${rider.gravity_id}</div>` : ''}
                    </td>
                    <td style="padding: var(--space-md) var(--space-sm); color: var(--color-text-secondary);">
                      ${rider.club_name || '–'}
                    </td>
                    ${events.map(ev => {
                      const points = rider.event_points && rider.event_points[ev.id];
                      const isCounted = rider.counted_event_ids && rider.counted_event_ids.includes(ev.id);
                      const style = isCounted
                        ? 'font-weight: var(--weight-bold); color: var(--color-accent-text);'
                        : 'color: var(--color-text-tertiary);';
                      return `<td style="padding: var(--space-md) var(--space-sm); text-align: center; ${style}">${points || '–'}</td>`;
                    }).join('')}
                    <td style="padding: var(--space-md) var(--space-sm); text-align: right; font-weight: var(--weight-bold); color: var(--color-accent-text); font-size: var(--text-md);">
                      ${rider.total_points}
                    </td>
                  </tr>
                `;
              }).join('')}
            </tbody>
          </table>
        </div>
      </div>
    `).join('');

    initLucideIcons();

  } catch (error) {
    console.error('Series standings error:', error);
    containerEl.innerHTML = '<div class="placeholder" style="color: var(--color-error); padding: var(--space-lg); text-align: center;">Kunde inte ladda ställning</div>';
  }
}

function filterSeriesStandings() {
  const searchTerm = document.getElementById('series-search-input').value.toLowerCase().trim();
  const category = document.getElementById('series-category-filter').value;

  if (!SERIES_STATE.current) return;

  // If search term, filter locally
  if (searchTerm && SERIES_STATE.standings.length > 0) {
    const filtered = SERIES_STATE.standings.filter(rider => {
      const name = (rider.rider_name || '').toLowerCase();
      const club = (rider.club_name || '').toLowerCase();
      const gravityId = (rider.gravity_id || '').toLowerCase();
      return name.includes(searchTerm) || club.includes(searchTerm) || gravityId.includes(searchTerm);
    });

    // Re-render with filtered data
    const containerEl = document.getElementById('series-standings-container');
    if (filtered.length === 0) {
      containerEl.innerHTML = `<div class="placeholder" style="padding: var(--space-lg); text-align: center;">Inga åkare matchar "${searchTerm}"</div>`;
      return;
    }

    // Simplified render for search results
    containerEl.innerHTML = `
      <div style="overflow-x: auto;">
        <table style="width: 100%; border-collapse: collapse; font-size: var(--text-sm);">
          <thead>
            <tr style="border-bottom: 2px solid var(--color-border); background: var(--color-bg-sunken);">
              <th style="padding: var(--space-sm); text-align: left; width: 50px;">#</th>
              <th style="padding: var(--space-sm); text-align: left;">Namn</th>
              <th style="padding: var(--space-sm); text-align: left;">Klubb</th>
              <th style="padding: var(--space-sm); text-align: left;">Klass</th>
              <th style="padding: var(--space-sm); text-align: right;">Poäng</th>
            </tr>
          </thead>
          <tbody>
            ${filtered.map(rider => `
              <tr style="border-bottom: 1px solid var(--color-divider); cursor: pointer;"
                  onclick="navigateTo('rider', '${rider.rider_id}')">
                <td style="padding: var(--space-md) var(--space-sm); font-weight: var(--weight-bold);">${rider.position}</td>
                <td style="padding: var(--space-md) var(--space-sm); font-weight: var(--weight-medium);">${rider.rider_name}</td>
                <td style="padding: var(--space-md) var(--space-sm); color: var(--color-text-secondary);">${rider.club_name || '–'}</td>
                <td style="padding: var(--space-md) var(--space-sm);"><span class="chip">${rider.category || '–'}</span></td>
                <td style="padding: var(--space-md) var(--space-sm); text-align: right; font-weight: var(--weight-bold); color: var(--color-accent-text);">${rider.total_points}</td>
              </tr>
            `).join('')}
          </tbody>
        </table>
      </div>
    `;
    return;
  }

  // Otherwise reload from API with category filter
  loadSeriesStandings(SERIES_STATE.current.slug || SERIES_STATE.current.id, category || null);
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
    const riderId = r.gravity_id || r.id || "";

    return `
      <div class="card mb-sm" style="cursor: pointer; transition: transform var(--transition-fast);"
           onclick="navigateTo('rider', '${riderId}')"
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
          <div class="flex items-center gap-sm">
            ${r.total_points ? `<div class="chip">${r.total_points} p</div>` : ''}
            <i data-lucide="chevron-right" style="color: var(--color-text-tertiary);"></i>
          </div>
        </div>
      </div>
    `;
  }).join("");
  initLucideIcons();
}

function renderDbClubs(rows, container) {
  if (!rows || rows.length === 0) {
    container.innerHTML = '<div class="placeholder">Inga klubbar hittades</div>';
    return;
  }

  container.innerHTML = rows.slice(0, 30).map((c, idx) => {
    const clubId = encodeURIComponent(c.name || "");
    return `
    <div class="card mb-sm" style="cursor: pointer; transition: transform var(--transition-fast);"
         onclick="navigateTo('club', '${clubId}')"
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
        <div class="flex items-center gap-sm">
          ${c.total_points ? `
            <div class="text-right">
              <div class="text-lg font-bold text-accent">${c.total_points}</div>
              <div class="text-xs text-secondary">poäng</div>
            </div>
          ` : ''}
          <i data-lucide="chevron-right" style="color: var(--color-text-tertiary);"></i>
        </div>
      </div>
    </div>
  `}).join("");
  initLucideIcons();
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

// ============ URL ROUTING ============

function getURLParams() {
  const params = new URLSearchParams(window.location.search);
  return {
    view: params.get('view'),
    id: params.get('id')
  };
}

function handleURLRouting() {
  const params = getURLParams();

  if (params.view && params.id) {
    // Special views with IDs
    switch(params.view) {
      case 'rider':
        showView('rider');
        loadRiderProfile(params.id);
        break;
      case 'club':
        showView('club');
        loadClubProfile(params.id);
        break;
      case 'event':
        showView('event');
        loadEventDetail(params.id);
        break;
      case 'series-detail':
        showView('series-detail');
        loadSeriesDetail(params.id);
        break;
      default:
        showView('dashboard');
        loadDashboard().catch(console.error);
    }
  } else if (params.view) {
    // Regular views
    showView(params.view);
  } else {
    // Default to dashboard
    showView('dashboard');
    loadDashboard().catch(console.error);
  }
}

function showView(viewName) {
  // Hide all views
  document.querySelectorAll('.page-content').forEach(view => {
    view.style.display = 'none';
  });

  // Show target view
  const targetView = document.getElementById(`view-${viewName}`);
  if (targetView) {
    targetView.style.display = 'block';
  }

  // Update sidebar
  document.querySelectorAll('.sidebar-link').forEach(link => {
    if (link.dataset.view === viewName) {
      link.setAttribute('aria-current', 'page');
    } else {
      link.removeAttribute('aria-current');
    }
  });

  // Update mobile nav
  document.querySelectorAll('.mobile-nav-link').forEach(link => {
    if (link.dataset.view === viewName) {
      link.classList.add('active');
    } else {
      link.classList.remove('active');
    }
  });

  // Load view data (if not a special profile view)
  if (!['rider', 'club', 'event'].includes(viewName) && !LOADED_VIEWS.has(viewName)) {
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

  // Re-init Lucide
  setTimeout(() => initLucideIcons(), 100);
}

function navigateTo(viewName, id = null) {
  const url = id ? `?view=${viewName}&id=${id}` : `?view=${viewName}`;
  window.history.pushState({}, '', url);
  if (id) {
    showView(viewName);
    switch(viewName) {
      case 'rider':
        loadRiderProfile(id);
        break;
      case 'club':
        loadClubProfile(id);
        break;
      case 'event':
        loadEventDetail(id);
        break;
    }
  } else {
    showView(viewName);
  }
}

// Handle browser back/forward
window.addEventListener('popstate', handleURLRouting);

// ============ RIDER PROFILE ============

async function loadRiderProfile(riderId) {
  try {
    setLoading('rider-results-list', true);

    // Try to find rider in cached data first, or fetch from API
    let rider = DB_STATE.riders.find(r => r.id == riderId || r.gravity_id == riderId);

    if (!rider) {
      // Try fetching from API
      try {
        rider = await apiGet(`rider.php?id=${riderId}`);
      } catch {
        // Fallback: search in riders list
        const riders = await apiGet('riders.php');
        rider = (riders || []).find(r => r.id == riderId || r.gravity_id == riderId);
      }
    }

    if (!rider) {
      showToast('Åkare hittades inte', 'error');
      document.getElementById('rider-name').textContent = 'Åkare hittades inte';
      return;
    }

    // Populate header
    const firstName = rider.firstname || rider.first_name || '';
    const lastName = rider.lastname || rider.last_name || '';
    const initials = `${firstName[0] || ''}${lastName[0] || ''}`.toUpperCase();

    document.getElementById('rider-avatar').textContent = initials || '?';
    document.getElementById('rider-name').textContent = `${firstName} ${lastName}`;
    document.getElementById('rider-club').textContent = rider.club_name || rider.club || 'Ingen klubb';

    // Meta info
    const metaParts = [];
    if (rider.birth_year) metaParts.push(`f. ${rider.birth_year}`);
    if (rider.gender) metaParts.push(rider.gender === 'M' ? 'Man' : 'Kvinna');
    if (rider.gravity_id) metaParts.push(`GID: ${rider.gravity_id}`);
    document.getElementById('rider-meta').innerHTML = metaParts.map(p => `<span>${p}</span>`).join(' • ');

    // Try to load results
    let results = [];
    try {
      results = await apiGet(`results.php?rider_id=${riderId}`);
    } catch {
      // Results API might not exist
    }

    // Calculate stats from results or use rider data
    const stats = calculateRiderStats(results, rider);
    document.getElementById('rider-stat-starts').textContent = stats.starts;
    document.getElementById('rider-stat-completed').textContent = stats.completed;
    document.getElementById('rider-stat-wins').textContent = stats.wins;
    document.getElementById('rider-stat-podiums').textContent = stats.podiums;

    // Ranking badge
    if (rider.ranking_position || rider.total_points) {
      const badge = document.getElementById('rider-ranking-badge');
      if (rider.ranking_position) {
        badge.querySelector('div:last-child').textContent = `#${rider.ranking_position}`;
      } else if (rider.total_points) {
        badge.querySelector('div:first-child').textContent = 'POÄNG';
        badge.querySelector('div:last-child').textContent = rider.total_points;
      }
    }

    // Setup tabs
    setupRiderTabs();

    // Render results
    renderRiderResults(results);

    initLucideIcons();

  } catch (error) {
    console.error('Rider profile error:', error);
    showToast('Kunde inte ladda åkarprofil', 'error');
  }
}

function calculateRiderStats(results, rider) {
  if (results && results.length > 0) {
    return {
      starts: results.length,
      completed: results.filter(r => r.position).length,
      wins: results.filter(r => r.position === 1).length,
      podiums: results.filter(r => r.position && r.position <= 3).length
    };
  }

  // Fallback to rider object data
  return {
    starts: rider.events_count || rider.race_count || 0,
    completed: rider.events_count || 0,
    wins: rider.wins || 0,
    podiums: rider.podiums || 0
  };
}

function setupRiderTabs() {
  document.querySelectorAll('.rider-tab-btn').forEach(btn => {
    btn.addEventListener('click', () => {
      document.querySelectorAll('.rider-tab-btn').forEach(b => b.classList.remove('active'));
      btn.classList.add('active');

      const tab = btn.dataset.tab;
      document.querySelectorAll('.rider-tab-content').forEach(content => {
        content.style.display = content.id === `rider-tab-${tab}` ? 'block' : 'none';
      });
    });
  });
}

function renderRiderResults(results) {
  const listEl = document.getElementById('rider-results-list');

  if (!results || results.length === 0) {
    listEl.innerHTML = '<div class="placeholder">Inga resultat registrerade</div>';
    return;
  }

  // Sort by date (newest first)
  const sorted = results.sort((a, b) => new Date(b.date) - new Date(a.date));

  listEl.innerHTML = sorted.map(result => {
    const position = result.position || '–';
    const positionEmoji = position === 1 ? '🥇' : position === 2 ? '🥈' : position === 3 ? '🥉' : position;
    const seriesClass = getSeriesClass(result.series || '');
    const date = new Date(result.date);

    return `
      <div style="display: flex; justify-content: space-between; align-items: center; padding: var(--space-md) 0; border-bottom: 1px solid var(--color-divider);">
        <div style="display: flex; align-items: center; gap: var(--space-md); flex: 1;">
          <div style="font-size: var(--text-xl); width: 40px; text-align: center;">
            ${positionEmoji}
          </div>
          <div style="flex: 1;">
            <div style="font-weight: var(--weight-semibold);">${result.event_name || result.name || 'Event'}</div>
            <div style="display: flex; gap: var(--space-xs); margin-top: var(--space-2xs); flex-wrap: wrap;">
              ${result.series ? `<span class="${seriesClass}" style="font-size: var(--text-xs);">${result.series}</span>` : ''}
              ${result.category ? `<span class="chip" style="font-size: var(--text-xs);">${result.category}</span>` : ''}
            </div>
          </div>
        </div>
        <div style="text-align: right;">
          <div style="font-size: var(--text-sm); color: var(--color-text-tertiary);">
            ${date.toLocaleDateString('sv-SE')}
          </div>
          ${result.points ? `<div style="font-weight: var(--weight-bold); color: var(--color-accent-text);">${result.points} p</div>` : ''}
        </div>
      </div>
    `;
  }).join('');
}

// ============ CLUB PROFILE ============

async function loadClubProfile(clubId) {
  try {
    // Find club in cached data
    let club = DB_STATE.clubs.find(c => c.id == clubId || c.name === clubId);

    if (!club) {
      // Search by name in riders
      const clubName = decodeURIComponent(clubId);
      const clubRiders = DB_STATE.riders.filter(r =>
        (r.club_name || r.club || '').toLowerCase() === clubName.toLowerCase()
      );

      if (clubRiders.length > 0) {
        club = {
          name: clubRiders[0].club_name || clubRiders[0].club,
          count: clubRiders.length,
          riders: clubRiders
        };
      }
    }

    if (!club) {
      showToast('Klubb hittades inte', 'error');
      document.getElementById('club-name').textContent = 'Klubb hittades inte';
      return;
    }

    // Populate header
    document.getElementById('club-name').textContent = club.name;
    document.getElementById('club-location').textContent = club.location || '';

    // Get riders for this club
    const clubRiders = club.riders || DB_STATE.riders.filter(r =>
      (r.club_name || r.club || '') === club.name
    );

    // Stats
    document.getElementById('club-stat-members').textContent = club.count || clubRiders.length;
    document.getElementById('club-stat-active').textContent = clubRiders.filter(r => r.events_count > 0).length || clubRiders.length;

    const totalStarts = clubRiders.reduce((sum, r) => sum + (r.events_count || 0), 0);
    const totalPoints = clubRiders.reduce((sum, r) => sum + (parseInt(r.total_points) || 0), 0);

    document.getElementById('club-stat-starts').textContent = totalStarts || '–';
    document.getElementById('club-stat-points').textContent = totalPoints || '–';

    // Top riders
    renderClubTopRiders(clubRiders);

    // Recent results (placeholder - would need results API)
    document.getElementById('club-recent-results').innerHTML = '<div class="placeholder">Resultat laddas från tävlingar</div>';

    initLucideIcons();

  } catch (error) {
    console.error('Club profile error:', error);
    showToast('Kunde inte ladda klubbprofil', 'error');
  }
}

function renderClubTopRiders(riders) {
  const listEl = document.getElementById('club-top-riders');

  if (!riders || riders.length === 0) {
    listEl.innerHTML = '<div class="placeholder">Inga åkare registrerade</div>';
    return;
  }

  // Sort by points or events
  const sorted = riders.sort((a, b) => (b.total_points || 0) - (a.total_points || 0));

  listEl.innerHTML = sorted.slice(0, 10).map((rider, index) => {
    const firstName = rider.firstname || rider.first_name || '';
    const lastName = rider.lastname || rider.last_name || '';
    const riderId = rider.id || rider.gravity_id;

    return `
      <div style="display: flex; justify-content: space-between; align-items: center; padding: var(--space-sm) 0; border-bottom: 1px solid var(--color-divider); cursor: pointer;"
           onclick="navigateTo('rider', '${riderId}')">
        <div style="display: flex; align-items: center; gap: var(--space-sm);">
          <div style="width: 24px; height: 24px; border-radius: 50%; background: var(--color-accent-light); color: var(--color-accent-text); display: flex; align-items: center; justify-content: center; font-weight: var(--weight-semibold); font-size: var(--text-xs);">
            ${index + 1}
          </div>
          <div>
            <div style="font-weight: var(--weight-medium);">${firstName} ${lastName}</div>
            <div style="font-size: var(--text-xs); color: var(--color-text-tertiary);">${rider.events_count || 0} starter</div>
          </div>
        </div>
        ${rider.total_points ? `<div style="font-weight: var(--weight-bold); color: var(--color-accent-text);">${rider.total_points}p</div>` : ''}
      </div>
    `;
  }).join('');
}

// ============ EVENT DETAIL ============

async function loadEventDetail(eventId) {
  try {
    setLoading('event-results-container', true);

    // Find event in calendar data or fetch
    let event = calendarEvents.find(e => e.id == eventId);

    if (!event) {
      try {
        event = await apiGet(`event.php?id=${eventId}`);
      } catch {
        // Try to find in events list
        const events = await apiGet('events.php');
        event = (events || []).find(e => e.id == eventId);
      }
    }

    if (!event) {
      showToast('Event hittades inte', 'error');
      document.getElementById('event-name').textContent = 'Event hittades inte';
      return;
    }

    // Populate header
    document.getElementById('event-name').textContent = event.name;

    // Date badge
    const date = new Date(event.date);
    const dateBadge = document.getElementById('event-date-badge');
    dateBadge.querySelector('div:first-child').textContent = date.toLocaleDateString('sv-SE', {month: 'short'}).toUpperCase();
    dateBadge.querySelector('div:last-child').textContent = date.getDate();

    // Meta info
    const metaEl = document.getElementById('event-meta');
    const metaItems = [];
    if (event.series) metaItems.push(`<span class="${getSeriesClass(event.series)}">${event.series}</span>`);
    if (event.discipline) metaItems.push(`<span class="chip">${event.discipline}</span>`);
    if (event.location) metaItems.push(`<span style="color: var(--color-text-secondary);"><i data-lucide="map-pin" style="width: 14px; height: 14px;"></i> ${event.location}</span>`);
    metaEl.innerHTML = metaItems.join('');

    // Try to load results
    let results = [];
    try {
      results = await apiGet(`results.php?event_id=${eventId}`);
    } catch {
      // Results API might not exist
    }

    // Stats
    const stats = calculateEventStats(results, event);
    document.getElementById('event-stat-participants').textContent = stats.participants;
    document.getElementById('event-stat-categories').textContent = stats.categories;
    document.getElementById('event-stat-clubs').textContent = stats.clubs;

    // Render results by category
    renderEventResults(results);

    initLucideIcons();

  } catch (error) {
    console.error('Event detail error:', error);
    showToast('Kunde inte ladda event', 'error');
  }
}

function calculateEventStats(results, event) {
  if (results && results.length > 0) {
    const uniqueClubs = new Set(results.map(r => r.club_id || r.club_name).filter(Boolean));
    const uniqueCategories = new Set(results.map(r => r.category).filter(Boolean));

    return {
      participants: results.length,
      categories: uniqueCategories.size || '–',
      clubs: uniqueClubs.size || '–'
    };
  }

  // Fallback to event data
  return {
    participants: event.participants || event.participants_count || '–',
    categories: event.categories_count || '–',
    clubs: event.clubs_count || '–'
  };
}

function renderEventResults(results) {
  const containerEl = document.getElementById('event-results-container');

  if (!results || results.length === 0) {
    containerEl.innerHTML = '<div class="card"><div class="placeholder">Resultat publiceras efter tävling</div></div>';
    return;
  }

  // Group by category
  const byCategory = {};
  results.forEach(r => {
    const cat = r.category || 'Alla';
    if (!byCategory[cat]) byCategory[cat] = [];
    byCategory[cat].push(r);
  });

  // Sort each category by position
  Object.values(byCategory).forEach(cat => {
    cat.sort((a, b) => (a.position || 999) - (b.position || 999));
  });

  // Render each category
  containerEl.innerHTML = Object.entries(byCategory).map(([category, categoryResults]) => `
    <div class="card" style="margin-bottom: var(--space-md);">
      <div class="card-header">
        <h3 class="card-title">${category}</h3>
        <span class="chip">${categoryResults.length} deltagare</span>
      </div>
      <div style="overflow-x: auto;">
        <table style="width: 100%; border-collapse: collapse;">
          <thead>
            <tr style="border-bottom: 2px solid var(--color-border);">
              <th style="padding: var(--space-sm); text-align: left; font-size: var(--text-xs); color: var(--color-text-tertiary); text-transform: uppercase; width: 60px;">#</th>
              <th style="padding: var(--space-sm); text-align: left; font-size: var(--text-xs); color: var(--color-text-tertiary); text-transform: uppercase;">Åkare</th>
              <th style="padding: var(--space-sm); text-align: left; font-size: var(--text-xs); color: var(--color-text-tertiary); text-transform: uppercase;">Klubb</th>
              <th style="padding: var(--space-sm); text-align: right; font-size: var(--text-xs); color: var(--color-text-tertiary); text-transform: uppercase;">Tid</th>
              <th style="padding: var(--space-sm); text-align: right; font-size: var(--text-xs); color: var(--color-text-tertiary); text-transform: uppercase;">Poäng</th>
            </tr>
          </thead>
          <tbody>
            ${categoryResults.map(result => {
              const position = result.position || '–';
              const positionDisplay = position === 1 ? '🥇' : position === 2 ? '🥈' : position === 3 ? '🥉' : position;
              const riderName = result.rider_name || `${result.firstname || ''} ${result.lastname || ''}`.trim() || 'Okänd';
              const riderId = result.rider_id || result.id;

              return `
                <tr style="border-bottom: 1px solid var(--color-divider); cursor: pointer;"
                    onclick="navigateTo('rider', '${riderId}')"
                    onmouseover="this.style.background='var(--color-bg-hover)'"
                    onmouseout="this.style.background='transparent'">
                  <td style="padding: var(--space-md) var(--space-sm); font-weight: var(--weight-bold);">
                    ${positionDisplay}
                  </td>
                  <td style="padding: var(--space-md) var(--space-sm);">
                    <div style="font-weight: var(--weight-semibold);">${riderName}</div>
                  </td>
                  <td style="padding: var(--space-md) var(--space-sm); color: var(--color-text-secondary);">
                    ${result.club_name || '–'}
                  </td>
                  <td style="padding: var(--space-md) var(--space-sm); text-align: right; font-family: var(--font-mono);">
                    ${result.time || '–'}
                  </td>
                  <td style="padding: var(--space-md) var(--space-sm); text-align: right; font-weight: var(--weight-bold); color: var(--color-accent-text);">
                    ${result.points || '–'}
                  </td>
                </tr>
              `;
            }).join('')}
          </tbody>
        </table>
      </div>
    </div>
  `).join('');
}
