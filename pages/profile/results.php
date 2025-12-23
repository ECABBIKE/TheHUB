<?php
/**
 * TheHUB V3.5 - My Results
 */

$currentUser = hub_current_user();
if (!$currentUser) {
    header('Location: /profile/login');
    exit;
}

$pdo = hub_db();

// Get results
$stmt = $pdo->prepare("
    SELECT res.*, e.name as event_name, e.date as event_date,
           cls.display_name as class_name, s.name as series_name
    FROM results res
    JOIN events e ON res.event_id = e.id
    LEFT JOIN classes cls ON res.class_id = cls.id
    LEFT JOIN series s ON e.series_id = s.id
    WHERE res.cyclist_id = ?
    ORDER BY e.date DESC
");
$stmt->execute([$currentUser['id']]);
$results = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate stats
$totalEvents = count($results);
$podiums = count(array_filter($results, fn($r) => $r['position'] <= 3));
$wins = count(array_filter($results, fn($r) => $r['position'] === 1));
$totalPoints = array_sum(array_column($results, 'points'));
?>

<div class="page-header">
    <nav class="breadcrumb">
        <a href="/profile">Min Sida</a>
        <span class="breadcrumb-sep">›</span>
        <span>Mina resultat</span>
    </nav>
    <h1 class="page-title">
        <i data-lucide="flag" class="page-icon"></i>
        Mina resultat
    </h1>
</div>

<!-- Stats -->
<div class="stats-grid">
    <div class="stat-card">
        <span class="stat-value"><?= $totalEvents ?></span>
        <span class="stat-label">Tävlingar</span>
    </div>
    <div class="stat-card">
        <span class="stat-value"><?= $wins ?></span>
        <span class="stat-label">Segrar</span>
    </div>
    <div class="stat-card">
        <span class="stat-value"><?= $podiums ?></span>
        <span class="stat-label">Pallplatser</span>
    </div>
    <div class="stat-card">
        <span class="stat-value"><?= number_format($totalPoints) ?></span>
        <span class="stat-label">Poäng</span>
    </div>
</div>

<!-- Results List -->
<?php if (empty($results)): ?>
    <div class="empty-state">
        <div class="empty-icon"><i data-lucide="flag" class="icon-xl"></i></div>
        <h3>Inga resultat ännu</h3>
        <p>Dina tävlingsresultat kommer visas här efter att du tävlat.</p>
    </div>
<?php else: ?>
    <div class="results-list">
        <?php
        $currentYear = null;
        foreach ($results as $result):
            $year = date('Y', strtotime($result['event_date']));
            if ($year !== $currentYear):
                $currentYear = $year;
        ?>
            <h2 class="year-header"><?= $year ?></h2>
        <?php endif; ?>

            <a href="/results/<?= $result['event_id'] ?>" class="result-card">
                <div class="result-position">
                    <?php if ($result['position'] == 1): ?>
                        <img src="/assets/icons/medal-1st.svg" alt="1:a" class="medal-icon">
                    <?php elseif ($result['position'] == 2): ?>
                        <img src="/assets/icons/medal-2nd.svg" alt="2:a" class="medal-icon">
                    <?php elseif ($result['position'] == 3): ?>
                        <img src="/assets/icons/medal-3rd.svg" alt="3:e" class="medal-icon">
                    <?php else: ?>
                        <span class="position-number">#<?= $result['position'] ?></span>
                    <?php endif; ?>
                </div>
                <div class="result-info">
                    <span class="result-event"><?= htmlspecialchars($result['event_name']) ?></span>
                    <span class="result-meta">
                        <?= date('j M', strtotime($result['event_date'])) ?>
                        <?php if ($result['class_name']): ?>
                            • <?= htmlspecialchars($result['class_name']) ?>
                        <?php endif; ?>
                    </span>
                </div>
                <div class="result-points">
                    <?php if ($result['points']): ?>
                        +<?= $result['points'] ?>p
                    <?php endif; ?>
                </div>
            </a>
        <?php endforeach; ?>
    </div>
<?php endif; ?>


<!-- CSS loaded from /assets/css/pages/profile-results.css -->
