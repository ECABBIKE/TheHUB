<?php
// Core functions only

function h($str) {
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
}

function redirect($url) {
    if (strpos($url, '/') !== 0) {
        $url = '/' . $url;
    }
    header("Location: " . $url);
    exit;
}

function calculateAge($birthYear) {
    if (empty($birthYear)) return null;
    return date('Y') - $birthYear;
}

function checkLicense($rider) {
    if (empty($rider['license_valid_until']) || $rider['license_valid_until'] === '0000-00-00') {
        return array('class' => 'gs-badge-secondary', 'message' => 'Ingen giltighetstid', 'valid' => false);
    }

    $validUntil = strtotime($rider['license_valid_until']);
    $now = time();

    if ($validUntil < $now) {
        return array('class' => 'gs-badge-danger', 'message' => 'Utgången', 'valid' => false);
    } elseif ($validUntil < strtotime('+30 days')) {
        return array('class' => 'gs-badge-warning', 'message' => 'Löper snart ut', 'valid' => true);
    } else {
        return array('class' => 'gs-badge-success', 'message' => 'Giltig', 'valid' => true);
    }
}

/**
 * Generate a unique advent_id for an event
 * Format: event-YYYY-NNN where YYYY is year and NNN is sequential number
 *
 * @param PDO $pdo Database connection
 * @param string $year Year for the event (YYYY format)
 * @return string Generated advent_id
 */
function generateEventAdventId($pdo, $year = null) {
    if ($year === null) {
        $year = date('Y');
    }

    // Find the highest number used for this year
    $stmt = $pdo->prepare("
        SELECT advent_id
        FROM events
        WHERE advent_id LIKE ?
        ORDER BY advent_id DESC
        LIMIT 1
    ");
    $stmt->execute(["event-$year-%"]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($existing && preg_match('/event-\d{4}-(\d+)/', $existing['advent_id'], $matches)) {
        $nextNum = intval($matches[1]) + 1;
    } else {
        $nextNum = 1;
    }

    // Format with leading zeros (3 digits)
    return sprintf('event-%s-%03d', $year, $nextNum);
}

class DatabaseWrapper {
    private $pdo;
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
    }
    
    public function getAll($sql, $params = array()) {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
    
    public function getRow($sql, $params = array()) {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch();
    }
    
    public function insert($table, $data) {
        $fields = array_keys($data);
        $placeholders = array_fill(0, count($fields), '?');
        
        $sql = "INSERT INTO " . $table . " (" . implode(', ', $fields) . ") VALUES (" . implode(', ', $placeholders) . ")";
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute(array_values($data));
    }
    
    public function update($table, $data, $where, $params = array()) {
        $sets = array();
        $values = array();
        
        foreach ($data as $key => $value) {
            $sets[] = $key . " = ?";
            $values[] = $value;
        }
        
        $sql = "UPDATE " . $table . " SET " . implode(', ', $sets) . " WHERE " . $where;
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute(array_merge($values, $params));
    }
    
    public function delete($table, $where, $params = array()) {
        $sql = "DELETE FROM " . $table . " WHERE " . $where;
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute($params);
    }
}

function getDB() {
    global $pdo;
    static $db = null;
    
    if ($db === null) {
        $db = new DatabaseWrapper($pdo);
    }
    
    return $db;
}

function checkCsrf() {
    return check_csrf();
}

function get_current_admin() {
    return get_admin_user();
}
?>
