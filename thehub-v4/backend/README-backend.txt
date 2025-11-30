TheHUB V4 - Backend FULL pack (skeleton)

Lägg denna mapp som /thehub-v4/backend/ på servern.

API:
  /thehub-v4/backend/public/api/riders.php
  /thehub-v4/backend/public/api/events.php
  /thehub-v4/backend/public/api/event.php?id=ID
  /thehub-v4/backend/public/api/results.php?event_id=ID
  /thehub-v4/backend/public/api/ranking.php?series=capital|gotland|...

OBS:
  - ResultsModel och RankingEngine är generiska och måste mappas mot dina riktiga resultat-/poängtabeller.
  - Du kan tweaka SQL direkt i de modellerna utan att röra frontend.
