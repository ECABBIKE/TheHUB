<?php
/**
 * Event Map Component - Clean Full-Width Design
 *
 * Renders an interactive map with GPX track, segments, and POI markers.
 * Uses Leaflet.js for map rendering.
 *
 * @since 2025-12-10
 */

// Prevent direct access
if (!defined('THEHUB_INIT')) {
    die('Direct access not allowed');
}

// Include map functions if not already loaded
if (!function_exists('getEventMapData')) {
    require_once INCLUDES_PATH . '/map_functions.php';
}

if (!function_exists('render_event_map')) {
    /**
     * Render an event map
     */
    function render_event_map(int $eventId, PDO $pdo, array $options = []): void {
        $mapData = getEventMapData($pdo, $eventId);

        if (!$mapData) {
            ?>
            <div class="event-map-empty">
                <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                    <polygon points="3 6 9 3 15 6 21 3 21 18 15 21 9 18 3 21"/>
                    <line x1="9" x2="9" y1="3" y2="18"/>
                    <line x1="15" x2="15" y1="6" y2="21"/>
                </svg>
                <p>Ingen karta tillgänglig</p>
            </div>
            <?php
            return;
        }

        $mapId = 'map-' . $eventId . '-' . uniqid();
        $mapDataJson = json_encode($mapData);

        // Group POIs
        $groupedPois = [];
        foreach ($mapData['pois'] as $poi) {
            $type = $poi['poi_type'];
            if (!isset($groupedPois[$type])) {
                $groupedPois[$type] = [
                    'label' => $poi['type_label'],
                    'emoji' => $poi['type_emoji'],
                    'color' => $poi['type_color'],
                    'count' => 0
                ];
            }
            $groupedPois[$type]['count']++;
        }
        ?>

        <style>
        .emap {
            background: var(--color-bg-card);
            border-radius: var(--radius-lg);
            overflow: hidden;
            border: 1px solid var(--color-border);
        }
        .emap-main {
            display: flex;
            min-height: 500px;
        }
        .emap-sidebar {
            width: 280px;
            background: var(--color-bg);
            border-right: 1px solid var(--color-border);
            display: flex;
            flex-direction: column;
            flex-shrink: 0;
        }
        .emap-header {
            padding: var(--space-md);
            border-bottom: 1px solid var(--color-border);
        }
        .emap-title {
            font-size: var(--text-lg);
            font-weight: 600;
            margin: 0 0 var(--space-xs) 0;
            display: flex;
            align-items: center;
            gap: var(--space-sm);
        }
        .emap-stats {
            display: flex;
            gap: var(--space-md);
            font-size: var(--text-sm);
            color: var(--color-text-secondary);
        }
        .emap-stats strong {
            color: var(--color-text);
        }
        .emap-section {
            padding: var(--space-sm) var(--space-md);
            border-bottom: 1px solid var(--color-border);
        }
        .emap-section h4 {
            font-size: var(--text-xs);
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--color-text-secondary);
            margin: 0 0 var(--space-sm) 0;
        }
        .emap-segment {
            display: flex;
            align-items: center;
            gap: var(--space-sm);
            padding: var(--space-xs) 0;
            font-size: var(--text-sm);
            cursor: pointer;
            border-radius: var(--radius-sm);
            transition: background 0.15s;
        }
        .emap-segment:hover {
            background: var(--color-bg-hover);
            margin: 0 calc(-1 * var(--space-sm));
            padding: var(--space-xs) var(--space-sm);
        }
        .emap-segment-dot {
            width: 10px;
            height: 10px;
            border-radius: 2px;
            flex-shrink: 0;
        }
        .emap-segment-name {
            flex: 1;
            font-weight: 500;
        }
        .emap-segment-info {
            color: var(--color-text-secondary);
            font-size: var(--text-xs);
        }
        .emap-poi {
            display: inline-flex;
            align-items: center;
            gap: var(--space-xs);
            padding: var(--space-xs) var(--space-sm);
            background: var(--color-bg-hover);
            border-radius: var(--radius-full);
            font-size: var(--text-xs);
            margin: 2px;
        }
        .emap-poi-icon {
            font-size: 14px;
        }
        .emap-content {
            flex: 1;
            display: flex;
            flex-direction: column;
            position: relative;
        }
        .emap-map {
            flex: 1;
            min-height: 400px;
        }
        .emap-elevation {
            height: 100px;
            background: var(--color-bg);
            border-top: 1px solid var(--color-border);
            position: relative;
            cursor: pointer;
        }
        .emap-elevation.collapsed {
            height: 32px;
        }
        .emap-elevation-toggle {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: var(--space-sm);
            font-size: var(--text-xs);
            color: var(--color-text-secondary);
            background: var(--color-bg-hover);
            cursor: pointer;
        }
        .emap-elevation-toggle:hover {
            background: var(--color-bg-sunken);
        }
        .emap-elevation-toggle svg {
            transition: transform 0.2s;
        }
        .emap-elevation.collapsed .emap-elevation-toggle svg {
            transform: rotate(180deg);
        }
        .emap-elevation-chart {
            position: absolute;
            top: 32px;
            left: 0;
            right: 0;
            bottom: 0;
        }
        .emap-elevation.collapsed .emap-elevation-chart {
            display: none;
        }
        .emap-elevation canvas {
            width: 100%;
            height: 100%;
        }
        .emap-locate {
            position: absolute;
            bottom: var(--space-md);
            right: var(--space-md);
            width: 40px;
            height: 40px;
            background: var(--color-bg-card);
            border: 1px solid var(--color-border);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            box-shadow: var(--shadow-md);
            z-index: 100;
            transition: all 0.2s;
        }
        .emap-locate:hover {
            background: var(--color-accent);
            color: white;
            border-color: var(--color-accent);
        }
        .emap-empty {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: var(--space-2xl);
            color: var(--color-text-secondary);
            text-align: center;
        }
        @media (max-width: 768px) {
            .emap-main {
                flex-direction: column;
            }
            .emap-sidebar {
                width: 100%;
                border-right: none;
                border-bottom: 1px solid var(--color-border);
            }
            .emap-map {
                min-height: 300px;
            }
        }
        </style>

        <div class="emap" id="<?= $mapId ?>-container">
            <div class="emap-main">
                <!-- Sidebar -->
                <div class="emap-sidebar">
                    <div class="emap-header">
                        <h3 class="emap-title">
                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <polygon points="3 6 9 3 15 6 21 3 21 18 15 21 9 18 3 21"/>
                            </svg>
                            <?= h($mapData['track']['name']) ?>
                        </h3>
                        <div class="emap-stats">
                            <span><strong><?= number_format($mapData['track']['total_distance_km'], 1) ?></strong> km</span>
                            <span><strong>+<?= number_format($mapData['track']['total_elevation_m']) ?></strong> m</span>
                        </div>
                    </div>

                    <?php if (!empty($mapData['segments'])): ?>
                    <div class="emap-section">
                        <h4>Sträckor</h4>
                        <?php foreach ($mapData['segments'] as $seg): ?>
                        <div class="emap-segment" data-segment-id="<?= $seg['id'] ?>">
                            <span class="emap-segment-dot" style="background: <?= h($seg['color']) ?>"></span>
                            <span class="emap-segment-name"><?= h($seg['segment_name'] ?: 'Segment ' . $seg['sequence_number']) ?></span>
                            <span class="emap-segment-info">
                                <?= number_format($seg['distance_km'], 1) ?> km
                                <?= $seg['elevation_gain_m'] > 0 ? ' · +' . $seg['elevation_gain_m'] . 'm' : '' ?>
                            </span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>

                    <?php if (!empty($groupedPois)): ?>
                    <div class="emap-section">
                        <h4>Platser</h4>
                        <div style="display: flex; flex-wrap: wrap; gap: 4px;">
                            <?php foreach ($groupedPois as $type => $poi): ?>
                            <span class="emap-poi">
                                <span class="emap-poi-icon"><?= $poi['emoji'] ?></span>
                                <?= h($poi['label']) ?>
                                <?= $poi['count'] > 1 ? '(' . $poi['count'] . ')' : '' ?>
                            </span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <div class="emap-section" style="margin-top: auto; border-bottom: none;">
                        <button onclick="locateUser('<?= $mapId ?>')" style="width: 100%; display: flex; align-items: center; justify-content: center; gap: var(--space-sm); padding: var(--space-sm); background: var(--color-bg-hover); border: 1px solid var(--color-border); border-radius: var(--radius-sm); cursor: pointer; font-size: var(--text-sm);">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <circle cx="12" cy="12" r="3"/>
                                <path d="M12 2v4M12 18v4M2 12h4M18 12h4"/>
                            </svg>
                            Visa min position
                        </button>
                    </div>
                </div>

                <!-- Map Content -->
                <div class="emap-content">
                    <div class="emap-map" id="<?= $mapId ?>"></div>

                    <?php if (!empty($mapData['segments'])): ?>
                    <div class="emap-elevation" id="<?= $mapId ?>-elev">
                        <div class="emap-elevation-toggle" onclick="toggleElevation('<?= $mapId ?>')">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m18 15-6-6-6 6"/></svg>
                            Höjdprofil
                        </div>
                        <div class="emap-elevation-chart">
                            <canvas id="<?= $mapId ?>-canvas"></canvas>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <script>
        (function() {
            const mapId = '<?= $mapId ?>';
            const data = <?= $mapDataJson ?>;
            let map, userMarker;

            function init() {
                map = L.map(mapId, { scrollWheelZoom: true }).setView([62, 15], 5);

                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                    attribution: '© OSM'
                }).addTo(map);

                // Add segments
                if (data.geojson) {
                    const segments = data.geojson.features.filter(f => f.properties.type === 'segment');
                    if (segments.length) {
                        L.geoJSON({ type: 'FeatureCollection', features: segments }, {
                            style: f => ({ color: f.properties.color, weight: 4, opacity: 0.9 })
                        }).addTo(map);
                    }

                    // Add POIs
                    const pois = data.geojson.features.filter(f => f.properties.type === 'poi');
                    pois.forEach(poi => {
                        const [lng, lat] = poi.geometry.coordinates;
                        const icon = L.divIcon({
                            className: 'poi-icon',
                            html: `<div style="background:${poi.properties.color};width:28px;height:28px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:14px;box-shadow:0 2px 6px rgba(0,0,0,0.3);">${poi.properties.emoji}</div>`,
                            iconSize: [28, 28],
                            iconAnchor: [14, 14]
                        });
                        L.marker([lat, lng], { icon }).bindPopup(`<b>${poi.properties.label}</b>`).addTo(map);
                    });
                }

                // Fit bounds
                if (data.bounds) {
                    map.fitBounds(data.bounds, { padding: [30, 30] });
                }

                // Draw elevation
                drawElevation();

                // Segment hover
                document.querySelectorAll(`#${mapId}-container .emap-segment`).forEach(el => {
                    el.addEventListener('click', () => {
                        const segId = el.dataset.segmentId;
                        // Could zoom to segment
                    });
                });
            }

            function drawElevation() {
                const canvas = document.getElementById(mapId + '-canvas');
                if (!canvas || !data.segments) return;

                const ctx = canvas.getContext('2d');
                const rect = canvas.parentElement.getBoundingClientRect();
                canvas.width = rect.width;
                canvas.height = rect.height;

                // Collect all elevations
                let elevs = [];
                data.segments.forEach(seg => {
                    if (seg.elevation_data) elevs = elevs.concat(seg.elevation_data);
                });
                if (!elevs.length) return;

                const min = Math.min(...elevs), max = Math.max(...elevs);
                const range = max - min || 1;

                ctx.clearRect(0, 0, canvas.width, canvas.height);

                // Fill
                ctx.beginPath();
                ctx.moveTo(0, canvas.height);
                elevs.forEach((e, i) => {
                    const x = (i / (elevs.length - 1)) * canvas.width;
                    const y = canvas.height - ((e - min) / range) * (canvas.height - 10);
                    ctx.lineTo(x, y);
                });
                ctx.lineTo(canvas.width, canvas.height);
                ctx.closePath();
                ctx.fillStyle = 'rgba(97, 206, 112, 0.2)';
                ctx.fill();

                // Line
                ctx.beginPath();
                elevs.forEach((e, i) => {
                    const x = (i / (elevs.length - 1)) * canvas.width;
                    const y = canvas.height - ((e - min) / range) * (canvas.height - 10);
                    if (i === 0) ctx.moveTo(x, y);
                    else ctx.lineTo(x, y);
                });
                ctx.strokeStyle = '#61CE70';
                ctx.lineWidth = 2;
                ctx.stroke();
            }

            window.toggleElevation = function(id) {
                document.getElementById(id + '-elev')?.classList.toggle('collapsed');
            };

            window.locateUser = function(id) {
                if (!navigator.geolocation) return alert('Geolocation stöds inte');
                navigator.geolocation.getCurrentPosition(pos => {
                    const { latitude, longitude } = pos.coords;
                    if (userMarker) map.removeLayer(userMarker);
                    userMarker = L.circleMarker([latitude, longitude], {
                        radius: 8, fillColor: '#3B82F6', fillOpacity: 1, color: 'white', weight: 3
                    }).addTo(map).bindPopup('Du är här').openPopup();
                    map.setView([latitude, longitude], 14);
                }, () => alert('Kunde inte hämta position'));
            };

            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', init);
            } else {
                init();
            }

            window.addEventListener('resize', drawElevation);
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
