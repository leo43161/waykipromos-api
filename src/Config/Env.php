<?php

declare(strict_types=1);

namespace App\Config;

class Env
{
    /**
     * Loads environment variables from a .env file.
     */
    public static function load(string $path): void
    {
        if (!file_exists($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (!str_contains($line, '=')) {
                continue;
            }

            [$name, $value] = explode('=', $line, 2);
            $name = trim($name);
            $value = trim($value);

            // Eliminar comillas dobles o simples si las tiene
            if (
                (str_starts_with($value, '"') && str_ends_with($value, '"')) ||
                (str_starts_with($value, "'") && str_ends_with($value, "'"))
            ) {
                $value = substr($value, 1, -1);
            }

            if (!array_key_exists($name, $_ENV)) {
                $_ENV[$name] = $value;
                putenv("{$name}={$value}");
            }
        }
    }

    /**
     * Loads configuration from a cPanel PHP config file (config.php) if present.
     */
    public static function loadConfigPhp(string $path): void
    {
        if (!file_exists($path)) {
            return;
        }

        if (!defined('WAYKI_API')) {
            define('WAYKI_API', true);
        }

        $config = require $path;
        if (!is_array($config)) {
            return;
        }

        $map = [];

        if (isset($config['env'])) {
            $map['APP_ENV'] = (string) $config['env'];
        }

        if (isset($config['port'])) {
            $map['PORT'] = (string) $config['port'];
        }

        if (isset($config['db']) && is_array($config['db'])) {
            if (isset($config['db']['host'])) $map['DB_HOST'] = (string) $config['db']['host'];
            if (isset($config['db']['port'])) $map['DB_PORT'] = (string) $config['db']['port'];
            if (isset($config['db']['name'])) $map['DB_NAME'] = (string) $config['db']['name'];
            if (isset($config['db']['user'])) $map['DB_USER'] = (string) $config['db']['user'];
            if (isset($config['db']['pass'])) $map['DB_PASSWORD'] = (string) $config['db']['pass'];
        }

        if (isset($config['jwt']['secret'])) {
            $map['JWT_SECRET'] = (string) $config['jwt']['secret'];
        }

        if (isset($config['google']['client_id'])) {
            $map['GOOGLE_CLIENT_ID'] = (string) $config['google']['client_id'];
        }

        foreach ($map as $key => $val) {
            if (!array_key_exists($key, $_ENV) || $_ENV[$key] === '') {
                $_ENV[$key] = $val;
                putenv("{$key}={$val}");
            }
        }
    }

    /**
     * Retrieves an environment variable with optional default.
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public static function get(string $key, $default = null)
    {
        $val = $_ENV[$key] ?? getenv($key);
        if ($val === false || $val === null || $val === '') {
            return $default;
        }

        $lower = strtolower((string) $val);
        switch ($lower) {
            case 'true':
            case '(true)':
                return true;
            case 'false':
            case '(false)':
                return false;
            case 'null':
            case '(null)':
                return null;
            case 'empty':
            case '(empty)':
                return '';
            default:
                return $val;
        }
    }
}
