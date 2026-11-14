<?php

namespace Pageyard\Admin\Controllers;

use Pageyard\Admin\Controller;
use Pageyard\Backup;
use Pageyard\Config;
use Pageyard\Database\Db;

defined('PAGEYARD') or exit;

final class DashboardController extends Controller
{
    public function display(): void
    {
        $this->title = t('DASHBOARD');
        $this->icon = 'bi-house-door';
        $this->active = 'dashboard';

        $db = Db::get();
        $this->render('dashboard', [
            'users'      => (int) $db->fetchValue('SELECT COUNT(*) FROM #__users'),
            'waiting'    => (int) $db->fetchValue("SELECT COUNT(*) FROM #__users WHERE pending = 'admin'"),
            'pages'      => (int) $db->fetchValue('SELECT COUNT(*) FROM #__pages'),
            'categories' => (int) $db->fetchValue('SELECT COUNT(*) FROM #__categories'),
            'recent' => $db->fetchAll('SELECT id, name, username, last_login FROM #__users WHERE last_login IS NOT NULL ORDER BY last_login DESC LIMIT 5'),
            'system' => [
                'Pageyard'          => PY_VERSION,
                'PHP'               => PHP_VERSION,
                t('SYSINFO_DB')     => $db->dialect()->name() === 'sqlite'
                    ? 'SQLite ' . $db->pdo()->getAttribute(\PDO::ATTR_SERVER_VERSION)
                    : 'MySQL ' . $db->pdo()->getAttribute(\PDO::ATTR_SERVER_VERSION),
                t('SYSINFO_SERVER') => (string) ($_SERVER['SERVER_SOFTWARE'] ?? PHP_SAPI),
                t('CONFIG_TIMEZONE') => (string) Config::get('timezone'),
            ],
            'backup'    => Backup::supported() ? (Backup::list()[0] ?? false) : null,
            'backupStatus' => Backup::status(),
        ]);
    }
}
