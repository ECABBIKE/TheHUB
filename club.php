<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/class-calculations.php';

$db = getDB();

// Get club ID from URL
$clubId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$clubId) {
    header('Location: /riders.php');
    exit;
}

// Fetch club details
$club = $db->getRow("
    SELECT * FROM clubs WHERE id = ?
", [$clubId]);

if (!$club) {
    header('Location: /riders.php');
    exit;
}

// Fetch all riders from this club with their total points
$clubRiders = $db->getAll("
    SELECT
        r.id,
        r.firstname,
        r.lastname,
        r.birth_year,
        r.gender,
        r.license_number,
        r.license_type,
        r.city,
        COUNT(DISTINCT res.id) as total_races,
        SUM(res.points) as total_points,
        MIN(res.position) as best_position,
        COUNT(CASE WHEN res.position = 1 THEN 1 END) as wins
    FROM riders r
    LEFT JOIN results res ON r.id = res.cyclist_id
    WHERE r.club_id = ? AND r.active = 1
    GROUP BY r.id
    ORDER BY total_points DESC NULLS LAST, r.lastname, r.firstname
", [$clubId]);

$currentYear = date('Y');

$pageTitle = $club['name'];
$pageType = 'public';
include __DIR__ . '/includes/layout-header.php';
?>

<style>
    .club-header-card {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        border-radius: 12px;
        padding: 2rem;
        margin-bottom: 2rem;
        box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3);
    }

    .club-stats {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
        gap: 1rem;
        margin-top: 1.5rem;
    }

    .club-stat {
        text-align: center;
        background: rgba(255, 255, 255, 0.1);
        padding: 1rem;
        border-radius: 8px;
    }

    .club-stat-number {
        font-size: 2rem;
        font-weight: 800;
        line-height: 1;
        margin-bottom: 0.5rem;
    }

    .club-stat-label {
        font-size: 0.875rem;
        opacity: 0.9;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .rider-card {
        background: white;
        border-radius: 8px;
        padding: 1rem;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        transition: transform 0.2s, box-shadow 0.2s;
        text-decoration: none;
        color: inherit;
        display: block;
    }

    .rider-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
    }

    .rider-name {
        font-size: 1.125rem;
        font-weight: 700;
        color: #1a202c;
        margin-bottom: 0.5rem;
    }

    .rider-stats {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 0.5rem;
        margin-top: 0.75rem;
    }

    .rider-stat {
        text-align: center;
    }

    .rider-stat-value {
        font-size: 1.25rem;
        font-weight: 700;
        color: #667eea;
    }

    .rider-stat-label {
        font-size: 0.75rem;
        color: #718096;
        text-transform: uppercase;
    }

    .no-points {
        opacity: 0.6;
    }
</style>

<main class="gs-main-content">
    <div class="gs-container">
        <!-- Back Button -->
        <div class="gs-mb-lg">
            <a href="/riders.php" class="gs-btn gs-btn-outline gs-btn-sm">
                <i data-lucide="arrow-left"></i>
                Tillbaka till deltagare
            </a>
        </div>

        <!-- Club Header -->
        <div class="club-header-card">
            <h1 class="gs-h1" style="margin: 0 0 0.5rem 0;">
                <i data-lucide="users"></i>
                <?= h($club['name']) ?>
            </h1>
            <?php if ($club['city']): ?>
                <p style="font-size: 1.125rem; opacity: 0.9; margin: 0 0 1.5rem 0;">
                    <i data-lucide="map-pin"></i>
                    <?= h($club['city']) ?>
                </p>
            <?php endif; ?>

            <div class="club-stats">
                <div class="club-stat">
                    <div class="club-stat-number"><?= count($clubRiders) ?></div>
                    <div class="club-stat-label">Aktiva medlemmar</div>
                </div>
                <div class="club-stat">
                    <div class="club-stat-number"><?= array_sum(array_column($clubRiders, 'total_races')) ?></div>
                    <div class="club-stat-label">Totalt lopp</div>
                </div>
                <div class="club-stat">
                    <div class="club-stat-number"><?= array_sum(array_column($clubRiders, 'total_points')) ?: 0 ?></div>
                    <div class="club-stat-label">Totala poäng</div>
                </div>
                <div class="club-stat">
                    <div class="club-stat-number"><?= array_sum(array_column($clubRiders, 'wins')) ?></div>
                    <div class="club-stat-label">Segrar</div>
                </div>
            </div>
        </div>

        <!-- Riders List -->
        <div class="gs-card">
            <div class="gs-card-header">
                <h2 class="gs-h4 gs-text-primary">
                    <i data-lucide="trophy"></i>
                    Medlemmar med poäng
                </h2>
            </div>
            <div class="gs-card-content">
                <?php if (empty($clubRiders)): ?>
                    <div class="gs-text-center" style="padding: 3rem;">
                        <i data-lucide="users" style="width: 64px; height: 64px; margin: 0 auto 1rem; opacity: 0.3;"></i>
                        <h3 class="gs-h4 gs-mb-sm">Inga medlemmar hittades</h3>
                        <p class="gs-text-secondary">
                            Denna klubb har inga aktiva medlemmar registrerade.
                        </p>
                    </div>
                <?php else: ?>
                    <div class="gs-grid gs-grid-cols-1 gs-md-grid-cols-2 gs-lg-grid-cols-3 gs-gap-md">
                        <?php foreach ($clubRiders as $rider): ?>
                            <?php
                            // Calculate age and class
                            $age = ($rider['birth_year'] && $rider['birth_year'] > 0)
                                ? ($currentYear - $rider['birth_year'])
                                : null;

                            $riderClass = null;
                            if ($rider['birth_year'] && $rider['gender']) {
                                $classId = determineRiderClass($db, $rider['birth_year'], $rider['gender'], date('Y-m-d'));
                                if ($classId) {
                                    $class = $db->getRow("SELECT name, display_name FROM classes WHERE id = ?", [$classId]);
                                    $riderClass = $class['display_name'] ?? null;
                                }
                            }

                            $hasPoints = $rider['total_points'] > 0;
                            ?>
                            <a href="/rider.php?id=<?= $rider['id'] ?>" class="rider-card <?= !$hasPoints ? 'no-points' : '' ?>">
                                <div class="rider-name">
                                    <?= h($rider['firstname']) ?> <?= h($rider['lastname']) ?>
                                </div>

                                <?php if ($riderClass): ?>
                                    <div class="gs-badge gs-badge-primary gs-badge-sm" style="margin-bottom: 0.5rem;">
                                        <?= h($riderClass) ?>
                                    </div>
                                <?php endif; ?>

                                <?php if ($age !== null): ?>
                                    <div class="gs-text-sm gs-text-secondary" style="margin-bottom: 0.5rem;">
                                        <?= $age ?> år
                                        <?php if ($rider['city']): ?>
                                            • <?= h($rider['city']) ?>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>

                                <div class="rider-stats">
                                    <div class="rider-stat">
                                        <div class="rider-stat-value"><?= $rider['total_points'] ?: 0 ?></div>
                                        <div class="rider-stat-label">Poäng</div>
                                    </div>
                                    <div class="rider-stat">
                                        <div class="rider-stat-value"><?= $rider['total_races'] ?: 0 ?></div>
                                        <div class="rider-stat-label">Lopp</div>
                                    </div>
                                    <div class="rider-stat">
                                        <div class="rider-stat-value"><?= $rider['wins'] ?: 0 ?></div>
                                        <div class="rider-stat-label">Segrar</div>
                                    </div>
                                    <div class="rider-stat">
                                        <div class="rider-stat-value"><?= $rider['best_position'] ?: '-' ?></div>
                                        <div class="rider-stat-label">Bäst</div>
                                    </div>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</main>

<?php include __DIR__ . '/includes/layout-footer.php'; ?>
