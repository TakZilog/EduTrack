<?php
session_start();
header('Content-Type: application/json');

require __DIR__ . '/db.php';
require __DIR__ . '/mail.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

$username = trim($input['username'] ?? '');
$email = filter_var(trim($input['email'] ?? ''), FILTER_VALIDATE_EMAIL);
$password = $input['password'] ?? '';
$privateCode = strtoupper(trim($input['privateCode'] ?? ''));

if (strlen($username) < 4 || !$email || strlen($password) < 8 || $privateCode === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid input']);
    exit;
}

$pdo = get_db();

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare('SELECT id, code FROM guard_codes WHERE code = ? AND used = 0 AND expires_at > NOW() FOR UPDATE');
    $stmt->execute([$privateCode]);
    $guardCode = $stmt->fetch();

    if (!$guardCode) {
        $pdo->rollBack();
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid or expired registration code']);
        exit;
    }

    $stmt = $pdo->prepare('SELECT id FROM users WHERE username = ? OR email = ?');
    $stmt->execute([$username, $email]);
    if ($stmt->fetch()) {
        $pdo->rollBack();
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Username or email already registered']);
        exit;
    }

    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $pdo->prepare('INSERT INTO users (username, email, password_hash, registered_with_code, email_verified) VALUES (?, ?, ?, ?, 0)');
    $stmt->execute([$username, $email, $passwordHash, $guardCode['code']]);
    $userId = (int) $pdo->lastInsertId();

    $stmt = $pdo->prepare('UPDATE guard_codes SET used = 1, used_by_user_id = ?, used_at = NOW() WHERE id = ?');
    $stmt->execute([$userId, $guardCode['id']]);

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Registration failed']);
    exit;
}

$code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

try {
    send_otp_email($email, $code);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Account created, but failed to send verification email']);
    exit;
}

$_SESSION['otp'] = [
    'email'      => $email,
    'code'       => $code,
    'expires_at' => time() + 600,
];

echo json_encode(['ok' => true]);
