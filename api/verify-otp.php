<?php

declare(strict_types=1);

require __DIR__ . '/db.php';
require __DIR__ . '/session.php';

app_session_start();
security_headers();
require_post();
csrf_check();

const OTP_MAX_ATTEMPTS = 5;

$code = trim((string) (json_input()['code'] ?? ''));
$otp  = $_SESSION['otp'] ?? null;

if (!$otp) {
    json_fail(400, 'No code was requested for this session');
}

if (time() > $otp['expires_at']) {
    unset($_SESSION['otp']);
    json_fail(400, 'Code expired, request a new one');
}

// A six digit code is only a million combinations, so unlimited guesses would
// let an attacker simply try them all. Five wrong answers burn the code.
$attempts = (int) ($otp['attempts'] ?? 0);
if ($attempts >= OTP_MAX_ATTEMPTS) {
    unset($_SESSION['otp']);
    json_fail(429, 'Too many incorrect codes. Request a new one.');
}

if (!hash_equals($otp['code'], $code)) {
    $_SESSION['otp']['attempts'] = $attempts + 1;
    $remaining = OTP_MAX_ATTEMPTS - ($attempts + 1);

    if ($remaining <= 0) {
        unset($_SESSION['otp']);
        json_fail(429, 'Too many incorrect codes. Request a new one.');
    }

    json_fail(400, sprintf(
        'Incorrect code. %d attempt%s left.',
        $remaining,
        $remaining === 1 ? '' : 's'
    ));
}

$pdo = get_db();

// Checked explicitly rather than through rowCount(), which on MySQL reports
// changed rows and would read zero for an account that is already verified.
$stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
$stmt->execute([$otp['email']]);

if (!$stmt->fetch()) {
    unset($_SESSION['otp']);
    json_fail(400, 'That account no longer exists. Register again.');
}

$stmt = $pdo->prepare('UPDATE users SET email_verified = 1 WHERE email = ?');
$stmt->execute([$otp['email']]);

unset($_SESSION['otp']);
json_ok();
