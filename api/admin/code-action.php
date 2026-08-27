<?php

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

$input  = admin_boot(['code.issue', 'code.revoke'], 'POST');
$action = (string) ($input['action'] ?? '');
$pdo    = get_db();
$who    = $_SESSION['admin_name'] ?? 'admin';

switch ($action) {
    case 'issue':
        if (!can('code.issue')) {
            json_fail(403, 'Your account cannot give out codes.');
        }

        $minutes = max(5, min(1440, (int) setting('code_lifetime_minutes', '60')));
        // No 0/O or 1/I: these get read aloud and written on paper at a desk.
        $chars   = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $code = '';
            for ($i = 0; $i < 6; $i++) {
                $code .= $chars[random_int(0, strlen($chars) - 1)];
            }

            try {
                $stmt = $pdo->prepare(
                    'INSERT INTO guard_codes (code, expires_at, issued_by)
                     VALUES (?, DATE_ADD(NOW(), INTERVAL ? MINUTE), ?)'
                );
                $stmt->execute([$code, $minutes, $who]);

                audit_log('code.issue', 'code', $code, "Gave out code {$code}.");

                json_ok([
                    'code'    => $code,
                    'minutes' => $minutes,
                    'message' => 'Give this code to the student now.',
                ]);
            } catch (PDOException $e) {
                if ($e->getCode() !== '23000') {
                    json_fail(500, 'The code could not be created. Please try again.');
                }
                // Collided with an existing code, so loop and pick another.
            }
        }

        json_fail(500, 'Could not create a new code. Please try again.');

    case 'revoke':
        if (!can('code.revoke')) {
            json_fail(403, 'Your account cannot cancel codes.');
        }

        $id   = (int) ($input['id'] ?? 0);
        $stmt = $pdo->prepare('SELECT id, code, used, revoked_at FROM guard_codes WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        if (!$row) {
            json_fail(404, 'That code no longer exists.');
        }
        if ((int) $row['used'] === 1) {
            json_fail(400, 'That code has already been used, so it cannot be cancelled.');
        }
        if ($row['revoked_at'] !== null) {
            json_fail(400, 'That code was already cancelled.');
        }

        $pdo->prepare('UPDATE guard_codes SET revoked_at = NOW() WHERE id = ?')->execute([$id]);
        audit_log('code.revoke', 'code', $row['code'], "Cancelled code {$row['code']}.");

        json_ok(['message' => 'That code can no longer be used.']);

    case 'revoke-all':
        if (!can('code.revoke')) {
            json_fail(403, 'Your account cannot cancel codes.');
        }

        $stmt = $pdo->prepare(
            'UPDATE guard_codes SET revoked_at = NOW()
              WHERE used = 0 AND revoked_at IS NULL AND expires_at > NOW()'
        );
        $stmt->execute();
        $count = $stmt->rowCount();

        audit_log('code.revoke_all', 'code', null, "Cancelled all {$count} unused code(s).");

        json_ok([
            'count'   => $count,
            'message' => $count === 0
                ? 'There were no unused codes to cancel.'
                : $count . ' code(s) can no longer be used.',
        ]);

    default:
        json_fail(400, 'That action is not recognised.');
}
