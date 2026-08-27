<?php

declare(strict_types=1);

require __DIR__ . '/db.php';
require __DIR__ . '/session.php';
require __DIR__ . '/rate-limit.php';

app_session_start();
security_headers();
require_post();
csrf_check();

if (empty($_SESSION['guard_authenticated'])) {
    json_fail(401, 'Not logged in as guard');
}

// The five second cooldown on the guard page is client side and trivially
// bypassed, so the real ceiling lives here.
rate_limit_action(
    session_id(),
    'issue_code',
    20,
    60,
    'That is 20 codes this hour. Wait a while before issuing more.'
);

$chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // no ambiguous chars (0/O, 1/I)
$pdo   = get_db();

// Expired codes are deliberately kept. They are the record of how many codes
// were issued and never redeemed, which the admin panel reports on, and the
// retry loop below already handles a collision with one.
for ($attempt = 0; $attempt < 5; $attempt++) {
    $code = '';
    for ($i = 0; $i < 6; $i++) {
        $code .= $chars[random_int(0, strlen($chars) - 1)];
    }

    try {
        $stmt = $pdo->prepare('INSERT INTO guard_codes (code, expires_at) VALUES (?, DATE_ADD(NOW(), INTERVAL 1 HOUR))');
        $stmt->execute([$code]);
        json_ok(['code' => $code]);
    } catch (PDOException $e) {
        if ($e->getCode() !== '23000') { // not a unique-constraint collision, real error
            json_fail(500, 'Failed to generate code');
        }
        // duplicate code, loop and retry with a new random code
    }
}

json_fail(500, 'Failed to generate a unique code, try again');
