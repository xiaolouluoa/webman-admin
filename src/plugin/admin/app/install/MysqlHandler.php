<?php

namespace plugin\admin\app\install;

use PDO;

class MysqlHandler extends InstallHandler
{
    public static function getDefaultPort(): int
    {
        return 3306;
    }

    public function quoteIdentifier(string $name): string
    {
        return "`$name`";
    }

    public function getPdo(): PDO
    {
        $host = $this->config['host'] ?? '127.0.0.1';
        $port = $this->config['port'] ?? 3306;
        $dsn = "mysql:host=$host;port=$port;";
        if (!empty($this->config['database'])) {
            $dsn .= "dbname=" . $this->config['database'];
        }
        return new PDO($dsn, $this->config['username'] ?? '', $this->config['password'] ?? '', [
            PDO::MYSQL_ATTR_INIT_COMMAND => "set names utf8mb4",
            PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => 5,
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
    }

    public function ensureDatabase(): PDO
    {
        $dbname = $this->config['database'] ?? '';
        $host = $this->config['host'] ?? '127.0.0.1';
        $port = $this->config['port'] ?? 3306;
        $username = $this->config['username'] ?? '';
        $password = $this->config['password'] ?? '';

        // 先连到服务端（不指定库）
        $dsn = "mysql:host=$host;port=$port;";
        $pdo = new PDO($dsn, $username, $password, [
            PDO::MYSQL_ATTR_INIT_COMMAND => "set names utf8mb4",
            PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => 5,
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        if ($dbname) {
            $pdo->exec("CREATE DATABASE IF NOT EXISTS `$dbname` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $pdo->exec("USE `$dbname`");
        }
        return $pdo;
    }

    public function getExistingTables(PDO $pdo): array
    {
        $smt = $pdo->query("show tables");
        $tables = [];
        foreach ($smt->fetchAll() as $row) {
            $tables[] = current((array)$row);
        }
        return $tables;
    }

    public function dropTable(PDO $pdo, string $table): void
    {
        $pdo->exec("DROP TABLE IF EXISTS `$table`");
    }

    public function getCreateTableSql(string $tableName, array $columns, array $keys = []): string
    {
        $q = [$this, 'quoteIdentifier'];
        $cols = [];
        foreach ($columns as $col) {
            $cols[] = "  " . $col;
        }
        foreach ($keys as $key) {
            $cols[] = "  " . $key;
        }
        return "CREATE TABLE IF NOT EXISTS " . $q($tableName) . " (\n" . implode(",\n", $cols) . "\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
    }

    public function getInsertSql(string $table, array $data): string
    {
        $q = [$this, 'quoteIdentifier'];
        $columns = implode(',', array_map($q, array_keys($data)));
        $values = implode(',', array_map(fn($k) => ":$k", array_keys($data)));
        return "INSERT INTO " . $q($table) . " ($columns) VALUES ($values)";
    }

    public function getTableCommentSql(string $table, string $comment): string
    {
        $q = [$this, 'quoteIdentifier'];
        $safe = str_replace("'", "\'", $comment);
        return "ALTER TABLE " . $q($table) . " COMMENT='$safe'";
    }
}
