/**
 * admin.js — GravityTiming Admin UI logic.
 *
 * Welcome screen → Create/select event → Tabbed workspace.
 */

// ─── State ──────────────────────────────────────────────────────────

let currentEventId = null;
let currentEvent = null;
let precision = 'seconds';
let setupDetailsVisible = false;

// ─── DOM refs ───────────────────────────────────────────────────────

const welcomeScreen  = document.getElementById('welcome-screen');
const mainTabs       = document.getElementById('main-tabs');
const mainContent    = document.getElementById('main-content');

// ─── WebSocket (safe init — never crash the whole page) ─────────────

let ws = null;
try {
    ws = new GravityWS(['all']);
    ws.bindStatus(
        document.getElementById('ws-dot'),
        document.getElementById('ws-status')
    );

    ws.on('punch', (msg) => {
        addToLiveFeed(msg);
        updateLiveHero(msg);
        updatePunchCount();
    });

    ws.on('standings', (msg) => {
        if (document.getElementById('tab-overall').classList.contains('active')) {
            loadOverallResults();
        }
    });

    ws.on('highlight', (msg) => {
        console.log('[Highlight]', msg.text);
    });
} catch (e) {
    console.warn('[admin] WebSocket init failed (non-fatal):', e);
}

// ─── Tab navigation ─────────────────────────────────────────────────

document.querySelectorAll('.tab').forEach(tab => {
    tab.addEventListener('click', () => {
        document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
        document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
        tab.classList.add('active');
        document.getElementById(`tab-${tab.dataset.tab}`).classList.add('active');
        // Load tab-specific data
        if (tab.dataset.tab === 'connections') {
            loadConnectionsTab();
        } else {
            stopRocStatusPolling();
        }
    });
});

function switchToTab(tabName) {
    document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
    document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
    const tab = document.querySelector(`.tab[data-tab="${tabName}"]`);
    const content = document.getElementById(`tab-${tabName}`);
    if (tab) tab.classList.add('active');
    if (content) content.classList.add('active');
}

// ─── Init ───────────────────────────────────────────────────────────

async function init() {
    // Always start at welcome screen so user can pick or create events
    showWelcome();
}

function showWelcome() {
    welcomeScreen.classList.remove('hidden');
    mainTabs.classList.add('hidden');
    mainContent.classList.add('hidden');
    loadEventListWelcome();
    loadNewEventTemplates();
}

async function loadNewEventTemplates() {
    try {
        const data = await API.get('/templates');
        const sel = document.getElementById('new-event-template');
        sel.innerHTML = data.builtin.map(t =>
            `<option value="${t.name}" data-format="${t.format}">${t.name}</option>`
        ).join('');
    } catch (e) {
        console.error('Could not load templates', e);
    }
}

function showWorkspace() {
    welcomeScreen.classList.add('hidden');
    mainTabs.classList.remove('hidden');
    mainContent.classList.remove('hidden');
}

// ─── Event management ───────────────────────────────────────────────

async function loadEventListWelcome() {
    try {
        const events = await API.get('/events');
        const container = document.getElementById('existing-events');
        const list = document.getElementById('event-list-welcome');

        if (events.length === 0) {
            container.classList.add('hidden');
            return;
        }

        container.classList.remove('hidden');
        list.innerHTML = events.map(e => {
            const badgeClass = e.status === 'active' ? 'badge-active' :
                               e.status === 'finished' ? 'badge-finished' : 'badge-setup';
            const statusText = e.status === 'active' ? 'Aktivt' :
                               e.status === 'finished' ? 'Avslutat' : 'Setup';
            return `
            <div class="event-card" style="display:flex; align-items:center; gap:0.5rem;">
                <div style="flex:1; cursor:pointer;" onclick="selectEvent(${e.id})">
                    <div class="event-info">
                        <span class="event-title">${e.name}</span>
                        <span class="event-meta">${e.date}${e.location ? ' — ' + e.location : ''}</span>
                    </div>
                </div>
                <span class="badge ${badgeClass}">${statusText}</span>
                <button class="btn btn-danger btn-sm" onclick="event.stopPropagation(); deleteEvent(${e.id}, '${e.name.replace(/'/g, "\\'")}')" title="Radera event">×</button>
            </div>`;
        }).join('');
    } catch (e) {
        console.error('Failed to load events:', e);
    }
}

async function createEvent() {
    const nameEl = document.getElementById('new-event-name');
    const dateEl = document.getElementById('new-event-date');
    const name = nameEl.value.trim();
    const date = dateEl.value;
    const location = document.getElementById('new-event-location').value.trim();
    const templateSel = document.getElementById('new-event-template');
    const templateName = templateSel.value;
    const format = templateSel.selectedOptions[0]?.dataset.format || 'enduro';
    const roc = document.getElementById('new-event-roc').value.trim();

    if (!name) {
        nameEl.focus();
        nameEl.style.borderColor = 'var(--danger-red)';
        return;
    }
    nameEl.style.borderColor = '';

    // Auto-fill today if no date set
    const useDate = date || new Date().toISOString().split('T')[0];
    if (!date) dateEl.value = useDate;

    // Disable button while creating
    const btn = document.querySelector('.welcome-create .btn-lg');
    if (btn) { btn.disabled = true; btn.textContent = 'Skapar...'; }

    try {
        console.log('[createEvent] Creating:', { name, date: useDate, location, format, roc });
        const result = await API.post('/events', {
            name, date: useDate, location, format,
            roc_competition_id: roc,
        });
        console.log('[createEvent] Created:', result);

        // Apply selected template automatically
        if (templateName) {
            console.log('[createEvent] Applying template:', templateName);
            await API.post(`/events/${result.id}/apply-template?name=${encodeURIComponent(templateName)}`);
        }

        selectEvent(result.id);
    } catch (e) {
        console.error('[createEvent] Error:', e);
        alert('Kunde inte skapa event: ' + e.message);
    } finally {
        if (btn) { btn.disabled = false; btn.textContent = 'Skapa event'; }
    }
}

