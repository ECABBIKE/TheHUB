# TheHUB V3 – Progressive Web App Implementation

## CONTEXT

You are enhancing the TheHUB cycling platform at:
https://thehub.gravityseries.se/v3/

The V3 UI structure already exists. Now we need to make it a **Progressive Web App (PWA)** so users can install it on their phones and use it like a native app – with NO browser chrome, fullscreen experience, and app icon on home screen.

**This is critical for:**
- Race day usage (riders checking results on phones)
- Landscape mode without browser UI eating 50% of screen
- Professional app-like experience
- Works on both iOS and Android without App Store

---

## GOAL

Add complete PWA support to /v3/ including:
- Web App Manifest
- iOS meta tags (Apple requires special handling)
- Service Worker for offline capability
- App icons in all required sizes
- Splash screens for iOS
- Install prompt handling

---

## 1. CREATE WEB APP MANIFEST

**File: /v3/manifest.json**

```json
{
  "name": "TheHUB – GravitySeries",
  "short_name": "TheHUB",
  "description": "Sveriges plattform för gravity cycling – resultat, serier och åkarprofiler",
  "start_url": "/v3/",
  "scope": "/v3/",
  "display": "standalone",
  "orientation": "any",
  "background_color": "#0A0C14",
  "theme_color": "#004A98",
  "categories": ["sports", "lifestyle"],
  "lang": "sv-SE",
  "dir": "ltr",
  "icons": [
    {
      "src": "/v3/assets/icons/icon-72.png",
      "sizes": "72x72",
      "type": "image/png",
      "purpose": "any"
    },
    {
      "src": "/v3/assets/icons/icon-96.png",
      "sizes": "96x96",
      "type": "image/png",
      "purpose": "any"
    },
    {
      "src": "/v3/assets/icons/icon-128.png",
      "sizes": "128x128",
      "type": "image/png",
      "purpose": "any"
    },
    {
      "src": "/v3/assets/icons/icon-144.png",
      "sizes": "144x144",
      "type": "image/png",
      "purpose": "any"
    },
    {
      "src": "/v3/assets/icons/icon-152.png",
      "sizes": "152x152",
      "type": "image/png",
      "purpose": "any"
    },
    {
      "src": "/v3/assets/icons/icon-192.png",
      "sizes": "192x192",
      "type": "image/png",
      "purpose": "any"
    },
    {
      "src": "/v3/assets/icons/icon-384.png",
      "sizes": "384x384",
      "type": "image/png",
      "purpose": "any"
    },
    {
      "src": "/v3/assets/icons/icon-512.png",
      "sizes": "512x512",
      "type": "image/png",
      "purpose": "any"
    },
    {
      "src": "/v3/assets/icons/icon-maskable-192.png",
      "sizes": "192x192",
      "type": "image/png",
      "purpose": "maskable"
    },
    {
      "src": "/v3/assets/icons/icon-maskable-512.png",
      "sizes": "512x512",
      "type": "image/png",
      "purpose": "maskable"
    }
  ],
  "screenshots": [
    {
      "src": "/v3/assets/screenshots/desktop.png",
      "sizes": "1920x1080",
      "type": "image/png",
      "form_factor": "wide",
      "label": "TheHUB Dashboard"
    },
    {
      "src": "/v3/assets/screenshots/mobile.png",
      "sizes": "390x844",
      "type": "image/png",
      "form_factor": "narrow",
      "label": "TheHUB Mobile"
    }
  ],
  "shortcuts": [
    {
      "name": "Resultat",
      "short_name": "Resultat",
      "description": "Visa senaste tävlingsresultat",
      "url": "/v3/results",
      "icons": [{ "src": "/v3/assets/icons/shortcut-results.png", "sizes": "192x192" }]
    },
    {
      "name": "Serier",
      "short_name": "Serier",
      "description": "Visa serieställningar",
      "url": "/v3/series",
      "icons": [{ "src": "/v3/assets/icons/shortcut-series.png", "sizes": "192x192" }]
    }
  ],
  "related_applications": [],
  "prefer_related_applications": false
}
```

---

## 2. UPDATE HEAD COMPONENT WITH PWA META TAGS

**File: /v3/components/head.php** – Replace entirely with:

