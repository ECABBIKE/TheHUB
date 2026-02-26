<?php
/**
 * Media Library - Admin Page
 * TheHUB V3 - Media & Sponsor System
 *
 * Upload, browse, and manage media files
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/media-functions.php';
require_admin();

global $pdo;

// Check if promotor - they get filtered view
$isPromotorOnly = isRole('promotor');
$promotorEventSlugs = [];
$promotorAllowedFolders = [];

if ($isPromotorOnly && $pdo) {
    // Promotors get full access to all sponsors/ folders
    // They are already scoped to only the sponsors main folder (line ~124)
    // so no further subfolder restriction is needed
    $promotorAllowedFolders[] = 'sponsors';
}

// Get current folder from query
$currentFolder = $_GET['folder'] ?? null;
$searchQuery = $_GET['search'] ?? '';

// Check if we're in a subfolder
$isSubfolder = $currentFolder && strpos($currentFolder, '/') !== false;
$parentFolder = $isSubfolder ? explode('/', $currentFolder)[0] : null;

// Get folder statistics
$folderStats = get_media_stats();
$statsByFolder = [];
$totalFiles = 0;
$totalSize = 0;

foreach ($folderStats as $stat) {
    // For promotors, only count files in their allowed folders
    if ($isPromotorOnly && !empty($promotorAllowedFolders)) {
        $isAllowed = false;
        foreach ($promotorAllowedFolders as $allowed) {
            if (strpos($stat['folder'], $allowed) === 0 || $stat['folder'] === $allowed) {
                $isAllowed = true;
                break;
            }
        }
        if (!$isAllowed) {
            continue;
        }
    }

    // Group subfolders under parent for stats
    $mainFolder = explode('/', $stat['folder'])[0];
    if (!isset($statsByFolder[$mainFolder])) {
        $statsByFolder[$mainFolder] = ['count' => 0, 'total_size' => 0];
    }
    $statsByFolder[$mainFolder]['count'] += (int) $stat['count'];
    $statsByFolder[$mainFolder]['total_size'] += (int) $stat['total_size'];
    $totalFiles += (int) $stat['count'];
    $totalSize += (int) $stat['total_size'];
}

// Get subfolders if viewing sponsors folder
$sponsorSubfolders = [];
if ($currentFolder === 'sponsors' || $parentFolder === 'sponsors') {
    $sponsorSubfolders = get_media_subfolders('sponsors');

    // Filter subfolders for promotors
    if ($isPromotorOnly && !empty($promotorAllowedFolders)) {
        $sponsorSubfolders = array_filter($sponsorSubfolders, function($subfolder) use ($promotorAllowedFolders) {
            foreach ($promotorAllowedFolders as $allowed) {
                // $subfolder is array with 'name', 'path', 'count', 'size'
                // Check if subfolder path matches or is prefix of allowed path
                $subfolderPath = $subfolder['path'] ?? $subfolder['name'] ?? '';
                if ($subfolderPath === $allowed ||
                    strpos($allowed, $subfolderPath . '/') === 0 ||
                    strpos($subfolderPath, $allowed) === 0) {
                    return true;
                }
            }
            return false;
        });
    }
}

// Define folders - promotors only see sponsors
if ($isPromotorOnly) {
    $folders = [
        ['id' => 'sponsors', 'name' => 'Eventmedia', 'icon' => 'handshake']
    ];
    // Force promotors to sponsors folder if no folder selected or outside sponsors
    if (!$currentFolder || strpos($currentFolder, 'sponsors') !== 0) {
        $currentFolder = 'sponsors';
    }
} else {
    $folders = [
        ['id' => 'branding', 'name' => 'Branding', 'icon' => 'palette'],
        ['id' => 'general', 'name' => 'Allmänt', 'icon' => 'folder'],
        ['id' => 'series', 'name' => 'Serier', 'icon' => 'trophy'],
        ['id' => 'sponsors', 'name' => 'Sponsorer', 'icon' => 'handshake'],
        ['id' => 'ads', 'name' => 'Annonser', 'icon' => 'megaphone'],
        ['id' => 'clubs', 'name' => 'Klubbar', 'icon' => 'users'],
        ['id' => 'events', 'name' => 'Event', 'icon' => 'calendar']
    ];
}

// Add counts to folders
foreach ($folders as &$folder) {
    $folder['count'] = $statsByFolder[$folder['id']]['count'] ?? 0;
    $folder['size'] = format_file_size($statsByFolder[$folder['id']]['total_size'] ?? 0);
}
unset($folder);

// Get media files
if ($searchQuery) {
    $mediaFiles = search_media($searchQuery, $currentFolder, 100, true); // include subfolders
} else {
    // Include subfolders when viewing sponsors folder (to see event subfolders)
    $includeSubfolders = ($currentFolder === 'sponsors' || strpos($currentFolder, 'sponsors/') === 0);
    $mediaFiles = get_media_by_folder($currentFolder, 100, 0, $includeSubfolders);
}

// Filter media files for promotors - only show their event folders
if ($isPromotorOnly && !empty($mediaFiles)) {
    if (!empty($promotorAllowedFolders)) {
        $mediaFiles = array_filter($mediaFiles, function($file) use ($promotorAllowedFolders) {
            foreach ($promotorAllowedFolders as $allowed) {
                // Check if file is in an allowed folder or subfolder
                if (strpos($file['folder'], $allowed) === 0 || $file['folder'] === $allowed) {
                    return true;
                }
            }
            return false;
        });
        $mediaFiles = array_values($mediaFiles); // Re-index array
    } else {
        // Promotor has no events assigned - show empty
        $mediaFiles = [];
    }
}

// Page config
$page_title = 'Mediabibliotek';
$breadcrumbs = [
    ['label' => 'Mediabibliotek', 'url' => '/admin/media']
];

if ($currentFolder) {
    if ($isSubfolder) {
        // Add parent folder first
        $parentName = array_column($folders, 'name', 'id')[$parentFolder] ?? ucfirst($parentFolder);
        $breadcrumbs[] = ['label' => $parentName, 'url' => '/admin/media?folder=' . $parentFolder];
        // Add current subfolder
        $subfolderName = ucfirst(basename($currentFolder));
        $breadcrumbs[] = ['label' => $subfolderName];
    } else {
        $folderName = array_column($folders, 'name', 'id')[$currentFolder] ?? ucfirst($currentFolder);
        $breadcrumbs[] = ['label' => $folderName];
    }
}

// Include unified layout
include __DIR__ . '/components/unified-layout.php';
?>

<style>
.media-layout {
    display: grid;
    grid-template-columns: 250px 1fr;
    gap: var(--space-lg);
}

@media (max-width: 768px) {
    .media-layout {
        grid-template-columns: 1fr;
    }
}

/* Sidebar */
.media-sidebar {
    background: var(--color-bg-surface);
    border-radius: var(--radius-md);
    padding: var(--space-md);
    border: 1px solid var(--color-border);
    height: fit-content;
}

.folder-list {
    list-style: none;
    padding: 0;
    margin: 0;
}

