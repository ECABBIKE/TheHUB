<?php http_response_code(404); $requested = $pageInfo['params']['requested'] ?? 'okänd sida'; ?>
<div class="page-grid">
  <section class="card grid-full text-center p-lg">
    <div style="margin-bottom:var(--space-md); color: var(--color-text-muted)">
      <i data-lucide="bike" style="width: 64px; height: 64px; stroke-width: 1.5"></i>
    </div>
    <h1 class="text-2xl font-bold mb-sm">404 – Sidan hittades inte</h1>
    <p class="text-secondary mb-lg">Sidan <code style="background:var(--color-bg-sunken);padding:2px 6px;border-radius:4px"><?= htmlspecialchars($requested) ?></code> finns inte.</p>
    <div class="flex justify-center gap-md">
      <a href="/" class="btn btn--primary">Tillbaka till start</a>
      <a href="/results" class="btn btn--secondary">Visa resultat</a>
    </div>
  </section>
</div>
