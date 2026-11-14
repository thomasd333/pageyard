<?php
/**
 * System > Backups.
 * @var bool $supported SQLite database
 * @var list<array{name: string, kind: string, time: int, size: int}> $backups newest first
 * @var array{ok: bool, time: int, message: string}|null $status last run
 * @var int $interval hours (0 = off)
 * @var int $keep
 * @var bool $writable configuration.php
 * @var array<string, string> $errors
 */

use Pageyard\Admin\Form;
use Pageyard\Uri;

defined('PAGEYARD') or exit;

$kinds = ['auto' => [t('BACKUPS_KIND_AUTO'), 'text-bg-secondary'], 'manual' => [t('BACKUPS_KIND_MANUAL'), 'text-bg-primary'],
    'update' => [t('BACKUPS_KIND_UPDATE'), 'text-bg-warning'], 'restore' => [t('BACKUPS_KIND_RESTORE'), 'text-bg-info']];
$intervals = [0 => t('BACKUPS_EVERY_OFF'), 6 => t('BACKUPS_EVERY_HOURS', 6), 12 => t('BACKUPS_EVERY_HOURS', 12),
    24 => t('BACKUPS_EVERY_DAY'), 72 => t('BACKUPS_EVERY_DAYS', 3), 168 => t('BACKUPS_EVERY_WEEK')];
$size = static fn (int $bytes) => $bytes >= 1048576 ? number_format($bytes / 1048576, 1, ',', ' ') . ' MB' : max(1, (int) round($bytes / 1024)) . ' KB';
$when = static fn (int $time) => py_date(gmdate('Y-m-d H:i:s', $time));
?>
<?php if (!$supported): ?>
    <div class="alert alert-info"><i class="bi bi-info-circle me-2" aria-hidden="true"></i><?= e(t('BACKUPS_MYSQL')) ?></div>
<?php else: ?>
    <?php if ($status !== null && !$status['ok']): ?>
        <div class="alert alert-danger"><i class="bi bi-exclamation-octagon me-2" aria-hidden="true"></i><?= e(t('BACKUPS_LAST_FAILED', $when($status['time']), $status['message'])) ?></div>
    <?php endif ?>
    <div class="row g-4">
        <div class="col-xl-8">
            <form id="adminForm" method="post" action="<?= e(Uri::admin()) ?>">
                <?= Form::token() ?>
                <?php if (!$backups): ?>
                    <div class="alert alert-info"><i class="bi bi-info-circle me-2" aria-hidden="true"></i><?= e(t('BACKUPS_NONE')) ?></div>
                <?php else: ?>
                    <div class="card py-card">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0 py-list">
                                <thead>
                                <tr>
                                    <th class="py-col-check"><input class="form-check-input" type="checkbox" data-check-all aria-label="<?= e(t('LIST_CHECK_ALL')) ?>"></th>
                                    <th scope="col"><?= e(t('BACKUPS_DATE')) ?></th>
                                    <th scope="col"><?= e(t('BACKUPS_KIND')) ?></th>
                                    <th scope="col" class="text-end"><?= e(t('BACKUPS_SIZE')) ?></th>
                                    <th scope="col" class="d-none d-md-table-cell"><?= e(t('BACKUPS_FILE')) ?></th>
                                    <th scope="col"><span class="visually-hidden"><?= e(t('BACKUPS_RESTORE_BUTTON')) ?></span></th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($backups as $i => $b): ?>
                                    <tr>
                                        <td class="py-col-check"><input class="form-check-input" type="checkbox" name="cid[]" value="<?= e($b['name']) ?>" id="cb<?= $i ?>" aria-label="<?= e($b['name']) ?>"></td>
                                        <th scope="row" class="fw-normal">
                                            <?= e($when($b['time'])) ?>
                                            <button type="submit" class="btn btn-link btn-sm p-0 ms-2 align-baseline" form="adminForm" name="task" value="backups.download"
                                                    data-row="cb<?= $i ?>" title="<?= e(t('BACKUPS_DOWNLOAD')) ?>"><i class="bi bi-download" aria-hidden="true"></i><span class="visually-hidden"><?= e(t('BACKUPS_DOWNLOAD')) ?></span></button>
                                        </th>
                                        <td><span class="badge <?= $kinds[$b['kind']][1] ?>"><?= e($kinds[$b['kind']][0]) ?></span></td>
                                        <td class="text-end text-nowrap"><?= e($size($b['size'])) ?></td>
                                        <td class="d-none d-md-table-cell small text-body-secondary"><code><?= e($b['name']) ?></code></td>
                                        <td class="text-end text-nowrap">
                                            <button type="submit" class="btn btn-sm btn-outline-secondary" form="adminForm" name="task" value="backups.restore"
                                                    data-row="cb<?= $i ?>" data-confirm="<?= e(t('BACKUPS_RESTORE_CONFIRM', $when($b['time']))) ?>"><i class="bi bi-arrow-counterclockwise me-1" aria-hidden="true"></i><?= e(t('BACKUPS_RESTORE_BUTTON')) ?></button>
                                        </td>
                                    </tr>
                                <?php endforeach ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <p class="small text-body-secondary mt-2"><?= e(t('BACKUPS_WHERE')) ?></p>
                <?php endif ?>
            </form>
        </div>

        <div class="col-xl-4">
            <form class="card py-card mb-4" method="post" action="<?= e(Uri::admin()) ?>">
                <?= Form::token() ?>
                <div class="card-header"><?= e(t('BACKUPS_SCHEDULE')) ?></div>
                <div class="card-body">
                    <?= Form::select('backup_interval', t('BACKUPS_INTERVAL'), $intervals, $interval, $errors, t('BACKUPS_INTERVAL_HINT')) ?>
                    <?= Form::text('backup_keep', t('BACKUPS_KEEP'), $keep, $errors, t('BACKUPS_KEEP_HINT'), 'number', 'min="1" max="100"') ?>
                    <button class="btn btn-sm btn-success" type="submit" name="task" value="backups.settings"<?= $writable ? '' : ' disabled' ?>>
                        <i class="bi bi-check-lg me-1" aria-hidden="true"></i><?= e(t('TOOLBAR_SAVE')) ?>
                    </button>
                    <?php if (!$writable): ?><div class="form-text text-danger"><?= e(t('CONFIG_NOT_WRITABLE')) ?></div><?php endif ?>
                </div>
            </form>

            <div class="card py-card">
                <div class="card-header"><?= e(t('BACKUPS_RESTORE')) ?></div>
                <div class="card-body small">
                    <p><?= e(t('BACKUPS_RESTORE_1')) ?></p>
                    <p><?= e(t('BACKUPS_RESTORE_2')) ?></p>
                    <p class="mb-1"><?= e(t('BACKUPS_RESTORE_3')) ?></p>
                    <code>php core/cli/restore-backup.php</code>
                </div>
            </div>
        </div>
    </div>
<?php endif ?>
