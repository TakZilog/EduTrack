<?php

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

$input = admin_boot('student.view');

$page = paging($input, 25);
$sort = sorting($input, ['full_name', 'email', 'created_at', 'id'], 'created_at');

$where  = [];
$params = [];

switch ((string) ($input['status'] ?? 'all')) {
    case 'verified':
        $where[] = 'email_verified = 1 AND deactivated_at IS NULL';
        break;
    case 'pending':
        $where[] = 'email_verified = 0 AND deactivated_at IS NULL';
        break;
    case 'off':
        $where[] = 'deactivated_at IS NOT NULL';
        break;
}

$search = trim((string) ($input['q'] ?? ''));
if ($search !== '') {
    $where[]  = '(full_name LIKE ? OR email LIKE ?)';
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
}

$clause = $where ? ' WHERE ' . implode(' AND ', $where) : '';

$stmt = get_db()->prepare("SELECT COUNT(*) AS n FROM users{$clause}");
$stmt->execute($params);
$total = (int) $stmt->fetch()['n'];

$stmt = get_db()->prepare(
    "SELECT id, full_name, email, email_verified, deactivated_at,
            registered_with_code, created_at, last_login_at
       FROM users{$clause}
      ORDER BY {$sort['column']} {$sort['direction']}
      LIMIT {$page['perPage']} OFFSET {$page['offset']}"
);
$stmt->execute($params);

$rows = array_map(static function (array $r): array {
    return [
        'id'         => (int) $r['id'],
        'fullName'   => $r['full_name'],
        'email'      => $r['email'],
        'status'     => $r['deactivated_at'] !== null ? 'off'
                        : ((int) $r['email_verified'] === 1 ? 'verified' : 'pending'),
        'code'       => $r['registered_with_code'],
        'createdAt'  => $r['created_at'],
        'lastLogin'  => $r['last_login_at'],
    ];
}, $stmt->fetchAll());

json_list($rows, $total, $page, ['sort' => $sort['key'], 'dir' => strtolower($sort['direction'])]);
