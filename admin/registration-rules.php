<?php
/**
 * Admin: Registration Rules Management
 *
 * Configure registration rules for series and events.
 * Supports national (strict) and sport/motion (relaxed) rule types.
 */

require_once __DIR__ . '/../config.php';
require_admin();

require_once __DIR__ . '/../includes/registration-rules.php';
require_once __DIR__ . '/../includes/registration-validator.php';

$db = getDB();
$pdo = $db->getPdo();
$current_admin = get_current_admin();

// Initialize message variables
$message = '';
$messageType = 'info';

// Get all rule types
$ruleTypes = getRuleTypes($pdo);

// Get all license types
$licenseTypes = getLicenseTypes($pdo);

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();

    $action = $_POST['action'] ?? '';

    try {
        switch ($action) {
            case 'set_series_rule_type':
                $seriesId = filter_input(INPUT_POST, 'series_id', FILTER_VALIDATE_INT);
                $ruleTypeId = filter_input(INPUT_POST, 'rule_type_id', FILTER_VALIDATE_INT);

                if ($seriesId) {
                    setSeriesRuleType($pdo, $seriesId, $ruleTypeId ?: null);

                    // Optionally apply default rules
                    if (isset($_POST['apply_defaults']) && $_POST['apply_defaults'] && $ruleTypeId) {
                        applyDefaultRules($pdo, $seriesId, $ruleTypeId);
                    }

                    $message = 'Registreringstyp för serien har sparats.';
                    $messageType = 'success';
                }
                break;

            case 'set_event_rule_type':
                $eventId = filter_input(INPUT_POST, 'event_id', FILTER_VALIDATE_INT);
                $useSeriesRules = isset($_POST['use_series_rules']) && $_POST['use_series_rules'] == '1';
                $ruleTypeId = filter_input(INPUT_POST, 'event_rule_type_id', FILTER_VALIDATE_INT);

                if ($eventId) {
                    setEventRuleType($pdo, $eventId, $useSeriesRules ? null : $ruleTypeId, $useSeriesRules);

                    // Copy series rules to event if switching to event-specific
                    if (!$useSeriesRules && isset($_POST['copy_series_rules'])) {
                        $event = getEventForValidation($pdo, $eventId);
                        if ($event && $event['series_id']) {
                            copySeriesRulesToEvent($pdo, $eventId, $event['series_id']);
                        }
                    }

                    $message = 'Registreringstyp för eventet har sparats.';
                    $messageType = 'success';
                }
                break;

            case 'save_class_rule':
                $targetType = $_POST['target_type'] ?? '';
                $targetId = filter_input(INPUT_POST, 'target_id', FILTER_VALIDATE_INT);
                $classId = filter_input(INPUT_POST, 'class_id', FILTER_VALIDATE_INT);

                if ($targetId && $classId) {
                    $data = [
                        'allowed_license_types' => $_POST['allowed_license_types'] ?? [],
                        'allowed_genders' => $_POST['allowed_genders'] ?? [],
                        'min_age' => !empty($_POST['min_age']) ? intval($_POST['min_age']) : null,
                        'max_age' => !empty($_POST['max_age']) ? intval($_POST['max_age']) : null,
                        'min_birth_year' => !empty($_POST['min_birth_year']) ? intval($_POST['min_birth_year']) : null,
                        'max_birth_year' => !empty($_POST['max_birth_year']) ? intval($_POST['max_birth_year']) : null,
                        'requires_license' => isset($_POST['requires_license']) ? 1 : 0,
                        'requires_club_membership' => isset($_POST['requires_club_membership']) ? 1 : 0,
                        'is_active' => isset($_POST['is_active']) ? 1 : 0
                    ];

                    if ($targetType === 'series') {
                        saveSeriesClassRule($pdo, $targetId, $classId, $data);
                    } else {
                        saveEventClassRule($pdo, $targetId, $classId, $data);
                    }

                    $message = 'Klassregler har sparats.';
                    $messageType = 'success';
                }
                break;

            case 'delete_class_rule':
                $targetType = $_POST['target_type'] ?? '';
                $targetId = filter_input(INPUT_POST, 'target_id', FILTER_VALIDATE_INT);
                $classId = filter_input(INPUT_POST, 'class_id', FILTER_VALIDATE_INT);

                if ($targetId && $classId) {
                    if ($targetType === 'series') {
                        deleteSeriesClassRule($pdo, $targetId, $classId);
                    } else {
                        deleteEventClassRule($pdo, $targetId, $classId);
                    }

                    $message = 'Klassregel har tagits bort.';
                    $messageType = 'success';
                }
                break;
        }
    } catch (Exception $e) {
        $message = 'Ett fel uppstod: ' . $e->getMessage();
        $messageType = 'error';
        error_log("Registration rules error: " . $e->getMessage());
    }
}

