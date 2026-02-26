<?php
/**
 * Admin Series Manage - Unified Series Management with Tabs
 * All series configuration in one place: Info, Events, Registration, Payment, Results
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/series-points.php';

// Require login (admin OR promotor)
if (!isLoggedIn()) {
    header('Location: /login?redirect=' . urlencode($_SERVER['REQUEST_URI']));
    exit;
}

$db = getDB();

// Get series ID from URL
$id = 0;
if (isset($_GET['id'])) {
    $id = intval($_GET['id']);
} else {
    $uri = $_SERVER['REQUEST_URI'];
    if (preg_match('#/admin/series/manage/(\d+)#', $uri, $matches)) {
        $id = intval($matches[1]);
    }
}

// Handle new series creation
$isNewSeries = ($id <= 0 && isset($_GET['new']));

if ($id <= 0 && !$isNewSeries) {
    $_SESSION['flash_message'] = 'Ogiltigt serie-ID';
    $_SESSION['flash_type'] = 'error';
    header('Location: /admin/series');
    exit;
}

// Check access (admins can create new series)
if (!$isNewSeries && !canAccessSeries($id)) {
    $_SESSION['flash_message'] = 'Du har inte behörighet att hantera denna serie';
    $_SESSION['flash_type'] = 'error';
    header('Location: /admin/series');
    exit;
}

// Only admins can create new series
if ($isNewSeries && !isAdmin()) {
    $_SESSION['flash_message'] = 'Endast administratörer kan skapa nya serier';
    $_SESSION['flash_type'] = 'error';
    header('Location: /admin/series');
    exit;
}

// Fetch series or create empty template for new series
if ($isNewSeries) {
    $series = [
        'id' => 0,
        'name' => '',
        'year' => date('Y'),
        'type' => '',
        'format' => 'Championship',
        'status' => 'planning',
        'description' => '',
        'organizer' => '',
        'brand_id' => null,
        'pricing_template_id' => null,
        'payment_recipient_id' => null,
    ];
} else {
    $series = $db->getRow("SELECT * FROM series WHERE id = ?", [$id]);
    if (!$series) {
        $_SESSION['flash_message'] = 'Serie hittades inte';
        $_SESSION['flash_type'] = 'error';
        header('Location: /admin/series');
        exit;
    }
}

// ============================================================
// AUTO-SYNC: Add events locked to this series (events.series_id) to series_events table
// This ensures events with series_id set always appear in the series management
// Only run for existing series, not new ones
// ============================================================
$syncMessage = '';
if (!$isNewSeries) {
try {
    // First, clean up stale entries in series_events (pointing to non-existent events)
    $staleEntries = $db->getAll("
        SELECT se.id, se.event_id
        FROM series_events se
        LEFT JOIN events e ON se.event_id = e.id
        WHERE se.series_id = ? AND e.id IS NULL
    ", [$id]);

    if (!empty($staleEntries)) {
        foreach ($staleEntries as $stale) {
            try {
                $db->delete('series_events', 'id = ?', [$stale['id']]);
            } catch (Exception $deleteError) {
                error_log("Series auto-sync: failed to delete stale entry {$stale['id']}: " . $deleteError->getMessage());
            }
        }
        $syncMessage = "Rensade " . count($staleEntries) . " ogiltiga serie-event kopplingar. ";
    }

    // Now find events with series_id set that are not in series_events
    $lockedEvents = $db->getAll("
        SELECT e.id, e.name, e.date
        FROM events e
        WHERE e.series_id = ?
        AND e.id NOT IN (SELECT event_id FROM series_events WHERE series_id = ?)
    ", [$id, $id]);

    if (!empty($lockedEvents)) {
        $syncedCount = 0;
        foreach ($lockedEvents as $ev) {
            try {
                $existingCount = $db->getRow("SELECT COUNT(*) as cnt FROM series_events WHERE series_id = ?", [$id]);
                $db->insert('series_events', [
                    'series_id' => $id,
                    'event_id' => $ev['id'],
                    'template_id' => null,
                    'sort_order' => ($existingCount['cnt'] ?? 0) + 1
                ]);
                $syncedCount++;
            } catch (Exception $insertError) {
                error_log("Series auto-sync insert error for event {$ev['id']}: " . $insertError->getMessage());
            }
        }

        if ($syncedCount > 0) {
            // Re-sort all events by date
            $allSeriesEvents = $db->getAll("
                SELECT se.id, e.date
                FROM series_events se
                JOIN events e ON se.event_id = e.id
                WHERE se.series_id = ?
                ORDER BY e.date ASC
            ", [$id]);

            $sortOrder = 1;
            foreach ($allSeriesEvents as $se) {
                $db->update('series_events', ['sort_order' => $sortOrder], 'id = ?', [$se['id']]);
                $sortOrder++;
            }

            $syncMessage .= "Auto-synkade {$syncedCount} event(s) från events.series_id till series_events.";
        }
    }
} catch (Exception $e) {
    error_log("Series auto-sync error: " . $e->getMessage());
    $syncMessage = "Fel vid auto-sync: " . $e->getMessage();
}
} // End of !$isNewSeries check

// Get active tab
$activeTab = $_GET['tab'] ?? 'info';
$validTabs = ['info', 'events', 'registration', 'payment', 'results', 'sponsors'];
if (!in_array($activeTab, $validTabs)) {
    $activeTab = 'info';
}

// Initialize message
$message = '';
$messageType = 'info';

// Check for flash message
if (isset($_SESSION['flash_message'])) {
    $message = $_SESSION['flash_message'];
    $messageType = $_SESSION['flash_type'] ?? 'info';
    unset($_SESSION['flash_message'], $_SESSION['flash_type']);
}

// Add sync message if events were auto-synced
if (!empty($syncMessage) && empty($message)) {
    $message = $syncMessage;
    $messageType = 'success';
}

// ============================================================
// HANDLE POST ACTIONS
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $action = $_POST['action'] ?? '';

    // INFO TAB ACTIONS
    if ($action === 'save_info') {
        $name = trim($_POST['name'] ?? '');
        $year = !empty($_POST['year']) ? intval($_POST['year']) : null;

        if (empty($name)) {
            $message = 'Namn är obligatoriskt';
            $messageType = 'error';
        } elseif (empty($year)) {
            $message = 'År är obligatoriskt';
            $messageType = 'error';
        } else {
            $seriesData = [
                'name' => $name,
                'year' => $year,
                'type' => trim($_POST['type'] ?? ''),
                'format' => $_POST['format'] ?? 'Championship',
                'status' => $_POST['status'] ?? 'planning',
                'description' => trim($_POST['description'] ?? ''),
                'organizer' => trim($_POST['organizer'] ?? ''),
            ];

            // Brand ID if exists
            try {
                $columns = $db->getAll("SHOW COLUMNS FROM series LIKE 'brand_id'");
                if (!empty($columns) && isset($_POST['brand_id'])) {
                    $seriesData['brand_id'] = !empty($_POST['brand_id']) ? intval($_POST['brand_id']) : null;
                }
            } catch (Exception $e) {}

            // Create new series or update existing
            if ($id <= 0) {
                // Insert new series
                $newId = $db->insert('series', $seriesData);
                if ($newId) {
                    $id = $newId;
                    $isNewSeries = false;
                    $series = $db->getRow("SELECT * FROM series WHERE id = ?", [$id]);
                    $_SESSION['flash_message'] = 'Serie skapad!';
                    $_SESSION['flash_type'] = 'success';
                    // Redirect to the new series manage page
                    header('Location: /admin/series/manage/' . $id);
                    exit;
                } else {
                    $message = 'Kunde inte skapa serien';
                    $messageType = 'error';
                }
            } else {
                $db->update('series', $seriesData, 'id = ?', [$id]);
                $series = $db->getRow("SELECT * FROM series WHERE id = ?", [$id]);
                $message = 'Serie uppdaterad!';
            }
            $messageType = 'success';
        }
    }

    // EVENTS TAB ACTIONS
    elseif ($action === 'add_event') {
        $eventId = intval($_POST['event_id']);
        $templateId = !empty($_POST['template_id']) ? intval($_POST['template_id']) : null;

        $existing = $db->getRow("SELECT id FROM series_events WHERE series_id = ? AND event_id = ?", [$id, $eventId]);
        if ($existing) {
            $message = 'Detta event finns redan i serien';
            $messageType = 'error';
        } else {
            $maxOrder = $db->getRow("SELECT MAX(sort_order) as max_order FROM series_events WHERE series_id = ?", [$id]);
            $sortOrder = ($maxOrder['max_order'] ?? 0) + 1;

            $db->insert('series_events', [
                'series_id' => $id,
                'event_id' => $eventId,
                'template_id' => $templateId,
                'sort_order' => $sortOrder
            ]);

            if ($templateId) {
                $stats = recalculateSeriesEventPoints($db, $id, $eventId);
                $message = "Event tillagt! {$stats['inserted']} seriepoäng beräknade.";
            } else {
                $message = 'Event tillagt i serien!';
            }
            $messageType = 'success';
        }
        $activeTab = 'events';
    }

    elseif ($action === 'remove_event') {
        $seriesEventId = intval($_POST['series_event_id']);
        try {
            // Get event_id before deleting
            $seriesEvent = $db->getOne("SELECT event_id FROM series_events WHERE id = ?", [$seriesEventId]);
            error_log("REMOVE_EVENT: Attempting to remove series_event {$seriesEventId}, event_id = " . ($seriesEvent ? $seriesEvent['event_id'] : 'NULL'));

            // Delete from series_events
            $deleted = $db->delete('series_events', 'id = ? AND series_id = ?', [$seriesEventId, $id]);
            error_log("REMOVE_EVENT: Deleted from series_events: " . ($deleted ? 'YES' : 'NO'));

            if ($deleted && $seriesEvent) {
                // Also clear events.series_id to prevent auto-sync from re-adding
                // Remove the series_id condition to ensure it always clears
                $pdo = $db->getPDO();
                $stmt = $pdo->prepare("UPDATE events SET series_id = NULL WHERE id = ?");
                $stmt->execute([$seriesEvent['event_id']]);
                $affectedRows = $stmt->rowCount();
                error_log("REMOVE_EVENT: Updated events.series_id to NULL for event {$seriesEvent['event_id']}, affected rows: {$affectedRows}");

                // Verify it was actually cleared
                $check = $db->getOne("SELECT series_id FROM events WHERE id = ?", [$seriesEvent['event_id']]);
                error_log("REMOVE_EVENT: Verification - event {$seriesEvent['event_id']} series_id is now: " . ($check['series_id'] ?? 'NULL'));

                $message = 'Event borttaget från serien';
                $messageType = 'success';
            } elseif (!$seriesEvent) {
                $message = 'Inget event hittades att ta bort';
                $messageType = 'error';
            } else {
                $message = 'Kunde inte ta bort eventet';
                $messageType = 'error';
            }
        } catch (Exception $e) {
            $message = 'Fel vid borttagning: ' . $e->getMessage();
            $messageType = 'error';
            error_log("SERIES MANAGE: Failed to remove event {$seriesEventId} from series {$id}: " . $e->getMessage());
        }
        $activeTab = 'events';
    }

    elseif ($action === 'update_template') {
        $seriesEventId = intval($_POST['series_event_id']);
        $templateId = !empty($_POST['template_id']) ? intval($_POST['template_id']) : null;

        $seriesEvent = $db->getRow("SELECT event_id FROM series_events WHERE id = ? AND series_id = ?", [$seriesEventId, $id]);
        if ($seriesEvent) {
            $db->update('series_events', ['template_id' => $templateId], 'id = ? AND series_id = ?', [$seriesEventId, $id]);
            $stats = recalculateSeriesEventPoints($db, $id, $seriesEvent['event_id']);
            $message = "Poängmall uppdaterad! {$stats['inserted']} poäng omräknade.";
            $messageType = 'success';
        }
        $activeTab = 'events';
    }

    elseif ($action === 'sync_all_orphaned') {
        // Sync all events from events.series_id to series_events
        $orphaned = $db->getAll("
            SELECT e.id, e.date
            FROM events e
            WHERE e.series_id = ?
            AND e.id NOT IN (SELECT event_id FROM series_events WHERE series_id = ?)
        ", [$id, $id]);

        $synced = 0;
        foreach ($orphaned as $oe) {
            try {
                $maxOrder = $db->getRow("SELECT MAX(sort_order) as max_order FROM series_events WHERE series_id = ?", [$id]);
                $sortOrder = ($maxOrder['max_order'] ?? 0) + 1;
                $db->insert('series_events', [
                    'series_id' => $id,
                    'event_id' => $oe['id'],
                    'template_id' => null,
                    'sort_order' => $sortOrder
                ]);
                $synced++;
            } catch (Exception $e) {
                error_log("Sync orphaned event error: " . $e->getMessage());
            }
        }

        // Re-sort by date
        if ($synced > 0) {
            $allEvents = $db->getAll("
                SELECT se.id FROM series_events se
                JOIN events e ON se.event_id = e.id
                WHERE se.series_id = ?
                ORDER BY e.date ASC
            ", [$id]);
            $order = 1;
            foreach ($allEvents as $ae) {
                $db->update('series_events', ['sort_order' => $order], 'id = ?', [$ae['id']]);
                $order++;
            }
        }

        $message = "Synkade {$synced} event(s) till series_events!";
        $messageType = 'success';
        $activeTab = 'events';
    }

    elseif ($action === 'cleanup_invalid_series_events') {
        // Remove series_events entries that point to non-existent events
        $deleted = $db->execute("
            DELETE se FROM series_events se
            LEFT JOIN events e ON se.event_id = e.id
            WHERE se.series_id = ? AND e.id IS NULL
        ", [$id]);

        $message = "Rensade ogiltiga kopplingar från series_events!";
        $messageType = 'success';
        $activeTab = 'events';
    }

    // REGISTRATION TAB ACTIONS
    elseif ($action === 'save_registration') {
        $registrationEnabled = isset($_POST['registration_enabled']) ? 1 : 0;
        $allowSeriesRegistration = isset($_POST['allow_series_registration']) ? 1 : 0;
        $pricingTemplateId = !empty($_POST['pricing_template_id']) ? intval($_POST['pricing_template_id']) : null;

        try {
            $pdo = $db->getPdo();
            $stmt = $pdo->prepare("UPDATE series SET registration_enabled = ?, allow_series_registration = ?, pricing_template_id = ? WHERE id = ?");
            $stmt->execute([$registrationEnabled, $allowSeriesRegistration, $pricingTemplateId, $id]);
            $series = $db->getRow("SELECT * FROM series WHERE id = ?", [$id]);
            $message = 'Anmälningsinställningar sparade!';
            $messageType = 'success';
        } catch (Exception $e) {
            error_log("save_registration error: " . $e->getMessage());
            $message = 'Kunde inte spara: ' . $e->getMessage();
            $messageType = 'error';
        }
        $activeTab = 'registration';
    }

    elseif ($action === 'save_event_times') {
        $eventIds = $_POST['event_id'] ?? [];
        $opensDate = $_POST['opens_date'] ?? [];
        $opensTime = $_POST['opens_time'] ?? [];
        $closesDate = $_POST['closes_date'] ?? [];
        $closesTime = $_POST['closes_time'] ?? [];

        // Use raw PDO for direct error handling (DatabaseWrapper swallows exceptions)
        $pdo = $db->getPdo();

        // Check which columns exist using fetchAll (rowCount is unreliable for SHOW)
        $hasRegOpens = false;
        $hasRegDeadlineTime = false;
        try {
            $chk = $pdo->query("SHOW COLUMNS FROM events LIKE 'registration_opens'");
            $hasRegOpens = count($chk->fetchAll()) > 0;
        } catch (Exception $e) {
            error_log("Column check registration_opens: " . $e->getMessage());
        }
        try {
            $chk = $pdo->query("SHOW COLUMNS FROM events LIKE 'registration_deadline_time'");
            $hasRegDeadlineTime = count($chk->fetchAll()) > 0;
        } catch (Exception $e) {
            error_log("Column check registration_deadline_time: " . $e->getMessage());
        }

        error_log("save_event_times: hasRegOpens=" . ($hasRegOpens ? 'Y' : 'N') . ", hasRegDeadlineTime=" . ($hasRegDeadlineTime ? 'Y' : 'N') . ", eventCount=" . count($eventIds));

        $saved = 0;
        $errors = 0;
        $errorMessages = [];
        foreach ($eventIds as $index => $eventId) {
            $eventId = intval($eventId);
            if ($eventId <= 0) continue;

            try {
                // Build SET clause dynamically based on available columns
                $sets = [];
                $values = [];

                // Registration deadline (DATE column - always exists)
                $regClosesDate = !empty($closesDate[$index]) ? $closesDate[$index] : null;
                $sets[] = 'registration_deadline = ?';
                $values[] = $regClosesDate;

                // Registration deadline time (TIME column - may not exist)
                if ($hasRegDeadlineTime) {
                    $regClosesTime = !empty($closesTime[$index]) ? $closesTime[$index] . ':00' : null;
                    $sets[] = 'registration_deadline_time = ?';
                    $values[] = $regClosesTime;
                }

                // Registration opens (DATETIME column - requires migration 036)
                if ($hasRegOpens) {
                    $regOpens = !empty($opensDate[$index])
                        ? $opensDate[$index] . ' ' . ($opensTime[$index] ?? '00:00') . ':00'
                        : null;
                    $sets[] = 'registration_opens = ?';
                    $values[] = $regOpens;
                }

                if (!empty($sets)) {
                    $values[] = $eventId;
                    $sql = "UPDATE events SET " . implode(', ', $sets) . " WHERE id = ?";
                    error_log("save_event_times SQL: $sql | values: " . json_encode($values));
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute($values);
                    $rowsAffected = $stmt->rowCount();
                    error_log("save_event_times: event $eventId updated, rows=$rowsAffected");
                    $saved++;
                }
            } catch (Exception $e) {
                error_log("save_event_times error for event $eventId: " . $e->getMessage());
                $errorMessages[] = "Event $eventId: " . $e->getMessage();
                $errors++;
            }
        }

        if ($errors > 0) {
            $message = "Sparade $saved events, $errors fel: " . implode('; ', $errorMessages);
            $messageType = 'warning';
        } elseif ($saved > 0) {
            $message = "Sparade anmälningstider för $saved event(s)!";
            $messageType = 'success';
        } else {
            $message = "Inga events att spara (event_ids: " . implode(',', $eventIds) . ")";
            $messageType = 'info';
        }
        $activeTab = 'registration';
    }

    // PAYMENT TAB ACTIONS
    elseif ($action === 'save_payment') {
        $gravityIdDiscount = floatval($_POST['gravity_id_discount'] ?? 0);

        $updateData = ['gravity_id_discount' => $gravityIdDiscount];

        $db->update('series', $updateData, 'id = ?', [$id]);
        $series = $db->getRow("SELECT * FROM series WHERE id = ?", [$id]);
        $message = 'Betalningsinställningar sparade!';
        $messageType = 'success';
        $activeTab = 'payment';
    }

    // RESULTS TAB ACTIONS
    elseif ($action === 'recalculate_all') {
        $totalStats = recalculateAllSeriesPoints($db, $id);
        $totalChanged = $totalStats['inserted'] + $totalStats['updated'];
        $message = "Alla poäng omräknade! {$totalStats['events']} events, {$totalChanged} resultat.";
        $messageType = 'success';
        $activeTab = 'results';
    }

    elseif ($action === 'update_count_best') {
        $countBest = $_POST['count_best_results'];
        $countBestValue = ($countBest === '' || $countBest === 'null') ? null : intval($countBest);

        $db->update('series', ['count_best_results' => $countBestValue], 'id = ?', [$id]);
        $series = $db->getRow("SELECT * FROM series WHERE id = ?", [$id]);
        $message = $countBestValue === null ? 'Alla resultat räknas nu' : "Räknar nu de {$countBestValue} bästa resultaten";
        $messageType = 'success';
        $activeTab = 'results';
    }

    elseif ($action === 'update_club_championship') {
        $enableClubChampionship = isset($_POST['enable_club_championship']) ? 1 : 0;

        // Check if column exists before updating
        try {
            $columns = $db->getAll("SHOW COLUMNS FROM series LIKE 'enable_club_championship'");
            if (!empty($columns)) {
                $db->update('series', ['enable_club_championship' => $enableClubChampionship], 'id = ?', [$id]);
                $series = $db->getRow("SELECT * FROM series WHERE id = ?", [$id]);
                $message = $enableClubChampionship ? 'Klubbmästerskap aktiverat' : 'Klubbmästerskap inaktiverat';
                $messageType = 'success';
            } else {
                $message = 'Kolumnen enable_club_championship finns inte. Kör migration 040.';
                $messageType = 'error';
            }
        } catch (Exception $e) {
            $message = 'Fel vid uppdatering: ' . $e->getMessage();
            $messageType = 'error';
        }
        $activeTab = 'results';
    }

    // SPONSORS TAB ACTIONS
    elseif ($action === 'save_sponsors') {
        try {
            $pdo = $db->getPdo();
            // Delete existing sponsor assignments
            $stmt = $pdo->prepare("DELETE FROM series_sponsors WHERE series_id = ?");
            $stmt->execute([$id]);

            $insertStmt = $pdo->prepare("INSERT INTO series_sponsors (series_id, sponsor_id, placement, display_order) VALUES (?, ?, ?, ?)");

            // Header (single)
            if (!empty($_POST['sponsor_header'])) {
                $insertStmt->execute([$id, (int)$_POST['sponsor_header'], 'header', 0]);
            }
            // Content / Logo row (array)
            if (!empty($_POST['sponsor_content']) && is_array($_POST['sponsor_content'])) {
                $order = 0;
                foreach ($_POST['sponsor_content'] as $sponsorId) {
                    $insertStmt->execute([$id, (int)$sponsorId, 'content', $order++]);
                }
            }
            // Sidebar / Results sponsor (single)
            if (!empty($_POST['sponsor_sidebar'])) {
                $insertStmt->execute([$id, (int)$_POST['sponsor_sidebar'], 'sidebar', 0]);
            }
            // Partners (array)
            if (!empty($_POST['sponsor_partner']) && is_array($_POST['sponsor_partner'])) {
                $order = 0;
                foreach ($_POST['sponsor_partner'] as $sponsorId) {
                    $insertStmt->execute([$id, (int)$sponsorId, 'partner', $order++]);
                }
            }

            $message = 'Sponsorer sparade!';
            $messageType = 'success';
        } catch (Exception $e) {
            $message = 'Kunde inte spara sponsorer: ' . $e->getMessage();
            $messageType = 'error';
        }
        $activeTab = 'sponsors';
    }
}

// ============================================================
// FETCH DATA FOR TABS
// ============================================================

// Get brands for dropdown
$brands = [];
try {
    $tables = $db->getAll("SHOW TABLES LIKE 'series_brands'");
    if (!empty($tables)) {
        $brands = $db->getAll("SELECT id, name FROM series_brands WHERE active = 1 ORDER BY display_order ASC, name ASC");
    }
} catch (Exception $e) {}

// Check which registration columns exist on events table
$hasEventsRegOpens = false;
$hasEventsRegDeadlineTime = false;
try {
    $cols = $db->getAll("SHOW COLUMNS FROM events LIKE 'registration_opens'");
    $hasEventsRegOpens = !empty($cols);
} catch (Exception $e) {}
try {
    $cols = $db->getAll("SHOW COLUMNS FROM events LIKE 'registration_deadline_time'");
    $hasEventsRegDeadlineTime = !empty($cols);
} catch (Exception $e) {}

$regOpensSelect = $hasEventsRegOpens ? 'e.registration_opens' : 'NULL as registration_opens';
$regDeadlineTimeSelect = $hasEventsRegDeadlineTime ? 'e.registration_deadline_time' : 'NULL as registration_deadline_time';

// Get events in this series from series_events table
$seriesEvents = [];
try {
    $seriesEvents = $db->getAll("
        SELECT se.id, se.event_id, se.series_id, se.template_id, se.sort_order,
               e.name as event_name, e.date as event_date, e.location, e.discipline,
               e.series_id as event_series_id, {$regOpensSelect}, e.registration_deadline,
               {$regDeadlineTimeSelect}, ps.name as template_name
        FROM series_events se
        JOIN events e ON se.event_id = e.id
        LEFT JOIN point_scales ps ON se.template_id = ps.id
        WHERE se.series_id = ?
        ORDER BY e.date ASC
    ", [$id]);
} catch (Exception $e) {
    error_log("series-manage.php: Main query failed: " . $e->getMessage());
}

// Fallback: simplest query if main fails
if (empty($seriesEvents)) {
    try {
        $seriesEvents = $db->getAll("
            SELECT se.id, se.event_id, se.series_id, se.template_id, se.sort_order,
                   e.name as event_name, e.date as event_date, e.location, e.discipline,
                   e.series_id as event_series_id, e.registration_deadline,
                   NULL as registration_opens, NULL as registration_deadline_time,
                   NULL as template_name
            FROM series_events se
            JOIN events e ON se.event_id = e.id
            WHERE se.series_id = ?
            ORDER BY e.date ASC
        ", [$id]);
    } catch (Exception $e) {
        error_log("series-manage.php: Fallback query also failed: " . $e->getMessage());
    }
}

// ALWAYS check for events linked via events.series_id that are NOT in series_events
// These are "orphaned" events that should be in series_events but aren't
// This can happen due to data sync issues or manual database changes
$orphanedEvents = $db->getAll("
    SELECT e.id, e.name as event_name, e.date as event_date, e.location, e.discipline,
           e.series_id as event_series_id, e.registration_opens, e.registration_deadline
    FROM events e
    WHERE e.series_id = ?
    AND e.id NOT IN (SELECT event_id FROM series_events WHERE series_id = ?)
    ORDER BY e.date ASC
", [$id, $id]);

// Get events not in series (for adding)
$eventsNotInSeries = $db->getAll("
    SELECT e.id, e.name, e.date, e.location, YEAR(e.date) as event_year
    FROM events e
    WHERE e.id NOT IN (SELECT event_id FROM series_events WHERE series_id = ?)
    AND e.active = 1
    ORDER BY e.date DESC
", [$id]);

// Separate by year match
$seriesYear = $series['year'] ?? null;
$matchingYearEvents = [];
$otherYearEvents = [];
foreach ($eventsNotInSeries as $ev) {
    if ($seriesYear && $ev['event_year'] == $seriesYear) {
        $matchingYearEvents[] = $ev;
    } else {
        $otherYearEvents[] = $ev;
    }
}

// Get point scales (templates)
$pointScales = $db->getAll("SELECT id, name FROM point_scales WHERE active = 1 ORDER BY name");

// Get pricing templates
$pricingTemplates = [];
try {
    $pricingTemplates = $db->getAll("SELECT id, name, is_default FROM pricing_templates ORDER BY is_default DESC, name ASC");
} catch (Exception $e) {}

// Get all active sponsors (for sponsor tab)
$allSponsors = [];
$seriesSponsors = ['header' => [], 'content' => [], 'sidebar' => [], 'partner' => []];
$sponsorLookup = [];
try {
    $pdo = $db->getPdo();
    $spStmt = $pdo->query("
        SELECT s.id, s.name, s.logo, s.tier, s.website,
               COALESCE(CONCAT('/', m1.filepath), CONCAT('/', m2.filepath), s.logo) as logo_url
        FROM sponsors s
        LEFT JOIN media m1 ON s.logo_media_id = m1.id
        LEFT JOIN media m2 ON s.logo_banner_id = m2.id
        WHERE s.active = 1
        ORDER BY s.name ASC
    ");
    $allSponsors = $spStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($allSponsors as $sp) {
        $sponsorLookup[(int)$sp['id']] = [
            'id' => (int)$sp['id'],
            'name' => $sp['name'],
            'logo_url' => $sp['logo_url'] ?? '',
        ];
    }

    if ($id > 0) {
        $ssStmt = $pdo->prepare("SELECT sponsor_id, placement FROM series_sponsors WHERE series_id = ? ORDER BY display_order ASC");
        $ssStmt->execute([$id]);
        foreach ($ssStmt->fetchAll(PDO::FETCH_ASSOC) as $ss) {
            $placement = $ss['placement'];
            if (isset($seriesSponsors[$placement])) {
                $seriesSponsors[$placement][] = (int)$ss['sponsor_id'];
            }
        }
    }
} catch (Exception $e) {
    error_log("series-manage.php: Sponsor load error: " . $e->getMessage());
}


// Count stats
$eventsCount = count($seriesEvents);
$eventsWithResults = 0;
foreach ($seriesEvents as $se) {
    $hasResults = $db->getRow("SELECT COUNT(*) as cnt FROM results WHERE event_id = ? AND status = 'finished'", [$se['event_id']]);
    if (($hasResults['cnt'] ?? 0) > 0) {
        $eventsWithResults++;
    }
}

// Get unique participants
$uniqueParticipants = $db->getRow("
    SELECT COUNT(DISTINCT r.cyclist_id) as cnt
    FROM results r
    JOIN series_events se ON r.event_id = se.event_id
    WHERE se.series_id = ? AND r.status = 'finished'
", [$id])['cnt'] ?? 0;

// ============================================================
// PAGE CONFIG
// ============================================================
if ($isNewSeries) {
    $page_title = 'Skapa ny serie';
    $breadcrumbs = [
        ['label' => 'Serier', 'url' => '/admin/series'],
        ['label' => 'Ny serie']
    ];
} else {
    $page_title = 'Hantera: ' . htmlspecialchars($series['name']);
    $breadcrumbs = [
        ['label' => 'Serier', 'url' => '/admin/series'],
        ['label' => htmlspecialchars($series['name'])]
    ];
}
include __DIR__ . '/components/unified-layout.php';
?>

<style>
/* Series Manage Tabs */
.series-tabs {
    display: flex;
    gap: var(--space-xs);
    margin-bottom: var(--space-lg);
    border-bottom: 2px solid var(--color-border);
    padding-bottom: var(--space-xs);
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
}

