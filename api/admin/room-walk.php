<?php

/**
 * Saves the photos of one room's walk: from its floor's point to the room,
 * the last photo being the room itself. Used to re-photograph a room and to
 * add a new one.
 *
 * Multipart POST (a file upload, so not JSON):
 *   mode      update | add
 *   building  building code, e.g. MAIN
 *   floor     floor number
 *   room      the room's name (for update, its current name)
 *   count     how many photos the page sent
 *   photos[]  the photos, in walking order
 *
 * The old walk stays live until the new one is fully in place. The new photos
 * are shrunk into a holding folder first (the slow part, done outside the
 * map lock), then moved in and the map saved under the lock. Only after the
 * save are the old photos deleted. Any failure before the save removes the
 * new files again: see the shutdown handler below.
 */

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/graph-lib.php';
require __DIR__ . '/graph-write.php';
require __DIR__ . '/walk-lib.php';
require __DIR__ . '/../lowres.php';

const UPLOAD_HOLD_DIR = __DIR__ . '/../../storage/walk-uploads';

admin_boot('room.edit', 'POST');

// A body larger than post_max_size reaches PHP with no fields and no files at
// all, which would otherwise read as "no photos chosen".
if ($_POST === [] && $_FILES === [] && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    json_fail(413, 'The photos are too large to send together. The server accepts up to '
        . ini_get('post_max_size') . 'B at once.');
}

if (!gd_ready()) {
    json_fail(503, 'Photos cannot be saved yet because the server cannot shrink pictures. '
        . 'Turn on PHP\'s GD extension (extension=gd in php.ini) and restart Apache.');
}

$mode     = (string) ($_POST['mode'] ?? '');
$code     = (string) ($_POST['building'] ?? '');
$floor    = (int) ($_POST['floor'] ?? 0);
$roomName = (string) ($_POST['room'] ?? '');

if (!in_array($mode, ['update', 'add'], true)) {
    json_fail(400, 'That action is not recognised.');
}

/* ------------------------------------------------------------- the photos */

$files = [];
$sent  = $_FILES['photos'] ?? null;
if (is_array($sent) && is_array($sent['name'] ?? null)) {
    foreach (array_keys($sent['name']) as $i) {
        $files[] = [
            'name'     => (string) $sent['name'][$i],
            'tmp_name' => (string) $sent['tmp_name'][$i],
            'error'    => (int) $sent['error'][$i],
            'size'     => (int) $sent['size'][$i],
        ];
    }
}

$limit = min(MAX_WALK_PHOTOS, (int) ini_get('max_file_uploads'));
if ($files === []) {
    json_fail(400, 'Choose the photos of the walk, from the floor point to the room.');
}
// PHP quietly drops files past max_file_uploads; the page says how many it sent.
if (count($files) !== (int) ($_POST['count'] ?? -1)) {
    json_fail(400, "Some photos did not arrive. A walk can have at most {$limit} photos.");
}
if (count($files) > $limit) {
    json_fail(400, "A walk can have at most {$limit} photos.");
}

foreach ($files as $i => $file) {
    $problem = upload_problem($file);
    if ($problem !== null) {
        json_fail(400, 'Photo ' . ($i + 1) . ' (' . basename($file['name']) . '): ' . $problem . ' Nothing was changed.');
    }
}

/* ------------------------------------------------- shrink, outside the lock */

$hold = UPLOAD_HOLD_DIR . '/' . bin2hex(random_bytes(8));
if (!mkdir($hold, 0775, true)) {
    json_fail(500, 'The server could not prepare a place for the new photos. Nothing was changed.');
}

$placed = [];      // new photos already moved into the photo folder
$saved  = false;   // true once the map points at them

// Runs however the request ends, including json_fail(): the holding folder is
// always emptied, and new photos are taken back out unless the map was saved.
register_shutdown_function(static function () use ($hold, &$placed, &$saved): void {
    foreach (glob($hold . '/*') ?: [] as $file) {
        @unlink($file);
    }
    @rmdir($hold);
    if (!$saved) {
        foreach ($placed as $file) {
            @unlink($file);
        }
    }
});

