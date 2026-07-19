<?php

namespace plugin\admin\app\common\database;

/**
 * 数据库适配器接口
 * 支持多种数据库（MySQL, SQLite, PostgreSQL）
 */
interface DatabaseAdapterInterface
{
    /**
     * 获取数据库连接名
     * @return string
     */
    public function getConnectionName(): string;

    /**
     * 获取数据库驱动名
     * @return string
     */
    public function getDriver(): string;

    /**
     * 引用标识符（表名、列名）
     * @param string $name
     * @return string
     */
    public function quoteIdentifier(string $name): string;

    /**
     * 获取表结构（列信息）
     * @param string $table
     * @return array
     */
    public function getTableColumns(string $table): array;

    /**
     * 获取表注释
     * @param string $table
     * @return string|null
     */
    public function getTableComment(string $table): ?string;

    /**
     * 获取表索引信息
     * @param string $table
     * @return array
     */
    public function getTableIndexes(string $table): array;

    /**
     * 获取所有表的列表（含注释等信息）
     * @param string $keyword
     * @param string $field
     * @param string $order
     * @param int $offset
     * @param int $limit
     * @return array
     */
    public function getTables(string $keyword = '', string $field = 'TABLE_NAME', string $order = 'asc', int $offset = 0, int $limit = 10): array;

    /**
     * 获取表总数
     * @param string $keyword
     * @return int
     */
    public function getTableCount(string $keyword = ''): int;

    /**
     * 获取模型创建所需的列信息
     * @param string $table
     * @return array
     */
    public function getModelColumns(string $table): array;

    /**
     * 设置表注释
     * @param string $table
     * @param string $comment
     * @return void
     */
    public function setTableComment(string $table, string $comment): void;

    /**
     * 修改列定义
     * @param array $column 列定义
     * @param string $table 表名
     * @return string SQL语句
     */
    public function buildModifyColumnSql(array $column, string $table): string;

    /**
     * 添加主键
     * @param string $table
     * @param string $primaryKey
     * @return string
     */
    public function buildAddPrimaryKeySql(string $table, string $primaryKey): string;

    /**
     * 删除主键
     * @param string $table
     * @return string
     */
    public function buildDropPrimaryKeySql(string $table): string;

    /**
     * 获取创建表时的引擎/额外参数
     * @return string|null
     */
    public function getCreateTableExtra(): ?string;

    /**
     * 获取PDO DSN
     * @param array $config
     * @return string
     */
    public function getPdoDsn(array $config): string;

    /**
     * 获取PDO连接参数
     * @return array
     */
    public function getPdoOptions(): array;

    /**
     * 检查数据库是否存在SQL
     * @param string $database
     * @return string
     */
    public function getDatabaseExistsSql(string $database): string;

    /**
     * 创建数据库SQL
     * @param string $database
     * @return string
     */
    public function getCreateDatabaseSql(string $database): string;

    /**
     * 获取所有表名列表
     * @param string $database
     * @return string
     */
    public function getShowTablesSql(string $database): string;

    /**
     * 获取列类型映射（数据库类型到Eloquent方法名）
     * @param string $type
     * @param string $columnType
     * @return array
     */
    public function mapColumnType(string $type, string $columnType): array;
}