.series-tab {
    display: flex;
    align-items: center;
    gap: var(--space-xs);
    padding: var(--space-sm) var(--space-md);
    border-radius: var(--radius-md) var(--radius-md) 0 0;
    text-decoration: none;
    color: var(--color-text-secondary);
    font-weight: 500;
    font-size: var(--text-sm);
    white-space: nowrap;
    transition: all 0.15s ease;
    border: 1px solid transparent;
    border-bottom: none;
}

.series-tab:hover {
    color: var(--color-accent);
    background: var(--color-bg-hover);
}

.series-tab.active {
    color: var(--color-accent);
    background: var(--color-bg-card);
    border-color: var(--color-border);
    margin-bottom: -2px;
    border-bottom: 2px solid var(--color-bg-card);
}

.series-tab svg {
    width: 16px;
    height: 16px;
}

.series-tab .badge {
    font-size: 10px;
    padding: 2px 6px;
}

/* Quick Stats */
.quick-stats {
    display: flex;
    gap: var(--space-md);
    margin-bottom: var(--space-lg);
    flex-wrap: wrap;
}

.quick-stat {
    display: flex;
    align-items: center;
    gap: var(--space-sm);
    padding: var(--space-sm) var(--space-md);
    background: var(--color-bg-card);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-md);
}

