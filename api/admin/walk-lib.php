<?php

/**
 * The building-and-floor walkthrough map, shared by the import tool
 * (tools/import-photos.php) and the staff panel's Walkthrough page.
 *
 * The map has one gate. Each floor of each building has a fixed path: a chain
 * of photos from the gate to that floor's point. Each room hangs off its
 * floor's point as its own chain of photos, and the last photo is the room.
 *
 *   GATE -> MAIN-F1-PATH-01 -> MAIN-F1-PATH-02 (the floor point)
 *                                  -> MAIN-F1-CASHIER-01 -> MAIN-F1-CASHIER
 *
 * Every node carries `building`, `floor` and `role` (gate, path, room-walk,
 * room) beside the fields the visitor walkthrough reads, and the map lists
 * its buildings with each floor's point under `buildings`. A room's photos
 * belong to that room only, so replacing or removing a room never touches
 * another room's walk.
 *
 * Nothing here answers the browser, so the command-line tool can use it too.
 */

declare(strict_types=1);

// The enrollment guide links rooms by name (ENROLLMENT_STEPS_PATH).
require_once __DIR__ . '/../tour-gate.php';

const PANORAMA_WIDTH   = 4096;               // every photo is shrunk to this width
const PANORAMA_QUALITY = 80;                 // WebP quality
const MAX_PHOTO_BYTES  = 25 * 1024 * 1024;   // one upload; a phone panorama is well under this
const MAX_WALK_PHOTOS  = 20;                 // PHP's default max_file_uploads
const THUMBS_DIR       = __DIR__ . '/../../storage/thumbs';

/* ------------------------------------------------------------------ names */

/**
 * How a room name is stored: capitals and single spaces, with "Room 201"
 * stored as plain "201", the way the map and the enrollment guide already
 * name numbered rooms. The visitor pages turn it back into "Room 201".
 */
function canonical_room_name(string $raw): string
{
    $name = mb_strtoupper(trim(preg_replace('/\s+/u', ' ', $raw) ?? ''));

    return preg_match('/^ROOM (\d+)$/u', $name, $m) ? $m[1] : $name;
}

/** "Main Building" -> MAIN, "Second Building" -> SECOND. */
function building_code(string $name): string
{
    $words = preg_replace('/\bbuilding\b/iu', '', $name) ?? '';

    return substr(strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $words) ?? ''), 0, 12);
}

/** 1ST, 2ND, 3RD, 4TH, 11TH, 21ST. */
function ordinal(int $n): string
{
    $suffix = in_array($n % 100, [11, 12, 13], true) ? 'TH' : (['TH', 'ST', 'ND', 'RD'][$n % 10] ?? 'TH');

    return $n . $suffix;
}

/** The floor text the visitor room list reads: "1ST FLOOR MAIN BUILDING". */
function floor_string(int $n, string $buildingName): string
{
    return ordinal($n) . ' FLOOR ' . mb_strtoupper(trim($buildingName));
}

/** "1st Floor" -> 1. Null when the folder name is not a floor. */
function floor_number(string $folder): ?int
{
    if (!preg_match('/^(\d{1,2})(st|nd|rd|th)?\s*floor$/i', trim($folder), $m) || (int) $m[1] < 1) {
        return null;
    }

    return (int) $m[1];
}

/** "Deans Office" -> DEANS-OFFICE. Only ever part of an id, never shown. */
function name_slug(string $name): string
{
    $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name) ?: '';
    $slug  = trim(preg_replace('/[^A-Z0-9]+/', '-', strtoupper($ascii)) ?? '', '-');

    return rtrim(substr($slug, 0, 24), '-') ?: 'ROOM';
}

/**
 * Ids for one room's photos, in walking order, the room itself last:
 * MAIN-F1-CASHIER-01, MAIN-F1-CASHIER-02, MAIN-F1-CASHIER.
 *
 * A room being re-photographed keeps its old photos live until the new ones
 * are saved, so the new ids must not collide with anything in use. The first
 * free one of KEY, KEY-V2, KEY-V3 ... is taken.
 *
 * @param callable(string): bool $taken true when an id is already used
 * @return string[]
 */
