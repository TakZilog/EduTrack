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
require __DIR__ . "/lowres.php";

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

// A versioned URL (?v=map timestamp) means this exact photo can never change
// under this URL: a replaced photo bumps the map and so gets a new URL. The
// browser may then keep it for a year and the walk costs no network on the way
// back. Kept private, so only the visitor's own browser caches it and never a
// shared proxy that would hand it out without the tour gate.
$immutable = ($_GET["v"] ?? "") !== "";

// Low-internet mode. A full panorama is 4096px at quality 80, around 450 KB;
// the low copy (lowres.php) is half the width at quality 55, around 60 KB. The
// copies are built ahead of time, so this is normally just a file read. If one
// cannot be made (GD off, an odd source), the full photo is served instead, so
// the walk never breaks.
if (($_GET["q"] ?? "") === "low") {
    $low = low_res_path($path, $f);
    if ($low !== null) {
        serve_photo($low, "image/webp", $immutable);
    }
}

$types = ["webp" => "image/webp", "jpg" => "image/jpeg", "jpeg" => "image/jpeg", "png" => "image/png"];
serve_photo($path, $types[strtolower(pathinfo($f, PATHINFO_EXTENSION))], $immutable);

/**
 * Sends one photo. With a versioned URL the copy is immutable and cached for a
 * year; without one it falls back to the revalidated response, which asks the
 * server whether the file changed on every view.
 */
function serve_photo(string $path, string $type, bool $immutable): never
{
    if ($immutable) {
        header("Cache-Control: private, max-age=31536000, immutable");
        header("Content-Type: {$type}");
        header("Content-Length: " . filesize($path));
        readfile($path);
        exit;
    }
    send_revalidated_file($path, $type);
}
