<?php
// modules/riders/RiderModel.php
require_once __DIR__ . '/../../core/Database.php';

final class RiderModel
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->pdo();
    }

    public function all(): array
    {
        $stmt = $this->db->query("
            SELECT id, firstname, lastname, gravity_id, club_id, active, license_number, created_at
            FROM riders
            ORDER BY lastname, firstname
        ");
        return $stmt->fetchAll();
    }

    public function find(int $id): ?array
    {
        $stmt = $this->db->prepare("
            SELECT *
            FROM riders
            WHERE id = ?
            LIMIT 1
        ");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }
}
