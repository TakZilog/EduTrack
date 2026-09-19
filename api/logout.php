<?php
/**
 * Ends the current session for whoever holds it.
 *
 * Without this a session stayed valid until PHP expired it, which on a shared
 * machine left the next person signed in as the last one.
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
