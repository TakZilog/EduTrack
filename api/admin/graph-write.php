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
    }

    foreach ($graph['edges'] as $edge) {
        foreach (['from_node', 'to_node'] as $end) {
            if (!isset($nodeIds[$edge[$end] ?? ''])) {
                $errors[] = 'A link points at a photo that does not exist: ' . ($edge[$end] ?? '?') . '.';
            }
        }
    }

    $names = [];
    foreach ($graph['rooms'] as $room) {
        foreach (['room_name', 'node_id'] as $field) {
            if (!isset($room[$field]) || trim((string) $room[$field]) === '') {
                $errors[] = 'A room is missing its ' . str_replace('_', ' ', $field) . '.';
                continue 2;
            }
        }
        if (!isset($nodeIds[$room['node_id']])) {
            $errors[] = 'Room "' . $room['room_name'] . '" points at a photo that does not exist.';
        }

        // The walkthrough looks rooms up by name, so duplicates make all but
        // the first unreachable. This is the check that stops that happening.
        $key = mb_strtolower(trim($room['room_name']));
        if (isset($names[$key])) {
            $errors[] = 'Two rooms are both called "' . $room['room_name'] . '". Room names must be different.';
        }
        $names[$key] = true;
    }

    return array_values(array_unique($errors));
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
 * Validates, snapshots and replaces the live map.
 *
 * A change is judged against what the map looked like before it, not against
 * perfection. The live map already has faults that predate this panel, and
 * refusing every save until they are gone would make them impossible to fix:
 * the duplicate room name can only be corrected by renaming a room, which is
 * itself a save. So only problems the change *introduces* block it.
 */
function save_graph(array $graph): string
{
    $before = is_file(GRAPH_PATH)
        ? validate_graph(json_decode((string) file_get_contents(GRAPH_PATH), true) ?: [])
        : [];

    $introduced = array_values(array_diff(validate_graph($graph), $before));

    if ($introduced) {
        json_fail(400, 'The change was not saved because it would break the map: ' . $introduced[0], [
            'problems' => $introduced,
        ]);
    }

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
