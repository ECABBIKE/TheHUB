<?php
/**
 * Fullscreen Event Map - Standalone page without header/footer
 *
 * URL: /map.php?id=242 or /map/242
 */
define('THEHUB_INIT', true);
require_once __DIR__ . '/config.php';

global $pdo;

// Get event ID
$eventId = intval($_GET['id'] ?? 0);
if ($eventId <= 0) {
    header('Location: /');
    exit;
}

// Get event info
$db = getDB();
$event = $db->getRow("SELECT id, name, date FROM events WHERE id = ?", [$eventId]);
if (!$event) {
    header('Location: /');
    exit;
}

// Check if map exists
require_once INCLUDES_PATH . '/map_functions.php';
$mapData = getEventMapDataMultiTrack($pdo, $eventId);
if (!$mapData) {
    // No map, redirect to event page
    header('Location: /event/' . $eventId);
    exit;
}

$tracks = $mapData['tracks'] ?? [];
$pois = $mapData['pois'] ?? [];

// Group POIs by type
$poiGroups = [];
foreach ($pois as $poi) {
    $type = $poi['poi_type'];
    if (!isset($poiGroups[$type])) {
        $poiGroups[$type] = [
            'label' => $poi['type_label'] ?? $type,
            'emoji' => $poi['type_emoji'] ?? '📍',
            'items' => []
        ];
    }
    $poiGroups[$type]['items'][] = $poi;
}

