<?php
/**
 * Blocks of one position. Each block has 'heading' ('' = no title), 'css_class', and one of
 * 'items' (menu tree: label, url, external, new_window, active, children), 'html' or 'hero'.
 * A "User" block has 'user' (Site\Account::blockData) as well as 'html' (the same as plain links).
 *
 * Styles: top (inside the navbar), hero (full width under the navbar), left / right (side panels),
 * sidebar (side menu layout), footer (columns).
 *
 * @var string $position
 * @var list<array> $blocks
 * @var Pageyard\Site\Layout $this
 */

use Pageyard\Uri;

defined('PAGEYARD') or exit;

$link = static function (array $item, string $class): string {
    return '<a class="' . $class . ($item['active'] ? ' active' : '') . '" href="' . e($item['url']) . '"'
        . ($item['active'] ? ' aria-current="page"' : '')
        . ($item['new_window'] ? ' target="_blank" rel="noopener"' : '') . '>' . e($item['label']) . '</a>';
};
// Pills as in Bootstrap's sidebars example; only the current item is filled, not its parent as well.
$pill = static function (array $item) use ($link): string {
    $current = $item['active'] && !array_filter($item['children'], fn ($child) => $child['active']);
    return $link(['active' => $current] + $item, $current ? 'nav-link' : 'nav-link link-body-emphasis');
};
?>
<?php foreach ($blocks as $block): ?>
    <?php $extra = $block['css_class'] !== '' ? ' ' . e($block['css_class']) : '' ?>

    <?php if (isset($block['hero'])): ?>
        <?php
        $hero = $block['hero'];
        $hasImage = $hero['image'] !== '';
        $textWidth = ['start' => 'text-start', 'center' => 'text-center mx-auto', 'end' => 'text-end ms-auto'][$hero['align']];
        ?>
        <section class="py-block py-hero py-hero-<?= e($hero['height']) ?> position-relative overflow-hidden d-flex align-items-center<?= $hasImage ? ' text-white' : ' bg-body-tertiary' ?><?= $extra ?>"<?= $hasImage ? ' data-bs-theme="dark"' : '' ?>>
            <?php if ($hasImage): ?>
                <img class="position-absolute top-0 start-0 w-100 h-100 object-fit-cover" src="<?= e(Uri::root() . $hero['image']) ?>" alt="" fetchpriority="high">
                <?php if ($hero['overlay'] > 0): ?>
                    <div class="position-absolute top-0 start-0 w-100 h-100 bg-black opacity-<?= (int) $hero['overlay'] ?>"></div>
                <?php endif ?>
            <?php endif ?>
            <div class="<?= e($this->wrapper()) ?> position-relative py-5">
                <div class="col-lg-8 <?= $textWidth ?>">
                    <?php if ($hero['heading'] !== ''): ?><h2 class="display-4 fw-bold"><?= e($hero['heading']) ?></h2><?php endif ?>
                    <?php if ($hero['text'] !== ''): ?><p class="lead"><?= nl2br(e($hero['text'])) ?></p><?php endif ?>
                    <?php if ($hero['button'] !== null): ?>
                        <a class="btn btn-primary btn-lg mt-2" href="<?= e($hero['button']['url']) ?>"<?= $hero['button']['new_window'] ? ' target="_blank" rel="noopener"' : '' ?>><?= e($hero['button']['label']) ?></a>
                    <?php endif ?>
                </div>
            </div>
        </section>

    <?php elseif ($position === 'hero'): ?>
        <?php if (isset($block['items'])): ?>
            <nav class="py-block py-block-menu<?= $extra ?> border-bottom">
                <ul class="nav justify-content-center <?= e($this->wrapper()) ?>">
                    <?php foreach ($block['items'] as $item): ?>
                        <li class="nav-item"><?= $link($item, 'nav-link') ?></li>
                    <?php endforeach ?>
                </ul>
            </nav>
        <?php else: ?>
            <section class="py-block py-block-html<?= $extra ?>"><?= $block['html'] ?></section>
        <?php endif ?>

    <?php elseif ($position === 'top' && isset($block['user'])): ?>
        <?php $u = $block['user'] ?>
        <ul class="navbar-nav ms-lg-auto py-block py-block-user<?= $extra ?>">
            <?php if (!$u['member']): ?>
                <li class="nav-item"><a class="nav-link py-user-login" href="<?= e($u['login']) ?>"><?= e(t('ACCOUNT_LOGIN')) ?></a></li>
                <?php if ($u['register'] !== null): ?>
                    <li class="nav-item"><a class="nav-link py-user-register" href="<?= e($u['register']) ?>"><?= e(t('ACCOUNT_REGISTER')) ?></a></li>
                <?php endif ?>
            <?php else: ?>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle py-user-name" href="<?= e($u['profile']) ?>" role="button" data-bs-toggle="dropdown" aria-expanded="false"><?= e($u['name']) ?></a>
                    <ul class="dropdown-menu dropdown-menu-lg-end">
                        <li><a class="dropdown-item py-user-profile" href="<?= e($u['profile']) ?>"><?= e(t('ACCOUNT_PROFILE')) ?></a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><?= Pageyard\Site\Account::logoutForm($u, 'dropdown-item py-user-logout') ?></li>
                    </ul>
                </li>
            <?php endif ?>
        </ul>

    <?php elseif ($position === 'top'): ?>
        <?php if (isset($block['items'])): ?>
            <ul class="navbar-nav py-block py-block-menu<?= $extra ?>">
                <?php foreach ($block['items'] as $i => $item): ?>
                    <?php if ($item['children']): ?>
                        <li class="nav-item dropdown">
                            <a class="nav-link dropdown-toggle<?= $item['active'] ? ' active' : '' ?>" href="<?= e($item['url']) ?>" role="button"
                               data-bs-toggle="dropdown" aria-expanded="false"><?= e($item['label']) ?></a>
                            <ul class="dropdown-menu">
                                <li><?= $link($item, 'dropdown-item') ?></li>
                                <li><hr class="dropdown-divider"></li>
                                <?php foreach ($item['children'] as $child): ?>
                                    <li><?= $link($child, 'dropdown-item') ?></li>
                                <?php endforeach ?>
                            </ul>
                        </li>
                    <?php else: ?>
                        <li class="nav-item"><?= $link($item, 'nav-link') ?></li>
                    <?php endif ?>
                <?php endforeach ?>
            </ul>
        <?php else: ?>
            <div class="navbar-text py-block py-block-html<?= $extra ?>"><?= $block['html'] ?></div>
        <?php endif ?>

    <?php elseif ($position === 'sidebar'): ?>
        <section class="py-block mb-4<?= $extra ?>">
            <?php if ($block['heading'] !== ''): ?><h2 class="h6 text-body-secondary"><?= e($block['heading']) ?></h2><?php endif ?>
            <?php if (isset($block['items'])): ?>
                <nav class="py-block-menu"<?= $block['heading'] !== '' ? ' aria-label="' . e($block['heading']) . '"' : '' ?>>
                    <ul class="nav nav-pills flex-column">
                        <?php foreach ($block['items'] as $item): ?>
                            <li class="nav-item">
                                <?= $pill($item) ?>
                                <?php if ($item['children']): ?>
                                    <ul class="nav nav-pills flex-column ms-3 small">
                                        <?php foreach ($item['children'] as $child): ?>
                                            <li class="nav-item"><?= $pill($child) ?></li>
                                        <?php endforeach ?>
                                    </ul>
                                <?php endif ?>
                            </li>
                        <?php endforeach ?>
                    </ul>
                </nav>
            <?php else: ?>
                <div class="py-block-html"><?= $block['html'] ?></div>
            <?php endif ?>
        </section>

    <?php elseif ($position === 'footer'): ?>
        <div class="col-md py-block<?= $extra ?>">
            <?php if ($block['heading'] !== ''): ?><h2 class="h6 text-uppercase"><?= e($block['heading']) ?></h2><?php endif ?>
            <?php if (isset($block['items'])): ?>
                <ul class="list-unstyled py-block-menu mb-0">
                    <?php foreach ($block['items'] as $item): ?>
                        <li class="mb-1"><?= $link($item, 'link-secondary text-decoration-none') ?></li>
                        <?php foreach ($item['children'] as $child): ?>
                            <li class="mb-1 ms-3"><?= $link($child, 'link-secondary text-decoration-none') ?></li>
                        <?php endforeach ?>
                    <?php endforeach ?>
                </ul>
            <?php else: ?>
                <div class="py-block-html"><?= $block['html'] ?></div>
            <?php endif ?>
        </div>

    <?php else: /* left, right */ ?>
        <section class="card mb-4 py-block<?= $extra ?>">
            <?php if ($block['heading'] !== ''): ?><h2 class="card-header h6 mb-0"><?= e($block['heading']) ?></h2><?php endif ?>
            <?php if (isset($block['items'])): ?>
                <nav class="list-group list-group-flush py-block-menu"<?= $block['heading'] !== '' ? ' aria-label="' . e($block['heading']) . '"' : '' ?>>
                    <?php foreach ($block['items'] as $item): ?>
                        <?= $link($item, 'list-group-item list-group-item-action') ?>
                        <?php foreach ($item['children'] as $child): ?>
                            <?= $link($child, 'list-group-item list-group-item-action ps-4 small') ?>
                        <?php endforeach ?>
                    <?php endforeach ?>
                </nav>
            <?php else: ?>
                <div class="card-body py-block-html"><?= $block['html'] ?></div>
            <?php endif ?>
        </section>
    <?php endif ?>
<?php endforeach ?>
