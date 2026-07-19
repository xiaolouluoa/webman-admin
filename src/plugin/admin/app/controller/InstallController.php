<?php

namespace plugin\admin\app\controller;

use Illuminate\Database\Capsule\Manager;
use plugin\admin\app\common\Util;
use support\exception\BusinessException;
use support\Request;
use support\Response;
use Webman\Captcha\CaptchaBuilder;

/**
 * 安装
 */
class InstallController extends Base
{
    /**
     * 不需要登录的方法
     * @var string[]
     */
    protected $noNeedLogin = ['step1', 'step2'];

    /**
     * 设置数据库
     * @param Request $request
     * @return Response
     * @throws BusinessException|\Throwable
     */
    public function step1(Request $request): Response
    {
        $database_config_file = base_path() . '/plugin/admin/config/database.php';
        clearstatcache();
        if (is_file($database_config_file)) {
            return $this->json(1, '管理后台已经安装！如需重新安装，请删除该插件数据库配置文件并重启');
        }

        if (!class_exists(CaptchaBuilder::class) || !class_exists(Manager::class)) {
            return $this->json(1, '请运行 composer require -W illuminate/database 安装illuminate/database组件并重启');
        }

        $driver = $request->post('driver', 'mysql');
        $user = $request->post('user');
        $password = $request->post('password');
        $database = $request->post('database');
        $host = $request->post('host');
        $port = (int)$request->post('port') ?: 3306;
        $overwrite = $request->post('overwrite');

        try {
            $db = $this->getPdo($driver, $host, $user, $password, $port, $database);

            if ($driver === 'mysql') {
                $smt = $db->query("show databases like '$database'");
                if (empty($smt->fetchAll())) {
                    $db->exec("create database `$database`");
                }
                $db->exec("use $database");
                $smt = $db->query("show tables");
                $tables = $smt->fetchAll();
            } elseif ($driver === 'pgsql') {
                $smt = $db->query("SELECT schema_name FROM information_schema.schemata WHERE schema_name = 'public'");
                $smt = $db->query("SELECT table_name FROM information_schema.tables WHERE table_schema = 'public'");
                $tables = $smt->fetchAll();
            } elseif ($driver === 'sqlite') {
                $smt = $db->query("SELECT name FROM sqlite_master WHERE type='table'");
                $tables = $smt->fetchAll();
            } elseif ($driver === 'sqlsrv') {
                $smt = $db->query("SELECT table_name FROM information_schema.tables WHERE table_type = 'BASE TABLE'");
                $tables = $smt->fetchAll();
            } else {
                $smt = $db->query("SELECT table_name FROM information_schema.tables WHERE table_schema = 'public'");
                $tables = $smt->fetchAll();
            }
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            if (stripos($msg, 'Access denied for user')) {
                return $this->json(1, '数据库用户名或密码错误');
            }
            if (stripos($msg, 'Connection refused')) {
                return $this->json(1, 'Connection refused. 请确认数据库IP端口是否正确，数据库已经启动');
            }
            if (stripos($msg, 'timed out')) {
                return $this->json(1, '数据库连接超时，请确认数据库IP端口是否正确，安全组及防火墙已经放行端口');
            }
            if (stripos($msg, 'could not find driver')) {
                return $this->json(1, '请安装对应的数据库PDO扩展');
            }
            if (stripos($msg, 'unable to open database')) {
                return $this->json(1, '无法打开数据库文件，请检查路径是否正确');
            }
            throw $e;
        }

        $tables_to_install = [
            'wa_admins',
            'wa_admin_roles',
            'wa_roles',
            'wa_rules',
            'wa_options',
            'wa_users',
            'wa_uploads',
        ];

        $tables_exist = [];
        foreach ($tables as $table) {
            $table_array = (array)$table;
            $tables_exist[] = current($table_array);
        }
        $tables_conflict = array_intersect($tables_to_install, $tables_exist);
        if (!$overwrite) {
            if ($tables_conflict) {
                return $this->json(1, '以下表' . implode(',', $tables_conflict) . '已经存在，如需覆盖请选择强制覆盖');
            }
        } else {
            foreach ($tables_conflict as $table) {
                $db->exec("DROP TABLE IF EXISTS \"$table\"");
            }
        }

        $sql_file = base_path() . '/plugin/admin/install.sql';
        if (!is_file($sql_file)) {
            return $this->json(1, '数据库SQL文件不存在');
        }

        $sql_query = file_get_contents($sql_file);
        $sql_query = $this->removeComments($sql_query);
        $sql_query = $this->splitSqlFile($sql_query, ';');

        // 转换SQL以适应不同数据库
        if ($driver !== 'mysql') {
            $sql_query = $this->convertSqlForDriver($sql_query, $driver);
        }

        foreach ($sql_query as $sql) {
            $sql = trim($sql);
            if ($sql) {
                $db->exec($sql);
            }
        }

        // 导入菜单
        $menus = include base_path() . '/plugin/admin/config/menu.php';
        // 安装过程中没有数据库配置，无法使用api\Menu::import()方法
        $this->importMenu($menus, $db, $driver);

        $config_content = $this->buildDatabaseConfig($driver, $host, $port, $database, $user, $password);

        file_put_contents($database_config_file, $config_content);

        // 尝试reload
        if (function_exists('posix_kill')) {
            set_error_handler(function () {});
            posix_kill(posix_getppid(), SIGUSR1);
            restore_error_handler();
        }

        return $this->json(0);
    }

