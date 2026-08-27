<?php

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

app_session_start();
security_headers();
require_post();

// Signing out is allowed from any role, so this checks the session directly
// rather than going through a permission.
if (!empty($_SESSION['admin_id'])) {
    csrf_check();
    audit_log('admin.logout', 'admin', (string) $_SESSION['admin_id'], 'Signed out.');
}

$_SESSION = [];

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
