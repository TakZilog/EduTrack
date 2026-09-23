<?php

/**
 * Renaming, swapping and removing rooms.
 *
 *   rename  a room gets a new name (on the older single-building map, also a
 *           new floor; on the building map the floor comes from its photos)
 *   swap    two rooms trade names: an office moved, the doors did not
 *   remove  a room leaves the map; on the building map its own photos are
 *           deleted too
 *   relist  a room photo that was taken off the list goes back on it (older
 *           map only: on the building map, removing deletes the photos)
 *
 * None of these adds photos or changes a route. Every change is worked out
 * under the map lock from the map as it is now (update_graph()).
 *
 * The public enrollment guide links rooms by name, so a rename is written
 * into it too, and a room it links to cannot be removed.
 */

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/graph-lib.php';
require __DIR__ . '/graph-write.php';
require __DIR__ . '/walk-lib.php';

$input  = admin_boot('room.edit', 'POST');
$action = (string) ($input['action'] ?? '');
$name   = (string) ($input['name'] ?? '');

/** Where a room is in the list, or a 404 the page can explain. */
function room_index(array $graph, string $name): int
{
    foreach ($graph['rooms'] as $i => $room) {
        if ($room['room_name'] === $name) {
            return $i;
        }
    }

    json_fail(404, 'That room is no longer on the map. The list may be out of date, so please refresh.');
}

/** A typed room name, stored the way every room name is (canonical_room_name()). */
function typed_name(array $input): string
{
    $name = canonical_room_name((string) ($input['newName'] ?? ''));
    if ($name === '' || mb_strlen($name) > 100) {
        json_fail(400, 'Enter a room name of up to 100 characters.');
    }

    return $name;
}

switch ($action) {
    case 'rename':
        $newName = typed_name($input);
        $floor   = trim((string) ($input['floor'] ?? ''));
        $moved   = false;

        // validate_graph() refuses a duplicate name, which is what stops a
        // second room becoming unreachable.
        $snapshot = update_graph(static function (array $graph) use ($name, $newName, $floor, &$moved): array {
            $i = room_index($graph, $name);

            if (!map_has_buildings($graph) && $floor !== '' && $floor !== ($graph['rooms'][$i]['floor'] ?? '')) {
                $graph['rooms'][$i]['floor'] = $floor;
                $moved = true;
            }
            $graph['rooms'][$i]['room_name'] = $newName;

            return relabel_room($graph, $graph['rooms'][$i]['node_id'], $newName);
        });

        $what = [];
        if ($newName !== $name) {
            $what[] = "renamed \"{$name}\" to \"{$newName}\"";
        }
        if ($moved) {
            $what[] = "moved it to {$floor}";
        }

        $guideOk = $newName === $name || enrollment_rename($name, $newName);

        audit_log('room.rename', 'room', $newName,
            $what ? ucfirst(implode(' and ', $what)) . '.' : 'Saved without changing anything.');

        json_ok([
            'message'  => !$what ? 'Nothing was changed.' : ($guideOk ? 'Saved.'
                : 'Saved, but the enrollment guide still uses the old name. Ask the developer to update '
                    . 'assets/enrollment/enrollment-steps.json.'),
            'snapshot' => $snapshot,
        ]);

    case 'swap':
        $other = (string) ($input['otherName'] ?? '');
        if ($other === '' || $other === $name) {
            json_fail(400, 'Choose a different room to swap with.');
        }

        $snapshot = update_graph(static function (array $graph) use ($name, $other): array {
            $a = room_index($graph, $name);
            $b = room_index($graph, $other);

            // Each name moves to the other room's door; the photos stay put.
            $graph['rooms'][$a]['room_name'] = $other;
            $graph['rooms'][$b]['room_name'] = $name;
            $graph = relabel_room($graph, $graph['rooms'][$a]['node_id'], $other);

            return relabel_room($graph, $graph['rooms'][$b]['node_id'], $name);
        });

        audit_log('room.swap', 'room', $name,
            "Swapped \"{$name}\" and \"{$other}\": each name now leads to the other's door.");

        json_ok([
            'message'  => "\"{$name}\" and \"{$other}\" have swapped places.",
            'snapshot' => $snapshot,
        ]);

    case 'remove':
        if (in_array($name, enrollment_room_names(), true)) {
            json_fail(409, "\"{$name}\" is a step in the enrollment guide, so removing it would break that guide. "
                . 'Rename it, or swap it with another room, instead.');
        }

        $dropped  = [];
        $snapshot = update_graph(static function (array $graph) use ($name, &$dropped): array {
            $i    = room_index($graph, $name);
            $room = $graph['rooms'][$i];
            array_splice($graph['rooms'], $i, 1);

            // On the building map a room's walk is its own, so it goes with
            // the room. On the older map hallway photos are shared, so the
            // photos stay and only the room leaves the list.
            if (map_has_buildings($graph)) {
                $byId    = array_column($graph['nodes'], null, 'node_id');
                $dropped = array_map(static fn ($id) => $byId[$id], room_own_nodes($graph, $room['node_id']));
                $graph   = drop_nodes($graph, array_column($dropped, 'node_id'));
            }

            return $graph;
        });

        delete_photo_files(dirname(GRAPH_PATH), $dropped);

        audit_log('room.remove', 'room', $name, $dropped
            ? "Removed \"{$name}\" and deleted its " . count($dropped) . ' photo(s).'
            : "Took \"{$name}\" off the visitor's room list. Its photos were kept.");

        json_ok([
            'message'  => $dropped
                ? "\"{$name}\" has been removed, with its " . count($dropped) . ' photo(s).'
                : "\"{$name}\" is no longer offered to visitors.",
            'snapshot' => $snapshot,
        ]);

    case 'relist':
        // Works from a photo id rather than a room name: the room is not on
        // the list, which is the whole point.
        $nodeId  = (string) ($input['nodeId'] ?? '');
        $newName = typed_name($input);
        $floor   = trim((string) ($input['floor'] ?? ''));
        if ($floor === '') {
            json_fail(400, 'Choose which floor this room is on.');
        }

        $snapshot = update_graph(static function (array $graph) use ($nodeId, $newName, $floor): array {
            $isRoomPhoto = false;
            foreach ($graph['nodes'] as $n) {
                if ($n['node_id'] === $nodeId && ($n['type'] ?? '') === 'room') {
                    $isRoomPhoto = true;
                    break;
                }
            }
            if (!$isRoomPhoto) {
                json_fail(404, 'There is no room photo with that id.');
            }
            if (in_array($nodeId, array_column($graph['rooms'], 'node_id'), true)) {
                json_fail(400, 'That room is already on the list.');
            }

            $graph['rooms'][] = ['room_name' => $newName, 'floor' => $floor, 'node_id' => $nodeId];

            return relabel_room($graph, $nodeId, $newName);
        });

        audit_log('room.relist', 'room', $newName, "Put \"{$newName}\" back on the visitor's room list.");

        json_ok([
            'message'  => '"' . $newName . '" is offered to visitors again.',
            'snapshot' => $snapshot,
        ]);

    default:
        json_fail(400, 'That action is not recognised.');
}
