<?php
/**
 * Administrator page frame (Atum-like): dark header, sidebar, toolbar strip, content.
 * @var Pageyard\Admin\Controller $controller
 * @var string $content
 * @var list<array{type: string, text: string}> $messages
 */

use Pageyard\Admin\Form;
use Pageyard\Auth;
use Pageyard\Config;
use Pageyard\Lang;
use Pageyard\Uri;

defined('PAGEYARD') or exit;

$user = Auth::user();
$pageTitle = $controller->title;

// Sidebar: groups of [key, view, icon, label]. Only working sections are listed.
$menu = [
    ['', [['dashboard', '', 'bi-house-door', t('DASHBOARD')]]],
    [t('MENU_CONTENT'), [
        ['pages', 'view=pages', 'bi-file-earmark-text', t('PAGES_TITLE')],
        ['categories', 'view=categories', 'bi-folder', t('CATEGORIES_TITLE')],
        ['media', 'view=media', 'bi-images', t('MEDIA_TITLE')],
        ['deleted', 'view=deleted', 'bi-trash3', t('DELETED_TITLE')],
    ]],
    [t('MENU_MENUS'), [
        ['menus', 'view=menus', 'bi-menu-button-wide', t('MENUS_TITLE')],
        ['blocks', 'view=blocks', 'bi-grid-1x2', t('BLOCKS_TITLE')],
    ]],
    [t('MENU_USERS'), [['users', 'view=users', 'bi-people', t('USERS_TITLE')]]],
    [t('MENU_SYSTEM'), [
        ['config', 'view=config', 'bi-gear', t('CONFIG_TITLE')],
        ['template', 'view=template', 'bi-palette', t('TEMPLATE_TITLE')],
        ['redirects', 'view=redirects', 'bi-signpost-split', t('REDIRECTS_TITLE')],
        ['backups', 'view=backups', 'bi-database-check', t('BACKUPS_TITLE')],
    ]],
];

$groups = [];
foreach ($controller->toolbar as $b) {
    $groups[$b['group']][] = $b;
}
$button = static function (array $b, bool $inDropdown = false): string {
    $class = $inDropdown ? 'dropdown-item ' . ($b['class'] === 'text-danger' ? 'text-danger' : '') : 'btn btn-sm ' . $b['class'];
    if (isset($b['url'])) {
        return '<a class="' . e($class) . '" href="' . e($b['url']) . '"' . (($b['newTab'] ?? true) ? ' target="_blank" rel="noopener"' : '') . '>'
            . '<i class="bi ' . e($b['icon']) . ' me-1" aria-hidden="true"></i>' . e($b['label']) . '</a>';
    }
    return '<button type="submit" form="adminForm" name="task" value="' . e($b['task']) . '" class="' . e($class) . '"'
        . ($b['list'] ? ' data-list-required' : '') . ($b['novalidate'] ? ' formnovalidate' : '') . '>'
        . '<i class="bi ' . e($b['icon']) . ' me-1" aria-hidden="true"></i>' . e($b['label']) . '</button>';
};
?><!doctype html>
<html lang="<?= e(Lang::current()) ?>">
<head>
<?php require __DIR__ . '/head.php' ?>
</head>
<body class="py-admin">

<header class="py-header navbar navbar-dark">
    <div class="py-brand">
        <button class="btn py-header-btn" type="button" id="py-sidebar-toggle" aria-controls="py-sidebar"
                aria-label="<?= e(t('SIDEBAR_TOGGLE')) ?>" title="<?= e(t('SIDEBAR_TOGGLE')) ?>">
            <i class="bi bi-list fs-4" aria-hidden="true"></i>
        </button>
        <a class="py-logo" href="<?= e(Uri::admin()) ?>"><i class="bi bi-boxes" aria-hidden="true"></i><span>Pageyard</span></a>
    </div>
    <h1 class="py-page-title"><i class="bi <?= e($controller->icon) ?>" aria-hidden="true"></i><?= e($controller->title) ?></h1>
    <div class="py-header-end">
        <a class="btn py-header-btn" href="<?= e(Uri::root()) ?>" target="_blank" rel="noopener" title="<?= e(t('VIEW_SITE')) ?>">
            <i class="bi bi-box-arrow-up-right" aria-hidden="true"></i><span class="d-none d-md-inline ms-2"><?= e(Config::get('sitename')) ?></span>
        </a>
        <button class="btn py-header-btn" type="button" id="py-theme-toggle" title="<?= e(t('THEME_TOGGLE')) ?>" aria-label="<?= e(t('THEME_TOGGLE')) ?>">
            <i class="bi bi-circle-half" aria-hidden="true"></i>
        </button>
        <div class="dropdown">
            <button class="btn py-header-btn dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                <i class="bi bi-person-circle" aria-hidden="true"></i><span class="d-none d-md-inline ms-2"><?= e($user['name']) ?></span>
            </button>
            <ul class="dropdown-menu dropdown-menu-end shadow">
                <li><h6 class="dropdown-header"><?= e($user['username']) ?></h6></li>
                <li><a class="dropdown-item" href="<?= e(Uri::admin('view=user&id=' . $user['id'])) ?>"><i class="bi bi-person me-2"></i><?= e(t('MY_ACCOUNT')) ?></a></li>
                <li><hr class="dropdown-divider"></li>
                <li>
                    <form method="post" action="<?= e(Uri::admin()) ?>">
                        <?= Form::token() ?>
                        <button class="dropdown-item" type="submit" name="task" value="login.logout"><i class="bi bi-box-arrow-right me-2"></i><?= e(t('LOGOUT')) ?></button>
                    </form>
                </li>
            </ul>
        </div>
    </div>
