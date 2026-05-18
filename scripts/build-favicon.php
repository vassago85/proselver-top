<?php
/*
 * One-shot script: extract the trident mark from public/logo.png and
 * generate the favicon set. Crops to the bounding box of non-near-
 * white pixels in the top half of the image (the text occupies the
 * bottom half, so cropping there isolates just the trident), trims
 * tightly, then writes:
 *
 *   public/favicon-16.png   (browser tab — low DPI)
 *   public/favicon-32.png   (browser tab — high DPI / Retina)
 *   public/favicon-48.png   (Windows site tile)
 *   public/favicon-192.png  (PWA manifest)
 *   public/favicon-512.png  (PWA splash)
 *   public/apple-touch-icon.png  (iOS home screen — 180×180)
 *
 * Re-run this any time the source logo changes. Output PNGs are
 * intentionally checked in so production doesn't need GD to rebuild.
 */

$root = dirname(__DIR__);
$src = $root . '/public/logo.png';
if (!is_file($src)) {
    fwrite(STDERR, "Source logo not found: {$src}\n");
    exit(1);
}

$im = imagecreatefrompng($src);
$w = imagesx($im);
$h = imagesy($im);
fwrite(STDOUT, "Source: {$w}×{$h}\n");

// Crop window: top 60% of image (above the text). The trident lives
// in the upper portion; the wordmark "TRIDENT" + tagline live below
// roughly the 60% line.
$cropTop = 0;
$cropBottom = (int) round($h * 0.60);

// Scan that window for the bounding box of any non-near-white pixel.
// "Near white" = RGB all > 240 OR alpha = transparent. This snaps the
// bounds to the trident itself, ignoring the soft cream background.
$minX = $w; $maxX = -1; $minY = $cropBottom; $maxY = -1;
for ($y = $cropTop; $y < $cropBottom; $y++) {
    for ($x = 0; $x < $w; $x++) {
        $rgb = imagecolorat($im, $x, $y);
        $a = ($rgb >> 24) & 0x7F;
        if ($a > 100) continue; // fully transparent
        $r = ($rgb >> 16) & 0xFF;
        $g = ($rgb >> 8)  & 0xFF;
        $b =  $rgb        & 0xFF;
        if ($r > 240 && $g > 240 && $b > 240) continue;
        if ($x < $minX) $minX = $x;
        if ($x > $maxX) $maxX = $x;
        if ($y < $minY) $minY = $y;
        if ($y > $maxY) $maxY = $y;
    }
}

if ($maxX < 0) {
    fwrite(STDERR, "Could not find trident bounds.\n");
    exit(1);
}

// Tight box with a small breathing-room margin (~3% of the longer
// side) so the icon doesn't feel clipped at small sizes.
$cropW = $maxX - $minX + 1;
$cropH = $maxY - $minY + 1;
$pad = (int) round(max($cropW, $cropH) * 0.03);
$minX = max(0, $minX - $pad);
$minY = max(0, $minY - $pad);
$maxX = min($w - 1, $maxX + $pad);
$maxY = min($h - 1, $maxY + $pad);
$cropW = $maxX - $minX + 1;
$cropH = $maxY - $minY + 1;
fwrite(STDOUT, "Trident bounds: x={$minX}..{$maxX}, y={$minY}..{$maxY} ({$cropW}×{$cropH})\n");

// Crop into a square canvas — favicons are square. Use the longer
// dimension as the side, centre the trident inside, transparent
// background. This avoids stretching / squashing.
$side = max($cropW, $cropH);
$square = imagecreatetruecolor($side, $side);
imagealphablending($square, false);
imagesavealpha($square, true);
$transparent = imagecolorallocatealpha($square, 0, 0, 0, 127);
imagefilledrectangle($square, 0, 0, $side, $side, $transparent);

$dstX = (int) round(($side - $cropW) / 2);
$dstY = (int) round(($side - $cropH) / 2);
imagecopy($square, $im, $dstX, $dstY, $minX, $minY, $cropW, $cropH);

// Knock the off-white background out of the cropped square so the
// favicon has a transparent backdrop (looks right on dark browser
// chrome / OS taskbars).
for ($y = 0; $y < $side; $y++) {
    for ($x = 0; $x < $side; $x++) {
        $rgb = imagecolorat($square, $x, $y);
        $r = ($rgb >> 16) & 0xFF;
        $g = ($rgb >> 8)  & 0xFF;
        $b =  $rgb        & 0xFF;
        if ($r > 240 && $g > 240 && $b > 240) {
            imagesetpixel($square, $x, $y, $transparent);
        }
    }
}

$sizes = [
    'favicon-16.png'        => 16,
    'favicon-32.png'        => 32,
    'favicon-48.png'        => 48,
    'favicon-192.png'       => 192,
    'favicon-512.png'       => 512,
    'apple-touch-icon.png'  => 180,
];

foreach ($sizes as $name => $px) {
    $out = imagecreatetruecolor($px, $px);
    imagealphablending($out, false);
    imagesavealpha($out, true);
    $t = imagecolorallocatealpha($out, 0, 0, 0, 127);
    imagefilledrectangle($out, 0, 0, $px, $px, $t);
    imagealphablending($out, true);
    imagecopyresampled($out, $square, 0, 0, 0, 0, $px, $px, $side, $side);

    $path = $root . '/public/' . $name;
    imagepng($out, $path);
    imagedestroy($out);
    fwrite(STDOUT, "Wrote {$path}\n");
}

// Pack a real multi-size .ico (PNG-encoded entries) so legacy
// browsers and pinned Windows tabs get crisp rendering. ICO with
// PNG payloads is officially supported by Vista+.
$icoSizes = [16, 32, 48];
$icoEntries = [];
foreach ($icoSizes as $px) {
    $out = imagecreatetruecolor($px, $px);
    imagealphablending($out, false);
    imagesavealpha($out, true);
    $t = imagecolorallocatealpha($out, 0, 0, 0, 127);
    imagefilledrectangle($out, 0, 0, $px, $px, $t);
    imagealphablending($out, true);
    imagecopyresampled($out, $square, 0, 0, 0, 0, $px, $px, $side, $side);

    ob_start();
    imagepng($out, null, 9);
    $icoEntries[$px] = ob_get_clean();
    imagedestroy($out);
}

$header = pack('vvv', 0, 1, count($icoEntries));
$dirEntries = '';
$payloads = '';
$offset = 6 + (16 * count($icoEntries));
foreach ($icoEntries as $px => $png) {
    $size = strlen($png);
    $dim = $px >= 256 ? 0 : $px;
    $dirEntries .= pack('CCCCvvVV', $dim, $dim, 0, 0, 1, 32, $size, $offset);
    $payloads .= $png;
    $offset += $size;
}
file_put_contents($root . '/public/favicon.ico', $header . $dirEntries . $payloads);
fwrite(STDOUT, "Wrote {$root}/public/favicon.ico (" . strlen($header . $dirEntries . $payloads) . " bytes, " . count($icoEntries) . " sizes)\n");

imagedestroy($square);
imagedestroy($im);
fwrite(STDOUT, "Done.\n");
