<?php

/**
 * Reads the campus map file and checks it for problems.
 *
 * The map lives in assets/nodes/nodes-edges.json rather than the database.
 * The panel reads and reports on it; it does not write to it.
 */

declare(strict_types=1);

const GRAPH_PATH = __DIR__ . '/../../assets/nodes/nodes-edges.json';

function load_graph(): array
{
    if (!is_readable(GRAPH_PATH)) {
        json_fail(503, 'The campus map file could not be read. Check assets/nodes/nodes-edges.json.');
    }

    $graph = json_decode((string) file_get_contents(GRAPH_PATH), true);

    if (!is_array($graph) || !isset($graph['nodes'], $graph['edges'], $graph['rooms'])) {
        json_fail(503, 'The campus map file is damaged. It should contain nodes, edges and rooms.');
    }

    return $graph;
}

/** Every node that can be walked to from the gate. */
function reachable_nodes(array $graph): array
{
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
    if ($start === null) {
        return [];
    }

    $seen  = [$start => true];
    $queue = [$start];

    while ($queue) {
        foreach ($adjacency[array_shift($queue)] ?? [] as $next) {
            if (!isset($seen[$next])) {
                $seen[$next] = true;
                $queue[]     = $next;
            }
        }
    }

    return $seen;
}

/** Steps from the gate to a node, or null when there is no way through. */
function route_length(array $graph, string $target): ?int
{
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
    if ($start === null) {
        return null;
    }
    if ($start === $target) {
        return 1;
    }

    $seen  = [$start => true];
    $queue = [[$start, 1]];

    while ($queue) {
        [$node, $depth] = array_shift($queue);
        foreach ($adjacency[$node] ?? [] as $next) {
            if ($next === $target) {
                return $depth + 1;
            }
            if (!isset($seen[$next])) {
                $seen[$next] = true;
                $queue[]     = [$next, $depth + 1];
            }
        }
    }

    return null;
}

/**
 * Problems worth showing an operator, in plain language.
 *
 * Each entry says what is wrong and what a visitor experiences because of it,
 * rather than naming the data structure that is broken.
 */
function graph_health(array $graph): array
{
    $problems = [];
    $reachable = reachable_nodes($graph);

    $stranded = [];
    foreach ($graph['rooms'] as $room) {
        if (!isset($reachable[$room['node_id']])) {
            $stranded[] = $room['room_name'];
        }
    }
    if ($stranded) {
        $problems[] = [
            'severity' => 'warning',
            'title'    => count($stranded) === 1
                ? 'One room cannot be reached from the gate'
                : count($stranded) . ' rooms cannot be reached from the gate',
            'detail'   => 'A visitor can pick ' . implode(', ', $stranded)
                . ' from the list, but the walkthrough stops and says no route was found. '
                . 'This usually means the room needs more photos linking it back to a hallway.',
            'items'    => $stranded,
        ];
    }

    $names      = array_column($graph['rooms'], 'room_name');
    $duplicates = array_keys(array_filter(array_count_values($names), static fn ($n) => $n > 1));
    if ($duplicates) {
        $problems[] = [
            'severity' => 'warning',
            'title'    => 'Two rooms share the same name',
            'detail'   => implode(', ', $duplicates)
                . ' appears more than once. The walkthrough finds rooms by name, so only the first one '
                . 'can ever be reached. Anyone choosing the other is quietly sent to the wrong floor.',
            'items'    => $duplicates,
        ];
    }

    $nodeIds = array_column($graph['nodes'], 'node_id');
    $orphans = [];
    foreach ($graph['rooms'] as $room) {
        if (!in_array($room['node_id'], $nodeIds, true)) {
            $orphans[] = $room['room_name'];
        }
    }
    if ($orphans) {
        $problems[] = [
            'severity' => 'error',
            'title'    => 'Some rooms point at photos that do not exist',
            'detail'   => implode(', ', $orphans) . ' cannot open at all.',
            'items'    => $orphans,
        ];
    }

    $missingImages = [];
    $imageDir      = dirname(GRAPH_PATH);
    foreach ($graph['nodes'] as $node) {
        if (!is_file($imageDir . '/' . $node['image_file'])) {
            $missingImages[] = $node['image_file'];
        }
    }
    if ($missingImages) {
        $problems[] = [
            'severity' => 'error',
            'title'    => count($missingImages) . ' photo file(s) are missing',
            'detail'   => 'The walkthrough will show a blank screen at these steps: '
                . implode(', ', array_slice($missingImages, 0, 8))
                . (count($missingImages) > 8 ? ' and more' : '') . '.',
            'items'    => $missingImages,
        ];
    }

    return $problems;
}
