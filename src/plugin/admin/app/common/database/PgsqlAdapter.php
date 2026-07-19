<?php

namespace plugin\admin\app\common\database;

/**
 * PostgreSQL数据库适配器
 */
class PgsqlAdapter extends AbstractAdapter
{
    /**
     * {@inheritdoc}
     */
    public function getConnectionName(): string
    {
        return 'plugin.admin.pgsql';
    }

    /**
     * {@inheritdoc}
     */
    public function getDriver(): string
    {
        return 'pgsql';
    }

    /**
     * {@inheritdoc}
     */
    public function getTableColumns(string $table): array
    {
        $schema = 'public';
        $rows = $this->connection()->select("
            SELECT 
                a.attname AS field,
                pg_catalog.format_type(a.atttypid, a.atttypmod) AS type,
                CASE WHEN a.attnotnull THEN 'NO' ELSE 'YES' END AS null,
                CASE WHEN idx.indisprimary THEN 'PRI' ELSE '' END AS key,
                COALESCE(df.adsrc, ad.adbin) AS default,
                '' AS extra,
                COALESCE(c. description, '') AS comment
            FROM pg_catalog.pg_attribute a
            LEFT JOIN pg_catalog.pg_class t ON a.attrelid = t.oid
            LEFT JOIN pg_catalog.pg_namespace n ON t.relnamespace = n.oid
            LEFT JOIN pg_catalog.pg_index idx ON a.attrelid = idx.indrelid AND a.attnum = ANY(idx.indkey) AND idx.indisprimary
            LEFT JOIN pg_catalog.pg_attrdef df ON a.attrelid = df.adrelid AND a.attnum = df.adnum
            LEFT JOIN pg_catalog.pg_description c ON a.attrelid = c.objoid AND a.attnum = c.objsubid
            LEFT JOIN pg_catalog.pg_attrdef ad ON a.attrelid = ad.adrelid AND a.attnum = ad.adnum
            WHERE t.relname = ? AND n.nspname = ? AND a.attnum > 0 AND NOT a.attisdropped
            ORDER BY a.attnum
        ", [$table, $schema]);

        $columns = [];
        foreach ($rows as $row) {
            $columns[$row->field] = [
                'field' => $row->field,
                'type' => $row->type,
                'null' => $row->null,
                'key' => $row->key,
                'default' => $row->default,
                'extra' => $row->extra,
                'comment' => $row->comment,
            ];
        }
        return $columns;
    }

    /**
     * {@inheritdoc}
     */
    public function getTableComment(string $table): ?string
    {
        $rows = $this->connection()->select("
            SELECT c.relname, pg_catalog.obj_description(c.oid, 'pg_class') AS comment
            FROM pg_catalog.pg_class c
            LEFT JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace
            WHERE c.relname = ? AND n.nspname = 'public'
        ", [$table]);
        return $rows[0]->comment ?? null;
    }

    /**
     * {@inheritdoc}
     */
    public function getTableIndexes(string $table): array
    {
        $rows = $this->connection()->select("
            SELECT
                i.relname AS index_name,
                a.attname AS column_name,
                ix.indisunique AS is_unique,
                ix.indisprimary AS is_primary
            FROM pg_catalog.pg_class t
            JOIN pg_catalog.pg_index ix ON t.oid = ix.indrelid
            JOIN pg_catalog.pg_class i ON ix.indexrelid = i.oid
            JOIN pg_catalog.pg_attribute a ON t.oid = a.attrelid AND a.attnum = ANY(ix.indkey)
            WHERE t.relname = ? AND a.attnum > 0 AND NOT a.attisdropped
            ORDER BY i.relname, a.attnum
        ", [$table]);

        $indexes = [];
        foreach ($rows as $row) {
            if ($row->is_primary) {
                continue;
            }
            $keyName = $row->index_name;
            if (!isset($indexes[$keyName])) {
                $indexes[$keyName] = [
                    'name' => $keyName,
                    'columns' => [],
                    'type' => $row->is_unique ? 'unique' : 'normal',
                ];
            }
            $indexes[$keyName]['columns'][] = $row->column_name;
        }
        return array_reverse($indexes, true);
    }

    /**
     * {@inheritdoc}
     */
    public function getTables(string $keyword = '', string $field = 'TABLE_NAME', string $order = 'asc', int $offset = 0, int $limit = 10): array
    {
        $where = "n.nspname = 'public' AND c.relkind = 'r'";
        $bindings = [];
        if ($keyword) {
            $where .= " AND c.relname LIKE ?";
            $bindings[] = "%{$keyword}%";
        }
        $order = strtoupper($order) === 'DESC' ? 'DESC' : 'ASC';
        $rows = $this->connection()->select("
            SELECT 
                c.relname AS TABLE_NAME,
                pg_catalog.obj_description(c.oid, 'pg_class') AS TABLE_COMMENT,
                'pgsql' AS ENGINE,
                n_live_tup AS TABLE_ROWS,
                '' AS CREATE_TIME,
                '' AS UPDATE_TIME,
                '' AS TABLE_COLLATION
            FROM pg_catalog.pg_class c
            LEFT JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace
            LEFT JOIN pg_catalog.pg_stat_user_tables s ON c.relname = s.relname
            WHERE {$where}
            ORDER BY c.relname {$order}
            LIMIT {$limit} OFFSET {$offset}
        ", $bindings);

        foreach ($rows as $row) {
            if ($row->table_rows === null || $row->table_rows < 0) {
                try {
                    $row->TABLE_ROWS = $this->connection()->table($row->table_name)->count();
                } catch (\Throwable $e) {
                    $row->TABLE_ROWS = 0;
                }
            }
        }
        return $rows;
    }

    /**
     * {@inheritdoc}
     */
    public function getTableCount(string $keyword = ''): int
    {
        $where = "n.nspname = 'public' AND c.relkind = 'r'";
        $bindings = [];
        if ($keyword) {
            $where .= " AND c.relname LIKE ?";
            $bindings[] = "%{$keyword}%";
        }
        $result = $this->connection()->select("
            SELECT COUNT(*) as total 
            FROM pg_catalog.pg_class c
            LEFT JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace
            WHERE {$where}
        ", $bindings);
        return $result[0]->total ?? 0;
    }

    /**
     * {@inheritdoc}
     */
    public function getModelColumns(string $table): array
    {
        $rows = $this->connection()->select("
            SELECT 
                a.attname AS column_name,
                pg_catalog.format_type(a.atttypid, a.atttypmod) AS data_type,
                CASE WHEN idx.indisprimary THEN 'PRI' ELSE '' END AS column_key,
                COALESCE(c.description, '') AS column_comment
            FROM pg_catalog.pg_attribute a
            LEFT JOIN pg_catalog.pg_class t ON a.attrelid = t.oid
            LEFT JOIN pg_catalog.pg_namespace n ON t.relnamespace = n.oid
            LEFT JOIN pg_catalog.pg_index idx ON a.attrelid = idx.indrelid AND a.attnum = ANY(idx.indkey) AND idx.indisprimary
            LEFT JOIN pg_catalog.pg_description c ON a.attrelid = c.objoid AND a.attnum = c.objsubid
            WHERE t.relname = ? AND n.nspname = 'public' AND a.attnum > 0 AND NOT a.attisdropped
            ORDER BY a.attnum
        ", [$table]);
        return $rows;
    }

    /**
     * {@inheritdoc}
     */
    public function setTableComment(string $table, string $comment): void
    {
        $quoted = $this->connection()->getPdo()->quote($comment);
        $this->connection()->statement("COMMENT ON TABLE {$this->quoteIdentifier($table)} IS {$quoted}");
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
        $length = (int)($column['length'] ?? 0);

        $parts = [];

        if ($oldField) {
            $parts[] = "ALTER TABLE {$table} RENAME COLUMN {$oldField} TO {$field}";
        }

        // 类型转换
        $pgType = $this->eloquentToPgType($type, $length);
        $parts[] = "ALTER TABLE {$table} ALTER COLUMN {$field} TYPE {$pgType}";

        // 可空
        if ($nullable) {
            $parts[] = "ALTER TABLE {$table} ALTER COLUMN {$field} DROP NOT NULL";
        } else {
            $parts[] = "ALTER TABLE {$table} ALTER COLUMN {$field} SET NOT NULL";
        }

        // 默认值
        if ($default !== null && $type !== 'text') {
            $quoted = $this->connection()->getPdo()->quote($default);
            $parts[] = "ALTER TABLE {$table} ALTER COLUMN {$field} SET DEFAULT {$quoted}";
        } else {
            $parts[] = "ALTER TABLE {$table} ALTER COLUMN {$field} DROP DEFAULT";
        }

        // 注释
        if ($comment) {
            $quoted = $this->connection()->getPdo()->quote($comment);
            $parts[] = "COMMENT ON COLUMN {$table}.{$field} IS {$quoted}";
        }

        return implode(";\n", $parts);
    }

    /**
     * Eloquent类型转PostgreSQL类型
     * @param string $type
     * @param int $length
     * @return string
     */
    private function eloquentToPgType(string $type, int $length): string
    {
        $map = [
            'string' => $length ? "varchar({$length})" : 'varchar(255)',
            'char' => $length ? "char({$length})" : 'char(1)',
            'integer' => 'integer',
            'tinyInteger' => 'smallint',
            'smallInteger' => 'smallint',
            'mediumInteger' => 'integer',
            'bigInteger' => 'bigint',
            'unsignedInteger' => 'integer',
            'unsignedTinyInteger' => 'smallint',
            'unsignedSmallInteger' => 'smallint',
            'unsignedMediumInteger' => 'integer',
            'unsignedBigInteger' => 'bigint',
            'float' => 'real',
            'double' => 'double precision',
            'decimal' => 'numeric',
            'text' => 'text',
            'mediumText' => 'text',
            'longText' => 'text',
            'date' => 'date',
            'dateTime' => 'timestamp',
            'time' => 'time',
            'timestamp' => 'timestamp',
            'boolean' => 'boolean',
            'enum' => 'varchar(255)',
            'json' => 'jsonb',
            'binary' => 'bytea',
        ];
        return $map[$type] ?? 'text';
    }

    /**
     * {@inheritdoc}
     */
    public function buildAddPrimaryKeySql(string $table, string $primaryKey): string
    {
        $table = $this->quoteIdentifier($table);
        $pk = $this->quoteIdentifier($primaryKey);
        return "ALTER TABLE {$table} ADD PRIMARY KEY ({$pk})";
    }

    /**
     * {@inheritdoc}
     */
    public function buildDropPrimaryKeySql(string $table): string
    {
        $table = $this->quoteIdentifier($table);
        return "ALTER TABLE {$table} DROP CONSTRAINT {$table}_pkey";
    }

    /**
     * {@inheritdoc}
     */
    public function getPdoDsn(array $config): string
    {
        $dsn = "pgsql:host={$config['host']};port={$config['port']}";
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
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_EMULATE_PREPARES => false,
            \PDO::ATTR_TIMEOUT => 5,
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function getDatabaseExistsSql(string $database): string
    {
        return "SELECT 1 FROM pg_database WHERE datname = '{$database}'";
    }

    /**
     * {@inheritdoc}
     */
    public function getCreateDatabaseSql(string $database): string
    {
        return "CREATE DATABASE {$database}";
    }

    /**
     * {@inheritdoc}
     */
    public function getShowTablesSql(string $database): string
    {
        return "SELECT tablename FROM pg_catalog.pg_tables WHERE schemaname = 'public'";
    }

    /**
     * {@inheritdoc}
     */
    public function mapColumnType(string $type, string $columnType): array
    {
        $result = parent::mapColumnType($type, $columnType);
        $lowerType = strtolower($type);

        // PostgreSQL serial类型是自增的
        if (in_array($lowerType, ['serial', 'bigserial', 'smallserial'])) {
            $result['type'] = 'integer';
            $result['auto_increment'] = true;
        }

        // 提取长度
        if (preg_match('/^varchar\((\d+)\)/', $columnType, $matches)) {
            $result['length'] = (int)$matches[1];
        }

        // 精度
        if (in_array($lowerType, ['numeric', 'decimal']) && preg_match('/\((\d+),(\d+)\)/', $columnType, $matches)) {
            $result['length'] = "{$matches[1]},{$matches[2]}";
        }

        return $result;
    }
}