```php
<?php 
$pageTitle = ucfirst($pageInfo['page'] ?? 'Dashboard') . ' – TheHUB';
$themeColor = hub_get_theme() === 'dark' ? '#0A0C14' : '#004A98';
?>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover, user-scalable=no">
<meta name="description" content="TheHUB – Sveriges plattform för gravity cycling">

<!-- PWA Meta Tags -->
<meta name="application-name" content="TheHUB">
<meta name="mobile-web-app-capable" content="yes">
<meta name="theme-color" content="<?= $themeColor ?>" id="theme-color-meta">

<!-- iOS PWA Meta Tags (Apple specific) -->
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="TheHUB">

<!-- iOS Icons -->
<link rel="apple-touch-icon" href="<?= HUB_V3_URL ?>/assets/icons/icon-152.png">
<link rel="apple-touch-icon" sizes="180x180" href="<?= HUB_V3_URL ?>/assets/icons/icon-180.png">
<link rel="apple-touch-icon" sizes="167x167" href="<?= HUB_V3_URL ?>/assets/icons/icon-167.png">

<!-- iOS Splash Screens -->
<!-- iPhone 14 Pro Max (430x932) -->
<link rel="apple-touch-startup-image" 
      href="<?= HUB_V3_URL ?>/assets/splash/splash-1290x2796.png"
      media="(device-width: 430px) and (device-height: 932px) and (-webkit-device-pixel-ratio: 3)">
<!-- iPhone 14 Pro (393x852) -->
<link rel="apple-touch-startup-image" 
      href="<?= HUB_V3_URL ?>/assets/splash/splash-1179x2556.png"
      media="(device-width: 393px) and (device-height: 852px) and (-webkit-device-pixel-ratio: 3)">
<!-- iPhone 13/14 (390x844) -->
<link rel="apple-touch-startup-image" 
      href="<?= HUB_V3_URL ?>/assets/splash/splash-1170x2532.png"
      media="(device-width: 390px) and (device-height: 844px) and (-webkit-device-pixel-ratio: 3)">
<!-- iPhone SE (375x667) -->
<link rel="apple-touch-startup-image" 
      href="<?= HUB_V3_URL ?>/assets/splash/splash-750x1334.png"
      media="(device-width: 375px) and (device-height: 667px) and (-webkit-device-pixel-ratio: 2)">
<!-- iPad Pro 12.9" -->
<link rel="apple-touch-startup-image" 
      href="<?= HUB_V3_URL ?>/assets/splash/splash-2048x2732.png"
      media="(device-width: 1024px) and (device-height: 1366px) and (-webkit-device-pixel-ratio: 2)">

<!-- Web App Manifest -->
<link rel="manifest" href="<?= HUB_V3_URL ?>/manifest.json">

<!-- Favicon -->
<link rel="icon" type="image/png" sizes="32x32" href="<?= HUB_V3_URL ?>/assets/icons/favicon-32.png">
<link rel="icon" type="image/png" sizes="16x16" href="<?= HUB_V3_URL ?>/assets/icons/favicon-16.png">
<link rel="icon" type="image/svg+xml" href="<?= HUB_V3_URL ?>/assets/favicon.svg">

<!-- Preconnect -->
<link rel="preconnect" href="https://fonts.googleapis.com">

<title><?= htmlspecialchars($pageTitle) ?></title>

<!-- CSS with cache busting -->
<link rel="stylesheet" href="<?= hub_asset('css/reset.css') ?>">
<link rel="stylesheet" href="<?= hub_asset('css/tokens.css') ?>">
<link rel="stylesheet" href="<?= hub_asset('css/theme.css') ?>">
<link rel="stylesheet" href="<?= hub_asset('css/layout.css') ?>">
<link rel="stylesheet" href="<?= hub_asset('css/components.css') ?>">
<link rel="stylesheet" href="<?= hub_asset('css/tables.css') ?>">
<link rel="stylesheet" href="<?= hub_asset('css/utilities.css') ?>">
<link rel="stylesheet" href="<?= hub_asset('css/pwa.css') ?>">
```

---

## 3. CREATE SERVICE WORKER

**File: /v3/sw.js**

