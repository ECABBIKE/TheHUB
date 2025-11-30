TheHUB V4 - Backend clean pack

Struktur:
backend/
  index.php
  core/
    config.php
    Database.php
    Controller.php
    Router.php
  modules/
    riders/
      RiderModel.php
      RiderController.php
    events/
      EventModel.php
      EventController.php
  public/
    api/
      riders.php
      rider.php
      events.php
      event.php

Installation:
1. Ladda upp mappen "backend" till /public_html/thehub/thehub-v4/ och skriv över befintlig backend-mapp.
2. Kontrollera att databasen stämmer i core/config.php (DB_HOST, DB_NAME, DB_USER, DB_PASS).
3. Testa:
   - https://.../thehub-v4/backend/public/api/riders.php
   - https://.../thehub-v4/backend/public/api/events.php
   De ska returnera JSON.
