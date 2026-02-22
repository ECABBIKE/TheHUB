/**
 * speaker.js — Speaker dashboard logic.
 *
 * Multi-stage overview with highlights, recent finishes, and overall standings.
 */

let eventId = null;
let precision = 'seconds';

const ws = new GravityWS(['speaker']);
ws.bindStatus(
    document.getElementById('ws-dot'),
    document.getElementById('ws-status')
);

ws.on('punch', (msg) => {
    if (msg.stage_result) {
        addRecentFinish(msg);
    }
});

ws.on('highlight', (msg) => {
    addHighlight(msg);
});

ws.on('standings', (msg) => {
    // Could auto-update standings display
});

ws.on('stage_status', (msg) => {
    updateStageStatus(msg);
});

ws.on('_connected', async () => {
    await initSpeaker();
});

async function initSpeaker() {
    try {
        const status = await API.get('/status');
        if (!status.active_event) return;
        eventId = status.active_event.id;
        precision = status.active_event.time_precision || 'seconds';
        document.getElementById('event-info').textContent = status.active_event.name;

        // Load stages for status bar
        const stages = await API.get(`/events/${eventId}/stages`);
        const bar = document.getElementById('stage-status-bar');
        bar.innerHTML = stages.map(s =>
            `<div class="btn btn-secondary btn-sm" id="stage-btn-${s.id}">
                Stage ${s.stage_number}
            </div>`
        ).join('');

        // Load classes
        const classes = await API.get(`/events/${eventId}/classes`);
        const sel = document.getElementById('speaker-class');
        sel.innerHTML = '<option value="">Alla</option>' +
            classes.map(c => `<option value="${c.name}">${c.name}</option>`).join('');

        loadSpeakerOverall();
    } catch (e) {
        console.error(e);
    }
}

function addRecentFinish(msg) {
    const tbody = document.getElementById('recent-finishes');
    const sr = msg.stage_result;
    const tr = document.createElement('tr');
    tr.innerHTML = `
        <td><strong>${msg.bib}</strong></td>
        <td>${msg.name}</td>
        <td>S${sr.stage_number}</td>
        <td class="time">${sr.elapsed}</td>
        <td class="pos">${sr.position || ''}</td>
        <td class="time text-muted">${sr.behind || ''}</td>
    `;
    tbody.insertBefore(tr, tbody.firstChild);
    while (tbody.children.length > 20) tbody.removeChild(tbody.lastChild);
}

function addHighlight(msg) {
    const feed = document.getElementById('highlights-feed');
    const div = document.createElement('div');
    div.className = `highlight ${msg.priority === 'high' ? 'high' : ''}`;
    div.textContent = msg.text;
    feed.insertBefore(div, feed.firstChild);
    while (feed.children.length > 15) feed.removeChild(feed.lastChild);
}

function updateStageStatus(msg) {
    const btn = document.getElementById(`stage-btn-${msg.stage_id}`);
    if (btn) {
        const icon = msg.status === 'live' ? '🔴' : msg.status === 'done' ? '✅' : '⏳';
        btn.innerHTML = `${icon} Stage ${msg.stage_name}`;
    }
}

async function loadSpeakerOverall() {
    if (!eventId) return;
    const className = document.getElementById('speaker-class').value;
    try {
        let path = `/events/${eventId}/overall`;
        if (className) path += `?class=${encodeURIComponent(className)}`;
        const results = await API.get(path);
        const tbody = document.getElementById('speaker-overall');
        tbody.innerHTML = results.filter(r => r.status === 'ok').slice(0, 20).map(r => `
            <tr data-pos="${r.position || ''}">
                <td class="pos">${r.position || ''}</td>
                <td><strong>${r.bib}</strong></td>
                <td>${r.first_name} ${r.last_name}</td>
                <td class="time">${formatElapsed(r.total_seconds, precision)}</td>
                <td class="time text-muted">${formatBehind(r.time_behind, precision)}</td>
            </tr>
        `).join('');
    } catch (e) {
        console.error(e);
    }
}

// Auto-refresh overall every 10s
setInterval(() => { if (eventId) loadSpeakerOverall(); }, 10000);

initSpeaker();
