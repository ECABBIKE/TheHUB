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

// Get active tab - default to 'anmalan' if registration deadline exists and hasn't passed
$defaultTab = 'info';
if (!isset($_GET['tab'])) {
    // Check if registration is still open (deadline exists AND hasn't passed)
    $registrationDeadline = $event['registration_deadline'] ?? null;
    if (!empty($registrationDeadline) && strtotime($registrationDeadline) >= time()) {
        $defaultTab = 'anmalan';
    }
}
$activeTab = isset($_GET['tab']) ? $_GET['tab'] : $defaultTab;

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

// Fetch global texts for use_global functionality
$globalTexts = $db->getAll("SELECT field_key, content FROM global_texts WHERE is_active = 1");
$globalTextMap = [];
foreach ($globalTexts as $gt) {
    $globalTextMap[$gt['field_key']] = $gt['content'];
}

// Helper function to get content with global text fallback
function getEventContent($event, $field, $useGlobalField, $globalTextMap) {
    if (!empty($event[$useGlobalField]) && !empty($globalTextMap[$field])) {
        return $globalTextMap[$field];
    }
    return $event[$field] ?? '';
}

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

                            <!-- Organizing Club Info -->
                            <?php if (!empty($event['organizer'])): ?>
                            <div class="event-organizer-info gs-mt-md">
                                <div class="gs-flex gs-items-center gs-gap-xs gs-mb-sm">
                                    <i data-lucide="building-2" class="gs-icon-md gs-text-primary"></i>
                                    <strong class="gs-text-primary"><?= h($event['organizer']) ?></strong>
                                </div>

                                <div class="gs-flex gs-gap-md gs-flex-wrap">
                                    <?php if (!empty($event['website'])): ?>
                                    <a href="<?= h($event['website']) ?>"
                                       target="_blank"
                                       rel="noopener noreferrer"
                                       class="gs-flex gs-items-center gs-gap-xs gs-link gs-text-sm">
                                        <i data-lucide="globe" class="gs-icon-sm"></i>
                                        Webbplats
                                    </a>
                                    <?php endif; ?>

                                    <?php if (!empty($event['contact_email'])): ?>
                                    <a href="mailto:<?= h($event['contact_email']) ?>"
                                       class="gs-flex gs-items-center gs-gap-xs gs-link gs-text-sm">
                                        <i data-lucide="mail" class="gs-icon-sm"></i>
                                        <?= h($event['contact_email']) ?>
                                    </a>
                                    <?php endif; ?>

                                    <?php if (!empty($event['contact_phone'])): ?>
                                    <a href="tel:<?= h($event['contact_phone']) ?>"
                                       class="gs-flex gs-items-center gs-gap-xs gs-link gs-text-sm">
                                        <i data-lucide="phone" class="gs-icon-sm"></i>
                                        <?= h($event['contact_phone']) ?>
                                    </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endif; ?>
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

            <?php
            // Check if registration is open for tab ordering
            $registrationOpen = !empty($event['registration_deadline']) && strtotime($event['registration_deadline']) >= time();
            ?>
            <div class="event-tabs-wrapper gs-mb-lg">
                <div class="event-tabs">
                    <?php if ($registrationOpen): ?>
                    <!-- Anmälan first when registration is open -->
                    <a href="?id=<?= $eventId ?>&tab=anmalan"
                       class="event-tab <?= $activeTab === 'anmalan' ? 'active' : '' ?>">
                        <i data-lucide="user-plus"></i>
                        Anmälan
                    </a>
                    <?php endif; ?>

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

                    <?php if (!$registrationOpen): ?>
                    <a href="?id=<?= $eventId ?>&tab=anmalan"
                       class="event-tab <?= $activeTab === 'anmalan' ? 'active' : '' ?>">
                        <i data-lucide="user-plus"></i>
                        Anmälan
                    </a>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Tab Content -->
            <?php if ($activeTab === 'info'): ?>
                <!-- INFORMATION TAB -->

                <!-- Section 1: Faciliteter & Logistik -->
                <div class="gs-card gs-mb-lg">
                    <div class="gs-card-header">
                        <h2 class="gs-h3 gs-text-primary">
                            <i data-lucide="building"></i>
                            Faciliteter & Logistik
                        </h2>
                    </div>
                    <div class="gs-card-content">
                        <div class="gs-grid gs-grid-cols-1 gs-md-grid-cols-2 gs-gap-lg">
                            <!-- Left Column -->
                            <div>
                                <?php
                                $driverMeeting = getEventContent($event, 'driver_meeting', 'driver_meeting_use_global', $globalTextMap);
                                if (!empty($driverMeeting)): ?>
                                    <div class="gs-mb-lg">
                                        <h3 class="gs-h5 gs-mb-sm gs-text-primary">
                                            <i data-lucide="megaphone" class="gs-icon-14"></i>
                                            Förarmöte
                                        </h3>
                                        <div class="gs-text-secondary">
                                            <?= nl2br(h($driverMeeting)) ?>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <?php
                                $trainingInfo = getEventContent($event, 'training_info', 'training_use_global', $globalTextMap);
                                if (!empty($trainingInfo)): ?>
                                    <div class="gs-mb-lg">
                                        <h3 class="gs-h5 gs-mb-sm gs-text-primary">
                                            <i data-lucide="bike" class="gs-icon-14"></i>
                                            Träning
                                        </h3>
                                        <div class="gs-text-secondary">
                                            <?= nl2br(h($trainingInfo)) ?>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <?php
                                $timingInfo = getEventContent($event, 'timing_info', 'timing_use_global', $globalTextMap);
                                if (!empty($timingInfo)): ?>
                                    <div class="gs-mb-lg">
                                        <h3 class="gs-h5 gs-mb-sm gs-text-primary">
                                            <i data-lucide="timer" class="gs-icon-14"></i>
                                            Tidtagning
                                        </h3>
                                        <div class="gs-text-secondary">
                                            <?= nl2br(h($timingInfo)) ?>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <?php
                                $liftInfo = getEventContent($event, 'lift_info', 'lift_use_global', $globalTextMap);
                                if (!empty($liftInfo)): ?>
                                    <div class="gs-mb-lg">
                                        <h3 class="gs-h5 gs-mb-sm gs-text-primary">
                                            <i data-lucide="cable-car" class="gs-icon-14"></i>
                                            Lift
                                        </h3>
                                        <div class="gs-text-secondary">
                                            <?= nl2br(h($liftInfo)) ?>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <?php
                                $hydrationStations = getEventContent($event, 'hydration_stations', 'hydration_use_global', $globalTextMap);
                                if (!empty($hydrationStations)): ?>
                                    <div class="gs-mb-lg">
                                        <h3 class="gs-h5 gs-mb-sm gs-text-primary">
                                            <i data-lucide="droplet" class="gs-icon-14"></i>
                                            Vätskekontroller
                                        </h3>
                                        <div class="gs-text-secondary">
                                            <?= nl2br(h($hydrationStations)) ?>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <?php
                                $toiletsShowers = getEventContent($event, 'toilets_showers', 'toilets_use_global', $globalTextMap);
                                if (!empty($toiletsShowers)): ?>
                                    <div class="gs-mb-lg">
                                        <h3 class="gs-h5 gs-mb-sm gs-text-primary">
                                            <i data-lucide="bath" class="gs-icon-14"></i>
                                            Toaletter/Dusch
                                        </h3>
                                        <div class="gs-text-secondary">
                                            <?= nl2br(h($toiletsShowers)) ?>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <!-- Right Column -->
                            <div>
                                <?php
                                $bikeWash = getEventContent($event, 'bike_wash', 'bike_wash_use_global', $globalTextMap);
                                if (!empty($bikeWash)): ?>
                                    <div class="gs-mb-lg">
                                        <h3 class="gs-h5 gs-mb-sm gs-text-primary">
                                            <i data-lucide="spray-can" class="gs-icon-14"></i>
                                            Cykeltvätt
                                        </h3>
                                        <div class="gs-text-secondary">
                                            <?= nl2br(h($bikeWash)) ?>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <?php
                                $foodCafe = getEventContent($event, 'food_cafe', 'food_use_global', $globalTextMap);
                                if (!empty($foodCafe)): ?>
                                    <div class="gs-mb-lg">
                                        <h3 class="gs-h5 gs-mb-sm gs-text-primary">
                                            <i data-lucide="utensils" class="gs-icon-14"></i>
                                            Mat/Café
                                        </h3>
                                        <div class="gs-text-secondary">
                                            <?= nl2br(h($foodCafe)) ?>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <?php if (!empty($event['shops_info'])): ?>
                                    <div class="gs-mb-lg">
                                        <h3 class="gs-h5 gs-mb-sm gs-text-primary">
                                            <i data-lucide="store" class="gs-icon-14"></i>
                                            Affärer/Butiker
                                        </h3>
                                        <div class="gs-text-secondary">
                                            <?= nl2br(h($event['shops_info'])) ?>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <?php if (!empty($event['exhibitors'])): ?>
                                    <div class="gs-mb-lg">
                                        <h3 class="gs-h5 gs-mb-sm gs-text-primary">
                                            <i data-lucide="tent" class="gs-icon-14"></i>
                                            Utställare
                                        </h3>
                                        <div class="gs-text-secondary">
                                            <?= nl2br(h($event['exhibitors'])) ?>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <?php if (!empty($event['parking_detailed'])): ?>
                                    <div class="gs-mb-lg">
                                        <h3 class="gs-h5 gs-mb-sm gs-text-primary">
                                            <i data-lucide="car" class="gs-icon-14"></i>
                                            Parkering
                                        </h3>
                                        <div class="gs-text-secondary">
                                            <?= nl2br(h($event['parking_detailed'])) ?>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <?php if (!empty($event['hotel_accommodation'])): ?>
                                    <div class="gs-mb-lg">
                                        <h3 class="gs-h5 gs-mb-sm gs-text-primary">
                                            <i data-lucide="bed" class="gs-icon-14"></i>
                                            Hotell/Boende
                                        </h3>
                                        <div class="gs-text-secondary">
                                            <?= nl2br(h($event['hotel_accommodation'])) ?>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <?php if (!empty($event['local_info'])): ?>
                                    <div class="gs-mb-lg">
                                        <h3 class="gs-h5 gs-mb-sm gs-text-primary">
                                            <i data-lucide="map-pin" class="gs-icon-14"></i>
                                            Lokal information
                                        </h3>
                                        <div class="gs-text-secondary">
                                            <?= nl2br(h($event['local_info'])) ?>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Section 2: Regler & Säkerhet -->
                <div class="gs-card gs-mb-lg">
                    <div class="gs-card-header">
                        <h2 class="gs-h3 gs-text-primary">
                            <i data-lucide="shield"></i>
                            Regler & Säkerhet
                        </h2>
                    </div>
                    <div class="gs-card-content">
                        <div class="gs-grid gs-grid-cols-1 gs-md-grid-cols-2 gs-gap-lg">
                            <!-- Left Column -->
                            <div>
                                <?php if (!empty($event['competition_tracks'])): ?>
                                    <div class="gs-mb-lg">
                                        <h3 class="gs-h5 gs-mb-sm gs-text-primary">
                                            <i data-lucide="route" class="gs-icon-14"></i>
                                            Tävlingsbanor
                                        </h3>
                                        <div class="gs-text-secondary">
                                            <?= nl2br(h($event['competition_tracks'])) ?>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <?php
                                $competitionRules = getEventContent($event, 'competition_rules', 'rules_use_global', $globalTextMap);
                                if (!empty($competitionRules)): ?>
                                    <div class="gs-mb-lg">
                                        <h3 class="gs-h5 gs-mb-sm gs-text-primary">
                                            <i data-lucide="book-open" class="gs-icon-14"></i>
                                            Tävlingsregler
                                        </h3>
                                        <div class="gs-text-secondary">
                                            <?= nl2br(h($competitionRules)) ?>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <!-- Right Column -->
                            <div>
                                <?php
                                $insuranceInfo = getEventContent($event, 'insurance_info', 'insurance_use_global', $globalTextMap);
                                if (!empty($insuranceInfo)): ?>
                                    <div class="gs-mb-lg">
                                        <h3 class="gs-h5 gs-mb-sm gs-text-primary">
                                            <i data-lucide="shield-check" class="gs-icon-14"></i>
                                            Försäkring
                                        </h3>
                                        <div class="gs-text-secondary">
                                            <?= nl2br(h($insuranceInfo)) ?>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <?php
                                $equipmentInfo = getEventContent($event, 'equipment_info', 'equipment_use_global', $globalTextMap);
                                if (!empty($equipmentInfo)): ?>
                                    <div class="gs-mb-lg">
                                        <h3 class="gs-h5 gs-mb-sm gs-text-primary">
                                            <i data-lucide="hard-hat" class="gs-icon-14"></i>
                                            Utrustning
                                        </h3>
                                        <div class="gs-text-secondary">
                                            <?= nl2br(h($equipmentInfo)) ?>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Section 3: Kontakter & Övrig Information -->
                <div class="gs-card gs-mb-xl">
                    <div class="gs-card-header">
                        <h2 class="gs-h3 gs-text-primary">
                            <i data-lucide="phone"></i>
                            Kontakter & Övrig Information
                        </h2>
                    </div>
                    <div class="gs-card-content">
                        <div class="gs-grid gs-grid-cols-1 gs-md-grid-cols-2 gs-gap-lg">
                            <!-- Left Column -->
                            <div>
                                <?php if (!empty($event['entry_fees_detailed'])): ?>
                                    <div class="gs-mb-lg">
                                        <h3 class="gs-h5 gs-mb-sm gs-text-primary">
                                            <i data-lucide="banknote" class="gs-icon-14"></i>
                                            Startavgifter
                                        </h3>
                                        <div class="gs-text-secondary">
                                            <?= nl2br(h($event['entry_fees_detailed'])) ?>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <?php
                                $resultsInfo = getEventContent($event, 'results_info', 'results_use_global', $globalTextMap);
                                if (!empty($resultsInfo)): ?>
                                    <div class="gs-mb-lg">
                                        <h3 class="gs-h5 gs-mb-sm gs-text-primary">
                                            <i data-lucide="trophy" class="gs-icon-14"></i>
                                            Resultatinformation
                                        </h3>
                                        <div class="gs-text-secondary">
                                            <?= nl2br(h($resultsInfo)) ?>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <?php
                                $medicalInfo = getEventContent($event, 'medical_info', 'medical_use_global', $globalTextMap);
                                if (!empty($medicalInfo)): ?>
                                    <div class="gs-mb-lg">
                                        <h3 class="gs-h5 gs-mb-sm gs-text-primary">
                                            <i data-lucide="heart-pulse" class="gs-icon-14"></i>
                                            Sjukvård
                                        </h3>
                                        <div class="gs-text-secondary">
                                            <?= nl2br(h($medicalInfo)) ?>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <!-- Right Column -->
                            <div>
                                <?php if (!empty($event['media_production'])): ?>
                                    <div class="gs-mb-lg">
                                        <h3 class="gs-h5 gs-mb-sm gs-text-primary">
                                            <i data-lucide="video" class="gs-icon-14"></i>
                                            Media/Produktion
                                        </h3>
                                        <div class="gs-text-secondary">
                                            <?= nl2br(h($event['media_production'])) ?>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <?php
                                $contactsInfo = getEventContent($event, 'contacts_info', 'contacts_use_global', $globalTextMap);
                                if (!empty($contactsInfo)): ?>
                                    <div class="gs-mb-lg">
                                        <h3 class="gs-h5 gs-mb-sm gs-text-primary">
                                            <i data-lucide="contact" class="gs-icon-14"></i>
                                            Kontaktpersoner
                                        </h3>
                                        <div class="gs-text-secondary">
                                            <?= nl2br(h($contactsInfo)) ?>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <?php if (!empty($event['scf_representatives'])): ?>
                                    <div class="gs-mb-lg">
                                        <h3 class="gs-h5 gs-mb-sm gs-text-primary">
                                            <i data-lucide="badge" class="gs-icon-14"></i>
                                            SCF-representanter
                                        </h3>
                                        <div class="gs-text-secondary">
                                            <?= nl2br(h($event['scf_representatives'])) ?>
                                        </div>
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
