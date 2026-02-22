/**
 * finish.js — Finish screen display logic.
 *
 * Shows big result display when riders finish a stage.
 * Stage selected via ?stage=N query parameter.
 */

// Get stage number from URL
const params = new URLSearchParams(location.search);
const stageNumber = parseInt(params.get('stage')) || 1;
let stageId = null;
let precision = 'seconds';
let heroTimeout = null;

// WebSocket
const ws = new GravityWS(['finish']);
ws.bindStatus(
    document.getElementById('ws-dot'),
    document.getElementById('ws-status')
);

ws.on('punch', (msg) => {
    if (!msg.stage_result) return;
    if (stageId && msg.stage_result.stage_id !== stageId) return;

    showFinishHero(msg);
    addToRecent(msg);
});

ws.on('_connected', async () => {
    await loadStageInfo();
});

async function loadStageInfo() {
    try {
        const status = await API.get('/status');
        if (!status.active_event) return;
        const eventId = status.active_event.id;
        precision = status.active_event.time_precision || 'seconds';

        const stages = await API.get(`/events/${eventId}/stages`);
        const stage = stages.find(s => s.stage_number === stageNumber);
        if (stage) {
            stageId = stage.id;
            document.getElementById('stage-title').textContent =
                `STAGE ${stage.stage_number} — ${stage.name}`;
        }
    } catch (e) {
        console.error(e);
    }
}

function showFinishHero(msg) {
    const hero = document.getElementById('finish-hero');
    hero.classList.remove('hidden', 'fading');
    hero.classList.add('finish-popup');

    document.getElementById('f-bib').textContent = `#${msg.bib}`;
    document.getElementById('f-name').textContent = msg.name || '';
    document.getElementById('f-time').textContent = msg.stage_result.elapsed || '';

    const pos = msg.stage_result.position;
    let posText = pos ? `${pos}:a plats` : '';
    if (pos === 1) posText = '🥇 1:a plats';
    else if (pos === 2) posText = '🥈 2:a plats';
    else if (pos === 3) posText = '🥉 3:e plats';
    document.getElementById('f-pos').textContent = posText;
    document.getElementById('f-behind').textContent = msg.stage_result.behind || '';

    if (msg.overall) {
        document.getElementById('f-overall').textContent =
            `Totalt: ${msg.overall.total} — ${msg.overall.position}:a totalt`;
    }

    // Auto-fade after 10s
    clearTimeout(heroTimeout);
    heroTimeout = setTimeout(() => {
        hero.classList.add('fading');
    }, 10000);
}

function addToRecent(msg) {
    const tbody = document.getElementById('recent-results');
    const sr = msg.stage_result;
    const tr = document.createElement('tr');
    tr.setAttribute('data-pos', sr.position || '');
    tr.innerHTML = `
        <td class="pos">${sr.position || ''}</td>
        <td><strong>${msg.bib}</strong></td>
        <td>${msg.name || ''}</td>
        <td class="time">${sr.elapsed || ''}</td>
        <td class="time text-muted">${sr.behind || ''}</td>
    `;
    tbody.insertBefore(tr, tbody.firstChild);
    while (tbody.children.length > 30) {
        tbody.removeChild(tbody.lastChild);
    }
}

loadStageInfo();
