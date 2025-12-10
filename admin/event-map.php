<?php
/**
 * Admin Event Map Management - Multi-track support
 */
require_once __DIR__ . '/../config.php';
require_admin();
require_once INCLUDES_PATH . '/map_functions.php';

$db = getDB();
global $pdo;

// Get event ID
$eventId = intval($_GET['id'] ?? $_GET['event_id'] ?? 0);
if ($eventId <= 0) {
    set_flash('error', 'Ogiltigt event-ID');
    header('Location: /admin/events');
    exit;
}

$event = $db->getRow("SELECT id, name, date FROM events WHERE id = ?", [$eventId]);
if (!$event) {
    set_flash('error', 'Event hittades inte');
    header('Location: /admin/events');
    exit;
}

$message = '';
$messageType = '';

// Selected track for editing
$selectedTrackId = intval($_GET['track'] ?? 0);

// Handle POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = $_POST['action'] ?? '';

    try {
        switch ($action) {
            case 'upload_gpx':
                if (!isset($_FILES['gpx_file']) || $_FILES['gpx_file']['error'] !== UPLOAD_ERR_OK) {
                    throw new Exception('Ingen fil uppladdad');
                }
                $file = $_FILES['gpx_file'];
                if (strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) !== 'gpx') {
                    throw new Exception('Endast GPX-filer');
                }
                $uploadDir = getGpxUploadPath();
                $filename = 'event_' . $eventId . '_' . time() . '.gpx';
                $filepath = $uploadDir . '/' . $filename;
                if (!move_uploaded_file($file['tmp_name'], $filepath)) {
                    throw new Exception('Kunde inte spara');
                }
                $parsedData = parseGpxFile($filepath);
                $trackName = trim($_POST['track_name'] ?? '') ?: $event['name'];
                $options = [
                    'route_type' => trim($_POST['route_type'] ?? ''),
                    'route_label' => trim($_POST['route_label'] ?? '') ?: $trackName,
                    'color' => $_POST['track_color'] ?? '#3B82F6',
                    'is_primary' => !empty($_POST['is_primary'])
                ];
                $newTrackId = saveEventTrack($pdo, $eventId, $trackName, $filename, $parsedData, $options);
                $selectedTrackId = $newTrackId;
                $message = 'GPX uppladdad!';
                $messageType = 'success';
                break;

            case 'update_track':
                $trackId = intval($_POST['track_id'] ?? 0);
                if ($trackId > 0) {
                    updateTrack($pdo, $trackId, [
                        'name' => trim($_POST['track_name'] ?? ''),
                        'route_label' => trim($_POST['route_label'] ?? ''),
                        'color' => $_POST['track_color'] ?? '#3B82F6',
                        'is_primary' => !empty($_POST['is_primary'])
                    ]);
                    $message = 'Bana uppdaterad';
                    $messageType = 'success';
                }
                break;

            case 'delete_track':
                $trackId = intval($_POST['track_id'] ?? 0);
                if ($trackId > 0) {
                    deleteEventTrack($pdo, $trackId);
                    if ($selectedTrackId == $trackId) $selectedTrackId = 0;
                }
                $message = 'Bana borttagen';
                $messageType = 'success';
                break;

            case 'add_poi':
                $poiData = [
                    'poi_type' => $_POST['poi_type'] ?? '',
                    'label' => trim($_POST['poi_label'] ?? ''),
                    'lat' => floatval($_POST['poi_lat'] ?? 0),
                    'lng' => floatval($_POST['poi_lng'] ?? 0),
                ];
                if (empty($poiData['poi_type']) || $poiData['lat'] == 0) {
                    throw new Exception('Typ och koordinater krävs');
                }
                addEventPoi($pdo, $eventId, $poiData);
                $message = 'POI tillagd';
                $messageType = 'success';
                break;

            case 'delete_poi':
                $poiId = intval($_POST['poi_id'] ?? 0);
                if ($poiId > 0) deleteEventPoi($pdo, $poiId);
                $message = 'POI borttagen';
                $messageType = 'success';
                break;

            case 'delete_segment':
                $segmentId = intval($_POST['segment_id'] ?? 0);
                if ($segmentId > 0) {
                    $pdo->prepare("DELETE FROM event_track_segments WHERE id = ?")->execute([$segmentId]);
                }
                $message = 'Segment borttaget';
                $messageType = 'success';
                break;

            case 'update_segment_type':
                $segmentId = intval($_POST['segment_id'] ?? 0);
                $newType = $_POST['new_type'] ?? '';
                if ($segmentId > 0 && in_array($newType, ['stage', 'liaison', 'lift'])) {
                    $colors = ['stage' => '#EF4444', 'liaison' => '#61CE70', 'lift' => '#F59E0B'];
                    $pdo->prepare("UPDATE event_track_segments SET segment_type = ?, color = ? WHERE id = ?")
                        ->execute([$newType, $colors[$newType], $segmentId]);
                }
                $message = 'Sektionstyp uppdaterad';
                $messageType = 'success';
                break;

            case 'add_segment_visual':
                $trackId = intval($_POST['track_id'] ?? 0);
                $segmentName = trim($_POST['segment_name'] ?? '');
                $segmentType = $_POST['segment_type'] ?? 'stage';
                $startIdx = intval($_POST['start_index'] ?? -1);
                $endIdx = intval($_POST['end_index'] ?? -1);
                if ($trackId <= 0 || $startIdx < 0 || $endIdx < 0 || $endIdx <= $startIdx) {
                    throw new Exception('Ogiltig sträckdata');
                }
                addSegmentByWaypointIndex($pdo, $trackId, [
                    'name' => $segmentName ?: ($segmentType === 'stage' ? 'SS' : 'Liaison'),
                    'type' => $segmentType,
                    'start_index' => $startIdx,
                    'end_index' => $endIdx
                ]);
                $message = 'Sträcka tillagd!';
                $messageType = 'success';
                break;
        }
    } catch (Exception $e) {
        $message = $e->getMessage();
        $messageType = 'error';
    }
}

