<?php

/**
 * The way back in.
 *
 * If the staff panel has been limited to a set of computers and nobody can
 * reach it any more, run this on the server to clear the restriction:
 *
 *     php tools/reset-ip-allowlist.php
 *
 * Signing in from the server itself also works, because that machine is always
 * allowed. This exists for the case where that is not convenient either.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This tool runs from the command line only.\n");
}

$root = dirname(__DIR__);
require_once "{$root}/api/db.php";

try {
    $pdo = get_db();
} catch (DatabaseUnavailableException $e) {
    exit('Cannot reach the database. ' . $e->hint . PHP_EOL);
}

$stmt = $pdo->prepare('SELECT setting_value FROM app_settings WHERE setting_key = ?');
$stmt->execute(['admin_ip_allowlist']);
$current = trim((string) ($stmt->fetch()['setting_value'] ?? ''));

if ($current === '') {
    exit("\nThe staff panel is not restricted to particular computers. Nothing to clear.\n\n");
}

echo PHP_EOL . 'The staff panel is currently limited to:' . PHP_EOL;
foreach (preg_split('/[\s,]+/', $current) ?: [] as $entry) {
    if (trim($entry) !== '') {
        echo '  ' . $entry . PHP_EOL;
    }
}

echo PHP_EOL . 'Clear this and let any computer on the network reach it? [y/N] ';
if (strtolower(trim((string) fgets(STDIN))) !== 'y') {
    exit("Left unchanged.\n\n");
}

$pdo->prepare('UPDATE app_settings SET setting_value = ?, updated_by = NULL WHERE setting_key = ?')
    ->execute(['', 'admin_ip_allowlist']);

// Recorded like any other change, with no admin attached, so the log shows
// plainly that this was done from the server rather than through the panel.
$pdo->prepare(
    'INSERT INTO admin_audit (admin_id, admin_name, role, action, target_type, target_id, detail, ip)
     VALUES (NULL, ?, ?, ?, ?, ?, ?, ?)'
)->execute([
    'command line', 'server', 'settings.access', 'settings', 'admin_ip_allowlist',
    'Cleared the computer restriction from the server command line.', null,
]);

echo PHP_EOL . "Cleared. Any computer on the network can reach the staff panel again.\n\n";
