/**
 * TheHUB Event Map Handler
 *
 * Interactive map component using Leaflet.js for displaying
 * GPX tracks, segments, and POI markers.
 *
 * @requires Leaflet.js
 * @since 2025-12-09
 */

/**
 * EventMap Class
 * Manages a single event map instance
 */
class EventMap {
    /**
     * Create an EventMap instance
     * @param {string} containerId - The map container element ID
     * @param {Object} mapData - Map data from the server
     */
    constructor(containerId, mapData) {
        this.containerId = containerId;
        this.data = mapData;
        this.map = null;
        this.layers = {
            segments: null,
            pois: null
        };

        this.init();
    }

    /**
     * Initialize the map
     */
    init() {
        this.createMap();
        this.addSegments();
        this.addPois();
        this.createElevationProfile();
        this.setupInteractions();
    }

    /**
     * Create the Leaflet map instance
     */
    createMap() {
        // Initialize Leaflet map
        this.map = L.map(this.containerId, {
            zoomControl: true,
            scrollWheelZoom: false,
            attributionControl: true
        });

        // Add tile layer (OpenStreetMap)
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
            maxZoom: 19
        }).addTo(this.map);

        // Fit to bounds
        if (this.data.bounds && this.data.bounds.length === 2) {
            this.map.fitBounds(this.data.bounds, { padding: [30, 30] });
        }
    }

    /**
     * Add track segments to the map
     */
    addSegments() {
        const segmentFeatures = this.data.geojson.features.filter(
            f => f.properties.type === 'segment'
        );

        if (segmentFeatures.length === 0) return;

        this.layers.segments = L.geoJSON({
            type: 'FeatureCollection',
            features: segmentFeatures
        }, {
            style: (feature) => ({
                color: feature.properties.color,
                weight: 4,
                opacity: 0.85,
                lineCap: 'round',
                lineJoin: 'round'
            }),
            onEachFeature: (feature, layer) => {
                const props = feature.properties;
                const typeLabel = props.segment_type === 'stage' ? 'Tavlingsstracka' : 'Transport';
                const popupContent = `
                    <div class="event-map-popup">
                        <strong>${props.name || 'Segment ' + props.sequence}</strong>
                        <p>
                            ${typeLabel}<br>
                            ${props.distance_km} km
                            ${props.segment_type === 'stage' ? ` &bull; +${props.elevation_gain}m` : ''}
                        </p>
                    </div>
                `;
                layer.bindPopup(popupContent);

                // Hover effects
                layer.on('mouseover', () => {
                    layer.setStyle({ weight: 6, opacity: 1 });
                    this.highlightSegmentInList(props.id);
                });

                layer.on('mouseout', () => {
                    layer.setStyle({ weight: 4, opacity: 0.85 });
                    this.unhighlightSegmentInList(props.id);
                });
            }
        }).addTo(this.map);

        // Add start/finish markers
        this.addStartFinishMarkers();
    }

    /**
     * Add start and finish markers
     */
    addStartFinishMarkers() {
        const segments = this.data.segments;
        if (!segments || segments.length === 0) return;

        // Start marker
        const firstSegment = segments[0];
        if (firstSegment.coordinates && firstSegment.coordinates.length > 0) {
            const startCoord = firstSegment.coordinates[0];

            const startIcon = L.divIcon({
                className: 'event-map-marker event-map-marker-start',
                html: '<div class="event-map-marker-inner">START</div>',
                iconSize: [60, 24],
                iconAnchor: [30, 12]
            });

            L.marker([startCoord.lat, startCoord.lng], { icon: startIcon })
                .addTo(this.map);
        }

        // Finish marker
        const lastSegment = segments[segments.length - 1];
        if (lastSegment.coordinates && lastSegment.coordinates.length > 0) {
            const finishCoord = lastSegment.coordinates[lastSegment.coordinates.length - 1];

            const finishIcon = L.divIcon({
                className: 'event-map-marker event-map-marker-finish',
                html: '<div class="event-map-marker-inner">MAL</div>',
                iconSize: [50, 24],
                iconAnchor: [25, 12]
            });

            L.marker([finishCoord.lat, finishCoord.lng], { icon: finishIcon })
                .addTo(this.map);
        }
    }

    /**
     * Add POI markers to the map
     */
    addPois() {
        const poiFeatures = this.data.geojson.features.filter(
            f => f.properties.type === 'poi'
        );

        if (poiFeatures.length === 0) return;

        this.layers.pois = L.geoJSON({
            type: 'FeatureCollection',
            features: poiFeatures
        }, {
            pointToLayer: (feature, latlng) => {
                const props = feature.properties;

                const icon = L.divIcon({
                    className: 'event-map-poi-icon',
                    html: `<div class="event-map-poi-pin" style="background: ${props.color}">${props.emoji}</div>`,
                    iconSize: [32, 40],
                    iconAnchor: [16, 40],
                    popupAnchor: [0, -40]
                });

                return L.marker(latlng, { icon: icon });
            },
            onEachFeature: (feature, layer) => {
                const props = feature.properties;
                const popupContent = `
                    <div class="event-map-popup">
                        <strong>${props.emoji} ${props.label}</strong>
                        ${props.description ? `<p>${props.description}</p>` : ''}
                    </div>
                `;
                layer.bindPopup(popupContent);
            }
        }).addTo(this.map);
    }

    /**
     * Create elevation profile canvas
     */
    createElevationProfile() {
        const canvas = document.getElementById(this.containerId + '-elevation');
        if (!canvas || !this.data.segments) return;

        // Collect all elevation data
        const elevationPoints = [];
        let cumulativeDistance = 0;

        this.data.segments.forEach((segment) => {
            const coords = segment.coordinates || [];

            coords.forEach((coord, i) => {
                if (i > 0) {
                    const prevCoord = coords[i - 1];
                    const dist = this.haversineDistance(
                        prevCoord.lat, prevCoord.lng,
                        coord.lat, coord.lng
                    );
                    cumulativeDistance += dist;
                }

                const elevation = coord.ele !== undefined ? coord.ele : 0;
                elevationPoints.push({
                    distance: cumulativeDistance,
                    elevation: elevation,
                    segmentType: segment.segment_type,
                    segmentColor: segment.color
                });
            });
        });

        if (elevationPoints.length < 2) return;

        this.drawElevationCanvas(canvas, elevationPoints);
    }

    /**
     * Draw elevation profile on canvas
     * @param {HTMLCanvasElement} canvas
     * @param {Array} points
     */
    drawElevationCanvas(canvas, points) {
        const ctx = canvas.getContext('2d');
        const container = canvas.parentElement;
        const width = container.offsetWidth || 600;
        const height = 80;

        // Set canvas size
        canvas.width = width;
        canvas.height = height;

        // Calculate ranges
        const maxDist = Math.max(...points.map(p => p.distance));
        const minEle = Math.min(...points.map(p => p.elevation));
        const maxEle = Math.max(...points.map(p => p.elevation));
        const eleRange = maxEle - minEle || 1;

        const padding = { top: 5, bottom: 15, left: 5, right: 5 };
        const chartWidth = width - padding.left - padding.right;
        const chartHeight = height - padding.top - padding.bottom;

        // Background
        ctx.fillStyle = 'var(--color-surface, #f9fafb)';
        ctx.fillRect(0, 0, width, height);

        // Draw filled area and line
        let currentColor = points[0].segmentColor;

        // Fill under the line
        ctx.beginPath();
        ctx.moveTo(padding.left, height - padding.bottom);

        points.forEach((point) => {
            const x = padding.left + (point.distance / maxDist) * chartWidth;
            const y = padding.top + (1 - (point.elevation - minEle) / eleRange) * chartHeight;
            ctx.lineTo(x, y);
        });

        ctx.lineTo(width - padding.right, height - padding.bottom);
        ctx.closePath();

        // Gradient fill
        const gradient = ctx.createLinearGradient(0, padding.top, 0, height - padding.bottom);
        gradient.addColorStop(0, 'rgba(97, 206, 112, 0.4)');
        gradient.addColorStop(1, 'rgba(97, 206, 112, 0.05)');
        ctx.fillStyle = gradient;
        ctx.fill();

        // Draw line with segment colors
        currentColor = points[0].segmentColor;
        ctx.beginPath();
        ctx.strokeStyle = currentColor;
        ctx.lineWidth = 2;

        points.forEach((point, i) => {
            const x = padding.left + (point.distance / maxDist) * chartWidth;
            const y = padding.top + (1 - (point.elevation - minEle) / eleRange) * chartHeight;

            if (point.segmentColor !== currentColor && i > 0) {
                ctx.stroke();
                ctx.beginPath();
                ctx.strokeStyle = point.segmentColor;
                currentColor = point.segmentColor;
                ctx.moveTo(x, y);
            } else if (i === 0) {
                ctx.moveTo(x, y);
            } else {
                ctx.lineTo(x, y);
            }
        });
        ctx.stroke();

        // Draw elevation labels
        ctx.fillStyle = 'var(--color-text-secondary, #6b7280)';
        ctx.font = '10px system-ui, sans-serif';
        ctx.textAlign = 'left';
        ctx.fillText(Math.round(maxEle) + 'm', padding.left + 2, padding.top + 10);
        ctx.fillText(Math.round(minEle) + 'm', padding.left + 2, height - padding.bottom - 2);

        // Draw distance label
        ctx.textAlign = 'right';
        ctx.fillText(maxDist.toFixed(1) + ' km', width - padding.right - 2, height - 2);
    }

    /**
     * Calculate Haversine distance between two points
     * @param {number} lat1
     * @param {number} lon1
     * @param {number} lat2
     * @param {number} lon2
     * @returns {number} Distance in kilometers
     */
    haversineDistance(lat1, lon1, lat2, lon2) {
        const R = 6371;
        const dLat = (lat2 - lat1) * Math.PI / 180;
        const dLon = (lon2 - lon1) * Math.PI / 180;
        const a = Math.sin(dLat / 2) * Math.sin(dLat / 2) +
            Math.cos(lat1 * Math.PI / 180) * Math.cos(lat2 * Math.PI / 180) *
            Math.sin(dLon / 2) * Math.sin(dLon / 2);
        const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
        return R * c;
    }

    /**
     * Setup interactive elements
     */
    setupInteractions() {
        const container = document.getElementById(this.containerId + '-container');
        if (!container) return;

        // Segment list hover and click
        container.querySelectorAll('.event-map-segment-item').forEach(item => {
            item.addEventListener('mouseenter', () => {
                const segmentId = item.dataset.segmentId;
                this.highlightSegmentOnMap(segmentId);
            });

            item.addEventListener('mouseleave', () => {
                const segmentId = item.dataset.segmentId;
                this.unhighlightSegmentOnMap(segmentId);
            });

            item.addEventListener('click', () => {
                const segmentId = item.dataset.segmentId;
                this.zoomToSegment(segmentId);
            });
        });

        // POI legend click
        container.querySelectorAll('.event-map-poi-item').forEach(item => {
            item.addEventListener('click', () => {
                const poiType = item.dataset.poiType;
                this.zoomToPois(poiType);
            });
        });

        // Toggle map collapse
        const toggleBtn = container.querySelector('.event-map-toggle');
        if (toggleBtn) {
            toggleBtn.addEventListener('click', () => {
                container.classList.toggle('collapsed');
                const isExpanded = !container.classList.contains('collapsed');
                toggleBtn.setAttribute('aria-expanded', isExpanded);

                // Invalidate map size after transition
                if (isExpanded) {
                    setTimeout(() => {
                        this.map.invalidateSize();
                    }, 350);
                }
            });
        }

        // Enable scroll zoom on focus/click
        this.map.getContainer().addEventListener('click', () => {
            this.map.scrollWheelZoom.enable();
        });

        this.map.getContainer().addEventListener('mouseleave', () => {
            this.map.scrollWheelZoom.disable();
        });
    }

    /**
     * Highlight segment on map
     * @param {number|string} segmentId
     */
    highlightSegmentOnMap(segmentId) {
        if (!this.layers.segments) return;

        this.layers.segments.eachLayer(layer => {
            if (layer.feature && layer.feature.properties.id == segmentId) {
                layer.setStyle({ weight: 6, opacity: 1 });
                layer.bringToFront();
            }
        });
    }

    /**
     * Remove segment highlight on map
     * @param {number|string} segmentId
     */
    unhighlightSegmentOnMap(segmentId) {
        if (!this.layers.segments) return;

        this.layers.segments.eachLayer(layer => {
            if (layer.feature && layer.feature.properties.id == segmentId) {
                layer.setStyle({ weight: 4, opacity: 0.85 });
            }
        });
    }

    /**
     * Highlight segment in list
     * @param {number|string} segmentId
     */
    highlightSegmentInList(segmentId) {
        const container = document.getElementById(this.containerId + '-container');
        if (!container) return;

        const item = container.querySelector(`.event-map-segment-item[data-segment-id="${segmentId}"]`);
        if (item) {
            item.classList.add('highlighted');
        }
    }

    /**
     * Remove segment highlight in list
     * @param {number|string} segmentId
     */
    unhighlightSegmentInList(segmentId) {
        const container = document.getElementById(this.containerId + '-container');
        if (!container) return;

        const item = container.querySelector(`.event-map-segment-item[data-segment-id="${segmentId}"]`);
        if (item) {
            item.classList.remove('highlighted');
        }
    }

    /**
     * Zoom to a specific segment
     * @param {number|string} segmentId
     */
    zoomToSegment(segmentId) {
        if (!this.layers.segments) return;

        this.layers.segments.eachLayer(layer => {
            if (layer.feature && layer.feature.properties.id == segmentId) {
                this.map.fitBounds(layer.getBounds(), { padding: [50, 50] });
            }
        });
    }

    /**
     * Zoom to POIs of a specific type
     * @param {string} poiType
     */
    zoomToPois(poiType) {
        if (!this.layers.pois) return;

        const bounds = L.latLngBounds();
        let found = false;

        this.layers.pois.eachLayer(layer => {
            if (layer.feature && layer.feature.properties.poi_type === poiType) {
                bounds.extend(layer.getLatLng());
                found = true;
            }
        });

        if (found) {
            this.map.fitBounds(bounds, { padding: [50, 50], maxZoom: 16 });
        }
    }
}

/**
 * Auto-initialize maps on page load
 */
document.addEventListener('DOMContentLoaded', () => {
    // Find all map containers
    document.querySelectorAll('.event-map-container').forEach(container => {
        const mapDataAttr = container.dataset.mapData;
        if (!mapDataAttr) return;

        try {
            const mapData = JSON.parse(mapDataAttr);
            const mapElement = container.querySelector('.event-map-view');

            if (mapElement && mapElement.id) {
                // Store instance for potential later access
                container._eventMap = new EventMap(mapElement.id, mapData);
            }
        } catch (e) {
            console.error('Error initializing event map:', e);
        }
    });
});

// Export for manual initialization
if (typeof window !== 'undefined') {
    window.EventMap = EventMap;
}
