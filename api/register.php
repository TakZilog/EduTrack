<?php

declare(strict_types=1);

require __DIR__ . '/db.php';
require __DIR__ . '/mail.php';
require __DIR__ . '/session.php';
require __DIR__ . '/rate-limit.php';

app_session_start();
security_headers();
require_post();
csrf_check();

$input = json_input();

// Collapse runs of whitespace so "Maria   Santos" and "Maria Santos" are the
// same name in the database and in the admin panel.
$fullName    = trim(preg_replace('/\s+/u', ' ', (string) ($input['fullName'] ?? '')) ?? '');
$email       = filter_var(trim((string) ($input['email'] ?? '')), FILTER_VALIDATE_EMAIL);
$password    = (string) ($input['password'] ?? '');

// Letters, spaces, hyphens, apostrophes and periods, starting with a letter.
// Deliberately permissive about accents and particles: "María Ángela Dela
// Cruz-Santos" and "O'Brien Jr." are both real names and both must pass.
if (mb_strlen($fullName) < 2 || mb_strlen($fullName) > 100
    || !preg_match("/^\p{L}[\p{L}\p{M}\s.'\x{2019}-]*$/u", $fullName)) {
    json_fail(400, 'Enter your full name as it appears on your school records.');
}
if (!$email) {
    json_fail(400, 'Enter a valid email address.');
}
if (strlen($password) < 8) {
    json_fail(400, 'Passwords need at least 8 characters.');
}

// Guessing registration codes is the way into an account without visiting the
// guard, so wrong codes are counted per address.
$ip = client_ip();
rate_limit_check($ip, 'register', 10, 15, 'Too many registration attempts. Wait %d minutes and try again.');

$pdo  = get_db();
$code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

try {
    $pdo->beginTransaction();



    // Only the email is checked for collisions. Two students sharing a name is
    // normal and must not block the second one from registering.
    $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        $pdo->rollBack();
        json_fail(400, 'That email is already registered. Log in instead.');
    }

    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $pdo->prepare('INSERT INTO users (full_name, email, password_hash, email_verified) VALUES (?, ?, ?, 0)');
    $stmt->execute([$fullName, $email, $passwordHash]);
    $userId = (int) $pdo->lastInsertId();


    // The verification email is sent before the commit on purpose. Sending it
    // afterwards meant a mail failure left the code spent and the student
    // stranded with an account they could never verify, needing a second trip
    // to the guard. Holding the row lock across the SMTP round trip costs a
    // little concurrency, which at this volume is a trade worth making.
    send_otp_email($email, $code);

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    json_fail(500, 'Could not send your verification email. Your code is still valid, so try again.');
}

rate_limit_record($ip, 'register', true);

$_SESSION['otp'] = [
    'email'      => $email,
    'code'       => $code,
    'expires_at' => time() + 600,
    'attempts'   => 0,
];

json_ok();
