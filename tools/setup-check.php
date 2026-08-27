<?php

/**
 * EduTrack environment check.
 *
 * Run from the project root:
 *     php tools/setup-check.php
 *
 * Reports what is wrong and names the fix, so moving the project between
 * Laragon, XAMPP and a server does not turn into guesswork. Read only: it
 * inspects the environment and changes nothing.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This check runs from the command line only.\n");
}

$root = dirname(__DIR__);
chdir($root);

$failures = 0;
$warnings = 0;

function report(string $state, string $label, string $detail = ''): void
{
    global $failures, $warnings;

    $marker = match ($state) {
        'ok'   => '  ok  ',
        'warn' => '  --  ',
        default => '  !!  ',
    };
    if ($state === 'fail') {
        $failures++;
    }
    if ($state === 'warn') {
        $warnings++;
    }

    echo $marker . $label . PHP_EOL;
    if ($detail !== '') {
        foreach (explode("\n", wordwrap($detail, 84)) as $line) {
            echo '        ' . $line . PHP_EOL;
        }
    }
}

function section(string $title): void
{
    echo PHP_EOL . strtoupper($title) . PHP_EOL;
    echo str_repeat('-', 60) . PHP_EOL;
}

echo PHP_EOL . 'EduTrack environment check' . PHP_EOL;
echo 'Project: ' . $root . PHP_EOL;

/* ------------------------------------------------------------------ PHP */

section('php');

PHP_VERSION_ID >= 80100
    ? report('ok', 'PHP ' . PHP_VERSION)
    : report('fail', 'PHP ' . PHP_VERSION . ' is too old', 'EduTrack needs PHP 8.1 or newer. Pick a newer PHP in Laragon under Menu > PHP > Version.');

foreach (['pdo_mysql', 'mbstring', 'openssl', 'json'] as $ext) {
    extension_loaded($ext)
        ? report('ok', "extension {$ext}")
        : report('fail', "extension {$ext} is missing", "Enable extension={$ext} in your php.ini, then restart Apache.");
}

/* --------------------------------------------------------------- Files */

section('files');

is_file("{$root}/api/config.php")
    ? report('ok', 'api/config.php present')
    : report('fail', 'api/config.php missing', 'Copy api/config.example.php to api/config.php and fill in the values. It is gitignored on purpose.');

is_file("{$root}/vendor/autoload.php")
    ? report('ok', 'composer dependencies installed')
    : report('fail', 'vendor/autoload.php missing', 'Run: composer install');

is_readable("{$root}/assets/nodes/nodes-edges.json")
    ? report('ok', 'campus graph file readable')
    : report('fail', 'assets/nodes/nodes-edges.json missing or unreadable', 'The walkthrough and the room picker both read this file directly.');

/* ------------------------------------------------------------- Database */

section('database');

$pdo = null;

if (!is_file("{$root}/api/config.php")) {
    report('fail', 'skipped, no config.php');
} else {
    require_once "{$root}/api/db.php";

    $config = require "{$root}/api/config.php";
    report('ok', sprintf(
        'target %s:%s/%s as %s',
        db_setting($config, 'db_host', '127.0.0.1'),
        db_setting($config, 'db_port', '3306'),
        db_setting($config, 'db_name', 'edutrack'),
        db_setting($config, 'db_user', 'root')
    ));

    try {
        $pdo = get_db();
        report('ok', 'connected, server ' . $pdo->getAttribute(PDO::ATTR_SERVER_VERSION));
    } catch (DatabaseUnavailableException $e) {
        report('fail', 'cannot connect', $e->hint);
    }
}

