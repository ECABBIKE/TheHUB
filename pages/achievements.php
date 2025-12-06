<?php
/**
 * TheHUB Achievements - Explanation Page
 * Shows all available badges with requirements and descriptions
 *
 * @version 2.0
 * @package TheHUB
 */

// Include required files
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/achievements.php';
require_once __DIR__ . '/../includes/achievements-club.php';

// Page setup
$pageTitle = 'Achievements';
$pageType = 'public';
$bodyClass = 'achievements-page-body';

// Include layout header
include __DIR__ . '/../includes/layout-header.php';

// Get achievement definitions
$riderAchievements = getRiderAchievementDefinitions();
$clubAchievements = getClubAchievementDefinitions();
?>

<main class="main-content">
    <link rel="stylesheet" href="/assets/css/achievements.css?v=<?= filemtime(__DIR__ . '/../assets/css/achievements.css') ?>">

    <div class="container">
        <div class="achievements-page">
            <!-- Page Header -->
            <div class="achievements-page-header">
                <h1 class="achievements-page-title">
                    <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align: middle; margin-right: 8px; color: var(--color-accent, #61CE70);">
                        <path d="M12 2L15.09 8.26L22 9.27L17 14.14L18.18 21.02L12 17.77L5.82 21.02L7 14.14L2 9.27L8.91 8.26L12 2Z"/>
                    </svg>
                    Achievements
                </h1>
                <p class="achievements-page-subtitle">Samla badges och visa dina prestationer i GravitySeries</p>
            </div>

            <!-- RIDER ACHIEVEMENTS SECTION -->
            <section id="rider" class="achievements-category">
                <div class="achievements-category-header">
                    <svg class="achievements-category-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="8" r="4"/>
                        <path d="M20 21a8 8 0 1 0-16 0"/>
                    </svg>
                    <h2 class="achievements-category-title">Rider Achievements</h2>
                    <span class="achievements-category-badge"><?= array_sum(array_map(fn($cat) => count($cat['badges']), $riderAchievements)) ?> badges</span>
                </div>

                <?php foreach ($riderAchievements as $categoryId => $category): ?>
                    <div class="achievements-subcategory" style="margin-bottom: var(--space-xl, 32px);">
                        <h3 style="font-size: 16px; font-weight: 600; margin-bottom: var(--space-md, 16px); color: var(--color-text-primary, #171717);">
                            <?= htmlspecialchars($category['title']) ?>
                        </h3>
                        <div class="achievements-list">
                            <?php foreach ($category['badges'] as $badge): ?>
                                <div class="achievement-explain-card<?= isset($badge['hidden']) && $badge['hidden'] ? ' locked' : '' ?>">
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
                                        <div class="achievement-explain-type">
                                            <?php if ($badge['has_counter'] ?? false): ?>
                                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                    <path d="M5 12h14M12 5l7 7-7 7"/>
                                                </svg>
                                                Med räknare
                                            <?php else: ?>
                                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                    <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
                                                    <polyline points="22 4 12 14.01 9 11.01"/>
                                                </svg>
                                                Engångs
                                            <?php endif; ?>
                                            <?php if (isset($badge['hidden']) && $badge['hidden']): ?>
                                                • <span style="color: var(--color-warning, #f59e0b);">Dold</span>
                                            <?php endif; ?>
                                        </div>
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
                    <h2 class="achievements-category-title">Klubb Achievements</h2>
                    <span class="achievements-category-badge"><?= array_sum(array_map(fn($cat) => count($cat['badges']), $clubAchievements)) ?> badges</span>
                </div>

                <?php foreach ($clubAchievements as $categoryId => $category): ?>
                    <div class="achievements-subcategory" style="margin-bottom: var(--space-xl, 32px);">
                        <h3 style="font-size: 16px; font-weight: 600; margin-bottom: var(--space-md, 16px); color: var(--color-text-primary, #171717);">
                            <?= htmlspecialchars($category['title']) ?>
                        </h3>
                        <div class="achievements-list">
                            <?php foreach ($category['badges'] as $badge): ?>
                                <div class="achievement-explain-card<?= isset($badge['hidden']) && $badge['hidden'] ? ' locked' : '' ?>">
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
                                        <div class="achievement-explain-type">
                                            <?php if ($badge['has_counter'] ?? false): ?>
                                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                    <path d="M5 12h14M12 5l7 7-7 7"/>
                                                </svg>
                                                Med räknare
                                            <?php elseif ($badge['has_levels'] ?? false): ?>
                                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                    <path d="M2 20h.01M7 20v-4M12 20v-8M17 20V8M22 4v16"/>
                                                </svg>
                                                Med nivåer
                                            <?php else: ?>
                                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                    <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
                                                    <polyline points="22 4 12 14.01 9 11.01"/>
                                                </svg>
                                                Engångs
                                            <?php endif; ?>
                                            <?php if (isset($badge['hidden']) && $badge['hidden']): ?>
                                                • <span style="color: var(--color-warning, #f59e0b);">Dold</span>
                                            <?php endif; ?>
                                            • <span style="opacity: 0.6;">Klubb</span>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </section>

            <!-- Info Section -->
            <section class="achievements-info" style="margin-top: var(--space-2xl, 48px); padding: var(--space-lg, 24px); background: var(--achievement-card-bg, #fff); border-radius: var(--radius-lg, 12px); border: 1px solid var(--achievement-border, #e0e0e0);">
                <h3 style="font-size: 16px; font-weight: 600; margin-bottom: var(--space-md, 16px); display: flex; align-items: center; gap: 8px;">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="10"/>
                        <path d="M12 16v-4M12 8h.01"/>
                    </svg>
                    Hur fungerar achievements?
                </h3>
                <div style="display: grid; gap: var(--space-md, 16px); color: var(--achievement-text-muted, #8a8a8a); font-size: 14px; line-height: 1.6;">
                    <p>
                        <strong style="color: var(--achievement-text-primary, #171717);">Automatisk tilldelning:</strong>
                        Badges tilldelas automatiskt när du uppfyller kraven. Du behöver inte göra något speciellt.
                    </p>
                    <p>
                        <strong style="color: var(--achievement-text-primary, #171717);">Räknare:</strong>
                        Badges markerade med "Med räknare" kan uppnås flera gånger. Räknaren visar totalt antal gånger.
                    </p>
                    <p>
                        <strong style="color: var(--achievement-text-primary, #171717);">Nivåer:</strong>
                        Vissa badges har nivåer (Brons, Silver, Guld, Diamant) baserat på prestationer.
                    </p>
                    <p>
                        <strong style="color: var(--achievement-text-primary, #171717);">Dolda badges:</strong>
                        Några speciella badges visas inte förrän de uppnåtts. De markeras med "Dold" ovan.
                    </p>
                    <p>
                        <strong style="color: var(--achievement-text-primary, #171717);">Experience-nivå:</strong>
                        Din erfarenhetsnivå ökar med antal säsonger och prestationer. Den 6:e nivån är dold!
                    </p>
                </div>
            </section>

        </div>
    </div>

<?php
// Include layout footer
include __DIR__ . '/../includes/layout-footer.php';
?>