// Get all tracks for event
$allTracks = getEventTracks($pdo, $eventId);
$pois = getEventPois($pdo, $eventId, false) ?: [];
$poiTypes = getPoiTypesForSelect() ?: [];

// Select first track if none selected
if ($selectedTrackId <= 0 && !empty($allTracks)) {
    $selectedTrackId = $allTracks[0]['id'];
}

// Get selected track details
$currentTrack = null;
$trackWaypoints = [];
foreach ($allTracks as $t) {
    if ($t['id'] == $selectedTrackId) {
        $currentTrack = $t;
        break;
    }
}
if ($currentTrack) {
    try {
        $trackWaypoints = getTrackWaypointsForEditor($pdo, $currentTrack['id']);
    } catch (Exception $e) {
        $trackWaypoints = [];
    }
}

// Get map data for display (all tracks)
$mapData = getEventMapDataMultiTrack($pdo, $eventId);

// Track colors for selection
$trackColors = [
    '#3B82F6' => 'Blå',
    '#61CE70' => 'Grön',
    '#EF4444' => 'Röd',
    '#F59E0B' => 'Orange',
    '#8B5CF6' => 'Lila',
    '#EC4899' => 'Rosa',
    '#14B8A6' => 'Teal',
    '#6B7280' => 'Grå'
];

// Page setup
$page_title = 'Karta - ' . htmlspecialchars($event['name']);
$breadcrumbs = [
    ['label' => 'Events', 'url' => '/admin/events'],
    ['label' => htmlspecialchars($event['name']), 'url' => '/admin/events/edit/' . $eventId],
    ['label' => 'Karta']
];
$page_actions = '<a href="/admin/events/edit/' . $eventId . '" class="btn-admin btn-admin-secondary">Tillbaka</a>';

include __DIR__ . '/components/unified-layout.php';
?>

<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" crossorigin="">

<?php if ($message): ?>
<div class="alert alert-<?= $messageType === 'error' ? 'error' : 'success' ?>" style="margin-bottom: var(--space-md);">
    <?= htmlspecialchars($message) ?>
</div>
<?php endif; ?>