// Get current view parameters
$selectedSeriesId = filter_input(INPUT_GET, 'series_id', FILTER_VALIDATE_INT);
$selectedEventId = filter_input(INPUT_GET, 'event_id', FILTER_VALIDATE_INT);
$view = $_GET['view'] ?? 'series';

// Get series list
$currentYear = date('Y');
$series = getSeriesWithRuleTypes($pdo, $currentYear);

// Get data for selected series
$selectedSeries = null;
$seriesClassRules = [];
$seriesEvents = [];

if ($selectedSeriesId) {
    // Get series info
    $sql = "SELECT s.*, rt.name AS rule_type_name, rt.code AS rule_type_code
            FROM series s
            LEFT JOIN registration_rule_types rt ON s.registration_rule_type_id = rt.id
            WHERE s.id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$selectedSeriesId]);
    $selectedSeries = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($selectedSeries) {
        $seriesClassRules = getSeriesClassRules($pdo, $selectedSeriesId);
        $seriesEvents = getEventsWithRuleTypes($pdo, $selectedSeriesId);
    }
}

// Get data for selected event
$selectedEvent = null;
$eventClassRules = [];

if ($selectedEventId) {
    $selectedEvent = getEventRuleType($pdo, $selectedEventId);
    if ($selectedEvent) {
        $eventClassRules = getEventClassRules($pdo, $selectedEventId);
    }
}

// Get all available classes
$allClasses = getAllClasses($pdo);

// Page setup
$pageTitle = 'Registreringsregler';
$pageType = 'admin';
include __DIR__ . '/../includes/layout-header.php';
?>

