/**
 * overlay.js — OBS transparent overlay logic.
 *
 * Pop-up on finish (slide in, hold 5s, fade out).
 * Ticker bar at bottom with recent results.
 * Use as Browser Source in OBS/vMix.
 */

let popupTimeout = null;
const recentResults = [];

const ws = new GravityWS(['overlay']);

ws.on('punch', (msg) => {
    if (!msg.stage_result || !msg.bib) return;
    showPopup(msg);
    addToTicker(msg);
});

ws.on('highlight', (msg) => {
    // Flash highlight in ticker
    const ticker = document.getElementById('ticker-text');
    ticker.textContent = `⚡ ${msg.text}`;
});

function showPopup(msg) {
    const popup = document.getElementById('overlay-popup');
    popup.classList.remove('hidden', 'fading');

    document.getElementById('ov-bib').textContent = `#${msg.bib}`;
    document.getElementById('ov-name').textContent = msg.name || '';
    document.getElementById('ov-time').textContent = msg.stage_result.elapsed || '';

    const pos = msg.stage_result.position;
    let posText = pos ? `${pos}:a` : '';
    if (pos === 1) posText = '🥇 1:a';
    else if (pos === 2) posText = '🥈 2:a';
    else if (pos === 3) posText = '🥉 3:e';
    document.getElementById('ov-pos').textContent = posText;
    document.getElementById('ov-behind').textContent = msg.stage_result.behind || '';
    document.getElementById('ov-stage').textContent = msg.stage_result.stage_name || '';

    // Hold for 5s, then fade
    clearTimeout(popupTimeout);
    popupTimeout = setTimeout(() => {
        popup.classList.add('fading');
        setTimeout(() => popup.classList.add('hidden'), 500);
    }, 5000);
}

function addToTicker(msg) {
    const sr = msg.stage_result;
    recentResults.unshift(
        `#${msg.bib} ${msg.name} — ${sr.elapsed} (${sr.position}:a) S${sr.stage_number}`
    );
    if (recentResults.length > 10) recentResults.pop();
    updateTicker();
}

function updateTicker() {
    const ticker = document.getElementById('ticker-text');
    ticker.textContent = '⏱ ' + recentResults.join('  •  ');
}
