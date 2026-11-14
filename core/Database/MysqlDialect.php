<?php

namespace Pageyard\Database;

defined('PAGEYARD') or exit;

final class MysqlDialect extends Dialect
{
    public function name(): string
    {
        return 'mysql';
    }

    public function quoteName(string $name): string
    {
        return '`' . str_replace('`', '``', $name) . '`';
    }

    protected function type(string $type, ?int $length): string
    {
        return match ($type) {
            'id'         => 'INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY',
            'int'        => 'INT',
            'bigint'     => 'BIGINT',
            'bool'       => 'TINYINT(1)',
            'float'      => 'DOUBLE',
            'string'     => "VARCHAR($length)",
            'text'       => 'TEXT',
            'mediumtext' => 'MEDIUMTEXT',
            'datetime'   => 'DATETIME',
        };
    }

    protected function tableSuffix(): string
    {
        return ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    }

    public function tableExistsSql(): string
    {
        return 'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?';
    }
}
