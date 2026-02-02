# TheHUB Databas-schema

> **REFERERA ALLTID till detta dokument innan du skriver kod som interagerar med databasen!**

---

## Namnkonventioner

### Kolumnnamn
- **INGA understreck** i sammansatta ord: `firstname` (INTE `first_name`)
- **Undantag**: FK-relationer och timestamps: `series_id`, `created_at`, `updated_at`
- **Boolean-kolumner**: Använd `active`, `enabled`, `is_default` (INTE `is_active`)

### Tabellnamn
- Plural form: `riders`, `events`, `series`, `orders`
- Snake_case med understreck: `series_events`, `payment_recipients`

---

## Kärntabeller

### riders (deltagare)
```sql
id INT PRIMARY KEY AUTO_INCREMENT,
firstname VARCHAR(100),
lastname VARCHAR(100),
birth_year INT,
gender ENUM('M', 'F'),
nationality VARCHAR(3),
active TINYINT(1) DEFAULT 1,
club_id INT,                       -- FK -> clubs.id
license_number VARCHAR(20),        -- UCI ID (t.ex. "10012345678")
license_type VARCHAR(50),
license_category VARCHAR(50),
license_year INT,
license_valid_until DATE,
discipline VARCHAR(50),
district VARCHAR(100),
first_season INT,
experience_level VARCHAR(50),
stats_total_starts INT DEFAULT 0,
stats_total_finished INT DEFAULT 0,
stats_total_wins INT DEFAULT 0,
stats_total_podiums INT DEFAULT 0,
stats_total_points INT DEFAULT 0,
created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
```

### events (tävlingar)
```sql
id INT PRIMARY KEY AUTO_INCREMENT,
name VARCHAR(200),
date DATE,
location VARCHAR(200),
venue_id INT,                      -- FK -> venues.id
series_id INT,                     -- FK -> series.id (legacy, använd series_events istället)
discipline VARCHAR(50),
event_level VARCHAR(50),           -- 'national', 'sportmotion', 'motion'
event_format VARCHAR(50),
active TINYINT(1) DEFAULT 1,
is_championship TINYINT(1) DEFAULT 0,
organizer_club_id INT,
stage_names TEXT,                  -- JSON array
pricing_template_id INT,
payment_recipient_id INT,          -- FK -> payment_recipients.id
registration_opens DATETIME,
registration_deadline DATETIME,
created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
```

### series (serier)
```sql
id INT PRIMARY KEY AUTO_INCREMENT,
name VARCHAR(200),
year INT,
type VARCHAR(50),                  -- 'Enduro', 'DH', 'XC', etc.
format VARCHAR(50) DEFAULT 'Championship',
status ENUM('planning', 'active', 'completed', 'cancelled'),
start_date DATE,
end_date DATE,
description TEXT,
organizer VARCHAR(200),
logo VARCHAR(255),
brand_id INT,                      -- FK -> series_brands.id
count_best_results INT,            -- NULL = räkna alla
registration_enabled TINYINT(1) DEFAULT 0,
pricing_template_id INT,
payment_recipient_id INT,          -- FK -> payment_recipients.id
gravity_id_discount DECIMAL(10,2) DEFAULT 0,
swish_number VARCHAR(20),          -- Legacy
swish_name VARCHAR(100),           -- Legacy
event_license_class VARCHAR(50),   -- 'national', 'sportmotion', 'motion'
created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
```

### series_events (junction table: serier <-> events)
```sql
id INT PRIMARY KEY AUTO_INCREMENT,
series_id INT NOT NULL,            -- FK -> series.id
event_id INT NOT NULL,             -- FK -> events.id
template_id INT,                   -- FK -> point_scales.id
sort_order INT DEFAULT 0,
UNIQUE KEY unique_series_event (series_id, event_id)
```
> **VIKTIGT**: Events kopplas till serier via denna tabell (many-to-many), INTE via `events.series_id`!

### results (resultat)
```sql
id INT PRIMARY KEY AUTO_INCREMENT,
event_id INT NOT NULL,             -- FK -> events.id
cyclist_id INT NOT NULL,           -- FK -> riders.id
class_id INT,                      -- FK -> classes.id
position INT,
finish_time VARCHAR(50),
status ENUM('finished', 'dnf', 'dns', 'dsq'),
bib_number VARCHAR(20),
points INT DEFAULT 0,
ss1 VARCHAR(20), ss2 VARCHAR(20), ..., ss15 VARCHAR(20),  -- Split times
created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
```

