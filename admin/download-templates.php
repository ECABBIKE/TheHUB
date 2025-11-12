<?php
require_once __DIR__ . '/../config.php';
require_admin();

$template = $_GET['template'] ?? '';

if ($template === 'riders') {
    // CSV template for riders
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="thehub_riders_template.csv"');

    $output = fopen('php://output', 'w');

    // BOM for UTF-8
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

    // Headers
    fputcsv($output, [
        'first_name',
        'last_name',
        'birth_year',
        'uci_id',
        'swe_id',
        'club_name',
        'category',
        'gender',
        'license_type'
    ]);

    // Example rows
    fputcsv($output, [
        'Johan',
        'Andersson',
        '1995',
        'SWE19950101',
        '',
        'Uppsala Cykelklubb',
        'Elite Men',
        'male',
        'Elite'
    ]);

    fputcsv($output, [
        'Emma',
        'Svensson',
        '1998',
        'SWE19980315',
        '',
        'Göteborg MTB',
        'Elite Women',
        'female',
        'Elite'
    ]);

    fputcsv($output, [
        'Erik',
        'Nilsson',
        '2003',
        '',
        'SWE25001',
        'Stockholm CK',
        'U21 Men',
        'male',
        'Youth'
    ]);

    fclose($output);
    exit;
}

if ($template === 'results') {
    // CSV template for results
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="thehub_results_template.csv"');

    $output = fopen('php://output', 'w');

    // BOM for UTF-8
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

    // Headers
    fputcsv($output, [
        'event_name',
        'event_date',
        'discipline',
        'category',
        'position',
        'first_name',
        'last_name',
        'club_name',
        'uci_id',
        'swe_id',
        'time_seconds',
        'status'
    ]);

    // Example rows
    fputcsv($output, [
        'Järvsö DH Finals 2024',
        '2024-09-15',
        'DHI',
        'Elite Men',
        '1',
        'Johan',
        'Andersson',
        'Uppsala Cykelklubb',
        'SWE19950101',
        '',
        '185.45',
        'finished'
    ]);

    fputcsv($output, [
        'Järvsö DH Finals 2024',
        '2024-09-15',
        'DHI',
        'Elite Women',
        '1',
        'Emma',
        'Svensson',
        'Göteborg MTB',
        'SWE19980315',
        '',
        '195.78',
        'finished'
    ]);

    fputcsv($output, [
        'Järvsö DH Finals 2024',
        '2024-09-15',
        'DHI',
        'U21 Men',
        '1',
        'Erik',
        'Nilsson',
        'Stockholm CK',
        '',
        'SWE25001',
        '189.23',
        'finished'
    ]);

    fputcsv($output, [
        'Järvsö DH Finals 2024',
        '2024-09-15',
        'DHI',
        'Elite Men',
        '2',
        'Anders',
        'Karlsson',
        'Malmö CK',
        'SWE19930812',
        '',
        '187.92',
        'finished'
    ]);

    fputcsv($output, [
        'Järvsö DH Finals 2024',
        '2024-09-15',
        'DHI',
        'Elite Men',
        '',
        'Lars',
        'Persson',
        'Umeå MTB',
        'SWE19940205',
        '',
        '',
        'dnf'
    ]);

    fclose($output);
    exit;
}

// If no template selected
header('HTTP/1.1 400 Bad Request');
die('Invalid template requested. Use ?template=riders or ?template=results');
