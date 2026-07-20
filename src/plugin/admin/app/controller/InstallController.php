<?php

namespace plugin\admin\app\controller;

use Illuminate\Database\Capsule\Manager;
use plugin\admin\app\common\Util;
use plugin\admin\app\install\MysqlHandler;
use plugin\admin\app\install\PgsqlHandler;
use plugin\admin\app\install\SqliteHandler;
use plugin\admin\app\install\SqlserverHandler;
use plugin\admin\app\install\InstallHandler;
use support\exception\BusinessException;
use support\Request;
use support\Response;
use Webman\Captcha\CaptchaBuilder;

/**
 * 安装
 */
class InstallController extends Base
{
    protected $noNeedLogin = ['step1', 'step2'];

    protected function getHandler(string $driver, array $config = []): InstallHandler
    {
        $map = ['mysql' => MysqlHandler::class, 'pgsql' => PgsqlHandler::class, 'sqlite' => SqliteHandler::class, 'sqlsrv' => SqlserverHandler::class];
        $class = $map[$driver] ?? null;
        if (!$class) throw new BusinessException("不支持的数据库类型: $driver");
        return new $class($config);
    }

    public function step1(Request $request): Response
    {
        $configFile = base_path() . '/plugin/admin/config/database.php';
        clearstatcache();
        if (is_file($configFile)) {
            return $this->json(1, '管理后台已经安装！如需重新安装，请删除该插件数据库配置文件并重启');
        }
        if (!class_exists(CaptchaBuilder::class) || !class_exists(Manager::class)) {
            return $this->json(1, '请运行 composer require -W illuminate/database 安装illuminate/database组件并重启');
        }

        $driver = $request->post('driver', 'mysql');
        $user = $request->post('user', '');
        $password = $request->post('password', '');
        $database = $request->post('database', '');
        $host = $request->post('host', '127.0.0.1');
        $port = (int)$request->post('port') ?: 0;
        $overwrite = $request->post('overwrite');

        if ($driver === 'sqlite') {
            $host = ''; $user = ''; $password = ''; $port = 0;
            if (!$database) $database = base_path() . '/plugin/admin/database.sqlite';
            elseif (!preg_match('#^/#', $database) && !preg_match('#^[a-zA-Z]:#', $database)) $database = base_path() . '/' . ltrim($database, '/');
            $dir = dirname($database);
            if (!is_dir($dir)) mkdir($dir, 0777, true);
        }
        if (!$port) {
            $map = ['mysql' => 3306, 'pgsql' => 5432, 'sqlsrv' => 1433, 'sqlite' => 0];
            $port = $map[$driver] ?? 3306;
        }

        $handler = $this->getHandler($driver, compact('driver','host','port','database') + ['username' => $user, 'password' => $password]);

        try {
            $pdo = $handler->ensureDatabase();

            $tables = ['wa_admins','wa_admin_roles','wa_roles','wa_rules','wa_options','wa_users','wa_uploads'];
            $existing = $handler->getExistingTables($pdo);
            $conflict = array_intersect($tables, $existing);

            if (!$overwrite) {
                if ($conflict) return $this->json(1, '以下表' . implode(',', $conflict) . '已经存在，如需覆盖请选择强制覆盖');
            } else {
                foreach ($conflict as $t) $handler->dropTable($pdo, $t);
            }

            $this->createTables($handler, $pdo);

            $menus = include base_path() . '/plugin/admin/config/menu.php';
            $handler->importMenu($pdo, $menus);

            $content = $this->buildConfig($handler);
            file_put_contents($configFile, $content);

            if (function_exists('posix_kill')) {
                set_error_handler(function(){});
                posix_kill(posix_getppid(), SIGUSR1);
                restore_error_handler();
            }
            return $this->json(0);
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            $code = (string)$e->getCode();

            // SQLSTATE 码（PDOException，跨语言）
            if (in_array($code, ['28000', '1045'])) return $this->json(1, '数据库用户名或密码错误');
            if (in_array($code, ['08006', '08001', '08004', '2002', '2003'])) return $this->json(1, 'Connection refused. 请确认数据库IP端口是否正确，数据库是否启动');
            if ($code === '3D000') return $this->json(1, "数据库 '$database' 不存在，请先创建");

            // 字符串匹配兜底（pg_connect 等非 PDO 异常）
            if (stripos($msg, 'Access denied for user') !== false) return $this->json(1, '数据库用户名或密码错误');
            if (stripos($msg, 'Connection refused') !== false) return $this->json(1, 'Connection refused. 请确认数据库IP端口是否正确，数据库是否启动');
            if (stripos($msg, 'timed out') !== false) return $this->json(1, '数据库连接超时');
            if (stripos($msg, 'could not find driver') !== false) return $this->json(1, '请安装对应的数据库PDO扩展');
            if (stripos($msg, 'unable to open database') !== false) return $this->json(1, '无法打开数据库文件，请检查路径是否正确');
            if (stripos($msg, 'does not exist') !== false || stripos($msg, '不存在') !== false) return $this->json(1, "数据库 '$database' 不存在，请先创建");
            return $this->json(1, '数据库连接失败: ' . $msg);
        }
    }

