<?php

/**
 * Safe writing of the campus map file.
 *
 * Every visitor's walkthrough reads assets/nodes/nodes-edges.json. A half
 * written file breaks the map for everyone, so writes here are:
 *
 *   1. taken under an exclusive lock, so two staff cannot interleave
 *   2. validated in full before anything is committed
 *   3. snapshotted, so the previous version is always recoverable
 *   4. written to a temporary file and moved into place with rename(),
 *      which is atomic on the same filesystem
 *
 * A validation failure leaves the live file completely untouched.
 */

declare(strict_types=1);

require_once __DIR__ . '/graph-lib.php';

const SNAPSHOT_DIR   = __DIR__ . '/../../storage/map-snapshots';
const SNAPSHOTS_KEPT = 20;

/**
 * Checks a graph is internally consistent before it is allowed anywhere near
 * the live file.
 *
 * @return string[] problems found, empty when the graph is sound
 */
function validate_graph(array $graph): array
{
    $errors = [];

    foreach (['nodes', 'edges', 'rooms'] as $key) {
        if (!isset($graph[$key]) || !is_array($graph[$key])) {
            return ["The map is missing its {$key} list."];
        }
    }

    $nodeIds = [];
    foreach ($graph['nodes'] as $node) {
        foreach (['node_id', 'label', 'image_file', 'type'] as $field) {
            if (!isset($node[$field])) {
                $errors[] = 'A photo entry is missing its ' . $field . '.';
                continue 2;
            }
        }

        /*
          Both of these become part of a path later: image_file is opened and
          overwritten by photo-replace.php, and node_id names the cached
          thumbnail in thumb.php. Neither is free text, and neither may carry a
          slash or a dot pair, or the map file becomes a way to read and write
          elsewhere on the server. Checked here because this function is the one
          gate every map write passes through.
        */
        if (!preg_match('/^[A-Za-z0-9._-]{1,64}$/', (string) $node['node_id'])
            || str_contains((string) $node['node_id'], '..')) {
            $errors[] = 'The photo id "' . $node['node_id']
                . '" is not allowed. Use letters, numbers, dots, dashes and underscores only.';
            continue;
        }
        if (!preg_match('/^[A-Za-z0-9._-]+\.(webp|jpg|jpeg|png)$/i', (string) $node['image_file'])
            || str_contains((string) $node['image_file'], '..')) {
            $errors[] = 'The picture file name "' . $node['image_file']
                . '" is not allowed. It must be a plain .webp, .jpg or .png file name.';
            continue;
        }

        if (isset($nodeIds[$node['node_id']])) {
            $errors[] = 'Two photos share the id ' . $node['node_id'] . '.';
        }
        $nodeIds[$node['node_id']] = true;
    }

    // walkthrough.js finds the starting point with type === 'landmark'.
    // Without one, every route fails.
    $landmarks = array_filter($graph['nodes'], static fn ($n) => ($n['type'] ?? '') === 'landmark');
    if (count($landmarks) === 0) {
        $errors[] = 'No starting point is marked. One photo must have the type "landmark".';
    } elseif (count($landmarks) > 1) {
        $errors[] = 'More than one photo is marked as the starting point. Only the main gate may be.';
    }

    foreach ($graph['edges'] as $edge) {
        foreach (['from_node', 'to_node'] as $end) {
            if (!isset($nodeIds[$edge[$end] ?? ''])) {
                $errors[] = 'A link points at a photo that does not exist: ' . ($edge[$end] ?? '?') . '.';
            }
        }
    }

    $reachable = reachable_nodes($graph);
    $names     = [];
    foreach ($graph['rooms'] as $room) {
        foreach (['room_name', 'node_id'] as $field) {
            if (!isset($room[$field]) || trim((string) $room[$field]) === '') {
                $errors[] = 'A room is missing its ' . str_replace('_', ' ', $field) . '.';
                continue 2;
            }
        }
        if (!isset($nodeIds[$room['node_id']])) {
            $errors[] = 'Room "' . $room['room_name'] . '" points at a photo that does not exist.';
        } elseif (!isset($reachable[$room['node_id']])) {
            // Named by photo, not by room, so renaming a room that was already
            // stranded does not look like a new fault and get refused.
            $errors[] = 'The room photo ' . $room['node_id'] . ' cannot be reached from the gate.';
        }

        // The walkthrough looks rooms up by name, so duplicates make all but
        // the first unreachable. This is the check that stops that happening.
        $key = mb_strtolower(trim($room['room_name']));
        if (isset($names[$key])) {
            $errors[] = 'Two rooms are both called "' . $room['room_name'] . '". Room names must be different.';
        }
        $names[$key] = true;
    }

    if (isset($graph['buildings'])) {
        $errors = [...$errors, ...validate_floors($graph)];
    }

    return array_values(array_unique($errors));
}

/**
 * The rules of the building-and-floor map (see walk-lib.php): one gate, each
 * floor's fixed path ending at its floor point, and no fixed path linked to
 * another floor.
 *
 * @return string[]
 */