$shrunk = [];
foreach ($files as $i => $file) {
    $out = sprintf('%s/%02d.webp', $hold, $i + 1);
    try {
        write_panorama($file['tmp_name'], $out);
    } catch (RuntimeException) {
        json_fail(500, 'Photo ' . ($i + 1) . ' could not be shrunk. Nothing was changed.');
    }
    $shrunk[] = $out;
}

/* --------------------------------------------------- swap in, under the lock */

$photoDir = dirname(GRAPH_PATH);
$oldNodes = [];
$name     = '';

$snapshot = update_graph(static function (array $graph) use (
    $mode, $code, $floor, $roomName, $shrunk, $photoDir, &$placed, &$oldNodes, &$name
): array {
    if (!map_has_buildings($graph)) {
        json_fail(409, 'The map has not been set up by building and floor yet, so rooms cannot be changed here.');
    }
    if (floor_point($graph, $code, $floor) === null) {
        json_fail(404, 'That building and floor are not on the map. Refresh the page and try again.');
    }

    $byId  = array_column($graph['nodes'], null, 'node_id');
    $index = null;

    if ($mode === 'update') {
        foreach ($graph['rooms'] as $i => $room) {
            $node = $byId[$room['node_id']] ?? [];
            if ($room['room_name'] === $roomName
                && ($node['building'] ?? '') === $code
                && (int) ($node['floor'] ?? 0) === $floor) {
                $index = $i;
                break;
            }
        }
        if ($index === null) {
            json_fail(404, 'That room is no longer on this floor. Refresh the page and try again.');
        }
        $name     = $graph['rooms'][$index]['room_name'];
        $oldNodes = array_map(static fn ($id) => $byId[$id], room_own_nodes($graph, $graph['rooms'][$index]['node_id']));
    } else {
        $name = canonical_room_name($roomName);
        if ($name === '' || mb_strlen($name) > 100) {
            json_fail(400, 'Enter a room name of up to 100 characters.');
        }
        foreach ($graph['rooms'] as $room) {
            if (mb_strtolower($room['room_name']) === mb_strtolower($name)) {
                json_fail(400, 'There is already a room called "' . $name . '". Every room needs a different name.');
            }
        }
    }

    try {
        $ids = room_photo_ids($code, $floor, $name, count($shrunk),
            static fn (string $id): bool => isset($byId[$id]) || is_file($photoDir . '/' . $id . '.webp'));
    } catch (RuntimeException) {
        json_fail(500, 'The new photos could not be named. Nothing was changed.');
    }

    foreach ($ids as $i => $id) {
        $target = $photoDir . '/' . $id . '.webp';
        if (!rename($shrunk[$i], $target)) {
            json_fail(500, 'The new photos could not be put in place. Nothing was changed.');
        }
        $placed[] = $target;
    }

    if ($oldNodes) {
        $graph = drop_nodes($graph, array_column($oldNodes, 'node_id'));
    }
    $graph = add_room_walk($graph, $code, $floor, $name, $ids);

    if ($index !== null) {
        $graph['rooms'][$index]['node_id'] = end($ids);
    } else {
        $graph['rooms'][] = [
            'room_name' => $name,
            'floor'     => floor_string($floor, (string) building_name($graph, $code)),
            'node_id'   => end($ids),
        ];
    }

    return $graph;
});
$saved = true;

// The map no longer points at the old photos, so they go. No copies are kept.
delete_photo_files($photoDir, $oldNodes);

// Build the low-internet copies of the new photos now, so nobody walking to
// this room waits for them. Best effort: node-image.php builds any it misses.
foreach ($placed as $file) {
    low_res_path($file, basename($file));
}

$count = count($shrunk);
audit_log($mode === 'add' ? 'room.add' : 'room.photos', 'room', $name, $mode === 'add'
    ? "Added \"{$name}\" with {$count} photo(s)."
    : "Saved {$count} new photo(s) for the walk to \"{$name}\". The " . count($oldNodes) . ' old one(s) were deleted.');

json_ok([
    'message'  => $mode === 'add'
        ? "\"{$name}\" has been added with {$count} photo(s)."
        : "The walk to \"{$name}\" now has {$count} new photo(s).",
    'room'     => $name,
    'snapshot' => $snapshot,
]);
