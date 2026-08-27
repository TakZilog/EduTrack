<?php

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/graph-lib.php';

$input = admin_boot('room.view');

$graph     = load_graph();
$reachable = reachable_nodes($graph);

$search = mb_strtolower(trim((string) ($input['q'] ?? '')));
$floor  = (string) ($input['floor'] ?? '');

$rooms = [];
foreach ($graph['rooms'] as $room) {
    if ($search !== '' && !str_contains(mb_strtolower($room['room_name']), $search)) {
        continue;
    }
    if ($floor !== '' && ($room['floor'] ?? '') !== $floor) {
        continue;
    }

    $ok = isset($reachable[$room['node_id']]);

    $rooms[] = [
        'name'      => $room['room_name'],
        'floor'     => $room['floor'] ?? '',
        'nodeId'    => $room['node_id'],
        'reachable' => $ok,
        'steps'     => $ok ? route_length($graph, $room['node_id']) : null,
    ];
}

usort($rooms, static fn ($a, $b) => strnatcasecmp($a['name'], $b['name']));

$floors = array_values(array_unique(array_map(
    static fn ($r) => $r['floor'] ?? '',
    $graph['rooms']
)));
sort($floors);

$counts = array_count_values(array_map(static fn ($n) => $n['type'], $graph['nodes']));

/*
  Room photos that are not offered to visitors. These appear when a room is
  taken off the list, and would otherwise be invisible and unrecoverable: the
  photos and the route are all still there, nothing points at them any more.
*/
$listed   = array_column($graph['rooms'], 'node_id');
$unlisted = [];
foreach ($graph['nodes'] as $node) {
    if (($node['type'] ?? '') !== 'room' || in_array($node['node_id'], $listed, true)) {
        continue;
    }
    $unlisted[] = [
        'nodeId'    => $node['node_id'],
        'label'     => $node['label'],
        'reachable' => isset($reachable[$node['node_id']]),
        'steps'     => isset($reachable[$node['node_id']]) ? route_length($graph, $node['node_id']) : null,
    ];
}

json_ok([
    'rooms'    => $rooms,
    'unlisted' => $unlisted,
    'floors'   => $floors,
    'problems' => graph_health($graph),
    'summary'  => [
        'rooms'     => count($graph['rooms']),
        'photos'    => count($graph['nodes']),
        'links'     => count($graph['edges']),
        'hallways'  => $counts['hallway'] ?? 0,
        'junctions' => $counts['junction'] ?? 0,
    ],
]);