$eventName = htmlspecialchars($event['name']);
?>
<!DOCTYPE html>
<html lang="sv">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="theme-color" content="#1a1a1a">
    <title>Karta - <?= $eventName ?></title>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" crossorigin="">
    <script src="https://unpkg.com/lucide@latest"></script>
    <style>
        :root {
            --color-accent: #61CE70;
            --color-icon: #F59E0B;
            --color-border: #e5e7eb;
            --color-text: #7A7A7A;
            --space-xs: 4px;
            --space-sm: 8px;
            --space-md: 16px;
            --space-lg: 24px;
            --radius-sm: 6px;
            --radius-md: 10px;
            --radius-lg: 16px;
            --radius-full: 9999px;
            --shadow-md: 0 4px 12px rgba(0,0,0,0.15);
            --shadow-lg: 0 10px 30px rgba(0,0,0,0.2);
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        html, body {
            width: 100%;
            height: 100%;
            overflow: hidden;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #1a1a1a;
            /* iOS safe areas */
            padding: env(safe-area-inset-top) env(safe-area-inset-right) env(safe-area-inset-bottom) env(safe-area-inset-left);
        }
        #map {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            z-index: 1;
        }
        /* Mobile controls */
        .controls-top {
            position: fixed;
            top: calc(var(--space-sm) + env(safe-area-inset-top));
            left: var(--space-sm);
            right: var(--space-sm);
            z-index: 1000;
            display: flex;
            gap: var(--space-sm);
            flex-wrap: wrap;
        }
        .dropdown {
            position: relative;
        }
        .dropdown-btn {
            display: flex;
            align-items: center;
            gap: var(--space-xs);
            padding: var(--space-sm) var(--space-md);
            background: rgba(255,255,255,0.97);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            border: none;
            border-radius: var(--radius-full);
            font-size: 0.9rem;
            font-weight: 500;
            cursor: pointer;
            box-shadow: var(--shadow-md);
            white-space: nowrap;
        }
        .dropdown-btn .dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
        }
        .dropdown-menu {
            position: absolute;
            top: calc(100% + var(--space-xs));
            left: 0;
            min-width: 200px;
            max-height: 60vh;
            overflow-y: auto;
            background: rgba(255,255,255,0.98);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            border-radius: var(--radius-md);
            box-shadow: var(--shadow-lg);
            display: none;
            z-index: 2000;
        }
        .dropdown.open .dropdown-menu {
            display: block;
        }
        .dropdown-item {
            display: flex;
            align-items: center;
            gap: var(--space-sm);
            padding: var(--space-sm) var(--space-md);
            cursor: pointer;
            border-bottom: 1px solid var(--color-border);
        }
        .dropdown-item:last-child { border-bottom: none; }
        .dropdown-item:active { background: var(--color-border); }
        .dropdown-item.active { background: rgba(97,206,112,0.1); }

        /* Mobile bottom bar */
        .mobile-bottom-bar {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: rgba(255,255,255,0.97);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            z-index: 1000;
            display: flex;
            align-items: stretch;
            justify-content: space-around;
            padding-bottom: env(safe-area-inset-bottom);
            box-shadow: 0 -2px 10px rgba(0,0,0,0.1);
        }
        .nav-item {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 2px;
            padding: var(--space-sm) var(--space-xs);
            background: none;
            border: none;
            cursor: pointer;
            font-size: 0.7rem;
            color: var(--color-text);
            text-decoration: none;
            transition: all 0.2s;
        }
        .nav-item:active { background: rgba(0,0,0,0.05); }
        .nav-item.active { color: var(--color-accent); }
        .nav-item.active .nav-icon { color: var(--color-accent); }
        .nav-item .nav-icon {
            width: 22px;
            height: 22px;
            color: var(--color-icon);
            stroke-width: 2;
        }
        .nav-item.loading .nav-icon { animation: pulse 1s infinite; }
        @keyframes pulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.1); }
        }
        /* Desktop bottom controls (hidden on mobile) */
        .controls-bottom {
            position: fixed;
            bottom: var(--space-lg);
            right: var(--space-md);
            z-index: 1000;
            display: none;
            gap: var(--space-sm);
        }
        .btn-circle {
            width: 48px;
            height: 48px;
            background: rgba(255,255,255,0.97);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            border: none;
            border-radius: 50%;
            box-shadow: var(--shadow-md);
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.3rem;
            transition: all 0.2s;
        }
        .btn-circle:active { transform: scale(0.95); }
        .btn-circle.active { background: var(--color-accent); color: white; }

        /* Elevation panel */
        .elevation {
            position: fixed;
            bottom: calc(56px + env(safe-area-inset-bottom)); /* Above mobile bottom bar */
            left: 0;
            right: 0;
            background: rgba(255,255,255,0.97);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            z-index: 900;
            transition: transform 0.3s ease, opacity 0.3s ease;
            transform: translateY(0);
            opacity: 1;
        }
        .elevation.hidden {
            transform: translateY(100%);
            opacity: 0;
            pointer-events: none;
        }
        .elevation-header {
            display: flex;
            align-items: center;
            gap: var(--space-sm);
            padding: var(--space-sm) var(--space-md);
            border-bottom: 1px solid var(--color-border);
        }
        .elevation-header span { font-size: 0.9rem; font-weight: 500; flex: 1; }
        .elevation-close {
            background: none;
            border: none;
            font-size: 1.2rem;
            cursor: pointer;
            padding: var(--space-xs);
            color: var(--color-text);
        }
        .elevation-content {
            height: 120px;
            padding: var(--space-sm);
        }
        .elevation canvas { width: 100%; height: 100%; }

        /* Desktop sidebar */
        @media (min-width: 769px) {
            .controls-top { display: none; }
            .mobile-bottom-bar { display: none; }
            .controls-bottom { display: flex; }
            .elevation {
                bottom: 0;
                padding-bottom: 0;
            }
            .sidebar {
                position: fixed;
                top: var(--space-md);
                left: var(--space-md);
                bottom: var(--space-md);
                width: 300px;
                background: rgba(255,255,255,0.97);
                backdrop-filter: blur(10px);
                border-radius: var(--radius-lg);
                box-shadow: var(--shadow-lg);
                z-index: 1000;
                display: flex;
                flex-direction: column;
                overflow: hidden;
            }
            .sidebar-header {
                padding: var(--space-md);
                border-bottom: 1px solid var(--color-border);
            }
            .sidebar-header h1 {
                font-size: 1.1rem;
                margin: 0;
            }
            .sidebar-body {
                flex: 1;
                overflow-y: auto;
                padding: var(--space-md);
            }
            .section { margin-bottom: var(--space-md); }
            .section-title {
                font-size: 0.7rem;
                font-weight: 600;
                text-transform: uppercase;
                letter-spacing: 0.05em;
                color: var(--color-text);
                margin-bottom: var(--space-sm);
            }
            .track-item {
                display: flex;
                align-items: center;
                gap: var(--space-sm);
                padding: var(--space-sm);
                border-radius: var(--radius-sm);
                cursor: pointer;
                margin-bottom: var(--space-xs);
            }
            .track-item:hover { background: rgba(0,0,0,0.05); }
            .track-item.active { background: rgba(97,206,112,0.15); }
            .track-dot { width: 14px; height: 14px; border-radius: 3px; }
            .track-name { font-weight: 500; font-size: 0.9rem; }
            .track-meta { font-size: 0.8rem; color: var(--color-text); }
            .checkbox-item {
                display: flex;
                align-items: center;
                gap: var(--space-sm);
                padding: var(--space-xs) 0;
                cursor: pointer;
                font-size: 0.9rem;
            }
            .checkbox-item input { width: 16px; height: 16px; }
        }
        @media (max-width: 768px) {
            .sidebar { display: none; }
            .controls-bottom { display: none; }
        }

        /* Leaflet overrides */
        .leaflet-control-zoom {
            border: none !important;
            box-shadow: var(--shadow-md) !important;
        }
        .leaflet-control-zoom a {
            background: rgba(255,255,255,0.97) !important;
            backdrop-filter: blur(10px) !important;
        }
        @media (max-width: 768px) {
            .leaflet-bottom.leaflet-left {
                bottom: calc(56px + env(safe-area-inset-bottom) + var(--space-sm)) !important;
            }
        }

        /* Segment items in dropdown */
        .dropdown-section-title {
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--color-text);
            padding: var(--space-sm) var(--space-md);
            background: #f5f5f5;
            border-bottom: 1px solid var(--color-border);
        }
        .segment-item {
            padding-left: calc(var(--space-md) + 20px);
            font-size: 0.85rem;
        }
        .segment-item .seg-info {
            display: flex;
            flex-direction: column;
            gap: 2px;
        }
        .segment-item .seg-name { font-weight: 500; }
        .segment-item .seg-meta { font-size: 0.75rem; color: var(--color-text); }

        /* Elevation stats */
        .elevation-stats {
            display: flex;
            gap: var(--space-lg);
            padding: var(--space-xs) var(--space-md);
            font-size: 0.8rem;
            color: var(--color-text);
            border-bottom: 1px solid var(--color-border);
        }
        .elevation-stats .stat {
            display: flex;
            align-items: center;
            gap: var(--space-xs);
        }
        .elevation-stats .stat-value {
            font-weight: 600;
            color: #333;
        }
    </style>
