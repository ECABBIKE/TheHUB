<?php
require_once __DIR__ . '/../config.php';
require_admin();

$db = getDB();
$current_admin = get_current_admin();

// Demo mode check
$is_demo = ($db->getConnection() === null);

// Initialize message variables
$message = '';
$messageType = 'info';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$is_demo) {
    checkCsrf();

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
                    $db->insert('riders', $riderData);
                    $message = 'Deltagare skapad!';
                    $messageType = 'success';
                } else {
                    $id = intval($_POST['id']);
                    $db->update('riders', $riderData, 'id = ?', [$id]);
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
            $db->delete('riders', 'id = ?', [$id]);
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

// Fetch clubs for dropdown (if not in demo mode)
$clubs = [];
$editRider = null;
$selectedClub = null;
if (!$is_demo) {
    $clubs = $db->getAll("SELECT id, name FROM clubs ORDER BY name");

    // Check if editing a rider
    if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
        $editRider = $db->getOne("SELECT * FROM riders WHERE id = ?", [intval($_GET['edit'])]);
    }

    // Get selected club info if filtering by club
    if ($club_id) {
        $selectedClub = $db->getOne("SELECT * FROM clubs WHERE id = ?", [$club_id]);
    }
}

if ($is_demo) {
    // Demo riders
    $all_riders = [
        ['id' => 1, 'firstname' => 'Erik', 'lastname' => 'Andersson', 'birth_year' => 1995, 'gender' => 'M', 'license_number' => 'SWE19950101', 'license_type' => 'Elite', 'license_category' => 'Elite Men', 'discipline' => 'MTB', 'license_valid_until' => '2025-12-31', 'active' => 1, 'club_name' => 'Team GravitySeries', 'club_id' => 1],
        ['id' => 2, 'firstname' => 'Anna', 'lastname' => 'Karlsson', 'birth_year' => 1998, 'gender' => 'F', 'license_number' => 'SWE19980315', 'license_type' => 'Elite', 'license_category' => 'Elite Women', 'discipline' => 'Road', 'license_valid_until' => '2025-12-31', 'active' => 1, 'club_name' => 'CK Olympia', 'club_id' => 2],
        ['id' => 3, 'firstname' => 'Johan', 'lastname' => 'Svensson', 'birth_year' => 1992, 'gender' => 'M', 'license_number' => 'SWE19920812', 'license_type' => 'Elite', 'license_category' => 'Elite Men', 'discipline' => 'MTB', 'license_valid_until' => '2025-12-31', 'active' => 1, 'club_name' => 'Uppsala CK', 'club_id' => 3],
        ['id' => 4, 'firstname' => 'Maria', 'lastname' => 'Lindström', 'birth_year' => 1996, 'gender' => 'F', 'license_number' => 'SWE19960524', 'license_type' => 'Elite', 'license_category' => 'Elite Women', 'discipline' => 'CX', 'license_valid_until' => '2025-12-31', 'active' => 1, 'club_name' => 'Team Sportson', 'club_id' => 4],
        ['id' => 5, 'firstname' => 'Peter', 'lastname' => 'Nilsson', 'birth_year' => 1985, 'gender' => 'M', 'license_number' => 'SWE19850615', 'license_type' => 'Elite', 'license_category' => 'Master Men 35+', 'discipline' => 'MTB', 'license_valid_until' => '2025-12-31', 'active' => 1, 'club_name' => 'IFK Göteborg CK', 'club_id' => 5],
        ['id' => 6, 'firstname' => 'Lisa', 'lastname' => 'Bergman', 'birth_year' => 2006, 'gender' => 'F', 'license_number' => 'SWE20060310', 'license_type' => 'Youth', 'license_category' => 'U19 Women', 'discipline' => 'Road', 'license_valid_until' => '2025-12-31', 'active' => 1, 'club_name' => 'Team GravitySeries', 'club_id' => 1],
    ];

    // Filter by club_id first
    if ($club_id) {
        $riders = array_filter($all_riders, fn($r) => $r['club_id'] == $club_id);
        $riders = array_values($riders);
        if (!empty($riders)) {
            $selectedClub = ['id' => $club_id, 'name' => $riders[0]['club_name']];
        }
    } else {
        $riders = $all_riders;
    }

    // Then filter by search
    if ($search) {
        $riders = array_filter($riders, function($r) use ($search) {
            $name = $r['firstname'] . ' ' . $r['lastname'];
            return stripos($name, $search) !== false || stripos($r['license_number'], $search) !== false;
        });
        $riders = array_values($riders);
    }
} else {
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

    // Get riders
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

    $riders = $db->getAll($sql, $params);
}

