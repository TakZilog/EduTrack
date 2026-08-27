<?php

/**
 * Creates an admin account.
 *
 * Command line only. There is deliberately no web page for this: the first
 * account has to come from someone with access to the machine, otherwise the
 * panel would ship with a way to mint its own administrators.
 *
 *     php tools/create-admin.php
 *     php tools/create-admin.php --username=mrosales --name="M. Rosales" --role=admin
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

echo PHP_EOL . 'Create an EduTrack admin' . PHP_EOL . str_repeat('-', 40) . PHP_EOL;

try {
    $pdo = get_db();
} catch (DatabaseUnavailableException $e) {
    exit('Cannot reach the database. ' . $e->hint . PHP_EOL);
}

$username = flag('username') ?? ask('Username (for signing in): ');
$fullName = flag('name')     ?? ask('Full name: ');
$role     = flag('role')     ?? ask('Role [super_admin, admin, faculty] (default super_admin): ');
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

$stmt = $pdo->prepare('SELECT id FROM admins WHERE username = ?');
$stmt->execute([$username]);
if ($stmt->fetch()) {
    exit("An admin called '{$username}' already exists.\n");
}

$password = flag('password') ?? ask('Password (12 characters minimum): ', true);
if (strlen($password) < 12) {
    exit("Password must be at least 12 characters.\n");
}

$confirm = flag('password') ?? ask('Repeat the password: ', true);
if (!hash_equals($password, $confirm)) {
    exit("Those passwords do not match.\n");
}

$stmt = $pdo->prepare(
    'INSERT INTO admins (username, full_name, password_hash, role) VALUES (?, ?, ?, ?)'
);
$stmt->execute([$username, $fullName, password_hash($password, PASSWORD_DEFAULT), $role]);

echo PHP_EOL . "Created '{$username}' ({$role})." . PHP_EOL;
echo 'Sign in at /EduTrack/admin/login.html' . PHP_EOL . PHP_EOL;
