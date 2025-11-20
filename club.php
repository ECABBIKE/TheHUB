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
    ORDER BY total_points DESC, r.lastname, r.firstname
", [$clubId]);

$currentYear = date('Y');

$pageTitle = $club['name'];
$pageType = 'public';
include __DIR__ . '/includes/layout-header.php';
?>

<style>
    /* Club License Card */
    .club-license-card-container {
        margin-bottom: 2rem;
    }

    .club-license-card {
        max-width: 800px;
        margin: 0 auto;
        background: white;
        border-radius: 12px;
        box-shadow: 0 8px 24px rgba(0, 0, 0, 0.15);
        overflow: hidden;
    }

    /* GravitySeries Stripe */
    .club-uci-stripe {
        height: 6px;
        background: linear-gradient(90deg,
            #004a98 0% 25%,
            #8A9A5B 25% 50%,
            #EF761F 50% 75%,
            #FFE009 75% 100%
        );
    }

    /* Header */
    .club-license-header {
        padding: 1.5rem;
        background: linear-gradient(135deg, #2d3748 0%, #1a202c 100%);
        color: white;
        text-align: center;
    }

    .club-license-season {
        font-size: 1rem;
        font-weight: 600;
        background: rgba(255, 255, 255, 0.2);
        padding: 0.5rem 1rem;
        border-radius: 20px;
        display: inline-block;
    }

    /* Main Content */
    .club-license-content {
        padding: 2rem;
    }

    /* Club Name */
    .club-name {
        font-size: clamp(1.75rem, 6vw, 2.5rem);
        font-weight: 800;
        color: #1a202c;
        line-height: 1.2;
        text-transform: uppercase;
        margin-bottom: 0.5rem;
        text-align: center;
    }

    /* Club Location */
    .club-location {
        text-align: center;
        font-size: clamp(1rem, 3.5vw, 1.25rem);
        color: #667eea;
        font-weight: 600;
        margin-bottom: 2rem;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 0.5rem;
    }

    /* Stats Grid */
    .club-stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
        gap: 1rem;
        margin-bottom: 1.5rem;
    }

    .club-stat-box {
        background: #f8f9fa;
        padding: 1.5rem;
        border-radius: 8px;
        border-left: 4px solid #667eea;
        text-align: center;
    }

    .club-stat-number {
        font-size: clamp(2rem, 8vw, 3rem);
        font-weight: 800;
        color: #667eea;
        line-height: 1;
        margin-bottom: 0.5rem;
    }

    .club-stat-label {
        font-size: clamp(0.75rem, 2.5vw, 0.875rem);
        color: #718096;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    /* Club Badge */
    .club-badge {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        padding: 1.5rem;
        border-radius: 8px;
        text-align: center;
    }

    .club-badge-label {
        font-size: clamp(0.75rem, 2.5vw, 0.875rem);
        opacity: 0.9;
        font-weight: 600;
        text-transform: uppercase;
        margin-bottom: 0.5rem;
    }

    .club-badge-text {
        font-size: clamp(1.25rem, 5vw, 1.5rem);
        font-weight: 800;
    }

    /* Footer */
    .club-license-footer {
        padding: 0.75rem 2rem;
        background: rgba(0, 0, 0, 0.03);
        text-align: center;
        font-size: clamp(0.625rem, 2vw, 0.75rem);
        color: #718096;
    }

    @media (max-width: 768px) {
        .club-license-content {
            padding: 1.5rem;
        }

        .club-stats-grid {
            grid-template-columns: repeat(2, 1fr);
        }
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

    /* Mobile Responsive */
    @media (max-width: 640px) {
        .rider-stats {
            grid-template-columns: repeat(2, 1fr);
            gap: 0.75rem;
        }

        .rider-stat-value {
            font-size: 1.125rem;
        }

        .rider-stat-label {
            font-size: 0.6875rem;
        }
    }

    @media (min-width: 641px) and (max-width: 1023px) {
        .rider-stats {
            grid-template-columns: repeat(2, 1fr);
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
        <div class="club-license-card-container">
            <div class="club-license-card">
                <!-- GravitySeries Stripe -->
                <div class="club-uci-stripe"></div>

                <!-- Header -->
                <div class="club-license-header">
                    <div class="club-license-season"><?= $currentYear ?></div>
                </div>

                <!-- Main Content -->
                <div class="club-license-content">
                    <!-- Logo and Name -->
                    <div class="gs-flex gs-items-center gs-gap-lg gs-mb-lg" style="justify-content: center;">
                        <?php if (!empty($club['logo'])): ?>
                            <img src="<?= h($club['logo']) ?>" alt="<?= h($club['name']) ?>" style="max-height: 80px; border-radius: 8px;">
                        <?php endif; ?>
                        <div class="club-name" style="<?= !empty($club['logo']) ? 'text-align: left;' : '' ?>">
                            <?= h($club['name']) ?>
                        </div>
                    </div>

                    <!-- Club Location -->
                    <?php if ($club['city']): ?>
                        <div class="club-location">
                            <i data-lucide="map-pin"></i>
                            <?= h($club['city']) ?>
                            <?php if (!empty($club['region'])): ?>
                                , <?= h($club['region']) ?>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <!-- Description -->
                    <?php if (!empty($club['description'])): ?>
                        <div class="gs-text-center gs-text-secondary gs-mb-lg" style="max-width: 600px; margin: 0 auto;">
                            <?= nl2br(h($club['description'])) ?>
                        </div>
                    <?php endif; ?>

                    <!-- Stats Grid -->
                    <div class="club-stats-grid">
                        <div class="club-stat-box">
                            <div class="club-stat-number"><?= count($clubRiders) ?></div>
                            <div class="club-stat-label">Aktiva Cyklister</div>
                        </div>
                        <div class="club-stat-box">
                            <div class="club-stat-number"><?= array_sum(array_column($clubRiders, 'total_races')) ?></div>
                            <div class="club-stat-label">Totalt Lopp</div>
                        </div>
                        <div class="club-stat-box">
                            <div class="club-stat-number"><?= array_sum(array_column($clubRiders, 'total_points')) ?: 0 ?></div>
                            <div class="club-stat-label">Totala Poäng</div>
                        </div>
                        <div class="club-stat-box">
                            <div class="club-stat-number"><?= array_sum(array_column($clubRiders, 'wins')) ?></div>
                            <div class="club-stat-label">Segrar</div>
                        </div>
                    </div>

                    <!-- Contact Information -->
                    <?php if (!empty($club['email']) || !empty($club['phone']) || !empty($club['website']) || !empty($club['facebook']) || !empty($club['instagram'])): ?>
                    <div class="gs-mt-lg" style="background: #f8f9fa; padding: 1rem; border-radius: 8px;">
                        <div class="gs-flex gs-gap-md gs-flex-wrap" style="justify-content: center;">
                            <?php if (!empty($club['website'])): ?>
                            <a href="<?= h($club['website']) ?>" target="_blank" rel="noopener" class="gs-flex gs-items-center gs-gap-xs gs-link">
                                <i data-lucide="globe" class="gs-icon-sm"></i>
                                Webbplats
                            </a>
                            <?php endif; ?>

                            <?php if (!empty($club['email'])): ?>
                            <a href="mailto:<?= h($club['email']) ?>" class="gs-flex gs-items-center gs-gap-xs gs-link">
                                <i data-lucide="mail" class="gs-icon-sm"></i>
                                <?= h($club['email']) ?>
                            </a>
                            <?php endif; ?>

                            <?php if (!empty($club['phone'])): ?>
                            <a href="tel:<?= h($club['phone']) ?>" class="gs-flex gs-items-center gs-gap-xs gs-link">
                                <i data-lucide="phone" class="gs-icon-sm"></i>
                                <?= h($club['phone']) ?>
                            </a>
                            <?php endif; ?>

                            <?php if (!empty($club['facebook'])): ?>
                            <a href="<?= h($club['facebook']) ?>" target="_blank" rel="noopener" class="gs-flex gs-items-center gs-gap-xs gs-link">
                                <i data-lucide="facebook" class="gs-icon-sm"></i>
                                Facebook
                            </a>
                            <?php endif; ?>

                            <?php if (!empty($club['instagram'])): ?>
                            <a href="<?= h($club['instagram']) ?>" target="_blank" rel="noopener" class="gs-flex gs-items-center gs-gap-xs gs-link">
                                <i data-lucide="instagram" class="gs-icon-sm"></i>
                                Instagram
                            </a>
                            <?php endif; ?>
                        </div>

                        <?php if (!empty($club['contact_person'])): ?>
                        <div class="gs-text-center gs-mt-sm gs-text-sm gs-text-secondary">
                            Kontaktperson: <?= h($club['contact_person']) ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

                    <!-- Club Badge -->
                    <div class="club-badge gs-mt-lg">
                        <div class="club-badge-label">Registrerad Klubb</div>
                        <div class="club-badge-text">GravitySeries <?= $currentYear ?></div>
                    </div>
                </div>

                <!-- Footer -->
                <div class="club-license-footer">
                    TheHUB by GravitySeries • Klubbprofil <?= $currentYear ?>
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
                                    <div class="gs-badge gs-badge-primary gs-badge-sm gs-mb-2">
                                        <?= h($riderClass) ?>
                                    </div>
                                <?php endif; ?>

                                <?php if ($age !== null): ?>
                                    <div class="gs-text-sm gs-text-secondary gs-mb-2">
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
