<?php

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

$input = admin_boot(['code.view', 'code.view.redacted']);

// View-only staff see that a code exists and what happened to it, but not the
// code itself. A code is a bearer credential: reading one is using one.
$redacted = !can('code.view');

$page = paging($input, 25);
$sort = sorting($input, ['created_at', 'expires_at', 'used_at'], 'created_at');

$where  = [];
$params = [];

switch ((string) ($input['status'] ?? 'all')) {
    case 'active':
        $where[] = 'used = 0 AND revoked_at IS NULL AND expires_at > NOW()';
        break;
    case 'redeemed':
        $where[] = 'used = 1';
        break;
    case 'expired':
        $where[] = 'used = 0 AND revoked_at IS NULL AND expires_at <= NOW()';
        break;
    case 'revoked':
        $where[] = 'revoked_at IS NOT NULL';
        break;
}

$clause = $where ? ' WHERE ' . implode(' AND ', $where) : '';

$stmt = get_db()->prepare("SELECT COUNT(*) AS n FROM guard_codes gc{$clause}");
$stmt->execute($params);
$total = (int) $stmt->fetch()['n'];

$stmt = get_db()->prepare(
    "SELECT gc.id, gc.code, gc.used, gc.revoked_at, gc.created_at, gc.expires_at,
            gc.used_at, gc.issued_by,
            u.id AS user_id, u.full_name, u.email, u.email_verified
       FROM guard_codes gc
       LEFT JOIN users u ON u.id = gc.used_by_user_id
       {$clause}
      ORDER BY gc.{$sort['column']} {$sort['direction']}
      LIMIT {$page['perPage']} OFFSET {$page['offset']}"
);
$stmt->execute($params);

$rows = array_map(static function (array $r) use ($redacted): array {
    $status = match (true) {
        (int) $r['used'] === 1        => 'redeemed',
        $r['revoked_at'] !== null     => 'revoked',
        strtotime($r['expires_at']) <= time() => 'expired',
        default                       => 'active',
    };

    return [
        'id'        => (int) $r['id'],
        'code'      => $redacted ? '••••••' : $r['code'],
        'status'    => $status,
        'createdAt' => $r['created_at'],
        'expiresAt' => $r['expires_at'],
        'usedAt'    => $r['used_at'],
        'issuedBy'  => $r['issued_by'] ?? 'Guard desk',
        'student'   => $r['user_id'] === null ? null : [
            'id'       => (int) $r['user_id'],
            'fullName' => $r['full_name'],
            'email'    => $r['email'],
            'verified' => (int) $r['email_verified'] === 1,
        ],
    ];
}, $stmt->fetchAll());

json_list($rows, $total, $page, ['redacted' => $redacted]);
