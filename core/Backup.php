<?php

namespace Pageyard;

use Pageyard\Database\Db;
use Pageyard\Database\Migrator;
use PDO;

defined('PAGEYARD') or exit;

/**
 * Backups of the SQLite database in data/backups/ as <kind>-YYYYmmdd-HHMMSS.sqlite.gz.
 *
 * - The copy is made with VACUUM INTO: a consistent snapshot even while the site is in use (WAL mode),
 *   compacted, then checked with PRAGMA integrity_check before it is kept, then gzip-compressed.
 * - Kinds: "auto" (made when an administrator uses the back end and the last one is older than
 *   backup_interval hours, only if the database changed since), "manual" (button), "update" (before
 *   new migrations change the database), "restore" (the state before a restore). Only the newest
 *   backup_keep automatic ones are kept; the others stay until deleted.
 * - restore() replaces the database with a backup, see there.
 * - The outcome of the last run is kept in data/backups/status.json (not in the database: writing it
 *   would count as a change, and it must survive a broken database).
 * MySQL databases are not handled here (use the host's backups / mysqldump).
 */
final class Backup
{
    public const KINDS = ['auto', 'manual', 'update', 'restore'];
    private const NAME = '~^(auto|manual|update|restore)-(\d{8}-\d{6})\.sqlite\.gz$~';

    /** Overridable for tests; default PY_ROOT/data/backups. */
    public static ?string $dir = null;

    public static function dir(): string
    {
        return self::$dir ?? PY_ROOT . '/data/backups';
    }

    public static function supported(): bool
    {
        return Config::get('dbtype') === 'sqlite' && Config::get('db_path') !== ':memory:';
    }

    /** Absolute path of the live database file. */
    public static function databaseFile(): string
    {
        $path = (string) Config::get('db_path');
        return preg_match('~^([a-zA-Z]:)?[\\\\/]~', $path) ? $path : PY_ROOT . '/' . $path;
    }

