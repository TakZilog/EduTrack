<?php

/**
 * The single gate every admin endpoint passes through.
 *
 * Because admin_boot() does method, session, CSRF and permission checks in one
 * call, it is not possible to add a new endpoint that quietly forgets one.
 */

declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../session.php';
require_once __DIR__ . '/permissions.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/ip-guard.php';

/**
 * @param string|string[] $permission one permission, or several of which any
 *                                    one is enough (used where a view-only
 *                                    role gets a redacted version of a screen)
 * @param string $method              'GET' or 'POST'
 * @return array                      query string for GET, JSON body for POST
 */
function admin_boot(string|array $permission, string $method = 'GET'): array
{
    app_session_start();
    security_headers();

    // ── Mobile app hard block ──────────────────────────────────────────
    // The admin panel is web-only. The mobile app identifies itself with a
    // custom User-Agent ("EduTrackMobile/…"). If that header is present the
    // request is refused outright — before the IP check, before the session
    // check, before anything that might leak whether an admin account exists.
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    if (stripos($ua, 'EduTrackMobile') !== false) {
        json_fail(403, 'The staff panel is not available from the mobile app.');
    }

    // Before anything else, including the session check: a computer that is
    // not allowed here should learn nothing about whether a session exists.
    enforce_ip_allowlist();

    if ($_SERVER['REQUEST_METHOD'] !== $method) {
        json_fail(405, 'Method not allowed');
    }

    if (empty($_SESSION['admin_id'])) {
        json_fail(401, 'Please sign in to continue.', ['code' => 'auth']);
    }
    if (current_admin() === null) {
        json_fail(401, 'Your session has ended. Please sign in again.', ['code' => 'auth']);
    }

    if ($method !== 'GET') {
        csrf_check();
    }

    $needed  = (array) $permission;
    $allowed = false;
    foreach ($needed as $one) {
        if (can($one)) {
            $allowed = true;
            break;
        }
    }

    if (!$allowed) {
        // A permission miss is worth keeping: an account repeatedly probing an
        // endpoint it cannot use is exactly what this log exists to catch.
        audit_log('access.denied', 'permission', implode('|', $needed),
            'Tried to use something their access level does not allow.');
        json_fail(403, 'Your account does not have access to that.');
    }

    return $method === 'GET' ? $_GET : json_input();
}

/**
 * The signed-in account, read fresh on every request.
 *
 * The session only says who signed in. Whether that account is still turned
 * on, and what it may do, can change while a page is open, so both are
 * checked against the database each time rather than trusted until the next
 * sign-in. An account that is gone or turned off ends the session at once.
 *
 * @return array{id: int, username: string, full_name: string, role: string}|null
 */
function current_admin(): ?array
{
    static $admin = false;
    if ($admin !== false) {
        return $admin;
    }

    $stmt = get_db()->prepare('SELECT id, username, full_name, role, active, password_hash FROM admins WHERE id = ?');
    $stmt->execute([(int) ($_SESSION['admin_id'] ?? 0)]);
    $row = $stmt->fetch();

    // A new password also ends every session signed in with the old one,
    // which is the point of resetting a password that may be known to others.
    // Sessions from before this check existed carry no mark and are given one.
    $mark = $row ? password_mark((string) $row['password_hash']) : '';
    $_SESSION['admin_pw_mark'] ??= $mark;

    if (!$row || !(int) $row['active'] || !hash_equals($_SESSION['admin_pw_mark'], $mark)) {
        $_SESSION = [];
        session_destroy();
        return $admin = null;
    }

    $_SESSION['admin_role'] = $row['role'];
    $row['id'] = (int) $row['id'];
    unset($row['password_hash']);

    return $admin = $row;
}

/** Stands in for the password in the session, so a password change can be noticed. */
function password_mark(string $hash): string
{
    return hash('sha256', $hash);
}

/* ------------------------------------------------------------------ helpers */

/** Reads a paging window from the query string, clamped to something sane. */
function paging(array $input, int $perPage = 25): array
{
    $page = max(1, (int) ($input['page'] ?? 1));
    return ['page' => $page, 'perPage' => $perPage, 'offset' => ($page - 1) * $perPage];
}

/**
 * Sorting, allow-listed. The column never reaches SQL unless it appears in
 * $allowed, so the sort parameter cannot be used for injection.
 */
function sorting(array $input, array $allowed, string $default): array
{
    $column    = (string) ($input['sort'] ?? $default);
    $direction = strtolower((string) ($input['dir'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';

    if (!in_array($column, $allowed, true)) {
        $column = $default;
    }

    return ['column' => $column, 'direction' => $direction, 'key' => $column];
}

function json_list(array $rows, int $total, array $page, array $extra = []): never
{
    json_ok([
        'data'     => $rows,
        'total'    => $total,
        'page'     => $page['page'],
        'per_page' => $page['perPage'],
        'pages'    => max(1, (int) ceil($total / $page['perPage'])),
    ] + $extra);
}