    protected function createTables(InstallHandler $handler, \PDO $pdo): void
    {
        $driver = $handler->getDriver();
        $q = [$handler, 'quoteIdentifier'];

        $schema = [
            'wa_admin_roles' => [
                'columns' => [
                    $this->col($handler, 'id', 'serial'),
                    $this->col($handler, 'role_id', 'integer', ['nn' => true]),
                    $this->col($handler, 'admin_id', 'integer', ['nn' => true]),
                ],
                'keys' => [
                    "PRIMARY KEY ({$q('id')})",
                    $driver === 'mysql' ? "UNIQUE KEY {$q('role_admin_id')} ({$q('role_id')},{$q('admin_id')})"
                        : "CONSTRAINT {$q('wa_admin_roles_role_admin_id')} UNIQUE ({$q('role_id')},{$q('admin_id')})",
                ],
            ],
            'wa_admins' => [
                'columns' => [
                    $this->col($handler, 'id', 'serial'),
                    $this->col($handler, 'username', 'varchar', ['len' => 32, 'nn' => true]),
                    $this->col($handler, 'nickname', 'varchar', ['len' => 40, 'nn' => true]),
                    $this->col($handler, 'password', 'varchar', ['len' => 255, 'nn' => true]),
                    $this->col($handler, 'avatar', 'varchar', ['len' => 255, 'def' => '/app/admin/avatar.png']),
                    $this->col($handler, 'email', 'varchar', ['len' => 100]),
                    $this->col($handler, 'mobile', 'varchar', ['len' => 16]),
                    $this->col($handler, 'created_at', 'timestamp'),
                    $this->col($handler, 'updated_at', 'timestamp'),
                    $this->col($handler, 'login_at', 'timestamp'),
                    $this->col($handler, 'status', 'smallint'),
                ],
                'keys' => [
                    "PRIMARY KEY ({$q('id')})",
                    $driver === 'mysql' ? "UNIQUE KEY {$q('username')} ({$q('username')})"
                        : "CONSTRAINT {$q('wa_admins_username')} UNIQUE ({$q('username')})",
                ],
            ],
            'wa_options' => [
                'columns' => [
                    $this->col($handler, 'id', 'serial'),
                    $this->col($handler, 'name', 'varchar', ['len' => 255, 'nn' => true]),
                    $this->col($handler, 'value', 'text', ['nn' => true]),
                    $this->col($handler, 'created_at', 'timestamp', ['def' => '2022-08-15 00:00:00']),
                    $this->col($handler, 'updated_at', 'timestamp', ['def' => '2022-08-15 00:00:00']),
                ],
                'keys' => ["PRIMARY KEY ({$q('id')})"],
            ],
            'wa_roles' => [
                'columns' => [
                    $this->col($handler, 'id', 'serial'),
                    $this->col($handler, 'name', 'varchar', ['len' => 80, 'nn' => true]),
                    $this->col($handler, 'rules', 'text'),
                    $this->col($handler, 'created_at', 'timestamp', ['nn' => true]),
                    $this->col($handler, 'updated_at', 'timestamp', ['nn' => true]),
                    $this->col($handler, 'pid', 'integer'),
                ],
                'keys' => ["PRIMARY KEY ({$q('id')})"],
            ],
            'wa_rules' => [
                'columns' => [
                    $this->col($handler, 'id', 'serial'),
                    $this->col($handler, 'title', 'varchar', ['len' => 255, 'nn' => true]),
                    $this->col($handler, 'icon', 'varchar', ['len' => 255]),
                    $this->col($handler, 'key', 'varchar', ['len' => 255, 'nn' => true]),
                    $this->col($handler, 'pid', 'integer', ['def' => 0]),
                    $this->col($handler, 'created_at', 'timestamp', ['nn' => true]),
                    $this->col($handler, 'updated_at', 'timestamp', ['nn' => true]),
                    $this->col($handler, 'href', 'varchar', ['len' => 255]),
                    $this->col($handler, 'type', 'integer', ['def' => 1, 'nn' => true]),
                    $this->col($handler, 'weight', 'integer', ['def' => 0]),
                ],
                'keys' => ["PRIMARY KEY ({$q('id')})"],
            ],
            'wa_uploads' => [
                'columns' => [
                    $this->col($handler, 'id', 'serial'),
                    $this->col($handler, 'name', 'varchar', ['len' => 128, 'nn' => true]),
                    $this->col($handler, 'url', 'varchar', ['len' => 255, 'nn' => true]),
                    $this->col($handler, 'admin_id', 'integer'),
                    $this->col($handler, 'file_size', 'integer', ['nn' => true]),
                    $this->col($handler, 'mime_type', 'varchar', ['len' => 255, 'nn' => true]),
                    $this->col($handler, 'image_width', 'integer'),
                    $this->col($handler, 'image_height', 'integer'),
                    $this->col($handler, 'ext', 'varchar', ['len' => 128, 'nn' => true]),
                    $this->col($handler, 'storage', 'varchar', ['len' => 255, 'nn' => true, 'def' => 'local']),
                    $this->col($handler, 'created_at', 'date'),
                    $this->col($handler, 'category', 'varchar', ['len' => 128]),
                    $this->col($handler, 'updated_at', 'date'),
                ],
                'keys' => array_merge(
                    ["PRIMARY KEY ({$q('id')})"],
                    $driver === 'mysql'
                        ? ["KEY {$q('category')} ({$q('category')})", "KEY {$q('admin_id')} ({$q('admin_id')})", "KEY {$q('name')} ({$q('name')})", "KEY {$q('ext')} ({$q('ext')})"]
                        : []
                ),
            ],
            'wa_users' => [
                'columns' => [
                    $this->col($handler, 'id', 'serial'),
                    $this->col($handler, 'username', 'varchar', ['len' => 32, 'nn' => true]),
                    $this->col($handler, 'nickname', 'varchar', ['len' => 40, 'nn' => true]),
                    $this->col($handler, 'password', 'varchar', ['len' => 255, 'nn' => true]),
                    $this->col($handler, 'sex', 'varchar', ['len' => 255, 'def' => '1']),
                    $this->col($handler, 'avatar', 'varchar', ['len' => 255]),
                    $this->col($handler, 'email', 'varchar', ['len' => 128]),
                    $this->col($handler, 'mobile', 'varchar', ['len' => 16]),
                    $this->col($handler, 'level', 'smallint', ['def' => 0, 'nn' => true]),
                    $this->col($handler, 'birthday', 'date'),
                    $this->col($handler, 'money', 'decimal', ['prec' => 10, 'scale' => 2, 'def' => 0.00]),
                    $this->col($handler, 'score', 'integer', ['def' => 0, 'nn' => true]),
                    $this->col($handler, 'last_time', 'timestamp'),
                    $this->col($handler, 'last_ip', 'varchar', ['len' => 50]),
                    $this->col($handler, 'join_time', 'timestamp'),
                    $this->col($handler, 'join_ip', 'varchar', ['len' => 50]),
                    $this->col($handler, 'token', 'varchar', ['len' => 50]),
                    $this->col($handler, 'created_at', 'timestamp'),
                    $this->col($handler, 'updated_at', 'timestamp'),
                    $this->col($handler, 'role', 'integer', ['def' => 1, 'nn' => true]),
                    $this->col($handler, 'status', 'smallint', ['def' => 0, 'nn' => true]),
                ],
                'keys' => array_merge(
                    ["PRIMARY KEY ({$q('id')})"],
                    $driver === 'mysql'
                        ? ["UNIQUE KEY {$q('username')} ({$q('username')})", "KEY {$q('join_time')} ({$q('join_time')})", "KEY {$q('mobile')} ({$q('mobile')})", "KEY {$q('email')} ({$q('email')})"]
                        : ["CONSTRAINT {$q('wa_users_username')} UNIQUE ({$q('username')})"]
                ),
            ],
        ];

        // 执行建表
        foreach ($schema as $table => $def) {
            $pdo->exec($handler->getCreateTableSql($table, $def['columns'], $def['keys']));
        }

        // 插入初始数据
        $this->insertData($handler, $pdo);

        // 非MySQL创建独立索引
        if ($driver !== 'mysql') {
            $indexes = [
                ['wa_uploads', 'category'], ['wa_uploads', 'admin_id'], ['wa_uploads', 'name'], ['wa_uploads', 'ext'],
                ['wa_users', 'join_time'], ['wa_users', 'mobile'], ['wa_users', 'email'],
            ];
            foreach ($indexes as $idx) {
                $pdo->exec("CREATE INDEX {$q($idx[0] . '_' . $idx[1])} ON {$q($idx[0])} ({$q($idx[1])})");
            }
        }
    }