    /**
     * Makes a backup. Returns the file name, or null if another backup is running right now.
     * @throws \RuntimeException when the copy fails or the copy is damaged (it is not kept then)
     */
    public static function create(string $kind = 'manual'): ?string
    {
        if (!self::supported()) {
            throw new \RuntimeException('Backups are available for SQLite databases only.');
        }
        $kind = in_array($kind, self::KINDS, true) ? $kind : 'manual';
        $dir = self::dir();
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException("Cannot create $dir.");
        }
        $lock = fopen("$dir/.lock", 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            return null;
        }
        $stamp = gmdate('Ymd-His');
        $name = "$kind-$stamp.sqlite.gz";
        for ($n = 1; is_file("$dir/$name"); $n++) {  // two backups in the same second
            $stamp = gmdate('Ymd-His', time() + $n);
            $name = "$kind-$stamp.sqlite.gz";
        }
        $tmp = "$dir/.tmp-" . bin2hex(random_bytes(4)) . '.sqlite';
        try {
            $pdo = Db::get()->pdo();
            $pdo->exec('VACUUM INTO ' . $pdo->quote($tmp));
            $problem = self::check($tmp);
            if ($problem !== null) {
                throw new \RuntimeException("The database copy failed the integrity check: $problem");
            }
            self::gzip($tmp, "$dir/$name");
            self::setStatus(true, $name);
            Log::info("Backup created: $name");
            if ($kind === 'auto') {
                self::prune((int) Config::get('backup_keep', 10));
            }
            return $name;
        } catch (\Throwable $e) {
            self::setStatus(false, $e->getMessage());
            Log::error('Backup failed: ' . $e->getMessage());
            throw $e instanceof \RuntimeException ? $e : new \RuntimeException($e->getMessage(), 0, $e);
        } finally {
            if (is_file($tmp)) {
                @unlink($tmp);
            }
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * Replaces the database with a backup. Nothing is touched until the backup has been unpacked and
     * has passed the integrity check and the checks of restorable().
     *
     * The current state is saved first as a "restore" backup. Normally the contents are then replaced in one
     * transaction, so a failure changes nothing and other requests see the old or the new state,
     * never a mix; replacing the file wouldn't work on Windows while anyone has it open.
     * A damaged database can't be copied or replaced that way: its file is then set aside
     * as <file>.damaged-<time> and the backup takes its place.
     *
     * @return array{safety: ?string, damaged: ?string} the backup of the previous state; the file set aside
     * @throws \RuntimeException with a message for the user; the database stays as it was
     */
    public static function restore(string $name): array
    {
        if (!self::supported()) {
            throw new \RuntimeException('Backups are available for SQLite databases only.');
        }
        $path = self::path($name);
        if ($path === null) {
            throw new \RuntimeException(t('ERR_NOT_FOUND'));
        }
        $tmp = self::dir() . '/.tmp-restore-' . bin2hex(random_bytes(4)) . '.sqlite';
        $lock = null;
        try {
            self::gunzip($path, $tmp);
            self::restorable($tmp);

            $safety = null;
            $healthy = true;
            try {
                $safety = self::create('restore');
                if ($safety === null) {
                    throw new \RuntimeException(t('BACKUPS_BUSY'));
                }
            } catch (\RuntimeException $e) {
                if ($e->getMessage() === t('BACKUPS_BUSY')) {
                    throw $e;
                }
                $healthy = false;  // can't be read properly: its file is kept below instead
            } catch (\Throwable) {
                $healthy = false;
            }

            $lock = fopen(self::dir() . '/.lock', 'c');
            if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
                throw new \RuntimeException(t('BACKUPS_BUSY'));
            }
            $damaged = null;
            if ($healthy) {
                try {
                    self::replaceContents($tmp);
                } catch (\Throwable $e) {
                    Log::error('Restore in place failed: ' . $e->getMessage());
                    $healthy = false;
                }
            }
            if (!$healthy) {
                $damaged = self::replaceFile($tmp);
            }
            Log::info("Backup restored: $name" . ($safety !== null ? ", previous state in $safety" : '') . ($damaged !== null ? ", previous file kept as $damaged" : ''));
            self::setStatus(true, $name);
            return ['safety' => $safety, 'damaged' => $damaged];
        } finally {
            if (is_file($tmp)) {
                @unlink($tmp);
            }
            if (is_resource($lock)) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }

    /**
     * A backup must be sound, belong to this site (same table prefix, a user who can log in) and not come
     * from a newer version, whose tables this code wouldn't know. An older one is fine:
     * its migrations run on the next request.
     * @throws \RuntimeException
     */
    private static function restorable(string $file): void
    {
        $problem = self::check($file);
        if ($problem !== null) {
            throw new \RuntimeException(t('BACKUPS_ERR_DAMAGED', $problem));
        }
        $pdo = new PDO('sqlite:' . $file, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        try {
            $prefix = (string) Config::get('dbprefix', 'py_');
            $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array($prefix . 'users', $tables, true) || !in_array($prefix . 'migrations', $tables, true)) {
                throw new \RuntimeException(t('BACKUPS_ERR_FOREIGN'));
            }
            // Older backups have no administrator flag: every user was one then.
            $columns = $pdo->query("PRAGMA table_info(\"{$prefix}users\")")->fetchAll(PDO::FETCH_COLUMN, 1);
            $admins = in_array('admin', $columns, true) ? " AND admin = 1 AND pending = ''" : '';
            if (!(int) $pdo->query("SELECT COUNT(*) FROM \"{$prefix}users\" WHERE active = 1$admins")->fetchColumn()) {
                throw new \RuntimeException(t('BACKUPS_ERR_NO_USER'));
            }
            $applied = $pdo->query("SELECT name FROM \"{$prefix}migrations\"")->fetchAll(PDO::FETCH_COLUMN);
            if (array_diff($applied, Migrator::files())) {
                throw new \RuntimeException(t('BACKUPS_ERR_NEWER'));
            }
        } finally {
            $pdo = null;
        }
    }

    /** Swaps all tables for those of $file in one transaction. */
    private static function replaceContents(string $file): void
    {
        $pdo = Db::get()->pdo();
        $pdo->exec('ATTACH DATABASE ' . $pdo->quote($file) . ' AS restored');
        try {
            $pdo->exec('BEGIN IMMEDIATE');
            try {
                $column = static fn (string $sql): array => $pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN);
                $quote = static fn (string $name): string => '"' . str_replace('"', '""', $name) . '"';
                foreach ($column("SELECT name FROM main.sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite\\_%' ESCAPE '\\'") as $table) {
                    $pdo->exec('DROP TABLE main.' . $quote($table));
                }
                $tables = $column("SELECT name FROM restored.sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite\\_%' ESCAPE '\\'");
                foreach ($column("SELECT sql FROM restored.sqlite_master WHERE type = 'table' AND sql IS NOT NULL AND name NOT LIKE 'sqlite\\_%' ESCAPE '\\'") as $sql) {
                    $pdo->exec($sql);
                }
                foreach ($tables as $table) {
                    $pdo->exec('INSERT INTO main.' . $quote($table) . ' SELECT * FROM restored.' . $quote($table));
                }
                // Indexes after the data; other objects (none so far) likewise.
                foreach ($column("SELECT sql FROM restored.sqlite_master WHERE type <> 'table' AND sql IS NOT NULL AND name NOT LIKE 'sqlite\\_%' ESCAPE '\\'") as $sql) {
                    $pdo->exec($sql);
                }
                // AUTOINCREMENT counters: an id must not come back for something else (deleted items return under it).
                if ($column("SELECT name FROM restored.sqlite_master WHERE name = 'sqlite_sequence'")) {
                    $pdo->exec('DELETE FROM main.sqlite_sequence');
                    $pdo->exec('INSERT INTO main.sqlite_sequence SELECT * FROM restored.sqlite_sequence');
                }
                $pdo->exec('COMMIT');
            } catch (\Throwable $e) {
                $pdo->exec('ROLLBACK');
                throw $e;
            }
        } finally {
            $pdo->exec('DETACH DATABASE restored');
        }
        Settings::reset();
    }

