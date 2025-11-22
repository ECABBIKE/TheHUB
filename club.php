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
// Use class position (not overall position) for best_position and wins
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
        MIN(
            CASE WHEN res.status = 'finished' THEN
                (SELECT COUNT(*) + 1
                 FROM results r2
                 WHERE r2.event_id = res.event_id
                 AND r2.class_id = res.class_id
                 AND r2.status = 'finished'
                 AND r2.id != res.id
                 AND (r2.finish_time < res.finish_time OR (r2.finish_time = res.finish_time AND r2.id < res.id)))
            ELSE NULL END
        ) as best_position,
        SUM(
            CASE WHEN res.status = 'finished' AND
                (SELECT COUNT(*) + 1
                 FROM results r2
                 WHERE r2.event_id = res.event_id
                 AND r2.class_id = res.class_id
                 AND r2.status = 'finished'
                 AND r2.id != res.id
                 AND (r2.finish_time < res.finish_time OR (r2.finish_time = res.finish_time AND r2.id < res.id))) = 1
            THEN 1 ELSE 0 END
        ) as wins
    FROM riders r
    LEFT JOIN results res ON r.id = res.cyclist_id
    WHERE r.club_id = ? AND r.active = 1
    GROUP BY r.id
    ORDER BY total_points DESC, r.lastname, r.firstname
", [$clubId]);

$currentYear = date('Y');

$pageTitle = $club['name'];
$pageType = 'public';
include __DIR__ . '/includes/layout-header.php';
?>

