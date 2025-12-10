<?php
/**
 * Event Map Component - Responsive Fullscreen Design
 *
 * Desktop: Full map with sidebar for track/segment/POI selection
 * Mobile: Fullscreen with floating dropdown menus
 *
 * @since 2025-12-10
 */

// Prevent direct access
if (!defined('THEHUB_INIT')) {
    die('Direct access not allowed');
}

// Include map functions if not already loaded
if (!function_exists('getEventMapDataMultiTrack')) {
    require_once INCLUDES_PATH . '/map_functions.php';
}

if (!function_exists('render_event_map')) {
    /**
     * Render an event map (fullscreen responsive version)
     */
    function render_event_map(int $eventId, PDO $pdo, array $options = []): void {
        // Try multi-track first, fall back to single track
        $mapData = getEventMapDataMultiTrack($pdo, $eventId);

        if (!$mapData) {
            // Fallback to old single-track function
            $mapData = getEventMapData($pdo, $eventId);
        }

        if (!$mapData) {
            ?>
            <div class="event-map-empty" style="padding: var(--space-2xl); text-align: center; color: var(--color-text);">
                <p>Ingen karta tillgänglig för detta event.</p>
            </div>
            <?php
            return;
        }

        $mapId = 'emap-' . $eventId . '-' . uniqid();

        // Handle both multi-track and single-track data formats
        $tracks = $mapData['tracks'] ?? [];
        if (empty($tracks) && isset($mapData['track'])) {
            // Convert single track format to multi-track format
            $tracks = [[
                'id' => $mapData['track']['id'],
                'name' => $mapData['track']['name'],
                'route_label' => $mapData['track']['name'],
                'color' => '#3B82F6',
                'is_primary' => true,
                'total_distance_km' => $mapData['track']['total_distance_km'],
                'total_elevation_m' => $mapData['track']['total_elevation_m'],
                'segments' => $mapData['segments'] ?? [],
                'geojson' => $mapData['geojson'] ?? null
            ]];
            $mapData['tracks'] = $tracks;
        }

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

        $fullscreen = $options['fullscreen'] ?? true;
        $showClose = $options['show_close'] ?? true;
        $eventName = $options['event_name'] ?? 'Event';
        ?>

<style>
.emap-container {
    position: relative;
    width: 100%;
    <?php if ($fullscreen): ?>
    height: 100vh;
    height: 100dvh;
    <?php else: ?>
    height: 600px;
    <?php endif; ?>
    background: #1a1a1a;
}
.emap-map {
    position: absolute;
    inset: 0;
    z-index: 1;
}
/* Desktop sidebar */
@media (min-width: 769px) {
    .emap-sidebar {
        position: absolute;
        top: var(--space-md);
        left: var(--space-md);
        bottom: var(--space-md);
        width: 280px;
        background: rgba(255,255,255,0.97);
        backdrop-filter: blur(10px);
        border-radius: var(--radius-lg);
        box-shadow: var(--shadow-lg);
        z-index: 100;
        display: flex;
        flex-direction: column;
        overflow: hidden;
    }
    .emap-sidebar-header {
        padding: var(--space-md);
        border-bottom: 1px solid var(--color-border);
        flex-shrink: 0;
    }
    .emap-sidebar-body {
        flex: 1;
        overflow-y: auto;
        padding: var(--space-md);
    }
    .emap-mobile-controls { display: none; }
}
/* Mobile floating controls */
@media (max-width: 768px) {
    .emap-sidebar { display: none; }
    .emap-mobile-controls {
        position: absolute;
        top: var(--space-sm);
        left: var(--space-sm);
        right: var(--space-sm);
        z-index: 100;
        display: flex;
        gap: var(--space-sm);
        flex-wrap: wrap;
    }
    .emap-dropdown {
        position: relative;
    }
    .emap-dropdown-btn {
        display: flex;
        align-items: center;
        gap: var(--space-xs);
        padding: var(--space-sm) var(--space-md);
        background: rgba(255,255,255,0.97);
        backdrop-filter: blur(10px);
        border: none;
        border-radius: var(--radius-full);
        font-size: 0.9rem;
        font-weight: 500;
        cursor: pointer;
        box-shadow: var(--shadow-md);
        white-space: nowrap;
    }
    .emap-dropdown-btn .dot {
        width: 10px;
        height: 10px;
        border-radius: 50%;
    }
    .emap-dropdown-menu {
        position: absolute;
        top: calc(100% + var(--space-xs));
        left: 0;
        min-width: 200px;
        max-height: 300px;
        overflow-y: auto;
        background: rgba(255,255,255,0.98);
        backdrop-filter: blur(10px);
        border-radius: var(--radius-md);
        box-shadow: var(--shadow-lg);
        display: none;
        z-index: 200;
    }
    .emap-dropdown.open .emap-dropdown-menu {
        display: block;
    }
    .emap-dropdown-item {
        display: flex;
        align-items: center;
        gap: var(--space-sm);
        padding: var(--space-sm) var(--space-md);
        cursor: pointer;
        border-bottom: 1px solid var(--color-border);
    }
    .emap-dropdown-item:last-child { border-bottom: none; }
    .emap-dropdown-item:hover { background: var(--color-border); }
    .emap-dropdown-item.active { background: rgba(97,206,112,0.1); }
}
.emap-section {
    margin-bottom: var(--space-md);
}
.emap-section-title {
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--color-text);
    margin-bottom: var(--space-sm);
}
.emap-track-item {
    display: flex;
    align-items: center;
    gap: var(--space-sm);
    padding: var(--space-sm);
    border-radius: var(--radius-sm);
    cursor: pointer;
    margin-bottom: var(--space-xs);
    transition: background 0.2s;
}
.emap-track-item:hover { background: var(--color-border); }
.emap-track-item.active { background: rgba(97,206,112,0.15); }
.emap-track-dot {
    width: 14px;
    height: 14px;
    border-radius: 3px;
    flex-shrink: 0;
}
.emap-track-info { flex: 1; min-width: 0; }
.emap-track-name { font-weight: 500; font-size: 0.9rem; }
.emap-track-meta { font-size: 0.8rem; color: var(--color-text); }
.emap-checkbox {
    display: flex;
    align-items: center;
    gap: var(--space-sm);
    padding: var(--space-xs) 0;
    cursor: pointer;
    font-size: 0.9rem;
}
.emap-checkbox input { width: 16px; height: 16px; }
.emap-location-btn {
    position: absolute;
    bottom: var(--space-lg);
    right: var(--space-md);
    width: 44px;
    height: 44px;
    background: white;
    border: none;
    border-radius: 50%;
    box-shadow: var(--shadow-md);
    cursor: pointer;
    z-index: 100;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.2rem;
    transition: all 0.2s;
}
.emap-location-btn:hover { background: var(--color-border); }
.emap-location-btn.active { background: var(--color-accent); color: white; }
.emap-location-btn.loading { animation: emap-pulse 1s infinite; }
@keyframes emap-pulse {
    0%, 100% { transform: scale(1); }
    50% { transform: scale(1.1); }
}
.emap-elevation {
    position: absolute;
    bottom: 0;
    left: 0;
    right: 0;
    background: rgba(255,255,255,0.97);
    backdrop-filter: blur(10px);
    z-index: 90;
    transition: transform 0.3s ease;
}
.emap-elevation.collapsed {
    transform: translateY(calc(100% - 40px));
}
.emap-elevation-toggle {
    display: flex;
    align-items: center;
    justify-content: center;
    height: 40px;
    cursor: pointer;
    border-bottom: 1px solid var(--color-border);
    font-size: 0.85rem;
    font-weight: 500;
    gap: var(--space-sm);
}
.emap-elevation-toggle .chevron {
    transition: transform 0.3s;
}
.emap-elevation.collapsed .emap-elevation-toggle .chevron {
    transform: rotate(180deg);
}
.emap-elevation-content {
    height: 120px;
    padding: var(--space-sm);
}
.emap-elevation canvas { width: 100%; height: 100%; }
.emap-close {
    position: absolute;
    top: var(--space-md);
    right: var(--space-md);
    width: 40px;
    height: 40px;
    background: rgba(255,255,255,0.97);
    border: none;
    border-radius: 50%;
    box-shadow: var(--shadow-md);
    cursor: pointer;
    z-index: 100;
    font-size: 1.2rem;
    display: flex;
    align-items: center;
    justify-content: center;
}
@media (max-width: 768px) {
    .emap-close {
        top: auto;
        bottom: var(--space-lg);
        right: 60px;
    }
    .emap-elevation.collapsed {
        transform: translateY(calc(100% - 36px));
    }
    .emap-elevation-toggle { height: 36px; }
    .emap-elevation-content { height: 100px; }
}
</style>

<div class="emap-container" id="<?= $mapId ?>-container">
    <div class="emap-map" id="<?= $mapId ?>"></div>

    <!-- Desktop Sidebar -->
    <div class="emap-sidebar">
        <div class="emap-sidebar-header">
            <strong><?= htmlspecialchars($eventName) ?></strong>
        </div>
        <div class="emap-sidebar-body">
            <?php if (count($tracks) > 1): ?>
            <div class="emap-section">
                <div class="emap-section-title">Banor</div>
                <?php foreach ($tracks as $i => $track): ?>
                <div class="emap-track-item <?= $track['is_primary'] ? 'active' : '' ?>"
                     data-track-id="<?= $track['id'] ?>"
                     onclick="<?= $mapId ?>_toggleTrack(<?= $track['id'] ?>)">
                    <span class="emap-track-dot" style="background: <?= htmlspecialchars($track['color'] ?? '#3B82F6') ?>;"></span>
                    <div class="emap-track-info">
                        <div class="emap-track-name"><?= htmlspecialchars($track['route_label'] ?? $track['name']) ?></div>
                        <div class="emap-track-meta"><?= number_format($track['total_distance_km'], 1) ?> km</div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php elseif (count($tracks) == 1): ?>
            <div class="emap-section">
                <div style="display: flex; align-items: center; gap: var(--space-sm);">
                    <span class="emap-track-dot" style="background: <?= htmlspecialchars($tracks[0]['color'] ?? '#3B82F6') ?>;"></span>
                    <div>
                        <div style="font-weight: 500;"><?= htmlspecialchars($tracks[0]['route_label'] ?? $tracks[0]['name']) ?></div>
                        <div style="font-size: 0.85rem; color: var(--color-text);">
                            <?= number_format($tracks[0]['total_distance_km'], 1) ?> km · +<?= number_format($tracks[0]['total_elevation_m']) ?> m
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <?php if (!empty($poiGroups)): ?>
            <div class="emap-section">
                <div class="emap-section-title">Visa på kartan</div>
                <?php foreach ($poiGroups as $type => $group): ?>
                <label class="emap-checkbox">
                    <input type="checkbox" checked data-poi-type="<?= htmlspecialchars($type) ?>" onchange="<?= $mapId ?>_togglePoiType('<?= htmlspecialchars($type) ?>')">
                    <span><?= $group['emoji'] ?> <?= htmlspecialchars($group['label']) ?> (<?= count($group['items']) ?>)</span>
                </label>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Mobile Controls -->
    <div class="emap-mobile-controls">
        <?php if (count($tracks) > 1): ?>
        <div class="emap-dropdown" id="<?= $mapId ?>-track-dropdown">
            <button class="emap-dropdown-btn" onclick="<?= $mapId ?>_toggleDropdown('<?= $mapId ?>-track-dropdown')">
                <span class="dot" id="<?= $mapId ?>-current-dot" style="background: <?= htmlspecialchars($tracks[0]['color'] ?? '#3B82F6') ?>;"></span>
                <span id="<?= $mapId ?>-current-name"><?= htmlspecialchars($tracks[0]['route_label'] ?? $tracks[0]['name']) ?></span>
                <span>▼</span>
            </button>
            <div class="emap-dropdown-menu">
                <?php foreach ($tracks as $track): ?>
                <div class="emap-dropdown-item <?= $track['is_primary'] ? 'active' : '' ?>"
                     data-track-id="<?= $track['id'] ?>"
                     onclick="<?= $mapId ?>_selectTrack(<?= $track['id'] ?>, '<?= htmlspecialchars(addslashes($track['route_label'] ?? $track['name'])) ?>', '<?= $track['color'] ?? '#3B82F6' ?>')">
                    <span class="dot" style="background: <?= htmlspecialchars($track['color'] ?? '#3B82F6') ?>; width: 12px; height: 12px; border-radius: 3px;"></span>
                    <?= htmlspecialchars($track['route_label'] ?? $track['name']) ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php if (!empty($poiGroups)): ?>
        <div class="emap-dropdown" id="<?= $mapId ?>-poi-dropdown">
            <button class="emap-dropdown-btn" onclick="<?= $mapId ?>_toggleDropdown('<?= $mapId ?>-poi-dropdown')">
                📍 POIs <span>▼</span>
            </button>
            <div class="emap-dropdown-menu">
                <?php foreach ($poiGroups as $type => $group): ?>
                <div class="emap-dropdown-item active" data-poi-type="<?= htmlspecialchars($type) ?>" onclick="<?= $mapId ?>_togglePoiTypeMobile('<?= htmlspecialchars($type) ?>', this)">
                    <input type="checkbox" checked style="pointer-events: none;">
                    <?= $group['emoji'] ?> <?= htmlspecialchars($group['label']) ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Location Button -->
    <button class="emap-location-btn" id="<?= $mapId ?>-location-btn" onclick="<?= $mapId ?>_toggleLocation()" title="Min plats">
        📍
    </button>

    <?php if ($showClose): ?>
    <button class="emap-close" onclick="history.back()" title="Tillbaka">✕</button>
    <?php endif; ?>

    <!-- Elevation Profile -->
    <?php if (!empty($tracks)): ?>
    <div class="emap-elevation collapsed" id="<?= $mapId ?>-elevation">
        <div class="emap-elevation-toggle" onclick="<?= $mapId ?>_toggleElevation()">
            <span class="chevron">▲</span>
            <span>Höjdprofil</span>
        </div>
        <div class="emap-elevation-content">
            <canvas id="<?= $mapId ?>-canvas"></canvas>
        </div>
    </div>
    <?php endif; ?>
</div>

<script>
(function() {
    const mapId = '<?= $mapId ?>';
    const mapData = <?= json_encode($mapData) ?>;
    let map, trackLayers = {}, poiLayers = {};
    let locationMarker, locationCircle, watchId;
    let visibleTracks = new Set();
    let visiblePoiTypes = new Set();

    function init() {
        map = L.map(mapId, { zoomControl: false }).setView([62, 15], 5);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19 }).addTo(map);
        L.control.zoom({ position: 'bottomleft' }).addTo(map);

        // Draw tracks
        if (mapData.tracks) {
            mapData.tracks.forEach(track => {
                const layer = L.layerGroup();
                if (track.geojson && track.geojson.features) {
                    L.geoJSON(track.geojson, {
                        style: f => ({
                            color: f.properties.color || track.color || '#3B82F6',
                            weight: 4,
                            opacity: 0.9
                        }),
                        onEachFeature: (feature, layer) => {
                            if (feature.properties.name) {
                                layer.bindPopup('<strong>' + feature.properties.name + '</strong><br>' + feature.properties.distance_km + ' km');
                            }
                        }
                    }).addTo(layer);
                }
                trackLayers[track.id] = layer;
                if (track.is_primary) {
                    layer.addTo(map);
                    visibleTracks.add(track.id);
                }
            });
        }

        // Draw POIs
        if (mapData.pois) {
            mapData.pois.forEach(poi => {
                const type = poi.poi_type;
                if (!poiLayers[type]) {
                    poiLayers[type] = L.layerGroup().addTo(map);
                    visiblePoiTypes.add(type);
                }
                const marker = L.marker([poi.lat, poi.lng], {
                    icon: L.divIcon({
                        className: 'emap-poi-marker',
                        html: '<div style="font-size: 1.5rem; filter: drop-shadow(0 1px 2px rgba(0,0,0,0.3));">' + (poi.type_emoji || '📍') + '</div>',
                        iconSize: [30, 30],
                        iconAnchor: [15, 15]
                    })
                }).bindPopup('<strong>' + (poi.type_emoji || '📍') + ' ' + (poi.label || poi.type_label || poi.poi_type) + '</strong>' + (poi.description ? '<br>' + poi.description : ''));
                marker.addTo(poiLayers[type]);
            });
        }

        if (mapData.bounds) map.fitBounds(mapData.bounds, { padding: [50, 50] });
        updateElevation();

        // Close dropdowns on outside click
        document.addEventListener('click', (e) => {
            if (!e.target.closest('.emap-dropdown')) {
                document.querySelectorAll('.emap-dropdown.open').forEach(d => d.classList.remove('open'));
            }
        });
    }

    window[mapId + '_toggleTrack'] = function(trackId) {
        const layer = trackLayers[trackId];
        if (!layer) return;
        const item = document.querySelector('#' + mapId + '-container [data-track-id="' + trackId + '"]');
        if (visibleTracks.has(trackId)) {
            map.removeLayer(layer);
            visibleTracks.delete(trackId);
            if (item) item.classList.remove('active');
        } else {
            layer.addTo(map);
            visibleTracks.add(trackId);
            if (item) item.classList.add('active');
        }
        updateElevation();
    };

    window[mapId + '_selectTrack'] = function(trackId, name, color) {
        Object.keys(trackLayers).forEach(id => {
            const layer = trackLayers[id];
            const intId = parseInt(id);
            if (intId === trackId) {
                if (!map.hasLayer(layer)) layer.addTo(map);
                visibleTracks.add(intId);
            } else {
                if (map.hasLayer(layer)) map.removeLayer(layer);
                visibleTracks.delete(intId);
            }
        });
        document.getElementById(mapId + '-current-name').textContent = name;
        document.getElementById(mapId + '-current-dot').style.background = color;
        document.querySelectorAll('#' + mapId + '-track-dropdown .emap-dropdown-item').forEach(item => {
            item.classList.toggle('active', parseInt(item.dataset.trackId) === trackId);
        });
        document.getElementById(mapId + '-track-dropdown').classList.remove('open');
        updateElevation();
    };

    window[mapId + '_togglePoiType'] = function(type) {
        const layer = poiLayers[type];
        if (!layer) return;
        if (visiblePoiTypes.has(type)) {
            map.removeLayer(layer);
            visiblePoiTypes.delete(type);
        } else {
            layer.addTo(map);
            visiblePoiTypes.add(type);
        }
    };

    window[mapId + '_togglePoiTypeMobile'] = function(type, el) {
        window[mapId + '_togglePoiType'](type);
        el.classList.toggle('active');
        el.querySelector('input').checked = visiblePoiTypes.has(type);
    };

    window[mapId + '_toggleDropdown'] = function(id) {
        const dropdown = document.getElementById(id);
        const wasOpen = dropdown.classList.contains('open');
        document.querySelectorAll('.emap-dropdown.open').forEach(d => d.classList.remove('open'));
        if (!wasOpen) dropdown.classList.add('open');
    };

    window[mapId + '_toggleLocation'] = function() {
        const btn = document.getElementById(mapId + '-location-btn');
        if (watchId) {
            navigator.geolocation.clearWatch(watchId);
            watchId = null;
            if (locationMarker) map.removeLayer(locationMarker);
            if (locationCircle) map.removeLayer(locationCircle);
            locationMarker = locationCircle = null;
            btn.classList.remove('active', 'loading');
            return;
        }
        if (!navigator.geolocation) { alert('Geolocation stöds inte'); return; }
        btn.classList.add('loading');
        watchId = navigator.geolocation.watchPosition(
            (pos) => {
                const { latitude, longitude, accuracy } = pos.coords;
                btn.classList.remove('loading');
                btn.classList.add('active');
                if (!locationMarker) {
                    locationMarker = L.circleMarker([latitude, longitude], {
                        radius: 8, fillColor: '#3B82F6', fillOpacity: 1, color: 'white', weight: 3
                    }).addTo(map);
                    locationCircle = L.circle([latitude, longitude], {
                        radius: accuracy, fillColor: '#3B82F6', fillOpacity: 0.1, color: '#3B82F6', weight: 1
                    }).addTo(map);
                    map.setView([latitude, longitude], 15);
                } else {
                    locationMarker.setLatLng([latitude, longitude]);
                    locationCircle.setLatLng([latitude, longitude]).setRadius(accuracy);
                }
            },
            (err) => { btn.classList.remove('loading'); alert('Kunde inte hämta position'); },
            { enableHighAccuracy: true, maximumAge: 10000 }
        );
    };

    window[mapId + '_toggleElevation'] = function() {
        document.getElementById(mapId + '-elevation').classList.toggle('collapsed');
        setTimeout(updateElevation, 350);
    };

    function updateElevation() {
        const canvas = document.getElementById(mapId + '-canvas');
        if (!canvas) return;
        const ctx = canvas.getContext('2d');
        const rect = canvas.parentElement.getBoundingClientRect();
        canvas.width = rect.width * 2;
        canvas.height = rect.height * 2;
        ctx.scale(2, 2);

        let allElevations = [], allDistances = [];
        if (mapData.tracks) {
            mapData.tracks.forEach(track => {
                if (!visibleTracks.has(track.id)) return;
                (track.segments || []).forEach(seg => {
                    const elevData = seg.elevation_data || [];
                    const coords = seg.coordinates || [];
                    let dist = allDistances.length > 0 ? allDistances[allDistances.length - 1] : 0;
                    coords.forEach((coord, i) => {
                        if (i > 0) {
                            const prev = coords[i-1];
                            dist += haversine(prev.lat, prev.lng, coord.lat, coord.lng);
                        }
                        allDistances.push(dist);
                        allElevations.push(elevData[i] ?? coord.ele ?? 0);
                    });
                });
            });
        }

        if (allElevations.length < 2) {
            ctx.fillStyle = '#999';
            ctx.font = '12px sans-serif';
            ctx.textAlign = 'center';
            ctx.fillText('Ingen höjddata', rect.width / 2, rect.height / 2);
            return;
        }

        const minEle = Math.min(...allElevations);
        const maxEle = Math.max(...allElevations);
        const maxDist = Math.max(...allDistances);
        const eleRange = maxEle - minEle || 1;
        const padding = { top: 10, right: 10, bottom: 20, left: 40 };
        const w = rect.width - padding.left - padding.right;
        const h = rect.height - padding.top - padding.bottom;

        ctx.fillStyle = 'rgba(97, 206, 112, 0.3)';
        ctx.strokeStyle = '#61CE70';
        ctx.lineWidth = 2;
        ctx.beginPath();
        ctx.moveTo(padding.left, padding.top + h);
        allDistances.forEach((dist, i) => {
            const x = padding.left + (dist / maxDist) * w;
            const y = padding.top + h - ((allElevations[i] - minEle) / eleRange) * h;
            ctx.lineTo(x, y);
        });
        ctx.lineTo(padding.left + w, padding.top + h);
        ctx.closePath();
        ctx.fill();

        ctx.beginPath();
        allDistances.forEach((dist, i) => {
            const x = padding.left + (dist / maxDist) * w;
            const y = padding.top + h - ((allElevations[i] - minEle) / eleRange) * h;
            if (i === 0) ctx.moveTo(x, y); else ctx.lineTo(x, y);
        });
        ctx.stroke();

        ctx.fillStyle = '#666';
        ctx.font = '10px sans-serif';
        ctx.textAlign = 'right';
        ctx.fillText(Math.round(maxEle) + ' m', padding.left - 4, padding.top + 10);
        ctx.fillText(Math.round(minEle) + ' m', padding.left - 4, padding.top + h);
        ctx.textAlign = 'center';
        ctx.fillText('0 km', padding.left, padding.top + h + 14);
        ctx.fillText(maxDist.toFixed(1) + ' km', padding.left + w, padding.top + h + 14);
    }

    function haversine(lat1, lon1, lat2, lon2) {
        const R = 6371;
        const dLat = (lat2 - lat1) * Math.PI / 180;
        const dLon = (lon2 - lon1) * Math.PI / 180;
        const a = Math.sin(dLat/2) * Math.sin(dLat/2) +
                  Math.cos(lat1 * Math.PI / 180) * Math.cos(lat2 * Math.PI / 180) *
                  Math.sin(dLon/2) * Math.sin(dLon/2);
        return R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1-a));
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
    window.addEventListener('resize', () => setTimeout(updateElevation, 100));
})();
</script>
        <?php
    }
}

/**
 * Output Leaflet.js dependencies
 */
if (!function_exists('render_map_head')) {
    function render_map_head(): void {
        ?>
        <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" crossorigin="">
        <?php
    }
}

/**
 * Output Leaflet.js scripts
 */
if (!function_exists('render_map_scripts')) {
    function render_map_scripts(): void {
        ?>
        <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" crossorigin=""></script>
        <?php
    }
}