<div class="admin-grid admin-grid-sidebar">
    <!-- Sidebar: Controls -->
    <div class="admin-sidebar-narrow">
        <!-- Banor -->
        <div class="admin-card admin-card-compact">
            <div class="admin-card-header">
                <h2>Banor (<?= count($allTracks) ?>)</h2>
            </div>
            <div class="admin-card-body">
                <?php if (!empty($allTracks)): ?>
                <div class="admin-list admin-list-compact">
                    <?php foreach ($allTracks as $t): ?>
                    <div class="admin-list-item <?= $t['id'] == $selectedTrackId ? 'active' : '' ?>">
                        <span class="color-dot" style="background: <?= htmlspecialchars($t['color'] ?? '#3B82F6') ?>;"></span>
                        <span class="admin-list-item-text"><?= htmlspecialchars($t['route_label'] ?? $t['name']) ?></span>
                        <span class="admin-text-muted"><?= number_format($t['total_distance_km'], 1) ?>km</span>
                        <?php if ($t['id'] != $selectedTrackId): ?>
                        <a href="?id=<?= $eventId ?>&track=<?= $t['id'] ?>" class="btn-admin btn-admin-ghost btn-admin-xs">Välj</a>
                        <?php endif; ?>
                        <form method="POST" class="inline-form">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete_track">
                            <input type="hidden" name="track_id" value="<?= $t['id'] ?>">
                            <button type="submit" class="btn-admin btn-admin-ghost btn-admin-xs btn-admin-danger" onclick="return confirm('Ta bort?')">×</button>
                        </form>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <details class="admin-details" <?= empty($allTracks) ? 'open' : '' ?>>
                    <summary>+ Ladda upp GPX</summary>
                    <form method="POST" enctype="multipart/form-data" class="admin-form-compact">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="upload_gpx">
                        <div class="admin-form-group">
                            <input type="text" name="track_name" class="admin-form-input admin-form-input-sm" placeholder="Bannamn">
                        </div>
                        <div class="admin-form-row">
                            <select name="track_color" class="admin-form-select admin-form-select-sm">
                                <?php foreach ($trackColors as $hex => $name): ?>
                                <option value="<?= $hex ?>"><?= $name ?></option>
                                <?php endforeach; ?>
                            </select>
                            <label class="admin-checkbox-inline">
                                <input type="checkbox" name="is_primary" value="1" <?= empty($allTracks) ? 'checked' : '' ?>>
                                Primär
                            </label>
                        </div>
                        <div class="admin-form-group">
                            <input type="file" name="gpx_file" accept=".gpx" required class="admin-form-input admin-form-input-sm">
                        </div>
                        <button type="submit" class="btn-admin btn-admin-primary btn-admin-sm btn-admin-block">Ladda upp</button>
                    </form>
                </details>
            </div>
        </div>

        <?php if ($currentTrack): ?>
        <!-- Redigera bana -->
        <div class="admin-card admin-card-compact">
            <div class="admin-card-header">
                <h2>
                    <span class="color-dot" style="background: <?= htmlspecialchars($currentTrack['color'] ?? '#3B82F6') ?>;"></span>
                    <?= htmlspecialchars($currentTrack['route_label'] ?? $currentTrack['name']) ?>
                </h2>
            </div>
            <div class="admin-card-body">
                <details class="admin-details">
                    <summary>⚙️ Inställningar</summary>
                    <form method="POST" class="admin-form-compact">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="update_track">
                        <input type="hidden" name="track_id" value="<?= $currentTrack['id'] ?>">
                        <div class="admin-form-group">
                            <input type="text" name="track_name" class="admin-form-input admin-form-input-sm" value="<?= htmlspecialchars($currentTrack['name']) ?>">
                        </div>
                        <div class="admin-form-row">
                            <select name="track_color" class="admin-form-select admin-form-select-sm">
                                <?php foreach ($trackColors as $hex => $name): ?>
                                <option value="<?= $hex ?>" <?= ($currentTrack['color'] ?? '#3B82F6') === $hex ? 'selected' : '' ?>><?= $name ?></option>
                                <?php endforeach; ?>
                            </select>
                            <label class="admin-checkbox-inline">
                                <input type="checkbox" name="is_primary" value="1" <?= $currentTrack['is_primary'] ? 'checked' : '' ?>>
                                Primär
                            </label>
                        </div>
                        <button type="submit" class="btn-admin btn-admin-secondary btn-admin-sm btn-admin-block">Spara</button>
                    </form>
                </details>
            </div>
        </div>

        <!-- Sektioner -->
        <div class="admin-card admin-card-compact">
            <div class="admin-card-header">
                <h2>Sektioner (<?= count($currentTrack['segments']) ?>)</h2>
            </div>
            <div class="admin-card-body">
                <div id="segment-status" class="admin-status-box">Klicka på banan för att markera</div>

                <div class="segment-type-buttons">
                    <button type="button" class="section-type-btn active" data-type="liaison">🚴 Transport</button>
                    <button type="button" class="section-type-btn" data-type="stage">🏁 SS</button>
                    <button type="button" class="section-type-btn" data-type="lift">🚡 Lift</button>
                </div>

                <div id="pending-actions" class="admin-pending-box" style="display: none;">
                    <span id="pending-info">Dra markören för att justera</span>
                    <div class="admin-btn-group">
                        <button type="button" onclick="savePendingSegment()" class="btn-admin btn-admin-primary btn-admin-sm">✓ Spara</button>
                        <button type="button" onclick="cancelPendingSegment()" class="btn-admin btn-admin-secondary btn-admin-sm">✕ Avbryt</button>
                    </div>
                </div>

                <div class="admin-segment-list">
                    <?php if (!empty($currentTrack['segments'])): ?>
                    <?php foreach ($currentTrack['segments'] as $seg):
                        $icon = $seg['segment_type'] === 'stage' ? '🏁' : ($seg['segment_type'] === 'lift' ? '🚡' : '🚴');
                    ?>
                    <div class="admin-segment-item">
                        <span class="color-dot" style="background: <?= htmlspecialchars($seg['color']) ?>;"></span>
                        <span class="admin-segment-name"><?= $icon ?> <?= htmlspecialchars($seg['segment_name'] ?: 'Sektion ' . $seg['sequence_number']) ?></span>
                        <span class="admin-text-muted"><?= number_format($seg['distance_km'], 1) ?>km</span>
                        <select onchange="changeSegmentType(<?= $seg['id'] ?>, this.value)" class="admin-form-select admin-form-select-xs">
                            <option value="liaison" <?= $seg['segment_type'] === 'liaison' ? 'selected' : '' ?>>T</option>
                            <option value="stage" <?= $seg['segment_type'] === 'stage' ? 'selected' : '' ?>>SS</option>
                            <option value="lift" <?= $seg['segment_type'] === 'lift' ? 'selected' : '' ?>>L</option>
                        </select>
                        <form method="POST" class="inline-form">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete_segment">
                            <input type="hidden" name="segment_id" value="<?= $seg['id'] ?>">
                            <button type="submit" class="btn-admin btn-admin-ghost btn-admin-xs btn-admin-danger" onclick="return confirm('Ta bort?')">×</button>
                        </form>
                    </div>
                    <?php endforeach; ?>
                    <?php else: ?>
                    <p class="admin-text-muted admin-text-sm">Inga sektioner markerade</p>
                    <?php endif; ?>
                </div>

                <form method="POST" id="segment-form" style="display: none;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="add_segment_visual">
                    <input type="hidden" name="track_id" value="<?= $currentTrack['id'] ?>">
                    <input type="hidden" name="start_index" id="start-index" value="">
                    <input type="hidden" name="end_index" id="end-index" value="">
                    <input type="hidden" name="segment_type" id="segment-type" value="liaison">
                    <input type="hidden" name="segment_name" id="segment-name-hidden" value="">
                </form>
                <form method="POST" id="update-type-form" style="display: none;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="update_segment_type">
                    <input type="hidden" name="segment_id" id="update-seg-id" value="">
                    <input type="hidden" name="new_type" id="update-new-type" value="">
                </form>
            </div>
        </div>
        <?php endif; ?>

        <!-- POIs -->
        <div class="admin-card admin-card-compact">
            <div class="admin-card-header">
                <h2>POIs (<?= count($pois) ?>)</h2>
            </div>
            <div class="admin-card-body">
                <?php if (!empty($pois)): ?>
                <div class="admin-list admin-list-compact">
                    <?php foreach ($pois as $poi): ?>
                    <div class="admin-list-item">
                        <span><?= $poi['type_emoji'] ?? '📍' ?></span>
                        <span class="admin-list-item-text"><?= htmlspecialchars($poi['label'] ?: $poi['type_label'] ?? $poi['poi_type']) ?></span>
                        <form method="POST" class="inline-form">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete_poi">
                            <input type="hidden" name="poi_id" value="<?= $poi['id'] ?>">
                            <button type="submit" class="btn-admin btn-admin-ghost btn-admin-xs btn-admin-danger" onclick="return confirm('Ta bort?')">×</button>
                        </form>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <details class="admin-details">
                    <summary>+ Lägg till POI</summary>
                    <form method="POST" id="poi-form" class="admin-form-compact">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="add_poi">
                        <input type="hidden" name="poi_lat" id="poi-lat">
                        <input type="hidden" name="poi_lng" id="poi-lng">
                        <div class="admin-form-row">
                            <select name="poi_type" class="admin-form-select admin-form-select-sm" required>
                                <option value="">Typ...</option>
                                <?php foreach ($poiTypes as $key => $label): ?>
                                <option value="<?= htmlspecialchars($key) ?>"><?= htmlspecialchars($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <input type="text" name="poi_label" class="admin-form-input admin-form-input-sm" placeholder="Etikett">
                        </div>
                        <p class="admin-text-muted admin-text-sm">Klicka på kartan för position</p>
                        <button type="submit" id="poi-btn" disabled class="btn-admin btn-admin-primary btn-admin-sm btn-admin-block">Lägg till</button>
                    </form>
                </details>
            </div>
        </div>
    </div>

    <!-- Main: Map -->
    <div class="admin-main-content">
        <div class="admin-card">
            <div class="admin-card-body" style="padding: 0;">
                <div id="map" style="height: 600px;"></div>
            </div>
        </div>
    </div>
</div>

<style>
/* Compact map editor styles */
.admin-grid-sidebar {
    display: grid;
    grid-template-columns: 300px 1fr;
    gap: var(--space-lg);
}
.admin-sidebar-narrow {
    display: flex;
    flex-direction: column;
    gap: var(--space-md);
}
.admin-card-compact .admin-card-header {
    padding: var(--space-sm) var(--space-md);
}
.admin-card-compact .admin-card-header h2 {
    font-size: var(--text-sm);
    display: flex;
    align-items: center;
    gap: var(--space-sm);
}
.admin-card-compact .admin-card-body {
    padding: var(--space-md);
}
.color-dot {
    width: 10px;
    height: 10px;
    border-radius: 2px;
    flex-shrink: 0;
}
.admin-list-compact {
    display: flex;
    flex-direction: column;
    gap: var(--space-xs);
    margin-bottom: var(--space-md);
}
.admin-list-item {
    display: flex;
    align-items: center;
    gap: var(--space-sm);
    padding: var(--space-xs) var(--space-sm);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-sm);
    font-size: var(--text-sm);
}
.admin-list-item.active {
    border-color: var(--color-accent);
    background: rgba(97, 206, 112, 0.1);
}
.admin-list-item-text {
    flex: 1;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.admin-details {
    margin-top: var(--space-sm);
}
.admin-details summary {
    cursor: pointer;
    color: var(--color-accent);
    font-size: var(--text-sm);
    font-weight: var(--weight-medium);
}
.admin-details[open] summary {
    margin-bottom: var(--space-sm);
}
.admin-form-compact .admin-form-group {
    margin-bottom: var(--space-sm);
}
.admin-form-row {
    display: flex;
    gap: var(--space-sm);
    align-items: center;
    margin-bottom: var(--space-sm);
}
.admin-form-input-sm,
.admin-form-select-sm {
    padding: var(--space-xs) var(--space-sm);
    font-size: var(--text-sm);
}
.admin-form-select-xs {
    padding: 2px 4px;
    font-size: var(--text-xs);
    border-radius: var(--radius-sm);
}
.admin-checkbox-inline {
    display: flex;
    align-items: center;
    gap: var(--space-xs);
    font-size: var(--text-sm);
    white-space: nowrap;
}
.btn-admin-block {
    width: 100%;
}
.btn-admin-xs {
    padding: 2px 6px;
    font-size: var(--text-xs);
}
.inline-form {
    display: inline;
    margin: 0;
}
.admin-status-box {
    padding: var(--space-xs) var(--space-sm);
    background: var(--color-bg-sunken);
    border-radius: var(--radius-sm);
    font-size: var(--text-sm);
    margin-bottom: var(--space-sm);
}
.segment-type-buttons {
    display: flex;
    gap: var(--space-xs);
    margin-bottom: var(--space-sm);
}
.section-type-btn {
    flex: 1;
    padding: var(--space-xs) var(--space-sm);
    font-size: var(--text-xs);
    border: none;
    border-radius: var(--radius-sm);
    cursor: pointer;
    opacity: 0.6;
    transition: opacity 0.2s;
}
.section-type-btn.active {
    opacity: 1;
}
.section-type-btn[data-type="liaison"] { background: var(--color-success); color: white; }
.section-type-btn[data-type="stage"] { background: var(--color-danger); color: white; }
.section-type-btn[data-type="lift"] { background: var(--color-warning); color: white; }
.admin-pending-box {
    padding: var(--space-sm);
    background: rgba(59, 130, 246, 0.1);
    border-radius: var(--radius-sm);
    margin-bottom: var(--space-sm);
}
.admin-pending-box span {
    display: block;
    font-size: var(--text-sm);
    margin-bottom: var(--space-xs);
}
.admin-btn-group {
    display: flex;
    gap: var(--space-xs);
}
.admin-segment-list {
    max-height: 200px;
    overflow-y: auto;
    border: 1px solid var(--color-border);
    border-radius: var(--radius-sm);
}
.admin-segment-item {
    display: flex;
    align-items: center;
    gap: var(--space-sm);
    padding: var(--space-xs) var(--space-sm);
    border-bottom: 1px solid var(--color-border);
    font-size: var(--text-sm);
}
.admin-segment-item:last-child {
    border-bottom: none;
}
.admin-segment-name {
    flex: 1;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.admin-text-sm {
    font-size: var(--text-sm);
}
.admin-text-xs {
    font-size: var(--text-xs);
}
</style>

<!-- Elevation Profile -->
<?php if ($currentTrack): ?>
<div class="admin-card" style="margin-top: var(--space-lg);">
    <div class="admin-card-header">
        <h2>Höjdprofil</h2>
    </div>
    <div class="admin-card-body" style="padding: 0;">
        <canvas id="elevation-canvas" style="width: 100%; height: 150px;"></canvas>
    </div>
</div>
<?php endif; ?>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" crossorigin=""></script>
<script>
const mapData = <?= json_encode($mapData) ?>;
const waypoints = <?= json_encode($trackWaypoints) ?>;
const currentTrackId = <?= $selectedTrackId ?: 'null' ?>;
let map, startMarker, endMarker, previewLine, tempMarker, baseTrackLine;
let mode = 'start';
let startIdx = -1, endIdx = -1;

// Segment colors
const SEGMENT_COLORS = {
    stage: '#EF4444',    // Red
    liaison: '#61CE70',  // Green
    lift: '#F59E0B'      // Orange/Yellow
};

function init() {
    map = L.map('map').setView([62, 15], 5);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png').addTo(map);

    if (mapData && mapData.tracks) {
        // Draw all tracks
        mapData.tracks.forEach(track => {
            if (track.geojson && track.geojson.features) {
                // First draw base track (gray), then segments on top
                track.geojson.features.forEach(feature => {
                    const props = feature.properties;
                    const isBase = props.type === 'base_track';
                    const isCurrent = track.id === currentTrackId;

                    L.geoJSON(feature, {
                        style: () => ({
                            color: props.color || track.color,
                            weight: isBase ? 4 : 6,
                            opacity: isCurrent ? (isBase ? 0.6 : 1) : 0.4
                        })
                    }).addTo(map);
                });
            }
        });

        // Draw POIs
        if (mapData.pois) {
            mapData.pois.forEach(p => {
                L.marker([p.lat, p.lng])
                    .bindPopup((p.type_emoji || '') + ' ' + (p.label || p.type_label || p.poi_type))
                    .addTo(map);
            });
        }

        if (mapData.bounds) map.fitBounds(mapData.bounds, {padding: [30, 30]});
    }

    // Draw clickable base track for current track (for segment selection)
    if (waypoints && waypoints.length) {
        const coords = waypoints.map(w => [w.lat, w.lng]);
        baseTrackLine = L.polyline(coords, {
            color: '#3B82F6',
            weight: 8,
            opacity: 0.3,
            className: 'clickable-track'
        }).addTo(map);
        baseTrackLine.on('click', onTrackClick);
    }

    map.on('click', onMapClick);

    // Setup section type buttons
    document.querySelectorAll('.section-type-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            document.querySelectorAll('.section-type-btn').forEach(b => {
                b.classList.remove('active');
                b.style.opacity = '0.6';
            });
            btn.classList.add('active');
            btn.style.opacity = '1';
            currentSegmentType = btn.dataset.type;
            document.getElementById('segment-type').value = currentSegmentType;
        });
    });
}

