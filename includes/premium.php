<?php
/**
 * Premium Membership Helper Functions
 * Check premium status and retrieve rider sponsor data
 */

/**
 * Check if a rider has an active premium membership
 * @param PDO $pdo Database connection
 * @param int $riderId Rider ID
 * @return bool True if rider has active premium
 */
function isPremiumMember(PDO $pdo, int $riderId): bool {
    static $cache = [];
    if (isset($cache[$riderId])) {
        return $cache[$riderId];
    }

    try {
        // Check by rider_id first, then fallback to email
        $stmt = $pdo->prepare("
            SELECT ms.id FROM member_subscriptions ms
            WHERE (ms.rider_id = ? OR ms.email = (SELECT email FROM riders WHERE id = ? LIMIT 1))
              AND ms.stripe_subscription_status IN ('active', 'trialing')
              AND (ms.current_period_end IS NULL OR ms.current_period_end > NOW())
            LIMIT 1
        ");
        $stmt->execute([$riderId, $riderId]);
        $cache[$riderId] = (bool) $stmt->fetch();
    } catch (PDOException $e) {
        // Table might not exist yet
        $cache[$riderId] = false;
    }

    return $cache[$riderId];
}

/**
 * Get premium subscription details for a rider
 * @param PDO $pdo Database connection
 * @param int $riderId Rider ID
 * @return array|null Subscription data or null
 */
function getPremiumSubscription(PDO $pdo, int $riderId): ?array {
    try {
        $stmt = $pdo->prepare("
            SELECT ms.*, mp.name as plan_name, mp.benefits, mp.billing_interval
            FROM member_subscriptions ms
            JOIN membership_plans mp ON ms.plan_id = mp.id
            WHERE (ms.rider_id = ? OR ms.email = (SELECT email FROM riders WHERE id = ? LIMIT 1))
              AND ms.stripe_subscription_status IN ('active', 'trialing')
              AND (ms.current_period_end IS NULL OR ms.current_period_end > NOW())
            ORDER BY ms.created_at DESC
            LIMIT 1
        ");
        $stmt->execute([$riderId, $riderId]);
        $sub = $stmt->fetch(PDO::FETCH_ASSOC);
        return $sub ?: null;
    } catch (PDOException $e) {
        return null;
    }
}

/**
 * Get rider's personal sponsors
 * @param PDO $pdo Database connection
 * @param int $riderId Rider ID
 * @return array List of active sponsors
 */
function getRiderSponsors(PDO $pdo, int $riderId): array {
    try {
        $stmt = $pdo->prepare("
            SELECT id, name, logo_url, website_url, sort_order
            FROM rider_sponsors
            WHERE rider_id = ? AND active = 1
            ORDER BY sort_order ASC, name ASC
        ");
        $stmt->execute([$riderId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return [];
    }
}
