<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/point-calculations.php';
require_once __DIR__ . '/includes/class-calculations.php';

$db = getDB();

// Get series ID from URL
$seriesId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$seriesId) {
    header('Location: /series.php');
    exit;
}

// Fetch series details
$series = $db->getRow("
    SELECT s.*, COUNT(DISTINCT e.id) as event_count
    FROM series s
    LEFT JOIN events e ON s.id = e.series_id
    WHERE s.id = ?
    GROUP BY s.id
", [$seriesId]);

if (!$series) {
    header('Location: /series.php');
    exit;
}

// Get view mode (overall or class)
$viewMode = isset($_GET['view']) ? $_GET['view'] : 'overall';
$selectedClass = isset($_GET['class']) ? (int)$_GET['class'] : null;

// Get overall standings
$overallStandings = getSeriesStandings($db, $seriesId, null, 100);

// Get class standings if classes are enabled
$classStandings = [];
$activeClasses = [];
if ($series['enable_classes']) {
    // Get all classes that have results in this series
    $activeClasses = $db->getAll("
        SELECT DISTINCT c.id, c.name, c.display_name, c.sort_order,
               COUNT(DISTINCT r.cyclist_id) as rider_count
        FROM classes c
        JOIN results r ON c.id = r.class_id
        JOIN events e ON r.event_id = e.id
        WHERE e.series_id = ? AND c.active = 1
        GROUP BY c.id
        ORDER BY c.sort_order ASC
    ", [$seriesId]);

    // Get standings for selected class or all classes
    if ($selectedClass) {
        $classStandings = getClassSeriesStandings($db, $seriesId, $selectedClass, 100);
    } elseif (!empty($activeClasses)) {
        // Get standings for first class by default
        $selectedClass = $activeClasses[0]['id'];
        $classStandings = getClassSeriesStandings($db, $seriesId, $selectedClass, 100);
    }
}

$pageTitle = $series['name'] . ' - Ställning';
$pageType = 'public';
include __DIR__ . '/includes/layout-header.php';
?>

<main class="gs-main-content">
    <div class="gs-container">
        <!-- Back Button -->
        <div class="gs-mb-lg">
            <a href="/series.php" class="gs-btn gs-btn-outline gs-btn-sm">
                <i data-lucide="arrow-left"></i>
                Tillbaka till serier
            </a>
        </div>

        <!-- Header -->
        <div class="gs-mb-xl">
            <h1 class="gs-h2 gs-text-primary gs-mb-sm">
                <i data-lucide="trophy"></i>
                <?= h($series['name']) ?>
            </h1>
            <?php if ($series['description']): ?>
                <p class="gs-text-secondary">
                    <?= h($series['description']) ?>
                </p>
            <?php endif; ?>
            <div class="gs-flex gs-gap-sm gs-mt-md">
                <span class="gs-badge gs-badge-primary">
                    <?= $series['year'] ?>
                </span>
                <span class="gs-badge gs-badge-secondary">
                    <?= $series['event_count'] ?> tävlingar
                </span>
                <?php if ($series['count_best_results']): ?>
                    <span class="gs-badge gs-badge-info">
                        Räknar <?= $series['count_best_results'] ?> bästa resultat
                    </span>
                <?php endif; ?>
            </div>
        </div>

        <!-- View Mode Tabs -->
        <?php if ($series['enable_classes'] && !empty($activeClasses)): ?>
            <div class="gs-tabs gs-mb-lg">
                <a href="?id=<?= $seriesId ?>&view=overall" class="gs-tab <?= $viewMode === 'overall' ? 'active' : '' ?>">
                    <i data-lucide="users"></i>
                    Totalställning
                    <span class="gs-badge"><?= count($overallStandings) ?></span>
                </a>
                <a href="?id=<?= $seriesId ?>&view=class" class="gs-tab <?= $viewMode === 'class' ? 'active' : '' ?>">
                    <i data-lucide="trophy"></i>
                    Klassställning
                    <span class="gs-badge"><?= count($activeClasses) ?> klasser</span>
                </a>
            </div>
        <?php endif; ?>

        <!-- Overall Standings -->
        <?php if ($viewMode === 'overall'): ?>
            <div class="gs-card">
                <div class="gs-card-header">
                    <h3 class="gs-h4">
                        <i data-lucide="trophy"></i>
                        Totalställning
                    </h3>
                </div>
                <div class="gs-card-content" style="padding: 0;">
                    <?php if (empty($overallStandings)): ?>
                        <div style="padding: 3rem; text-align: center;">
                            <i data-lucide="inbox" style="width: 48px; height: 48px; margin: 0 auto 1rem; opacity: 0.3;"></i>
                            <p class="gs-text-secondary">Inga resultat ännu</p>
                        </div>
                    <?php else: ?>
                        <table class="gs-table">
                            <thead>
                                <tr>
                                    <th style="width: 60px;">Plac.</th>
                                    <th>Namn</th>
                                    <th>Klubb</th>
                                    <th style="text-align: center;">Lopp</th>
                                    <th style="text-align: center;">Poäng</th>
                                    <th style="text-align: center;">Segrar</th>
                                    <th style="text-align: center;">Pall</th>
                                    <th style="text-align: center;">Bäst</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $position = 1; ?>
                                <?php foreach ($overallStandings as $rider): ?>
                                    <tr>
                                        <td>
                                            <?php if ($position == 1): ?>
                                                <span class="gs-badge gs-badge-success">🥇 1</span>
                                            <?php elseif ($position == 2): ?>
                                                <span class="gs-badge gs-badge-secondary">🥈 2</span>
                                            <?php elseif ($position == 3): ?>
                                                <span class="gs-badge gs-badge-warning">🥉 3</span>
                                            <?php else: ?>
                                                <?= $position ?>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <a href="/rider.php?id=<?= $rider['rider_id'] ?>" class="gs-link">
                                                <strong><?= h($rider['firstname']) ?> <?= h($rider['lastname']) ?></strong>
                                            </a>
                                            <?php if ($rider['birth_year']): ?>
                                                <br><small class="gs-text-secondary">
                                                    <?= $rider['gender'] === 'M' ? '👨' : '👩' ?> <?= calculateAge($rider['birth_year']) ?> år
                                                </small>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= h($rider['club_name']) ?: '–' ?></td>
                                        <td style="text-align: center;"><?= $rider['events_count'] ?></td>
                                        <td style="text-align: center;">
                                            <strong class="gs-text-primary"><?= $rider['total_points'] ?></strong>
                                        </td>
                                        <td style="text-align: center;"><?= $rider['wins'] ?></td>
                                        <td style="text-align: center;"><?= $rider['podiums'] ?></td>
                                        <td style="text-align: center;"><?= $rider['best_position'] ?? '–' ?></td>
                                    </tr>
                                    <?php $position++; ?>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- Class Standings -->
        <?php if ($viewMode === 'class' && $series['enable_classes']): ?>
            <!-- Class Selector -->
            <?php if (count($activeClasses) > 1): ?>
                <div class="gs-mb-lg">
                    <label class="gs-label">Välj klass</label>
                    <select class="gs-input" style="max-width: 300px;" onchange="window.location.href='?id=<?= $seriesId ?>&view=class&class=' + this.value">
                        <?php foreach ($activeClasses as $class): ?>
                            <option value="<?= $class['id'] ?>" <?= $selectedClass == $class['id'] ? 'selected' : '' ?>>
                                <?= h($class['name']) ?> - <?= h($class['display_name']) ?> (<?= $class['rider_count'] ?> deltagare)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>

            <!-- Selected Class Standings -->
            <?php if ($selectedClass): ?>
                <?php
                $selectedClassName = '';
                $selectedClassDisplay = '';
                foreach ($activeClasses as $class) {
                    if ($class['id'] == $selectedClass) {
                        $selectedClassName = $class['name'];
                        $selectedClassDisplay = $class['display_name'];
                        break;
                    }
                }
                ?>

                <div class="gs-card">
                    <div class="gs-card-header">
                        <h3 class="gs-h4">
                            <i data-lucide="trophy"></i>
                            <?= h($selectedClassName) ?> - <?= h($selectedClassDisplay) ?>
                        </h3>
                    </div>
                    <div class="gs-card-content" style="padding: 0;">
                        <?php if (empty($classStandings)): ?>
                            <div style="padding: 3rem; text-align: center;">
                                <i data-lucide="inbox" style="width: 48px; height: 48px; margin: 0 auto 1rem; opacity: 0.3;"></i>
                                <p class="gs-text-secondary">Inga resultat för denna klass</p>
                            </div>
                        <?php else: ?>
                            <table class="gs-table">
                                <thead>
                                    <tr>
                                        <th style="width: 60px;">Plac.</th>
                                        <th>Namn</th>
                                        <th>Klubb</th>
                                        <th style="text-align: center;">Lopp</th>
                                        <th style="text-align: center;">Klasspoäng</th>
                                        <th style="text-align: center;">Klasssegrar</th>
                                        <th style="text-align: center;">Klasspall</th>
                                        <th style="text-align: center;">Bäst i klass</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php $position = 1; ?>
                                    <?php foreach ($classStandings as $rider): ?>
                                        <tr>
                                            <td>
                                                <?php if ($position == 1): ?>
                                                    <span class="gs-badge gs-badge-success">🥇 1</span>
                                                <?php elseif ($position == 2): ?>
                                                    <span class="gs-badge gs-badge-secondary">🥈 2</span>
                                                <?php elseif ($position == 3): ?>
                                                    <span class="gs-badge gs-badge-warning">🥉 3</span>
                                                <?php else: ?>
                                                    <?= $position ?>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <a href="/rider.php?id=<?= $rider['rider_id'] ?>" class="gs-link">
                                                    <strong><?= h($rider['firstname']) ?> <?= h($rider['lastname']) ?></strong>
                                                </a>
                                                <?php if ($rider['birth_year']): ?>
                                                    <br><small class="gs-text-secondary">
                                                        <?= $rider['gender'] === 'M' ? '👨' : '👩' ?> <?= calculateAge($rider['birth_year']) ?> år
                                                    </small>
                                                <?php endif; ?>
                                            </td>
                                            <td><?= h($rider['club_name']) ?: '–' ?></td>
                                            <td style="text-align: center;"><?= $rider['events_count'] ?></td>
                                            <td style="text-align: center;">
                                                <strong class="gs-text-primary"><?= $rider['total_class_points'] ?? 0 ?></strong>
                                            </td>
                                            <td style="text-align: center;"><?= $rider['class_wins'] ?? 0 ?></td>
                                            <td style="text-align: center;"><?= $rider['class_podiums'] ?? 0 ?></td>
                                            <td style="text-align: center;"><?= $rider['best_class_position'] ?? '–' ?></td>
                                        </tr>
                                        <?php $position++; ?>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</main>

<?php include __DIR__ . '/includes/layout-footer.php'; ?>
