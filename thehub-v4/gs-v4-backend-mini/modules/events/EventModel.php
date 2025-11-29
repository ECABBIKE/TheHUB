<?php
require_once __DIR__ . '/../../core/Database.php';

final class EventModel
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->pdo();
    }

    public function all(): array
    {
        // Read-anything table, dynamic structure
        $stmt = $this->db->query("SELECT * FROM events ORDER BY id DESC");
        return $stmt->fetchAll();
    }

    public function find(int $id): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM events WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }
}
