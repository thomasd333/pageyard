<?php
/**
 * Account pages: logging in, registration, "forgot password", new password, profile.
 * @var string $mode login | register | forgot | reset | profile
 * @var string $title
 * @var string $action address the form posts to
 * @var list<array{type: string, text: string}> $messages
 * @var array<string, mixed> $values
 * @var array<string, string> $errors keyed by field; '' = about the whole form
 * login:    @var string $return, ?string $resetUrl, ?string $registerUrl
 * register: @var string $stamp, ?array{string, string} $privacy [title, url], string $loginUrl
 * forgot:   @var string $loginUrl
 * reset:    @var string $token
 * profile:  @var array $user, ?string $adminUrl, string $logoutUrl
 * @var Pageyard\Site\Document $doc
 */

use Pageyard\Accounts;
use Pageyard\Session;

defined('PAGEYARD') or exit;

$csrf = '<input type="hidden" name="token" value="' . e(Session::token()) . '">';
$invalid = static fn (string $name): string => isset($errors[$name]) ? ' is-invalid' : '';
$feedback = static fn (string $name): string => isset($errors[$name]) ? '<div class="invalid-feedback">' . e($errors[$name]) . '</div>' : '';
/** A labelled input. */
$field = static function (string $name, string $label, string $type = 'text', string $attributes = '', string $hint = '') use ($values, $invalid, $feedback): string {
    $value = $type === 'password' ? '' : (string) ($values[$name] ?? '');
    return '<div class="mb-3"><label class="form-label" for="py-account-' . $name . '">' . e($label) . '</label>'
        . '<input class="form-control' . $invalid($name) . '" type="' . $type . '" id="py-account-' . $name . '" name="' . $name . '" value="' . e($value) . '" ' . $attributes . '>'
        . $feedback($name) . ($hint !== '' ? '<div class="form-text">' . e($hint) . '</div>' : '') . '</div>';
};
?>
<section class="py-account py-account-<?= e($mode) ?> mx-auto" style="max-width: <?= $mode === 'profile' ? '40rem' : '26rem' ?>">
    <h1 class="h2 mb-4"><?= e($title) ?></h1>

    <?php foreach ($messages as $m): ?>
        <div class="alert alert-<?= e($m['type']) ?>" role="alert"><?= e($m['text']) ?></div>
    <?php endforeach ?>
    <?php if (isset($errors[''])): ?>
        <div class="alert alert-danger" role="alert"><?= e($errors['']) ?></div>
    <?php endif ?>

    <?php if ($mode === 'login'): ?>
        <form method="post" action="<?= e($action) ?>">
            <?= $csrf ?>
            <input type="hidden" name="return" value="<?= e($return) ?>">
            <?= $field('username', t('LOGIN_USERNAME'), 'text', 'autocomplete="username" required' . (($values['username'] ?? '') === '' ? ' autofocus' : '')) ?>
            <?= $field('password', t('LOGIN_PASSWORD'), 'password', 'autocomplete="current-password" required' . (($values['username'] ?? '') !== '' ? ' autofocus' : '')) ?>
            <button class="btn btn-primary w-100" type="submit"><?= e(t('LOGIN_SUBMIT')) ?></button>
        </form>
        <?php if ($resetUrl !== null || $registerUrl !== null): ?>
            <div class="mt-3 d-flex flex-wrap justify-content-between gap-2 small">
                <?php if ($resetUrl !== null): ?><a href="<?= e($resetUrl) ?>"><?= e(t('RESET_LINK')) ?></a><?php endif ?>
                <?php if ($registerUrl !== null): ?><a href="<?= e($registerUrl) ?>"><?= e(t('ACCOUNT_NO_ACCOUNT')) ?></a><?php endif ?>
            </div>
        <?php endif ?>

    <?php elseif ($mode === 'register'): ?>
        <form method="post" action="<?= e($action) ?>" novalidate>
            <?= $csrf ?>
            <input type="hidden" name="py_form" value="<?= e($stamp) ?>">
            <?php /* The trap: off-screen for people, attractive to bots. */ ?>
            <div style="position:absolute;left:-10000px" aria-hidden="true"><label>Website<input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>
            <?= $field('name', t('ACCOUNT_NAME'), 'text', 'maxlength="100" autocomplete="name" required autofocus') ?>
            <?= $field('username', t('ACCOUNT_USERNAME'), 'text', 'maxlength="150" autocomplete="username" required', t('ACCOUNT_USERNAME_HINT')) ?>
            <?= $field('email', t('ACCOUNT_EMAIL'), 'email', 'maxlength="255" autocomplete="email" required') ?>
            <?= $field('password', t('ACCOUNT_PASSWORD'), 'password', 'autocomplete="new-password" required', t('ACCOUNT_PASSWORD_HINT', Pageyard\Users::MIN_PASSWORD)) ?>
            <?= $field('password2', t('ACCOUNT_PASSWORD2'), 'password', 'autocomplete="new-password" required') ?>
            <?php if (Accounts::privacyPage() > 0): ?>
                <div class="form-check mb-3">
                    <input class="form-check-input<?= $invalid('consent') ?>" type="checkbox" id="py-account-consent" name="consent" value="1"<?= !empty($values['consent']) ? ' checked' : '' ?> required>
                    <label class="form-check-label" for="py-account-consent">
                        <?php if ($privacy !== null): ?>
                            <?= e(t('ACCOUNT_CONSENT')) ?> <a href="<?= e($privacy[1]) ?>" target="_blank"><?= e($privacy[0]) ?></a>
                        <?php else: ?>
                            <?= e(t('ACCOUNT_CONSENT_PLAIN')) ?>
                        <?php endif ?>
                    </label>
                    <?= $feedback('consent') ?>
                </div>
            <?php endif ?>
            <button class="btn btn-primary w-100" type="submit"><?= e(t('ACCOUNT_REGISTER_SUBMIT')) ?></button>
        </form>
        <div class="mt-3 small"><a href="<?= e($loginUrl) ?>"><?= e(t('ACCOUNT_HAVE_ACCOUNT')) ?></a></div>

    <?php elseif ($mode === 'forgot'): ?>
        <p class="text-body-secondary"><?= e(t('RESET_FORGOT_HINT')) ?></p>
        <form method="post" action="<?= e($action) ?>">
            <?= $csrf ?>
            <?= $field('username', t('LOGIN_USERNAME'), 'text', 'autocomplete="username" required autofocus') ?>
            <button class="btn btn-primary w-100" type="submit"><?= e(t('RESET_SEND')) ?></button>
        </form>
        <div class="mt-3 small"><a href="<?= e($loginUrl) ?>"><?= e(t('RESET_BACK')) ?></a></div>

    <?php elseif ($mode === 'reset'): ?>
        <p class="text-body-secondary"><?= e(t('RESET_NEW_FOR', (string) $values['username'])) ?></p>
        <form method="post" action="<?= e($action) ?>">
            <?= $csrf ?>
            <input type="hidden" name="reset_token" value="<?= e($token) ?>">
            <?= $field('password', t('RESET_NEW_PASSWORD'), 'password', 'autocomplete="new-password" required autofocus') ?>
            <?= $field('password2', t('RESET_NEW_PASSWORD2'), 'password', 'autocomplete="new-password" required') ?>
            <button class="btn btn-primary w-100" type="submit"><?= e(t('RESET_SAVE')) ?></button>
        </form>

    <?php else: /* profile */ ?>
        <?php if ($adminUrl !== null): ?>
            <div class="alert alert-secondary small" role="note"><?= e(t('ACCOUNT_IS_ADMIN')) ?> <a href="<?= e($adminUrl) ?>"><?= e(t('ACCOUNT_ADMIN_LINK')) ?></a></div>
        <?php endif ?>
        <form method="post" action="<?= e($action) ?>" novalidate class="mb-5">
            <?= $csrf ?>
            <?= $field('name', t('ACCOUNT_NAME'), 'text', 'maxlength="100" autocomplete="name" required') ?>
            <div class="mb-3">
                <label class="form-label" for="py-account-username"><?= e(t('ACCOUNT_USERNAME')) ?></label>
                <input class="form-control" id="py-account-username" value="<?= e($user['username']) ?>" readonly>
            </div>
            <?= $field('email', t('ACCOUNT_EMAIL'), 'email', 'maxlength="255" autocomplete="email" required', t('ACCOUNT_EMAIL_HINT')) ?>
            <fieldset class="border-top pt-3 mt-4">
                <legend class="h5"><?= e(t('ACCOUNT_CHANGE_PASSWORD')) ?></legend>
                <div class="row">
                    <div class="col-sm-6"><?= $field('password', t('RESET_NEW_PASSWORD'), 'password', 'autocomplete="new-password"', t('ACCOUNT_PASSWORD_KEEP')) ?></div>
                    <div class="col-sm-6"><?= $field('password2', t('RESET_NEW_PASSWORD2'), 'password', 'autocomplete="new-password"') ?></div>
                </div>
            </fieldset>
            <?= $field('current', t('ACCOUNT_CURRENT'), 'password', 'autocomplete="current-password"', t('ACCOUNT_CURRENT_HINT')) ?>
            <div class="d-flex flex-wrap gap-2">
                <button class="btn btn-primary" type="submit"><?= e(t('ACCOUNT_SAVE')) ?></button>
                <button class="btn btn-outline-secondary" type="submit" form="py-account-logout"><?= e(t('ACCOUNT_LOGOUT')) ?></button>
            </div>
        </form>
        <form id="py-account-logout" method="post" action="<?= e($logoutUrl) ?>"><?= $csrf ?></form>

        <?php if (!$user['admin']): ?>
            <details class="border-top pt-3" id="py-account-delete">
                <summary class="text-danger"><?= e(t('ACCOUNT_DELETE')) ?></summary>
                <form method="post" action="<?= e($action) ?>" class="mt-3">
                    <?= $csrf ?>
                    <input type="hidden" name="task" value="delete">
                    <p class="small text-body-secondary"><?= e(t('ACCOUNT_DELETE_HINT')) ?></p>
                    <div class="mb-3">
                        <label class="form-label" for="py-account-delete-password"><?= e(t('LOGIN_PASSWORD')) ?></label>
                        <input class="form-control" type="password" id="py-account-delete-password" name="delete_password" autocomplete="current-password" required>
                    </div>
                    <button class="btn btn-danger" type="submit"><?= e(t('ACCOUNT_DELETE_SUBMIT')) ?></button>
                </form>
            </details>
        <?php endif ?>
    <?php endif ?>
</section>
