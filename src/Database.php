<?php
declare(strict_types=1);

namespace App;

use PDO;
use PDOException;

class Database
{
    private static ?PDO $connection = null;

    public static function getConnection(?string $dbNameOverride = null): PDO
    {
        if ($dbNameOverride === null && self::$connection !== null) {
            return self::$connection;
        }

        EnvLoader::load(dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env');

        $host = getenv('DB_HOST') ?: '127.0.0.1';
        $port = getenv('DB_PORT') ?: '5432';
        $db   = $dbNameOverride ?? (getenv('DB_NAME') ?: 'restaurante_db');
        $user = getenv('DB_USER') ?: 'postgres';
        $pass = getenv('DB_PASS') ?: '';

        $dsn = "pgsql:host={$host};port={$port};dbname={$db}";

        try {
            $pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            if ($dbNameOverride === null) {
                self::$connection = $pdo;
            }
            return $pdo;
        } catch (PDOException $e) {
            throw new \RuntimeException('Error de conexión a PostgreSQL: ' . $e->getMessage(), 0, $e);
        }
    }

    public static function resetConnection(): void
    {
        self::$connection = null;
    }
}
