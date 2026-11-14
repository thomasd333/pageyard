<?php
/**
 * User: New / Edit.
 * @var array $user
 * @var array<string, string> $errors
 * @var bool $self editing your own account
 */

use Pageyard\Admin\Form;
use Pageyard\Lang;
use Pageyard\Uri;

defined('PAGEYARD') or exit;

$languages = ['' => t('USERS_LANGUAGE_DEFAULT')] + array_map(fn ($m) => $m['name'], Lang::available());
$isNew = !$user['id'];
?>
<form id="adminForm" method="post" action="<?= e(Uri::admin()) ?>" novalidate data-track-changes>
    <?= Form::token() ?>
    <input type="hidden" name="id" value="<?= (int) $user['id'] ?>">
    <div class="row g-4">
        <div class="col-lg-8">
            <div class="card py-card">
                <div class="card-header"><?= e(t('USERS_ACCOUNT')) ?></div>
                <div class="card-body">
                    <?= Form::text('name', t('USERS_NAME'), $user['name'], $errors, '', 'text', 'required autocomplete="off"' . ($isNew ? ' autofocus' : '')) ?>
                    <div class="row">
                        <div class="col-md-6"><?= Form::text('username', t('USERS_USERNAME'), $user['username'], $errors, t('USERS_USERNAME_HINT'), 'text', 'required autocomplete="off"') ?></div>
                        <div class="col-md-6"><?= Form::text('email', t('USERS_EMAIL'), $user['email'], $errors, '', 'email', 'required autocomplete="off"') ?></div>
                    </div>
                    <div class="row">
                        <div class="col-md-6"><?= Form::text('password', t('USERS_PASSWORD'), '', $errors, $isNew ? t('USERS_PASSWORD_HINT_NEW') : t('USERS_PASSWORD_HINT'), 'password', 'autocomplete="new-password"' . ($isNew ? ' required' : '')) ?></div>
                        <div class="col-md-6"><?= Form::text('password2', t('USERS_PASSWORD2'), '', $errors, '', 'password', 'autocomplete="new-password"') ?></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="card py-card mb-4">
                <div class="card-header"><?= e(t('USERS_SETTINGS')) ?></div>
                <div class="card-body">
                    <?php if ($self): ?><input type="hidden" name="active" value="1"><?php endif ?>
                    <?php if (($user['pending'] ?? '') !== ''): ?>
                        <div class="alert alert-warning small"><?= e(t($user['pending'] === 'admin' ? 'USERS_PENDING_ADMIN_HINT' : 'USERS_PENDING_EMAIL_HINT')) ?></div>
                    <?php endif ?>
                    <?= Form::toggle('active', t('USERS_ACTIVE'), (bool) $user['active'], $errors, $self ? t('USERS_ACTIVE_SELF') : t('USERS_ACTIVE_HINT'), $self) ?>
                    <?php if ($self): ?><input type="hidden" name="admin" value="1"><?php endif ?>
                    <?= Form::toggle('admin', t('USERS_ADMIN'), (bool) ($user['admin'] ?? false), $errors, $self ? t('USERS_ADMIN_SELF') : t('USERS_ADMIN_HINT'), $self) ?>
                    <?= Form::select('language', t('USERS_LANGUAGE'), $languages, $user['language'] ?? '', $errors, t('USERS_LANGUAGE_HINT')) ?>
                </div>
            </div>
            <?php if (!$isNew): ?>
                <div class="card py-card">
                    <div class="card-header"><?= e(t('USERS_INFO')) ?></div>
                    <ul class="list-group list-group-flush small">
                        <li class="list-group-item d-flex justify-content-between"><span class="text-body-secondary"><?= e(t('USERS_CREATED')) ?></span><span><?= e(py_date($user['created'])) ?></span></li>
                        <li class="list-group-item d-flex justify-content-between"><span class="text-body-secondary"><?= e(t('USERS_LAST_LOGIN')) ?></span><span><?= e($user['last_login'] ? py_date($user['last_login']) : t('USERS_NEVER')) ?></span></li>
                        <li class="list-group-item d-flex justify-content-between"><span class="text-body-secondary">ID</span><span><?= (int) $user['id'] ?></span></li>
                    </ul>
                </div>
            <?php endif ?>
        </div>
    </div>
</form>
