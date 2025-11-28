<?php
/**
 * Profil-sida / Mitt
 * Om ej inloggad → visa login
 * Om inloggad → visa profil-dashboard
 */

session_start();
require_once __DIR__ . '/includes/db.php';

$isLoggedIn = isset($_SESSION['rider_id']) && $_SESSION['rider_id'] > 0;
$tab = $_GET['tab'] ?? 'overview';

// Om ej inloggad, visa login
if (!$isLoggedIn) {
    $pageTitle = 'Logga in';
    $pageType = 'public';
    include __DIR__ . '/includes/layout-header.php';
    include __DIR__ . '/includes/header-modern.php';
    ?>

    <div class="login-page">
        <div class="login-container">
            <div class="login-card">

                <div class="login-header">
                    <div class="login-icon">👤</div>
                    <h1 class="login-title">Logga in</h1>
                    <p class="login-subtitle">Logga in för att se dina anmälningar och resultat</p>
                </div>

                <?php if (isset($_GET['error']) && $_GET['error'] === 'invalid'): ?>
                <div class="alert alert--error mb-lg">
                    Felaktiga inloggningsuppgifter.
                </div>
                <?php endif; ?>

                <form action="/rider-login.php" method="post" class="login-form">
                    <input type="hidden" name="redirect" value="/profile.php">

                    <div class="form-group">
                        <label for="email" class="form-label">E-post</label>
                        <input type="email" id="email" name="email" class="form-input"
                               placeholder="din@email.se" required autofocus>
                    </div>

                    <div class="form-group">
                        <label for="password" class="form-label">Lösenord</label>
                        <input type="password" id="password" name="password" class="form-input"
                               placeholder="••••••••" required>
                    </div>

                    <button type="submit" class="btn btn--primary btn--block btn--lg">
                        Logga in
                    </button>
                </form>

                <div class="login-footer">
                    <a href="/forgot-password.php" class="login-link">Glömt lösenord?</a>
                </div>

            </div>
        </div>
    </div>

    <?php
    include __DIR__ . '/includes/nav-bottom.php';
    include __DIR__ . '/includes/layout-footer.php';
    exit;
}

// Hämta användardata
if (!function_exists('get_current_rider')) {
    require_once __DIR__ . '/includes/rider-auth.php';
}
$user = get_current_rider();

$pdo = getDB()->getConnection();

