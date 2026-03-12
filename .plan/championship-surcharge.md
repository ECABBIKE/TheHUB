# SM-tilläggsavgift (Championship Surcharge)

## Koncept

En fast tilläggsavgift på SM-event som:
- Läggs på ALLA prisperioder (early bird, normal, sen anmälan)
- Aldrig rabatteras vid serieanmälan
- Alltid tillfaller SM-eventets betalningsmottagare (aldrig proportionellt delad)

## Exempel

Klass "Herrar Elit", base_price = 600 kr, SM-tillägg = 100 kr:
- Early bird (15% rabatt): 510 + 100 = **610 kr**
- Normal: 600 + 100 = **700 kr**
- Sen anmälan (25% tillägg): 750 + 100 = **850 kr**

Serie med 4 event (varav 1 SM), 15% serierabatt:
- Vanliga 3 event: 600 × 3 = 1800 kr
- SM event base: 600 kr
- Serierabatt: (600 × 4) × 15% = -360 kr
- SM-tillägg: +100 kr (ingen rabatt)
- **Totalt: 2400 - 360 + 100 = 2140 kr**

Avräkning:
- 3 vanliga event: 510 kr vardera (proportionellt)
- SM-event: 510 kr (proportionellt) + 100 kr (tillägg) = 610 kr

## Databasändring

```sql
-- Migration 097
ALTER TABLE events
    ADD COLUMN championship_surcharge DECIMAL(10,2) NULL DEFAULT NULL
    AFTER is_championship;
```

- NULL = inget tillägg (default, alla befintliga event)
- 0 = explicit inget tillägg
- 100 = 100 kr tillägg

## Ändringar per fil

### 1. admin/event-edit.php
- Nytt fält "SM-tillägg (kr)" bredvid is_championship-checkboxen
- Visas/döljs baserat på is_championship
- Sparas i events-tabellen

### 2. includes/order-manager.php — getEligibleClassesForEvent()
- Efter prisberäkning (early bird/normal/sen): lägg till championship_surcharge
- `$currentPrice += $championshipSurcharge`
- Returnera surcharge separat så frontend kan visa "600 kr + 100 kr SM-avgift"

### 3. includes/order-manager.php — getEligibleClassesForSeries()
- Beräkna seriepris som vanligt (summa base_price × (1 - rabatt%))
- Lägg till championship_surcharge UTANFÖR rabatten
- Returnera surcharge-info per event så frontend visar det

### 4. includes/order-manager.php — createMultiRiderOrder()
- Vid event-registrering: spara surcharge som del av priset
- Vid serie-registrering: spara surcharge separat (ej rabatterat)

### 5. includes/economy-helpers.php — explodeSeriesOrdersToEvents()
- Vid split: dra av surcharge från orderbeloppet FÖRST
- Fördela resten proportionellt
- Lägg till surcharge odelat på SM-eventet

### 6. pages/event.php
- Visa prisinfo: "700 kr (inkl. 100 kr SM-avgift)"
- Serieanmälan: visa att SM-avgiften inte rabatteras

### 7. admin/series-manage.php (registration tab)
- Visa SM-tillägg per event i anmälningsinställningar (read-only info)