// Section-based state
let sectionMarkers = [];
let sectionLines = [];
let currentSegmentType = 'liaison';

// Pending segment state (before saving)
let pendingMarker = null;
let pendingLine = null;
let pendingStartIdx = -1;
let pendingEndIdx = -1;
let pendingType = 'liaison';

// Find where the last existing segment ends (to continue from there)
let lastSectionIndex = 0;
// Count existing segments by type for auto-naming
let existingSegmentCounts = { stage: 0, liaison: 0, lift: 0 };
<?php
if ($currentTrack && !empty($currentTrack['segments'])) {
    $lastSegment = end($currentTrack['segments']);
    $lastEndIndex = $lastSegment['end_index'] ?? 0;
    echo "lastSectionIndex = " . intval($lastEndIndex) . ";\n";

    // Count existing segments by type
    $typeCounts = ['stage' => 0, 'liaison' => 0, 'lift' => 0];
    foreach ($currentTrack['segments'] as $seg) {
        $type = $seg['segment_type'] ?? 'liaison';
        if (isset($typeCounts[$type])) {
            $typeCounts[$type]++;
        }
    }
    echo "existingSegmentCounts = " . json_encode($typeCounts) . ";\n";
}
?>

function onTrackClick(e) {
    L.DomEvent.stopPropagation(e);
    const nearest = findNearest(e.latlng);
    if (!nearest) return;

    // Don't allow clicking before the last section point
    if (nearest.index <= lastSectionIndex && lastSectionIndex > 0) {
        document.getElementById('segment-status').innerHTML =
            '<span style="color: var(--color-danger);">Måste klicka längre fram på banan!</span>';
        return;
    }

    // If there's already a pending segment, save it first or cancel
    if (pendingMarker) {
        // Update the pending marker position instead
        updatePendingMarker(nearest);
        return;
    }

    // Create pending segment from lastSectionIndex to this point
    pendingStartIdx = lastSectionIndex;
    pendingEndIdx = nearest.index;
    pendingType = currentSegmentType;

    // Add draggable marker
    pendingMarker = L.marker([nearest.wp.lat, nearest.wp.lng], {
        draggable: true,
        icon: L.divIcon({
            className: 'segment-marker',
            html: `<div style="width: 20px; height: 20px; background: ${SEGMENT_COLORS[pendingType]}; border: 3px solid white; border-radius: 50%; box-shadow: 0 2px 6px rgba(0,0,0,0.4); cursor: grab;"></div>`,
            iconSize: [20, 20],
            iconAnchor: [10, 10]
        })
    }).addTo(map);

    // Handle marker drag
    pendingMarker.on('drag', onMarkerDrag);
    pendingMarker.on('dragend', onMarkerDragEnd);

    // Draw pending segment line
    drawPendingLine();

    // Show pending actions UI
    updatePendingUI();
}