    /**
     * 设置管理员
     * @param Request $request
     * @return Response
     * @throws BusinessException
     */
    public function step2(Request $request): Response
    {
        $username = $request->post('username');
        $password = $request->post('password');
        $password_confirm = $request->post('password_confirm');
        if ($password != $password_confirm) {
            return $this->json(1, '两次密码不一致');
        }
        if (!is_file($config_file = base_path() . '/plugin/admin/config/database.php')) {
            return $this->json(1, '请先完成第一步数据库配置');
        }
        $config = include $config_file;
        $default = $config['default'] ?? 'mysql';
        $connection = $config['connections'][$default];
        $driver = $connection['driver'] ?? 'mysql';

        $pdo = $this->getPdo($driver, $connection['host'] ?? '', $connection['username'] ?? '', $connection['password'] ?? '', $connection['port'] ?? 3306, $connection['database'] ?? '');

        $tablePrefix = $this->getTablePrefix($driver);
        $adminsTable = $tablePrefix . 'wa_admins';
        $adminRolesTable = $tablePrefix . 'wa_admin_roles';

        $pdo->query("select * from `$adminsTable`")->fetchAll();
        $smt = $pdo->prepare("insert into `$adminsTable` (`username`, `password`, `nickname`, `created_at`, `updated_at`) values (:username, :password, :nickname, :created_at, :updated_at)");
        $time = date('Y-m-d H:i:s');
        $data = [
            'username' => $username,
            'password' => Util::passwordHash($password),
            'nickname' => '超级管理员',
            'created_at' => $time,
            'updated_at' => $time
        ];
        foreach ($data as $key => $value) {
            $smt->bindValue($key, $value);
        }
        $smt->execute();
        $admin_id = $pdo->lastInsertId();

        $smt = $pdo->prepare("insert into `$adminRolesTable` (`role_id`, `admin_id`) values (:role_id, :admin_id)");
        $smt->bindValue('role_id', 1);
        $smt->bindValue('admin_id', $admin_id);
        $smt->execute();

        $request->session()->flush();
        return $this->json(0);
    }

    /**
     * 添加菜单
     * @param array $menu
     * @param \PDO $pdo
     * @param string $driver
     * @return int
     */
    protected function addMenu(array $menu, \PDO $pdo, string $driver = 'mysql'): int
    {
        $allow_columns = ['title', 'key', 'icon', 'href', 'pid', 'weight', 'type'];
        $data = [];
        foreach ($allow_columns as $column) {
            if (isset($menu[$column])) {
                $data[$column] = $menu[$column];
            }
        }
        $time = date('Y-m-d H:i:s');
        $data['created_at'] = $data['updated_at'] = $time;
        $values = [];
        foreach ($data as $k => $v) {
            $values[] = ":$k";
        }
        $columns = array_keys($data);
        foreach ($columns as $k => $column) {
            $columns[$k] = "\"$column\"";
        }
        $sql = "insert into wa_rules (" .implode(',', $columns). ") values (" . implode(',', $values) . ")";
        $smt = $pdo->prepare($sql);
        foreach ($data as $key => $value) {
            $smt->bindValue($key, $value);
        }
        $smt->execute();
        return $pdo->lastInsertId();
    }

