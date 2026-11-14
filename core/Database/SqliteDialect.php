<?php

namespace Pageyard\Database;

defined('PAGEYARD') or exit;

final class SqliteDialect extends Dialect
{
    public function name(): string
    {
        return 'sqlite';
    }

    public function quoteName(string $name): string
    {
        return '"' . str_replace('"', '""', $name) . '"';
    }

    protected function type(string $type, ?int $length): string
    {
        return match ($type) {
            'id'                         => 'INTEGER PRIMARY KEY AUTOINCREMENT',
            'int', 'bigint', 'bool'      => 'INTEGER',
            'float'                      => 'REAL',
            'string', 'text', 'mediumtext', 'datetime' => 'TEXT',
        };
    }

    protected function tableSuffix(): string
    {
        return '';
    }

    public function tableExistsSql(): string
    {
        return "SELECT name FROM sqlite_master WHERE type = 'table' AND name = ?";
    }
}
