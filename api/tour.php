<?php
/**
 * The building graph for the walkthrough and room list. Students only.
 *
 * ?room=NAME for a room the public enrollment map names also answers a guest,
 * with just the walk from the gate to that room (see enrollment_route()).
 */
declare(strict_types=1);
require __DIR__ . "/tour-gate.php";

$room = isset($_GET["room"]) ? (string) $_GET["room"] : null;

// A version for the photos: the map file's timestamp. The walkthrough page
// appends it to every image URL, so a replaced photo (which keeps its file
// name) gets a fresh URL and the browser can otherwise cache photos for good.
header("X-Map-Version: " . (int) @filemtime(TOUR_GRAPH_PATH));

if ($room !== null && tour_access_refusal() !== null) {
    $route = enrollment_route($room);
    if ($route !== null) {
        header("Content-Type: application/json; charset=utf-8");
        header("Cache-Control: private, no-store");
        echo json_encode($route);
        exit;
    }
}

require_tour_access();
header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: private, no-store");
readfile(TOUR_GRAPH_PATH);
