<?php
/**
 * Series Pricing, Registration & Class Rules
 * Configure pricing, registration settings and license restrictions for a series
 */

require_once __DIR__ . '/../config.php';
require_admin();

$db = getDB();

// Get series ID
$seriesId = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($seriesId <= 0) {
    $_SESSION['flash_message'] = 'Välj en serie';
    $_SESSION['flash_type'] = 'warning';
    header('Location: /admin/series.php');
    exit;
}

// Fetch series
$series = $db->getRow("SELECT * FROM series WHERE id = ?", [$seriesId]);

if (!$series) {
    $_SESSION['flash_message'] = 'Serie hittades inte';
    $_SESSION['flash_type'] = 'error';
    header('Location: /admin/series.php');
    exit;
}

// Fetch pricing templates for dropdown
$pricingTemplates = $db->getAll("SELECT id, name, is_default FROM pricing_templates ORDER BY is_default DESC, name ASC");

// Initialize message
$message = '';
$messageType = 'info';

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $action = $_POST['action'] ?? '';

    // Save registration settings
    if ($action === 'save_registration') {
        $registrationEnabled = isset($_POST['registration_enabled']) ? 1 : 0;
        $registrationOpens = !empty($_POST['registration_opens']) ? $_POST['registration_opens'] : null;
        $registrationOpensTime = !empty($_POST['registration_opens_time']) ? $_POST['registration_opens_time'] : null;
        $registrationCloses = !empty($_POST['registration_closes']) ? $_POST['registration_closes'] : null;
        $registrationClosesTime = !empty($_POST['registration_closes_time']) ? $_POST['registration_closes_time'] : null;
        $pricingTemplateId = !empty($_POST['pricing_template_id']) ? intval($_POST['pricing_template_id']) : null;

        $db->update('series', [
            'registration_enabled' => $registrationEnabled,
            'registration_opens' => $registrationOpens,
            'registration_opens_time' => $registrationOpensTime,
            'registration_closes' => $registrationCloses,
            'registration_closes_time' => $registrationClosesTime,
            'pricing_template_id' => $pricingTemplateId
        ], 'id = ?', [$seriesId]);

        // Refresh series data
        $series = $db->getRow("SELECT * FROM series WHERE id = ?", [$seriesId]);

        $message = 'Anmälningsinställningar sparade!';
        $messageType = 'success';
    }

    elseif ($action === 'save_pricing') {
        $classIds = $_POST['class_id'] ?? [];
        $basePrices = $_POST['base_price'] ?? [];
        $earlyBirdDiscounts = $_POST['early_bird_discount'] ?? [];
        $earlyBirdDays = $_POST['early_bird_days'] ?? [];

        $saved = 0;
        foreach ($classIds as $index => $classId) {
            $basePrice = floatval($basePrices[$index] ?? 0);
            $earlyBirdDiscount = floatval($earlyBirdDiscounts[$index] ?? 0);
            $earlyBirdDaysBefore = intval($earlyBirdDays[$index] ?? 20);

            if ($basePrice > 0) {
                $existing = $db->getRow("SELECT id FROM series_pricing_rules WHERE series_id = ? AND class_id = ?", [$seriesId, $classId]);

                if ($existing) {
                    $db->update('series_pricing_rules', [
                        'base_price' => $basePrice,
                        'early_bird_discount_percent' => $earlyBirdDiscount,
                        'early_bird_days_before' => $earlyBirdDaysBefore
                    ], 'id = ?', [$existing['id']]);
                } else {
                    $db->insert('series_pricing_rules', [
                        'series_id' => $seriesId,
                        'class_id' => $classId,
                        'base_price' => $basePrice,
                        'early_bird_discount_percent' => $earlyBirdDiscount,
                        'early_bird_days_before' => $earlyBirdDaysBefore
                    ]);
                }
                $saved++;
            } else {
                $db->delete('series_pricing_rules', 'series_id = ? AND class_id = ?', [$seriesId, $classId]);
            }
        }
        $message = "Sparade $saved priser";
        $messageType = 'success';
    }

    elseif ($action === 'save_class_rules') {
 $classIds = $_POST['class_id'] ?? [];
 $allowedLicenses = $_POST['allowed_licenses'] ?? [];
 $minBirthYears = $_POST['min_birth_year'] ?? [];
 $maxBirthYears = $_POST['max_birth_year'] ?? [];
 $allowedGenders = $_POST['allowed_genders'] ?? [];
 $requiresLicense = $_POST['requires_license'] ?? [];

 $saved = 0;
 foreach ($classIds as $index => $classId) {
 $licenses = array_filter(explode(',', trim($allowedLicenses[$index] ?? '')));
 $genders = array_filter(explode(',', trim($allowedGenders[$index] ?? '')));
 $minYear = !empty($minBirthYears[$index]) ? intval($minBirthYears[$index]) : null;
 $maxYear = !empty($maxBirthYears[$index]) ? intval($maxBirthYears[$index]) : null;
 $reqLicense = isset($requiresLicense[$index]) ? 1 : 0;

 // Check if exists
 $existing = $db->getRow("SELECT id FROM series_class_rules WHERE series_id = ? AND class_id = ?", [$seriesId, $classId]);

 $data = [
 'allowed_license_types' => !empty($licenses) ? json_encode($licenses) : null,
 'min_birth_year' => $minYear,
 'max_birth_year' => $maxYear,
 'allowed_genders' => !empty($genders) ? json_encode($genders) : null,
 'requires_license' => $reqLicense,
 'is_active' => 1
 ];

 if ($existing) {
 $db->update('series_class_rules', $data, 'id = ?', [$existing['id']]);
 } else {
 $data['series_id'] = $seriesId;
 $data['class_id'] = $classId;
 $db->insert('series_class_rules', $data);
 }
 $saved++;
 }
 $message ="Sparade $saved klassregler";
 $messageType = 'success';
 }
}

