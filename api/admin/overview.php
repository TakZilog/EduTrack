<?php

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/graph-lib.php';

admin_boot('student.view');

$pdo = get_db();

$students = $pdo->query(
    'SELECT
        COUNT(*)                                              AS total,
        SUM(email_verified = 1 AND deactivated_at IS NULL)    AS verified,
        SUM(email_verified = 0 AND deactivated_at IS NULL)    AS pending,
        SUM(deactivated_at IS NOT NULL)                       AS turned_off
     FROM users'
)->fetch();

$codes = $pdo->query(
    'SELECT
        SUM(used = 0 AND revoked_at IS NULL AND expires_at > NOW())  AS active,
        SUM(used = 1)                                                AS redeemed,
        SUM(used = 1 AND used_at > DATE_SUB(NOW(), INTERVAL 7 DAY))  AS redeemed_week,
        SUM(used = 0 AND revoked_at IS NULL AND expires_at <= NOW()) AS expired_unused
     FROM guard_codes'
)->fetch();

$graph    = load_graph();
$problems = graph_health($graph);

$recent = [];
if (can('audit.view')) {
    $recent = $pdo->query(
        'SELECT admin_name, action, detail, created_at
           FROM admin_audit
          ORDER BY created_at DESC
          LIMIT 8'
    )->fetchAll();
}

json_ok([
    'students' => [
        'total'      => (int) $students['total'],
        'verified'   => (int) $students['verified'],
        'pending'    => (int) $students['pending'],
        'turnedOff'  => (int) $students['turned_off'],
    ],
    'codes' => [
        'active'        => (int) $codes['active'],
        'redeemed'      => (int) $codes['redeemed'],
        'redeemedWeek'  => (int) $codes['redeemed_week'],
        'expiredUnused' => (int) $codes['expired_unused'],
    ],
    'rooms' => [
        'total'    => count($graph['rooms']),
        'problems' => count($problems),
    ],
    'problems' => $problems,
    'recent'   => $recent,
]);
