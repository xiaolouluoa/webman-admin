<?php

namespace plugin\admin\app\common;

use plugin\admin\app\common\database\DatabaseAdapterInterface;
use plugin\admin\app\common\database\MysqlAdapter;
use plugin\admin\app\common\database\SqliteAdapter;
use plugin\admin\app\common\database\PgsqlAdapter;
use support\Db;

/**
 * 数据库辅助类
 * 根据配置自动选择数据库适配器，提供跨数据库支持
 */
class Database
{
    /**
     * @var DatabaseAdapterInterface|null
     */
    protected static $adapter = null;

    /**
     * @var array 适配器实例缓存
     */
    protected static $adapters = [];

    /**
     * 获取当前数据库适配器
     * @return DatabaseAdapterInterface
     */
    public static function adapter(): DatabaseAdapterInterface
    {
        if (static::$adapter === null) {
            static::$adapter = static::resolveAdapter();
        }
        return static::$adapter;
    }

    /**
     * 设置适配器（用于测试或覆盖）
     * @param DatabaseAdapterInterface $adapter
     * @return void
     */
    public static function setAdapter(DatabaseAdapterInterface $adapter): void
    {
        static::$adapter = $adapter;
    }

    /**
     * 重置适配器
     * @return void
     */
    public static function resetAdapter(): void
    {
        static::$adapter = null;
    }

    /**
     * 获取数据库连接名
     * @return string
     */
    public static function getConnectionName(): string
    {
        return static::adapter()->getConnectionName();
    }

    /**
     * 获取数据库驱动名
     * @return string
     */
    public static function getDriver(): string
    {
        return static::adapter()->getDriver();
    }

    /**
     * 获取数据库连接
     * @return \Illuminate\Database\Connection
     */
    public static function connection()
    {
        return Db::connection(static::getConnectionName());
    }

    /**
     * 获取Schema Builder
     * @return \Illuminate\Database\Schema\Builder
     */
    public static function schema()
    {
        return Db::schema(static::getConnectionName());
    }

    /**
     * 获取数据库配置
     * @return array
     */
    public static function getConfig(): array
    {
        $connectionName = static::getConnectionName();
        return config("database.connections.{$connectionName}", []);
    }

    /**
     * 获取数据库名
     * @return string
     */
    public static function getDatabaseName(): string
    {
        return static::getConfig()['database'] ?? '';
    }

    /**
     * 获取表前缀
     * @return string
     */
    public static function getPrefix(): string
    {
        return static::getConfig()['prefix'] ?? '';
    }

    /**
     * 解析数据库适配器
     * @return DatabaseAdapterInterface
     */
    protected static function resolveAdapter(): DatabaseAdapterInterface
    {
        // 尝试从插件配置获取数据库驱动
        $driver = static::detectDriver();

        switch ($driver) {
            case 'mysql':
                return new MysqlAdapter();
            case 'sqlite':
                return new SqliteAdapter();
            case 'pgsql':
            case 'postgresql':
                return new PgsqlAdapter();
            default:
                // 默认使用MySQL
                return new MysqlAdapter();
        }
    }

    /**
     * 检测数据库驱动类型
     * @return string
     */
    protected static function detectDriver(): string
    {
        // 1. 优先从插件配置中检测
        $pluginConfig = config('plugin.admin.database', []);
        if (!empty($pluginConfig['default'])) {
            $default = $pluginConfig['default'];
            $connections = $pluginConfig['connections'] ?? [];
            if (!empty($connections[$default]['driver'])) {
                return $connections[$default]['driver'];
            }
            // 根据连接名推断
            if (strpos($default, 'sqlite') !== false) {
                return 'sqlite';
            }
            if (strpos($default, 'pgsql') !== false || strpos($default, 'postgres') !== false) {
                return 'pgsql';
            }
        }

        // 2. 检测插件配置中的connections
        if (!empty($pluginConfig['connections'])) {
            foreach ($pluginConfig['connections'] as $name => $config) {
                if (!empty($config['driver'])) {
                    return $config['driver'];
                }
            }
        }

        // 3. 尝试从主配置中检测
        $mainConfig = config('database', []);
        $default = $mainConfig['default'] ?? 'mysql';

        // 检测plugin.admin.*连接
        foreach ($mainConfig['connections'] ?? [] as $name => $config) {
            if (strpos($name, 'plugin.admin.') === 0) {
                if (!empty($config['driver'])) {
                    return $config['driver'];
                }
                if (strpos($name, 'mysql') !== false) return 'mysql';
                if (strpos($name, 'sqlite') !== false) return 'sqlite';
                if (strpos($name, 'pgsql') !== false) return 'pgsql';
            }
        }

        // 4. 根据默认连接推断
        if (!empty($mainConfig['connections'][$default]['driver'])) {
            return $mainConfig['connections'][$default]['driver'];
        }

        // 5. 根据连接名推断
        if (strpos($default, 'sqlite') !== false) return 'sqlite';
        if (strpos($default, 'pgsql') !== false || strpos($default, 'postgres') !== false) return 'pgsql';

        return 'mysql';
    }
}