.folder-item {
    display: flex;
    align-items: center;
    padding: var(--space-sm) var(--space-md);
    border-radius: var(--radius-sm);
    cursor: pointer;
    transition: background 0.15s;
    margin-bottom: 2px;
    text-decoration: none;
    color: inherit;
}

.folder-item:hover {
    background: var(--color-bg-hover);
}

.folder-item.active {
    background: var(--color-accent);
    color: white;
}

.folder-icon {
    width: 20px;
    height: 20px;
    margin-right: var(--space-sm);
    opacity: 0.7;
}

.folder-item.active .folder-icon {
    opacity: 1;
}

.folder-name {
    flex: 1;
    font-weight: 500;
}

.folder-count {
    font-size: 0.75rem;
    background: var(--color-bg-sunken);
    padding: 2px 6px;
    border-radius: 10px;
    color: var(--color-text-secondary);
}

.folder-item.active .folder-count {
    background: rgba(255,255,255,0.2);
    color: white;
}

/* Upload area */
.upload-zone {
    background: var(--color-bg-surface);
    border: 2px dashed var(--color-border);
    border-radius: var(--radius-md);
    padding: var(--space-xl);
    text-align: center;
    cursor: pointer;
    transition: all 0.2s;
    margin-bottom: var(--space-lg);
}

.upload-zone:hover,
.upload-zone.dragover {
    border-color: var(--color-accent);
    background: var(--color-bg-hover);
}

.upload-zone-icon {
    width: 48px;
    height: 48px;
    margin: 0 auto var(--space-md);
    color: var(--color-text-secondary);
}

.upload-zone-text {
    color: var(--color-text-secondary);
    margin-bottom: var(--space-xs);
}

.upload-zone-hint {
    font-size: 0.75rem;
    color: var(--color-text-secondary);
    opacity: 0.7;
}

/* Media grid */
.media-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
    gap: var(--space-md);
}

.media-item {
    background: var(--color-bg-surface);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-md);
    overflow: hidden;
    cursor: pointer;
    transition: all 0.15s;
    position: relative;
}

.media-item:hover {
    transform: translateY(-2px);
    box-shadow: var(--shadow-lg);
    border-color: var(--color-accent);
}

.media-item.selected {
    border-color: var(--color-accent);
    box-shadow: 0 0 0 2px var(--color-accent);
}

.media-thumbnail {
    aspect-ratio: 1;
    background: var(--color-bg-sunken);
    display: flex;
    align-items: center;
    justify-content: center;
    overflow: hidden;
}

.media-thumbnail img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.media-thumbnail-icon {
    width: 48px;
    height: 48px;
    color: var(--color-text-secondary);
}

.media-info {
    padding: var(--space-sm);
}

.media-filename {
    font-size: 0.75rem;
    font-weight: 500;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.media-meta {
    font-size: 0.65rem;
    color: var(--color-text-secondary);
    margin-top: 2px;
}

.media-checkbox {
    position: absolute;
    top: var(--space-xs);
    left: var(--space-xs);
    width: 20px;
    height: 20px;
    background: var(--color-bg-surface);
    border: 2px solid var(--color-border);
    border-radius: 4px;
    cursor: pointer;
    opacity: 0;
    transition: opacity 0.15s;
}

.media-item:hover .media-checkbox,
.media-item.selected .media-checkbox {
    opacity: 1;
}

.media-item.selected .media-checkbox {
    background: var(--color-accent);
    border-color: var(--color-accent);
}

/* Toolbar */
.media-toolbar {
    display: flex;
    gap: var(--space-md);
    align-items: center;
    margin-bottom: var(--space-lg);
    flex-wrap: wrap;
}

.media-search {
    flex: 1;
    min-width: 200px;
}

.media-search input {
    width: 100%;
    padding: var(--space-sm) var(--space-md);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-sm);
    background: var(--color-bg-surface);
    color: var(--color-text-primary);
}

/* Stats bar */
.media-stats {
    display: flex;
    gap: var(--space-lg);
    padding: var(--space-sm) 0;
    margin-bottom: var(--space-md);
    border-bottom: 1px solid var(--color-border);
    font-size: 0.875rem;
    color: var(--color-text-secondary);
}

/* Modal */
.media-modal {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.8);
    z-index: 1000;
    padding: var(--space-lg);
    overflow-y: auto;
}

.media-modal.active {
    display: flex;
    align-items: center;
    justify-content: center;
}

.media-modal-content {
    background: var(--color-bg-surface);
    border-radius: var(--radius-lg);
    max-width: 900px;
    width: 100%;
    max-height: 90vh;
    overflow-y: auto;
}

.media-modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: var(--space-md) var(--space-lg);
    border-bottom: 1px solid var(--color-border);
}

.media-modal-close {
    background: none;
    border: none;
    padding: var(--space-xs);
    cursor: pointer;
    color: var(--color-text-secondary);
}

.media-modal-body {
    display: grid;
    grid-template-columns: 1fr 300px;
    gap: var(--space-lg);
    padding: var(--space-lg);
}

@media (max-width: 768px) {
    .media-modal {
        padding: 0;
        z-index: 10000;
    }
    .media-modal.active {
        align-items: stretch;
    }
    .media-modal-content {
        max-height: 100vh;
        max-height: 100dvh;
        height: 100%;
        border-radius: 0;
        display: flex;
        flex-direction: column;
    }
    .media-modal-header {
        position: sticky;
        top: 0;
        background: var(--color-bg-surface);
        z-index: 2;
        flex-shrink: 0;
    }
    .media-modal-body {
        grid-template-columns: 1fr;
        overflow-y: auto;
        flex: 1;
        padding: var(--space-md);
        padding-bottom: calc(var(--space-lg) + env(safe-area-inset-bottom, 0px) + 70px);
    }
    .media-preview {
        min-height: 160px;
        max-height: 220px;
    }
    .media-preview img {
        max-height: 200px;
    }
}

.media-preview {
    background: var(--color-bg-sunken);
    border-radius: var(--radius-md);
    padding: var(--space-md);
    display: flex;
    align-items: center;
    justify-content: center;
    min-height: 300px;
}

.media-preview img {
    max-width: 100%;
    max-height: 400px;
    object-fit: contain;
}

.media-details {
    display: flex;
    flex-direction: column;
    gap: var(--space-md);
}

.media-detail-group {
    display: flex;
    flex-direction: column;
    gap: var(--space-xs);
}

.media-detail-label {
    font-size: 0.75rem;
    text-transform: uppercase;
    color: var(--color-text-secondary);
    font-weight: 600;
}

.media-detail-value {
    font-size: 0.875rem;
}

.media-detail-input {
    padding: var(--space-sm);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-sm);
    background: var(--color-bg-sunken);
    color: var(--color-text-primary);
}

/* Empty state */
.media-empty {
    text-align: center;
    padding: var(--space-2xl);
    color: var(--color-text-secondary);
}

.media-empty-icon {
    width: 64px;
    height: 64px;
    margin: 0 auto var(--space-md);
    opacity: 0.5;
}

/* Progress bar for uploads */
.upload-progress {
    display: none;
    margin-top: var(--space-md);
}

.upload-progress.active {
    display: block;
}

