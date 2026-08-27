<?php

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

$input = admin_boot('audit.view');

$page = paging($input, 30);

$where  = [];
$params = [];

$who = trim((string) ($input['who'] ?? ''));
if ($who !== '') {
    $where[]  = 'admin_name = ?';
    $params[] = $who;
}

$action = trim((string) ($input['action'] ?? ''));
if ($action !== '') {
    $where[]  = 'action = ?';
    $params[] = $action;
}

$clause = $where ? ' WHERE ' . implode(' AND ', $where) : '';
$pdo    = get_db();

$stmt = $pdo->prepare("SELECT COUNT(*) AS n FROM admin_audit{$clause}");
$stmt->execute($params);
$total = (int) $stmt->fetch()['n'];

$stmt = $pdo->prepare(
    "SELECT id, admin_name, role, action, target_type, target_id, detail, ip, created_at
       FROM admin_audit{$clause}
      ORDER BY created_at DESC, id DESC
      LIMIT {$page['perPage']} OFFSET {$page['offset']}"
);
$stmt->execute($params);

json_list($stmt->fetchAll(), $total, $page, [
    // Populates the filter menus without a second request.
    'people'  => $pdo->query('SELECT DISTINCT admin_name FROM admin_audit ORDER BY admin_name')
                     ->fetchAll(PDO::FETCH_COLUMN),
    'actions' => $pdo->query('SELECT DISTINCT action FROM admin_audit ORDER BY action')
                     ->fetchAll(PDO::FETCH_COLUMN),
]);
