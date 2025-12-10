<?php
/**
 * Admin Event Map Management - Full-width Map Editor
 *
 * Clean map-focused interface with collapsible panels
 *
 * @since 2025-12-09
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../config.php';
require_admin();
require_once INCLUDES_PATH . '/map_functions.php';

$db = getDB();
global $pdo;

// Get event ID from URL
$eventId = 0;
if (isset($_GET['id'])) {
    $eventId = intval($_GET['id']);
} elseif (isset($_GET['event_id'])) {
    $eventId = intval($_GET['event_id']);
} else {
    $uri = $_SERVER['REQUEST_URI'];
    if (preg_match('#/admin/events?/map/(\d+)#', $uri, $matches)) {
        $eventId = intval($matches[1]);
    }
}

if ($eventId <= 0) {
    set_flash('error', 'Ogiltigt event-ID');
    header('Location: /admin/events');
    exit;
}

// Fetch event
$event = $db->getRow("SELECT id, name, date FROM events WHERE id = ?", [$eventId]);

if (!$event) {
    set_flash('error', 'Event hittades inte');
    header('Location: /admin/events');
    exit;
}

// Initialize message
$message = '';
$messageType = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = $_POST['action'] ?? '';

    try {
        switch ($action) {
            case 'upload_gpx':
                if (!isset($_FILES['gpx_file']) || $_FILES['gpx_file']['error'] !== UPLOAD_ERR_OK) {
                    throw new Exception('Ingen fil uppladdad eller uppladdningsfel');
                }
                $file = $_FILES['gpx_file'];
                $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                if ($ext !== 'gpx') throw new Exception('Endast GPX-filer tillåtna');
                if ($file['size'] > 10 * 1024 * 1024) throw new Exception('Max 10MB');

                $uploadDir = getGpxUploadPath();
                $filename = 'event_' . $eventId . '_' . time() . '.gpx';
                $filepath = $uploadDir . '/' . $filename;
                if (!move_uploaded_file($file['tmp_name'], $filepath)) {
                    throw new Exception('Kunde inte spara filen');
                }

                $existingTrack = getEventTrack($pdo, $eventId);
                if ($existingTrack) deleteEventTrack($pdo, $existingTrack['id']);

                $parsedData = parseGpxFile($filepath);
                $trackName = trim($_POST['track_name'] ?? '') ?: pathinfo($file['name'], PATHINFO_FILENAME);
                saveEventTrack($pdo, $eventId, $trackName, $filename, $parsedData);
                $message = 'GPX uppladdad!';
                $messageType = 'success';
                break;

            case 'delete_track':
                $trackId = intval($_POST['track_id'] ?? 0);
                if ($trackId > 0) {
                    deleteEventTrack($pdo, $trackId);
                    $message = 'Bana borttagen';
                    $messageType = 'success';
                }
                break;

            case 'add_poi':
                $poiData = [
                    'poi_type' => $_POST['poi_type'] ?? '',
                    'label' => trim($_POST['poi_label'] ?? ''),
                    'description' => trim($_POST['poi_description'] ?? ''),
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
                if ($poiId > 0) {
                    deleteEventPoi($pdo, $poiId);
                    $message = 'POI borttagen';
                    $messageType = 'success';
                }
                break;

            case 'update_segment':
                $segmentId = intval($_POST['segment_id'] ?? 0);
                $segmentType = $_POST['segment_type'] ?? 'stage';
                $segmentName = trim($_POST['segment_name'] ?? '');
                if ($segmentId > 0) {
                    updateSegmentClassification($pdo, $segmentId, $segmentType, $segmentName, null);
                    $message = 'Segment uppdaterat';
                    $messageType = 'success';
                }
                break;

            case 'delete_segment':
                $segmentId = intval($_POST['segment_id'] ?? 0);
                if ($segmentId > 0) {
                    $pdo->prepare("DELETE FROM event_track_segments WHERE id = ?")->execute([$segmentId]);
                    $message = 'Segment borttaget';
                    $messageType = 'success';
                }
                break;

            case 'add_segment_visual':
                $trackId = intval($_POST['track_id'] ?? 0);
                $segmentName = trim($_POST['segment_name'] ?? '');
                $segmentType = $_POST['segment_type'] ?? 'stage';
                $startIdx = intval($_POST['start_index'] ?? -1);
                $endIdx = intval($_POST['end_index'] ?? -1);

                if ($trackId <= 0) throw new Exception('Ogiltigt track-ID');
                if ($startIdx < 0 || $endIdx < 0) throw new Exception('Välj start och slut på kartan');
                if ($endIdx <= $startIdx) throw new Exception('Slutpunkt måste vara efter start');

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

// Get data
$track = getEventTrack($pdo, $eventId);
$pois = getEventPois($pdo, $eventId, false) ?: [];
$poiTypes = getPoiTypesForSelect() ?: [];
$mapData = getEventMapData($pdo, $eventId);
$mapDataJson = $mapData ? json_encode($mapData) : 'null';

$trackWaypoints = [];
if ($track) {
    try {
        $trackWaypoints = getTrackWaypointsForEditor($pdo, $track['id']);
    } catch (Exception $e) {
        $trackWaypoints = [];
    }
}
$waypointsJson = json_encode($trackWaypoints);
$poisJson = json_encode($pois);
?>
<!DOCTYPE html>
<html lang="sv">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Karta - <?= h($event['name']) ?> | TheHUB Admin</title>
    <link rel="stylesheet" href="<?= hub_asset('css/base.css') ?>">
    <link rel="stylesheet" href="<?= hub_asset('css/admin.css') ?>">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" crossorigin="">
    <style>
    /* Full-screen map layout */
    .map-editor {
        position: fixed;
        inset: 0;
        display: flex;
        flex-direction: column;
        background: var(--color-bg);
    }

    /* Top bar */
    .map-topbar {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: var(--space-sm) var(--space-md);
        background: var(--color-bg-card);
        border-bottom: 1px solid var(--color-border);
        z-index: 1000;
    }
    .map-topbar h1 {
        font-size: var(--text-lg);
        font-weight: 600;
        margin: 0;
        display: flex;
        align-items: center;
        gap: var(--space-sm);
    }
    .map-topbar-stats {
        display: flex;
        gap: var(--space-lg);
        font-size: var(--text-sm);
        color: var(--color-text-secondary);
    }
    .map-topbar-stat strong {
        color: var(--color-text);
    }

    /* Main content area */
    .map-content {
        flex: 1;
        display: flex;
        position: relative;
        overflow: hidden;
    }

    /* Sidebar panel */
    .map-sidebar {
        width: 320px;
        background: var(--color-bg-card);
        border-right: 1px solid var(--color-border);
        display: flex;
        flex-direction: column;
        z-index: 100;
        transition: margin-left 0.3s ease;
    }
    .map-sidebar.collapsed {
        margin-left: -320px;
    }
    .map-sidebar-toggle {
        position: absolute;
        left: 320px;
        top: 50%;
        transform: translateY(-50%);
        width: 24px;
        height: 48px;
        background: var(--color-bg-card);
        border: 1px solid var(--color-border);
        border-left: none;
        border-radius: 0 var(--radius-sm) var(--radius-sm) 0;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        z-index: 101;
        transition: left 0.3s ease;
    }
    .map-sidebar.collapsed + .map-sidebar-toggle {
        left: 0;
    }
    .map-sidebar-toggle svg {
        transition: transform 0.3s;
    }
    .map-sidebar.collapsed + .map-sidebar-toggle svg {
        transform: rotate(180deg);
    }

    /* Sidebar sections */
    .sidebar-section {
        border-bottom: 1px solid var(--color-border);
    }
    .sidebar-section-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: var(--space-sm) var(--space-md);
        cursor: pointer;
        font-weight: 600;
        font-size: var(--text-sm);
        background: var(--color-bg-hover);
    }
    .sidebar-section-header:hover {
        background: var(--color-bg-sunken);
    }
    .sidebar-section-content {
        padding: var(--space-sm) var(--space-md);
        max-height: 300px;
        overflow-y: auto;
    }

    /* Segment list */
    .segment-item {
        display: flex;
        align-items: center;
        gap: var(--space-sm);
        padding: var(--space-xs) 0;
        font-size: var(--text-sm);
        cursor: pointer;
        border-radius: var(--radius-sm);
    }
    .segment-item:hover {
        background: var(--color-bg-hover);
    }
    .segment-color {
        width: 12px;
        height: 12px;
        border-radius: 2px;
        flex-shrink: 0;
    }
    .segment-item-name {
        flex: 1;
    }
    .segment-item-dist {
        color: var(--color-text-secondary);
        font-size: var(--text-xs);
    }

    /* POI list */
    .poi-item {
        display: flex;
        align-items: center;
        gap: var(--space-sm);
        padding: var(--space-xs) 0;
        font-size: var(--text-sm);
    }
    .poi-item-icon {
        font-size: 16px;
    }

    /* Map container */
    .map-container {
        flex: 1;
        position: relative;
    }
    #map {
        width: 100%;
        height: 100%;
    }

    /* Elevation profile */
    .elevation-panel {
        position: absolute;
        bottom: 0;
        left: 0;
        right: 0;
        background: var(--color-bg-card);
        border-top: 1px solid var(--color-border);
        z-index: 100;
        transition: transform 0.3s ease;
    }
    .elevation-panel.collapsed {
        transform: translateY(calc(100% - 32px));
    }
    .elevation-toggle {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: var(--space-sm);
        padding: var(--space-xs) var(--space-md);
        cursor: pointer;
        font-size: var(--text-sm);
        font-weight: 500;
        background: var(--color-bg-hover);
    }
    .elevation-toggle:hover {
        background: var(--color-bg-sunken);
    }
    .elevation-toggle svg {
        transition: transform 0.3s;
    }
    .elevation-panel.collapsed .elevation-toggle svg {
        transform: rotate(180deg);
    }
    #elevation-chart {
        height: 120px;
        padding: var(--space-sm);
    }
    #elevation-canvas {
        width: 100%;
        height: 100%;
    }

    /* Floating action buttons */
    .map-fab {
        position: absolute;
        right: var(--space-md);
        top: var(--space-md);
        display: flex;
        flex-direction: column;
        gap: var(--space-sm);
        z-index: 100;
    }
    .fab-btn {
        width: 44px;
        height: 44px;
        border-radius: 50%;
        background: var(--color-bg-card);
        border: 1px solid var(--color-border);
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        box-shadow: var(--shadow-md);
        transition: all 0.2s;
    }
    .fab-btn:hover {
        background: var(--color-accent);
        color: white;
        border-color: var(--color-accent);
    }
    .fab-btn.active {
        background: var(--color-accent);
        color: white;
    }

    /* Mode indicator */
    .mode-indicator {
        position: absolute;
        top: var(--space-md);
        left: 50%;
        transform: translateX(-50%);
        background: var(--color-bg-card);
        padding: var(--space-sm) var(--space-md);
        border-radius: var(--radius-md);
        box-shadow: var(--shadow-lg);
        font-size: var(--text-sm);
        z-index: 100;
        display: none;
    }
    .mode-indicator.active {
        display: flex;
        align-items: center;
        gap: var(--space-sm);
    }
    .mode-indicator .step-dot {
        width: 8px;
        height: 8px;
        border-radius: 50%;
        background: var(--color-border);
    }
    .mode-indicator .step-dot.active {
        background: var(--color-accent);
    }
    .mode-indicator .step-dot.done {
        background: var(--color-success);
    }

    /* Toast messages */
    .toast {
        position: fixed;
        bottom: var(--space-xl);
        left: 50%;
        transform: translateX(-50%);
        background: var(--color-bg-card);
        padding: var(--space-sm) var(--space-lg);
        border-radius: var(--radius-md);
        box-shadow: var(--shadow-lg);
        z-index: 2000;
        animation: toast-in 0.3s ease;
    }
    .toast.error { border-left: 4px solid var(--color-error); }
    .toast.success { border-left: 4px solid var(--color-success); }
    @keyframes toast-in {
        from { opacity: 0; transform: translateX(-50%) translateY(20px); }
        to { opacity: 1; transform: translateX(-50%) translateY(0); }
    }

    /* Upload modal */
    .modal-overlay {
        position: fixed;
        inset: 0;
        background: rgba(0,0,0,0.5);
        display: flex;
        align-items: center;
        justify-content: center;
        z-index: 2000;
        opacity: 0;
        pointer-events: none;
        transition: opacity 0.2s;
    }
    .modal-overlay.active {
        opacity: 1;
        pointer-events: auto;
    }
    .modal {
        background: var(--color-bg-card);
        border-radius: var(--radius-lg);
        padding: var(--space-lg);
        width: 400px;
        max-width: 90vw;
        transform: scale(0.9);
        transition: transform 0.2s;
    }
    .modal-overlay.active .modal {
        transform: scale(1);
    }
    .modal h2 {
        margin: 0 0 var(--space-md) 0;
        font-size: var(--text-lg);
    }

    /* Segment markers */
    .segment-marker {
        width: 28px;
        height: 28px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: bold;
        font-size: 12px;
        color: white;
        box-shadow: 0 2px 6px rgba(0,0,0,0.3);
    }
    .segment-marker.start { background: #61CE70; }
    .segment-marker.end { background: #ef4444; }
    </style>
</head>
<body>
<div class="map-editor">
    <!-- Top Bar -->
    <div class="map-topbar">
        <h1>
            <a href="/admin/events/edit/<?= $eventId ?>" style="color: var(--color-text-secondary); text-decoration: none;">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m15 18-6-6 6-6"/></svg>
            </a>
            <?= h($event['name']) ?>
        </h1>
        <?php if ($track): ?>
        <div class="map-topbar-stats">
            <span><strong><?= number_format($track['total_distance_km'], 1) ?></strong> km</span>
            <span><strong><?= number_format($track['total_elevation_m']) ?></strong> m höjd</span>
            <span><strong><?= count($track['segments']) ?></strong> sträckor</span>
            <span><strong><?= count($pois) ?></strong> POIs</span>
        </div>
        <?php endif; ?>
        <div style="display: flex; gap: var(--space-sm);">
            <button type="button" onclick="openModal('gpx-modal')" class="btn-admin btn-admin-secondary btn-admin-sm">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" x2="12" y1="3" y2="15"/></svg>
                GPX
            </button>
        </div>
    </div>

    <!-- Main Content -->
    <div class="map-content">
        <!-- Sidebar -->
        <div class="map-sidebar" id="sidebar">
            <!-- Segments Section -->
            <div class="sidebar-section">
                <div class="sidebar-section-header" onclick="toggleSection(this)">
                    <span>Sträckor (<?= $track ? count($track['segments']) : 0 ?>)</span>
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m6 9 6 6 6-6"/></svg>
                </div>
                <div class="sidebar-section-content">
                    <?php if ($track && !empty($track['segments'])): ?>
                        <?php foreach ($track['segments'] as $seg): ?>
                        <div class="segment-item" onclick="zoomToSegment(<?= $seg['id'] ?>)" data-segment-id="<?= $seg['id'] ?>">
                            <span class="segment-color" style="background: <?= h($seg['color']) ?>"></span>
                            <span class="segment-item-name"><?= h($seg['segment_name'] ?: 'Segment ' . $seg['sequence_number']) ?></span>
                            <span class="segment-item-dist"><?= number_format($seg['distance_km'], 1) ?> km</span>
                            <form method="POST" style="margin: 0;" onsubmit="return confirm('Ta bort?')">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="delete_segment">
                                <input type="hidden" name="segment_id" value="<?= $seg['id'] ?>">
                                <button type="submit" style="background: none; border: none; cursor: pointer; padding: 4px; color: var(--color-text-secondary);">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/></svg>
                                </button>
                            </form>
                        </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p style="color: var(--color-text-secondary); font-size: var(--text-sm); margin: 0;">
                            Ladda upp GPX eller klicka på kartan för att definiera sträckor
                        </p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- POIs Section -->
            <div class="sidebar-section">
                <div class="sidebar-section-header" onclick="toggleSection(this)">
                    <span>POIs (<?= count($pois ?: []) ?>)</span>
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m6 9 6 6 6-6"/></svg>
                </div>
                <div class="sidebar-section-content">
                    <?php if (!empty($pois)): ?>
                        <?php foreach ($pois as $poi): ?>
                        <div class="poi-item">
                            <span class="poi-item-icon"><?= $poi['type_emoji'] ?></span>
                            <span style="flex: 1;"><?= h($poi['label'] ?: $poi['type_label']) ?></span>
                            <form method="POST" style="margin: 0;" onsubmit="return confirm('Ta bort?')">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="delete_poi">
                                <input type="hidden" name="poi_id" value="<?= $poi['id'] ?>">
                                <button type="submit" style="background: none; border: none; cursor: pointer; padding: 4px; color: var(--color-text-secondary);">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/></svg>
                                </button>
                            </form>
                        </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p style="color: var(--color-text-secondary); font-size: var(--text-sm); margin: 0;">
                            Klicka på kartan för att lägga till POIs
                        </p>
                    <?php endif; ?>

                    <!-- Legend -->
                    <div style="margin-top: var(--space-md); padding-top: var(--space-md); border-top: 1px solid var(--color-border);">
                        <div style="font-size: var(--text-xs); color: var(--color-text-secondary); margin-bottom: var(--space-xs);">Typer:</div>
                        <div style="display: flex; flex-wrap: wrap; gap: var(--space-xs); font-size: var(--text-xs);">
                            <?php foreach (array_slice($poiTypes, 0, 8) as $key => $label): ?>
                            <span style="padding: 2px 6px; background: var(--color-bg-hover); border-radius: 4px;"><?= h($label) ?></span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Add Segment Section -->
            <?php if ($track): ?>
            <div class="sidebar-section" style="flex: 1;">
                <div class="sidebar-section-header" onclick="toggleSection(this)">
                    <span>Lägg till sträcka</span>
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m6 9 6 6 6-6"/></svg>
                </div>
                <div class="sidebar-section-content">
                    <div id="segment-status" style="font-size: var(--text-sm); margin-bottom: var(--space-sm);">
                        <span style="color: var(--color-success);">1.</span> Klicka startpunkt på banan
                    </div>
                    <form method="POST" id="segment-form">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="add_segment_visual">
                        <input type="hidden" name="track_id" value="<?= $track['id'] ?>">
                        <input type="hidden" name="start_index" id="start-index" value="">
                        <input type="hidden" name="end_index" id="end-index" value="">

                        <div style="display: flex; gap: var(--space-sm); margin-bottom: var(--space-sm);">
                            <select name="segment_type" class="admin-form-select admin-form-select-sm" style="flex: 1;">
                                <option value="stage">Tävling</option>
                                <option value="liaison">Transport</option>
                            </select>
                            <input type="text" name="segment_name" class="admin-form-input admin-form-input-sm" placeholder="Namn" style="flex: 2;">
                        </div>

                        <div id="segment-preview" style="display: none; font-size: var(--text-xs); color: var(--color-text-secondary); margin-bottom: var(--space-sm);">
                            <span id="preview-distance">0</span> km vald
                        </div>

                        <div style="display: flex; gap: var(--space-sm);">
                            <button type="button" onclick="resetSegmentEditor()" class="btn-admin btn-admin-ghost btn-admin-sm" style="flex: 1;">Rensa</button>
                            <button type="submit" id="save-segment-btn" disabled class="btn-admin btn-admin-primary btn-admin-sm" style="flex: 1;">Spara</button>
                        </div>
                    </form>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- Sidebar Toggle -->
        <button class="map-sidebar-toggle" onclick="toggleSidebar()">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m15 18-6-6 6-6"/></svg>
        </button>

        <!-- Map Container -->
        <div class="map-container">
            <div id="map"></div>

            <!-- Floating Action Buttons -->
            <div class="map-fab">
                <button class="fab-btn" onclick="togglePOIMode()" id="poi-mode-btn" title="Lägg till POI">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
                </button>
                <button class="fab-btn" onclick="centerMap()" title="Centrera">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="3"/></svg>
                </button>
            </div>

            <!-- Mode Indicator -->
            <div class="mode-indicator" id="mode-indicator">
                <span class="step-dot" id="step-1"></span>
                <span class="step-dot" id="step-2"></span>
                <span id="mode-text">Klicka startpunkt</span>
            </div>

            <!-- Elevation Panel -->
            <?php if ($track): ?>
            <div class="elevation-panel" id="elevation-panel">
                <div class="elevation-toggle" onclick="toggleElevation()">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m18 15-6-6-6 6"/></svg>
                    <span>Höjdprofil</span>
                </div>
                <div id="elevation-chart">
                    <canvas id="elevation-canvas"></canvas>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- GPX Upload Modal -->
<div class="modal-overlay" id="gpx-modal">
    <div class="modal">
        <h2>Ladda upp GPX</h2>
        <form method="POST" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="upload_gpx">
            <div class="admin-form-group">
                <label class="admin-form-label">Namn</label>
                <input type="text" name="track_name" class="admin-form-input" value="<?= h($event['name']) ?>">
            </div>
            <div class="admin-form-group">
                <label class="admin-form-label">GPX-fil</label>
                <input type="file" name="gpx_file" accept=".gpx" required class="admin-form-input">
            </div>
            <div style="display: flex; gap: var(--space-sm); justify-content: flex-end;">
                <button type="button" onclick="closeModal('gpx-modal')" class="btn-admin btn-admin-ghost">Avbryt</button>
                <button type="submit" class="btn-admin btn-admin-primary">Ladda upp</button>
            </div>
        </form>
        <?php if ($track): ?>
        <form method="POST" style="margin-top: var(--space-md); padding-top: var(--space-md); border-top: 1px solid var(--color-border);">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="delete_track">
            <input type="hidden" name="track_id" value="<?= $track['id'] ?>">
            <button type="submit" onclick="return confirm('Ta bort befintlig bana?')" class="btn-admin btn-admin-danger btn-admin-sm">
                Ta bort nuvarande bana
            </button>
        </form>
        <?php endif; ?>
    </div>
</div>

<!-- POI Modal -->
<div class="modal-overlay" id="poi-modal">
    <div class="modal">
        <h2>Lägg till POI</h2>
        <form method="POST" id="poi-form">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="add_poi">
            <input type="hidden" name="poi_lat" id="poi-lat">
            <input type="hidden" name="poi_lng" id="poi-lng">
            <div class="admin-form-group">
                <label class="admin-form-label">Typ</label>
                <select name="poi_type" class="admin-form-select" required>
                    <option value="">Välj...</option>
                    <?php foreach ($poiTypes as $key => $label): ?>
                    <option value="<?= h($key) ?>"><?= h($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="admin-form-group">
                <label class="admin-form-label">Etikett</label>
                <input type="text" name="poi_label" class="admin-form-input" placeholder="Valfritt namn">
            </div>
            <div style="display: flex; gap: var(--space-sm); justify-content: flex-end;">
                <button type="button" onclick="closeModal('poi-modal')" class="btn-admin btn-admin-ghost">Avbryt</button>
                <button type="submit" class="btn-admin btn-admin-primary">Lägg till</button>
            </div>
        </form>
    </div>
</div>

<?php if ($message): ?>
<div class="toast <?= $messageType ?>" id="toast"><?= h($message) ?></div>
<script>setTimeout(() => document.getElementById('toast')?.remove(), 3000);</script>
<?php endif; ?>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" crossorigin=""></script>
<script>
const mapData = <?= $mapDataJson ?>;
const waypoints = <?= $waypointsJson ?>;
const trackId = <?= $track ? $track['id'] : 'null' ?>;

let map, trackLine, startMarker, endMarker, previewLine;
let segmentMode = 'start';
let selectedStart = -1, selectedEnd = -1;
let poiMode = false;

function initMap() {
    map = L.map('map', { zoomControl: false }).setView([62, 15], 5);

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '© OSM'
    }).addTo(map);

    L.control.zoom({ position: 'bottomright' }).addTo(map);

    if (mapData) {
        // Add segments
        const segments = mapData.geojson.features.filter(f => f.properties.type === 'segment');
        if (segments.length > 0) {
            L.geoJSON({ type: 'FeatureCollection', features: segments }, {
                style: f => ({ color: f.properties.color, weight: 5, opacity: 0.8 })
            }).addTo(map);
        }

        // Add POIs
        const pois = mapData.geojson.features.filter(f => f.properties.type === 'poi');
        pois.forEach(poi => {
            const coords = poi.geometry.coordinates;
            const icon = L.divIcon({
                className: 'poi-marker',
                html: `<div style="background:${poi.properties.color};width:28px;height:28px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:14px;box-shadow:0 2px 6px rgba(0,0,0,0.3);">${poi.properties.emoji}</div>`,
                iconSize: [28, 28],
                iconAnchor: [14, 14]
            });
            L.marker([coords[1], coords[0]], { icon }).addTo(map);
        });

        if (mapData.bounds) {
            map.fitBounds(mapData.bounds, { padding: [50, 50] });
        }
    }

    // Clickable track
    if (waypoints && waypoints.length > 0) {
        const coords = waypoints.map(w => [w.lat, w.lng]);
        trackLine = L.polyline(coords, { color: '#3B82F6', weight: 6, opacity: 0.6 }).addTo(map);
        trackLine.on('click', handleTrackClick);
    }

    map.on('click', handleMapClick);
    drawElevation();
}

function handleMapClick(e) {
    if (poiMode) {
        document.getElementById('poi-lat').value = e.latlng.lat;
        document.getElementById('poi-lng').value = e.latlng.lng;
        openModal('poi-modal');
        poiMode = false;
        document.getElementById('poi-mode-btn').classList.remove('active');
    }
}

function handleTrackClick(e) {
    L.DomEvent.stopPropagation(e);
    const nearest = findNearest(e.latlng);
    if (!nearest) return;

    const wp = nearest.waypoint;

    if (segmentMode === 'start') {
        selectedStart = nearest.index;
        if (startMarker) map.removeLayer(startMarker);
        startMarker = L.marker([wp.lat, wp.lng], {
            icon: L.divIcon({ html: '<div class="segment-marker start">S</div>', iconSize: [28,28], iconAnchor: [14,14] })
        }).addTo(map);

        segmentMode = 'end';
        updateSegmentUI();
    } else if (segmentMode === 'end' && nearest.index > selectedStart) {
        selectedEnd = nearest.index;
        if (endMarker) map.removeLayer(endMarker);
        endMarker = L.marker([wp.lat, wp.lng], {
            icon: L.divIcon({ html: '<div class="segment-marker end">M</div>', iconSize: [28,28], iconAnchor: [14,14] })
        }).addTo(map);

        // Preview line
        if (previewLine) map.removeLayer(previewLine);
        const previewCoords = waypoints.slice(selectedStart, selectedEnd + 1).map(w => [w.lat, w.lng]);
        previewLine = L.polyline(previewCoords, { color: '#F59E0B', weight: 8 }).addTo(map);

        segmentMode = 'complete';
        document.getElementById('start-index').value = selectedStart;
        document.getElementById('end-index').value = selectedEnd;
        document.getElementById('save-segment-btn').disabled = false;

        const dist = (waypoints[selectedEnd].distance_km - waypoints[selectedStart].distance_km).toFixed(2);
        document.getElementById('preview-distance').textContent = dist;
        document.getElementById('segment-preview').style.display = 'block';
        updateSegmentUI();
    }
}

function findNearest(latlng) {
    if (!waypoints || !waypoints.length) return null;
    let best = { index: 0, dist: Infinity };
    waypoints.forEach((wp, i) => {
        const d = latlng.distanceTo(L.latLng(wp.lat, wp.lng));
        if (d < best.dist) best = { index: i, dist: d, waypoint: wp };
    });
    return best.dist < 200 ? best : null;
}

function updateSegmentUI() {
    const status = document.getElementById('segment-status');
    const indicator = document.getElementById('mode-indicator');
    const step1 = document.getElementById('step-1');
    const step2 = document.getElementById('step-2');
    const text = document.getElementById('mode-text');

    if (segmentMode === 'start') {
        status.innerHTML = '<span style="color: var(--color-success);">1.</span> Klicka startpunkt på banan';
        indicator.classList.remove('active');
    } else if (segmentMode === 'end') {
        status.innerHTML = '<span style="color: var(--color-success);">✓</span> Start vald. <span style="color: var(--color-error);">2.</span> Klicka slutpunkt';
        indicator.classList.add('active');
        step1.classList.add('done');
        step2.classList.add('active');
        text.textContent = 'Klicka slutpunkt';
    } else {
        status.innerHTML = '<span style="color: var(--color-success);">✓ ✓</span> Klar! Spara sträckan';
        step1.classList.add('done');
        step2.classList.add('done');
        text.textContent = 'Spara sträckan';
    }
}

function resetSegmentEditor() {
    segmentMode = 'start';
    selectedStart = selectedEnd = -1;
    if (startMarker) { map.removeLayer(startMarker); startMarker = null; }
    if (endMarker) { map.removeLayer(endMarker); endMarker = null; }
    if (previewLine) { map.removeLayer(previewLine); previewLine = null; }
    document.getElementById('start-index').value = '';
    document.getElementById('end-index').value = '';
    document.getElementById('save-segment-btn').disabled = true;
    document.getElementById('segment-preview').style.display = 'none';
    document.getElementById('step-1')?.classList.remove('done', 'active');
    document.getElementById('step-2')?.classList.remove('done', 'active');
    updateSegmentUI();
}

function drawElevation() {
    const canvas = document.getElementById('elevation-canvas');
    if (!canvas || !waypoints || !waypoints.length) return;

    const ctx = canvas.getContext('2d');
    const rect = canvas.parentElement.getBoundingClientRect();
    canvas.width = rect.width;
    canvas.height = rect.height;

    const elevs = waypoints.map(w => w.elevation).filter(e => e != null);
    if (!elevs.length) return;

    const min = Math.min(...elevs), max = Math.max(...elevs);
    const range = max - min || 1;

    ctx.clearRect(0, 0, canvas.width, canvas.height);

    // Fill
    ctx.beginPath();
    ctx.moveTo(0, canvas.height);
    elevs.forEach((e, i) => {
        const x = (i / (elevs.length - 1)) * canvas.width;
        const y = canvas.height - ((e - min) / range) * (canvas.height - 20);
        if (i === 0) ctx.lineTo(x, y);
        else ctx.lineTo(x, y);
    });
    ctx.lineTo(canvas.width, canvas.height);
    ctx.closePath();
    ctx.fillStyle = 'rgba(97, 206, 112, 0.3)';
    ctx.fill();

    // Line
    ctx.beginPath();
    elevs.forEach((e, i) => {
        const x = (i / (elevs.length - 1)) * canvas.width;
        const y = canvas.height - ((e - min) / range) * (canvas.height - 20);
        if (i === 0) ctx.moveTo(x, y);
        else ctx.lineTo(x, y);
    });
    ctx.strokeStyle = '#61CE70';
    ctx.lineWidth = 2;
    ctx.stroke();
}

function toggleSidebar() {
    document.getElementById('sidebar').classList.toggle('collapsed');
}

function toggleElevation() {
    document.getElementById('elevation-panel').classList.toggle('collapsed');
}

function toggleSection(el) {
    const content = el.nextElementSibling;
    content.style.display = content.style.display === 'none' ? 'block' : 'none';
    el.querySelector('svg').style.transform = content.style.display === 'none' ? 'rotate(-90deg)' : '';
}

function togglePOIMode() {
    poiMode = !poiMode;
    document.getElementById('poi-mode-btn').classList.toggle('active', poiMode);
}

function centerMap() {
    if (mapData?.bounds) map.fitBounds(mapData.bounds, { padding: [50, 50] });
}

function zoomToSegment(id) {
    // Could implement segment highlighting
}

function openModal(id) {
    document.getElementById(id).classList.add('active');
}

function closeModal(id) {
    document.getElementById(id).classList.remove('active');
}

// Close modal on overlay click
document.querySelectorAll('.modal-overlay').forEach(m => {
    m.addEventListener('click', e => { if (e.target === m) m.classList.remove('active'); });
});

// Init
document.addEventListener('DOMContentLoaded', initMap);
window.addEventListener('resize', drawElevation);
</script>
</body>
</html>
