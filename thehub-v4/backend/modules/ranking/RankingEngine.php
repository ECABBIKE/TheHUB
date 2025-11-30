<?php

class RankingEngine
{
    /**
     * Simple placeholder: sums points per rider_id from a results table.
     * Adjust to match your real ranking logic & series mapping.
     */
    public static function seriesRanking(string $seriesKey): array
    {
        $pdo = Database::pdo();

        // This is intentionally generic. You can swap to your series tables later.
        $sql = "
            SELECT rider_id, SUM(points) AS total_points
            FROM series_results
            WHERE series_key = :series
            GROUP BY rider_id
            ORDER BY total_points DESC
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute(['series' => $seriesKey]);
        return $stmt->fetchAll();
    }
}
