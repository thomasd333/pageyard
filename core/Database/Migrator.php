<?php

namespace Pageyard\Database;

defined('PAGEYARD') or exit;

/**
 * Applies core/Database/migrations/NNNN_name.php in order. Each file returns
 * function (Schema $schema, Db $db): void. Applied names are kept in #__migrations.
 * Never edit a migration that has shipped; add a new one instead.
 */
final class Migrator
{
    public function __construct(
        private readonly Db $db,
        private readonly string $dir = __DIR__ . '/migrations',
    ) {
    }

    /** @return list<string> names of all migration files, sorted */
    public function all(): array
    {
        return self::files($this->dir);
    }

    /** @return list<string> */
    public static function files(string $dir = __DIR__ . '/migrations'): array
    {
        $names = array_map(fn ($f) => basename($f, '.php'), glob($dir . '/*.php') ?: []);
        sort($names, SORT_STRING);
        return $names;
    }

    /** @return list<string> */
    public function pending(): array
    {
        $applied = $this->db->tableExists('#__migrations')
            ? $this->db->fetchColumn('SELECT name FROM #__migrations')
            : [];
        return array_values(array_diff($this->all(), $applied));
    }

    /** Runs pending migrations and returns their names. */
    public function migrate(): array
    {
        if (!$this->db->tableExists('#__migrations')) {
            (new Schema($this->db))->createTable('#__migrations', [
                'name'    => 'string(190) primary',
                'applied' => 'datetime',
            ]);
        }

        $done = [];
        foreach ($this->pending() as $name) {
            $migration = require $this->dir . "/$name.php";
            $run = function (Db $db) use ($migration, $name) {
                $migration(new Schema($db), $db);
                $db->insert('#__migrations', ['name' => $name, 'applied' => Db::now()]);
            };
            // MySQL commits DDL implicitly (PDO's commit() would then fail), so only SQLite gets a transaction.
            $this->db->dialect() instanceof SqliteDialect ? $this->db->transaction($run) : $run($this->db);
            $done[] = $name;
        }
        return $done;
    }
}
