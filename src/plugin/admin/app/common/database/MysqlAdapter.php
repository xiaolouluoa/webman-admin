<?php

namespace plugin\admin\app\common\database;

use support\Db;

/**
 * MySQL数据库适配器
 */
class MysqlAdapter extends AbstractAdapter
{
    /**
     * {@inheritdoc}
     */
    public function getConnectionName(): string
    {
        return 'plugin.admin.mysql';
    }

    /**
     * {@inheritdoc}
     */
    public function getDriver(): string
    {
        return 'mysql';
    }

    /**
     * {@inheritdoc}
     */
    public function quoteIdentifier(string $name): string
    {
        return '`' . str_replace('`', '``', $name) . '`';
    }

    /**
     * {@inheritdoc}
     */
    public function getTableColumns(string $table): array
    {
        $rows = $this->connection()->select("desc {$this->quoteIdentifier($table)}");
        $columns = [];
        foreach ($rows as $row) {
            $columns[$row->Field] = [
                'field' => $row->Field,
                'type' => $row->Type,
                'null' => $row->Null,
                'key' => $row->Key,
                'default' => $row->Default,
                'extra' => $row->Extra,
                'comment' => '',
            ];
        }
        // 获取注释
        $db = $this->getDatabaseName();
        $comments = $this->connection()->select(
            "SELECT COLUMN_NAME, COLUMN_COMMENT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND table_name = ?",
            [$db, $table]
        );
        foreach ($comments as $row) {
            if (isset($columns[$row->COLUMN_NAME])) {
                $columns[$row->COLUMN_NAME]['comment'] = $row->COLUMN_COMMENT;
            }
        }
        return $columns;
    }

    /**
     * {@inheritdoc}
     */
    public function getTableComment(string $table): ?string
    {
        $db = $this->getDatabaseName();
        $rows = $this->connection()->select(
            "SELECT TABLE_COMMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?",
            [$db, $table]
        );
        return $rows[0]->TABLE_COMMENT ?? null;
    }

    /**
     * {@inheritdoc}
     */
    public function getTableIndexes(string $table): array
    {
        $rows = $this->connection()->select("SHOW INDEX FROM {$this->quoteIdentifier($table)}");
        $indexes = [];
        foreach ($rows as $row) {
            $keyName = $row->Key_name;
            if ($keyName === 'PRIMARY') {
                continue;
            }
            if (!isset($indexes[$keyName])) {
                $indexes[$keyName] = [
                    'name' => $keyName,
                    'columns' => [],
                    'type' => $row->Non_unique == 0 ? 'unique' : 'normal',
                ];
            }
            $indexes[$keyName]['columns'][] = $row->Column_name;
        }
        return array_reverse($indexes, true);
    }

    /**
     * {@inheritdoc}
     */
    public function getTables(string $keyword = '', string $field = 'TABLE_NAME', string $order = 'asc', int $offset = 0, int $limit = 10): array
    {
        $db = $this->getDatabaseName();
        $where = "TABLE_SCHEMA = ?";
        $bindings = [$db];
        if ($keyword) {
            $where .= " AND TABLE_NAME LIKE ?";
            $bindings[] = "%{$keyword}%";
        }
        $order = strtoupper($order) === 'DESC' ? 'DESC' : 'ASC';
        $allowColumn = ['TABLE_NAME', 'TABLE_COMMENT', 'ENGINE', 'TABLE_ROWS', 'CREATE_TIME', 'UPDATE_TIME', 'TABLE_COLLATION'];
        if (!in_array($field, $allowColumn)) {
            $field = 'TABLE_NAME';
        }
        return $this->connection()->select(
            "SELECT TABLE_NAME, TABLE_COMMENT, ENGINE, TABLE_ROWS, CREATE_TIME, UPDATE_TIME, TABLE_COLLATION FROM information_schema.TABLES WHERE {$where} ORDER BY {$field} {$order} LIMIT {$limit} OFFSET {$offset}",
            $bindings
        );
    }

    /**
     * {@inheritdoc}
     */
    public function getTableCount(string $keyword = ''): int
    {
        $db = $this->getDatabaseName();
        $where = "TABLE_SCHEMA = ?";
        $bindings = [$db];
        if ($keyword) {
            $where .= " AND TABLE_NAME LIKE ?";
            $bindings[] = "%{$keyword}%";
        }
        $result = $this->connection()->select("SELECT COUNT(*) as total FROM information_schema.TABLES WHERE {$where}", $bindings);
        return $result[0]->total ?? 0;
    }

    /**
     * {@inheritdoc}
     */
    public function getModelColumns(string $table): array
    {
        $db = $this->getDatabaseName();
        return $this->connection()->select(
            "SELECT COLUMN_NAME, DATA_TYPE, COLUMN_KEY, COLUMN_COMMENT FROM information_schema.COLUMNS WHERE table_name = ? AND table_schema = ? ORDER BY ORDINAL_POSITION",
            [$table, $db]
        );
    }

    /**
     * {@inheritdoc}
     */
    public function setTableComment(string $table, string $comment): void
    {
        $quote = $this->connection()->getPdo()->quote($comment);
        $this->connection()->statement("ALTER TABLE {$this->quoteIdentifier($table)} COMMENT {$quote}");
    }