```javascript
/**
 * TheHUB V3 Service Worker
 * Handles caching and offline functionality
 */

const CACHE_NAME = 'thehub-v3-cache-v1';
const OFFLINE_URL = '/v3/offline.html';

// Assets to cache immediately on install
const PRECACHE_ASSETS = [
  '/v3/',
  '/v3/manifest.json',
  '/v3/offline.html',
  '/v3/assets/css/reset.css',
  '/v3/assets/css/tokens.css',
  '/v3/assets/css/theme.css',
  '/v3/assets/css/layout.css',
  '/v3/assets/css/components.css',
  '/v3/assets/css/tables.css',
  '/v3/assets/css/utilities.css',
  '/v3/assets/css/pwa.css',
  '/v3/assets/js/theme.js',
  '/v3/assets/js/router.js',
  '/v3/assets/js/app.js',
  '/v3/assets/js/pwa.js',
  '/v3/assets/icons/icon-192.png',
  '/v3/assets/icons/icon-512.png',
  '/v3/assets/favicon.svg'
];

// Install event - precache assets
self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME)
      .then((cache) => {
        console.log('[SW] Precaching assets');
        return cache.addAll(PRECACHE_ASSETS);
      })
      .then(() => self.skipWaiting())
  );
});

// Activate event - clean old caches
self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((cacheNames) => {
        return Promise.all(
          cacheNames
            .filter((name) => name !== CACHE_NAME)
            .map((name) => {
              console.log('[SW] Deleting old cache:', name);
              return caches.delete(name);
            })
        );
      })
      .then(() => self.clients.claim())
  );
});

// Fetch event - network first, fallback to cache
self.addEventListener('fetch', (event) => {
  const { request } = event;
  const url = new URL(request.url);
  
  // Skip non-GET requests
  if (request.method !== 'GET') return;
  
  // Skip external requests
  if (url.origin !== location.origin) return;
  
  // Skip API/PHP requests that need fresh data
  if (url.pathname.includes('/api/') || url.search.includes('fresh=1')) {
    return;
  }
  
  // For navigation requests (HTML pages)
  if (request.mode === 'navigate') {
    event.respondWith(
      fetch(request)
        .then((response) => {
          // Cache successful responses
          if (response.ok) {
            const clone = response.clone();
            caches.open(CACHE_NAME).then((cache) => cache.put(request, clone));
          }
          return response;
        })
        .catch(() => {
          // Offline - try cache, then offline page
          return caches.match(request)
            .then((cached) => cached || caches.match(OFFLINE_URL));
        })
    );
    return;
  }
  
  // For static assets - cache first, fallback to network
  if (url.pathname.match(/\.(css|js|png|jpg|jpeg|svg|woff2?)$/)) {
    event.respondWith(
      caches.match(request)
        .then((cached) => {
          if (cached) return cached;
          
          return fetch(request).then((response) => {
            if (response.ok) {
              const clone = response.clone();
              caches.open(CACHE_NAME).then((cache) => cache.put(request, clone));
            }
            return response;
          });
        })
    );
    return;
  }
  
  // Default - network first
  event.respondWith(
    fetch(request)
      .then((response) => {
        if (response.ok) {
          const clone = response.clone();
          caches.open(CACHE_NAME).then((cache) => cache.put(request, clone));
        }
        return response;
      })
      .catch(() => caches.match(request))
  );
});

// Handle messages from client
self.addEventListener('message', (event) => {
  if (event.data === 'skipWaiting') {
    self.skipWaiting();
  }
});
```

---

## 4. CREATE OFFLINE PAGE

**File: /v3/offline.html**

```html
<!DOCTYPE html>
<html lang="sv" data-theme="dark">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  <title>Offline – TheHUB</title>
  <style>
    :root {
      --bg: #0A0C14;
      --surface: #12141C;
      --text: #F9FAFB;
      --text-secondary: #9CA3AF;
      --accent: #3B9EFF;
      --border: #2D3139;
    }
    
    * { box-sizing: border-box; margin: 0; padding: 0; }
    
    body {
      font-family: system-ui, -apple-system, sans-serif;
      background: var(--bg);
      color: var(--text);
      min-height: 100vh;
      min-height: 100dvh;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      padding: 24px;
      text-align: center;
    }
    
    .offline-icon {
      font-size: 4rem;
      margin-bottom: 24px;
      animation: pulse 2s ease-in-out infinite;
    }
    
    @keyframes pulse {
      0%, 100% { opacity: 1; }
      50% { opacity: 0.5; }
    }
    
    h1 {
      font-size: 1.5rem;
      font-weight: 600;
      margin-bottom: 12px;
    }
    
    p {
      color: var(--text-secondary);
      max-width: 300px;
      line-height: 1.6;
      margin-bottom: 32px;
    }
    
    .retry-btn {
      background: var(--accent);
      color: white;
      border: none;
      padding: 12px 24px;
      border-radius: 10px;
      font-size: 1rem;
      font-weight: 500;
      cursor: pointer;
      transition: transform 0.15s ease, opacity 0.15s ease;
    }
    
    .retry-btn:hover {
      opacity: 0.9;
    }
    
    .retry-btn:active {
      transform: scale(0.97);
    }
    
    .cached-pages {
      margin-top: 48px;
      padding-top: 24px;
      border-top: 1px solid var(--border);
      width: 100%;
      max-width: 300px;
    }
    
    .cached-pages h2 {
      font-size: 0.875rem;
      color: var(--text-secondary);
      margin-bottom: 16px;
      font-weight: 500;
    }
    
    .cached-pages a {
      display: block;
      color: var(--accent);
      padding: 8px;
      text-decoration: none;
    }
  </style>
</head>
<body>
  <div class="offline-icon">📡</div>
  <h1>Du är offline</h1>
  <p>Ingen internetanslutning. Kontrollera din uppkoppling och försök igen.</p>
  <button class="retry-btn" onclick="location.reload()">Försök igen</button>
  
  <div class="cached-pages" id="cached-pages" style="display: none;">
    <h2>Cachade sidor</h2>
    <div id="cached-links"></div>
  </div>
  
  <script>
    // Show cached pages if available
    if ('caches' in window) {
      caches.open('thehub-v3-cache-v1').then(cache => {
        cache.keys().then(requests => {
          const pages = requests
            .filter(r => r.url.includes('/v3/') && !r.url.match(/\.(css|js|png|jpg|svg)$/))
            .map(r => new URL(r.url).pathname);
          
          if (pages.length > 1) {
            const container = document.getElementById('cached-links');
            const uniquePages = [...new Set(pages)];
            uniquePages.forEach(page => {
              const link = document.createElement('a');
              link.href = page;
              link.textContent = page.replace('/v3/', '/').replace(/\/$/, '') || 'Dashboard';
              container.appendChild(link);
            });
            document.getElementById('cached-pages').style.display = 'block';
          }
        });
      });
    }
  </script>
</body>
</html>
```

