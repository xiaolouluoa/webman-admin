<?php

namespace plugin\admin\app\model;

use DateTimeInterface;
use plugin\admin\app\common\Database;
use support\Model;


class Base extends Model
{
    /**
     * 获取数据库连接名
     * 根据配置自动切换 MySQL/SQLite/PostgreSQL
     *
     * @return string
     */
    public function getConnectionName(): string
    {
        return Database::getConnectionName();
    }

    /**
     * 格式化日期
     *
     * @param DateTimeInterface $date
     * @return string
     */
    protected function serializeDate(DateTimeInterface $date)
    {
        return $date->format('Y-m-d H:i:s');
    }
}
