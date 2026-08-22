<?php
session_start();
header('Content-Type: application/json');

require __DIR__ . '/mail.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$otp = $_SESSION['otp'] ?? null;
if (!$otp) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'No pending verification for this session']);
    exit;
}

$code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

try {
    send_otp_email($otp['email'], $code);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Failed to resend email']);
    exit;
}

$_SESSION['otp'] = [
    'email'      => $otp['email'],
    'code'       => $code,
    'expires_at' => time() + 600,
];

echo json_encode(['ok' => true]);
