<?php
/** Shared <head> content of administrator pages. Expects $pageTitle. */

use Pageyard\Config;
use Pageyard\Uri;

defined('PAGEYARD') or exit;
?>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= e($pageTitle) ?> - <?= e(Config::get('sitename')) ?> - <?= e(t('ADMIN_TITLE')) ?></title>
    <script>
        // Before first paint: colour theme (saved choice, else the system's) and the collapsed sidebar.
        (function () {
            var d = document.documentElement, theme = null, closed = null;
            try { theme = localStorage.getItem('py-theme'); closed = localStorage.getItem('py-sidebar'); } catch (e) {}
            if (theme !== 'light' && theme !== 'dark') {
                theme = matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
            }
            d.setAttribute('data-bs-theme', theme);
            if (closed === 'closed') d.classList.add('py-sidebar-closed');
        })();
    </script>
    <link rel="stylesheet" href="<?= e(Uri::media('vendor/bootstrap/css/bootstrap.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(Uri::media('vendor/bootstrap-icons/bootstrap-icons.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(Uri::media('admin/admin.css')) ?>?v=<?= e(PY_VERSION) ?>">
