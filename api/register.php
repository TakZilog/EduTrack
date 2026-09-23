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

// Enrolment details. Formats vary by intake, so this accepts letters, digits
// and dashes; checking them against the registrar's records is left to staff.
$studentNo   = strtoupper(trim((string) ($input['studentNo'] ?? '')));
$studyLoadNo = strtoupper(trim((string) ($input['studyLoadNo'] ?? '')));
if (!preg_match('/^[A-Z0-9-]{4,20}$/', $studentNo)) {
    json_fail(400, 'Enter your student ID number exactly as it appears on your school ID.');
}
if (!preg_match('/^[A-Z0-9-]{3,30}$/', $studyLoadNo)) {
    json_fail(400, 'Enter the number printed on your study load.');
}

// Registration is throttled per address so one machine cannot mass-create
// accounts or use this form to send verification mail to strangers. Every
// attempt counts: rate_limit_check() only counts failures, and this endpoint
// has none to record, so it never tripped.
rate_limit_action(client_ip(), 'register', 10, 15, 'Too many registration attempts. Wait a few minutes and try again.');

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

    // One account per enrolled student.
    $stmt = $pdo->prepare('SELECT id FROM users WHERE student_no = ?');
    $stmt->execute([$studentNo]);
    if ($stmt->fetch()) {
        $pdo->rollBack();
        json_fail(400, 'That student ID number already has an account. Log in instead.');
    }

    $passwordHash = hash_password($password);
    $stmt = $pdo->prepare('INSERT INTO users (full_name, email, student_no, study_load_no, password_hash, email_verified) VALUES (?, ?, ?, ?, ?, 0)');
    $stmt->execute([$fullName, $email, $studentNo, $studyLoadNo, $passwordHash]);
    $userId = (int) $pdo->lastInsertId();


    // The verification email is sent before the commit on purpose. Sending it
    // afterwards meant a mail failure left the student stranded with an account
    // they could never verify. Holding the transaction open across the SMTP
    // round trip costs a little concurrency, which at this volume is worth it.
    send_otp_email($email, $code);

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    json_fail(500, 'Could not send your verification email. Your code is still valid, so try again.');
}

$_SESSION['otp'] = [
    'email'      => $email,
    'code'       => $code,
    'expires_at' => time() + 600,
    'attempts'   => 0,
];

json_ok();
