<?php

namespace plugin\admin\app\install;

use PDO;

class PgsqlHandler extends InstallHandler
{
    public static function getDefaultPort(): int
    {
        return 5432;
    }

    public function quoteIdentifier(string $name): string
    {
        return "\"$name\"";
    }

    public function getPdo(): PDO
    {
        $host = $this->config['host'] ?? '127.0.0.1';
        $port = (int)($this->config['port'] ?? 5432);
        $dbname = $this->config['database'] ?? '';
        $username = $this->config['username'] ?? '';
        $password = $this->config['password'] ?? '';
        // 优先用 pg_connect（Windows 下 PDO 有卡死问题）
        if (function_exists('pg_connect')) {
            $connStr = "host=$host port=$port dbname=$dbname user=$username password=$password";
            $pg = @pg_connect($connStr);
            if (!$pg) {
                $err = error_get_last();
                throw new \RuntimeException($err['message'] ?? 'PostgreSQL 连接失败');
            }
            pg_close($pg);
        }
        // 再用 PDO 连接
        $dsn = "pgsql:host=$host;port=$port;dbname=$dbname";
        return new PDO($dsn, $username, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
    }

    public function ensureDatabase(): PDO
    {
        $host = $this->config['host'] ?? '127.0.0.1';
        $port = (int)($this->config['port'] ?? 5432);
        $dbname = $this->config['database'] ?? '';
        $username = $this->config['username'] ?? '';
        $password = $this->config['password'] ?? '';

        // 先连到默认的管理库检查/创建目标库
        if ($dbname) {
            $this->createDatabaseIfNotExists($host, $port, $dbname, $username, $password);
        }
        // 再连到目标库
        return $this->getPdo();
    }

    private function createDatabaseIfNotExists(string $host, int $port, string $dbname, string $username, string $password): void
    {
        $defaultDbs = ['postgres', 'template1'];
        $connected = false;
        foreach ($defaultDbs as $defaultDb) {
            try {
                $pdo = new PDO("pgsql:host=$host;port=$port;dbname=$defaultDb", $username, $password, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                ]);
                $connected = true;
                break;
            } catch (\Throwable $e) {
                continue;
            }
        }
        if (!$connected) return;

        $stmt = $pdo->query("SELECT 1 FROM pg_database WHERE datname = " . $pdo->quote($dbname));
        if (!$stmt->fetch()) {
            $safe = str_replace('"', '""', $dbname);
            $pdo->exec("CREATE DATABASE \"$safe\"");
        }
    }

    public function getExistingTables(PDO $pdo): array
    {
        $smt = $pdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema = 'public'");
        $tables = [];
        foreach ($smt->fetchAll() as $row) {
            $tables[] = current((array)$row);
        }
        return $tables;
    }

    public function dropTable(PDO $pdo, string $table): void
    {
        $pdo->exec("DROP TABLE IF EXISTS \"$table\" CASCADE");
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
        return "CREATE TABLE IF NOT EXISTS " . $q($tableName) . " (\n" . implode(",\n", $cols) . "\n)";
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
        // PostgreSQL 通过 COMMENT ON 语句设置表注释
        $q = [$this, 'quoteIdentifier'];
        $safe = str_replace("'", "''", $comment);
        return "COMMENT ON TABLE " . $q($table) . " IS '$safe'";
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
                $parts[] = 'SERIAL';
                break;
            case 'integer':
                $parts[] = 'INTEGER';
                break;
            case 'bigint':
                $parts[] = 'BIGINT';
                break;
            case 'smallint':
                $parts[] = 'SMALLINT';
                break;
            case 'varchar':
                $len = $opts['length'] ?? 255;
                $parts[] = "VARCHAR($len)";
                break;
            case 'text':
                $parts[] = 'TEXT';
                break;
            case 'timestamp':
                $parts[] = 'TIMESTAMP(0)';
                break;
            case 'date':
                $parts[] = 'DATE';
                break;
            case 'boolean':
                $parts[] = 'BOOLEAN';
                break;
            case 'decimal':
                $precision = $opts['precision'] ?? 10;
                $scale = $opts['scale'] ?? 2;
                $parts[] = "DECIMAL($precision, $scale)";
                break;
            default:
                $parts[] = $type;
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
        $prefix = $this->config['table_prefix'] ?? '';
        $colStr = implode(',', array_map($q, explode(',', $columns)));
        if ($type === 'primary') {
            return "PRIMARY KEY ($colStr)";
        }
        $constraintName = $q($prefix . $tableName . '_' . $name);
        return "CONSTRAINT $constraintName UNIQUE ($colStr)";
    }

    /**
     * 生成索引定义（返回单独的 CREATE INDEX 语句）
     */
    public function indexDef(string $tableName, string $name, string $columns): string
    {
        $q = [$this, 'quoteIdentifier'];
        $colStr = implode(',', array_map($q, explode(',', $columns)));
        $idxName = $q($tableName . '_' . $name);
        return "CREATE INDEX $idxName ON " . $q($tableName) . " ($colStr)";
    }
}
