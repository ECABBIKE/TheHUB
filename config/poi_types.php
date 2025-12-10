<?php
/**
 * POI Type Configuration for TheHUB Event Maps
 *
 * This file defines all available POI (Point of Interest) types
 * with their labels, icons, and colors.
 *
 * @since 2025-12-09
 */

// Prevent direct access
if (!defined('THEHUB_INIT')) {
    die('Direct access not allowed');
}

/**
 * POI Types Definition
 * Each type has: label (Swedish), icon (emoji), color (hex), description
 */
define('POI_TYPES', [
    'start' => [
        'label' => 'Start',
        'icon' => 'flag',
        'emoji' => '🏁',
        'color' => '#61CE70',
        'description' => 'Startplats'
    ],
    'finish' => [
        'label' => 'Mal',
        'icon' => 'trophy',
        'emoji' => '🏆',
        'color' => '#61CE70',
        'description' => 'Malgang'
    ],
    'water' => [
        'label' => 'Vatten',
        'icon' => 'droplet',
        'emoji' => '💧',
        'color' => '#3C78D8',
        'description' => 'Vattenpunkt'
    ],
    'depot' => [
        'label' => 'Depa',
        'icon' => 'wrench',
        'emoji' => '🔧',
        'color' => '#F4B400',
        'description' => 'Service/depaomrade'
    ],
    'spectator' => [
        'label' => 'Publikplats',
        'icon' => 'users',
        'emoji' => '👥',
        'color' => '#0F9D58',
        'description' => 'Rekommenderad publikplats'
    ],
    'food' => [
        'label' => 'Mat',
        'icon' => 'utensils',
        'emoji' => '🍔',
        'color' => '#674EA7',
        'description' => 'Restaurang/matstalle'
    ],
    'bike_wash' => [
        'label' => 'Cykeltvatt',
        'icon' => 'spray-can',
        'emoji' => '🚿',
        'color' => '#46BDC6',
        'description' => 'Cykeltvattstation'
    ],
    'tech_zone' => [
        'label' => 'Teknisk zon',
        'icon' => 'settings',
        'emoji' => '⚙️',
        'color' => '#EA4335',
        'description' => 'Teknisk assistans'
    ],
    'feed_zone' => [
        'label' => 'Langning',
        'icon' => 'package',
        'emoji' => '🍌',
        'color' => '#FBBC04',
        'description' => 'Langningszon'
    ],
    'parking' => [
        'label' => 'Parkering',
        'icon' => 'car',
        'emoji' => '🅿️',
        'color' => '#0B5394',
        'description' => 'Parkeringsplats'
    ],
    'aid_station' => [
        'label' => 'Hjalpstation',
        'icon' => 'heart-pulse',
        'emoji' => '➕',
        'color' => '#990000',
        'description' => 'Forsta hjalpen'
    ],
    'information' => [
        'label' => 'Information',
        'icon' => 'info',
        'emoji' => 'ℹ️',
        'color' => '#0B5394',
        'description' => 'Informationspunkt'
    ]
]);

/**
 * Segment type colors
 */
define('SEGMENT_COLORS', [
    'stage' => '#EF4444',    // Red for SS/tävlingssträcka
    'liaison' => '#9CA3AF',  // Gray for transport
    'lift' => '#F59E0B'      // Orange for lift
]);

/**
 * Get POI type info
 *
 * @param string $type POI type key
 * @return array|null POI type configuration or null if not found
 */
function getPoiType($type) {
    $types = POI_TYPES;
    return $types[$type] ?? null;
}

/**
 * Get all POI types for admin dropdown
 *
 * @return array Associative array of type => label with emoji
 */
function getPoiTypesForSelect() {
    $options = [];
    foreach (POI_TYPES as $key => $config) {
        $options[$key] = $config['emoji'] . ' ' . $config['label'];
    }
    return $options;
}

/**
 * Get segment color by type
 *
 * @param string $type 'stage' or 'liaison'
 * @return string Hex color code
 */
function getSegmentColor($type) {
    return SEGMENT_COLORS[$type] ?? SEGMENT_COLORS['stage'];
}

/**
 * Get all POI types as array for JavaScript
 *
 * @return array Full POI types configuration
 */
function getPoiTypesArray() {
    return POI_TYPES;
}
