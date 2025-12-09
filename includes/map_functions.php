<?php
/**
 * Map Functions for TheHUB Event Maps
 *
 * Handles GPX parsing, track/segment management, and POI operations.
 *
 * @since 2025-12-09
 */

// Prevent direct access
if (!defined('THEHUB_INIT')) {
    die('Direct access not allowed');
}

// Include POI types configuration
require_once __DIR__ . '/../config/poi_types.php';

// =====================================================
// GPX PARSING
// =====================================================

/**
 * Parse GPX file and extract tracks/segments
 *
 * @param string $filepath Path to GPX file
 * @return array Parsed GPX data with tracks, bounds, and statistics
 * @throws Exception If file not found or invalid GPX
 */
function parseGpxFile($filepath) {
    if (!file_exists($filepath)) {
        throw new Exception("GPX-fil hittades inte: $filepath");
    }

    $xml = @simplexml_load_file($filepath);
    if ($xml === false) {
        throw new Exception("Kunde inte lasa GPX-filen - ogiltigt format");
    }

    // Register namespaces
    $namespaces = $xml->getNamespaces(true);
    $gpx_ns = $namespaces[''] ?? 'http://www.topografix.com/GPX/1/1';
    $xml->registerXPathNamespace('gpx', $gpx_ns);

    $tracks = [];
    $bounds = [
        'north' => -90,
        'south' => 90,
        'east' => -180,
        'west' => 180
    ];

    // Parse tracks (trk elements)
    foreach ($xml->trk as $track) {
        $trackData = [
            'name' => (string)$track->name ?: 'Unnamed Track',
            'segments' => []
        ];

        $segmentIndex = 0;
        foreach ($track->trkseg as $segment) {
            $coordinates = [];
            $elevations = [];
            $totalDistance = 0;
            $elevationGain = 0;
            $elevationLoss = 0;
            $prevPoint = null;

            foreach ($segment->trkpt as $point) {
                $lat = (float)$point['lat'];
                $lng = (float)$point['lon'];
                $ele = isset($point->ele) ? (float)$point->ele : null;

                // Update bounds
                $bounds['north'] = max($bounds['north'], $lat);
                $bounds['south'] = min($bounds['south'], $lat);
                $bounds['east'] = max($bounds['east'], $lng);
                $bounds['west'] = min($bounds['west'], $lng);

                $coord = ['lat' => $lat, 'lng' => $lng];
                if ($ele !== null) {
                    $coord['ele'] = $ele;
                    $elevations[] = $ele;
                }
                $coordinates[] = $coord;

                // Calculate distance and elevation changes
                if ($prevPoint !== null) {
                    $distance = haversineDistance(
                        $prevPoint['lat'], $prevPoint['lng'],
                        $lat, $lng
                    );
                    $totalDistance += $distance;

                    if ($ele !== null && isset($prevPoint['ele'])) {
                        $eleDiff = $ele - $prevPoint['ele'];
                        if ($eleDiff > 0) {
                            $elevationGain += $eleDiff;
                        } else {
                            $elevationLoss += abs($eleDiff);
                        }
                    }
                }

                $prevPoint = ['lat' => $lat, 'lng' => $lng, 'ele' => $ele];
            }

            if (count($coordinates) > 0) {
                $trackData['segments'][] = [
                    'index' => $segmentIndex++,
                    'coordinates' => $coordinates,
                    'elevations' => $elevations,
                    'distance_km' => round($totalDistance, 2),
                    'elevation_gain_m' => round($elevationGain),
                    'elevation_loss_m' => round($elevationLoss),
                    'start' => $coordinates[0],
                    'end' => $coordinates[count($coordinates) - 1]
                ];
            }
        }

        $tracks[] = $trackData;
    }

    return [
        'tracks' => $tracks,
        'bounds' => $bounds,
        'total_segments' => array_sum(array_map(fn($t) => count($t['segments']), $tracks))
    ];
}

/**
 * Haversine distance formula (km)
 *
 * @param float $lat1 Latitude of point 1
 * @param float $lon1 Longitude of point 1
 * @param float $lat2 Latitude of point 2
 * @param float $lon2 Longitude of point 2
 * @return float Distance in kilometers
 */
