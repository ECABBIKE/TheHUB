-- Migration 012: Insert default global texts
-- Run this after migration 011 to add standard templates

-- PM (Promemoria) - main tab
INSERT INTO global_texts (field_key, field_name, field_category, content, is_active, sort_order) VALUES
('pm_content', 'PM (Promemoria)', 'event_tabs', 'Välkommen till tävlingen!

ALLMÄN INFORMATION
- Tävlingen genomförs enligt Svenska Cykelförbundets regler
- Obligatorisk utrustning: Hjälm (godkänd för cykling), handskar
- Tävlingsnummer ska vara synligt under hela tävlingen

TIDTAGNING
- Tidtagning sker elektroniskt
- Se till att din transponder är korrekt monterad

SÄKERHET
- Följ funktionärernas anvisningar
- Var uppmärksam på andra tävlande
- Rapportera eventuella olyckor omedelbart

Lycka till!', 1, 1);

-- Jurykommuniké
INSERT INTO global_texts (field_key, field_name, field_category, content, is_active, sort_order) VALUES
('jury_communication', 'Jurykommuniké', 'event_tabs', 'JURYKOMMUNIKÉ

Jury för tävlingen:
- Huvuddomare: [Namn]
- Jurymedlem: [Namn]
- Jurymedlem: [Namn]

Eventuella protester ska lämnas in skriftligt till juryn senast 15 minuter efter att resultatlistan publicerats.

Beslut och meddelanden från juryn publiceras här under tävlingen.', 1, 2);

-- Tävlingsschema
INSERT INTO global_texts (field_key, field_name, field_category, content, is_active, sort_order) VALUES
('competition_schedule', 'Tävlingsschema', 'event_tabs', 'TÄVLINGSSCHEMA

LÖRDAG
07:00 - Sekretariatet öppnar
08:00 - Träning öppnar
09:00 - Förarmöte
09:30 - Första start
12:00 - Lunchpaus
13:00 - Tävlingen fortsätter
16:00 - Beräknad avslutning
17:00 - Prisutdelning

Tiderna är preliminära och kan ändras.', 1, 3);

-- Starttider
INSERT INTO global_texts (field_key, field_name, field_category, content, is_active, sort_order) VALUES
('start_times', 'Starttider', 'event_tabs', 'STARTTIDER

Starttider publiceras här när anmälan stängt.

Startordning bestäms av:
- Ranking i serien
- Tidigare resultat
- Anmälningsordning

Individuella starttider skickas även via e-post till anmälda deltagare.', 1, 4);

-- Karta
INSERT INTO global_texts (field_key, field_name, field_category, content, is_active, sort_order) VALUES
('map_content', 'Karta', 'event_tabs', 'KARTA OCH VÄGBESKRIVNING

Arena och parkering markeras på kartan ovan.

GPS-koordinater: [Lägg till koordinater]

VÄGBESKRIVNING
Från E4/huvudväg: [Lägg till beskrivning]

PARKERING
- Parkering finns på anvisad plats
- Följ skyltning från huvudväg
- Tänk på att lämna plats för utryckningsfordon', 1, 5);

-- Facilities
INSERT INTO global_texts (field_key, field_name, field_category, content, is_active, sort_order) VALUES
('driver_meeting', 'Förarmöte', 'facilities', 'Obligatoriskt förarmöte hålls 30 minuter före första start vid arenan. Alla deltagare måste närvara.', 1, 10),
('training_info', 'Träning', 'facilities', 'Träning på tävlingssträckorna öppnar enligt schemat. Träningskort krävs för liftåkning under träning.', 1, 11),
('timing_info', 'Tidtagning', 'facilities', 'Tidtagning sker med SPORTident eller MyLaps-system. Transpondrar delas ut vid sekretariatet.', 1, 12),
('lift_info', 'Lift', 'facilities', 'Liften är öppen för tävlande enligt schema. Visa startnummer för åkning.', 1, 13),
('hydration_stations', 'Vätskekontroller', 'facilities', 'Vätskekontroller finns utplacerade längs banan. Medhavd vätskeflaska rekommenderas.', 1, 14),
('toilets_showers', 'Toaletter/Dusch', 'facilities', 'Toaletter finns vid arenan. Dusch kan finnas tillgänglig - se information på plats.', 1, 15),
('bike_wash', 'Cykeltvätt', 'facilities', 'Cykeltvätt finns tillgänglig vid arenan.', 1, 16),
('food_cafe', 'Mat/Café', 'facilities', 'Café/servering finns på plats med mat och dryck.', 1, 17);

-- Rules & Safety
INSERT INTO global_texts (field_key, field_name, field_category, content, is_active, sort_order) VALUES
('competition_rules', 'Tävlingsregler', 'rules', 'Tävlingen genomförs enligt Svenska Cykelförbundets regler för [disciplin]. Regelbrott rapporteras till juryn.', 1, 20),
('insurance_info', 'Försäkring', 'rules', 'Deltagare tävlar på egen risk. Försäkring genom SCF-licens eller dagslicens som tecknas vid anmälan.', 1, 21),
('equipment_info', 'Utrustning', 'rules', 'Obligatorisk utrustning:\n- Godkänd cykelhjälm\n- Handskar\n- Korrekt monterat startnummer', 1, 22),
('medical_info', 'Sjukvård', 'rules', 'Sjukvårdspersonal finns på plats. Vid olycka, kontakta närmaste funktionär omedelbart.', 1, 23);

-- Contacts & Info
INSERT INTO global_texts (field_key, field_name, field_category, content, is_active, sort_order) VALUES
('contacts_info', 'Kontakter', 'contacts', 'KONTAKTPERSONER\n\nTävlingsledare: [Namn]\nTelefon: [Nummer]\nE-post: [E-post]\n\nSekretariat: [Telefon]', 1, 30),
('results_info', 'Resultat', 'contacts', 'Resultat publiceras löpande på resultattavlan vid arenan och på webbplatsen.', 1, 31);