---

## 5. CREATE PWA JAVASCRIPT

**File: /v3/assets/js/pwa.js**

```javascript
/**
 * TheHUB V3 - PWA Functionality
 * Handles service worker registration, install prompts, and updates
 */

const HubPWA = (function() {
  'use strict';
  
  let deferredPrompt = null;
  let swRegistration = null;
  
  /**
   * Register service worker
   */
  async function registerServiceWorker() {
    if (!('serviceWorker' in navigator)) {
      console.log('[PWA] Service workers not supported');
      return;
    }
    
    try {
      swRegistration = await navigator.serviceWorker.register('/v3/sw.js', {
        scope: '/v3/'
      });
      
      console.log('[PWA] Service worker registered:', swRegistration.scope);
      
      // Check for updates
      swRegistration.addEventListener('updatefound', () => {
        const newWorker = swRegistration.installing;
        
        newWorker.addEventListener('statechange', () => {
          if (newWorker.state === 'installed' && navigator.serviceWorker.controller) {
            // New version available
            showUpdateNotification();
          }
        });
      });
      
    } catch (error) {
      console.error('[PWA] Service worker registration failed:', error);
    }
  }
  
  /**
   * Handle beforeinstallprompt event
   */
  function handleInstallPrompt(e) {
    // Prevent Chrome's default prompt
    e.preventDefault();
    
    // Store the event for later
    deferredPrompt = e;
    
    // Show custom install UI
    showInstallButton();
    
    console.log('[PWA] Install prompt ready');
  }
  
  /**
   * Show install button in UI
   */
  function showInstallButton() {
    const installBtn = document.getElementById('pwa-install-btn');
    if (installBtn) {
      installBtn.classList.remove('hidden');
    }
    
    // Also show in header if exists
    const headerInstall = document.querySelector('.header-install');
    if (headerInstall) {
      headerInstall.classList.remove('hidden');
    }
  }
  
  /**
   * Hide install button
   */
  function hideInstallButton() {
    const installBtn = document.getElementById('pwa-install-btn');
    if (installBtn) {
      installBtn.classList.add('hidden');
    }
    
    const headerInstall = document.querySelector('.header-install');
    if (headerInstall) {
      headerInstall.classList.add('hidden');
    }
  }
  
  /**
   * Trigger install prompt
   */
  async function promptInstall() {
    if (!deferredPrompt) {
      console.log('[PWA] No install prompt available');
      return false;
    }
    
    // Show the prompt
    deferredPrompt.prompt();
    
    // Wait for user response
    const { outcome } = await deferredPrompt.userChoice;
    console.log('[PWA] Install prompt outcome:', outcome);
    
    // Clear the prompt
    deferredPrompt = null;
    hideInstallButton();
    
    return outcome === 'accepted';
  }
  
  /**
   * Show update notification
   */
  function showUpdateNotification() {
    // Create notification element
    const notification = document.createElement('div');
    notification.className = 'pwa-update-notification';
    notification.innerHTML = `
      <span>En ny version av TheHUB finns tillgänglig</span>
      <button onclick="HubPWA.applyUpdate()">Uppdatera</button>
      <button onclick="this.parentElement.remove()" aria-label="Stäng">✕</button>
    `;
    document.body.appendChild(notification);
    
    // Animate in
    requestAnimationFrame(() => {
      notification.classList.add('visible');
    });
  }
  
  /**
   * Apply service worker update
   */
  function applyUpdate() {
    if (swRegistration && swRegistration.waiting) {
      swRegistration.waiting.postMessage('skipWaiting');
    }
    window.location.reload();
  }
  
  /**
   * Check if app is installed (running as PWA)
   */
  function isInstalled() {
    // Check display mode
    if (window.matchMedia('(display-mode: standalone)').matches) {
      return true;
    }
    
    // Check iOS standalone
    if (window.navigator.standalone === true) {
      return true;
    }
    
    // Check if launched from home screen on Android
    if (document.referrer.includes('android-app://')) {
      return true;
    }
    
    return false;
  }
  
  /**
   * Get install instructions for current platform
   */
  function getInstallInstructions() {
    const ua = navigator.userAgent;
    
    if (/iPhone|iPad|iPod/.test(ua)) {
      return {
        platform: 'iOS',
        steps: [
          'Tryck på dela-knappen (□↑) i Safari',
          'Scrolla ner och tryck "Lägg till på hemskärmen"',
          'Tryck "Lägg till"'
        ]
      };
    }
    
    if (/Android/.test(ua)) {
      return {
        platform: 'Android',
        steps: [
          'Tryck på menyn (⋮) i Chrome',
          'Välj "Lägg till på startskärmen"',
          'Tryck "Lägg till"'
        ]
      };
    }
    
    return {
      platform: 'Desktop',
      steps: [
        'Klicka på installera-ikonen i adressfältet',
        'Eller använd webbläsarmenyn → "Installera app"'
      ]
    };
  }
  
  /**
   * Show iOS install instructions modal
   */
  function showIOSInstallGuide() {
    const modal = document.createElement('div');
    modal.className = 'pwa-ios-modal';
    modal.innerHTML = `
      <div class="pwa-ios-modal-content">
        <button class="pwa-ios-modal-close" onclick="this.parentElement.parentElement.remove()">✕</button>
        <div class="pwa-ios-modal-icon">📲</div>
        <h2>Installera TheHUB</h2>
        <p>Lägg till appen på din hemskärm för bästa upplevelse:</p>
        <ol>
          <li>Tryck på <span class="pwa-ios-share-icon">□↑</span> i Safari</li>
          <li>Scrolla och tryck <strong>"Lägg till på hemskärmen"</strong></li>
          <li>Tryck <strong>"Lägg till"</strong></li>
        </ol>
        <button class="pwa-ios-modal-btn" onclick="this.parentElement.parentElement.remove()">Jag förstår</button>
      </div>
    `;
    document.body.appendChild(modal);
    
    // Animate in
    requestAnimationFrame(() => {
      modal.classList.add('visible');
    });
  }
  
  /**
   * Initialize PWA functionality
   */
  function init() {
    // Register service worker
    registerServiceWorker();
    
    // Listen for install prompt (Chrome, Edge, Samsung Internet)
    window.addEventListener('beforeinstallprompt', handleInstallPrompt);
    
    // Listen for successful install
    window.addEventListener('appinstalled', () => {
      console.log('[PWA] App installed successfully');
      hideInstallButton();
      deferredPrompt = null;
    });
    
    // Handle service worker updates on page load
    if (navigator.serviceWorker) {
      navigator.serviceWorker.addEventListener('controllerchange', () => {
        window.location.reload();
      });
    }
    
    // Add installed class to body if running as PWA
    if (isInstalled()) {
      document.body.classList.add('pwa-installed');
      console.log('[PWA] Running as installed app');
    }
    
    // Bind install button click
    document.addEventListener('click', (e) => {
      const installBtn = e.target.closest('[data-pwa-install]');
      if (installBtn) {
        // iOS needs special handling
        if (/iPhone|iPad|iPod/.test(navigator.userAgent) && !window.navigator.standalone) {
          showIOSInstallGuide();
        } else if (deferredPrompt) {
          promptInstall();
        }
      }
    });
  }
  
  // Public API
  return {
    init,
    promptInstall,
    isInstalled,
    getInstallInstructions,
    showIOSInstallGuide,
    applyUpdate
  };
})();

// Initialize on DOM ready
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', HubPWA.init);
} else {
  HubPWA.init();
}
```

