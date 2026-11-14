<?php
/**
 * Site template: navbar (logo/site name, "top" blocks, language switcher), full-width "hero" blocks,
 * content with optional left/right panels or a side menu, footer blocks. Plain Bootstrap 5.3; options
 * and custom CSS come from System > Template in the administrator.
 *
 * @var Pageyard\Site\Document $doc
 * @var Pageyard\Site\Layout $layout
 * @var string $content
 */

use Pageyard\Config;
use Pageyard\Lang;
use Pageyard\Settings;
use Pageyard\Site\App;
use Pageyard\Site\Router;
use Pageyard\Uri;

defined('PAGEYARD') or exit;

$o = $layout->options;
$siteName = (string) Config::get('sitename');
$fullTitle = match (true) {
    $doc->isHome || $doc->title === ''               => $siteName,
    Config::get('sitename_title') === 'before'       => $siteName . ' - ' . $doc->title,
    Config::get('sitename_title') === 'none'         => $doc->title,
    default                                          => $doc->title . ' - ' . $siteName,
};
$homeUrl = Router::url('', $doc->lang);
$absolute = Uri::absolute(...);
$robots = Config::get('robots', 'index') === 'noindex' ? 'noindex, nofollow' : ($doc->noindex ? 'noindex' : '');
$isArticle = $doc->published !== null && !$doc->isHome;
$isoDate = static fn (string $utc) => (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->format(DATE_ATOM);
$locales = array_map(fn ($meta) => $meta['locale'], Lang::available());

// Language switcher: the same content in another language, else that language's home page.
$languages = [];
if (count(Config::languages()) > 1) {
    foreach (Lang::names(Config::languages()) as $tag => $name) {
        $url = $doc->alternates[$tag] ?? (Router::home($tag) !== null ? Router::url('', $tag) : null);
        if ($url !== null) {
            $languages[$tag] = [$name, $url];
        }
    }
}

$navbarClass = ['light' => 'bg-body-tertiary', 'dark' => 'bg-dark', 'primary' => 'bg-primary'][$o['navbar']];
$navbarTheme = $o['navbar'] === 'light' ? '' : ' data-bs-theme="dark"';
$hasTop = $layout->has('top');
$hasLeft = $layout->has('left');
$hasRight = $layout->has('right');
$sidebar = $layout->sidebar();
$wrapper = $layout->wrapper();
$customCss = Settings::customCss();

$languageLink = static function (string $tag, string $name, string $url) use ($doc): string {
    $current = $tag === $doc->lang;
    return '<a class="nav-link px-2' . ($current ? ' active fw-semibold' : '') . '" href="' . e($url) . '" hreflang="' . e($tag) . '" lang="' . e($tag)
        . '" title="' . e($name) . '"' . ($current ? ' aria-current="true"' : '') . '>' . e(strtoupper($tag)) . '</a>';
};

ob_start();
?>
        <main id="content" class="col-lg order-1 order-lg-2 py-content">
            <?php if ($doc->preview !== null): ?>
                <div class="alert <?= $doc->preview ? 'alert-warning' : 'alert-info' ?> small py-preview" role="status">
                    <strong><?= e(t('SITE_PREVIEW')) ?></strong>
                    <?= e($doc->preview ? implode(' ', $doc->preview) : t('SITE_PREVIEW_VISIBLE')) ?>
                </div>
            <?php endif ?>
            <?php if ($doc->breadcrumbs): ?>
                <nav aria-label="<?= e(t('SITE_BREADCRUMB')) ?>">
                    <ol class="breadcrumb small">
                        <li class="breadcrumb-item"><a href="<?= e($homeUrl) ?>"><?= e(t('SITE_HOME')) ?></a></li>
                        <?php foreach ($doc->breadcrumbs as [$title, $url]): ?>
                            <?php if ($url !== null): ?>
                                <li class="breadcrumb-item"><a href="<?= e($url) ?>"><?= e($title) ?></a></li>
                            <?php else: ?>
                                <li class="breadcrumb-item active" aria-current="page"><?= e($title) ?></li>
                            <?php endif ?>
                        <?php endforeach ?>
                    </ol>
                </nav>
            <?php endif ?>
            <?= $content ?>
        </main>
<?php
$main = (string) ob_get_clean();
?><!doctype html>
<html lang="<?= e($doc->lang) ?>"<?= $o['color_mode'] !== 'auto' ? ' data-bs-theme="' . e($o['color_mode']) . '"' : '' ?>>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($fullTitle) ?></title>
<?php if ($o['color_mode'] === 'auto'): ?>
    <script>document.documentElement.setAttribute('data-bs-theme', matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');</script>
<?php endif ?>
<?php if ($doc->description !== ''): ?>
    <meta name="description" content="<?= e($doc->description) ?>">
<?php endif ?>
<?php if ($robots !== ''): ?>
    <meta name="robots" content="<?= $robots ?>">
<?php endif ?>
<?php if ($doc->canonical !== ''): ?>
    <link rel="canonical" href="<?= e($absolute($doc->canonical)) ?>">
<?php endif ?>
<?php if (count($doc->alternates) > 1): foreach ($doc->alternates as $tag => $url): ?>
    <link rel="alternate" hreflang="<?= e($tag) ?>" href="<?= e($absolute($url)) ?>">
<?php endforeach; endif ?>
    <meta property="og:type" content="<?= $isArticle ? 'article' : 'website' ?>">
    <meta property="og:title" content="<?= e($doc->title !== '' ? $doc->title : $siteName) ?>">
    <meta property="og:site_name" content="<?= e($siteName) ?>">
<?php if ($doc->canonical !== ''): ?>
    <meta property="og:url" content="<?= e($absolute($doc->canonical)) ?>">
<?php endif ?>
    <meta property="og:locale" content="<?= e($locales[$doc->lang] ?? Lang::locale()) ?>">
<?php foreach (array_keys($doc->alternates) as $tag): if ($tag !== $doc->lang && isset($locales[$tag])): ?>
    <meta property="og:locale:alternate" content="<?= e($locales[$tag]) ?>">
<?php endif; endforeach ?>
<?php if ($isArticle): ?>
    <meta property="article:published_time" content="<?= e($isoDate($doc->published)) ?>">
<?php if ($doc->modified !== null): ?>
    <meta property="article:modified_time" content="<?= e($isoDate($doc->modified)) ?>">
<?php endif ?>
<?php endif ?>
    <meta name="twitter:card" content="<?= $doc->image !== '' ? 'summary_large_image' : 'summary' ?>">
<?php if ($doc->description !== ''): ?>
    <meta property="og:description" content="<?= e($doc->description) ?>">
<?php endif ?>
<?php if ($doc->image !== ''): ?>
    <meta property="og:image" content="<?= e($absolute($doc->image)) ?>">
<?php endif ?>
    <link rel="stylesheet" href="<?= e(Uri::media('vendor/bootstrap/css/bootstrap.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(Uri::root()) ?>templates/site/css/template.css?v=<?= e(PY_VERSION) ?>">
<?php if (($customSheet = App::customStylesheet()) !== null): ?>
    <link rel="stylesheet" href="<?= e($customSheet) ?>">
<?php endif ?>
<?php if ($customCss !== ''): ?>
    <style id="py-custom-css">
<?= $customCss ?>

    </style>
<?php endif ?>
<?= ($codeHead = $doc->code('head')) !== '' ? $codeHead . "\n" : '' ?>
</head>
<body class="d-flex flex-column min-vh-100 <?= e($doc->bodyClass) ?><?= $doc->isHome ? ' py-home' : '' ?><?= $o['navbar_sticky'] ? ' py-navbar-sticky' : '' ?>">
<a class="visually-hidden-focusable position-absolute top-0 start-0 m-2 btn btn-primary" style="z-index: 1080" href="#content"><?= e(t('SITE_SKIP')) ?></a>

<header class="py-site-header<?= $o['navbar_sticky'] ? ' sticky-top' : '' ?>">
    <nav class="navbar navbar-expand-lg <?= $navbarClass ?>"<?= $navbarTheme ?>>
        <div class="<?= e($wrapper) ?>">
            <a class="navbar-brand d-flex align-items-center gap-2 fw-semibold" href="<?= e($homeUrl) ?>">
                <?php if ($o['logo'] !== ''): ?>
                    <img class="py-logo" src="<?= e(Uri::root() . $o['logo']) ?>" alt="<?= $o['show_sitename'] ? '' : e($siteName) ?>">
                <?php endif ?>
                <?php if ($o['show_sitename'] || $o['logo'] === ''): ?><span><?= e($siteName) ?></span><?php endif ?>
            </a>
            <?php if ($sidebar): /* one button on phones: the side menu also holds the top menu there */ ?>
                <button class="navbar-toggler" type="button" data-bs-toggle="offcanvas" data-bs-target="#py-sidebar"
                        aria-controls="py-sidebar" aria-label="<?= e(t('SITE_MENU_TOGGLE')) ?>">
                    <span class="navbar-toggler-icon"></span>
                </button>
            <?php elseif ($hasTop || $languages): ?>
                <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#py-navbar"
                        aria-controls="py-navbar" aria-expanded="false" aria-label="<?= e(t('SITE_MENU_TOGGLE')) ?>">
                    <span class="navbar-toggler-icon"></span>
                </button>
            <?php endif ?>
            <?php if ($hasTop || $languages): ?>
                <div class="collapse navbar-collapse" id="py-navbar">
                    <div class="py-top d-lg-flex flex-lg-grow-1 align-items-center gap-3 me-lg-3"><?= $layout->render('top') ?></div>
                    <?php if ($languages): ?>
                        <ul class="navbar-nav flex-row gap-1 py-languages" aria-label="<?= e(t('SITE_LANGUAGE')) ?>">
                            <?php foreach ($languages as $tag => [$name, $url]): ?>
                                <li class="nav-item"><?= $languageLink($tag, $name, $url) ?></li>
                            <?php endforeach ?>
                        </ul>
                    <?php endif ?>
                </div>
            <?php endif ?>
        </div>
    </nav>
</header>

<?= $layout->render('hero') ?>

<?php if ($sidebar): ?>
    <div class="container-fluid d-flex flex-column flex-grow-1">
        <div class="row flex-grow-1">
            <aside class="col-lg-<?= (int) $o['left_width'] ?> p-0 bg-body-tertiary border-end py-sidebar">
                <div class="offcanvas-lg offcanvas-end" tabindex="-1" id="py-sidebar" aria-labelledby="py-sidebar-title">
                    <div class="offcanvas-header">
                        <h2 class="offcanvas-title h5" id="py-sidebar-title"><?= e($siteName) ?></h2>
                        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" data-bs-target="#py-sidebar" aria-label="<?= e(t('SITE_MENU_TOGGLE')) ?>"></button>
                    </div>
                    <div class="offcanvas-body d-block p-3">
                        <?= $layout->render('left', 'sidebar') ?>
                        <?php if ($hasTop || $languages): ?>
                            <div class="d-lg-none border-top pt-3">
                                <?= $layout->render('top', 'sidebar') ?>
                                <?php if ($languages): ?>
                                    <ul class="nav" aria-label="<?= e(t('SITE_LANGUAGE')) ?>">
                                        <?php foreach ($languages as $tag => [$name, $url]): ?>
                                            <li class="nav-item"><?= $languageLink($tag, $name, $url) ?></li>
                                        <?php endforeach ?>
                                    </ul>
                                <?php endif ?>
                            </div>
                        <?php endif ?>
                    </div>
                </div>
            </aside>
            <div class="col-lg py-4 px-lg-4" style="min-width: 0">
                <div class="row g-4">
                    <?= $main ?>
                    <?php if ($hasRight): ?>
                        <aside class="col-lg-<?= (int) $o['right_width'] ?> order-3 py-panel py-panel-right"><?= $layout->render('right') ?></aside>
                    <?php endif ?>
                </div>
            </div>
        </div>
    </div>
<?php else: ?>
    <div class="<?= e($wrapper) ?> py-4 flex-grow-1">
        <div class="row g-4">
            <?= $main ?>
            <?php if ($hasLeft): ?>
                <aside class="col-lg-<?= (int) $o['left_width'] ?> order-2 order-lg-1 py-panel py-panel-left"><?= $layout->render('left') ?></aside>
            <?php endif ?>
            <?php if ($hasRight): ?>
                <aside class="col-lg-<?= (int) $o['right_width'] ?> order-3 py-panel py-panel-right"><?= $layout->render('right') ?></aside>
            <?php endif ?>
        </div>
    </div>
<?php endif ?>

<footer class="py-site-footer border-top bg-body-tertiary">
    <div class="<?= e($wrapper) ?> py-4 small text-body-secondary">
        <?php if ($layout->has('footer')): ?>
            <div class="row g-4 mb-3"><?= $layout->render('footer') ?></div>
        <?php endif ?>
        <?php if ($o['show_copyright']): ?>
            <div>&copy; <?= date('Y') ?> <?= e($siteName) ?></div>
        <?php endif ?>
    </div>
</footer>

<script src="<?= e(Uri::media('vendor/bootstrap/js/bootstrap.bundle.min.js')) ?>" defer></script>
<?= ($codeBody = $doc->code('body')) !== '' ? $codeBody . "\n" : '' ?>
</body>
</html>