</header>

<div class="py-wrapper">
    <nav class="py-sidebar" id="py-sidebar" aria-label="<?= e(t('MENU_MAIN')) ?>">
        <?php foreach ($menu as [$heading, $items]): ?>
            <?php if ($heading !== ''): ?><div class="py-sidebar-heading"><span><?= e($heading) ?></span></div><?php endif ?>
            <ul class="nav flex-column">
                <?php foreach ($items as [$key, $query, $icon, $label]): ?>
                    <li class="nav-item">
                        <a class="nav-link<?= $controller->active === $key ? ' active' : '' ?>" href="<?= e(Uri::admin($query)) ?>"
                           title="<?= e($label) ?>"<?= $controller->active === $key ? ' aria-current="page"' : '' ?>>
                            <i class="bi <?= e($icon) ?>" aria-hidden="true"></i><span><?= e($label) ?></span>
                        </a>
                    </li>
                <?php endforeach ?>
            </ul>
        <?php endforeach ?>
    </nav>
    <div class="py-sidebar-backdrop" id="py-sidebar-backdrop"></div>

    <main class="py-main">
        <?php if ($controller->toolbar): ?>
            <div class="py-subhead" role="toolbar" aria-label="<?= e(t('TOOLBAR')) ?>">
                <?php foreach ($groups[''] ?? [] as $b): ?>
                    <?= $button($b) ?>
                <?php endforeach ?>
                <?php if (!empty($groups['actions'])): ?>
                    <div class="dropdown">
                        <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown"
                                aria-expanded="false" data-list-required>
                            <i class="bi bi-three-dots-vertical me-1" aria-hidden="true"></i><?= e(t('TOOLBAR_ACTIONS')) ?>
                        </button>
                        <ul class="dropdown-menu shadow">
                            <?php foreach ($groups['actions'] as $b): ?>
                                <li><?= $button($b, true) ?></li>
                            <?php endforeach ?>
                        </ul>
                    </div>
                <?php endif ?>
            </div>
        <?php endif ?>

        <section class="py-content">
            <?php foreach ($messages as $m): ?>
                <div class="alert alert-<?= e($m['type']) ?> alert-dismissible fade show" role="alert">
                    <?= e($m['text']) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="<?= e(t('CLOSE')) ?>"></button>
                </div>
            <?php endforeach ?>
            <?= $content ?>
        </section>
    </main>
</div>

<script src="<?= e(Uri::media('vendor/bootstrap/js/bootstrap.bundle.min.js')) ?>"></script>
<script>window.PY = <?= json_encode(['selectFirst' => t('LIST_SELECT_FIRST'), 'confirmDelete' => t('LIST_CONFIRM_DELETE'),
    'confirmDiscard' => t('LIST_CONFIRM_DISCARD'), 'sessionSeconds' => max(1, (int) Pageyard\Config::get('session_lifetime', 60)) * 60,
    'adminUrl' => Uri::admin(), 'pingUrl' => Uri::admin('view=ping'), 'sessionLost' => t('SESSION_LOST'), 'sessionLogin' => t('SESSION_LOGIN')],
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?>;</script>
<script src="<?= e(Uri::media('admin/admin.js')) ?>?v=<?= e(PY_VERSION) ?>"></script>
</body>
</html>
