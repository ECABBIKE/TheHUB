<?php
require_once __DIR__ . '/../config.php';
require_admin();

// Use global $pdo instead of getDB()
global $pdo;

$current_admin = get_admin_user();

// Demo mode disabled
$is_demo = false;

// Initialize message variables
$message = '';
$messageType = 'info';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();

    $action = $_POST['action'] ?? '';

    if ($action === 'create' || $action === 'update') {
        // Validate required fields
        $firstname = trim($_POST['firstname'] ?? '');
        $lastname = trim($_POST['lastname'] ?? '');

        if (empty($firstname) || empty($lastname)) {
            $message = 'Förnamn och efternamn är obligatoriska';
            $messageType = 'error';
        } else {
            // Prepare rider data
            $riderData = [
                'firstname' => $firstname,
                'lastname' => $lastname,
                'birth_year' => !empty($_POST['birth_year']) ? intval($_POST['birth_year']) : null,
                'gender' => $_POST['gender'] ?? null,
                'email' => trim($_POST['email'] ?? ''),
                'phone' => trim($_POST['phone'] ?? ''),
                'city' => trim($_POST['city'] ?? ''),
                'club_id' => !empty($_POST['club_id']) ? intval($_POST['club_id']) : null,
                'license_number' => trim($_POST['license_number'] ?? ''),
                'license_type' => $_POST['license_type'] ?? null,
                'license_category' => trim($_POST['license_category'] ?? ''),
                'discipline' => $_POST['discipline'] ?? null,
                'license_valid_until' => !empty($_POST['license_valid_until']) ? trim($_POST['license_valid_until']) : null,
                'active' => isset($_POST['active']) ? 1 : 0,
                'notes' => trim($_POST['notes'] ?? ''),
            ];

            try {
                if ($action === 'create') {
                    // Build INSERT query
                    $fields = array_keys($riderData);
                    $placeholders = array_fill(0, count($fields), '?');
                    
                    $sql = "INSERT INTO riders (" . implode(', ', $fields) . ") VALUES (" . implode(', ', $placeholders) . ")";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute(array_values($riderData));
                    
                    $message = 'Deltagare skapad!';
                    $messageType = 'success';
                } else {
                    // UPDATE query
                    $id = intval($_POST['id']);
                    $sets = [];
                    $values = [];
                    
                    foreach ($riderData as $key => $value) {
                        $sets[] = "$key = ?";
                        $values[] = $value;
                    }
                    $values[] = $id;
                    
                    $sql = "UPDATE riders SET " . implode(', ', $sets) . " WHERE id = ?";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute($values);
                    
                    $message = 'Deltagare uppdaterad!';
                    $messageType = 'success';
                }
            } catch (Exception $e) {
                $message = 'Ett fel uppstod: ' . $e->getMessage();
                $messageType = 'error';
            }
        }
    } elseif ($action === 'delete') {
        $id = intval($_POST['id']);
        try {
            $stmt = $pdo->prepare("DELETE FROM riders WHERE id = ?");
            $stmt->execute([$id]);
            $message = 'Deltagare borttagen!';
            $messageType = 'success';
        } catch (Exception $e) {
            $message = 'Ett fel uppstod: ' . $e->getMessage();
            $messageType = 'error';
        }
    }
}

// Handle search and filters
$search = $_GET['search'] ?? '';
$club_id = isset($_GET['club_id']) && is_numeric($_GET['club_id']) ? intval($_GET['club_id']) : null;

// Fetch clubs for dropdown
$clubs = $pdo->query("SELECT id, name FROM clubs ORDER BY name")->fetchAll();

// Check if editing a rider
$editRider = null;
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    $stmt = $pdo->prepare("SELECT * FROM riders WHERE id = ?");
    $stmt->execute([intval($_GET['edit'])]);
    $editRider = $stmt->fetch();
}

// Get selected club info if filtering by club
$selectedClub = null;
if ($club_id) {
    $stmt = $pdo->prepare("SELECT * FROM clubs WHERE id = ?");
    $stmt->execute([$club_id]);
    $selectedClub = $stmt->fetch();
}

// Build query filters
$where = [];
$params = [];

if ($search) {
    $where[] = "(CONCAT(c.firstname, ' ', c.lastname) LIKE ? OR c.license_number LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if ($club_id) {
    $where[] = "c.club_id = ?";
    $params[] = $club_id;
}

$whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// Get riders from database
$sql = "SELECT
            c.id,
            c.firstname,
            c.lastname,
            c.birth_year,
            c.gender,
            c.license_number,
            c.license_type,
            c.license_category,
            c.discipline,
            c.license_valid_until,
            c.active,
            cl.name as club_name,
            cl.id as club_id
        FROM riders c
        LEFT JOIN clubs cl ON c.club_id = cl.id
        $whereClause
        ORDER BY c.lastname, c.firstname
        LIMIT 1000";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$riders = $stmt->fetchAll();

// Helper functions
if (!function_exists('calculateAge')) {
    function calculateAge($birthYear) {
        if (empty($birthYear)) return null;
        return date('Y') - $birthYear;
    }
}

if (!function_exists('checkLicense')) {
    function checkLicense($rider) {
        if (empty($rider['license_valid_until']) || $rider['license_valid_until'] === '0000-00-00') {
            return ['class' => 'gs-badge-secondary', 'message' => 'Ingen giltighetstid'];
        }
        
        $validUntil = strtotime($rider['license_valid_until']);
        $now = time();
        
        if ($validUntil < $now) {
            return ['class' => 'gs-badge-danger', 'message' => 'Utgången'];
        } elseif ($validUntil < strtotime('+30 days')) {
            return ['class' => 'gs-badge-warning', 'message' => 'Löper snart ut'];
        } else {
            return ['class' => 'gs-badge-success', 'message' => 'Giltig'];
        }
    }
}

$pageTitle = 'Deltagare';
$pageType = 'admin';
include __DIR__ . '/../includes/layout-header.php';
?>

<!-- REST OF FILE IS IDENTICAL - KEEP YOUR EXISTING HTML/FORM CODE -->
<!-- Just copy everything from your current file starting from <main> tag -->

<?php
// ... YOUR EXISTING HTML CODE HERE (all the forms, tables, etc.)
?>

<?php include __DIR__ . '/../includes/layout-footer.php'; ?>
