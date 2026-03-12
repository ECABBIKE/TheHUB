<?php
/**
 * GravitySeries — Startsida
 * Design from docs/design-references/gs-homepage-reference.html
 */

$gsPageTitle = 'Svensk Gravitycykling';
$gsMetaDesc = 'GravitySeries — Organisationen bakom svensk gravitycykling. Enduro och Downhill från Motion till Elite.';
$gsActiveNav = 'start';
$gsEditUrl = '/admin/pages/gs-homepage.php';

require_once __DIR__ . '/includes/gs-header.php';

// Load editable content from site_settings (gs_ prefix)
$_gsContent = [];
try {
    $gsRows = $pdo->query("SELECT setting_key, setting_value FROM sponsor_settings WHERE setting_key LIKE 'gs_%'")->fetchAll();
    foreach ($gsRows as $r) {
        $_gsContent[$r['setting_key']] = $r['setting_value'];
    }
} catch (PDOException $e) {
    // Fallback to defaults
}
function gs($key, $default = '') {
    global $_gsContent;
    return $_gsContent[$key] ?? $default;
}

// Load stats from database
$stats = ['riders' => 0, 'events' => 0, 'clubs' => 0];
try {
    $row = $pdo->query("
        SELECT
            (SELECT COUNT(*) FROM riders WHERE active = 1) AS riders,
            (SELECT COUNT(*) FROM events WHERE active = 1 AND YEAR(date) = YEAR(CURDATE())) AS events,
            (SELECT COUNT(*) FROM clubs WHERE active = 1) AS clubs
    ")->fetch();
    if ($row) {
        $stats = $row;
    }
} catch (PDOException $e) {
    // Fallback values
}

// Load series from database with event stats
$series = [];
try {
    $series = $pdo->query("
        SELECT s.id, s.name, s.year,
            sb.name AS brand_name, sb.slug AS brand_slug,
            (SELECT COUNT(*) FROM series_events se WHERE se.series_id = s.id) AS total_events,
            (SELECT COUNT(*) FROM series_events se JOIN events e ON se.event_id = e.id WHERE se.series_id = s.id AND e.date < CURDATE()) AS done_events,
            (SELECT COUNT(DISTINCT r.cyclist_id) FROM results r JOIN series_events se ON r.event_id = se.event_id WHERE se.series_id = s.id) AS total_riders
        FROM series s
        LEFT JOIN series_brands sb ON s.brand_id = sb.id
        WHERE s.status = 'active' AND s.year = YEAR(CURDATE())
        ORDER BY s.name ASC
    ")->fetchAll();
} catch (PDOException $e) {
    // Fallback
}

// Load events per series for pills
$seriesEvents = [];
try {
    foreach ($series as $s) {
        $evtStmt = $pdo->prepare("
            SELECT e.id, e.name, e.location, e.date
            FROM series_events se
            JOIN events e ON se.event_id = e.id
            WHERE se.series_id = ?
            ORDER BY e.date ASC
        ");
        $evtStmt->execute([$s['id']]);
        $seriesEvents[$s['id']] = $evtStmt->fetchAll();
    }
} catch (PDOException $e) {}

// Load top 3 clubs per series (club championship)
$seriesClubs = [];
try {
    foreach ($series as $s) {
        $clubStmt = $pdo->prepare("
            SELECT c.name AS club_name, SUM(r.points) AS total
            FROM results r
            JOIN series_events se ON r.event_id = se.event_id
            JOIN riders rd ON r.cyclist_id = rd.id
            JOIN clubs c ON rd.club_id = c.id
            WHERE se.series_id = ?
            AND r.points > 0
            GROUP BY c.id, c.name
            ORDER BY total DESC
            LIMIT 3
        ");
        $clubStmt->execute([$s['id']]);
        $seriesClubs[$s['id']] = $clubStmt->fetchAll();
    }
} catch (PDOException $e) {}

// Load sponsors from gs_sponsors table
$sponsors = [];
$collaborators = [];
try {
    $allSponsors = $pdo->query("
        SELECT name, website_url, logo_url, type
        FROM gs_sponsors
        WHERE active = 1
        ORDER BY sort_order ASC
    ")->fetchAll();
    foreach ($allSponsors as $sp) {
        if ($sp['type'] === 'collaborator') {
            $collaborators[] = $sp;
        } else {
            $sponsors[] = $sp;
        }
    }
} catch (PDOException $e) {
    // Table might not exist yet
}

// Load nav pages for footer links
$footerPages = $gsNavPages;

// Build slug→series_id lookup from DB series
$seriesIdBySlug = [];
foreach ($series as $s) {
    $slug = strtolower($s['brand_slug'] ?? '');
    if ($slug) $seriesIdBySlug[$slug] = $s['id'];
}

// Map pin SVG (reused)
$mapPinSvg = '<svg viewBox="0 0 24 24"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>';
$chevronSvg = '<svg viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"/></svg>';
?>

<!-- HERO -->
<section class="hero">
  <div class="hero-bg">
    <div class="hero-bg-stripe" style="background: linear-gradient(180deg, var(--ggs), var(--ges))"></div>
    <div class="hero-bg-grid"></div>
    <div class="hero-series-bar">
      <span style="background:var(--ggs)"></span>
      <span style="background:var(--ges)"></span>
      <span style="background:var(--cgs)"></span>
      <span style="background:var(--gs-blue)"></span>
      <span style="background:var(--accent)"></span>
    </div>
  </div>
  <div class="hero-content">
    <div class="hero-eyebrow"><?= gs('gs_hero_eyebrow', 'Svensk Gravitycykling sedan 2016') ?></div>
    <h1 class="hero-title"><?= gs('gs_hero_title', 'Gravity<em>Series</em>') ?></h1>
    <p class="hero-body"><?= gs('gs_hero_body', 'Organisationen bakom svensk enduro och downhill. Vi arrangerar tävlingar, sätter regler och utvecklar sporten — från Motion till Elite.') ?></p>
    <div class="hero-actions">
      <a class="btn-primary" href="#serier">
        <?= $chevronSvg ?>
        Utforska serierna
      </a>
      <a class="btn-ghost" href="https://thehub.gravityseries.se">Öppna TheHUB</a>
    </div>
  </div>
</section>

<!-- STAT BAR -->
<div class="stat-bar">
  <div class="stat-bar-inner">
    <div class="stat-item">
      <span class="stat-val"><?= number_format($stats['riders'], 0, ',', ' ') ?></span>
      <span class="stat-label">Licensierade åkare</span>
    </div>
    <div class="stat-item">
      <span class="stat-val"><?= (int)$stats['events'] ?></span>
      <span class="stat-label">Tävlingar <?= date('Y') ?></span>
    </div>
    <div class="stat-item">
      <span class="stat-val"><?= number_format($stats['clubs'], 0, ',', ' ') ?></span>
      <span class="stat-label">Klubbar</span>
    </div>
    <div class="stat-item">
      <span class="stat-val">2016</span>
      <span class="stat-label">Grundat</span>
    </div>
  </div>
</div>

<!-- SERIER -->
<section id="serier">
  <div class="gs-section">
    <div class="section-head">
      <div class="section-label"><?= gs('gs_section_series_label', 'Tävlingsserier') ?></div>
      <h2 class="section-title"><?= gs('gs_section_series_title', 'Fyra serier.<br>En rörelse.') ?></h2>
      <p class="section-body"><?= gs('gs_section_series_body', 'GravitySeries driver Enduro och Downhill-tävlingar från Malmö till Umeå. Hitta din serie — och ditt nästa lopp.') ?></p>
    </div>
    <div class="gs-series-grid">
      <?php
      // Card definitions: slug → display config
      $cardConfig = [
          'ggs'     => ['abbr' => 'GGS', 'css' => 'ggs',  'disc' => 'Enduro',      'region' => 'Götaland'],
          'capital' => ['abbr' => 'CGS', 'css' => 'cgs',  'disc' => 'Enduro',      'region' => 'Mälardalen'],
          'jgs'     => ['abbr' => 'JGS', 'css' => 'jgs',  'disc' => 'Enduro',      'region' => 'Jämtland'],
          'gsd'     => ['abbr' => 'GSD', 'css' => 'gsdh', 'disc' => 'Downhill',    'region' => 'Nationell'],
          'gse'     => ['abbr' => 'GSE', 'css' => '',     'disc' => 'Enduro',      'region' => 'Nationell'],
          'gstotal' => ['abbr' => 'TOTAL','css' => '',    'disc' => 'Enduro + DH', 'region' => 'Alla serier samlat'],
      ];
      foreach ($series as $s):
          $slug = strtolower($s['brand_slug'] ?? '');
          $cfg = $cardConfig[$slug] ?? null;
          if (!$cfg) continue;
          $seriesId = $s['id'];
          $events = $seriesEvents[$seriesId] ?? [];
          $clubs = $seriesClubs[$seriesId] ?? [];
          $totalEvents = (int)($s['total_events'] ?? count($events));
          $doneEvents = (int)($s['done_events'] ?? 0);
          $remainingEvents = $totalEvents - $doneEvents;
          $totalRiders = (int)($s['total_riders'] ?? 0);
          $today = date('Y-m-d');

          // Determine next event
          $nextEventIdx = -1;
          foreach ($events as $i => $evt) {
              if ($evt['date'] >= $today) { $nextEventIdx = $i; break; }
          }
      ?>
        <a class="gs-serie-card <?= $cfg['css'] ?>" data-serie="<?= $cfg['abbr'] ?>" href="https://thehub.gravityseries.se/series/<?= $seriesId ?>">
          <div class="gsc-inner">
            <div class="gsc-top">
              <div class="gsc-badge"><i class="gsc-dot"></i> <?= $cfg['abbr'] ?></div>
              <span class="gsc-discipline"><?= htmlspecialchars($cfg['disc']) ?></span>
            </div>
            <div class="gsc-title-wrap">
              <h3 class="gsc-title"><?= htmlspecialchars($s['name']) ?></h3>
              <div class="gsc-meta"><?= htmlspecialchars($cfg['disc']) ?> &middot; <?= htmlspecialchars($cfg['region']) ?></div>
            </div>
            <div class="gsc-stats">
              <div class="gsc-stat"><strong><?= $totalEvents ?></strong><span>Deltävlingar</span></div>
              <div class="gsc-stat"><strong><?= $doneEvents ?></strong><span>Avgjorda</span></div>
              <div class="gsc-stat"><strong><?= $totalRiders ?></strong><span>Åkare</span></div>
              <div class="gsc-stat"><strong><?= $remainingEvents ?></strong><span>Kvar</span></div>
            </div>
            <?php if (!empty($events)): ?>
            <div class="gsc-events">
              <?php foreach ($events as $i => $evt):
                  $isDone = $evt['date'] < $today;
                  $isNext = ($i === $nextEventIdx);
                  $pillClass = $isDone ? 'done' : ($isNext ? 'next' : '');
              ?>
                <div class="gsc-pill <?= $pillClass ?>"><i></i> <?= htmlspecialchars($evt['location'] ?: $evt['name']) ?></div>
              <?php endforeach; ?>
            </div>
            <?php endif; ?>
            <div class="gsc-clubs">
              <?php if (!empty($clubs)): ?>
                <div class="gsc-clubs-label">Klubbmästerskap</div>
                <?php foreach ($clubs as $pos => $club): ?>
                <div class="gsc-club-row">
                  <span class="gsc-club-pos"><?= $pos + 1 ?></span>
                  <span class="gsc-club-name"><?= htmlspecialchars($club['club_name']) ?></span>
                  <span class="gsc-club-pts"><?= (int)$club['total'] ?> p</span>
                </div>
                <?php endforeach; ?>
              <?php else: ?>
                <div class="gsc-clubs-empty">Säsongen pågår — ställningen uppdateras efter varje deltävling</div>
              <?php endif; ?>
            </div>
          </div>
        </a>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- ARRANGERA / REGLER / LICENSER -->
<div style="background:var(--bg-2, var(--white)); border-top: 1px solid var(--border, var(--rule)); border-bottom: 1px solid var(--border, var(--rule));">
  <div class="gs-section" id="arrangera">
    <div class="section-head">
      <div class="section-label"><?= gs('gs_section_info_label', 'Praktisk info') ?></div>
      <h2 class="section-title"><?= gs('gs_section_info_title', 'För åkare<br>&amp; arrangörer') ?></h2>
    </div>
    <div class="info-grid">
      <a class="info-card" href="<?= $gsBaseUrl ?>/arrangor-info">
        <div class="info-icon">
          <svg viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
        </div>
        <div class="info-title"><?= gs('gs_info_card_1_title', 'Arrangera ett event') ?></div>
        <p class="info-desc"><?= gs('gs_info_card_1_desc', 'Vill du arrangera en tävling inom GravitySeries? Här hittar du allt från ansökan till banprojektering och praktisk info.') ?></p>
        <span class="info-link">Arrangörsinformation <?= $chevronSvg ?></span>
      </a>
      <a class="info-card" href="<?= $gsBaseUrl ?>/licenser">
        <div class="info-icon">
          <svg viewBox="0 0 24 24"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>
        </div>
        <div class="info-title"><?= gs('gs_info_card_2_title', 'Licenser &amp; SCF') ?></div>
        <p class="info-desc"><?= gs('gs_info_card_2_desc', 'För att tävla i GravitySeries behöver du en giltig SCF-licens. Här förklarar vi hur du skaffar en och vad den kostar.') ?></p>
        <span class="info-link">Licensinfo <?= $chevronSvg ?></span>
      </a>
      <a class="info-card" href="<?= $gsBaseUrl ?>/gravity-id">
        <div class="info-icon">
          <svg viewBox="0 0 24 24"><rect x="2" y="3" width="20" height="14" rx="2"/><path d="M8 21h8M12 17v4"/></svg>
        </div>
        <div class="info-title"><?= gs('gs_info_card_3_title', 'Gravity-ID') ?></div>
        <p class="info-desc"><?= gs('gs_info_card_3_desc', 'Ditt Gravity-ID kopplar ihop dina tävlingsresultat, licens och profil. Allt på ett ställe — oavsett vilken serie du kör.') ?></p>
        <span class="info-link">Om Gravity-ID <?= $chevronSvg ?></span>
      </a>
    </div>
  </div>
</div>

<!-- STYRELSE -->
<div class="board-section" id="om">
  <div class="gs-section">
    <div class="section-head">
      <div class="section-label" style="color:var(--accent)"><?= gs('gs_section_board_label', 'Organisation') ?></div>
      <h2 class="section-title"><?= gs('gs_section_board_title', 'Styrelsen') ?></h2>
      <p class="section-body"><?= gs('gs_section_board_body', 'GravitySeries drivs ideellt av ett engagerat gäng med passion för gravitycykling.') ?></p>
    </div>
    <div class="board-grid">
      <?php
      $boardMembers = json_decode(gs('gs_board_members', ''), true);
      if (empty($boardMembers)) {
          $boardMembers = [
              ['role' => 'Ordförande', 'name' => 'Förnamn Efternamn', 'contact' => 'ordforde@gravityseries.se'],
              ['role' => 'Vice ordförande', 'name' => 'Förnamn Efternamn', 'contact' => 'vice@gravityseries.se'],
              ['role' => 'Kassör', 'name' => 'Förnamn Efternamn', 'contact' => 'kassor@gravityseries.se'],
              ['role' => 'Tävlingsansvarig', 'name' => 'Förnamn Efternamn', 'contact' => 'tavling@gravityseries.se'],
              ['role' => 'Teknisk ansvarig', 'name' => 'Förnamn Efternamn', 'contact' => 'teknik@gravityseries.se'],
              ['role' => 'Kontakt', 'name' => 'info@gravityseries.se', 'contact' => 'Allmänna frågor & media'],
          ];
      }
      foreach ($boardMembers as $member):
      ?>
      <div class="board-card">
        <div class="board-role"><?= htmlspecialchars($member['role'] ?? '') ?></div>
        <div class="board-name"><?= htmlspecialchars($member['name'] ?? '') ?></div>
        <div class="board-contact"><?= htmlspecialchars($member['contact'] ?? '') ?></div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<!-- PARTNERS -->
<?php if (!empty($sponsors)): ?>
<div class="partners-section">
  <div class="partners-inner">
    <div class="section-label" style="margin-bottom:24px;">Partners &amp; Leverantörer</div>
    <div class="partners-list">
      <?php foreach ($sponsors as $sp): ?>
        <a href="<?= htmlspecialchars($sp['website_url'] ?: '#') ?>" target="_blank" rel="noopener">
          <?php if ($sp['logo_url']): ?>
            <img src="<?= htmlspecialchars($sp['logo_url']) ?>" alt="<?= htmlspecialchars($sp['name']) ?>">
          <?php else: ?>
            <?= htmlspecialchars($sp['name']) ?>
          <?php endif; ?>
        </a>
      <?php endforeach; ?>
    </div>
  </div>
</div>
<?php endif; ?>

<?php if (!empty($collaborators)): ?>
<div class="partners-section" style="border-top:none;">
  <div class="partners-inner">
    <div class="section-label" style="margin-bottom:24px;">Samarbeten</div>
    <div class="partners-list">
      <?php foreach ($collaborators as $sp): ?>
        <a href="<?= htmlspecialchars($sp['website_url'] ?: '#') ?>" target="_blank" rel="noopener">
          <?php if ($sp['logo_url']): ?>
            <img src="<?= htmlspecialchars($sp['logo_url']) ?>" alt="<?= htmlspecialchars($sp['name']) ?>">
          <?php else: ?>
            <?= htmlspecialchars($sp['name']) ?>
          <?php endif; ?>
        </a>
      <?php endforeach; ?>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- HUB CTA -->
<div class="hub-cta-section">
  <div class="hub-cta-inner">
    <div>
      <div class="hub-cta-title"><?= gs('gs_hub_cta_title', 'Kalender, resultat<br>&amp; ranking') ?></div>
      <div class="hub-cta-sub"><?= gs('gs_hub_cta_body', 'Allt samlat på TheHUB — vår tävlingsplattform.') ?></div>
    </div>
    <a class="hub-cta-btn" href="https://thehub.gravityseries.se">
      <svg viewBox="0 0 24 24"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
      Öppna TheHUB
    </a>
  </div>
</div>

<?php require_once __DIR__ . '/includes/gs-footer.php'; ?>
