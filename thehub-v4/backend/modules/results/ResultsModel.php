<?php

class ResultsModel
{
    public static function forEvent(int $eventId): array
    {
        $pdo = Database::pdo();
        // NOTE: This assumes a generic results table. Adjust to your schema.
        $sql = "SELECT * FROM results WHERE event_id = :event_id ORDER BY category, position";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['event_id' => $eventId]);
        return $stmt->fetchAll();
    }
}
