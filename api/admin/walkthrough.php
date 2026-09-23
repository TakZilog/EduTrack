<?php

/**
 * Everything the Walkthrough page shows: each building and floor, the fixed
 * path from the gate to that floor's point, and the rooms reached from it.
 *
 * Pictures are not sent here; the page asks thumb.php for each one by id.
 * On the older single-building map, `structured` is false and the page says
 * the map has to be imported by building and floor first.
 */

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/graph-lib.php';
require __DIR__ . '/walk-lib.php';

admin_boot('room.view');

$graph = load_graph();

if (!map_has_buildings($graph)) {
    json_ok(['structured' => false, 'buildings' => [], 'enrollmentRooms' => enrollment_room_names()]);
}

$byId    = array_column($graph['nodes'], null, 'node_id');
$roomsAt = [];   // "MAIN|1" => rooms on that floor
foreach ($graph['rooms'] as $room) {
    $node = $byId[$room['node_id']] ?? null;
    if ($node === null || !isset($node['building'], $node['floor'])) {
        continue;
    }
    $roomsAt[$node['building'] . '|' . $node['floor']][] = [
        'name'   => $room['room_name'],
        'nodeId' => $room['node_id'],
        'photos' => count(room_own_nodes($graph, $room['node_id'])),
    ];
}

$buildings = [];
foreach ($graph['buildings'] as $building) {
    $floors = [];
    foreach ($building['floors'] as $floor) {
        $n     = (int) $floor['n'];
        $rooms = $roomsAt[$building['code'] . '|' . $n] ?? [];
        usort($rooms, static fn ($a, $b) => strnatcasecmp($a['name'], $b['name']));

        $floors[] = [
            'n'     => $n,
            'label' => strtolower(ordinal($n)) . ' Floor',   // "1st Floor"
            'path'  => fixed_path($graph, $floor['point']),
            'rooms' => $rooms,
        ];
    }
    usort($floors, static fn ($a, $b) => $a['n'] <=> $b['n']);

    $buildings[] = ['code' => $building['code'], 'name' => $building['name'], 'floors' => $floors];
}

json_ok([
    'structured'      => true,
    'buildings'       => $buildings,
    // Rooms the public enrollment guide links to by name: renaming one
    // updates the guide, and removing one is refused.
    'enrollmentRooms' => enrollment_room_names(),
]);