</head>
<body>
    <div id="map"></div>

    <!-- Desktop Sidebar -->
    <div class="sidebar">
        <div class="sidebar-header">
            <h1><?= $eventName ?></h1>
        </div>
        <div class="sidebar-body">
            <?php if (count($tracks) > 0): ?>
            <div class="section">
                <div class="section-title">Banor</div>
                <?php foreach ($tracks as $track): ?>
                <div class="track-item <?= $track['is_primary'] ? 'active' : '' ?>" data-track-id="<?= $track['id'] ?>" onclick="toggleTrack(<?= $track['id'] ?>)">
                    <span class="track-dot" style="background: <?= htmlspecialchars($track['color'] ?? '#3B82F6') ?>;"></span>
                    <div>
                        <div class="track-name"><?= htmlspecialchars($track['route_label'] ?? $track['name']) ?></div>
                        <div class="track-meta"><?= number_format($track['total_distance_km'], 1) ?> km · +<?= number_format($track['total_elevation_m']) ?> m</div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <?php if (!empty($poiGroups)): ?>
            <?php foreach ($poiGroups as $type => $group): ?>
            <div class="section">
                <div class="section-title"><?= $group['emoji'] ?> <?= htmlspecialchars($group['label']) ?></div>
                <?php foreach ($group['items'] as $poi): ?>
                <div class="track-item" onclick="zoomToPoi(<?= $poi['lat'] ?>, <?= $poi['lng'] ?>, '<?= htmlspecialchars(addslashes($poi['label'] ?? $group['label'])) ?>')">
                    <div>
                        <div class="track-name"><?= htmlspecialchars($poi['label'] ?? $group['label']) ?></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Mobile dropdowns (positioned from bottom bar) -->
    <div class="controls-top" id="mobile-dropdowns">
        <!-- Track & Segment dropdown menu (opens from bottom bar) -->
        <?php if (count($tracks) > 0): ?>
        <div class="dropdown" id="track-dropdown" style="position: fixed; bottom: calc(56px + env(safe-area-inset-bottom) + var(--space-sm)); left: var(--space-sm); display: none; max-width: calc(100vw - var(--space-md));">
            <div class="dropdown-menu" style="position: static; display: block; max-height: 50vh;">
                <div class="dropdown-section-title">Banor</div>
                <?php foreach ($tracks as $track): ?>
                <div class="dropdown-item <?= $track['is_primary'] ? 'active' : '' ?>" data-track-id="<?= $track['id'] ?>" onclick="selectTrack(<?= $track['id'] ?>)">
                    <span class="dot" style="background: <?= htmlspecialchars($track['color'] ?? '#3B82F6') ?>; width: 12px; height: 12px; border-radius: 3px;"></span>
                    <?= htmlspecialchars($track['route_label'] ?? $track['name']) ?>
                    <span style="margin-left: auto; font-size: 0.8em; opacity: 0.7;"><?= number_format($track['total_distance_km'], 1) ?> km</span>
                </div>
                <?php endforeach; ?>

                <?php
                // Collect all segments from all tracks
                $allSegments = [];
                foreach ($tracks as $track) {
                    if (!empty($track['segments'])) {
                        foreach ($track['segments'] as $seg) {
                            $seg['track_id'] = $track['id'];
                            $seg['track_color'] = $track['color'] ?? '#3B82F6';
                            $allSegments[] = $seg;
                        }
                    }
                }
                if (!empty($allSegments)): ?>
                <div class="dropdown-section-title">Sträckor</div>
                <?php foreach ($allSegments as $seg): ?>
                <div class="dropdown-item segment-item" data-segment-id="<?= $seg['id'] ?>" data-track-id="<?= $seg['track_id'] ?>" onclick="selectSegment(<?= $seg['id'] ?>, <?= $seg['track_id'] ?>)">
                    <div class="seg-info">
                        <span class="seg-name"><?= htmlspecialchars($seg['name']) ?></span>
                        <span class="seg-meta"><?= number_format($seg['distance_km'], 2) ?> km · <?= number_format($seg['elevation_drop_m'] ?? 0) ?> m FHM</span>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- POI dropdown menu (opens from bottom bar) -->
        <?php if (!empty($pois)): ?>
        <div class="dropdown" id="poi-dropdown" style="position: fixed; bottom: calc(56px + env(safe-area-inset-bottom) + var(--space-sm)); left: 50%; transform: translateX(-50%); display: none; max-width: calc(100vw - var(--space-md));">
            <div class="dropdown-menu" style="position: static; display: block; max-height: 50vh;">
                <?php foreach ($poiGroups as $type => $group): ?>
                <div class="dropdown-section-title"><?= $group['emoji'] ?> <?= htmlspecialchars($group['label']) ?></div>
                <?php foreach ($group['items'] as $poi): ?>
                <div class="dropdown-item" onclick="zoomToPoi(<?= $poi['lat'] ?>, <?= $poi['lng'] ?>, '<?= htmlspecialchars(addslashes($poi['label'] ?? $group['label'])) ?>')">
                    <?= htmlspecialchars($poi['label'] ?? $group['label']) ?>
                </div>
                <?php endforeach; ?>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Mobile bottom bar -->
    <div class="mobile-bottom-bar">
        <a href="/event/<?= $eventId ?>" class="nav-item" title="Tillbaka">
            <i data-lucide="arrow-left" class="nav-icon"></i>
            <span>Tillbaka</span>
        </a>
        <?php if (count($tracks) > 0): ?>
        <button class="nav-item" onclick="toggleMobileMenu('track-dropdown')" id="nav-tracks">
            <i data-lucide="map" class="nav-icon"></i>
            <span>Banor</span>
        </button>
        <?php endif; ?>
        <?php if (!empty($poiGroups)): ?>
        <button class="nav-item" onclick="toggleMobileMenu('poi-dropdown')" id="nav-pois">
            <i data-lucide="map-pin" class="nav-icon"></i>
            <span>POIs</span>
        </button>
        <?php endif; ?>
        <button class="nav-item" onclick="toggleElevation()" id="nav-elevation">
            <i data-lucide="mountain" class="nav-icon"></i>
            <span>Höjd</span>
        </button>
        <button class="nav-item" id="nav-location" onclick="toggleLocation()">
            <i data-lucide="locate" class="nav-icon"></i>
            <span>Din plats</span>
        </button>
    </div>

    <!-- Desktop bottom controls -->
    <div class="controls-bottom">
        <a href="/event/<?= $eventId ?>" class="btn-circle" title="Tillbaka till event" style="text-decoration: none;">✕</a>
        <button class="btn-circle" id="location-btn" onclick="toggleLocation()" title="Min plats">📍</button>
    </div>

    <!-- Elevation -->
    <div class="elevation hidden" id="elevation">
        <div class="elevation-header">
            <i data-lucide="mountain" style="width: 18px; height: 18px; color: var(--color-icon);"></i>
            <span id="elevation-title">Höjdprofil</span>
            <button class="elevation-close" onclick="toggleElevation()">✕</button>
        </div>
        <div class="elevation-stats" id="elevation-stats">
            <div class="stat">
                <span>Distans:</span>
                <span class="stat-value" id="stat-distance">-</span>
            </div>
            <div class="stat">
                <span>FHM:</span>
                <span class="stat-value" id="stat-fhm">-</span>
            </div>
            <div class="stat">
                <span>Stigning:</span>
                <span class="stat-value" id="stat-climb">-</span>
            </div>
        </div>
        <div class="elevation-content">
            <canvas id="elevation-canvas"></canvas>
        </div>
    </div>

    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" crossorigin=""></script>
    <script>
    const mapData = <?= json_encode($mapData) ?>;
    let map, trackLayers = {}, poiLayers = {}, segmentLayers = {};
    let locationMarker, locationCircle, watchId;
    let visibleTracks = new Set();
    let visiblePoiTypes = new Set();
    let selectedSegment = null;
    let highlightLayer = null;

    // Init
    document.addEventListener('DOMContentLoaded', () => {
        map = L.map('map', { zoomControl: false }).setView([62, 15], 5);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19 }).addTo(map);
        L.control.zoom({ position: 'bottomleft' }).addTo(map);

        // Draw tracks
        if (mapData.tracks) {
            mapData.tracks.forEach(track => {
                const layer = L.layerGroup();
                if (track.geojson && track.geojson.features) {
                    L.geoJSON(track.geojson, {
                        style: f => ({ color: f.properties.color || track.color || '#3B82F6', weight: 4, opacity: 0.9 }),
                        onEachFeature: (f, l) => { if (f.properties.name) l.bindPopup('<strong>' + f.properties.name + '</strong><br>' + f.properties.distance_km + ' km'); }
                    }).addTo(layer);
                }
                trackLayers[track.id] = layer;
                if (track.is_primary) { layer.addTo(map); visibleTracks.add(track.id); }
            });
        }

        // Draw POIs
        if (mapData.pois) {
            mapData.pois.forEach(poi => {
                const type = poi.poi_type;
                if (!poiLayers[type]) { poiLayers[type] = L.layerGroup().addTo(map); visiblePoiTypes.add(type); }
                L.marker([poi.lat, poi.lng], {
                    icon: L.divIcon({
                        className: '',
                        html: '<div style="font-size:1.5rem;filter:drop-shadow(0 1px 2px rgba(0,0,0,0.3));">' + (poi.type_emoji || '📍') + '</div>',
                        iconSize: [30, 30], iconAnchor: [15, 15]
                    })
                }).bindPopup('<strong>' + (poi.type_emoji || '📍') + ' ' + (poi.label || poi.type_label || type) + '</strong>').addTo(poiLayers[type]);
            });
        }

        if (mapData.bounds) map.fitBounds(mapData.bounds, { padding: [50, 50] });
        updateElevation();

        // Initialize Lucide icons
        lucide.createIcons();

        // Close dropdowns and mobile menus when clicking on map
        document.addEventListener('click', e => {
            if (!e.target.closest('.dropdown')) document.querySelectorAll('.dropdown.open').forEach(d => d.classList.remove('open'));
            // Close mobile menus when clicking outside
            if (!e.target.closest('.dropdown') && !e.target.closest('.mobile-bottom-bar') && !e.target.closest('.nav-item')) {
                document.querySelectorAll('#mobile-dropdowns .dropdown').forEach(d => d.style.display = 'none');
            }
        });
    });

    function toggleTrack(id) {
        const layer = trackLayers[id];
        if (!layer) return;
        const item = document.querySelector('[data-track-id="' + id + '"]');
        if (visibleTracks.has(id)) {
            map.removeLayer(layer); visibleTracks.delete(id);
            if (item) item.classList.remove('active');
        } else {
            layer.addTo(map); visibleTracks.add(id);
            if (item) item.classList.add('active');
        }
        updateElevation();
    }

    function selectTrack(id) {
        // Clear selected segment
        selectedSegment = null;
        if (highlightLayer) { map.removeLayer(highlightLayer); highlightLayer = null; }

        // Show only this track
        Object.keys(trackLayers).forEach(tid => {
            const layer = trackLayers[tid], intId = parseInt(tid);
            if (intId === id) {
                if (!map.hasLayer(layer)) layer.addTo(map);
                visibleTracks.add(intId);
            } else {
                if (map.hasLayer(layer)) map.removeLayer(layer);
                visibleTracks.delete(intId);
            }
        });

        // Zoom to track bounds
        const track = mapData.tracks.find(t => t.id === id);
        if (track && track.bounds) {
            map.fitBounds(track.bounds, { padding: [50, 50] });
        }

        // Update UI
        document.querySelectorAll('#track-dropdown .dropdown-item[data-track-id]').forEach(i => {
            i.classList.toggle('active', parseInt(i.dataset.trackId) === id && !i.classList.contains('segment-item'));
        });
        document.querySelectorAll('.segment-item').forEach(i => i.classList.remove('active'));

        // Close mobile menu
        document.querySelectorAll('#mobile-dropdowns .dropdown').forEach(d => d.style.display = 'none');

        // Update elevation title
        document.getElementById('elevation-title').textContent = track ? (track.route_label || track.name) : 'Höjdprofil';

        updateElevation();
    }

    function selectSegment(segmentId, trackId) {
        // First make sure the parent track is visible
        const layer = trackLayers[trackId];
        if (layer && !map.hasLayer(layer)) {
            layer.addTo(map);
            visibleTracks.add(trackId);
        }

        // Find the segment
        const track = mapData.tracks.find(t => t.id === trackId);
        const segment = track?.segments?.find(s => s.id === segmentId);
        if (!segment) return;

        selectedSegment = { trackId, segmentId, segment };

        // Remove old highlight
        if (highlightLayer) { map.removeLayer(highlightLayer); highlightLayer = null; }

        // Highlight the segment
        if (segment.coordinates && segment.coordinates.length > 0) {
            const coords = segment.coordinates.map(c => [c.lat, c.lng]);
            highlightLayer = L.polyline(coords, {
                color: '#F59E0B',
                weight: 6,
                opacity: 1
            }).addTo(map);

            // Zoom to segment
            map.fitBounds(highlightLayer.getBounds(), { padding: [50, 50] });
        }

        // Update UI
        document.querySelectorAll('#track-dropdown .dropdown-item').forEach(i => i.classList.remove('active'));
        document.querySelector(`.segment-item[data-segment-id="${segmentId}"]`)?.classList.add('active');

        // Close mobile menu
        document.querySelectorAll('#mobile-dropdowns .dropdown').forEach(d => d.style.display = 'none');

        // Update elevation title
        document.getElementById('elevation-title').textContent = segment.name;

        // Show elevation for this segment only
        updateElevation(segment);
    }

    function zoomToPoi(lat, lng, label) {
        // Zoom to POI location
        map.setView([lat, lng], 17);

        // Close mobile menu
        document.querySelectorAll('#mobile-dropdowns .dropdown').forEach(d => d.style.display = 'none');

        // Show a temporary popup at the POI
        L.popup()
            .setLatLng([lat, lng])
            .setContent('<strong>' + label + '</strong>')
            .openOn(map);
    }

    function toggleDropdown(id) {
        const d = document.getElementById(id), wasOpen = d.classList.contains('open');
        document.querySelectorAll('.dropdown.open').forEach(x => x.classList.remove('open'));
        if (!wasOpen) d.classList.add('open');
    }

    function toggleMobileMenu(id) {
        const menu = document.getElementById(id);
        if (!menu) return;
        const isVisible = menu.style.display !== 'none';
        // Hide all menus first
        document.querySelectorAll('#mobile-dropdowns .dropdown').forEach(d => d.style.display = 'none');
        // Hide elevation if showing menu
        document.getElementById('elevation').classList.add('hidden');
        document.getElementById('nav-elevation')?.classList.remove('active');
        // Toggle the clicked menu
        if (!isVisible) {
            menu.style.display = 'block';
        }
    }

    function toggleLocation() {
        const btn = document.getElementById('location-btn');
        const navBtn = document.getElementById('nav-location');
        // Hide menus when toggling location
        document.querySelectorAll('#mobile-dropdowns .dropdown').forEach(d => d.style.display = 'none');

        if (watchId) {
            navigator.geolocation.clearWatch(watchId); watchId = null;
            if (locationMarker) map.removeLayer(locationMarker);
            if (locationCircle) map.removeLayer(locationCircle);
            locationMarker = locationCircle = null;
            btn?.classList.remove('active', 'loading');
            navBtn?.classList.remove('active', 'loading');
            return;
        }
        if (!navigator.geolocation) { alert('Geolocation stöds inte'); return; }
        btn?.classList.add('loading');
        navBtn?.classList.add('loading');
        watchId = navigator.geolocation.watchPosition(
            pos => {
                const { latitude, longitude, accuracy } = pos.coords;
                btn?.classList.remove('loading'); btn?.classList.add('active');
                navBtn?.classList.remove('loading'); navBtn?.classList.add('active');
                if (!locationMarker) {
                    locationMarker = L.circleMarker([latitude, longitude], { radius: 8, fillColor: '#3B82F6', fillOpacity: 1, color: 'white', weight: 3 }).addTo(map);
                    locationCircle = L.circle([latitude, longitude], { radius: accuracy, fillColor: '#3B82F6', fillOpacity: 0.1, color: '#3B82F6', weight: 1 }).addTo(map);
                    map.setView([latitude, longitude], 15);
                } else {
                    locationMarker.setLatLng([latitude, longitude]);
                    locationCircle.setLatLng([latitude, longitude]).setRadius(accuracy);
                }
            },
            () => { btn?.classList.remove('loading'); navBtn?.classList.remove('loading'); alert('Kunde inte hämta position'); },
            { enableHighAccuracy: true, maximumAge: 10000 }
        );
    }

    function toggleElevation() {
        const el = document.getElementById('elevation');
        const navBtn = document.getElementById('nav-elevation');
        // Hide menus when showing elevation
        document.querySelectorAll('#mobile-dropdowns .dropdown').forEach(d => d.style.display = 'none');

        el.classList.toggle('hidden');
        navBtn?.classList.toggle('active', !el.classList.contains('hidden'));
        setTimeout(updateElevation, 350);
    }

    function updateElevation(singleSegment = null) {
        const canvas = document.getElementById('elevation-canvas');
        if (!canvas) return;
        const ctx = canvas.getContext('2d');
        const rect = canvas.parentElement.getBoundingClientRect();
        canvas.width = rect.width * 2; canvas.height = rect.height * 2;
        ctx.scale(2, 2);

        let allEle = [], allDist = [];

        if (singleSegment) {
            // Show single segment elevation
            const ele = singleSegment.elevation_data || [];
            const coords = singleSegment.coordinates || [];
            let dist = 0;
            coords.forEach((c, i) => {
                if (i > 0) dist += haversine(coords[i-1].lat, coords[i-1].lng, c.lat, c.lng);
                allDist.push(dist);
                allEle.push(ele[i] ?? c.ele ?? 0);
            });
        } else if (mapData.tracks) {
            // Show all visible tracks
            mapData.tracks.forEach(track => {
                if (!visibleTracks.has(track.id)) return;
                (track.segments || []).forEach(seg => {
                    const ele = seg.elevation_data || [], coords = seg.coordinates || [];
                    let dist = allDist.length ? allDist[allDist.length - 1] : 0;
                    coords.forEach((c, i) => {
                        if (i > 0) dist += haversine(coords[i-1].lat, coords[i-1].lng, c.lat, c.lng);
                        allDist.push(dist);
                        allEle.push(ele[i] ?? c.ele ?? 0);
                    });
                });
            });
        }

        // Calculate stats
        const totalDist = allDist.length > 0 ? allDist[allDist.length - 1] : 0;
        let totalClimb = 0, totalDescent = 0;
        for (let i = 1; i < allEle.length; i++) {
            const diff = allEle[i] - allEle[i-1];
            if (diff > 0) totalClimb += diff;
            else totalDescent += Math.abs(diff);
        }
        const fhm = totalDescent; // Fall Height Meters

        // Update stats display
        document.getElementById('stat-distance').textContent = totalDist.toFixed(2) + ' km';
        document.getElementById('stat-fhm').textContent = Math.round(fhm) + ' m';
        document.getElementById('stat-climb').textContent = '+' + Math.round(totalClimb) + ' m';

        if (allEle.length < 2) {
            ctx.fillStyle = '#999'; ctx.font = '12px sans-serif'; ctx.textAlign = 'center';
            ctx.fillText('Ingen höjddata', rect.width / 2, rect.height / 2);
            return;
        }

        const minE = Math.min(...allEle), maxE = Math.max(...allEle), maxD = Math.max(...allDist), range = maxE - minE || 1;
        const pad = { t: 10, r: 10, b: 20, l: 40 }, w = rect.width - pad.l - pad.r, h = rect.height - pad.t - pad.b;

        ctx.fillStyle = 'rgba(97,206,112,0.3)'; ctx.strokeStyle = '#61CE70'; ctx.lineWidth = 2;
        ctx.beginPath(); ctx.moveTo(pad.l, pad.t + h);
        allDist.forEach((d, i) => ctx.lineTo(pad.l + (d / maxD) * w, pad.t + h - ((allEle[i] - minE) / range) * h));
        ctx.lineTo(pad.l + w, pad.t + h); ctx.closePath(); ctx.fill();

        ctx.beginPath();
        allDist.forEach((d, i) => { const x = pad.l + (d / maxD) * w, y = pad.t + h - ((allEle[i] - minE) / range) * h; i === 0 ? ctx.moveTo(x, y) : ctx.lineTo(x, y); });
        ctx.stroke();

        ctx.fillStyle = '#666'; ctx.font = '10px sans-serif';
        ctx.textAlign = 'right'; ctx.fillText(Math.round(maxE) + ' m', pad.l - 4, pad.t + 10); ctx.fillText(Math.round(minE) + ' m', pad.l - 4, pad.t + h);
        ctx.textAlign = 'center'; ctx.fillText('0', pad.l, pad.t + h + 14); ctx.fillText(maxD.toFixed(1) + ' km', pad.l + w, pad.t + h + 14);
    }

    function haversine(lat1, lon1, lat2, lon2) {
        const R = 6371, dLat = (lat2 - lat1) * Math.PI / 180, dLon = (lon2 - lon1) * Math.PI / 180;
        const a = Math.sin(dLat/2)**2 + Math.cos(lat1 * Math.PI / 180) * Math.cos(lat2 * Math.PI / 180) * Math.sin(dLon/2)**2;
        return R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1-a));
    }

    window.addEventListener('resize', () => setTimeout(updateElevation, 100));
    </script>
</body>
</html>