async function selectEvent(eventId) {
    currentEventId = eventId;
    try {
        currentEvent = await API.get(`/events/${eventId}`);
        precision = currentEvent.time_precision || 'seconds';

        // Show workspace
        showWorkspace();

        document.getElementById('event-name').textContent = currentEvent.name;
        document.getElementById('status-event').textContent = `${currentEvent.name} [${currentEvent.status}]`;

        // If event is in setup mode, start on Setup tab
        if (currentEvent.status === 'setup') {
            switchToTab('setup');
        }

        // Load all data
        loadControls();
        loadStages();
        loadCourses();
        loadClasses();
        loadTemplateList();
        loadEntries();
        loadChips();
        loadPunches();
        loadStageSelectors();
        loadClassSelectors();
        updatePunchCount();
        updateActionButtons();
        updateSetupStats();
        loadRaceState();
        loadBackups();
        loadAuditLog();

        // Pre-fill ROC competition ID and load ROC status
        if (currentEvent.roc_competition_id) {
            document.getElementById('roc-competition-id').value = currentEvent.roc_competition_id;
        }
        loadRocStatus();
    } catch (e) {
        alert('Kunde inte ladda event: ' + e.message);
    }
}

async function deleteEvent(eventId, eventName) {
    const typed = prompt(`Radera eventet permanent?\n\nAlla stämplingar, resultat och data försvinner.\nSkriv eventets namn för att bekräfta:\n\n"${eventName}"`);
    if (typed === null) return; // cancelled
    if (typed.trim() !== eventName.trim()) {
        alert('Namnet stämmer inte — radering avbruten.');
        return;
    }
    try {
        await API.del(`/events/${eventId}`);
        // If we just deleted the active event, clear state
        if (currentEventId === eventId) {
            currentEventId = null;
            currentEvent = null;
        }
        showWelcome();
    } catch (e) {
        alert('Kunde inte radera: ' + e.message);
    }
}

function switchEvent() {
    currentEventId = null;
    currentEvent = null;
    document.getElementById('event-name').textContent = 'Inget event';
    showWelcome();
}

async function activateEvent() {
    if (!currentEventId) return;
    try {
        await API.post(`/events/${currentEventId}/activate`);
        currentEvent.status = 'active';
        updateActionButtons();
        document.getElementById('status-event').textContent = `${currentEvent.name} [active]`;
        switchToTab('live');
    } catch (e) {
        alert('Fel: ' + e.message);
    }
}

async function finishEvent() {
    if (!currentEventId) return;
    if (!confirm('Avsluta eventet? Resultat blir read-only.')) return;
    try {
        await API.post(`/events/${currentEventId}/finish`);
        currentEvent.status = 'finished';
        updateActionButtons();
    } catch (e) {
        alert('Fel: ' + e.message);
    }
}

function updateActionButtons() {
    const activateBtn = document.getElementById('btn-activate');
    const finishBtn = document.getElementById('btn-finish');
    if (currentEvent) {
        activateBtn.disabled = currentEvent.status !== 'setup';
        finishBtn.disabled = currentEvent.status !== 'active';
    }
}

// ─── Setup Stats ────────────────────────────────────────────────────

async function updateSetupStats() {
    if (!currentEventId) return;
    try {
        const [controls, stages, courses, classes, entries, chips] = await Promise.all([
            API.get(`/events/${currentEventId}/controls`),
            API.get(`/events/${currentEventId}/stages`),
            API.get(`/events/${currentEventId}/courses`),
            API.get(`/events/${currentEventId}/classes`),
            API.get(`/events/${currentEventId}/entries`),
            API.get(`/events/${currentEventId}/chips`),
        ]);
        document.getElementById('stat-controls').textContent = controls.length;
        document.getElementById('stat-stages').textContent = stages.length;
        document.getElementById('stat-courses').textContent = courses.length;
        document.getElementById('stat-classes').textContent = classes.length;
        document.getElementById('stat-entries').textContent = entries.length;
        document.getElementById('stat-chips').textContent = chips.length;
    } catch (e) {}
}

function toggleSetupDetails() {
    const el = document.getElementById('setup-details');
    setupDetailsVisible = !setupDetailsVisible;
    el.classList.toggle('hidden', !setupDetailsVisible);
}

// ─── Controls ───────────────────────────────────────────────────────

async function loadControls() {
    if (!currentEventId) return;
    try {
        const controls = await API.get(`/events/${currentEventId}/controls`);
        const tbody = document.getElementById('controls-list');
        tbody.innerHTML = controls.map(c => `
            <tr>
                <td class="editable" onclick="editControlField(this, ${c.id}, 'code', ${c.code})">${c.code}</td>
                <td class="editable" onclick="editControlField(this, ${c.id}, 'name', '${c.name.replace(/'/g, "\\'")}')">${c.name}</td>
                <td class="editable" onclick="editControlType(this, ${c.id}, '${c.type}')">${c.type}</td>
                <td><button class="btn btn-danger btn-sm" onclick="deleteControl(${c.id})">×</button></td>
            </tr>
        `).join('');

        // Update stage control dropdowns
        const opts = controls.map(c => `<option value="${c.id}">${c.code} — ${c.name}</option>`).join('');
        document.getElementById('stage-start-ctrl').innerHTML = opts;
        document.getElementById('stage-finish-ctrl').innerHTML = opts;
    } catch (e) {
        console.error(e);
    }
}

function editControlField(td, controlId, field, currentValue) {
    // Already editing? Skip
    if (td.querySelector('input')) return;

    const inputType = field === 'code' ? 'number' : 'text';
    const input = document.createElement('input');
    input.type = inputType;
    input.value = currentValue;
    input.className = 'inline-edit';
    input.style.width = '100%';

    const original = td.textContent;
    td.textContent = '';
    td.appendChild(input);
    input.focus();
    input.select();

    async function save() {
        const newValue = field === 'code' ? parseInt(input.value) : input.value.trim();
        if (!newValue || newValue === currentValue) {
            td.textContent = original;
            return;
        }
        try {
            await API.put(`/events/${currentEventId}/controls/${controlId}`, { [field]: newValue });
            loadControls();
        } catch (e) {
            alert('Fel: ' + e.message);
            td.textContent = original;
        }
    }

    input.addEventListener('blur', save);
    input.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') { e.preventDefault(); input.blur(); }
        if (e.key === 'Escape') { td.textContent = original; }
    });
}

