const API_RIDERS = "/thehub-v4/backend/public/api/riders.php";
const API_EVENTS = "/thehub-v4/backend/public/api/events.php";

let ridersCache = null;
let eventsCache = null;

function setActiveView(target) {
  const views = document.querySelectorAll(".view");
  views.forEach(v => v.classList.remove("view-active"));
  const active = document.getElementById("view-" + target);
  if (active) active.classList.add("view-active");

  document.querySelectorAll(".sidebar-link[data-target]").forEach(btn => {
    btn.classList.toggle("is-active", btn.getAttribute("data-target") === target);
  });

  document.querySelectorAll(".tab-btn[data-target]").forEach(btn => {
    btn.classList.toggle("tab-active", btn.getAttribute("data-target") === target);
  });

  const titles = {
    home: "Dashboard",
    results: "Resultat",
    riders: "Riders",
    events: "Events",
    ranking: "Ranking"
  };
  const topTitle = document.getElementById("topbar-title");
  if (topTitle && titles[target]) topTitle.textContent = titles[target];

  if (target === "riders") loadRiders();
  if (target === "results") loadEvents();
  if (target === "home") updateDashboardStats();
}

async function ensureEventsLoaded() {
  if (eventsCache) return;
  try {
    const res = await fetch(API_EVENTS);
    const json = await res.json();
    if (json.ok) {
      eventsCache = json.data || [];
    } else {
      console.error("Event API error", json);
    }
  } catch (err) {
    console.error("Event API fetch fail", err);
  }
}

async function ensureRidersLoaded() {
  if (ridersCache) return;
  try {
    const res = await fetch(API_RIDERS);
    const json = await res.json();
    if (json.ok) {
      ridersCache = json.data || [];
    } else {
      console.error("Rider API error", json);
    }
  } catch (err) {
    console.error("Rider API fetch fail", err);
  }
}

async function updateDashboardStats() {
  const ridersCountEl = document.getElementById("dash-riders-count");
  const eventsCountEl = document.getElementById("dash-events-count");
  const upcomingEl = document.getElementById("dash-upcoming-events");
  const lastEventEl = document.getElementById("dash-last-event");
  const lastEventMetaEl = document.getElementById("dash-last-event-meta");

  await Promise.all([ensureRidersLoaded(), ensureEventsLoaded()]);

  if (ridersCache && ridersCountEl) {
    ridersCountEl.textContent = ridersCache.length.toString();
  }
  if (eventsCache && eventsCountEl) {
    eventsCountEl.textContent = eventsCache.length.toString();
  }

  if (eventsCache && eventsCache.length > 0) {
    const now = new Date();
    const upcoming = eventsCache.filter(ev => {
      const d = new Date(ev.date || ev.start_date || "");
      return d >= now;
    });
    if (upcomingEl) upcomingEl.textContent = upcoming.length.toString();

    const sorted = [...eventsCache].sort((a, b) => {
      const da = new Date(a.date || a.start_date || "");
      const db = new Date(b.date || b.start_date || "");
      return db - da;
    });
    const last = sorted[0];
    if (last) {
      if (lastEventEl) lastEventEl.textContent = last.name || ("Event #" + last.id);
      if (lastEventMetaEl) {
        const loc = last.location || last.venue || "";
        const ser = last.series || "";
        lastEventMetaEl.textContent = [ser, loc].filter(Boolean).join(" · ");
      }
    }
  }
}

async function loadRiders() {
  const statusEl = document.getElementById("riders-status");
  const listEl = document.getElementById("riders-list");
  const countEl = document.getElementById("riders-count-badge");
  const searchEl = document.getElementById("riders-search");
  if (!statusEl || !listEl || !countEl) return;

  await ensureRidersLoaded();
  if (!ridersCache) {
    statusEl.textContent = "Kunde inte hämta riders.";
    return;
  }

  const query = (searchEl?.value || "").toLowerCase().trim();
  const filtered = ridersCache.filter(r => {
    if (!query) return true;
    const fields = [
      r.firstname || r.first_name || "",
      r.lastname || r.last_name || "",
      r.gravity_id || "",
      r.license_number || ""
    ].join(" ").toLowerCase();
    return fields.includes(query);
  });

  countEl.textContent = filtered.length + " riders";
  statusEl.textContent = "Visar " + filtered.length + " riders.";

  listEl.innerHTML = "";
  filtered.forEach(r => {
    const card = document.createElement("div");
    card.className = "rider-card";

    const main = document.createElement("div");
    main.className = "rider-main";

    const name = document.createElement("div");
    name.className = "rider-name";
    const firstname = r.firstname || r.first_name || "";
    const lastname = r.lastname || r.last_name || "";
    name.textContent = (firstname + " " + lastname).trim();

    const meta = document.createElement("div");
    meta.className = "rider-meta";
    const club = r.club || r.club_id || "";
    const gid = r.gravity_id ? `GID: ${r.gravity_id}` : "";
    meta.textContent = [club, gid].filter(Boolean).join(" · ");

    main.appendChild(name);
    main.appendChild(meta);

    const tag = document.createElement("div");
    tag.className = "rider-tag";
    tag.textContent = r.active ? "Aktiv" : "Inaktiv";

    card.appendChild(main);
    card.appendChild(tag);
    listEl.appendChild(card);
  });
}

