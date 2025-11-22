<?php
/**
 * Rider Lookup Tool
 * Paste CSV with firstname, lastname, club to find UCI IDs
 */
require_once __DIR__ . '/../config.php';
require_admin();

$db = getDB();
$results = [];
$csvInput = '';
$matchStats = ['exact' => 0, 'fuzzy' => 0, 'partial' => 0, 'not_found' => 0];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $csvInput = $_POST['csv_data'] ?? '';

    if (!empty($csvInput)) {
        $lines = explode("\n", trim($csvInput));

        foreach ($lines as $lineNum => $line) {
            $line = trim($line);
            if (empty($line)) continue;

            // Parse CSV line
            $parts = str_getcsv($line, ';');
            if (count($parts) < 2) {
                $parts = str_getcsv($line, ',');
            }
            if (count($parts) < 2) {
                $parts = str_getcsv($line, "\t");
            }

            $firstname = trim($parts[0] ?? '');
            $lastname = trim($parts[1] ?? '');
            $club = trim($parts[2] ?? '');
            $inputClass = trim($parts[3] ?? '');

            if (empty($firstname) || empty($lastname)) {
                $results[] = [
                    'input' => $line,
                    'firstname' => $firstname,
                    'lastname' => $lastname,
                    'club' => $club,
                    'input_class' => $inputClass,
                    'match_type' => 'invalid',
                    'uci_id' => '',
                    'found_name' => '',
                    'found_club' => '',
                    'found_class' => '',
                    'confidence' => 0
                ];
                continue;
            }

            // Search for rider
            $match = findRider($db, $firstname, $lastname, $club);

            if ($match) {
                $matchStats[$match['match_type']]++;
            } else {
                $matchStats['not_found']++;
            }

            $results[] = [
                'input' => $line,
                'firstname' => $firstname,
                'lastname' => $lastname,
                'club' => $club,
                'input_class' => $inputClass,
                'match_type' => $match ? $match['match_type'] : 'not_found',
                'uci_id' => $match ? $match['uci_id'] : '',
                'found_name' => $match ? $match['firstname'] . ' ' . $match['lastname'] : '',
                'found_club' => $match ? $match['club_name'] : '',
                'found_class' => $match ? $match['class_name'] : '',
                'birth_year' => $match ? $match['birth_year'] : '',
                'gender' => $match ? $match['gender'] : '',
                'confidence' => $match ? $match['confidence'] : 0,
                'rider_id' => $match ? $match['id'] : null
            ];
        }
    }
}

/**
 * Find rider in database using multiple strategies
 */