    /**
     * 导入菜单
     * @param array $menu_tree
     * @param \PDO $pdo
     * @param string $driver
     * @return void
     */
    protected function importMenu(array $menu_tree, \PDO $pdo, string $driver = 'mysql')
    {
        if (is_numeric(key($menu_tree)) && !isset($menu_tree['key'])) {
            foreach ($menu_tree as $item) {
                $this->importMenu($item, $pdo, $driver);
            }
            return;
        }
        $children = $menu_tree['children'] ?? [];
        unset($menu_tree['children']);
        $smt = $pdo->prepare("select * from wa_rules where \"key\"=:key limit 1");
        $smt->execute(['key' => $menu_tree['key']]);
        $old_menu = $smt->fetch();
        if ($old_menu) {
            $pid = $old_menu['id'];
            $params = [
                'title' => $menu_tree['title'],
                'icon' => $menu_tree['icon'] ?? '',
                'key' => $menu_tree['key'],
            ];
            $sql = "update wa_rules set title=:title, icon=:icon where \"key\"=:key";
            $smt = $pdo->prepare($sql);
            $smt->execute($params);
        } else {
            $pid = $this->addMenu($menu_tree, $pdo, $driver);
        }
        foreach ($children as $menu) {
            $menu['pid'] = $pid;
            $this->importMenu($menu, $pdo, $driver);
        }
    }

    /**
     * 去除sql文件中的注释
     * @param $sql
     * @return string
     */
    protected function removeComments($sql): string
    {
        return preg_replace("/(\n--[^\n]*)/","", $sql);
    }

    /**
     * 分割sql文件
     * @param $sql
     * @param $delimiter
     * @return array
     */
    function splitSqlFile($sql, $delimiter): array
    {
        $tokens = explode($delimiter, $sql);
        $output = array();
        $matches = array();
        $token_count = count($tokens);
        for ($i = 0; $i < $token_count; $i++) {
            if (($i != ($token_count - 1)) || (strlen($tokens[$i] > 0))) {
                $total_quotes = preg_match_all("/'/", $tokens[$i], $matches);
                $escaped_quotes = preg_match_all("/(?<!\\\\)(\\\\\\\\)*\\\\'/", $tokens[$i], $matches);
                $unescaped_quotes = $total_quotes - $escaped_quotes;

                if (($unescaped_quotes % 2) == 0) {
                    $output[] = $tokens[$i];
                    $tokens[$i] = "";
                } else {
                    $temp = $tokens[$i] . $delimiter;
                    $tokens[$i] = "";

                    $complete_stmt = false;
                    for ($j = $i + 1; (!$complete_stmt && ($j < $token_count)); $j++) {
                        $total_quotes = preg_match_all("/'/", $tokens[$j], $matches);
                        $escaped_quotes = preg_match_all("/(?<!\\\\)(\\\\\\\\)*\\\\'/", $tokens[$j], $matches);
                        $unescaped_quotes = $total_quotes - $escaped_quotes;
                        if (($unescaped_quotes % 2) == 1) {
                            $output[] = $temp . $tokens[$j];
                            $tokens[$j] = "";
                            $temp = "";
                            $complete_stmt = true;
                            $i = $j;
                        } else {
                            $temp .= $tokens[$j] . $delimiter;
                            $tokens[$j] = "";
                        }

                    }
                }
            }
        }

        return $output;
    }

    /**
     * 获取pdo连接
     * @param string $driver
     * @param $host
     * @param $username
     * @param $password
     * @param $port
     * @param $database
     * @return \PDO
     */
    protected function getPdo(string $driver, $host, $username, $password, $port, $database = null): \PDO
    {
        $driver = strtolower($driver);

        if ($driver === 'mysql') {
            $dsn = "mysql:host=$host;port=$port;";
            if ($database) {
                $dsn .= "dbname=$database";
            }
            $params = [
                \PDO::MYSQL_ATTR_INIT_COMMAND => "set names utf8mb4",
                \PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
                \PDO::ATTR_EMULATE_PREPARES => false,
                \PDO::ATTR_TIMEOUT => 5,
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            ];
        } elseif ($driver === 'pgsql') {
            $dsn = "pgsql:host=$host;port=$port;";
            if ($database) {
                $dsn .= "dbname=$database";
            }
            $params = [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_TIMEOUT => 5,
            ];
        } elseif ($driver === 'sqlite') {
            $dbPath = $database ?: base_path() . '/plugin/admin/database.sqlite';
            $dsn = "sqlite:$dbPath";
            $params = [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_TIMEOUT => 5,
            ];
        } elseif ($driver === 'sqlsrv') {
            $dsn = "sqlsrv:Server=$host,$port;";
            if ($database) {
                $dsn .= "Database=$database";
            }
            $params = [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_TIMEOUT => 5,
            ];
        } else {
            throw new BusinessException("不支持的数据库类型: $driver");
        }

        return new \PDO($dsn, $username, $password, $params);
    }

