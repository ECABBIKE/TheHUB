<?php
/**
 * Import Event Entries (Pre-registration)
 *
 * Use this BEFORE importing results to pre-populate rider data.
 * This reduces manual work on result files since riders already exist in the database.
 *
 * Expected Excel format:
 * Column A: Firstname (Förnamn)
 * Column B: Lastname (Efternamn)
 * Column C: Club (Klubb)
 * Column D: UCI ID / License Number (UCI-ID / Licensnummer)
 * Column E: Birth Year (Födelseår)
 * Column F: Nationality (Nationalitet: ISO 3166-1 alpha-3, default SWE)
 * Column G: Gender (Kön: M/F/K, default M)
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

class EntryImporter {
    private $db;
    private $errors = [];
    private $stats = [
        'total' => 0,
        'created' => 0,
        'updated' => 0,
        'skipped' => 0,
        'failed' => 0
    ];
    private $clubCache = [];

    public function __construct() {
        $this->db = getDB();
    }

    /**
     * Import entries from Excel file
     */
    public function import($filePath, $startRow = 2) {
        try {
            $spreadsheet = IOFactory::load($filePath);
            $worksheet = $spreadsheet->getActiveSheet();
            $highestRow = $worksheet->getHighestRow();

            echo "Found " . ($highestRow - $startRow + 1) . " entries to process\n\n";

            $this->db->beginTransaction();

            for ($row = $startRow; $row <= $highestRow; $row++) {
                $this->stats['total']++;

                // Read row data
                $data = [
                    'firstname' => trim($worksheet->getCell("A{$row}")->getValue()),
                    'lastname' => trim($worksheet->getCell("B{$row}")->getValue()),
                    'club_name' => trim($worksheet->getCell("C{$row}")->getValue()),
                    'license_number' => trim($worksheet->getCell("D{$row}")->getValue()),
                    'birth_year' => $this->parseYear($worksheet->getCell("E{$row}")->getValue()),
                    'nationality' => strtoupper(trim($worksheet->getCell("F{$row}")->getValue())) ?: 'SWE',
                    'gender' => $this->normalizeGender(trim($worksheet->getCell("G{$row}")->getValue()))
                ];

                // Validate required fields
                if (empty($data['firstname']) || empty($data['lastname'])) {
                    $this->errors[] = "Row {$row}: Missing firstname or lastname";
                    $this->stats['skipped']++;
                    continue;
                }

                try {
                    $this->processEntry($data, $row);
                } catch (Exception $e) {
                    $this->errors[] = "Row {$row}: " . $e->getMessage();
                    $this->stats['failed']++;
                    echo "X";
                }

                // Progress indicator
                if ($row % 50 == 0) {
                    echo " [{$row}/{$highestRow}]\n";
                }
            }

            $this->db->commit();

            echo "\n\nImport completed!\n";
            $this->printStats();

            // Log import
            if (function_exists('logImport')) {
                logImport(
                    'riders',
                    basename($filePath),
                    $this->stats['total'],
                    $this->stats['created'] + $this->stats['updated'],
                    $this->stats['failed'],
                    $this->errors
                );
            }

            return true;

        } catch (Exception $e) {
            $this->db->rollback();
            echo "ERROR: " . $e->getMessage() . "\n";
            return false;
        }
    }

    /**
     * Process a single entry
     */
    private function processEntry($data, $row) {
        // Get or create club
        $clubId = null;
        if (!empty($data['club_name'])) {
            $clubId = $this->getOrCreateClub($data['club_name']);
        }

        // Try to find existing rider
        $existingRider = $this->findExistingRider($data);

        if ($existingRider) {
            // Update existing rider with new data
            $updateData = [];

            // Only update fields that have values and differ
            if (!empty($data['club_name']) && $clubId != $existingRider['club_id']) {
                $updateData['club_id'] = $clubId;
            }
            if (!empty($data['license_number']) && $data['license_number'] != $existingRider['license_number']) {
                $updateData['license_number'] = $data['license_number'];
            }
            if (!empty($data['birth_year']) && $data['birth_year'] != $existingRider['birth_year']) {
                $updateData['birth_year'] = $data['birth_year'];
            }
            if ($data['nationality'] != 'SWE' && $data['nationality'] != $existingRider['nationality']) {
                $updateData['nationality'] = $data['nationality'];
            }
            if (!empty($data['gender']) && $data['gender'] != $existingRider['gender']) {
                $updateData['gender'] = $data['gender'];
            }

            if (!empty($updateData)) {
                $this->db->update('riders', $updateData, 'id = ?', [$existingRider['id']]);
                $this->stats['updated']++;
                echo "u";
            } else {
                $this->stats['skipped']++;
                echo ".";
            }
        } else {
            // Create new rider
            $insertData = [
                'firstname' => $data['firstname'],
                'lastname' => $data['lastname'],
                'club_id' => $clubId,
                'license_number' => $data['license_number'] ?: null,
                'birth_year' => $data['birth_year'] ?: null,
                'nationality' => $data['nationality'],
                'gender' => $data['gender'],
                'active' => 1
            ];

            $this->db->insert('riders', $insertData);
            $this->stats['created']++;
            echo "+";
        }
    }

    /**
     * Find existing rider by license number or name + birth year
     */
    private function findExistingRider($data) {
        // First try UCI ID / license number (most reliable)
        if (!empty($data['license_number'])) {
            $sql = "SELECT id, firstname, lastname, club_id, license_number, birth_year, nationality, gender
                    FROM riders WHERE license_number = ? LIMIT 1";
            $result = $this->db->getRow($sql, [$data['license_number']]);
            if ($result) return $result;
        }

        // Then try name + birth year
        if (!empty($data['birth_year'])) {
            $sql = "SELECT id, firstname, lastname, club_id, license_number, birth_year, nationality, gender
                    FROM riders
                    WHERE firstname = ? AND lastname = ? AND birth_year = ?
                    LIMIT 1";
            $result = $this->db->getRow($sql, [
                $data['firstname'],
                $data['lastname'],
                $data['birth_year']
            ]);
            if ($result) return $result;
        }

        // Finally try just name (less reliable, might match wrong person)
        $sql = "SELECT id, firstname, lastname, club_id, license_number, birth_year, nationality, gender
                FROM riders
                WHERE firstname = ? AND lastname = ? AND birth_year IS NULL
                LIMIT 1";
        $result = $this->db->getRow($sql, [$data['firstname'], $data['lastname']]);

        return $result ?: null;
    }

    /**
     * Get or create club
     */
    private function getOrCreateClub($clubName) {
        // Check cache first
        if (isset($this->clubCache[$clubName])) {
            return $this->clubCache[$clubName];
        }

        // Look up in database
        $sql = "SELECT id FROM clubs WHERE name = ? LIMIT 1";
        $result = $this->db->getRow($sql, [$clubName]);

        if ($result) {
            $this->clubCache[$clubName] = $result['id'];
            return $result['id'];
        }

        // Create new club
        $clubId = $this->db->insert('clubs', [
            'name' => $clubName,
            'active' => 1
        ]);

        $this->clubCache[$clubName] = $clubId;
        return $clubId;
    }

    /**
     * Parse year from various formats
     */
    private function parseYear($value) {
        if (empty($value)) return null;

        $value = trim($value);

        // Already a 4-digit year
        if (preg_match('/^(19|20)\d{2}$/', $value)) {
            return (int)$value;
        }

        // 2-digit year
        if (preg_match('/^\d{2}$/', $value)) {
            $year = (int)$value;
            return $year > 50 ? 1900 + $year : 2000 + $year;
        }

        // Try to extract year from date
        if (preg_match('/(19|20)\d{2}/', $value, $matches)) {
            return (int)$matches[0];
        }

        return null;
    }

    /**
     * Normalize gender value
     */
    private function normalizeGender($value) {
        $value = strtoupper($value);

        if (in_array($value, ['F', 'K', 'W', 'FEMALE', 'KVINNA', 'WOMAN'])) {
            return 'F';
        }

        if (in_array($value, ['M', 'MALE', 'MAN', 'HERR'])) {
            return 'M';
        }

        return 'M'; // Default
    }

    /**
     * Print import statistics
     */
    private function printStats() {
        echo "\n";
        echo "┌─────────────────────────────┐\n";
        echo "│       Import Summary        │\n";
        echo "├─────────────────────────────┤\n";
        printf("│ Total processed: %9d │\n", $this->stats['total']);
        printf("│ New riders:      %9d │\n", $this->stats['created']);
        printf("│ Updated:         %9d │\n", $this->stats['updated']);
        printf("│ Skipped:         %9d │\n", $this->stats['skipped']);
        printf("│ Failed:          %9d │\n", $this->stats['failed']);
        echo "└─────────────────────────────┘\n";

        if (!empty($this->errors)) {
            echo "\nErrors (showing first 10):\n";
            foreach (array_slice($this->errors, 0, 10) as $error) {
                echo "  ⚠ " . $error . "\n";
            }
            if (count($this->errors) > 10) {
                echo "  ... and " . (count($this->errors) - 10) . " more\n";
            }
        }
    }

    public function getStats() {
        return $this->stats;
    }

    public function getErrors() {
        return $this->errors;
    }
}