function room_photo_ids(string $code, int $floor, string $roomName, int $count, callable $taken): array
{
    $base = $code . '-F' . $floor . '-' . name_slug($roomName);

    for ($version = 1; $version < 100; $version++) {
        $key = $version === 1 ? $base : $base . '-V' . $version;

        $ids = [];
        for ($i = 1; $i < $count; $i++) {
            $ids[] = sprintf('%s-%02d', $key, $i);
        }
        $ids[] = $key;

        if (!array_filter($ids, $taken)) {
            return $ids;
        }
    }

    throw new RuntimeException('There is no free photo name left for ' . $roomName . '.');
}

/* ----------------------------------------------------------------- photos */

/**
 * Why a file cannot be a walkthrough photo, in plain words, or null when it
 * can. Judged by reading the file, never by its name.
 */
function photo_problem(string $path): ?string
{
    $info = @getimagesize($path);
    if ($info === false || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
        return 'This is not a JPG, PNG or WebP picture.';
    }

    /*
      The viewer wraps each photo around a sphere, so it needs a 360 panorama,
      which is always twice as wide as it is tall. An ordinary photo would
      appear badly stretched.
    */
    [$width, $height] = $info;
    $ratio = $height > 0 ? $width / $height : 0;
    if ($ratio < 1.9 || $ratio > 2.1) {
        return sprintf('This is not a 360 photo. It is %d by %d, and a 360 photo is always twice as wide as it is tall.',
            $width, $height);
    }

    return null;
}

/** photo_problem() for one file from a browser upload, plus the upload's own failures. */
function upload_problem(array $file): ?string
{
    $error = $file['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($error !== UPLOAD_ERR_OK) {
        return match ($error) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The picture is too large for the server to accept.',
            UPLOAD_ERR_PARTIAL                         => 'The upload was cut off. Please try again.',
            UPLOAD_ERR_NO_FILE                         => 'No picture was chosen.',
            default                                    => 'The picture could not be uploaded. Please try again.',
        };
    }
    if (($file['size'] ?? 0) > MAX_PHOTO_BYTES) {
        return 'The picture is larger than 25 MB.';
    }
    if (!is_uploaded_file((string) ($file['tmp_name'] ?? ''))) {
        return 'The picture could not be uploaded. Please try again.';
    }

    $problem = photo_problem($file['tmp_name']);
    if ($problem !== null) {
        return $problem;
    }

    // The Walkthrough page shrinks photos before sending them. A far larger
    // one would take more memory to open than the server should spend.
    [$width, $height] = getimagesize($file['tmp_name']);
    if ($width * $height > 40_000_000) {
        return 'The picture is too large to shrink on the server. Choose it again on the Walkthrough page, which shrinks it first.';
    }

    return null;
}

/** When the photo was taken (EXIF "YYYY:MM:DD HH:MM:SS"), or null when it does not say. */
function taken_time(string $path): ?string
{
    if (!function_exists('exif_read_data')) {
        return null;
    }

    $exif = @exif_read_data($path, 'EXIF');
    $when = is_array($exif) ? (string) ($exif['DateTimeOriginal'] ?? '') : '';

    return preg_match('/^\d{4}:\d\d:\d\d \d\d:\d\d:\d\d$/', $when) && !str_starts_with($when, '0000') ? $when : null;
}

/** Shrinking needs PHP's GD extension, which XAMPP ships turned off. */
function gd_ready(): bool
{
    return function_exists('imagecreatefromjpeg') && function_exists('imagewebp');
}

/**
 * Shrinks a panorama to PANORAMA_WIDTH (never enlarges) and writes it as WebP.
 *
 * Written beside the target and then moved into place, so a failure never
 * leaves a half-written photo where a working one was.
 *
 * @throws RuntimeException when the picture cannot be opened or written
 */