.quick-stat-value {
    font-size: var(--text-xl);
    font-weight: 700;
    color: var(--color-accent);
}

.quick-stat-label {
    font-size: var(--text-sm);
    color: var(--color-text-secondary);
}

/* Tab content */
.tab-content {
    display: none;
}

.tab-content.active {
    display: block;
}

/* Event table */
.event-table {
    width: 100%;
}

.event-table th,
.event-table td {
    padding: var(--space-sm) var(--space-md);
    text-align: left;
    border-bottom: 1px solid var(--color-border);
}

.event-table th {
    font-weight: 600;
    color: var(--color-text-secondary);
    font-size: var(--text-sm);
    background: var(--color-bg-surface);
}

.template-select {
    min-width: 150px;
}

/* Registration times grid */
.reg-times-grid {
    display: grid;
    gap: var(--space-md);
}

.reg-time-row {
    display: grid;
    grid-template-columns: 2fr 1fr 1fr 1fr;
    gap: var(--space-md);
    align-items: center;
    padding: var(--space-md);
    background: var(--color-bg-surface);
    border-radius: var(--radius-md);
}

@media (max-width: 768px) {
    .reg-time-row {
        grid-template-columns: 1fr;
    }

    .series-tabs {
        gap: var(--space-2xs);
    }

    .series-tab {
        padding: var(--space-xs) var(--space-sm);
        font-size: var(--text-xs);
    }

    .series-tab svg {
        width: 14px;
        height: 14px;
    }
}

