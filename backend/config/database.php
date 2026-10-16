<?php
/**
 * EVORA HRMS — Database Connection Configuration
 * 
 * Reusable Core PHP 8.2 + PDO MySQL connection manager.
 * Safely parses root .env and provides a singleton connection instance.
 */

declare(strict_types=1);

class Database
{
    private static ?PDO $instance = null;
    private static ?array $envConfig = null;

    /**
     * Parse root .env configuration safely
     * 
     * @param string|null $customPath
     * @return array
     */
    public static function loadEnv(?string $customPath = null): array
    {
        if (self::$envConfig !== null && $customPath === null) {
            return self::$envConfig;
        }

        $envFile = $customPath ?? (dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '.env');

        if (!file_exists($envFile) || !is_readable($envFile)) {
            return [];
        }

        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $config = [];

        foreach ($lines as $line) {
            $line = trim($line);
            // Skip empty lines or comments
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (str_contains($line, '=')) {
                [$key, $value] = explode('=', $line, 2);
                $key = trim($key);
                $value = trim($value);
                // Strip surrounding single or double quotes
                $value = trim($value, "\"'");
                $config[$key] = $value;
            }
        }

        if ($customPath === null) {
            self::$envConfig = $config;
        }

        return $config;
    }

    /**
     * Obtain a reusable PDO connection (Singleton)
     * 
     * @param bool $selectDatabase Whether to bind to DB_NAME directly
     * @return PDO
     * @throws PDOException
     */
    public static function getConnection(bool $selectDatabase = true): PDO
    {
        if (self::$instance !== null && $selectDatabase) {
            return self::$instance;
        }

        $env = self::loadEnv();

        $host = $env['DB_HOST'] ?? '127.0.0.1';
        $port = (int)($env['DB_PORT'] ?? 3306);
        $name = $env['DB_NAME'] ?? 'evora_hrms';
        $user = $env['DB_USER'] ?? 'root';
        $pass = $env['DB_PASS'] ?? '';

        $dsn = "mysql:host={$host};port={$port};charset=utf8mb4";
        if ($selectDatabase && !empty($name)) {
            $dsn .= ";dbname={$name}";
        }

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
        ];

        try {
            $pdo = new PDO($dsn, $user, $pass, $options);

            if ($selectDatabase) {
                self::$instance = $pdo;
            }

            return $pdo;
        } catch (PDOException $e) {
            // Log generic connection error to server log without leaking credentials to client
            error_log(sprintf(
                '[EVORA DB ERROR] Failed to connect to MySQL on %s:%d - Code: %s',
                $host,
                $port,
                (string)$e->getCode()
            ));
            throw $e;
        }
    }

    /**
     * Clear cached singleton connection (useful in CLI or test workflows)
     */
    public static function resetConnection(): void
    {
        self::$instance = null;
    }
}

/**
 * Global convenience helper for retrieving the PDO connection
 */
if (!function_exists('getDbConnection')) {
    function getDbConnection(bool $selectDatabase = true): PDO
    {
        return Database::getConnection($selectDatabase);
    }
}
