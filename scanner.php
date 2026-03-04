<?php
/**
 * ARCHITECTURE BLUEPRINT SCANNER
 * Detta script skannar din struktur för att ge en AI en överblick av mönster.
 * Det läser INTE bilder, tunga bibliotek eller känslig data i .env.
 */

header('Content-Type: text/plain; charset=utf-8');

$root = __DIR__;
$ignoreDirs = ['node_modules', 'vendor', '.git', 'uploads', 'cache', 'images', 'assets'];
$importantFiles = ['index.php', '.htaccess', 'wp-config.php', 'functions.php', 'router.php'];
$extensionsToRead = ['php', 'sql', 'json'];

echo "=== SYSTEM BLUEPRINT START ===\n";
echo "Root Directory: " . $root . "\n\n";

// 1. GENERERA MAPPTRAID (FILSTRUKTUR)
echo "--- FILSTRUKTUR ---\n";
$iter = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
);
$iter->setMaxDepth(3); // Vi kollar 3 nivåer djupt för att se mönstret

foreach ($iter as $path) {
    $relativePath = str_replace($root, '', $path);
    $skip = false;
    foreach ($ignoreDirs as $ignore) {
        if (strpos($relativePath, DIRECTORY_SEPARATOR . $ignore) !== false) {
            $skip = true;
            break;
        }
    }
    if (!$skip) {
        echo str_repeat("  ", $iter->getDepth()) . ($path->isDir() ? "[D] " : "[F] ") . basename($path) . "\n";
    }
}

echo "\n--- KÄRNLOGIK (FILINNEHÅLL) ---\n";

// 2. LÄS VIKTIGA FILER
foreach ($iter as $path) {
    if ($path->isDir()) continue;
    
    $filename = basename($path);
    $ext = pathinfo($filename, PATHINFO_EXTENSION);
    $relativePath = str_replace($root, '', $path);

    // Vi läser bara filer som sannolikt innehåller arkitekturmönster
    $isCoreFile = in_array($filename, $importantFiles);
    $isSmallLogic = (in_array($ext, $extensionsToRead) && $path->getSize() < 50000); // Max 50KB

    if ($isCoreFile || $isSmallLogic) {
        // Hoppa över bibliotek/tredjepart
        foreach ($ignoreDirs as $ignore) {
            if (strpos($relativePath, DIRECTORY_SEPARATOR . $ignore) !== false) continue 2;
        }

        echo "\nFILE: " . $relativePath . "\n";
        echo "------------------------------------------\n";
        $content = file_get_contents($path);
        // Ta bort kommentarer för att spara plats om det behövs, 
        // men här behåller vi dem för att se logiken.
        echo substr($content, 0, 5000); // Vi tar de första 5000 tecknen
        echo "\n[...]\n";
    }
}

echo "\n=== SYSTEM BLUEPRINT END ===\n";