function haversineDistance($lat1, $lon1, $lat2, $lon2) {
    $earthRadius = 6371; // km

    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);

    $a = sin($dLat/2) * sin($dLat/2) +
         cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
         sin($dLon/2) * sin($dLon/2);

    $c = 2 * atan2(sqrt($a), sqrt(1-$a));

    return $earthRadius * $c;
}

// =====================================================
// TRACK CRUD OPERATIONS
// =====================================================

/**
 * Save track to database
 *
 * @param PDO $pdo Database connection
 * @param int $eventId Event ID
 * @param string $name Track name
 * @param string $gpxFile GPX filename
 * @param array $parsedData Parsed GPX data from parseGpxFile()
 * @return int Track ID
 */
function saveEventTrack($pdo, $eventId, $name, $gpxFile, $parsedData) {
    $pdo->beginTransaction();

    try {
        // Calculate totals
        $totalDistance = 0;
        $totalElevation = 0;

        foreach ($parsedData['tracks'] as $track) {
            foreach ($track['segments'] as $segment) {
                $totalDistance += $segment['distance_km'];
                $totalElevation += $segment['elevation_gain_m'];
            }
        }

        // Insert main track record
        $stmt = $pdo->prepare("
            INSERT INTO event_tracks
            (event_id, name, gpx_file, total_distance_km, total_elevation_m,
             bounds_north, bounds_south, bounds_east, bounds_west)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $eventId,
            $name,
            $gpxFile,
            $totalDistance,
            $totalElevation,
            $parsedData['bounds']['north'],
            $parsedData['bounds']['south'],
            $parsedData['bounds']['east'],
            $parsedData['bounds']['west']
        ]);

        $trackId = $pdo->lastInsertId();

        // Insert segments
        $sequenceNumber = 1;
        foreach ($parsedData['tracks'] as $track) {
            foreach ($track['segments'] as $segment) {
                $stmt = $pdo->prepare("
                    INSERT INTO event_track_segments
                    (track_id, segment_type, segment_name, sequence_number,
                     distance_km, elevation_gain_m, elevation_loss_m,
                     start_lat, start_lng, end_lat, end_lng,
                     coordinates, elevation_data, color)
                    VALUES (?, 'stage', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $trackId,
                    "SS$sequenceNumber",
                    $sequenceNumber,
                    $segment['distance_km'],
                    $segment['elevation_gain_m'],
                    $segment['elevation_loss_m'],
                    $segment['start']['lat'],
                    $segment['start']['lng'],
                    $segment['end']['lat'],
                    $segment['end']['lng'],
                    json_encode($segment['coordinates']),
                    json_encode($segment['elevations']),
                    SEGMENT_COLORS['stage']
                ]);
                $sequenceNumber++;
            }
        }

        $pdo->commit();
        return $trackId;

    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Get track with all segments for an event
 *
 * @param PDO $pdo Database connection
 * @param int $eventId Event ID
 * @return array|null Track data with segments or null if not found
 */
function getEventTrack($pdo, $eventId) {
    $stmt = $pdo->prepare("
        SELECT * FROM event_tracks WHERE event_id = ? LIMIT 1
    ");
    $stmt->execute([$eventId]);
    $track = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$track) {
        return null;
    }

    // Get segments
    $stmt = $pdo->prepare("
        SELECT * FROM event_track_segments
        WHERE track_id = ?
        ORDER BY sequence_number ASC
    ");
    $stmt->execute([$track['id']]);
    $track['segments'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Decode JSON fields
    foreach ($track['segments'] as &$segment) {
        $segment['coordinates'] = json_decode($segment['coordinates'], true);
        $segment['elevation_data'] = json_decode($segment['elevation_data'], true);
    }

    return $track;
}

/**
 * Delete event track and all related data
 *
 * @param PDO $pdo Database connection
 * @param int $trackId Track ID
 * @return bool Success
 */
function deleteEventTrack($pdo, $trackId) {
    // Get track info for file deletion
    $stmt = $pdo->prepare("SELECT gpx_file FROM event_tracks WHERE id = ?");
    $stmt->execute([$trackId]);
    $track = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($track) {
        // Delete GPX file
        $gpxPath = UPLOADS_PATH . '/gpx/' . $track['gpx_file'];
        if (file_exists($gpxPath)) {
            @unlink($gpxPath);
        }
    }

    // Delete track (segments cascade)
    $stmt = $pdo->prepare("DELETE FROM event_tracks WHERE id = ?");
    return $stmt->execute([$trackId]);
}

/**
 * Update segment classification
 *
 * @param PDO $pdo Database connection
 * @param int $segmentId Segment ID
 * @param string $type 'stage' or 'liaison'
 * @param string $name Segment name
 * @param string|null $timingId Optional timing ID
 * @return bool Success
 */
function updateSegmentClassification($pdo, $segmentId, $type, $name, $timingId = null) {
    $color = getSegmentColor($type);

    $stmt = $pdo->prepare("
        UPDATE event_track_segments
        SET segment_type = ?, segment_name = ?, timing_id = ?, color = ?
        WHERE id = ?
    ");
    return $stmt->execute([$type, $name, $timingId, $color, $segmentId]);
}

/**
 * Reorder segments
 *
 * @param PDO $pdo Database connection
 * @param int $trackId Track ID
 * @param array $segmentOrder Array of segment IDs in desired order
 * @return bool Success
 */
function reorderSegments($pdo, $trackId, $segmentOrder) {
    $pdo->beginTransaction();
    try {
        foreach ($segmentOrder as $sequence => $segmentId) {
            $stmt = $pdo->prepare("
                UPDATE event_track_segments
                SET sequence_number = ?
                WHERE id = ? AND track_id = ?
            ");
            $stmt->execute([$sequence + 1, $segmentId, $trackId]);
        }
        $pdo->commit();
        return true;
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}

// =====================================================
// POI CRUD OPERATIONS
// =====================================================

/**
 * Get all POIs for an event
 *
 * @param PDO $pdo Database connection
 * @param int $eventId Event ID
 * @param bool $visibleOnly Only return visible POIs
 * @return array POIs with type info
 */
function getEventPois($pdo, $eventId, $visibleOnly = true) {
    $sql = "SELECT * FROM event_pois WHERE event_id = ?";
    if ($visibleOnly) {
        $sql .= " AND is_visible = 1";
    }
    $sql .= " ORDER BY poi_type, sequence_number";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$eventId]);
    $pois = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Enrich with type info
    foreach ($pois as &$poi) {
        $typeInfo = getPoiType($poi['poi_type']);
        if ($typeInfo) {
            $poi['type_label'] = $typeInfo['label'];
            $poi['type_icon'] = $typeInfo['icon'];
            $poi['type_emoji'] = $typeInfo['emoji'];
            $poi['type_color'] = $typeInfo['color'];
        }
    }

    return $pois;
}

/**
 * Add POI
 *
 * @param PDO $pdo Database connection
 * @param int $eventId Event ID
 * @param array $data POI data (poi_type, lat, lng, label, description, sequence_number)
 * @return int POI ID
 */
function addEventPoi($pdo, $eventId, $data) {
    $stmt = $pdo->prepare("
        INSERT INTO event_pois
        (event_id, poi_type, label, description, lat, lng, sequence_number)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $eventId,
        $data['poi_type'],
        $data['label'] ?? null,
        $data['description'] ?? null,
        $data['lat'],
        $data['lng'],
        $data['sequence_number'] ?? null
    ]);

    return $pdo->lastInsertId();
}

/**
 * Update POI
 *
 * @param PDO $pdo Database connection
 * @param int $poiId POI ID
 * @param array $data POI data
 * @return bool Success
 */
function updateEventPoi($pdo, $poiId, $data) {
    $stmt = $pdo->prepare("
        UPDATE event_pois
        SET poi_type = ?, label = ?, description = ?, lat = ?, lng = ?
        WHERE id = ?
    ");
    return $stmt->execute([
        $data['poi_type'],
        $data['label'] ?? null,
        $data['description'] ?? null,
        $data['lat'],
        $data['lng'],
        $poiId
    ]);
}

/**
 * Delete POI
 *
 * @param PDO $pdo Database connection
 * @param int $poiId POI ID
 * @return bool Success
 */
function deleteEventPoi($pdo, $poiId) {
    $stmt = $pdo->prepare("DELETE FROM event_pois WHERE id = ?");
    return $stmt->execute([$poiId]);
}

/**
 * Toggle POI visibility
 *
 * @param PDO $pdo Database connection
 * @param int $poiId POI ID
 * @return bool Success
 */
function togglePoiVisibility($pdo, $poiId) {
    $stmt = $pdo->prepare("
        UPDATE event_pois SET is_visible = NOT is_visible WHERE id = ?
    ");
    return $stmt->execute([$poiId]);
}

/**
 * Bulk add POIs (for import)
 *
 * @param PDO $pdo Database connection
 * @param int $eventId Event ID
 * @param array $poisData Array of POI data
 * @return int Number of POIs added
 */
function bulkAddPois($pdo, $eventId, $poisData) {
    $pdo->beginTransaction();
    try {
        $count = 0;
        foreach ($poisData as $poi) {
            addEventPoi($pdo, $eventId, $poi);
            $count++;
        }
        $pdo->commit();
        return $count;
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}

// =====================================================
// MAP DATA API
// =====================================================

/**
 * Get complete map data for frontend
 *
 * @param PDO $pdo Database connection
 * @param int $eventId Event ID
 * @return array|null Map data or null if no track exists
 */
function getEventMapData($pdo, $eventId) {
    $track = getEventTrack($pdo, $eventId);
    $pois = getEventPois($pdo, $eventId);

    if (!$track) {
        return null;
    }

    // Build GeoJSON for segments
    $features = [];

    // Add segment lines
    foreach ($track['segments'] as $segment) {
        $coordinates = array_map(function($coord) {
            return [$coord['lng'], $coord['lat']];
        }, $segment['coordinates']);

        $features[] = [
            'type' => 'Feature',
            'geometry' => [
                'type' => 'LineString',
                'coordinates' => $coordinates
            ],
            'properties' => [
                'id' => (int)$segment['id'],
                'type' => 'segment',
                'segment_type' => $segment['segment_type'],
                'name' => $segment['segment_name'],
                'sequence' => (int)$segment['sequence_number'],
                'distance_km' => (float)$segment['distance_km'],
                'elevation_gain' => (int)$segment['elevation_gain_m'],
                'color' => $segment['color'],
                'timing_id' => $segment['timing_id']
            ]
        ];
    }

    // Add POI points
    foreach ($pois as $poi) {
        $features[] = [
            'type' => 'Feature',
            'geometry' => [
                'type' => 'Point',
                'coordinates' => [(float)$poi['lng'], (float)$poi['lat']]
            ],
            'properties' => [
                'id' => (int)$poi['id'],
                'type' => 'poi',
                'poi_type' => $poi['poi_type'],
                'label' => $poi['label'] ?: $poi['type_label'],
                'description' => $poi['description'],
                'icon' => $poi['type_icon'],
                'emoji' => $poi['type_emoji'],
                'color' => $poi['type_color']
            ]
        ];
    }

    return [
        'track' => [
            'id' => (int)$track['id'],
            'name' => $track['name'],
            'total_distance_km' => (float)$track['total_distance_km'],
            'total_elevation_m' => (int)$track['total_elevation_m']
        ],
        'bounds' => [
            [(float)$track['bounds_south'], (float)$track['bounds_west']],
            [(float)$track['bounds_north'], (float)$track['bounds_east']]
        ],
        'geojson' => [
            'type' => 'FeatureCollection',
            'features' => $features
        ],
        'segments' => $track['segments'],
        'pois' => $pois,
        'poi_types' => POI_TYPES
    ];
}

/**
 * Check if event has map data
 *
 * @param PDO $pdo Database connection
 * @param int $eventId Event ID
 * @return bool
 */
function eventHasMap($pdo, $eventId) {
    $stmt = $pdo->prepare("SELECT 1 FROM event_tracks WHERE event_id = ? LIMIT 1");
    $stmt->execute([$eventId]);
    return $stmt->fetch() !== false;
}

/**
 * Get upload path for GPX files
 *
 * @return string Path to GPX upload directory
 */
function getGpxUploadPath() {
    $path = UPLOADS_PATH . '/gpx';
    if (!is_dir($path)) {
        mkdir($path, 0755, true);
    }
    return $path;
}
