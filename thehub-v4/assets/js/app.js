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
    home: "Översikt",
    calendar: "Kalender",
    results: "Resultat",
    series: "Serier",
    database: "Rider-databas",
    ranking: "Ranking"
  };
  const topTitle = document.getElementById("topbar-title");
  if (topTitle && titles[target]) topTitle.textContent = titles[target];

  if (target === "database") loadRiders();
  if (target === "results") loadEvents();
}

async function loadRiders() {
  const statusEl = document.getElementById("riders-status");
  const listEl = document.getElementById("riders-list");
  const countEl = document.getElementById("riders-count-badge");
  const searchEl = document.getElementById("riders-search");
  if (!statusEl || !listEl || !countEl) return;

  if (!ridersCache) {
    statusEl.textContent = "Laddar rider-databas…";
    try {
      const res = await fetch(API_RIDERS);
      const json = await res.json();
      if (!json.ok) {
        statusEl.textContent = "Fel: " + (json.error || "Okänt fel");
        return;
      }
      ridersCache = json.data || [];
    } catch (err) {
      console.error(err);
      statusEl.textContent = "Tekniskt fel vid hämtning av riders.";
      return;
    }
  }

  const query = (searchEl?.value || "").toLowerCase().trim();
  const filtered = ridersCache.filter(r => {
    if (!query) return true;
    const fields = [
      r.firstname || "",
      r.lastname || "",
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
    name.textContent = ((r.firstname || "") + " " + (r.lastname || "")).trim();

    const meta = document.createElement("div");
    meta.className = "rider-meta";
    const club = r.club_id ? `Klubb: ${r.club_id}` : "Ingen klubb";
    const gid = r.gravity_id ? ` · GID: ${r.gravity_id}` : "";
    meta.textContent = club + gid;

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

  if (!eventsCache) {
    statusEl.textContent = "Laddar tävlingar…";
    try {
      const res = await fetch(API_EVENTS);
      const json = await res.json();
      if (!json.ok) {
        statusEl.textContent = "Fel: " + (json.error || "Okänt fel");
        return;
      }
      eventsCache = json.data || [];
    } catch (err) {
      console.error(err);
      statusEl.textContent = "Tekniskt fel vid hämtning av events.";
      return;
    }
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
  filtered.forEach(ev => {
    const card = document.createElement("div");
    card.className = "result-card";

    const dateEl = document.createElement("div");
    dateEl.className = "result-date";
    const d = (ev.date || ev.start_date || "").slice(8,10) || "--";
    const m = (ev.date || ev.start_date || "").slice(5,7) || "--";
    const months = ["JAN","FEB","MAR","APR","MAJ","JUN","JUL","AUG","SEP","OKT","NOV","DEC"];
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
    meta.innerHTML = [series, venue ? `<span class="result-venue">${venue}</span>` : ""]
      .filter(Boolean).join(" · ");
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
});
