<?php

namespace Database;

use PDO;
use PDOException;
use Core\Config;

class Database
{
    private static $instance = null;
    private $connection;

    private function __construct()
    {
        $host = Config::get('DB_HOST', 'localhost');
        $db   = Config::get('DB_NAME');
        $user = Config::get('DB_USER', 'root');
        $pass = Config::get('DB_PASS', '');
        $charset = 'utf8mb4';

        $dsn = "mysql:host=$host;dbname=$db;charset=$charset";
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        try {
            if (!in_array('mysql', PDO::getAvailableDrivers())) {
                throw new PDOException("خطا: درایور 'pdo_mysql' در PHP شما فعال نیست. لطفا فایل php.ini را ویرایش کنید.");
            }
            $this->connection = new PDO($dsn, $user, $pass, $options);
        } catch (PDOException $e) {
            die("<div style='direction:rtl; font-family:tahoma; padding:20px; border:1px solid red; background:#fff5f5;'>" . 
                "<b>خطای اتصال به دیتابیس:</b><br>" . $e->getMessage() . "</div>");
        }
    }

    public static function getInstance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function getConnection()
    {
        return $this->connection;
    }

    public function query($sql, $params = [])
    {
        $stmt = $this->connection->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /**
     * Prepare a SQL statement for execution.
     *
     * @param string $sql
     * @return \PDOStatement
     */
    public function prepare(string $sql): \PDOStatement
    {
        return $this->connection->prepare($sql);
    }

    /**
     * Get the last inserted row ID.
     */
    public function lastInsertId(): string
    {
        return $this->connection->lastInsertId();
    }

    // Method to get the raw PDO object if needed for advanced operations
    public function pdo()
    {
        return $this->connection;
    }
}
