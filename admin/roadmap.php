<?php
/**
 * TheHUB Roadmap - Visual Project Overview
 * Displays project status and planned features
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
requireLogin();

// Read ROADMAP.md
$roadmapPath = __DIR__ . '/../ROADMAP.md';
$roadmapContent = file_exists($roadmapPath) ? file_get_contents($roadmapPath) : '';

// Parse project areas from table
$projectAreas = [];
if (preg_match('/\| Omrade \| Status \| Beskrivning \|[\s\S]*?\n((?:\|[^\n]+\n)+)/', $roadmapContent, $matches)) {
    $rows = explode("\n", trim($matches[1]));
    foreach ($rows as $row) {
        if (preg_match('/\|\s*([^|]+)\s*\|\s*([^|]+)\s*\|\s*([^|]+)\s*\|/', $row, $cols)) {
            $projectAreas[] = [
                'name' => trim($cols[1]),
                'status' => trim($cols[2]),
                'description' => trim($cols[3])
            ];
        }
    }
}

// Parse changelog entries (last 10)
$changelog = [];
if (preg_match_all('/### (\d{4}-\d{2}-\d{2}) \(([^)]+)\)\n([\s\S]*?)(?=\n### \d{4}|\n---|\Z)/', $roadmapContent, $matches, PREG_SET_ORDER)) {
    foreach (array_slice($matches, 0, 10) as $match) {
        $changelog[] = [
            'date' => $match[1],
            'title' => $match[2],
            'content' => trim($match[3])
        ];
    }
}

// Count completed and pending items
$completedCount = substr_count($roadmapContent, '[x]');
$pendingCount = substr_count($roadmapContent, '[ ]');

$page_title = 'Roadmap';
$breadcrumbs = [
    ['label' => 'System', 'url' => '/admin/tools.php'],
    ['label' => 'Roadmap']
];

include __DIR__ . '/components/unified-layout.php';
?>

<style>
.roadmap-stats {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    gap: var(--space-md);
    margin-bottom: var(--space-xl);
}
.roadmap-stat {
    background: var(--color-bg-surface);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-md);
    padding: var(--space-lg);
    text-align: center;
}
.roadmap-stat-value {
    font-size: 2rem;
    font-weight: 700;
    color: var(--color-accent);
}
.roadmap-stat-label {
    font-size: 0.875rem;
    color: var(--color-text-muted);
}
.roadmap-stat.completed .roadmap-stat-value { color: var(--color-success); }
.roadmap-stat.pending .roadmap-stat-value { color: var(--color-warning); }

.project-areas {
    display: grid;
    gap: var(--space-md);
    margin-bottom: var(--space-xl);
}
.project-area {
    background: var(--color-bg-surface);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-md);
    padding: var(--space-lg);
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.project-area-info h3 {
    margin: 0 0 var(--space-xs);
    font-size: 1.125rem;
    color: var(--color-text-primary);
}
.project-area-info p {
    margin: 0;
    color: var(--color-text-secondary);
    font-size: 0.875rem;
}
.status-badge {
    padding: var(--space-xs) var(--space-md);
    border-radius: var(--radius-full);
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
}
.status-badge.klar { background: var(--color-success); color: white; }
.status-badge.progress { background: var(--color-warning); color: #000; }
.status-badge.planned { background: var(--color-info); color: white; }

.changelog-section {
    margin-top: var(--space-xl);
}
.changelog-entry {
    background: var(--color-bg-surface);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-md);
    padding: var(--space-lg);
    margin-bottom: var(--space-md);
}
.changelog-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: var(--space-md);
}
.changelog-date {
    font-size: 0.875rem;
    color: var(--color-text-muted);
}
.changelog-title {
    font-weight: 600;
    color: var(--color-accent);
}
.changelog-content {
    font-size: 0.875rem;
    color: var(--color-text-secondary);
    line-height: 1.6;
}
.changelog-content ul {
    margin: var(--space-sm) 0;
    padding-left: var(--space-lg);
}

.quick-links {
    display: flex;
    gap: var(--space-sm);
    margin-bottom: var(--space-lg);
    flex-wrap: wrap;
}
</style>

<!-- Quick Links -->
<div class="quick-links">
    <a href="/admin/tools.php" class="btn-admin btn-admin-secondary">
        <i data-lucide="wrench"></i> Verktyg
    </a>
    <a href="/admin/migrations.php" class="btn-admin btn-admin-secondary">
        <i data-lucide="database"></i> Migrationer
    </a>
    <a href="https://github.com" target="_blank" class="btn-admin btn-admin-ghost">
        <i data-lucide="github"></i> GitHub
    </a>
</div>

<!-- Stats -->
<div class="roadmap-stats">
    <div class="roadmap-stat">
        <div class="roadmap-stat-value"><?= count($projectAreas) ?></div>
        <div class="roadmap-stat-label">Projektomraden</div>
    </div>
    <div class="roadmap-stat completed">
        <div class="roadmap-stat-value"><?= $completedCount ?></div>
        <div class="roadmap-stat-label">Avklarade uppgifter</div>
    </div>
    <div class="roadmap-stat pending">
        <div class="roadmap-stat-value"><?= $pendingCount ?></div>
        <div class="roadmap-stat-label">Kommande uppgifter</div>
    </div>
    <div class="roadmap-stat">
        <div class="roadmap-stat-value"><?= count($changelog) ?></div>
        <div class="roadmap-stat-label">Senaste uppdateringar</div>
    </div>
</div>

<!-- Project Areas -->
<div class="admin-card">
    <div class="admin-card-header">
        <h2><i data-lucide="layers"></i> Projektomraden</h2>
    </div>
    <div class="admin-card-body" style="padding:0;">
        <div class="project-areas" style="padding:var(--space-md);">
            <?php foreach ($projectAreas as $area): ?>
            <?php
                $statusLower = strtolower($area['status']);
                $statusClass = 'planned';
                if (strpos($statusLower, 'klar') !== false) {
                    $statusClass = 'klar';
                } elseif (strpos($statusLower, '%') !== false) {
                    $statusClass = 'progress';
                }
            ?>
            <div class="project-area">
                <div class="project-area-info">
                    <h3><?= htmlspecialchars($area['name']) ?></h3>
                    <p><?= htmlspecialchars($area['description']) ?></p>
                </div>
                <span class="status-badge <?= $statusClass ?>"><?= htmlspecialchars($area['status']) ?></span>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- Changelog -->
<div class="changelog-section">
    <div class="admin-card">
        <div class="admin-card-header">
            <h2><i data-lucide="history"></i> Senaste uppdateringar</h2>
        </div>
        <div class="admin-card-body" style="padding:0;">
            <?php if (empty($changelog)): ?>
            <p style="padding:var(--space-xl);text-align:center;color:var(--color-text-muted);">
                Ingen changelog hittad i ROADMAP.md
            </p>
            <?php else: ?>
            <?php foreach ($changelog as $entry): ?>
            <div class="changelog-entry" style="margin:var(--space-md);margin-bottom:0;">
                <div class="changelog-header">
                    <span class="changelog-title"><?= htmlspecialchars($entry['title']) ?></span>
                    <span class="changelog-date"><?= htmlspecialchars($entry['date']) ?></span>
                </div>
                <div class="changelog-content">
                    <?php
                    // Convert markdown bullet points to HTML
                    $content = htmlspecialchars($entry['content']);
                    $content = preg_replace('/^\s*-\s+\*\*([^*]+)\*\*/m', '<li><strong>$1</strong>', $content);
                    $content = preg_replace('/^\s*-\s+/m', '<li>', $content);
                    $content = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $content);
                    $content = preg_replace('/`([^`]+)`/', '<code>$1</code>', $content);
                    echo nl2br($content);
                    ?>
                </div>
            </div>
            <?php endforeach; ?>
            <div style="padding:var(--space-md);"></div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Raw Markdown Link -->
<div style="margin-top:var(--space-lg);text-align:center;">
    <p style="color:var(--color-text-muted);font-size:0.875rem;">
        Full dokumentation finns i <code>/ROADMAP.md</code>
    </p>
</div>

<?php include __DIR__ . '/components/unified-layout-footer.php'; ?>