---

## 6. CREATE PWA CSS

**File: /v3/assets/css/pwa.css**

```css
/**
 * TheHUB V3 - PWA Styles
 * Styles for PWA-specific UI elements
 */

/* ========== SAFE AREA HANDLING (Notch, etc) ========== */
@supports (padding-top: env(safe-area-inset-top)) {
  .header {
    padding-top: env(safe-area-inset-top);
    height: calc(var(--header-height) + env(safe-area-inset-top));
  }
  
  .mobile-nav {
    padding-bottom: env(safe-area-inset-bottom);
    height: calc(var(--mobile-nav-height) + env(safe-area-inset-bottom));
  }
  
  .main-content {
    padding-left: max(var(--space-lg), env(safe-area-inset-left));
    padding-right: max(var(--space-lg), env(safe-area-inset-right));
  }
}

/* ========== PWA INSTALLED STATE ========== */
body.pwa-installed .header-install,
body.pwa-installed .pwa-install-prompt {
  display: none !important;
}

/* Hide browser-specific UI hints when installed */
body.pwa-installed .browser-hint {
  display: none;
}

/* ========== INSTALL BUTTON ========== */
.pwa-install-btn {
  display: inline-flex;
  align-items: center;
  gap: var(--space-xs);
  padding: var(--space-xs) var(--space-md);
  background: var(--color-accent);
  color: var(--color-text-inverse);
  border: none;
  border-radius: var(--radius-full);
  font-size: var(--text-sm);
  font-weight: var(--weight-medium);
  cursor: pointer;
  transition: all var(--transition-fast);
  white-space: nowrap;
}

.pwa-install-btn:hover {
  background: var(--color-accent-hover);
  transform: scale(1.02);
}

.pwa-install-btn.hidden {
  display: none;
}

.pwa-install-icon {
  font-size: var(--text-md);
}

/* ========== UPDATE NOTIFICATION ========== */
.pwa-update-notification {
  position: fixed;
  bottom: calc(var(--mobile-nav-height) + var(--space-md) + env(safe-area-inset-bottom, 0px));
  left: var(--space-md);
  right: var(--space-md);
  background: var(--color-bg-elevated);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-lg);
  padding: var(--space-md);
  display: flex;
  align-items: center;
  gap: var(--space-md);
  box-shadow: var(--shadow-lg);
  z-index: var(--z-toast);
  transform: translateY(120%);
  transition: transform var(--transition-base);
}

.pwa-update-notification.visible {
  transform: translateY(0);
}

.pwa-update-notification span {
  flex: 1;
  font-size: var(--text-sm);
}

.pwa-update-notification button {
  padding: var(--space-xs) var(--space-sm);
  border-radius: var(--radius-md);
  font-size: var(--text-sm);
  font-weight: var(--weight-medium);
  transition: all var(--transition-fast);
}

.pwa-update-notification button:first-of-type {
  background: var(--color-accent);
  color: var(--color-text-inverse);
}

.pwa-update-notification button:last-of-type {
  background: transparent;
  color: var(--color-text-secondary);
}

@media (min-width: 600px) {
  .pwa-update-notification {
    left: auto;
    right: var(--space-lg);
    max-width: 400px;
  }
}

/* ========== iOS INSTALL MODAL ========== */
.pwa-ios-modal {
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.6);
  backdrop-filter: blur(4px);
  display: flex;
  align-items: flex-end;
  justify-content: center;
  z-index: var(--z-modal);
  opacity: 0;
  transition: opacity var(--transition-base);
}

.pwa-ios-modal.visible {
  opacity: 1;
}

.pwa-ios-modal-content {
  background: var(--color-bg-surface);
  border-radius: var(--radius-xl) var(--radius-xl) 0 0;
  padding: var(--space-xl);
  padding-bottom: calc(var(--space-xl) + env(safe-area-inset-bottom, 0px));
  width: 100%;
  max-width: 400px;
  text-align: center;
  transform: translateY(100%);
  transition: transform var(--transition-base);
}

.pwa-ios-modal.visible .pwa-ios-modal-content {
  transform: translateY(0);
}

.pwa-ios-modal-close {
  position: absolute;
  top: var(--space-md);
  right: var(--space-md);
  width: 32px;
  height: 32px;
  display: flex;
  align-items: center;
  justify-content: center;
  background: var(--color-bg-sunken);
  border-radius: var(--radius-full);
  color: var(--color-text-secondary);
  font-size: var(--text-sm);
}

.pwa-ios-modal-icon {
  font-size: 3rem;
  margin-bottom: var(--space-md);
}

.pwa-ios-modal h2 {
  font-size: var(--text-xl);
  font-weight: var(--weight-semibold);
  margin-bottom: var(--space-sm);
}

.pwa-ios-modal p {
  color: var(--color-text-secondary);
  font-size: var(--text-sm);
  margin-bottom: var(--space-lg);
}

.pwa-ios-modal ol {
  text-align: left;
  padding-left: var(--space-lg);
  margin-bottom: var(--space-xl);
}

.pwa-ios-modal li {
  color: var(--color-text-secondary);
  font-size: var(--text-sm);
  margin-bottom: var(--space-sm);
  line-height: 1.6;
}

.pwa-ios-share-icon {
  display: inline-block;
  padding: 2px 6px;
  background: var(--color-accent-light);
  color: var(--color-accent-text);
  border-radius: var(--radius-sm);
  font-size: var(--text-md);
}

.pwa-ios-modal-btn {
  width: 100%;
  padding: var(--space-md);
  background: var(--color-accent);
  color: var(--color-text-inverse);
  border-radius: var(--radius-md);
  font-size: var(--text-md);
  font-weight: var(--weight-medium);
}

/* ========== INSTALL PROMPT BANNER ========== */
.pwa-install-prompt {
  background: linear-gradient(135deg, var(--color-accent) 0%, var(--color-accent-hover) 100%);
  color: white;
  padding: var(--space-md) var(--space-lg);
  display: flex;
  align-items: center;
  gap: var(--space-md);
}

.pwa-install-prompt-icon {
  font-size: var(--text-2xl);
}

.pwa-install-prompt-text {
  flex: 1;
}

.pwa-install-prompt-text strong {
  display: block;
  font-weight: var(--weight-semibold);
}

.pwa-install-prompt-text span {
  font-size: var(--text-sm);
  opacity: 0.9;
}

.pwa-install-prompt-btn {
  padding: var(--space-sm) var(--space-md);
  background: rgba(255, 255, 255, 0.2);
  border-radius: var(--radius-md);
  font-weight: var(--weight-medium);
  transition: background var(--transition-fast);
}

.pwa-install-prompt-btn:hover {
  background: rgba(255, 255, 255, 0.3);
}

.pwa-install-prompt-close {
  padding: var(--space-xs);
  opacity: 0.7;
  transition: opacity var(--transition-fast);
}

.pwa-install-prompt-close:hover {
  opacity: 1;
}

/* ========== STANDALONE MODE ADJUSTMENTS ========== */
@media (display-mode: standalone) {
  /* Extra padding for notch/status bar when in app mode */
  .header {
    padding-top: max(var(--space-sm), env(safe-area-inset-top));
  }
  
  /* Hide install prompts */
  .pwa-install-prompt,
  .pwa-install-btn,
  .header-install {
    display: none !important;
  }
}

/* iOS standalone mode */
@media (display-mode: standalone), (display-mode: fullscreen) {
  html {
    /* Prevent overscroll bounce on iOS */
    overscroll-behavior: none;
  }
  
  body {
    /* Prevent pull-to-refresh */
    overscroll-behavior-y: contain;
  }
}
```