    /**
     * Sets the database file aside and moves $file into its place.
     * @return string name of the file set aside (in the database's folder), or null if there was none
     * @throws \RuntimeException when the file is in use; everything is left as it was
     */
    private static function replaceFile(string $file): ?string
    {
        Db::setInstance(null);
        gc_collect_cycles();
        $live = self::databaseFile();
        $suffix = '.damaged-' . gmdate('Ymd-His');
        $existed = is_file($live);
        if ($existed && !@rename($live, $live . $suffix)) {
            throw new \RuntimeException(t('BACKUPS_ERR_IN_USE'));
        }
        if (!@rename($file, $live)) {
            if ($existed) {
                @rename($live . $suffix, $live);
            }
            throw new \RuntimeException(t('BACKUPS_ERR_IN_USE'));
        }
        foreach (['-wal', '-shm'] as $part) {
            if (is_file($live . $part)) {
                @rename($live . $part, $live . $suffix . $part);
            }
        }
        Settings::reset();
        return $existed ? basename($live . $suffix) : null;
    }

    /**
     * Automatic backup when due: interval set, last automatic backup older than it, and the database
     * changed since (file times of the database and its WAL). Never throws (logged instead).
     */
    public static function runIfDue(): ?string
    {
        $hours = (int) Config::get('backup_interval', 24);
        if ($hours <= 0 || !self::supported()) {
            return null;
        }
        $last = null;
        foreach (self::list() as $b) {
            if ($b['kind'] === 'auto') {
                $last = $b['time'];
                break;
            }
        }
        if ($last !== null) {
            $file = self::databaseFile();
            clearstatcache();
            $changed = max((int) @filemtime($file), (int) @filemtime("$file-wal"));
            if (time() - $last < $hours * 3600 || $changed <= $last) {
                return null;
            }
        }
        try {
            return self::create('auto');
        } catch (\Throwable) {
            return null;  // logged and stored in backup_status by create()
        }
    }

