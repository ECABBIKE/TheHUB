<?php
/**
 * ExportLogger
 *
 * Hanterar loggning av alla analytics-exporter for GDPR och reproducerbarhet.
 * Skapar manifest med fingerprint for varje export.
 *
 * @package TheHUB Analytics
 * @version 1.0
 */

require_once __DIR__ . '/AnalyticsConfig.php';

class ExportLogger {
    private PDO $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    /**
     * Logga en export
     *
     * @param string $exportType Typ av export (riders_at_risk, cohort, winback, etc.)
     * @param array $data Exporterad data
     * @param array $options Export-optioner
     * @return int Export ID
     */
    public function logExport(string $exportType, array $data, array $options = []): int {
        $manifest = $this->createManifest($exportType, $data, $options);

        $stmt = $this->pdo->prepare("
            INSERT INTO analytics_exports (
                export_type, export_format, filename,
                exported_by, ip_address,
                season_year, series_id, filters,
                row_count, contains_pii,
                snapshot_id, data_fingerprint, source_query_hash,
                manifest
            ) VALUES (
                ?, ?, ?,
                ?, ?,
                ?, ?, ?,
                ?, ?,
                ?, ?, ?,
                ?
            )
        ");

        $stmt->execute([
            $exportType,
            $options['format'] ?? 'csv',
            $options['filename'] ?? null,
            $options['user_id'] ?? null,
            $options['ip_address'] ?? $this->getClientIP(),
            $options['year'] ?? null,
            $options['series_id'] ?? null,
            json_encode($options['filters'] ?? []),
            count($data),
            $this->containsPII($data) ? 1 : 0,
            $options['snapshot_id'] ?? null,
            $manifest['data_fingerprint'],
            $manifest['query_hash'] ?? null,
            json_encode($manifest),
        ]);

        return (int)$this->pdo->lastInsertId();
    }

    /**
     * Skapa komplett manifest for en export
     *
     * @param string $exportType Typ
     * @param array $data Data
     * @param array $options Optioner
     * @return array Manifest
     */
    public function createManifest(string $exportType, array $data, array $options = []): array {
        $dataFingerprint = $this->calculateFingerprint($data);

        return [
            // Metadata
            'export_type' => $exportType,
            'exported_at' => date('Y-m-d H:i:s'),
            'exported_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
            'timezone' => date_default_timezone_get(),

            // Platform
            'platform_version' => AnalyticsConfig::PLATFORM_VERSION,
            'calculation_version' => AnalyticsConfig::CALCULATION_VERSION,

            // Data
            'row_count' => count($data),
            'data_fingerprint' => $dataFingerprint,
            'columns' => $this->extractColumns($data),

            // Filters/parametrar
            'season_year' => $options['year'] ?? null,
            'series_id' => $options['series_id'] ?? null,
            'filters' => $options['filters'] ?? [],
            'query_hash' => $options['query_hash'] ?? null,

            // Reproducerbarhet
            'source_max_updated_at' => $options['source_max_updated'] ?? null,
            'snapshot_id' => $options['snapshot_id'] ?? null,

            // GDPR
            'contains_pii' => $this->containsPII($data),
            'pii_fields' => $this->identifyPIIFields($data),

            // Validering
            'checksum_algorithm' => 'sha256',
            'can_reproduce' => !empty($options['snapshot_id']),
        ];
    }

    /**
     * Berakna fingerprint for data
     *
     * @param array $data Data
     * @return string SHA256 hash
     */
    public function calculateFingerprint(array $data): string {
        // Sortera och normalisera data for konsekvent hash
        $normalized = json_encode($data, JSON_SORT_KEYS | JSON_NUMERIC_CHECK);
        return hash('sha256', $normalized);
    }

    /**
     * Kolla om data innehaller persondata (PII)
     *
     * @param array $data Data
     * @return bool
     */
    public function containsPII(array $data): bool {
        if (empty($data)) return false;

        $piiFields = ['firstname', 'lastname', 'email', 'phone', 'birth_year', 'birth_date'];
        $firstRow = is_array($data[0] ?? null) ? $data[0] : [];

        foreach ($piiFields as $field) {
            if (array_key_exists($field, $firstRow)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Identifiera vilka PII-falt som finns i data
     *
     * @param array $data Data
     * @return array Lista over PII-falt
     */
    private function identifyPIIFields(array $data): array {
        if (empty($data)) return [];

        $piiFields = ['firstname', 'lastname', 'email', 'phone', 'birth_year', 'birth_date', 'rider_id', 'name'];
        $firstRow = is_array($data[0] ?? null) ? $data[0] : [];
        $found = [];

        foreach ($piiFields as $field) {
            if (array_key_exists($field, $firstRow)) {
                $found[] = $field;
            }
        }

        return $found;
    }

    /**
     * Extrahera kolumnnamn fran data
     *
     * @param array $data Data
     * @return array Kolumnnamn
     */
    private function extractColumns(array $data): array {
        if (empty($data)) return [];
        $firstRow = is_array($data[0] ?? null) ? $data[0] : [];
        return array_keys($firstRow);
    }

    /**
     * Hamta klient-IP
     *
     * @return string|null IP-adress
     */
    private function getClientIP(): ?string {
        // Kolla proxy-headers forst
        $headers = [
            'HTTP_CF_CONNECTING_IP',     // Cloudflare
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_REAL_IP',
            'REMOTE_ADDR',
        ];

        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ip = $_SERVER[$header];
                // Om flera IPs (proxy chain), ta forsta
                if (strpos($ip, ',') !== false) {
                    $ip = trim(explode(',', $ip)[0]);
                }
                return $ip;
            }
        }

        return null;
    }

    /**
     * Hamta tidigare exporter for en anvandare
     *
     * @param int $userId Anvandar-ID
     * @param int $limit Max antal
     * @return array Exporter
     */
    public function getUserExports(int $userId, int $limit = 50): array {
        $stmt = $this->pdo->prepare("
            SELECT
                id, export_type, export_format, filename,
                exported_at, season_year, row_count, contains_pii
            FROM analytics_exports
            WHERE exported_by = ?
            ORDER BY exported_at DESC
            LIMIT ?
        ");
        $stmt->execute([$userId, $limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Hamta exportstatistik
     *
     * @param string|null $period 'day', 'week', 'month', 'year'
     * @return array Statistik
     */
    public function getExportStats(?string $period = 'month'): array {
        $dateFilter = match($period) {
            'day' => 'DATE(exported_at) = CURDATE()',
            'week' => 'exported_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)',
            'month' => 'exported_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)',
            'year' => 'exported_at >= DATE_SUB(NOW(), INTERVAL 1 YEAR)',
            default => '1=1',
        };

        $stmt = $this->pdo->query("
            SELECT
                COUNT(*) as total_exports,
                COUNT(DISTINCT exported_by) as unique_users,
                SUM(row_count) as total_rows,
                SUM(contains_pii) as pii_exports,
                COUNT(DISTINCT export_type) as export_types
            FROM analytics_exports
            WHERE $dateFilter
        ");

        $stats = $stmt->fetch(PDO::FETCH_ASSOC);

        // Top export types
        $stmt = $this->pdo->query("
            SELECT export_type, COUNT(*) as count
            FROM analytics_exports
            WHERE $dateFilter
            GROUP BY export_type
            ORDER BY count DESC
            LIMIT 5
        ");
        $stats['top_types'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $stats;
    }

    /**
     * Verifiera en export mot dess fingerprint
     *
     * @param int $exportId Export-ID
     * @param array $data Data att verifiera
     * @return bool Matchar fingerprint
     */
    public function verifyExport(int $exportId, array $data): bool {
        $stmt = $this->pdo->prepare("
            SELECT data_fingerprint FROM analytics_exports WHERE id = ?
        ");
        $stmt->execute([$exportId]);
        $storedFingerprint = $stmt->fetchColumn();

        if (!$storedFingerprint) {
            return false;
        }

        return $this->calculateFingerprint($data) === $storedFingerprint;
    }

    /**
     * Hamta manifest for en export
     *
     * @param int $exportId Export-ID
     * @return array|null Manifest eller null
     */
    public function getManifest(int $exportId): ?array {
        $stmt = $this->pdo->prepare("
            SELECT manifest FROM analytics_exports WHERE id = ?
        ");
        $stmt->execute([$exportId]);
        $manifest = $stmt->fetchColumn();

        return $manifest ? json_decode($manifest, true) : null;
    }
}
