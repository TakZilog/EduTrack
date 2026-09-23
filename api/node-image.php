<?php
/**
 * One 360 photo from assets/nodes. The folder itself is closed to direct
 * requests (assets/nodes/.htaccess).
 *
 *   ?f=HALL-01.webp   any photo, for signed-in enrolled students only (the tour)
 *   ?room=CASHIER     the door photo of one room, for anyone, but only for the
 *                     rooms the public enrollment map names. Visitors enrolling
 *                     have no account yet and need to recognise the office;
 *                     the rest of the building stays behind the tour gate.
 */
declare(strict_types=1);
require __DIR__ . "/tour-gate.php";

const NODES_DIR = __DIR__ . "/../assets/nodes/";

if (isset($_GET["room"])) {
    $f = enrollment_room_photo((string) $_GET["room"]);
    if ($f === null) { http_response_code(404); exit; }
} else {
    require_tour_access();
    $f = (string) ($_GET["f"] ?? "");
}

// Plain file names only: no paths, no traversal.
if (!preg_match("/^[A-Za-z0-9._-]+\.(webp|jpg|jpeg|png)$/", $f)) { http_response_code(404); exit; }
$path = NODES_DIR . $f;
if (!is_file($path)) { http_response_code(404); exit; }
$types = ["webp" => "image/webp", "jpg" => "image/jpeg", "jpeg" => "image/jpeg", "png" => "image/png"];
header("Content-Type: " . $types[strtolower(pathinfo($f, PATHINFO_EXTENSION))]);
header("Cache-Control: private, max-age=86400");
header("Content-Length: " . filesize($path));
readfile($path);

/** The door photo file for a room the enrollment map names, else null. */
function enrollment_room_photo(string $room): ?string
{
    $steps = json_decode((string) @file_get_contents(__DIR__ . "/../assets/enrollment/enrollment-steps.json"), true);
    $graph = json_decode((string) @file_get_contents(NODES_DIR . "nodes-edges.json"), true);
    if (!is_array($steps) || !is_array($graph)) { return null; }

    $named = [];
    array_walk_recursive($steps, static function ($v, $k) use (&$named) {
        if ($k === "room_name") { $named[(string) $v] = true; }
    });
    if (!isset($named[$room])) { return null; }

    foreach ($graph["rooms"] ?? [] as $r) {
        if ((string) $r["room_name"] !== $room) { continue; }
        foreach ($graph["nodes"] ?? [] as $n) {
            if ($n["node_id"] === $r["node_id"]) { return (string) $n["image_file"]; }
        }
    }
    return null;
}