function editControlType(td, controlId, currentType) {
    // Already editing? Skip
    if (td.querySelector('select')) return;

    const select = document.createElement('select');
    select.className = 'inline-edit';
    ['start', 'finish', 'split'].forEach(t => {
        const opt = document.createElement('option');
        opt.value = t;
        opt.textContent = t;
        if (t === currentType) opt.selected = true;
        select.appendChild(opt);
    });

    const original = td.textContent;
    td.textContent = '';
    td.appendChild(select);
    select.focus();

    async function save() {
        const newType = select.value;
        if (newType === currentType) {
            td.textContent = original;
            return;
        }
        try {
            await API.put(`/events/${currentEventId}/controls/${controlId}`, { type: newType });
            loadControls();
        } catch (e) {
            alert('Fel: ' + e.message);
            td.textContent = original;
        }
    }

    select.addEventListener('blur', save);
    select.addEventListener('change', () => select.blur());
}

async function addControl() {
    if (!currentEventId) return;
    const code = parseInt(document.getElementById('ctrl-code').value);
    const name = document.getElementById('ctrl-name').value.trim();
    const type = document.getElementById('ctrl-type').value;
    if (!code || !name) return;
    try {
        await API.post(`/events/${currentEventId}/controls`, { code, name, type });
        loadControls();
        updateSetupStats();
        document.getElementById('ctrl-code').value = '';
        document.getElementById('ctrl-name').value = '';
    } catch (e) {
        alert('Fel: ' + e.message);
    }
}

async function deleteControl(id) {
    try {
        await API.del(`/events/${currentEventId}/controls/${id}`);
        loadControls();
        updateSetupStats();
    } catch (e) {
        alert(e.message);
    }
}

// ─── Stages ─────────────────────────────────────────────────────────

async function loadStages() {
    if (!currentEventId) return;
    try {
        const stages = await API.get(`/events/${currentEventId}/stages`);
        const controls = await API.get(`/events/${currentEventId}/controls`);
        // Store controls for stage control dropdowns
        window._stageControls = controls;

        const tbody = document.getElementById('stages-list');
        tbody.innerHTML = stages.map(s => {
            const startLabel = s.start_control_code != null ? `${s.start_control_code} ${s.start_control_name}` : `ID:${s.start_control_id}`;
            const finishLabel = s.finish_control_code != null ? `${s.finish_control_code} ${s.finish_control_name}` : `ID:${s.finish_control_id}`;
            return `
            <tr>
                <td class="editable" onclick="editStageField(this, ${s.id}, 'stage_number', ${s.stage_number})">${s.stage_number}</td>
                <td class="editable" onclick="editStageField(this, ${s.id}, 'name', '${s.name.replace(/'/g, "\\'")}')">${s.name}</td>
                <td class="editable" onclick="editStageControl(this, ${s.id}, 'start_control_id', ${s.start_control_id})">${startLabel}</td>
                <td class="editable" onclick="editStageControl(this, ${s.id}, 'finish_control_id', ${s.finish_control_id})">${finishLabel}</td>
                <td>${s.is_timed ? '✓' : '—'}</td>
                <td class="editable" onclick="editStageField(this, ${s.id}, 'runs_to_count', ${s.runs_to_count || 1})" title="Bästa N åk räknas">${s.runs_to_count || 1}</td>
                <td class="editable" onclick="editStageField(this, ${s.id}, 'max_runs', ${s.max_runs || 0})" title="Max antal åk (0=obegränsat)">${s.max_runs || '∞'}</td>
                <td><button class="btn btn-danger btn-sm" onclick="deleteStage(${s.id})">×</button></td>
            </tr>`;
        }).join('');
        loadStageSelectors();
    } catch (e) {
        console.error(e);
    }
}

function editStageControl(td, stageId, field, currentCtrlId) {
    if (td.querySelector('select')) return;
    const controls = window._stageControls || [];
    const select = document.createElement('select');
    select.className = 'inline-edit';
    controls.forEach(c => {
        const opt = document.createElement('option');
        opt.value = c.id;
        opt.textContent = `${c.code} — ${c.name}`;
        if (c.id === currentCtrlId) opt.selected = true;
        select.appendChild(opt);
    });

    const original = td.textContent;
    td.textContent = '';
    td.appendChild(select);
    select.focus();

    async function save() {
        const newId = parseInt(select.value);
        if (newId === currentCtrlId) { td.textContent = original; return; }
        try {
            await API.put(`/events/${currentEventId}/stages/${stageId}`, { [field]: newId });
            loadStages();
        } catch (e) {
            alert('Fel: ' + e.message);
            td.textContent = original;
        }
    }

    select.addEventListener('blur', save);
    select.addEventListener('change', () => select.blur());
}

function editStageField(td, stageId, field, currentValue) {
    if (td.querySelector('input')) return;
    const input = document.createElement('input');
    input.type = 'number';
    input.value = currentValue;
    input.className = 'inline-edit';
    input.style.width = field === 'name' ? '120px' : '60px';
    if (field === 'name') input.type = 'text';

    const original = td.textContent;
    td.textContent = '';
    td.appendChild(input);
    input.focus();
    input.select();

    async function save() {
        let newValue;
        if (field === 'name') {
            newValue = input.value.trim();
            if (!newValue || newValue === String(currentValue)) { td.textContent = original; return; }
        } else {
            newValue = parseInt(input.value);
            if (field === 'max_runs' && (isNaN(newValue) || newValue <= 0)) newValue = null;
            if (newValue === currentValue) { td.textContent = original; return; }
        }
        try {
            const body = {};
            body[field] = newValue;
            await API.put(`/events/${currentEventId}/stages/${stageId}`, body);
            loadStages();
        } catch (e) {
            alert('Fel: ' + e.message);
            td.textContent = original;
        }
    }

    input.addEventListener('blur', save);
    input.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') { e.preventDefault(); input.blur(); }
        if (e.key === 'Escape') { td.textContent = original; }
    });
}

async function addStage() {
    if (!currentEventId) return;
    const num = parseInt(document.getElementById('stage-num').value);
    const name = document.getElementById('stage-name').value.trim() || `Stage ${num}`;
    const startCtrl = parseInt(document.getElementById('stage-start-ctrl').value);
    const finishCtrl = parseInt(document.getElementById('stage-finish-ctrl').value);
    if (!num || !startCtrl || !finishCtrl) return;
    try {
        await API.post(`/events/${currentEventId}/stages`, {
            stage_number: num, name,
            start_control_id: startCtrl, finish_control_id: finishCtrl,
        });
        loadStages();
        updateSetupStats();
    } catch (e) {
        alert('Fel: ' + e.message);
    }
}

async function deleteStage(id) {
    try {
        await API.del(`/events/${currentEventId}/stages/${id}`);
        loadStages();
        updateSetupStats();
    } catch (e) {
        alert(e.message);
    }
}