/* Info grid */
.info-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: var(--space-md);
}
</style>

<?php if ($message): ?>
<div class="alert alert-<?= $messageType === 'success' ? 'success' : ($messageType === 'error' ? 'error' : 'info') ?> mb-lg">
    <?= htmlspecialchars($message) ?>
</div>
<?php endif; ?>

<?php if ($isNewSeries): ?>
<!-- New Series - simplified view -->
<div class="alert alert-info">
    <i data-lucide="info"></i>
    Fyll i grundläggande information och klicka "Spara" för att skapa serien. Därefter kan du lägga till events och konfigurera anmälan/betalning.
</div>
<?php else: ?>
<!-- Quick Stats -->
<div class="quick-stats">
    <div class="quick-stat">
        <div class="quick-stat-value"><?= $eventsCount ?></div>
        <div class="quick-stat-label">Events</div>
    </div>
    <div class="quick-stat">
        <div class="quick-stat-value"><?= $eventsWithResults ?>/<?= $eventsCount ?></div>
        <div class="quick-stat-label">Med resultat</div>
    </div>
    <div class="quick-stat">
        <div class="quick-stat-value"><?= number_format($uniqueParticipants) ?></div>
        <div class="quick-stat-label">Deltagare</div>
    </div>
    <div class="quick-stat">
        <span class="badge badge-<?= $series['status'] === 'active' ? 'success' : ($series['status'] === 'completed' ? 'info' : 'secondary') ?>">
            <?= ucfirst($series['status'] ?? 'planning') ?>
        </span>
    </div>
</div>

<!-- Tabs Navigation -->
<nav class="series-tabs">
    <a href="?id=<?= $id ?>&tab=info" class="series-tab <?= $activeTab === 'info' ? 'active' : '' ?>">
        <i data-lucide="settings"></i>
        Info
    </a>
    <a href="?id=<?= $id ?>&tab=events" class="series-tab <?= $activeTab === 'events' ? 'active' : '' ?>">
        <i data-lucide="calendar"></i>
        Events
        <span class="badge badge-secondary"><?= $eventsCount ?></span>
    </a>
    <a href="?id=<?= $id ?>&tab=registration" class="series-tab <?= $activeTab === 'registration' ? 'active' : '' ?>">
        <i data-lucide="clipboard-list"></i>
        Anmälan
        <?php if ($series['registration_enabled'] ?? 0): ?>
            <span class="badge badge-success">ON</span>
        <?php endif; ?>
    </a>
    <a href="?id=<?= $id ?>&tab=payment" class="series-tab <?= $activeTab === 'payment' ? 'active' : '' ?>">
        <i data-lucide="credit-card"></i>
        Betalning
    </a>
    <a href="?id=<?= $id ?>&tab=results" class="series-tab <?= $activeTab === 'results' ? 'active' : '' ?>">
        <i data-lucide="trophy"></i>
        Resultat
    </a>
    <a href="?id=<?= $id ?>&tab=sponsors" class="series-tab <?= $activeTab === 'sponsors' ? 'active' : '' ?>">
        <i data-lucide="heart"></i>
        Sponsorer
    </a>
</nav>
<?php endif; // End of !$isNewSeries ?>

