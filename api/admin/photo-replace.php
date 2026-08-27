<?php

/**
 * Replaces one photo in the walkthrough.
 *
 * This is the safest change that can be made to the map: the photo at one step
 * is swapped, and nothing about the route changes. No node is created, no link
 * is moved, no room is touched. If the new picture is wrong, the old one is
 * kept and can be put straight back.
 *
 * It takes a normal file upload rather than JSON, because that is what a file
 * input sends.
 */

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/graph-lib.php';

const PHOTO_BACKUP_DIR = __DIR__ . '/../../storage/photo-backups';
const MAX_UPLOAD_BYTES = 25 * 1024 * 1024;   // a phone panorama is well under this
const PANORAMA_WIDTH   = 4096;               // matches the rest of the map

app_session_start();
security_headers();
enforce_ip_allowlist();

if (empty($_SESSION['admin_id'])) {
    json_fail(401, 'Please sign in to continue.', ['code' => 'auth']);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_fail(405, 'Method not allowed');
}
csrf_check();
if (!can('room.edit')) {
    audit_log('access.denied', 'permission', 'room.edit', 'Tried to replace a photo without permission.');
    json_fail(403, 'Your account cannot change photos.');
}

$nodeId = (string) ($_POST['node'] ?? '');

/* The node must be one the map already knows, so nothing the browser sends
   can ever decide a filename. */
$graph = load_graph();
$node  = null;
foreach ($graph['nodes'] as $n) {
    if ($n['node_id'] === $nodeId) {
        $node = $n;
        break;
    }
}
if ($node === null) {
    json_fail(404, 'That photo is not part of the map.');
}

/* --------------------------------------------------------------- the upload */

if (!isset($_FILES['photo'])) {
    json_fail(400, 'No picture was chosen.');
}

$upload = $_FILES['photo'];

if ($upload['error'] !== UPLOAD_ERR_OK) {
    json_fail(400, match ($upload['error']) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE =>
            'That picture is too large for the server to accept. Try one under 25 MB.',
        UPLOAD_ERR_PARTIAL   => 'The upload was cut off. Please try again.',
        UPLOAD_ERR_NO_FILE   => 'No picture was chosen.',
        default              => 'The picture could not be uploaded. Please try again.',
    });
}

if ($upload['size'] > MAX_UPLOAD_BYTES) {
    json_fail(400, 'That picture is larger than 25 MB. Please use a smaller one.');
}

// Judged by reading the file, never by its name or the type the browser claims.
$info = @getimagesize($upload['tmp_name']);
if ($info === false) {
    json_fail(400, 'That file is not a picture the server can read. Use a JPG, PNG or WebP.');
}

[$width, $height, $type] = $info;

if (!in_array($type, [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
    json_fail(400, 'Use a JPG, PNG or WebP picture.');
}

/*
  The viewer wraps each photo around a sphere, so it needs a 360 panorama,
  which is always twice as wide as it is tall. An ordinary phone photo would
  appear badly stretched, so it is refused with an explanation rather than
  quietly accepted.
*/
$ratio = $height > 0 ? $width / $height : 0;
if ($ratio < 1.9 || $ratio > 2.1) {
    json_fail(400, sprintf(
        'This does not look like a 360 photo. It is %d by %d, and a 360 photo is always twice '
        . 'as wide as it is tall, like 4096 by 2048. Take it with the same 360 camera or app '
        . 'used for the rest of the map.',
        $width,
        $height
    ));
}

/* ------------------------------------------------------------ keep the old */

if (!is_dir(PHOTO_BACKUP_DIR) && !mkdir(PHOTO_BACKUP_DIR, 0775, true) && !is_dir(PHOTO_BACKUP_DIR)) {
    json_fail(500, 'The server could not prepare a place to keep the old picture. Nothing was changed.');
}

$target = dirname(GRAPH_PATH) . '/' . $node['image_file'];
$backup = PHOTO_BACKUP_DIR . '/' . date('Y-m-d_His') . '_' . $node['image_file'];

if (is_file($target) && !copy($target, $backup)) {
    json_fail(500, 'The old picture could not be saved first, so nothing was changed.');
}

/* --------------------------------------------------------------- write it */

$source = match ($type) {
    IMAGETYPE_JPEG => @imagecreatefromjpeg($upload['tmp_name']),
    IMAGETYPE_PNG  => @imagecreatefrompng($upload['tmp_name']),
    IMAGETYPE_WEBP => @imagecreatefromwebp($upload['tmp_name']),
};

if ($source === false || $source === null) {
    json_fail(500, 'The picture could not be opened. Nothing was changed.');
}

// Held to the same size as every other photo, so one huge upload cannot make
// the walkthrough slow on a phone.
if ($width > PANORAMA_WIDTH) {
    $scaled = imagescale($source, PANORAMA_WIDTH, (int) round(PANORAMA_WIDTH * $height / $width));
    if ($scaled !== false) {
        imagedestroy($source);
        $source = $scaled;
    }
}

// Written beside the target then moved, so a failure halfway cannot leave a
// broken image where a working one used to be.
$temp = $target . '.tmp';
$written = imagewebp($source, $temp, 90);
imagedestroy($source);

if (!$written || !rename($temp, $target)) {
    @unlink($temp);
    json_fail(500, 'The new picture could not be saved. The old one is still in place.');
}

// The cached preview is keyed on the photo's timestamp, so it rebuilds itself.
@unlink(__DIR__ . '/../../storage/thumbs/' . $nodeId . '.webp');

audit_log('photo.replace', 'photo', $nodeId, sprintf(
    'Replaced the picture at %s. The previous one was kept as %s.',
    $nodeId,
    basename($backup)
));

json_ok([
    'message'  => 'The picture has been replaced.',
    'width'    => min($width, PANORAMA_WIDTH),
    'height'   => (int) round(min($width, PANORAMA_WIDTH) * $height / $width),
    'sizeKb'   => (int) round(filesize($target) / 1024),
    'keptAs'   => basename($backup),
]);