function updatePendingMarker(nearest) {
    pendingEndIdx = nearest.index;
    pendingMarker.setLatLng([nearest.wp.lat, nearest.wp.lng]);
    drawPendingLine();
    updatePendingUI();
}

function onMarkerDrag(e) {
    // Find nearest waypoint while dragging
    const nearest = findNearest(e.latlng);
    if (nearest && nearest.index > pendingStartIdx) {
        pendingEndIdx = nearest.index;
        drawPendingLine();
        updatePendingUI();
    }
}

function onMarkerDragEnd(e) {
    // Snap to nearest waypoint
    const nearest = findNearest(e.target.getLatLng());
    if (nearest && nearest.index > pendingStartIdx) {
        pendingEndIdx = nearest.index;
        pendingMarker.setLatLng([nearest.wp.lat, nearest.wp.lng]);
        drawPendingLine();
        updatePendingUI();
    } else {
        // Reset to previous valid position
        const wp = waypoints[pendingEndIdx];
        pendingMarker.setLatLng([wp.lat, wp.lng]);
    }
}

function drawPendingLine() {
    // Remove old pending line
    if (pendingLine) {
        map.removeLayer(pendingLine);
    }

    // Draw new pending line
    const coords = waypoints.slice(pendingStartIdx, pendingEndIdx + 1).map(w => [w.lat, w.lng]);
    pendingLine = L.polyline(coords, {
        color: SEGMENT_COLORS[pendingType],
        weight: 6,
        opacity: 0.7,
        dashArray: '10, 10' // Dashed to show it's pending
    }).addTo(map);
}