function write_panorama(string $source, string $target): void
{
    $info  = @getimagesize($source);
    $image = match ($info[2] ?? null) {
        IMAGETYPE_JPEG => @imagecreatefromjpeg($source),
        IMAGETYPE_PNG  => @imagecreatefrompng($source),
        IMAGETYPE_WEBP => @imagecreatefromwebp($source),
        default        => false,
    };
    if ($image === false) {
        throw new RuntimeException('The picture could not be opened.');
    }
    if (!imageistruecolor($image)) {
        imagepalettetotruecolor($image);   // WebP cannot be written from a palette image
    }

    $width  = imagesx($image);
    $height = imagesy($image);
    if ($width > PANORAMA_WIDTH) {
        $small = imagecreatetruecolor(PANORAMA_WIDTH, (int) round(PANORAMA_WIDTH * $height / $width));
        imagecopyresampled($small, $image, 0, 0, 0, 0, imagesx($small), imagesy($small), $width, $height);
        imagedestroy($image);
        $image = $small;
    }

    $temp    = $target . '.tmp';
    $written = imagewebp($image, $temp, PANORAMA_QUALITY);
    imagedestroy($image);

    if (!$written || !rename($temp, $target)) {
        @unlink($temp);
        throw new RuntimeException('The photo could not be saved.');
    }
}

/**
 * Deletes photo files and their cached previews. The names come from the map,
 * never from a request; basename() keeps every delete inside its folder.
 *
 * @param array<int, array{node_id: string, image_file: string}> $nodes
 */
function delete_photo_files(string $dir, array $nodes): void
{
    foreach ($nodes as $node) {
        @unlink($dir . '/' . basename((string) $node['image_file']));
        @unlink(THUMBS_DIR . '/' . basename((string) $node['node_id']) . '.webp');
    }
}

/* -------------------------------------------------------------- the graph */

/** True for a map imported by building and floor; false for the older map. */
function map_has_buildings(array $graph): bool
{
    return isset($graph['buildings']) && is_array($graph['buildings']);
}

function building_name(array $graph, string $code): ?string
{
    foreach ($graph['buildings'] ?? [] as $building) {
        if ($building['code'] === $code) {
            return $building['name'];
        }
    }

    return null;
}

/** The last photo of a floor's fixed path, where that floor's room walks begin. */
function floor_point(array $graph, string $code, int $floor): ?string
{
    foreach ($graph['buildings'] ?? [] as $building) {
        if ($building['code'] !== $code) {
            continue;
        }
        foreach ($building['floors'] as $f) {
            if ((int) $f['n'] === $floor) {
                return $f['point'];
            }
        }
    }

    return null;
}

/** @return array<string, string[]> links in both directions */
function graph_links(array $graph): array
{
    $links = [];
    foreach ($graph['edges'] as $edge) {
        $links[$edge['from_node']][] = $edge['to_node'];
        $links[$edge['to_node']][]   = $edge['from_node'];
    }

    return $links;
}

/**
 * Follows a chain of photos from $start through neighbours whose role is in
 * $through, and returns it with $start first. It stops after the first
 * photo whose own role is not part of a chain (the gate).
 */
function follow_chain(array $graph, string $start, array $through): array
{
    $role  = array_column($graph['nodes'], 'role', 'node_id');
    $links = graph_links($graph);
    $chain = [$start];
    $seen  = [$start => true];

    for ($at = $start; in_array($role[$at] ?? '', ['path', 'room', 'room-walk'], true); ) {
        $next = null;
        foreach ($links[$at] ?? [] as $n) {
            if (!isset($seen[$n]) && in_array($role[$n] ?? '', $through, true)) {
                $next = $n;
                break;
            }
        }
        if ($next === null) {
            break;
        }
        $chain[] = $next;
        $seen[$next] = true;
        $at = $next;
    }

    return $chain;
}

