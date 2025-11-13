<?php
/**
 * Import Cyclists from Excel
 *
 * Expected Excel format:
 * Column A: Firstname (Förnamn)
 * Column B: Lastname (Efternamn)
 * Column C: Birth Year (Födelseår)
 * Column D: Gender (Kön: M/F)
 * Column E: Club (Klubb)
 * Column F: License Number (Licensnummer)
 * Column G: Email
 * Column H: Phone (Telefon)
 * Column I: City (Ort)
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

class CyclistImporter {
    private $db;
    private $errors = [];
    private $stats = [
        'total' => 0,
        'success' => 0,
        'failed' => 0,
        'skipped' => 0
    ];

    public function __construct() {
        $this->db = getDB();
    }

    /**
     * Import cyclists from Excel file
     */
    public function import($filePath, $startRow = 2) {
        try {
            $spreadsheet = IOFactory::load($filePath);
            $worksheet = $spreadsheet->getActiveSheet();
            $highestRow = $worksheet->getHighestRow();

            echo "Found " . ($highestRow - $startRow + 1) . " rows to process\n\n";

            $this->db->beginTransaction();

            for ($row = $startRow; $row <= $highestRow; $row++) {
                $this->stats['total']++;

                // Read row data
                $data = [
                    'firstname' => trim($worksheet->getCell("A{$row}")->getValue()),
                    'lastname' => trim($worksheet->getCell("B{$row}")->getValue()),
                    'birth_year' => $worksheet->getCell("C{$row}")->getValue(),
                    'gender' => strtoupper(trim($worksheet->getCell("D{$row}")->getValue())),
                    'club_name' => trim($worksheet->getCell("E{$row}")->getValue()),
                    'license_number' => trim($worksheet->getCell("F{$row}")->getValue()),
                    'email' => trim($worksheet->getCell("G{$row}")->getValue()),
                    'phone' => trim($worksheet->getCell("H{$row}")->getValue()),
                    'city' => trim($worksheet->getCell("I{$row}")->getValue())
                ];

                // Validate required fields
                if (empty($data['firstname']) || empty($data['lastname'])) {
                    $this->errors[] = "Row {$row}: Missing firstname or lastname";
                    $this->stats['skipped']++;
                    continue;
                }

                // Normalize gender
                if (!in_array($data['gender'], ['M', 'F'])) {
                    $data['gender'] = 'M'; // Default
                }

                // Handle club
                $clubId = null;
                if (!empty($data['club_name'])) {
                    $clubId = $this->getOrCreateClub($data['club_name']);
                }

                // Check if cyclist already exists (by license or name)
                $existingId = $this->findExistingCyclist($data);

                try {
                    if ($existingId) {
                        // Update existing
                        $updateData = [
                            'firstname' => $data['firstname'],
                            'lastname' => $data['lastname'],
                            'birth_year' => $data['birth_year'],
                            'gender' => $data['gender'],
                            'club_id' => $clubId,
                            'email' => $data['email'],
                            'phone' => $data['phone'],
                            'city' => $data['city']
                        ];

                        if (!empty($data['license_number'])) {
                            $updateData['license_number'] = $data['license_number'];
                        }

                        $this->db->update('cyclists', $updateData, 'id = ?', [$existingId]);
                        $this->stats['success']++;
                        echo ".";
                    } else {
                        // Insert new
                        $insertData = [
                            'firstname' => $data['firstname'],
                            'lastname' => $data['lastname'],
                            'birth_year' => $data['birth_year'],
                            'gender' => $data['gender'],
                            'club_id' => $clubId,
                            'license_number' => $data['license_number'] ?: null,
                            'email' => $data['email'] ?: null,
                            'phone' => $data['phone'] ?: null,
                            'city' => $data['city'] ?: null,
                            'active' => 1
                        ];

                        $this->db->insert('cyclists', $insertData);
                        $this->stats['success']++;
                        echo "+";
                    }
                } catch (Exception $e) {
                    $this->errors[] = "Row {$row}: " . $e->getMessage();
                    $this->stats['failed']++;
                    echo "X";
                }

                // Progress indicator every 50 rows
                if ($row % 50 == 0) {
                    echo " [{$row}/{$highestRow}]\n";
                }
            }

            $this->db->commit();

            echo "\n\nImport completed!\n";
            $this->printStats();

            // Log import
            logImport(
                'cyclists',
                basename($filePath),
                $this->stats['total'],
                $this->stats['success'],
                $this->stats['failed'],
                $this->errors
            );

            return true;

        } catch (Exception $e) {
            $this->db->rollback();
            echo "ERROR: " . $e->getMessage() . "\n";
            return false;
        }
    }

    /**
     * Find existing cyclist by license number or name
     */
    private function findExistingCyclist($data) {
        // First try license number
        if (!empty($data['license_number'])) {
            $sql = "SELECT id FROM riders WHERE license_number = ? LIMIT 1";
            $result = $this->db->getRow($sql, [$data['license_number']]);
            if ($result) return $result['id'];
        }

        // Then try name + birth year
        if (!empty($data['birth_year'])) {
            $sql = "SELECT id FROM riders
                    WHERE firstname = ? AND lastname = ? AND birth_year = ?
                    LIMIT 1";
            $result = $this->db->getRow($sql, [
                $data['firstname'],
                $data['lastname'],
                $data['birth_year']
            ]);
            if ($result) return $result['id'];
        }

        return null;
    }

    /**
     * Get or create club
     */
    private function getOrCreateClub($clubName) {
        // Check if club exists
        $sql = "SELECT id FROM clubs WHERE name = ? LIMIT 1";
        $result = $this->db->getRow($sql, [$clubName]);

        if ($result) {
            return $result['id'];
        }

        // Create new club
        return $this->db->insert('clubs', [
            'name' => $clubName,
            'active' => 1
        ]);
    }

    /**
     * Print import statistics
     */
    private function printStats() {
        echo "\nStatistics:\n";
        echo "  Total rows: " . $this->stats['total'] . "\n";
        echo "  Successful: " . $this->stats['success'] . "\n";
        echo "  Failed: " . $this->stats['failed'] . "\n";
        echo "  Skipped: " . $this->stats['skipped'] . "\n";

        if (!empty($this->errors)) {
            echo "\nErrors:\n";
            foreach (array_slice($this->errors, 0, 10) as $error) {
                echo "  - " . $error . "\n";
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
        echo "Usage: php import_cyclists.php <excel_file.xlsx> [start_row]\n";
        echo "Example: php import_cyclists.php cyclists.xlsx 2\n";
        exit(1);
    }

    $filePath = $argv[1];
    $startRow = isset($argv[2]) ? (int)$argv[2] : 2;

    if (!file_exists($filePath)) {
        echo "ERROR: File not found: {$filePath}\n";
        exit(1);
    }

    echo "=== TheHUB Cyclist Import ===\n";
    echo "File: {$filePath}\n";
    echo "Start row: {$startRow}\n\n";

    $importer = new CyclistImporter();
    $success = $importer->import($filePath, $startRow);

    exit($success ? 0 : 1);
}
