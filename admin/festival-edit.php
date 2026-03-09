<?php
/**
 * Admin Festival Edit - Skapa och redigera festivaler
 * Flikar: Grundinfo, Tävlingsevent, Aktiviteter, Festivalpass, Sponsorer
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();

global $pdo;

// ============================================================
// CHECK TABLE EXISTS
// ============================================================
try {
    $pdo->query("SELECT 1 FROM festivals LIMIT 1");
} catch (PDOException $e) {
    $_SESSION['flash_message'] = 'Kör migration 085 först via Migrationer';
    $_SESSION['flash_type'] = 'error';
    header('Location: /admin/migrations.php');
    exit;
}

// ============================================================
// GET ID / NEW MODE
// ============================================================
$id = intval($_GET['id'] ?? 0);
$isNew = isset($_GET['new']) || $id <= 0;
$activeTab = $_GET['tab'] ?? 'info';

// ============================================================
// HANDLE POST ACTIONS
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'save_info';

    // ── Save basic info ──
    if ($action === 'save_info') {
        $data = [
            'name' => trim($_POST['name'] ?? ''),
            'slug' => trim($_POST['slug'] ?? ''),
            'short_description' => trim($_POST['short_description'] ?? ''),
            'description' => trim($_POST['description'] ?? ''),
            'start_date' => $_POST['start_date'] ?? null,
            'end_date' => $_POST['end_date'] ?? null,
            'location' => trim($_POST['location'] ?? ''),
            'venue_id' => !empty($_POST['venue_id']) ? intval($_POST['venue_id']) : null,
            'website' => trim($_POST['website'] ?? ''),
            'contact_email' => trim($_POST['contact_email'] ?? ''),
            'contact_phone' => trim($_POST['contact_phone'] ?? ''),
            'venue_coordinates' => trim($_POST['venue_coordinates'] ?? ''),
            'venue_map_url' => trim($_POST['venue_map_url'] ?? ''),
            'status' => $_POST['status'] ?? 'draft',
            'header_banner_media_id' => !empty($_POST['header_banner_media_id']) ? intval($_POST['header_banner_media_id']) : null,
            'logo_media_id' => !empty($_POST['logo_media_id']) ? intval($_POST['logo_media_id']) : null,
        ];

        if (empty($data['name'])) {
            $_SESSION['flash_message'] = 'Namn krävs';
            $_SESSION['flash_type'] = 'error';
        } else {
            // Auto-generate slug
            if (empty($data['slug'])) {
                $data['slug'] = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $data['name']));
                $data['slug'] = trim($data['slug'], '-');
            }

            if ($isNew) {
                $data['created_by'] = $_SESSION['admin_id'] ?? null;
                $cols = implode(', ', array_keys($data));
                $placeholders = implode(', ', array_fill(0, count($data), '?'));
                $stmt = $pdo->prepare("INSERT INTO festivals ($cols) VALUES ($placeholders)");
                $stmt->execute(array_values($data));
                $id = $pdo->lastInsertId();
                $isNew = false;
                $_SESSION['flash_message'] = 'Festival skapad!';
                $_SESSION['flash_type'] = 'success';
                header("Location: /admin/festival-edit.php?id=$id&tab=events");
                exit;
            } else {
                $sets = [];
                $vals = [];
                foreach ($data as $col => $val) {
                    $sets[] = "$col = ?";
                    $vals[] = $val;
                }
                $vals[] = $id;
                $stmt = $pdo->prepare("UPDATE festivals SET " . implode(', ', $sets) . " WHERE id = ?");
                $stmt->execute($vals);
                $_SESSION['flash_message'] = 'Festival uppdaterad';
                $_SESSION['flash_type'] = 'success';
            }
        }
        header("Location: /admin/festival-edit.php?id=$id&tab=info");
        exit;
    }

    // ── Add event to festival ──
    if ($action === 'add_event' && $id > 0) {
        $eventId = intval($_POST['event_id'] ?? 0);
        if ($eventId > 0) {
            try {
                $stmt = $pdo->prepare("INSERT IGNORE INTO festival_events (festival_id, event_id, sort_order) VALUES (?, ?, (SELECT COALESCE(MAX(fe2.sort_order),0)+1 FROM festival_events fe2 WHERE fe2.festival_id = ?))");
                $stmt->execute([$id, $eventId, $id]);
                // Also set convenience column
                $pdo->prepare("UPDATE events SET festival_id = ? WHERE id = ? AND festival_id IS NULL")->execute([$id, $eventId]);
                $_SESSION['flash_message'] = 'Event tillagt';
                $_SESSION['flash_type'] = 'success';
            } catch (PDOException $e) {
                $_SESSION['flash_message'] = 'Kunde inte lägga till event';
                $_SESSION['flash_type'] = 'error';
            }
        }
        header("Location: /admin/festival-edit.php?id=$id&tab=events");
        exit;
    }

    // ── Remove event from festival ──
    if ($action === 'remove_event' && $id > 0) {
        $eventId = intval($_POST['event_id'] ?? 0);
        $pdo->prepare("DELETE FROM festival_events WHERE festival_id = ? AND event_id = ?")->execute([$id, $eventId]);
        $pdo->prepare("UPDATE events SET festival_id = NULL WHERE id = ? AND festival_id = ?")->execute([$eventId, $id]);
        $_SESSION['flash_message'] = 'Event borttaget från festivalen';
        $_SESSION['flash_type'] = 'success';
        header("Location: /admin/festival-edit.php?id=$id&tab=events");
        exit;
    }

    // ── Save activity ──
    if ($action === 'save_activity' && $id > 0) {
        $actId = intval($_POST['activity_id'] ?? 0);
        $actData = [
            'festival_id' => $id,
            'name' => trim($_POST['act_name'] ?? ''),
            'description' => trim($_POST['act_description'] ?? ''),
            'activity_type' => $_POST['act_type'] ?? 'other',
            'date' => $_POST['act_date'] ?? null,
            'start_time' => !empty($_POST['act_start_time']) ? $_POST['act_start_time'] : null,
            'end_time' => !empty($_POST['act_end_time']) ? $_POST['act_end_time'] : null,
            'location_detail' => trim($_POST['act_location'] ?? ''),
            'instructor_name' => trim($_POST['act_instructor'] ?? ''),
            'instructor_info' => trim($_POST['act_instructor_info'] ?? ''),
            'price' => floatval($_POST['act_price'] ?? 0),
            'max_participants' => !empty($_POST['act_max']) ? intval($_POST['act_max']) : null,
            'included_in_pass' => isset($_POST['act_included_in_pass']) ? 1 : 0,
        ];

        // Add group_id if column exists
        try {
            $pdo->query("SELECT group_id FROM festival_activities LIMIT 0");
            $actData['group_id'] = !empty($_POST['act_group_id']) ? intval($_POST['act_group_id']) : null;
        } catch (PDOException $e) {
            // Column doesn't exist yet
        }

        if (empty($actData['name'])) {
            $_SESSION['flash_message'] = 'Aktivitetsnamn krävs';
            $_SESSION['flash_type'] = 'error';
        } else {
            if ($actId > 0) {
                // Update
                $sets = [];
                $vals = [];
                foreach ($actData as $col => $val) {
                    $sets[] = "$col = ?";
                    $vals[] = $val;
                }
                $vals[] = $actId;
                $pdo->prepare("UPDATE festival_activities SET " . implode(', ', $sets) . " WHERE id = ?")->execute($vals);
                $_SESSION['flash_message'] = 'Aktivitet uppdaterad';
            } else {
                // Create
                $cols = implode(', ', array_keys($actData));
                $placeholders = implode(', ', array_fill(0, count($actData), '?'));
                $pdo->prepare("INSERT INTO festival_activities ($cols) VALUES ($placeholders)")->execute(array_values($actData));
                $_SESSION['flash_message'] = 'Aktivitet skapad';
            }
            $_SESSION['flash_type'] = 'success';
        }
        header("Location: /admin/festival-edit.php?id=$id&tab=activities");
        exit;
    }

    // ── Delete activity ──
    if ($action === 'delete_activity' && $id > 0) {
        $actId = intval($_POST['activity_id'] ?? 0);
        $pdo->prepare("DELETE FROM festival_activities WHERE id = ? AND festival_id = ?")->execute([$actId, $id]);
        $_SESSION['flash_message'] = 'Aktivitet raderad';
        $_SESSION['flash_type'] = 'success';
        header("Location: /admin/festival-edit.php?id=$id&tab=activities");
        exit;
    }

    // ── Save activity group ──
    if ($action === 'save_group' && $id > 0) {
        $groupId = intval($_POST['group_id'] ?? 0);
        $groupData = [
            'festival_id' => $id,
            'name' => trim($_POST['grp_name'] ?? ''),
            'description' => trim($_POST['grp_description'] ?? ''),
            'short_description' => trim($_POST['grp_short_description'] ?? ''),
            'activity_type' => $_POST['grp_type'] ?? 'other',
            'date' => $_POST['grp_date'] ?? null,
            'start_time' => !empty($_POST['grp_start_time']) ? $_POST['grp_start_time'] : null,
            'end_time' => !empty($_POST['grp_end_time']) ? $_POST['grp_end_time'] : null,
            'location_detail' => trim($_POST['grp_location'] ?? ''),
            'instructor_name' => trim($_POST['grp_instructor'] ?? ''),
            'instructor_info' => trim($_POST['grp_instructor_info'] ?? ''),
        ];

        if (empty($groupData['name'])) {
            $_SESSION['flash_message'] = 'Gruppnamn krävs';
            $_SESSION['flash_type'] = 'error';
        } else {
            try {
                if ($groupId > 0) {
                    $sets = [];
                    $vals = [];
                    foreach ($groupData as $col => $val) {
                        $sets[] = "$col = ?";
                        $vals[] = $val;
                    }
                    $vals[] = $groupId;
                    $pdo->prepare("UPDATE festival_activity_groups SET " . implode(', ', $sets) . " WHERE id = ?")->execute($vals);
                    $_SESSION['flash_message'] = 'Grupp uppdaterad';
                } else {
                    $cols = implode(', ', array_keys($groupData));
                    $placeholders = implode(', ', array_fill(0, count($groupData), '?'));
                    $pdo->prepare("INSERT INTO festival_activity_groups ($cols) VALUES ($placeholders)")->execute(array_values($groupData));
                    $_SESSION['flash_message'] = 'Grupp skapad';
                }
                $_SESSION['flash_type'] = 'success';
            } catch (PDOException $e) {
                $_SESSION['flash_message'] = 'Fel: ' . $e->getMessage();
                $_SESSION['flash_type'] = 'error';
            }
        }
        header("Location: /admin/festival-edit.php?id=$id&tab=groups");
        exit;
    }

    // ── Delete activity group ──
    if ($action === 'delete_group' && $id > 0) {
        $groupId = intval($_POST['group_id'] ?? 0);
        // Unlink activities (set group_id = NULL), then delete group
        try {
            $pdo->prepare("UPDATE festival_activities SET group_id = NULL WHERE group_id = ?")->execute([$groupId]);
            $pdo->prepare("DELETE FROM festival_activity_groups WHERE id = ? AND festival_id = ?")->execute([$groupId, $id]);
            $_SESSION['flash_message'] = 'Grupp raderad (aktiviteter bevarade)';
            $_SESSION['flash_type'] = 'success';
        } catch (PDOException $e) {
            $_SESSION['flash_message'] = 'Fel: ' . $e->getMessage();
            $_SESSION['flash_type'] = 'error';
        }
        header("Location: /admin/festival-edit.php?id=$id&tab=groups");
        exit;
    }

    // ── Assign activity to group ──
    if ($action === 'assign_activity_group' && $id > 0) {
        $actId = intval($_POST['activity_id'] ?? 0);
        $groupId = !empty($_POST['group_id']) ? intval($_POST['group_id']) : null;
        try {
            $pdo->prepare("UPDATE festival_activities SET group_id = ? WHERE id = ? AND festival_id = ?")->execute([$groupId, $actId, $id]);
            $_SESSION['flash_message'] = 'Aktivitet uppdaterad';
            $_SESSION['flash_type'] = 'success';
        } catch (PDOException $e) {
            $_SESSION['flash_message'] = 'Fel: ' . $e->getMessage();
            $_SESSION['flash_type'] = 'error';
        }
        header("Location: /admin/festival-edit.php?id=$id&tab=activities");
        exit;
    }

    // ── Save activity slot ──
    if ($action === 'save_slot' && $id > 0) {
        $slotActId = intval($_POST['slot_activity_id'] ?? 0);
        $slotId = intval($_POST['slot_id'] ?? 0);
        $slotData = [
            'activity_id' => $slotActId,
            'date' => $_POST['slot_date'] ?? null,
            'start_time' => $_POST['slot_start_time'] ?? null,
            'end_time' => !empty($_POST['slot_end_time']) ? $_POST['slot_end_time'] : null,
            'max_participants' => !empty($_POST['slot_max']) ? intval($_POST['slot_max']) : null,
        ];

        if (empty($slotData['date']) || empty($slotData['start_time'])) {
            $_SESSION['flash_message'] = 'Datum och starttid krävs för tidspass';
            $_SESSION['flash_type'] = 'error';
        } else {
            try {
                if ($slotId > 0) {
                    $sets = [];
                    $vals = [];
                    foreach ($slotData as $col => $val) {
                        $sets[] = "$col = ?";
                        $vals[] = $val;
                    }
                    $vals[] = $slotId;
                    $pdo->prepare("UPDATE festival_activity_slots SET " . implode(', ', $sets) . " WHERE id = ?")->execute($vals);
                    $_SESSION['flash_message'] = 'Tidspass uppdaterat';
                } else {
                    $cols = implode(', ', array_keys($slotData));
                    $placeholders = implode(', ', array_fill(0, count($slotData), '?'));
                    $pdo->prepare("INSERT INTO festival_activity_slots ($cols) VALUES ($placeholders)")->execute(array_values($slotData));
                    $_SESSION['flash_message'] = 'Tidspass skapat';
                }
                $_SESSION['flash_type'] = 'success';
            } catch (PDOException $e) {
                $_SESSION['flash_message'] = 'Fel: ' . $e->getMessage();
                $_SESSION['flash_type'] = 'error';
            }
        }
        header("Location: /admin/festival-edit.php?id=$id&tab=activities&edit_act=$slotActId#slots-section");
        exit;
    }

    // ── Delete activity slot ──
    if ($action === 'delete_slot' && $id > 0) {
        $slotId = intval($_POST['slot_id'] ?? 0);
        $slotActId = intval($_POST['slot_activity_id'] ?? 0);
        try {
            $pdo->prepare("DELETE FROM festival_activity_slots WHERE id = ?")->execute([$slotId]);
            $_SESSION['flash_message'] = 'Tidspass raderat';
            $_SESSION['flash_type'] = 'success';
        } catch (PDOException $e) {
            $_SESSION['flash_message'] = 'Fel: ' . $e->getMessage();
            $_SESSION['flash_type'] = 'error';
        }
        header("Location: /admin/festival-edit.php?id=$id&tab=activities&edit_act=$slotActId#slots-section");
        exit;
    }

    // ── Toggle event included_in_pass ──
    if ($action === 'toggle_event_pass' && $id > 0) {
        $eventId = intval($_POST['event_id'] ?? 0);
        $included = isset($_POST['included_in_pass']) ? 1 : 0;
        try {
            $pdo->prepare("UPDATE festival_events SET included_in_pass = ? WHERE festival_id = ? AND event_id = ?")->execute([$included, $id, $eventId]);
            $_SESSION['flash_message'] = $included ? 'Tävling ingår nu i festivalpass' : 'Tävling borttagen från festivalpass';
            $_SESSION['flash_type'] = 'success';
        } catch (PDOException $e) {
            $_SESSION['flash_message'] = 'Fel: ' . $e->getMessage();
            $_SESSION['flash_type'] = 'error';
        }
        header("Location: /admin/festival-edit.php?id=$id&tab=events");
        exit;
    }

    // ── Save pass settings ──
    if ($action === 'save_pass' && $id > 0) {
        $pdo->prepare("UPDATE festivals SET pass_enabled = ?, pass_name = ?, pass_description = ?, pass_price = ?, pass_max_quantity = ? WHERE id = ?")->execute([
            isset($_POST['pass_enabled']) ? 1 : 0,
            trim($_POST['pass_name'] ?? 'Festivalpass'),
            trim($_POST['pass_description'] ?? ''),
            !empty($_POST['pass_price']) ? floatval($_POST['pass_price']) : null,
            !empty($_POST['pass_max_quantity']) ? intval($_POST['pass_max_quantity']) : null,
            $id
        ]);
        $_SESSION['flash_message'] = 'Passettinställningar sparade';
        $_SESSION['flash_type'] = 'success';
        header("Location: /admin/festival-edit.php?id=$id&tab=pass");
        exit;
    }
}

// ============================================================
// LOAD FESTIVAL DATA
// ============================================================
$festival = null;
$festivalEvents = [];
$activities = [];
$venues = [];

if (!$isNew && $id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM festivals WHERE id = ?");
    $stmt->execute([$id]);
    $festival = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$festival) {
        $_SESSION['flash_message'] = 'Festival hittades inte';
        $_SESSION['flash_type'] = 'error';
        header('Location: /admin/festivals.php');
        exit;
    }

    // Load media URLs for banner/logo preview
    $festivalBannerUrl = null;
    $festivalLogoUrl = null;
    if (!empty($festival['header_banner_media_id'])) {
        $mStmt = $pdo->prepare("SELECT url, original_filename FROM media WHERE id = ?");
        $mStmt->execute([$festival['header_banner_media_id']]);
        $bMedia = $mStmt->fetch(PDO::FETCH_ASSOC);
        if ($bMedia) { $festivalBannerUrl = $bMedia['url']; $festivalBannerName = $bMedia['original_filename']; }
    }
    if (!empty($festival['logo_media_id'])) {
        $mStmt = $pdo->prepare("SELECT url, original_filename FROM media WHERE id = ?");
        $mStmt->execute([$festival['logo_media_id']]);
        $lMedia = $mStmt->fetch(PDO::FETCH_ASSOC);
        if ($lMedia) { $festivalLogoUrl = $lMedia['url']; $festivalLogoName = $lMedia['original_filename']; }
    }

    // Load all media for picker
    $allMedia = [];
    try {
        $allMedia = $pdo->query("SELECT id, url, original_filename, folder FROM media ORDER BY created_at DESC LIMIT 200")->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {}

    // Load linked events (with included_in_pass if column exists)
    $feIncludedCol = '';
    try {
        $pdo->query("SELECT included_in_pass FROM festival_events LIMIT 0");
        $feIncludedCol = ', fe.included_in_pass';
    } catch (PDOException $e) {}

    $festivalEvents = $pdo->prepare("
        SELECT e.id, e.name, e.date, e.end_date, e.location, e.discipline,
            s.name as series_name,
            (SELECT COUNT(*) FROM event_registrations er WHERE er.event_id = e.id AND er.status != 'cancelled') as reg_count
            $feIncludedCol
        FROM festival_events fe
        JOIN events e ON fe.event_id = e.id
        LEFT JOIN series_events se ON se.event_id = e.id
        LEFT JOIN series s ON se.series_id = s.id
        WHERE fe.festival_id = ?
        GROUP BY e.id
        ORDER BY e.date ASC
    ");
    $festivalEvents->execute([$id]);
    $festivalEvents = $festivalEvents->fetchAll(PDO::FETCH_ASSOC);

    // Load activities
    $actStmt = $pdo->prepare("SELECT * FROM festival_activities WHERE festival_id = ? ORDER BY date ASC, start_time ASC, sort_order ASC");
    $actStmt->execute([$id]);
    $activities = $actStmt->fetchAll(PDO::FETCH_ASSOC);

    // Load activity slots (indexed by activity_id)
    $activitySlots = [];
    try {
        $slotStmt = $pdo->prepare("
            SELECT s.*,
                (SELECT COUNT(*) FROM festival_activity_registrations far WHERE far.slot_id = s.id AND far.status != 'cancelled') as reg_count
            FROM festival_activity_slots s
            JOIN festival_activities fa ON s.activity_id = fa.id
            WHERE fa.festival_id = ? AND s.active = 1
            ORDER BY s.date ASC, s.start_time ASC
        ");
        $slotStmt->execute([$id]);
        foreach ($slotStmt->fetchAll(PDO::FETCH_ASSOC) as $slot) {
            $activitySlots[$slot['activity_id']][] = $slot;
        }
    } catch (PDOException $e) {
        // Table doesn't exist yet
    }

    // Load activity groups
    $activityGroups = [];
    try {
        $gStmt = $pdo->prepare("SELECT g.*, (SELECT COUNT(*) FROM festival_activities fa WHERE fa.group_id = g.id) as activity_count FROM festival_activity_groups g WHERE g.festival_id = ? ORDER BY g.date ASC, g.sort_order ASC");
        $gStmt->execute([$id]);
        $activityGroups = $gStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        // Table doesn't exist yet
    }
}

// Venues for dropdown
try {
    $venues = $pdo->query("SELECT id, name FROM venues WHERE active = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $venues = [];
}

// ============================================================
// PAGE CONFIG
// ============================================================
$page_title = $isNew ? 'Ny festival' : 'Redigera: ' . htmlspecialchars($festival['name']);
$breadcrumbs = [
    ['label' => 'Festivaler', 'url' => '/admin/festivals.php'],
    ['label' => $isNew ? 'Ny' : htmlspecialchars($festival['name'])]
];
include __DIR__ . '/components/unified-layout.php';

// Activity type labels
$activityTypes = [
    'clinic' => ['label' => 'Clinic', 'icon' => 'bike'],
    'lecture' => ['label' => 'Föreläsning', 'icon' => 'presentation'],
    'groupride' => ['label' => 'Grupptur', 'icon' => 'route'],
    'workshop' => ['label' => 'Workshop', 'icon' => 'wrench'],
    'social' => ['label' => 'Socialt', 'icon' => 'users'],
    'other' => ['label' => 'Övrigt', 'icon' => 'circle-dot'],
];
?>

<style>
.festival-tabs {
    display: flex;
    gap: var(--space-xs);
    margin-bottom: var(--space-lg);
    border-bottom: 2px solid var(--color-border);
    padding-bottom: var(--space-xs);
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
}
.festival-tab {
    padding: var(--space-xs) var(--space-md);
    border-radius: var(--radius-sm) var(--radius-sm) 0 0;
    font-size: 0.875rem;
    font-weight: 600;
    color: var(--color-text-muted);
    text-decoration: none;
    white-space: nowrap;
    display: flex;
    align-items: center;
    gap: var(--space-2xs);
    transition: color 0.2s, background 0.2s;
}
.festival-tab:hover {
    color: var(--color-text-primary);
    background: var(--color-bg-hover);
}
.festival-tab.active {
    color: var(--color-accent);
    border-bottom: 2px solid var(--color-accent);
    margin-bottom: -3px;
}
.festival-tab i {
    width: 16px;
    height: 16px;
}
.form-subsection {
    padding: var(--space-md) 0;
    border-bottom: 1px solid var(--color-border);
}
.form-subsection:last-child {
    border-bottom: none;
}
.form-subsection-label {
    display: flex;
    align-items: center;
    gap: var(--space-xs);
    font-size: 0.75rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: var(--color-text-muted);
    margin-bottom: var(--space-sm);
}
.form-subsection-label i {
    width: 14px;
    height: 14px;
    color: var(--color-accent);
}
.form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: var(--space-md);
}
.form-row-3 {
    display: grid;
    grid-template-columns: 1fr 1fr 1fr;
    gap: var(--space-md);
}
.form-group {
    margin-bottom: var(--space-sm);
}
.form-group label {
    display: block;
    font-size: 0.8rem;
    font-weight: 600;
    color: var(--color-text-secondary);
    margin-bottom: 4px;
}
.form-group input,
.form-group select,
.form-group textarea {
    width: 100%;
    padding: var(--space-xs) var(--space-sm);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-sm);
    background: var(--color-bg-surface);
    color: var(--color-text-primary);
    font-size: 0.875rem;
}
.form-group textarea {
    min-height: 80px;
    resize: vertical;
}
.event-search-list {
    max-height: 300px;
    overflow-y: auto;
    border: 1px solid var(--color-border);
    border-radius: var(--radius-sm);
}
.event-search-item {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: var(--space-xs) var(--space-sm);
    border-bottom: 1px solid var(--color-border);
    gap: var(--space-sm);
}
.event-search-item:last-child {
    border-bottom: none;
}
.event-search-item:hover {
    background: var(--color-bg-hover);
}
.linked-event {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: var(--space-sm) var(--space-md);
    background: var(--color-bg-card);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-sm);
    margin-bottom: var(--space-xs);
}
.linked-event-info {
    display: flex;
    align-items: center;
    gap: var(--space-sm);
}
.linked-event-date {
    font-size: 0.75rem;
    color: var(--color-text-muted);
    min-width: 80px;
}
.linked-event-name {
    font-weight: 600;
    color: var(--color-text-primary);
}
.linked-event-series {
    font-size: 0.75rem;
    color: var(--color-accent);
}
.activity-card {
    background: var(--color-bg-card);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-sm);
    padding: var(--space-md);
    margin-bottom: var(--space-sm);
}
.activity-card-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: var(--space-sm);
}
.activity-card-title {
    font-weight: 700;
    color: var(--color-text-primary);
    display: flex;
    align-items: center;
    gap: var(--space-xs);
}
.activity-card-title i {
    width: 18px;
    height: 18px;
    color: var(--color-accent);
}
.activity-card-meta {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-sm);
    font-size: 0.8rem;
    color: var(--color-text-muted);
}
.activity-card-meta span {
    display: flex;
    align-items: center;
    gap: 3px;
}
.activity-card-meta i {
    width: 13px;
    height: 13px;
}
.pass-preview {
    background: linear-gradient(135deg, var(--color-accent-light), var(--color-bg-hover));
    border: 2px solid var(--color-accent);
    border-radius: var(--radius-md);
    padding: var(--space-lg);
    text-align: center;
    margin-bottom: var(--space-lg);
}
.pass-preview-title {
    font-family: var(--font-heading);
    font-size: 1.5rem;
    color: var(--color-accent);
    margin-bottom: var(--space-xs);
}
.pass-preview-price {
    font-size: 2rem;
    font-weight: 800;
    color: var(--color-text-primary);
}
@media (max-width: 767px) {
    .form-row, .form-row-3 {
        grid-template-columns: 1fr;
    }
    .linked-event {
        flex-direction: column;
        align-items: flex-start;
        gap: var(--space-xs);
    }
}
</style>

<?php if (!$isNew): ?>
<!-- Tabs -->
<div class="festival-tabs">
    <a href="?id=<?= $id ?>&tab=info" class="festival-tab <?= $activeTab === 'info' ? 'active' : '' ?>">
        <i data-lucide="info"></i> Grundinfo
    </a>
    <a href="?id=<?= $id ?>&tab=events" class="festival-tab <?= $activeTab === 'events' ? 'active' : '' ?>">
        <i data-lucide="calendar"></i> Tävlingsevent
        <span class="badge" style="font-size: 0.7rem; padding: 1px 6px;"><?= count($festivalEvents) ?></span>
    </a>
    <a href="?id=<?= $id ?>&tab=groups" class="festival-tab <?= $activeTab === 'groups' ? 'active' : '' ?>">
        <i data-lucide="layers"></i> Grupper
        <span class="badge" style="font-size: 0.7rem; padding: 1px 6px;"><?= count($activityGroups) ?></span>
    </a>
    <a href="?id=<?= $id ?>&tab=activities" class="festival-tab <?= $activeTab === 'activities' ? 'active' : '' ?>">
        <i data-lucide="tent"></i> Aktiviteter
        <span class="badge" style="font-size: 0.7rem; padding: 1px 6px;"><?= count($activities) ?></span>
    </a>
    <a href="?id=<?= $id ?>&tab=pass" class="festival-tab <?= $activeTab === 'pass' ? 'active' : '' ?>">
        <i data-lucide="ticket"></i> Festivalpass
    </a>
</div>
<?php endif; ?>

<?php
// Flash messages
if (!empty($_SESSION['flash_message'])):
    $fType = $_SESSION['flash_type'] ?? 'info';
    $fClass = $fType === 'success' ? 'alert-success' : ($fType === 'error' ? 'alert-danger' : 'alert-warning');
?>
<div class="alert <?= $fClass ?>" style="margin-bottom: var(--space-md);">
    <?= htmlspecialchars($_SESSION['flash_message']) ?>
</div>
<?php
    unset($_SESSION['flash_message'], $_SESSION['flash_type']);
endif;
?>

<!-- ============================================================ -->
<!-- TAB: GRUNDINFO                                                -->
<!-- ============================================================ -->
<?php if ($isNew || $activeTab === 'info'): ?>

<form method="post" action="/admin/festival-edit.php?id=<?= $id ?>&tab=info">
    <input type="hidden" name="action" value="save_info">

    <div class="admin-card">
        <div class="admin-card-header">
            <h3><?= $isNew ? 'Skapa ny festival' : 'Grundläggande information' ?></h3>
        </div>
        <div class="admin-card-body">

            <!-- Namn & slug -->
            <div class="form-subsection">
                <div class="form-subsection-label"><i data-lucide="type"></i> Namn</div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Festivalnamn *</label>
                        <input type="text" name="name" value="<?= htmlspecialchars($festival['name'] ?? '') ?>" required placeholder="T.ex. Götaland Gravity Festival">
                    </div>
                    <div class="form-group">
                        <label>Slug (URL)</label>
                        <input type="text" name="slug" value="<?= htmlspecialchars($festival['slug'] ?? '') ?>" placeholder="Auto-genereras">
                    </div>
                </div>
                <div class="form-group">
                    <label>Kort beskrivning (max 500 tecken)</label>
                    <input type="text" name="short_description" value="<?= htmlspecialchars($festival['short_description'] ?? '') ?>" maxlength="500" placeholder="Visas i kort och listor">
                </div>
            </div>

            <!-- Datum -->
            <div class="form-subsection">
                <div class="form-subsection-label"><i data-lucide="calendar"></i> Datum</div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Startdatum *</label>
                        <input type="date" name="start_date" value="<?= htmlspecialchars($festival['start_date'] ?? '') ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Slutdatum *</label>
                        <input type="date" name="end_date" value="<?= htmlspecialchars($festival['end_date'] ?? '') ?>" required>
                    </div>
                </div>
            </div>

            <!-- Plats -->
            <div class="form-subsection">
                <div class="form-subsection-label"><i data-lucide="map-pin"></i> Plats</div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Plats</label>
                        <input type="text" name="location" value="<?= htmlspecialchars($festival['location'] ?? '') ?>" placeholder="T.ex. Isaberg, Hestra">
                    </div>
                    <div class="form-group">
                        <label>Destination</label>
                        <select name="venue_id">
                            <option value="">Välj...</option>
                            <?php foreach ($venues as $v): ?>
                            <option value="<?= $v['id'] ?>" <?= ($festival['venue_id'] ?? '') == $v['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($v['name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>GPS-koordinater</label>
                        <input type="text" name="venue_coordinates" value="<?= htmlspecialchars($festival['venue_coordinates'] ?? '') ?>" placeholder="57.123, 13.456">
                    </div>
                    <div class="form-group">
                        <label>Google Maps-länk</label>
                        <input type="url" name="venue_map_url" value="<?= htmlspecialchars($festival['venue_map_url'] ?? '') ?>" placeholder="https://maps.google.com/...">
                    </div>
                </div>
            </div>

            <!-- Kontakt -->
            <div class="form-subsection">
                <div class="form-subsection-label"><i data-lucide="mail"></i> Kontakt</div>
                <div class="form-row-3">
                    <div class="form-group">
                        <label>Webbplats</label>
                        <input type="url" name="website" value="<?= htmlspecialchars($festival['website'] ?? '') ?>" placeholder="https://...">
                    </div>
                    <div class="form-group">
                        <label>E-post</label>
                        <input type="email" name="contact_email" value="<?= htmlspecialchars($festival['contact_email'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label>Telefon</label>
                        <input type="text" name="contact_phone" value="<?= htmlspecialchars($festival['contact_phone'] ?? '') ?>">
                    </div>
                </div>
            </div>

            <!-- Beskrivning -->
            <div class="form-subsection">
                <div class="form-subsection-label"><i data-lucide="file-text"></i> Beskrivning</div>
                <div class="form-group">
                    <label>Fullständig beskrivning</label>
                    <textarea name="description" rows="6" data-format-toolbar placeholder="Beskriv festivalen..."><?= htmlspecialchars($festival['description'] ?? '') ?></textarea>
                </div>
            </div>

            <!-- Bilder -->
            <?php if (!$isNew): ?>
            <div class="form-subsection">
                <div class="form-subsection-label"><i data-lucide="image"></i> Bilder</div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Omslagsbild (banner)</label>
                        <select name="header_banner_media_id" onchange="previewFestivalMedia(this, 'banner-preview')">
                            <option value="">-- Ingen --</option>
                            <?php foreach ($allMedia as $m): ?>
                            <option value="<?= $m['id'] ?>" data-url="<?= htmlspecialchars($m['url']) ?>" <?= ($festival['header_banner_media_id'] ?? '') == $m['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($m['original_filename']) ?> (<?= htmlspecialchars($m['folder'] ?? '') ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <div id="banner-preview" style="margin-top: var(--space-xs);">
                            <?php if ($festivalBannerUrl): ?>
                            <img src="<?= htmlspecialchars($festivalBannerUrl) ?>" alt="Banner" style="max-height: 80px; border-radius: var(--radius-sm); border: 1px solid var(--color-border);">
                            <?php endif; ?>
                        </div>
                        <small class="form-help">Rekommenderat: 1200×400 px eller liknande (3:1). Visas som bakgrundsbild i hero-sektionen. Ladda upp via <a href="/admin/media.php" target="_blank">Mediabiblioteket</a>.</small>
                    </div>
                    <div class="form-group">
                        <label>Logotyp</label>
                        <select name="logo_media_id" onchange="previewFestivalMedia(this, 'logo-preview')">
                            <option value="">-- Ingen --</option>
                            <?php foreach ($allMedia as $m): ?>
                            <option value="<?= $m['id'] ?>" data-url="<?= htmlspecialchars($m['url']) ?>" <?= ($festival['logo_media_id'] ?? '') == $m['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($m['original_filename']) ?> (<?= htmlspecialchars($m['folder'] ?? '') ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <div id="logo-preview" style="margin-top: var(--space-xs);">
                            <?php if ($festivalLogoUrl): ?>
                            <img src="<?= htmlspecialchars($festivalLogoUrl) ?>" alt="Logo" style="max-height: 60px; border-radius: var(--radius-sm);">
                            <?php endif; ?>
                        </div>
                        <small class="form-help">Visas ovanför festivalnamnet i hero-sektionen.</small>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Status -->
            <div class="form-subsection">
                <div class="form-subsection-label"><i data-lucide="toggle-left"></i> Status</div>
                <div class="form-group" style="max-width: 200px;">
                    <select name="status">
                        <option value="draft" <?= ($festival['status'] ?? 'draft') === 'draft' ? 'selected' : '' ?>>Utkast</option>
                        <option value="published" <?= ($festival['status'] ?? '') === 'published' ? 'selected' : '' ?>>Publicerad</option>
                        <option value="completed" <?= ($festival['status'] ?? '') === 'completed' ? 'selected' : '' ?>>Avslutad</option>
                        <option value="cancelled" <?= ($festival['status'] ?? '') === 'cancelled' ? 'selected' : '' ?>>Inställd</option>
                    </select>
                </div>
            </div>

        </div>
    </div>

    <div style="display: flex; gap: var(--space-sm); margin-top: var(--space-md);">
        <button type="submit" class="btn-admin btn-admin-primary">
            <i data-lucide="save"></i> <?= $isNew ? 'Skapa festival' : 'Spara ändringar' ?>
        </button>
        <a href="/admin/festivals.php" class="btn-admin btn-admin-secondary">Avbryt</a>
    </div>
</form>

<?php endif; ?>

<!-- ============================================================ -->
<!-- TAB: TÄVLINGSEVENT                                            -->
<!-- ============================================================ -->
<?php if (!$isNew && $activeTab === 'events'): ?>

<div class="admin-card" style="margin-bottom: var(--space-lg);">
    <div class="admin-card-header">
        <h3>Kopplade tävlingsevent (<?= count($festivalEvents) ?>)</h3>
    </div>
    <div class="admin-card-body">
        <?php if (empty($festivalEvents)): ?>
        <p style="color: var(--color-text-muted); text-align: center; padding: var(--space-lg);">
            Inga event kopplade ännu. Sök och lägg till nedan.
        </p>
        <?php else: ?>
            <?php foreach ($festivalEvents as $fe): ?>
            <div class="linked-event">
                <div class="linked-event-info">
                    <div class="linked-event-date"><?= date('j M Y', strtotime($fe['date'])) ?></div>
                    <div>
                        <div class="linked-event-name">
                            <a href="/admin/event-edit.php?id=<?= $fe['id'] ?>" style="color: inherit; text-decoration: none;">
                                <?= htmlspecialchars($fe['name']) ?>
                            </a>
                        </div>
                        <?php if ($fe['series_name']): ?>
                        <div class="linked-event-series"><?= htmlspecialchars($fe['series_name']) ?></div>
                        <?php endif; ?>
                        <div style="font-size: 0.75rem; color: var(--color-text-muted);">
                            <?= htmlspecialchars($fe['location'] ?? '') ?>
                            <?php if ($fe['discipline']): ?> · <?= htmlspecialchars($fe['discipline']) ?><?php endif; ?>
                            · <?= $fe['reg_count'] ?> anmälda
                        </div>
                    </div>
                </div>
                <div style="display: flex; align-items: center; gap: var(--space-sm);">
                    <?php if ($feIncludedCol): ?>
                    <form method="post" style="margin: 0;" title="Ingår i festivalpass">
                        <input type="hidden" name="action" value="toggle_event_pass">
                        <input type="hidden" name="event_id" value="<?= $fe['id'] ?>">
                        <label style="display: flex; align-items: center; gap: 4px; cursor: pointer; font-size: 0.75rem; color: var(--color-text-muted); white-space: nowrap;">
                            <input type="checkbox" name="included_in_pass" <?= ($fe['included_in_pass'] ?? 0) ? 'checked' : '' ?> onchange="this.form.submit()">
                            <i data-lucide="ticket" style="width: 12px; height: 12px;"></i> I pass
                        </label>
                    </form>
                    <?php endif; ?>
                    <form method="post" style="margin: 0;" onsubmit="return confirm('Ta bort detta event från festivalen?')">
                        <input type="hidden" name="action" value="remove_event">
                        <input type="hidden" name="event_id" value="<?= $fe['id'] ?>">
                        <button type="submit" class="btn-admin btn-admin-danger" style="padding: 4px 8px;" title="Ta bort">
                            <i data-lucide="x" style="width: 14px; height: 14px;"></i>
                        </button>
                    </form>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<!-- Sök och lägg till event -->
<div class="admin-card">
    <div class="admin-card-header">
        <h3><i data-lucide="search" style="width: 18px; height: 18px;"></i> Lägg till event</h3>
    </div>
    <div class="admin-card-body">
        <div class="form-group">
            <input type="text" id="event-search-input" placeholder="Sök event (namn, plats, datum)..." style="width: 100%;">
        </div>
        <div id="event-search-results" class="event-search-list" style="display: none;"></div>
    </div>
</div>

<script>
(function() {
    const input = document.getElementById('event-search-input');
    const results = document.getElementById('event-search-results');
    const festivalId = <?= $id ?>;
    const linkedIds = <?= json_encode(array_column($festivalEvents, 'id')) ?>;
    let debounceTimer;

    input.addEventListener('input', function() {
        clearTimeout(debounceTimer);
        const q = this.value.trim();
        if (q.length < 2) { results.style.display = 'none'; return; }

        debounceTimer = setTimeout(async () => {
            try {
                const resp = await fetch('/api/search.php?type=events&q=' + encodeURIComponent(q));
                const data = await resp.json();
                if (!data.results || data.results.length === 0) {
                    results.innerHTML = '<div style="padding: 12px; color: var(--color-text-muted);">Inga träffar</div>';
                    results.style.display = 'block';
                    return;
                }
                let html = '';
                data.results.forEach(ev => {
                    const isLinked = linkedIds.includes(ev.id);
                    html += `<div class="event-search-item">
                        <div>
                            <strong>${ev.name || ev.label || ''}</strong>
                            <div style="font-size: 0.75rem; color: var(--color-text-muted);">${ev.date || ''} · ${ev.location || ''}</div>
                        </div>
                        ${isLinked
                            ? '<span class="badge badge-success" style="font-size: 0.7rem;">Tillagd</span>'
                            : `<form method="post" style="margin:0;"><input type="hidden" name="action" value="add_event"><input type="hidden" name="event_id" value="${ev.id}"><button type="submit" class="btn-admin btn-admin-primary" style="padding: 4px 10px; font-size: 0.8rem;"><i data-lucide="plus" style="width:12px;height:12px;"></i> Lägg till</button></form>`
                        }
                    </div>`;
                });
                results.innerHTML = html;
                results.style.display = 'block';
                if (typeof lucide !== 'undefined') lucide.createIcons();
            } catch (err) {
                console.error(err);
            }
        }, 300);
    });
})();
</script>

<?php endif; ?>

<!-- ============================================================ -->
<!-- TAB: GRUPPER                                                  -->
<!-- ============================================================ -->
<?php if (!$isNew && $activeTab === 'groups'): ?>

<!-- Befintliga grupper -->
<?php if (!empty($activityGroups)): ?>
<div style="margin-bottom: var(--space-lg);">
    <?php foreach ($activityGroups as $grp):
        $grpTypeInfo = $activityTypes[$grp['activity_type']] ?? $activityTypes['other'];
    ?>
    <div class="activity-card">
        <div class="activity-card-header">
            <div class="activity-card-title">
                <i data-lucide="<?= $grpTypeInfo['icon'] ?>"></i>
                <?= htmlspecialchars($grp['name']) ?>
                <span class="badge" style="font-size: 0.65rem; padding: 1px 6px; margin-left: 4px;">
                    <?= $grpTypeInfo['label'] ?>
                </span>
                <span class="badge" style="font-size: 0.65rem; padding: 1px 6px;">
                    <?= (int)$grp['activity_count'] ?> aktiviteter
                </span>
            </div>
            <div style="display: flex; gap: var(--space-2xs);">
                <a href="/festival/<?= $id ?>/activity/<?= $grp['id'] ?>" target="_blank" class="btn-admin btn-admin-secondary" style="padding: 4px 8px;" title="Visa publik sida">
                    <i data-lucide="external-link" style="width: 14px; height: 14px;"></i>
                </a>
                <a href="?id=<?= $id ?>&tab=groups&edit_grp=<?= $grp['id'] ?>#edit-group-form" class="btn-admin btn-admin-secondary" style="padding: 4px 8px;">
                    <i data-lucide="pencil" style="width: 14px; height: 14px;"></i>
                </a>
                <form method="post" style="margin: 0;" onsubmit="return confirm('Radera denna grupp? Aktiviteter behålls men kopplas bort.')">
                    <input type="hidden" name="action" value="delete_group">
                    <input type="hidden" name="group_id" value="<?= $grp['id'] ?>">
                    <button type="submit" class="btn-admin btn-admin-danger" style="padding: 4px 8px;">
                        <i data-lucide="trash-2" style="width: 14px; height: 14px;"></i>
                    </button>
                </form>
            </div>
        </div>
        <div class="activity-card-meta">
            <?php if ($grp['date']): ?>
            <span><i data-lucide="calendar"></i> <?= date('j M Y', strtotime($grp['date'])) ?></span>
            <?php endif; ?>
            <?php if ($grp['start_time']): ?>
            <span><i data-lucide="clock"></i> <?= substr($grp['start_time'], 0, 5) ?><?= $grp['end_time'] ? ' – ' . substr($grp['end_time'], 0, 5) : '' ?></span>
            <?php endif; ?>
            <?php if ($grp['instructor_name']): ?>
            <span><i data-lucide="user"></i> <?= htmlspecialchars($grp['instructor_name']) ?></span>
            <?php endif; ?>
            <?php if ($grp['location_detail']): ?>
            <span><i data-lucide="map-pin"></i> <?= htmlspecialchars($grp['location_detail']) ?></span>
            <?php endif; ?>
        </div>
        <?php if ($grp['short_description']): ?>
        <p style="margin: var(--space-xs) 0 0; font-size: 0.85rem; color: var(--color-text-secondary);">
            <?= htmlspecialchars(mb_strimwidth($grp['short_description'], 0, 200, '...')) ?>
        </p>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Formulär: Skapa/redigera grupp -->
<?php
    $editGrp = null;
    $editGrpId = intval($_GET['edit_grp'] ?? 0);
    if ($editGrpId > 0) {
        foreach ($activityGroups as $g) {
            if ($g['id'] == $editGrpId) { $editGrp = $g; break; }
        }
    }
?>

<div class="admin-card" id="edit-group-form">
    <div class="admin-card-header">
        <h3><?= $editGrp ? 'Redigera grupp' : 'Ny grupp' ?></h3>
    </div>
    <div class="admin-card-body">
        <form method="post">
            <input type="hidden" name="action" value="save_group">
            <?php if ($editGrp): ?>
            <input type="hidden" name="group_id" value="<?= $editGrp['id'] ?>">
            <?php endif; ?>

            <div class="form-row">
                <div class="form-group">
                    <label>Gruppnamn *</label>
                    <input type="text" name="grp_name" value="<?= htmlspecialchars($editGrp['name'] ?? '') ?>" required placeholder="T.ex. Enduro Clinics">
                </div>
                <div class="form-group">
                    <label>Typ</label>
                    <select name="grp_type">
                        <?php foreach ($activityTypes as $key => $info): ?>
                        <option value="<?= $key ?>" <?= ($editGrp['activity_type'] ?? '') === $key ? 'selected' : '' ?>>
                            <?= $info['label'] ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="form-group">
                <label>Kort beskrivning (visas i programlistan)</label>
                <input type="text" name="grp_short_description" value="<?= htmlspecialchars($editGrp['short_description'] ?? '') ?>" placeholder="Max 1-2 meningar" maxlength="500">
            </div>

            <div class="form-row-3">
                <div class="form-group">
                    <label>Datum</label>
                    <input type="date" name="grp_date" value="<?= htmlspecialchars($editGrp['date'] ?? $festival['start_date'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label>Starttid</label>
                    <input type="time" name="grp_start_time" value="<?= htmlspecialchars($editGrp['start_time'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label>Sluttid</label>
                    <input type="time" name="grp_end_time" value="<?= htmlspecialchars($editGrp['end_time'] ?? '') ?>">
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>Instruktör / Ledare</label>
                    <input type="text" name="grp_instructor" value="<?= htmlspecialchars($editGrp['instructor_name'] ?? '') ?>" placeholder="Namn">
                </div>
                <div class="form-group">
                    <label>Plats (inom festivalen)</label>
                    <input type="text" name="grp_location" value="<?= htmlspecialchars($editGrp['location_detail'] ?? '') ?>" placeholder="T.ex. Start/mål-området">
                </div>
            </div>

            <div class="form-group">
                <label>Om instruktören</label>
                <textarea name="grp_instructor_info" rows="2"><?= htmlspecialchars($editGrp['instructor_info'] ?? '') ?></textarea>
            </div>

            <div class="form-group">
                <label>Beskrivning (visas på gruppsidan)</label>
                <textarea name="grp_description" rows="4"><?= htmlspecialchars($editGrp['description'] ?? '') ?></textarea>
            </div>

            <div style="display: flex; gap: var(--space-sm); margin-top: var(--space-md);">
                <button type="submit" class="btn-admin btn-admin-primary">
                    <i data-lucide="save" style="width: 16px; height: 16px;"></i>
                    <?= $editGrp ? 'Uppdatera grupp' : 'Skapa grupp' ?>
                </button>
                <?php if ($editGrp): ?>
                <a href="?id=<?= $id ?>&tab=groups" class="btn-admin btn-admin-secondary">Avbryt</a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<?php endif; ?>

<!-- ============================================================ -->
<!-- TAB: AKTIVITETER                                              -->
<!-- ============================================================ -->
<?php if (!$isNew && $activeTab === 'activities'): ?>

<!-- Befintliga aktiviteter -->
<?php if (!empty($activities)): ?>
<div style="margin-bottom: var(--space-lg);">
    <?php foreach ($activities as $act):
        $typeInfo = $activityTypes[$act['activity_type']] ?? $activityTypes['other'];
    ?>
    <div class="activity-card">
        <div class="activity-card-header">
            <div class="activity-card-title">
                <i data-lucide="<?= $typeInfo['icon'] ?>"></i>
                <?= htmlspecialchars($act['name']) ?>
                <span class="badge" style="font-size: 0.65rem; padding: 1px 6px; margin-left: 4px;">
                    <?= $typeInfo['label'] ?>
                </span>
                <?php if ($act['included_in_pass']): ?>
                <span class="badge badge-success" style="font-size: 0.65rem; padding: 1px 6px;">Ingår i pass</span>
                <?php endif; ?>
            </div>
            <div style="display: flex; gap: var(--space-2xs);">
                <a href="?id=<?= $id ?>&tab=activities&edit_act=<?= $act['id'] ?>#edit-form" class="btn-admin btn-admin-secondary" style="padding: 4px 8px;">
                    <i data-lucide="pencil" style="width: 14px; height: 14px;"></i>
                </a>
                <form method="post" style="margin: 0;" onsubmit="return confirm('Radera denna aktivitet?')">
                    <input type="hidden" name="action" value="delete_activity">
                    <input type="hidden" name="activity_id" value="<?= $act['id'] ?>">
                    <button type="submit" class="btn-admin btn-admin-danger" style="padding: 4px 8px;">
                        <i data-lucide="trash-2" style="width: 14px; height: 14px;"></i>
                    </button>
                </form>
            </div>
        </div>
        <div class="activity-card-meta">
            <span><i data-lucide="calendar"></i> <?= date('j M Y', strtotime($act['date'])) ?></span>
            <?php if ($act['start_time']): ?>
            <span><i data-lucide="clock"></i> <?= substr($act['start_time'], 0, 5) ?><?= $act['end_time'] ? ' – ' . substr($act['end_time'], 0, 5) : '' ?></span>
            <?php endif; ?>
            <?php if ($act['instructor_name']): ?>
            <span><i data-lucide="user"></i> <?= htmlspecialchars($act['instructor_name']) ?></span>
            <?php endif; ?>
            <?php if ($act['price'] > 0): ?>
            <span><i data-lucide="tag"></i> <?= number_format($act['price'], 0) ?> kr</span>
            <?php else: ?>
            <span><i data-lucide="tag"></i> Gratis</span>
            <?php endif; ?>
            <?php if ($act['max_participants']): ?>
            <span><i data-lucide="users"></i> Max <?= $act['max_participants'] ?></span>
            <?php endif; ?>
            <?php if (!empty($activitySlots[$act['id']])): ?>
            <span style="color: var(--color-accent);"><i data-lucide="clock"></i> <?= count($activitySlots[$act['id']]) ?> tidspass</span>
            <?php endif; ?>
            <?php if ($act['location_detail']): ?>
            <span><i data-lucide="map-pin"></i> <?= htmlspecialchars($act['location_detail']) ?></span>
            <?php endif; ?>
        </div>
        <?php if ($act['description']): ?>
        <p style="margin: var(--space-xs) 0 0; font-size: 0.85rem; color: var(--color-text-secondary);">
            <?= htmlspecialchars(mb_strimwidth($act['description'], 0, 200, '...')) ?>
        </p>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Formulär: Skapa/redigera aktivitet -->
<?php
    $editAct = null;
    $editActId = intval($_GET['edit_act'] ?? 0);
    if ($editActId > 0) {
        foreach ($activities as $a) {
            if ($a['id'] == $editActId) { $editAct = $a; break; }
        }
    }
?>

<div class="admin-card" id="edit-form">
    <div class="admin-card-header">
        <h3><?= $editAct ? 'Redigera aktivitet' : 'Ny aktivitet' ?></h3>
    </div>
    <div class="admin-card-body">
        <form method="post">
            <input type="hidden" name="action" value="save_activity">
            <?php if ($editAct): ?>
            <input type="hidden" name="activity_id" value="<?= $editAct['id'] ?>">
            <?php endif; ?>

            <div class="form-row">
                <div class="form-group">
                    <label>Namn *</label>
                    <input type="text" name="act_name" value="<?= htmlspecialchars($editAct['name'] ?? '') ?>" required placeholder="T.ex. Enduro Clinic">
                </div>
                <div class="form-group">
                    <label>Typ</label>
                    <select name="act_type">
                        <?php foreach ($activityTypes as $key => $info): ?>
                        <option value="<?= $key ?>" <?= ($editAct['activity_type'] ?? '') === $key ? 'selected' : '' ?>>
                            <?= $info['label'] ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <?php if (!empty($activityGroups)): ?>
            <div class="form-group">
                <label>Grupp (valfritt)</label>
                <select name="act_group_id">
                    <option value="">– Ingen grupp –</option>
                    <?php foreach ($activityGroups as $grp): ?>
                    <option value="<?= $grp['id'] ?>" <?= (int)($editAct['group_id'] ?? 0) === (int)$grp['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($grp['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>

            <div class="form-row-3">
                <div class="form-group">
                    <label>Datum *</label>
                    <input type="date" name="act_date" value="<?= htmlspecialchars($editAct['date'] ?? $festival['start_date'] ?? '') ?>" required>
                </div>
                <div class="form-group">
                    <label>Starttid</label>
                    <input type="time" name="act_start_time" value="<?= htmlspecialchars($editAct['start_time'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label>Sluttid</label>
                    <input type="time" name="act_end_time" value="<?= htmlspecialchars($editAct['end_time'] ?? '') ?>">
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>Instruktör / Ledare</label>
                    <input type="text" name="act_instructor" value="<?= htmlspecialchars($editAct['instructor_name'] ?? '') ?>" placeholder="Namn">
                </div>
                <div class="form-group">
                    <label>Plats (inom festivalen)</label>
                    <input type="text" name="act_location" value="<?= htmlspecialchars($editAct['location_detail'] ?? '') ?>" placeholder="T.ex. Start/mål-området">
                </div>
            </div>

            <div class="form-group">
                <label>Om instruktören</label>
                <textarea name="act_instructor_info" rows="2"><?= htmlspecialchars($editAct['instructor_info'] ?? '') ?></textarea>
            </div>

            <div class="form-group">
                <label>Beskrivning</label>
                <textarea name="act_description" rows="3" data-format-toolbar><?= htmlspecialchars($editAct['description'] ?? '') ?></textarea>
            </div>

            <div class="form-row-3">
                <div class="form-group">
                    <label>Pris (kr)</label>
                    <input type="number" name="act_price" value="<?= $editAct['price'] ?? '0' ?>" min="0" step="1">
                </div>
                <div class="form-group">
                    <label>Max deltagare</label>
                    <input type="number" name="act_max" value="<?= $editAct['max_participants'] ?? '' ?>" min="1" placeholder="Obegränsat">
                </div>
                <div class="form-group" style="display: flex; align-items: flex-end; padding-bottom: var(--space-xs);">
                    <label style="display: flex; align-items: center; gap: var(--space-xs); cursor: pointer;">
                        <input type="checkbox" name="act_included_in_pass" <?= ($editAct['included_in_pass'] ?? 1) ? 'checked' : '' ?>>
                        Ingår i festivalpass
                    </label>
                </div>
            </div>

            <div style="display: flex; gap: var(--space-sm); margin-top: var(--space-sm);">
                <button type="submit" class="btn-admin btn-admin-primary">
                    <i data-lucide="save"></i> <?= $editAct ? 'Uppdatera' : 'Skapa aktivitet' ?>
                </button>
                <?php if ($editAct): ?>
                <a href="?id=<?= $id ?>&tab=activities" class="btn-admin btn-admin-secondary">Avbryt</a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- ── Tidspass för vald aktivitet ── -->
<?php if ($editAct): ?>
<?php
    $editActSlots = $activitySlots[$editAct['id']] ?? [];
    $editSlot = null;
    $editSlotId = intval($_GET['edit_slot'] ?? 0);
    if ($editSlotId > 0) {
        foreach ($editActSlots as $s) {
            if ($s['id'] == $editSlotId) { $editSlot = $s; break; }
        }
    }
?>
<div class="admin-card" id="slots-section" style="margin-top: var(--space-md);">
    <div class="admin-card-header">
        <h3><i data-lucide="clock" style="width: 18px; height: 18px;"></i> Tidspass för <?= htmlspecialchars($editAct['name']) ?></h3>
    </div>
    <div class="admin-card-body">
        <p style="font-size: 0.85rem; color: var(--color-text-muted); margin-bottom: var(--space-md);">
            Skapa flera tidspass istället för att kopiera aktiviteten. Deltagare väljer ett tidspass vid anmälan.
        </p>

        <!-- Befintliga tidspass -->
        <?php if (!empty($editActSlots)): ?>
        <div style="margin-bottom: var(--space-md);">
            <?php
            $months = ['jan','feb','mar','apr','maj','jun','jul','aug','sep','okt','nov','dec'];
            $weekdays = ['Sön','Mån','Tis','Ons','Tor','Fre','Lör'];
            foreach ($editActSlots as $slot):
                $slotTs = strtotime($slot['date']);
                $slotDateStr = $weekdays[date('w', $slotTs)] . ' ' . date('j', $slotTs) . ' ' . $months[date('n', $slotTs) - 1];
                $slotFull = $slot['max_participants'] && $slot['reg_count'] >= $slot['max_participants'];
            ?>
            <div style="display: flex; align-items: center; justify-content: space-between; padding: var(--space-xs) var(--space-sm); border: 1px solid var(--color-border); border-radius: var(--radius-sm); margin-bottom: var(--space-2xs); <?= $slotFull ? 'opacity: 0.6;' : '' ?>">
                <div style="display: flex; align-items: center; gap: var(--space-sm); font-size: 0.85rem;">
                    <span style="font-weight: 600;"><?= $slotDateStr ?></span>
                    <span style="color: var(--color-accent);"><?= substr($slot['start_time'], 0, 5) ?><?= $slot['end_time'] ? ' – ' . substr($slot['end_time'], 0, 5) : '' ?></span>
                    <span style="color: var(--color-text-muted);">
                        <?= (int)$slot['reg_count'] ?><?= $slot['max_participants'] ? '/' . $slot['max_participants'] : '' ?> anmälda
                    </span>
                    <?php if ($slotFull): ?>
                    <span class="badge badge-warning" style="font-size: 0.65rem;">Fullbokat</span>
                    <?php endif; ?>
                </div>
                <div style="display: flex; gap: var(--space-2xs);">
                    <a href="?id=<?= $id ?>&tab=activities&edit_act=<?= $editAct['id'] ?>&edit_slot=<?= $slot['id'] ?>#slots-section" class="btn-admin btn-admin-secondary" style="padding: 3px 6px;">
                        <i data-lucide="pencil" style="width: 12px; height: 12px;"></i>
                    </a>
                    <form method="post" style="margin:0;" onsubmit="return confirm('Radera detta tidspass?')">
                        <input type="hidden" name="action" value="delete_slot">
                        <input type="hidden" name="slot_id" value="<?= $slot['id'] ?>">
                        <input type="hidden" name="slot_activity_id" value="<?= $editAct['id'] ?>">
                        <button type="submit" class="btn-admin btn-admin-danger" style="padding: 3px 6px;">
                            <i data-lucide="trash-2" style="width: 12px; height: 12px;"></i>
                        </button>
                    </form>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- Formulär: Nytt/redigera tidspass -->
        <form method="post" style="border-top: 1px solid var(--color-border); padding-top: var(--space-md);">
            <input type="hidden" name="action" value="save_slot">
            <input type="hidden" name="slot_activity_id" value="<?= $editAct['id'] ?>">
            <?php if ($editSlot): ?>
            <input type="hidden" name="slot_id" value="<?= $editSlot['id'] ?>">
            <?php endif; ?>

            <div style="font-size: 0.8rem; font-weight: 700; text-transform: uppercase; color: var(--color-text-muted); margin-bottom: var(--space-xs);">
                <?= $editSlot ? 'Redigera tidspass' : 'Lägg till tidspass' ?>
            </div>

            <div class="form-row" style="grid-template-columns: 1fr 1fr 1fr 1fr;">
                <div class="form-group">
                    <label>Datum *</label>
                    <input type="date" name="slot_date" value="<?= htmlspecialchars($editSlot['date'] ?? $editAct['date'] ?? $festival['start_date'] ?? '') ?>" required>
                </div>
                <div class="form-group">
                    <label>Starttid *</label>
                    <input type="time" name="slot_start_time" value="<?= htmlspecialchars($editSlot['start_time'] ?? '') ?>" required>
                </div>
                <div class="form-group">
                    <label>Sluttid</label>
                    <input type="time" name="slot_end_time" value="<?= htmlspecialchars($editSlot['end_time'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label>Max deltagare</label>
                    <input type="number" name="slot_max" value="<?= $editSlot['max_participants'] ?? $editAct['max_participants'] ?? '' ?>" min="1" placeholder="Obegränsat">
                </div>
            </div>

            <div style="display: flex; gap: var(--space-xs);">
                <button type="submit" class="btn-admin btn-admin-primary" style="padding: 6px 14px; font-size: 0.85rem;">
                    <i data-lucide="<?= $editSlot ? 'save' : 'plus' ?>" style="width: 14px; height: 14px;"></i>
                    <?= $editSlot ? 'Uppdatera' : 'Lägg till' ?>
                </button>
                <?php if ($editSlot): ?>
                <a href="?id=<?= $id ?>&tab=activities&edit_act=<?= $editAct['id'] ?>#slots-section" class="btn-admin btn-admin-secondary" style="padding: 6px 14px; font-size: 0.85rem;">Avbryt</a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php endif; ?>

<!-- ============================================================ -->
<!-- TAB: FESTIVALPASS                                             -->
<!-- ============================================================ -->
<?php if (!$isNew && $activeTab === 'pass'): ?>

<?php
    $passCount = 0;
    $passRevenue = 0;
    try {
        $passStats = $pdo->prepare("SELECT COUNT(*) as cnt, COALESCE(SUM(CASE WHEN payment_status='paid' THEN 1 ELSE 0 END), 0) as paid FROM festival_passes WHERE festival_id = ? AND status = 'active'");
        $passStats->execute([$id]);
        $ps = $passStats->fetch(PDO::FETCH_ASSOC);
        $passCount = $ps['paid'] ?? 0;
        if ($festival['pass_price']) {
            $passRevenue = $passCount * $festival['pass_price'];
        }
    } catch (PDOException $e) {}

    $includedCount = 0;
    foreach ($activities as $a) {
        if ($a['included_in_pass']) $includedCount++;
    }
?>

<!-- Pass preview -->
<?php if ($festival['pass_enabled']): ?>
<div class="pass-preview">
    <div class="pass-preview-title"><?= htmlspecialchars($festival['pass_name'] ?: 'Festivalpass') ?></div>
    <div class="pass-preview-price"><?= $festival['pass_price'] ? number_format($festival['pass_price'], 0) . ' kr' : 'Gratis' ?></div>
    <div style="color: var(--color-text-muted); margin-top: var(--space-xs);">
        <?= $includedCount ?> aktiviteter inkluderade · <?= $passCount ?> sålda
        <?php if ($festival['pass_max_quantity']): ?>
         · Max <?= $festival['pass_max_quantity'] ?>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- Stats -->
<div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: var(--space-md); margin-bottom: var(--space-lg);">
    <div class="admin-card" style="padding: var(--space-md); text-align: center;">
        <div style="font-size: 1.75rem; font-weight: 700; color: var(--color-accent);"><?= $passCount ?></div>
        <div style="font-size: 0.8rem; color: var(--color-text-muted);">Sålda pass</div>
    </div>
    <div class="admin-card" style="padding: var(--space-md); text-align: center;">
        <div style="font-size: 1.75rem; font-weight: 700; color: var(--color-success);"><?= number_format($passRevenue, 0) ?> kr</div>
        <div style="font-size: 0.8rem; color: var(--color-text-muted);">Intäkter</div>
    </div>
    <div class="admin-card" style="padding: var(--space-md); text-align: center;">
        <div style="font-size: 1.75rem; font-weight: 700; color: var(--color-text-primary);"><?= $includedCount ?></div>
        <div style="font-size: 0.8rem; color: var(--color-text-muted);">Inkl. aktiviteter</div>
    </div>
</div>

<!-- Pass settings form -->
<form method="post">
    <input type="hidden" name="action" value="save_pass">

    <div class="admin-card">
        <div class="admin-card-header">
            <h3>Passinställningar</h3>
        </div>
        <div class="admin-card-body">
            <div class="form-group" style="margin-bottom: var(--space-md);">
                <label style="display: flex; align-items: center; gap: var(--space-xs); cursor: pointer; font-size: 1rem;">
                    <input type="checkbox" name="pass_enabled" <?= $festival['pass_enabled'] ? 'checked' : '' ?> style="width: 18px; height: 18px;">
                    Aktivera festivalpass
                </label>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>Passnamn</label>
                    <input type="text" name="pass_name" value="<?= htmlspecialchars($festival['pass_name'] ?? 'Festivalpass') ?>" placeholder="Festivalpass">
                </div>
                <div class="form-group">
                    <label>Pris (kr)</label>
                    <input type="number" name="pass_price" value="<?= $festival['pass_price'] ?? '' ?>" min="0" step="1" placeholder="0 = gratis">
                </div>
            </div>

            <div class="form-group">
                <label>Max antal pass</label>
                <input type="number" name="pass_max_quantity" value="<?= $festival['pass_max_quantity'] ?? '' ?>" min="1" placeholder="Obegränsat" style="max-width: 200px;">
            </div>

            <div class="form-group">
                <label>Beskrivning av vad passet innehåller</label>
                <textarea name="pass_description" rows="3" data-format-toolbar><?= htmlspecialchars($festival['pass_description'] ?? '') ?></textarea>
            </div>

            <!-- Events included in pass -->
            <?php
                $passEvents = array_filter($festivalEvents, function($fe) { return !empty($fe['included_in_pass']); });
            ?>
            <?php if (!empty($passEvents)): ?>
            <div style="margin-top: var(--space-md); padding-top: var(--space-md); border-top: 1px solid var(--color-border);">
                <h4 style="margin-bottom: var(--space-sm); color: var(--color-text-secondary);">Tävlingar som ingår i passet:</h4>
                <div style="display: flex; flex-direction: column; gap: var(--space-2xs);">
                    <?php foreach ($passEvents as $pe): ?>
                    <div style="display: flex; align-items: center; gap: var(--space-sm); padding: var(--space-2xs) 0;">
                        <i data-lucide="check-circle" style="width: 16px; height: 16px; color: var(--color-success);"></i>
                        <span style="color: var(--color-text-primary);"><?= htmlspecialchars($pe['name']) ?></span>
                        <span style="font-size: 0.8rem; color: var(--color-text-muted);"><?= date('j M', strtotime($pe['date'])) ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
                <p style="font-size: 0.8rem; color: var(--color-text-muted); margin-top: var(--space-sm);">
                    Ändra under <a href="?id=<?= $id ?>&tab=events">Tävlingsevent</a>-fliken (kryssrutan "I pass").
                </p>
            </div>
            <?php endif; ?>

            <div style="margin-top: var(--space-md); padding-top: var(--space-md); border-top: 1px solid var(--color-border);">
                <h4 style="margin-bottom: var(--space-sm); color: var(--color-text-secondary);">Aktiviteter som ingår i passet:</h4>
                <?php if (empty($activities)): ?>
                <p style="color: var(--color-text-muted);">Inga aktiviteter skapade ännu. Gå till <a href="?id=<?= $id ?>&tab=activities">Aktiviteter</a>-fliken.</p>
                <?php else: ?>
                <div style="display: flex; flex-direction: column; gap: var(--space-2xs);">
                    <?php foreach ($activities as $a):
                        $typeInfo = $activityTypes[$a['activity_type']] ?? $activityTypes['other'];
                    ?>
                    <div style="display: flex; align-items: center; gap: var(--space-sm); padding: var(--space-2xs) 0;">
                        <?php if ($a['included_in_pass']): ?>
                        <i data-lucide="check-circle" style="width: 16px; height: 16px; color: var(--color-success);"></i>
                        <?php else: ?>
                        <i data-lucide="circle" style="width: 16px; height: 16px; color: var(--color-text-muted);"></i>
                        <?php endif; ?>
                        <span style="color: var(--color-text-primary);"><?= htmlspecialchars($a['name']) ?></span>
                        <span class="badge" style="font-size: 0.65rem;"><?= $typeInfo['label'] ?></span>
                        <?php if ($a['price'] > 0): ?>
                        <span style="font-size: 0.8rem; color: var(--color-text-muted);"><?= number_format($a['price'], 0) ?> kr</span>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
                <p style="font-size: 0.8rem; color: var(--color-text-muted); margin-top: var(--space-sm);">
                    Ändra "Ingår i festivalpass" per aktivitet under <a href="?id=<?= $id ?>&tab=activities">Aktiviteter</a>-fliken.
                </p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div style="margin-top: var(--space-md);">
        <button type="submit" class="btn-admin btn-admin-primary">
            <i data-lucide="save"></i> Spara passinställningar
        </button>
    </div>
</form>

<?php endif; ?>

<script>
function previewFestivalMedia(select, previewId) {
    var el = document.getElementById(previewId);
    var opt = select.options[select.selectedIndex];
    var url = opt ? opt.getAttribute('data-url') : null;
    if (url) {
        el.innerHTML = '<img src="' + url + '" alt="" style="max-height: 80px; border-radius: var(--radius-sm); border: 1px solid var(--color-border);">';
    } else {
        el.innerHTML = '';
    }
}
</script>

<?php
// Include format toolbar if available
$formatToolbar = __DIR__ . '/components/format-toolbar.php';
if (file_exists($formatToolbar)) include $formatToolbar;
?>

<?php include __DIR__ . '/components/unified-layout-footer.php'; ?>