    /**
     * {@inheritdoc}
     */
    public function buildModifyColumnSql(array $column, string $table): string
    {
        $table = $this->quoteIdentifier($table);
        $field = $this->quoteIdentifier($column['field']);
        $oldField = isset($column['old_field']) && $column['old_field'] !== $column['field']
            ? $this->quoteIdentifier($column['old_field'])
            : null;
        $type = $column['type'];
        $nullable = $column['nullable'] ?? true;
        $default = $column['default'];
        $comment = $column['comment'] ?? '';
        $autoIncrement = $column['auto_increment'] ?? false;
        $length = (int)($column['length'] ?? 0);

        if ($oldField) {
            $sql = "ALTER TABLE {$table} CHANGE COLUMN {$oldField} {$field} ";
        } else {
            $sql = "ALTER TABLE {$table} MODIFY {$field} ";
        }

        if (stripos($type, 'integer') !== false) {
            $t = str_ireplace('integer', 'int', $type);
            if (stripos($type, 'unsigned') !== false) {
                $t = str_ireplace('unsigned', '', $t);
                $sql .= "{$t} UNSIGNED ";
            } else {
                $sql .= "{$t} ";
            }
            if ($autoIncrement) {
                $sql .= 'AUTO_INCREMENT ';
            }
        } else {
            switch ($type) {
                case 'string':
                    $length = $length ?: 255;
                    $sql .= "varchar({$length}) ";
                    break;
                case 'char':
                case 'time':
                    $sql .= $length ? "{$type}({$length}) " : "{$type} ";
                    break;
                case 'enum':
                    $args = array_map('trim', explode(',', (string)$column['length']));
                    $quoted = [];
                    foreach ($args as $v) {
                        $quoted[] = "'{$v}'";
                    }
                    $sql .= 'enum(' . implode(',', $quoted) . ') ';
                    break;
                case 'double':
                case 'float':
                case 'decimal':
                    if (trim($column['length'] ?? '')) {
                        $args = array_map('intval', explode(',', $column['length']));
                        $args[1] = $args[1] ?? $args[0];
                        $sql .= "{$type}({$args[0]}, {$args[1]}) ";
                        break;
                    }
                    $sql .= "{$type} ";
                    break;
                default:
                    $sql .= "{$type} ";
            }
        }

        if (!$nullable) {
            $sql .= 'NOT NULL ';
        }

        if ($type !== 'text' && $default !== null) {
            $quoted = $this->connection()->getPdo()->quote($default);
            $sql .= "DEFAULT {$quoted} ";
        }

        if ($comment) {
            $quoted = $this->connection()->getPdo()->quote($comment);
            $sql .= "COMMENT {$quoted} ";
        }

        return $sql;
    }

    /**
     * {@inheritdoc}
     */
    public function buildAddPrimaryKeySql(string $table, string $primaryKey): string
    {
        $table = $this->quoteIdentifier($table);
        $pk = $this->quoteIdentifier($primaryKey);
        return "ALTER TABLE {$table} ADD PRIMARY KEY({$pk})";
    }

    /**
     * {@inheritdoc}
     */
    public function buildDropPrimaryKeySql(string $table): string
    {
        $table = $this->quoteIdentifier($table);
        return "ALTER TABLE {$table} DROP PRIMARY KEY";
    }

    /**
     * {@inheritdoc}
     */
    public function getCreateTableExtra(): ?string
    {
        return 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci';
    }

    /**
     * {@inheritdoc}
     */
    public function getPdoDsn(array $config): string
    {
        $dsn = "mysql:host={$config['host']};port={$config['port']}";
        if (!empty($config['database'])) {
            $dsn .= ";dbname={$config['database']}";
        }
        return $dsn;
    }

    /**
     * {@inheritdoc}
     */
    public function getPdoOptions(): array
    {
        return [
            \PDO::MYSQL_ATTR_INIT_COMMAND => "set names utf8mb4",
            \PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
            \PDO::ATTR_EMULATE_PREPARES => false,
            \PDO::ATTR_TIMEOUT => 5,
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function getDatabaseExistsSql(string $database): string
    {
        return "show databases like '{$database}'";
    }

    /**
     * {@inheritdoc}
     */
    public function getCreateDatabaseSql(string $database): string
    {
        return "create database {$database}";
    }

    /**
     * {@inheritdoc}
     */
    public function getShowTablesSql(string $database): string
    {
        return "show tables";
    }

    /**
     * {@inheritdoc}
     */
    public function mapColumnType(string $type, string $columnType): array
    {
        $result = parent::mapColumnType($type, $columnType);
        $lowerType = strtolower($type);

        // MySQL auto_increment
        $result['auto_increment'] = stripos($columnType, 'auto_increment') !== false;

        // 提取长度信息
        if (preg_match('/\((\d+)\)/', $columnType, $matches)) {
            $result['length'] = (int)$matches[1];
        }

        // 精度
        if (in_array($lowerType, ['float', 'double', 'decimal']) && preg_match('/\((\d+),(\d+)\)/', $columnType, $matches)) {
            $result['length'] = "{$matches[1]},{$matches[2]}";
        }

        // enum值
        if ($lowerType === 'enum') {
            $result['length'] = implode(',', array_map(function ($item) {
                return trim($item, "'");
            }, explode(',', substr($columnType, 5, -1))));
        }

        // varchar/text长度
        if (in_array($lowerType, ['varchar', 'char'])) {
            $result['length'] = (int)($matches[1] ?? 255);
        }

        // 可空
        $result['nullable'] = stripos($columnType, 'not null') === false;

        return $result;
    }
}