    protected function col(InstallHandler $handler, string $name, string $type, array $opts = []): string
    {
        $d = $handler->getDriver();
        $q = [$handler, 'quoteIdentifier'];
        $nn = !empty($opts['nn']);
        $def = $opts['def'] ?? null;
        $len = $opts['len'] ?? 255;
        $prec = $opts['prec'] ?? 10;
        $scale = $opts['scale'] ?? 2;

        if ($d === 'mysql') {
            switch ($type) {
                case 'serial': return "{$q($name)} int(10) unsigned NOT NULL AUTO_INCREMENT";
                case 'integer': return "{$q($name)} int(11)" . ($nn ? ' NOT NULL' : '') . ($def !== null ? " DEFAULT $def" : '');
                case 'smallint': return "{$q($name)} tinyint(4)" . ($nn ? ' NOT NULL' : '') . ($def !== null ? " DEFAULT $def" : '');
                case 'varchar': return "{$q($name)} varchar($len)" . ($nn ? ' NOT NULL' : '') . ($def !== null ? " DEFAULT '$def'" : '');
                case 'text': return "{$q($name)} longtext" . ($nn ? ' NOT NULL' : '');
                case 'timestamp': return "{$q($name)} datetime" . ($nn ? ' NOT NULL' : '') . ($def !== null ? " DEFAULT '$def'" : '');
                case 'date': return "{$q($name)} date";
                case 'decimal': return "{$q($name)} decimal($prec,$scale) NOT NULL" . ($def !== null ? " DEFAULT $def" : '');
                default: return "{$q($name)} $type";
            }
        }
        if ($d === 'pgsql') {
            switch ($type) {
                case 'serial': return "{$q($name)} SERIAL";
                case 'integer': return "{$q($name)} INTEGER" . ($nn ? ' NOT NULL' : '') . ($def !== null ? " DEFAULT $def" : '');
                case 'smallint': return "{$q($name)} SMALLINT" . ($nn ? ' NOT NULL' : '') . ($def !== null ? " DEFAULT $def" : '');
                case 'varchar': return "{$q($name)} VARCHAR($len)" . ($nn ? ' NOT NULL' : '') . ($def !== null ? " DEFAULT '$def'" : '');
                case 'text': return "{$q($name)} TEXT" . ($nn ? ' NOT NULL' : '');
                case 'timestamp': return "{$q($name)} TIMESTAMP(0)" . ($nn ? ' NOT NULL' : '') . ($def !== null ? " DEFAULT '$def'" : '');
                case 'date': return "{$q($name)} DATE";
                case 'decimal': return "{$q($name)} DECIMAL($prec,$scale)" . ($nn ? ' NOT NULL' : '') . ($def !== null ? " DEFAULT $def" : '');
                default: return "{$q($name)} $type";
            }
        }
        if ($d === 'sqlite') {
            switch ($type) {
                case 'serial': return "{$q($name)} INTEGER PRIMARY KEY AUTOINCREMENT";
                case 'integer': case 'smallint': return "{$q($name)} INTEGER" . ($nn ? ' NOT NULL' : '') . ($def !== null ? " DEFAULT $def" : '');
                case 'varchar': case 'text': case 'timestamp': case 'date': return "{$q($name)} TEXT" . ($nn ? ' NOT NULL' : '') . ($def !== null ? " DEFAULT '$def'" : '');
                case 'decimal': return "{$q($name)} REAL" . ($nn ? ' NOT NULL' : '') . ($def !== null ? " DEFAULT $def" : '');
                default: return "{$q($name)} TEXT";
            }
        }
        if ($d === 'sqlsrv') {
            switch ($type) {
                case 'serial': return "{$q($name)} INT IDENTITY(1,1) NOT NULL";
                case 'integer': return "{$q($name)} INT" . ($nn ? ' NOT NULL' : '') . ($def !== null ? " DEFAULT $def" : '');
                case 'smallint': return "{$q($name)} TINYINT" . ($nn ? ' NOT NULL' : '') . ($def !== null ? " DEFAULT $def" : '');
                case 'varchar': return "{$q($name)} " . ($len > 4000 ? 'NVARCHAR(MAX)' : "NVARCHAR($len)") . ($nn ? ' NOT NULL' : '') . ($def !== null ? " DEFAULT '$def'" : '');
                case 'text': return "{$q($name)} NVARCHAR(MAX)" . ($nn ? ' NOT NULL' : '');
                case 'timestamp': return "{$q($name)} DATETIME2" . ($nn ? ' NOT NULL' : '') . ($def !== null ? " DEFAULT '$def'" : '');
                case 'date': return "{$q($name)} DATE";
                case 'decimal': return "{$q($name)} DECIMAL($prec,$scale) NOT NULL" . ($def !== null ? " DEFAULT $def" : '');
                default: return "{$q($name)} NVARCHAR(255)";
            }
        }
        return '';
    }