<!-- ============================================================ -->
<!-- INFO TAB -->
<!-- ============================================================ -->
<div class="tab-content <?= $activeTab === 'info' ? 'active' : '' ?>" id="tab-info">
    <form method="POST">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_info">

        <div class="admin-card">
            <div class="admin-card-header">
                <h2><i data-lucide="settings"></i> Grundinformation</h2>
            </div>
            <div class="admin-card-body">
                <?php if (!empty($brands)): ?>
                <div class="admin-form-group mb-md">
                    <label class="admin-form-label">Huvudserie (Varumärke)</label>
                    <select name="brand_id" class="admin-form-select">
                        <option value="">-- Ingen --</option>
                        <?php foreach ($brands as $brand): ?>
                        <option value="<?= $brand['id'] ?>" <?= ($series['brand_id'] ?? '') == $brand['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($brand['name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>

                <div class="info-grid">
                    <div class="admin-form-group">
                        <label class="admin-form-label">Serienamn *</label>
                        <input type="text" name="name" class="admin-form-input" required
                               value="<?= htmlspecialchars($series['name'] ?? '') ?>">
                    </div>
                    <div class="admin-form-group">
                        <label class="admin-form-label">År *</label>
                        <input type="number" name="year" class="admin-form-input" required
                               value="<?= htmlspecialchars($series['year'] ?? date('Y')) ?>"
                               min="2000" max="2100">
                    </div>
                    <div class="admin-form-group">
                        <label class="admin-form-label">Typ</label>
                        <input type="text" name="type" class="admin-form-input"
                               value="<?= htmlspecialchars($series['type'] ?? '') ?>"
                               placeholder="Enduro, DH, XC...">
                    </div>
                    <div class="admin-form-group">
                        <label class="admin-form-label">Status</label>
                        <select name="status" class="admin-form-select">
                            <option value="planning" <?= ($series['status'] ?? '') === 'planning' ? 'selected' : '' ?>>Planering</option>
                            <option value="active" <?= ($series['status'] ?? '') === 'active' ? 'selected' : '' ?>>Aktiv</option>
                            <option value="completed" <?= ($series['status'] ?? '') === 'completed' ? 'selected' : '' ?>>Avslutad</option>
                            <option value="cancelled" <?= ($series['status'] ?? '') === 'cancelled' ? 'selected' : '' ?>>Inställd</option>
                        </select>
                    </div>
                </div>

                <div class="admin-form-group mt-md">
                    <label class="admin-form-label">Arrangör</label>
                    <input type="text" name="organizer" class="admin-form-input"
                           value="<?= htmlspecialchars($series['organizer'] ?? '') ?>">
                </div>

                <div class="admin-form-group">
                    <label class="admin-form-label">Beskrivning</label>
                    <textarea name="description" class="admin-form-input" rows="3"><?= htmlspecialchars($series['description'] ?? '') ?></textarea>
                </div>

                <div class="mt-lg">
                    <button type="submit" class="btn-admin btn-admin-primary">
                        <i data-lucide="save"></i> Spara
                    </button>
                </div>
            </div>
        </div>
    </form>
</div>

<?php if (!$isNewSeries): ?>
<!-- ============================================================ -->
<!-- EVENTS TAB -->
<!-- ============================================================ -->
<div class="tab-content <?= $activeTab === 'events' ? 'active' : '' ?>" id="tab-events">
    <div class="grid grid-cols-1 gs-lg-grid-cols-3 gap-lg">
        <!-- Add Event -->
        <div>
            <div class="admin-card">
                <div class="admin-card-header">
                    <h2><i data-lucide="plus"></i> Lägg till Event</h2>
                </div>
                <div class="admin-card-body">
                    <?php if (empty($eventsNotInSeries)): ?>
                        <p class="text-secondary">Alla events är redan tillagda.</p>
                    <?php else: ?>
                    <form method="POST">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="add_event">

                        <div class="admin-form-group">
                            <label class="admin-form-label">Välj event</label>
                            <select name="event_id" class="admin-form-select" required>
                                <option value="">-- Välj --</option>
                                <?php if (!empty($matchingYearEvents)): ?>
                                <optgroup label="Matchar serieåret (<?= $seriesYear ?>)">
                                    <?php foreach ($matchingYearEvents as $ev): ?>
                                    <option value="<?= $ev['id'] ?>">
                                        <?= htmlspecialchars($ev['name']) ?>
                                        <?php if ($ev['date']): ?>(<?= date('Y-m-d', strtotime($ev['date'])) ?>)<?php endif; ?>
                                    </option>
                                    <?php endforeach; ?>
                                </optgroup>
                                <?php endif; ?>
                                <?php if (!empty($otherYearEvents)): ?>
                                <optgroup label="Andra år">
                                    <?php foreach ($otherYearEvents as $ev): ?>
                                    <option value="<?= $ev['id'] ?>">
                                        <?= htmlspecialchars($ev['name']) ?>
                                        <?php if ($ev['date']): ?>(<?= date('Y-m-d', strtotime($ev['date'])) ?>)<?php endif; ?>
                                    </option>
                                    <?php endforeach; ?>
                                </optgroup>
                                <?php endif; ?>
                            </select>
                        </div>

                        <div class="admin-form-group">
                            <label class="admin-form-label">Poängmall (valfritt)</label>
                            <select name="template_id" class="admin-form-select">
                                <option value="">-- Ingen --</option>
                                <?php foreach ($pointScales as $scale): ?>
                                <option value="<?= $scale['id'] ?>"><?= htmlspecialchars($scale['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <button type="submit" class="btn-admin btn-admin-primary w-full">
                            <i data-lucide="plus"></i> Lägg till
                        </button>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Events List -->
        <div class="gs-lg-col-span-2">
            <div class="admin-card">
                <div class="admin-card-header">
                    <h2><i data-lucide="list"></i> Events i serien (<?= $eventsCount ?>)</h2>
                </div>
                <div class="admin-card-body" style="padding: 0;">
                    <?php
                    // Debug: Show diagnostic info if no events found
                    if (empty($seriesEvents) && empty($orphanedEvents)) {
                        // Check actual database state
                        $directEventsCount = $db->getRow("SELECT COUNT(*) as cnt FROM events WHERE series_id = ?", [$id]);
                        $seriesEventsCount = $db->getRow("SELECT COUNT(*) as cnt FROM series_events WHERE series_id = ?", [$id]);

                        // Check for mismatched event_ids in series_events
                        $mismatchedEntries = $db->getAll("
                            SELECT se.id, se.event_id, se.series_id
                            FROM series_events se
                            LEFT JOIN events e ON se.event_id = e.id
                            WHERE se.series_id = ? AND e.id IS NULL
                        ", [$id]);

                        // Check what event_ids are in series_events
                        $seEventIds = $db->getAll("SELECT event_id FROM series_events WHERE series_id = ? LIMIT 10", [$id]);

                        // Check if those events exist
                        $existingEvents = [];
                        if (!empty($seEventIds)) {
                            $ids = array_column($seEventIds, 'event_id');
                            $placeholders = implode(',', array_fill(0, count($ids), '?'));
                            $existingEvents = $db->getAll("SELECT id, name FROM events WHERE id IN ($placeholders)", $ids);
                        }

                        echo '<div class="alert alert-warning m-md">';
                        echo '<i data-lucide="alert-triangle"></i> ';
                        echo '<strong>Diagnostik - Dataproblem upptäckt:</strong><br>';
                        echo 'Events med series_id=' . $id . ': ' . ($directEventsCount['cnt'] ?? 0) . '<br>';
                        echo 'Rader i series_events: ' . ($seriesEventsCount['cnt'] ?? 0) . '<br>';
                        echo 'Ogiltiga kopplingar (event saknas): ' . count($mismatchedEntries) . '<br>';

                        if (!empty($seEventIds)) {
                            echo 'Event-IDs i series_events: ' . implode(', ', array_column($seEventIds, 'event_id')) . '<br>';
                        }
                        if (!empty($existingEvents)) {
                            echo 'Av dessa finns: ' . implode(', ', array_map(function($e) { return $e['id'] . ' (' . $e['name'] . ')'; }, $existingEvents));
                        } else {
                            echo '<strong style="color: var(--color-error);">INGA av dessa event-IDs finns i events-tabellen!</strong>';
                        }
                        echo '</div>';

                        // Auto-fix: If we have mismatched entries, offer to clean them up
                        if (!empty($mismatchedEntries)) {
                            echo '<form method="POST" class="m-md">';
                            echo csrf_field();
                            echo '<input type="hidden" name="action" value="cleanup_invalid_series_events">';
                            echo '<button type="submit" class="btn-admin btn-admin-warning">';
                            echo '<i data-lucide="trash-2"></i> Rensa ' . count($mismatchedEntries) . ' ogiltiga kopplingar';
                            echo '</button>';
                            echo '</form>';
                        }
                    }
                    ?>
                    <?php if (empty($seriesEvents) && empty($orphanedEvents)): ?>
                        <p class="text-secondary p-lg">Inga events tillagda ännu.</p>
                    <?php elseif (empty($seriesEvents) && !empty($orphanedEvents)): ?>
                        <!-- Orphaned events found - need to sync -->
                        <div class="alert alert-warning m-md">
                            <i data-lucide="alert-triangle"></i>
                            <strong>Hittade <?= count($orphanedEvents) ?> event(s) kopplade via events.series_id som saknas i series_events.</strong>
                            <p style="margin-top: var(--space-sm);">Dessa events visas på publika sidan men inte här. Klicka nedan för att synka dem.</p>
                        </div>
                        <div class="table-responsive">
                            <table class="event-table">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Event</th>
                                        <th>Datum</th>
                                        <th>Åtgärd</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($orphanedEvents as $idx => $oe): ?>
                                    <tr>
                                        <td><?= $idx + 1 ?></td>
                                        <td><?= htmlspecialchars($oe['event_name']) ?></td>
                                        <td><?= $oe['event_date'] ? date('Y-m-d', strtotime($oe['event_date'])) : '-' ?></td>
                                        <td>
                                            <form method="POST" style="display: inline;">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="add_event">
                                                <input type="hidden" name="event_id" value="<?= $oe['id'] ?>">
                                                <button type="submit" class="btn-admin btn-admin-success btn-admin-sm">
                                                    <i data-lucide="link"></i> Synka
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="p-md">
                            <form method="POST">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="sync_all_orphaned">
                                <button type="submit" class="btn-admin btn-admin-primary">
                                    <i data-lucide="refresh-cw"></i> Synka alla <?= count($orphanedEvents) ?> events
                                </button>
                            </form>
                        </div>
                    <?php else: ?>
                    <div class="table-responsive">
                        <table class="event-table">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Event</th>
                                    <th>Datum</th>
                                    <th>Poängmall</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $num = 1; foreach ($seriesEvents as $se): ?>
                                <tr>
                                    <td><span class="badge badge-primary"><?= $num++ ?></span></td>
                                    <td>
                                        <strong><?= htmlspecialchars($se['event_name']) ?></strong>
                                        <?php if ($se['location']): ?>
                                        <br><small class="text-secondary"><?= htmlspecialchars($se['location']) ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= $se['event_date'] ? date('Y-m-d', strtotime($se['event_date'])) : '-' ?></td>
                                    <td>
                                        <form method="POST" class="flex gap-xs items-center">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="update_template">
                                            <input type="hidden" name="series_event_id" value="<?= $se['id'] ?>">
                                            <select name="template_id" class="admin-form-select template-select" onchange="this.form.submit()">
                                                <option value="">-- Ingen --</option>
                                                <?php foreach ($pointScales as $scale): ?>
                                                <option value="<?= $scale['id'] ?>" <?= $se['template_id'] == $scale['id'] ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($scale['name']) ?>
                                                </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <button type="submit" class="btn-admin btn-admin-sm btn-admin-primary" title="Spara poängmall">
                                                <i data-lucide="save" style="width:14px;height:14px;"></i> Spara
                                            </button>
                                        </form>
                                    </td>
                                    <td>
                                        <form method="POST" onsubmit="return confirm('Ta bort event från serien?');">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="remove_event">
                                            <input type="hidden" name="series_event_id" value="<?= $se['id'] ?>">
                                            <button type="submit" class="btn-admin btn-admin-sm btn-admin-danger" title="Ta bort event">
                                                <i data-lucide="trash-2" style="width:14px;height:14px;"></i> Ta bort
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ============================================================ -->
<!-- REGISTRATION TAB -->
<!-- ============================================================ -->
<div class="tab-content <?= $activeTab === 'registration' ? 'active' : '' ?>" id="tab-registration">
    <!-- Registration Settings -->
    <div class="admin-card mb-lg">
        <div class="admin-card-header">
            <h2><i data-lucide="settings"></i> Anmälningsinställningar</h2>
        </div>
        <div class="admin-card-body">
            <form method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save_registration">

                <div class="info-grid">
                    <div class="admin-form-group">
                        <label class="admin-form-label flex items-center gap-sm">
                            <input type="checkbox" name="registration_enabled" value="1"
                                   <?= ($series['registration_enabled'] ?? 0) ? 'checked' : '' ?>>
                            Anmälan aktiverad
                        </label>
                        <small class="text-secondary">Tillåt anmälan till events i serien</small>
                    </div>

                    <div class="admin-form-group">
                        <label class="admin-form-label flex items-center gap-sm">
                            <input type="checkbox" name="allow_series_registration" value="1"
                                   <?= ($series['allow_series_registration'] ?? 0) ? 'checked' : '' ?>>
                            Tillåt serieanmälan
                        </label>
                        <small class="text-secondary">Visa "Anmäl dig till hela serien"-kortet på event-sidor</small>
                    </div>

                    <div class="admin-form-group">
                        <label class="admin-form-label">Prismall</label>
                        <select name="pricing_template_id" class="admin-form-select">
                            <option value="">-- Välj --</option>
                            <?php foreach ($pricingTemplates as $pt): ?>
                            <option value="<?= $pt['id'] ?>" <?= ($series['pricing_template_id'] ?? '') == $pt['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($pt['name']) ?> <?= $pt['is_default'] ? '(Standard)' : '' ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-secondary">
                            <a href="/admin/pricing-templates" class="text-accent">Hantera prismallar</a>
                        </small>
                    </div>
                </div>

                <div class="mt-md">
                    <button type="submit" class="btn-admin btn-admin-primary">
                        <i data-lucide="save"></i> Spara
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Event Times -->
    <?php if (!empty($seriesEvents)): ?>
    <div class="admin-card">
        <div class="admin-card-header">
            <h2><i data-lucide="clock"></i> Anmälningstider per event</h2>
        </div>
        <div class="admin-card-body">
            <form method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save_event_times">

                <div class="reg-times-grid">
                    <?php foreach ($seriesEvents as $se):
                        // Opens: stored as DATETIME in registration_opens
                        $opensDate = !empty($se['registration_opens']) ? date('Y-m-d', strtotime($se['registration_opens'])) : '';
                        $opensTime = !empty($se['registration_opens']) ? date('H:i', strtotime($se['registration_opens'])) : '00:00';
                        // Closes: stored as DATE in registration_deadline + optional TIME in registration_deadline_time
                        $closesDate = !empty($se['registration_deadline']) ? date('Y-m-d', strtotime($se['registration_deadline'])) : '';
                        if (!empty($se['registration_deadline_time'])) {
                            $closesTime = date('H:i', strtotime($se['registration_deadline_time']));
                        } elseif (!empty($se['registration_deadline'])) {
                            $closesTime = '23:59';
                        } else {
                            $closesTime = '23:59';
                        }
                    ?>
                    <div class="reg-time-row">
                        <div>
                            <input type="hidden" name="event_id[]" value="<?= $se['event_id'] ?>">
                            <strong><?= htmlspecialchars($se['event_name']) ?></strong>
                            <br><small class="text-muted"><?= date('Y-m-d', strtotime($se['event_date'])) ?></small>
                        </div>
                        <div>
                            <label class="text-xs text-secondary">Öppnar</label>
                            <div class="flex gap-xs">
                                <input type="date" name="opens_date[]" class="admin-form-input" value="<?= $opensDate ?>">
                                <input type="time" name="opens_time[]" class="admin-form-input" value="<?= $opensTime ?>" style="width: 100px;">
                            </div>
                        </div>
                        <div>
                            <label class="text-xs text-secondary">Stänger</label>
                            <div class="flex gap-xs">
                                <input type="date" name="closes_date[]" class="admin-form-input" value="<?= $closesDate ?>">
                                <input type="time" name="closes_time[]" class="admin-form-input" value="<?= $closesTime ?>" style="width: 100px;">
                            </div>
                        </div>
                        <div></div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <div class="mt-lg">
                    <button type="submit" class="btn-admin btn-admin-primary">
                        <i data-lucide="save"></i> Spara anmälningstider
                    </button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- ============================================================ -->
<!-- PAYMENT TAB -->
<!-- ============================================================ -->
<div class="tab-content <?= $activeTab === 'payment' ? 'active' : '' ?>" id="tab-payment">
    <form method="POST">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_payment">

        <!-- Gravity ID Discount -->
        <div class="admin-card mb-lg">
            <div class="admin-card-header">
                <h2><i data-lucide="badge-check"></i> Gravity ID-rabatt</h2>
            </div>
            <div class="admin-card-body">
                <p class="text-secondary mb-md">
                    Medlemmar med Gravity ID kan få rabatt på anmälan.
                </p>

                <div class="admin-form-group">
                    <label class="admin-form-label">Rabatt (SEK)</label>
                    <input type="number" name="gravity_id_discount" class="admin-form-input" style="max-width: 150px;"
                           value="<?= htmlspecialchars($series['gravity_id_discount'] ?? 0) ?>"
                           min="0" step="1">
                    <small class="text-secondary">0 = inaktiverat</small>
                </div>
            </div>
        </div>

        <button type="submit" class="btn-admin btn-admin-primary">
            <i data-lucide="save"></i> Spara betalningsinställningar
        </button>
    </form>
</div>

<!-- ============================================================ -->
<!-- RESULTS TAB -->
<!-- ============================================================ -->
<div class="tab-content <?= $activeTab === 'results' ? 'active' : '' ?>" id="tab-results">
    <div class="grid grid-cols-1 gs-lg-grid-cols-3 gap-lg">
        <!-- Settings -->
        <div>
            <div class="admin-card mb-lg">
                <div class="admin-card-header">
                    <h2><i data-lucide="calculator"></i> Poängräkning</h2>
                </div>
                <div class="admin-card-body">
                    <form method="POST">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="update_count_best">

                        <div class="admin-form-group">
                            <label class="admin-form-label">Räkna bästa resultat</label>
                            <select name="count_best_results" class="admin-form-select" onchange="this.form.submit()">
                                <option value="null" <?= ($series['count_best_results'] ?? null) === null ? 'selected' : '' ?>>
                                    Alla resultat
                                </option>
                                <?php for ($i = 1; $i <= 10; $i++): ?>
                                <option value="<?= $i ?>" <?= ($series['count_best_results'] ?? null) == $i ? 'selected' : '' ?>>
                                    Bästa <?= $i ?> av <?= $eventsCount ?>
                                </option>
                                <?php endfor; ?>
                            </select>
                            <small class="text-secondary">
                                Stryker sämsta resultat från totalen
                            </small>
                        </div>
                    </form>
                </div>
            </div>

            <div class="admin-card mb-lg">
                <div class="admin-card-header">
                    <h2><i data-lucide="users"></i> Klubbmästerskap</h2>
                </div>
                <div class="admin-card-body">
                    <form method="POST">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="update_club_championship">

                        <div class="admin-form-group">
                            <label class="admin-form-label">
                                <input type="checkbox" name="enable_club_championship" value="1"
                                       <?= !empty($series['enable_club_championship']) ? 'checked' : '' ?>
                                       onchange="this.form.submit()">
                                Visa klubbmästerskap
                            </label>
                            <small class="text-secondary">
                                Aktivera klubbmästerskapställning för denna serie
                            </small>
                        </div>
                    </form>
                </div>
            </div>

            <div class="admin-card">
                <div class="admin-card-header">
                    <h2><i data-lucide="refresh-cw"></i> Omräkna poäng</h2>
                </div>
                <div class="admin-card-body">
                    <p class="text-secondary mb-md">
                        Beräkna om alla seriepoäng baserat på resultat och poängmallar.
                    </p>
                    <form method="POST">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="recalculate_all">
                        <button type="submit" class="btn-admin btn-admin-primary w-full"
                                onclick="return confirm('Beräkna om alla seriepoäng?');">
                            <i data-lucide="refresh-cw"></i> Beräkna om
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <!-- Stats & Links -->
        <div class="gs-lg-col-span-2">
            <div class="admin-card">
                <div class="admin-card-header">
                    <h2><i data-lucide="bar-chart-3"></i> Statistik</h2>
                </div>
                <div class="admin-card-body">
                    <div class="grid grid-stats grid-gap-md mb-lg">
                        <div class="admin-stat-card">
                            <div class="admin-stat-value"><?= $eventsWithResults ?>/<?= $eventsCount ?></div>
                            <div class="admin-stat-label">Events med resultat</div>
                        </div>
                        <div class="admin-stat-card">
                            <div class="admin-stat-value"><?= number_format($uniqueParticipants) ?></div>
                            <div class="admin-stat-label">Unika deltagare</div>
                        </div>
                    </div>

                    <div class="flex gap-md flex-wrap">
                        <a href="/series/<?= $id ?>" class="btn-admin btn-admin-secondary" target="_blank">
                            <i data-lucide="external-link"></i> Visa serietabell
                        </a>
                        <a href="/admin/club-points?series=<?= $id ?>" class="btn-admin btn-admin-secondary">
                            <i data-lucide="building-2"></i> Klubbpoäng
                        </a>
                        <a href="/admin/import-results" class="btn-admin btn-admin-secondary">
                            <i data-lucide="upload"></i> Importera resultat
                        </a>
                    </div>
                </div>
            </div>

            <?php if ($eventsCount > 0 && $eventsWithResults >= $eventsCount && $series['status'] !== 'completed'): ?>
            <div class="alert alert-success mt-lg">
                <i data-lucide="check-circle"></i>
                <strong>Alla events har resultat!</strong>
                Du kan nu markera serien som "Avslutad" under Info-fliken för att beräkna seriemästare.
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>
<!-- ============================================================ -->
<!-- SPONSORS TAB -->
<!-- ============================================================ -->
<div class="tab-content <?= $activeTab === 'sponsors' ? 'active' : '' ?>" id="tab-sponsors">
    <form method="POST">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_sponsors">

        <div class="admin-card">
            <div class="admin-card-header">
                <h2><i data-lucide="heart"></i> Sponsorer &amp; Partners</h2>
            </div>
            <div class="admin-card-body">
                <p class="mb-lg text-secondary text-sm">
                    Välj bilder till sponsorplatserna. Ladda upp bilder via
                    <a href="/admin/media?folder=sponsors" target="_blank">Mediabiblioteket</a> först.
                    Dessa sponsorer visas på seriesidan och ärvs av event utan egna sponsorer.
                </p>

                <div class="flex flex-col gap-lg">
                    <!-- Banner sponsor -->
                    <div class="admin-form-group">
                        <label class="admin-form-label">Banner-sponsor (bred bild högst upp)</label>
                        <div id="spl-header" class="placement-imgs"></div>
                        <div id="spl-header-inputs"></div>
                        <button type="button" class="btn btn-sm btn-secondary mt-sm" onclick="openSeriesImgPicker('header',1)">
                            <i data-lucide="image-plus" class="icon-sm"></i> Välj bild
                        </button>
                        <small class="form-help block mt-sm">Rekommenderad storlek: 1200 x 150 px</small>
                    </div>

                    <!-- Logo row -->
                    <div class="admin-form-group">
                        <label class="admin-form-label">
                            Logo-rad (under serieinfo)
                            <span id="spl-content-count" class="font-normal text-secondary ml-sm"></span>
                        </label>
                        <div id="spl-content" class="placement-imgs"></div>
                        <div id="spl-content-inputs"></div>
                        <button type="button" class="btn btn-sm btn-secondary mt-sm" onclick="openSeriesImgPicker('content',5)">
                            <i data-lucide="image-plus" class="icon-sm"></i> Lägg till bild
                        </button>
                        <small class="form-help block mt-sm">Max 5. Rekommenderad storlek: 600 x 150 px</small>
                    </div>

                    <!-- Results sponsor -->
                    <div class="admin-form-group">
                        <label class="admin-form-label">Resultat-sponsor</label>
                        <div id="spl-sidebar" class="placement-imgs"></div>
                        <div id="spl-sidebar-inputs"></div>
                        <button type="button" class="btn btn-sm btn-secondary mt-sm" onclick="openSeriesImgPicker('sidebar',1)">
                            <i data-lucide="image-plus" class="icon-sm"></i> Välj bild
                        </button>
                        <small class="form-help block mt-sm">Visas som "Resultat sponsrat av..."</small>
                    </div>

                    <!-- Partners -->
                    <div class="admin-form-group">
                        <label class="admin-form-label">
                            Samarbetspartners
                            <span id="spl-partner-count" class="font-normal text-secondary ml-sm"></span>
                        </label>
                        <div id="spl-partner" class="placement-imgs"></div>
                        <div id="spl-partner-inputs"></div>
                        <button type="button" class="btn btn-sm btn-secondary mt-sm" onclick="openSeriesImgPicker('partner',0)">
                            <i data-lucide="image-plus" class="icon-sm"></i> Lägg till bild
                        </button>
                        <small class="form-help block mt-sm">Visas längst ner på seriesidan</small>
                    </div>
                </div>

                <div class="mt-lg">
                    <button type="submit" class="btn-admin btn-admin-primary">
                        <i data-lucide="save"></i> Spara sponsorer
                    </button>
                </div>
            </div>
        </div>
    </form>
</div>

<!-- Image Picker Modal (series sponsors) -->
<div id="seriesImgPickerModal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;z-index:9999;background:rgba(0,0,0,0.6);">
    <div style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);width:92%;max-width:700px;background:var(--color-bg-surface);border-radius:var(--radius-lg);border:1px solid var(--color-border);max-height:85vh;display:flex;flex-direction:column;">
        <div style="padding:var(--space-md) var(--space-lg);border-bottom:1px solid var(--color-border);display:flex;justify-content:space-between;align-items:center;">
            <h3 style="margin:0;font-size:1rem;">Välj bild</h3>
            <button type="button" onclick="closeSeriesImgPicker()" style="background:none;border:none;cursor:pointer;color:var(--color-text-secondary);font-size:1.5rem;line-height:1;">&times;</button>
        </div>
        <div style="padding:var(--space-md);border-bottom:1px solid var(--color-border);display:flex;gap:var(--space-sm);align-items:center;flex-wrap:wrap;">
            <input type="file" id="seriesImgPickerUpload" accept="image/*" style="display:none" onchange="seriesUploadAndPick(this)">
            <button type="button" class="btn btn-sm btn-primary" onclick="document.getElementById('seriesImgPickerUpload').click()">
                <i data-lucide="upload" class="icon-sm"></i> Ladda upp ny bild
            </button>
            <span class="text-secondary text-sm">eller välj en befintlig nedan</span>
        </div>
        <div id="seriesImgPickerGrid" style="padding:var(--space-md);overflow-y:auto;flex:1;display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:var(--space-sm);"></div>
    </div>
</div>

<?php endif; // End of !$isNewSeries for Events, Registration, Payment, Results tabs ?>

<!-- Shared sponsor picker styles -->
<style>
.placement-imgs {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-sm);
    min-height: 40px;
}
.pl-tile {
    position: relative;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border: 1px solid var(--color-border);
    border-radius: var(--radius-sm);
    background: var(--color-bg-card);
    overflow: hidden;
    max-width: 200px;
    height: 60px;
    padding: var(--space-2xs);
}
.pl-tile img {
    max-width: 100%;
    max-height: 100%;
    object-fit: contain;
}
.pl-tile-text {
    font-size: 0.75rem;
    color: var(--color-text-secondary);
    padding: 0 var(--space-xs);
}
.pl-tile-remove {
    position: absolute;
    top: 0;
    right: 0;
    background: var(--color-error);
    color: #fff;
    border: none;
    cursor: pointer;
    width: 20px;
    height: 20px;
    font-size: 14px;
    line-height: 1;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 0 var(--radius-sm) 0 var(--radius-sm);
    opacity: 0.8;
}
.pl-tile-remove:hover { opacity: 1; }
.img-picker-item {
    cursor: pointer;
    border: 2px solid var(--color-border);
    border-radius: var(--radius-sm);
    overflow: hidden;
    aspect-ratio: 4/3;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    background: var(--color-bg-card);
    transition: border-color 0.15s;
}
.img-picker-item:hover { border-color: var(--color-accent); }
.img-picker-item img {
    width: 100%;
    flex: 1;
    object-fit: contain;
    padding: var(--space-2xs);
}
.img-picker-name {
    font-size: 0.65rem;
    color: var(--color-text-muted);
    padding: 2px var(--space-2xs);
    text-align: center;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    width: 100%;
}
</style>

<script>
// =====================================================
// Series sponsor image picker (mirrors event-edit.php)
// =====================================================
const seriesSponsorLookup = <?= json_encode($sponsorLookup) ?>;
const seriesInitPlacements = <?= json_encode($seriesSponsors) ?>;
let seriesPickerPlacement = null;
let seriesPickerMax = 0;
const seriesPlacements = { header: [], content: [], sidebar: [], partner: [] };

function initSeriesSponsors() {
    for (const [pl, ids] of Object.entries(seriesInitPlacements)) {
        ids.forEach(function(id) {
            const sp = seriesSponsorLookup[id];
            if (sp) addToSeriesPlacement(pl, id, sp.name, sp.logo_url, false);
        });
    }
    updateSeriesCounts();
}

function addToSeriesPlacement(pl, sponsorId, name, logoUrl, updateCount) {
    if (seriesPlacements[pl].some(function(s){ return s.id === sponsorId; })) return;
    if ((pl === 'header' || pl === 'sidebar') && seriesPlacements[pl].length > 0) {
        clearSeriesPlacement(pl);
    }
    seriesPlacements[pl].push({ id: sponsorId, name: name, logo_url: logoUrl });

    const container = document.getElementById('spl-' + pl);
    const tile = document.createElement('div');
    tile.className = 'pl-tile';
    tile.dataset.sponsorId = sponsorId;
    tile.innerHTML = (logoUrl
        ? '<img src="' + logoUrl + '" alt="' + (name||'') + '" title="' + (name||'') + '">'
        : '<span class="pl-tile-text">' + (name||'?') + '</span>')
        + '<button type="button" class="pl-tile-remove" onclick="removeFromSeriesPlacement(\'' + pl + '\',' + sponsorId + ')" title="Ta bort">&times;</button>';
    container.appendChild(tile);

    const inputContainer = document.getElementById('spl-' + pl + '-inputs');
    const input = document.createElement('input');
    input.type = 'hidden';
    input.id = 'spl-input-' + pl + '-' + sponsorId;
    input.name = (pl === 'header' || pl === 'sidebar') ? 'sponsor_' + pl : 'sponsor_' + pl + '[]';
    input.value = sponsorId;
    inputContainer.appendChild(input);

    if (updateCount !== false) updateSeriesCounts();
}

function removeFromSeriesPlacement(pl, sponsorId) {
    seriesPlacements[pl] = seriesPlacements[pl].filter(function(s){ return s.id !== sponsorId; });
    const tile = document.querySelector('#spl-' + pl + ' .pl-tile[data-sponsor-id="' + sponsorId + '"]');
    if (tile) tile.remove();
    const input = document.getElementById('spl-input-' + pl + '-' + sponsorId);
    if (input) input.remove();
    updateSeriesCounts();
}

function clearSeriesPlacement(pl) {
    seriesPlacements[pl] = [];
    document.getElementById('spl-' + pl).innerHTML = '';
    document.getElementById('spl-' + pl + '-inputs').innerHTML = '';
}

function updateSeriesCounts() {
    const cc = document.getElementById('spl-content-count');
    if (cc) cc.textContent = '(' + seriesPlacements.content.length + '/5 valda)';
    const pc = document.getElementById('spl-partner-count');
    if (pc) pc.textContent = '(' + seriesPlacements.partner.length + ' valda)';
}

function openSeriesImgPicker(pl, max) {
    if (max > 0 && seriesPlacements[pl].length >= max) {
        alert('Max ' + max + ' bilder tillåtet');
        return;
    }
    seriesPickerPlacement = pl;
    seriesPickerMax = max;
    loadSeriesImgPickerGrid();
    document.getElementById('seriesImgPickerModal').style.display = 'block';
}

function closeSeriesImgPicker() {
    document.getElementById('seriesImgPickerModal').style.display = 'none';
    seriesPickerPlacement = null;
}

async function loadSeriesImgPickerGrid() {
    const grid = document.getElementById('seriesImgPickerGrid');
    grid.innerHTML = '<p style="color:var(--color-text-secondary);text-align:center;grid-column:1/-1;">Laddar bilder...</p>';
    try {
        const response = await fetch('/api/media.php?action=list&folder=sponsors&subfolders=1&limit=200');
        if (!response.ok) throw new Error('HTTP ' + response.status);
        const result = await response.json();
        grid.innerHTML = '';
        if (!result.success || !Array.isArray(result.data) || !result.data.length) {
            grid.innerHTML = '<p style="color:var(--color-text-secondary);text-align:center;grid-column:1/-1;">Inga bilder. Ladda upp via <a href="/admin/media?folder=sponsors" target="_blank">Mediabiblioteket</a>.</p>';
            return;
        }
        let imgCount = 0;
        result.data.forEach(function(media) {
            if (!media.mime_type || !media.mime_type.startsWith('image/')) return;
            const imgSrc = media.url || ('/' + media.filepath);
            const div = document.createElement('div');
            div.className = 'img-picker-item';
            div.innerHTML = '<img src="' + imgSrc + '" alt="' + (media.original_filename||'') + '" onerror="this.style.display=\'none\'">'
                + '<span class="img-picker-name">' + (media.original_filename||'').substring(0,25) + '</span>';
            div.onclick = function() { selectSeriesMedia(media.id, imgSrc); };
            grid.appendChild(div);
            imgCount++;
        });
        if (imgCount === 0) {
            grid.innerHTML = '<p style="color:var(--color-text-secondary);text-align:center;grid-column:1/-1;">Inga bilder hittades.</p>';
        }
    } catch (e) {
        console.error('Series image picker error:', e);
        grid.innerHTML = '<p style="color:var(--color-error);text-align:center;grid-column:1/-1;">Kunde inte ladda bilder: ' + e.message + '</p>';
    }
}

async function seriesUploadAndPick(input) {
    if (!input.files.length) return;
    const formData = new FormData();
    formData.append('file', input.files[0]);
    formData.append('folder', 'sponsors');
    try {
        const response = await fetch('/api/media.php?action=upload', { method: 'POST', body: formData });
        const result = await response.json();
        if (result.success && result.data) {
            const url = result.data.url || ('/' + result.data.filepath);
            await selectSeriesMedia(result.data.id, url);
        } else {
            alert('Kunde inte ladda upp: ' + (result.error || 'Okänt fel'));
        }
    } catch (e) {
        alert('Uppladdningsfel');
    }
    input.value = '';
}

async function selectSeriesMedia(mediaId, imageUrl) {
    try {
        const response = await fetch('/api/sponsors.php?action=find_or_create_by_media&media_id=' + mediaId);
        const result = await response.json();
        if (result.success && result.data) {
            if (result.created && !result.data.website) {
                const website = prompt('Ange sponsorns webbplats (krävs):', 'https://');
                if (website && website !== 'https://' && website.length > 5) {
                    await fetch('/api/sponsors.php?action=update_website', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ sponsor_id: result.data.id, website: website })
                    });
                }
            }
            addToSeriesPlacement(seriesPickerPlacement, result.data.id, result.data.name, imageUrl, true);
            closeSeriesImgPicker();
        } else {
            alert('Fel: ' + (result.error || 'Okänt fel'));
        }
    } catch (e) {
        alert('Kunde inte skapa sponsor');
    }
}

// Init on page load
document.addEventListener('DOMContentLoaded', function() {
    if (document.getElementById('spl-header')) {
        initSeriesSponsors();
    }
    if (typeof lucide !== 'undefined') {
        lucide.createIcons();
    }
});
</script>

<?php include __DIR__ . '/components/unified-layout-footer.php'; ?>
