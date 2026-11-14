<?php

namespace Pageyard\Database;

defined('PAGEYARD') or exit;

/**
 * Database-specific SQL. Columns are described portably as strings:
 *
 *   'id'                      auto-increment integer primary key
 *   'int' | 'bigint' | 'bool' | 'float'
 *   'string(150)'             short text (VARCHAR in MySQL)
 *   'text' | 'mediumtext'     long text (mediumtext = up to 16 MB in MySQL)
 *   'datetime'                stored as 'Y-m-d H:i:s' in UTC
 *
 * followed by optional flags: "null" (nullable; columns are NOT NULL otherwise),
 * "default <value>" (a number or a 'quoted string'), "primary".
 * MySQL doesn't allow defaults on text/mediumtext, so don't give them one.
 */
abstract class Dialect
{
    abstract public function name(): string;

    abstract public function quoteName(string $name): string;

    /** SQL type for a parsed column; $id columns include the primary key clause. */
    abstract protected function type(string $type, ?int $length): string;

    /** Text appended after CREATE TABLE (...). */
    abstract protected function tableSuffix(): string;

    /** Query returning a row when table ? exists. */
    abstract public function tableExistsSql(): string;

    /**
     * @param array<string, string> $columns name => spec
     * @param array{primary?: list<string>} $options
     */
    public function createTable(string $table, array $columns, array $options = []): string
    {
        $lines = [];
        foreach ($columns as $name => $spec) {
            $lines[] = '  ' . $this->column($name, $spec);
        }
        if (!empty($options['primary'])) {
            $lines[] = '  PRIMARY KEY (' . implode(', ', array_map($this->quoteName(...), $options['primary'])) . ')';
        }
        return 'CREATE TABLE ' . $this->quoteName($table) . " (\n" . implode(",\n", $lines) . "\n)" . $this->tableSuffix();
    }

    /** @param list<string> $columns */
    public function createIndex(string $table, array $columns, bool $unique = false): string
    {
        $name = $table . '_' . implode('_', $columns) . ($unique ? '_uq' : '_idx');
        return 'CREATE ' . ($unique ? 'UNIQUE ' : '') . 'INDEX ' . $this->quoteName($name)
            . ' ON ' . $this->quoteName($table)
            . ' (' . implode(', ', array_map($this->quoteName(...), $columns)) . ')';
    }

    public function addColumn(string $table, string $name, string $spec): string
    {
        return 'ALTER TABLE ' . $this->quoteName($table) . ' ADD COLUMN ' . $this->column($name, $spec);
    }

    public function dropTable(string $table): string
    {
        return 'DROP TABLE IF EXISTS ' . $this->quoteName($table);
    }

    public function column(string $name, string $spec): string
    {
        $c = self::parse($spec);
        $sql = $this->quoteName($name) . ' ' . $this->type($c['type'], $c['length']);
        if ($c['type'] === 'id') {
            return $sql;
        }
        $sql .= $c['null'] ? ' NULL' : ' NOT NULL';
        if ($c['default'] !== null) {
            $sql .= ' DEFAULT ' . $c['default'];
        }
        if ($c['primary']) {
            $sql .= ' PRIMARY KEY';
        }
        return $sql;
    }

    /** @return array{type: string, length: ?int, null: bool, default: ?string, primary: bool} */
    public static function parse(string $spec): array
    {
        if (!preg_match('/^(id|int|bigint|bool|float|string|text|mediumtext|datetime)(?:\((\d+)\))?(.*)$/', trim($spec), $m)) {
            throw new \InvalidArgumentException("Unknown column type: $spec");
        }
        $rest = $m[3];
        $default = null;
        if (preg_match("/\\bdefault\\s+('(?:[^']|'')*'|-?\\d+(?:\\.\\d+)?)/", $rest, $d)) {
            $default = $d[1];
            $rest = str_replace($d[0], '', $rest);
        }
        $flags = preg_split('/\s+/', trim($rest), -1, PREG_SPLIT_NO_EMPTY);
        foreach ($flags as $flag) {
            if (!in_array($flag, ['null', 'primary'], true)) {
                throw new \InvalidArgumentException("Unknown column flag '$flag' in: $spec");
            }
        }
        if ($m[1] === 'string' && $m[2] === '') {
            throw new \InvalidArgumentException("string needs a length, e.g. string(255): $spec");
        }
        return [
            'type'    => $m[1],
            'length'  => $m[2] !== '' ? (int) $m[2] : null,
            'null'    => in_array('null', $flags, true),
            'default' => $default,
            'primary' => in_array('primary', $flags, true),
        ];
    }
}
