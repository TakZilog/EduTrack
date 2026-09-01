<?php

/**
 * Clears a sign-in lock-out.
 *
 * After too many wrong passwords, an account and the computer it was tried
 * from are both blocked for a while. That is deliberate, but it also blocks
 * the person who simply mistyped, and the only alternative was to sit and
 * wait. This clears it immediately.
 *
 *     php tools/unlock-login.php                 show who is locked out
 *     php tools/unlock-login.php --all           clear every lock-out
 *     php tools/unlock-login.php --who=headadmin clear one account or address
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This tool runs from the command line only.\n");
}

require_once dirname(__DIR__) . '/api/db.php';

try {
    $pdo = get_db();
} catch (DatabaseUnavailableException $e) {
    exit('Cannot reach the database. ' . $e->hint . PHP_EOL);
}

/** @return string|null the --who= value, if given */
function flag_who(): ?string
{
    foreach ($GLOBALS['argv'] as $arg) {
        if (str_starts_with($arg, '--who=')) {
            return substr($arg, 6);
        }
    }
    return null;
}

$all = in_array('--all', $GLOBALS['argv'], true);
$who = flag_who();

$locked = $pdo->query(
    "SELECT identifier, scope, COUNT(*) AS fails,
            TIMESTAMPDIFF(MINUTE, NOW(), DATE_ADD(MAX(attempted_at), INTERVAL 15 MINUTE)) AS minutes_left
       FROM login_attempts
      WHERE successful = 0
        AND attempted_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)
      GROUP BY identifier, scope
      HAVING fails >= 5"
)->fetchAll();

if (!$all && $who === null) {
    echo PHP_EOL;
    if (!$locked) {
        echo "Nobody is locked out." . PHP_EOL . PHP_EOL;
        exit(0);
    }

    echo 'Locked out right now:' . PHP_EOL;
    foreach ($locked as $row) {
        printf(
            "  %-24s %-18s %d wrong tries, %d minute(s) left%s",
            $row['identifier'],
            $row['scope'],
            $row['fails'],
            max(0, (int) $row['minutes_left']),
            PHP_EOL
        );
    }
    echo PHP_EOL . 'Clear with:  php tools/unlock-login.php --all' . PHP_EOL;
    echo '        or:  php tools/unlock-login.php --who=' . $locked[0]['identifier'] . PHP_EOL . PHP_EOL;
    exit(0);
}

if ($all) {
    $count = $pdo->exec('DELETE FROM login_attempts WHERE successful = 0');
    echo PHP_EOL . "Cleared {$count} failed attempt(s). Everyone can sign in again." . PHP_EOL . PHP_EOL;
    exit(0);
}

$stmt = $pdo->prepare('DELETE FROM login_attempts WHERE identifier = ? AND successful = 0');
$stmt->execute([$who]);

echo PHP_EOL . "Cleared {$stmt->rowCount()} failed attempt(s) for '{$who}'." . PHP_EOL . PHP_EOL;