// ─── Courses ────────────────────────────────────────────────────────

async function loadCourses() {
    if (!currentEventId) return;
    try {
        const [courses, allStages] = await Promise.all([
            API.get(`/events/${currentEventId}/courses`),
            API.get(`/events/${currentEventId}/stages`),
        ]);
        // Store for use in stage-add dropdown
        window._allStages = allStages;

        const el = document.getElementById('courses-list');
        el.innerHTML = courses.map(c => {
            const linkedIds = new Set(c.stages.map(s => s.stage_id));
            const stageChips = c.stages.map(s =>
                `<span class="stage-chip">${s.stage_name || '#' + s.stage_number}` +
                ` <button class="chip-x" onclick="unlinkStageFromCourse(${c.id}, ${s.stage_id})">×</button></span>`
            ).join(' ') || '<span class="text-muted">Inga</span>';

            // Stages not yet linked
            const available = allStages.filter(s => !linkedIds.has(s.id));
            const addSelect = available.length > 0 ? `
                <select id="add-stage-to-${c.id}" class="inline-edit" style="width:auto; display:inline-block; margin-left:0.5rem;">
                    ${available.map(s => `<option value="${s.id}">${s.name}</option>`).join('')}
                </select>
                <button class="btn btn-primary btn-sm" onclick="linkStageToCourse(${c.id})" style="margin-left:0.3rem;">+</button>
            ` : '';

            return `
            <div class="card" style="margin-bottom:0.5rem; padding:0.75rem;">
                <div class="flex items-center justify-between mb-1">
                    <strong class="editable" onclick="editCourseField(this, ${c.id}, 'name', '${c.name.replace(/'/g, "\\'")}')">${c.name}</strong>
                    <button class="btn btn-danger btn-sm" onclick="deleteCourse(${c.id})">×</button>
                </div>
                <div style="font-size:0.85rem; display:flex; gap:1rem; flex-wrap:wrap; align-items:center; margin-bottom:0.4rem;">
                    <span class="text-muted">Varv:</span>
                    <span class="editable" onclick="editCourseField(this, ${c.id}, 'laps', ${c.laps})">${c.laps}</span>
                    <label style="cursor:pointer; color:var(--text-muted);">
                        <input type="checkbox" ${c.stages_any_order ? 'checked' : ''} onchange="updateCourseFlag(${c.id}, 'stages_any_order', this.checked ? 1 : 0)"> Fri ordning
                    </label>
                    <label style="cursor:pointer; color:var(--text-muted);">
                        <input type="checkbox" ${c.allow_repeat ? 'checked' : ''} onchange="updateCourseFlag(${c.id}, 'allow_repeat', this.checked ? 1 : 0)"> Tillåt upprepning
                    </label>
                </div>
                <div style="font-size:0.85rem; display:flex; align-items:center; flex-wrap:wrap; gap:0.3rem;">
                    <span class="text-muted">Stages:</span> ${stageChips} ${addSelect}
                </div>
            </div>`;
        }).join('') || '<p class="text-muted">Inga banor</p>';

        // Update class course dropdown
        const opts = courses.map(c => `<option value="${c.id}">${c.name}</option>`).join('');
        document.getElementById('class-course').innerHTML = opts;
    } catch (e) {
        console.error(e);
    }
}

async function linkStageToCourse(courseId) {
    const sel = document.getElementById(`add-stage-to-${courseId}`);
    if (!sel) return;
    const stageId = parseInt(sel.value);
    // Figure out next stage_order
    const courses = await API.get(`/events/${currentEventId}/courses`);
    const course = courses.find(c => c.id === courseId);
    const nextOrder = course ? course.stages.length + 1 : 1;
    try {
        await API.post(`/events/${currentEventId}/courses/${courseId}/stages`, {
            stage_id: stageId, stage_order: nextOrder
        });
        loadCourses();
    } catch (e) {
        alert('Fel: ' + e.message);
    }
}

async function unlinkStageFromCourse(courseId, stageId) {
    try {
        await API.del(`/events/${currentEventId}/courses/${courseId}/stages/${stageId}`);
        loadCourses();
    } catch (e) {
        alert('Fel: ' + e.message);
    }
}

function editCourseField(el, courseId, field, currentValue) {
    if (el.querySelector('input')) return;
    const inputType = field === 'laps' ? 'number' : 'text';
    const input = document.createElement('input');
    input.type = inputType;
    input.value = currentValue;
    input.className = 'inline-edit';
    if (field === 'laps') input.style.width = '60px';

    const original = el.textContent;
    el.textContent = '';
    el.appendChild(input);
    input.focus();
    input.select();

    async function save() {
        const newValue = field === 'laps' ? parseInt(input.value) : input.value.trim();
        if (!newValue || newValue === currentValue) {
            el.textContent = original;
            return;
        }
        try {
            await API.put(`/events/${currentEventId}/courses/${courseId}`, { [field]: newValue });
            loadCourses();
        } catch (e) {
            alert('Fel: ' + e.message);
            el.textContent = original;
        }
    }

    input.addEventListener('blur', save);
    input.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') { e.preventDefault(); input.blur(); }
        if (e.key === 'Escape') { el.textContent = original; }
    });
}

async function updateCourseFlag(courseId, field, value) {
    try {
        await API.put(`/events/${currentEventId}/courses/${courseId}`, { [field]: value });
        loadCourses();
    } catch (e) {
        alert('Fel: ' + e.message);
        loadCourses();
    }
}

async function addCourse() {
    if (!currentEventId) return;
    const name = document.getElementById('course-name').value.trim();
    if (!name) return;
    try {
        await API.post(`/events/${currentEventId}/courses`, { name });
        loadCourses();
        updateSetupStats();
        document.getElementById('course-name').value = '';
    } catch (e) {
        alert('Fel: ' + e.message);
    }
}

async function deleteCourse(id) {
    try {
        await API.del(`/events/${currentEventId}/courses/${id}`);
        loadCourses();
        updateSetupStats();
    } catch (e) {
        alert(e.message);
    }
}

// ─── Classes ────────────────────────────────────────────────────────