.progress-bar {
    height: 4px;
    background: var(--color-border);
    border-radius: 2px;
    overflow: hidden;
}

.progress-fill {
    height: 100%;
    background: var(--color-accent);
    width: 0;
    transition: width 0.3s;
}

.progress-text {
    font-size: 0.75rem;
    color: var(--color-text-secondary);
    margin-top: var(--space-xs);
}
</style>

<div class="media-layout">
    <!-- Sidebar -->
    <aside class="media-sidebar">
        <h3 style="margin: 0 0 var(--space-md); font-size: 0.875rem; text-transform: uppercase; color: var(--color-text-secondary);">Mappar</h3>

        <ul class="folder-list">
            <?php if (!$isPromotorOnly): ?>
            <li>
                <a href="/admin/media" class="folder-item <?= $currentFolder === null ? 'active' : '' ?>">
                    <svg class="folder-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg>
                    <span class="folder-name">Alla filer</span>
                    <span class="folder-count"><?= $totalFiles ?></span>
                </a>
            </li>
            <?php endif; ?>
            <?php foreach ($folders as $folder): ?>
            <li>
                <a href="/admin/media?folder=<?= $folder['id'] ?>" class="folder-item <?= $currentFolder === $folder['id'] || $parentFolder === $folder['id'] ? 'active' : '' ?>">
                    <svg class="folder-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg>
                    <span class="folder-name"><?= htmlspecialchars($folder['name']) ?></span>
                    <span class="folder-count"><?= $folder['count'] ?></span>
                </a>
                <?php if ($folder['id'] === 'sponsors' && !empty($sponsorSubfolders)): ?>
                <!-- Sponsor subfolders -->
                <ul class="subfolder-list" style="margin-left: var(--space-md); margin-top: 2px;">
                    <?php foreach ($sponsorSubfolders as $sub): ?>
                    <li>
                        <a href="/admin/media?folder=<?= urlencode($sub['path']) ?>" class="folder-item <?= $currentFolder === $sub['path'] ? 'active' : '' ?>" style="padding: var(--space-xs) var(--space-sm); font-size: 0.8rem;">
                            <svg class="folder-icon" style="width: 14px; height: 14px;" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg>
                            <span class="folder-name"><?= htmlspecialchars(ucfirst($sub['name'])) ?></span>
                            <span class="folder-count" style="font-size: 0.65rem;"><?= $sub['count'] ?></span>
                        </a>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
            </li>
            <?php endforeach; ?>
        </ul>

        <div style="margin-top: var(--space-lg); padding-top: var(--space-md); border-top: 1px solid var(--color-border); font-size: 0.75rem; color: var(--color-text-secondary);">
            <strong>Totalt:</strong> <?= $totalFiles ?> filer<br>
            <strong>Storlek:</strong> <?= format_file_size($totalSize) ?>
        </div>

        <!-- Bildformat-info knapp -->
        <button type="button" onclick="document.getElementById('imageGuideModal').classList.add('active')" style="margin-top: var(--space-md); width: 100%; display: flex; align-items: center; gap: var(--space-sm); padding: var(--space-sm) var(--space-md); background: var(--color-accent-light); border: 1px solid var(--color-accent); border-radius: var(--radius-sm); cursor: pointer; color: var(--color-accent-text); font-size: 0.8rem; font-weight: 500;">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg>
            Bildformat &amp; storlekar
        </button>
    </aside>

    <!-- Main content -->
    <div class="media-main">
        <!-- Upload zone -->
        <div class="upload-zone" id="uploadZone">
            <svg class="upload-zone-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                <polyline points="17 8 12 3 7 8"/>
                <line x1="12" x2="12" y1="3" y2="15"/>
            </svg>
            <p class="upload-zone-text">Dra och släpp filer här eller klicka för att välja</p>
            <p class="upload-zone-hint">Max 10MB. Format: JPG, PNG, GIF, WebP, SVG, PDF</p>
            <p class="upload-zone-hint" style="margin-top: 4px; font-size: 0.7rem;">
                <strong>Rekommenderade storlekar:</strong> Banner 1200×150px · Logo 600×150px · Ikon 300×75px
            </p>
            <input type="file" id="fileInput" multiple accept=".jpg,.jpeg,.png,.gif,.webp,.svg,.pdf,image/jpeg,image/png,image/gif,image/webp,image/svg+xml,application/pdf" class="hidden">

            <div class="upload-progress" id="uploadProgress">
                <div class="progress-bar">
                    <div class="progress-fill" id="progressFill"></div>
                </div>
                <div class="progress-text" id="progressText">Laddar upp...</div>
            </div>
        </div>

        <?php if ($currentFolder === 'sponsors'): ?>
        <!-- Create sponsor subfolder -->
        <div class="create-subfolder-box" style="background: var(--color-bg-surface); border: 1px solid var(--color-border); border-radius: var(--radius-md); padding: var(--space-md); margin-bottom: var(--space-lg);">
            <h4 style="margin: 0 0 var(--space-sm); font-size: 0.875rem; display: flex; align-items: center; gap: var(--space-sm);">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 10v6"/><path d="m15 13-3-3-3 3"/><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2v11z"/></svg>
                Skapa sponsor-mapp
            </h4>
            <p style="font-size: 0.8rem; color: var(--color-text-secondary); margin-bottom: var(--space-sm);">
                Skapa en undermapp för att organisera alla bilder för en sponsor.
            </p>
            <div style="display: flex; gap: var(--space-sm);">
                <input type="text" id="newSubfolderName" placeholder="Sponsornamn (t.ex. Husqvarna)" style="flex: 1; padding: var(--space-sm); border: 1px solid var(--color-border); border-radius: var(--radius-sm);">
                <button type="button" class="btn btn-primary" onclick="createSponsorSubfolder()">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" x2="12" y1="5" y2="19"/><line x1="5" x2="19" y1="12" y2="12"/></svg>
                    Skapa
                </button>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($isSubfolder): ?>
        <!-- Delete subfolder option -->
        <div style="display: flex; align-items: center; justify-content: space-between; background: var(--color-bg-surface); border: 1px solid var(--color-border); border-radius: var(--radius-md); padding: var(--space-sm) var(--space-md); margin-bottom: var(--space-lg);">
            <div style="display: flex; align-items: center; gap: var(--space-sm); font-size: 0.85rem;">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg>
                <strong><?= htmlspecialchars($currentFolder) ?></strong>
                <span style="color: var(--color-text-muted);">(<?= count($mediaFiles) ?> filer)</span>
            </div>
            <button type="button" class="btn btn-sm btn-danger" onclick="deleteCurrentFolder()" title="Radera denna mapp">
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/></svg>
                Radera mapp
            </button>
        </div>
        <?php endif; ?>

        <!-- Toolbar -->
        <div class="media-toolbar">
            <div class="media-search">
                <form method="get" action="/admin/media" style="display: flex; gap: var(--space-sm);">
                    <?php if ($currentFolder): ?>
                    <input type="hidden" name="folder" value="<?= htmlspecialchars($currentFolder) ?>">
                    <?php endif; ?>
                    <input type="text" name="search" placeholder="Sök filer..." value="<?= htmlspecialchars($searchQuery) ?>">
                    <button type="submit" class="btn btn-sm btn-secondary">Sök</button>
                </form>
            </div>

            <div id="bulkActions" class="hidden">
                <button class="btn btn-sm btn-danger" onclick="deleteSelected()">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/></svg>
                    Radera valda (<span id="selectedCount">0</span>)
                </button>
            </div>
        </div>

        <!-- Stats -->
        <?php if ($searchQuery): ?>
        <div class="media-stats">
            <span>Sökresultat för "<?= htmlspecialchars($searchQuery) ?>": <?= count($mediaFiles) ?> filer</span>
            <a href="/admin/media<?= $currentFolder ? '?folder=' . $currentFolder : '' ?>">Rensa sökning</a>
        </div>
        <?php endif; ?>

        <!-- Media grid -->
        <?php if (empty($mediaFiles)): ?>
        <div class="media-empty">
            <svg class="media-empty-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <rect width="18" height="18" x="3" y="3" rx="2" ry="2"/>
                <circle cx="9" cy="9" r="2"/>
                <path d="m21 15-3.086-3.086a2 2 0 0 0-2.828 0L6 21"/>
            </svg>
            <h3>Inga filer</h3>
            <p><?= $searchQuery ? 'Inga filer matchar din sökning' : 'Ladda upp filer genom att dra och släppa dem ovan' ?></p>
        </div>
        <?php else: ?>
        <div class="media-grid" id="mediaGrid">
            <?php foreach ($mediaFiles as $media): ?>
            <div class="media-item" data-id="<?= $media['id'] ?>" onclick="openMedia(<?= $media['id'] ?>)">
                <input type="checkbox" class="media-checkbox" onclick="event.stopPropagation(); toggleSelect(<?= $media['id'] ?>)">
                <div class="media-thumbnail">
                    <?php if (str_starts_with($media['mime_type'], 'image/')): ?>
                        <img src="<?= htmlspecialchars(get_media_thumbnail($media['id'], 'small')) ?>" alt="<?= htmlspecialchars($media['alt_text'] ?? $media['original_filename']) ?>" loading="lazy">
                    <?php else: ?>
                        <svg class="media-thumbnail-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2z"/>
                            <polyline points="14 2 14 8 20 8"/>
                        </svg>
                    <?php endif; ?>
                </div>
                <div class="media-info">
                    <div class="media-filename" title="<?= htmlspecialchars($media['original_filename']) ?>">
                        <?= htmlspecialchars($media['original_filename']) ?>
                    </div>
                    <div class="media-meta">
                        <?= format_file_size($media['size']) ?>
                        <?php if ($media['width'] && $media['height']): ?>
                            &bull; <?= $media['width'] ?>x<?= $media['height'] ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Media detail modal -->
