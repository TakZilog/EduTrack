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

    // Before anything else, including the session check: a computer that is
    // not allowed here should learn nothing about whether a session exists.
    enforce_ip_allowlist();

    if ($_SERVER['REQUEST_METHOD'] !== $method) {
        json_fail(405, 'Method not allowed');
    }

    if (empty($_SESSION['admin_id'])) {
        json_fail(401, 'Please sign in to continue.', ['code' => 'auth']);
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

/** A settings value, falling back to the given default. */
function setting(string $key, string $default = ''): string
{
    static $cache = null;

    if ($cache === null) {
        $cache = [];
        foreach (get_db()->query('SELECT setting_key, setting_value FROM app_settings') as $row) {
            $cache[$row['setting_key']] = $row['setting_value'];
        }
    }

    return $cache[$key] ?? $default;
}
