<?php

namespace plugin\admin\app\common;

use process\Monitor;
use Throwable;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Builder;
use plugin\admin\app\model\Option;
use support\exception\BusinessException;
use support\Db;
use Workerman\Timer;
use Workerman\Worker;

class Util
{
    /**
     * 数据库连接名
     * @var string
     */
    protected static $connectionName = 'plugin.admin.database';

    /**
     * 获取当前数据库连接名
     * @return string
     */
    public static function getConnectionName(): string
    {
        return static::$connectionName;
    }

    /**
     * 设置数据库连接名
     * @param string $name
     */
    public static function setConnectionName(string $name): void
    {
        static::$connectionName = $name;
    }

    /**
     * 获取数据库驱动类型
     * @return string
     */
    public static function getDriver(): string
    {
        return config('database.connections.' . static::$connectionName . '.driver', 'mysql');
    }

    /**
     * 密码哈希
     * @param $password
     * @param string $algo
     * @return false|string|null
     */
    public static function passwordHash($password, string $algo = PASSWORD_DEFAULT)
    {
        return password_hash($password, $algo);
    }

    /**
     * 验证密码哈希
     * @param string $password
     * @param string $hash
     * @return bool
     */
    public static function passwordVerify(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    /**
     * 获取webman-admin数据库连接
     * @return Connection
     */
    public static function db(): Connection
    {
        return Db::connection(static::$connectionName);
    }

    /**
     * 获取SchemaBuilder
     * @return Builder
     */
    public static function schema(): Builder
    {
        return Db::schema(static::$connectionName);
    }

    /**
     * 获取数据库连接配置
     * @return array
     */
    public static function getConnectionConfig(): array
    {
        return config('database.connections.' . static::$connectionName, []);
    }

    /**
     * 获取数据库名
     * @return string
     */
    public static function getDatabaseName(): string
    {
        $config = static::getConnectionConfig();
        $driver = $config['driver'] ?? 'mysql';
        if ($driver === 'sqlite') {
            return $config['database'] ?? '';
        }
        return $config['database'] ?? '';
    }

    /**
     * 获取语义化时间
     * @param $time
     * @return false|string
     */
    public static function humanDate($time)
    {
        $timestamp = is_numeric($time) ? $time : strtotime($time);
        $dur = time() - $timestamp;
        if ($dur < 0) {
            return date('Y-m-d', $timestamp);
        } else {
            if ($dur < 60) {
                return $dur . '秒前';
            } else {
                if ($dur < 3600) {
                    return floor($dur / 60) . '分钟前';
                } else {
                    if ($dur < 86400) {
                        return floor($dur / 3600) . '小时前';
                    } else {
                        if ($dur < 2592000) { // 30天内
                            return floor($dur / 86400) . '天前';
                        } else {
                            return date('Y-m-d', $timestamp);;
                        }
                    }
                }
            }
        }
        return date('Y-m-d', $timestamp);
    }

    /**
     * 格式化文件大小
     * @param $file_size
     * @return string
     */
    public static function formatBytes($file_size): string
    {
        $size = sprintf("%u", $file_size);
        if($size == 0) {
            return("0 Bytes");
        }
        $size_name = array(" Bytes", " KB", " MB", " GB", " TB", " PB", " EB", " ZB", " YB");
        return round($size/pow(1024, ($i = floor(log($size, 1024)))), 2) . $size_name[$i];
    }

    /**
     * 数据库字符串转义
     * @param $var
     * @return false|string
     */
    public static function pdoQuote($var)
    {
        return Util::db()->getPdo()->quote($var);
    }

    /**
     * 检查表名是否合法
     * @param string $table
     * @return string
     * @throws BusinessException
     */
    public static function checkTableName(string $table): string
    {
        if (!preg_match('/^[a-zA-Z_0-9]+$/', $table)) {
            throw new BusinessException('表名不合法');
        }
        return $table;
    }

    /**
     * 变量或数组中的元素只能是字母数字下划线组合
     * @param $var
     * @return mixed
     * @throws BusinessException
     */
    public static function filterAlphaNum($var)
    {
        $vars = (array)$var;
        array_walk_recursive($vars, function ($item) {
            if (is_string($item) && !preg_match('/^[a-zA-Z_0-9]+$/', $item)) {
                throw new BusinessException('参数不合法');
            }
        });
        return $var;
    }

    /**
     * 变量或数组中的元素只能是字母数字
     * @param $var
     * @return mixed
     * @throws BusinessException
     */
    public static function filterNum($var)
    {
        $vars = (array)$var;
        array_walk_recursive($vars, function ($item) {
            if (is_string($item) && !preg_match('/^[0-9]+$/', $item)) {
                throw new BusinessException('参数不合法');
            }
        });
        return $var;
    }

    /**
     * @desc 检测是否是合法URL Path
     * @param $var
     * @return string
     * @throws BusinessException
     */
    public static function filterUrlPath($var): string
    {
        if (!is_string($var)) {
            throw new BusinessException('参数不合法，地址必须是一个字符串！');
        }

        if (strpos($var, 'https://') === 0 || strpos($var, 'http://') === 0) {
            if (!filter_var($var, FILTER_VALIDATE_URL)) {
                throw new BusinessException('参数不合法，不是合法的URL地址！');
            }
        } elseif (!preg_match('/^[a-zA-Z0-9_\-\/&?.]+$/', $var)) {
            throw new BusinessException('参数不合法，不是合法的Path！');
        }
        return $var;
    }

    /**
     * 检测是否是合法Path
     * @param $var
     * @return string
     * @throws BusinessException
     */
    public static function filterPath($var): string
    {
        if (!is_string($var) || !preg_match('/^[a-zA-Z0-9_\-\/]+$/', $var)) {
            throw new BusinessException('参数不合法');
        }
        return $var;
    }

    /**
     * 类转换为url path
     * @param $controller_class
     * @return false|string
     */
    static function controllerToUrlPath($controller_class)
    {
        $key = strtolower($controller_class);
        $action = '';
        if (strpos($key, '@')) {
            [$key, $action] = explode( '@', $key, 2);
        }
        $prefix = 'plugin';
        $paths = explode('\\', $key);
        if (count($paths) < 2) {
            return false;
        }
        $base = '';
        if (strpos($key, "$prefix\\") === 0) {
            if (count($paths) < 4) {
                return false;
            }
            array_shift($paths);
            $plugin = array_shift($paths);
            $base = "/app/$plugin/";
        }
        array_shift($paths);
        foreach ($paths as $index => $path) {
            if ($path === 'controller') {
                unset($paths[$index]);
            }
        }
        $suffix = 'controller';
        $code = $base . implode('/', $paths);
        if (substr($code, -strlen($suffix)) === $suffix) {
            $code = substr($code, 0, -strlen($suffix));
        }
        return $action ? "$code/$action" : $code;
    }

    /**
     * 转换为驼峰
     * @param string $value
     * @return string
     */
    public static function camel(string $value): string
    {
        static $cache = [];
        $key = $value;

        if (isset($cache[$key])) {
            return $cache[$key];
        }

        $value = ucwords(str_replace(['-', '_'], ' ', $value));

        return $cache[$key] = str_replace(' ', '', $value);
    }

    /**
     * 转换为小驼峰
     * @param $value
     * @return string
     */
    public static function smCamel($value): string
    {
        return lcfirst(static::camel($value));
    }

    /**
     * 获取注释中第一行
     * @param $comment
     * @return false|mixed|string
     */
    public static function getCommentFirstLine($comment)
    {
        if ($comment === false) {
            return false;
        }
        foreach (explode("\n", $comment) as $str) {
            if ($s = trim($str, "*/\ \t\n\r\0\x0B")) {
                return $s;
            }
        }
        return $comment;
    }

    /**
     * 表单类型到插件的映射
     * @return \string[][]
     */
    public static function methodControlMap(): array
    {
        return  [
            //method=>[控件]
            'integer' => ['InputNumber'],
            'string' => ['Input'],
            'text' => ['TextArea'],
            'date' => ['DatePicker'],
            'enum' => ['Select'],
            'float' => ['Input'],

            'tinyInteger' => ['InputNumber'],
            'smallInteger' => ['InputNumber'],
            'mediumInteger' => ['InputNumber'],
            'bigInteger' => ['InputNumber'],

            'unsignedInteger' => ['InputNumber'],
            'unsignedTinyInteger' => ['InputNumber'],
            'unsignedSmallInteger' => ['InputNumber'],
            'unsignedMediumInteger' => ['InputNumber'],
            'unsignedBigInteger' => ['InputNumber'],

            'decimal' => ['Input'],
            'double' => ['Input'],

            'mediumText' => ['TextArea'],
            'longText' => ['TextArea'],

            'dateTime' => ['DateTimePicker'],

            'time' => ['DateTimePicker'],
            'timestamp' => ['DateTimePicker'],

            'char' => ['Input'],

            'binary' => ['Input'],

            'json' => ['input']
        ];
    }

    /**
     * 数据库类型到插件的转换
     * @param $type
     * @return string
     */
    public static function typeToControl($type): string
    {
        if (stripos($type, 'int') !== false) {
            return 'inputNumber';
        }
        if (stripos($type, 'time') !== false || stripos($type, 'date') !== false) {
            return 'dateTimePicker';
        }
        if (stripos($type, 'text') !== false) {
            return 'textArea';
        }
        if ($type === 'enum') {
            return 'select';
        }
        return 'input';
    }

    /**
     * 数据库类型到表单类型的转换
     * @param $type
     * @param $unsigned
     * @return string
     */
    public static function typeToMethod($type, $unsigned = false)
    {
        if (stripos($type, 'int') !== false) {
            $type = str_replace('int', 'Integer', $type);
            return $unsigned ? "unsigned" . ucfirst($type) : lcfirst($type);
        }
        $map = [
            'int' => 'integer',
            'varchar' => 'string',
            'mediumtext' => 'mediumText',
            'longtext' => 'longText',
            'datetime' => 'dateTime',
        ];
        return $map[$type] ?? $type;
    }

    /**
     * 获取数据库列信息
     * @param string $table
     * @return array
     * @throws BusinessException
     */
    public static function getColumnInfo(string $table): array
    {
        Util::checkTableName($table);
        $schema = Util::schema();
        $driver = Util::getDriver();
        $columns = $schema->getColumnListing($table);
        $result = [];

        foreach ($columns as $column) {
            $type = $schema->getColumnType($table, $column);
            $result[$column] = [
                'field' => $column,
                'type' => $type,
                'nullable' => true,
                'default' => null,
                'primary_key' => false,
                'auto_increment' => false,
                'comment' => '',
                'length' => '',
            ];
        }

        // 尝试获取更详细的信息，如果支持
        try {
            $connection = Util::db();
            $pdo = $connection->getPdo();

            if ($driver === 'mysql') {
                $database = Util::getDatabaseName();
                $rows = $connection->select(
                    "SELECT * FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND table_name = ? ORDER BY ORDINAL_POSITION",
                    [$database, $table]
                );
                foreach ($rows as $item) {
                    $field = $item->COLUMN_NAME;
                    if (isset($result[$field])) {
                        $result[$field] = [
                            'field' => $field,
                            'type' => Util::typeToMethod($item->DATA_TYPE, (bool)strpos($item->COLUMN_TYPE ?? '', 'unsigned')),
                            'comment' => $item->COLUMN_COMMENT ?? '',
                            'default' => $item->COLUMN_DEFAULT,
                            'length' => static::getLengthValue($item),
                            'nullable' => ($item->IS_NULLABLE ?? 'YES') !== 'NO',
                            'primary_key' => ($item->COLUMN_KEY ?? '') === 'PRI',
                            'auto_increment' => strpos($item->EXTRA ?? '', 'auto_increment') !== false
                        ];
                    }
                }
            } elseif ($driver === 'pgsql') {
                $database = Util::getDatabaseName();
                $rows = $connection->select(
                    "SELECT
                        c.column_name,
                        c.data_type,
                        c.is_nullable,
                        c.column_default,
                        c.character_maximum_length,
                        c.numeric_precision,
                        c.numeric_scale,
                        COALESCE(pd.description, '') as column_comment
                    FROM information_schema.columns c
                    LEFT JOIN pg_catalog.pg_statio_all_tables st ON st.relname = c.table_name
                    LEFT JOIN pg_catalog.pg_description pd ON pd.objoid = st.relid AND pd.objsubid = c.ordinal_position
                    WHERE c.table_schema = 'public' AND c.table_name = ?
                    ORDER BY c.ordinal_position",
                    [$table]
                );
                foreach ($rows as $item) {
                    $field = $item->column_name;
                    if (isset($result[$field])) {
                        $result[$field] = [
                            'field' => $field,
                            'type' => static::pgsqlTypeToMethod($item->data_type),
                            'comment' => $item->column_comment ?? '',
                            'default' => $item->column_default,
                            'length' => $item->character_maximum_length ?? '',
                            'nullable' => ($item->is_nullable ?? 'YES') === 'YES',
                            'primary_key' => false,
                            'auto_increment' => false,
                        ];
                    }
                }
                // 获取主键信息
                try {
                    $pkRows = $connection->select(
                        "SELECT kcu.column_name
                        FROM information_schema.table_constraints tc
                        JOIN information_schema.key_column_usage kcu ON tc.constraint_name = kcu.constraint_name
                        WHERE tc.table_name = ? AND tc.constraint_type = 'PRIMARY KEY'",
                        [$table]
                    );
                    foreach ($pkRows as $pk) {
                        if (isset($result[$pk->column_name])) {
                            $result[$pk->column_name]['primary_key'] = true;
                        }
                    }
                } catch (Throwable $e) {
                    // ignore
                }
            } elseif ($driver === 'sqlite') {
                // SQLite - use PRAGMA
                try {
                    $rows = $connection->select("PRAGMA table_info(`$table`)");
                    foreach ($rows as $item) {
                        $field = $item->name;
                        if (isset($result[$field])) {
                            $result[$field] = [
                                'field' => $field,
                                'type' => static::sqliteTypeToMethod($item->type),
                                'comment' => '',
                                'default' => $item->dflt_value,
                                'length' => '',
                                'nullable' => !$item->notnull,
                                'primary_key' => (bool)$item->pk,
                                'auto_increment' => (bool)$item->pk && stripos($item->type ?? '', 'int') !== false,
                            ];
                        }
                    }
                } catch (Throwable $e) {
                    // ignore
                }
            } elseif ($driver === 'sqlsrv') {
                $database = Util::getDatabaseName();
                try {
                    $rows = $connection->select(
                        "SELECT
                            c.name AS column_name,
                            t.name AS data_type,
                            c.is_nullable,
                            c.column_default,
                            c.max_length,
                            c.precision,
                            c.scale,
                            ep.value AS column_comment
                        FROM sys.columns c
                        JOIN sys.types t ON c.user_type_id = t.user_type_id
                        LEFT JOIN sys.extended_properties ep ON ep.major_id = c.object_id AND ep.minor_id = c.column_id AND ep.name = 'MS_Description'
                        WHERE c.object_id = OBJECT_ID(?)
                        ORDER BY c.column_id",
                        [$table]
                    );
                    foreach ($rows as $item) {
                        $field = $item->column_name;
                        if (isset($result[$field])) {
                            $result[$field] = [
                                'field' => $field,
                                'type' => static::sqlsrvTypeToMethod($item->data_type),
                                'comment' => $item->column_comment ?? '',
                                'default' => $item->column_default,
                                'length' => $item->max_length,
                                'nullable' => (bool)$item->is_nullable,
                                'primary_key' => false,
                                'auto_increment' => false,
                            ];
                        }
                    }
                } catch (Throwable $e) {
                    // ignore
                }
            }
        } catch (Throwable $e) {
            // 如果无法获取详细信息，使用schema builder的基本信息
        }

        return $result;
    }

    /**
     * PostgreSQL类型转换
     * @param string $type
     * @return string
     */
    protected static function pgsqlTypeToMethod(string $type): string
    {
        $type = strtolower($type);
        $map = [
            'integer' => 'integer',
            'bigint' => 'bigInteger',
            'smallint' => 'smallInteger',
            'character varying' => 'string',
            'varchar' => 'string',
            'character' => 'char',
            'text' => 'text',
            'boolean' => 'boolean',
            'date' => 'date',
            'timestamp without time zone' => 'dateTime',
            'timestamp' => 'dateTime',
            'time without time zone' => 'time',
            'time' => 'time',
            'numeric' => 'decimal',
            'double precision' => 'double',
            'real' => 'float',
            'json' => 'json',
            'jsonb' => 'json',
        ];
        return $map[$type] ?? $type;
    }

    /**
     * SQLite类型转换
     * @param string $type
     * @return string
     */
    protected static function sqliteTypeToMethod(string $type): string
    {
        $type = strtolower($type);
        if (strpos($type, 'int') !== false) return 'integer';
        if ($type === 'text') return 'text';
        if (in_array($type, ['real', 'float', 'double'])) return 'float';
        if ($type === 'blob') return 'binary';
        return 'string';
    }

    /**
     * SQL Server类型转换
     * @param string $type
     * @return string
     */
    protected static function sqlsrvTypeToMethod(string $type): string
    {
        $type = strtolower($type);
        $map = [
            'int' => 'integer',
            'bigint' => 'bigInteger',
            'smallint' => 'smallInteger',
            'tinyint' => 'tinyInteger',
            'nvarchar' => 'string',
            'varchar' => 'string',
            'nchar' => 'char',
            'char' => 'char',
            'text' => 'text',
            'ntext' => 'text',
            'bit' => 'boolean',
            'date' => 'date',
            'datetime' => 'dateTime',
            'datetime2' => 'dateTime',
            'smalldatetime' => 'dateTime',
            'time' => 'time',
            'decimal' => 'decimal',
            'numeric' => 'decimal',
            'float' => 'double',
            'real' => 'float',
            'json' => 'json',
        ];
        return $map[$type] ?? $type;
    }

    /**
     * 按表获取摘要
     * @param $table
     * @param null $section
     * @return array|mixed
     * @throws BusinessException
     */
    public static function getSchema($table, $section = null)
    {
        Util::checkTableName($table);
        $driver = Util::getDriver();
        $columns = static::getColumnInfo($table);
        $forms = [];
        $data_columns = [];

        foreach ($columns as $field => $info) {
            $data_columns[$field] = $info;

            $forms[$field] = [
                'field' => $field,
                'comment' => $info['comment'],
                'control' => static::typeToControl($info['type']),
                'form_show' => !$info['primary_key'],
                'list_show' => true,
                'enable_sort' => false,
                'searchable' => false,
                'search_type' => 'normal',
                'control_args' => '',
            ];
        }

        // 获取表注释和主键、索引 - 使用驱动特定方法
        $tableInfo = static::getTableInfo($table);
        $keys = static::getTableIndexes($table);

        $data = [
            'table' => ['name' => $table, 'comment' => $tableInfo['comment'] ?? '', 'primary_key' => $tableInfo['primary_key'] ?? []],
            'columns' => $data_columns,
            'forms' => $forms,
            'keys' => $keys,
        ];

        $schema = Option::where('name', "table_form_schema_$table")->value('value');
        $form_schema_map = $schema ? json_decode($schema, true) : [];

        foreach ($data['forms'] as $field => $item) {
            if (isset($form_schema_map[$field])) {
                $data['forms'][$field] = $form_schema_map[$field];
            }
        }

        return $section ? $data[$section] : $data;
    }

    /**
     * 获取表信息（注释、主键等）
     * @param string $table
     * @return array
     */
    public static function getTableInfo(string $table): array
    {
        $driver = Util::getDriver();
        $result = ['comment' => '', 'primary_key' => []];

        try {
            if ($driver === 'mysql') {
                $database = Util::getDatabaseName();
                $rows = Util::db()->select(
                    "SELECT TABLE_COMMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?",
                    [$database, $table]
                );
                if (!empty($rows)) {
                    $result['comment'] = $rows[0]->TABLE_COMMENT ?? '';
                }
            } elseif ($driver === 'pgsql') {
                $rows = Util::db()->select(
                    "SELECT obj_description(relfilenode, 'pg_class') AS table_comment FROM pg_class WHERE relname = ?",
                    [$table]
                );
                if (!empty($rows)) {
                    $result['comment'] = $rows[0]->table_comment ?? '';
                }
            } elseif ($driver === 'sqlite') {
                // SQLite 没有表注释
                $result['comment'] = '';
            } elseif ($driver === 'sqlsrv') {
                $rows = Util::db()->select(
                    "SELECT ep.value AS table_comment
                    FROM sys.extended_properties ep
                    WHERE ep.major_id = OBJECT_ID(?) AND ep.minor_id = 0 AND ep.name = 'MS_Description'",
                    [$table]
                );
                if (!empty($rows)) {
                    $result['comment'] = $rows[0]->table_comment ?? '';
                }
            }
        } catch (Throwable $e) {
            // ignore
        }

        // 获取主键
        try {
            $result['primary_key'] = static::getPrimaryKeys($table);
        } catch (Throwable $e) {
            // ignore
        }

        return $result;
    }

    /**
     * 获取主键列表
     * @param string $table
     * @return array
     */
    public static function getPrimaryKeys(string $table): array
    {
        $driver = Util::getDriver();
        $pks = [];

        try {
            if ($driver === 'mysql') {
                $rows = Util::db()->select("SHOW KEYS FROM `$table` WHERE Key_name = 'PRIMARY'");
                foreach ($rows as $row) {
                    $pks[] = $row->Column_name;
                }
            } elseif ($driver === 'pgsql') {
                $rows = Util::db()->select(
                    "SELECT kcu.column_name
                    FROM information_schema.table_constraints tc
                    JOIN information_schema.key_column_usage kcu ON tc.constraint_name = kcu.constraint_name
                    WHERE tc.table_name = ? AND tc.constraint_type = 'PRIMARY KEY'",
                    [$table]
                );
                foreach ($rows as $row) {
                    $pks[] = $row->column_name;
                }
            } elseif ($driver === 'sqlite') {
                $rows = Util::db()->select("PRAGMA table_info(`$table`)");
                foreach ($rows as $row) {
                    if ($row->pk) {
                        $pks[] = $row->name;
                    }
                }
            } elseif ($driver === 'sqlsrv') {
                $rows = Util::db()->select(
                    "SELECT kcu.column_name
                    FROM information_schema.table_constraints tc
                    JOIN information_schema.key_column_usage kcu ON tc.constraint_name = kcu.constraint_name
                    WHERE tc.table_name = ? AND tc.constraint_type = 'PRIMARY KEY'",
                    [$table]
                );
                foreach ($rows as $row) {
                    $pks[] = $row->column_name;
                }
            }
        } catch (Throwable $e) {
            // ignore
        }

        return $pks;
    }

    /**
     * 获取表索引
     * @param string $table
     * @return array
     */
    public static function getTableIndexes(string $table): array
    {
        $driver = Util::getDriver();
        $keys = [];

        try {
            if ($driver === 'mysql') {
                $rows = Util::db()->select("SHOW INDEX FROM `$table`");
                foreach ($rows as $index) {
                    $key_name = $index->Key_name;
                    if ($key_name === 'PRIMARY') continue;
                    if (!isset($keys[$key_name])) {
                        $keys[$key_name] = [
                            'name' => $key_name,
                            'columns' => [],
                            'type' => $index->Non_unique == 0 ? 'unique' : 'normal'
                        ];
                    }
                    $keys[$key_name]['columns'][] = $index->Column_name;
                }
            } elseif ($driver === 'pgsql') {
                $rows = Util::db()->select(
                    "SELECT
                        i.relname AS index_name,
                        a.attname AS column_name,
                        ix.indisunique AS is_unique,
                        ix.indisprimary AS is_primary
                    FROM pg_class t
                    JOIN pg_index ix ON t.oid = ix.indrelid
                    JOIN pg_class i ON i.oid = ix.indexrelid
                    JOIN pg_attribute a ON a.attrelid = t.oid AND a.attnum = ANY(ix.indkey)
                    WHERE t.relname = ? AND NOT ix.indisprimary
                    ORDER BY i.relname, a.attnum",
                    [$table]
                );
                foreach ($rows as $row) {
                    $key_name = $row->index_name;
                    if (!isset($keys[$key_name])) {
                        $keys[$key_name] = [
                            'name' => $key_name,
                            'columns' => [],
                            'type' => $row->is_unique ? 'unique' : 'normal'
                        ];
                    }
                    $keys[$key_name]['columns'][] = $row->column_name;
                }
            } elseif ($driver === 'sqlite') {
                $rows = Util::db()->select("PRAGMA index_list(`$table`)");
                foreach ($rows as $index) {
                    $key_name = $index->name;
                    if ($key_name === 'sqlite_autoindex_' . $table . '_1') continue;
                    $detail = Util::db()->select("PRAGMA index_info(`$key_name`)");
                    $columns = [];
                    foreach ($detail as $d) {
                        $columns[] = $d->name;
                    }
                    $keys[$key_name] = [
                        'name' => $key_name,
                        'columns' => $columns,
                        'type' => $index->unique ? 'unique' : 'normal'
                    ];
                }
            } elseif ($driver === 'sqlsrv') {
                $rows = Util::db()->select(
                    "SELECT
                        i.name AS index_name,
                        c.name AS column_name,
                        i.is_unique,
                        i.is_primary_key
                    FROM sys.indexes i
                    JOIN sys.index_columns ic ON i.object_id = ic.object_id AND i.index_id = ic.index_id
                    JOIN sys.columns c ON ic.object_id = c.object_id AND ic.column_id = c.column_id
                    WHERE i.object_id = OBJECT_ID(?) AND i.is_primary_key = 0
                    ORDER BY i.name, ic.key_ordinal",
                    [$table]
                );
                foreach ($rows as $row) {
                    $key_name = $row->index_name;
                    if (!isset($keys[$key_name])) {
                        $keys[$key_name] = [
                            'name' => $key_name,
                            'columns' => [],
                            'type' => $row->is_unique ? 'unique' : 'normal'
                        ];
                    }
                    $keys[$key_name]['columns'][] = $row->column_name;
                }
            }
        } catch (Throwable $e) {
            // ignore
        }

        return array_reverse($keys, true);
    }

    /**
     * 获取字段长度或默认值
     * @param $schema
     * @return mixed|string
     */
    public static function getLengthValue($schema)
    {
        $type = $schema->DATA_TYPE ?? $schema->type ?? '';
        if (in_array($type, ['float', 'decimal', 'double', 'numeric'])) {
            $precision = $schema->NUMERIC_PRECISION ?? $schema->numeric_precision ?? '';
            $scale = $schema->NUMERIC_SCALE ?? $schema->numeric_scale ?? '';
            return $precision ? "{$precision},{$scale}" : '';
        }
        if ($type === 'enum') {
            $colType = $schema->COLUMN_TYPE ?? '';
            if ($colType) {
                return implode(',', array_map(function($item){
                    return trim($item, "'");
                }, explode(',', substr($colType, 5, -1))));
            }
            return '';
        }
        if (in_array($type, ['varchar', 'text', 'char', 'character varying'])) {
            return $schema->CHARACTER_MAXIMUM_LENGTH ?? $schema->character_maximum_length ?? '';
        }
        if (in_array($type, ['time', 'datetime', 'timestamp'])) {
            return $schema->CHARACTER_MAXIMUM_LENGTH ?? $schema->character_maximum_length ?? '';
        }
        return '';
    }

    /**
     * 获取控件参数
     * @param $control
     * @param $control_args
     * @return array
     */
    public static function getControlProps($control, $control_args): array
    {
        if (!$control_args) {
            return [];
        }
        $control = strtolower($control);
        $props = [];
        $split = explode(';', $control_args);
        foreach ($split as $item) {
            $pos = strpos($item, ':');
            if ($pos === false) {
                continue;
            }
            $name = trim(substr($item, 0, $pos));
            $values = trim(substr($item, $pos + 1));
            // values = a:v,c:d
            $pos = strpos($values, ':');
            if ($pos !== false && strpos($values, "#") !== 0) {
                $options = explode(',', $values);
                $values = [];
                foreach ($options as $option) {
                    [$v, $n] = explode(':', $option);
                    if (in_array($control, ['select', 'selectmulti', 'treeselect', 'treemultiselect']) && $name == 'data') {
                        $values[] = ['value' => $v, 'name' => $n];
                    } else {
                        $values[$v] = $n;
                    }
                }
            }
            $props[$name] = $values;
        }
        return $props;

    }

    /**
     * 获取某个composer包的版本
     * @param string $package
     * @return mixed|string
     */
    public static function getPackageVersion(string $package)
    {
        $installed_php = base_path('vendor/composer/installed.php');
        if (is_file($installed_php)) {
            $packages = include $installed_php;
        }
        return substr($packages['versions'][$package]['version'] ?? 'unknown  ', 0, -2);
    }


    /**
     * Reload webman
     * @return bool
     */
    public static function reloadWebman()
    {
        if (function_exists('posix_kill')) {
            try {
                posix_kill(posix_getppid(), SIGUSR1);
                return true;
            } catch (Throwable $e) {}
        } else {
            Timer::add(1, function () {
                Worker::stopAll();
            });
        }
        return false;
    }

    /**
     * Pause file monitor
     * @return void
     */
    public static function pauseFileMonitor()
    {
        if (method_exists(Monitor::class, 'pause')) {
            Monitor::pause();
        }
    }

    /**
     * Resume file monitor
     * @return void
     */
    public static function resumeFileMonitor()
    {
        if (method_exists(Monitor::class, 'resume')) {
            Monitor::resume();
        }
    }

}