/** One floor's fixed path, the gate first and the floor point last. */
function fixed_path(array $graph, string $point): array
{
    return array_reverse(follow_chain($graph, $point, ['path', 'gate']));
}

/**
 * The photos that belong to one room only, in walking order: its own walk
 * from the floor point, then the room. Empty on the older map, where hallway
 * photos are shared between rooms.
 */
function room_own_nodes(array $graph, string $roomNodeId): array
{
    $role = array_column($graph['nodes'], 'role', 'node_id');
    if (($role[$roomNodeId] ?? '') !== 'room') {
        return [];
    }

    return array_reverse(follow_chain($graph, $roomNodeId, ['room-walk']));
}

/**
 * Hangs a room's walk below its floor point.
 *
 * @param string[] $ids from room_photo_ids(), the room last
 */
function add_room_walk(array $graph, string $code, int $floor, string $roomName, array $ids): array
{
    $previous = floor_point($graph, $code, $floor);
    $last     = count($ids) - 1;

    foreach ($ids as $i => $id) {
        $isRoom = $i === $last;
        $graph['nodes'][] = [
            'node_id'    => $id,
            'label'      => $isRoom ? $roomName : 'On the way to ' . $roomName,
            'image_file' => $id . '.webp',
            // walkthrough.js names a step by its type: a room by its label,
            // anything else "Hallway".
            'type'       => $isRoom ? 'room' : 'hallway',
            'building'   => $code,
            'floor'      => $floor,
            'role'       => $isRoom ? 'room' : 'room-walk',
        ];
        $graph['edges'][] = ['from_node' => $previous, 'to_node' => $id, 'direction_label' => ''];
        $previous = $id;
    }

    return $graph;
}

/** Takes photos out of the map, with every link that touches them. */
function drop_nodes(array $graph, array $ids): array
{
    $gone = array_flip($ids);

    $graph['nodes'] = array_values(array_filter($graph['nodes'],
        static fn ($n) => !isset($gone[$n['node_id']])));
    $graph['edges'] = array_values(array_filter($graph['edges'],
        static fn ($e) => !isset($gone[$e['from_node']]) && !isset($gone[$e['to_node']])));

    return $graph;
}

/**
 * Keeps a room's photo labels in step with its name. The walkthrough's
 * "You have arrived" banner shows the room photo's label.
 */
function relabel_room(array $graph, string $roomNodeId, string $name): array
{
    $walk = array_flip(room_own_nodes($graph, $roomNodeId));

    foreach ($graph['nodes'] as &$node) {
        if ($node['node_id'] === $roomNodeId) {
            $node['label'] = $name;
        } elseif (isset($walk[$node['node_id']])) {
            $node['label'] = 'On the way to ' . $name;
        }
    }
    unset($node);

    return $graph;
}

/* ------------------------------------------------------ enrollment guide */

/**
 * Points the enrollment guide at a room's new name. Only the name is
 * replaced, so the file keeps its layout. False when the guide could not be
 * rewritten; true when it was, or never named the room.
 */
function enrollment_rename(string $old, string $new): bool
{
    $text = @file_get_contents(ENROLLMENT_STEPS_PATH);
    if ($text === false) {
        return false;
    }

    $flags   = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
    $count   = 0;
    $updated = preg_replace_callback(
        '/("room_name"\s*:\s*)' . preg_quote((string) json_encode($old, $flags), '/') . '/u',
        static fn (array $m): string => $m[1] . json_encode($new, $flags),
        $text,
        -1,
        $count
    );

    if ($count === 0) {
        return true;
    }
    if ($updated === null || !is_array(json_decode($updated, true))) {
        return false;
    }

    $temp = ENROLLMENT_STEPS_PATH . '.tmp';
    if (file_put_contents($temp, $updated) === false || !rename($temp, ENROLLMENT_STEPS_PATH)) {
        @unlink($temp);
        return false;
    }

    return true;
}