function validate_floors(array $graph): array
{
    $errors = [];
    $byId   = array_column($graph['nodes'], null, 'node_id');

    $gates = array_values(array_filter($graph['nodes'], static fn ($n) => ($n['role'] ?? '') === 'gate'));
    if (count($gates) !== 1 || ($gates[0]['type'] ?? '') !== 'landmark') {
        $errors[] = 'The map must have exactly one main gate photo, marked as the starting point.';
    }

    foreach ($graph['buildings'] as $building) {
        foreach ($building['floors'] ?? [] as $floor) {
            $point = $byId[$floor['point'] ?? ''] ?? null;
            if ($point === null
                || ($point['role'] ?? '') !== 'path'
                || ($point['building'] ?? '') !== $building['code']
                || (int) ($point['floor'] ?? 0) !== (int) $floor['n']) {
                $errors[] = 'The fixed path of ' . $building['name'] . ' floor ' . $floor['n'] . ' has lost its end point.';
            }
        }
    }

    foreach ($graph['edges'] as $edge) {
        $a = $byId[$edge['from_node']] ?? null;
        $z = $byId[$edge['to_node']] ?? null;
        if ($a === null || $z === null) {
            continue;   // a link to a missing photo is reported by validate_graph()
        }
        foreach ([[$a, $z], [$z, $a]] as [$path, $other]) {
            if (($path['role'] ?? '') !== 'path' || ($other['role'] ?? '') === 'gate') {
                continue;
            }
            if (($other['building'] ?? null) !== ($path['building'] ?? null)
                || (int) ($other['floor'] ?? 0) !== (int) ($path['floor'] ?? 0)) {
                $errors[] = 'The fixed path photo ' . $path['node_id'] . ' is linked to a different floor.';
            }
        }
    }

    return $errors;
}

/**
 * The gate and every fixed path photo are locked: a change may replace their
 * pictures (photo-replace.php), never take them off the map.
 *
 * @return string[]
 */
function locked_photos_removed(array $before, array $after): array
{
    $now    = array_column($after['nodes'] ?? [], 'role', 'node_id');
    $errors = [];

    foreach ($before['nodes'] ?? [] as $node) {
        $role = $node['role'] ?? '';
        if ($role !== 'gate' && $role !== 'path') {
            continue;
        }
        if (($now[$node['node_id']] ?? null) !== $role) {
            $errors[] = $role === 'gate'
                ? 'The main gate photo is locked and cannot be removed.'
                : 'The fixed path photo ' . $node['node_id'] . ' is locked and cannot be removed.';
        }
    }

    return $errors;
}

/** Copies the current map aside before it is replaced. */
function snapshot_graph(): ?string
{
    if (!is_file(GRAPH_PATH)) {
        return null;
    }

    if (!is_dir(SNAPSHOT_DIR) && !mkdir(SNAPSHOT_DIR, 0775, true) && !is_dir(SNAPSHOT_DIR)) {
        return null;
    }

    $name = date('Y-m-d_His') . '.json';
    if (!copy(GRAPH_PATH, SNAPSHOT_DIR . '/' . $name)) {
        return null;
    }

    // Keep the most recent few and let the rest go.
    $kept = glob(SNAPSHOT_DIR . '/*.json') ?: [];
    rsort($kept);
    foreach (array_slice($kept, SNAPSHOTS_KEPT) as $old) {
        @unlink($old);
    }

    return $name;
}

/**
 * Changes the live map: reads it, applies $change, validates, snapshots and
 * replaces it, all under one lock.
 *
 * The change is worked out from the map as it is at that moment, not as it
 * was when the page loaded, so two staff saving at once cannot overwrite each
 * other, and a photo one of them deletes is never left in the other's map.
 * $change receives the current map and returns the new one; it may refuse by
 * calling json_fail().
 *
 * A change is judged against what the map looked like before it, not against
 * perfection. The live map already has faults that predate this panel, and
 * refusing every save until they are gone would make them impossible to fix:
 * the duplicate room name can only be corrected by renaming a room, which is
 * itself a save. So only problems the change *introduces* block it. The gate
 * and the fixed paths are also locked (locked_photos_removed()).
 *
 * @param callable(array): array $change
 * @return string the snapshot of the previous map, or '' when none was taken
 */
function update_graph(callable $change): string
{
    /*
      The lock lives on a separate file, never on the map itself. Windows
      refuses rename() onto a destination that has an open handle, so locking
      the target directly made every save fail with "Access is denied" while
      working fine on Linux.
    */
    $handle = fopen(GRAPH_PATH . '.lock', 'c');
    if ($handle === false) {
        json_fail(503, 'The map file could not be locked for writing.');
    }

    try {
        if (!flock($handle, LOCK_EX)) {
            json_fail(503, 'Someone else is saving a change right now. Please try again in a moment.');
        }

        $before = is_file(GRAPH_PATH)
            ? (json_decode((string) file_get_contents(GRAPH_PATH), true) ?: [])
            : [];
        $graph = $change($before);

        $introduced = [
            ...array_diff(validate_graph($graph), $before ? validate_graph($before) : []),
            ...locked_photos_removed($before, $graph),
        ];
        if ($introduced) {
            json_fail(400, 'The change was not saved because it would break the map: ' . $introduced[0], [
                'problems' => array_values($introduced),
            ]);
        }

        $snapshot = snapshot_graph();

        $json = json_encode($graph, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            json_fail(500, 'The map could not be prepared for saving. Nothing was changed.');
        }

        // Written beside the target so rename() stays on one filesystem.
        $temp = GRAPH_PATH . '.tmp';
        if (file_put_contents($temp, $json) === false || !rename($temp, GRAPH_PATH)) {
            @unlink($temp);
            json_fail(500, 'The map could not be saved. The previous version is still in place.');
        }

        return $snapshot ?? '';
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

/** Replaces the live map with one already worked out (see update_graph()). */
function save_graph(array $graph): string
{
    return update_graph(static fn (): array => $graph);
}
