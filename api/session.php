<?php
/**
 * Central session bootstrap, CSRF, and JSON response helpers.
 *
 * Every endpoint must require this file and call app_session_start()
 * instead of calling session_start() directly. Doing it in one place is
 * what makes the cookie flags and the idle timeout impossible to forget.
 */

declare(strict_types=1);

const SESSION_IDLE_TIMEOUT = 1800; // 30 minutes

function app_session_start(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,                    // the cookie is unreadable from JavaScript
        'samesite' => 'Lax',                   // blocks cross-site form posts
        'secure'   => !empty($_SERVER['HTTPS']), // on automatically once served over TLS
    ]);

    session_start();

    // Idle timeout. Anything older than the window starts over with a fresh id,
    // which matters most for the guard desk machine, which is shared and public.
    $now = time();
    if (isset($_SESSION['last_activity']) && ($now - $_SESSION['last_activity']) > SESSION_IDLE_TIMEOUT) {
        $_SESSION = [];
        session_regenerate_id(true);
    }
    $_SESSION['last_activity'] = $now;
}

function security_headers(): void
{
    header('Content-Type: application/json');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header('Cache-Control: no-store');
}

/**
 * Ends the request with a JSON error, keeping the { ok, error } shape the
 * frontend already expects everywhere.
 */
function json_fail(int $status, string $message, array $extra = []): never
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => $message] + $extra);
    exit;
}

function json_ok(array $extra = []): never
{
    echo json_encode(['ok' => true] + $extra);
    exit;
}

function require_post(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_fail(405, 'Method not allowed');
    }
}

/** Decodes a JSON request body, always returning an array. */
function json_input(): array
{
    $decoded = json_decode(file_get_contents('php://input') ?: '', true);
    return is_array($decoded) ? $decoded : [];
}

/* ---------------------------------------------------------------------------
   CSRF, synchronizer-token pattern.

   The token lives in the session and is handed to the page by
   api/csrf-token.php. The browser echoes it back in an X-CSRF-Token header on
   every state-changing request. A cross-origin page cannot read the token, so
   it cannot forge the header.
   --------------------------------------------------------------------------- */

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_check(): void
{
    $sent  = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    $known = $_SESSION['csrf_token'] ?? '';

    if ($known === '' || $sent === '' || !hash_equals($known, $sent)) {
        // 403 rather than the Laravel-style 419: Apache rewrites unregistered
        // status codes to 500, which hid this failure entirely. The `code`
        // field is what lets the client tell a stale token apart from a real
        // permission failure and retry once.
        json_fail(403, 'Your session expired. Reload the page and try again.', ['code' => 'csrf']);
    }
}

/** The client address, used as a rate-limit identifier. */
function client_ip(): string
{
    return (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
}

/** True when the request came from the machine running the server. */
function is_local_request(): bool
{
    return in_array(client_ip(), ['127.0.0.1', '::1'], true);
}

/*
  Anything that escapes an endpoint becomes a JSON answer rather than a PHP
  fatal, so the frontend always has something to show. The specific hint is
  only sent back on a local request: it names the host, port and database, and
  that is a detail worth giving a developer and not worth giving the internet.
*/
set_exception_handler(static function (Throwable $e): void {
    error_log('[EduTrack] ' . $e::class . ': ' . $e->getMessage());

    if ($e instanceof DatabaseUnavailableException) {
        json_fail(503, is_local_request()
            ? $e->hint
            : 'The system cannot reach its database right now. Try again shortly.');
    }

    json_fail(500, is_local_request()
        ? $e::class . ': ' . $e->getMessage()
        : 'Something went wrong on our end. Try again.');
});
