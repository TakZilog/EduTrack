<?php

declare(strict_types=1);

require __DIR__ . '/mail.php';
require __DIR__ . '/session.php';
require __DIR__ . '/rate-limit.php';

app_session_start();
security_headers();
require_post();
csrf_check();

$otp = $_SESSION['otp'] ?? null;
if (!$otp) {
    json_fail(400, 'No pending verification for this session');
}

// Every call sends real mail, so without a ceiling this endpoint is an
// outbound spam amplifier pointed at whoever registered.
rate_limit_action(
    session_id(),
    'resend_otp',
    3,
    15,
    'You have requested three codes recently. Wait a few minutes before asking for another.'
);

$code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

try {
    send_otp_email($otp['email'], $code);
} catch (Throwable $e) {
    json_fail(500, 'Could not send the email. Try again in a moment.');
}

$_SESSION['otp'] = [
    'email'      => $otp['email'],
    'code'       => $code,
    'expires_at' => time() + 600,
    'attempts'   => 0,
];

json_ok();
