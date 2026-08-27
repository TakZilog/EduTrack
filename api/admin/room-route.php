<?php

/**
 * The walk to one room, step by step.
 *
 * This is the screen that answers "which photo is which part of the building".
 * Nobody has to memorise HALL-07 or JUNC-12: each step is shown as a picture,
 * in the order a visitor walks it, and every corridor is described by where it
 * leads rather than by the folder it happened to come from.
 */

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/graph-lib.php';

$input = admin_boot('room.view');

$name  = (string) ($input['name'] ?? '');
$graph = load_graph();

$room = null;
foreach ($graph['rooms'] as $r) {
    if ($r['room_name'] === $name) {
        $room = $r;
        break;
    }
}

if ($room === null) {
    json_fail(404, 'That room is not on the map.');
}

/* The gate, and the links in both directions. */
$adjacency = [];
foreach ($graph['edges'] as $edge) {
    $adjacency[$edge['from_node']][] = $edge['to_node'];
    $adjacency[$edge['to_node']][]   = $edge['from_node'];
}

$start = null;
foreach ($graph['nodes'] as $node) {
    if (($node['type'] ?? '') === 'landmark') {
        $start = $node['node_id'];
        break;
    }
}

/** The same breadth-first walk the visitor's walkthrough performs. */
function walk_to(array $adjacency, ?string $start, string $target): ?array
{
    if ($start === null) {
        return null;
    }
    if ($start === $target) {
        return [$start];
    }

    $seen  = [$start => true];
    $queue = [[$start]];

    while ($queue) {
        $path = array_shift($queue);
        foreach ($adjacency[end($path)] ?? [] as $next) {
            if ($next === $target) {
                return [...$path, $next];
            }
            if (!isset($seen[$next])) {
                $seen[$next] = true;
                $queue[]     = [...$path, $next];
            }
        }
    }

    return null;
}

$path = walk_to($adjacency, $start, $room['node_id']);

if ($path === null) {
    json_ok([
        'room'      => $room,
        'reachable' => false,
        'steps'     => [],
        'why'       => 'There is no way to walk here from the gate. The photos for this room are not '
            . 'linked to any corridor, which usually means the walk was recorded with too few photos '
            . 'between the gate and the door.',
    ]);
}

/*
  Which rooms each corridor leads to. This is what makes a hallway photo
  identifiable: "on the way to 214, 215, Auditorium, Library" is something a
  person recognises, where "HALL-07" is not. Computed from the map, so it
  stays true when the map changes and nobody has to maintain it.
*/
$leadsTo = [];
foreach ($graph['rooms'] as $r) {
    $route = walk_to($adjacency, $start, $r['node_id']);
    if ($route === null) {
        continue;
    }
    // Every step except the door itself is "on the way to" this room.
    foreach (array_slice($route, 0, -1) as $nodeId) {
        $leadsTo[$nodeId][] = $r['room_name'];
    }
}

$byId = [];
foreach ($graph['nodes'] as $node) {
    $byId[$node['node_id']] = $node;
}

/*
  What each room photo is really called.

  A node's `label` is the folder that first produced it during the original
  build, so a photo that is now ROOM-105 can still be labelled "101". The room
  list is the authority, and the id is a better fallback than the label.
*/
$roomNameOf = [];
foreach ($graph['rooms'] as $r) {
    $roomNameOf[$r['node_id']] = $r['room_name'];
}
$nameForRoomNode = static function (string $nodeId) use ($roomNameOf): string {
    if (isset($roomNameOf[$nodeId])) {
        return $roomNameOf[$nodeId];
    }
    return str_replace('-', ' ', preg_replace('/^ROOM-/', '', $nodeId) ?? $nodeId);
};

$steps = [];
foreach ($path as $i => $nodeId) {
    $node   = $byId[$nodeId] ?? null;
    $isLast = $i === count($path) - 1;
    $serves = $leadsTo[$nodeId] ?? [];
    sort($serves);

    $steps[] = [
        'position'  => $i + 1,
        'nodeId'    => $nodeId,
        'type'      => $node['type'] ?? 'unknown',
        // What to call this photo, in words, without anyone memorising ids.
        // A room node that is not the last step is somewhere you walk past on
        // the way, so it is named, not called a corridor.
        'title'     => match (true) {
            $isLast                              => $room['room_name'],
            ($node['type'] ?? '') === 'landmark' => 'The gate',
            ($node['type'] ?? '') === 'room'     => 'Past ' . $nameForRoomNode($nodeId),
            ($node['type'] ?? '') === 'junction' => 'A turning',
            default                              => 'Corridor',
        },
        'leadsTo'   => $serves,
        'shared'    => count($serves),
        'turnings'  => count(array_unique($adjacency[$nodeId] ?? [])),
        'isLast'    => $isLast,
    ];
}

json_ok([
    'room'      => $room,
    'reachable' => true,
    'steps'     => $steps,
    'total'     => count($steps),
]);