async function loadClasses() {
    if (!currentEventId) return;
    try {
        const classes = await API.get(`/events/${currentEventId}/classes`);
        const courses = await API.get(`/events/${currentEventId}/courses`);
        const courseMap = {};
        courses.forEach(c => courseMap[c.id] = c.name);

        const tbody = document.getElementById('classes-list');
        tbody.innerHTML = classes.map(c => `
            <tr>
                <td class="editable" onclick="editClassField(this, ${c.id}, 'name', '${c.name.replace(/'/g, "\\'")}')">${c.name}</td>
                <td>${courseMap[c.course_id] || c.course_id}</td>
                <td><button class="btn btn-danger btn-sm" onclick="deleteClass(${c.id})">×</button></td>
            </tr>
        `).join('');
        loadClassSelectors();
    } catch (e) {
        console.error(e);
    }
}

function editClassField(td, classId, field, currentValue) {
    if (td.querySelector('input')) return;
    const input = document.createElement('input');
    input.type = 'text';
    input.value = currentValue;
    input.className = 'inline-edit';

    const original = td.textContent;
    td.textContent = '';
    td.appendChild(input);
    input.focus();
    input.select();

    async function save() {
        const newValue = input.value.trim();
        if (!newValue || newValue === currentValue) { td.textContent = original; return; }
        try {
            await API.put(`/events/${currentEventId}/classes/${classId}`, { [field]: newValue });
            loadClasses();
        } catch (e) {
            alert('Fel: ' + e.message);
            td.textContent = original;
        }
    }

    input.addEventListener('blur', save);
    input.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') { e.preventDefault(); input.blur(); }
        if (e.key === 'Escape') { td.textContent = original; }
    });
}

async function addClass() {
    if (!currentEventId) return;
    const name = document.getElementById('class-name').value.trim();
    const courseId = parseInt(document.getElementById('class-course').value);
    if (!name || !courseId) return;
    try {
        await API.post(`/events/${currentEventId}/classes`, { name, course_id: courseId });
        loadClasses();
        updateSetupStats();
        document.getElementById('class-name').value = '';
    } catch (e) {
        alert('Fel: ' + e.message);
    }
}

async function deleteClass(id) {
    try {
        await API.del(`/events/${currentEventId}/classes/${id}`);
        loadClasses();
        updateSetupStats();
    } catch (e) {
        alert(e.message);
    }
}

// ─── Templates ──────────────────────────────────────────────────────

async function loadTemplateList() {
    try {
        const data = await API.get('/templates');
        const sel = document.getElementById('template-select');
        sel.innerHTML = '';
        data.builtin.forEach(t => {
            sel.innerHTML += `<option value="${t.name}">${t.name}</option>`;
        });
        data.user.forEach(t => {
            sel.innerHTML += `<option value="${t.name}">[Egen] ${t.name}</option>`;
        });
    } catch (e) {
        console.error(e);
    }
}

async function applyTemplate() {
    if (!currentEventId) return;
    const name = document.getElementById('template-select').value;
    if (!name) return;
    if (!confirm(`Ladda mall "${name}"? Detta ersätter befintlig struktur.`)) return;
    try {
        const result = await API.post(`/events/${currentEventId}/apply-template?name=${encodeURIComponent(name)}`);
        loadControls();
        loadStages();
        loadCourses();
        loadClasses();
        updateSetupStats();
        alert(`Mall laddad: ${result.count} objekt skapade`);
    } catch (e) {
        alert('Fel: ' + e.message);
    }
}

// ─── Entries ────────────────────────────────────────────────────────

async function loadEntries() {
    if (!currentEventId) return;
    try {
        const entries = await API.get(`/events/${currentEventId}/entries`);
        const tbody = document.getElementById('entries-list');
        tbody.innerHTML = entries.map(e => `
            <tr>
                <td><strong>${e.bib}</strong></td>
                <td>${e.first_name} ${e.last_name}</td>
                <td>${e.club || ''}</td>
                <td>${e.class_name || ''}</td>
                <td><button class="btn btn-danger btn-sm" onclick="deleteEntry(${e.id})">×</button></td>
            </tr>
        `).join('');
    } catch (e) {
        console.error(e);
    }
}

async function importStartlist() {
    if (!currentEventId) return;
    const fileInput = document.getElementById('startlist-file');
    if (!fileInput.files[0]) { alert('Välj en fil'); return; }
    try {
        const result = await API.upload(`/events/${currentEventId}/entries/import`, fileInput.files[0]);
        const msg = document.getElementById('startlist-msg');
        msg.className = 'alert alert-success';
        msg.textContent = `${result.count} åkare importerade`;
        msg.classList.remove('hidden');
        loadEntries();
        loadClassSelectors();
        updateSetupStats();
    } catch (e) {
        alert('Fel: ' + e.message);
    }
}

async function deleteEntry(id) {
    try {
        await API.del(`/events/${currentEventId}/entries/${id}`);
        loadEntries();
        updateSetupStats();
    } catch (e) {
        alert(e.message);
    }
}

// ─── Chips ──────────────────────────────────────────────────────────

async function loadChips() {
    if (!currentEventId) return;
    try {
        const chips = await API.get(`/events/${currentEventId}/chips`);
        const tbody = document.getElementById('chips-list');
        tbody.innerHTML = chips.map(c => `
            <tr>
                <td>${c.bib}</td>
                <td class="text-mono">${c.siac}</td>
                <td>${c.is_primary ? 'Ja' : 'Nej'}</td>
                <td><button class="btn btn-danger btn-sm" onclick="deleteChip(${c.id})">×</button></td>
            </tr>
        `).join('');
    } catch (e) {
        console.error(e);
    }
}

async function importChips() {
    if (!currentEventId) return;
    const fileInput = document.getElementById('chip-file');
    if (!fileInput.files[0]) { alert('Välj en fil'); return; }
    try {
        const result = await API.upload(`/events/${currentEventId}/chips/import`, fileInput.files[0]);
        const msg = document.getElementById('chip-msg');
        msg.className = 'alert alert-success';
        msg.textContent = `${result.count} mappningar importerade`;
        msg.classList.remove('hidden');
        loadChips();
        updateSetupStats();
    } catch (e) {
        alert('Fel: ' + e.message);
    }
}

async function deleteChip(id) {
    try {
        await API.del(`/events/${currentEventId}/chips/${id}`);
        loadChips();
        updateSetupStats();
    } catch (e) {
        alert(e.message);
    }
}

// ─── Punches ────────────────────────────────────────────────────────

