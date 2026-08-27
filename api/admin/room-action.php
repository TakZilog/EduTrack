<?php

/**
 * Editing a room's details.
 *
 * Deliberately limited to the three things that are safe to change by typing:
 * what the room is called, which floor it is on, and whether it appears in the
 * visitor's list at all.
 *
 * It cannot add a room, move a route, or change which photos lead where. Those
 * come from walking the building with a camera, because the connections
 * between photos are worked out by comparing the images, not by hand.
 */

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/graph-lib.php';
require __DIR__ . '/graph-write.php';

$input  = admin_boot('room.edit', 'POST');
$action = (string) ($input['action'] ?? '');

$graph    = load_graph();
$original = (string) ($input['name'] ?? '');

/*
  Putting a room back on the list. Handled before the lookup below, because
  this one works from a photo id rather than a room name: the room is not on
  the list, which is the whole point.
*/
if ($action === 'relist') {
    $nodeId = (string) ($input['nodeId'] ?? '');
    $name   = trim(preg_replace('/\s+/u', ' ', (string) ($input['newName'] ?? '')) ?? '');
    $floor  = trim((string) ($input['floor'] ?? ''));

    $node = null;
    foreach ($graph['nodes'] as $n) {
        if ($n['node_id'] === $nodeId && ($n['type'] ?? '') === 'room') {
            $node = $n;
            break;
        }
    }
    if ($node === null) {
        json_fail(404, 'There is no room photo with that id.');
    }
    if (in_array($nodeId, array_column($graph['rooms'], 'node_id'), true)) {
        json_fail(400, 'That room is already on the list.');
    }
    if ($name === '' || mb_strlen($name) > 100) {
        json_fail(400, 'Enter a room name of up to 100 characters.');
    }
    if ($floor === '') {
        json_fail(400, 'Choose which floor this room is on.');
    }

    $graph['rooms'][] = ['room_name' => $name, 'floor' => $floor, 'node_id' => $nodeId];
    $snapshot = save_graph($graph);

    audit_log('room.relist', 'room', $name, "Put \"{$name}\" back on the visitor's room list.");

    json_ok([
        'message'  => '"' . $name . '" is offered to visitors again.',
        'snapshot' => $snapshot,
    ]);
}

$index = null;
foreach ($graph['rooms'] as $i => $room) {
    if ($room['room_name'] === $original) {
        $index = $i;
        break;
    }
}

if ($index === null) {
    json_fail(404, 'That room is no longer on the map. The list may be out of date, so please refresh.');
}

$room = $graph['rooms'][$index];

switch ($action) {
    case 'rename':
        $name  = trim(preg_replace('/\s+/u', ' ', (string) ($input['newName'] ?? '')) ?? '');
        $floor = trim((string) ($input['floor'] ?? $room['floor'] ?? ''));

        if ($name === '' || mb_strlen($name) > 100) {
            json_fail(400, 'Enter a room name of up to 100 characters.');
        }
        if ($floor === '') {
            json_fail(400, 'Choose which floor this room is on.');
        }

        $graph['rooms'][$index]['room_name'] = $name;
        $graph['rooms'][$index]['floor']     = $floor;

        // validate_graph() catches a duplicate name and refuses the whole save,
        // which is what stops a second room becoming unreachable.
        $snapshot = save_graph($graph);

        $what = [];
        if ($name !== $original) {
            $what[] = "renamed \"{$original}\" to \"{$name}\"";
        }
        if ($floor !== ($room['floor'] ?? '')) {
            $what[] = "moved it to {$floor}";
        }

        audit_log('room.rename', 'room', $name,
            $what ? ucfirst(implode(' and ', $what)) . '.' : 'Saved without changing anything.');

        json_ok([
            'message'  => $what ? 'Saved.' : 'Nothing was changed.',
            'snapshot' => $snapshot,
        ]);

    case 'remove':
        // The photos and the route stay exactly as they are. This only takes
        // the room out of the list visitors choose from.
        array_splice($graph['rooms'], $index, 1);
        $snapshot = save_graph($graph);

        audit_log('room.remove', 'room', $original,
            "Took \"{$original}\" off the visitor's room list. Its photos were kept.");

        json_ok([
            'message'  => '"' . $original . '" is no longer offered to visitors.',
            'snapshot' => $snapshot,
        ]);

    default:
        json_fail(400, 'That action is not recognised.');
}
