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
        'personnummer',
        'birth_year',
        'uci_id',
        'swe_id',
        'club_name',
        'gender',
        'license_type',
        'license_category',
        'discipline',
        'license_valid_until'
    ]);

    // Example rows
    fputcsv($output, [
        'Johan',
        'Andersson',
        '19950525-1234',  // Personnummer (parsas automatiskt)
        '',               // Birth year (lämnas tom om personnummer finns)
        'SWE19950101',
        '',
        'Uppsala Cykelklubb',
        'M',
        'Elite',
        'Elite Men',      // Licenskategori
        'MTB',           // Gren
        '2025-12-31'     // Licens giltig till
    ]);

    fputcsv($output, [
        'Emma',
        'Svensson',
        '19980315-5678',
        '',
        'SWE19980315',
        '',
        'Göteborg MTB',
        'F',
        'Elite',
        'Elite Women',
        'Road',
        '2025-12-31'
    ]);

    fputcsv($output, [
        'Erik',
        'Nilsson',
        '',              // Inget personnummer
        '2003',          // Använd birth_year istället
        '',              // UCI-ID (autogenereras SWE-ID om tomt)
        '',
        'Stockholm CK',
        'M',
        'Youth',
        'U21 Men',
        'CX',
        '2025-12-31'
    ]);

    fputcsv($output, [
        'Anna',
        'Bergström',
        '850812-9876',   // Kort format (YYMMDD-XXXX)
        '',
        'SWE19850812',
        '',
        'Luleå CK',
        'F',
        'Elite',
        'Master Women 35+',
        'MTB',
        '2025-12-31'
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
        'finish_time',
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
        '3:05.45',
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
        '3:15.78',
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
        '3:09.23',
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
        '3:07.92',
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

if ($template === 'results_dh') {
    // CSV template for Downhill results (two runs)
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="thehub_dh_results_template.csv"');

    $output = fopen('php://output', 'w');

    // BOM for UTF-8
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

    // Headers
    fputcsv($output, [
        'event_name',
        'event_date',
        'discipline',
        'class_name',
        'position',
        'first_name',
        'last_name',
        'club_name',
        'uci_id',
        'swe_id',
        'run_1_time',
        'run_2_time',
        'status'
    ]);

    // Example rows - Standard DH (both runs, fastest counts)
    fputcsv($output, [
        'Järvsö DH 2024',
        '2024-09-15',
        'DHI',
        'Elite Men',
        '1',
        'Johan',
        'Andersson',
        'Uppsala Cykelklubb',
        'SWE19950101',
        '',
        '3:07.45',   // Run 1
        '3:05.12',   // Run 2 (fastest = position)
        'finished'
    ]);

    fputcsv($output, [
        'Järvsö DH 2024',
        '2024-09-15',
        'DHI',
        'Elite Men',
        '2',
        'Erik',
        'Nilsson',
        'Stockholm CK',
        'SWE20031201',
        '',
        '3:08.23',   // Run 1
        '3:06.89',   // Run 2 (fastest)
        'finished'
    ]);

    fputcsv($output, [
        'Järvsö DH 2024',
        '2024-09-15',
        'DHI',
        'Elite Women',
        '1',
        'Emma',
        'Svensson',
        'Göteborg MTB',
        'SWE19980315',
        '',
        '3:18.45',   // Run 1
        '3:15.78',   // Run 2 (fastest)
        'finished'
    ]);

    fputcsv($output, [
        'Järvsö DH 2024',
        '2024-09-15',
        'DHI',
        'Elite Men',
        '',
        'Lars',
        'Persson',
        'Umeå MTB',
        'SWE19940205',
        '',
        '3:12.45',   // Run 1 OK
        '',          // Run 2 DNF
        'dnf'
    ]);

    fclose($output);
    exit;
}

// If no template selected
header('HTTP/1.1 400 Bad Request');
die('Invalid template requested. Use ?template=riders, ?template=results, or ?template=results_dh');
