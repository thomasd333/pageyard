<?php

namespace Pageyard;

use Pageyard\Database\Db;
use Pageyard\Database\Migrator;

defined('PAGEYARD') or exit;

/** First-run setup: checks the server, creates the database and writes configuration.php. */
final class Installer
{
    public const FIELDS = [
        'sitename' => '', 'language' => 'en', 'languages' => ['en'], 'timezone' => 'UTC',
        'dbtype' => 'sqlite', 'host' => 'localhost', 'port' => '3306', 'user' => 'root', 'password' => '',
        'db' => 'pageyard', 'dbprefix' => 'py_',
        'admin_name' => '', 'admin_username' => 'admin', 'admin_email' => '', 'admin_password' => '',
        'admin_password2' => '',
    ];

    public function __construct(private readonly string $root = PY_ROOT)
    {
    }

    /** @return list<array{label: string, ok: bool, note: string}> */
    public function requirements(): array
    {
        $writable = fn (string $dir) => is_dir($dir) && is_writable($dir);
        return [
            ['label' => t('INSTALL_REQ_PHP', PY_MIN_PHP), 'ok' => version_compare(PHP_VERSION, PY_MIN_PHP, '>='), 'note' => PHP_VERSION],
            ['label' => 'PDO SQLite / PDO MySQL', 'ok' => extension_loaded('pdo_sqlite') || extension_loaded('pdo_mysql'),
                'note' => implode(', ', array_filter([extension_loaded('pdo_sqlite') ? 'SQLite' : '', extension_loaded('pdo_mysql') ? 'MySQL' : '']))],
            ['label' => 'mbstring', 'ok' => extension_loaded('mbstring'), 'note' => ''],
            ['label' => 'fileinfo', 'ok' => extension_loaded('fileinfo'), 'note' => t('INSTALL_REQ_FILEINFO')],
            ['label' => t('INSTALL_REQ_ROOT_WRITABLE'), 'ok' => $writable($this->root), 'note' => t('INSTALL_REQ_WRITABLE_ROOT')],
            ['label' => t('INSTALL_REQ_WRITABLE', 'data/'), 'ok' => $writable($this->root . '/data'), 'note' => ''],
            ['label' => t('INSTALL_REQ_WRITABLE', 'images/'), 'ok' => $writable($this->root . '/images'), 'note' => ''],
        ];
    }

    public function requirementsMet(): bool
    {
        return !in_array(false, array_column($this->requirements(), 'ok'), true);
    }

    /** @return array<string, string> field => error message */
    public function validate(array $in): array
    {
        $err = [];
        if (trim($in['sitename']) === '') {
            $err['sitename'] = t('INSTALL_ERR_REQUIRED');
        }
        if (!Lang::exists($in['language'])) {
            $err['language'] = t('INSTALL_ERR_LANGUAGE');
        }
        if (!in_array($in['timezone'], \DateTimeZone::listIdentifiers(), true)) {
            $err['timezone'] = t('INSTALL_ERR_TIMEZONE');
        }
        if (!in_array($in['dbtype'], ['sqlite', 'mysql'], true)) {
            $err['dbtype'] = t('INSTALL_ERR_REQUIRED');
        } elseif ($in['dbtype'] === 'mysql') {
            foreach (['host', 'user', 'db'] as $f) {
                if (trim($in[$f]) === '') {
                    $err[$f] = t('INSTALL_ERR_REQUIRED');
                }
            }
            if (!ctype_digit($in['port']) || (int) $in['port'] < 1 || (int) $in['port'] > 65535) {
                $err['port'] = t('INSTALL_ERR_PORT');
            }
            if (!preg_match('/^[A-Za-z0-9_$]{1,64}$/', $in['db'])) {
                $err['db'] = t('INSTALL_ERR_DBNAME');
            }
        }
        if (!preg_match('/^[a-z][a-z0-9]{0,14}_$/', $in['dbprefix'])) {
            $err['dbprefix'] = t('INSTALL_ERR_PREFIX');
        }
        if (trim($in['admin_name']) === '') {
            $err['admin_name'] = t('INSTALL_ERR_REQUIRED');
        }
        if (!preg_match('/^[A-Za-z0-9._-]{2,150}$/', $in['admin_username'])) {
            $err['admin_username'] = t('INSTALL_ERR_USERNAME');
        }
        if (!filter_var($in['admin_email'], FILTER_VALIDATE_EMAIL)) {
            $err['admin_email'] = t('INSTALL_ERR_EMAIL');
        }
        if (mb_strlen($in['admin_password']) < 8) {
            $err['admin_password'] = t('INSTALL_ERR_PASSWORD');
        } elseif ($in['admin_password'] !== $in['admin_password2']) {
            $err['admin_password2'] = t('INSTALL_ERR_PASSWORD_MATCH');
        }
        return $err;
    }

