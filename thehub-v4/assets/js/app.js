// TheHUB V4 – frontend app (API-baserad)

// API endpoints (relativt från /thehub-v4/)
const API_RIDERS = "backend/public/api/riders.php";
const API_EVENTS = "backend/public/api/events.php";

let ridersCache = null;
let eventsCache = null;

function selectView(name) {
  const views = document.querySelectorAll(".view");
  views.forEach(v => v.classList.remove("view-active"));

  const target = document.getElementById("view-" + name);
  if (target) target.classList.add("view-active");

  const tabs = document.querySelectorAll(".tab-btn");
  tabs.forEach(t => t.classList.remove("tab-active"));
  const active = document.querySelector('.tab-btn[data-target="' + name + '"]');
  if (active) active.classList.add("tab-active");
}

async function loadRiders() {
  const statusEl = document.getElementById("riders-status");
  const listEl = document.getElementById("riders-list");
  const countEl = document.getElementById("riders-count");

  if (!statusEl || !listEl || !countEl) return;

  if (ridersCache) {
    renderRiders(ridersCache);
    return;
  }

  statusEl.textContent = "Laddar riders…";

  try {
    const res = await fetch(API_RIDERS);
    const json = await res.json();

    if (!json.ok) {
      statusEl.textContent = "Fel: " + (json.error || "Okänt fel");
      return;
    }

    ridersCache = json.data || [];
    countEl.textContent = ridersCache.length + " st";
    renderRiders(ridersCache);
    statusEl.textContent = "Visar " + ridersCache.length + " riders.";
  } catch (err) {
    statusEl.textContent = "Tekniskt fel vid hämtning av riders.";
    console.error(err);
  }
}

function renderRiders(data) {
  const listEl = document.getElementById("riders-list");
  const searchEl = document.getElementById("riders-search");
  if (!listEl) return;

  const query = (searchEl?.value || "").toLowerCase().trim();

  const filtered = data.filter(r => {
    if (!query) return true;
    const fields = [
      r.firstname || "",
      r.lastname || "",
      r.gravity_id || "",
      r.license_number || ""
    ].join(" ").toLowerCase();
    return fields.includes(query);
  });

  listEl.innerHTML = "";

  filtered.forEach(r => {
    const item = document.createElement("div");
    item.className = "list-item";

    const main = document.createElement("div");
    main.className = "list-item-main";

    const name = document.createElement("div");
    name.className = "list-item-name";
    name.textContent = `${r.firstname ?? ""} ${r.lastname ?? ""}`.trim();

    const sub = document.createElement("div");
    sub.className = "list-item-sub";
    const club = r.club_id ? `Klubb: ${r.club_id}` : "Ingen klubb";
    const gid = r.gravity_id ? `· GID: ${r.gravity_id}` : "";
    sub.textContent = `${club} ${gid}`.trim();

    main.appendChild(name);
    main.appendChild(sub);

    const pill = document.createElement("div");
    pill.className = "list-item-pill";
    pill.textContent = r.active ? "Aktiv" : "Inaktiv";

    item.appendChild(main);
    item.appendChild(pill);
    listEl.appendChild(item);
  });
}

async function loadEvents() {
  const statusEl = document.getElementById("events-status");
  const listEl = document.getElementById("events-list");
  const countEl = document.getElementById("events-count");

  if (!statusEl || !listEl || !countEl) return;

  if (eventsCache) {
    renderEvents(eventsCache);
    return;
  }

  statusEl.textContent = "Laddar events…";

  try {
    const res = await fetch(API_EVENTS);
    const json = await res.json();

    if (!json.ok) {
      statusEl.textContent = "Fel: " + (json.error || "Okänt fel");
      return;
    }

    eventsCache = json.data || [];
    countEl.textContent = eventsCache.length + " st";
    renderEvents(eventsCache);
    statusEl.textContent = "Visar " + eventsCache.length + " events.";
  } catch (err) {
    statusEl.textContent = "Tekniskt fel vid hämtning av events.";
    console.error(err);
  }
}

function renderEvents(data) {
  const listEl = document.getElementById("events-list");
  if (!listEl) return;

  listEl.innerHTML = "";

  data.forEach(ev => {
    const item = document.createElement("div");
    item.className = "list-item";

    const main = document.createElement("div");
    main.className = "list-item-main";

    const name = document.createElement("div");
    name.className = "list-item-name";
    name.textContent = ev.name || `Event #${ev.id}`;

    const sub = document.createElement("div");
    sub.className = "list-item-sub";
    const date = ev.date || ev.start_date || "";
    const loc = ev.location || ev.venue || "";
    sub.textContent = [date, loc].filter(Boolean).join(" · ");

    main.appendChild(name);
    main.appendChild(sub);

    const pill = document.createElement("div");
    pill.className = "list-item-pill";
    pill.textContent = ev.series || "Event";

    item.appendChild(main);
    item.appendChild(pill);
    listEl.appendChild(item);
  });
}

/* INIT */

document.addEventListener("DOMContentLoaded", () => {
  // Tab navigation
  document.querySelectorAll(".tab-btn").forEach(btn => {
    btn.addEventListener("click", () => {
      const target = btn.getAttribute("data-target");
      if (!target) return;

      if (target === "backend") {
        window.location.href = "backend/";
        return;
      }

      selectView(target);

      if (target === "riders") loadRiders();
      if (target === "events") loadEvents();
    });
  });

  // Live filter for riders
  const searchEl = document.getElementById("riders-search");
  if (searchEl) {
    searchEl.addEventListener("input", () => {
      if (ridersCache) renderRiders(ridersCache);
    });
  }
});
