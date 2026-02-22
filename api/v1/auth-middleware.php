<?php
/**
 * GravityTiming API - Auth Middleware
 *
 * Validates API key + secret from request headers.
 * Rate limiting: 60 requests/minute per key.
 *
 * Usage in endpoints:
 *   require_once __DIR__ . '/auth-middleware.php';
 *   $auth = validateApiRequest(); // dies with JSON error on failure
 *   // $auth contains: id, name, scope, event_ids, series_ids
 */

// Mark as API request to skip session/HTTPS redirect in config.php
define('HUB_API_REQUEST', true);

require_once __DIR__ . '/../../config.php';

/**
 * Send JSON response and exit
 */
function apiResponse($data, int $httpCode = 200): void {
    http_response_code($httpCode);
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Headers: X-API-Key, X-API-Secret, Content-Type');
    header('Access-Control-Allow-Methods: GET, POST, PATCH, DELETE, OPTIONS');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

/**
 * Send error response and exit
 */
function apiError(string $message, int $httpCode = 400, array $extra = []): void {
    $response = array_merge(['success' => false, 'error' => $message], $extra);
    apiResponse($response, $httpCode);
}

/**
 * Handle CORS preflight requests
 */
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Headers: X-API-Key, X-API-Secret, Content-Type');
    header('Access-Control-Allow-Methods: GET, POST, PATCH, DELETE, OPTIONS');
    header('Access-Control-Max-Age: 86400');
    http_response_code(204);
    exit;
}

/**
 * Check rate limit for an API key
 * Returns true if within limit, false if exceeded
 */
function checkRateLimit(PDO $pdo, int $apiKeyId): bool {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM api_request_log
        WHERE api_key_id = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 MINUTE)
    ");
    $stmt->execute([$apiKeyId]);
    $count = (int)$stmt->fetchColumn();
    return $count < 60;
}

/**
 * Log an API request
 */
function logApiRequest(PDO $pdo, int $apiKeyId, string $endpoint, string $method, ?int $eventId, int $responseCode, int $bodySize = 0): void {
    try {
        $startTime = $_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true);
        $responseTimeMs = (int)((microtime(true) - $startTime) * 1000);

        $stmt = $pdo->prepare("
            INSERT INTO api_request_log (api_key_id, endpoint, method, event_id, response_code, request_body_size, response_time_ms, ip_address)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $apiKeyId,
            $endpoint,
            $method,
            $eventId,
            $responseCode,
            $bodySize,
            $responseTimeMs,
            $_SERVER['REMOTE_ADDR'] ?? null
        ]);
    } catch (Exception $e) {
        error_log("API log error: " . $e->getMessage());
    }
}

/**
 * Validate API request and return auth info
 * Dies with JSON error on failure
 *
 * @param string|null $requiredScope Minimum required scope (timing, readonly, admin)
 * @return array Auth info: id, name, scope, event_ids, series_ids
 */
function validateApiRequest(?string $requiredScope = 'timing'): array {
    $pdo = $GLOBALS['pdo'];

    // Read headers
    $apiKey = $_SERVER['HTTP_X_API_KEY'] ?? '';
    $apiSecret = $_SERVER['HTTP_X_API_SECRET'] ?? '';

    if (empty($apiKey) || empty($apiSecret)) {
        apiError('API-nyckel och hemlighet krävs (X-API-Key, X-API-Secret)', 401);
    }

    // Look up key
    $stmt = $pdo->prepare("
        SELECT id, name, api_secret_hash, scope, event_ids, series_ids, expires_at, active
        FROM api_keys
        WHERE api_key = ?
    ");
    $stmt->execute([$apiKey]);
    $key = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$key) {
        apiError('Ogiltig API-nyckel', 401);
    }

    // Check active
    if (!$key['active']) {
        apiError('API-nyckeln är inaktiverad', 403);
    }

    // Check expiry
    if ($key['expires_at'] && strtotime($key['expires_at']) < time()) {
        apiError('API-nyckeln har gått ut', 403);
    }

    // Verify secret (bcrypt)
    if (!password_verify($apiSecret, $key['api_secret_hash'])) {
        apiError('Felaktig API-hemlighet', 401);
    }

    // Check scope
    $scopeHierarchy = ['readonly' => 1, 'timing' => 2, 'admin' => 3];
    $keyLevel = $scopeHierarchy[$key['scope']] ?? 0;
    $requiredLevel = $scopeHierarchy[$requiredScope] ?? 0;

    if ($keyLevel < $requiredLevel) {
        apiError("Otillräcklig behörighet. Kräver scope: $requiredScope", 403);
    }

    // Rate limiting
    if (!checkRateLimit($pdo, $key['id'])) {
        apiError('Rate limit överskriden (60 anrop/minut)', 429);
    }

    // Update last_used_at
    $stmt = $pdo->prepare("UPDATE api_keys SET last_used_at = NOW() WHERE id = ?");
    $stmt->execute([$key['id']]);

    // Parse event/series restrictions
    $eventIds = $key['event_ids'] ? json_decode($key['event_ids'], true) : null;
    $seriesIds = $key['series_ids'] ? json_decode($key['series_ids'], true) : null;

    return [
        'id' => $key['id'],
        'name' => $key['name'],
        'scope' => $key['scope'],
        'event_ids' => $eventIds,    // null = all events
        'series_ids' => $seriesIds   // null = all series
    ];
}

/**
 * Check if the API key has access to a specific event
 */
function checkEventAccess(array $auth, int $eventId): bool {
    // null = access to all events
    if ($auth['event_ids'] === null) {
        return true;
    }
    return in_array($eventId, $auth['event_ids']);
}

/**
 * Get event ID from URL path: /api/v1/events/{id}/...
 * Returns null if not found
 */
function getEventIdFromPath(): ?int {
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    if (preg_match('#/api/v1/events/(\d+)#', $uri, $matches)) {
        return (int)$matches[1];
    }
    return null;
}

/**
 * Parse JSON request body
 */
function getJsonBody(): array {
    $raw = file_get_contents('php://input');
    if (empty($raw)) {
        return [];
    }
    $data = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        apiError('Ogiltig JSON i request body: ' . json_last_error_msg(), 400);
    }
    return $data ?: [];
}
