<?php
/**
 * Promotor Guide - Visar arrangörsinstruktionen
 * Tillgänglig för alla inloggade admin-användare (promotor, admin, super_admin)
 */

require_once __DIR__ . '/../config.php';
require_admin();

// Require at least promotor role
if (!hasRole('promotor')) {
    set_flash('error', 'Du har inte behörighet till denna sida');
    redirect('/');
}

$page_title = 'Arrangörsguide';
include __DIR__ . '/components/unified-layout.php';

// Read the markdown file
$mdFile = __DIR__ . '/../docs/promotor-instruktion.md';
$mdContent = file_exists($mdFile) ? file_get_contents($mdFile) : 'Guiden kunde inte hittas.';

// Simple markdown to HTML conversion (no external dependencies)
function md_to_html($md) {
    $lines = explode("\n", $md);
    $html = '';
    $inTable = false;
    $inList = false;
    $inBlockquote = false;
    $inCode = false;
    $tableHeaderDone = false;

    foreach ($lines as $line) {
        $trimmed = trim($line);

        // Skip front matter lines (> **Version:** etc at the very top)
        // Code blocks
        if (preg_match('/^```/', $trimmed)) {
            if ($inCode) {
                $html .= '</code></pre>';
                $inCode = false;
            } else {
                if ($inList) { $html .= '</ul>'; $inList = false; }
                $html .= '<pre><code>';
                $inCode = true;
            }
            continue;
        }
        if ($inCode) {
            $html .= htmlspecialchars($line) . "\n";
            continue;
        }

        // Close open lists/blockquotes if line doesn't continue them
        if ($inBlockquote && !preg_match('/^>/', $trimmed)) {
            $html .= '</blockquote>';
            $inBlockquote = false;
        }

        // Table end
        if ($inTable && !preg_match('/^\|/', $trimmed)) {
            $html .= '</tbody></table></div>';
            $inTable = false;
            $tableHeaderDone = false;
        }

        // Empty line
        if ($trimmed === '') {
            if ($inList) { $html .= '</ul>'; $inList = false; }
            continue;
        }

        // Horizontal rule
        if (preg_match('/^---+$/', $trimmed)) {
            if ($inList) { $html .= '</ul>'; $inList = false; }
            $html .= '<hr>';
            continue;
        }

        // Headers
        if (preg_match('/^(#{1,6})\s+(.+)$/', $trimmed, $m)) {
            if ($inList) { $html .= '</ul>'; $inList = false; }
            $level = strlen($m[1]);
            $text = inline_md($m[2]);
            // Generate anchor from text
            $anchor = preg_replace('/[^a-zåäö0-9\-\s]/i', '', strtolower($m[2]));
            $anchor = preg_replace('/\s+/', '-', trim($anchor));
            $html .= '<h' . $level . ' id="' . htmlspecialchars($anchor) . '">' . $text . '</h' . $level . '>';
            continue;
        }

        // Blockquote
        if (preg_match('/^>\s*(.*)$/', $trimmed, $m)) {
            if (!$inBlockquote) {
                if ($inList) { $html .= '</ul>'; $inList = false; }
                $html .= '<blockquote>';
                $inBlockquote = true;
            }
            $html .= '<p>' . inline_md($m[1]) . '</p>';
            continue;
        }

        // Table
        if (preg_match('/^\|/', $trimmed)) {
            // Skip separator rows
            if (preg_match('/^\|[\s\-:|]+\|$/', $trimmed)) {
                continue;
            }

            if (!$inTable) {
                if ($inList) { $html .= '</ul>'; $inList = false; }
                $html .= '<div class="table-responsive"><table class="table">';
                $inTable = true;
                $tableHeaderDone = false;
            }

            $cells = array_map('trim', explode('|', $trimmed));
            $cells = array_filter($cells, fn($c) => $c !== '');

            if (!$tableHeaderDone) {
                $html .= '<thead><tr>';
                foreach ($cells as $cell) {
                    $html .= '<th>' . inline_md($cell) . '</th>';
                }
                $html .= '</tr></thead><tbody>';
                $tableHeaderDone = true;
            } else {
                $html .= '<tr>';
                foreach ($cells as $cell) {
                    $html .= '<td>' . inline_md($cell) . '</td>';
                }
                $html .= '</tr>';
            }
            continue;
        }

        // Unordered list
        if (preg_match('/^[-*]\s+(.+)$/', $trimmed, $m)) {
            if (!$inList) {
                $html .= '<ul>';
                $inList = true;
            }
            $html .= '<li>' . inline_md($m[1]) . '</li>';
            continue;
        }

        // Ordered list
        if (preg_match('/^\d+\.\s+(.+)$/', $trimmed, $m)) {
            if (!$inList) {
                $html .= '<ul>';
                $inList = true;
            }
            $html .= '<li>' . inline_md($m[1]) . '</li>';
            continue;
        }

        // Paragraph
        if ($inList) { $html .= '</ul>'; $inList = false; }
        $html .= '<p>' . inline_md($trimmed) . '</p>';
    }

    // Close any open elements
    if ($inList) $html .= '</ul>';
    if ($inBlockquote) $html .= '</blockquote>';
    if ($inTable) $html .= '</tbody></table></div>';
    if ($inCode) $html .= '</code></pre>';

    return $html;
}

