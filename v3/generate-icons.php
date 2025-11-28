<?php
/**
 * Placeholder icon generator for TheHUB PWA
 * Run this once to create basic icons, then replace with real branding
 *
 * Usage: php generate-icons.php
 */

$sizes = [16, 32, 72, 96, 128, 144, 152, 167, 180, 192, 384, 512];
$iconDir = __DIR__ . '/assets/icons';

if (!is_dir($iconDir)) {
    mkdir($iconDir, 0755, true);
    echo "Created directory: $iconDir\n";
}

foreach ($sizes as $size) {
    $img = imagecreatetruecolor($size, $size);

    // Enable alpha blending
    imagealphablending($img, true);
    imagesavealpha($img, true);

    // Background color (TheHUB blue: #004A98)
    $bg = imagecolorallocate($img, 0, 74, 152);
    imagefill($img, 0, 0, $bg);

    // White color for text
    $white = imagecolorallocate($img, 255, 255, 255);

    // Draw a simple "HUB" text or circle
    if ($size >= 72) {
        // Draw decorative circle
        $centerX = $size / 2;
        $centerY = $size / 2;
        $radius = $size * 0.4;

        imagesetthickness($img, max(1, $size / 32));
        imagearc($img, $centerX, $centerY, $radius * 2, $radius * 2, 0, 360, $white);

        // Add text "HUB" in center
        $text = 'HUB';
        $fontFile = null; // Would use TTF font if available

        // Use built-in font (font size 1-5)
        $fontSize = min(5, max(1, floor($size / 50)));
        $textWidth = imagefontwidth($fontSize) * strlen($text);
        $textHeight = imagefontheight($fontSize);
        $x = ($size - $textWidth) / 2;
        $y = ($size - $textHeight) / 2;

        imagestring($img, $fontSize, $x, $y, $text, $white);
    } else {
        // For small icons, just draw a simple shape
        $margin = $size * 0.2;
        imagefilledrectangle($img, $margin, $margin, $size - $margin, $size - $margin, $white);
    }

    // Save regular icon
    $filename = "$iconDir/icon-$size.png";
    imagepng($img, $filename);
    echo "Created: $filename\n";

    // Save maskable version (with padding) for 192 and 512
    if ($size === 192 || $size === 512) {
        $maskable = imagecreatetruecolor($size, $size);
        imagealphablending($maskable, true);
        imagesavealpha($maskable, true);

        // Fill with background color
        imagefill($maskable, 0, 0, $bg);

        // Add padding (10% on each side for safe zone)
        $padding = $size * 0.1;
        $innerSize = $size - ($padding * 2);

        // Copy and resize the original to center with padding
        imagecopyresampled(
            $maskable, $img,
            $padding, $padding, 0, 0,
            $innerSize, $innerSize, $size, $size
        );

        $maskableFilename = "$iconDir/icon-maskable-$size.png";
        imagepng($maskable, $maskableFilename);
        echo "Created: $maskableFilename\n";
        imagedestroy($maskable);
    }

    imagedestroy($img);
}

// Create favicon copies
if (file_exists("$iconDir/icon-16.png")) {
    copy("$iconDir/icon-16.png", "$iconDir/favicon-16.png");
    echo "Created: $iconDir/favicon-16.png\n";
}
if (file_exists("$iconDir/icon-32.png")) {
    copy("$iconDir/icon-32.png", "$iconDir/favicon-32.png");
    echo "Created: $iconDir/favicon-32.png\n";
}

// Create shortcut icons (copies of 192)
if (file_exists("$iconDir/icon-192.png")) {
    copy("$iconDir/icon-192.png", "$iconDir/shortcut-results.png");
    copy("$iconDir/icon-192.png", "$iconDir/shortcut-series.png");
    echo "Created: $iconDir/shortcut-results.png\n";
    echo "Created: $iconDir/shortcut-series.png\n";
}

echo "\n✓ Icons generated in $iconDir\n";
echo "Note: Replace these placeholder icons with properly designed icons!\n";