    /**
     * Backups, newest first.
     * @return list<array{name: string, kind: string, time: int, size: int}>
     */
    public static function list(): array
    {
        $out = [];
        foreach (glob(self::dir() . '/*.sqlite.gz') ?: [] as $path) {
            if (preg_match(self::NAME, basename($path), $m)) {
                $time = \DateTimeImmutable::createFromFormat('Ymd-His', $m[2], new \DateTimeZone('UTC'));
                $out[] = ['name' => basename($path), 'kind' => $m[1], 'time' => $time ? $time->getTimestamp() : 0, 'size' => (int) filesize($path)];
            }
        }
        usort($out, fn ($a, $b) => [$b['time'], $b['name']] <=> [$a['time'], $a['name']]);
        return $out;
    }

    /** Full path of an existing backup by its file name, or null (also for names that aren't backups). */
    public static function path(string $name): ?string
    {
        if (!preg_match(self::NAME, $name)) {
            return null;
        }
        $path = self::dir() . '/' . $name;
        return is_file($path) ? $path : null;
    }

    /** @param list<string> $names */
    public static function delete(array $names): int
    {
        $count = 0;
        foreach ($names as $name) {
            $path = self::path($name);
            if ($path !== null && unlink($path)) {
                $count++;
                Log::info("Backup deleted: $name");
            }
        }
        return $count;
    }

    /** Deletes automatic backups beyond the newest $keep. */
    public static function prune(int $keep): int
    {
        $auto = array_values(array_filter(self::list(), fn ($b) => $b['kind'] === 'auto'));
        return self::delete(array_column(array_slice($auto, max(1, $keep)), 'name'));
    }

    /** Integrity check of the live database: null when fine, else the first problems reported. */
    public static function checkLive(): ?string
    {
        $rows = Db::get()->pdo()->query('PRAGMA integrity_check')->fetchAll(PDO::FETCH_COLUMN);
        return $rows === ['ok'] ? null : implode('; ', array_slice($rows, 0, 5));
    }

    /** @return array{ok: bool, time: int, message: string}|null outcome of the last backup */
    public static function status(): ?array
    {
        $file = self::dir() . '/status.json';
        $status = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        return is_array($status) && isset($status['ok'], $status['time']) ? $status : null;
    }

    private static function setStatus(bool $ok, string $message): void
    {
        @file_put_contents(self::dir() . '/status.json', json_encode(['ok' => $ok, 'time' => time(), 'message' => $message]), LOCK_EX);
    }

    /** Opens a database file on its own and runs PRAGMA integrity_check; null when "ok". */
    private static function check(string $file): ?string
    {
        $pdo = new PDO('sqlite:' . $file, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $rows = $pdo->query('PRAGMA integrity_check')->fetchAll(PDO::FETCH_COLUMN);
        $pdo = null;
        return $rows === ['ok'] ? null : implode('; ', array_slice($rows, 0, 5));
    }

    private static function gunzip(string $source, string $target): void
    {
        $in = @gzopen($source, 'rb');
        $out = fopen($target, 'wb');
        if ($in === false || $out === false) {
            throw new \RuntimeException("Cannot read $source.");
        }
        try {
            while (!gzeof($in)) {
                $chunk = gzread($in, 1 << 20);
                if ($chunk === false) {
                    throw new \RuntimeException(t('BACKUPS_ERR_DAMAGED', 'gzip'));
                }
                fwrite($out, $chunk);
            }
        } finally {
            gzclose($in);
            fclose($out);
        }
    }

    private static function gzip(string $source, string $target): void
    {
        $part = "$target.part";
        $in = fopen($source, 'rb');
        $out = gzopen($part, 'wb6');
        if ($in === false || $out === false) {
            throw new \RuntimeException("Cannot write $target.");
        }
        while (!feof($in)) {
            gzwrite($out, (string) fread($in, 1 << 20));
        }
        fclose($in);
        gzclose($out);
        if (!rename($part, $target)) {
            @unlink($part);
            throw new \RuntimeException("Cannot write $target.");
        }
    }
}
