// TheHUB V4 frontend shell

const API_BASE = '/thehub-v4/backend/public/api';

const $ = (q) => document.querySelector(q);
const $$ = (q) => Array.from(document.querySelectorAll(q));

async function fetchJson(url) {
  const res = await fetch(url, { headers: { 'Accept': 'application/json' } });
  if (!res.ok) throw new Error('HTTP ' + res.status);
  const txt = await res.text();
  try {
    return JSON.parse(txt);
  } catch (e) {
    console.error('JSON parse error', e, txt.slice(0, 400));
    throw new Error('Kunde inte tolka JSON från API.');
  }
}

function normalize(payload) {
  if (Array.isArray(payload)) return payload;
  if (payload && Array.isArray(payload.data)) return payload.data;
  return [];
}

/* NAVIGATION */

function setActiveView(target) {
  $$('.view').forEach(v => v.classList.remove('view-active'));
  $('#view-' + target)?.classList.add('view-active');

  $$('.sidebar-link').forEach(btn => {
    btn.classList.toggle('is-active', btn.dataset.target === target);
  });
  $$('.tab-btn').forEach(btn => {
    btn.classList.toggle('tab-active', btn.dataset.target === target);
  });

  const titles = {
    dashboard: 'Dashboard',
    results: 'Resultat',
    riders: 'Riders',
    events: 'Events',
    ranking: 'Ranking'
  };
  const title = titles[target] || 'TheHUB V4';
  const h = $('#topbar-title');
  if (h) h.textContent = title;

  if (target === 'dashboard') loadDashboard();
  if (target === 'riders') loadRiders();
  if (target === 'results') loadResults();
  if (target === 'events') loadEvents();
}

function setupNav() {
  $$('.sidebar-link').forEach(btn => {
    btn.addEventListener('click', () => {
      setActiveView(btn.dataset.target);
    });
  });
  $$('.tab-btn').forEach(btn => {
    btn.addEventListener('click', () => {
      setActiveView(btn.dataset.target);
    });
  });
  $$('.quick-link').forEach(btn => {
    btn.addEventListener('click', () => {
      const target = btn.dataset.goto;
      if (target) setActiveView(target);
    });
  });
}

/* DASHBOARD */

async function loadDashboard() {
  try {
    const [ridersPayload, eventsPayload] = await Promise.allSettled([
      fetchJson(API_BASE + '/riders.php'),
      fetchJson(API_BASE + '/events.php')
    ]);

    if (ridersPayload.status === 'fulfilled') {
      const riders = normalize(ridersPayload.value);
      const el = $('#dash-riders-count');
      if (el) el.textContent = riders.length.toString();
    }

    if (eventsPayload.status === 'fulfilled') {
      const events = normalize(eventsPayload.value);
      const elCount = $('#dash-events-count');
      if (elCount) elCount.textContent = events.length.toString();

      // simple "next event" based on date field if it exists
      const now = new Date();
      const future = events
        .map(ev => {
          const d = ev.date || ev.event_date || ev.start_date || null;
          return { raw: ev, date: d ? new Date(d) : null };
        })
        .filter(ev => ev.date && ev.date >= now)
        .sort((a, b) => a.date - b.date);

      if (future.length && $('#dash-upcoming-events')) {
        const e = future[0];
        $('#dash-upcoming-events').textContent = e.raw.name || e.raw.title || 'Nästa event';
      }
    }
  } catch (e) {
    console.warn('Dashboard error', e);
  }
}

/* RIDERS */

let ridersCache = [];

async function loadRiders() {
  const statusEl = $('#riders-status');
  const listEl = $('#riders-list');
  const badgeEl = $('#riders-count-badge');
  if (!statusEl || !listEl) return;

  statusEl.textContent = 'Laddar riders…';
  listEl.innerHTML = '';

  try {
    const data = await fetchJson(API_BASE + '/riders.php');
    ridersCache = normalize(data);
    if (badgeEl) badgeEl.textContent = ridersCache.length + ' riders';

    renderRiders();
    statusEl.textContent = ridersCache.length
      ? 'Visar de ' + ridersCache.length + ' första riders.'
      : 'Inga riders hittades.';
  } catch (e) {
    console.error(e);
    statusEl.textContent = 'Kunde inte ladda riders.';
  }
}