async function loadPunches() {
    if (!currentEventId) return;
    try {
        let path = `/events/${currentEventId}/punches`;
        const params = [];
        const source = document.getElementById('punch-source-filter').value;
        const dup = document.getElementById('punch-dup-filter').value;
        if (source) params.push(`source=${source}`);
        if (dup !== '') params.push(`dup=${dup}`);
        if (params.length) path += '?' + params.join('&');

        const punches = await API.get(path);
        const tbody = document.getElementById('punches-list');
        tbody.innerHTML = punches.slice(0, 500).map(p => `
            <tr class="${p.is_duplicate ? 'text-muted' : ''}">
                <td>${p.id}</td>
                <td class="text-mono">${p.siac}</td>
                <td>${p.control_code}</td>
                <td class="time">${p.punch_time}</td>
                <td>${p.source}</td>
                <td>${p.is_duplicate ? 'Ja' : ''}</td>
            </tr>
        `).join('');
    } catch (e) {
        console.error(e);
    }
}

async function addManualPunch() {
    if (!currentEventId) return;
    const siac = parseInt(document.getElementById('manual-siac').value);
    const control_code = parseInt(document.getElementById('manual-control').value);
    const punch_time = document.getElementById('manual-time').value.trim();
    if (!siac || !control_code || !punch_time) { alert('Fyll i alla fält'); return; }
    try {
        await API.post(`/events/${currentEventId}/punches`, {
            siac, control_code, punch_time, source: 'manual'
        });
        loadPunches();
    } catch (e) {
        alert('Fel: ' + e.message);
    }
}

// ─── Stage/Overall results ──────────────────────────────────────────

async function loadStageSelectors() {
    if (!currentEventId) return;
    try {
        const stages = await API.get(`/events/${currentEventId}/stages`);
        const sel = document.getElementById('stage-select');
        sel.innerHTML = stages.map(s => `<option value="${s.id}">Stage ${s.stage_number} — ${s.name}</option>`).join('');
    } catch (e) {}
}

async function loadClassSelectors() {
    if (!currentEventId) return;
    try {
        const classes = await API.get(`/events/${currentEventId}/classes`);
        const opts = '<option value="">Alla</option>' +
            classes.map(c => `<option value="${c.name}">${c.name}</option>`).join('');
        document.getElementById('stage-class-select').innerHTML = opts;
        document.getElementById('overall-class-select').innerHTML = opts;
    } catch (e) {}
}

async function loadStageResults() {
    if (!currentEventId) return;
    const stageId = document.getElementById('stage-select').value;
    const className = document.getElementById('stage-class-select').value;
    if (!stageId) return;
    try {
        let path = `/events/${currentEventId}/stages/${stageId}/results`;
        if (className) path += `?class=${encodeURIComponent(className)}`;
        const results = await API.get(path);
        const tbody = document.getElementById('stage-results');

        let pos = 0;
        let leaderTime = null;
        tbody.innerHTML = results.map(r => {
            if (r.status === 'ok') {
                pos++;
                if (leaderTime === null) leaderTime = r.elapsed_seconds;
                const behind = r.elapsed_seconds - leaderTime;
                return `<tr data-pos="${pos}">
                    <td class="pos">${pos}</td>
                    <td><strong>${r.bib}</strong></td>
                    <td>${r.first_name} ${r.last_name}</td>
                    <td>${r.club || ''}</td>
                    <td>${r.class_name}</td>
                    <td class="time">${formatElapsed(r.elapsed_seconds, precision)}</td>
                    <td class="time text-muted">${formatBehind(behind, precision)}</td>
                </tr>`;
            }
            return `<tr>
                <td></td><td>${r.bib}</td><td>${r.first_name} ${r.last_name}</td>
                <td>${r.club || ''}</td><td>${r.class_name}</td>
                <td></td><td class="text-muted">${r.status}</td>
            </tr>`;
        }).join('');
    } catch (e) {
        console.error(e);
    }
}

async function loadOverallResults() {
    if (!currentEventId) return;
    const className = document.getElementById('overall-class-select').value;
    try {
        let path = `/events/${currentEventId}/overall`;
        if (className) path += `?class=${encodeURIComponent(className)}`;
        const results = await API.get(path);
        const tbody = document.getElementById('overall-results');
        tbody.innerHTML = results.map(r => `
            <tr data-pos="${r.position || ''}">
                <td class="pos">${r.position || ''}</td>
                <td><strong>${r.bib}</strong></td>
                <td>${r.first_name} ${r.last_name}</td>
                <td>${r.club || ''}</td>
                <td>${r.class_name}</td>
                <td class="time">${r.total_seconds != null ? formatElapsed(r.total_seconds, precision) : ''}</td>
                <td class="time text-muted">${r.time_behind ? formatBehind(r.time_behind, precision) : ''}</td>
                <td>${r.status}</td>
            </tr>
        `).join('');
    } catch (e) {
        console.error(e);
    }
}

// ─── Live feed ──────────────────────────────────────────────────────

function addToLiveFeed(msg) {
    const tbody = document.getElementById('live-feed');
    const stageResult = msg.stage_result;
    const elapsed = stageResult ? stageResult.elapsed : '';
    const tr = document.createElement('tr');
    tr.innerHTML = `
        <td class="time">${msg.punch_time || ''}</td>
        <td><strong>${msg.bib || ''}</strong></td>
        <td>${msg.name || ''}</td>
        <td>${msg.control_code || ''} (${msg.control_type || ''})</td>
        <td>${stageResult ? stageResult.stage_name : ''}</td>
        <td class="time">${elapsed}</td>
        <td class="text-muted">${msg.source || ''}</td>
    `;
    tbody.insertBefore(tr, tbody.firstChild);
    while (tbody.children.length > 100) {
        tbody.removeChild(tbody.lastChild);
    }
}

function updateLiveHero(msg) {
    const hero = document.getElementById('live-hero');
    if (!msg.bib || !msg.stage_result) {
        return;
    }
    hero.classList.remove('hidden');
    document.getElementById('live-bib').textContent = `#${msg.bib}`;
    document.getElementById('live-name').textContent = msg.name || '';
    document.getElementById('live-time').textContent = msg.stage_result.elapsed || '';
    document.getElementById('live-pos').textContent =
        msg.stage_result.position ? `${msg.stage_result.position}:a plats` : '';
    document.getElementById('live-behind').textContent = msg.stage_result.behind || '';
}