    protected function insertData(InstallHandler $handler, \PDO $pdo): void
    {
        $q = [$handler, 'quoteIdentifier'];
        $t = date('Y-m-d H:i:s');

        $options = [
            'system_config' => '{"logo":{"title":"Webman Admin","image":"\\/app\\/admin\\/admin\\/images\\/logo.png"},"menu":{"data":"\\/app\\/admin\\/rule\\/get","method":"GET","accordion":true,"collapse":false,"control":false,"controlWidth":500,"select":"0","async":true},"tab":{"enable":true,"keepState":true,"preload":false,"session":true,"max":"30","index":{"id":"0","href":"\\/app\\/admin\\/index\\/dashboard","title":"仪表盘"}},"theme":{"defaultColor":"2","defaultMenu":"light-theme","defaultHeader":"light-theme","allowCustom":true,"banner":false},"colors":[{"id":"1","color":"#36b368","second":"#f0f9eb"},{"id":"2","color":"#2d8cf0","second":"#ecf5ff"},{"id":"3","color":"#f6ad55","second":"#fdf6ec"},{"id":"4","color":"#f56c6c","second":"#fef0f0"},{"id":"5","color":"#3963bc","second":"#ecf5ff"}],"other":{"keepLoad":"500","autoHead":false,"footer":false},"header":{"message":false}}',
            'dict_upload' => '[{"value":"1","name":"分类1"},{"value":"2","name":"分类2"},{"value":"3","name":"分类3"}]',
            'dict_sex' => '[{"value":"0","name":"女"},{"value":"1","name":"男"}]',
            'dict_status' => '[{"value":"0","name":"正常"},{"value":"1","name":"禁用"}]',
            'dict_dict_name' => '[{"value":"dict_name","name":"字典名称"},{"value":"status","name":"启禁用状态"},{"value":"sex","name":"性别"},{"value":"upload","name":"附件分类"}]',
        ];

        foreach ($options as $name => $value) {
            $s = $pdo->prepare("INSERT INTO {$q('wa_options')} ({$q('name')},{$q('value')},{$q('created_at')},{$q('updated_at')}) VALUES (:n,:v,:c,:u)");
            $s->execute(['n' => $name, 'v' => $value, 'c' => $t, 'u' => $t]);
        }

        $s = $pdo->prepare("INSERT INTO {$q('wa_roles')} ({$q('id')},{$q('name')},{$q('rules')},{$q('created_at')},{$q('updated_at')}) VALUES (1,:n,:r,:c,:u)");
        $s->execute(['n' => '超级管理员', 'r' => '*', 'c' => $t, 'u' => $t]);
    }

