<?php

declare(strict_types=1);

/**
 * Shagun Ladies Tailor — Database Connection Service
 * 
 * Provides a secure, reusable PDO connection to the MySQL/MariaDB database.
 * Reads configuration from includes/bootstrap.php and .env.
 * 
 * Architecture Guarantees:
 * - Credentials are read exclusively from environment variables (never hardcoded).
 * - Sensitive connection details (passwords, usernames) are never exposed in errors.
 * - Enforces PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION.
 * - Standardized on utf8mb4 with utf8mb4_unicode_ci collation.
 * - Non-persistent connections by default for predictable resource management.
 * - Singleton instance per request lifecycle with optional fresh connection flag.
 */

require_once __DIR__ . '/bootstrap.php';

/**
 * Get the active PDO database connection instance.
 *
 * @param bool $fresh If true, creates a new PDO instance instead of reusing cached connection.
 * @return PDO The active PDO connection.
 * @throws RuntimeException If connection cannot be established (without credential exposure).
 */
function get_db_connection(bool $fresh = false): PDO
{
    static $instance = null;

    if (!$fresh && $instance instanceof PDO) {
        return $instance;
    }

    $host = (string) app_env('DB_HOST', '127.0.0.1');
    $port = (int) app_env('DB_PORT', '3306');
    $dbname = (string) app_env('DB_NAME', 'shagun_ladies_tailor');
    $user = (string) app_env('DB_USER', 'root');
    $password = (string) app_env('DB_PASSWORD', '');

    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $dbname);

    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_PERSISTENT => false,
        PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci',
    ];

    try {
        $pdo = new PDO($dsn, $user, $password, $options);
    } catch (PDOException $e) {
        // Redact any potential credentials from error messages before logging
        error_log(sprintf('[DB Connection Error] Code %s on %s:%d/%s', $e->getCode(), $host, $port, $dbname));

        // Throw generic safe runtime exception to prevent exposing credentials
        throw new RuntimeException(
            'Unable to connect to the Shagun Ladies Tailor database. Please check database service availability.',
            (int) $e->getCode()
        );
    }

    if (!$fresh) {
        $instance = $pdo;
    }

    return $pdo;
}

/**
 * Convenience alias for get_db_connection().
 */
function db_connect(bool $fresh = false): PDO
{
    return get_db_connection($fresh);
}

/**
 * Safely verify whether a connection can currently be established without throwing unhandled exceptions.
 *
 * @return bool True if connection succeeds and SELECT 1 returns valid response.
 */
function db_is_connected(): bool
{
    try {
        $pdo = get_db_connection();
        $stmt = $pdo->query('SELECT 1');
        return $stmt !== false && $stmt->fetchColumn() !== false;
    } catch (Throwable) {
        return false;
    }
}