async function updatePunchCount() {
    if (!currentEventId) return;
    try {
        const status = await API.get('/status');
        const count = status.punch_count || 0;
        document.getElementById('punch-count').textContent = `${count} stämplingar`;
        document.getElementById('status-punches').textContent = `${count} st`;
    } catch (e) {}
}

// ─── Race Day Controls ──────────────────────────────────────────────

async function loadRaceState() {
    try {
        const state = await API.get('/race/state');
        updateIngestBadge(state.ingest_paused);
        updateStandingsBadge(state.standings_frozen);
    } catch (e) {}
}

function updateIngestBadge(paused) {
    const badge = document.getElementById('badge-ingest');
    const btn = document.getElementById('btn-toggle-ingest');
    if (!badge || !btn) return;
    if (paused) {
        badge.textContent = 'Pausad';
        badge.className = 'badge badge-danger';
        btn.textContent = 'Återuppta';
        btn.className = 'btn btn-primary btn-sm';
    } else {
        badge.textContent = 'Aktiv';
        badge.className = 'badge badge-ok';
        btn.textContent = 'Pausa';
        btn.className = 'btn btn-warn btn-sm';
    }
}

function updateStandingsBadge(frozen) {
    const badge = document.getElementById('badge-standings');
    const btn = document.getElementById('btn-toggle-standings');
    if (!badge || !btn) return;
    if (frozen) {
        badge.textContent = 'Frusen';
        badge.className = 'badge badge-warn';
        btn.textContent = 'Frisläpp';
        btn.className = 'btn btn-primary btn-sm';
    } else {
        badge.textContent = 'Aktiv';
        badge.className = 'badge badge-ok';
        btn.textContent = 'Frys';
        btn.className = 'btn btn-warn btn-sm';
    }
}

async function toggleIngest() {
    try {
        const state = await API.get('/race/state');
        if (state.ingest_paused) {
            await API.post('/race/resume-ingest');
            updateIngestBadge(false);
        } else {
            if (!confirm('Pausa all stämplingsmottagning?')) return;
            await API.post('/race/pause-ingest');
            updateIngestBadge(true);
        }
    } catch (e) {
        alert('Fel: ' + e.message);
    }
}

async function toggleStandings() {
    try {
        const state = await API.get('/race/state');
        if (state.standings_frozen) {
            await API.post('/race/unfreeze-standings');
            updateStandingsBadge(false);
        } else {
            if (!confirm('Frysa publika resultatvyer?')) return;
            await API.post('/race/freeze-standings');
            updateStandingsBadge(true);
        }
    } catch (e) {
        alert('Fel: ' + e.message);
    }
}

async function recomputeResults() {
    if (!currentEventId) return;
    if (!confirm('Räkna om alla resultat från stämplingarna?')) return;
    try {
        await API.post(`/events/${currentEventId}/recalculate`);
        alert('Alla resultat omberäknade.');
        loadStageResults();
        loadOverallResults();
    } catch (e) {
        alert('Fel: ' + e.message);
    }
}

// ─── Backup ────────────────────────────────────────────────────────

async function createBackup() {
    try {
        const result = await API.post('/backup', { label: currentEvent ? currentEvent.name.replace(/\s+/g, '_') : '' });
        const msg = document.getElementById('backup-msg');
        msg.className = 'alert alert-success';
        msg.textContent = `Backup skapad: ${result.filename}`;
        msg.classList.remove('hidden');
        setTimeout(() => msg.classList.add('hidden'), 5000);
        loadBackups();
    } catch (e) {
        alert('Fel: ' + e.message);
    }
}

async function loadBackups() {
    try {
        const backups = await API.get('/backups');
        const el = document.getElementById('backups-list');
        if (!backups.length) {
            el.innerHTML = '<p class="text-muted" style="font-size:0.85rem">Inga backups ännu</p>';
            return;
        }
        el.innerHTML = backups.slice(0, 10).map(b => `
            <div class="backup-item">
                <div class="backup-info">
                    <span>${b.filename}</span>
                    <span class="backup-meta">${b.size_mb} MB</span>
                </div>
                <button class="btn btn-secondary btn-sm" onclick="restoreBackup('${b.filename}')">Återställ</button>
            </div>
        `).join('');
    } catch (e) {}
}

async function restoreBackup(filename) {
    if (!confirm(`Återställ databasen från ${filename}?\n\nDetta ersätter ALL nuvarande data!`)) return;
    try {
        await API.post(`/restore/${filename}`);
        alert('Databas återställd! Laddar om sidan...');
        location.reload();
    } catch (e) {
        alert('Fel: ' + e.message);
    }
}

// ─── Audit Log ─────────────────────────────────────────────────────

async function loadAuditLog() {
    try {
        const log = currentEventId
            ? await API.get(`/events/${currentEventId}/audit?limit=50`)
            : await API.get('/audit?limit=50');
        const tbody = document.getElementById('audit-list');
        if (!log.length) {
            tbody.innerHTML = '<tr><td colspan="5" class="text-muted">Inga loggar ännu</td></tr>';
            return;
        }
        tbody.innerHTML = log.map(l => `
            <tr>
                <td class="time" style="font-size:0.8rem">${l.created_at || ''}</td>
                <td>${l.action}</td>
                <td class="text-muted">${l.entity_type || ''}</td>
                <td class="text-muted" style="font-size:0.8rem">${l.details || ''}</td>
                <td class="text-muted">${l.source || ''}</td>
            </tr>
        `).join('');
    } catch (e) {}
}

// ─── Connections Tab ────────────────────────────────────────────────

let rocStatusInterval = null;

async function loadConnectionsTab() {
    if (!currentEventId) return;
    loadRocStatus();
    refreshUsbPorts();
    startRocStatusPolling();
}

function startRocStatusPolling() {
    stopRocStatusPolling();
    rocStatusInterval = setInterval(loadRocStatus, 3000);
}

function stopRocStatusPolling() {
    if (rocStatusInterval) {
        clearInterval(rocStatusInterval);
        rocStatusInterval = null;
    }
}

