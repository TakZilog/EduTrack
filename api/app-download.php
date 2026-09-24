<?php
/**
 * The Android app download (the button on the home page).
 *
 * Sends the browser to the APK at an address that carries the file's
 * timestamp, so every new build gets a new address. Cloudflare caches .apk
 * files and tells browsers to keep them for hours, overriding the site's own
 * cache headers, so a fixed address kept handing out an old build long after a
 * new one was uploaded. A new address per build is fetched fresh everywhere;
 * an unchanged build keeps its address, so cached copies are still reused.
 */
declare(strict_types=1);

$apk = __DIR__ . '/../downloads/edutrack.apk';

if (!is_file($apk)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'The app is not available for download right now.';
    exit;
}

// Never kept anywhere: this answer changes whenever the app is rebuilt.
header('Cache-Control: no-store');
header('Location: ../downloads/edutrack.apk?v=' . filemtime($apk), true, 302);
