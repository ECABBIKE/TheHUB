-- ============================================================================
-- TheHUB: Skapa testanvändare för login
-- Kör detta i phpMyAdmin eller MySQL CLI
-- ============================================================================

-- Först, kolla om det finns en rider utan lösenord som vi kan använda:
-- SELECT id, email, firstname, lastname, password FROM riders WHERE active = 1 LIMIT 5;

-- ALTERNATIV 1: Sätt lösenord för en befintlig rider (ersätt EMAIL med riktig email)
-- Lösenordet blir: test123
-- UPDATE riders SET password = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi' WHERE email = 'ÄNDRA_TILL_RIKTIG_EMAIL';

-- ALTERNATIV 2: Skapa ny testanvändare
-- Lösenordet blir: test123
INSERT INTO riders (
    firstname,
    lastname,
    email,
    password,
    active,
    role_id,
    created_at
) VALUES (
    'Test',
    'Användare',
    'test@thehub.se',
    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
    1,
    1,
    NOW()
) ON DUPLICATE KEY UPDATE
    password = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi';

-- Verifiera att användaren skapades:
-- SELECT id, email, firstname, lastname, password IS NOT NULL as has_password FROM riders WHERE email = 'test@thehub.se';

-- ============================================================================
-- TESTINLOGGNING:
-- Email: test@thehub.se
-- Lösenord: test123
-- ============================================================================