// Hämta kommande anmälningar (placeholder - anpassa efter din databas)
$upcomingEvents = [];
if ($pdo) {
    try {
        $stmt = $pdo->prepare("
            SELECT e.*, reg.class_id
            FROM event_registrations reg
            JOIN events e ON reg.event_id = e.id
            WHERE reg.rider_id = ? AND e.date >= CURDATE()
            ORDER BY e.date ASC
            LIMIT 5
        ");
        $stmt->execute([$_SESSION['rider_id']]);
        $upcomingEvents = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        // Ignorera fel
    }
}

// Hämta senaste resultat (placeholder - anpassa efter din databas)
$recentResults = [];
if ($pdo) {
    try {
        $stmt = $pdo->prepare("
            SELECT e.name as event_name, e.date, r.position, r.time
            FROM results r
            JOIN events e ON r.event_id = e.id
            WHERE r.rider_id = ?
            ORDER BY e.date DESC
            LIMIT 5
        ");
        $stmt->execute([$_SESSION['rider_id']]);
        $recentResults = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        // Ignorera fel
    }
}

$pageTitle = 'Min profil';
$pageType = 'public';
include __DIR__ . '/includes/layout-header.php';
include __DIR__ . '/includes/header-modern.php';
?>

<div class="page-container">

    <!-- Profil-header -->
    <div class="profile-header">
        <div class="profile-avatar profile-avatar--lg">
            <?= strtoupper(substr($user['firstname'] ?? 'U', 0, 1)) ?>
        </div>
        <div class="profile-info">
            <h1 class="profile-name"><?= htmlspecialchars(($user['firstname'] ?? '') . ' ' . ($user['lastname'] ?? '')) ?></h1>
            <p class="profile-club"><?= htmlspecialchars($user['club'] ?? 'Ingen klubb') ?></p>
        </div>
    </div>

    <!-- Snabblänkar -->
    <nav class="profile-nav">
        <a href="/profile.php?tab=overview" class="profile-nav-item <?= $tab === 'overview' ? 'is-active' : '' ?>">
            <span class="profile-nav-icon">🏠</span>
            Översikt
        </a>
        <a href="/my-registrations.php" class="profile-nav-item">
            <span class="profile-nav-icon">📅</span>
            Anmälningar
        </a>
        <a href="/my-results.php" class="profile-nav-item">
            <span class="profile-nav-icon">🏆</span>
            Resultat
        </a>
        <a href="/profile.php?tab=settings" class="profile-nav-item <?= $tab === 'settings' ? 'is-active' : '' ?>">
            <span class="profile-nav-icon">⚙️</span>
            Inställningar
        </a>
    </nav>

    <?php if ($tab === 'settings'): ?>
        <!-- INSTÄLLNINGAR -->
        <div class="settings-page">

            <h2 class="section-title">⚙️ Inställningar</h2>

            <!-- Tema -->
            <section class="card mb-lg">
                <h3 class="card-title">Utseende</h3>

                <div class="setting-item">
                    <div class="setting-info">
                        <strong>Tema</strong>
                        <span class="text-secondary">Välj ljust, mörkt eller följ systemet</span>
                    </div>
                    <div class="theme-picker">
                        <button data-theme-set="light" class="theme-picker-btn" title="Ljust">
                            <span class="theme-picker-icon">☀️</span>
                            <span class="theme-picker-label">Ljust</span>
                        </button>
                        <button data-theme-set="auto" class="theme-picker-btn" title="Auto">
                            <span class="theme-picker-icon">🖥️</span>
                            <span class="theme-picker-label">Auto</span>
                        </button>
                        <button data-theme-set="dark" class="theme-picker-btn" title="Mörkt">
                            <span class="theme-picker-icon">🌙</span>
                            <span class="theme-picker-label">Mörkt</span>
                        </button>
                    </div>
                </div>
            </section>

            <!-- Profil-info -->
            <section class="card mb-lg">
                <h3 class="card-title">Profilinformation</h3>
                <p class="text-secondary">Kontakta en administratör för att uppdatera din profilinformation.</p>
            </section>

        </div>

    <?php else: ?>
        <!-- ÖVERSIKT -->

        <!-- Kommande tävlingar -->
        <?php if ($upcomingEvents): ?>
        <section class="card mb-lg">
            <div class="card-header">
                <h2>📅 Kommande tävlingar</h2>
                <a href="/my-registrations.php" class="btn btn--ghost btn--sm">Visa alla</a>
            </div>
            <div class="event-list">
                <?php foreach ($upcomingEvents as $event): ?>
                <a href="/event.php?id=<?= $event['id'] ?>" class="event-list-item">
                    <div class="event-date">
                        <span class="event-date-day"><?= date('j', strtotime($event['date'])) ?></span>
                        <span class="event-date-month"><?= date('M', strtotime($event['date'])) ?></span>
                    </div>
                    <div class="event-info">
                        <strong><?= htmlspecialchars($event['name']) ?></strong>
                        <span class="text-secondary"><?= date('Y-m-d', strtotime($event['date'])) ?></span>
                    </div>
                    <span class="event-arrow">→</span>
                </a>
                <?php endforeach; ?>
            </div>
        </section>
        <?php endif; ?>

        <!-- Senaste resultat -->
        <?php if ($recentResults): ?>
        <section class="card mb-lg">
            <div class="card-header">
                <h2>🏆 Senaste resultat</h2>
                <a href="/my-results.php" class="btn btn--ghost btn--sm">Visa alla</a>
            </div>
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Tävling</th>
                            <th class="text-center">Plac.</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentResults as $result): ?>
                        <tr>
                            <td>
                                <strong><?= htmlspecialchars($result['event_name']) ?></strong><br>
                                <span class="text-secondary text-sm"><?= date('Y-m-d', strtotime($result['date'])) ?></span>
                            </td>
                            <td class="text-center">
                                <?php if ($result['position'] <= 3): ?>
                                    <span class="badge badge--<?= ['gold','silver','bronze'][$result['position']-1] ?>">
                                        <?= $result['position'] ?>
                                    </span>
                                <?php else: ?>
                                    <?= $result['position'] ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
        <?php endif; ?>

        <!-- Om ingen data -->
        <?php if (empty($upcomingEvents) && empty($recentResults)): ?>
        <div class="card text-center py-lg">
            <p class="text-secondary">Välkommen! Du har inga kommande tävlingar eller resultat ännu.</p>
        </div>
        <?php endif; ?>

    <?php endif; ?>

    <!-- Admin-länk om admin -->
    <?php if ($isAdmin): ?>
    <section class="card mb-lg">
        <a href="/admin/" class="profile-admin-link">
            <span class="profile-admin-icon">⚙️</span>
            <div class="profile-admin-info">
                <strong>Admin-panel</strong>
                <span class="text-secondary">Hantera tävlingar, serier och användare</span>
            </div>
            <span class="profile-admin-arrow">→</span>
        </a>
    </section>
    <?php endif; ?>

    <!-- Logga ut -->
    <a href="/rider-logout.php" class="btn btn--secondary btn--block mb-lg">
        Logga ut
    </a>

</div>

<?php
include __DIR__ . '/includes/nav-bottom.php';
include __DIR__ . '/includes/layout-footer.php';
?>