<div class="media-modal" id="mediaModal">
    <div class="media-modal-content">
        <div class="media-modal-header">
            <h3 id="modalTitle">Mediainfo</h3>
            <button class="media-modal-close" onclick="closeModal()">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" x2="6" y1="6" y2="18"/><line x1="6" x2="18" y1="6" y2="18"/></svg>
            </button>
        </div>
        <div class="media-modal-body">
            <div class="media-preview">
                <img id="modalImage" src="" alt="">
            </div>
            <div class="media-details">
                <div class="media-detail-group">
                    <span class="media-detail-label">Filnamn</span>
                    <span class="media-detail-value" id="detailFilename"></span>
                </div>
                <div class="media-detail-group">
                    <span class="media-detail-label">Storlek</span>
                    <span class="media-detail-value" id="detailSize"></span>
                </div>
                <div class="media-detail-group">
                    <span class="media-detail-label">Dimensioner</span>
                    <span class="media-detail-value" id="detailDimensions"></span>
                </div>
                <div class="media-detail-group">
                    <span class="media-detail-label">Uppladdad</span>
                    <span class="media-detail-value" id="detailUploaded"></span>
                </div>
                <div class="media-detail-group">
                    <span class="media-detail-label">Mapp</span>
                    <select class="media-detail-input" id="detailFolder">
                        <?php foreach ($folders as $folder): ?>
                        <option value="<?= $folder['id'] ?>"><?= htmlspecialchars($folder['name']) ?></option>
                        <?php if ($folder['id'] === 'sponsors' && !empty($sponsorSubfolders)): ?>
                            <?php foreach ($sponsorSubfolders as $sub): ?>
                            <option value="<?= htmlspecialchars($sub['path']) ?>">&nbsp;&nbsp;└ <?= htmlspecialchars(ucfirst($sub['name'])) ?></option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="media-detail-group">
                    <span class="media-detail-label">Alt-text</span>
                    <input type="text" class="media-detail-input" id="detailAltText" placeholder="Beskrivning för tillgänglighet">
                </div>
                <div class="media-detail-group">
                    <span class="media-detail-label">Bildtext</span>
                    <textarea class="media-detail-input" id="detailCaption" rows="2" placeholder="Valfri bildtext"></textarea>
                </div>
                <div class="media-detail-group">
                    <span class="media-detail-label">Länk (webbplats)</span>
                    <input type="url" class="media-detail-input" id="detailLinkUrl" placeholder="https://www.example.com">
                </div>
                <div class="media-detail-group">
                    <span class="media-detail-label">Fil-URL</span>
                    <input type="text" class="media-detail-input" id="detailUrl" readonly onclick="this.select()">
                </div>

                <div id="detailUsage" class="hidden">
                    <span class="media-detail-label">Används i</span>
                    <ul id="usageList" style="font-size: 0.875rem; margin: var(--space-xs) 0; padding-left: var(--space-md);"></ul>
                </div>

                <div style="display: flex; gap: var(--space-sm); margin-top: auto;">
                    <button class="btn btn-primary" onclick="saveMedia()">Spara</button>
                    <button class="btn btn-danger" id="deleteBtn" onclick="deleteMedia()">Radera</button>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
const uploadZone = document.getElementById('uploadZone');
const fileInput = document.getElementById('fileInput');
const uploadProgress = document.getElementById('uploadProgress');
const progressFill = document.getElementById('progressFill');
const progressText = document.getElementById('progressText');
const currentFolder = <?= json_encode($currentFolder ?? 'general') ?>;

let selectedIds = new Set();
let currentMediaId = null;

// Create sponsor subfolder
async function createSponsorSubfolder() {
    const input = document.getElementById('newSubfolderName');
    const name = input.value.trim();

    if (!name) {
        alert('Ange ett namn för mappen');
        return;
    }

    // Generate slug from name
    let slug = name.toLowerCase()
        .replace(/[åä]/g, 'a')
        .replace(/ö/g, 'o')
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-|-$/g, '');

    if (!slug) {
        alert('Ogiltigt namn');
        return;
    }

    const folderPath = 'sponsors/' + slug;

    try {
        const response = await fetch('/api/media.php?action=create_folder', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ folder: folderPath })
        });

        const result = await response.json();

        if (result.success) {
            // Navigate to the new subfolder
            window.location.href = '/admin/media?folder=' + encodeURIComponent(folderPath);
        } else {
            alert('Fel: ' + (result.error || 'Kunde inte skapa mappen'));
        }
    } catch (error) {
        console.error('Create folder error:', error);
        alert('Ett fel uppstod');
    }
}

