<?php
/**
 * Promotor Panel - Simplified interface for event promotors
 * Shows only their assigned events with registration/economy info
 * No admin menu - clean, focused interface
 */

require_once __DIR__ . '/../config.php';

// Require at least promotor role
if (!isLoggedIn()) {
    redirect('/admin/login.php');
}

if (!hasRole('promotor')) {
    $_SESSION['flash_message'] = 'Du har inte behörighet till denna sida';
    $_SESSION['flash_type'] = 'error';
    redirect('/');
}

// Admins should use the full admin panel
if (hasRole('admin')) {
    redirect('/admin/events.php');
}

$db = getDB();
$currentUser = getCurrentAdmin();
$userId = $currentUser['id'] ?? 0;

// Get promotor's events with optimized query (using JOINs instead of subqueries)
$events = [];
try {
    $events = $db->getAll("
        SELECT e.*,
               s.name as series_name,
               s.logo as series_logo,
               COALESCE(reg.registration_count, 0) as registration_count,
               COALESCE(reg.confirmed_count, 0) as confirmed_count,
               COALESCE(reg.pending_count, 0) as pending_count,
               COALESCE(ord.total_paid, 0) as total_paid,
               COALESCE(ord.total_pending, 0) as total_pending
        FROM events e
        LEFT JOIN series s ON e.series_id = s.id
        JOIN promotor_events pe ON pe.event_id = e.id
        LEFT JOIN (
            SELECT event_id,
                   COUNT(*) as registration_count,
                   SUM(CASE WHEN status = 'confirmed' THEN 1 ELSE 0 END) as confirmed_count,
                   SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_count
            FROM event_registrations
            GROUP BY event_id
        ) reg ON reg.event_id = e.id
        LEFT JOIN (
            SELECT event_id,
                   SUM(CASE WHEN payment_status = 'paid' THEN total_amount ELSE 0 END) as total_paid,
                   SUM(CASE WHEN payment_status = 'pending' THEN total_amount ELSE 0 END) as total_pending
            FROM orders
            GROUP BY event_id
        ) ord ON ord.event_id = e.id
        WHERE pe.user_id = ?
        ORDER BY e.date DESC
    ", [$userId]);
} catch (Exception $e) {
    error_log("Promotor events error: " . $e->getMessage());
}

$pageTitle = 'Mina Tävlingar';
?>
<!DOCTYPE html>
<html lang="sv" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h($pageTitle) ?> - TheHUB</title>
    <link rel="stylesheet" href="/assets/css/reset.css">
    <link rel="stylesheet" href="/assets/css/tokens.css">
    <link rel="stylesheet" href="/assets/css/theme.css">
    <link rel="stylesheet" href="/assets/css/components.css">
    <script src="https://unpkg.com/lucide@latest/dist/umd/lucide.min.js"></script>
    <style>
        .promotor-page {
            min-height: 100vh;
            background: var(--color-bg-page);
        }
        .promotor-header {
            background: var(--color-bg-surface);
            border-bottom: 1px solid var(--color-border);
            padding: var(--space-md) var(--space-lg);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .promotor-header h1 {
            font-size: 1.5rem;
            font-weight: 600;
            color: var(--color-text-primary);
            display: flex;
            align-items: center;
            gap: var(--space-sm);
        }
        .promotor-header h1 i {
            color: var(--color-accent);
        }
        /* Desktop Navigation - hidden on mobile */
        .promotor-desktop-nav {
            display: none;
        }
        @media (min-width: 769px) {
            .promotor-desktop-nav {
                display: flex;
                gap: var(--space-xs);
            }
            .desktop-nav-link {
                display: flex;
                align-items: center;
                gap: var(--space-xs);
                padding: var(--space-sm) var(--space-md);
                color: var(--color-text-secondary);
                text-decoration: none;
                font-size: var(--text-sm);
                font-weight: 500;
                border-radius: var(--radius-md);
                transition: all 0.15s ease;
            }
            .desktop-nav-link:hover {
                background: var(--color-bg-hover);
                color: var(--color-text-primary);
            }
            .desktop-nav-link.active {
                background: var(--color-accent);
                color: white;
            }
            .desktop-nav-link i {
                width: 18px;
                height: 18px;
            }
        }
        .promotor-content {
            max-width: 1200px;
            margin: 0 auto;
            padding: var(--space-lg);
        }
        .back-link {
            display: inline-flex;
            align-items: center;
            gap: var(--space-xs);
            color: var(--color-text-secondary);
            text-decoration: none;
            font-size: var(--text-sm);
        }
        .back-link:hover {
            color: var(--color-text-primary);
        }
        .event-grid {
            display: grid;
            gap: var(--space-lg);
        }
        .event-card {
            background: var(--color-bg-surface);
            border-radius: var(--radius-lg);
            border: 1px solid var(--color-border);
            overflow: hidden;
        }
        .event-card-header {
            padding: var(--space-lg);
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: var(--space-md);
            border-bottom: 1px solid var(--color-border);
        }
        .event-info {
            flex: 1;
        }
        .event-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--color-text-primary);
            margin-bottom: var(--space-xs);
        }
        .event-meta {
            display: flex;
            flex-wrap: wrap;
            gap: var(--space-md);
            font-size: var(--text-sm);
            color: var(--color-text-secondary);
        }
        .event-meta-item {
            display: inline-flex;
            align-items: center;
            gap: var(--space-xs);
        }
        .event-meta-item i {
            width: 16px;
            height: 16px;
        }
        .event-series {
            display: inline-flex;
            align-items: center;
            gap: var(--space-xs);
            padding: var(--space-xs) var(--space-sm);
            background: var(--color-bg-sunken);
            border-radius: var(--radius-full);
            font-size: var(--text-xs);
            font-weight: 500;
        }
        .event-series img {
            width: 20px;
            height: 20px;
            object-fit: contain;
        }
        .event-card-body {
            padding: var(--space-lg);
        }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
            gap: var(--space-md);
            margin-bottom: var(--space-lg);
        }
        .stat-box {
            text-align: center;
            padding: var(--space-md);
            background: var(--color-bg-sunken);
            border-radius: var(--radius-md);
        }
        .stat-value {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--color-text-primary);
        }
        .stat-value.pending {
            color: var(--color-warning);
        }
        .stat-value.success {
            color: var(--color-success);
        }
        .stat-label {
            font-size: var(--text-xs);
            color: var(--color-text-secondary);
            margin-top: var(--space-xs);
        }
        .event-actions {
            display: flex;
            flex-wrap: wrap;
            gap: var(--space-sm);
        }
        .btn {
            display: inline-flex;
            align-items: center;
            gap: var(--space-xs);
            padding: var(--space-sm) var(--space-md);
            border-radius: var(--radius-md);
            font-size: var(--text-sm);
            font-weight: 500;
            text-decoration: none;
            cursor: pointer;
            border: none;
            transition: all 0.15s ease;
        }
        .btn i {
            width: 16px;
            height: 16px;
        }
        .btn-primary {
            background: var(--color-accent);
            color: white;
        }
        .btn-primary:hover {
            opacity: 0.9;
        }
        .btn-secondary {
            background: var(--color-bg-surface);
            color: var(--color-text-primary);
            border: 1px solid var(--color-border);
        }
        .btn-secondary:hover {
            background: var(--color-bg-hover);
        }
        .empty-state {
            text-align: center;
            padding: var(--space-2xl);
            color: var(--color-text-secondary);
        }
        .empty-state i {
            width: 48px;
            height: 48px;
            margin-bottom: var(--space-md);
            opacity: 0.5;
        }
        .empty-state h2 {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--color-text-primary);
            margin-bottom: var(--space-sm);
        }
        @media (max-width: 640px) {
            .promotor-content {
                padding: var(--space-md);
                padding-bottom: calc(var(--space-md) + 80px);
            }
            .event-card-header {
                flex-direction: column;
            }
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }
        /* Bottom Navigation - Mobile only */
        .promotor-bottom-nav {
            display: none; /* Hidden on desktop */
        }
        @media (max-width: 768px) {
            .promotor-bottom-nav {
                display: flex;
                position: fixed;
                bottom: 0;
                left: 0;
                right: 0;
                height: 64px;
                padding-bottom: env(safe-area-inset-bottom);
                background: var(--color-bg-surface);
                border-top: 1px solid var(--color-border);
                z-index: 100;
                justify-content: center;
                align-items: center;
            }
        }
        .promotor-bottom-nav-inner {
            display: flex;
            justify-content: space-around;
            align-items: center;
            width: 100%;
            max-width: 400px;
            height: 100%;
        }
        .promotor-nav-link {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 4px;
            padding: var(--space-xs) var(--space-md);
            color: var(--color-text-secondary);
            font-size: var(--text-xs);
            font-weight: 500;
            text-decoration: none;
            min-width: 72px;
            transition: color 0.15s ease;
        }
        .promotor-nav-link:hover {
            color: var(--color-text-primary);
        }
        .promotor-nav-link.active {
            color: var(--color-accent);
        }
        .promotor-nav-link i {
            width: 24px;
            height: 24px;
        }
    </style>
