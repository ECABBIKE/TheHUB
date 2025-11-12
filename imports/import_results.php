<?php
/**
 * Import Race Results from Excel
 *
 * Expected Excel format:
 * Column A: Event Name (Tävlingsnamn)
 * Column B: Event Date (Datum: YYYY-MM-DD)
 * Column C: Location (Plats)
 * Column D: Position (Placering)
 * Column E: Bib Number (Startnummer)
 * Column F: Firstname (Förnamn)
 * Column G: Lastname (Efternamn)
 * Column H: Birth Year (Födelseår)
 * Column I: Club (Klubb)
 * Column J: Time (Tid: HH:MM:SS)
 * Column K: Category (Kategori)
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

class ResultImporter {
    private $db;
    private $errors = [];
    private $stats = [
        'total' => 0,
        'success' => 0,
        'failed' => 0,
        'skipped' => 0
    ];
    private $eventCache = [];

    public function __construct() {
        $this->db = getDB();
    }

    /**
     * Import results from Excel file
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
                    'event_name' => trim($worksheet->getCell("A{$row}")->getValue()),
                    'event_date' => $worksheet->getCell("B{$row}")->getFormattedValue(),
                    'location' => trim($worksheet->getCell("C{$row}")->getValue()),
                    'position' => $worksheet->getCell("D{$row}")->getValue(),
                    'bib_number' => trim($worksheet->getCell("E{$row}")->getValue()),
                    'firstname' => trim($worksheet->getCell("F{$row}")->getValue()),
                    'lastname' => trim($worksheet->getCell("G{$row}")->getValue()),
                    'birth_year' => $worksheet->getCell("H{$row}")->getValue(),
                    'club_name' => trim($worksheet->getCell("I{$row}")->getValue()),
                    'time' => trim($worksheet->getCell("J{$row}")->getValue()),
                    'category' => trim($worksheet->getCell("K{$row}")->getValue())
                ];

                // Validate required fields
                if (empty($data['event_name']) || empty($data['firstname']) || empty($data['lastname'])) {
                    $this->errors[] = "Row {$row}: Missing required data";
                    $this->stats['skipped']++;
                    continue;
                }

                try {
                    // Get or create event
                    $eventId = $this->getOrCreateEvent($data);

                    // Find cyclist
                    $cyclistId = $this->findCyclist($data);

                    if (!$cyclistId) {
                        // Create cyclist if not found
                        $cyclistId = $this->createCyclist($data);
                    }

                    // Find category
                    $categoryId = $this->findCategory($data['category']);

                    // Format time
                    $finishTime = $this->formatTime($data['time']);

                    // Check if result already exists
                    $existingResult = $this->db->getRow(
                        "SELECT id FROM results WHERE event_id = ? AND cyclist_id = ?",
                        [$eventId, $cyclistId]
                    );

                    if ($existingResult) {
                        // Update existing result
                        $this->db->update('results', [
                            'position' => $data['position'] ?: null,
                            'finish_time' => $finishTime,
                            'bib_number' => $data['bib_number'] ?: null,
                            'category_id' => $categoryId
                        ], 'id = ?', [$existingResult['id']]);
                    } else {
                        // Insert new result
                        $this->db->insert('results', [
                            'event_id' => $eventId,
                            'cyclist_id' => $cyclistId,
                            'category_id' => $categoryId,
                            'position' => $data['position'] ?: null,
                            'finish_time' => $finishTime,
                            'bib_number' => $data['bib_number'] ?: null,
                            'status' => 'finished'
                        ]);
                    }

                    $this->stats['success']++;
                    echo ".";

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
            logImport(
                'results',
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
     * Get or create event
     */
    private function getOrCreateEvent($data) {
        $cacheKey = $data['event_name'] . '_' . $data['event_date'];

        if (isset($this->eventCache[$cacheKey])) {
            return $this->eventCache[$cacheKey];
        }

        // Try to find existing event
        $sql = "SELECT id FROM events WHERE name = ? AND event_date = ? LIMIT 1";
        $result = $this->db->getRow($sql, [$data['event_name'], $data['event_date']]);

        if ($result) {
            $this->eventCache[$cacheKey] = $result['id'];
            return $result['id'];
        }

        // Create new event
        $eventId = $this->db->insert('events', [
            'name' => $data['event_name'],
            'event_date' => $data['event_date'],
            'location' => $data['location'] ?: null,
            'status' => 'completed'
        ]);

        $this->eventCache[$cacheKey] = $eventId;
        return $eventId;
    }

    /**
     * Find cyclist
     */
    private function findCyclist($data) {
        $sql = "SELECT id FROM cyclists
                WHERE firstname = ? AND lastname = ? AND birth_year = ?
                LIMIT 1";

        $result = $this->db->getRow($sql, [
            $data['firstname'],
            $data['lastname'],
            $data['birth_year']
        ]);

        return $result ? $result['id'] : null;
    }

    /**
     * Create cyclist
     */
    private function createCyclist($data) {
        $clubId = null;
        if (!empty($data['club_name'])) {
            $clubId = $this->getOrCreateClub($data['club_name']);
        }

        return $this->db->insert('cyclists', [
            'firstname' => $data['firstname'],
            'lastname' => $data['lastname'],
            'birth_year' => $data['birth_year'],
            'club_id' => $clubId,
            'active' => 1
        ]);
    }

    /**
     * Get or create club
     */
    private function getOrCreateClub($clubName) {
        $sql = "SELECT id FROM clubs WHERE name = ? LIMIT 1";
        $result = $this->db->getRow($sql, [$clubName]);

        if ($result) {
            return $result['id'];
        }

        return $this->db->insert('clubs', [
            'name' => $clubName,
            'active' => 1
        ]);
    }

    /**
     * Find category by name
     */
    private function findCategory($categoryName) {
        if (empty($categoryName)) return null;

        $sql = "SELECT id FROM categories WHERE name LIKE ? OR short_name = ? LIMIT 1";
        $result = $this->db->getRow($sql, ["%{$categoryName}%", $categoryName]);

        return $result ? $result['id'] : null;
    }

    /**
     * Format time string
     */
    private function formatTime($timeStr) {
        if (empty($timeStr)) return null;

        // Try to parse different time formats
        $timeStr = trim($timeStr);

        // HH:MM:SS
        if (preg_match('/^(\d{1,2}):(\d{2}):(\d{2})$/', $timeStr, $matches)) {
            return sprintf("%02d:%02d:%02d", $matches[1], $matches[2], $matches[3]);
        }

        // MM:SS
        if (preg_match('/^(\d{1,2}):(\d{2})$/', $timeStr, $matches)) {
            return sprintf("00:%02d:%02d", $matches[1], $matches[2]);
        }

        return null;
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
            echo "\nErrors (showing first 10):\n";
            foreach (array_slice($this->errors, 0, 10) as $error) {
                echo "  - " . $error . "\n";
            }
            if (count($this->errors) > 10) {
                echo "  ... and " . (count($this->errors) - 10) . " more\n";
            }
        }
    }
}

// Command line usage
if (php_sapi_name() === 'cli') {
    if ($argc < 2) {
        echo "Usage: php import_results.php <excel_file.xlsx> [start_row]\n";
        echo "Example: php import_results.php results.xlsx 2\n";
        exit(1);
    }

    $filePath = $argv[1];
    $startRow = isset($argv[2]) ? (int)$argv[2] : 2;

    if (!file_exists($filePath)) {
        echo "ERROR: File not found: {$filePath}\n";
        exit(1);
    }

    echo "=== TheHUB Results Import ===\n";
    echo "File: {$filePath}\n";
    echo "Start row: {$startRow}\n\n";

    $importer = new ResultImporter();
    $success = $importer->import($filePath, $startRow);

    exit($success ? 0 : 1);
}