// Upload zone events
uploadZone.addEventListener('click', () => fileInput.click());
uploadZone.addEventListener('dragover', (e) => {
    e.preventDefault();
    uploadZone.classList.add('dragover');
});
uploadZone.addEventListener('dragleave', () => uploadZone.classList.remove('dragover'));
uploadZone.addEventListener('drop', (e) => {
    e.preventDefault();
    uploadZone.classList.remove('dragover');
    handleFiles(e.dataTransfer.files);
});
fileInput.addEventListener('change', () => handleFiles(fileInput.files));

async function handleFiles(files) {
    if (files.length === 0) return;

    uploadProgress.classList.add('active');
    let completed = 0;
    const total = files.length;

    for (const file of files) {
        const formData = new FormData();
        formData.append('file', file);
        formData.append('folder', currentFolder);

        try {
            progressText.textContent = `Laddar upp ${file.name}... (${completed + 1}/${total})`;

            const response = await fetch('/api/media.php?action=upload', {
                method: 'POST',
                body: formData
            });

            const result = await response.json();
            if (!result.success) {
                console.error('Upload failed:', result.error);
                alert(`Kunde inte ladda upp ${file.name}: ${result.error}`);
            }
        } catch (error) {
            console.error('Upload error:', error);
        }

        completed++;
        progressFill.style.width = (completed / total * 100) + '%';
    }

    progressText.textContent = 'Klar!';
    setTimeout(() => {
        uploadProgress.classList.remove('active');
        progressFill.style.width = '0';
        location.reload();
    }, 1000);
}

// Selection
function toggleSelect(id) {
    const item = document.querySelector(`.media-item[data-id="${id}"]`);
    const checkbox = item.querySelector('.media-checkbox');

    if (selectedIds.has(id)) {
        selectedIds.delete(id);
        item.classList.remove('selected');
        checkbox.checked = false;
    } else {
        selectedIds.add(id);
        item.classList.add('selected');
        checkbox.checked = true;
    }

    updateBulkActions();
}

function updateBulkActions() {
    const bulkActions = document.getElementById('bulkActions');
    const count = document.getElementById('selectedCount');

    if (selectedIds.size > 0) {
        bulkActions.style.display = 'block';
        count.textContent = selectedIds.size;
    } else {
        bulkActions.style.display = 'none';
    }
}

async function deleteSelected() {
    if (!confirm(`Vill du radera ${selectedIds.size} filer? (Kopplingar till sponsorer/event rensas automatiskt)`)) return;

    let errors = [];
    for (const id of selectedIds) {
        try {
            const response = await fetch('/api/media.php?action=delete&id=' + id + '&force=1', { method: 'DELETE' });
            const result = await response.json();
            if (!result.success) errors.push(result.error);
        } catch (error) {
            console.error('Delete error:', error);
            errors.push('Nätverksfel');
        }
    }

    if (errors.length > 0) {
        alert('Vissa filer kunde inte raderas:\n' + errors.join('\n'));
    }
    location.reload();
}

// Modal
let currentMediaFolder = null; // Track original folder for move detection

async function openMedia(id) {
    currentMediaId = id;

    try {
        const response = await fetch(`/api/media.php?action=get&id=${id}`);
        const result = await response.json();

        if (!result.success) {
            alert(result.error);
            return;
        }

        const media = result.data;
        currentMediaFolder = media.folder;

        document.getElementById('modalTitle').textContent = media.original_filename;
        document.getElementById('modalImage').src = media.url;
        document.getElementById('detailFilename').textContent = media.original_filename;
        document.getElementById('detailSize').textContent = formatFileSize(media.size);
        document.getElementById('detailDimensions').textContent = media.width && media.height
            ? `${media.width} x ${media.height} px`
            : 'N/A';
        document.getElementById('detailUploaded').textContent = new Date(media.uploaded_at).toLocaleString('sv-SE');
        document.getElementById('detailFolder').value = media.folder;
        document.getElementById('detailAltText').value = media.alt_text || '';
        document.getElementById('detailCaption').value = media.caption || '';
        document.getElementById('detailLinkUrl').value = media.link_url || '';
        document.getElementById('detailUrl').value = media.url;

        // Show usage
        const usageSection = document.getElementById('detailUsage');
        const usageList = document.getElementById('usageList');
        const deleteBtn = document.getElementById('deleteBtn');

        if (media.usage && media.usage.length > 0) {
            usageSection.style.display = 'block';
            usageList.innerHTML = media.usage.map(u =>
                `<li>${u.entity_type}: ${u.entity_name || u.entity_id} (${u.field})</li>`
            ).join('');
            // Allow delete but mark as "in use" - will need force confirmation
            deleteBtn.disabled = false;
            deleteBtn.dataset.inUse = '1';
            deleteBtn.title = 'Filen används - klicka för att radera ändå';
            deleteBtn.textContent = 'Radera ändå';
        } else {
            usageSection.style.display = 'none';
            deleteBtn.disabled = false;
            deleteBtn.dataset.inUse = '0';
            deleteBtn.title = '';
            deleteBtn.textContent = 'Radera';
        }

        document.getElementById('mediaModal').classList.add('active');
    } catch (error) {
        console.error('Error loading media:', error);
        alert('Kunde inte ladda mediainformation');
    }
}

function closeModal() {
    document.getElementById('mediaModal').classList.remove('active');
    currentMediaId = null;
}

async function saveMedia() {
    if (!currentMediaId) return;

    const newFolder = document.getElementById('detailFolder').value;
    const data = {
        id: currentMediaId,
        folder: newFolder,
        alt_text: document.getElementById('detailAltText').value,
        caption: document.getElementById('detailCaption').value,
        link_url: document.getElementById('detailLinkUrl').value
    };

    const folderChanged = currentMediaFolder && newFolder !== currentMediaFolder;

    try {
        const response = await fetch('/api/media.php?action=update', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(data)
        });

        const result = await response.json();
        if (result.success) {
            if (folderChanged) {
                const sel = document.getElementById('detailFolder');
                const folderName = sel.options[sel.selectedIndex].text.trim();
                alert('Bilden flyttad till ' + folderName);
            } else {
                alert('Sparad!');
            }
            location.reload();
        } else {
            alert(result.error || 'Kunde inte spara');
        }
    } catch (error) {
        console.error('Save error:', error);
        alert('Kunde inte spara');
    }
}

