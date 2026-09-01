<?php

/**
 * Changes the guard desk password.
 *
 * Command line only, for the same reason as tools/create-admin.php: the guard
 * credential is what stands between a stranger and a valid registration code,
 * so it is not changeable from any web page.
 *
 *     php tools/set-guard-password.php --password=whatever-they-type
 *     php tools/set-guard-password.php                  (prompts instead)
 *     php tools/set-guard-password.php --password=x --plain
 *
 * By default the password is stored as a bcrypt hash, because api/config.php
 * lives inside the web root. --plain stores the literal text instead, which is
 * what api/config.example.php documents and is easier to read back later.
 *
 * Until now there was no tool for this at all and the only way to change the
 * guard password was to hand-edit two lines of api/config.php, where setting
 * the wrong one of them silently does nothing.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This tool runs from the command line only.\n");
}

$root       = dirname(__DIR__);
$configPath = "{$root}/api/config.php";

if (!is_file($configPath)) {
    exit("api/config.php is missing. Copy api/config.example.php to api/config.php first.\n");
}

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

$plain = in_array('--plain', $GLOBALS['argv'], true);

$password = flag('password');
if ($password === null) {
    echo 'New guard password: ';
    $password = rtrim((string) fgets(STDIN), "\r\n");
}

if ($password === '') {
    exit("The password cannot be empty.\n");
}

// api/guard-login.php treats these as "not configured" and returns a 500, so
// storing one would leave the guard desk broken rather than protected.
if (in_array($password, ['change-me', 'PASTE-BCRYPT-HASH-HERE'], true)) {
    exit("That value is the placeholder guard-login.php treats as unset. Pick another.\n");
}

$source = file_get_contents($configPath);
if ($source === false) {
    exit("Could not read api/config.php.\n");
}

/*
  Both keys are rewritten, always. guard-login.php prefers the hash and only
  falls back to the plain value when the hash is empty, so writing one without
  clearing the other is the mistake that makes a password change look like it
  did nothing.
*/
$hash = $plain ? '' : password_hash($password, PASSWORD_DEFAULT);

$replacements = [
    'guard_passphrase'      => $plain ? $password : '',
    'guard_passphrase_hash' => $hash,
];

foreach ($replacements as $key => $value) {
    $updated = preg_replace(
        "/('{$key}'\s*=>\s*)'[^']*'/",
        '${1}' . "'" . str_replace(['\\', '$'], ['\\\\', '\\$'], $value) . "'",
        $source,
        1,
        $count
    );

    if ($updated === null || $count !== 1) {
        exit("Could not find a '{$key}' line in api/config.php. Edit it by hand.\n");
    }

    $source = $updated;
}

if (file_put_contents($configPath, $source) === false) {
    exit("Could not write api/config.php. Check the file is not read-only.\n");
}

// Read it back and confirm the new password actually verifies, so a silent
// bad write cannot be mistaken for success.
$config = require $configPath;
$stored = $plain
    ? (string) ($config['guard_passphrase'] ?? '')
    : (string) ($config['guard_passphrase_hash'] ?? '');

$ok = $plain
    ? hash_equals($stored, $password)
    : password_verify($password, $stored);

if (!$ok) {
    exit("The file was written but the new password does not verify. Check api/config.php by hand.\n");
}

echo PHP_EOL;
echo 'Guard password updated, stored as ' . ($plain ? 'plain text' : 'a bcrypt hash') . '.' . PHP_EOL;
echo 'The guard types it at /EduTrack/Guard/login.html' . PHP_EOL;
echo PHP_EOL;
echo 'If the guard desk was locked out while testing:' . PHP_EOL;
echo '    php tools/unlock-login.php --all' . PHP_EOL . PHP_EOL;
