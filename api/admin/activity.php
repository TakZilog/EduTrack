<?php

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

$input = admin_boot('audit.view');

$page = paging($input, 20);

$where  = [
    "action IN ('admin.login', 'admin.logout', 'admin.create', 'admin.enable',
                'admin.disable', 'settings.save', 'access.denied',
                'room.register', 'room.update', 'room.remove',
                'route.create', 'route.update', 'route.remove',
                'walkthrough.add', 'walkthrough.update', 'walkthrough.photo.remove',
                'map.issue.detected', 'map.issue.resolved', 'user.create',
                'user.access.change', 'admin.failed_login')"
];
            $scope = $where[0];
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

$search = trim((string) ($input['search'] ?? ''));
if ($search !== '') {
    $where[] = '(admin_name LIKE ? OR action LIKE ? OR target_type LIKE ? OR target_id LIKE ? OR detail LIKE ?)';
    $term = '%' . $search . '%';
    array_push($params, $term, $term, $term, $term, $term);
}

$date = trim((string) ($input['date'] ?? ''));
$dateFrom = trim((string) ($input['date_from'] ?? ''));
$dateTo = trim((string) ($input['date_to'] ?? ''));
if ($date === 'today') {
    $where[] = 'created_at >= CURDATE()';
} elseif ($date === 'yesterday') {
    $where[] = 'created_at >= CURDATE() - INTERVAL 1 DAY AND created_at < CURDATE()';
} elseif ($date === '7d') {
    $where[] = 'created_at >= NOW() - INTERVAL 7 DAY';
} elseif ($date === '30d') {
    $where[] = 'created_at >= NOW() - INTERVAL 30 DAY';
} else {
    if (preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $dateFrom)) {
        $where[] = 'created_at >= ?';
        $params[] = $dateFrom . ' 00:00:00';
    }
    if (preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $dateTo)) {
        $where[] = 'created_at < DATE_ADD(?, INTERVAL 1 DAY)';
        $params[] = $dateTo . ' 00:00:00';
    }
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
    'people'  => $pdo->query("SELECT DISTINCT admin_name FROM admin_audit WHERE {$scope} ORDER BY admin_name")
                     ->fetchAll(PDO::FETCH_COLUMN),
    'actions' => $pdo->query("SELECT DISTINCT action FROM admin_audit WHERE {$scope} ORDER BY action")
                     ->fetchAll(PDO::FETCH_COLUMN),
]);
