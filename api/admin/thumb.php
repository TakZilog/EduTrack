<?php

/**
 * Small preview images of the walkthrough photos.
 *
 * The real photos are 4096x2048 panoramas, around 468 KB each. A route can be
 * nineteen steps long, so showing the real files would be a seven megabyte
 * page on a campus network. These are generated once and cached on disk.
 *
 *     <img src="../api/admin/thumb.php?node=HALL-01">
 *
 * Serves an image rather than JSON, so it does its own auth check instead of
 * going through admin_boot().
 */

declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../session.php';
require_once __DIR__ . '/permissions.php';
require_once __DIR__ . '/ip-guard.php';
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/graph-lib.php';
require_once __DIR__ . '/walk-lib.php';   // THUMBS_DIR

const THUMB_W   = 480;   // 2:1, so 480x240

app_session_start();
enforce_ip_allowlist();

if (empty($_SESSION['admin_id']) || current_admin() === null || !can('room.view')) {
    http_response_code(403);
    exit;
}

$requested = (string) ($_GET['node'] ?? '');

// The id is checked against the map rather than sanitised, so nothing the
// browser sends can ever become a path.
$graph = load_graph();
$file  = null;
foreach ($graph['nodes'] as $node) {
    if ($node['node_id'] === $requested) {
        $file = $node['image_file'];
        break;
    }
}

if ($file === null) {
    http_response_code(404);
    exit;
}

$source = dirname(GRAPH_PATH) . '/' . $file;
if (!is_file($source)) {
    http_response_code(404);
    exit;
}

// The id already had to match a node in the map to get this far, and
// graph-write.php refuses an id that could act as a path. basename() is the
// belt to that brace: a map file written before that rule existed cannot steer
// this write out of the thumbnail folder.
$cache = THUMBS_DIR . '/' . basename($requested) . '.webp';

/*
  Shrinking needs PHP's GD extension with WebP support, which XAMPP ships
  turned off (;extension=gd in php.ini). Rather than showing a broken picture
  in the staff panel, serve the real photo: heavier, but the screen works.
  Turning GD on restores the small previews with no other change.
*/
if (!function_exists('imagecreatefromwebp')) {
    send_revalidated_file($source, 'image/webp');
}

// Rebuild when the photo is newer than its thumbnail, so replacing a panorama
// does not leave a stale preview behind.
if (!is_file($cache) || filemtime($cache) < filemtime($source)) {
    if (!is_dir(THUMBS_DIR) && !mkdir(THUMBS_DIR, 0775, true) && !is_dir(THUMBS_DIR)) {
        http_response_code(500);
        exit;
    }

    $image = @imagecreatefromwebp($source);
    if ($image === false) {
        http_response_code(500);
        exit;
    }

    $w = imagesx($image);
    $h = imagesy($image);
    $thumb = imagecreatetruecolor(THUMB_W, (int) round(THUMB_W * $h / $w));

    imagecopyresampled(
        $thumb, $image,
        0, 0, 0, 0,
        imagesx($thumb), imagesy($thumb),
        $w, $h
    );

    imagewebp($thumb, $cache, 72);
    imagedestroy($thumb);
    imagedestroy($image);
}

// Checked on every view rather than cached for a day: a node id comes back
// when a room is photographed again, with a different picture.
send_revalidated_file($cache, 'image/webp');
