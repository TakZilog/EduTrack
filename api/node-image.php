<?php
/**
 * One 360 photo from assets/nodes, for signed-in enrolled students only.
 * The folder itself is closed to direct requests (assets/nodes/.htaccess).
 */
declare(strict_types=1);
require __DIR__ . "/tour-gate.php";
require_tour_access();
$f = (string) ($_GET["f"] ?? "");
// Plain file names only: no paths, no traversal.
if (!preg_match("/^[A-Za-z0-9._-]+\.(webp|jpg|jpeg|png)$/", $f)) { http_response_code(404); exit; }
$path = __DIR__ . "/../assets/nodes/" . $f;
if (!is_file($path)) { http_response_code(404); exit; }
$types = ["webp" => "image/webp", "jpg" => "image/jpeg", "jpeg" => "image/jpeg", "png" => "image/png"];
header("Content-Type: " . $types[strtolower(pathinfo($f, PATHINFO_EXTENSION))]);
header("Cache-Control: private, max-age=86400");
header("Content-Length: " . filesize($path));
readfile($path);