// Command line usage
if (php_sapi_name() === 'cli') {
    if ($argc < 2) {
        echo "╔═══════════════════════════════════════════════════════════╗\n";
        echo "║          TheHUB Entry Import (Pre-registration)          ║\n";
        echo "╠═══════════════════════════════════════════════════════════╣\n";
        echo "║ Usage: php import_entries.php <excel_file.xlsx> [row]    ║\n";
        echo "║                                                           ║\n";
        echo "║ Excel format:                                             ║\n";
        echo "║   A: Förnamn (required)                                   ║\n";
        echo "║   B: Efternamn (required)                                 ║\n";
        echo "║   C: Klubb                                                ║\n";
        echo "║   D: UCI-ID / Licensnummer                                ║\n";
        echo "║   E: Födelseår                                            ║\n";
        echo "║   F: Nationalitet (default: SWE)                          ║\n";
        echo "║   G: Kön (M/F, default: M)                                ║\n";
        echo "╚═══════════════════════════════════════════════════════════╝\n";
        exit(1);
    }

    $filePath = $argv[1];
    $startRow = isset($argv[2]) ? (int)$argv[2] : 2;

    if (!file_exists($filePath)) {
        echo "ERROR: File not found: {$filePath}\n";
        exit(1);
    }

    echo "╔═══════════════════════════════════════════════════════════╗\n";
    echo "║          TheHUB Entry Import (Pre-registration)          ║\n";
    echo "╚═══════════════════════════════════════════════════════════╝\n\n";
    echo "File: {$filePath}\n";
    echo "Start row: {$startRow}\n\n";
    echo "Processing: ";

    $importer = new EntryImporter();
    $success = $importer->import($filePath, $startRow);

    exit($success ? 0 : 1);
}