<main class="gs-main-content">
    <div class="gs-container">
        <!-- Header -->
        <div class="gs-flex gs-items-center gs-justify-between gs-mb-lg">
            <h1 class="gs-h1">
                <i data-lucide="shield-check"></i>
                Registreringsregler
            </h1>
        </div>

        <!-- Messages -->
        <?php if ($message): ?>
            <div class="gs-alert gs-alert-<?= $messageType ?> gs-mb-lg">
                <i data-lucide="<?= $messageType === 'success' ? 'check-circle' : ($messageType === 'error' ? 'alert-circle' : 'info') ?>"></i>
                <?= h($message) ?>
            </div>
        <?php endif; ?>

        <!-- Series Selector -->
        <div class="gs-card gs-mb-lg">
            <div class="gs-card-header">
                <h2 class="gs-h4">
                    <i data-lucide="calendar"></i>
                    Välj serie
                </h2>
            </div>
            <div class="gs-card-content">
                <form method="GET" class="gs-flex gs-items-end gs-gap-md">
                    <div class="gs-form-group gs-flex-1">
                        <label for="series_id" class="gs-label">Serie</label>
                        <select name="series_id" id="series_id" class="gs-input" onchange="this.form.submit()">
                            <option value="">-- Välj serie --</option>
                            <?php foreach ($series as $s): ?>
                                <option value="<?= $s['id'] ?>" <?= $selectedSeriesId == $s['id'] ? 'selected' : '' ?>>
                                    <?= h($s['name']) ?> (<?= $s['year'] ?>)
                                    <?php if ($s['rule_type_name']): ?>
                                        - <?= h($s['rule_type_name']) ?>
                                    <?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </form>
            </div>
        </div>

        <?php if ($selectedSeries): ?>
            <!-- Series Rule Type Configuration -->
            <div class="gs-card gs-mb-lg">
                <div class="gs-card-header">
                    <h2 class="gs-h4">
                        <i data-lucide="settings"></i>
                        Serieinställningar: <?= h($selectedSeries['name']) ?>
                    </h2>
                </div>
                <div class="gs-card-content">
                    <form method="POST">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="set_series_rule_type">
                        <input type="hidden" name="series_id" value="<?= $selectedSeriesId ?>">

                        <div class="gs-grid gs-grid-cols-1 gs-md-grid-cols-2 gs-gap-lg">
                            <div class="gs-form-group">
                                <label for="rule_type_id" class="gs-label">
                                    <i data-lucide="shield"></i>
                                    Registreringstyp
                                </label>
                                <select name="rule_type_id" id="rule_type_id" class="gs-input">
                                    <option value="">-- Ingen vald --</option>
                                    <?php foreach ($ruleTypes as $rt): ?>
                                        <option value="<?= $rt['id'] ?>" <?= $selectedSeries['registration_rule_type_id'] == $rt['id'] ? 'selected' : '' ?>>
                                            <?= h($rt['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <small class="gs-text-secondary">
                                    Nationell = strikta licens- och åldersregler<br>
                                    Sport/Motion = milda regler, främst könsbegränsning
                                </small>
                            </div>

                            <div class="gs-form-group">
                                <label class="gs-label">&nbsp;</label>
                                <div class="gs-flex gs-items-center gs-gap-sm">
                                    <input type="checkbox" name="apply_defaults" id="apply_defaults" value="1">
                                    <label for="apply_defaults">Tillämpa standardregler på alla klasser</label>
                                </div>
                                <small class="gs-text-secondary gs-mt-sm">
                                    Skapar basregler för alla klasser baserat på regeltypen.
                                </small>
                            </div>
                        </div>

                        <div class="gs-mt-lg">
                            <button type="submit" class="gs-btn gs-btn-primary">
                                <i data-lucide="save"></i>
                                Spara serieinställningar
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Rule Type Info -->
            <?php if ($selectedSeries['rule_type_code']): ?>
                <div class="gs-alert gs-alert-info gs-mb-lg">
                    <i data-lucide="info"></i>
                    <strong>Aktiv regeltyp: <?= h($selectedSeries['rule_type_name']) ?></strong>
                    <?php
                    $ruleTypeInfo = getRuleType($pdo, $selectedSeries['registration_rule_type_id']);
                    if ($ruleTypeInfo && $ruleTypeInfo['description']):
                    ?>
                        <br><span class="gs-text-secondary"><?= h($ruleTypeInfo['description']) ?></span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <!-- Tab Navigation -->
            <div class="gs-flex gs-gap-sm gs-mb-lg">
                <a href="?series_id=<?= $selectedSeriesId ?>&view=series"
                   class="gs-btn <?= $view === 'series' ? 'gs-btn-primary' : 'gs-btn-outline' ?>">
                    <i data-lucide="list"></i>
                    Klassregler (<?= count($seriesClassRules) ?>)
                </a>
                <a href="?series_id=<?= $selectedSeriesId ?>&view=events"
                   class="gs-btn <?= $view === 'events' ? 'gs-btn-primary' : 'gs-btn-outline' ?>">
                    <i data-lucide="calendar-days"></i>
                    Events (<?= count($seriesEvents) ?>)
                </a>
            </div>

            <?php if ($view === 'series'): ?>
                <!-- Series Class Rules -->
                <div class="gs-card">
                    <div class="gs-card-header gs-flex gs-items-center gs-justify-between">
                        <h2 class="gs-h4">
                            <i data-lucide="users"></i>
                            Klassregler för serien
                        </h2>
                        <button type="button" class="gs-btn gs-btn-primary gs-btn-sm" onclick="openAddClassRuleModal('series', <?= $selectedSeriesId ?>)">
                            <i data-lucide="plus"></i>
                            Lägg till klass
                        </button>
                    </div>
                    <div class="gs-card-content">
                        <?php if (empty($seriesClassRules)): ?>
                            <p class="gs-text-secondary">
                                Inga klassregler har konfigurerats för denna serie än.
                                Klicka på "Lägg till klass" eller välj en regeltyp och tillämpa standardregler.
                            </p>
                        <?php else: ?>
                            <div class="gs-table-responsive">
                                <table class="gs-table">
                                    <thead>
                                        <tr>
                                            <th>Klass</th>
                                            <th>Kön</th>
                                            <th>Ålder</th>
                                            <th>Licenstyper</th>
                                            <th>Krav</th>
                                            <th>Status</th>
                                            <th>Åtgärder</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($seriesClassRules as $rule): ?>
                                            <tr>
                                                <td>
                                                    <strong><?= h($rule['class_display_name'] ?: $rule['class_name']) ?></strong>
                                                </td>
                                                <td>
                                                    <?php
                                                    $genders = $rule['allowed_genders'];
                                                    if (empty($genders) || in_array('ALL', $genders)) {
                                                        echo '<span class="gs-badge">Alla</span>';
                                                    } else {
                                                        foreach ($genders as $g) {
                                                            $label = $g === 'M' ? 'Herrar' : ($g === 'K' ? 'Damer' : $g);
                                                            echo '<span class="gs-badge gs-badge-' . ($g === 'M' ? 'info' : 'accent') . '">' . h($label) . '</span> ';
                                                        }
                                                    }
                                                    ?>
                                                </td>
                                                <td>
                                                    <?php
                                                    $ageText = [];
                                                    if ($rule['min_age']) $ageText[] = 'Min: ' . $rule['min_age'];
                                                    if ($rule['max_age']) $ageText[] = 'Max: ' . $rule['max_age'];
                                                    echo $ageText ? implode(', ', $ageText) : '-';
                                                    ?>
                                                </td>
                                                <td>
                                                    <?php
                                                    $licenses = $rule['allowed_license_types'];
                                                    if (empty($licenses)) {
                                                        echo '<span class="gs-text-secondary">Alla</span>';
                                                    } else {
                                                        echo h(implode(', ', $licenses));
                                                    }
                                                    ?>
                                                </td>
                                                <td>
                                                    <?php if ($rule['requires_license']): ?>
                                                        <span class="gs-badge gs-badge-warning">Licens</span>
                                                    <?php endif; ?>
                                                    <?php if ($rule['requires_club_membership']): ?>
                                                        <span class="gs-badge gs-badge-info">Klubb</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ($rule['is_active']): ?>
                                                        <span class="gs-badge gs-badge-success">Aktiv</span>
                                                    <?php else: ?>
                                                        <span class="gs-badge gs-badge-danger">Inaktiv</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <div class="gs-flex gs-gap-xs">
                                                        <button type="button"
                                                                class="gs-btn gs-btn-outline gs-btn-sm"
                                                                onclick='openEditClassRuleModal("series", <?= $selectedSeriesId ?>, <?= json_encode($rule) ?>)'>
                                                            <i data-lucide="edit-2"></i>
                                                        </button>
                                                        <button type="button"
                                                                class="gs-btn gs-btn-outline gs-btn-sm"
                                                                onclick="previewClassRiders(<?= $selectedSeriesId ?>, <?= $rule['class_id'] ?>)">
                                                            <i data-lucide="eye"></i>
                                                        </button>
                                                        <form method="POST" class="gs-inline" onsubmit="return confirm('Vill du ta bort denna klassregel?')">
                                                            <?= csrf_field() ?>
                                                            <input type="hidden" name="action" value="delete_class_rule">
                                                            <input type="hidden" name="target_type" value="series">
                                                            <input type="hidden" name="target_id" value="<?= $selectedSeriesId ?>">
                                                            <input type="hidden" name="class_id" value="<?= $rule['class_id'] ?>">
                                                            <button type="submit" class="gs-btn gs-btn-outline gs-btn-sm gs-text-error">
                                                                <i data-lucide="trash-2"></i>
                                                            </button>
                                                        </form>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

            <?php elseif ($view === 'events'): ?>
                <!-- Events List -->
                <div class="gs-card">
                    <div class="gs-card-header">
                        <h2 class="gs-h4">
                            <i data-lucide="calendar-days"></i>
                            Events i serien
                        </h2>
                    </div>
                    <div class="gs-card-content">
                        <?php if (empty($seriesEvents)): ?>
                            <p class="gs-text-secondary">Inga events finns i denna serie.</p>
                        <?php else: ?>
                            <div class="gs-table-responsive">
                                <table class="gs-table">
                                    <thead>
                                        <tr>
                                            <th>Event</th>
                                            <th>Datum</th>
                                            <th>Regelkälla</th>
                                            <th>Regeltyp</th>
                                            <th>Åtgärder</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($seriesEvents as $event): ?>
                                            <tr>
                                                <td><strong><?= h($event['name']) ?></strong></td>
                                                <td><?= h($event['date']) ?></td>
                                                <td>
                                                    <?php if ($event['use_series_rules']): ?>
                                                        <span class="gs-badge gs-badge-info">Från serie</span>
                                                    <?php else: ?>
                                                        <span class="gs-badge gs-badge-warning">Eventspecifik</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?= h($event['effective_rule_name'] ?? 'Ingen') ?>
                                                </td>
                                                <td>
                                                    <button type="button"
                                                            class="gs-btn gs-btn-outline gs-btn-sm"
                                                            onclick="openEventRuleModal(<?= $event['id'] ?>, <?= $event['use_series_rules'] ? '1' : '0' ?>, <?= $event['registration_rule_type_id'] ?? 'null' ?>)">
                                                        <i data-lucide="settings"></i>
                                                        Konfigurera
                                                    </button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</main>

<!-- Add/Edit Class Rule Modal -->
<div id="classRuleModal" class="gs-modal gs-hidden">
    <div class="gs-modal-overlay" onclick="closeClassRuleModal()"></div>
    <div class="gs-modal-content gs-modal-lg">
        <div class="gs-modal-header">
            <h2 class="gs-modal-title" id="classRuleModalTitle">Klassregel</h2>
            <button type="button" class="gs-modal-close" onclick="closeClassRuleModal()">
                <i data-lucide="x"></i>
            </button>
        </div>

        <form method="POST" id="classRuleForm">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_class_rule">
            <input type="hidden" name="target_type" id="classRuleTargetType" value="">
            <input type="hidden" name="target_id" id="classRuleTargetId" value="">

            <div class="gs-modal-body">
                <div class="gs-grid gs-grid-cols-1 gs-md-grid-cols-2 gs-gap-lg">
                    <!-- Class Selection -->
                    <div class="gs-form-group">
                        <label for="class_id" class="gs-label">
                            <i data-lucide="users"></i>
                            Klass <span class="gs-text-error">*</span>
                        </label>
                        <select name="class_id" id="classRuleClassId" class="gs-input" required>
                            <option value="">-- Välj klass --</option>
                            <?php foreach ($allClasses as $class): ?>
                                <option value="<?= $class['id'] ?>"
                                        data-gender="<?= h($class['gender']) ?>"
                                        data-min-age="<?= $class['min_age'] ?? '' ?>"
                                        data-max-age="<?= $class['max_age'] ?? '' ?>">
                                    <?= h($class['display_name'] ?: $class['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Active Status -->
                    <div class="gs-form-group">
                        <label class="gs-label">&nbsp;</label>
                        <div class="gs-flex gs-items-center gs-gap-sm">
                            <input type="checkbox" name="is_active" id="classRuleIsActive" value="1" checked>
                            <label for="classRuleIsActive">Aktiv för anmälan</label>
                        </div>
                    </div>

                    <!-- Gender Restrictions -->
                    <div class="gs-form-group">
                        <label class="gs-label">
                            <i data-lucide="users"></i>
                            Tillåtna kön
                        </label>
                        <div class="gs-flex gs-flex-col gs-gap-sm">
                            <label class="gs-flex gs-items-center gs-gap-sm">
                                <input type="checkbox" name="allowed_genders[]" value="M" id="genderM">
                                Herrar (M)
                            </label>
                            <label class="gs-flex gs-items-center gs-gap-sm">
                                <input type="checkbox" name="allowed_genders[]" value="K" id="genderK">
                                Damer (K)
                            </label>
                            <label class="gs-flex gs-items-center gs-gap-sm">
                                <input type="checkbox" name="allowed_genders[]" value="ALL" id="genderALL">
                                Alla (ingen begränsning)
                            </label>
                        </div>
                    </div>

                    <!-- Age Restrictions -->
                    <div class="gs-form-group">
                        <label class="gs-label">
                            <i data-lucide="calendar"></i>
                            Åldersgränser
                        </label>
                        <div class="gs-grid gs-grid-cols-2 gs-gap-sm">
                            <div>
                                <label for="min_age" class="gs-text-sm">Min ålder</label>
                                <input type="number" name="min_age" id="classRuleMinAge" class="gs-input" min="0" max="99">
                            </div>
                            <div>
                                <label for="max_age" class="gs-text-sm">Max ålder</label>
                                <input type="number" name="max_age" id="classRuleMaxAge" class="gs-input" min="0" max="99">
                            </div>
                        </div>
                    </div>

                    <!-- License Types -->
                    <div class="gs-form-group gs-md-col-span-2">
                        <label class="gs-label">
                            <i data-lucide="badge"></i>
                            Tillåtna licenstyper
                        </label>
                        <div class="gs-grid gs-grid-cols-2 gs-md-grid-cols-4 gs-gap-sm">
                            <?php foreach ($licenseTypes as $lt): ?>
                                <label class="gs-flex gs-items-center gs-gap-sm">
                                    <input type="checkbox"
                                           name="allowed_license_types[]"
                                           value="<?= h($lt['code']) ?>"
                                           class="licenseTypeCheckbox">
                                    <?= h($lt['name']) ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        <small class="gs-text-secondary gs-mt-sm">
                            Lämna tomt för att tillåta alla licenstyper.
                        </small>
                    </div>

                    <!-- Requirements -->
                    <div class="gs-form-group">
                        <label class="gs-label">
                            <i data-lucide="check-square"></i>
                            Krav
                        </label>
                        <div class="gs-flex gs-flex-col gs-gap-sm">
                            <label class="gs-flex gs-items-center gs-gap-sm">
                                <input type="checkbox" name="requires_license" id="classRuleRequiresLicense" value="1">
                                Kräver giltig licens
                            </label>
                            <label class="gs-flex gs-items-center gs-gap-sm">
                                <input type="checkbox" name="requires_club_membership" id="classRuleRequiresClub" value="1">
                                Kräver klubbmedlemskap
                            </label>
                        </div>
                    </div>
                </div>
            </div>

            <div class="gs-modal-footer">
                <button type="button" class="gs-btn gs-btn-outline" onclick="closeClassRuleModal()">
                    Avbryt
                </button>
                <button type="submit" class="gs-btn gs-btn-primary">
                    <i data-lucide="save"></i>
                    Spara
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Event Rule Configuration Modal -->
<div id="eventRuleModal" class="gs-modal gs-hidden">
    <div class="gs-modal-overlay" onclick="closeEventRuleModal()"></div>
    <div class="gs-modal-content gs-modal-md">
        <div class="gs-modal-header">
            <h2 class="gs-modal-title">Eventkonfiguration</h2>
            <button type="button" class="gs-modal-close" onclick="closeEventRuleModal()">
                <i data-lucide="x"></i>
            </button>
        </div>

        <form method="POST" id="eventRuleForm">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="set_event_rule_type">
            <input type="hidden" name="event_id" id="eventRuleEventId" value="">

            <div class="gs-modal-body">
                <div class="gs-form-group">
                    <label class="gs-label">Regelkälla</label>
                    <div class="gs-flex gs-flex-col gs-gap-sm">
                        <label class="gs-flex gs-items-center gs-gap-sm">
                            <input type="radio" name="use_series_rules" value="1" id="useSeriesRules" checked onchange="toggleEventRuleType()">
                            Använd serieinställning
                        </label>
                        <label class="gs-flex gs-items-center gs-gap-sm">
                            <input type="radio" name="use_series_rules" value="0" id="useEventRules" onchange="toggleEventRuleType()">
                            Använd eventspecifika regler
                        </label>
                    </div>
                </div>

                <div id="eventRuleTypeSection" class="gs-form-group gs-hidden">
                    <label for="event_rule_type_id" class="gs-label">
                        Regeltyp för eventet
                    </label>
                    <select name="event_rule_type_id" id="eventRuleTypeId" class="gs-input">
                        <option value="">-- Välj regeltyp --</option>
                        <?php foreach ($ruleTypes as $rt): ?>
                            <option value="<?= $rt['id'] ?>"><?= h($rt['name']) ?></option>
                        <?php endforeach; ?>
                    </select>

                    <div class="gs-mt-md">
                        <label class="gs-flex gs-items-center gs-gap-sm">
                            <input type="checkbox" name="copy_series_rules" id="copySeriesRules" value="1" checked>
                            Kopiera seriens klassregler som utgångspunkt
                        </label>
                    </div>
                </div>
            </div>

            <div class="gs-modal-footer">
                <button type="button" class="gs-btn gs-btn-outline" onclick="closeEventRuleModal()">
                    Avbryt
                </button>
                <button type="submit" class="gs-btn gs-btn-primary">
                    <i data-lucide="save"></i>
                    Spara
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Preview Modal -->
<div id="previewModal" class="gs-modal gs-hidden">
    <div class="gs-modal-overlay" onclick="closePreviewModal()"></div>
    <div class="gs-modal-content gs-modal-lg">
        <div class="gs-modal-header">
            <h2 class="gs-modal-title">Förhandsgranskning av behöriga åkare</h2>
            <button type="button" class="gs-modal-close" onclick="closePreviewModal()">
                <i data-lucide="x"></i>
            </button>
        </div>
        <div class="gs-modal-body">
            <div id="previewContent">
                <p class="gs-text-center gs-text-secondary">Laddar...</p>
            </div>
        </div>
        <div class="gs-modal-footer">
            <button type="button" class="gs-btn gs-btn-outline" onclick="closePreviewModal()">
                Stäng
            </button>
        </div>
    </div>
</div>

<script>
// Class Rule Modal
function openAddClassRuleModal(targetType, targetId) {
    document.getElementById('classRuleModalTitle').textContent = 'Lägg till klassregel';
    document.getElementById('classRuleTargetType').value = targetType;
    document.getElementById('classRuleTargetId').value = targetId;
    document.getElementById('classRuleForm').reset();
    document.getElementById('classRuleIsActive').checked = true;
    document.getElementById('classRuleModal').style.display = 'flex';
}

function openEditClassRuleModal(targetType, targetId, rule) {
    document.getElementById('classRuleModalTitle').textContent = 'Redigera klassregel';
    document.getElementById('classRuleTargetType').value = targetType;
    document.getElementById('classRuleTargetId').value = targetId;

    // Set class
    document.getElementById('classRuleClassId').value = rule.class_id;

    // Set genders
    document.getElementById('genderM').checked = rule.allowed_genders && rule.allowed_genders.includes('M');
    document.getElementById('genderK').checked = rule.allowed_genders && rule.allowed_genders.includes('K');
    document.getElementById('genderALL').checked = rule.allowed_genders && rule.allowed_genders.includes('ALL');

    // Set age
    document.getElementById('classRuleMinAge').value = rule.min_age || '';
    document.getElementById('classRuleMaxAge').value = rule.max_age || '';

    // Set license types
    document.querySelectorAll('.licenseTypeCheckbox').forEach(cb => {
        cb.checked = rule.allowed_license_types && rule.allowed_license_types.includes(cb.value);
    });

    // Set requirements
    document.getElementById('classRuleRequiresLicense').checked = rule.requires_license == 1;
    document.getElementById('classRuleRequiresClub').checked = rule.requires_club_membership == 1;
    document.getElementById('classRuleIsActive').checked = rule.is_active == 1;

    document.getElementById('classRuleModal').style.display = 'flex';
}

function closeClassRuleModal() {
    document.getElementById('classRuleModal').style.display = 'none';
}

// Event Rule Modal
function openEventRuleModal(eventId, useSeriesRules, ruleTypeId) {
    document.getElementById('eventRuleEventId').value = eventId;
    document.getElementById('useSeriesRules').checked = useSeriesRules == 1;
    document.getElementById('useEventRules').checked = useSeriesRules == 0;

    if (ruleTypeId) {
        document.getElementById('eventRuleTypeId').value = ruleTypeId;
    }

    toggleEventRuleType();
    document.getElementById('eventRuleModal').style.display = 'flex';
}

function closeEventRuleModal() {
    document.getElementById('eventRuleModal').style.display = 'none';
}

function toggleEventRuleType() {
    const section = document.getElementById('eventRuleTypeSection');
    if (document.getElementById('useEventRules').checked) {
        section.classList.remove('gs-hidden');
    } else {
        section.classList.add('gs-hidden');
    }
}

// Preview Modal
function closePreviewModal() {
    document.getElementById('previewModal').style.display = 'none';
}

async function previewClassRiders(seriesId, classId) {
    const modal = document.getElementById('previewModal');
    const content = document.getElementById('previewContent');

    content.innerHTML = '<p class="gs-text-center gs-text-secondary">Laddar...</p>';
    modal.style.display = 'flex';

    try {
        // Get first event in the series for validation
        const events = <?= json_encode($seriesEvents) ?>;
        if (events.length === 0) {
            content.innerHTML = '<p class="gs-text-error">Inga events finns i serien.</p>';
            return;
        }

        const eventId = events[0].id;
        const response = await fetch(`/api/validate-registration.php?event_id=${eventId}&class_id=${classId}&action=eligible_riders&limit=50`);
        const data = await response.json();

        if (data.success) {
            let html = `
                <div class="gs-mb-md">
                    <strong>Totalt kontrollerade:</strong> ${data.total}<br>
                    <span class="gs-text-success"><strong>Behöriga:</strong> ${data.eligible_count}</span><br>
                    <span class="gs-text-error"><strong>Ej behöriga:</strong> ${data.ineligible_count}</span>
                </div>
            `;

            if (data.eligible.length > 0) {
                html += '<h4 class="gs-h4 gs-mb-sm">Behöriga åkare</h4>';
                html += '<ul class="gs-list">';
                data.eligible.forEach(rider => {
                    html += `<li>${rider.name} (${rider.birth_year}, ${rider.gender})`;
                    if (rider.warnings && rider.warnings.length > 0) {
                        html += ` <span class="gs-text-warning gs-text-sm">⚠ ${rider.warnings.join(', ')}</span>`;
                    }
                    html += '</li>';
                });
                html += '</ul>';
            }

            if (data.ineligible.length > 0) {
                html += '<h4 class="gs-h4 gs-mt-lg gs-mb-sm">Ej behöriga</h4>';
                html += '<ul class="gs-list">';
                data.ineligible.forEach(rider => {
                    html += `<li class="gs-text-error">${rider.name} - ${rider.errors.join(', ')}</li>`;
                });
                html += '</ul>';
            }

            content.innerHTML = html;
        } else {
            content.innerHTML = `<p class="gs-text-error">${data.error || 'Ett fel uppstod'}</p>`;
        }
    } catch (error) {
        content.innerHTML = `<p class="gs-text-error">Kunde inte ladda data: ${error.message}</p>`;
    }
}

// Keyboard shortcuts
document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
        closeClassRuleModal();
        closeEventRuleModal();
        closePreviewModal();
    }
});

// Initialize Lucide icons
document.addEventListener('DOMContentLoaded', () => {
    if (typeof lucide !== 'undefined') {
        lucide.createIcons();
    }
});
</script>

<?php include __DIR__ . '/../includes/layout-footer.php'; ?>
