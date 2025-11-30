<?php
?><!DOCTYPE html>
<html lang="sv">
<head>
  <meta charset="UTF-8">
  <title>TheHUB V4 backend</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    body { font-family: system-ui, -apple-system, sans-serif; background:#020617; color:#e5e7eb; margin:0; padding:2rem; }
    a { color:#38bdf8; }
    .card { max-width:640px; margin:0 auto; background:#020824; border-radius:16px; padding:1.25rem 1.5rem; border:1px solid #1f2937; }
    h1 { margin-top:0; font-size:1.4rem; }
    code { background:#020617; padding:0.15rem 0.35rem; border-radius:4px; }
    ul { padding-left:1.2rem; }
  </style>
</head>
<body>
  <div class="card">
    <h1>TheHUB V4 – Backend</h1>
    <p>Detta är en enkel översikt över backend-API:t som används av V4-frontenden.</p>
    <h2>API endpoints</h2>
    <ul>
      <li><code>/thehub-v4/backend/public/api/riders.php</code> – hämtar riders (max 500 st)</li>
      <li><code>/thehub-v4/backend/public/api/events.php</code> – stub, returnerar tom lista</li>
      <li><code>/thehub-v4/backend/public/api/results.php</code> – stub, returnerar tom lista</li>
      <li><code>/thehub-v4/backend/public/api/ranking.php</code> – stub, returnerar tom lista</li>
    </ul>
    <p>När vi vet exakt vilka tabeller som skall användas för events, resultat och ranking kan vi uppdatera dessa filer.</p>
  </div>
</body>
</html>
