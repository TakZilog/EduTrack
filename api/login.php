<?php

declare(strict_types=1);

require __DIR__ . '/db.php';
require __DIR__ . '/session.php';
require __DIR__ . '/rate-limit.php';

app_session_start();
security_headers();
require_post();
csrf_check();

$input    = json_input();
$email    = strtolower(trim((string) ($input['email'] ?? '')));
$password = (string) ($input['password'] ?? '');

if ($email === '' || $password === '') {
    json_fail(400, 'Enter both your email and password.');
}

// Email is the login identifier. Full names are not unique, so they cannot be.
$ip = client_ip();
rate_limit_check($email, 'student_login');
rate_limit_check($ip, 'student_login_ip', 20);

$pdo  = get_db();
$stmt = $pdo->prepare('SELECT id, full_name, email, password_hash, email_verified FROM users WHERE email = ?');
$stmt->execute([$email]);
$user = $stmt->fetch();

if (!$user || !password_verify($password, $user['password_hash'])) {
    rate_limit_record($email, 'student_login', false);
    rate_limit_record($ip, 'student_login_ip', false);
    json_fail(401, 'Incorrect email or password');
}

if (!$user['email_verified']) {
    // A correct password, so this is not a failed attempt worth counting.
    json_fail(403, 'Verify your email before logging in. Check your inbox for the code.');
}

rate_limit_record($email, 'student_login', true);
rate_limit_record($ip, 'student_login_ip', true);

session_regenerate_id(true); // closes off session fixation
$_SESSION['user_id']   = $user['id'];
$_SESSION['full_name'] = $user['full_name'];
$_SESSION['email']     = $user['email'];

json_ok(['fullName' => $user['full_name']]);
