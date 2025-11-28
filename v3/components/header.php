<header class="header" role="banner">
  <a href="/v3/" class="header-brand" aria-label="TheHUB startsida">
    <svg class="header-logo" viewBox="0 0 32 32" fill="currentColor" aria-hidden="true">
      <circle cx="16" cy="16" r="14" stroke="currentColor" stroke-width="2" fill="none"/>
      <text x="16" y="21" text-anchor="middle" font-size="12" font-weight="bold">HUB</text>
    </svg>
    <span>TheHUB</span>
  </a>

  <div class="header-actions">
    <button type="button"
            class="pwa-install-btn header-install hidden"
            id="pwa-install-btn"
            data-pwa-install
            aria-label="Installera TheHUB som app">
      <span class="pwa-install-icon" aria-hidden="true">📲</span>
      <span class="hide-mobile">Installera</span>
    </button>
    <span class="header-version">V<?= HUB_VERSION ?></span>
  </div>
</header>
