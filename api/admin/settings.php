<?php

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

/**
 * Operational settings and admin accounts.
 *
 * Deliberately never touches SMTP or database credentials. Those stay in
 * api/config.php, out of reach of anything served over HTTP.
 */

const EDITABLE_SETTINGS = [
    'code_lifetime_minutes' => ['label' => 'How long a code lasts',        'min' => 5, 'max' => 1440, 'unit' => 'minutes'],
    'otp_lifetime_minutes'  => ['label' => 'How long an email code lasts', 'min' => 5, 'max' => 60,   'unit' => 'minutes'],
    'login_max_attempts'    => ['label' => 'Wrong password tries allowed', 'min' => 3, 'max' => 20,   'unit' => 'tries'],
    'login_lockout_minutes' => ['label' => 'Lock-out length',              'min' => 5, 'max' => 120,  'unit' => 'minutes'],
];

$isWrite = $_SERVER['REQUEST_METHOD'] === 'POST';
$input   = admin_boot('settings.manage', $isWrite ? 'POST' : 'GET');
$pdo     = get_db();

if (!$isWrite) {
    $values = [];
    foreach (EDITABLE_SETTINGS as $key => $meta) {
        $values[] = $meta + ['key' => $key, 'value' => setting($key)];
    }

    $admins = $pdo->query(
        'SELECT id, username, full_name, role, active, last_login_at, created_at
           FROM admins ORDER BY role, username'
    )->fetchAll();

    $allowlist = parse_allowlist(setting(IP_ALLOWLIST_KEY, ''));

    json_ok([
        'settings' => $values,
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
        'roles' => ADMIN_ROLE_LABELS,
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

    case 'save-ip-allowlist':
        $entries = parse_allowlist((string) ($input['allowlist'] ?? ''));

        $bad = invalid_allowlist_entries($entries);
        if ($bad) {
            json_fail(400, 'This is not an address or range: ' . $bad[0]
                . '. Use something like 192.168.0.50, or 192.168.0.0/24 for a whole network.');
        }

        /*
          The guard that makes this feature safe to offer. Saving a list that
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
        if (strlen($password) < 12) {
            json_fail(400, 'The password needs at least 12 characters.');
        }

        $stmt = $pdo->prepare('SELECT id FROM admins WHERE username = ?');
        $stmt->execute([$username]);
        if ($stmt->fetch()) {
            json_fail(400, 'Someone already uses that username.');
        }

        $pdo->prepare(
            'INSERT INTO admins (username, full_name, password_hash, role) VALUES (?, ?, ?, ?)'
        )->execute([$username, $fullName, password_hash($password, PASSWORD_DEFAULT), $role]);

        audit_log('admin.create', 'admin', $username, "Added {$fullName} ({$username}) as " . ADMIN_ROLE_LABELS[$role] . '.');

        json_ok(['message' => $fullName . ' can now sign in.']);

    case 'set-admin-active':
        $id     = (int) ($input['id'] ?? 0);
        $active = (bool) ($input['active'] ?? false);

        $stmt = $pdo->prepare('SELECT id, username, full_name, role, active FROM admins WHERE id = ?');
        $stmt->execute([$id]);
        $target = $stmt->fetch();

        if (!$target) {
            json_fail(404, 'That admin no longer exists.');
        }
        if ($id === (int) $_SESSION['admin_id']) {
            json_fail(400, 'You cannot turn off your own account.');
        }

        // Losing the last full-access account would lock everyone out of the
        // settings screen permanently, so it is refused.
        if (!$active && $target['role'] === 'super_admin') {
            $remaining = (int) $pdo->query(
                "SELECT COUNT(*) AS n FROM admins WHERE role = 'super_admin' AND active = 1"
            )->fetch()['n'];

            if ($remaining <= 1) {
                json_fail(400, 'This is the last full-access account. Turning it off would lock everyone out.');
            }
        }

        $pdo->prepare('UPDATE admins SET active = ? WHERE id = ?')->execute([$active ? 1 : 0, $id]);

        audit_log(
            $active ? 'admin.enable' : 'admin.disable',
            'admin',
            $target['username'],
            ($active ? 'Turned on' : 'Turned off') . " {$target['full_name']}'s account."
        );

        json_ok(['message' => $target['full_name'] . ($active ? ' can sign in again.' : ' can no longer sign in.')]);

    default:
        json_fail(400, 'That action is not recognised.');
}
