<?php
/**
 * TheHUB Home Page Module
 * Content-only - layout handled by app.php
 */

$db = getDB();

// Get active series
$activeSeries = $db->getAll("
    SELECT id, name, logo, year
    FROM series
    WHERE status = 'active'
    ORDER BY year DESC, name ASC
    LIMIT 6
");

// Get upcoming events
$upcomingEvents = $db->getAll("
    SELECT e.id, e.name, e.date, e.location, s.name as series_name, s.logo as series_logo
    FROM events e
    LEFT JOIN series s ON e.series_id = s.id
    WHERE e.date >= CURDATE() AND e.status = 'active'
    ORDER BY e.date ASC
    LIMIT 5
");

// Get recent results
$recentResults = $db->getAll("
    SELECT e.id, e.name, e.date, s.name as series_name,
           COUNT(DISTINCT r.id) as result_count
    FROM events e
    LEFT JOIN series s ON e.series_id = s.id
    INNER JOIN results r ON e.id = r.event_id
    WHERE e.date <= CURDATE()
    GROUP BY e.id
    ORDER BY e.date DESC
    LIMIT 5
");
?>

<div class="container">
    <!-- Hero Section -->
    <div class="hero text-center mb-xl">
        <img src="http://gravityseries.se/wp-content/uploads/2024/03/Gravity-Series-White.png"
             alt="Gravity Series"
             style="width: 100%; max-width: 500px; margin-bottom: 1rem;">
        <h1 class="hero-title">TheHUB</h1>
        <p class="text-secondary text-lg">
            Det centrala navet for svensk cykeltatling
        </p>
    </div>

    <!-- Quick Links Grid -->
    <div class="grid grid-cols-2 md-grid-cols-4 gap-md mb-xl">
        <a href="/calendar" class="card card-hover text-center p-md">
            <i data-lucide="calendar" class="gs-icon-48 text-accent mb-sm"></i>
            <div class="font-semibold">Kalender</div>
        </a>
        <a href="/results" class="card card-hover text-center p-md">
            <i data-lucide="trophy" class="gs-icon-48 text-accent mb-sm"></i>
            <div class="font-semibold">Resultat</div>
        </a>
        <a href="/series" class="card card-hover text-center p-md">
            <i data-lucide="award" class="gs-icon-48 text-accent mb-sm"></i>
            <div class="font-semibold">Serier</div>
        </a>
        <a href="/ranking" class="card card-hover text-center p-md">
            <i data-lucide="trending-up" class="gs-icon-48 text-accent mb-sm"></i>
            <div class="font-semibold">Ranking</div>
        </a>
    </div>

    <div class="grid grid-cols-1 md-grid-cols-2 gap-lg">
        <!-- Upcoming Events -->
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">
                    <i data-lucide="calendar"></i>
                    Kommande tavlingar
                </h2>
            </div>
            <div class="card-body">
                <?php if (empty($upcomingEvents)): ?>
                <p class="text-secondary">Inga kommande tavlingar</p>
                <?php else: ?>
                <div class="space-y-sm">
                    <?php foreach ($upcomingEvents as $event): ?>
                    <a href="/event/<?= $event['id'] ?>" class="flex items-center gap-sm p-sm rounded hover-bg">
                        <div class="text-center" style="min-width: 50px;">
                            <div class="text-lg font-bold text-accent"><?= date('d', strtotime($event['date'])) ?></div>
                            <div class="text-xs text-muted uppercase"><?= date('M', strtotime($event['date'])) ?></div>
                        </div>
                        <div class="flex-1">
                            <div class="font-semibold"><?= h($event['name']) ?></div>
                            <?php if ($event['location']): ?>
                            <div class="text-sm text-secondary"><?= h($event['location']) ?></div>
                            <?php endif; ?>
                        </div>
                    </a>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Recent Results -->
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">
                    <i data-lucide="trophy"></i>
                    Senaste resultat
                </h2>
            </div>
            <div class="card-body">
                <?php if (empty($recentResults)): ?>
                <p class="text-secondary">Inga resultat annu</p>
                <?php else: ?>
                <div class="space-y-sm">
                    <?php foreach ($recentResults as $event): ?>
                    <a href="/results/<?= $event['id'] ?>" class="flex items-center gap-sm p-sm rounded hover-bg">
                        <div class="text-center" style="min-width: 50px;">
                            <div class="text-lg font-bold"><?= date('d', strtotime($event['date'])) ?></div>
                            <div class="text-xs text-muted uppercase"><?= date('M', strtotime($event['date'])) ?></div>
                        </div>
                        <div class="flex-1">
                            <div class="font-semibold"><?= h($event['name']) ?></div>
                            <div class="text-sm text-secondary">
                                <?= $event['result_count'] ?> deltagare
                            </div>
                        </div>
                    </a>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Active Series -->
    <?php if (!empty($activeSeries)): ?>
    <div class="card mt-lg">
        <div class="card-header">
            <h2 class="card-title">
                <i data-lucide="award"></i>
                Aktiva serier
            </h2>
        </div>
        <div class="card-body">
            <div class="grid grid-cols-2 md-grid-cols-3 gap-md">
                <?php foreach ($activeSeries as $series): ?>
                <a href="/series/<?= $series['id'] ?>" class="card card-hover p-md text-center">
                    <?php if ($series['logo']): ?>
                    <img src="<?= h($series['logo']) ?>" alt="<?= h($series['name']) ?>" style="max-height: 60px; margin: 0 auto 0.5rem;">
                    <?php endif; ?>
                    <div class="font-semibold"><?= h($series['name']) ?></div>
                    <?php if ($series['year']): ?>
                    <div class="text-sm text-secondary"><?= $series['year'] ?></div>
                    <?php endif; ?>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>