---

## 7. CREATE APP ICONS

Create the following directory and generate icons:

**Directory: /v3/assets/icons/**

You need to create PNG icons in these sizes. Use your TheHUB logo.

```
icon-72.png    (72x72)
icon-96.png    (96x96)
icon-128.png   (128x128)
icon-144.png   (144x144)
icon-152.png   (152x152)
icon-167.png   (167x167) - iPad Pro
icon-180.png   (180x180) - iPhone
icon-192.png   (192x192)
icon-384.png   (384x384)
icon-512.png   (512x512)
icon-maskable-192.png  (192x192, with safe zone padding)
icon-maskable-512.png  (512x512, with safe zone padding)
favicon-16.png (16x16)
favicon-32.png (32x32)
shortcut-results.png (192x192)
shortcut-series.png  (192x192)
```

**For now, create a simple placeholder icon generator script:**

**File: /v3/generate-icons.php** (run once to generate placeholder icons)

```php
<?php
/**
 * Placeholder icon generator
 * Run this once to create basic icons, then replace with real branding
 */

$sizes = [16, 32, 72, 96, 128, 144, 152, 167, 180, 192, 384, 512];
$iconDir = __DIR__ . '/assets/icons';

if (!is_dir($iconDir)) {
    mkdir($iconDir, 0755, true);
}

