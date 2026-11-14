<?php
/**
 * Administrator login page.
 * @var list<array{type: string, text: string}> $messages
 * @var string $username
 * @var string $mode login | forgot | reset
 * @var bool $resetLink offer "forgot password" (login mode)
 */

use Pageyard\Admin\Form;
use Pageyard\Config;
use Pageyard\Lang;
use Pageyard\Uri;

defined('PAGEYARD') or exit;

$pageTitle = t('LOGIN_TITLE');
$return = Pageyard\Request::isPost() ? Pageyard\Request::str('return') : (string) ($_SERVER['REQUEST_URI'] ?? '');
?><!doctype html>
<html lang="<?= e(Lang::current()) ?>">
<head>
<?php require __DIR__ . '/head.php' ?>
</head>
<body class="py-login">
<main class="py-login-box">
    <div class="py-login-brand">
        <i class="bi bi-boxes" aria-hidden="true"></i>
        <div>
            <div class="py-login-name">Pageyard</div>
            <div class="py-login-site"><?= e(Config::get('sitename')) ?></div>
        </div>
    </div>
    <div class="card shadow-lg border-0">
        <div class="card-body p-4">
            <h1 class="h5 mb-3"><?= e(t(['forgot' => 'RESET_FORGOT_TITLE', 'reset' => 'RESET_NEW_TITLE'][$mode] ?? 'LOGIN_TITLE')) ?></h1>
            <?php foreach ($messages as $m): ?>
                <div class="alert alert-<?= e($m['type']) ?> py-2" role="alert"><?= e($m['text']) ?></div>
            <?php endforeach ?>
            <?php if ($mode === 'forgot'): ?>
                <p class="small text-body-secondary"><?= e(t('RESET_FORGOT_HINT')) ?></p>
                <form method="post" action="<?= e(Uri::admin()) ?>">
                    <?= Form::token() ?>
                    <input type="hidden" name="task" value="login.forgot">
                    <div class="mb-4">
                        <label class="form-label" for="f-username"><?= e(t('LOGIN_USERNAME')) ?></label>
                        <input class="form-control" id="f-username" name="username" autocomplete="username" required autofocus>
                    </div>
                    <button class="btn btn-primary w-100" type="submit"><i class="bi bi-envelope me-2" aria-hidden="true"></i><?= e(t('RESET_SEND')) ?></button>
                </form>
                <div class="text-center mt-3 small"><a href="<?= e(Uri::admin()) ?>"><?= e(t('RESET_BACK')) ?></a></div>
            <?php elseif ($mode === 'reset'): ?>
                <p class="small text-body-secondary"><?= e(t('RESET_NEW_FOR', $username)) ?></p>
                <form method="post" action="<?= e(Uri::admin()) ?>">
                    <?= Form::token() ?>
                    <input type="hidden" name="task" value="login.reset">
                    <input type="hidden" name="reset_token" value="<?= e(Pageyard\Request::str('token') ?: Pageyard\Request::str('reset_token')) ?>">
                    <div class="mb-3">
                        <label class="form-label" for="f-password"><?= e(t('RESET_NEW_PASSWORD')) ?></label>
                        <input class="form-control" type="password" id="f-password" name="password" autocomplete="new-password" required autofocus>
                    </div>
                    <div class="mb-4">
                        <label class="form-label" for="f-password2"><?= e(t('RESET_NEW_PASSWORD2')) ?></label>
                        <input class="form-control" type="password" id="f-password2" name="password2" autocomplete="new-password" required>
                    </div>
                    <button class="btn btn-primary w-100" type="submit"><i class="bi bi-check-lg me-2" aria-hidden="true"></i><?= e(t('RESET_SAVE')) ?></button>
                </form>
            <?php else: ?>
            <form method="post" action="<?= e(Uri::admin()) ?>">
                <?= Form::token() ?>
                <input type="hidden" name="task" value="login.login">
                <input type="hidden" name="return" value="<?= e($return) ?>">
                <div class="mb-3">
                    <label class="form-label" for="f-username"><?= e(t('LOGIN_USERNAME')) ?></label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-person" aria-hidden="true"></i></span>
                        <input class="form-control" id="f-username" name="username" value="<?= e($username) ?>"
                               autocomplete="username" required<?= $username === '' ? ' autofocus' : '' ?>>
                    </div>
                </div>
                <div class="mb-4">
                    <label class="form-label" for="f-password"><?= e(t('LOGIN_PASSWORD')) ?></label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-lock" aria-hidden="true"></i></span>
                        <input class="form-control" type="password" id="f-password" name="password"
                               autocomplete="current-password" required<?= $username !== '' ? ' autofocus' : '' ?>>
                    </div>
                </div>
                <button class="btn btn-primary w-100" type="submit"><i class="bi bi-box-arrow-in-right me-2" aria-hidden="true"></i><?= e(t('LOGIN_SUBMIT')) ?></button>
            </form>
            <?php if ($resetLink): ?>
                <div class="text-center mt-3 small"><a href="<?= e(Uri::admin('view=forgot')) ?>"><?= e(t('RESET_LINK')) ?></a></div>
            <?php endif ?>
            <?php endif ?>
        </div>
    </div>
    <div class="py-login-footer">
        <a href="<?= e(Uri::root()) ?>"><i class="bi bi-arrow-left me-1" aria-hidden="true"></i><?= e(t('LOGIN_BACK_TO_SITE')) ?></a>
    </div>
</main>
</body>
</html>