function findRider($db, $firstname, $lastname, $club) {
    // Normalize names for matching
    $normFirstname = normalizeString($firstname);
    $normLastname = normalizeString($lastname);
    $normClub = normalizeString($club);

    // Strategy 1: Exact match with club
    if (!empty($club)) {
        $rider = $db->getRow("
            SELECT r.id, r.firstname, r.lastname, r.uci_id, r.license_number,
                   r.birth_year, r.gender, c.name as club_name,
                   cl.name as class_name
            FROM riders r
            LEFT JOIN clubs c ON r.club_id = c.id
            LEFT JOIN classes cl ON r.class_id = cl.id
            WHERE LOWER(r.firstname) = LOWER(?)
              AND LOWER(r.lastname) = LOWER(?)
              AND LOWER(c.name) LIKE LOWER(?)
            LIMIT 1
        ", [$firstname, $lastname, '%' . $club . '%']);

        if ($rider && !empty($rider['uci_id'])) {
            return array_merge($rider, ['match_type' => 'exact', 'confidence' => 100]);
        }
    }

    // Strategy 2: Exact name match (any club)
    $rider = $db->getRow("
        SELECT r.id, r.firstname, r.lastname, r.uci_id, r.license_number,
               r.birth_year, r.gender, c.name as club_name,
               cl.name as class_name
        FROM riders r
        LEFT JOIN clubs c ON r.club_id = c.id
        LEFT JOIN classes cl ON r.class_id = cl.id
        WHERE LOWER(r.firstname) = LOWER(?)
          AND LOWER(r.lastname) = LOWER(?)
          AND r.uci_id IS NOT NULL
          AND r.uci_id != ''
        ORDER BY r.license_year DESC
        LIMIT 1
    ", [$firstname, $lastname]);

    if ($rider) {
        $confidence = empty($club) ? 90 : (stripos($rider['club_name'] ?? '', $club) !== false ? 95 : 80);
        return array_merge($rider, ['match_type' => 'exact', 'confidence' => $confidence]);
    }

    // Strategy 3: Fuzzy match (normalized names)
    $riders = $db->getAll("
        SELECT r.id, r.firstname, r.lastname, r.uci_id, r.license_number,
               r.birth_year, r.gender, c.name as club_name,
               cl.name as class_name
        FROM riders r
        LEFT JOIN clubs c ON r.club_id = c.id
        LEFT JOIN classes cl ON r.class_id = cl.id
        WHERE r.uci_id IS NOT NULL
          AND r.uci_id != ''
        ORDER BY r.license_year DESC
    ");

    $bestMatch = null;
    $bestScore = 0;

    foreach ($riders as $rider) {
        $riderNormFirst = normalizeString($rider['firstname']);
        $riderNormLast = normalizeString($rider['lastname']);

        // Check normalized match
        if ($riderNormFirst === $normFirstname && $riderNormLast === $normLastname) {
            $score = 85;

            // Boost score if club matches
            if (!empty($normClub) && !empty($rider['club_name'])) {
                $riderNormClub = normalizeString($rider['club_name']);
                if (strpos($riderNormClub, $normClub) !== false || strpos($normClub, $riderNormClub) !== false) {
                    $score = 90;
                }
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestMatch = array_merge($rider, ['match_type' => 'fuzzy', 'confidence' => $score]);
            }
        }

        // Check partial match (first 3 chars of each name)
        if (strlen($normFirstname) >= 3 && strlen($normLastname) >= 3) {
            if (substr($riderNormFirst, 0, 3) === substr($normFirstname, 0, 3) &&
                substr($riderNormLast, 0, 3) === substr($normLastname, 0, 3)) {
                $score = 60;

                // Require club match for partial matches
                if (!empty($normClub) && !empty($rider['club_name'])) {
                    $riderNormClub = normalizeString($rider['club_name']);
                    if (strpos($riderNormClub, $normClub) !== false || strpos($normClub, $riderNormClub) !== false) {
                        $score = 70;
                        if ($score > $bestScore) {
                            $bestScore = $score;
                            $bestMatch = array_merge($rider, ['match_type' => 'partial', 'confidence' => $score]);
                        }
                    }
                }
            }
        }
    }

    return $bestMatch;
}

/**
 * Normalize string for comparison
 */
function normalizeString($str) {
    $str = mb_strtolower($str, 'UTF-8');
    // Remove accents
    $str = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $str);
    // Remove non-alphanumeric
    $str = preg_replace('/[^a-z0-9]/', '', $str);
    return $str;
}

$pageTitle = 'Sök UCI-ID';
$pageType = 'admin';
include __DIR__ . '/../includes/layout-header.php';
?>

<main class="gs-main-content">
    <div class="gs-container">

        <!-- Header -->
        <div class="gs-flex gs-justify-between gs-items-center gs-mb-lg">
            <div>
                <h1 class="gs-h1">
                    <i data-lucide="search"></i>
                    Sök UCI-ID
                </h1>
                <p class="gs-text-secondary">
                    Klistra in CSV med förnamn, efternamn, klubb för att hitta UCI-ID
                </p>
            </div>
        </div>

        <!-- Input Form -->
        <div class="gs-card gs-mb-lg">
            <div class="gs-card-header">
                <h2 class="gs-h3">
                    <i data-lucide="clipboard-paste"></i>
                    Klistra in data
                </h2>
            </div>
            <div class="gs-card-content">
                <form method="POST">
                    <?= csrf_field() ?>

                    <div class="gs-form-group gs-mb-md">
                        <label class="gs-label">
                            CSV-data (förnamn;efternamn;klubb;klass)
                        </label>
                        <textarea
                            name="csv_data"
                            class="gs-input"
                            rows="10"
                            placeholder="Erik;Johansson;CK Örn;Herrar
Anna;Svensson;IK Hakarpspojkarna;Damer
Johan;Karlsson;Team 42;;
..."
                        ><?= h($csvInput) ?></textarea>
                        <small class="gs-text-secondary">
                            Format: förnamn;efternamn;klubb;klass (klass är valfritt). Separera med semikolon (;), komma (,) eller tab.
                        </small>
                    </div>

                    <button type="submit" class="gs-btn gs-btn-primary">
                        <i data-lucide="search"></i>
                        Sök UCI-ID
                    </button>
                </form>
            </div>
        </div>

        <?php if (!empty($results)): ?>
            <!-- Stats -->
            <div class="gs-grid gs-grid-cols-2 gs-md-grid-cols-4 gs-gap-md gs-mb-lg">
                <div class="gs-card">
                    <div class="gs-card-content gs-text-center">
                        <div class="gs-text-2xl gs-text-success"><?= $matchStats['exact'] ?></div>
                        <div class="gs-text-sm gs-text-secondary">Exakt match</div>
                    </div>
                </div>
                <div class="gs-card">
                    <div class="gs-card-content gs-text-center">
                        <div class="gs-text-2xl gs-text-warning"><?= $matchStats['fuzzy'] ?></div>
                        <div class="gs-text-sm gs-text-secondary">Fuzzy match</div>
                    </div>
                </div>
                <div class="gs-card">
                    <div class="gs-card-content gs-text-center">
                        <div class="gs-text-2xl gs-text-info"><?= $matchStats['partial'] ?></div>
                        <div class="gs-text-sm gs-text-secondary">Delvis match</div>
                    </div>
                </div>
                <div class="gs-card">
                    <div class="gs-card-content gs-text-center">
                        <div class="gs-text-2xl gs-text-danger"><?= $matchStats['not_found'] ?></div>
                        <div class="gs-text-sm gs-text-secondary">Ej hittade</div>
                    </div>
                </div>
            </div>

            <!-- Results Table -->
            <div class="gs-card gs-mb-lg">
                <div class="gs-card-header">
                    <h2 class="gs-h3">
                        <i data-lucide="list"></i>
                        Resultat (<?= count($results) ?> rader)
                    </h2>
                </div>
                <div class="gs-card-content">
                    <div class="gs-table-responsive">
                        <table class="gs-table">
                            <thead>
                                <tr>
                                    <th>Indata</th>
                                    <th>Hittad åkare</th>
                                    <th>Klubb</th>
                                    <th>Klass</th>
                                    <th>UCI-ID</th>
                                    <th>Match</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($results as $result): ?>
                                    <tr class="<?= $result['match_type'] === 'not_found' ? 'gs-bg-danger-light' : '' ?>">
                                        <td>
                                            <strong><?= h($result['firstname'] . ' ' . $result['lastname']) ?></strong>
                                            <?php if ($result['club']): ?>
                                                <br><small class="gs-text-secondary"><?= h($result['club']) ?></small>
                                            <?php endif; ?>
                                            <?php if (!empty($result['input_class'])): ?>
                                                <br><small class="gs-text-info"><?= h($result['input_class']) ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($result['found_name']): ?>
                                                <?= h($result['found_name']) ?>
                                                <?php if (!empty($result['birth_year'])): ?>
                                                    <br><small class="gs-text-secondary"><?= h($result['birth_year']) ?> • <?= h($result['gender'] ?? '?') ?></small>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span class="gs-text-danger">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($result['found_club']): ?>
                                                <?= h($result['found_club']) ?>
                                            <?php else: ?>
                                                <span class="gs-text-secondary">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if (!empty($result['found_class'])): ?>
                                                <span class="gs-badge gs-badge-secondary"><?= h($result['found_class']) ?></span>
                                            <?php else: ?>
                                                <span class="gs-text-secondary">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($result['uci_id']): ?>
                                                <code class="gs-text-success"><?= h($result['uci_id']) ?></code>
                                            <?php else: ?>
                                                <span class="gs-text-danger">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php
                                            $badgeClass = match($result['match_type']) {
                                                'exact' => 'gs-badge-success',
                                                'fuzzy' => 'gs-badge-warning',
                                                'partial' => 'gs-badge-info',
                                                default => 'gs-badge-danger'
                                            };
                                            $matchLabel = match($result['match_type']) {
                                                'exact' => 'Exakt',
                                                'fuzzy' => 'Fuzzy',
                                                'partial' => 'Delvis',
                                                'invalid' => 'Ogiltig',
                                                default => 'Ej hittad'
                                            };
                                            ?>
                                            <span class="gs-badge <?= $badgeClass ?>">
                                                <?= $matchLabel ?>
                                                <?php if ($result['confidence']): ?>
                                                    (<?= $result['confidence'] ?>%)
                                                <?php endif; ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Export CSV -->
            <div class="gs-card">
                <div class="gs-card-header">
                    <h2 class="gs-h3">
                        <i data-lucide="download"></i>
                        Exportera resultat
                    </h2>
                </div>
                <div class="gs-card-content">
                    <p class="gs-text-secondary gs-mb-md">
                        Kopiera CSV-data nedan och klistra in i din resultatfil:
                    </p>
                    <textarea
                        id="exportCsv"
                        class="gs-input"
                        rows="10"
                        readonly
                        onclick="this.select()"
                    ><?php
                        echo "Förnamn;Efternamn;Klubb;Klass;UCI-ID;Födelsear;Kön;Match\n";
                        foreach ($results as $result) {
                            echo h($result['firstname']) . ';';
                            echo h($result['lastname']) . ';';
                            echo h($result['club']) . ';';
                            echo h($result['found_class'] ?? $result['input_class'] ?? '') . ';';
                            echo h($result['uci_id']) . ';';
                            echo h($result['birth_year'] ?? '') . ';';
                            echo h($result['gender'] ?? '') . ';';
                            echo h($result['match_type']) . "\n";
                        }
                    ?></textarea>
                    <button type="button" class="gs-btn gs-btn-outline gs-mt-sm" onclick="copyExport()">
                        <i data-lucide="copy"></i>
                        Kopiera till urklipp
                    </button>
                </div>
            </div>
        <?php endif; ?>

    </div>
</main>

<script>
function copyExport() {
    const textarea = document.getElementById('exportCsv');
    textarea.select();
    document.execCommand('copy');

    // Show feedback
    const btn = event.target.closest('button');
    const originalText = btn.innerHTML;
    btn.innerHTML = '<i data-lucide="check"></i> Kopierat!';
    btn.classList.add('gs-btn-success');
    btn.classList.remove('gs-btn-outline');

    setTimeout(() => {
        btn.innerHTML = originalText;
        btn.classList.remove('gs-btn-success');
        btn.classList.add('gs-btn-outline');
        lucide.createIcons();
    }, 2000);
}
</script>

<?php include __DIR__ . '/../includes/layout-footer.php'; ?>
