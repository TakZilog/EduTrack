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

// Low-internet copies of the panoramas: half width, lower quality (see the
// ?q=low branch and low_res_panorama() below).
const LOW_RES_WIDTH   = 2048;
const LOW_RES_QUALITY = 55;

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

// Low-internet mode. A full panorama is 4096px at quality 80, around 450 KB;
// the low copy is half the width at quality 55, roughly 90-130 KB. On a weak
// phone signal that is the difference between a room that loads and one that
// stalls. If the smaller copy cannot be made (GD off, an odd source), the full
// photo is served instead, so the walk never breaks.
if (($_GET["q"] ?? "") === "low") {
    $low = low_res_panorama($path, $f);
    if ($low !== null) {
        send_revalidated_file($low, "image/webp");
    }
}

$types = ["webp" => "image/webp", "jpg" => "image/jpeg", "jpeg" => "image/jpeg", "png" => "image/png"];
send_revalidated_file($path, $types[strtolower(pathinfo($f, PATHINFO_EXTENSION))]);

/**
 * A cached half-width, lower-quality WebP copy of one panorama for the
 * low-internet mode, or null when one cannot be produced.
 *
 * Cached in storage/lowres and rebuilt whenever the source photo is newer, the
 * same pattern the admin thumbnails use. The name is the already-validated file
 * name, which cannot hold a path separator, so this can never write outside the
 * cache folder.
 */
function low_res_panorama(string $source, string $file): ?string
{
    if (!function_exists("imagecreatefromwebp") || !function_exists("imagewebp")) {
        return null; // GD/WebP not built in: nothing to shrink with.
    }

    $dir = __DIR__ . "/../storage/lowres";
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        return null;
    }
    $cache = $dir . "/" . $file . ".webp";

    if (is_file($cache) && filemtime($cache) >= filemtime($source)) {
        return $cache;
    }

    $info  = @getimagesize($source);
    $image = match ($info[2] ?? null) {
        IMAGETYPE_JPEG => @imagecreatefromjpeg($source),
        IMAGETYPE_PNG  => @imagecreatefrompng($source),
        IMAGETYPE_WEBP => @imagecreatefromwebp($source),
        default        => false,
    };
    if ($image === false) {
        return null;
    }
    if (!imageistruecolor($image)) {
        imagepalettetotruecolor($image); // WebP cannot be written from a palette image
    }

    $w = imagesx($image);
    $h = imagesy($image);
    if ($w > LOW_RES_WIDTH) {
        $small = imagecreatetruecolor(LOW_RES_WIDTH, (int) round(LOW_RES_WIDTH * $h / $w));
        imagecopyresampled($small, $image, 0, 0, 0, 0, imagesx($small), imagesy($small), $w, $h);
        imagedestroy($image);
        $image = $small;
    }

    // Written beside the target and moved into place, so a failure never leaves
    // a half-written file where a good one was.
    $temp = $cache . ".tmp";
    $ok   = imagewebp($image, $temp, LOW_RES_QUALITY);
    imagedestroy($image);

    if (!$ok || !@rename($temp, $cache)) {
        @unlink($temp);
        return null;
    }
    return $cache;
}
