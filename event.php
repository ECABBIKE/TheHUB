<?php
require_once __DIR__ . '/config.php';

$db = getDB();

// Get event ID from URL
$eventId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$eventId) {
    header('Location: /events.php');
    exit;
}

// Fetch event details
$event = $db->getRow("
    SELECT
        e.*,
        s.name as series_name,
        s.logo as series_logo,
        v.name as venue_name,
        v.city as venue_city,
        v.address as venue_address
    FROM events e
    LEFT JOIN series s ON e.series_id = s.id
    LEFT JOIN venues v ON e.venue_id = v.id
    WHERE e.id = ?
", [$eventId]);

if (!$event) {
    header('Location: /events.php');
    exit;
}

// Get active tab (default: info)
$activeTab = isset($_GET['tab']) ? $_GET['tab'] : 'info';

// Fetch registered participants for this event
$registrations = $db->getAll("
    SELECT
        reg.*,
        r.id as rider_id,
        r.firstname,
        r.lastname,
        c.name as club_name
    FROM event_registrations reg
    LEFT JOIN riders r ON reg.rider_id = r.id
    LEFT JOIN clubs c ON r.club_id = c.id
    WHERE reg.event_id = ?
    ORDER BY reg.registration_date ASC
", [$eventId]);

$totalRegistrations = count($registrations);
$confirmedRegistrations = count(array_filter($registrations, function($r) {
    return $r['status'] === 'confirmed';
}));

// Fetch results count for this event (for tab badge)
$results = $db->getAll("
    SELECT id
    FROM results
    WHERE event_id = ?
", [$eventId]);

$pageTitle = $event['name'];
$pageType = 'public';
include __DIR__ . '/includes/layout-header.php';
?>

    <main class="gs-main-content">
        <div class="gs-container">

            <!-- Event Header -->
            <div class="gs-card gs-mb-xl">
                <div class="gs-card-content event-header-content">
                    <!-- Back Button -->
                    <div class="gs-mb-lg">
                        <a href="/events.php" class="gs-btn gs-btn-outline gs-btn-sm">
                            <i data-lucide="arrow-left" class="gs-icon-md"></i>
                            Tillbaka till tävlingar
                        </a>
                    </div>

                    <div class="event-header-layout">
                        <?php if ($event['series_logo']): ?>
                            <div class="event-logo">
                                <img src="<?= h($event['series_logo']) ?>"
                                     alt="<?= h($event['series_name'] ?? 'Serie') ?>">
                            </div>
                        <?php endif; ?>

                        <div class="event-info">
                            <h1 class="gs-h1 gs-text-primary gs-mb-sm event-title">
                                <?= h($event['name']) ?>
                            </h1>

                            <div class="gs-flex gs-gap-md gs-flex-wrap gs-mb-md event-meta">
                                <div class="gs-flex gs-items-center gs-gap-xs">
                                    <i data-lucide="calendar" class="gs-icon-md"></i>
                                    <span class="gs-text-secondary">
                                        <?= date('l j F Y', strtotime($event['date'])) ?>
                                    </span>
                                </div>

                                <?php if ($event['venue_name']): ?>
                                    <div class="gs-flex gs-items-center gs-gap-xs">
                                        <i data-lucide="map-pin" class="gs-icon-md"></i>
                                        <span class="gs-text-secondary">
                                            <?= h($event['venue_name']) ?>
                                            <?php if ($event['venue_city']): ?>
                                                , <?= h($event['venue_city']) ?>
                                            <?php endif; ?>
                                        </span>
                                    </div>
                                <?php elseif ($event['location']): ?>
                                    <div class="gs-flex gs-items-center gs-gap-xs">
                                        <i data-lucide="map-pin" class="gs-icon-md"></i>
                                        <span class="gs-text-secondary"><?= h($event['location']) ?></span>
                                    </div>
                                <?php endif; ?>

                                <?php if ($event['series_name']): ?>
                                    <div class="gs-flex gs-items-center gs-gap-xs">
                                        <i data-lucide="award" class="gs-icon-md"></i>
                                        <span class="gs-badge gs-badge-primary">
                                            <?= h($event['series_name']) ?>
                                        </span>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <!-- Event Stats -->
                            <div class="event-stats">
                                <div class="event-stat-full">
                                    <span class="gs-text-sm gs-text-secondary">Anmälda: </span>
                                    <strong class="gs-text-primary"><?= $totalRegistrations ?></strong>
                                </div>
                                <div class="event-stat-half">
                                    <span class="gs-text-sm gs-text-secondary">Bekräftade: </span>
                                    <strong class="gs-text-success"><?= $confirmedRegistrations ?></strong>
                                </div>
                                <div class="event-stat-half">
                                    <span class="gs-text-sm gs-text-secondary">Resultat: </span>
                                    <strong class="gs-text-primary"><?= count($results) ?></strong>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Tab Navigation - Mobile Responsive -->
            <style>
            .event-tabs-wrapper {
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
                margin: 0 -1rem;
                padding: 0 1rem;
            }
            .event-tabs {
                display: flex;
                gap: 0.25rem;
                min-width: max-content;
                padding-bottom: 0.5rem;
            }
            .event-tab {
                display: flex;
                align-items: center;
                gap: 0.375rem;
                padding: 0.625rem 0.875rem;
                border-radius: 0.5rem;
                font-size: 0.8125rem;
                font-weight: 500;
                text-decoration: none;
                white-space: nowrap;
                background: var(--gs-bg-secondary);
                color: var(--gs-text-secondary);
                border: 1px solid var(--gs-border);
                transition: all 0.2s;
            }
            .event-tab:hover {
                background: var(--gs-bg-tertiary);
                color: var(--gs-text-primary);
            }
            .event-tab.active {
                background: var(--gs-primary);
                color: white;
                border-color: var(--gs-primary);
            }
            .event-tab i {
                width: 14px;
                height: 14px;
            }
            @media (min-width: 768px) {
                .event-tabs-wrapper {
                    margin: 0;
                    padding: 0;
                    overflow-x: visible;
                }
                .event-tabs {
                    flex-wrap: wrap;
                }
            }
            </style>

            <div class="event-tabs-wrapper gs-mb-lg">
                <div class="event-tabs">
                    <a href="?id=<?= $eventId ?>&tab=info"
                       class="event-tab <?= $activeTab === 'info' ? 'active' : '' ?>">
                        <i data-lucide="info"></i>
                        Information
                    </a>

                    <?php if (!empty($event['pm_content']) || !empty($event['pm_use_global'])): ?>
                    <a href="?id=<?= $eventId ?>&tab=pm"
                       class="event-tab <?= $activeTab === 'pm' ? 'active' : '' ?>">
                        <i data-lucide="clipboard-list"></i>
                        PM
                    </a>
                    <?php endif; ?>

                    <?php if (!empty($event['jury_communication']) || !empty($event['jury_use_global'])): ?>
                    <a href="?id=<?= $eventId ?>&tab=jury"
                       class="event-tab <?= $activeTab === 'jury' ? 'active' : '' ?>">
                        <i data-lucide="gavel"></i>
                        Jurykommuniké
                    </a>
                    <?php endif; ?>

                    <?php if (!empty($event['competition_schedule']) || !empty($event['schedule_use_global'])): ?>
                    <a href="?id=<?= $eventId ?>&tab=schema"
                       class="event-tab <?= $activeTab === 'schema' ? 'active' : '' ?>">
                        <i data-lucide="calendar-clock"></i>
                        Tävlingsschema
                    </a>
                    <?php endif; ?>

                    <?php if (!empty($event['start_times']) || !empty($event['start_times_use_global'])): ?>
                    <a href="?id=<?= $eventId ?>&tab=starttider"
                       class="event-tab <?= $activeTab === 'starttider' ? 'active' : '' ?>">
                        <i data-lucide="clock"></i>
                        Starttider
                    </a>
                    <?php endif; ?>

                    <?php if (!empty($event['map_content']) || !empty($event['map_image_url']) || !empty($event['map_use_global'])): ?>
                    <a href="?id=<?= $eventId ?>&tab=karta"
                       class="event-tab <?= $activeTab === 'karta' ? 'active' : '' ?>">
                        <i data-lucide="map"></i>
                        Karta
                    </a>
                    <?php endif; ?>

                    <?php if (!empty($results)): ?>
                    <a href="/event-results.php?id=<?= $eventId ?>"
                       class="event-tab">
                        <i data-lucide="trophy"></i>
                        Resultat
                        <span class="gs-badge gs-badge-accent gs-badge-sm"><?= count($results) ?></span>
                    </a>
                    <?php endif; ?>

                    <a href="?id=<?= $eventId ?>&tab=anmalda"
                       class="event-tab <?= $activeTab === 'anmalda' ? 'active' : '' ?>">
                        <i data-lucide="users"></i>
                        Anmälda
                        <span class="gs-badge gs-badge-secondary gs-badge-sm"><?= $totalRegistrations ?></span>
                    </a>

                    <a href="?id=<?= $eventId ?>&tab=anmalan"
                       class="event-tab <?= $activeTab === 'anmalan' ? 'active' : '' ?>">
                        <i data-lucide="user-plus"></i>
                        Anmälan
                    </a>
                </div>
            </div>

            <!-- Tab Content -->
            <?php if ($activeTab === 'info'): ?>
                <!-- INFORMATION TAB -->
                <div class="gs-card gs-mb-xl">
                    <div class="gs-card-header">
                        <h2 class="gs-h3 gs-text-primary">
                            <i data-lucide="info"></i>
                            Event-information
                        </h2>
                    </div>
                    <div class="gs-card-content">
                        <div class="gs-grid gs-grid-cols-1 gs-md-grid-cols-2 gs-gap-lg">
                            <!-- Left Column -->
                            <div>
                                <?php if (!empty($event['description'])): ?>
                                    <div class="gs-mb-lg">
                                        <h3 class="gs-h5 gs-mb-sm gs-text-primary">
                                            <i data-lucide="file-text" class="gs-icon-14"></i>
                                            Beskrivning
                                        </h3>
                                        <p class="gs-text-secondary">
                                            <?= nl2br(h($event['description'])) ?>
                                        </p>
                                    </div>
                                <?php endif; ?>

                                <?php if (!empty($event['schedule'])): ?>
                                    <div class="gs-mb-lg">
                                        <h3 class="gs-h5 gs-mb-sm gs-text-primary">
                                            <i data-lucide="clock" class="gs-icon-14"></i>
                                            Schema
                                        </h3>
                                        <div class="gs-text-secondary">
                                            <?= nl2br(h($event['schedule'])) ?>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <?php if (!empty($event['course_description'])): ?>
                                    <div class="gs-mb-lg">
                                        <h3 class="gs-h5 gs-mb-sm gs-text-primary">
                                            <i data-lucide="route" class="gs-icon-14"></i>
                                            Bana
                                        </h3>
                                        <p class="gs-text-secondary">
                                            <?= nl2br(h($event['course_description'])) ?>
                                        </p>
                                        <?php if (!empty($event['distance']) || !empty($event['elevation_gain'])): ?>
                                            <div class="gs-flex gs-gap-md gs-mt-sm">
                                                <?php if (!empty($event['distance'])): ?>
                                                    <div>
                                                        <span class="gs-text-sm gs-text-secondary">Distans: </span>
                                                        <strong><?= $event['distance'] ?> km</strong>
                                                    </div>
                                                <?php endif; ?>
                                                <?php if (!empty($event['elevation_gain'])): ?>
                                                    <div>
                                                        <span class="gs-text-sm gs-text-secondary">Höjdmeter: </span>
                                                        <strong><?= $event['elevation_gain'] ?> m</strong>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                        <?php if (!empty($event['course_map_url']) || !empty($event['gpx_file_url'])): ?>
                                            <div class="gs-flex gs-gap-sm gs-mt-sm">
                                                <?php if (!empty($event['course_map_url'])): ?>
                                                    <a href="<?= h($event['course_map_url']) ?>"
                                                       target="_blank"
                                                       class="gs-btn gs-btn-sm gs-btn-outline">
                                                        <i data-lucide="map" class="gs-icon-14"></i>
                                                        Bankarta
                                                    </a>
                                                <?php endif; ?>
                                                <?php if (!empty($event['gpx_file_url'])): ?>
                                                    <a href="<?= h($event['gpx_file_url']) ?>"
                                                       download
                                                       class="gs-btn gs-btn-sm gs-btn-outline">
                                                        <i data-lucide="download" class="gs-icon-14"></i>
                                                        Ladda ner GPX
                                                    </a>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>

                                <?php if (!empty($event['safety_rules'])): ?>
                                    <div class="gs-mb-lg">
                                        <h3 class="gs-h5 gs-mb-sm gs-text-primary">
                                            <i data-lucide="shield" class="gs-icon-14"></i>
                                            Säkerhet & Regler
                                        </h3>
                                        <div class="gs-text-secondary">
                                            <?= nl2br(h($event['safety_rules'])) ?>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <?php if (!empty($event['organizer'])): ?>
                                    <div class="gs-mb-lg">
                                        <h3 class="gs-h5 gs-mb-sm gs-text-primary">
                                            <i data-lucide="user" class="gs-icon-14"></i>
                                            Arrangör
                                        </h3>
                                        <p class="gs-text-secondary">
                                            <?= h($event['organizer']) ?>
                                        </p>
                                        <?php if (!empty($event['contact_email']) || !empty($event['contact_phone'])): ?>
                                            <div class="gs-mt-sm">
                                                <?php if (!empty($event['contact_email'])): ?>
                                                    <p class="gs-text-sm gs-mb-xs">
                                                        <i data-lucide="mail" class="gs-icon-sm"></i>
                                                        <a href="mailto:<?= h($event['contact_email']) ?>" class="gs-link">
                                                            <?= h($event['contact_email']) ?>
                                                        </a>
                                                    </p>
                                                <?php endif; ?>
                                                <?php if (!empty($event['contact_phone'])): ?>
                                                    <p class="gs-text-sm">
                                                        <i data-lucide="phone" class="gs-icon-sm"></i>
                                                        <a href="tel:<?= h($event['contact_phone']) ?>" class="gs-link">
                                                            <?= h($event['contact_phone']) ?>
                                                        </a>
                                                    </p>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <!-- Right Column -->
                            <div>
                                <?php if (!empty($event['practical_info'])): ?>
                                    <div class="gs-mb-lg">
                                        <h3 class="gs-h5 gs-mb-sm gs-text-primary">
                                            <i data-lucide="info" class="gs-icon-14"></i>
                                            Praktisk information
                                        </h3>
                                        <div class="gs-text-secondary">
                                            <?= nl2br(h($event['practical_info'])) ?>
                                        </div>
                                        <?php if (!empty($event['entry_fee']) || !empty($event['max_participants'])): ?>
                                            <div class="gs-mt-sm">
                                                <?php if (!empty($event['entry_fee'])): ?>
                                                    <p class="gs-text-sm gs-mb-xs">
                                                        <strong>Startavgift:</strong> <?= $event['entry_fee'] ?> kr
                                                    </p>
                                                <?php endif; ?>
                                                <?php if (!empty($event['max_participants'])): ?>
                                                    <p class="gs-text-sm gs-mb-xs">
                                                        <strong>Max deltagare:</strong> <?= $event['max_participants'] ?>
                                                    </p>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>

                                <?php if (!empty($event['parking_info'])): ?>
                                    <div class="gs-mb-lg">
                                        <h3 class="gs-h5 gs-mb-sm gs-text-primary">
                                            <i data-lucide="car" class="gs-icon-14"></i>
                                            Parkering
                                        </h3>
                                        <div class="gs-text-secondary">
                                            <?= nl2br(h($event['parking_info'])) ?>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <?php if (!empty($event['accommodation_info'])): ?>
                                    <div class="gs-mb-lg">
                                        <h3 class="gs-h5 gs-mb-sm gs-text-primary">
                                            <i data-lucide="bed" class="gs-icon-14"></i>
                                            Boende
                                        </h3>
                                        <div class="gs-text-secondary">
                                            <?= nl2br(h($event['accommodation_info'])) ?>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <?php if (!empty($event['food_info'])): ?>
                                    <div class="gs-mb-lg">
                                        <h3 class="gs-h5 gs-mb-sm gs-text-primary">
                                            <i data-lucide="utensils" class="gs-icon-14"></i>
                                            Mat & Dryck
                                        </h3>
                                        <div class="gs-text-secondary">
                                            <?= nl2br(h($event['food_info'])) ?>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <?php if (!empty($event['prizes_info'])): ?>
                                    <div class="gs-mb-lg">
                                        <h3 class="gs-h5 gs-mb-sm gs-text-primary">
                                            <i data-lucide="award" class="gs-icon-14"></i>
                                            Priser
                                        </h3>
                                        <div class="gs-text-secondary">
                                            <?= nl2br(h($event['prizes_info'])) ?>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <?php if (!empty($event['sponsors'])): ?>
                                    <div class="gs-mb-lg">
                                        <h3 class="gs-h5 gs-mb-sm gs-text-primary">
                                            <i data-lucide="handshake" class="gs-icon-14"></i>
                                            Sponsorer
                                        </h3>
                                        <div class="gs-text-secondary">
                                            <?= nl2br(h($event['sponsors'])) ?>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <?php if (!empty($event['website'])): ?>
                                    <div>
                                        <a href="<?= h($event['website']) ?>"
                                           target="_blank"
                                           rel="noopener noreferrer"
                                           class="gs-btn gs-btn-outline gs-w-full">
                                            <i data-lucide="globe" class="gs-icon-14"></i>
                                            Event-webbplats
                                        </a>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

            <?php elseif ($activeTab === 'pm'): ?>
                <!-- PM TAB -->
                <div class="gs-card gs-mb-xl">
                    <div class="gs-card-header">
                        <h2 class="gs-h3 gs-text-primary">
                            <i data-lucide="clipboard-list"></i>
                            PM (Promemoria)
                        </h2>
                    </div>
                    <div class="gs-card-content">
                        <?php
                        $pmContent = $event['pm_content'] ?? '';
                        if ($event['pm_use_global'] ?? false) {
                            $globalPm = $db->getRow("SELECT content FROM global_texts WHERE field_key = 'pm_content'");
                            $pmContent = $globalPm['content'] ?? $pmContent;
                        }
                        ?>
                        <?php if ($pmContent): ?>
                            <div class="gs-text-secondary">
                                <?= nl2br(h($pmContent)) ?>
                            </div>
                        <?php else: ?>
                            <p class="gs-text-secondary">Inget PM tillgängligt för detta event.</p>
                        <?php endif; ?>
                    </div>
                </div>

            <?php elseif ($activeTab === 'jury'): ?>
                <!-- JURY COMMUNICATION TAB -->
                <div class="gs-card gs-mb-xl">
                    <div class="gs-card-header">
                        <h2 class="gs-h3 gs-text-primary">
                            <i data-lucide="gavel"></i>
                            Jurykommuniké
                        </h2>
                    </div>
                    <div class="gs-card-content">
                        <?php
                        $juryContent = $event['jury_communication'] ?? '';
                        if ($event['jury_use_global'] ?? false) {
                            $globalJury = $db->getRow("SELECT content FROM global_texts WHERE field_key = 'jury_communication'");
                            $juryContent = $globalJury['content'] ?? $juryContent;
                        }
                        ?>
                        <?php if ($juryContent): ?>
                            <div class="gs-text-secondary">
                                <?= nl2br(h($juryContent)) ?>
                            </div>
                        <?php else: ?>
                            <p class="gs-text-secondary">Ingen jurykommuniké tillgänglig.</p>
                        <?php endif; ?>
                    </div>
                </div>

            <?php elseif ($activeTab === 'schema'): ?>
                <!-- COMPETITION SCHEDULE TAB -->
                <div class="gs-card gs-mb-xl">
                    <div class="gs-card-header">
                        <h2 class="gs-h3 gs-text-primary">
                            <i data-lucide="calendar-clock"></i>
                            Tävlingsschema
                        </h2>
                    </div>
                    <div class="gs-card-content">
                        <?php
                        $scheduleContent = $event['competition_schedule'] ?? '';
                        if ($event['schedule_use_global'] ?? false) {
                            $globalSchedule = $db->getRow("SELECT content FROM global_texts WHERE field_key = 'competition_schedule'");
                            $scheduleContent = $globalSchedule['content'] ?? $scheduleContent;
                        }
                        ?>
                        <?php if ($scheduleContent): ?>
                            <div class="gs-text-secondary">
                                <?= nl2br(h($scheduleContent)) ?>
                            </div>
                        <?php else: ?>
                            <p class="gs-text-secondary">Inget tävlingsschema tillgängligt.</p>
                        <?php endif; ?>
                    </div>
                </div>

            <?php elseif ($activeTab === 'starttider'): ?>
                <!-- START TIMES TAB -->
                <div class="gs-card gs-mb-xl">
                    <div class="gs-card-header">
                        <h2 class="gs-h3 gs-text-primary">
                            <i data-lucide="clock"></i>
                            Starttider
                        </h2>
                    </div>
                    <div class="gs-card-content">
                        <?php
                        $startContent = $event['start_times'] ?? '';
                        if ($event['start_times_use_global'] ?? false) {
                            $globalStart = $db->getRow("SELECT content FROM global_texts WHERE field_key = 'start_times'");
                            $startContent = $globalStart['content'] ?? $startContent;
                        }
                        ?>
                        <?php if ($startContent): ?>
                            <div class="gs-text-secondary">
                                <?= nl2br(h($startContent)) ?>
                            </div>
                        <?php else: ?>
                            <p class="gs-text-secondary">Inga starttider publicerade ännu.</p>
                        <?php endif; ?>
                    </div>
                </div>

            <?php elseif ($activeTab === 'karta'): ?>
                <!-- MAP TAB -->
                <div class="gs-card gs-mb-xl">
                    <div class="gs-card-header">
                        <h2 class="gs-h3 gs-text-primary">
                            <i data-lucide="map"></i>
                            Karta
                        </h2>
                    </div>
                    <div class="gs-card-content">
                        <?php if (!empty($event['map_image_url'])): ?>
                            <div class="gs-mb-lg">
                                <img src="<?= h($event['map_image_url']) ?>"
                                     alt="Karta"
                                     style="max-width: 100%; height: auto; border-radius: 0.5rem;">
                            </div>
                        <?php endif; ?>

                        <?php
                        $mapContent = $event['map_content'] ?? '';
                        if ($event['map_use_global'] ?? false) {
                            $globalMap = $db->getRow("SELECT content FROM global_texts WHERE field_key = 'map_content'");
                            $mapContent = $globalMap['content'] ?? $mapContent;
                        }
                        ?>
                        <?php if ($mapContent): ?>
                            <div class="gs-text-secondary">
                                <?= nl2br(h($mapContent)) ?>
                            </div>
                        <?php elseif (empty($event['map_image_url'])): ?>
                            <p class="gs-text-secondary">Ingen karta tillgänglig.</p>
                        <?php endif; ?>

                        <?php if (!empty($event['venue_coordinates'])): ?>
                            <div class="gs-mt-lg">
                                <a href="https://www.google.com/maps?q=<?= urlencode($event['venue_coordinates']) ?>"
                                   target="_blank"
                                   class="gs-btn gs-btn-outline">
                                    <i data-lucide="navigation"></i>
                                    Öppna i Google Maps
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

            <?php elseif ($activeTab === 'anmalda'): ?>
                <!-- REGISTERED PARTICIPANTS TAB -->
                <div class="gs-card gs-mb-xl">
                    <div class="gs-card-header">
                        <h2 class="gs-h3 gs-text-primary">
                            <i data-lucide="users"></i>
                            Anmälda deltagare
                            <span class="gs-badge gs-badge-primary gs-ml-sm">
                                <?= $totalRegistrations ?> anmälda
                            </span>
                            <span class="gs-badge gs-badge-success gs-ml-xs">
                                <?= $confirmedRegistrations ?> bekräftade
                            </span>
                        </h2>
                    </div>
                    <div class="gs-card-content">
                        <?php if (empty($registrations)): ?>
                            <div class="gs-alert gs-alert-warning">
                                <p>Inga anmälningar ännu. Var först med att anmäla dig!</p>
                            </div>
                        <?php else: ?>
                            <div class="gs-table-responsive">
                                <table class="gs-table">
                                    <thead>
                                        <tr>
                                            <th>Nr</th>
                                            <th>Namn</th>
                                            <th>Klubb</th>
                                            <th>Kategori</th>
                                            <th>Status</th>
                                            <th>Anmäld</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($registrations as $index => $reg): ?>
                                            <tr>
                                                <td class="gs-table-center"><?= $index + 1 ?></td>
                                                <td>
                                                    <strong>
                                                        <?= h($reg['first_name']) ?> <?= h($reg['last_name']) ?>
                                                    </strong>
                                                    <?php if ($reg['birth_year']): ?>
                                                        <div class="gs-text-sm gs-text-secondary">
                                                            <?= calculateAge($reg['birth_year']) ?> år
                                                            <?php if ($reg['gender']): ?>
                                                                • <?= $reg['gender'] == 'M' ? 'Herr' : ($reg['gender'] == 'F' ? 'Dam' : 'Övrigt') ?>
                                                            <?php endif; ?>
                                                        </div>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if (!empty($reg['club_name'])): ?>
                                                        <span class="gs-badge gs-badge-secondary gs-badge-sm">
                                                            <?= h($reg['club_name']) ?>
                                                        </span>
                                                    <?php else: ?>
                                                        <span class="gs-text-secondary">-</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?= !empty($reg['category']) ? h($reg['category']) : '<span class="gs-text-secondary">-</span>' ?>
                                                </td>
                                                <td>
                                                    <?php
                                                    $statusBadge = 'gs-badge-secondary';
                                                    $statusText = ucfirst($reg['status']);
                                                    if ($reg['status'] === 'confirmed') {
                                                        $statusBadge = 'gs-badge-success';
                                                        $statusText = 'Bekräftad';
                                                    } elseif ($reg['status'] === 'pending') {
                                                        $statusBadge = 'gs-badge-warning';
                                                        $statusText = 'Väntande';
                                                    } elseif ($reg['status'] === 'waitlist') {
                                                        $statusBadge = 'gs-badge-accent';
                                                        $statusText = 'Reserv';
                                                    } elseif ($reg['status'] === 'cancelled') {
                                                        $statusBadge = 'gs-badge-danger';
                                                        $statusText = 'Avbokad';
                                                    }
                                                    ?>
                                                    <span class="gs-badge <?= $statusBadge ?> gs-badge-sm">
                                                        <?= $statusText ?>
                                                    </span>
                                                </td>
                                                <td class="gs-text-sm gs-text-secondary">
                                                    <?= date('d M Y', strtotime($reg['registration_date'])) ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

            <?php elseif ($activeTab === 'anmalan'): ?>
                <!-- REGISTRATION FORM TAB -->
                <div class="gs-card gs-mb-xl">
                    <div class="gs-card-header">
                        <h2 class="gs-h3 gs-text-primary">
                            <i data-lucide="user-plus"></i>
                            Anmälan till <?= h($event['name']) ?>
                        </h2>
                    </div>
                    <div class="gs-card-content">
                        <?php if (!empty($event['registration_deadline']) && strtotime($event['registration_deadline']) < time()): ?>
                            <div class="gs-alert gs-alert-danger">
                                <h3 class="gs-h5 gs-mb-sm">Anmälan stängd</h3>
                                <p>Anmälan stängde <?= date('d M Y', strtotime($event['registration_deadline'])) ?>.</p>
                            </div>
                        <?php elseif (!empty($event['registration_url'])): ?>
                            <div class="gs-alert gs-alert-primary gs-mb-lg">
                                <h3 class="gs-h5 gs-mb-sm">
                                    <i data-lucide="external-link" class="gs-icon-14"></i>
                                    Extern anmälan
                                </h3>
                                <p class="gs-mb-sm">
                                    Anmälan till detta event görs via en extern webbplats.
                                </p>
                                <?php if (!empty($event['registration_deadline'])): ?>
                                    <p class="gs-text-sm gs-mb-sm">
                                        <strong>Sista anmälan:</strong> <?= date('d M Y', strtotime($event['registration_deadline'])) ?>
                                    </p>
                                <?php endif; ?>
                                <a href="<?= h($event['registration_url']) ?>"
                                   target="_blank"
                                   rel="noopener noreferrer"
                                   class="gs-btn gs-btn-primary">
                                    <i data-lucide="external-link" class="gs-icon-14"></i>
                                    Gå till anmälan
                                </a>
                            </div>
                        <?php else: ?>
                            <!-- Registration form coming soon -->
                            <div class="gs-alert gs-alert-info">
                                <h3 class="gs-h5 gs-mb-sm">Anmälningsformulär</h3>
                                <p>Anmälningsfunktionen är under utveckling och kommer snart att vara tillgänglig här.</p>
                                <?php if (!empty($event['registration_deadline'])): ?>
                                    <p class="gs-text-sm gs-mt-sm">
                                        <strong>Planerad sista anmälan:</strong> <?= date('d M Y', strtotime($event['registration_deadline'])) ?>
                                    </p>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </main>

<?php include __DIR__ . '/includes/layout-footer.php'; ?>
