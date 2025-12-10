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

<div class="admin-grid admin-grid-2">
    <!-- Left: Controls -->
    <div>
        <!-- All Tracks -->
        <div class="admin-card">
            <div class="admin-card-header"><h2>Banor (<?= count($allTracks) ?>)</h2></div>
            <div class="admin-card-body">
                <?php if (!empty($allTracks)): ?>
                <div style="display: flex; flex-direction: column; gap: var(--space-sm); margin-bottom: var(--space-md);">
                    <?php foreach ($allTracks as $t): ?>
                    <div style="display: flex; align-items: center; gap: var(--space-sm); padding: var(--space-sm); border: 2px solid <?= $t['id'] == $selectedTrackId ? 'var(--color-accent)' : 'var(--color-border)' ?>; border-radius: var(--radius-sm); background: <?= $t['id'] == $selectedTrackId ? 'var(--color-accent-light, rgba(97,206,112,0.1))' : 'transparent' ?>;">
                        <span style="width: 16px; height: 16px; background: <?= htmlspecialchars($t['color'] ?? '#3B82F6') ?>; border-radius: 3px; flex-shrink: 0;"></span>
                        <div style="flex: 1; min-width: 0;">
                            <div style="font-weight: 500;"><?= htmlspecialchars($t['route_label'] ?? $t['name']) ?></div>
                            <div class="admin-text-muted" style="font-size: 0.85em;">
                                <?= number_format($t['total_distance_km'], 1) ?> km
                                <?php if ($t['is_primary']): ?><span style="color: var(--color-accent);">• Primär</span><?php endif; ?>
                            </div>
                        </div>
                        <?php if ($t['id'] != $selectedTrackId): ?>
                        <a href="?id=<?= $eventId ?>&track=<?= $t['id'] ?>" class="btn-admin btn-admin-ghost btn-admin-sm">Välj</a>
                        <?php endif; ?>
                        <form method="POST" style="margin: 0;">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete_track">
                            <input type="hidden" name="track_id" value="<?= $t['id'] ?>">
                            <button type="submit" class="btn-admin btn-admin-ghost btn-admin-sm" style="color: var(--color-danger);" onclick="return confirm('Ta bort banan?')">×</button>
                        </form>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <p class="admin-text-muted">Inga banor uppladdade än.</p>
                <?php endif; ?>

                <!-- Upload new track -->
                <details style="margin-top: var(--space-md);">
                    <summary style="cursor: pointer; font-weight: 500; color: var(--color-accent);">+ Lägg till ny bana</summary>
                    <form method="POST" enctype="multipart/form-data" style="margin-top: var(--space-md);">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="upload_gpx">
                        <div class="admin-form-group">
                            <label class="admin-form-label">Bannamn</label>
                            <input type="text" name="track_name" class="admin-form-input" placeholder="t.ex. Elite 45km">
                        </div>
                        <div class="admin-form-group">
                            <label class="admin-form-label">Etikett (visas i dropdown)</label>
                            <input type="text" name="route_label" class="admin-form-input" placeholder="t.ex. Elite">
                        </div>
                        <div class="admin-form-group">
                            <label class="admin-form-label">Färg</label>
                            <select name="track_color" class="admin-form-select">
                                <?php foreach ($trackColors as $hex => $name): ?>
                                <option value="<?= $hex ?>" style="background: <?= $hex ?>; color: white;"><?= $name ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="admin-form-group">
                            <label style="display: flex; align-items: center; gap: var(--space-sm);">
                                <input type="checkbox" name="is_primary" value="1">
                                <span>Primär bana (visas först)</span>
                            </label>
                        </div>
                        <div class="admin-form-group">
                            <label class="admin-form-label">GPX-fil</label>
                            <input type="file" name="gpx_file" accept=".gpx" required class="admin-form-input">
                        </div>
                        <button type="submit" class="btn-admin btn-admin-primary">Ladda upp</button>
                    </form>
                </details>
            </div>
        </div>

        <?php if ($currentTrack): ?>
        <!-- Edit Current Track -->
        <div class="admin-card" style="margin-top: var(--space-lg);">
            <div class="admin-card-header">
                <h2 style="display: flex; align-items: center; gap: var(--space-sm);">
                    <span style="width: 16px; height: 16px; background: <?= htmlspecialchars($currentTrack['color'] ?? '#3B82F6') ?>; border-radius: 3px;"></span>
                    <?= htmlspecialchars($currentTrack['route_label'] ?? $currentTrack['name']) ?>
                </h2>
            </div>
            <div class="admin-card-body">
                <form method="POST">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="update_track">
                    <input type="hidden" name="track_id" value="<?= $currentTrack['id'] ?>">
                    <div class="admin-form-group">
                        <label class="admin-form-label">Namn</label>
                        <input type="text" name="track_name" class="admin-form-input" value="<?= htmlspecialchars($currentTrack['name']) ?>">
                    </div>
                    <div class="admin-form-group">
                        <label class="admin-form-label">Etikett</label>
                        <input type="text" name="route_label" class="admin-form-input" value="<?= htmlspecialchars($currentTrack['route_label'] ?? '') ?>">
                    </div>
                    <div class="admin-form-group">
                        <label class="admin-form-label">Färg</label>
                        <select name="track_color" class="admin-form-select">
                            <?php foreach ($trackColors as $hex => $name): ?>
                            <option value="<?= $hex ?>" <?= ($currentTrack['color'] ?? '#3B82F6') === $hex ? 'selected' : '' ?>><?= $name ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="admin-form-group">
                        <label style="display: flex; align-items: center; gap: var(--space-sm);">
                            <input type="checkbox" name="is_primary" value="1" <?= $currentTrack['is_primary'] ? 'checked' : '' ?>>
                            <span>Primär bana</span>
                        </label>
                    </div>
                    <button type="submit" class="btn-admin btn-admin-secondary btn-admin-sm">Uppdatera</button>
                </form>
            </div>
        </div>

        <!-- Segment Legend -->
        <div class="admin-card" style="margin-top: var(--space-lg);">
            <div class="admin-card-header"><h2>Sträcktyper</h2></div>
            <div class="admin-card-body">
                <div style="display: flex; flex-wrap: wrap; gap: var(--space-md);">
                    <div style="display: flex; align-items: center; gap: var(--space-xs);">
                        <span style="width: 20px; height: 4px; background: #61CE70; border-radius: 2px;"></span>
                        <span class="admin-text-muted">Transport (standard)</span>
                    </div>
                    <div style="display: flex; align-items: center; gap: var(--space-xs);">
                        <span style="width: 20px; height: 4px; background: #EF4444; border-radius: 2px;"></span>
                        <span>SS (Tävling)</span>
                    </div>
                    <div style="display: flex; align-items: center; gap: var(--space-xs);">
                        <span style="width: 20px; height: 4px; background: #F59E0B; border-radius: 2px;"></span>
                        <span>Lift</span>
                    </div>
                </div>
                <p class="admin-text-muted" style="margin-top: var(--space-sm); font-size: 0.85em;">
                    Hela banan visas som transport (grön). Markera sektioner som SS eller Lift.
                </p>
            </div>
        </div>

        <!-- Sektioner - kompakt lista med redigering -->
        <div class="admin-card" style="margin-top: var(--space-md);">
            <div class="admin-card-header" style="padding: var(--space-sm) var(--space-md);">
                <div style="display: flex; justify-content: space-between; align-items: center;">
                    <h2 style="font-size: 0.95rem; margin: 0;">Sektioner (<?= count($currentTrack['segments']) ?>)</h2>
                    <span id="segment-status" style="font-size: 0.8rem; color: var(--color-primary);">Klicka på banan</span>
                </div>
            </div>
            <div class="admin-card-body" style="padding: var(--space-sm);">
                <!-- Typ-väljare (kompakt) -->
                <div style="display: flex; gap: 4px; margin-bottom: var(--space-sm);">
                    <button type="button" class="section-type-btn active" data-type="liaison" style="flex:1; padding: 6px; font-size: 0.75rem; background: #61CE70; color: white; border: none; border-radius: 4px; cursor: pointer;">🚴 Transport</button>
                    <button type="button" class="section-type-btn" data-type="stage" style="flex:1; padding: 6px; font-size: 0.75rem; background: #EF4444; color: white; border: none; border-radius: 4px; cursor: pointer; opacity: 0.5;">🏁 SS</button>
                    <button type="button" class="section-type-btn" data-type="lift" style="flex:1; padding: 6px; font-size: 0.75rem; background: #F59E0B; color: white; border: none; border-radius: 4px; cursor: pointer; opacity: 0.5;">🚡 Lift</button>
                </div>

                <!-- Segment-lista med inline redigering -->
                <div style="max-height: 180px; overflow-y: auto; border: 1px solid var(--color-border); border-radius: 4px;">
                    <?php if (!empty($currentTrack['segments'])): ?>
                    <?php foreach ($currentTrack['segments'] as $seg):
                        $icon = $seg['segment_type'] === 'stage' ? '🏁' : ($seg['segment_type'] === 'lift' ? '🚡' : '🚴');
                    ?>
                    <div class="seg-row" style="display: flex; align-items: center; gap: 6px; padding: 6px 8px; border-bottom: 1px solid var(--color-border); font-size: 0.85rem;">
                        <span style="width: 8px; height: 8px; background: <?= htmlspecialchars($seg['color']) ?>; border-radius: 2px; flex-shrink: 0;"></span>
                        <span style="flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                            <?= $icon ?> <?= htmlspecialchars($seg['segment_name'] ?: 'Sektion ' . $seg['sequence_number']) ?>
                        </span>
                        <span style="color: var(--color-text); font-size: 0.75rem;"><?= number_format($seg['distance_km'], 1) ?>km</span>
                        <!-- Ändra typ dropdown -->
                        <select onchange="changeSegmentType(<?= $seg['id'] ?>, this.value)" style="padding: 2px 4px; font-size: 0.7rem; border: 1px solid var(--color-border); border-radius: 3px; background: white;">
                            <option value="liaison" <?= $seg['segment_type'] === 'liaison' ? 'selected' : '' ?>>Transport</option>
                            <option value="stage" <?= $seg['segment_type'] === 'stage' ? 'selected' : '' ?>>SS</option>
                            <option value="lift" <?= $seg['segment_type'] === 'lift' ? 'selected' : '' ?>>Lift</option>
                        </select>
                        <form method="POST" style="margin: 0;">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete_segment">
                            <input type="hidden" name="segment_id" value="<?= $seg['id'] ?>">
                            <button type="submit" style="background: none; border: none; color: var(--color-danger); cursor: pointer; padding: 2px 4px; font-size: 0.8rem;" onclick="return confirm('Ta bort?')">×</button>
                        </form>
                    </div>
                    <?php endforeach; ?>
                    <?php else: ?>
                    <p style="padding: var(--space-sm); margin: 0; color: var(--color-text); font-size: 0.85rem;">Inga sektioner. Klicka på banan!</p>
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
        <div class="admin-card" style="margin-top: var(--space-lg);">
            <div class="admin-card-header"><h2>POIs (<?= count($pois) ?>)</h2></div>
            <div class="admin-card-body">
                <?php if (!empty($pois)): ?>
                <?php foreach ($pois as $poi): ?>
                <div style="display: flex; align-items: center; gap: var(--space-sm); padding: var(--space-xs) 0;">
                    <span><?= $poi['type_emoji'] ?? '📍' ?></span>
                    <span style="flex: 1;"><?= htmlspecialchars($poi['label'] ?: $poi['type_label'] ?? $poi['poi_type']) ?></span>
                    <form method="POST" style="margin: 0;">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="delete_poi">
                        <input type="hidden" name="poi_id" value="<?= $poi['id'] ?>">
                        <button type="submit" class="btn-admin btn-admin-ghost btn-admin-sm" onclick="return confirm('Ta bort?')">×</button>
                    </form>
                </div>
                <?php endforeach; ?>
                <hr style="margin: var(--space-md) 0;">
                <?php endif; ?>
                <p class="admin-text-muted">Klicka på kartan för att lägga till POI</p>
                <form method="POST" id="poi-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="add_poi">
                    <input type="hidden" name="poi_lat" id="poi-lat">
                    <input type="hidden" name="poi_lng" id="poi-lng">
                    <div class="admin-form-group">
                        <select name="poi_type" class="admin-form-select" required>
                            <option value="">Välj typ...</option>
                            <?php foreach ($poiTypes as $key => $label): ?>
                            <option value="<?= htmlspecialchars($key) ?>"><?= htmlspecialchars($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="admin-form-group">
                        <input type="text" name="poi_label" class="admin-form-input" placeholder="Etikett (valfritt)">
                    </div>
                    <button type="submit" id="poi-btn" disabled class="btn-admin btn-admin-primary">Lägg till POI</button>
                </form>
            </div>
        </div>
    </div>

    <!-- Right: Map -->
    <div>
        <div class="admin-card">
            <div class="admin-card-body" style="padding: 0;">
                <div id="map" style="height: 600px; border-radius: var(--radius-md);"></div>
            </div>
        </div>
    </div>
</div>

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

// Find where the last existing segment ends (to continue from there)
let lastSectionIndex = 0;
<?php
if ($currentTrack && !empty($currentTrack['segments'])) {
    $lastSegment = end($currentTrack['segments']);
    $lastEndIndex = $lastSegment['end_index'] ?? 0;
    echo "lastSectionIndex = " . intval($lastEndIndex) . ";\n";
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

    // Create segment from lastSectionIndex to this point
    const startIdx = lastSectionIndex;
    const endIdx = nearest.index;

    // Add section marker
    const marker = L.circleMarker([nearest.wp.lat, nearest.wp.lng], {
        radius: 8,
        color: SEGMENT_COLORS[currentSegmentType],
        fillColor: SEGMENT_COLORS[currentSegmentType],
        fillOpacity: 1,
        weight: 2
    }).addTo(map);
    sectionMarkers.push({ marker, index: endIdx, type: currentSegmentType });

    // Draw segment line
    const coords = waypoints.slice(startIdx, endIdx + 1).map(w => [w.lat, w.lng]);
    const line = L.polyline(coords, {
        color: SEGMENT_COLORS[currentSegmentType],
        weight: 6,
        opacity: 0.9
    }).addTo(map);
    sectionLines.push(line);

    // Calculate distance
    const dist = (waypoints[endIdx].distance_km - waypoints[startIdx].distance_km).toFixed(2);

    // Auto-generate name
    let autoName = '';
    if (currentSegmentType === 'stage') {
        const ssCount = sectionMarkers.filter(m => m.type === 'stage').length;
        autoName = 'SS' + ssCount;
    } else if (currentSegmentType === 'lift') {
        const liftCount = sectionMarkers.filter(m => m.type === 'lift').length;
        autoName = 'Lift ' + liftCount;
    } else {
        autoName = 'Transport';
    }

    // Save segment to server via form
    document.getElementById('start-index').value = startIdx;
    document.getElementById('end-index').value = endIdx;
    document.getElementById('segment-name-hidden').value = autoName;
    document.getElementById('segment-form').submit();

    // Update state for next section
    lastSectionIndex = endIdx;

    // Update status
    const typeLabel = currentSegmentType === 'stage' ? 'SS' : (currentSegmentType === 'lift' ? 'Lift' : 'Transport');
    document.getElementById('segment-status').innerHTML =
        `<span style="color: ${SEGMENT_COLORS[currentSegmentType]};">${typeLabel} sparad (${dist} km)</span> - Klicka för nästa sektion`;
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

document.addEventListener('DOMContentLoaded', init);
</script>

<?php include __DIR__ . '/components/unified-layout-footer.php'; ?>
