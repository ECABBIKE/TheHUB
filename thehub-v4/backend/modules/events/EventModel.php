<?php

class EventModel
{
    public static function all(int $limit = 200): array
    {
        $pdo = Database::pdo();
        // NOTE: Adjust column names if your table differs.
        $sql = "SELECT * FROM events ORDER BY event_date DESC LIMIT :limit";
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public static function find(int $id): ?array
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare("SELECT * FROM events WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }
}
