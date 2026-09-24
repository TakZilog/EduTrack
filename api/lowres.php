<?php

/**
 * The low-internet copy of a panorama: half width, lower quality.
 *
 * One place, shared by the public image endpoint (node-image.php, which builds
 * a copy the first time it is asked for one) and the staff photo tools
 * (photo-replace.php, room-walk.php, which build it the moment a photo changes
 * so a visitor never waits for it). A one-off backfill builds the rest.
 */

declare(strict_types=1);

const LOW_RES_WIDTH   = 2048;                 // half the 4096px master
const LOW_RES_QUALITY = 55;
const LOWRES_DIR      = __DIR__ . '/../storage/lowres';

/**
 * Path to the cached low copy of one panorama, building it when missing or
 * stale, or null when it cannot be made (no GD/WebP, or an unreadable source).
 *
 * Rebuilt whenever the source is newer, the same way the admin thumbnails work.
 * $filename is the already-validated image file name and cannot hold a path
 * separator, so nothing here writes outside the cache folder.
 */
function low_res_path(string $source, string $filename): ?string
{
    if (!function_exists('imagecreatefromwebp') || !function_exists('imagewebp')) {
        return null; // GD/WebP not built in: nothing to shrink with.
    }
    if (!is_dir(LOWRES_DIR) && !@mkdir(LOWRES_DIR, 0775, true) && !is_dir(LOWRES_DIR)) {
        return null;
    }

    $cache = LOWRES_DIR . '/' . $filename . '.webp';
    if (is_file($cache) && filemtime($cache) >= filemtime($source)) {
        return $cache;
    }

    $info  = @getimagesize($source);
    $image = match ($info[2] ?? null) {
        IMAGETYPE_JPEG => @imagecreatefromjpeg($source),
        IMAGETYPE_PNG  => @imagecreatefrompng($source),
        IMAGETYPE_WEBP => @imagecreatefromwebp($source),
        default        => false,
    };
    if ($image === false) {
        return null;
    }
    if (!imageistruecolor($image)) {
        imagepalettetotruecolor($image); // WebP cannot be written from a palette image
    }

    $w = imagesx($image);
    $h = imagesy($image);
    if ($w > LOW_RES_WIDTH) {
        $small = imagecreatetruecolor(LOW_RES_WIDTH, (int) round(LOW_RES_WIDTH * $h / $w));
        imagecopyresampled($small, $image, 0, 0, 0, 0, imagesx($small), imagesy($small), $w, $h);
        imagedestroy($image);
        $image = $small;
    }

    // Written beside the target and moved into place, so a failure never leaves
    // a half-written file where a good one was.
    $temp = $cache . '.tmp';
    $ok   = imagewebp($image, $temp, LOW_RES_QUALITY);
    imagedestroy($image);

    if (!$ok || !@rename($temp, $cache)) {
        @unlink($temp);
        return null;
    }
    return $cache;
}
