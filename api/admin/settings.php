<?php

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../rate-limit.php';

/**
 * Operational settings and admin accounts.
 *
 * Deliberately never touches SMTP or database credentials. Those stay in
 * api/config.php, out of reach of anything served over HTTP.
 */

const EDITABLE_SETTINGS = [
    'otp_lifetime_minutes'  => ['label' => 'How long an email code lasts', 'min' => 5, 'max' => 60,   'unit' => 'minutes'],
    'login_max_attempts'    => ['label' => 'Wrong password tries allowed', 'min' => 3, 'max' => 20,   'unit' => 'tries'],
    'login_lockout_minutes' => ['label' => 'Lock-out length',              'min' => 5, 'max' => 120,  'unit' => 'minutes'],
    'session_timeout_minutes' => ['label' => 'Session timeout',            'min' => 5, 'max' => 480,  'unit' => 'minutes'],
];

const STUDENT_VERIFICATION_SETTINGS = [
    'student_id_verification_enabled'          => ['label' => 'Student ID verification'],
    'require_active_study_load_enabled'        => ['label' => 'Require active Study Load'],
    'verify_current_semester_enabled'          => ['label' => 'Verify current semester'],
    'verify_current_school_year_enabled'       => ['label' => 'Verify current school year'],
];

function student_verification_bool(string $key, string $default = '1'): bool
{
    $value = strtolower(trim((string) setting($key, $default)));
    return in_array($value, ['1', 'true', 'yes', 'on'], true);
}

/** One staff account, or a 404 the page can explain. */
function admin_by_id(PDO $pdo, int $id): array
{
    $stmt = $pdo->prepare('SELECT id, username, full_name, role, active FROM admins WHERE id = ?');
    $stmt->execute([$id]);
    $admin = $stmt->fetch();
    if (!$admin) {
        json_fail(404, 'That account no longer exists. Refresh the page.');
    }

    return $admin;
}

/**
 * Refuses a change that would leave no full-access account turned on: nobody
 * could then reach this screen to undo it.
 */
function keep_one_full_access(PDO $pdo, array $target): void
{
    if ($target['role'] !== 'super_admin' || !(int) $target['active']) {
        return;
    }
    $remaining = (int) $pdo->query(
        "SELECT COUNT(*) AS n FROM admins WHERE role = 'super_admin' AND active = 1"
    )->fetch()['n'];
    if ($remaining <= 1) {
        json_fail(400, 'This is the last full-access account. Changing it would lock everyone out of this screen.');
    }
}

function valid_new_password(string $password): void
{
    if (strlen($password) < ADMIN_PASSWORD_MIN) {
        json_fail(400, 'The password needs at least ' . ADMIN_PASSWORD_MIN . ' characters.');
    }
}

$isWrite = $_SERVER['REQUEST_METHOD'] === 'POST';
$input   = admin_boot('settings.manage', $isWrite ? 'POST' : 'GET');
$pdo     = get_db();

if (!$isWrite) {
    $values = [];
    foreach (EDITABLE_SETTINGS as $key => $meta) {
        $default = $key === 'session_timeout_minutes' ? '30' : '';
        $values[] = $meta + ['key' => $key, 'value' => setting($key, $default)];
    }

    // Accounts that can sign in first, then by level (most access first), then by name.
    $admins = $pdo->query(
        "SELECT id, username, full_name, role, active, last_login_at, created_at
           FROM admins
          ORDER BY active DESC, FIELD(role, 'super_admin', 'admin', 'faculty'), full_name"
    )->fetchAll();

    $allowlist = parse_allowlist(setting(IP_ALLOWLIST_KEY, ''));
    $studentVerification = [];
    foreach (STUDENT_VERIFICATION_SETTINGS as $key => $meta) {
        $studentVerification[] = [
            'key' => $key,
            'label' => $meta['label'],
            'enabled' => student_verification_bool($key, '1'),
        ];
    }

    json_ok([
        'settings' => $values,
        'studentVerification' => $studentVerification,
        'access'   => [
            'allowlist' => $allowlist,
            'enabled'   => $allowlist !== [],
            'yourIp'    => client_ip(),
        ],
        'admins'   => array_map(static fn ($a) => [
            'id'        => (int) $a['id'],
            'username'  => $a['username'],
            'fullName'  => $a['full_name'],
            'role'      => $a['role'],
            'roleLabel' => ADMIN_ROLE_LABELS[$a['role']] ?? $a['role'],
            'active'    => (int) $a['active'] === 1,
            'lastLogin' => $a['last_login_at'],
            'createdAt' => $a['created_at'],
            'isYou'     => (int) $a['id'] === (int) $_SESSION['admin_id'],
        ], $admins),
        'roles'         => ADMIN_ROLE_LABELS,
        'roleSummaries' => ADMIN_ROLE_SUMMARIES,
        'passwordMin'   => ADMIN_PASSWORD_MIN,
    ]);
}

/* ------------------------------------------------------------------ writes */

