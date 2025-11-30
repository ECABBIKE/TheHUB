<div class="card">
  <div class="card-header">
    <h1 class="card-title">API Explorer</h1>
    <span class="badge">Read-only JSON</span>
  </div>

  <p>Testa API-endpoints direkt. JSON-resultat visas nedan.</p>

  <div style="margin-bottom: 0.75rem;">
    <button class="btn" type="button"
      onclick="runApi('<?php echo url('public/api/riders.php'); ?>')">
      GET /riders
    </button>

    <button class="btn btn-secondary" type="button"
      onclick="runApi('<?php echo url('public/api/events.php'); ?>')">
      GET /events
    </button>
  </div>

  <pre id="apiOutput" style="font-size: 0.75rem; background:#020617; border-radius:0.5rem; padding:0.6rem; border:1px solid #111827; max-height:380px; overflow:auto;">
Klicka på en knapp ovan för att köra ett API-anrop…
  </pre>
</div>

<script src="<?php echo url('public/js/api-explorer.js'); ?>"></script>
