// TheHUB V4 frontend JS

const API_BASE = "/thehub-v4/backend/public/api";

const endpoints = {
  riders: API_BASE + "/riders.php",
  events: API_BASE + "/events.php",
  ranking: API_BASE + "/ranking.php",
};

function qs(sel) { return document.querySelector(sel); }
function qsa(sel) { return Array.from(document.querySelectorAll(sel)); }

function setActiveView(key) {
  qsa(".view").forEach(v => v.classList.remove("view-active"));
  const view = qs("#view-" + key);
  if (view) view.classList.add("view-active");

  qsa(".sidebar-link").forEach(btn => {
    btn.classList.toggle("is-active", btn.dataset.target === key);
  });
  qsa(".tab-btn").forEach(btn => {
    btn.classList.toggle("tab-active", btn.dataset.target === key);
  });

  const titleMap = {
    dashboard: "Dashboard",
    results: "Resultat",
    riders: "Riders",
    events: "Events",
    ranking: "Ranking"
  };
  const t = qs("#topbar-title");
  if (t && titleMap[key]) t.textContent = titleMap[key];
}

/* Fetch helpers */

async function fetchJSON(url) {
  const res = await fetch(url);
  if (!res.ok) throw new Error("HTTP " + res.status);
  return res.json();
}

/* DASHBOARD */

async function loadDashboard() {
  // För nu: räkna riders/events via API
  try {
    const [riders, events] = await Promise.all([
      fetchJSON(endpoints.riders),
      fetchJSON(endpoints.events),
    ]);

    if (riders.ok) qs("#stat-riders").textContent = riders.data.length;
    if (events.ok) qs("#stat-events").textContent = events.data.length;
  } catch (err) {
    console.error("Dashboard error", err);
  }
}

/* RIDERS */

let cachedRiders = [];

async function loadRiders() {
  const status = qs("#riders-status");
  const list = qs("#riders-list");
  list.innerHTML = "";
  status.textContent = "Laddar riders…";

  try {
    const data = await fetchJSON(endpoints.riders);
    if (!data.ok) throw new Error(data.error || "API error");
    cachedRiders = data.data || [];
    qs("#riders-count-badge").textContent = cachedRiders.length + " st";
    status.textContent = "";
    renderRidersList(cachedRiders);
  } catch (err) {
    console.error(err);
    status.textContent = "Kunde inte ladda riders.";
  }
}

function renderRidersList(items) {
  const list = qs("#riders-list");
  list.innerHTML = "";

  items.forEach(r => {
    const li = document.createElement("div");
    li.className = "list-item";
    const name = (r.first_name || "") + " " + (r.last_name || "");
    const club = r.club || r.club_name || "";
    const gravityId = r.gravity_id || r.gravity_id_inner || r.id;

    li.innerHTML = `
      <div class="list-main">
        <div class="list-title">${name}</div>
        <div class="list-meta">
          ${gravityId ? "Gravity ID: " + gravityId + " · " : ""}${club}
        </div>
      </div>
      <div class="list-pill">Visa</div>
    `;
    list.appendChild(li);
  });
}

function setupRidersSearch() {
  const input = qs("#riders-search");
  if (!input) return;
  input.addEventListener("input", () => {
    const q = input.value.toLowerCase();
    const filtered = cachedRiders.filter(r => {
      const name = ((r.first_name || "") + " " + (r.last_name || "")).toLowerCase();
      const club = (r.club || r.club_name || "").toLowerCase();
      const gid = String(r.gravity_id || r.gravity_id_inner || r.id || "").toLowerCase();
      return name.includes(q) || club.includes(q) || gid.includes(q);
    });
    renderRidersList(filtered);
    qs("#riders-count-badge").textContent = filtered.length + " st";
  });
}

/* EVENTS (used both in Results & Events views) */

let cachedEvents = [];