switch ((string) ($input['action'] ?? '')) {
    case 'save-settings':
        $changes = [];
        foreach (EDITABLE_SETTINGS as $key => $meta) {
            if (!array_key_exists($key, $input)) {
                continue;
            }

            $value = (int) $input[$key];
            if ($value < $meta['min'] || $value > $meta['max']) {
                json_fail(400, sprintf(
                    '%s must be between %d and %d %s.',
                    $meta['label'], $meta['min'], $meta['max'], $meta['unit']
                ));
            }

            if (setting($key) !== (string) $value) {
                $changes[] = "{$meta['label']}: {$value} {$meta['unit']}";
            }

            /*
              The update half repeats the placeholders rather than using
              VALUES(setting_value). That function is deprecated as of MySQL
              8.0.20 and warns on this server, and its replacement — the row
              alias form, `VALUES (?, ?, ?) AS new` — does not exist in
              MariaDB. Binding the value twice is the one spelling that is both
              current and portable across the two engines this may run on.
            */
            $pdo->prepare(
                'INSERT INTO app_settings (setting_key, setting_value, updated_by) VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE setting_value = ?, updated_by = ?'
            )->execute([
                $key, (string) $value, $_SESSION['admin_id'],
                (string) $value, $_SESSION['admin_id'],
            ]);
        }

        if ($changes) {
            audit_log('settings.save', 'settings', null, 'Changed ' . implode('; ', $changes) . '.');
        }

        json_ok(['message' => $changes ? 'Settings saved.' : 'Nothing was changed.']);

    case 'save-student-verification-settings':
        $changes = 0;
        foreach (STUDENT_VERIFICATION_SETTINGS as $key => $meta) {
            $raw = strtolower(trim((string) ($input[$key] ?? '1')));
            $enabled = in_array($raw, ['1', 'true', 'yes', 'on', 'enabled'], true);
            $value = $enabled ? '1' : '0';

            if (setting($key, '1') !== $value) {
                $changes++;
            }

            $pdo->prepare(
                'INSERT INTO app_settings (setting_key, setting_value, updated_by) VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE setting_value = ?, updated_by = ?'
            )->execute([
                $key, $value, $_SESSION['admin_id'],
                $value, $_SESSION['admin_id'],
            ]);
        }

        audit_log('settings.student-verification', 'settings', null, $changes
            ? 'Updated the student verification configuration.'
            : 'No student verification settings changed.');

        json_ok(['message' => $changes ? 'Student verification settings saved.' : 'Nothing was changed.']);

    case 'save-ip-allowlist':
        $entries = parse_allowlist((string) ($input['allowlist'] ?? ''));

        $bad = invalid_allowlist_entries($entries);
        if ($bad) {
            json_fail(400, 'This is not an address or range: ' . $bad[0]
                . '. Use something like 192.168.0.50, or 192.168.0.0/24 for a whole network.');
        }

        /*
          The check that makes this feature safe to offer. Saving a list that
          does not include the computer you are sitting at would lock you out
          of the screen you would need to undo it. The server's own machine
          always passes, so whoever is at it can still recover.
        */
        if ($entries !== [] && !ip_allowed(client_ip(), $entries)) {
            json_fail(400, 'That list does not include this computer (' . client_ip()
                . '), so saving it would lock you out. Add this address first.');
        }

        $stored = implode("\n", $entries);
        // Placeholders repeated on the update half, for the reason given above.
        $pdo->prepare(
            'INSERT INTO app_settings (setting_key, setting_value, updated_by) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE setting_value = ?, updated_by = ?'
        )->execute([
            IP_ALLOWLIST_KEY, $stored, $_SESSION['admin_id'],
            $stored, $_SESSION['admin_id'],
        ]);

        audit_log('settings.access', 'settings', IP_ALLOWLIST_KEY, $entries === []
            ? 'Turned off the computer restriction. The staff panel is reachable from anywhere on the network again.'
            : 'Limited the staff panel to ' . count($entries) . ' address(es) or range(s): ' . implode(', ', $entries) . '.');

        json_ok([
            'message' => $entries === []
                ? 'The restriction is off. Any computer on the network can reach the staff panel.'
                : 'Saved. Only the listed computers can reach the staff panel now.',
        ]);

    case 'add-admin':
        $username = trim((string) ($input['username'] ?? ''));
        $fullName = trim(preg_replace('/\s+/u', ' ', (string) ($input['fullName'] ?? '')) ?? '');
        $role     = (string) ($input['role'] ?? 'faculty');
        $password = (string) ($input['password'] ?? '');

        if (!preg_match('/^[a-zA-Z0-9._-]{4,50}$/', $username)) {
            json_fail(400, 'The username needs 4 to 50 letters, numbers, dots, dashes or underscores.');
        }
        if (mb_strlen($fullName) < 2 || mb_strlen($fullName) > 100) {
            json_fail(400, 'Enter the person\'s full name.');
        }
        if (!isset(ADMIN_ROLE_LABELS[$role])) {
            json_fail(400, 'Choose an access level from the list.');
        }
        valid_new_password($password);

        $stmt = $pdo->prepare('SELECT id FROM admins WHERE username = ?');
        $stmt->execute([$username]);
        if ($stmt->fetch()) {
            json_fail(400, 'Someone already uses that username.');
        }

        $pdo->prepare(
            'INSERT INTO admins (username, full_name, password_hash, role) VALUES (?, ?, ?, ?)'
        )->execute([$username, $fullName, hash_password($password), $role]);

        audit_log('admin.create', 'admin', $username, "Added {$fullName} ({$username}) as " . ADMIN_ROLE_LABELS[$role] . '.');

        json_ok(['message' => $fullName . ' can now sign in.']);

    case 'set-admin-active':
        $id     = (int) ($input['id'] ?? 0);
        $active = (bool) ($input['active'] ?? false);

        $target = admin_by_id($pdo, $id);
        if ($id === (int) $_SESSION['admin_id']) {
            json_fail(400, 'You cannot turn off your own account.');
        }
        if (!$active) {
            keep_one_full_access($pdo, $target);
        }

        $pdo->prepare('UPDATE admins SET active = ? WHERE id = ?')->execute([$active ? 1 : 0, $id]);

        audit_log(
            $active ? 'admin.enable' : 'admin.disable',
            'admin',
            $target['username'],
            ($active ? 'Turned on' : 'Turned off') . " {$target['full_name']}'s account."
        );

        json_ok(['message' => $target['full_name'] . ($active ? ' can sign in again.' : ' can no longer sign in.')]);

    case 'update-admin':
        // A name spelled wrong, or someone who needs more or less access.
        // The new access level applies from their very next click.
        $id       = (int) ($input['id'] ?? 0);
        $fullName = trim(preg_replace('/\s+/u', ' ', (string) ($input['fullName'] ?? '')) ?? '');
        $role     = (string) ($input['role'] ?? '');
        $target   = admin_by_id($pdo, $id);

        if (mb_strlen($fullName) < 2 || mb_strlen($fullName) > 100) {
            json_fail(400, 'Enter the person\'s full name.');
        }
        if (!isset(ADMIN_ROLE_LABELS[$role])) {
            json_fail(400, 'Choose an access level from the list.');
        }
        if ($role !== $target['role']) {
            if ($id === (int) $_SESSION['admin_id']) {
                json_fail(400, 'You cannot change your own access level. Ask another full-access admin.');
            }
            keep_one_full_access($pdo, $target);
        }

        $pdo->prepare('UPDATE admins SET full_name = ?, role = ? WHERE id = ?')->execute([$fullName, $role, $id]);

        $what = [];
        if ($fullName !== $target['full_name']) {
            $what[] = "renamed {$target['full_name']} to {$fullName}";
        }
        if ($role !== $target['role']) {
            $what[] = 'changed access from ' . ADMIN_ROLE_LABELS[$target['role']] . ' to ' . ADMIN_ROLE_LABELS[$role];
        }
        if ($what) {
            audit_log('admin.update', 'admin', $target['username'], ucfirst(implode(' and ', $what)) . '.');
        }

        json_ok(['message' => $what ? 'Saved.' : 'Nothing was changed.']);

    case 'reset-password':
        // For someone who forgot theirs. Tell them the new one in person.
        $id       = (int) ($input['id'] ?? 0);
        $password = (string) ($input['password'] ?? '');
        $target   = admin_by_id($pdo, $id);

        if ($id === (int) $_SESSION['admin_id']) {
            json_fail(400, 'To change your own password, use "Change my password".');
        }
        valid_new_password($password);

        $pdo->prepare('UPDATE admins SET password_hash = ? WHERE id = ?')->execute([hash_password($password), $id]);
        audit_log('admin.password', 'admin', $target['username'], "Set a new password for {$target['full_name']}.");

        json_ok(['message' => $target['full_name'] . '\'s new password is saved. Tell them in person, not by message.']);

    case 'change-own-password':
        $me      = current_admin();
        $current = (string) ($input['currentPassword'] ?? '');
        $new     = (string) ($input['password'] ?? '');

        // Someone at an unattended desk must not be able to guess their way
        // to a password that outlives the session.
        rate_limit_check($me['username'], 'admin_pw_change', 5, 15,
            'Too many wrong tries. Please wait %d minutes and try again.');

        $stmt = $pdo->prepare('SELECT password_hash FROM admins WHERE id = ?');
        $stmt->execute([$me['id']]);
        if (!verify_password($current, (string) $stmt->fetchColumn(), 'admins', $me['id'])) {
            rate_limit_record($me['username'], 'admin_pw_change', false);
            json_fail(400, 'Your current password is not right.');
        }
        rate_limit_record($me['username'], 'admin_pw_change', true);

        valid_new_password($new);
        if (hash_equals($current, $new)) {
            json_fail(400, 'Choose a password different from the one you have now.');
        }

        $hash = hash_password($new);
        $pdo->prepare('UPDATE admins SET password_hash = ? WHERE id = ?')->execute([$hash, $me['id']]);
        // This session stays signed in; any other session on the old password ends.
        $_SESSION['admin_pw_mark'] = password_mark($hash);
        audit_log('admin.password', 'admin', $me['username'], 'Changed their own password.');

        json_ok(['message' => 'Your password has been changed.']);

    default:
        json_fail(400, 'That action is not recognised.');
}
