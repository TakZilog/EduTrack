<?php
/**
 * What the enrollment guide needs to work offline: the rooms it names, the
 * photos on the walks to them, and the guide's own step pictures.
 *
 * Everything listed here is already open to everyone (see enrollment_route()
 * in tour-gate.php). This only names it, so a phone can save the guide while
 * it has signal and follow it at the gate without any.
 */
declare(strict_types=1);
require __DIR__ . "/tour-gate.php";

$images = array_keys(enrollment_route_images());
sort($images);

// The guide's own pictures (assets/enrollment), whatever has been added so far.
$photos = [];
foreach (glob(__DIR__ . "/../assets/enrollment/*") ?: [] as $file) {
    if (preg_match('/\.(jpe?g|png|webp)$/i', $file)) {
        $photos[] = "assets/enrollment/" . basename($file);
    }
}
sort($photos);

header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: no-cache");
header("X-Content-Type-Options: nosniff");
echo json_encode([
    // Same version the walkthrough appends to photo addresses (tour.php).
    "version" => (int) @filemtime(TOUR_GRAPH_PATH),
    "rooms"   => enrollment_room_names(),
    "images"  => $images,
    "photos"  => $photos,
]);
