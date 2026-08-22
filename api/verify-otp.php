<?php
session_start();
header('Content-Type: application/json');

require __DIR__ . '/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$code = trim($input['code'] ?? '');

$otp = $_SESSION['otp'] ?? null;

if (!$otp) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'No code was requested for this session']);
    exit;
}

if (time() > $otp['expires_at']) {
    unset($_SESSION['otp']);
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Code expired, request a new one']);
    exit;
}

if (!hash_equals($otp['code'], $code)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Incorrect code']);
    exit;
}

$pdo = get_db();
$stmt = $pdo->prepare('UPDATE users SET email_verified = 1 WHERE email = ?');
$stmt->execute([$otp['email']]);

unset($_SESSION['otp']);
echo json_encode(['ok' => true]);