### clubs (klubbar)
```sql
id INT PRIMARY KEY AUTO_INCREMENT,
name VARCHAR(200),
city VARCHAR(100),
country VARCHAR(3) DEFAULT 'SWE',
active TINYINT(1) DEFAULT 1,
created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
```

### classes (tävlingsklasser)
```sql
id INT PRIMARY KEY AUTO_INCREMENT,
name VARCHAR(100),
display_name VARCHAR(100),
sort_order INT DEFAULT 0,
series_eligible TINYINT(1) DEFAULT 1,
awards_points TINYINT(1) DEFAULT 1,
active TINYINT(1) DEFAULT 1
```

---

## Betalningssystemet

### payment_recipients (betalningsmottagare)
```sql
id INT PRIMARY KEY AUTO_INCREMENT,
name VARCHAR(100) NOT NULL,
description VARCHAR(255),
swish_number VARCHAR(20),
swish_name VARCHAR(100),
gateway_type ENUM('swish', 'stripe', 'bank', 'manual') DEFAULT 'swish',
bankgiro VARCHAR(20),
plusgiro VARCHAR(20),
bank_account VARCHAR(30),
bank_name VARCHAR(50),
bank_clearing VARCHAR(10),
stripe_account_id VARCHAR(100),    -- Stripe Connect account ID
stripe_account_status ENUM('pending', 'active', 'restricted', 'disabled'),
contact_email VARCHAR(100),
contact_phone VARCHAR(20),
org_number VARCHAR(20),
active TINYINT(1) DEFAULT 1,       -- OBS: "active", INTE "is_active"!
created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
```

### orders (beställningar)
```sql
id INT PRIMARY KEY AUTO_INCREMENT,
order_number VARCHAR(50) UNIQUE,
user_id INT,
email VARCHAR(255),
total_amount DECIMAL(10,2),
vat_amount DECIMAL(10,2),
currency VARCHAR(3) DEFAULT 'SEK',
payment_status ENUM('pending', 'paid', 'failed', 'refunded', 'partial_refund'),
payment_method VARCHAR(50),
gateway_code VARCHAR(50),          -- 'stripe', 'swish', etc.
gateway_transaction_id VARCHAR(100),
paid_at DATETIME,
refunded_at DATETIME,
refunded_amount DECIMAL(10,2) DEFAULT 0.00,
callback_received_at DATETIME,
metadata JSON,
created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
```

### order_items (orderrader)
```sql
id INT PRIMARY KEY AUTO_INCREMENT,
order_id INT NOT NULL,             -- FK -> orders.id
item_type VARCHAR(50),             -- 'event_registration', 'membership', etc.
item_id INT,                       -- Event ID, membership ID, etc.
description VARCHAR(255),
quantity INT DEFAULT 1,
unit_price DECIMAL(10,2),
total_price DECIMAL(10,2),
vat_rate DECIMAL(5,2) DEFAULT 6.00,
vat_amount DECIMAL(10,2),
seller_id INT,                     -- FK -> payment_recipients.id
metadata JSON,
created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
```

### order_transfers (överföringar till säljare)
```sql
id INT PRIMARY KEY AUTO_INCREMENT,
order_id INT NOT NULL,             -- FK -> orders.id
order_item_id INT,                 -- FK -> order_items.id
recipient_id INT NOT NULL,         -- FK -> payment_recipients.id
stripe_account_id VARCHAR(100),
amount DECIMAL(10,2) NOT NULL,
currency VARCHAR(3) DEFAULT 'SEK',
status ENUM('pending', 'processing', 'completed', 'failed') DEFAULT 'pending',
stripe_transfer_id VARCHAR(100),
reversed TINYINT(1) DEFAULT 0,
reversed_amount DECIMAL(10,2) DEFAULT 0.00,
reversed_at DATETIME,
error_message TEXT,
created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
completed_at DATETIME
```

### order_refunds (återbetalningar)
```sql
id INT PRIMARY KEY AUTO_INCREMENT,
order_id INT NOT NULL,
amount DECIMAL(10,2) NOT NULL,
refund_type ENUM('full', 'partial') DEFAULT 'full',
reason TEXT,
admin_id INT,
stripe_refund_id VARCHAR(100),
status ENUM('pending', 'processing', 'completed', 'partial_completed', 'failed') DEFAULT 'pending',
transfer_reversals_completed TINYINT(1) DEFAULT 0,
error_message TEXT,
created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
processed_at DATETIME,
completed_at DATETIME
```

---

## Anmälningssystemet