    /**
     * 构建数据库配置内容
     * @param string $driver
     * @param string $host
     * @param int $port
     * @param string $database
     * @param string $user
     * @param string $password
     * @return string
     */
    protected function buildDatabaseConfig(string $driver, string $host, int $port, string $database, string $user, string $password): string
    {
        $driver = strtolower($driver);

        if ($driver === 'mysql') {
            return <<<EOF
<?php
return  [
    'default' => 'mysql',
    'connections' => [
        'mysql' => [
            'driver'      => 'mysql',
            'host'        => '$host',
            'port'        => '$port',
            'database'    => '$database',
            'username'    => '$user',
            'password'    => '$password',
            'charset'     => 'utf8mb4',
            'collation'   => 'utf8mb4_general_ci',
            'prefix'      => '',
            'strict'      => true,
            'engine'      => null,
        ],
    ],
];
EOF;
        } elseif ($driver === 'pgsql') {
            return <<<EOF
<?php
return  [
    'default' => 'pgsql',
    'connections' => [
        'pgsql' => [
            'driver'      => 'pgsql',
            'host'        => '$host',
            'port'        => '$port',
            'database'    => '$database',
            'username'    => '$user',
            'password'    => '$password',
            'charset'     => 'utf8',
            'prefix'      => '',
            'schema'      => 'public',
            'sslmode'     => 'prefer',
        ],
    ],
];
EOF;
        } elseif ($driver === 'sqlite') {
            $dbPath = base_path() . '/plugin/admin/database.sqlite';
            return <<<EOF
<?php
return  [
    'default' => 'sqlite',
    'connections' => [
        'sqlite' => [
            'driver'   => 'sqlite',
            'database' => '$dbPath',
            'prefix'   => '',
        ],
    ],
];
EOF;
        } elseif ($driver === 'sqlsrv') {
            return <<<EOF
<?php
return  [
    'default' => 'sqlsrv',
    'connections' => [
        'sqlsrv' => [
            'driver'      => 'sqlsrv',
            'host'        => '$host',
            'port'        => '$port',
            'database'    => '$database',
            'username'    => '$user',
            'password'    => '$password',
            'charset'     => 'utf8',
            'prefix'      => '',
        ],
    ],
];
EOF;
        }

        throw new BusinessException("不支持的数据库类型: $driver");
    }

    /**
     * 获取表前缀
     * @param string $driver
     * @return string
     */
    protected function getTablePrefix(string $driver): string
    {
        return '';
    }

    /**
     * 转换SQL以适应不同数据库
     * @param array $sqls
     * @param string $driver
     * @return array
     */
    protected function convertSqlForDriver(array $sqls, string $driver): array
    {
        $converted = [];
        foreach ($sqls as $sql) {
            $sql = trim($sql);
            if (!$sql) continue;

            if ($driver === 'pgsql') {
                // 转换MySQL的CREATE TABLE到PostgreSQL
                $sql = $this->mysqlToPgsql($sql);
            } elseif ($driver === 'sqlite') {
                $sql = $this->mysqlToSqlite($sql);
            } elseif ($driver === 'sqlsrv') {
                $sql = $this->mysqlToSqlsrv($sql);
            }

            $converted[] = $sql;
        }
        return $converted;
    }

