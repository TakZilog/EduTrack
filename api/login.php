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
$stmt = $pdo->prepare('SELECT id, full_name, email, password_hash, email_verified, deactivated_at FROM users WHERE email = ?');
$stmt->execute([$email]);
$user = $stmt->fetch();

if (!$user || !password_verify($password, $user['password_hash'])) {
    rate_limit_record($email, 'student_login', false);
    rate_limit_record($ip, 'student_login_ip', false);
    json_fail(401, 'Incorrect email or password');
}

/*
  Deactivation has to be enforced here or it means nothing. The admin panel
  tells whoever turns an account off that the student "can no longer sign in"
  (api/admin/student-action.php), but until now nothing in the login path ever
  read this column: the password kept working and the promise was false.

  Checked before email_verified because it is the stronger state — a turned-off
  account should not be told to go and verify its email.
*/
if ($user['deactivated_at'] !== null) {
    // A correct password, so this is not a failed attempt worth counting.
    json_fail(403, 'This account has been turned off. Ask the campus office to turn it back on.');
}

if (!$user['email_verified']) {
    // A correct password, so this is not a failed attempt worth counting.
    json_fail(403, 'Verify your email before logging in. Check your inbox for the code.');
}

rate_limit_record($email, 'student_login', true);
rate_limit_record($ip, 'student_login_ip', true);

/*
  The admin student list has a "last seen" column and this is the only place
  that can fill it. Nothing wrote it before, so every student read as never
  having logged in, which made the column look broken rather than empty.
  Deliberately after the password and verification checks: a failed attempt is
  not a login and must not move this date.

  Wrapped, because this is telemetry. The password was correct and the failed
  attempt counter has already been cleared above; a lock wait or a dropped
  connection on this one statement must not turn a good login into a 500. A
  missing timestamp is the acceptable failure here.
*/
try {
    $pdo->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?')->execute([$user['id']]);
} catch (PDOException $e) {
    error_log('EduTrack: could not record last_login_at for user ' . $user['id'] . ': ' . $e->getMessage());
}

session_regenerate_id(true); // closes off session fixation
$_SESSION['user_id']   = $user['id'];
$_SESSION['full_name'] = $user['full_name'];
$_SESSION['email']     = $user['email'];

json_ok(['fullName' => $user['full_name']]);