function updatePendingUI() {
    const dist = (waypoints[pendingEndIdx].distance_km - waypoints[pendingStartIdx].distance_km).toFixed(2);
    const typeLabel = pendingType === 'stage' ? 'SS' : (pendingType === 'lift' ? 'Lift' : 'Transport');

    document.getElementById('pending-actions').style.display = 'block';
    document.getElementById('pending-info').innerHTML =
        `<strong>${typeLabel}</strong>: ${dist} km — Dra markören för att justera`;
    document.getElementById('segment-status').innerHTML =
        `<span style="color: ${SEGMENT_COLORS[pendingType]};">Förhandsvisning</span>`;
}

function savePendingSegment() {
    if (!pendingMarker) return;

    // Auto-generate name (count existing from DB + any added this session)
    let autoName = '';
    const dbCount = existingSegmentCounts[pendingType] || 0;
    const sessionCount = sectionMarkers.filter(m => m.type === pendingType).length;
    const totalOfType = dbCount + sessionCount;

    if (pendingType === 'stage') {
        autoName = 'SS' + (totalOfType + 1);
    } else if (pendingType === 'lift') {
        autoName = 'Lift ' + (totalOfType + 1);
    } else {
        autoName = 'Transport';
    }

    // Save segment to server via form
    document.getElementById('start-index').value = pendingStartIdx;
    document.getElementById('end-index').value = pendingEndIdx;
    document.getElementById('segment-type').value = pendingType;
    document.getElementById('segment-name-hidden').value = autoName;
    document.getElementById('segment-form').submit();
}