    /**
     * MySQL DDL转PostgreSQL
     * @param string $sql
     * @return string
     */
    protected function mysqlToPgsql(string $sql): string
    {
        // 替换反引号为双引号
        $sql = str_replace('`', '"', $sql);

        // 移除 ENGINE=InnoDB
        $sql = preg_replace('/ENGINE\s*=\s*\w+/i', '', $sql);
        // 移除 DEFAULT CHARSET
        $sql = preg_replace('/DEFAULT\s+CHARSET\s*=\s*\w+/i', '', $sql);
        // 移除 COLLATE
        $sql = preg_replace('/COLLATE\s*=\s*\w+/i', '', $sql);
        // 移除 AUTO_INCREMENT
        $sql = preg_replace('/AUTO_INCREMENT\s*=\s*\d+/i', '', $sql);
        // 移除 COMMENT
        $sql = preg_replace('/COMMENT\s*=\s*\'.*?\'/i', '', $sql);

        // 转换 AUTO_INCREMENT 为 SERIAL
        $sql = preg_replace('/\bint\s*\(\s*\d+\s*\)\s*NOT\s+NULL\s+AUTO_INCREMENT\b/i', 'SERIAL', $sql);
        $sql = preg_replace('/\bint\s*\(\s*\d+\s*\)\s*AUTO_INCREMENT\b/i', 'SERIAL', $sql);
        $sql = preg_replace('/\bint\s*\(\s*\d+\s*\)\s*unsigned\s*NOT\s+NULL\s+AUTO_INCREMENT\b/i', 'SERIAL', $sql);

        // 转换 tinyint(4) 为 smallint
        $sql = preg_replace('/tinyint\s*\(\s*\d+\s*\)/i', 'smallint', $sql);

        // 转换 int(10) unsigned 为 integer
        $sql = preg_replace('/int\s*\(\s*\d+\s*\)\s*unsigned/i', 'integer', $sql);
        $sql = preg_replace('/int\s*\(\s*\d+\s*\)/i', 'integer', $sql);

        // 移除 COMMENT
        $sql = preg_replace("/COMMENT\s+'(?:[^']|'')*'/i", '', $sql);

        // 移除 LOCK TABLES / UNLOCK TABLES
        if (preg_match('/^\s*LOCK\s+TABLES/i', $sql) || preg_match('/^\s*UNLOCK\s+TABLES/i', $sql)) {
            return '';
        }

        // 转换 INSERT 语法
        $sql = preg_replace('/INSERT\s+INTO\s+/i', 'INSERT INTO ', $sql);

        // 移除行尾分号后的多余内容
        $sql = rtrim($sql, ';');

        // 转换 enum 类型为 varchar
        $sql = preg_replace_callback('/enum\s*\(([^)]+)\)/i', function ($matches) {
            return 'varchar(255)';
        }, $sql);

        return $sql;
    }

    /**
     * MySQL DDL转SQLite
     * @param string $sql
     * @return string
     */
    protected function mysqlToSqlite(string $sql): string
    {
        // 移除反引号
        $sql = str_replace('`', '', $sql);

        // 移除 ENGINE=InnoDB
        $sql = preg_replace('/ENGINE\s*=\s*\w+/i', '', $sql);
        // 移除 DEFAULT CHARSET
        $sql = preg_replace('/DEFAULT\s+CHARSET\s*=\s*\w+/i', '', $sql);
        // 移除 COLLATE
        $sql = preg_replace('/COLLATE\s*=\s*\w+/i', '', $sql);
        // 移除 COMMENT
        $sql = preg_replace('/COMMENT\s*=\s*\'.*?\'/i', '', $sql);

        // 转换 AUTO_INCREMENT 为 AUTOINCREMENT
        $sql = preg_replace('/AUTO_INCREMENT/i', 'AUTOINCREMENT', $sql);

        // 转换 int(N) 为 INTEGER
        $sql = preg_replace('/int\s*\(\s*\d+\s*\)/i', 'INTEGER', $sql);

        // 转换 tinyint 为 INTEGER
        $sql = preg_replace('/tinyint\s*\(\s*\d+\s*\)/i', 'INTEGER', $sql);

        // 转换 varchar(N) 为 TEXT
        $sql = preg_replace('/varchar\s*\(\s*\d+\s*\)/i', 'TEXT', $sql);

        // 移除 COMMENT
        $sql = preg_replace("/COMMENT\s+'(?:[^']|'')*'/i", '', $sql);

        // 移除 LOCK TABLES / UNLOCK TABLES
        if (preg_match('/^\s*LOCK\s+TABLES/i', $sql) || preg_match('/^\s*UNLOCK\s+TABLES/i', $sql)) {
            return '';
        }

        // 转换 enum 为 TEXT
        $sql = preg_replace_callback('/enum\s*\(([^)]+)\)/i', function ($matches) {
            return 'TEXT';
        }, $sql);

        // 转换 decimal 为 REAL
        $sql = preg_replace('/decimal\s*\(\s*\d+\s*,\s*\d+\s*\)/i', 'REAL', $sql);

        // 移除 KEY 定义
        $sql = preg_replace('/,\s*KEY\s+\w+\s*\([^)]+\)/i', '', $sql);
        $sql = preg_replace('/,\s*UNIQUE\s+KEY\s+\w+\s*\([^)]+\)/i', '', $sql);
        $sql = preg_replace('/,\s*UNIQUE\s+\w+\s*\([^)]+\)/i', '', $sql);

        // 移除行尾分号
        $sql = rtrim($sql, ';');

        return $sql . ';';
    }