### event_registrations (eventanmälningar)
```sql
id INT PRIMARY KEY AUTO_INCREMENT,
event_id INT NOT NULL,             -- FK -> events.id
rider_id INT NOT NULL,             -- FK -> riders.id
class_id INT,                      -- FK -> classes.id
order_id INT,                      -- FK -> orders.id
status ENUM('pending', 'confirmed', 'cancelled'),
payment_status ENUM('pending', 'paid', 'refunded'),
registration_date DATETIME,
bib_number VARCHAR(20),
team_name VARCHAR(100),
emergency_contact VARCHAR(200),
metadata JSON,
created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
```

### pricing_templates (prismallar)
```sql
id INT PRIMARY KEY AUTO_INCREMENT,
name VARCHAR(100),
description TEXT,
is_default TINYINT(1) DEFAULT 0,
active TINYINT(1) DEFAULT 1,
created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
```

### pricing_items (prisrader i mall)
```sql
id INT PRIMARY KEY AUTO_INCREMENT,
template_id INT NOT NULL,          -- FK -> pricing_templates.id
class_id INT,                      -- FK -> classes.id (NULL = alla klasser)
price DECIMAL(10,2) NOT NULL,
vat_rate DECIMAL(5,2) DEFAULT 6.00,
early_bird_price DECIMAL(10,2),
early_bird_until DATE,
late_price DECIMAL(10,2),
late_from DATE,
gravity_id_discount DECIMAL(10,2) DEFAULT 0,
active TINYINT(1) DEFAULT 1
```

---

## Poängsystem

### point_scales (poängmallar)
```sql
id INT PRIMARY KEY AUTO_INCREMENT,
name VARCHAR(100),
description TEXT,
active TINYINT(1) DEFAULT 1,
created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
```

### point_scale_values (poängvärden per placering)
```sql
id INT PRIMARY KEY AUTO_INCREMENT,
scale_id INT NOT NULL,             -- FK -> point_scales.id
position INT NOT NULL,             -- 1 = vinnare, 2 = tvåa, etc.
points INT NOT NULL,
UNIQUE KEY unique_scale_position (scale_id, position)
```

### series_results (seriepoäng per event)
```sql
id INT PRIMARY KEY AUTO_INCREMENT,
series_id INT NOT NULL,            -- FK -> series.id
event_id INT NOT NULL,             -- FK -> events.id
cyclist_id INT NOT NULL,           -- FK -> riders.id
class_id INT,                      -- FK -> classes.id
position INT,
points INT DEFAULT 0,
run_1_points INT,                  -- För DH med flera åk
run_2_points INT,
created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
UNIQUE KEY unique_series_event_rider (series_id, event_id, cyclist_id)
```

---

## Användare & behörigheter

### admins (administratörer)
```sql
id INT PRIMARY KEY AUTO_INCREMENT,
username VARCHAR(50) UNIQUE,
email VARCHAR(255) UNIQUE,
password_hash VARCHAR(255),
name VARCHAR(100),
roles JSON,                        -- ['admin', 'promotor', 'moderator']
active TINYINT(1) DEFAULT 1,
last_login DATETIME,
created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
```

### promotor_series (promotor-tilldelningar)
```sql
id INT PRIMARY KEY AUTO_INCREMENT,
user_id INT NOT NULL,              -- FK -> admins.id
series_id INT NOT NULL,            -- FK -> series.id
role VARCHAR(50) DEFAULT 'manager',
created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
UNIQUE KEY unique_user_series (user_id, series_id)
```

---

## Vanliga misstag att undvika

### 1. Kolumnnamn: `active` vs `is_active`
```php
// FEL!
WHERE is_active = 1

// RÄTT!
WHERE active = 1
```

### 2. Serie-event koppling
```php
// FEL! - Missar events som bara är i series_events
JOIN events e ON e.series_id = ?

// RÄTT! - Använd junction table
JOIN series_events se ON se.series_id = ?
JOIN events e ON se.event_id = e.id
```

### 3. Räkna events i serie
```php
// FEL! - Räknar bort-tagna events
SELECT COUNT(*) FROM series_events WHERE series_id = ?

// RÄTT! - Endast existerande events
SELECT COUNT(*) FROM series_events se
INNER JOIN events e ON se.event_id = e.id
WHERE se.series_id = ?
```

---

## Relationsdiagram (förenklat)

```
series_brands
    │
    └── series
         │
         ├── series_events ─────┬── events ─── event_registrations
         │                      │       │
         │                      │       └── results
         │                      │
         └── payment_recipients ┴── orders ─── order_items
                                       │
                                       └── order_transfers
```

---

## Uppdateringslogg

| Datum | Ändring |
|-------|---------|
| 2026-02-01 | Initial version med kärntabeller |