function inline_md($text) {
    $text = htmlspecialchars($text);
    // Bold
    $text = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $text);
    // Italic
    $text = preg_replace('/\*(.+?)\*/', '<em>$1</em>', $text);
    // Inline code
    $text = preg_replace('/`(.+?)`/', '<code>$1</code>', $text);
    // Links [text](url)
    $text = preg_replace('/\[(.+?)\]\((.+?)\)/', '<a href="$2">$1</a>', $text);
    return $text;
}

$htmlContent = md_to_html($mdContent);
?>

<style>
.guide-container {
    max-width: 800px;
    margin: 0 auto;
}
.guide-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--space-md);
    margin-bottom: var(--space-lg);
}
.guide-header h1 {
    display: flex;
    align-items: center;
    gap: var(--space-sm);
    margin: 0;
}
.guide-header h1 i { width: 28px; height: 28px; color: var(--color-accent); }
.guide-back {
    display: inline-flex;
    align-items: center;
    gap: var(--space-2xs);
    color: var(--color-accent);
    text-decoration: none;
    font-size: var(--text-sm);
    white-space: nowrap;
}
.guide-back:hover { text-decoration: underline; }
.guide-back i { width: 16px; height: 16px; }

/* Content styling */
.guide-content h1 { font-size: var(--text-2xl); margin: var(--space-2xl) 0 var(--space-md); color: var(--color-text-primary); border-bottom: 2px solid var(--color-border); padding-bottom: var(--space-sm); }
.guide-content h2 { font-size: var(--text-xl); margin: var(--space-xl) 0 var(--space-md); color: var(--color-text-primary); border-bottom: 1px solid var(--color-border); padding-bottom: var(--space-xs); }
.guide-content h3 { font-size: var(--text-lg); margin: var(--space-lg) 0 var(--space-sm); color: var(--color-text-primary); }
.guide-content h4 { font-size: var(--text-md); margin: var(--space-md) 0 var(--space-xs); color: var(--color-text-primary); }
.guide-content p { margin: var(--space-sm) 0; line-height: 1.7; color: var(--color-text-secondary); }
.guide-content ul { margin: var(--space-sm) 0; padding-left: var(--space-lg); }
.guide-content li { margin: var(--space-2xs) 0; line-height: 1.6; color: var(--color-text-secondary); }
.guide-content strong { color: var(--color-text-primary); }
.guide-content hr { border: none; border-top: 1px solid var(--color-border); margin: var(--space-xl) 0; }
.guide-content blockquote {
    border-left: 3px solid var(--color-accent);
    padding: var(--space-sm) var(--space-md);
    margin: var(--space-md) 0;
    background: var(--color-accent-light);
    border-radius: 0 var(--radius-sm) var(--radius-sm) 0;
}
.guide-content blockquote p { margin: var(--space-2xs) 0; color: var(--color-text-primary); font-size: var(--text-sm); }
.guide-content code {
    background: var(--color-bg-hover);
    padding: 2px 6px;
    border-radius: var(--radius-sm);
    font-size: 0.875em;
    color: var(--color-accent-text);
}
.guide-content pre {
    background: var(--color-bg-surface);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-sm);
    padding: var(--space-md);
    overflow-x: auto;
}
.guide-content pre code { background: none; padding: 0; }
.guide-content .table-responsive { margin: var(--space-md) 0; }
.guide-content .table th {
    background: var(--color-bg-hover);
    font-weight: 600;
    text-align: left;
    white-space: nowrap;
}
.guide-content .table td, .guide-content .table th { padding: var(--space-sm) var(--space-md); }
.guide-content a { color: var(--color-accent); text-decoration: none; }
.guide-content a:hover { text-decoration: underline; }

/* TOC - first h2 "Innehåll" */
.guide-content > h2:first-of-type + ul { background: var(--color-bg-surface); border: 1px solid var(--color-border); border-radius: var(--radius-md); padding: var(--space-md) var(--space-md) var(--space-md) var(--space-xl); }
.guide-content > h2:first-of-type + ul li a { color: var(--color-accent); }

/* Mobile */
@media (max-width: 767px) {
    .guide-container { padding: 0; }
    .guide-header { flex-direction: column; align-items: flex-start; }
    .guide-content .table-responsive { margin-left: -16px; margin-right: -16px; border-radius: 0; }
}
</style>

<div class="guide-container">
    <div class="guide-header">
        <h1><i data-lucide="book-open"></i> Arrangörsguide</h1>
        <a href="/admin/promotor.php" class="guide-back">
            <i data-lucide="arrow-left"></i> Tillbaka
        </a>
    </div>

    <div class="guide-content">
        <?= $htmlContent ?>
    </div>
</div>

<?php include __DIR__ . '/components/unified-layout-footer.php'; ?>