<style>
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
        grid-template-columns: repeat(5, 1fr);
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

    /* Mobile Responsive */
    @media (max-width: 640px) {
        .rider-stats {
            grid-template-columns: repeat(3, 1fr);
            gap: 0.5rem;
        }

        .rider-stat-value {
            font-size: 1rem;
        }

        .rider-stat-label {
            font-size: 0.625rem;
        }
    }

    @media (min-width: 641px) and (max-width: 1023px) {
        .rider-stats {
            grid-template-columns: repeat(5, 1fr);
        }
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

        <!-- Club License Card -->
        <div class="license-card-container">
            <div class="license-card">
                <!-- Stripe -->
                <div class="uci-stripe"></div>

                <!-- Content -->
                <div class="license-content" style="flex-direction: column; align-items: center; text-align: center;">
                    <!-- Logo -->
                    <?php if (!empty($club['logo'])): ?>
                        <div class="license-photo" style="margin-bottom: 1rem;">
                            <img src="<?= h($club['logo']) ?>" alt="<?= h($club['name']) ?>" style="max-height: 80px; border-radius: 8px; object-fit: contain;">
                        </div>
                    <?php endif; ?>

                    <!-- Info Section -->
                    <div class="license-info" style="text-align: center; width: 100%;">
                        <!-- Club Name -->
                        <div class="rider-name" style="font-size: clamp(1.5rem, 5vw, 2rem);">
                            <?= h($club['name']) ?>
                        </div>

                        <!-- Location -->
                        <?php if ($club['city']): ?>
                            <div class="license-id" style="color: var(--gs-primary); font-weight: 600;">
                                <i data-lucide="map-pin" style="width: 14px; height: 14px; display: inline;"></i>
                                <?= h($club['city']) ?>
                                <?php if (!empty($club['region'])): ?>
                                    , <?= h($club['region']) ?>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <!-- Description -->
                        <?php if (!empty($club['description'])): ?>
                            <div class="gs-text-secondary gs-mt-md" style="max-width: 500px; margin: 0 auto; font-size: 0.875rem;">
                                <?= nl2br(h($club['description'])) ?>
                            </div>
                        <?php endif; ?>

                        <!-- Stats Grid -->
                        <div class="info-grid-compact gs-mt-lg" style="grid-template-columns: repeat(4, 1fr);">
                            <div class="info-box">
                                <div class="info-box-label">Cyklister</div>
                                <div class="info-box-value"><?= count($clubRiders) ?></div>
                            </div>
                            <div class="info-box">
                                <div class="info-box-label">Lopp</div>
                                <div class="info-box-value"><?= array_sum(array_column($clubRiders, 'total_races')) ?></div>
                            </div>
                            <div class="info-box">
                                <div class="info-box-label">Poäng</div>
                                <div class="info-box-value"><?= array_sum(array_column($clubRiders, 'total_points')) ?: 0 ?></div>
                            </div>
                            <div class="info-box">
                                <div class="info-box-label">Segrar</div>
                                <div class="info-box-value"><?= array_sum(array_column($clubRiders, 'wins')) ?></div>
                            </div>
                        </div>

                        <!-- Contact Information -->
                        <?php if (!empty($club['email']) || !empty($club['phone']) || !empty($club['website']) || !empty($club['facebook']) || !empty($club['instagram'])): ?>
                        <div class="gs-mt-lg" style="background: rgba(0,0,0,0.03); padding: 1rem; border-radius: 8px;">
                            <div class="gs-flex gs-gap-md gs-flex-wrap" style="justify-content: center;">
                                <?php if (!empty($club['website'])): ?>
                                <a href="<?= h($club['website']) ?>" target="_blank" rel="noopener" class="gs-flex gs-items-center gs-gap-xs gs-link">
                                    <i data-lucide="globe" class="gs-icon-sm"></i>
                                    Webb
                                </a>
                                <?php endif; ?>

                                <?php if (!empty($club['email'])): ?>
                                <a href="mailto:<?= h($club['email']) ?>" class="gs-flex gs-items-center gs-gap-xs gs-link">
                                    <i data-lucide="mail" class="gs-icon-sm"></i>
                                    E-post
                                </a>
                                <?php endif; ?>

                                <?php if (!empty($club['phone'])): ?>
                                <a href="tel:<?= h($club['phone']) ?>" class="gs-flex gs-items-center gs-gap-xs gs-link">
                                    <i data-lucide="phone" class="gs-icon-sm"></i>
                                    Telefon
                                </a>
                                <?php endif; ?>

                                <?php if (!empty($club['facebook'])): ?>
                                <a href="<?= h($club['facebook']) ?>" target="_blank" rel="noopener" class="gs-flex gs-items-center gs-gap-xs gs-link">
                                    <i data-lucide="facebook" class="gs-icon-sm"></i>
                                    FB
                                </a>
                                <?php endif; ?>

                                <?php if (!empty($club['instagram'])): ?>
                                <a href="<?= h($club['instagram']) ?>" target="_blank" rel="noopener" class="gs-flex gs-items-center gs-gap-xs gs-link">
                                    <i data-lucide="instagram" class="gs-icon-sm"></i>
                                    IG
                                </a>
                                <?php endif; ?>
                            </div>

                            <?php if (!empty($club['contact_person'])): ?>
                            <div class="gs-text-center gs-mt-sm gs-text-xs gs-text-secondary">
                                Kontakt: <?= h($club['contact_person']) ?>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </div>
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
                    <div class="gs-text-center gs-empty-state-container">
                        <i data-lucide="users" class="gs-empty-state-icon-lg"></i>
                        <h3 class="gs-h4 gs-mb-sm">Inga medlemmar hittades</h3>
                        <p class="gs-text-secondary">
                            Denna klubb har inga aktiva medlemmar registrerade.
                        </p>
                    </div>
                <?php else: ?>
                    <div class="gs-grid gs-grid-cols-1 gs-md-grid-cols-2 gs-lg-grid-cols-3 gs-gap-md">
                        <?php foreach ($clubRiders as $rider): ?>
                            <?php
                            // Determine rider's class
                            $riderClass = null;
                            $classId = null;
                            $ranking = '-';

                            if ($rider['birth_year'] && $rider['gender']) {
                                $classId = determineRiderClass($db, $rider['birth_year'], $rider['gender'], date('Y-m-d'));
                                if ($classId) {
                                    $class = $db->getRow("SELECT name, display_name FROM classes WHERE id = ?", [$classId]);
                                    $riderClass = $class['display_name'] ?? null;

                                    // Calculate ranking in class (position based on total points)
                                    if ($rider['total_points'] > 0) {
                                        $rankResult = $db->getRow("
                                            SELECT COUNT(*) + 1 as ranking
                                            FROM riders r
                                            WHERE r.active = 1
                                            AND r.id != ?
                                            AND (
                                                SELECT SUM(res.points)
                                                FROM results res
                                                WHERE res.cyclist_id = r.id
                                            ) > ?
                                            AND r.id IN (
                                                SELECT DISTINCT res2.cyclist_id
                                                FROM results res2
                                                JOIN events e ON res2.event_id = e.id
                                                WHERE res2.class_id = ?
                                            )
                                        ", [$rider['id'], $rider['total_points'], $classId]);
                                        $ranking = $rankResult['ranking'] ?? '-';
                                    }
                                }
                            }

                            $hasPoints = $rider['total_points'] > 0;
                            ?>
                            <a href="/rider.php?id=<?= $rider['id'] ?>" class="rider-card <?= !$hasPoints ? 'no-points' : '' ?>">
                                <div class="rider-name">
                                    <?= h($rider['firstname']) ?> <?= h($rider['lastname']) ?>
                                </div>

                                <?php if ($riderClass): ?>
                                    <div class="gs-badge gs-badge-primary gs-badge-sm gs-mb-2">
                                        <?= h($riderClass) ?>
                                    </div>
                                <?php endif; ?>

                                <div class="rider-stats">
                                    <div class="rider-stat">
                                        <div class="rider-stat-value"><?= $rider['total_races'] ?: 0 ?></div>
                                        <div class="rider-stat-label">Lopp</div>
                                    </div>
                                    <div class="rider-stat">
                                        <div class="rider-stat-value"><?= $rider['total_points'] ?: 0 ?></div>
                                        <div class="rider-stat-label">Poäng</div>
                                    </div>
                                    <div class="rider-stat">
                                        <div class="rider-stat-value"><?= $ranking ?></div>
                                        <div class="rider-stat-label">Ranking</div>
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
