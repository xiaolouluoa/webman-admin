<?php

namespace plugin\admin\app\controller;

use Illuminate\Database\Capsule\Manager;
use plugin\admin\app\common\database\DatabaseAdapterInterface;
use plugin\admin\app\common\database\MysqlAdapter;
use plugin\admin\app\common\database\PgsqlAdapter;
use plugin\admin\app\common\database\SqliteAdapter;
use plugin\admin\app\common\Util;
use support\exception\BusinessException;
use support\Request;
use support\Response;
use Webman\Captcha\CaptchaBuilder;

/**
 * 安装 - 支持多种数据库
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

        // 获取数据库类型
        $dbType = $request->post('db_type', 'mysql');
        $dbType = in_array($dbType, ['mysql', 'sqlite', 'pgsql']) ? $dbType : 'mysql';

        $user = $request->post('user', '');
        $password = $request->post('password', '');
        $database = $request->post('database', '');
        $host = $request->post('host', '127.0.0.1');
        $port = (int)$request->post('port') ?: 3306;
        $overwrite = $request->post('overwrite', false);

        // SQLite特殊处理
        if ($dbType === 'sqlite') {
            return $this->installSqlite($request, $database, $overwrite);
        }

        $adapter = $this->createAdapter($dbType);

        try {
            $db = $this->getPdo($dbType, $host, $user, $password, $port, $database);
            $db->exec("use $database");
            $smt = $db->query("show tables");
            $tables = $smt->fetchAll();
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
            $tables_exist[] = current($table);
        }
        $tables_conflict = array_intersect($tables_to_install, $tables_exist);
        if (!$overwrite) {
            if ($tables_conflict) {
                return $this->json(1, '以下表' . implode(',', $tables_conflict) . '已经存在，如需覆盖请选择强制覆盖');
            }
        } else {
            foreach ($tables_conflict as $table) {
                $quote = $adapter->quoteIdentifier($table);
                $db->exec("DROP TABLE $quote");
            }
        }

        // 根据数据库类型选择SQL文件
        $sql_file = base_path() . "/plugin/admin/install_{$dbType}.sql";
        if (!is_file($sql_file)) {
            $sql_file = base_path() . '/plugin/admin/install.sql';
        }
        if (!is_file($sql_file)) {
            return $this->json(1, '数据库SQL文件不存在');
        }

        $sql_query = file_get_contents($sql_file);
        $sql_query = $this->removeComments($sql_query);
        $sql_query = $this->splitSqlFile($sql_query, ';');
        foreach ($sql_query as $sql) {
            if (trim($sql)) {
                $db->exec($sql);
            }
        }

        // 导入菜单
        $menus = include base_path() . '/plugin/admin/config/menu.php';
        $this->importMenu($menus, $db, $adapter);

        // 生成数据库配置文件
        $this->generateConfig($dbType, $host, $port, $database, $user, $password, $database_config_file);

        // 尝试reload
        if (function_exists('posix_kill')) {
            set_error_handler(function () {});
            posix_kill(posix_getppid(), SIGUSR1);
            restore_error_handler();
        }

        return $this->json(0);
    }

    /**
     * SQLite安装
     * @param Request $request
     * @param string $database
     * @param bool $overwrite
     * @return Response
     * @throws \Throwable
     */
    protected function installSqlite(Request $request, string $database, bool $overwrite): Response
    {
        $database_config_file = base_path() . '/plugin/admin/config/database.php';
        $dbPath = $database ?: base_path() . '/runtime/webman-admin.sqlite';

        // 检查SQLite数据库文件是否存在
        $tables_exist = [];
        if (is_file($dbPath)) {
            $pdo = new \PDO("sqlite:$dbPath");
            $result = $pdo->query("SELECT name FROM sqlite_master WHERE type='table'");
            if ($result) {
                foreach ($result as $row) {
                    $tables_exist[] = $row['name'];
                }
            }
        }

        $tables_to_install = [
            'wa_admins', 'wa_admin_roles', 'wa_roles', 'wa_rules',
            'wa_options', 'wa_users', 'wa_uploads',
        ];

        $tables_conflict = array_intersect($tables_to_install, $tables_exist);
        if (!$overwrite) {
            if ($tables_conflict) {
                return $this->json(1, '以下表' . implode(',', $tables_conflict) . '已经存在，如需覆盖请选择强制覆盖');
            }
        } elseif (is_file($dbPath)) {
            unlink($dbPath);
        }

        // 执行SQLite安装SQL
        $sql_file = base_path() . '/plugin/admin/install_sqlite.sql';
        if (!is_file($sql_file)) {
            return $this->json(1, 'SQLite安装SQL文件不存在');
        }

        $pdo = new \PDO("sqlite:$dbPath");
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA journal_mode=WAL');
        $pdo->exec('PRAGMA foreign_keys=ON');

        $sql_query = file_get_contents($sql_file);
        $sql_query = $this->removeComments($sql_query);
        $sql_query = $this->splitSqlFile($sql_query, ';');
        foreach ($sql_query as $sql) {
            if (trim($sql)) {
                $pdo->exec($sql);
            }
        }

        // 导入菜单
        $menus = include base_path() . '/plugin/admin/config/menu.php';
        $adapter = new SqliteAdapter();
        $this->importMenu($menus, $pdo, $adapter);

        // 生成配置
        $config_content = <<<EOF
<?php
return [
    'default' => 'sqlite',
    'connections' => [
        'sqlite' => [
            'driver'  => 'sqlite',
            'database' => '$dbPath',
            'prefix'  => '',
        ],
    ],
];
EOF;
        file_put_contents($database_config_file, $config_content);

        if (function_exists('posix_kill')) {
            set_error_handler(function () {});
            posix_kill(posix_getppid(), SIGUSR1);
            restore_error_handler();
        }

        return $this->json(0);
    }

    /**
     * 生成数据库配置
     * @param string $dbType
     * @param string $host
     * @param int $port
     * @param string $database
     * @param string $user
     * @param string $password
     * @param string $configFile
     * @return void
     */
    protected function generateConfig(string $dbType, string $host, int $port, string $database, string $user, string $password, string $configFile): void
    {
        if ($dbType === 'pgsql') {
            $config = <<<EOF
<?php
return [
    'default' => 'pgsql',
    'connections' => [
        'pgsql' => [
            'driver'   => 'pgsql',
            'host'     => '$host',
            'port'     => $port,
            'database' => '$database',
            'username' => '$user',
            'password' => '$password',
            'charset'  => 'utf8',
            'prefix'   => '',
            'schema'   => 'public',
            'sslmode'  => 'prefer',
        ],
    ],
];
EOF;
        } else {
            $config = <<<EOF
<?php
return [
    'default' => 'mysql',
    'connections' => [
        'mysql' => [
            'driver'      => 'mysql',
            'host'        => '$host',
            'port'        => $port,
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
        }
        file_put_contents($configFile, $config);
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

        $pdo = $this->getPdoFromConfig($connection);

        $adapter = $this->createAdapterFromConfig($connection);
        $quote = $adapter->quoteIdentifier('wa_admins');

        if ($pdo->query("select * from $quote")->fetchAll()) {
            return $this->json(1, '后台已经安装完毕，无法通过此页面创建管理员');
        }

        $time = date('Y-m-d H:i:s');
        $data = [
            'username' => $username,
            'password' => Util::passwordHash($password),
            'nickname' => '超级管理员',
            'created_at' => $time,
            'updated_at' => $time
        ];

        $columns = [];
        $values = [];
        foreach ($data as $k => $v) {
            $columns[] = $adapter->quoteIdentifier($k);
            $values[] = ":$k";
        }

        $table = $adapter->quoteIdentifier('wa_admins');
        $sql = "insert into $table (" . implode(',', $columns) . ") values (" . implode(',', $values) . ")";
        $smt = $pdo->prepare($sql);
        foreach ($data as $key => $value) {
            $smt->bindValue($key, $value);
        }
        $smt->execute();
        $admin_id = $pdo->lastInsertId();

        $table = $adapter->quoteIdentifier('wa_admin_roles');
        $smt = $pdo->prepare("insert into $table (role_id, admin_id) values (:role_id, :admin_id)");
        $smt->bindValue('role_id', 1);
        $smt->bindValue('admin_id', $admin_id);
        $smt->execute();

        $request->session()->flush();
        return $this->json(0);
    }

    /**
     * 从配置创建PDO连接
     * @param array $connection
     * @return \PDO
     */
    protected function getPdoFromConfig(array $connection): \PDO
    {
        $driver = $connection['driver'] ?? 'mysql';
        $host = $connection['host'] ?? '127.0.0.1';
        $port = $connection['port'] ?? 3306;
        $database = $connection['database'] ?? '';
        $username = $connection['username'] ?? '';
        $password = $connection['password'] ?? '';

        return $this->getPdo($driver, $host, $username, $password, $port, $database);
    }

    /**
     * 从配置创建适配器
     * @param array $connection
     * @return DatabaseAdapterInterface
     */
    protected function createAdapterFromConfig(array $connection): DatabaseAdapterInterface
    {
        $driver = $connection['driver'] ?? 'mysql';
        return $this->createAdapter($driver);
    }

    /**
     * 创建数据库适配器
     * @param string $dbType
     * @return DatabaseAdapterInterface
     */
    protected function createAdapter(string $dbType): DatabaseAdapterInterface
    {
        switch ($dbType) {
            case 'pgsql':
                return new PgsqlAdapter();
            case 'sqlite':
                return new SqliteAdapter();
            default:
                return new MysqlAdapter();
        }
    }

    /**
     * 添加菜单
     * @param array $menu
     * @param \PDO $pdo
     * @param DatabaseAdapterInterface $adapter
     * @return int
     */
    protected function addMenu(array $menu, \PDO $pdo, DatabaseAdapterInterface $adapter): int
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
            $columns[$k] = $adapter->quoteIdentifier($column);
        }
        $table = $adapter->quoteIdentifier('wa_rules');
        $sql = "insert into $table (" . implode(',', $columns) . ") values (" . implode(',', $values) . ")";
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
     * @param DatabaseAdapterInterface $adapter
     * @return void
     */
    protected function importMenu(array $menu_tree, \PDO $pdo, DatabaseAdapterInterface $adapter)
    {
        if (is_numeric(key($menu_tree)) && !isset($menu_tree['key'])) {
            foreach ($menu_tree as $item) {
                $this->importMenu($item, $pdo, $adapter);
            }
            return;
        }
        $children = $menu_tree['children'] ?? [];
        unset($menu_tree['children']);
        $table = $adapter->quoteIdentifier('wa_rules');
        $keyCol = $adapter->quoteIdentifier('key');
        $smt = $pdo->prepare("select * from $table where $keyCol=:key limit 1");
        $smt->execute(['key' => $menu_tree['key']]);
        $old_menu = $smt->fetch();
        if ($old_menu) {
            $pid = $old_menu['id'];
            $params = [
                'title' => $menu_tree['title'],
                'icon' => $menu_tree['icon'] ?? '',
                'key' => $menu_tree['key'],
            ];
            $sql = "update $table set title=:title, icon=:icon where $keyCol=:key";
            $smt = $pdo->prepare($sql);
            $smt->execute($params);
        } else {
            $pid = $this->addMenu($menu_tree, $pdo, $adapter);
        }
        foreach ($children as $menu) {
            $menu['pid'] = $pid;
            $this->importMenu($menu, $pdo, $adapter);
        }
    }

    /**
     * 去除sql文件中的注释
     * @param $sql
     * @return string
     */
    protected function removeComments($sql): string
    {
        $sql = preg_replace("/(\n--[^\n]*)/","", $sql);
        $sql = preg_replace("/\/\*.*?\*\//s", "", $sql);
        return $sql;
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
     * @param string $dbType
     * @param string $host
     * @param string $username
     * @param string $password
     * @param int $port
     * @param string|null $database
     * @return \PDO
     */
    protected function getPdo(string $dbType, string $host, string $username, string $password, int $port, ?string $database = null): \PDO
    {
        $adapter = $this->createAdapter($dbType);
        $config = [
            'host' => $host,
            'port' => $port,
            'database' => $database,
            'username' => $username,
            'password' => $password,
        ];
        $dsn = $adapter->getPdoDsn($config);
        $options = $adapter->getPdoOptions();
        return new \PDO($dsn, $username, $password, $options);
    }

}
