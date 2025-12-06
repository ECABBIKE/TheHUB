<?php
/**
 * TheHUB Achievements - Förklaringssida
 * Visar alla tillgängliga badges med krav och beskrivningar
 *
 * @version 2.1
 * @package TheHUB
 */

// Include achievements system
$achievementsPath = dirname(__DIR__) . '/includes/achievements.php';
if (file_exists($achievementsPath)) {
    require_once $achievementsPath;
}

$achievementsClubPath = dirname(__DIR__) . '/includes/achievements-club.php';
if (file_exists($achievementsClubPath)) {
    require_once $achievementsClubPath;
}

// Get achievement definitions
$riderAchievements = getRiderAchievementDefinitions();
$clubAchievements = getClubAchievementDefinitions();

// Count visible badges (exclude hidden)
function countVisibleBadges($categories) {
    $count = 0;
    foreach ($categories as $cat) {
        foreach ($cat['badges'] as $badge) {
            if (!isset($badge['hidden']) || !$badge['hidden']) {
                $count++;
            }
        }
    }
    return $count;
}
?>

<main class="main-content">
    <link rel="stylesheet" href="/assets/css/achievements.css?v=<?= filemtime(__DIR__ . '/../assets/css/achievements.css') ?>">

    <div class="container">
        <div class="achievements-page">
            <!-- Page Header -->
            <div class="achievements-page-header">
                <h1 class="achievements-page-title">
                    <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color: var(--color-accent, #61CE70);">
                        <path d="M12 2L15.09 8.26L22 9.27L17 14.14L18.18 21.02L12 17.77L5.82 21.02L7 14.14L2 9.27L8.91 8.26L12 2Z"/>
                    </svg>
                    Dina Achievements
                </h1>
                <p class="achievements-page-subtitle">Samla badges och fira dina prestationer i GravitySeries!</p>
            </div>

            <!-- RIDER ACHIEVEMENTS SECTION -->
            <section id="rider" class="achievements-category">
                <div class="achievements-category-header">
                    <svg class="achievements-category-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="8" r="4"/>
                        <path d="M20 21a8 8 0 1 0-16 0"/>
                    </svg>
                    <h2 class="achievements-category-title">Åkarbadges</h2>
                    <span class="achievements-category-badge"><?= countVisibleBadges($riderAchievements) ?> st</span>
                </div>

                <?php foreach ($riderAchievements as $categoryId => $category): ?>
                    <?php
                    // Filter out hidden badges
                    $visibleBadges = array_filter($category['badges'], fn($b) => !isset($b['hidden']) || !$b['hidden']);
                    if (empty($visibleBadges)) continue;
                    ?>
                    <div class="achievements-subcategory" style="margin-bottom: var(--space-xl, 32px);">
                        <h3 style="font-size: 14px; font-weight: 600; margin-bottom: var(--space-md, 16px); color: var(--achievement-text-primary, #171717);">
                            <?= htmlspecialchars($category['title']) ?>
                        </h3>
                        <div class="achievements-list">
                            <?php foreach ($visibleBadges as $badge): ?>
                                <div class="achievement-explain-card">
                                    <div class="achievement-explain-icon">
                                        <?php if (isset($badge['svg_function']) && function_exists($badge['svg_function'])): ?>
                                            <?= call_user_func($badge['svg_function']) ?>
                                        <?php else: ?>
                                            <div style="width: 48px; height: 48px; background: var(--achievement-badge-bg, #f0f2f5); border-radius: 8px; display: flex; align-items: center; justify-content: center;">
                                                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="<?= $badge['accent'] ?? '#61CE70' ?>" stroke-width="2">
                                                    <path d="M12 2L15.09 8.26L22 9.27L17 14.14L18.18 21.02L12 17.77L5.82 21.02L7 14.14L2 9.27L8.91 8.26L12 2Z"/>
                                                </svg>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="achievement-explain-content">
                                        <h4 class="achievement-explain-name"><?= htmlspecialchars($badge['name']) ?></h4>
                                        <p class="achievement-explain-requirement"><?= htmlspecialchars($badge['requirement']) ?></p>
                                        <p class="achievement-explain-description"><?= htmlspecialchars($badge['description']) ?></p>
                                        <?php if ($badge['has_counter'] ?? false): ?>
                                        <div class="achievement-explain-type">
                                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                <path d="M5 12h14M12 5l7 7-7 7"/>
                                            </svg>
                                            Kan samlas flera gånger
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </section>

            <!-- CLUB ACHIEVEMENTS SECTION -->
            <section id="club" class="achievements-category">
                <div class="achievements-category-header">
                    <svg class="achievements-category-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
                        <circle cx="9" cy="7" r="4"/>
                        <path d="M23 21v-2a4 4 0 0 0-3-3.87"/>
                        <path d="M16 3.13a4 4 0 0 1 0 7.75"/>
                    </svg>
                    <h2 class="achievements-category-title">Klubbbadges</h2>
                    <span class="achievements-category-badge"><?= countVisibleBadges($clubAchievements) ?> st</span>
                </div>

                <?php foreach ($clubAchievements as $categoryId => $category): ?>
                    <?php
                    // Filter out hidden badges
                    $visibleBadges = array_filter($category['badges'], fn($b) => !isset($b['hidden']) || !$b['hidden']);
                    if (empty($visibleBadges)) continue;
                    ?>
                    <div class="achievements-subcategory" style="margin-bottom: var(--space-xl, 32px);">
                        <h3 style="font-size: 14px; font-weight: 600; margin-bottom: var(--space-md, 16px); color: var(--achievement-text-primary, #171717);">
                            <?= htmlspecialchars($category['title']) ?>
                        </h3>
                        <div class="achievements-list">
                            <?php foreach ($visibleBadges as $badge): ?>
                                <div class="achievement-explain-card">
                                    <div class="achievement-explain-icon">
                                        <?php if (isset($badge['svg_function']) && function_exists($badge['svg_function'])): ?>
                                            <?= call_user_func($badge['svg_function']) ?>
                                        <?php else: ?>
                                            <div style="width: 48px; height: 48px; background: var(--achievement-badge-bg, #f0f2f5); border-radius: 8px; display: flex; align-items: center; justify-content: center;">
                                                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="<?= $badge['accent'] ?? '#61CE70' ?>" stroke-width="2">
                                                    <path d="M12 2L15.09 8.26L22 9.27L17 14.14L18.18 21.02L12 17.77L5.82 21.02L7 14.14L2 9.27L8.91 8.26L12 2Z"/>
                                                </svg>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="achievement-explain-content">
                                        <h4 class="achievement-explain-name"><?= htmlspecialchars($badge['name']) ?></h4>
                                        <p class="achievement-explain-requirement"><?= htmlspecialchars($badge['requirement']) ?></p>
                                        <p class="achievement-explain-description"><?= htmlspecialchars($badge['description']) ?></p>
                                        <?php if ($badge['has_counter'] ?? false): ?>
                                        <div class="achievement-explain-type">
                                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                <path d="M5 12h14M12 5l7 7-7 7"/>
                                            </svg>
                                            Kan samlas flera gånger
                                        </div>
                                        <?php elseif ($badge['has_levels'] ?? false): ?>
                                        <div class="achievement-explain-type">
                                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                <path d="M2 20h.01M7 20v-4M12 20v-8M17 20V8M22 4v16"/>
                                            </svg>
                                            Brons → Silver → Guld → Diamant
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </section>

            <!-- Info Section -->
            <section class="achievements-info" style="margin-top: var(--space-xl, 32px); padding: var(--space-md, 16px); background: var(--achievement-badge-bg, #f9f9f9); border-radius: var(--radius-md, 10px);">
                <h3 style="font-size: 14px; font-weight: 600; margin-bottom: var(--space-sm, 8px); display: flex; align-items: center; gap: 8px; color: var(--achievement-text-primary, #171717);">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="10"/>
                        <path d="M12 16v-4M12 8h.01"/>
                    </svg>
                    Så funkar det
                </h3>
                <div style="color: var(--achievement-text-muted, #7a7a7a); font-size: 13px; line-height: 1.5;">
                    <p style="margin: 0;">
                        Badges tilldelas automatiskt när du tävlar och uppfyller kraven.
                        Kolla din profil för att se vilka du har samlat!
                    </p>
                </div>
            </section>

        </div>
    </div>
</main>
