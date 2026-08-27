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
 * Laragon, XAMPP, or a real server without anyone editing a tracked file.
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

        // Nothing listening. Under Laragon this usually means MySQL is stopped,
        // or another MySQL (a leftover XAMPP install) has taken the port.
        $driverCode === 2002 || str_contains($message, 'No connection could be made'),
        str_contains($message, "Can't connect") =>
            "No MySQL server answered on {$host}:{$port}. "
            . 'Start MySQL in Laragon, and check no other MySQL or XAMPP instance is holding the port.',

        // Bad credentials.
        $driverCode === 1045 || str_contains($message, 'Access denied') =>
            "MySQL refused the user '{$user}'. "
            . 'Check db_user and db_pass in api/config.php. Laragon and XAMPP both default to root with an empty password.',

        default => 'Could not connect to MySQL. Run "php tools/setup-check.php" for a full diagnosis.',
    };
}

/**
 * True when the schema is present. Used by tools/setup-check.php.
 *
 * @return string[] names of the tables that are missing
 */
function db_missing_tables(PDO $pdo): array
{
    $required = ['users', 'guard_codes', 'login_attempts'];
    $present  = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);

    return array_values(array_diff($required, array_map('strval', $present)));
}
