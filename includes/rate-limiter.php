<?php
/**
 * TheHUB Rate Limiter
 * Prevents abuse of email-sending endpoints and other sensitive actions
 * Uses file-based storage for simplicity (no database required)
 */

/**
 * Check if an action is rate limited
 *
 * @param string $action Action identifier (e.g., 'forgot_password', 'activate_account')
 * @param string $identifier Unique identifier (IP address or email)
 * @param int $maxAttempts Maximum attempts allowed
 * @param int $windowSeconds Time window in seconds
 * @return bool True if rate limited (should block), false if allowed
 */
function is_rate_limited(string $action, string $identifier, int $maxAttempts = 5, int $windowSeconds = 3600): bool {
    $storageDir = __DIR__ . '/../storage/rate-limits';

    // Create storage directory if it doesn't exist
    if (!is_dir($storageDir)) {
        @mkdir($storageDir, 0755, true);
    }

    // Create a safe filename
    $hash = md5($action . ':' . $identifier);
    $file = $storageDir . '/' . $hash . '.json';

    $now = time();
    $attempts = [];

    // Read existing attempts
    if (file_exists($file)) {
        $data = @json_decode(file_get_contents($file), true);
        if (is_array($data)) {
            // Filter out attempts outside the window
            $attempts = array_filter($data, function($timestamp) use ($now, $windowSeconds) {
                return ($now - $timestamp) < $windowSeconds;
            });
        }
    }

    // Check if rate limited
    if (count($attempts) >= $maxAttempts) {
        return true; // Rate limited
    }

    return false;
}

/**
 * Record an attempt for rate limiting
 *
 * @param string $action Action identifier
 * @param string $identifier Unique identifier (IP or email)
 * @param int $windowSeconds Time window for cleanup
 */
function record_rate_limit_attempt(string $action, string $identifier, int $windowSeconds = 3600): void {
    $storageDir = __DIR__ . '/../storage/rate-limits';

    if (!is_dir($storageDir)) {
        @mkdir($storageDir, 0755, true);
    }

    $hash = md5($action . ':' . $identifier);
    $file = $storageDir . '/' . $hash . '.json';

    $now = time();
    $attempts = [];

    // Read existing attempts
    if (file_exists($file)) {
        $data = @json_decode(file_get_contents($file), true);
        if (is_array($data)) {
            // Keep only attempts within window
            $attempts = array_filter($data, function($timestamp) use ($now, $windowSeconds) {
                return ($now - $timestamp) < $windowSeconds;
            });
        }
    }

    // Add new attempt
    $attempts[] = $now;

    // Save
    @file_put_contents($file, json_encode(array_values($attempts)));
}

/**
 * Get remaining attempts before rate limited
 *
 * @param string $action Action identifier
 * @param string $identifier Unique identifier
 * @param int $maxAttempts Maximum attempts allowed
 * @param int $windowSeconds Time window in seconds
 * @return int Remaining attempts
 */
function get_remaining_attempts(string $action, string $identifier, int $maxAttempts = 5, int $windowSeconds = 3600): int {
    $storageDir = __DIR__ . '/../storage/rate-limits';
    $hash = md5($action . ':' . $identifier);
    $file = $storageDir . '/' . $hash . '.json';

    $now = time();
    $attempts = [];

    if (file_exists($file)) {
        $data = @json_decode(file_get_contents($file), true);
        if (is_array($data)) {
            $attempts = array_filter($data, function($timestamp) use ($now, $windowSeconds) {
                return ($now - $timestamp) < $windowSeconds;
            });
        }
    }

    return max(0, $maxAttempts - count($attempts));
}

/**
 * Get client IP address
 */
function get_client_ip(): string {
    // Check for forwarded IP (behind proxy/load balancer)
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        return trim($ips[0]);
    }

    if (!empty($_SERVER['HTTP_X_REAL_IP'])) {
        return $_SERVER['HTTP_X_REAL_IP'];
    }

    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

/**
 * Clean up old rate limit files (call periodically)
 */
function cleanup_rate_limits(int $maxAgeSeconds = 86400): int {
    $storageDir = __DIR__ . '/../storage/rate-limits';

    if (!is_dir($storageDir)) {
        return 0;
    }

    $cleaned = 0;
    $now = time();

    foreach (glob($storageDir . '/*.json') as $file) {
        if (($now - filemtime($file)) > $maxAgeSeconds) {
            @unlink($file);
            $cleaned++;
        }
    }

    return $cleaned;
}