if ($pdo instanceof PDO) {
    $missing = db_missing_tables($pdo);
    empty($missing)
        ? report('ok', 'all required tables present')
        : report('fail', 'missing tables: ' . implode(', ', $missing), 'Import the schema: mysql -uroot < sql/schema.sql');

    if (!in_array('users', $missing, true)) {
        $columns = $pdo->query('SHOW COLUMNS FROM users')->fetchAll(PDO::FETCH_COLUMN);

        in_array('full_name', $columns, true)
            ? report('ok', 'users.full_name present (migration 002 applied)')
            : report('fail', 'users still has the old username column', 'Apply it: mysql -uroot < sql/migrations/002_full_name.sql');

        // A unique index here would reject the second "Maria Santos" to enrol.
        $unique = $pdo->query(
            "SHOW INDEX FROM users WHERE Column_name = 'full_name' AND Non_unique = 0"
        )->fetch();

        $unique
            ? report('fail', 'users.full_name is UNIQUE', 'Two students can share a name. Drop that index or people will be blocked from registering.')
            : report('ok', 'users.full_name is not unique, as intended');
    }

    if (!in_array('guard_codes', $missing, true)) {
        $columns = $pdo->query('SHOW COLUMNS FROM guard_codes')->fetchAll(PDO::FETCH_COLUMN);
        $strays  = array_intersect(['student_name', 'student_id'], $columns);

        empty($strays)
            ? report('ok', 'guard_codes has no student_name/student_id, as intended')
            : report('fail', 'guard_codes has stray NOT NULL columns: ' . implode(', ', $strays), 'The guard types nothing at issue time, so issue-code.php never fills these and every insert fails. Drop them.');

        $counts = $pdo->query(
            'SELECT COUNT(*) total,
                    SUM(used = 1) redeemed,
                    SUM(used = 0 AND expires_at > NOW()) active
               FROM guard_codes'
        )->fetch();

        report('ok', sprintf(
            'codes: %d total, %d redeemed, %d active',
            $counts['total'],
            (int) $counts['redeemed'],
            (int) $counts['active']
        ));

        $users = $pdo->query('SELECT COUNT(*) c, SUM(email_verified = 1) v FROM users')->fetch();
        report('ok', sprintf('students: %d registered, %d verified', $users['c'], (int) $users['v']));
    }
}

/* ---------------------------------------------------------- Campus graph */

section('campus graph');

$graphPath = "{$root}/assets/nodes/nodes-edges.json";
if (is_readable($graphPath)) {
    $graph = json_decode((string) file_get_contents($graphPath), true);

    if (!is_array($graph) || !isset($graph['nodes'], $graph['edges'], $graph['rooms'])) {
        report('fail', 'graph file is not valid, or is missing nodes/edges/rooms');
    } else {
        report('ok', sprintf(
            '%d nodes, %d edges, %d rooms',
            count($graph['nodes']),
            count($graph['edges']),
            count($graph['rooms'])
        ));

        // Reachability from the gate, the same walk the walkthrough performs.
        $adjacency = [];
        foreach ($graph['edges'] as $edge) {
            $adjacency[$edge['from_node']][] = $edge['to_node'];
            $adjacency[$edge['to_node']][]   = $edge['from_node'];
        }

        $start = null;
        foreach ($graph['nodes'] as $node) {
            if (($node['type'] ?? '') === 'landmark') {
                $start = $node['node_id'];
                break;
            }
        }

        $seen  = [$start => true];
        $queue = [$start];
        while ($queue) {
            foreach ($adjacency[array_shift($queue)] ?? [] as $next) {
                if (!isset($seen[$next])) {
                    $seen[$next]= true;
                    $queue[]    = $next;
                }
            }
        }

        $stranded = [];
        foreach ($graph['rooms'] as $room) {
            if (!isset($seen[$room['node_id']])) {
                $stranded[] = $room['room_name'];
            }
        }

        empty($stranded)
            ? report('ok', 'every room is reachable from the gate')
            : report('warn', 'unreachable rooms: ' . implode(', ', $stranded), 'These appear in the picker but dead-end with "No route found".');

        $names      = array_column($graph['rooms'], 'room_name');
        $duplicates = array_keys(array_filter(array_count_values($names), static fn ($n) => $n > 1));

        empty($duplicates)
            ? report('ok', 'room names are unique')
            : report('warn', 'duplicate room names: ' . implode(', ', $duplicates), 'The walkthrough resolves rooms by name, so only the first of each duplicate can ever be routed to.');
    }
} else {
    report('fail', 'skipped, graph file unreadable');
}

/* --------------------------------------------------------------- Verdict */

echo PHP_EOL . str_repeat('=', 60) . PHP_EOL;

if ($failures > 0) {
    echo "{$failures} problem(s) must be fixed before the app will run correctly." . PHP_EOL . PHP_EOL;
    exit(1);
}

echo $warnings > 0
    ? "Environment is usable. {$warnings} data issue(s) worth fixing." . PHP_EOL . PHP_EOL
    : 'Everything checks out.' . PHP_EOL . PHP_EOL;

exit(0);
