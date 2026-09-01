<?php

/**
 * Whether a student is signed in on this browser, and who.
 *
 * The student side had no equivalent of api/admin/session.php, so a page could
 * not tell a signed-in student from a guest. map/select-room.html needs to:
 * it is reached both from Auth/login.html after signing in and from
 * guest-map.html by someone who never signed in, and it must not offer to log
 * out a person who has no session.
 *
 * A guest is not an error here. This answers 200 either way and puts the
 * answer in `signedIn`; only a genuine server fault is a failure.
 */

declare(strict_types=1);

require __DIR__ . '/db.php';
require __DIR__ . '/session.php';

app_session_start();
security_headers();

$userId = $_SESSION['user_id'] ?? null;

if (!$userId) {
    json_ok(['signedIn' => false]);
}

$stmt = get_db()->prepare('SELECT id, full_name, deactivated_at FROM users WHERE id = ?');
$stmt->execute([$userId]);
$user = $stmt->fetch();

/*
  The account may have been deleted or turned off since sign-in. api/login.php
  refuses a deactivated account at the door; this closes the other half of that
  promise, so an admin turning someone off also ends the session they already
  hold rather than waiting for it to expire.
*/
if (!$user || $user['deactivated_at'] !== null) {
    $_SESSION = [];
    session_destroy();
    json_ok(['signedIn' => false]);
}

json_ok([
    'signedIn' => true,
    'fullName' => $user['full_name'],
]);
