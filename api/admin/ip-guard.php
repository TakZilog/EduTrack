<?php

/**
 * Restricts the staff panel to known computers.
 *
 * The panel is served over plain HTTP on a shared campus network, so anything
 * on that network can reach it. This narrows that down to the machines that
 * are supposed to use it.
 *
 * It is not a replacement for HTTPS. It stops someone reaching the sign-in
 * page at all; it does not stop someone on an allowed network reading a
 * password off the wire.
 *
 * Safety rules, because a wrong entry here could lock everyone out for good:
 *   - an empty list means the restriction is off
 *   - localhost is always allowed, so whoever sits at the server can undo it
 *   - a list that would lock out the person saving it is refused
 *   - tools/reset-ip-allowlist.php clears it from the command line
 */

declare(strict_types=1);

const IP_ALLOWLIST_KEY = 'admin_ip_allowlist';

/** Turns the stored text into a list of entries, one per line or comma. */
function parse_allowlist(string $raw): array
{
    $parts = preg_split('/[\s,]+/', trim($raw)) ?: [];

    return array_values(array_filter(array_map('trim', $parts), static fn ($p) => $p !== ''));
}

/** IPv6-mapped IPv4 (::ffff:192.168.0.5) is treated as the plain IPv4 address. */
function normalise_ip(string $ip): string
{
    if (stripos($ip, '::ffff:') === 0 && filter_var(substr($ip, 7), FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        return substr($ip, 7);
    }
    return $ip;
}

function ip_in_cidr(string $ip, string $cidr): bool
{
    [$subnet, $bits] = array_pad(explode('/', $cidr, 2), 2, null);

    if ($bits === null || !ctype_digit((string) $bits)) {
        return false;
    }
    $bits = (int) $bits;

    // IPv4 only. A campus network is a v4 subnet in practice, and getting
    // v6 prefix maths subtly wrong here would fail open.
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
        || !filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
        || $bits < 0 || $bits > 32) {
        return false;
    }

    if ($bits === 0) {
        return true;
    }

    $mask = -1 << (32 - $bits);

    return (ip2long($ip) & $mask) === (ip2long($subnet) & $mask);
}

/** True when this address may use the staff panel. */
function ip_allowed(string $ip, array $entries): bool
{
    if ($entries === []) {
        return true; // restriction is off
    }

    $ip = normalise_ip($ip);

    // Always. This is the way back in if the list is wrong.
    if (in_array($ip, ['127.0.0.1', '::1'], true)) {
        return true;
    }

    foreach ($entries as $entry) {
        if (str_contains($entry, '/')) {
            if (ip_in_cidr($ip, $entry)) {
                return true;
            }
        } elseif (strcasecmp(normalise_ip($entry), $ip) === 0) {
            return true;
        }
    }

    return false;
}

/**
 * @return string[] the entries that are not a valid address or range
 */
function invalid_allowlist_entries(array $entries): array
{
    $bad = [];

    foreach ($entries as $entry) {
        if (str_contains($entry, '/')) {
            [$subnet, $bits] = array_pad(explode('/', $entry, 2), 2, '');
            if (!filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
                || !ctype_digit($bits) || (int) $bits > 32) {
                $bad[] = $entry;
            }
        } elseif (!filter_var($entry, FILTER_VALIDATE_IP)) {
            $bad[] = $entry;
        }
    }

    return $bad;
}

/**
 * Refuses the request when this computer is not on the list.
 * Called from the admin gate and from the sign-in endpoint.
 */
function enforce_ip_allowlist(): void
{
    $entries = parse_allowlist(setting(IP_ALLOWLIST_KEY, ''));

    if (ip_allowed(client_ip(), $entries)) {
        return;
    }

    // The address is named on purpose: whoever is locked out needs to be able
    // to read it out to someone who can add it.
    json_fail(403, 'The staff panel is not available from this computer (' . client_ip() . '). '
        . 'Ask a full-access admin to add this address, or use the computer at the office.');
}