foreach ($sizes as $size) {
    $img = imagecreatetruecolor($size, $size);
    
    // Background color (TheHUB blue)
    $bg = imagecolorallocate($img, 0, 74, 152);
    imagefill($img, 0, 0, $bg);
    
    // White text
    $white = imagecolorallocate($img, 255, 255, 255);
    
    // Add "HUB" text
    $fontSize = max(1, floor($size / 4));
    $text = 'HUB';
    
    // Center the text (approximate)
    $x = $size * 0.25;
    $y = $size * 0.65;
    
    imagestring($img, min(5, $fontSize), $x, $y, $text, $white);
    
    // Save regular icon
    imagepng($img, "$iconDir/icon-$size.png");
    
    // Save maskable version (with padding) for 192 and 512
    if ($size === 192 || $size === 512) {
        $maskable = imagecreatetruecolor($size, $size);
        imagefill($maskable, 0, 0, $bg);
        
        // Add padding (10% on each side for safe zone)
        $padding = $size * 0.1;
        $innerSize = $size - ($padding * 2);
        
        imagecopyresampled(
            $maskable, $img,
            $padding, $padding, 0, 0,
            $innerSize, $innerSize, $size, $size
        );
        
        imagepng($maskable, "$iconDir/icon-maskable-$size.png");
        imagedestroy($maskable);
    }
    
    imagedestroy($img);
}

