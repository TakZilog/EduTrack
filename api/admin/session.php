<?php

/**
 * Who is signed in, what they may do, and the CSRF token for this page.
 *
 * Every admin page calls this first. A 401 here is what sends the browser back
 * to the sign-in screen.
 */

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

app_session_start();
security_headers();
enforce_ip_allowlist();

if (empty($_SESSION['admin_id'])) {
    json_fail(401, 'Please sign in to continue.', ['code' => 'auth']);
}

$stmt = get_db()->prepare('SELECT id, username, full_name, role, active FROM admins WHERE id = ?');
$stmt->execute([$_SESSION['admin_id']]);
$admin = $stmt->fetch();

// The account may have been turned off, or its role changed, since sign-in.
if (!$admin || !$admin['active']) {
    $_SESSION = [];
    session_destroy();
    json_fail(401, 'Your session has ended. Please sign in again.', ['code' => 'auth']);
}

$_SESSION['admin_role'] = $admin['role'];

json_ok([
    'admin' => [
        'id'        => (int) $admin['id'],
        'username'  => $admin['username'],
        'fullName'  => $admin['full_name'],
        'role'      => $admin['role'],
        'roleLabel' => ADMIN_ROLE_LABELS[$admin['role']] ?? $admin['role'],
    ],
    'permissions' => granted_permissions(),
    'csrfToken'   => csrf_token(),
]);
