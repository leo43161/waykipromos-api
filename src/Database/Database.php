<?php

declare(strict_types=1);

namespace App\Database;

use App\Config\Env;
use PDO;

class Database
{
    private static ?PDO $instance = null;

    /**
     * Obtains the shared PDO instance.
     */
    public static function getConnection(): PDO
    {
        if (self::$instance === null) {
            $rawHost = (string) Env::get('DB_HOST', '127.0.0.1');
            // Sanitización defensiva: remueve prefijos http:// o https:// y barras finales
            $host = preg_replace('#^https?://#i', '', rtrim($rawHost, '/'));

            $port = (string) Env::get('DB_PORT', '3306');
            $db   = (string) Env::get('DB_DATABASE', Env::get('DB_NAME', 'huakipromo'));
            $user = (string) Env::get('DB_USERNAME', Env::get('DB_USER', 'root'));
            $pass = (string) Env::get('DB_PASSWORD', '');

            $dsn = "mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4";

            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_TIMEOUT            => 5,
            ];

            self::$instance = new PDO($dsn, $user, $pass, $options);
        }

        return self::$instance;
    }

    /**
     * Allows overriding the connection (useful for unit/feature tests).
     */
    public static function setConnection(?PDO $pdo): void
    {
        self::$instance = $pdo;
    }
}