async function loadEvents(targetView) {
  const statusId = targetView === "results" ? "#results-events-status" : "#events-status";
  const listId = targetView === "results" ? "#results-events-list" : "#events-list";
  const badgeId = targetView === "results" ? "#results-events-count" : "#events-count-badge";

  const status = qs(statusId);
  const list = qs(listId);
  list.innerHTML = "";
  status.textContent = "Laddar events…";

  try {
    const data = await fetchJSON(endpoints.events);
    if (!data.ok) throw new Error(data.error || "API error");
    cachedEvents = data.data || [];
    if (badgeId) qs(badgeId).textContent = cachedEvents.length + " tävlingar";
    status.textContent = "";
    renderEventsList(list, cachedEvents);
  } catch (err) {
    console.error(err);
    status.textContent = "Kunde inte ladda events.";
  }
}

function renderEventsList(container, events) {
  container.innerHTML = "";
  events.forEach(ev => {
    const li = document.createElement("div");
    li.className = "list-item";

    const name = ev.name || ev.event_name || ("Event #" + ev.id);
    const place = ev.location || ev.venue || ev.place || "";
    const date = ev.event_date || ev.date || "";

    li.innerHTML = `
      <div class="list-main">
        <div class="list-title">${name}</div>
        <div class="list-meta">${date} · ${place}</div>
      </div>
      <div class="list-pill">Visa resultat</div>
    `;
    container.appendChild(li);
  });
}

/* RANKING (placeholder, uses generic ranking API) */

async function loadRanking() {
  const series = qs("#ranking-series").value;
  const status = qs("#ranking-status");
  const list = qs("#ranking-list");
  list.innerHTML = "";
  status.textContent = "Laddar ranking…";

  try {
    const data = await fetchJSON(endpoints.ranking + "?series=" + encodeURIComponent(series));
    if (!data.ok) throw new Error(data.error || "API error");
    status.textContent = "";
    const rows = data.data || [];
    renderRanking(list, rows);
  } catch (err) {
    console.error(err);
    status.textContent = "Kunde inte ladda ranking.";
  }
}

function renderRanking(container, rows) {
  container.innerHTML = "";
  rows.forEach((row, idx) => {
    const li = document.createElement("div");
    li.className = "list-item";
    const name = row.name || row.rider_name || ("Rider #" + row.rider_id);
    const pts = row.total_points || row.points || 0;
    li.innerHTML = `
      <div class="list-main">
        <div class="list-title">${idx + 1}. ${name}</div>
        <div class="list-meta">Total: ${pts} p</div>
      </div>
      <div class="list-pill">Detaljer</div>
    `;
    container.appendChild(li);
  });
}

/* Wiring */

function setupNavigation() {
  qsa(".sidebar-link").forEach(btn => {
    btn.addEventListener("click", () => {
      const t = btn.dataset.target;
      setActiveView(t);
      if (t === "riders") loadRiders();
      if (t === "events") loadEvents("events");
      if (t === "results") loadEvents("results");
      if (t === "ranking") loadRanking();
    });
  });

  qsa(".tab-btn").forEach(btn => {
    btn.addEventListener("click", () => {
      const t = btn.dataset.target;
      setActiveView(t);
      if (t === "riders") loadRiders();
      if (t === "events") loadEvents("events");
      if (t === "results") loadEvents("results");
      if (t === "ranking") loadRanking();
    });
  });

  qsa(".quick-link").forEach(btn => {
    btn.addEventListener("click", () => {
      const t = btn.dataset.goto;
      setActiveView(t);
      if (t === "riders") loadRiders();
      if (t === "events") loadEvents("events");
      if (t === "results") loadEvents("results");
    });
  });

  const rankingSeries = qs("#ranking-series");
  if (rankingSeries) {
    rankingSeries.addEventListener("change", () => {
      loadRanking();
    });
  }
}

document.addEventListener("DOMContentLoaded", () => {
  setupNavigation();
  setupRidersSearch();
  loadDashboard();
});
