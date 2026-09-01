<?php

declare(strict_types=1);

require __DIR__ . '/session.php';
require __DIR__ . '/rate-limit.php';

app_session_start();
security_headers();
require_post();
csrf_check();

$config = require __DIR__ . '/config.php';

// Accept either a plain passphrase or a bcrypt hash in config.php.
// Anything starting with $2y$/$2a$/$2b$ is treated as a hash, everything else as plain text.
$secret = trim((string) ($config['guard_passphrase_hash'] ?? ''));
if ($secret === '' || $secret === 'PASTE-BCRYPT-HASH-HERE') {
    $secret = trim((string) ($config['guard_passphrase'] ?? ''));
}

$unset = ['', 'change-me', 'PASTE-BCRYPT-HASH-HERE'];
if (in_array($secret, $unset, true)) {
    // Worded as "password" to match the guard login screen. The config keys
    // are still guard_passphrase / guard_passphrase_hash.
    json_fail(500, 'Guard password is not configured');
}

// One shared credential guards this desk, so the address is the only
// identifier available to throttle on.
$ip = client_ip();
rate_limit_check($ip, 'guard_login');

$passphrase = (string) (json_input()['passphrase'] ?? '');

$isHash = (bool) preg_match('/^\$2[aby]\$\d{2}\$/', $secret);
$ok = $passphrase !== ''
    && ($isHash ? password_verify($passphrase, $secret) : hash_equals($secret, $passphrase));

if (!$ok) {
    rate_limit_record($ip, 'guard_login', false);
    json_fail(401, 'Incorrect password');
}

rate_limit_record($ip, 'guard_login', true);

session_regenerate_id(true);
$_SESSION['guard_authenticated'] = true;

json_ok();
