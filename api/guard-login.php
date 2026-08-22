<?php
session_start();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$config = require __DIR__ . '/config.php';
$input = json_decode(file_get_contents('php://input'), true);
$passphrase = $input['passphrase'] ?? '';

if (!hash_equals($config['guard_passphrase'], $passphrase)) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Incorrect passphrase']);
    exit;
}

$_SESSION['guard_authenticated'] = true;
echo json_encode(['ok' => true]);
