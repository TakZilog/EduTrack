<?php

/**
 * Creates an admin account, or gives an existing one a new password.
 *
 * Command line only. There is deliberately no web page for creating the first
 * account: it has to come from someone with access to the machine, otherwise
 * the panel would ship with a way to mint its own administrators. Later
 * accounts are made on the Users & Access page.
 *
 *     php tools/create-admin.php
 *     php tools/create-admin.php --username=mrosales --name="M. Rosales" --role=admin
 *     php tools/create-admin.php --reset --username=mrosales
 *
 * --reset is the way back in when nobody who can sign in remembers their
 * password: it sets a new password and turns the account back on.
 *
 * Roles: super_admin (everything), admin (daily work), faculty (view only).
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This tool runs from the command line only.\n");
}

$root = dirname(__DIR__);
require_once "{$root}/api/db.php";

const ROLES = ['super_admin', 'admin', 'faculty'];

/** Reads --key=value flags. */
function flag(string $name): ?string
{
    foreach ($GLOBALS['argv'] as $arg) {
        if (str_starts_with($arg, "--{$name}=")) {
            return substr($arg, strlen($name) + 3);
        }
    }
    return null;
}

function ask(string $prompt, bool $hidden = false): string
{
    echo $prompt;

    if ($hidden && stripos(PHP_OS_FAMILY, 'Windows') === false) {
        shell_exec('stty -echo');
        $value = trim((string) fgets(STDIN));
        shell_exec('stty echo');
        echo PHP_EOL;
        return $value;
    }

    if ($hidden) {
        echo '(typing is visible on Windows) ';
    }

    return trim((string) fgets(STDIN));
}

/** Asks for a password twice and checks it against the panel's own rule. */
function new_password(): string
{
    $password = flag('password') ?? ask('Password (' . ADMIN_PASSWORD_MIN . ' characters minimum): ', true);
    if (strlen($password) < ADMIN_PASSWORD_MIN) {
        exit('Password must be at least ' . ADMIN_PASSWORD_MIN . " characters.\n");
    }

    $confirm = flag('password') ?? ask('Repeat the password: ', true);
    if (!hash_equals($password, $confirm)) {
        exit("Those passwords do not match.\n");
    }

    return $password;
}

$reset = in_array('--reset', $GLOBALS['argv'], true);

echo PHP_EOL . ($reset ? 'Set a new password for an EduTrack admin' : 'Create an EduTrack admin')
    . PHP_EOL . str_repeat('-', 40) . PHP_EOL;

try {
    $pdo = get_db();
} catch (DatabaseUnavailableException $e) {
    exit('Cannot reach the database. ' . $e->hint . PHP_EOL);
}

$username = flag('username') ?? ask('Username (for signing in): ');

$stmt = $pdo->prepare('SELECT id, active FROM admins WHERE username = ?');
$stmt->execute([$username]);
$existing = $stmt->fetch();

if ($reset) {
    if (!$existing) {
        exit("There is no admin called '{$username}'.\n");
    }

    $password = new_password();
    $pdo->prepare('UPDATE admins SET password_hash = ?, active = 1 WHERE id = ?')
        ->execute([hash_password($password), $existing['id']]);

    echo PHP_EOL . "'{$username}' has a new password" . ((int) $existing['active'] ? '' : ' and is turned on again')
        . '.' . PHP_EOL . 'Anyone still signed in to that account is signed out.' . PHP_EOL . PHP_EOL;
    exit;
}

if ($existing) {
    exit("An admin called '{$username}' already exists. To give it a new password, add --reset.\n");
}

$fullName = flag('name') ?? ask('Full name: ');
$role     = flag('role') ?? ask('Role [super_admin, admin, faculty] (default super_admin): ');
$role     = $role !== '' ? $role : 'super_admin';

if (!preg_match('/^[a-zA-Z0-9._-]{4,50}$/', $username)) {
    exit("Username must be 4 to 50 characters: letters, numbers, dots, dashes, underscores.\n");
}
if ($fullName === '' || mb_strlen($fullName) > 100) {
    exit("Enter a full name of up to 100 characters.\n");
}
if (!in_array($role, ROLES, true)) {
    exit('Role must be one of: ' . implode(', ', ROLES) . PHP_EOL);
}

$password = new_password();

$stmt = $pdo->prepare(
    'INSERT INTO admins (username, full_name, password_hash, role) VALUES (?, ?, ?, ?)'
);
$stmt->execute([$username, $fullName, hash_password($password), $role]);

echo PHP_EOL . "Created '{$username}' ({$role})." . PHP_EOL;
echo 'Sign in at /EduTrack/admin/login.html' . PHP_EOL . PHP_EOL;
