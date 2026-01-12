# TheHUB - Roadmap & Utvecklingsregler

## Versionspolicy

### INGA VERSIONSPREFIX

**Använd ALDRIG versionsnummer (V2, V3, V4) i filnamn, konstanter eller referenser.**

Detta projekt har EN version. Historiskt fanns V2 och V3 men dessa slogs samman januari 2026.

#### Förbjudet:
```php
HUB_V2_ROOT          // ALDRIG
HUB_V3_ROOT          // ALDRIG
HUB_V3_URL           // ALDRIG
include 'v2/file.php'; // ALDRIG
```

#### Korrekt:
```php
HUB_ROOT             // Projektets rotmapp
HUB_URL              // Projektets bas-URL
ROOT_PATH            // Alias för HUB_ROOT
INCLUDES_PATH        // /includes mappen
```

---

## Slutförda milstolpar

### 2026-01-12: Versionskonsolidering
- [x] Alla V2/V3/V4-konstanter borttagna
- [x] 30+ filer uppdaterade till HUB_ROOT/HUB_URL
- [x] Backward compatibility aliases borttagna från hub-config.php

### 2026-01-11: Serie-registreringssystem
- [x] Migration 110: series_registrations tabell
- [x] Migration 110: series_registration_events tabell
- [x] Promotor serie-inställningar (promotor-series.php)

### 2026-01-10: Prissystem
- [x] Migration 101: pricing_templates tabell
- [x] Migration 101: pricing_template_rules tabell
- [x] Migration 101: event_pricing_rules tabell

### 2026-01-09: Promotor-system
- [x] promotor_series tabell för serie-tilldelning
- [x] promotor_events tabell för event-tilldelning
- [x] user-events.php för tilldelningshantering

---

## Pågående arbete

### Ekonomi-modul
- [ ] Serie-anmälan (season pass) frontend
- [ ] Swish-integration per serie
- [ ] Early bird-prissättning

### Admin-förbättringar
- [ ] Förbättrad promotor-dashboard
- [ ] Bulk-operationer för events

---

## Tekniska regler

### Konstanter som ska användas
| Konstant | Värde | Användning |
|----------|-------|------------|
| `HUB_ROOT` | `__DIR__` | Alla filsökvägar |
| `HUB_URL` | `''` | Alla URL:er |
| `ROOT_PATH` | `__DIR__` | Legacy alias |
| `INCLUDES_PATH` | `__DIR__ . '/includes'` | Include-sökvägar |

### Filer som ALDRIG ska innehålla versionsprefix
- Alla PHP-filer
- Alla JavaScript-filer
- Alla konfigurationsfiler
- Dokumentation (utom historisk referens)

---

## Kontakt

Se CLAUDE.md för fullständiga utvecklingsregler.
