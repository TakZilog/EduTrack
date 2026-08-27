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

const THUMB_DIR = __DIR__ . '/../../storage/thumbs';
const THUMB_W   = 480;   // 2:1, so 480x240

app_session_start();
enforce_ip_allowlist();

if (empty($_SESSION['admin_id']) || !can('room.view')) {
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

$cache = THUMB_DIR . '/' . $requested . '.webp';

// Rebuild when the photo is newer than its thumbnail, so replacing a panorama
// does not leave a stale preview behind.
if (!is_file($cache) || filemtime($cache) < filemtime($source)) {
    if (!is_dir(THUMB_DIR) && !mkdir(THUMB_DIR, 0775, true) && !is_dir(THUMB_DIR)) {
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

header('Content-Type: image/webp');
header('Content-Length: ' . filesize($cache));
// Safe to cache hard: the filename is the node id and the content only changes
// when the photo is replaced, which busts it through the mtime check above.
header('Cache-Control: private, max-age=86400');

readfile($cache);