</head>
<body class="promotor-page">

<header class="promotor-header">
    <h1>
        <i data-lucide="calendar-check"></i>
        <?= h($pageTitle) ?>
    </h1>
    <!-- Desktop Navigation -->
    <nav class="promotor-desktop-nav">
        <a href="/admin/promotor.php" class="desktop-nav-link active">
            <i data-lucide="calendar-check"></i>
            Events
        </a>
        <a href="/admin/sponsors.php" class="desktop-nav-link">
            <i data-lucide="heart-handshake"></i>
            Sponsorer
        </a>
        <a href="/admin/media.php" class="desktop-nav-link">
            <i data-lucide="image"></i>
            Media
        </a>
        <a href="/" class="desktop-nav-link">
            <i data-lucide="home"></i>
            Hem
        </a>
    </nav>
</header>

<main class="promotor-content">
    <?php if (empty($events)): ?>
    <div class="event-card">
        <div class="empty-state">
            <i data-lucide="calendar-x"></i>
            <h2>Inga tävlingar</h2>
            <p>Du har inga tävlingar tilldelade ännu. Kontakta administratören för att få tillgång.</p>
        </div>
    </div>
    <?php else: ?>
    <div class="event-grid">
        <?php foreach ($events as $event): ?>
        <div class="event-card">
            <div class="event-card-header">
                <div class="event-info">
                    <h2 class="event-title"><?= h($event['name']) ?></h2>
                    <div class="event-meta">
                        <span class="event-meta-item">
                            <i data-lucide="calendar"></i>
                            <?= date('j M Y', strtotime($event['date'])) ?>
                        </span>
                        <?php if ($event['location']): ?>
                        <span class="event-meta-item">
                            <i data-lucide="map-pin"></i>
                            <?= h($event['location']) ?>
                        </span>
                        <?php endif; ?>
                    </div>
                </div>
                <?php if ($event['series_name']): ?>
                <span class="event-series">
                    <?php if ($event['series_logo']): ?>
                    <img src="<?= h($event['series_logo']) ?>" alt="">
                    <?php endif; ?>
                    <?= h($event['series_name']) ?>
                </span>
                <?php endif; ?>
            </div>

            <div class="event-card-body">
                <div class="stats-grid">
                    <div class="stat-box">
                        <div class="stat-value"><?= (int)$event['registration_count'] ?></div>
                        <div class="stat-label">Anmälda</div>
                    </div>
                    <div class="stat-box">
                        <div class="stat-value success"><?= (int)$event['confirmed_count'] ?></div>
                        <div class="stat-label">Bekräftade</div>
                    </div>
                    <div class="stat-box">
                        <div class="stat-value pending"><?= (int)$event['pending_count'] ?></div>
                        <div class="stat-label">Väntande</div>
                    </div>
                    <div class="stat-box">
                        <div class="stat-value"><?= number_format($event['total_paid'], 0, ',', ' ') ?> kr</div>
                        <div class="stat-label">Betalat</div>
                    </div>
                </div>

                <div class="event-actions">
                    <a href="/admin/event-edit.php?id=<?= $event['id'] ?>" class="btn btn-primary">
                        <i data-lucide="pencil"></i>
                        Redigera event
                    </a>
                    <a href="/admin/promotor-registrations.php?event_id=<?= $event['id'] ?>" class="btn btn-secondary">
                        <i data-lucide="users"></i>
                        Anmälningar
                    </a>
                    <a href="/admin/promotor-payments.php?event_id=<?= $event['id'] ?>" class="btn btn-secondary">
                        <i data-lucide="credit-card"></i>
                        Betalningar
                    </a>
                    <a href="/organizer/register.php?event=<?= $event['id'] ?>" class="btn btn-secondary">
                        <i data-lucide="user-plus"></i>
                        Platsreg
                    </a>
                    <a href="/event/<?= $event['id'] ?>" class="btn btn-secondary" target="_blank">
                        <i data-lucide="external-link"></i>
                        Visa
                    </a>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</main>

<!-- Bottom Navigation -->
<nav class="promotor-bottom-nav">
    <div class="promotor-bottom-nav-inner">
        <a href="/admin/promotor.php" class="promotor-nav-link active">
            <i data-lucide="calendar-check"></i>
            <span>Events</span>
        </a>
        <a href="/admin/sponsors.php" class="promotor-nav-link">
            <i data-lucide="heart-handshake"></i>
            <span>Sponsorer</span>
        </a>
        <a href="/admin/media.php" class="promotor-nav-link">
            <i data-lucide="image"></i>
            <span>Media</span>
        </a>
        <a href="/" class="promotor-nav-link">
            <i data-lucide="home"></i>
            <span>Hem</span>
        </a>
    </div>
</nav>

<script>
    lucide.createIcons();
</script>
</body>
</html>