    /**
     * MySQL DDL转SQL Server
     * @param string $sql
     * @return string
     */
    protected function mysqlToSqlsrv(string $sql): string
    {
        // 替换反引号为方括号
        $sql = preg_replace('/`([^`]+)`/', '[$1]', $sql);

        // 移除 ENGINE=InnoDB
        $sql = preg_replace('/ENGINE\s*=\s*\w+/i', '', $sql);
        // 移除 DEFAULT CHARSET
        $sql = preg_replace('/DEFAULT\s+CHARSET\s*=\s*\w+/i', '', $sql);
        // 移除 COLLATE
        $sql = preg_replace('/COLLATE\s*=\s*\w+/i', '', $sql);

        // 转换 AUTO_INCREMENT 为 IDENTITY
        $sql = preg_replace('/\bint\s*\(\s*\d+\s*\)\s*NOT\s+NULL\s+AUTO_INCREMENT\b/i', 'INT IDENTITY(1,1)', $sql);
        $sql = preg_replace('/\bint\s*\(\s*\d+\s*\)\s*AUTO_INCREMENT\b/i', 'INT IDENTITY(1,1)', $sql);
        $sql = preg_replace('/\bint\s*\(\s*\d+\s*\)\s*unsigned\s*NOT\s+NULL\s+AUTO_INCREMENT\b/i', 'INT IDENTITY(1,1)', $sql);

        // 转换 int(N) unsigned 为 INT
        $sql = preg_replace('/int\s*\(\s*\d+\s*\)\s*unsigned/i', 'INT', $sql);
        $sql = preg_replace('/int\s*\(\s*\d+\s*\)/i', 'INT', $sql);

        // 转换 tinyint(N) 为 TINYINT
        $sql = preg_replace('/tinyint\s*\(\s*\d+\s*\)/i', 'TINYINT', $sql);

        // 转换 varchar(N) 为 NVARCHAR(N)
        $sql = preg_replace_callback('/varchar\s*\(\s*(\d+)\s*\)/i', function ($matches) {
            return 'NVARCHAR(' . $matches[1] . ')';
        }, $sql);

        // 转换 longtext 为 NVARCHAR(MAX)
        $sql = preg_replace('/longtext/i', 'NVARCHAR(MAX)', $sql);
        $sql = preg_replace('/mediumtext/i', 'NVARCHAR(MAX)', $sql);
        $sql = preg_replace('/text/i', 'NVARCHAR(MAX)', $sql);

        // 移除 COMMENT
        $sql = preg_replace("/COMMENT\s+'(?:[^']|'')*'/i", '', $sql);

        // 移除 LOCK TABLES / UNLOCK TABLES
        if (preg_match('/^\s*LOCK\s+TABLES/i', $sql) || preg_match('/^\s*UNLOCK\s+TABLES/i', $sql)) {
            return '';
        }

        // 转换 enum 为 NVARCHAR
        $sql = preg_replace_callback('/enum\s*\(([^)]+)\)/i', function ($matches) {
            return 'NVARCHAR(255)';
        }, $sql);

        // 转换 decimal 精度
        $sql = preg_replace_callback('/decimal\s*\(\s*(\d+)\s*,\s*(\d+)\s*\)/i', function ($matches) {
            return 'DECIMAL(' . $matches[1] . ', ' . $matches[2] . ')';
        }, $sql);

        $sql = rtrim($sql, ';');

        return $sql;
    }

}
