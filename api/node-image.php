<?php
/**
 * One 360 photo from assets/nodes. The folder itself is closed to direct
 * requests (assets/nodes/.htaccess).
 *
 *   ?f=HALL-01.webp   a photo. Signed-in enrolled students get any of them;
 *                     anyone else only the photos on a walk from the gate to a
 *                     room the public enrollment map names.
 *   ?room=CASHIER     the door photo of one of those enrollment rooms, for
 *                     anyone. Visitors enrolling have no account yet and need
 *                     to recognise the office; the rest of the building stays
 *                     behind the tour gate.
 */
declare(strict_types=1);
require __DIR__ . "/tour-gate.php";

if (isset($_GET["room"])) {
    $route = enrollment_route((string) $_GET["room"]);
    if ($route === null) { http_response_code(404); exit; }
    $target = $route["rooms"][0]["node_id"];
    $f = "";
    foreach ($route["nodes"] as $n) {
        if ($n["node_id"] === $target) { $f = (string) $n["image_file"]; }
    }
} else {
    $f = (string) ($_GET["f"] ?? "");
    if (!isset(enrollment_route_images()[$f])) {
        require_tour_access();
    }
}

// Plain file names only: no paths, no traversal.
if (!preg_match("/^[A-Za-z0-9._-]+\.(webp|jpg|jpeg|png)$/", $f)) { http_response_code(404); exit; }
$path = dirname(TOUR_GRAPH_PATH) . "/" . $f;
if (!is_file($path)) { http_response_code(404); exit; }
$types = ["webp" => "image/webp", "jpg" => "image/jpeg", "jpeg" => "image/jpeg", "png" => "image/png"];
send_revalidated_file($path, $types[strtolower(pathinfo($f, PATHINFO_EXTENSION))]);
