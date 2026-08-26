<?php
session_start();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$config = require __DIR__ . '/config.php';

// Accept either a plain passphrase or a bcrypt hash in config.php.
// Anything starting with $2y$/$2a$/$2b$ is treated as a hash, everything else as plain text.
$secret = trim((string)($config['guard_passphrase_hash'] ?? ''));
if ($secret === '' || $secret === 'PASTE-BCRYPT-HASH-HERE') {
    $secret = trim((string)($config['guard_passphrase'] ?? ''));
}

$unset = ['', 'change-me', 'PASTE-BCRYPT-HASH-HERE'];
if (in_array($secret, $unset, true)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Guard passphrase is not configured']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$passphrase = is_array($input) ? (string)($input['passphrase'] ?? '') : '';

$isHash = (bool)preg_match('/^\$2[aby]\$\d{2}\$/', $secret);
$ok = $passphrase !== ''
    && ($isHash ? password_verify($passphrase, $secret) : hash_equals($secret, $passphrase));

if (!$ok) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Incorrect passphrase']);
    exit;
}

session_regenerate_id(true);
$_SESSION['guard_authenticated'] = true;
echo json_encode(['ok' => true]);