async function loadRocStatus() {
    try {
        const status = await API.get('/roc/status');

        // Update badge
        const badge = document.getElementById('badge-roc');
        if (status.is_running) {
            if (status.status === 'Online') {
                badge.textContent = 'Online';
                badge.className = 'badge badge-ok';
            } else if (status.status.startsWith('Fel')) {
                badge.textContent = status.status;
                badge.className = 'badge badge-warn';
            } else {
                badge.textContent = status.status;
                badge.className = 'badge badge-ok';
            }
        } else {
            badge.textContent = 'Stoppad';
            badge.className = 'badge badge-danger';
        }

        // Update toggle button
        const btn = document.getElementById('btn-roc-toggle');
        if (status.is_running) {
            btn.textContent = 'Stoppa';
            btn.className = 'btn btn-danger btn-sm';
        } else {
            btn.textContent = 'Starta';
            btn.className = 'btn btn-primary btn-sm';
        }

        // Update stats
        document.getElementById('roc-punch-count').textContent = status.punch_count || 0;
        document.getElementById('roc-error-count').textContent = status.error_count || 0;
        document.getElementById('roc-last-poll').textContent = status.last_poll || '\u2014';
        document.getElementById('roc-last-id').textContent = status.last_id || 0;
        document.getElementById('roc-status-text').textContent =
            status.is_running ? `Pollar ${status.competition_id || '?'}` : 'Inte startad';

        // Update competition ID field (only if empty)
        const idInput = document.getElementById('roc-competition-id');
        if (!idInput.value && status.competition_id) {
            idInput.value = status.competition_id;
        }

        // Update status bar
        const rocDot = document.getElementById('roc-dot');
        const rocBarStatus = document.getElementById('roc-bar-status');
        if (status.is_running && status.status === 'Online') {
            rocDot.className = 'status-dot online';
            rocBarStatus.textContent = 'ROC';
        } else if (status.is_running) {
            rocDot.className = 'status-dot warning';
            rocBarStatus.textContent = 'ROC...';
        } else {
            rocDot.className = 'status-dot offline';
            rocBarStatus.textContent = 'ROC av';
        }
    } catch (e) {
        console.error('ROC status error:', e);
    }
}

async function toggleRocPolling() {
    try {
        const status = await API.get('/roc/status');
        if (status.is_running) {
            await API.post('/roc/stop');
        } else {
            await API.post('/roc/start');
        }
        await loadRocStatus();
    } catch (e) {
        alert('Fel: ' + e.message);
    }
}

async function saveRocConfig() {
    const id = document.getElementById('roc-competition-id').value.trim();
    if (!id) {
        alert('Ange ett t\u00e4vlings-ID');
        return;
    }
    try {
        await API.put('/roc/config', { competition_id: id });
        alert('ROC t\u00e4vlings-ID sparat: ' + id);
    } catch (e) {
        alert('Fel: ' + e.message);
    }
}

// ─── USB ────────────────────────────────────────────────────────────

async function refreshUsbPorts() {
    try {
        const data = await API.get('/usb/ports');
        const sel = document.getElementById('usb-port-select');
        if (!data.ports || data.ports.length === 0) {
            sel.innerHTML = '<option value="">Inga portar hittades</option>';
        } else {
            sel.innerHTML = data.ports.map(p =>
                `<option value="${p.device}">${p.device} \u2014 ${p.description}</option>`
            ).join('');
        }
    } catch (e) {
        console.error('USB ports error:', e);
        const sel = document.getElementById('usb-port-select');
        sel.innerHTML = '<option value="">Kunde inte s\u00f6ka portar</option>';
    }
}

async function toggleUsbReader() {
    alert('USB-l\u00e4sare \u00e4r inte implementerad \u00e4nnu. Kommer i en framtida version.');
}

// ─── TheHUB ─────────────────────────────────────────────────────────

let theHubPreviewData = null;

async function previewTheHub() {
    if (!currentEventId) return;
    const baseUrl = document.getElementById('thehub-base-url').value.trim();
    const compId = document.getElementById('thehub-competition-id').value.trim();
    if (!compId) {
        alert('Ange ett t\u00e4vlings-ID');
        return;
    }

    const msg = document.getElementById('thehub-msg');
    msg.className = 'alert alert-info';
    msg.textContent = 'H\u00e4mtar startlista fr\u00e5n TheHUB...';
    msg.classList.remove('hidden');

    try {
        const data = await API.post(`/events/${currentEventId}/preview-thehub`, {
            competition_id: compId,
            base_url: baseUrl,
        });

        theHubPreviewData = data;
        msg.className = 'alert alert-success';
        msg.textContent = `Hittade ${data.count} deltagare`;

        // Show preview table
        const preview = document.getElementById('thehub-preview');
        const tbody = document.getElementById('thehub-preview-list');
        if (data.entries && data.entries.length > 0) {
            tbody.innerHTML = data.entries.map(e => `
                <tr>
                    <td><strong>${e.bib || ''}</strong></td>
                    <td>${e.first_name || ''} ${e.last_name || ''}</td>
                    <td>${e.club || ''}</td>
                    <td>${e.class_name || ''}</td>
                </tr>
            `).join('');
            preview.classList.remove('hidden');
            document.getElementById('btn-thehub-import').disabled = false;
        } else {
            preview.classList.add('hidden');
            document.getElementById('btn-thehub-import').disabled = true;
        }
    } catch (e) {
        msg.className = 'alert alert-danger';
        msg.textContent = 'Fel: ' + e.message;
        document.getElementById('thehub-preview').classList.add('hidden');
        document.getElementById('btn-thehub-import').disabled = true;
        theHubPreviewData = null;
    }
}

async function importFromTheHub() {
    if (!currentEventId) return;
    const baseUrl = document.getElementById('thehub-base-url').value.trim();
    const compId = document.getElementById('thehub-competition-id').value.trim();
    if (!compId) return;

    if (!confirm(`Importera ${theHubPreviewData?.count || '?'} deltagare fr\u00e5n TheHUB?`)) return;

    const msg = document.getElementById('thehub-msg');
    try {
        const result = await API.post(`/events/${currentEventId}/import-thehub`, {
            competition_id: compId,
            base_url: baseUrl,
        });
        msg.className = 'alert alert-success';
        let text = `${result.count} deltagare importerade fr\u00e5n TheHUB`;
        if (result.warnings && result.warnings.length > 0) {
            text += ` (${result.warnings.length} varningar)`;
        }
        msg.textContent = text;

        // Reload entries and classes
        loadEntries();
        loadClasses();
        loadClassSelectors();
        updateSetupStats();

        // Disable import button
        document.getElementById('btn-thehub-import').disabled = true;
    } catch (e) {
        msg.className = 'alert alert-danger';
        msg.textContent = 'Importfel: ' + e.message;
    }
}

// ─── Boot ───────────────────────────────────────────────────────────

init();
