<?php

namespace plugin\admin\app\common\database;

/**
 * SQLite数据库适配器
 */
class SqliteAdapter extends AbstractAdapter
{
    /**
     * {@inheritdoc}
     */
    public function getConnectionName(): string
    {
        return 'plugin.admin.sqlite';
    }

    /**
     * {@inheritdoc}
     */
    public function getDriver(): string
    {
        return 'sqlite';
    }

    /**
     * {@inheritdoc}
     */
    public function getTableColumns(string $table): array
    {
        $rows = $this->connection()->select("PRAGMA table_info({$this->quoteIdentifier($table)})");
        $columns = [];
        foreach ($rows as $row) {
            $columns[$row->name] = [
                'field' => $row->name,
                'type' => $row->type,
                'null' => $row->notnull ? 'NO' : 'YES',
                'key' => $row->pk ? 'PRI' : '',
                'default' => $row->dflt_value,
                'extra' => $row->pk && stripos($row->type, 'int') !== false ? 'auto_increment' : '',
                'comment' => '',
            ];
        }
        return $columns;
    }

    /**
     * {@inheritdoc}
     */
    public function getTableComment(string $table): ?string
    {
        return null;
    }

    /**
     * {@inheritdoc}
     */
    public function getTableIndexes(string $table): array
    {
        $rows = $this->connection()->select("PRAGMA index_list({$this->quoteIdentifier($table)})");
        $indexes = [];
        foreach ($rows as $row) {
            if ($row->origin === 'pk') {
                continue;
            }
            $columns = $this->connection()->select("PRAGMA index_info({$this->quoteIdentifier($row->name)})");
            $cols = [];
            foreach ($columns as $col) {
                $cols[] = $col->name;
            }
            $indexes[$row->name] = [
                'name' => $row->name,
                'columns' => $cols,
                'type' => $row->unique ? 'unique' : 'normal',
            ];
        }
        return array_reverse($indexes, true);
    }

    /**
     * {@inheritdoc}
     */
    public function getTables(string $keyword = '', string $field = 'TABLE_NAME', string $order = 'asc', int $offset = 0, int $limit = 10): array
    {
        $where = "type='table'";
        $bindings = [];
        if ($keyword) {
            $where .= " AND name LIKE ?";
            $bindings[] = "%{$keyword}%";
        }
        $order = strtoupper($order) === 'DESC' ? 'DESC' : 'ASC';
        $rows = $this->connection()->select(
            "SELECT name as TABLE_NAME, '' as TABLE_COMMENT, 'sqlite' as ENGINE, 0 as TABLE_ROWS, '' as CREATE_TIME, '' as UPDATE_TIME, '' as TABLE_COLLATION FROM sqlite_master WHERE {$where} ORDER BY name {$order} LIMIT {$limit} OFFSET {$offset}",
            $bindings
        );
        foreach ($rows as $row) {
            try {
                $row->TABLE_ROWS = $this->connection()->table($row->TABLE_NAME)->count();
            } catch (\Throwable $e) {
                $row->TABLE_ROWS = 0;
            }
        }
        return $rows;
    }

    /**
     * {@inheritdoc}
     */
    public function getTableCount(string $keyword = ''): int
    {
        $where = "type='table'";
        $bindings = [];
        if ($keyword) {
            $where .= " AND name LIKE ?";
            $bindings[] = "%{$keyword}%";
        }
        $result = $this->connection()->select("SELECT COUNT(*) as total FROM sqlite_master WHERE {$where}", $bindings);
        return $result[0]->total ?? 0;
    }

    /**
     * {@inheritdoc}
     */
    public function getModelColumns(string $table): array
    {
        $rows = $this->connection()->select("PRAGMA table_info({$this->quoteIdentifier($table)})");
        $result = [];
        foreach ($rows as $row) {
            $result[] = (object)[
                'COLUMN_NAME' => $row->name,
                'DATA_TYPE' => $row->type,
                'COLUMN_KEY' => $row->pk ? 'PRI' : '',
                'COLUMN_COMMENT' => '',
            ];
        }
        return $result;
    }

    /**
     * {@inheritdoc}
     */
    public function setTableComment(string $table, string $comment): void
    {
        // SQLite不支持表注释，忽略
    }

    /**
     * {@inheritdoc}
     */
    public function buildModifyColumnSql(array $column, string $table): string
    {
        // SQLite不支持ALTER TABLE MODIFY，需要通过重建表实现
        // 这里返回空字符串，由调用方处理
        return '';
    }

    /**
     * {@inheritdoc}
     */
    public function buildAddPrimaryKeySql(string $table, string $primaryKey): string
    {
        return '';
    }

    /**
     * {@inheritdoc}
     */
    public function buildDropPrimaryKeySql(string $table): string
    {
        return '';
    }

    /**
     * {@inheritdoc}
     */
    public function getPdoDsn(array $config): string
    {
        return "sqlite:{$config['database']}";
    }

    /**
     * {@inheritdoc}
     */
    public function getPdoOptions(): array
    {
        return [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_TIMEOUT => 5,
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function getDatabaseExistsSql(string $database): string
    {
        // SQLite是文件数据库，不需要创建数据库
        return '';
    }

    /**
     * {@inheritdoc}
     */
    public function getCreateDatabaseSql(string $database): string
    {
        // SQLite是文件数据库，不需要创建数据库
        return '';
    }

    /**
     * {@inheritdoc}
     */
    public function getShowTablesSql(string $database): string
    {
        return "SELECT name FROM sqlite_master WHERE type='table'";
    }

    /**
     * {@inheritdoc}
     */
    public function mapColumnType(string $type, string $columnType): array
    {
        $result = parent::mapColumnType($type, $columnType);
        $lowerType = strtolower($type);

        // SQLite的INTEGER PRIMARY KEY是自增的
        if ($lowerType === 'integer' && stripos($columnType, 'pk') !== false) {
            $result['auto_increment'] = true;
        }

        return $result;
    }
}