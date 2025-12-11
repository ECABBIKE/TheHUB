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

            case 'save_all_segments':
                $trackId = intval($_POST['track_id'] ?? 0);
                $segmentsJson = $_POST['segments_data'] ?? '[]';
                $segments = json_decode($segmentsJson, true);

                if ($trackId <= 0 || empty($segments)) {
                    throw new Exception('Ingen segmentdata');
                }

                // Delete existing segments for this track
                $pdo->prepare("DELETE FROM event_track_segments WHERE track_id = ?")->execute([$trackId]);

                // Add all new segments
                $ssCount = 0;
                $liftCount = 0;
                foreach ($segments as $seg) {
                    $type = $seg['type'] ?? 'liaison';
                    $name = '';
                    if ($type === 'stage') {
                        $ssCount++;
                        $name = 'SS' . $ssCount;
                    } elseif ($type === 'lift') {
                        $liftCount++;
                        $name = 'Lift ' . $liftCount;
                    } else {
                        $name = 'Transport';
                    }

                    addSegmentByWaypointIndex($pdo, $trackId, [
                        'name' => $name,
                        'type' => $type,
                        'start_index' => intval($seg['start']),
                        'end_index' => intval($seg['end'])
                    ]);
                }

                $message = count($segments) . ' sektioner sparade!';
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

        <!-- Sektioner - Multi-point editor -->
        <div class="admin-card admin-card-compact">
            <div class="admin-card-header">
                <h2>Sektioner</h2>
            </div>
            <div class="admin-card-body">
                <div id="segment-status" class="admin-status-box">
                    <strong>Steg 1:</strong> Klicka på banan för att sätta ut delningspunkter
                </div>

                <div id="markers-info" class="admin-info-box" style="display: none;">
                    <span id="markers-count">0 punkter</span>
                    <button type="button" onclick="clearAllMarkers()" class="btn-admin btn-admin-ghost btn-admin-xs">Rensa alla</button>
                </div>

                <!-- Segment list - dynamically updated -->
                <div id="segments-list" class="admin-segment-list">
                    <p class="admin-text-muted admin-text-sm">Sätt ut minst 1 punkt för att skapa sektioner</p>
                </div>

                <!-- Save button -->
                <div id="save-actions" style="display: none; margin-top: var(--space-sm);">
                    <button type="button" onclick="saveAllSegments()" class="btn-admin btn-admin-primary btn-admin-block">
                        <i data-lucide="save" style="width: 14px; height: 14px;"></i> Spara alla sektioner
                    </button>
                </div>

                <!-- Existing segments (saved) -->
                <?php if (!empty($currentTrack['segments'])): ?>
                <div class="admin-existing-segments" style="margin-top: var(--space-md); padding-top: var(--space-md); border-top: 1px solid var(--color-border);">
                    <div class="admin-text-muted admin-text-sm" style="margin-bottom: var(--space-xs);">Sparade sektioner:</div>
                    <?php foreach ($currentTrack['segments'] as $seg):
                        $iconName = $seg['segment_type'] === 'stage' ? 'flag' : ($seg['segment_type'] === 'lift' ? 'cable-car' : 'route');
                    ?>
                    <div class="admin-segment-item">
                        <span class="color-dot" style="background: <?= htmlspecialchars($seg['color']) ?>;"></span>
                        <span class="admin-segment-name"><i data-lucide="<?= $iconName ?>" style="width: 14px; height: 14px;"></i> <?= htmlspecialchars($seg['segment_name'] ?: 'Sektion ' . $seg['sequence_number']) ?></span>
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
                    <button type="button" onclick="editExistingSegments()" class="btn-admin btn-admin-secondary btn-admin-sm btn-admin-block" style="margin-top: var(--space-sm);">
                        <i data-lucide="pencil" style="width: 14px; height: 14px;"></i> Redigera sektioner
                    </button>
                </div>
                <?php endif; ?>

                <!-- Hidden form for saving -->
                <form method="POST" id="save-segments-form" style="display: none;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="save_all_segments">
                    <input type="hidden" name="track_id" value="<?= $currentTrack['id'] ?>">
                    <input type="hidden" name="segments_data" id="segments-data" value="[]">
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
                        <i data-lucide="<?= htmlspecialchars($poi['type_icon'] ?? 'map-pin') ?>" style="width: 14px; height: 14px;"></i>
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
                <div id="map" style="height: calc(100vh - 280px); min-height: 500px;"></div>
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
.admin-info-box {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: var(--space-xs) var(--space-sm);
    background: rgba(59, 130, 246, 0.1);
    border-radius: var(--radius-sm);
    font-size: var(--text-sm);
    margin-bottom: var(--space-sm);
}
.segment-type-select {
    padding: 2px 4px;
    font-size: var(--text-xs);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-sm);
    background: white;
}
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
    transition: background-color 0.15s ease;
}
.admin-segment-item:hover {
    background-color: var(--color-star-fade);
}
.admin-segment-item:last-child {
    border-bottom: none;
}
.admin-segment-name {
    flex: 1;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    display: flex;
    align-items: center;
    gap: var(--space-xs);
}
.admin-segment-name svg {
    flex-shrink: 0;
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
<div class="admin-card" id="elevation-card" style="margin-top: var(--space-lg);">
    <div class="admin-card-header" style="display: flex; justify-content: space-between; align-items: center;">
        <h2 id="elevation-title">Höjdprofil</h2>
        <button type="button" onclick="showFullTrack()" class="btn btn-ghost btn-sm" style="display: flex; align-items: center; gap: var(--space-xs);">
            <i data-lucide="maximize-2" style="width: 14px; height: 14px;"></i>
            Visa hela
        </button>
    </div>
    <div class="admin-card-body" style="padding: var(--space-sm);">
        <canvas id="elevation-canvas" style="width: 100%; height: 150px; display: block;"></canvas>
    </div>
</div>
<?php endif; ?>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" crossorigin=""></script>
<script>
const mapData = <?= json_encode($mapData) ?>;
const waypoints = <?= json_encode($trackWaypoints) ?>;
const currentTrackId = <?= $selectedTrackId ?: 'null' ?>;
let map, baseTrackLine, tempMarker;

// Segment colors
const SEGMENT_COLORS = {
    stage: '#EF4444',    // Red
    liaison: '#61CE70',  // Green
    lift: '#F59E0B'      // Orange/Yellow
};

// Multi-point editor state
let splitMarkers = [];      // Array of {marker, index, id}
let segmentLines = [];      // Array of polylines between markers
let segmentTypes = [];      // Array of segment types (liaison/stage/lift)
let segmentBounds = [];     // Array of bounds for each segment (for zoom)

// Icon SVGs for segment types (no emojis!)
const SEGMENT_ICONS = {
    liaison: '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="5" r="3"/><path d="M12 8v4"/><path d="m5 21 7-4 7 4"/><path d="M12 12v5"/></svg>',
    stage: '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z"/><line x1="4" x2="4" y1="22" y2="15"/></svg>',
    lift: '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16"/><path d="M6 4v16"/><path d="M18 4v16"/><rect x="8" y="8" width="8" height="6" rx="1"/></svg>'
};

function init() {
    map = L.map('map').setView([62, 15], 5);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png').addTo(map);

    if (mapData && mapData.tracks) {
        mapData.tracks.forEach(track => {
            if (track.geojson && track.geojson.features) {
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

        if (mapData.pois) {
            mapData.pois.forEach(p => {
                L.marker([p.lat, p.lng])
                    .bindPopup(p.label || p.type_label || p.poi_type)
                    .addTo(map);
            });
        }

        if (mapData.bounds) map.fitBounds(mapData.bounds, {padding: [30, 30]});
    }

    // Draw invisible clickable track for segment selection (wide hit area)
    if (waypoints && waypoints.length) {
        const coords = waypoints.map(w => [w.lat, w.lng]);
        baseTrackLine = L.polyline(coords, {
            color: 'transparent',
            weight: 20,  // Wide for easy clicking
            opacity: 0,
            className: 'clickable-track'
        }).addTo(map);
        baseTrackLine.on('click', onTrackClick);
    }

    map.on('click', onMapClick);
}

// Handle click on track - add a split point
function onTrackClick(e) {
    L.DomEvent.stopPropagation(e);
    const nearest = findNearest(e.latlng);
    if (!nearest) return;

    // Check if there's already a marker very close to this index
    const existingIdx = splitMarkers.findIndex(m => Math.abs(m.index - nearest.index) < 20);
    if (existingIdx >= 0) {
        // Remove that marker instead of adding new
        removeMarker(existingIdx);
        return;
    }

    // Add new split marker
    addMarker(nearest.index, nearest.wp);
}

function addMarker(index, wp) {
    const markerId = Date.now();

    const marker = L.marker([wp.lat, wp.lng], {
        draggable: true,
        icon: L.divIcon({
            className: 'split-marker',
            html: `<div style="width: 24px; height: 24px; background: #3B82F6; border: 3px solid white; border-radius: 50%; box-shadow: 0 2px 8px rgba(0,0,0,0.4); cursor: grab; display: flex; align-items: center; justify-content: center; color: white; font-size: 12px; font-weight: bold;">${splitMarkers.length + 1}</div>`,
            iconSize: [24, 24],
            iconAnchor: [12, 12]
        })
    }).addTo(map);

    marker.on('drag', (e) => onMarkerDrag(markerId, e));
    marker.on('dragend', (e) => onMarkerDragEnd(markerId, e));

    // Insert marker in sorted position
    const newMarker = { marker, index, id: markerId };
    splitMarkers.push(newMarker);
    splitMarkers.sort((a, b) => a.index - b.index);

    // Default type for new segment is liaison
    updateSegmentTypes();
    updateUI();
    drawSegmentLines();
}

function removeMarker(idx) {
    const m = splitMarkers[idx];
    if (m) {
        map.removeLayer(m.marker);
        splitMarkers.splice(idx, 1);
    }
    updateSegmentTypes();
    updateUI();
    drawSegmentLines();
    updateMarkerNumbers();
}

function updateMarkerNumbers() {
    splitMarkers.forEach((m, i) => {
        const el = m.marker.getElement();
        if (el) {
            const div = el.querySelector('div');
            if (div) div.textContent = i + 1;
        }
    });
}

function onMarkerDrag(markerId, e) {
    const nearest = findNearest(e.latlng);
    if (nearest) {
        const idx = splitMarkers.findIndex(m => m.id === markerId);
        if (idx >= 0) {
            // Check bounds - can't go past neighbors
            const prevIdx = idx > 0 ? splitMarkers[idx - 1].index : -1;
            const nextIdx = idx < splitMarkers.length - 1 ? splitMarkers[idx + 1].index : waypoints.length;

            if (nearest.index > prevIdx && nearest.index < nextIdx) {
                splitMarkers[idx].index = nearest.index;
                drawSegmentLines();
                updateSegmentListDistances();
            }
        }
    }
}

function onMarkerDragEnd(markerId, e) {
    const nearest = findNearest(e.target.getLatLng());
    const idx = splitMarkers.findIndex(m => m.id === markerId);

    if (nearest && idx >= 0) {
        const prevIdx = idx > 0 ? splitMarkers[idx - 1].index : -1;
        const nextIdx = idx < splitMarkers.length - 1 ? splitMarkers[idx + 1].index : waypoints.length;

        if (nearest.index > prevIdx && nearest.index < nextIdx) {
            splitMarkers[idx].index = nearest.index;
            splitMarkers[idx].marker.setLatLng([nearest.wp.lat, nearest.wp.lng]);
        } else {
            // Snap back to previous valid position
            const wp = waypoints[splitMarkers[idx].index];
            splitMarkers[idx].marker.setLatLng([wp.lat, wp.lng]);
        }
    }
    drawSegmentLines();
    updateUI();
}

function updateSegmentTypes() {
    // Ensure we have the right number of segment types
    const numSegments = splitMarkers.length + 1; // segments = markers + 1
    while (segmentTypes.length < numSegments) {
        segmentTypes.push('liaison'); // Default to transport
    }
    while (segmentTypes.length > numSegments) {
        segmentTypes.pop();
    }
}

function drawSegmentLines() {
    // Remove old lines
    segmentLines.forEach(line => map.removeLayer(line));
    segmentLines = [];
    segmentBounds = [];

    if (splitMarkers.length === 0) return;

    // Build segment ranges
    const ranges = [];
    let start = 0;

    for (let i = 0; i <= splitMarkers.length; i++) {
        const end = i < splitMarkers.length ? splitMarkers[i].index : waypoints.length - 1;
        const type = segmentTypes[i] || 'liaison';

        if (end > start) {
            const coords = waypoints.slice(start, end + 1).map(w => [w.lat, w.lng]);
            const line = L.polyline(coords, {
                color: SEGMENT_COLORS[type],
                weight: 6,
                opacity: 0.9,
                interactive: false  // Let clicks pass through to base track
            }).addTo(map);
            segmentLines.push(line);
            segmentBounds.push(L.latLngBounds(coords));
            ranges.push({ start, end, type });
        }

        start = end;
    }
}

function updateUI() {
    const markersInfo = document.getElementById('markers-info');
    const segmentsList = document.getElementById('segments-list');
    const saveActions = document.getElementById('save-actions');
    const statusBox = document.getElementById('segment-status');

    if (splitMarkers.length === 0) {
        markersInfo.style.display = 'none';
        saveActions.style.display = 'none';
        segmentsList.innerHTML = '<p class="admin-text-muted admin-text-sm">Sätt ut minst 1 punkt för att skapa sektioner</p>';
        statusBox.innerHTML = '<strong>Steg 1:</strong> Klicka på banan för att sätta ut delningspunkter';
        return;
    }

    markersInfo.style.display = 'flex';
    document.getElementById('markers-count').textContent = splitMarkers.length + ' punkt' + (splitMarkers.length > 1 ? 'er' : '');

    // Build segment list
    let html = '';
    let start = 0;

    for (let i = 0; i <= splitMarkers.length; i++) {
        const end = i < splitMarkers.length ? splitMarkers[i].index : waypoints.length - 1;
        const type = segmentTypes[i] || 'liaison';
        const dist = (waypoints[end].distance_km - waypoints[start].distance_km).toFixed(1);

        html += `
        <div class="admin-segment-item" data-segment-idx="${i}" onclick="zoomToSegment(${i})" style="cursor: pointer;">
            <span class="color-dot" style="background: ${SEGMENT_COLORS[type]};"></span>
            <span class="admin-segment-name">${SEGMENT_ICONS[type]} Sektion ${i + 1}</span>
            <span class="admin-text-muted">${dist}km</span>
            <select onclick="event.stopPropagation()" onchange="changeType(${i}, this.value)" class="segment-type-select">
                <option value="liaison" ${type === 'liaison' ? 'selected' : ''}>T</option>
                <option value="stage" ${type === 'stage' ? 'selected' : ''}>SS</option>
                <option value="lift" ${type === 'lift' ? 'selected' : ''}>L</option>
            </select>
        </div>`;

        start = end;
    }

    segmentsList.innerHTML = html;
    saveActions.style.display = 'block';
    statusBox.innerHTML = '<strong>Steg 2:</strong> Välj typ för varje sektion, sen spara';
}

function zoomToSegment(idx) {
    if (segmentBounds[idx]) {
        map.fitBounds(segmentBounds[idx], { padding: [50, 50] });

        // Update elevation profile for this segment
        let start = 0;
        for (let i = 0; i < idx; i++) {
            start = splitMarkers[i].index;
        }
        const end = idx < splitMarkers.length ? splitMarkers[idx].index : waypoints.length - 1;
        const type = segmentTypes[idx] || 'liaison';
        const typeLabel = type === 'stage' ? 'SS' : (type === 'lift' ? 'Lift' : 'Transport');
        drawElevationProfile(start, end, `Sektion ${idx + 1} (${typeLabel})`);

        // Highlight selected segment in list
        document.querySelectorAll('.admin-segment-item').forEach((item, i) => {
            item.style.background = i === idx ? 'var(--color-star-fade)' : '';
        });
    }
}

function showFullTrack() {
    // Zoom to full track
    if (baseTrackLine) {
        map.fitBounds(baseTrackLine.getBounds(), { padding: [50, 50] });
    }
    // Show full elevation profile
    drawElevationProfile(0, null, 'Hela banan');
    // Remove highlight
    document.querySelectorAll('.admin-segment-item').forEach(item => {
        item.style.background = '';
    });
}

function updateSegmentListDistances() {
    // Quick update of distances during drag
    let start = 0;
    const items = document.querySelectorAll('[data-segment-idx]');

    items.forEach((item, i) => {
        const end = i < splitMarkers.length ? splitMarkers[i].index : waypoints.length - 1;
        const dist = (waypoints[end].distance_km - waypoints[start].distance_km).toFixed(1);
        const distSpan = item.querySelector('.admin-text-muted');
        if (distSpan) distSpan.textContent = dist + 'km';
        start = end;
    });
}

function changeType(segIdx, newType) {
    segmentTypes[segIdx] = newType;
    drawSegmentLines();
    updateUI();
}

function clearAllMarkers() {
    if (!confirm('Ta bort alla punkter?')) return;
    splitMarkers.forEach(m => map.removeLayer(m.marker));
    splitMarkers = [];
    segmentTypes = [];
    drawSegmentLines();
    updateUI();
}

function saveAllSegments() {
    if (splitMarkers.length === 0) {
        alert('Sätt ut minst en punkt först');
        return;
    }

    // Build segments data
    const segments = [];
    let start = 0;

    for (let i = 0; i <= splitMarkers.length; i++) {
        const end = i < splitMarkers.length ? splitMarkers[i].index : waypoints.length - 1;
        const type = segmentTypes[i] || 'liaison';

        if (end > start) {
            segments.push({ start, end, type });
        }
        start = end;
    }

    document.getElementById('segments-data').value = JSON.stringify(segments);
    document.getElementById('save-segments-form').submit();
}

function editExistingSegments() {
    // Load existing segments into the editor
    <?php if ($currentTrack && !empty($currentTrack['segments'])): ?>
    const existingSegments = <?= json_encode(array_map(function($s) {
        return [
            'start' => $s['start_index'] ?? 0,
            'end' => $s['end_index'] ?? 0,
            'type' => $s['segment_type'] ?? 'liaison'
        ];
    }, $currentTrack['segments'])) ?>;

    // Clear current markers
    splitMarkers.forEach(m => map.removeLayer(m.marker));
    splitMarkers = [];
    segmentTypes = [];

    // Add markers at each split point (end of each segment except last)
    existingSegments.forEach((seg, i) => {
        if (i < existingSegments.length - 1 && seg.end > 0 && seg.end < waypoints.length) {
            const wp = waypoints[seg.end];
            if (wp) addMarker(seg.end, wp);
        }
        segmentTypes.push(seg.type);
    });

    updateUI();
    drawSegmentLines();
    <?php endif; ?>
}

function onMapClick(e) {
    const poiLat = document.getElementById('poi-lat');
    const poiLng = document.getElementById('poi-lng');
    const poiBtn = document.getElementById('poi-btn');

    if (poiLat && poiLng && poiBtn) {
        poiLat.value = e.latlng.lat;
        poiLng.value = e.latlng.lng;
        poiBtn.disabled = false;
        if (tempMarker) map.removeLayer(tempMarker);
        tempMarker = L.circleMarker(e.latlng, {radius: 6, color: '#3B82F6', fillOpacity: 1}).addTo(map);
    }
}

function findNearest(latlng) {
    if (!waypoints || !waypoints.length) return null;
    let best = {dist: Infinity};
    waypoints.forEach((wp, i) => {
        const d = latlng.distanceTo(L.latLng(wp.lat, wp.lng));
        if (d < best.dist) best = {index: i, wp: wp, dist: d};
    });
    return best.dist < 300 ? best : null;
}

function changeSegmentType(segId, newType) {
    document.getElementById('update-seg-id').value = segId;
    document.getElementById('update-new-type').value = newType;
    document.getElementById('update-type-form').submit();
}

// Elevation profile rendering
function drawElevationProfile(startIdx = 0, endIdx = null, title = 'Höjdprofil') {
    const canvas = document.getElementById('elevation-canvas');
    if (!canvas || !waypoints || waypoints.length < 2) return;

    // Update title in header
    const headerTitle = document.getElementById('elevation-title');
    if (headerTitle) headerTitle.textContent = title;

    // Slice waypoints if filtering to a section
    const wps = endIdx !== null ? waypoints.slice(startIdx, endIdx + 1) : waypoints;
    if (wps.length < 2) return;

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
    const elevations = wps.map(w => w.elevation || 0);
    const distances = wps.map(w => w.distance_km || 0);
    const baseDistance = distances[0];

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
    const minDist = distances[0];
    const maxDist = distances[distances.length - 1];
    const distRange = maxDist - minDist || 1;

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

    wps.forEach((wp, i) => {
        const x = padding.left + ((distances[i] - minDist) / distRange) * chartWidth;
        const y = padding.top + chartHeight - ((elevations[i] - minEle) / eleRange) * chartHeight;
        ctx.lineTo(x, y);
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
    wps.forEach((wp, i) => {
        const x = padding.left + ((distances[i] - minDist) / distRange) * chartWidth;
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
    // Delay elevation profile to ensure canvas has proper dimensions
    setTimeout(() => drawElevationProfile(), 100);
});
window.addEventListener('resize', () => drawElevationProfile());
window.addEventListener('load', () => drawElevationProfile());
</script>

<?php include __DIR__ . '/components/unified-layout-footer.php'; ?>
