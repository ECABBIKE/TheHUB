<div class="card">
  <div class="card-header">
    <h1 class="card-title">API Explorer</h1>
    <span class="badge">Read-only API</span>
  </div>

  <p>Test API endpoints direkt här i backend. JSON visas nedan.</p>

  <h2>Riders API</h2>
  <div class="form-group">
    <label>Rider ID</label>
    <input id="riderIdInput" type="number" placeholder="t.ex 123">
  </div>
  <button class="btn" onclick="runApi('/api/riders')">GET /api/riders</button>
  <button class="btn btn-secondary" onclick="runApi('/api/rider?id=' + document.getElementById('riderIdInput').value)">GET /api/rider?id=ID</button>

  <h2 style="margin-top:2rem;">Events API</h2>
  <div class="form-group">
    <label>Event ID</label>
    <input id="eventIdInput" type="number" placeholder="t.ex 5">
  </div>
  <button class="btn" onclick="runApi('/api/events')">GET /api/events</button>
  <button class="btn btn-secondary" onclick="runApi('/api/event?id=' + document.getElementById('eventIdInput').value)">GET /api/event?id=ID</button>

  <h2 style="margin-top:2rem;">Output</h2>
  <pre id="apiOutput" class="json-output" style="padding:1rem; background:#000; color:#0f0; border-radius:8px; min-height:200px;"></pre>
</div>

<script src="<?php echo url('js/api-explorer.js'); ?>"></script>
