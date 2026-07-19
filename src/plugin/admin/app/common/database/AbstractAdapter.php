<?php

namespace plugin\admin\app\common\database;

use support\Db;

/**
 * 数据库适配器抽象基类
 */
abstract class AbstractAdapter implements DatabaseAdapterInterface
{
    /**
     * 获取数据库连接
     * @return \Illuminate\Database\Connection
     */
    protected function connection()
    {
        return Db::connection($this->getConnectionName());
    }

    /**
     * 获取Schema Builder
     * @return \Illuminate\Database\Schema\Builder
     */
    protected function schema()
    {
        return Db::schema($this->getConnectionName());
    }

    /**
     * {@inheritdoc}
     */
    public function quoteIdentifier(string $name): string
    {
        return '"' . str_replace('"', '""', $name) . '"';
    }

    /**
     * {@inheritdoc}
     */
    public function getCreateTableExtra(): ?string
    {
        return null;
    }

    /**
     * 从数据库类型映射到Eloquent数据类型
     * @param string $type
     * @param string $columnType
     * @return array [type, unsigned, length, auto_increment, nullable, default, comment, primary_key]
     */
    public function mapColumnType(string $type, string $columnType): array
    {
        $result = [
            'type' => 'string',
            'unsigned' => false,
            'length' => null,
            'auto_increment' => false,
            'nullable' => true,
            'default' => null,
            'comment' => '',
            'primary_key' => false,
        ];

        $type = strtolower($type);

        if (in_array($type, ['int', 'integer', 'tinyint', 'smallint', 'mediumint', 'bigint', 'int2', 'int4', 'int8', 'serial', 'bigserial', 'smallserial'])) {
            $result['type'] = 'integer';
            $result['unsigned'] = stripos($columnType, 'unsigned') !== false;
            return $result;
        }

        if (in_array($type, ['varchar', 'character varying', 'character', 'char'])) {
            $result['type'] = 'string';
            return $result;
        }

        if (in_array($type, ['text', 'mediumtext', 'longtext', 'tinytext', 'clob'])) {
            $result['type'] = 'text';
            return $result;
        }

        if (in_array($type, ['datetime', 'timestamp', 'timestamptz'])) {
            $result['type'] = 'datetime';
            return $result;
        }

        if (in_array($type, ['date'])) {
            $result['type'] = 'date';
            return $result;
        }

        if (in_array($type, ['time', 'timetz'])) {
            $result['type'] = 'time';
            return $result;
        }

        if (in_array($type, ['float', 'real', 'double', 'double precision', 'decimal', 'numeric', 'money'])) {
            $result['type'] = 'float';
            return $result;
        }

        if ($type === 'boolean') {
            $result['type'] = 'boolean';
            return $result;
        }

        if ($type === 'enum') {
            $result['type'] = 'enum';
            return $result;
        }

        if (in_array($type, ['blob', 'bytea', 'binary', 'varbinary'])) {
            $result['type'] = 'binary';
            return $result;
        }

        if (in_array($type, ['json', 'jsonb'])) {
            $result['type'] = 'json';
            return $result;
        }

        return $result;
    }

    /**
     * 获取配置中的数据库名
     * @return string
     */
    protected function getDatabaseName(): string
    {
        $config = config("database.connections.{$this->getConnectionName()}", []);
        return $config['database'] ?? '';
    }
}