<?php
/**
 * Fix Results Club IDs Tool
 *
 * This tool updates results.club_id based on rider_club_seasons data.
 * It ensures that points are attributed to the correct club for each event
 * based on which club the rider was a member of at the time of the event.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();

$pageTitle = 'Fixa resultat klubb-ID';
include __DIR__ . '/../includes/admin-header.php';

$db = hub_db();
$message = '';
$messageType = '';
$dryRun = !isset($_POST['execute']);
$stats = [];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Get all results that have NULL club_id or potentially wrong club_id
        // Join with events to get the event year
        $resultsQuery = $db->prepare("
            SELECT
                r.id as result_id,
                r.cyclist_id as rider_id,
                r.club_id as current_result_club_id,
                r.event_id,
                YEAR(e.date) as event_year,
                e.name as event_name,
                e.date as event_date,
                rd.firstname,
                rd.lastname,
                rd.club_id as rider_current_club_id
            FROM results r
            JOIN events e ON r.event_id = e.id
            JOIN riders rd ON r.cyclist_id = rd.id
            WHERE r.status = 'finished'
            ORDER BY e.date DESC, r.id
        ");
        $resultsQuery->execute();
        $allResults = $resultsQuery->fetchAll(PDO::FETCH_ASSOC);

        $stats = [
            'total_results' => count($allResults),
            'checked' => 0,
            'fixed' => 0,
            'already_correct' => 0,
            'no_season_data' => 0,
            'details' => []
        ];

        foreach ($allResults as $result) {
            $stats['checked']++;

            $riderId = $result['rider_id'];
            $eventYear = $result['event_year'];
            $currentClubId = $result['current_result_club_id'];

            // Find what club the rider was in for this event's year
            $seasonQuery = $db->prepare("
                SELECT club_id, c.name as club_name
                FROM rider_club_seasons rcs
                JOIN clubs c ON rcs.club_id = c.id
                WHERE rcs.rider_id = ? AND rcs.season_year = ?
                LIMIT 1
            ");
            $seasonQuery->execute([$riderId, $eventYear]);
            $seasonData = $seasonQuery->fetch(PDO::FETCH_ASSOC);

            if (!$seasonData) {
                $stats['no_season_data']++;
                continue;
            }

            $correctClubId = $seasonData['club_id'];

            if ($currentClubId == $correctClubId) {
                $stats['already_correct']++;
                continue;
            }

            // Need to fix this result
            $stats['fixed']++;

            // Get club names for the log
            $oldClubName = 'NULL';
            if ($currentClubId) {
                $oldClubQuery = $db->prepare("SELECT name FROM clubs WHERE id = ?");
                $oldClubQuery->execute([$currentClubId]);
                $oldClub = $oldClubQuery->fetch(PDO::FETCH_ASSOC);
                $oldClubName = $oldClub['name'] ?? 'Okänd';
            }

            $stats['details'][] = [
                'rider' => $result['firstname'] . ' ' . $result['lastname'],
                'rider_id' => $riderId,
                'event' => $result['event_name'],
                'event_date' => $result['event_date'],
                'old_club' => $oldClubName,
                'new_club' => $seasonData['club_name'],
                'result_id' => $result['result_id']
            ];

            // Update the result if not dry run
            if (!$dryRun) {
                $updateQuery = $db->prepare("
                    UPDATE results SET club_id = ? WHERE id = ?
                ");
                $updateQuery->execute([$correctClubId, $result['result_id']]);
            }
        }

        if ($dryRun) {
            $message = "Simulering klar. {$stats['fixed']} resultat skulle korrigeras.";
            $messageType = 'info';
        } else {
            $message = "Klart! {$stats['fixed']} resultat har uppdaterats.";
            $messageType = 'success';
        }

    } catch (Exception $e) {
        $message = 'Fel: ' . $e->getMessage();
        $messageType = 'danger';
    }
}
?>

<div class="admin-content">
    <div class="page-header">
        <h1><?= $pageTitle ?></h1>
        <p class="text-muted">Uppdaterar results.club_id baserat på rider_club_seasons för att säkerställa att poäng tillskrivs rätt klubb.</p>
    </div>

    <?php if ($message): ?>
    <div class="alert alert-<?= $messageType ?>">
        <?= htmlspecialchars($message) ?>
    </div>
    <?php endif; ?>

    <div class="card">
        <div class="card-header">
            <h3>Hur det fungerar</h3>
        </div>
        <div class="card-body">
            <p>Detta verktyg går igenom alla resultat och kontrollerar:</p>
            <ol>
                <li>Vilken säsong (år) tävlingen ägde rum</li>
                <li>Vilken klubb åkaren var medlem i den säsongen (från rider_club_seasons)</li>
                <li>Om results.club_id skiljer sig från säsongsklubben, uppdateras det</li>
            </ol>
            <p><strong>Viktigt:</strong> Efter uppdatering behöver klubbrankingen beräknas om.</p>
        </div>
    </div>

    <div class="card mt-lg">
        <div class="card-header">
            <h3>Kör verktyget</h3>
        </div>
        <div class="card-body">
            <form method="post">
                <div class="form-group">
                    <button type="submit" class="btn btn-secondary" name="simulate">
                        <i data-lucide="search"></i>
                        Simulera (visa vad som skulle ändras)
                    </button>
                    <button type="submit" class="btn btn-primary" name="execute" onclick="return confirm('Är du säker? Detta kommer uppdatera databasen.')">
                        <i data-lucide="zap"></i>
                        Kör på riktigt
                    </button>
                </div>
            </form>
        </div>
    </div>

    <?php if (!empty($stats)): ?>
    <div class="card mt-lg">
        <div class="card-header">
            <h3>Resultat</h3>
        </div>
        <div class="card-body">
            <div class="stats-grid" style="grid-template-columns: repeat(4, 1fr); margin-bottom: var(--space-lg);">
                <div class="stat-card">
                    <div class="stat-value"><?= number_format($stats['total_results']) ?></div>
                    <div class="stat-label">Totalt resultat</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value"><?= number_format($stats['already_correct']) ?></div>
                    <div class="stat-label">Redan korrekta</div>
                </div>
                <div class="stat-card stat-card--accent">
                    <div class="stat-value"><?= number_format($stats['fixed']) ?></div>
                    <div class="stat-label"><?= $dryRun ? 'Skulle korrigeras' : 'Korrigerade' ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-value"><?= number_format($stats['no_season_data']) ?></div>
                    <div class="stat-label">Saknar säsongsdata</div>
                </div>
            </div>

            <?php if (!empty($stats['details'])): ?>
            <h4>Detaljerade ändringar (visar max 100)</h4>
            <div class="table-responsive">
                <table class="table table--striped">
                    <thead>
                        <tr>
                            <th>Åkare</th>
                            <th>Tävling</th>
                            <th>Datum</th>
                            <th>Från klubb</th>
                            <th>Till klubb</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach (array_slice($stats['details'], 0, 100) as $detail): ?>
                        <tr>
                            <td>
                                <a href="/rider/<?= $detail['rider_id'] ?>">
                                    <?= htmlspecialchars($detail['rider']) ?>
                                </a>
                            </td>
                            <td><?= htmlspecialchars($detail['event']) ?></td>
                            <td><?= $detail['event_date'] ?></td>
                            <td><?= htmlspecialchars($detail['old_club']) ?></td>
                            <td><strong><?= htmlspecialchars($detail['new_club']) ?></strong></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if (count($stats['details']) > 100): ?>
            <p class="text-muted">...och <?= count($stats['details']) - 100 ?> fler</p>
            <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

</div>

<?php include __DIR__ . '/../includes/admin-footer.php'; ?>