async function deleteMedia() {
    if (!currentMediaId) return;

    const deleteBtn = document.getElementById('deleteBtn');
    const inUse = deleteBtn.dataset.inUse === '1';

    if (inUse) {
        if (!confirm('Denna bild används av sponsorer/event/serier.\n\nOm du raderar den rensas alla kopplingar automatiskt.\n\nVill du radera ändå?')) return;
    } else {
        if (!confirm('Vill du radera denna fil?')) return;
    }

    // Use force=1 if in use (or always, since the backend handles it gracefully)
    const forceParam = inUse ? '&force=1' : '';

    try {
        const response = await fetch(`/api/media.php?action=delete&id=${currentMediaId}${forceParam}`, {
            method: 'DELETE'
        });

        const result = await response.json();
        if (result.success) {
            closeModal();
            location.reload();
        } else if (result.in_use) {
            // Backend returned in_use without force - offer force delete
            if (confirm(result.error + '\n\nVill du radera och rensa alla kopplingar?')) {
                const forceResponse = await fetch(`/api/media.php?action=delete&id=${currentMediaId}&force=1`, {
                    method: 'DELETE'
                });
                const forceResult = await forceResponse.json();
                if (forceResult.success) {
                    closeModal();
                    location.reload();
                } else {
                    alert(forceResult.error || 'Kunde inte radera');
                }
            }
        } else {
            alert(result.error);
        }
    } catch (error) {
        console.error('Delete error:', error);
        alert('Kunde inte radera');
    }
}

async function deleteCurrentFolder() {
    const folder = <?= json_encode($currentFolder ?? '') ?>;
    if (!folder) return;

    const fileCount = <?= count($mediaFiles) ?>;
    if (fileCount > 0) {
        alert('Mappen innehåller ' + fileCount + ' filer. Radera alla filer först innan du kan radera mappen.');
        return;
    }

    if (!confirm('Vill du radera mappen "' + folder + '"?')) return;

    try {
        const response = await fetch('/api/media.php?action=delete_folder&folder=' + encodeURIComponent(folder));
        const result = await response.json();

        if (result.success) {
            // Navigate to parent folder
            const parts = folder.split('/');
            parts.pop();
            const parentFolder = parts.join('/') || parts[0] || '';
            window.location.href = '/admin/media' + (parentFolder ? '?folder=' + encodeURIComponent(parentFolder) : '');
        } else {
            alert(result.error || 'Kunde inte radera mappen');
        }
    } catch (error) {
        console.error('Delete folder error:', error);
        alert('Ett fel uppstod');
    }
}

function formatFileSize(bytes) {
    const units = ['B', 'KB', 'MB', 'GB'];
    let unitIndex = 0;
    let size = bytes;

    while (size >= 1024 && unitIndex < units.length - 1) {
        size /= 1024;
        unitIndex++;
    }

    return size.toFixed(1) + ' ' + units[unitIndex];
}

// Close modal on escape
document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
        closeModal();
        document.getElementById('imageGuideModal').classList.remove('active');
    }
});

// Close modal on backdrop click
document.getElementById('mediaModal').addEventListener('click', (e) => {
    if (e.target === document.getElementById('mediaModal')) closeModal();
});

document.getElementById('imageGuideModal').addEventListener('click', (e) => {
    if (e.target === document.getElementById('imageGuideModal')) {
        e.target.classList.remove('active');
    }
});
</script>

