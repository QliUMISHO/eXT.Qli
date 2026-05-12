<?php
declare(strict_types=1);

class Database
{
    public static function connect(): PDO
    {
        if (!defined('DB_HOST')) {
            $config = dirname(__DIR__, 2) . '/config/config.php';

            if (is_file($config)) {
                require_once $config;
            }
        }

        if (!defined('DB_HOST') || !defined('DB_NAME') || !defined('DB_USER') || !defined('DB_PASS')) {
            throw new RuntimeException('Database constants are not defined. Check config/config.php.');
        }

        $port = defined('DB_PORT') ? (int)DB_PORT : 3306;

        $dsn = 'mysql:host=' . DB_HOST . ';port=' . $port . ';dbname=' . DB_NAME . ';charset=utf8mb4';

        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);

        $pdo->exec("SET time_zone = '+08:00'");

        return $pdo;
    }
}