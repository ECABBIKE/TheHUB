<?php
// modules/cyclists/CyclistModel.php
require_once __DIR__ . '/../../core/Database.php';

final class CyclistModel
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->pdo();
    }

    public function all(): array
    {
        $stmt = $this->db->query('SELECT * FROM cyclists ORDER BY last_name, first_name');
        return $stmt->fetchAll();
    }

    public function find(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM cyclists WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function create(array $data): void
    {
        $stmt = $this->db->prepare('
            INSERT INTO cyclists (first_name, last_name, uci_id, club)
            VALUES (:first_name, :last_name, :uci_id, :club)
        ');
        $stmt->execute([
            'first_name' => $data['first_name'],
            'last_name'  => $data['last_name'],
            'uci_id'     => $data['uci_id'] ?? null,
            'club'       => $data['club'] ?? null,
        ]);
    }

    public function update(int $id, array $data): void
    {
        $stmt = $this->db->prepare('
            UPDATE cyclists
            SET first_name = :first_name,
                last_name = :last_name,
                uci_id = :uci_id,
                club = :club
            WHERE id = :id
        ');
        $stmt->execute([
            'first_name' => $data['first_name'],
            'last_name'  => $data['last_name'],
            'uci_id'     => $data['uci_id'] ?? null,
            'club'       => $data['club'] ?? null,
            'id'         => $id,
        ]);
    }

    public function delete(int $id): void
    {
        $stmt = $this->db->prepare('DELETE FROM cyclists WHERE id = ?');
        $stmt->execute([$id]);
    }
}
