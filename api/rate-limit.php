<?php
/**
 * Attempt throttling, backed by the login_attempts table.
 *
 * Counts are kept per identifier and scope so that a targeted attack on one
 * username and a spray across many both run into a limit. Callers record the
 * outcome of every attempt; a successful one clears the counter.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/session.php';

/**
 * Refuses the request when $identifier has exceeded $maxAttempts failures
 * within the trailing $windowMinutes.
 */
function rate_limit_check(
    string $identifier,
    string $scope,
    int $maxAttempts = 5,
    int $windowMinutes = 15,
    string $message = 'Too many attempts. Wait %d minutes and try again.'
): void {
    if ($identifier === '') {
        return;
    }

    $pdo = get_db();

    // The remaining lockout is computed by MySQL rather than in PHP. Reading
    // attempted_at back and comparing it against PHP's time() silently mixes
    // two clocks, and any offset between the database and the web server turns
    // a 15 minute lockout into a wildly wrong number in the message.
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) AS failures,
                TIMESTAMPDIFF(SECOND, NOW(), DATE_ADD(MAX(attempted_at), INTERVAL ? MINUTE)) AS seconds_left
           FROM login_attempts
          WHERE identifier = ?
            AND scope = ?
            AND successful = 0
            AND attempted_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)'
    );
    $stmt->execute([$windowMinutes, $identifier, $scope, $windowMinutes]);
    $row = $stmt->fetch();

    if ((int) ($row['failures'] ?? 0) < $maxAttempts) {
        return;
    }

    $waitMins = max(1, (int) ceil(((int) $row['seconds_left']) / 60));

    json_fail(429, sprintf($message, $waitMins));
}

/** Records one attempt. A success clears prior failures for that identifier. */
function rate_limit_record(string $identifier, string $scope, bool $successful): void
{
    if ($identifier === '') {
        return;
    }

    $pdo = get_db();

    if ($successful) {
        $stmt = $pdo->prepare('DELETE FROM login_attempts WHERE identifier = ? AND scope = ?');
        $stmt->execute([$identifier, $scope]);
        return;
    }

    $stmt = $pdo->prepare(
        'INSERT INTO login_attempts (identifier, scope, successful) VALUES (?, ?, 0)'
    );
    $stmt->execute([$identifier, $scope]);

    // Opportunistic cleanup so the table cannot grow without bound.
    if (random_int(1, 50) === 1) {
        $pdo->exec('DELETE FROM login_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 1 DAY)');
    }
}

/**
 * A plain counter with no success/failure semantics, for limiting how often an
 * action may be performed at all. Used for code issuance and OTP resends.
 */
function rate_limit_action(
    string $identifier,
    string $scope,
    int $maxActions,
    int $windowMinutes,
    string $message
): void {
    if ($identifier === '') {
        return;
    }

    $pdo = get_db();
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) AS n FROM login_attempts
          WHERE identifier = ? AND scope = ?
            AND attempted_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)'
    );
    $stmt->execute([$identifier, $scope, $windowMinutes]);

    if ((int) $stmt->fetch()['n'] >= $maxActions) {
        json_fail(429, $message);
    }

    $stmt = $pdo->prepare('INSERT INTO login_attempts (identifier, scope, successful) VALUES (?, ?, 1)');
    $stmt->execute([$identifier, $scope]);
}
