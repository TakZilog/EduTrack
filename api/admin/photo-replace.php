<?php

/**
 * Replaces the picture of one photo in the walkthrough, for example a fixed
 * path photo after a hallway is repainted.
 *
 * This is the smallest change that can be made to the map: the picture at one
 * step is swapped and nothing about the route changes. No photo is added or
 * removed, so it also works on the gate and the locked fixed paths.
 *
 * The old picture is deleted, not kept: copies would pile up, and the map is
 * meant to stay small. The new one is shrunk to the same size as every other
 * photo (walk-lib.php).
 *
 * It takes a normal file upload rather than JSON, because that is what a file
 * input sends.
 */

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/graph-lib.php';
require __DIR__ . '/walk-lib.php';

admin_boot('room.edit', 'POST');

if (!gd_ready()) {
    json_fail(503, 'Photos cannot be changed yet because the server cannot shrink pictures. '
        . 'Turn on PHP\'s GD extension (extension=gd in php.ini) and restart Apache.');
}

$nodeId = (string) ($_POST['node'] ?? '');

/* The photo must be one the map already knows, so nothing the browser sends
   can ever decide a filename. */
$node = null;
foreach (load_graph()['nodes'] as $n) {
    if ($n['node_id'] === $nodeId) {
        $node = $n;
        break;
    }
}
if ($node === null) {
    json_fail(404, 'That photo is not part of the map.');
}

$problem = upload_problem($_FILES['photo'] ?? []);
if ($problem !== null) {
    json_fail(400, $problem . ' The old picture is still in place.');
}

// The name comes from the map file, not from this request, and graph-write.php
// refuses one that could act as a path. basename() keeps that true for map
// files written before that rule existed: this endpoint overwrites a picture
// and must never be able to write outside the photo folder.
$target = dirname(GRAPH_PATH) . '/' . basename((string) $node['image_file']);

try {
    write_panorama($_FILES['photo']['tmp_name'], $target);
} catch (RuntimeException) {
    json_fail(500, 'The new picture could not be saved. The old one is still in place.');
}

// The cached preview is keyed on the photo's timestamp, so it would rebuild
// itself; removing it frees the space straight away.
@unlink(THUMBS_DIR . '/' . basename($nodeId) . '.webp');

audit_log('photo.replace', 'photo', $nodeId, 'Replaced the picture at ' . $nodeId . '. The old picture was deleted.');

[$width, $height] = getimagesize($target);
json_ok([
    'message' => 'The picture has been replaced.',
    'width'   => $width,
    'height'  => $height,
    'sizeKb'  => (int) round(filesize($target) / 1024),
]);