async function loadEvents() {
  const statusEl = document.getElementById("events-status");
  const listEl = document.getElementById("events-list");
  const countBadge = document.getElementById("events-count-badge");
  const seriesSelect = document.getElementById("results-series");
  const yearSelect = document.getElementById("results-year");
  if (!statusEl || !listEl || !countBadge) return;

  await ensureEventsLoaded();
  if (!eventsCache) {
    statusEl.textContent = "Kunde inte hämta tävlingar.";
    return;
  }

  if (seriesSelect && seriesSelect.options.length === 1) {
    const seriesSet = new Set();
    const yearSet = new Set();
    eventsCache.forEach(ev => {
      if (ev.series) seriesSet.add(ev.series);
      const dateStr = ev.date || ev.start_date || "";
      const y = dateStr.slice(0,4);
      if (y) yearSet.add(y);
    });
    [...seriesSet].sort().forEach(s => {
      const opt = document.createElement("option");
      opt.value = s;
      opt.textContent = s;
      seriesSelect.appendChild(opt);
    });
    [...yearSet].sort().forEach(y => {
      const opt = document.createElement("option");
      opt.value = y;
      opt.textContent = y;
      yearSelect.appendChild(opt);
    });
  }

  const seriesFilter = (seriesSelect?.value || "").trim();
  const yearFilter = (yearSelect?.value || "").trim();

  const filtered = eventsCache.filter(ev => {
    let ok = true;
    if (seriesFilter) ok = ok && ev.series === seriesFilter;
    if (yearFilter) {
      const dateStr = ev.date || ev.start_date || "";
      if (!dateStr.startsWith(yearFilter)) ok = false;
    }
    return ok;
  });

  countBadge.textContent = filtered.length + " tävlingar";
  statusEl.textContent = "Visar " + filtered.length + " tävlingar.";

  listEl.innerHTML = "";
  const months = ["JAN","FEB","MAR","APR","MAJ","JUN","JUL","AUG","SEP","OKT","NOV","DEC"];

  filtered.forEach(ev => {
    const card = document.createElement("div");
    card.className = "result-card";

    const dateEl = document.createElement("div");
    dateEl.className = "result-date";
    const rawDate = ev.date || ev.start_date || "";
    const d = rawDate.slice(8,10) || "--";
    const m = rawDate.slice(5,7) || "--";
    const monthText = months[parseInt(m,10)-1] || "";

    const dayEl = document.createElement("div");
    dayEl.className = "result-date-day";
    dayEl.textContent = d;
    const monthEl = document.createElement("div");
    monthEl.className = "result-date-month";
    monthEl.textContent = monthText;
    dateEl.appendChild(dayEl);
    dateEl.appendChild(monthEl);

    const main = document.createElement("div");
    main.className = "result-main";
    const title = document.createElement("div");
    title.className = "result-title";
    title.textContent = ev.name || ("Event #" + ev.id);
    const meta = document.createElement("div");
    meta.className = "result-meta";
    const series = ev.series ? `<span class="result-series">${ev.series}</span>` : "";
    const venue = ev.location || ev.venue || "";
    meta.innerHTML = [series, venue ? `<span class="result-venue">${venue}</span>` : ""].filter(Boolean).join(" · ");
    main.appendChild(title);
    main.appendChild(meta);

    const aside = document.createElement("div");
    aside.className = "result-aside";
    const strong = document.createElement("strong");
    strong.textContent = ev.participants || "";
    aside.appendChild(strong);
    const small = document.createElement("span");
    small.textContent = ev.participants ? "deltagare" : "";
    aside.appendChild(small);

    card.appendChild(dateEl);
    card.appendChild(main);
    card.appendChild(aside);

    listEl.appendChild(card);
  });
}

document.addEventListener("DOMContentLoaded", () => {
  document.querySelectorAll(".sidebar-link[data-target]").forEach(btn => {
    btn.addEventListener("click", (e) => {
      e.preventDefault();
      const target = btn.getAttribute("data-target");
      if (!target) return;
      setActiveView(target);
    });
  });

  document.querySelectorAll(".tab-btn[data-target]").forEach(btn => {
    btn.addEventListener("click", (e) => {
      e.preventDefault();
      const target = btn.getAttribute("data-target");
      if (!target) return;
      if (target === "menu") {
        setActiveView("home");
        return;
      }
      setActiveView(target);
    });
  });

  const search = document.getElementById("riders-search");
  if (search) {
    search.addEventListener("input", () => {
      if (ridersCache) loadRiders();
    });
  }

  const quickLinks = document.querySelectorAll(".quick-link[data-goto]");
  quickLinks.forEach(btn => {
    btn.addEventListener("click", () => {
      const target = btn.getAttribute("data-goto");
      if (target) setActiveView(target);
    });
  });

  // initial dashboard info
  updateDashboardStats();
});
