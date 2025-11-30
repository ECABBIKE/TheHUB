// TheHUB V4 frontend shell – SPA + API-integration

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
  if (target === 'ranking') loadRanking();
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

      const now = new Date();
      const parsed = events
        .map(ev => {
          const dateStr = ev.date || ev.event_date || ev.start_date || null;
          const d = dateStr ? new Date(dateStr) : null;
          return { raw: ev, date: d };
        })
        .filter(ev => ev.date)
        .sort((a, b) => a.date - b.date);

      const future = parsed.filter(ev => ev.date >= now);
      const past = parsed.filter(ev => ev.date < now);

      if (future.length && $('#dash-upcoming-events')) {
        const e = future[0];
        $('#dash-upcoming-events').textContent =
          (e.raw.name || e.raw.title || 'Nästa event') +
          ' · ' +
          (e.date.toISOString().slice(0, 10));
      }

      if (past.length && $('#dash-last-event')) {
        const e = past[past.length - 1];
        $('#dash-last-event').textContent = e.raw.name || e.raw.title || 'Senaste event';
        const meta = $('#dash-last-event-meta');
        if (meta) {
          meta.textContent = [
            e.date.toISOString().slice(0, 10),
            e.raw.location || null,
            e.raw.discipline || null
          ].filter(Boolean).join(' · ');
        }
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
    const name = ((r.name || '') + ' ' + (r.firstname || '') + ' ' + (r.lastname || '')).toLowerCase();
    const id = (r.gravity_id || r.id || '').toString().toLowerCase();
    const club = (r.club || r.club_name || r.team || '').toLowerCase();
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
      [r.firstname, r.lastname].filter(Boolean).join(' ') ||
      ('Gravity ID: ' + (r.gravity_id || r.id));
    title.textContent = fullName;

    const sub = document.createElement('div');
    sub.className = 'list-sub';
    const pieces = [];
    if (r.gravity_id) pieces.push('Gravity ID: ' + r.gravity_id);
    if (r.club || r.club_name) pieces.push(r.club || r.club_name);
    if (r.license_number) pieces.push('Licens: ' + r.license_number);
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

/* RESULTS */

async function loadResults() {
  const statusEl = $('#results-status');
  const listEl = $('#results-list');
  const badgeEl = $('#events-count-badge');
  const yearEl = $('#results-year');
  if (!statusEl || !listEl) return;

  statusEl.textContent = 'Laddar tävlingar…';
  listEl.innerHTML = '';

  const year = yearEl?.value || '';

  let url = API_BASE + '/results.php';
  if (year) url += '?year=' + encodeURIComponent(year);

  try {
    const data = await fetchJson(url);
    const rows = normalize(data);

    if (badgeEl) badgeEl.textContent = rows.length + ' tävlingar';

    if (!rows.length) {
      statusEl.textContent = 'Inga resultat hittades för valt filter.';
      return;
    }

    listEl.innerHTML = '';
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
      if (ev.discipline) bits.push(ev.discipline);
      sub.textContent = bits.join(' · ');

      main.appendChild(title);
      main.appendChild(sub);

      const pill = document.createElement('div');
      pill.className = 'list-meta-pill';
      if (ev.participants) {
        pill.textContent = ev.participants + ' deltagare';
      } else {
        pill.textContent = 'Visa';
      }

      li.appendChild(main);
      li.appendChild(pill);
      listEl.appendChild(li);
    });

    statusEl.textContent = '';
  } catch (e) {
    console.error(e);
    statusEl.textContent = 'Kunde inte ladda resultat.';
  }
}

/* EVENTS */

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

    listEl.innerHTML = '';
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
      if (ev.discipline) bits.push(ev.discipline);
      sub.textContent = bits.join(' · ');

      main.appendChild(title);
      main.appendChild(sub);

      const pill = document.createElement('div');
      pill.className = 'list-meta-pill';
      pill.textContent = ev.status || 'Event';

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

/* RANKING */

async function loadRanking() {
  const statusEl = $('#ranking-status');
  const listEl = $('#ranking-list');
  const badgeEl = $('#ranking-badge');
  const disciplineEl = $('#ranking-discipline');
  if (!statusEl || !listEl) return;

  statusEl.textContent = 'Laddar ranking…';
  listEl.innerHTML = '';

  const discipline = disciplineEl?.value || '';
  let url = API_BASE + '/ranking.php';
  if (discipline) url += '?discipline=' + encodeURIComponent(discipline);

  try {
    const data = await fetchJson(url);
    const rows = normalize(data);

    if (badgeEl) badgeEl.textContent = rows.length + ' riders';

    if (!rows.length) {
      statusEl.textContent = 'Ingen rankingdata hittades.';
      return;
    }

    listEl.innerHTML = '';
    rows.forEach((r, index) => {
      const li = document.createElement('div');
      li.className = 'list-item';

      const main = document.createElement('div');
      main.className = 'list-main';

      const title = document.createElement('div');
      title.className = 'list-title';
      const fullName =
        [r.firstname, r.lastname].filter(Boolean).join(' ') ||
        r.name ||
        ('Rider #' + r.rider_id);
      title.textContent = (index + 1) + '. ' + fullName;

      const sub = document.createElement('div');
      sub.className = 'list-sub';
      const bits = [];
      if (r.gravity_id) bits.push('Gravity ID: ' + r.gravity_id);
      if (r.club || r.club_name) bits.push(r.club || r.club_name);
      if (r.events_count) bits.push(r.events_count + ' events');
      sub.textContent = bits.join(' · ');

      main.appendChild(title);
      main.appendChild(sub);

      const pill = document.createElement('div');
      pill.className = 'list-meta-pill';
      pill.textContent = (r.total_points ? r.total_points : 0) + ' p';

      li.appendChild(main);
      li.appendChild(pill);
      listEl.appendChild(li);
    });

    statusEl.textContent = '24-månaders ranking baserad på tabellen ranking_points.';
  } catch (e) {
    console.error(e);
    statusEl.textContent = 'Kunde inte ladda ranking.';
  }
}

/* INIT */

document.addEventListener('DOMContentLoaded', () => {
  setupNav();

  const search = $('#riders-search');
  if (search) {
    search.addEventListener('input', () => renderRiders());
  }

  const resultsYear = $('#results-year');
  if (resultsYear) {
    resultsYear.addEventListener('change', () => loadResults());
  }

  const rankingDiscipline = $('#ranking-discipline');
  if (rankingDiscipline) {
    rankingDiscipline.addEventListener('change', () => loadRanking());
  }

  setActiveView('dashboard');
});
