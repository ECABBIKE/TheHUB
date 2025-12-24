<?php
/**
 * Club matching utilities
 * Smart matching for club names during import to reduce duplicates
 */

/**
 * Normalize club name for comparison
 * Handles variations like: CK Fix, Ck Fix, Cykelklubben Fix, Cykelklubb Fix
 */
function normalizeClubName($name) {
    $name = mb_strtolower(trim($name), 'UTF-8');

    // Remove common prefixes/suffixes (order matters - longer patterns first)
    $patterns = [
        '/^cykelklubben\s+/u',           // "Cykelklubben Fix" -> "fix"
        '/^cykelklubb\s+/u',             // "Cykelklubb Fix" -> "fix"
        '/^cykelföreningen\s+/u',        // "Cykelföreningen X" -> "x"
        '/^ok\s+/u',                     // "OK Tyr" -> "tyr"
        '/^ck\s+/u',                     // "CK Fix" -> "fix"
        '/^if\s+/u',                     // "IF Ceres" -> "ceres"
        '/^ik\s+/u',                     // "IK X" -> "x"
        '/^sk\s+/u',                     // "SK X" -> "x"
        '/^fk\s+/u',                     // "FK X" -> "x"
        '/^bk\s+/u',                     // "BK X" -> "x"
        '/\s+ck$/u',                     // "Fix CK" -> "fix"
        '/\s+ok$/u',                     // "Tyr OK" -> "tyr"
        '/\s+cykelklubb$/u',             // "Fix Cykelklubb" -> "fix"
        '/\s+cykelklubben$/u',           // "Fix Cykelklubben" -> "fix"
        '/\s+if$/u',                     // "Fix IF" -> "fix"
        '/\s+ik$/u',                     // "X IK" -> "X"
        '/\s+sk$/u',                     // "X SK" -> "X"
        '/\s+mtb$/u',                    // "Fix MTB" -> "fix"
        '/\s+enduro$/u',                 // "Fix Enduro" -> "fix"
        '/\s+idrottssällskap$/u',
        '/\s+idrottsällskap$/u',
        '/\s+idrottsförening$/u',
    ];

    foreach ($patterns as $pattern) {
        $name = preg_replace($pattern, '', $name);
    }

    // Replace Swedish chars for comparison
    $name = preg_replace('/[åä]/u', 'a', $name);
    $name = preg_replace('/[ö]/u', 'o', $name);
    $name = preg_replace('/[é]/u', 'e', $name);
    $name = preg_replace('/[ø]/u', 'o', $name);
    $name = preg_replace('/[æ]/u', 'ae', $name);

    // Remove non-alphanumeric (keeps only letters and numbers)
    $name = preg_replace('/[^a-z0-9]/u', '', $name);

    // Remove trailing 's' to match singular/plural (e.g., "masters" vs "master")
    $name = preg_replace('/s$/u', '', $name);

    return $name;
}

/**
 * Find club by name with smart matching
 *
 * Tries matching in this order:
 * 1. Exact case-insensitive match
 * 2. Normalized name match (handles abbreviations like CK/Ck/Cykelklubben)
 * 3. Partial match if no exact match found
 *
 * @param object $db Database connection
 * @param string $clubName Name to search for
 * @return array|null Matching club row or null
 */
function findClubByName($db, $clubName) {
    $clubName = trim($clubName);
    if (empty($clubName)) {
        return null;
    }

    // 1. Try exact case-insensitive match first
    $club = $db->getRow(
        "SELECT id, name FROM clubs WHERE LOWER(name) = LOWER(?)",
        [$clubName]
    );
    if ($club) {
        return $club;
    }

    // 2. Try normalized matching (handles CK/Ck/Cykelklubben variants)
    $normalizedSearch = normalizeClubName($clubName);

    // Get all clubs and normalize them for comparison
    // (This is cached per request so it's not too expensive)
    static $normalizedClubCache = null;
    if ($normalizedClubCache === null) {
        $allClubs = $db->getAll("SELECT id, name FROM clubs WHERE active = 1");
        $normalizedClubCache = [];
        foreach ($allClubs as $c) {
            $normalized = normalizeClubName($c['name']);
            if (!isset($normalizedClubCache[$normalized])) {
                $normalizedClubCache[$normalized] = $c;
            }
        }
    }

    // Check for normalized match
    if (isset($normalizedClubCache[$normalizedSearch])) {
        return $normalizedClubCache[$normalizedSearch];
    }

    // 3. Try Levenshtein distance for very similar names (typos)
    // Only if the normalized name is long enough
    if (strlen($normalizedSearch) >= 4) {
        $bestMatch = null;
        $bestDistance = PHP_INT_MAX;

        foreach ($normalizedClubCache as $normalized => $c) {
            if (strlen($normalized) < 4) continue;

            $distance = levenshtein($normalizedSearch, $normalized);

            // Only accept if distance is <= 2 and the match is significantly better
            if ($distance <= 2 && $distance < $bestDistance) {
                $bestDistance = $distance;
                $bestMatch = $c;
            }
        }

        if ($bestMatch) {
            return $bestMatch;
        }
    }

    // 4. Fallback: try partial match (LIKE) - but be more careful
    // Only if the search term is long enough to be meaningful
    if (strlen($clubName) >= 5) {
        $club = $db->getRow(
            "SELECT id, name FROM clubs WHERE LOWER(name) LIKE LOWER(?)",
            ['%' . $clubName . '%']
        );
        if ($club) {
            return $club;
        }
    }

    return null;
}

/**
 * Check if a club name matches an existing club
 * Returns the matched club or null
 *
 * This is a simpler version for preview/stats that doesn't create clubs
 */
function checkClubMatch($db, $clubName) {
    return findClubByName($db, $clubName);
}