function renderRiders() {
  const listEl = $('#riders-list');
  const searchEl = $('#riders-search');
  if (!listEl) return;

  const q = (searchEl?.value || '').toLowerCase().trim();

  const filtered = ridersCache.filter(r => {
    const name = ((r.name || '') + ' ' + (r.first_name || '') + ' ' + (r.last_name || '')).toLowerCase();
    const id = (r.gravity_id || r.id || '').toString().toLowerCase();
    const club = (r.club || r.team || '').toLowerCase();
    return !q || name.includes(q) || id.includes(q) || club.includes(q);
  });

  listEl.innerHTML = '';

  filtered.slice(0, 500).forEach(r => {
    const li = document.createElement('div');
    li.className = 'list-item';

    const main = document.createElement('div');
    main.className = 'list-main';

    const title = document.createElement('div');
    title.className = 'list-title';
    const fullName =
      r.name ||
      [r.first_name, r.last_name].filter(Boolean).join(' ') ||
      ('Gravity ID: ' + (r.gravity_id || r.id));
    title.textContent = fullName;

    const sub = document.createElement('div');
    sub.className = 'list-sub';
    const pieces = [];
    if (r.gravity_id) pieces.push('Gravity ID: ' + r.gravity_id);
    if (r.club) pieces.push(r.club);
    if (r.uci_id) pieces.push('UCI: ' + r.uci_id);
    sub.textContent = pieces.join(' · ');

    main.appendChild(title);
    main.appendChild(sub);

    const pill = document.createElement('div');
    pill.className = 'list-meta-pill';
    pill.textContent = 'Visa';

    li.appendChild(main);
    li.appendChild(pill);
    listEl.appendChild(li);
  });
}

/* RESULTS / EVENTS (stub-friendly) */

async function loadResults() {
  const statusEl = $('#results-status');
  const listEl = $('#results-list');
  const badgeEl = $('#events-count-badge');
  if (!statusEl || !listEl) return;

  statusEl.textContent = 'Laddar tävlingar…';
  listEl.innerHTML = '';

  try {
    const data = await fetchJson(API_BASE + '/results.php');
    const rows = normalize(data);
    if (badgeEl) badgeEl.textContent = rows.length + ' tävlingar';

    if (!rows.length) {
      statusEl.textContent = 'Inga resultat är kopplade till V4 ännu.';
      return;
    }

    rows.forEach(ev => {
      const li = document.createElement('div');
      li.className = 'list-item';

      const main = document.createElement('div');
      main.className = 'list-main';

      const title = document.createElement('div');
      title.className = 'list-title';
      title.textContent = ev.name || ev.title || 'Event #' + ev.id;

      const sub = document.createElement('div');
      sub.className = 'list-sub';
      const bits = [];
      if (ev.date) bits.push(ev.date);
      if (ev.location) bits.push(ev.location);
      if (ev.series) bits.push(ev.series);
      sub.textContent = bits.join(' · ');

      main.appendChild(title);
      main.appendChild(sub);

      const pill = document.createElement('div');
      pill.className = 'list-meta-pill';
      pill.textContent = ev.participants ? ev.participants + ' deltagare' : 'Visa';

      li.appendChild(main);
      li.appendChild(pill);
      listEl.appendChild(li);
    });

    statusEl.textContent = '';
  } catch (e) {
    console.error(e);
    statusEl.textContent = 'Kunde inte ladda events.';
  }
}

async function loadEvents() {
  const statusEl = $('#events-status');
  const listEl = $('#events-list');
  if (!statusEl || !listEl) return;

  statusEl.textContent = 'Laddar events…';
  listEl.innerHTML = '';

  try {
    const data = await fetchJson(API_BASE + '/events.php');
    const rows = normalize(data);

    if (!rows.length) {
      statusEl.textContent = 'Inga events returnerades från API:t.';
      return;
    }

    rows.forEach(ev => {
      const li = document.createElement('div');
      li.className = 'list-item';

      const main = document.createElement('div');
      main.className = 'list-main';

      const title = document.createElement('div');
      title.className = 'list-title';
      title.textContent = ev.name || ev.title || 'Event #' + ev.id;

      const sub = document.createElement('div');
      sub.className = 'list-sub';
      const bits = [];
      if (ev.date) bits.push(ev.date);
      if (ev.location) bits.push(ev.location);
      if (ev.series) bits.push(ev.series);
      sub.textContent = bits.join(' · ');

      main.appendChild(title);
      main.appendChild(sub);

      const pill = document.createElement('div');
      pill.className = 'list-meta-pill';
      pill.textContent = 'Visa';

      li.appendChild(main);
      li.appendChild(pill);
      listEl.appendChild(li);
    });

    statusEl.textContent = '';
  } catch (e) {
    console.error(e);
    statusEl.textContent = 'Kunde inte ladda events.';
  }
}

/* INIT */

document.addEventListener('DOMContentLoaded', () => {
  setupNav();
  const search = $('#riders-search');
  if (search) {
    search.addEventListener('input', () => renderRiders());
  }
  setActiveView('dashboard');
});