    /**
     * Creates the database, the first user and configuration.php.
     * Throws \RuntimeException with a user-facing message on failure.
     */
    public function install(array $in): void
    {
        if (is_file($this->root . '/configuration.php')) {
            throw new \RuntimeException(t('INSTALL_ERR_INSTALLED'));
        }

        $languages = array_values(array_filter((array) $in['languages'], [Lang::class, 'exists']));
        $config = [
            'sitename'       => trim($in['sitename']),
            'language'       => $in['language'],
            'languages'      => implode(',', array_unique([$in['language'], ...$languages])),
            'admin_language' => Lang::current(),
            'timezone'       => $in['timezone'],
            'dbtype'         => $in['dbtype'],
            'dbprefix'       => $in['dbprefix'],
            'secret'         => bin2hex(random_bytes(16)),
        ];

        if ($in['dbtype'] === 'sqlite') {
            // A random file name, so the database can't be downloaded even where
            // data/.htaccess is ignored (nginx, PHP's built-in server).
            $config['db_path'] = 'data/pageyard-' . bin2hex(random_bytes(6)) . '.sqlite';
        } else {
            $config += [
                'host' => trim($in['host']), 'port' => (int) $in['port'], 'user' => trim($in['user']),
                'password' => $in['password'], 'db' => trim($in['db']),
            ];
        }

        $db = $this->connect($config);
        if ($db->tableExists('#__users')) {
            throw new \RuntimeException(t('INSTALL_ERR_TABLES_EXIST', $config['dbprefix']));
        }

        try {
            (new Migrator($db))->migrate();
            $now = Db::now();
            $db->insert('#__users', [
                'name'     => trim($in['admin_name']),
                'username' => strtolower($in['admin_username']),
                'email'    => strtolower(trim($in['admin_email'])),
                'password' => password_hash($in['admin_password'], PASSWORD_DEFAULT),
                'active'   => 1,
                'admin'    => 1,
                'created'  => $now,
                'modified' => $now,
            ]);
            Config::write($config, $this->root . '/configuration.php');
        } catch (\Throwable $e) {
            if ($config['dbtype'] === 'sqlite') {
                unset($db);
                foreach (['', '-wal', '-shm'] as $suffix) {
                    @unlink($this->root . '/' . $config['db_path'] . $suffix);
                }
            }
            throw $e;
        }
        date_default_timezone_set($config['timezone']);
        Log::info("Installed ($config[dbtype]).");
    }

    /** Connects, creating the MySQL database when it doesn't exist yet. */
    private function connect(array $config): Db
    {
        try {
            return Db::connect($config, $this->root);
        } catch (\PDOException $e) {
            if ($config['dbtype'] === 'mysql' && str_contains($e->getMessage(), '[1049]')) {
                try {
                    $server = Db::connect(['db' => ''] + $config, $this->root);
                    $server->exec('CREATE DATABASE ' . $server->qn($config['db'])
                        . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
                    return Db::connect($config, $this->root);
                } catch (\PDOException $e2) {
                    throw new \RuntimeException(t('INSTALL_ERR_CREATE_DB', $e2->getMessage()), 0, $e2);
                }
            }
            throw new \RuntimeException(t('INSTALL_ERR_CONNECT', $e->getMessage()), 0, $e);
        }
    }
}
