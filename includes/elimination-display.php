<?php
/**
 * Elimination Display Component
 * Include this file to display elimination brackets and qualifying results
 *
 * Required variables:
 * - $db: Database connection
 * - $eventId: Event ID
 * - $selectedClassId: (optional) Class to display, defaults to first class with data
 */

if (!isset($eventId)) {
    return;
}

// Use PDO from global scope (set by config.php)
$elimPdo = $GLOBALS['pdo'] ?? null;
if (!$elimPdo) {
    return;
}

// Check if elimination tables exist
$eliminationTablesExist = false;
try {
    $elimPdo->query("SELECT 1 FROM elimination_qualifying LIMIT 1");
    $eliminationTablesExist = true;
} catch (PDOException $e) {
    $eliminationTablesExist = false;
}

if (!$eliminationTablesExist) {
    return;
}

// Get classes with elimination data
$stmt = $elimPdo->prepare("
    SELECT c.id, c.name, c.display_name,
        COUNT(DISTINCT eq.id) as qual_count,
        COUNT(DISTINCT eb.id) as bracket_count
    FROM classes c
    LEFT JOIN elimination_qualifying eq ON c.id = eq.class_id AND eq.event_id = ?
    LEFT JOIN elimination_brackets eb ON c.id = eb.class_id AND eb.event_id = ?
    WHERE eq.id IS NOT NULL OR eb.id IS NOT NULL
    GROUP BY c.id, c.name, c.display_name, c.sort_order
    ORDER BY c.sort_order, c.name
");
$stmt->execute([$eventId, $eventId]);
$eliminationClasses = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($eliminationClasses)) {
    return;
}

// Get selected class (from URL or default to first)
$elimClassId = isset($_GET['elim_class']) ? intval($_GET['elim_class']) : $eliminationClasses[0]['id'];

