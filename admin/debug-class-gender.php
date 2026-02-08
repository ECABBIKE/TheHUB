<?php
/**
 * Debug Class Gender Values
 */

require_once __DIR__ . '/../hub-config.php';

$pdo = hub_db();

// Check all active classes
$stmt = $pdo->query("
    SELECT id, name, short_name, gender, min_age, max_age, active
    FROM classes
    WHERE active = 1
    ORDER BY gender, name
");

$classes = $stmt->fetchAll(PDO::FETCH_ASSOC);

header('Content-Type: text/plain; charset=utf-8');

echo "AKTIVA KLASSER I DATABASEN:\n\n";
printf("%-5s | %-30s | %-10s | %-8s | %6s | %6s\n",
    'ID', 'Namn', 'Short', 'Gender', 'MinAge', 'MaxAge');
echo str_repeat('-', 80) . "\n";

foreach ($classes as $class) {
    printf("%-5s | %-30s | %-10s | %-8s | %6s | %6s\n",
        $class['id'],
        $class['name'],
        $class['short_name'] ?: '-',
        "'" . $class['gender'] . "'",
        $class['min_age'] ?: '-',
        $class['max_age'] ?: '-'
    );
}

echo "\n";
echo "Gender values found:\n";
$genders = array_unique(array_column($classes, 'gender'));
foreach ($genders as $g) {
    echo "  - '$g'\n";
}

// Now check Clara specifically
echo "\n" . str_repeat('=', 80) . "\n";
echo "CLARA ATTERMO CHECK:\n\n";

$stmt = $pdo->query("
    SELECT id, firstname, lastname, birth_year, gender
    FROM riders
    WHERE firstname = 'Clara' AND lastname = 'Attermo'
    LIMIT 1
");

$clara = $stmt->fetch(PDO::FETCH_ASSOC);

if ($clara) {
    $currentYear = date('Y');
    $age = $currentYear - intval($clara['birth_year']);

    echo "Clara Attermo (ID: {$clara['id']}):\n";
    echo "  - Födelsår: {$clara['birth_year']}\n";
    echo "  - Ålder: $age år\n";
    echo "  - Kön: '{$clara['gender']}'\n";
    echo "  - Kön uppercase: '" . strtoupper($clara['gender']) . "'\n";

    echo "\nSkulle matcha dessa klasser:\n";

    $riderGender = strtoupper($clara['gender']);

    foreach ($classes as $class) {
        $matchesGender = (empty($class['gender']) || $class['gender'] === $riderGender);
        $matchesMinAge = (empty($class['min_age']) || $age >= $class['min_age']);
        $matchesMaxAge = (empty($class['max_age']) || $age <= $class['max_age']);

        if ($matchesGender && $matchesMinAge && $matchesMaxAge) {
            echo "  ✓ {$class['name']} (gender='{$class['gender']}', age {$class['min_age']}-{$class['max_age']})\n";
        }
    }

    echo "\nBlockas från dessa klasser:\n";
    foreach ($classes as $class) {
        $matchesGender = (empty($class['gender']) || $class['gender'] === $riderGender);
        $matchesMinAge = (empty($class['min_age']) || $age >= $class['min_age']);
        $matchesMaxAge = (empty($class['max_age']) || $age <= $class['max_age']);

        if (!($matchesGender && $matchesMinAge && $matchesMaxAge)) {
            $reason = '';
            if (!$matchesGender) $reason = "gender (klass='{$class['gender']}', Clara='" . $riderGender . "')";
            elseif (!$matchesMinAge) $reason = "för ung ($age < {$class['min_age']})";
            elseif (!$matchesMaxAge) $reason = "för gammal ($age > {$class['max_age']})";

            echo "  ✗ {$class['name']} - $reason\n";
        }
    }
} else {
    echo "Clara Attermo hittades inte!\n";
}
