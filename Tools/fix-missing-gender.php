#!/usr/bin/env php
<?php
/**
 * Fix riders with missing gender
 *
 * Finds all active riders with NULL or empty gender and allows fixing them
 */

require_once __DIR__ . '/../hub-config.php';

$pdo = hub_db();

// Find riders with missing gender
$stmt = $pdo->query("
    SELECT id, firstname, lastname, birth_year, gender, club_id
    FROM riders
    WHERE active = 1
    AND (gender IS NULL OR gender = '')
    ORDER BY lastname, firstname
");

$riders = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($riders)) {
    echo "✓ Inga aktiva riders saknar könsuppgift!\n";
    exit(0);
}

echo "Hittade " . count($riders) . " aktiva riders som saknar könsuppgift:\n\n";

foreach ($riders as $rider) {
    echo "ID: {$rider['id']} - {$rider['firstname']} {$rider['lastname']}";
    if ($rider['birth_year']) {
        echo " (född {$rider['birth_year']})";
    }
    echo "\n";
}

echo "\n";
echo "För att fixa Clara Attermo specifikt, kör:\n";
echo "UPDATE riders SET gender = 'F' WHERE id = [Clara's ID];\n\n";

echo "Eller fixa alla manuellt via admin-gränssnittet.\n";