// Get qualifying results
$stmt = $elimPdo->prepare("
    SELECT eq.*, r.firstname, r.lastname, cl.name as club_name
    FROM elimination_qualifying eq
    JOIN riders r ON eq.rider_id = r.id
    LEFT JOIN clubs cl ON r.club_id = cl.id
    WHERE eq.event_id = ? AND eq.class_id = ?
    ORDER BY eq.seed_position ASC, eq.best_time ASC
");
$stmt->execute([$eventId, $elimClassId]);
$qualifyingResults = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get bracket data grouped by round
$stmt = $elimPdo->prepare("
    SELECT eb.*,
        r1.firstname as rider1_firstname, r1.lastname as rider1_lastname,
        cl1.name as rider1_club,
        r2.firstname as rider2_firstname, r2.lastname as rider2_lastname,
        cl2.name as rider2_club,
        w.firstname as winner_firstname, w.lastname as winner_lastname
    FROM elimination_brackets eb
    LEFT JOIN riders r1 ON eb.rider_1_id = r1.id
    LEFT JOIN clubs cl1 ON r1.club_id = cl1.id
    LEFT JOIN riders r2 ON eb.rider_2_id = r2.id
    LEFT JOIN clubs cl2 ON r2.club_id = cl2.id
    LEFT JOIN riders w ON eb.winner_id = w.id
    WHERE eb.event_id = ? AND eb.class_id = ?
    ORDER BY eb.round_number ASC, eb.heat_number ASC
");
$stmt->execute([$eventId, $elimClassId]);
$bracketsRaw = $stmt->fetchAll(PDO::FETCH_ASSOC);

$brackets = [];
foreach ($bracketsRaw as $b) {
    $brackets[$b['round_name']][] = $b;
}

// Get final results
$stmt = $elimPdo->prepare("
    SELECT er.*, r.firstname, r.lastname, cl.name as club_name
    FROM elimination_results er
    JOIN riders r ON er.rider_id = r.id
    LEFT JOIN clubs cl ON r.club_id = cl.id
    WHERE er.event_id = ? AND er.class_id = ?
    ORDER BY er.final_position ASC
");
$stmt->execute([$eventId, $elimClassId]);
$finalResults = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Round name translations
$roundNames = [
    'round_of_32' => '32-delsfinal',
    'round_of_16' => '16-delsfinal',
    'quarterfinal' => 'Kvartsfinal',
    'semifinal' => 'Semifinal',
    'third_place' => 'Match om 3:e plats',
    'final' => 'Final',
    'b_semifinal' => 'B-Semifinal',
    'b_final' => 'B-Final',
];
?>

<div class="elimination-display">
    <!-- Class Selector -->
    <?php if (count($eliminationClasses) > 1): ?>
    <div class="class-selector mb-lg">
        <div class="class-tabs">
            <?php foreach ($eliminationClasses as $ec): ?>
                <a href="?id=<?= $eventId ?>&tab=elimination&elim_class=<?= $ec['id'] ?>"
                   class="class-tab <?= $elimClassId == $ec['id'] ? 'active' : '' ?>">
                    <?= h($ec['display_name'] ?? $ec['name']) ?>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Sub-tabs: Kval | Bracket | Resultat -->
    <div class="elim-subtabs mb-lg">
        <button class="elim-subtab active" data-target="elim-qualifying">
            <i data-lucide="timer"></i> Kvalificering
        </button>
        <button class="elim-subtab" data-target="elim-bracket">
            <i data-lucide="git-branch"></i> Bracket
        </button>
        <?php if (!empty($finalResults)): ?>
        <button class="elim-subtab" data-target="elim-results">
            <i data-lucide="trophy"></i> Resultat
        </button>
        <?php endif; ?>
    </div>

    <!-- QUALIFYING SECTION -->
    <div class="elim-section" id="elim-qualifying">
        <?php if (empty($qualifyingResults)): ?>
            <div class="empty-state">
                <i data-lucide="timer"></i>
                <p>Inga kvalresultat tillgängliga.</p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table elimination-table">
                    <thead>
                        <tr>
                            <th class="col-pos">Seed</th>
                            <th class="col-bib">Nr</th>
                            <th class="col-name">Namn</th>
                            <th class="col-club">Klubb</th>
                            <th class="col-time text-right">Åk 1</th>
                            <th class="col-time text-right">Åk 2</th>
                            <th class="col-time text-right">Bäst</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($qualifyingResults as $idx => $qr): ?>
                            <tr class="<?= $qr['advances_to_bracket'] ? 'advances' : '' ?>">
                                <td class="col-pos">
                                    <span class="seed-badge"><?= $qr['seed_position'] ?? ($idx + 1) ?></span>
                                </td>
                                <td class="col-bib"><?= h($qr['bib_number'] ?? '-') ?></td>
                                <td class="col-name">
                                    <a href="/rider/<?= $qr['rider_id'] ?>" class="rider-link">
                                        <?= h($qr['firstname'] . ' ' . $qr['lastname']) ?>
                                    </a>
                                </td>
                                <td class="col-club"><?= h($qr['club_name'] ?? '-') ?></td>
                                <td class="col-time text-right"><?= $qr['run_1_time'] ? number_format($qr['run_1_time'], 3) : '-' ?></td>
                                <td class="col-time text-right"><?= $qr['run_2_time'] ? number_format($qr['run_2_time'], 3) : '-' ?></td>
                                <td class="col-time text-right best-time"><?= $qr['best_time'] ? number_format($qr['best_time'], 3) : '-' ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <!-- BRACKET SECTION -->
    <div class="elim-section hidden" id="elim-bracket">
        <?php if (empty($brackets)): ?>
            <div class="empty-state">
                <i data-lucide="git-branch"></i>
                <p>Bracket har inte genererats än.</p>
            </div>
        <?php else: ?>
            <div class="bracket-container">
                <?php foreach ($brackets as $roundName => $heats): ?>
                    <div class="bracket-round">
                        <h4 class="round-title"><?= $roundNames[$roundName] ?? ucfirst(str_replace('_', ' ', $roundName)) ?></h4>
                        <div class="round-heats">
                            <?php foreach ($heats as $heat): ?>
                                <div class="bracket-heat <?= $heat['status'] ?>">
                                    <div class="heat-matchup">
                                        <!-- Rider 1 -->
                                        <div class="matchup-rider <?= $heat['winner_id'] == $heat['rider_1_id'] ? 'winner' : '' ?>">
                                            <span class="rider-seed"><?= $heat['rider_1_seed'] ?></span>
                                            <span class="rider-name">
                                                <?php if ($heat['rider_1_id']): ?>
                                                    <?= h($heat['rider1_firstname'] . ' ' . $heat['rider1_lastname']) ?>
                                                <?php else: ?>
                                                    <em>TBD</em>
                                                <?php endif; ?>
                                            </span>
                                            <span class="rider-times">
                                                <?php if ($heat['rider_1_total']): ?>
                                                    <?= number_format($heat['rider_1_total'], 3) ?>
                                                <?php elseif ($heat['status'] === 'bye'): ?>
                                                    BYE
                                                <?php endif; ?>
                                            </span>
                                        </div>

                                        <!-- Rider 2 -->
                                        <div class="matchup-rider <?= $heat['winner_id'] == $heat['rider_2_id'] ? 'winner' : '' ?>">
                                            <span class="rider-seed"><?= $heat['rider_2_seed'] ?></span>
                                            <span class="rider-name">
                                                <?php if ($heat['rider_2_id']): ?>
                                                    <?= h($heat['rider2_firstname'] . ' ' . $heat['rider2_lastname']) ?>
                                                <?php else: ?>
                                                    <em>-</em>
                                                <?php endif; ?>
                                            </span>
                                            <span class="rider-times">
                                                <?php if ($heat['rider_2_total']): ?>
                                                    <?= number_format($heat['rider_2_total'], 3) ?>
                                                <?php endif; ?>
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- FINAL RESULTS SECTION -->
    <?php if (!empty($finalResults)): ?>
    <div class="elim-section hidden" id="elim-results">
        <div class="table-responsive">
            <table class="table elimination-table">
                <thead>
                    <tr>
                        <th class="col-pos">Plac</th>
                        <th class="col-name">Namn</th>
                        <th class="col-club">Klubb</th>
                        <th class="col-seed">Seed</th>
                        <th class="col-bracket text-center">Bracket</th>
                        <th class="col-points text-right">Poäng</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($finalResults as $result): ?>
                        <tr>
                            <td class="col-pos">
                                <?php if ($result['final_position'] <= 3): ?>
                                    <span class="position-medal position-<?= $result['final_position'] ?>"><?= $result['final_position'] ?></span>
                                <?php else: ?>
                                    <?= $result['final_position'] ?>
                                <?php endif; ?>
                            </td>
                            <td class="col-name">
                                <a href="/rider/<?= $result['rider_id'] ?>" class="rider-link">
                                    <?= h($result['firstname'] . ' ' . $result['lastname']) ?>
                                </a>
                            </td>
                            <td class="col-club"><?= h($result['club_name'] ?? '-') ?></td>
                            <td class="col-seed"><?= $result['qualifying_position'] ?? '-' ?></td>
                            <td class="col-bracket text-center">
                                <?php if ($result['bracket_type'] === 'consolation'): ?>
                                    <span class="badge badge-secondary">B</span>
                                <?php else: ?>
                                    <span class="badge badge-success">A</span>
                                <?php endif; ?>
                            </td>
                            <td class="col-points text-right"><?= $result['points'] ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
</div>

<style>
/* Elimination Display Styles */
.elimination-display {
    margin-bottom: var(--space-lg);
}

.class-tabs {
    display: flex;
    gap: var(--space-xs);
    flex-wrap: wrap;
}

.class-tab {
    padding: var(--space-sm) var(--space-md);
    background: var(--color-bg-secondary);
    border-radius: var(--radius-md);
    text-decoration: none;
    color: var(--color-text);
    font-size: var(--text-sm);
    transition: all 0.2s;
}

.class-tab:hover {
    background: var(--color-border);
}

.class-tab.active {
    background: var(--color-accent);
    color: white;
}

/* Sub-tabs */
.elim-subtabs {
    display: flex;
    gap: var(--space-xs);
    border-bottom: 2px solid var(--color-border);
    padding-bottom: var(--space-xs);
}

.elim-subtab {
    display: flex;
    align-items: center;
    gap: var(--space-xs);
    padding: var(--space-sm) var(--space-md);
    border: none;
    background: none;
    cursor: pointer;
    color: var(--color-text-secondary);
    font-size: var(--text-sm);
    border-bottom: 2px solid transparent;
    margin-bottom: -2px;
    transition: all 0.2s;
}

.elim-subtab:hover {
    color: var(--color-text);
}

.elim-subtab.active {
    color: var(--color-accent);
    border-bottom-color: var(--color-accent);
}

.elim-subtab i {
    width: 16px;
    height: 16px;
}

.elim-section {
    padding-top: var(--space-md);
}

.elim-section.hidden {
    display: none;
}

/* Qualifying Table */
.elimination-table {
    width: 100%;
}

.elimination-table .col-pos { width: 50px; text-align: center; }
.elimination-table .col-bib { width: 50px; }
.elimination-table .col-time { width: 80px; }
.elimination-table .col-seed { width: 50px; }
.elimination-table .col-bracket { width: 60px; }
.elimination-table .col-points { width: 60px; }

.seed-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 28px;
    height: 28px;
    background: var(--color-bg-secondary);
    border-radius: 50%;
    font-weight: 600;
    font-size: var(--text-sm);
}

tr.advances {
    background: rgba(97, 206, 112, 0.1);
}

tr.advances .seed-badge {
    background: var(--color-accent);
    color: white;
}

.best-time {
    font-weight: 600;
}

/* Bracket Styles */
.bracket-container {
    display: flex;
    gap: var(--space-lg);
    overflow-x: auto;
    padding: var(--space-md) 0;
}

.bracket-round {
    flex: 0 0 auto;
    min-width: 200px;
}

.round-title {
    text-align: center;
    margin-bottom: var(--space-md);
    font-size: var(--text-sm);
    color: var(--color-text-secondary);
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.round-heats {
    display: flex;
    flex-direction: column;
    gap: var(--space-md);
}

.bracket-heat {
    background: var(--color-bg-secondary);
    border-radius: var(--radius-md);
    overflow: hidden;
}

.bracket-heat.completed {
    border: 1px solid var(--color-border);
}

.heat-matchup {
    padding: var(--space-xs);
}

.matchup-rider {
    display: flex;
    align-items: center;
    gap: var(--space-sm);
    padding: var(--space-xs) var(--space-sm);
    border-radius: var(--radius-sm);
}

.matchup-rider.winner {
    background: var(--color-accent);
    color: white;
}

.rider-seed {
    font-weight: 600;
    min-width: 20px;
    font-size: var(--text-sm);
}

.rider-name {
    flex: 1;
    font-size: var(--text-sm);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.rider-times {
    font-size: var(--text-sm);
    font-weight: 500;
    min-width: 50px;
    text-align: right;
}

/* Position medals */
.position-medal {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 28px;
    height: 28px;
    border-radius: 50%;
    font-weight: 600;
    color: white;
}

.position-1 { background: linear-gradient(135deg, #FFD700, #FFA500); color: #333; }
.position-2 { background: linear-gradient(135deg, #C0C0C0, #A0A0A0); color: #333; }
.position-3 { background: linear-gradient(135deg, #CD7F32, #A0522D); }

/* Responsive */
@media (max-width: 767px) {
    .bracket-container {
        flex-direction: column;
    }

    .bracket-round {
        min-width: 100%;
    }

    .elim-subtabs {
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
    }
}
</style>

<script>
// Tab switching for elimination display
document.querySelectorAll('.elim-subtab').forEach(btn => {
    btn.addEventListener('click', () => {
        const targetId = btn.dataset.target;

        // Update buttons
        document.querySelectorAll('.elim-subtab').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');

        // Update sections
        document.querySelectorAll('.elim-section').forEach(s => s.classList.add('hidden'));
        document.getElementById(targetId).classList.remove('hidden');
    });
});
</script>
