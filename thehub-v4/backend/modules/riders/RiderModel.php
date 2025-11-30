<?php
// backend/modules/riders/RiderModel.php
require_once __DIR__ . '/../../core/Database.php';

final class RiderModel
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->pdo();
    }

    public function all(string $search = null, string $discipline = null, $active = null, $club = null): array
    {
        $sql = "
            SELECT id, firstname, lastname, gravity_id, club_id, active, disciplines, license_number 
            FROM riders 
            WHERE 1=1
        ";

        $params = [];

        if (!empty($search)) {
            $sql .= " AND (
                firstname LIKE :s 
                OR lastname LIKE :s 
                OR gravity_id LIKE :s
                OR license_number LIKE :s
            )";
            $params[':s'] = '%' . $search . '%';
        }

        if ($active !== null && ($active === '1' || $active === '0')) {
            $sql .= " AND active = :a";
            $params[':a'] = (int)$active;
        }

        if (!empty($discipline)) {
            $sql .= " AND disciplines LIKE :d";
            $params[':d'] = '%' . $discipline . '%';
        }

        if (!empty($club)) {
            $sql .= " AND club_id = :club";
            $params[':club'] = $club;
        }

        $sql .= " ORDER BY lastname ASC, firstname ASC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

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

    public function clubs(): array
    {
        $stmt = $this->db->query("SELECT DISTINCT club_id FROM riders WHERE club_id IS NOT NULL ORDER BY club_id ASC");
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
}
