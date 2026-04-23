<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Throwable;

/**
 * Normalises driver-uploaded photographs so they render consistently in
 * every viewer (browser, Dompdf, email client).
 *
 * The PWA sends whatever the phone's camera spat out, which is typically
 * a raw sensor image with an EXIF "Orientation" tag that says "rotate
 * 90° for display". Browsers usually honour that tag, Dompdf and some
 * image previewers don't — so the same photo renders upright in Chrome
 * and sideways on the PDF. Other fun surprises: 12MP sensor images,
 * GPS EXIF leaking into customer-facing PDFs, and HEIC/HEIF files from
 * recent iPhones that GD can't read at all.
 *
 * What this does, in order:
 *   1. Only touch JPEG/PNG/WebP. Anything else (HEIC, PDF, etc.) passes
 *      through untouched — we don't have HEIC codecs available.
 *   2. Decode with GD.
 *   3. Apply the EXIF orientation transform so the pixels match what
 *      the photographer actually saw.
 *   4. Downscale if the longest side exceeds the cap (default 2560px).
 *      Driver photos don't need to be 12MP — insurance claims are fine
 *      at 2k and storage cost is 20× lower.
 *   5. Re-encode as JPEG (the format the rest of the stack assumes),
 *      which also strips every remaining EXIF/GPS/maker-note field.
 *   6. Overwrite the temp file in place so the caller's subsequent
 *      $file->store() / ->getSize() / hash calls pick up the new bytes.
 *
 * If anything fails we leave the file untouched — we never want a
 * flaky image library call to prevent a driver from finishing a job.
 */
class ImageNormalizer
{
    /** Longest edge (px) to cap at. ~2k keeps vehicle detail visible
     *  while cutting a ~4MB phone shot to well under 1MB. */
    public const MAX_EDGE = 2560;

    /** JPEG quality after re-encode. 85 is the sweet spot for photographs. */
    public const JPEG_QUALITY = 85;

    /**
     * Normalise the uploaded file IN PLACE. Returns true if the file
     * was rewritten; false if we left it alone (unsupported type, GD
     * failed, etc.). Never throws.
     */
    public function normalise(UploadedFile $file): bool
    {
        try {
            return $this->doNormalise($file);
        } catch (Throwable) {
            return false;
        }
    }

    protected function doNormalise(UploadedFile $file): bool
    {
        $mime = (string) $file->getMimeType();
        $path = (string) $file->getRealPath();

        if ($path === '' || !is_file($path)) {
            return false;
        }

        $image = match (true) {
            str_contains($mime, 'jpeg'), str_contains($mime, 'jpg') => @imagecreatefromjpeg($path),
            str_contains($mime, 'png')  => @imagecreatefrompng($path),
            str_contains($mime, 'webp') => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
            default => null,
        };

        // null = unsupported type (e.g. HEIC); false = decoder failed.
        // In either case leave the file alone — the upload still succeeds.
        if ($image === null || $image === false) {
            return false;
        }

        try {
            $image = $this->applyExifOrientation($image, $path, $mime);
            $image = $this->downscaleIfNeeded($image);

            $ok = @imagejpeg($image, $path, self::JPEG_QUALITY);
            return (bool) $ok;
        } finally {
            if (is_resource($image) || $image instanceof \GdImage) {
                @imagedestroy($image);
            }
        }
    }

    /**
     * GD doesn't consult EXIF itself — we have to read the Orientation
     * tag and flip/rotate the canvas manually. EXIF tag 1 = default,
     * 3 = 180°, 6 = 90° CW, 8 = 90° CCW; 2/4/5/7 are flipped variants
     * which also show up occasionally (front-camera selfies etc).
     */
    protected function applyExifOrientation(\GdImage $image, string $path, string $mime): \GdImage
    {
        // exif_read_data only works on JPEG/TIFF.
        if (!str_contains($mime, 'jpeg') && !str_contains($mime, 'jpg')) {
            return $image;
        }
        if (!function_exists('exif_read_data')) {
            return $image;
        }

        $exif = @exif_read_data($path);
        $orientation = (int) ($exif['Orientation'] ?? 1);
        if ($orientation === 1) {
            return $image;
        }

        switch ($orientation) {
            case 2: // horizontal flip
                imageflip($image, IMG_FLIP_HORIZONTAL);
                break;
            case 3: // 180°
                $image = imagerotate($image, 180, 0) ?: $image;
                break;
            case 4: // vertical flip
                imageflip($image, IMG_FLIP_VERTICAL);
                break;
            case 5: // vertical flip + 90° CW
                imageflip($image, IMG_FLIP_VERTICAL);
                $image = imagerotate($image, -90, 0) ?: $image;
                break;
            case 6: // 90° CW (most common — portrait iPhone shot)
                $image = imagerotate($image, -90, 0) ?: $image;
                break;
            case 7: // horizontal flip + 90° CW
                imageflip($image, IMG_FLIP_HORIZONTAL);
                $image = imagerotate($image, -90, 0) ?: $image;
                break;
            case 8: // 90° CCW
                $image = imagerotate($image, 90, 0) ?: $image;
                break;
        }

        return $image;
    }

    /**
     * Resize so the longest edge equals MAX_EDGE, preserving aspect.
     * Small images pass through untouched so we don't upscale anything.
     */
    protected function downscaleIfNeeded(\GdImage $image): \GdImage
    {
        $w = imagesx($image);
        $h = imagesy($image);
        if ($w <= 0 || $h <= 0) {
            return $image;
        }

        $longest = max($w, $h);
        if ($longest <= self::MAX_EDGE) {
            return $image;
        }

        $scale = self::MAX_EDGE / $longest;
        $newW = max(1, (int) round($w * $scale));
        $newH = max(1, (int) round($h * $scale));

        $resized = imagecreatetruecolor($newW, $newH);
        // Preserve a white background for any transparent PNGs we
        // re-encode as JPEG. Damage photos are essentially always
        // opaque but this keeps non-photo uploads looking sane.
        $white = imagecolorallocate($resized, 255, 255, 255);
        imagefilledrectangle($resized, 0, 0, $newW, $newH, $white);
        imagecopyresampled($resized, $image, 0, 0, 0, 0, $newW, $newH, $w, $h);

        imagedestroy($image);
        return $resized;
    }
}