// Fetch all classes
$classes = $db->getAll("SELECT id, name, display_name FROM classes ORDER BY sort_order ASC");

// Fetch existing pricing rules
$pricingRules = $db->getAll("SELECT * FROM series_pricing_rules WHERE series_id = ?", [$seriesId]);
$pricingMap = [];
foreach ($pricingRules as $rule) {
 $pricingMap[$rule['class_id']] = $rule;
}

// Fetch existing class rules
$classRules = $db->getAll("SELECT * FROM series_class_rules WHERE series_id = ?", [$seriesId]);
$rulesMap = [];
foreach ($classRules as $rule) {
 $rulesMap[$rule['class_id']] = $rule;
}

// Get active tab - default to registration
$activeTab = $_GET['tab'] ?? 'registration';

$pageTitle = 'Anmälan & Priser - ' . $series['name'];
$pageType = 'admin';
include __DIR__ . '/../includes/layout-header.php';
?>

<main class="main-content">
    <div class="container">
        <!-- Header -->
        <div class="card mb-lg">
            <div class="card-body">
                <div class="flex justify-between items-center">
                    <div>
                        <h1>
                            <i data-lucide="settings"></i>
                            <?= htmlspecialchars($series['name']) ?>
                        </h1>
                        <p class="text-secondary text-sm">
                            Konfigurera anmälan, priser och klassregler
                        </p>
                    </div>
                    <a href="/admin/series.php" class="btn btn--secondary">
                        <i data-lucide="arrow-left"></i>
                        Tillbaka
                    </a>
                </div>
            </div>
        </div>

        <?php if ($message): ?>
        <div class="alert alert-<?= $messageType ?> mb-lg">
            <?= htmlspecialchars($message) ?>
        </div>
        <?php endif; ?>

        <!-- Tabs -->
        <div class="gs-tabs mb-lg">
            <a href="?id=<?= $seriesId ?>&tab=registration" class="tab <?= $activeTab === 'registration' ? 'active' : '' ?>">
                <i data-lucide="clipboard-list"></i>
                Anmälan
            </a>
            <a href="?id=<?= $seriesId ?>&tab=pricing" class="tab <?= $activeTab === 'pricing' ? 'active' : '' ?>">
                <i data-lucide="credit-card"></i>
                Priser
            </a>
            <a href="?id=<?= $seriesId ?>&tab=rules" class="tab <?= $activeTab === 'rules' ? 'active' : '' ?>">
                <i data-lucide="shield-check"></i>
                Klassregler
            </a>
        </div>

        <?php if ($activeTab === 'registration'): ?>
        <!-- Registration Tab -->
        <div class="card">
            <div class="card-header">
                <h2>
                    <i data-lucide="clipboard-list"></i>
                    Anmälningsinställningar
                </h2>
            </div>
            <div class="card-body">
                <form method="POST">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="save_registration">

                    <div class="admin-form-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: var(--space-lg);">
                        <!-- Enable/Disable -->
                        <div class="admin-form-group">
                            <label class="admin-form-label">
                                <input type="checkbox" name="registration_enabled" value="1"
                                    <?= ($series['registration_enabled'] ?? 0) ? 'checked' : '' ?>>
                                Anmälan aktiverad för serien
                            </label>
                            <small class="form-help">Aktivera för att tillåta anmälan till events i denna serie</small>
                        </div>

                        <!-- Pricing Template -->
                        <div class="admin-form-group">
                            <label class="admin-form-label">Standard prismall</label>
                            <select name="pricing_template_id" class="form-select">
                                <option value="">-- Välj prismall --</option>
                                <?php foreach ($pricingTemplates as $template): ?>
                                    <option value="<?= $template['id'] ?>"
                                        <?= ($series['pricing_template_id'] ?? '') == $template['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($template['name']) ?>
                                        <?= $template['is_default'] ? '(Standard)' : '' ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <small class="form-help">
                                Används som default för nya events.
                                <a href="/admin/pricing-templates.php">Hantera prismallar</a>
                            </small>
                        </div>
                    </div>

                    <hr style="margin: var(--space-lg) 0; border-color: var(--color-border);">

                    <h3 style="margin-bottom: var(--space-md);">
                        <i data-lucide="calendar"></i>
                        Anmälningsperiod
                    </h3>

                    <div class="admin-form-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: var(--space-md);">
                        <!-- Opens Date -->
                        <div class="admin-form-group">
                            <label class="admin-form-label">Anmälan öppnar (datum)</label>
                            <input type="date" name="registration_opens" class="form-input"
                                value="<?= htmlspecialchars($series['registration_opens'] ?? '') ?>">
                        </div>

                        <!-- Opens Time -->
                        <div class="admin-form-group">
                            <label class="admin-form-label">Tid</label>
                            <input type="time" name="registration_opens_time" class="form-input"
                                value="<?= htmlspecialchars($series['registration_opens_time'] ?? '00:00') ?>">
                        </div>

                        <!-- Closes Date -->
                        <div class="admin-form-group">
                            <label class="admin-form-label">Anmälan stänger (datum)</label>
                            <input type="date" name="registration_closes" class="form-input"
                                value="<?= htmlspecialchars($series['registration_closes'] ?? '') ?>">
                        </div>

                        <!-- Closes Time -->
                        <div class="admin-form-group">
                            <label class="admin-form-label">Tid</label>
                            <input type="time" name="registration_closes_time" class="form-input"
                                value="<?= htmlspecialchars($series['registration_closes_time'] ?? '23:59') ?>">
                        </div>
                    </div>

                    <div class="alert alert--info mt-lg">
                        <i data-lucide="info"></i>
                        <div>
                            <strong>Tips:</strong> Dessa inställningar gäller som default för alla events i serien.
                            Varje event kan ha egna inställningar som överskrider dessa.
                        </div>
                    </div>

                    <div class="mt-lg">
                        <button type="submit" class="btn btn--primary">
                            <i data-lucide="save"></i>
                            Spara inställningar
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <?php elseif ($activeTab === 'pricing'): ?>
        <!-- Pricing Tab -->
        <div class="card">
 <div class="card-header">
 <h2 class="">
  <i data-lucide="credit-card"></i>
  Priser per klass
 </h2>
 </div>
 <div class="card-body">
 <form method="POST">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="save_pricing">

  <div class="table-responsive">
  <table class="table">
  <thead>
  <tr>
   <th>Klass</th>
   <th>Grundpris (kr)</th>
   <th>Early Bird %</th>
   <th>Dagar före</th>
  </tr>
  </thead>
  <tbody>
  <?php foreach ($classes as $class):
   $pricing = $pricingMap[$class['id']] ?? null;
  ?>
   <tr>
   <td>
   <input type="hidden" name="class_id[]" value="<?= $class['id'] ?>">
   <strong><?= htmlspecialchars($class['display_name'] ?: $class['name']) ?></strong>
   </td>
   <td>
   <input type="number" name="base_price[]" class="input"
    value="<?= $pricing['base_price'] ?? '' ?>"
    min="0" step="1" style="width: 100px;">
   </td>
   <td>
   <input type="number" name="early_bird_discount[]" class="input"
    value="<?= $pricing['early_bird_discount_percent'] ?? '10' ?>"
    min="0" max="100" step="1" style="width: 80px;">
   </td>
   <td>
   <input type="number" name="early_bird_days[]" class="input"
    value="<?= $pricing['early_bird_days_before'] ?? '20' ?>"
    min="0" max="90" step="1" style="width: 80px;">
   </td>
   </tr>
  <?php endforeach; ?>
  </tbody>
  </table>
  </div>

  <div class="alert alert--info mt-md">
  <i data-lucide="info"></i>
  <strong>Tips:</strong> Lämna grundpris tomt (0) för klasser som inte ska vara tillgängliga i denna serie.
  </div>

  <div class="mt-lg">
  <button type="submit" class="btn btn--primary">
  <i data-lucide="save"></i>
  Spara priser
  </button>
  </div>
 </form>
 </div>
 </div>

 <?php else: ?>
 <!-- Class Rules Tab -->
 <div class="card">
 <div class="card-header">
 <h2 class="">
  <i data-lucide="shield-check"></i>
  Klassregler (licensrestriktioner)
 </h2>
 </div>
 <div class="card-body">
 <form method="POST">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="save_class_rules">

  <div class="table-responsive">
  <table class="table">
  <thead>
  <tr>
   <th>Klass</th>
   <th>Tillåtna licenser</th>
   <th>Födelseår (min-max)</th>
   <th>Kön</th>
   <th>Kräver licens</th>
  </tr>
  </thead>
  <tbody>
  <?php foreach ($classes as $class):
   $rule = $rulesMap[$class['id']] ?? null;
   $licenses = $rule && $rule['allowed_license_types'] ? implode(',', json_decode($rule['allowed_license_types'], true)) : '';
   $genders = $rule && $rule['allowed_genders'] ? implode(',', json_decode($rule['allowed_genders'], true)) : '';
  ?>
   <tr>
   <td>
   <input type="hidden" name="class_id[]" value="<?= $class['id'] ?>">
   <strong><?= htmlspecialchars($class['display_name'] ?: $class['name']) ?></strong>
   </td>
   <td>
   <input type="text" name="allowed_licenses[]" class="input"
    value="<?= htmlspecialchars($licenses) ?>"
    placeholder="Elite,Junior,Hobby"
    style="width: 150px;">
   </td>
   <td>
   <div class="flex gap-sm">
   <input type="number" name="min_birth_year[]" class="input"
    value="<?= $rule['min_birth_year'] ?? '' ?>"
    placeholder="1990" style="width: 70px;">
   <span>-</span>
   <input type="number" name="max_birth_year[]" class="input"
    value="<?= $rule['max_birth_year'] ?? '' ?>"
    placeholder="2010" style="width: 70px;">
   </div>
   </td>
   <td>
   <input type="text" name="allowed_genders[]" class="input"
    value="<?= htmlspecialchars($genders) ?>"
    placeholder="M,F"
    style="width: 60px;">
   </td>
   <td class="text-center">
   <input type="checkbox" name="requires_license[<?= $class['id'] ?>]" value="1"
    <?= ($rule['requires_license'] ?? 1) ? 'checked' : '' ?>>
   </td>
   </tr>
  <?php endforeach; ?>
  </tbody>
  </table>
  </div>

  <div class="alert alert--info mt-md">
  <i data-lucide="info"></i>
  <strong>Format:</strong>
  <ul class="mt-sm gs-ml-lg">
  <li>Licenser: Kommaseparerade (t.ex. Elite,Junior,Hobby)</li>
  <li>Kön: M för man, F för kvinna</li>
  <li>Lämna tomt = alla tillåtna</li>
  </ul>
  </div>

  <div class="mt-lg">
  <button type="submit" class="btn btn--primary">
  <i data-lucide="save"></i>
  Spara klassregler
  </button>
  </div>
 </form>
 </div>
 </div>
 <?php endif; ?>
 </div>
</main>


<?php include __DIR__ . '/../includes/layout-footer.php'; ?>
