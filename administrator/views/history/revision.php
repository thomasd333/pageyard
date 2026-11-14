<?php
/**
 * Preview of one version. Stored HTML is shown in a sandboxed frame: no scripts run and the
 * admin's styles don't mix with it. {script} tags appear as plain text.
 * @var array $revision
 * @var bool $exists false = the item was deleted
 * @var array<string, string> $languages tag => name
 */

use Pageyard\Admin\Form;
use Pageyard\Uri;

defined('PAGEYARD') or exit;

$type = $revision['item_type'];
$content = $revision['content'];
$first = array_key_first($languages);
$frame = static function (?string $html): string {
    if ($html === null || $html === '') {
        return '<p class="small text-body-secondary mb-0">' . e(t('HISTORY_NO_TEXT')) . '</p>';
    }
    $doc = '<!doctype html><html><head><meta charset="utf-8"><base href="' . e(Uri::root()) . '" target="_blank">'
        . '<link rel="stylesheet" href="' . e(Uri::media('vendor/bootstrap/css/bootstrap.min.css')) . '">'
        . '<link rel="stylesheet" href="' . e(Uri::media('admin/editor-content.css')) . '">'
        . '</head><body class="p-3">' . $html . '</body></html>';
    return '<iframe class="py-revision-frame" sandbox="allow-same-origin" data-autoheight title="' . e(t('TOOLBAR_PREVIEW')) . '" srcdoc="' . e($doc) . '"></iframe>';
};
$field = static fn (string $label, string $value): string => '<div class="mb-3"><div class="small text-body-secondary">' . e($label) . '</div><div>'
    . ($value !== '' ? e($value) : '&ndash;') . '</div></div>';
?>
<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
    <div class="text-body-secondary small me-auto">
        <?= e(t('HISTORY_REPLACED')) ?>: <?= e(py_date($revision['created'])) ?>
        <?= $revision['author'] !== '' ? ' &middot; ' . e(t('PAGES_AUTHOR')) . ': ' . e($revision['author']) : '' ?>
    </div>
    <form method="post" action="<?= e(Uri::admin()) ?>" data-confirm="<?= e(t($exists ? 'HISTORY_CONFIRM' : 'HISTORY_CONFIRM_DELETED')) ?>">
        <?= Form::token() ?>
        <input type="hidden" name="rid" value="<?= (int) $revision['id'] ?>">
        <button class="btn btn-sm btn-primary" type="submit" name="task" value="history.restore"><i class="bi bi-arrow-counterclockwise me-1" aria-hidden="true"></i><?= e(t('HISTORY_RESTORE')) ?></button>
    </form>
</div>

<?php if ($type === 'css'): ?>
    <div class="card py-card">
        <div class="card-body">
            <?php if (($content['css'] ?? '') === ''): ?>
                <p class="text-body-secondary mb-0"><?= e(t('HISTORY_CSS_EMPTY')) ?></p>
            <?php else: ?>
                <pre class="py-code mb-0"><code><?= e($content['css']) ?></code></pre>
            <?php endif ?>
        </div>
    </div>
<?php else: ?>
    <?php if ($type === 'block'): ?>
        <?= $field(t('BLOCKS_NAME'), (string) ($content['title'] ?? '')) ?>
    <?php else: ?>
        <p class="small text-body-secondary"><i class="bi bi-info-circle me-1" aria-hidden="true"></i><?= e(t('HISTORY_KEPT_PAGE')) ?></p>
    <?php endif ?>
    <?= Form::langTabs($languages, []) ?>
    <div class="card py-card<?= count($languages) > 1 ? ' py-card-tabbed' : '' ?>">
        <div class="card-body tab-content">
            <?php if (($content['image'] ?? '') !== ''): ?>
                <div class="mb-3">
                    <div class="small text-body-secondary mb-1"><?= e(t('PAGES_IMAGE')) ?></div>
                    <img class="img-fluid rounded" style="max-height: 12rem" src="<?= e(Uri::root() . $content['image']) ?>" alt="">
                </div>
            <?php endif ?>
            <?php foreach ($languages as $lang => $name): ?>
                <?php $tr = $content['translations'][$lang] ?? [] ?>
                <div class="tab-pane fade<?= $lang === $first ? ' show active' : '' ?>" id="pane-lang-<?= e($lang) ?>" role="tabpanel" aria-labelledby="tab-lang-<?= e($lang) ?>">
                    <?php if ($type === 'page'): ?>
                        <div class="row">
                            <div class="col-md-8"><?= $field(t('FIELD_TITLE'), (string) ($tr['title'] ?? '')) ?></div>
                            <div class="col-md-4"><?= $field(t('FIELD_ALIAS'), (string) ($tr['alias'] ?? '')) ?></div>
                        </div>
                        <div class="mb-3"><div class="small text-body-secondary mb-1"><?= e(t('PAGES_INTRO')) ?></div><?= $frame($tr['intro'] ?? null) ?></div>
                        <div class="mb-3"><div class="small text-body-secondary mb-1"><?= e(t('PAGES_BODY')) ?></div><?= $frame($tr['body'] ?? null) ?></div>
                        <?= $field(t('FIELD_META_DESCRIPTION'), (string) ($tr['meta_description'] ?? '')) ?>
                    <?php else: ?>
                        <?= $field(t('FIELD_TITLE'), (string) ($tr['title'] ?? '')) ?>
                        <?php if (($content['type'] ?? '') === 'hero'): ?>
                            <?= $field(t('HERO_TEXT'), (string) ($tr['content'] ?? '')) ?>
                            <?= $field(t('HERO_LINK_LABEL'), (string) ($tr['link_label'] ?? '')) ?>
                        <?php else: ?>
                            <div><div class="small text-body-secondary mb-1"><?= e(t('BLOCKS_CONTENT')) ?></div><?= $frame($tr['content'] ?? null) ?></div>
                        <?php endif ?>
                    <?php endif ?>
                </div>
            <?php endforeach ?>
        </div>
    </div>
<?php endif ?>
