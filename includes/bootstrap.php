<?php

declare(strict_types=1);

/**
 * Loads local .env values without external dependencies.
 * Server-provided environment variables always take precedence, which keeps
 * the same setup compatible with Hostinger deployment configuration.
 */
if (!defined('APP_BOOTSTRAPPED')) {
    define('APP_BOOTSTRAPPED', true);

    $envFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env';

    if (is_readable($envFile)) {
        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        if ($lines !== false) {
            foreach ($lines as $line) {
                $line = trim($line);

                if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                    continue;
                }

                [$name, $value] = explode('=', $line, 2);
                $name = trim($name);
                $value = trim($value);

                if ($name === '' || getenv($name) !== false) {
                    continue;
                }

                if (strlen($value) >= 2 && (($value[0] === '"' && substr($value, -1) === '"') || ($value[0] === "'" && substr($value, -1) === "'"))) {
                    $value = substr($value, 1, -1);
                }

                putenv($name . '=' . $value);
                $_ENV[$name] = $value;
            }
        }
    }
}

/**
 * Reads a configuration value from a server environment variable or .env.
 */
function app_env(string $name, ?string $default = null): ?string
{
    $value = getenv($name);

    if ($value !== false) {
        return $value;
    }

    return $_ENV[$name] ?? $default;
}

/**
 * Returns the URL path to use for project assets and local links.
 */
function app_base_path(string $appUrl): string
{
    $path = parse_url($appUrl, PHP_URL_PATH) ?: '/';
    $path = '/' . trim($path, '/');

    return $path === '/' ? '/' : $path . '/';
}
