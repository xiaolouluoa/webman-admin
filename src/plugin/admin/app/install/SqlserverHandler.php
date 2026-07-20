<?php

namespace plugin\admin\app\install;

use PDO;

class SqlserverHandler extends InstallHandler
{
    public static function getDefaultPort(): int
    {
        return 1433;
    }

    public function quoteIdentifier(string $name): string
    {
        return "[$name]";
    }

    public function getPdo(): PDO
    {
        $host = $this->config['host'] ?? '127.0.0.1';
        $port = $this->config['port'] ?? 1433;
        $dsn = "sqlsrv:Server=$host,$port;";
        if (!empty($this->config['database'])) {
            $dsn .= "Database=" . $this->config['database'];
        }
        return new PDO($dsn, $this->config['username'] ?? '', $this->config['password'] ?? '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 5,
        ]);
    }

    public function ensureDatabase(): PDO
    {
        $dbname = $this->config['database'] ?? '';
        $host = $this->config['host'] ?? '127.0.0.1';
        $port = $this->config['port'] ?? 1433;
        $username = $this->config['username'] ?? '';
        $password = $this->config['password'] ?? '';

        // 先连到 master 库检查/创建目标库
        $dsn = "sqlsrv:Server=$host,$port;Database=master";
        $pdo = new PDO($dsn, $username, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 5,
        ]);
        if ($dbname) {
            $safe = str_replace("'", "''", $dbname);
            $pdo->exec("IF NOT EXISTS (SELECT name FROM sys.databases WHERE name = '$safe') CREATE DATABASE [$dbname]");
        }
        // 再连到目标库
        return $this->getPdo();
    }

    public function getExistingTables(PDO $pdo): array
    {
        $smt = $pdo->query("SELECT table_name FROM information_schema.tables WHERE table_type = 'BASE TABLE'");
        $tables = [];
        foreach ($smt->fetchAll() as $row) {
            $tables[] = current((array)$row);
        }
        return $tables;
    }

    public function dropTable(PDO $pdo, string $table): void
    {
        $pdo->exec("DROP TABLE IF EXISTS [$table]");
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
        return "CREATE TABLE " . $q($tableName) . " (\n" . implode(",\n", $cols) . "\n)";
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
        $safe = str_replace("'", "''", $comment);
        return "EXEC sp_addextendedproperty N'MS_Description', N'$safe', N'SCHEMA', N'dbo', N'TABLE', " . $q($table);
    }

    /**
     * 生成列定义
     */
    public function columnDef(string $name, string $type, array $opts = []): string
    {
        $q = [$this, 'quoteIdentifier'];
        $parts = [$q($name)];

        switch ($type) {
            case 'serial':
                $parts[] = 'INT IDENTITY(1,1)';
                break;
            case 'integer':
                $parts[] = 'INT';
                break;
            case 'bigint':
                $parts[] = 'BIGINT';
                break;
            case 'smallint':
                $parts[] = 'SMALLINT';
                break;
            case 'tinyint':
                $parts[] = 'TINYINT';
                break;
            case 'varchar':
                $len = $opts['length'] ?? 255;
                $parts[] = $len > 4000 ? 'NVARCHAR(MAX)' : "NVARCHAR($len)";
                break;
            case 'text':
                $parts[] = 'NVARCHAR(MAX)';
                break;
            case 'timestamp':
            case 'datetime':
                $parts[] = 'DATETIME2';
                break;
            case 'date':
                $parts[] = 'DATE';
                break;
            case 'boolean':
                $parts[] = 'BIT';
                break;
            case 'decimal':
                $precision = $opts['precision'] ?? 10;
                $scale = $opts['scale'] ?? 2;
                $parts[] = "DECIMAL($precision, $scale)";
                break;
            default:
                $parts[] = 'NVARCHAR(255)';
        }

        if (!empty($opts['not_null'])) {
            $parts[] = 'NOT NULL';
        }
        if (array_key_exists('default', $opts) && $opts['default'] !== null) {
            $default = is_string($opts['default']) ? "'" . str_replace("'", "''", $opts['default']) . "'" : $opts['default'];
            $parts[] = "DEFAULT $default";
        }

        return implode(' ', $parts);
    }

    /**
     * 生成约束定义
     */
    public function constraintDef(string $name, string $columns, string $type = 'unique'): string
    {
        $q = [$this, 'quoteIdentifier'];
        $colStr = implode(',', array_map($q, explode(',', $columns)));
        if ($type === 'primary') {
            return "PRIMARY KEY ($colStr)";
        }
        return "CONSTRAINT " . $q($name) . " UNIQUE ($colStr)";
    }

    /**
     * 生成索引定义
     */
    public function indexDef(string $tableName, string $name, string $columns): string
    {
        $q = [$this, 'quoteIdentifier'];
        $colStr = implode(',', array_map($q, explode(',', $columns)));
        $idxName = $q($tableName . '_' . $name);
        return "CREATE INDEX $idxName ON " . $q($tableName) . " ($colStr)";
    }
}