// Create favicon copies
copy("$iconDir/icon-16.png", "$iconDir/favicon-16.png");
copy("$iconDir/icon-32.png", "$iconDir/favicon-32.png");

// Create shortcut icons
copy("$iconDir/icon-192.png", "$iconDir/shortcut-results.png");
copy("$iconDir/icon-192.png", "$iconDir/shortcut-series.png");

echo "Icons generated in $iconDir\n";
echo "Replace these with properly designed icons!\n";
```

---

## 8. CREATE SPLASH SCREENS DIRECTORY

**Directory: /v3/assets/splash/**

iOS splash screens are optional but recommended. Create placeholder files or skip for now.

The sizes needed are:
- splash-750x1334.png (iPhone SE)
- splash-1170x2532.png (iPhone 13/14)
- splash-1179x2556.png (iPhone 14 Pro)
- splash-1290x2796.png (iPhone 14 Pro Max)
- splash-2048x2732.png (iPad Pro 12.9")

For now, you can create simple colored images or skip the splash screen links in head.php.

---

## 9. UPDATE INDEX.PHP TO INCLUDE PWA SCRIPT

**Update /v3/index.php** - Add pwa.js to the scripts section:

Find this section:
```php
<script src="<?= hub_asset('js/theme.js') ?>"></script>
<script src="<?= hub_asset('js/router.js') ?>"></script>
<script src="<?= hub_asset('js/app.js') ?>"></script>
```

Add after:
```php
<script src="<?= hub_asset('js/pwa.js') ?>"></script>
```

---

## 10. ADD INSTALL BUTTON TO HEADER

**Update /v3/components/header.php:**

```php
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
      <span class="hide-mobile">Installera app</span>
    </button>
    <span class="header-version">V3.0</span>
  </div>
</header>
```

---

## 11. VERIFICATION CHECKLIST

After implementing, verify PWA functionality:

### Desktop (Chrome/Edge)
1. Visit `/v3/`
2. Look for install icon in address bar
3. Click to install
4. App should open in standalone window

### Android (Chrome)
1. Visit `/v3/`
2. Wait for install banner or use menu → "Install app"
3. App appears on home screen
4. Opens without browser chrome

### iOS (Safari)
1. Visit `/v3/`
2. Tap Share button (□↑)
3. Tap "Add to Home Screen"
4. Opens in fullscreen without Safari UI

### Verify Service Worker
1. Open DevTools → Application → Service Workers
2. Should show `sw.js` as active
3. Check Cache Storage for cached assets

### Test Offline
1. Install the PWA
2. Turn on airplane mode
3. Open the app
4. Should show cached content or offline page

---

## 12. LIGHTHOUSE PWA AUDIT

Run Lighthouse in Chrome DevTools to verify PWA score:

1. Open DevTools (F12)
2. Go to Lighthouse tab
3. Check "Progressive Web App"
4. Click "Analyze page load"

Target: 100% PWA score

Common issues to fix:
- Missing icons → ensure all sizes exist
- No HTTPS → required for service workers (should work on your domain)
- Missing manifest → verify manifest.json loads
- No offline support → verify sw.js is registered

---

## SUMMARY

Files to create:
```
/v3/manifest.json           - Web app manifest
/v3/sw.js                   - Service worker
/v3/offline.html            - Offline fallback page
/v3/generate-icons.php      - Icon generator (run once)
/v3/assets/css/pwa.css      - PWA-specific styles
/v3/assets/js/pwa.js        - PWA JavaScript
/v3/assets/icons/           - App icons (generated)
/v3/assets/splash/          - iOS splash screens (optional)
```

Files to update:
```
/v3/components/head.php     - Add PWA meta tags
/v3/components/header.php   - Add install button
/v3/index.php               - Include pwa.js
```

After implementation, users can install TheHUB on their phones and use it like a native app – no browser chrome, fullscreen experience, works offline!
