<?php http_response_code(404); $requested = $pageInfo['params']['requested'] ?? 'okänd sida'; ?>
<div class="page-grid">
  <section class="card grid-full text-center p-lg">
    <div style="font-size:4rem;margin-bottom:var(--space-md)">🚴‍♂️💨</div>
    <h1 class="text-2xl font-bold mb-sm">404 – Sidan hittades inte</h1>
    <p class="text-secondary mb-lg">Sidan <code style="background:var(--color-bg-sunken);padding:2px 6px;border-radius:4px"><?= htmlspecialchars($requested) ?></code> finns inte.</p>
    <div class="flex justify-center gap-md">
      <a href="/v3/" class="btn btn--primary">Tillbaka till start</a>
      <a href="/v3/results" class="btn btn--secondary">Visa resultat</a>
    </div>
  </section>
</div>
