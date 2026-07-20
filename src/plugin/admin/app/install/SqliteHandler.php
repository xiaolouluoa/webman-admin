<?php

namespace plugin\admin\app\install;

use PDO;

class SqliteHandler extends InstallHandler
{
    public static function getDefaultPort(): int
    {
        return 0;
    }

    public function quoteIdentifier(string $name): string
    {
        return "\"$name\"";
    }

    public function getPdo(): PDO
    {
        $dbPath = $this->config['database'] ?? base_path() . '/plugin/admin/database.sqlite';
        return new PDO("sqlite:$dbPath", '', '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 5,
        ]);
    }

    public function ensureDatabase(): PDO
    {
        // SQLite 是文件型，PDO 自动创建文件
        return $this->getPdo();
    }

    public function getExistingTables(PDO $pdo): array
    {
        $smt = $pdo->query("SELECT name FROM sqlite_master WHERE type='table'");
        $tables = [];
        foreach ($smt->fetchAll() as $row) {
            $tables[] = current((array)$row);
        }
        return $tables;
    }

    public function dropTable(PDO $pdo, string $table): void
    {
        $pdo->exec("DROP TABLE IF EXISTS \"$table\"");
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
        return ''; // SQLite 不支持表注释
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
            case 'integer':
                $parts[] = 'INTEGER';
                if (!empty($opts['auto_increment'])) {
                    $parts[] = 'PRIMARY KEY AUTOINCREMENT';
                }
                break;
            case 'bigint':
                $parts[] = 'INTEGER';
                break;
            case 'smallint':
                $parts[] = 'INTEGER';
                break;
            case 'varchar':
                $parts[] = 'TEXT';
                break;
            case 'text':
                $parts[] = 'TEXT';
                break;
            case 'timestamp':
            case 'datetime':
                $parts[] = 'TEXT';
                break;
            case 'date':
                $parts[] = 'TEXT';
                break;
            case 'boolean':
                $parts[] = 'INTEGER';
                break;
            case 'decimal':
                $parts[] = 'REAL';
                break;
            default:
                $parts[] = 'TEXT';
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
        return "UNIQUE ($colStr)";
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