    public function step2(Request $request): Response
    {
        $username = $request->post('username');
        $password = $request->post('password');
        $confirm = $request->post('password_confirm');
        if ($password != $confirm) return $this->json(1, '两次密码不一致');
        if (!is_file($f = base_path() . '/plugin/admin/config/database.php')) return $this->json(1, '请先完成第一步数据库配置');

        $cfg = include $f;
        $default = $cfg['default'] ?? 'mysql';
        $conn = $cfg['connections'][$default];
        $driver = $conn['driver'] ?? 'mysql';

        $handler = $this->getHandler($driver, $conn);
        $pdo = $handler->getPdo();
        $q = [$handler, 'quoteIdentifier'];

        $s = $pdo->prepare("select * from {$q('wa_admins')}");
        $s->execute();
        if ($s->fetchAll()) return $this->json(1, '后台已经安装完毕，无法通过此页面创建管理员');

        $t = date('Y-m-d H:i:s');
        $s = $pdo->prepare("INSERT INTO {$q('wa_admins')} ({$q('username')},{$q('password')},{$q('nickname')},{$q('created_at')},{$q('updated_at')}) VALUES (:u,:p,:n,:c,:t)");
        $s->execute(['u' => $username, 'p' => Util::passwordHash($password), 'n' => '超级管理员', 'c' => $t, 't' => $t]);
        $id = (int)$pdo->lastInsertId();

        $s = $pdo->prepare("INSERT INTO {$q('wa_admin_roles')} ({$q('role_id')},{$q('admin_id')}) VALUES (1,:id)");
        $s->execute(['id' => $id]);

        $request->session()->flush();
        return $this->json(0);
    }