<!-- Bildformat & Storlekar - Informationsmodal -->
<div class="media-modal" id="imageGuideModal">
    <div class="media-modal-content" style="max-width: 750px;">
        <div class="media-modal-header">
            <h3 style="display: flex; align-items: center; gap: var(--space-sm);">
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg>
                Bildformat &amp; storlekar
            </h3>
            <button class="media-modal-close" onclick="document.getElementById('imageGuideModal').classList.remove('active')">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" x2="6" y1="6" y2="18"/><line x1="6" x2="18" y1="6" y2="18"/></svg>
            </button>
        </div>
        <div style="padding: var(--space-lg); overflow-y: auto; max-height: calc(90vh - 60px);">

            <!-- Sektion: Bildtyper -->
            <div style="margin-bottom: var(--space-xl);">
                <h4 style="margin: 0 0 var(--space-md); font-size: 0.95rem; color: var(--color-accent-text); display: flex; align-items: center; gap: var(--space-sm);">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect width="18" height="18" x="3" y="3" rx="2" ry="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-3.086-3.086a2 2 0 0 0-2.828 0L6 21"/></svg>
                    Bildtyper och rekommenderade storlekar
                </h4>

                <div style="display: grid; gap: var(--space-md);">
                    <!-- Banner -->
                    <div style="background: var(--color-bg-sunken); border-radius: var(--radius-sm); padding: var(--space-md); border-left: 3px solid var(--color-accent);">
                        <div style="display: flex; justify-content: space-between; align-items: baseline; flex-wrap: wrap; gap: var(--space-xs);">
                            <strong style="font-size: 0.9rem;">Banner</strong>
                            <code style="background: var(--color-bg-card); padding: 2px 8px; border-radius: 4px; font-size: 0.75rem; color: var(--color-accent-text);">1200 x 150 px</code>
                        </div>
                        <p style="margin: var(--space-xs) 0 0; font-size: 0.8rem; color: var(--color-text-secondary);">
                            Stor sponsorbanner som visas i full bredd h&ouml;gst upp p&aring; eventsidan. Proportioner 8:1.
                        </p>
                        <div style="margin-top: var(--space-xs); font-size: 0.75rem; color: var(--color-text-muted);">
                            <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align: middle;"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
                            Eventsida &ndash; toppbanner
                        </div>
                    </div>

                    <!-- Logo (standard) -->
                    <div style="background: var(--color-bg-sunken); border-radius: var(--radius-sm); padding: var(--space-md); border-left: 3px solid var(--color-success);">
                        <div style="display: flex; justify-content: space-between; align-items: baseline; flex-wrap: wrap; gap: var(--space-xs);">
                            <strong style="font-size: 0.9rem;">Logo (standard)</strong>
                            <code style="background: var(--color-bg-card); padding: 2px 8px; border-radius: 4px; font-size: 0.75rem; color: var(--color-success);">600 x 150 px</code>
                        </div>
                        <p style="margin: var(--space-xs) 0 0; font-size: 0.8rem; color: var(--color-text-secondary);">
                            Prim&auml;r logotyp f&ouml;r de flesta placeringar. Proportioner 4:1. Skalas automatiskt ned till mindre storlekar.
                        </p>
                        <div style="margin-top: var(--space-xs); font-size: 0.75rem; color: var(--color-text-muted);">
                            <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align: middle;"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
                            Eventsida (logorader, partner-sektion) &bull; Header &bull; Footer &bull; Resultatheader
                        </div>
                        <div style="margin-top: var(--space-2xs); font-size: 0.7rem; color: var(--color-text-muted);">
                            Auto-skalas till: 300&times;75, 240&times;60, 160&times;40 px
                        </div>
                    </div>

                    <!-- Resultat-logo (sidebar) -->
                    <div style="background: var(--color-bg-sunken); border-radius: var(--radius-sm); padding: var(--space-md); border-left: 3px solid var(--color-warning);">
                        <div style="display: flex; justify-content: space-between; align-items: baseline; flex-wrap: wrap; gap: var(--space-xs);">
                            <strong style="font-size: 0.9rem;">Resultatheader-logo</strong>
                            <code style="background: var(--color-bg-card); padding: 2px 8px; border-radius: 4px; font-size: 0.75rem; color: var(--color-warning);">h&ouml;jd 40 px</code>
                        </div>
                        <p style="margin: var(--space-xs) 0 0; font-size: 0.8rem; color: var(--color-text-secondary);">
                            Visas bredvid klassrubriken i resultat. Anv&auml;nder standard-logon (600&times;150) men renderas p&aring; 40px h&ouml;jd. Transparent bakgrund rekommenderas.
                        </p>
                        <div style="margin-top: var(--space-xs); font-size: 0.75rem; color: var(--color-text-muted);">
                            <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align: middle;"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
                            Eventsida &ndash; &rdquo;Resultaten presenteras av&rdquo;
                        </div>
                    </div>
                </div>
            </div>

            <!-- Sektion: Generella riktlinjer -->
            <div style="margin-bottom: var(--space-xl);">
                <h4 style="margin: 0 0 var(--space-md); font-size: 0.95rem; color: var(--color-accent-text); display: flex; align-items: center; gap: var(--space-sm);">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22c5.523 0 10-4.477 10-10S17.523 2 12 2 2 6.477 2 12s4.477 10 10 10z"/><path d="m9 12 2 2 4-4"/></svg>
                    Generella riktlinjer
                </h4>
                <div style="background: var(--color-bg-sunken); border-radius: var(--radius-sm); padding: var(--space-md); font-size: 0.8rem; color: var(--color-text-secondary);">
                    <ul style="margin: 0; padding-left: var(--space-md); display: flex; flex-direction: column; gap: var(--space-xs);">
                        <li><strong>Format:</strong> PNG eller SVG med transparent bakgrund (b&auml;st). JPG och WebP fungerar ocks&aring;.</li>
                        <li><strong>Maxstorlek:</strong> 10 MB per fil.</li>
                        <li><strong>Transparent bakgrund:</strong> Starkt rekommenderat f&ouml;r logotyper &ndash; de visas p&aring; b&aring;de ljust och m&ouml;rkt tema.</li>
                        <li><strong>H&aring;ll det enkelt:</strong> Logotyper med h&ouml;g kontrast och tydlig text fungerar b&auml;st i sm&aring; storlekar.</li>
                        <li><strong>Proportioner:</strong> Bevara originalproportionerna. Systemet skalas aldrig sn&ouml;vrigt &ndash; bilder klipps med <code>object-fit: contain</code>.</li>
                    </ul>
                </div>
            </div>

            <!-- Sektion: Mappstruktur -->
            <div style="margin-bottom: var(--space-xl);">
                <h4 style="margin: 0 0 var(--space-md); font-size: 0.95rem; color: var(--color-accent-text); display: flex; align-items: center; gap: var(--space-sm);">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg>
                    Mappstruktur
                </h4>
                <div style="display: grid; gap: var(--space-sm);">

                    <?php if (!$isPromotorOnly): ?>
                    <div style="background: var(--color-bg-sunken); border-radius: var(--radius-sm); padding: var(--space-md);">
                        <div style="display: flex; align-items: center; gap: var(--space-sm); margin-bottom: var(--space-xs);">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2 2 7l10 5 10-5-10-5Z"/><path d="m2 17 10 5 10-5"/><path d="m2 12 10 5 10-5"/></svg>
                            <strong style="font-size: 0.85rem;">Branding</strong>
                        </div>
                        <p style="margin: 0; font-size: 0.75rem; color: var(--color-text-secondary);">
                            GravitySeries egna logotyper, profilbilder och varumärkesmaterial.
                        </p>
                    </div>

                    <div style="background: var(--color-bg-sunken); border-radius: var(--radius-sm); padding: var(--space-md);">
                        <div style="display: flex; align-items: center; gap: var(--space-sm); margin-bottom: var(--space-xs);">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg>
                            <strong style="font-size: 0.85rem;">Allm&auml;nt</strong>
                        </div>
                        <p style="margin: 0; font-size: 0.75rem; color: var(--color-text-secondary);">
                            &Ouml;vriga bilder som inte h&ouml;r till n&aring;gon specifik kategori.
                        </p>
                    </div>

                    <div style="background: var(--color-bg-sunken); border-radius: var(--radius-sm); padding: var(--space-md);">
                        <div style="display: flex; align-items: center; gap: var(--space-sm); margin-bottom: var(--space-xs);">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 9H4.5a2.5 2.5 0 0 1 0-5H6"/><path d="M18 9h1.5a2.5 2.5 0 0 0 0-5H18"/><path d="M4 22h16"/><path d="M10 14.66V17c0 .55-.47.98-.97 1.21C7.85 18.75 7 20.24 7 22"/><path d="M14 14.66V17c0 .55.47.98.97 1.21C16.15 18.75 17 20.24 17 22"/><path d="M18 2H6v7a6 6 0 0 0 12 0V2Z"/></svg>
                            <strong style="font-size: 0.85rem;">Serier</strong>
                        </div>
                        <p style="margin: 0; font-size: 0.75rem; color: var(--color-text-secondary);">
                            Serielogotyper och seriespecifikt bildmaterial. Visas p&aring; seriesidor.
                        </p>
                    </div>
                    <?php endif; ?>

                    <div style="background: var(--color-bg-sunken); border-radius: var(--radius-sm); padding: var(--space-md); border: 1px solid var(--color-accent-light);">
                        <div style="display: flex; align-items: center; gap: var(--space-sm); margin-bottom: var(--space-xs);">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20.42 4.58a5.4 5.4 0 0 0-7.65 0l-.77.78-.77-.78a5.4 5.4 0 0 0-7.65 0C1.46 6.7 1.33 10.28 4 13l8 8 8-8c2.67-2.72 2.54-6.3.42-8.42z"/></svg>
                            <strong style="font-size: 0.85rem;">Sponsorer</strong>
                            <?php if ($isPromotorOnly): ?>
                            <span style="font-size: 0.65rem; background: var(--color-accent); color: white; padding: 1px 6px; border-radius: 8px;">Din mapp</span>
                            <?php endif; ?>
                        </div>
                        <p style="margin: 0; font-size: 0.75rem; color: var(--color-text-secondary);">
                            Sponsorlogotyper och banners. Organiseras i undermappar per sponsor eller serie.
                            <?php if ($isPromotorOnly): ?>
                            <br>Du laddar upp till din series mapp och v&auml;ljer bilder h&auml;rifr&aring;n n&auml;r du skapar/redigerar sponsorer.
                            <?php else: ?>
                            <br>Inneh&aring;ller &auml;ven bilder som promotorer laddat upp till sina serier.
                            <?php endif; ?>
                        </p>
                    </div>

                    <?php if (!$isPromotorOnly): ?>
                    <div style="background: var(--color-bg-sunken); border-radius: var(--radius-sm); padding: var(--space-md);">
                        <div style="display: flex; align-items: center; gap: var(--space-sm); margin-bottom: var(--space-xs);">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg>
                            <strong style="font-size: 0.85rem;">Annonser</strong>
                        </div>
                        <p style="margin: 0; font-size: 0.75rem; color: var(--color-text-secondary);">
                            Annonsbilder och kampanjmaterial.
                        </p>
                    </div>

                    <div style="background: var(--color-bg-sunken); border-radius: var(--radius-sm); padding: var(--space-md);">
                        <div style="display: flex; align-items: center; gap: var(--space-sm); margin-bottom: var(--space-xs);">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                            <strong style="font-size: 0.85rem;">Klubbar</strong>
                        </div>
                        <p style="margin: 0; font-size: 0.75rem; color: var(--color-text-secondary);">
                            Klubblogotyper och klubbrelaterade bilder. Visas p&aring; klubbsidor.
                        </p>
                    </div>

                    <div style="background: var(--color-bg-sunken); border-radius: var(--radius-sm); padding: var(--space-md);">
                        <div style="display: flex; align-items: center; gap: var(--space-sm); margin-bottom: var(--space-xs);">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                            <strong style="font-size: 0.85rem;">Event</strong>
                        </div>
                        <p style="margin: 0; font-size: 0.75rem; color: var(--color-text-secondary);">
                            Eventspecifika bilder, banderoller och eventrelaterat material.
                        </p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Sektion: Var visas bilderna? -->
            <div style="margin-bottom: var(--space-lg);">
                <h4 style="margin: 0 0 var(--space-md); font-size: 0.95rem; color: var(--color-accent-text); display: flex; align-items: center; gap: var(--space-sm);">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/></svg>
                    Var visas bilderna p&aring; sajten?
                </h4>
                <div style="display: grid; gap: 1px; background: var(--color-border); border-radius: var(--radius-sm); overflow: hidden;">
                    <div style="background: var(--color-bg-sunken); padding: var(--space-sm) var(--space-md); display: grid; grid-template-columns: 1fr 1fr 1fr; gap: var(--space-sm); font-size: 0.7rem; font-weight: 600; text-transform: uppercase; color: var(--color-text-muted);">
                        <span>Placering</span>
                        <span>Bildtyp</span>
                        <span>Renderad storlek</span>
                    </div>

                    <div style="background: var(--color-bg-card); padding: var(--space-sm) var(--space-md); display: grid; grid-template-columns: 1fr 1fr 1fr; gap: var(--space-sm); font-size: 0.8rem;">
                        <span>Eventsida &ndash; toppbanner</span>
                        <span style="color: var(--color-accent-text);">Banner</span>
                        <span style="color: var(--color-text-secondary);">Full bredd, max 150px h&ouml;jd</span>
                    </div>

                    <div style="background: var(--color-bg-card); padding: var(--space-sm) var(--space-md); display: grid; grid-template-columns: 1fr 1fr 1fr; gap: var(--space-sm); font-size: 0.8rem;">
                        <span>Eventsida &ndash; logorader</span>
                        <span style="color: var(--color-success);">Logo</span>
                        <span style="color: var(--color-text-secondary);">Max 180&times;50px (desktop), 120&times;65px (mobil)</span>
                    </div>

                    <div style="background: var(--color-bg-card); padding: var(--space-sm) var(--space-md); display: grid; grid-template-columns: 1fr 1fr 1fr; gap: var(--space-sm); font-size: 0.8rem;">
                        <span>Eventsida &ndash; partner-sektion</span>
                        <span style="color: var(--color-success);">Logo</span>
                        <span style="color: var(--color-text-secondary);">Max 280&times;100px (desktop), 160&times;70px (mobil)</span>
                    </div>

                    <div style="background: var(--color-bg-card); padding: var(--space-sm) var(--space-md); display: grid; grid-template-columns: 1fr 1fr 1fr; gap: var(--space-sm); font-size: 0.8rem;">
                        <span>Resultat &ndash; klassrubrik</span>
                        <span style="color: var(--color-warning);">Logo (sidebar)</span>
                        <span style="color: var(--color-text-secondary);">40px h&ouml;jd, auto bredd</span>
                    </div>

                    <div style="background: var(--color-bg-card); padding: var(--space-sm) var(--space-md); display: grid; grid-template-columns: 1fr 1fr 1fr; gap: var(--space-sm); font-size: 0.8rem;">
                        <span>Header (global)</span>
                        <span style="color: var(--color-success);">Logo</span>
                        <span style="color: var(--color-text-secondary);">Max 120px bredd, 28&ndash;36px h&ouml;jd</span>
                    </div>

                    <div style="background: var(--color-bg-card); padding: var(--space-sm) var(--space-md); display: grid; grid-template-columns: 1fr 1fr 1fr; gap: var(--space-sm); font-size: 0.8rem;">
                        <span>Footer (global)</span>
                        <span style="color: var(--color-success);">Logo</span>
                        <span style="color: var(--color-text-secondary);">Kompakt, 6 per rad</span>
                    </div>
                </div>
            </div>

            <!-- Promotor-specifik info -->
            <?php if ($isPromotorOnly): ?>
            <div style="background: var(--color-accent-light); border: 1px solid var(--color-accent); border-radius: var(--radius-sm); padding: var(--space-md);">
                <h4 style="margin: 0 0 var(--space-sm); font-size: 0.85rem; color: var(--color-accent-text); display: flex; align-items: center; gap: var(--space-sm);">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                    Tips f&ouml;r promotorer
                </h4>
                <ul style="margin: 0; padding-left: var(--space-md); font-size: 0.8rem; color: var(--color-text-secondary); display: flex; flex-direction: column; gap: var(--space-xs);">
                    <li>Dina uppladdade bilder hamnar i din series mapp under <strong>Sponsorer</strong>.</li>
                    <li>N&auml;r du skapar en sponsor kan du v&auml;lja bilder fr&aring;n mediabiblioteket via &rdquo;V&auml;lj fr&aring;n media&rdquo;.</li>
                    <li>Admin kan &auml;ven se och anv&auml;nda dina uppladdade bilder.</li>
                    <li>Ladda upp b&aring;de en <strong>banner</strong> (1200&times;150) och en <strong>logo</strong> (600&times;150) f&ouml;r b&auml;st resultat.</li>
                </ul>
            </div>
            <?php else: ?>
            <div style="background: var(--color-accent-light); border: 1px solid var(--color-accent); border-radius: var(--radius-sm); padding: var(--space-md);">
                <h4 style="margin: 0 0 var(--space-sm); font-size: 0.85rem; color: var(--color-accent-text); display: flex; align-items: center; gap: var(--space-sm);">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                    Admin-info
                </h4>
                <ul style="margin: 0; padding-left: var(--space-md); font-size: 0.8rem; color: var(--color-text-secondary); display: flex; flex-direction: column; gap: var(--space-xs);">
                    <li>Du ser alla mappar inklusive bilder som promotorer laddat upp.</li>
                    <li>Promotorers bilder hamnar i undermappar under <strong>Sponsorer</strong> (organiserat per serie).</li>
                    <li>N&auml;r du v&auml;ljer logotyp f&ouml;r en sponsor kan du anv&auml;nda b&aring;de admin-uppladdade och promotor-uppladdade bilder.</li>
                    <li>Globala placeringar (header, footer) konfigureras i <a href="/admin/sponsor-placements.php" style="color: var(--color-accent-text);">Sponsorplaceringar</a>.</li>
                </ul>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include __DIR__ . '/components/unified-layout-footer.php'; ?>
