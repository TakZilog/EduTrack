<?php

declare(strict_types=1);

/**
 * Raised when the database cannot be reached or is not set up.
 *
 * Carries an operator-facing hint naming the actual fix, because the raw PDO
 * message ("SQLSTATE[HY000] [1049]...") tells whoever is running the machine
 * nothing useful, and on a misconfigured server it can echo credentials.
 */
class DatabaseUnavailableException extends RuntimeException
{
    public function __construct(
        public readonly string $hint,
        ?Throwable $previous = null
    ) {
        parent::__construct($hint, 0, $previous);
    }
}

/**
 * Resolves one connection setting.
 *
 * Environment variables win over config.php so the same checkout runs under
 * Laragon or a real server without anyone editing a tracked file.
 */
function db_setting(array $config, string $key, string $default = ''): string
{
    $env = getenv('EDUTRACK_' . strtoupper($key));
    if ($env !== false && $env !== '') {
        return $env;
    }
    return (string) ($config[$key] ?? $default);
}

function get_db(): PDO
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    $configPath = __DIR__ . '/config.php';
    if (!is_file($configPath)) {
        throw new DatabaseUnavailableException(
            'api/config.php is missing. Copy api/config.example.php to api/config.php and fill in the values.'
        );
    }

    $config = require $configPath;

    $host = db_setting($config, 'db_host', '127.0.0.1');
    $port = db_setting($config, 'db_port', '3306');
    $name = db_setting($config, 'db_name', 'edutrack');
    $user = db_setting($config, 'db_user', 'root');
    $pass = db_setting($config, 'db_pass');

    $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";

    try {
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_TIMEOUT            => 5,
        ]);
    } catch (PDOException $e) {
        throw new DatabaseUnavailableException(db_failure_hint($e, $host, $port, $name, $user), $e);
    }

    return $pdo;
}

/**
 * Turns a driver-level failure into something the person running the machine
 * can act on. Never includes the password.
 */
function db_failure_hint(PDOException $e, string $host, string $port, string $name, string $user): string
{
    $driverCode = (int) ($e->errorInfo[1] ?? 0);
    $message    = $e->getMessage();

    return match (true) {
        // Unknown database.
        $driverCode === 1049 || str_contains($message, 'Unknown database') =>
            "The database '{$name}' does not exist on {$host}:{$port}. "
            . 'Create it by importing sql/schema.sql, for example: '
            . 'mysql -uroot < sql/schema.sql',

        // Nothing listening. This normally means Laragon's MySQL is stopped.
        // A dormant XAMPP install is still on this machine with its services
        // disabled; if someone re-enables one it will fight for the port again.
        $driverCode === 2002 || str_contains($message, 'No connection could be made'),
        str_contains($message, "Can't connect") =>
            "No MySQL server answered on {$host}:{$port}. "
            . 'Start MySQL in Laragon, and check nothing else has taken the port.',

        // Bad credentials.
        $driverCode === 1045 || str_contains($message, 'Access denied') =>
            "MySQL refused the user '{$user}'. "
            . 'Check db_user and db_pass in api/config.php. Laragon defaults to root with an empty password.',

        default => 'Could not connect to MySQL. Run "php tools/setup-check.php" for a full diagnosis.',
    };
}

/**
 * Every table the application reads or writes at runtime.
 *
 * The admin panel's three were missing from this list, so a fresh install made
 * from the old sql/schema.sql — which did not create them — passed the setup
 * check and then failed on the first staff screen anyone opened. If a table is
 * named in a query anywhere under api/, it belongs here.
 */
const DB_REQUIRED_TABLES = [
    'users',
    'guard_codes',
    'login_attempts',
    'admins',
    'admin_audit',
    'app_settings',
];

/**
 * True when the schema is present. Used by tools/setup-check.php.
 *
 * @return string[] names of the tables that are missing
 */
function db_missing_tables(PDO $pdo): array
{
    $present = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);

    return array_values(array_diff(DB_REQUIRED_TABLES, array_map('strval', $present)));
}

/**
 * Which sql/migrations files this database has recorded as applied.
 *
 * Returns null when schema_migrations does not exist, which means the database
 * predates migration 004 and cannot say anything about itself. That is not a
 * failure on its own; it just means the answer has to come from inspecting
 * columns instead.
 *
 * @return string[]|null version prefixes, e.g. ['001', '002', '003', '004']
 */
function db_applied_migrations(PDO $pdo): ?array
{
    try {
        return $pdo->query('SELECT version FROM schema_migrations ORDER BY version')
            ->fetchAll(PDO::FETCH_COLUMN);
    } catch (PDOException $e) {
        // 1146 is "table doesn't exist", the one case this function is allowed
        // to answer for. Anything else — a dropped connection, a permission
        // problem, a damaged table — must not be reported to the operator as
        // "your database predates migration 004", sending them to re-run a
        // migration that already succeeded while the real fault stays hidden.
        if ((int) ($e->errorInfo[1] ?? 0) !== 1146) {
            throw $e;
        }
        return null;
    }
}
