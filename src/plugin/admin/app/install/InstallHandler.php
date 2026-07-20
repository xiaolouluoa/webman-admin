<?php

namespace plugin\admin\app\install;

use PDO;

/**
 * 数据库安装处理器基类
 */
abstract class InstallHandler
{
    protected string $driver;
    protected array $config = [];

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->driver = $config['driver'] ?? 'mysql';
    }

    abstract public function getPdo(): PDO;
    abstract public function ensureDatabase(): PDO;
    abstract public function getExistingTables(PDO $pdo): array;
    abstract public function dropTable(PDO $pdo, string $table): void;
    abstract public function getCreateTableSql(string $tableName, array $columns, array $keys = []): string;
    abstract public function getInsertSql(string $table, array $data): string;
    abstract public function getTableCommentSql(string $table, string $comment): string;
    abstract public function quoteIdentifier(string $name): string;
    abstract public static function getDefaultPort(): int;

    public function getDriver(): string
    {
        return $this->driver;
    }

    public function getConfig(): array
    {
        return $this->config;
    }

    public function addMenu(PDO $pdo, array $menu): int
    {
        $q = [$this, 'quoteIdentifier'];
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
        foreach ($columns as $k => $c) {
            $columns[$k] = $q($c);
        }
        $sql = "insert into wa_rules (" . implode(',', $columns) . ") values (" . implode(',', $values) . ")";
        $smt = $pdo->prepare($sql);
        foreach ($data as $key => $value) {
            $smt->bindValue($key, $value);
        }
        $smt->execute();
        return (int)$pdo->lastInsertId();
    }

    public function importMenu(PDO $pdo, array $menuTree): void
    {
        $q = [$this, 'quoteIdentifier'];
        if (is_numeric(key($menuTree)) && !isset($menuTree['key'])) {
            foreach ($menuTree as $item) {
                $this->importMenu($pdo, $item);
            }
            return;
        }
        $children = $menuTree['children'] ?? [];
        unset($menuTree['children']);
        $smt = $pdo->prepare("select * from wa_rules where " . $q('key') . "=:key limit 1");
        $smt->execute(['key' => $menuTree['key']]);
        $oldMenu = $smt->fetch();
        if ($oldMenu) {
            $pid = $oldMenu['id'];
            $params = [
                'title' => $menuTree['title'],
                'icon' => $menuTree['icon'] ?? '',
                'key' => $menuTree['key'],
            ];
            $sql = "update wa_rules set title=:title, icon=:icon where " . $q('key') . "=:key";
            $smt = $pdo->prepare($sql);
            $smt->execute($params);
        } else {
            $pid = $this->addMenu($pdo, $menuTree);
        }
        foreach ($children as $menu) {
            $menu['pid'] = $pid;
            $this->importMenu($pdo, $menu);
        }
    }
}
