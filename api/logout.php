<?php
/**
 * Ends the current session for whoever holds it, student or guard.
 *
 * This matters most at the guard desk: that machine is shared and sits in a
 * public corridor, and without this endpoint a session there stayed valid
 * until PHP expired it, leaving anyone who walked up able to issue codes.
 */

declare(strict_types=1);

require __DIR__ . '/session.php';

app_session_start();
security_headers();
require_post();
csrf_check();

$_SESSION = [];

// Expire the cookie itself, not just the server-side data.
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', [
        'expires'  => time() - 42000,
        'path'     => $params['path'],
        'domain'   => $params['domain'],
        'secure'   => $params['secure'],
        'httponly' => $params['httponly'],
        'samesite' => $params['samesite'] ?? 'Lax',
    ]);
}

session_destroy();

json_ok();
