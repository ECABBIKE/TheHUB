<?php
/**
 * Debug CSV column mapping
 * Test what happens when we parse your CSV header
 */

require_once __DIR__ . '/../config.php';
require_admin();

$pageTitle = 'Debug CSV Kolumnmappning';
$pageType = 'admin';
include __DIR__ . '/../includes/layout-header.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file'])) {
    $file = $_FILES['csv_file'];

    if ($file['error'] === UPLOAD_ERR_OK) {
        $filepath = $file['tmp_name'];
        $handle = fopen($filepath, 'r');

        // Detect delimiter
        $firstLine = fgets($handle);
        rewind($handle);
        $delimiter = (substr_count($firstLine, ';') > substr_count($firstLine, ',')) ? ';' : ',';

        // Read header
        $rawHeader = fgetcsv($handle, 1000, $delimiter);

        echo "<div class='gs-card gs-mb-lg'>";
        echo "<div class='gs-card-header'><h3>RAW Header (från CSV)</h3></div>";
        echo "<div class='gs-card-content'><pre>";
        print_r($rawHeader);
        echo "</pre></div></div>";

        // Normalize header (same as import does)
        $header = array_map(function($col) {
            $original = $col;
            $col = mb_strtolower(trim($col), 'UTF-8');
            $col = str_replace([' ', '-', '_'], '', $col);
            return ['original' => $original, 'normalized' => $col];
        }, $rawHeader);

        echo "<div class='gs-card gs-mb-lg'>";
        echo "<div class='gs-card-header'><h3>Normaliserade Kolumner</h3></div>";
        echo "<div class='gs-card-content'>";
        echo "<table class='gs-table'>";
        echo "<thead><tr><th>Original</th><th>Normaliserad</th><th>Mappas till?</th></tr></thead>";
        echo "<tbody>";

        $mappings = [
            'gender' => 'gender',
            'kön' => 'gender',
            'kon' => 'gender',
            'kategori' => 'gender',
            'sex' => 'gender',
            'licensår' => 'license_year',
            'licensar' => 'license_year',
            'år' => 'license_year',
            'ar' => 'license_year',
            'ucikod' => 'license_number',
            'uciid' => 'license_number',
            'huvudförening' => 'club_name',
            'huvudforening' => 'club_name',
            'team' => 'club_name',
            'förnamn' => 'firstname',
            'fornamn' => 'firstname',
            'efternamn' => 'lastname',
            'födelsedatum' => 'birth_year',
            'fodelsedatum' => 'birth_year',
            'födelseår' => 'birth_year',
            'fodelsear' => 'birth_year',
            'epost' => 'email',
            'epostadress' => 'email',
        ];

        foreach ($header as $h) {
            $mapped = isset($mappings[$h['normalized']]) ? $mappings[$h['normalized']] : '❌ INGEN MAPPNING';
            $style = strpos($mapped, '❌') !== false ? 'color: red; font-weight: bold;' : '';
            echo "<tr>";
            echo "<td>" . h($h['original']) . "</td>";
            echo "<td>" . h($h['normalized']) . "</td>";
            echo "<td style='$style'>" . h($mapped) . "</td>";
            echo "</tr>";
        }

        echo "</tbody></table>";
        echo "</div></div>";

        // Read first data row
        $firstRow = fgetcsv($handle, 1000, $delimiter);

        if ($firstRow) {
            echo "<div class='gs-card'>";
            echo "<div class='gs-card-header'><h3>Första Dataraden</h3></div>";
            echo "<div class='gs-card-content'>";
            echo "<table class='gs-table'>";
            echo "<thead><tr><th>Kolumn</th><th>Värde</th></tr></thead>";
            echo "<tbody>";

            foreach ($rawHeader as $idx => $colName) {
                $value = $firstRow[$idx] ?? '';
                echo "<tr>";
                echo "<td>" . h($colName) . "</td>";
                echo "<td><strong>" . h($value) . "</strong></td>";
                echo "</tr>";
            }

            echo "</tbody></table>";
            echo "</div></div>";
        }

        fclose($handle);
    }
}
?>

<main class="gs-content-with-sidebar">
    <div class="gs-container">
        <h1 class="gs-h1 gs-mb-lg">Debug CSV Kolumnmappning</h1>

        <div class="gs-card">
            <div class="gs-card-content">
                <p class="gs-mb-md">Ladda upp din CSV för att se hur kolumner mappas:</p>

                <form method="POST" enctype="multipart/form-data">
                    <?= csrf_field() ?>
                    <div class="gs-form-group">
                        <label class="gs-label">CSV-fil</label>
                        <input type="file" name="csv_file" accept=".csv" class="gs-input" required>
                    </div>
                    <button type="submit" class="gs-btn gs-btn-primary">
                        <i data-lucide="search"></i>
                        Analysera CSV
                    </button>
                </form>
            </div>
        </div>
    </div>
</main>

<?php include __DIR__ . '/../includes/layout-footer.php'; ?>