    protected function buildConfig(InstallHandler $handler): string
    {
        $c = $handler->getConfig();
        $driver = $c['driver'];
        $host = $c['host'] ?? '';
        $port = (int)($c['port'] ?? 0);
        $database = $c['database'] ?? '';
        $username = $c['username'] ?? '';
        $password = $c['password'] ?? '';
        $config = match ($driver) {
            'mysql' => [
                'default' => 'database',
                'connections' => [
                    'database' => [
                        'driver' => 'mysql', 'host' => $host, 'port' => $port,
                        'database' => $database, 'username' => $username, 'password' => $password,
                        'charset' => 'utf8mb4', 'collation' => 'utf8mb4_general_ci',
                        'prefix' => '', 'strict' => true, 'engine' => null,
                    ],
                ],
            ],
            'pgsql' => [
                'default' => 'database',
                'connections' => [
                    'database' => [
                        'driver' => 'pgsql', 'host' => $host, 'port' => $port,
                        'database' => $database, 'username' => $username, 'password' => $password,
                        'charset' => 'utf8', 'prefix' => '', 'schema' => 'public', 'sslmode' => 'prefer',
                    ],
                ],
            ],
            'sqlite' => [
                'default' => 'database',
                'connections' => [
                    'database' => [
                        'driver' => 'sqlite', 'database' => $database, 'prefix' => '',
                    ],
                ],
            ],
            'sqlsrv' => [
                'default' => 'database',
                'connections' => [
                    'database' => [
                        'driver' => 'sqlsrv', 'host' => $host, 'port' => $port,
                        'database' => $database, 'username' => $username, 'password' => $password,
                        'charset' => 'utf8', 'prefix' => '',
                    ],
                ],
            ],
            default => throw new BusinessException("不支持的数据库类型: $driver"),
        };
        return '<?php return ' . var_export($config, true) . ';' . PHP_EOL;
    }
}

    /**
     * 添加菜单
     * @param array $menu
     * @param \PDO $pdo
     * @param string $driver
     * @return int
     */