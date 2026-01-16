<?php
/**
 * ExportLogger
 *
 * Hanterar loggning av alla analytics-exporter for GDPR och reproducerbarhet.
 * Skapar manifest med fingerprint for varje export.
 *
 * v3.0.1: Mandatory snapshot_id, export_uid, rate limiting
 *
 * @package TheHUB Analytics
 * @version 3.0.1
 */

require_once __DIR__ . '/AnalyticsConfig.php';

class ExportLogger {
    private PDO $pdo;

    /** @var bool Om snapshot_id ar obligatoriskt */
    private bool $requireSnapshotId = true;

    /** @var int Max exporter per timme per anvandare */
    private int $hourlyRateLimit = 50;

    /** @var int Max exporter per dag per anvandare */
    private int $dailyRateLimit = 200;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    /**
     * Logga en export (v3.0.1 med mandatory snapshot_id)
     *
     * @param string $exportType Typ av export (riders_at_risk, cohort, winback, etc.)
     * @param array $data Exporterad data
     * @param array $options Export-optioner (snapshot_id REQUIRED)
     * @return int Export ID
     * @throws InvalidArgumentException Om snapshot_id saknas
     * @throws RuntimeException Om rate limit overskrids
     */
    public function logExport(string $exportType, array $data, array $options = []): int {
        // v3.0.1: Validera mandatory snapshot_id
        if ($this->requireSnapshotId && empty($options['snapshot_id'])) {
            throw new InvalidArgumentException(
                'snapshot_id is required for all exports in v3.0.1+. ' .
                'Create a snapshot first using AnalyticsEngine::createSnapshot()'
            );
        }

        // Rate limiting check
        $userId = $options['user_id'] ?? null;
        $ipAddress = $options['ip_address'] ?? $this->getClientIP();

        if (!$this->checkRateLimit($userId, $ipAddress)) {
            throw new RuntimeException(
                'Export rate limit exceeded. Please wait before exporting again.'
            );
        }

        // Generate unique export ID
        $exportUid = $this->generateExportUid();

        $manifest = $this->createManifest($exportType, $data, $options);
        $manifest['export_uid'] = $exportUid;

        $stmt = $this->pdo->prepare("
            INSERT INTO analytics_exports (
                export_uid, export_type, export_format, filename,
                exported_by, ip_address,
                season_year, series_id, filters, filters_json,
                row_count, contains_pii,
                snapshot_id, data_fingerprint, source_query_hash,
                manifest, status
            ) VALUES (
                ?, ?, ?, ?,
                ?, ?,
                ?, ?, ?, ?,
                ?, ?,
                ?, ?, ?,
                ?, 'completed'
            )
        ");

        $filters = $options['filters'] ?? [];
        $stmt->execute([
            $exportUid,
            $exportType,
            $options['format'] ?? 'csv',
            $options['filename'] ?? null,
            $userId,
            $ipAddress,
            $options['year'] ?? null,
            $options['series_id'] ?? null,
            json_encode($filters),       // Legacy filters column
            json_encode($filters),       // New JSON column
            count($data),
            $this->containsPII($data) ? 1 : 0,
            $options['snapshot_id'],
            $manifest['data_fingerprint'],
            $manifest['query_hash'] ?? null,
            json_encode($manifest),
        ]);

        return (int)$this->pdo->lastInsertId();
    }

    /**
     * Logga export utan snapshot (for bakåtkompatibilitet, loggar varning)
     *
     * @deprecated Anvand logExport() med snapshot_id istallet
     */
    public function logExportLegacy(string $exportType, array $data, array $options = []): int {
        error_log('WARNING: logExportLegacy() used without snapshot_id. Export will not be reproducible.');

        $this->requireSnapshotId = false;
        $result = $this->logExport($exportType, $data, $options);
        $this->requireSnapshotId = true;

        return $result;
    }

    /**
     * Generera unik export-UID
     *
     * @return string UUID v4
     */
    private function generateExportUid(): string {
        // UUID v4 implementation
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    /**
     * Kontrollera rate limit for anvandare/IP
     *
     * @param int|null $userId
     * @param string|null $ipAddress
     * @return bool True om under limit
     */
    public function checkRateLimit(?int $userId, ?string $ipAddress): bool {
        // Kolla hourly limit
        $hourlyCount = $this->getExportCount($userId, $ipAddress, 'hour');
        if ($hourlyCount >= $this->hourlyRateLimit) {
            return false;
        }

        // Kolla daily limit
        $dailyCount = $this->getExportCount($userId, $ipAddress, 'day');
        if ($dailyCount >= $this->dailyRateLimit) {
            return false;
        }

        return true;
    }

    /**
     * Hamta antal exporter for period
     *
     * @param int|null $userId
     * @param string|null $ipAddress
     * @param string $period 'hour' eller 'day'
     * @return int Antal exporter
     */
    private function getExportCount(?int $userId, ?string $ipAddress, string $period): int {
        $interval = $period === 'hour' ? '1 HOUR' : '1 DAY';

        $conditions = [];
        $params = [];

        if ($userId) {
            $conditions[] = 'exported_by = ?';
            $params[] = $userId;
        }
        if ($ipAddress) {
            $conditions[] = 'ip_address = ?';
            $params[] = $ipAddress;
        }

        if (empty($conditions)) {
            return 0;
        }

        $whereClause = implode(' OR ', $conditions);

        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) FROM analytics_exports
            WHERE ($whereClause)
            AND exported_at >= DATE_SUB(NOW(), INTERVAL $interval)
        ");
        $stmt->execute($params);

        return (int)$stmt->fetchColumn();
    }

    /**
     * Hamta rate limit status for en anvandare
     *
     * @param int|null $userId
     * @param string|null $ipAddress
     * @return array Status med counts och limits
     */
    public function getRateLimitStatus(?int $userId, ?string $ipAddress = null): array {
        $ipAddress = $ipAddress ?? $this->getClientIP();

        return [
            'hourly' => [
                'current' => $this->getExportCount($userId, $ipAddress, 'hour'),
                'limit' => $this->hourlyRateLimit,
            ],
            'daily' => [
                'current' => $this->getExportCount($userId, $ipAddress, 'day'),
                'limit' => $this->dailyRateLimit,
            ],
            'can_export' => $this->checkRateLimit($userId, $ipAddress),
        ];
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
        $snapshotId = $options['snapshot_id'] ?? null;

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

            // Reproducerbarhet (v3.0.1 enhanced)
            'source_max_updated_at' => $options['source_max_updated'] ?? null,
            'snapshot_id' => $snapshotId,
            'can_reproduce' => !empty($snapshotId),
            'reproducibility_note' => empty($snapshotId)
                ? 'WARNING: No snapshot_id. Export cannot be reproduced.'
                : 'Export can be reproduced from snapshot #' . $snapshotId,

            // GDPR
            'contains_pii' => $this->containsPII($data),
            'pii_fields' => $this->identifyPIIFields($data),
            'data_retention_days' => 365,
            'gdpr_compliant' => true,

            // Validering
            'checksum_algorithm' => 'sha256',
            'manifest_version' => '3.0.1',
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

        $piiFields = AnalyticsConfig::PII_FIELDS ?? [
            'firstname', 'lastname', 'email', 'phone',
            'birth_year', 'birth_date', 'address', 'license_number'
        ];

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

        $piiFields = AnalyticsConfig::PII_FIELDS ?? [
            'firstname', 'lastname', 'email', 'phone',
            'birth_year', 'birth_date', 'rider_id', 'name',
            'address', 'license_number'
        ];

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
                id, export_uid, export_type, export_format, filename,
                exported_at, season_year, row_count, contains_pii,
                snapshot_id, status
            FROM analytics_exports
            WHERE exported_by = ?
            ORDER BY exported_at DESC
            LIMIT ?
        ");
        $stmt->execute([$userId, $limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Hamta export by UID
     *
     * @param string $exportUid Export UID
     * @return array|null Export data
     */
    public function getExportByUid(string $exportUid): ?array {
        $stmt = $this->pdo->prepare("
            SELECT * FROM analytics_exports WHERE export_uid = ?
        ");
        $stmt->execute([$exportUid]);
        $export = $stmt->fetch(PDO::FETCH_ASSOC);

        return $export ?: null;
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
                COUNT(DISTINCT export_type) as export_types,
                SUM(CASE WHEN snapshot_id IS NOT NULL THEN 1 ELSE 0 END) as reproducible_exports
            FROM analytics_exports
            WHERE $dateFilter
        ");

        $stats = $stmt->fetch(PDO::FETCH_ASSOC);

        // Reproducibility rate
        $stats['reproducibility_rate'] = $stats['total_exports'] > 0
            ? round(($stats['reproducible_exports'] / $stats['total_exports']) * 100, 1)
            : 0;

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
     * Verifiera export by UID
     *
     * @param string $exportUid Export UID
     * @param array $data Data att verifiera
     * @return array Verification result
     */
    public function verifyExportByUid(string $exportUid, array $data): array {
        $export = $this->getExportByUid($exportUid);

        if (!$export) {
            return [
                'valid' => false,
                'error' => 'Export not found',
            ];
        }

        $currentFingerprint = $this->calculateFingerprint($data);
        $matches = $currentFingerprint === $export['data_fingerprint'];

        return [
            'valid' => $matches,
            'export_uid' => $exportUid,
            'original_fingerprint' => $export['data_fingerprint'],
            'current_fingerprint' => $currentFingerprint,
            'exported_at' => $export['exported_at'],
            'snapshot_id' => $export['snapshot_id'],
            'can_reproduce' => !empty($export['snapshot_id']),
        ];
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

    /**
     * Hamta manifest by UID
     *
     * @param string $exportUid Export UID
     * @return array|null Manifest
     */
    public function getManifestByUid(string $exportUid): ?array {
        $stmt = $this->pdo->prepare("
            SELECT manifest FROM analytics_exports WHERE export_uid = ?
        ");
        $stmt->execute([$exportUid]);
        $manifest = $stmt->fetchColumn();

        return $manifest ? json_decode($manifest, true) : null;
    }

    /**
     * Satt rate limits (for admin/testing)
     *
     * @param int $hourly
     * @param int $daily
     */
    public function setRateLimits(int $hourly, int $daily): void {
        $this->hourlyRateLimit = $hourly;
        $this->dailyRateLimit = $daily;
    }

    /**
     * Inaktivera snapshot requirement (for migration/legacy)
     *
     * @param bool $require
     */
    public function setRequireSnapshotId(bool $require): void {
        $this->requireSnapshotId = $require;
    }
}
