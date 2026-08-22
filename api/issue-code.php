<?php
session_start();
header('Content-Type: application/json');

require __DIR__ . '/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

if (empty($_SESSION['guard_authenticated'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Not logged in as guard']);
    exit;
}

$chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // no ambiguous chars (0/O, 1/I)
$pdo = get_db();

$pdo->exec('DELETE FROM guard_codes WHERE expires_at < NOW() AND used = 0');

for ($attempt = 0; $attempt < 5; $attempt++) {
    $code = '';
    for ($i = 0; $i < 6; $i++) {
        $code .= $chars[random_int(0, strlen($chars) - 1)];
    }

    try {
        $stmt = $pdo->prepare('INSERT INTO guard_codes (code, expires_at) VALUES (?, DATE_ADD(NOW(), INTERVAL 1 HOUR))');
        $stmt->execute([$code]);
        echo json_encode(['ok' => true, 'code' => $code]);
        exit;
    } catch (PDOException $e) {
        if ($e->getCode() !== '23000') { // not a unique-constraint collision, real error
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'Failed to generate code']);
            exit;
        }
        // duplicate code, loop and retry with a new random code
    }
}

http_response_code(500);
echo json_encode(['ok' => false, 'error' => 'Failed to generate a unique code, try again']);
