<?php

namespace Pageyard\Database;

defined('PAGEYARD') or exit;

/** Schema changes used by migrations; table names take the #__ prefix. */
final class Schema
{
    public function __construct(private readonly Db $db)
    {
    }

    /**
     * @param array<string, string> $columns name => column spec (see Dialect)
     * @param array{primary?: list<string>, unique?: list<list<string>>, index?: list<list<string>>} $options
     */
    public function createTable(string $table, array $columns, array $options = []): void
    {
        $name = $this->db->table($table);
        $dialect = $this->db->dialect();
        $this->db->pdo()->exec($dialect->createTable($name, $columns, $options));
        foreach ($options['unique'] ?? [] as $cols) {
            $this->db->pdo()->exec($dialect->createIndex($name, $cols, true));
        }
        foreach ($options['index'] ?? [] as $cols) {
            $this->db->pdo()->exec($dialect->createIndex($name, $cols));
        }
    }

    public function addColumn(string $table, string $column, string $spec): void
    {
        $this->db->pdo()->exec($this->db->dialect()->addColumn($this->db->table($table), $column, $spec));
    }

    /** @param list<string> $columns */
    public function addIndex(string $table, array $columns, bool $unique = false): void
    {
        $this->db->pdo()->exec($this->db->dialect()->createIndex($this->db->table($table), $columns, $unique));
    }

    public function dropTable(string $table): void
    {
        $this->db->pdo()->exec($this->db->dialect()->dropTable($this->db->table($table)));
    }
}