function cancelPendingSegment() {
    // Remove pending marker and line
    if (pendingMarker) {
        map.removeLayer(pendingMarker);
        pendingMarker = null;
    }
    if (pendingLine) {
        map.removeLayer(pendingLine);
        pendingLine = null;
    }

    // Reset state
    pendingStartIdx = -1;
    pendingEndIdx = -1;

    // Hide pending actions UI
    document.getElementById('pending-actions').style.display = 'none';
    document.getElementById('segment-status').innerHTML = 'Klicka på banan';
}

function onMapClick(e) {
    document.getElementById('poi-lat').value = e.latlng.lat;
    document.getElementById('poi-lng').value = e.latlng.lng;
    document.getElementById('poi-btn').disabled = false;
    if (tempMarker) map.removeLayer(tempMarker);
    tempMarker = L.circleMarker(e.latlng, {radius: 6, color: '#3B82F6', fillOpacity: 1}).addTo(map);
}

function findNearest(latlng) {
    if (!waypoints || !waypoints.length) return null;
    let best = {dist: Infinity};
    waypoints.forEach((wp, i) => {
        const d = latlng.distanceTo(L.latLng(wp.lat, wp.lng));
        if (d < best.dist) best = {index: i, wp: wp, dist: d};
    });
    return best.dist < 200 ? best : null;
}

function undoLastSection() {
    // This would need server-side support to delete the last segment
    // For now, just reload the page
    if (confirm('Vill du ta bort senaste sektionen? (Sidan laddas om)')) {
        const lastSegment = document.querySelector('[data-segment-id]:last-child form');
        if (lastSegment) {
            lastSegment.submit();
        } else {
            location.reload();
        }
    }
}

function resetAllSections() {
    if (confirm('Vill du ta bort ALLA sektioner och börja om?')) {
        location.reload();
    }
}

function changeSegmentType(segId, newType) {
    document.getElementById('update-seg-id').value = segId;
    document.getElementById('update-new-type').value = newType;
    document.getElementById('update-type-form').submit();
}

