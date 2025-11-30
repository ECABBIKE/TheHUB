TheHUB V4 – NeoGlass Dark UI (Dashboard start)

Detta paket innehåller endast frontend-filer:

- index.php              : huvudlayout med sidebar, dashboard, resultat, riders m.m.
- assets/css/app.css     : komplett mörkt tema (NeoGlass-stil)
- assets/js/app.js       : navigation + API-koppling till backend

Installation:
1. Ladda upp innehållet i denna mapp till /public_html/thehub-v4/ på servern.
   - index.php skrivs över
   - assets/css/app.css skrivs över
   - assets/js/app.js skrivs över
   Backend-mappen påverkas inte.

2. Öppna https://thehub.gravityseries.se/thehub-v4/ i webbläsaren.
3. Gör hård reload (Cmd/Ctrl + Shift + R).

Förutsätter att backend-API:erna redan finns:
- /thehub-v4/backend/public/api/riders.php  (returnerar { ok: true, data: [...] })
- /thehub-v4/backend/public/api/events.php  (returnerar { ok: true, data: [...] })
