<?php

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/../rate-limit.php';

app_session_start();
security_headers();

// Checked before the password is even looked at, so a computer that is not
// allowed cannot use this endpoint to test whether an account exists.
enforce_ip_allowlist();

require_post();
csrf_check();

$input    = json_input();
$username = trim((string) ($input['username'] ?? ''));
$password = (string) ($input['password'] ?? '');

if ($username === '' || $password === '') {
    json_fail(400, 'Enter your username and password.');
}

$ip = client_ip();
rate_limit_check($username, 'admin_login', 5, 15, 'Too many tries. Please wait %d minutes and try again.');
rate_limit_check($ip, 'admin_login_ip', 20, 15, 'Too many tries from this computer. Please wait %d minutes.');

$pdo  = get_db();
$stmt = $pdo->prepare('SELECT id, username, full_name, password_hash, role, active FROM admins WHERE username = ?');
$stmt->execute([$username]);
$admin = $stmt->fetch();

if (!$admin || !password_verify($password, $admin['password_hash'])) {
    rate_limit_record($username, 'admin_login', false);
    rate_limit_record($ip, 'admin_login_ip', false);
    json_fail(401, 'That username or password is not right.');
}

if (!$admin['active']) {
    json_fail(403, 'This account has been turned off. Ask a full-access admin to turn it back on.');
}

rate_limit_record($username, 'admin_login', true);
rate_limit_record($ip, 'admin_login_ip', true);

session_regenerate_id(true);
$_SESSION['admin_id']   = (int) $admin['id'];
$_SESSION['admin_name'] = $admin['username'];
$_SESSION['admin_role'] = $admin['role'];

$pdo->prepare('UPDATE admins SET last_login_at = NOW() WHERE id = ?')->execute([$admin['id']]);

audit_log('admin.login', 'admin', (string) $admin['id'], 'Signed in.');

json_ok([
    'admin' => [
        'id'         => (int) $admin['id'],
        'username'   => $admin['username'],
        'fullName'   => $admin['full_name'],
        'role'       => $admin['role'],
        'roleLabel'  => ADMIN_ROLE_LABELS[$admin['role']] ?? $admin['role'],
    ],
]);
