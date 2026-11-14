<?php
/**
 * Frame for pages shown inside a dialog (iframe): no header, sidebar or toolbar; messages and content only.
 * @var Pageyard\Admin\Controller $controller
 * @var string $content
 * @var list<array{text: string, type: string}> $messages
 */

use Pageyard\Lang;
use Pageyard\Uri;

defined('PAGEYARD') or exit;

$pageTitle = $controller->title;
?><!doctype html>
<html lang="<?= e(Lang::current()) ?>">
<head>
<?php require __DIR__ . '/head.php' ?>
</head>
<body class="py-admin py-bare">
<main class="p-3">
    <?php foreach ($messages as $m): ?>
        <div class="alert alert-<?= e($m['type']) ?> alert-dismissible fade show" role="alert">
            <?= e($m['text']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="<?= e(t('CLOSE')) ?>"></button>
        </div>
    <?php endforeach ?>
    <?= $content ?>
</main>
<script src="<?= e(Uri::media('vendor/bootstrap/js/bootstrap.bundle.min.js')) ?>"></script>
<script>window.PY = <?= json_encode(['selectFirst' => t('LIST_SELECT_FIRST'), 'confirmDelete' => t('LIST_CONFIRM_DELETE'),
    'confirmDiscard' => t('LIST_CONFIRM_DISCARD')], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;</script>
<script src="<?= e(Uri::media('admin/admin.js')) ?>?v=<?= e(PY_VERSION) ?>"></script>
</body>
</html>