// Elevation profile rendering
function drawElevationProfile() {
    const canvas = document.getElementById('elevation-canvas');
    if (!canvas || !waypoints || waypoints.length < 2) return;

    const ctx = canvas.getContext('2d');
    const dpr = window.devicePixelRatio || 1;

    // Set canvas size based on container
    const rect = canvas.getBoundingClientRect();
    canvas.width = rect.width * dpr;
    canvas.height = rect.height * dpr;
    ctx.scale(dpr, dpr);

    const width = rect.width;
    const height = rect.height;
    const padding = { top: 20, right: 20, bottom: 30, left: 50 };
    const chartWidth = width - padding.left - padding.right;
    const chartHeight = height - padding.top - padding.bottom;

    // Extract elevation data
    const elevations = waypoints.map(w => w.elevation || 0);
    const distances = waypoints.map(w => w.distance_km || 0);

    if (elevations.every(e => e === 0)) {
        // No elevation data available
        ctx.fillStyle = '#7A7A7A';
        ctx.font = '12px system-ui, sans-serif';
        ctx.textAlign = 'center';
        ctx.fillText('Ingen höjddata tillgänglig', width / 2, height / 2);
        return;
    }

    const minEle = Math.min(...elevations);
    const maxEle = Math.max(...elevations);
    const eleRange = maxEle - minEle || 100;
    const maxDist = Math.max(...distances);

    // Clear canvas
    ctx.fillStyle = '#f8f9fa';
    ctx.fillRect(0, 0, width, height);

    // Draw grid lines
    ctx.strokeStyle = '#e5e7eb';
    ctx.lineWidth = 1;
    for (let i = 0; i <= 4; i++) {
        const y = padding.top + (chartHeight * i / 4);
        ctx.beginPath();
        ctx.moveTo(padding.left, y);
        ctx.lineTo(width - padding.right, y);
        ctx.stroke();
    }

    // Draw elevation profile with gradient fill
    ctx.beginPath();
    ctx.moveTo(padding.left, padding.top + chartHeight);

    waypoints.forEach((wp, i) => {
        const x = padding.left + (distances[i] / maxDist) * chartWidth;
        const y = padding.top + chartHeight - ((elevations[i] - minEle) / eleRange) * chartHeight;
        if (i === 0) {
            ctx.lineTo(x, y);
        } else {
            ctx.lineTo(x, y);
        }
    });

    ctx.lineTo(padding.left + chartWidth, padding.top + chartHeight);
    ctx.closePath();

    // Gradient fill
    const gradient = ctx.createLinearGradient(0, padding.top, 0, padding.top + chartHeight);
    gradient.addColorStop(0, 'rgba(97, 206, 112, 0.6)');
    gradient.addColorStop(1, 'rgba(97, 206, 112, 0.1)');
    ctx.fillStyle = gradient;
    ctx.fill();

    // Draw elevation line on top
    ctx.beginPath();
    waypoints.forEach((wp, i) => {
        const x = padding.left + (distances[i] / maxDist) * chartWidth;
        const y = padding.top + chartHeight - ((elevations[i] - minEle) / eleRange) * chartHeight;
        if (i === 0) {
            ctx.moveTo(x, y);
        } else {
            ctx.lineTo(x, y);
        }
    });
    ctx.strokeStyle = '#61CE70';
    ctx.lineWidth = 2;
    ctx.stroke();

    // Draw Y-axis labels (elevation)
    ctx.fillStyle = '#7A7A7A';
    ctx.font = '10px system-ui, sans-serif';
    ctx.textAlign = 'right';
    for (let i = 0; i <= 4; i++) {
        const ele = minEle + (eleRange * (4 - i) / 4);
        const y = padding.top + (chartHeight * i / 4);
        ctx.fillText(Math.round(ele) + 'm', padding.left - 5, y + 3);
    }

    // Draw X-axis labels (distance)
    ctx.textAlign = 'center';
    for (let i = 0; i <= 4; i++) {
        const dist = (maxDist * i / 4).toFixed(1);
        const x = padding.left + (chartWidth * i / 4);
        ctx.fillText(dist + 'km', x, height - 10);
    }

    // Draw stats
    const totalClimb = calculateClimb(elevations);
    ctx.fillStyle = '#171717';
    ctx.font = '11px system-ui, sans-serif';
    ctx.textAlign = 'left';
    ctx.fillText(`↑ ${Math.round(totalClimb)}m höjdmeter`, padding.left, 12);
    ctx.textAlign = 'right';
    ctx.fillText(`${Math.round(minEle)}m - ${Math.round(maxEle)}m`, width - padding.right, 12);
}

function calculateClimb(elevations) {
    let climb = 0;
    for (let i = 1; i < elevations.length; i++) {
        const diff = elevations[i] - elevations[i - 1];
        if (diff > 0) climb += diff;
    }
    return climb;
}

document.addEventListener('DOMContentLoaded', () => {
    init();
    drawElevationProfile();
});
window.addEventListener('resize', drawElevationProfile);
</script>

<?php include __DIR__ . '/components/unified-layout-footer.php'; ?>
