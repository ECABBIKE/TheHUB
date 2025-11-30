<?php

class RiderModel
{
    public static function all(int $limit = 500): array
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare("SELECT * FROM riders ORDER BY id LIMIT :limit");
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public static function find(int $id): ?array
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare("SELECT * FROM riders WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }
}
