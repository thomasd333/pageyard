<?php

namespace Pageyard\Database;

use PDO;
use PDOStatement;
use Pageyard\Config;

defined('PAGEYARD') or exit;

/**
 * Thin PDO wrapper. Table names are written as #__name and get the configured
 * prefix (as in Joomla). Values always go through bound parameters.
 */
final class Db
{
    private static ?Db $instance = null;

    private function __construct(
        private readonly PDO $pdo,
        private readonly Dialect $dialect,
        private readonly string $prefix,
    ) {
    }

    /** The site database from configuration.php, connected on first use. */
    public static function get(): Db
    {
        return self::$instance ??= self::connect(Config::all());
    }

    /**
     * Opens a connection from config-style options (dbtype, db_path, host, port, user,
     * password, db, dbprefix). A relative db_path is resolved against $root.
     */
    public static function connect(array $options, string $root = PY_ROOT): Db
    {
        $options += Config::DEFAULTS;
        $pdoOptions = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        switch ($options['dbtype']) {
            case 'sqlite':
                $path = (string) $options['db_path'];
                if ($path === '') {
                    throw new \InvalidArgumentException('No SQLite file set (db_path).');
                }
                if ($path !== ':memory:' && !preg_match('~^([a-zA-Z]:)?[\\\\/]~', $path)) {
                    $path = $root . '/' . $path;
                }
                // PHP 8.5 deprecates PDO::sqliteCreateFunction in favour of the driver's own class (8.4+).
                $modern = class_exists(\Pdo\Sqlite::class);
                $pdo = $modern ? new \Pdo\Sqlite('sqlite:' . $path, null, null, $pdoOptions) : new PDO('sqlite:' . $path, null, null, $pdoOptions);
                // SQLite's own LOWER() knows only A-Z, which would make searches for "Ł" case-sensitive.
                $lower = static fn (?string $text): ?string => $text === null ? null : mb_strtolower($text);
                $modern ? $pdo->createFunction('LOWER', $lower, 1) : $pdo->sqliteCreateFunction('LOWER', $lower, 1);
                $pdo->exec('PRAGMA foreign_keys = ON');
                $pdo->exec('PRAGMA busy_timeout = 5000');
                if ($path !== ':memory:') {
                    $pdo->exec('PRAGMA journal_mode = WAL');
                }
                $dialect = new SqliteDialect();
                break;

            case 'mysql':
                $dsn = sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $options['host'], (int) $options['port']);
                if ($options['db'] !== '') {
                    $dsn .= ';dbname=' . $options['db'];
                }
                $pdo = new PDO($dsn, (string) $options['user'], (string) $options['password'], $pdoOptions);
                $pdo->exec("SET time_zone = '+00:00', sql_mode = 'STRICT_ALL_TABLES,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO'");
                $dialect = new MysqlDialect();
                break;

            default:
                throw new \InvalidArgumentException("Unknown database type: {$options['dbtype']}");
        }

        return new Db($pdo, $dialect, (string) $options['dbprefix']);
    }

    /** Replaces the shared instance (installer, tests). */
    public static function setInstance(?Db $db): void
    {
        self::$instance = $db;
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    public function dialect(): Dialect
    {
        return $this->dialect;
    }

    public function prefix(): string
    {
        return $this->prefix;
    }

    /** "#__users" -> "py_users". */
    public function table(string $name): string
    {
        return str_starts_with($name, '#__') ? $this->prefix . substr($name, 3) : $name;
    }

    public function qn(string $name): string
    {
        return $this->dialect->quoteName($this->table($name));
    }

    public function query(string $sql, array $params = []): PDOStatement
    {
        $stmt = $this->pdo->prepare(str_replace('#__', $this->prefix, $sql));
        $positional = array_is_list($params);
        foreach ($params as $key => $value) {
            if (is_bool($value)) {
                $value = (int) $value;
            }
            $stmt->bindValue($positional ? $key + 1 : ':' . ltrim((string) $key, ':'), $value, self::pdoType($value));
        }
        $stmt->execute();
        return $stmt;
    }

    public function exec(string $sql): void
    {
        $this->pdo->exec(str_replace('#__', $this->prefix, $sql));
    }

    /** @return list<array<string, mixed>> */
    public function fetchAll(string $sql, array $params = []): array
    {
        return $this->query($sql, $params)->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public function fetchRow(string $sql, array $params = []): ?array
    {
        $row = $this->query($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    /** First column of every row. */
    public function fetchColumn(string $sql, array $params = []): array
    {
        return $this->query($sql, $params)->fetchAll(PDO::FETCH_COLUMN);
    }

    /** First column of the first row, or null. */
    public function fetchValue(string $sql, array $params = []): mixed
    {
        $value = $this->query($sql, $params)->fetchColumn();
        return $value === false ? null : $value;
    }

    /** Inserts a row and returns the new auto-increment id (0 for tables without one). */
    public function insert(string $table, array $data): int
    {
        $cols = implode(', ', array_map($this->dialect->quoteName(...), array_keys($data)));
        $marks = implode(', ', array_fill(0, count($data), '?'));
        $this->query("INSERT INTO {$this->qn($table)} ($cols) VALUES ($marks)", array_values($data));
        return (int) $this->pdo->lastInsertId();
    }

    /** Updates rows matching all $where pairs; returns the affected row count. */
    public function update(string $table, array $data, array $where): int
    {
        $set = implode(', ', array_map(fn ($c) => $this->dialect->quoteName($c) . ' = ?', array_keys($data)));
        [$cond, $params] = $this->where($where);
        return $this->query("UPDATE {$this->qn($table)} SET $set WHERE $cond", [...array_values($data), ...$params])
            ->rowCount();
    }

    public function delete(string $table, array $where): int
    {
        [$cond, $params] = $this->where($where);
        return $this->query("DELETE FROM {$this->qn($table)} WHERE $cond", $params)->rowCount();
    }

    /** Runs $fn in a transaction (nested calls join the outer one) and returns its result. */
    public function transaction(callable $fn): mixed
    {
        if ($this->pdo->inTransaction()) {
            return $fn($this);
        }
        $this->pdo->beginTransaction();
        try {
            $result = $fn($this);
            $this->pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function tableExists(string $table): bool
    {
        return $this->fetchValue($this->dialect->tableExistsSql(), [$this->table($table)]) !== null;
    }

    /** Escapes % and _ for LIKE ... ESCAPE '!' (backslash behaves differently in MySQL and SQLite). */
    public static function escapeLike(string $text): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $text);
    }

    /** Current UTC time in the format used by datetime columns. */
    public static function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }

    /** @return array{string, list<mixed>} */
    private function where(array $where): array
    {
        if ($where === []) {
            throw new \InvalidArgumentException('Refusing to run UPDATE/DELETE without a WHERE condition.');
        }
        $parts = [];
        $params = [];
        foreach ($where as $col => $value) {
            if ($value === null) {
                $parts[] = $this->dialect->quoteName($col) . ' IS NULL';
            } else {
                $parts[] = $this->dialect->quoteName($col) . ' = ?';
                $params[] = $value;
            }
        }
        return [implode(' AND ', $parts), $params];
    }

    private static function pdoType(mixed $value): int
    {
        return match (true) {
            is_int($value)  => PDO::PARAM_INT,
            $value === null => PDO::PARAM_NULL,
            default         => PDO::PARAM_STR,
        };
    }
}
