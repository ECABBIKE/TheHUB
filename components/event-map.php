<?php
/**
 * Event Map Component
 *
 * Renders an interactive map with GPX track, segments, and POI markers.
 * Uses Leaflet.js for map rendering.
 *
 * Usage:
 *   <?php render_event_map($eventId, $pdo, $options); ?>
 *
 * Options:
 *   - height: string (default: '400px') - Map container height
 *   - show_elevation: bool (default: true) - Show elevation profile
 *   - show_legend: bool (default: true) - Show POI legend
 *   - show_segments: bool (default: true) - Show segment list
 *   - collapsed: bool (default: false) - Start with map collapsed
 *
 * @since 2025-12-09
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
     *
     * @param int $eventId Event ID
     * @param PDO $pdo Database connection
     * @param array $options Display options
     * @return void Outputs HTML directly
     */
    function render_event_map(int $eventId, PDO $pdo, array $options = []): void {
        // Get map data
        $mapData = getEventMapData($pdo, $eventId);

        // If no map data, show placeholder
        if (!$mapData) {
            ?>
            <div class="event-map-placeholder">
                <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                    <polygon points="3 6 9 3 15 6 21 3 21 18 15 21 9 18 3 21"/>
                    <line x1="9" x2="9" y1="3" y2="18"/>
                    <line x1="15" x2="15" y1="6" y2="21"/>
                </svg>
                <p>Ingen karta tillganglig for detta event</p>
            </div>
            <?php
            return;
        }

        // Extract options
        $height = $options['height'] ?? '400px';
        $showElevation = $options['show_elevation'] ?? true;
        $showLegend = $options['show_legend'] ?? true;
        $showSegments = $options['show_segments'] ?? true;
        $collapsed = $options['collapsed'] ?? false;

        // Generate unique ID for this map instance
        $mapId = 'event-map-' . $eventId . '-' . uniqid();

        // Encode map data for JavaScript
        $mapDataJson = htmlspecialchars(json_encode($mapData), ENT_QUOTES, 'UTF-8');

        // Group POIs by type for legend
        $groupedPois = [];
        foreach ($mapData['pois'] as $poi) {
            $type = $poi['poi_type'];
            if (!isset($groupedPois[$type])) {
                $groupedPois[$type] = [
                    'type' => $type,
                    'label' => $poi['type_label'],
                    'emoji' => $poi['type_emoji'],
                    'color' => $poi['type_color'],
                    'count' => 0,
                    'items' => []
                ];
            }
            $groupedPois[$type]['count']++;
            $groupedPois[$type]['items'][] = $poi;
        }
        ?>

        <div class="event-map-container <?= $collapsed ? 'collapsed' : '' ?>"
             id="<?= $mapId ?>-container"
             data-event-id="<?= $eventId ?>"
             data-map-data="<?= $mapDataJson ?>">

            <!-- Map Header -->
            <div class="event-map-header">
                <div class="event-map-title">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <polygon points="3 6 9 3 15 6 21 3 21 18 15 21 9 18 3 21"/>
                        <line x1="9" x2="9" y1="3" y2="18"/>
                        <line x1="15" x2="15" y1="6" y2="21"/>
                    </svg>
                    <h3><?= htmlspecialchars($mapData['track']['name']) ?></h3>
                </div>
                <div class="event-map-stats">
                    <span class="event-map-stat">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="12" cy="12" r="10"/>
                            <polyline points="12 6 12 12 16 14"/>
                        </svg>
                        <?= number_format($mapData['track']['total_distance_km'], 1) ?> km
                    </span>
                    <span class="event-map-stat">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="m2 20 7-7 4 4 8-8"/>
                            <path d="M21 12V5h-7"/>
                        </svg>
                        <?= number_format($mapData['track']['total_elevation_m']) ?> m
                    </span>
                </div>
                <button class="event-map-toggle" aria-expanded="<?= $collapsed ? 'false' : 'true' ?>" aria-controls="<?= $mapId ?>-content">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="m6 9 6 6 6-6"/>
                    </svg>
                </button>
            </div>

            <!-- Map Content -->
            <div class="event-map-content" id="<?= $mapId ?>-content">
                <!-- Map View -->
                <div class="event-map-view" id="<?= $mapId ?>" style="height: <?= htmlspecialchars($height) ?>;"></div>

                <?php if ($showElevation && !empty($mapData['segments'])): ?>
                <!-- Elevation Profile -->
                <div class="event-map-elevation">
                    <canvas id="<?= $mapId ?>-elevation"></canvas>
                </div>
                <?php endif; ?>

                <?php if ($showSegments && !empty($mapData['segments'])): ?>
                <!-- Segment List -->
                <div class="event-map-segments">
                    <h4>Strackor</h4>
                    <ul class="event-map-segment-list">
                        <?php foreach ($mapData['segments'] as $segment): ?>
                        <li class="event-map-segment-item"
                            data-segment-id="<?= $segment['id'] ?>"
                            data-segment-type="<?= htmlspecialchars($segment['segment_type']) ?>">
                            <span class="event-map-segment-color" style="background: <?= htmlspecialchars($segment['color']) ?>"></span>
                            <span class="event-map-segment-name">
                                <?= htmlspecialchars($segment['segment_name'] ?: 'Segment ' . $segment['sequence_number']) ?>
                            </span>
                            <span class="event-map-segment-info">
                                <?= number_format($segment['distance_km'], 1) ?> km
                                <?php if ($segment['segment_type'] === 'stage'): ?>
                                <span class="event-map-segment-elevation">+<?= $segment['elevation_gain_m'] ?>m</span>
                                <?php endif; ?>
                            </span>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php endif; ?>

                <?php if ($showLegend && !empty($groupedPois)): ?>
                <!-- POI Legend -->
                <div class="event-map-legend">
                    <h4>Platser</h4>
                    <ul class="event-map-poi-list">
                        <?php foreach ($groupedPois as $group): ?>
                        <li class="event-map-poi-item" data-poi-type="<?= htmlspecialchars($group['type']) ?>">
                            <span class="event-map-poi-marker" style="background: <?= htmlspecialchars($group['color']) ?>">
                                <?= $group['emoji'] ?>
                            </span>
                            <span class="event-map-poi-label"><?= htmlspecialchars($group['label']) ?></span>
                            <?php if ($group['count'] > 1): ?>
                            <span class="event-map-poi-count">(<?= $group['count'] ?>)</span>
                            <?php endif; ?>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <?php
    }
}

/**
 * Output Leaflet.js dependencies (call once per page in <head>)
 */
if (!function_exists('render_map_head')) {
    function render_map_head(): void {
        ?>
        <!-- Leaflet.js -->
        <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
              integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY="
              crossorigin="">
        <?php
    }
}

/**
 * Output Leaflet.js scripts (call once per page before </body>)
 */
if (!function_exists('render_map_scripts')) {
    function render_map_scripts(): void {
        ?>
        <!-- Leaflet.js -->
        <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
                integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo="
                crossorigin=""></script>
        <!-- TheHUB Event Map Handler -->
        <script src="<?= hub_asset('js/event-map.js') ?>"></script>
        <?php
    }
}

// If this file is included with $eventId set, render the map
if (isset($eventId) && isset($pdo)) {
    $mapOptions = $mapOptions ?? [];
    render_event_map($eventId, $pdo, $mapOptions);
}