$pageTitle = 'Deltagare';
$pageType = 'admin';
include __DIR__ . '/../includes/layout-header.php';
?>

    <main class="gs-content-with-sidebar">
        <div class="gs-container">
            <!-- Header -->
            <div class="gs-flex gs-items-center gs-justify-between gs-mb-xl">
                <h1 class="gs-h1 gs-text-primary">
                    <i data-lucide="users"></i>
                    Deltagare
                </h1>
                <?php if (!$is_demo): ?>
                    <button type="button" class="gs-btn gs-btn-primary" onclick="openRiderModal()">
                        <i data-lucide="plus"></i>
                        Ny Deltagare
                    </button>
                <?php endif; ?>
            </div>

            <!-- Messages -->
            <?php if ($message): ?>
                <div class="gs-alert gs-alert-<?= $messageType ?> gs-mb-lg">
                    <i data-lucide="<?= $messageType === 'success' ? 'check-circle' : ($messageType === 'error' ? 'alert-circle' : 'info') ?>"></i>
                    <?= h($message) ?>
                </div>
            <?php endif; ?>

            <!-- Filter indicator -->
            <?php if ($selectedClub): ?>
                <div class="gs-alert gs-alert-info gs-mb-lg">
                    <i data-lucide="filter"></i>
                    Visar deltagare från <strong><?= h($selectedClub['name']) ?></strong>
                    <a href="/admin/riders.php" class="gs-btn gs-btn-sm gs-btn-outline" style="margin-left: auto;">
                        <i data-lucide="x"></i>
                        Rensa filter
                    </a>
                </div>
            <?php endif; ?>

            <!-- Rider Modal -->
            <?php if (!$is_demo): ?>
                <div id="riderModal" class="gs-modal" style="display: none;">
                    <div class="gs-modal-overlay" onclick="closeRiderModal()"></div>
                    <div class="gs-modal-content" style="max-width: 800px;">
                        <div class="gs-modal-header">
                            <h2 class="gs-modal-title" id="modalTitle">
                                <i data-lucide="user"></i>
                                <span id="modalTitleText">Ny Deltagare</span>
                            </h2>
                            <button type="button" class="gs-modal-close" onclick="closeRiderModal()">
                                <i data-lucide="x"></i>
                            </button>
                        </div>
                        <form method="POST" id="riderForm">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" id="formAction" value="create">
                            <input type="hidden" name="id" id="riderId" value="">

                            <div class="gs-modal-body">
                                <div class="gs-grid gs-grid-cols-1 gs-gap-md">
                                    <!-- Personal Information Section -->
                                    <div class="gs-mb-md">
                                        <h3 class="gs-text-lg gs-font-bold gs-text-primary gs-mb-md">
                                            <i data-lucide="user"></i> Personuppgifter
                                        </h3>
                                    </div>

                                    <!-- First Name (Required) -->
                                    <div class="gs-grid gs-grid-cols-2 gs-gap-md">
                                        <div>
                                            <label for="firstname" class="gs-label">
                                                <i data-lucide="user"></i>
                                                Förnamn <span class="gs-text-error">*</span>
                                            </label>
                                            <input
                                                type="text"
                                                id="firstname"
                                                name="firstname"
                                                class="gs-input"
                                                required
                                                placeholder="T.ex. Erik"
                                            >
                                        </div>

                                        <!-- Last Name (Required) -->
                                        <div>
                                            <label for="lastname" class="gs-label">
                                                <i data-lucide="user"></i>
                                                Efternamn <span class="gs-text-error">*</span>
                                            </label>
                                            <input
                                                type="text"
                                                id="lastname"
                                                name="lastname"
                                                class="gs-input"
                                                required
                                                placeholder="T.ex. Andersson"
                                            >
                                        </div>
                                    </div>

                                    <!-- Birth Year and Gender -->
                                    <div class="gs-grid gs-grid-cols-2 gs-gap-md">
                                        <div>
                                            <label for="birth_year" class="gs-label">
                                                <i data-lucide="calendar"></i>
                                                Födelseår
                                            </label>
                                            <input
                                                type="number"
                                                id="birth_year"
                                                name="birth_year"
                                                class="gs-input"
                                                min="1900"
                                                max="2020"
                                                placeholder="T.ex. 1995"
                                            >
                                        </div>

                                        <div>
                                            <label for="gender" class="gs-label">
                                                <i data-lucide="users"></i>
                                                Kön
                                            </label>
                                            <select id="gender" name="gender" class="gs-input">
                                                <option value="">Välj...</option>
                                                <option value="M">Man</option>
                                                <option value="F">Kvinna</option>
                                                <option value="Other">Annat</option>
                                            </select>
                                        </div>
                                    </div>

                                    <!-- Email and Phone -->
                                    <div class="gs-grid gs-grid-cols-2 gs-gap-md">
                                        <div>
                                            <label for="email" class="gs-label">
                                                <i data-lucide="mail"></i>
                                                E-post
                                            </label>
                                            <input
                                                type="email"
                                                id="email"
                                                name="email"
                                                class="gs-input"
                                                placeholder="exempel@email.se"
                                            >
                                        </div>

                                        <div>
                                            <label for="phone" class="gs-label">
                                                <i data-lucide="phone"></i>
                                                Telefon
                                            </label>
                                            <input
                                                type="tel"
                                                id="phone"
                                                name="phone"
                                                class="gs-input"
                                                placeholder="070-123 45 67"
                                            >
                                        </div>
                                    </div>

                                    <!-- City and Club -->
                                    <div class="gs-grid gs-grid-cols-2 gs-gap-md">
                                        <div>
                                            <label for="city" class="gs-label">
                                                <i data-lucide="map-pin"></i>
                                                Stad
                                            </label>
                                            <input
                                                type="text"
                                                id="city"
                                                name="city"
                                                class="gs-input"
                                                placeholder="T.ex. Stockholm"
                                            >
                                        </div>

                                        <div>
                                            <label for="club_id" class="gs-label">
                                                <i data-lucide="building"></i>
                                                Klubb
                                            </label>
                                            <select id="club_id" name="club_id" class="gs-input">
                                                <option value="">Ingen klubb</option>
                                                <?php foreach ($clubs as $club): ?>
                                                    <option value="<?= $club['id'] ?>"><?= h($club['name']) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>

                                    <!-- License Information Section -->
                                    <div class="gs-mb-md gs-mt-lg">
                                        <h3 class="gs-text-lg gs-font-bold gs-text-primary gs-mb-md">
                                            <i data-lucide="credit-card"></i> Licensinformation
                                        </h3>
                                    </div>

                                    <!-- License Number -->
                                    <div>
                                        <label for="license_number" class="gs-label">
                                            <i data-lucide="hash"></i>
                                            Licensnummer
                                        </label>
                                        <input
                                            type="text"
                                            id="license_number"
                                            name="license_number"
                                            class="gs-input"
                                            placeholder="T.ex. 101 637 581 11 eller SWE25XXXXX"
                                        >
                                    </div>

                                    <!-- License Type and Discipline -->
                                    <div class="gs-grid gs-grid-cols-2 gs-gap-md">
                                        <div>
                                            <label for="license_type" class="gs-label">
                                                <i data-lucide="award"></i>
                                                Licenstyp
                                            </label>
                                            <select id="license_type" name="license_type" class="gs-input">
                                                <option value="">Ingen</option>
                                                <option value="Elite">Elite</option>
                                                <option value="Master">Master</option>
                                                <option value="Youth">Youth</option>
                                                <option value="Base">Base</option>
                                                <option value="Team Manager">Team Manager</option>
                                            </select>
                                        </div>

                                        <div>
                                            <label for="discipline" class="gs-label">
                                                <i data-lucide="flag"></i>
                                                Gren
                                            </label>
                                            <select id="discipline" name="discipline" class="gs-input">
                                                <option value="">Ingen</option>
                                                <option value="MTB">MTB</option>
                                                <option value="Road">Road</option>
                                                <option value="CX">CX</option>
                                                <option value="Track">Track</option>
                                                <option value="BMX">BMX</option>
                                                <option value="Other">Other</option>
                                            </select>
                                        </div>
                                    </div>

                                    <!-- License Category and Valid Until -->
                                    <div class="gs-grid gs-grid-cols-2 gs-gap-md">
                                        <div>
                                            <label for="license_category" class="gs-label">
                                                <i data-lucide="tag"></i>
                                                Licenskategori
                                            </label>
                                            <input
                                                type="text"
                                                id="license_category"
                                                name="license_category"
                                                class="gs-input"
                                                placeholder="T.ex. Elite Men, Master Men 35+"
                                            >
                                        </div>

                                        <div>
                                            <label for="license_valid_until" class="gs-label">
                                                <i data-lucide="calendar-clock"></i>
                                                Giltig till
                                            </label>
                                            <input
                                                type="date"
                                                id="license_valid_until"
                                                name="license_valid_until"
                                                class="gs-input"
                                            >
                                        </div>
                                    </div>

                                    <!-- Other Section -->
                                    <div class="gs-mb-md gs-mt-lg">
                                        <h3 class="gs-text-lg gs-font-bold gs-text-primary gs-mb-md">
                                            <i data-lucide="settings"></i> Övrigt
                                        </h3>
                                    </div>

                                    <!-- Active Status -->
                                    <div>
                                        <label class="gs-checkbox-label">
                                            <input
                                                type="checkbox"
                                                id="active"
                                                name="active"
                                                class="gs-checkbox"
                                                checked
                                            >
                                            <span>
                                                <i data-lucide="check-circle"></i>
                                                Aktiv
                                            </span>
                                        </label>
                                    </div>

                                    <!-- Notes -->
                                    <div>
                                        <label for="notes" class="gs-label">
                                            <i data-lucide="file-text"></i>
                                            Anteckningar
                                        </label>
                                        <textarea
                                            id="notes"
                                            name="notes"
                                            class="gs-input"
                                            rows="3"
                                            placeholder="Övriga anteckningar..."
                                        ></textarea>
                                    </div>
                                </div>
                            </div>

                            <div class="gs-modal-footer">
                                <button type="button" class="gs-btn gs-btn-outline" onclick="closeRiderModal()">
                                    Avbryt
                                </button>
                                <button type="submit" class="gs-btn gs-btn-primary" id="submitButton">
                                    <i data-lucide="check"></i>
                                    <span id="submitButtonText">Skapa</span>
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Search -->
            <div class="gs-card gs-mb-lg">
                <div class="gs-card-content">
                    <form method="GET" class="gs-flex gs-gap-md">
                        <div class="gs-flex-1">
                            <div class="gs-input-group">
                                <i data-lucide="search"></i>
                                <input
                                    type="text"
                                    name="search"
                                    class="gs-input"
                                    placeholder="Sök efter namn eller licensnummer..."
                                    value="<?= h($search) ?>"
                                >
                            </div>
                        </div>
                        <button type="submit" class="gs-btn gs-btn-primary">
                            <i data-lucide="search"></i>
                            Sök
                        </button>
                        <?php if ($search): ?>
                            <a href="/admin/riders.php" class="gs-btn gs-btn-outline">
                                Rensa
                            </a>
                        <?php endif; ?>
                    </form>
                </div>
            </div>

            <!-- Stats -->
            <div class="gs-grid gs-grid-cols-1 gs-md-grid-cols-3 gs-gap-lg gs-mb-lg">
                <div class="gs-stat-card">
                    <i data-lucide="users" class="gs-icon-lg gs-text-primary gs-mb-md"></i>
                    <div class="gs-stat-number"><?= count($riders) ?></div>
                    <div class="gs-stat-label">Totalt deltagare</div>
                </div>
                <div class="gs-stat-card">
                    <i data-lucide="user-check" class="gs-icon-lg gs-text-success gs-mb-md"></i>
                    <div class="gs-stat-number">
                        <?= count(array_filter($riders, fn($r) => $r['active'] == 1)) ?>
                    </div>
                    <div class="gs-stat-label">Aktiva</div>
                </div>
                <div class="gs-stat-card">
                    <i data-lucide="building" class="gs-icon-lg gs-text-accent gs-mb-md"></i>
                    <div class="gs-stat-number">
                        <?= count(array_unique(array_column($riders, 'club_id'))) ?>
                    </div>
                    <div class="gs-stat-label">Klubbar</div>
                </div>
            </div>

            <!-- Riders Table -->
            <?php if (empty($riders)): ?>
                <div class="gs-card">
                    <div class="gs-card-content gs-text-center gs-py-xl">
                        <i data-lucide="user-x" style="width: 64px; height: 64px; color: var(--gs-text-secondary); margin-bottom: var(--gs-space-md);"></i>
                        <p class="gs-text-secondary">Inga deltagare hittades</p>
                    </div>
                </div>
            <?php else: ?>
                <div class="gs-card">
                    <div class="gs-table-responsive">
                        <table class="gs-table">
                            <thead>
                                <tr>
                                    <th>
                                        <i data-lucide="user-circle"></i>
                                        Namn
                                    </th>
                                    <th>Född</th>
                                    <th>Ålder</th>
                                    <th>Kön</th>
                                    <th>
                                        <i data-lucide="building"></i>
                                        Klubb
                                    </th>
                                    <th>Licenskategori</th>
                                    <th>Gren</th>
                                    <th>Licens</th>
                                    <th>Status</th>
                                    <th style="width: 100px; text-align: right;">Åtgärder</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($riders as $rider): ?>
                                    <tr>
                                        <td>
                                            <strong><?= h($rider['firstname'] . ' ' . $rider['lastname']) ?></strong>
                                        </td>
                                        <td><?= h($rider['birth_year']) ?></td>
                                        <td>
                                            <?php if ($rider['birth_year']): ?>
                                                <?= calculateAge($rider['birth_year']) ?> år
                                            <?php else: ?>
                                                <span class="gs-text-secondary">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($rider['gender'] === 'M'): ?>
                                                <span class="gs-badge gs-badge-primary">👨 Man</span>
                                            <?php elseif ($rider['gender'] === 'F'): ?>
                                                <span class="gs-badge gs-badge-accent">👩 Kvinna</span>
                                            <?php else: ?>
                                                <span class="gs-badge gs-badge-secondary">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="gs-text-secondary"><?= h($rider['club_name'] ?? '-') ?></td>
                                        <td>
                                            <?php if (!empty($rider['license_category'])): ?>
                                                <span class="gs-badge gs-badge-primary gs-text-xs">
                                                    <?= h($rider['license_category']) ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="gs-text-secondary">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if (!empty($rider['discipline'])): ?>
                                                <span class="gs-badge gs-badge-accent gs-text-xs">
                                                    <?= h($rider['discipline']) ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="gs-text-secondary">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php
                                            if (!empty($rider['license_type']) && $rider['license_type'] !== 'None') {
                                                $licenseCheck = checkLicense($rider);
                                                echo '<span class="gs-badge ' . $licenseCheck['class'] . ' gs-text-xs">';
                                                echo h($licenseCheck['message']);
                                                echo '</span>';
                                            } else {
                                                echo '<span class="gs-badge gs-badge-secondary gs-text-xs">Ingen</span>';
                                            }
                                            ?>
                                        </td>
                                        <td>
                                            <?php if ($rider['active']): ?>
                                                <span class="gs-badge gs-badge-success gs-text-xs">
                                                    <i data-lucide="check-circle"></i>
                                                    Aktiv
                                                </span>
                                            <?php else: ?>
                                                <span class="gs-badge gs-badge-secondary gs-text-xs">Inaktiv</span>
                                            <?php endif; ?>
                                        </td>
                                        <td style="text-align: right;">
                                            <?php if ($is_demo): ?>
                                                <span class="gs-badge gs-badge-secondary">Demo</span>
                                            <?php else: ?>
                                                <div class="gs-flex gs-gap-sm gs-justify-end">
                                                    <button
                                                        type="button"
                                                        class="gs-btn gs-btn-sm gs-btn-outline"
                                                        onclick="editRider(<?= $rider['id'] ?>)"
                                                        title="Redigera"
                                                    >
                                                        <i data-lucide="edit"></i>
                                                    </button>
                                                    <button
                                                        type="button"
                                                        class="gs-btn gs-btn-sm gs-btn-outline gs-btn-danger"
                                                        onclick="deleteRider(<?= $rider['id'] ?>, '<?= addslashes(h($rider['firstname'] . ' ' . $rider['lastname'])) ?>')"
                                                        title="Ta bort"
                                                    >
                                                        <i data-lucide="trash-2"></i>
                                                    </button>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <?php if (!$is_demo): ?>
        <script>
            // Open modal for creating new rider
            function openRiderModal() {
                document.getElementById('riderModal').style.display = 'flex';
                document.getElementById('riderForm').reset();
                document.getElementById('formAction').value = 'create';
                document.getElementById('riderId').value = '';
                document.getElementById('modalTitleText').textContent = 'Ny Deltagare';
                document.getElementById('submitButtonText').textContent = 'Skapa';
                // Set active checkbox to checked by default
                document.getElementById('active').checked = true;

                // Re-initialize Lucide icons
                if (typeof lucide !== 'undefined') {
                    lucide.createIcons();
                }
            }

            // Close modal
            function closeRiderModal() {
                document.getElementById('riderModal').style.display = 'none';
            }

            // Edit rider - reload page with edit parameter
            function editRider(id) {
                window.location.href = `?edit=${id}`;
            }

            // Delete rider
            function deleteRider(id, name) {
                if (!confirm(`Är du säker på att du vill ta bort "${name}"?`)) {
                    return;
                }

                // Create form and submit
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="${id}">
                `;
                document.body.appendChild(form);
                form.submit();
            }

            // Close modal when clicking outside
            document.addEventListener('DOMContentLoaded', function() {
                const modal = document.getElementById('riderModal');
                if (modal) {
                    modal.addEventListener('click', function(e) {
                        if (e.target === modal) {
                            closeRiderModal();
                        }
                    });
                }

                // Handle edit mode from URL parameter
                <?php if ($editRider): ?>
                    // Populate form with rider data
                    document.getElementById('formAction').value = 'update';
                    document.getElementById('riderId').value = '<?= $editRider['id'] ?>';
                    document.getElementById('firstname').value = '<?= addslashes($editRider['firstname']) ?>';
                    document.getElementById('lastname').value = '<?= addslashes($editRider['lastname']) ?>';
                    document.getElementById('birth_year').value = '<?= $editRider['birth_year'] ?? '' ?>';
                    document.getElementById('gender').value = '<?= $editRider['gender'] ?? '' ?>';
                    document.getElementById('email').value = '<?= addslashes($editRider['email'] ?? '') ?>';
                    document.getElementById('phone').value = '<?= addslashes($editRider['phone'] ?? '') ?>';
                    document.getElementById('city').value = '<?= addslashes($editRider['city'] ?? '') ?>';
                    document.getElementById('club_id').value = '<?= $editRider['club_id'] ?? '' ?>';
                    document.getElementById('license_number').value = '<?= addslashes($editRider['license_number'] ?? '') ?>';
                    document.getElementById('license_type').value = '<?= $editRider['license_type'] ?? '' ?>';
                    document.getElementById('license_category').value = '<?= addslashes($editRider['license_category'] ?? '') ?>';
                    document.getElementById('discipline').value = '<?= $editRider['discipline'] ?? '' ?>';
                    document.getElementById('license_valid_until').value = '<?= $editRider['license_valid_until'] ?? '' ?>';
                    document.getElementById('active').checked = <?= $editRider['active'] ? 'true' : 'false' ?>;
                    document.getElementById('notes').value = '<?= addslashes($editRider['notes'] ?? '') ?>';

                    // Update modal title and button
                    document.getElementById('modalTitleText').textContent = 'Redigera Deltagare';
                    document.getElementById('submitButtonText').textContent = 'Uppdatera';

                    // Open modal
                    document.getElementById('riderModal').style.display = 'flex';

                    // Re-initialize Lucide icons
                    if (typeof lucide !== 'undefined') {
                        lucide.createIcons();
                    }
                <?php endif; ?>
            });

            // Close modal with Escape key
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    closeRiderModal();
                }
            });
        </script>
        <?php endif; ?>

<?php include __DIR__ . '/../includes/layout-footer.php'; ?>
