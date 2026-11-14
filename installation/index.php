<?php
/**
 * Installer. Locked once configuration.php exists.
 */

define('PY_CLIENT', 'installation');
require dirname(__DIR__) . '/core/bootstrap.php';

use Pageyard\Config;
use Pageyard\Installer;
use Pageyard\Lang;
use Pageyard\Uri;

session_name('pageyard_install');
session_start();

if (isset($_GET['lang']) && Lang::exists($_GET['lang'])) {
    $_SESSION['lang'] = $_GET['lang'];
}
Lang::set($_SESSION['lang'] ?? Lang::fromBrowser());
$_SESSION['token'] ??= bin2hex(random_bytes(16));

$installer = new Installer();
$input = Installer::FIELDS;
$input['language'] = Lang::current();
$input['languages'] = [Lang::current()];
$input['timezone'] = Lang::current() === 'pl' ? 'Europe/Warsaw' : 'UTC';
$errors = [];
$message = '';
$view = 'form';

if (!empty($_SESSION['installed']) && Config::exists()) {
    $view = 'done';
} elseif (Config::exists()) {
    $view = 'locked';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach (Installer::FIELDS as $name => $default) {
        $value = $_POST[$name] ?? (is_array($default) ? [] : '');
        $input[$name] = is_array($default) ? array_map('strval', (array) $value) : trim((string) $value);
    }
    $input['admin_password'] = (string) ($_POST['admin_password'] ?? '');
    $input['admin_password2'] = (string) ($_POST['admin_password2'] ?? '');
    $input['password'] = (string) ($_POST['password'] ?? '');

    if (!hash_equals($_SESSION['token'], (string) ($_POST['token'] ?? ''))) {
        $message = t('INSTALL_ERR_TOKEN');
    } elseif (!$installer->requirementsMet()) {
        $message = t('INSTALL_REQ_FAILED');
    } elseif ($errors = $installer->validate($input)) {
        $message = t('INSTALL_ERR_FORM');
    } else {
        try {
            $installer->install($input);
            $_SESSION['installed'] = true;
            Uri::redirect(Uri::root() . 'installation/');
        } catch (RuntimeException $e) {
            $message = $e->getMessage();
        }
    }
}

$requirements = $installer->requirements();
$languages = Lang::available();

/** Renders a text input with its label and error. */
$field = function (string $name, string $label, string $type = 'text', string $hint = '', string $extra = '') use ($input, $errors): string {
    $value = $type === 'password' ? '' : $input[$name];
    $invalid = isset($errors[$name]) ? ' is-invalid' : '';
    $html = '<div class="mb-3"><label class="form-label" for="f-' . e($name) . '">' . e($label) . '</label>'
        . '<input class="form-control' . $invalid . '" type="' . e($type) . '" id="f-' . e($name) . '" name="' . e($name)
        . '" value="' . e($value) . '" ' . $extra . '>';
    if (isset($errors[$name])) {
        $html .= '<div class="invalid-feedback">' . e($errors[$name]) . '</div>';
    }
    if ($hint !== '') {
        $html .= '<div class="form-text">' . e($hint) . '</div>';
    }
    return $html . '</div>';
};

?><!doctype html>
<html lang="<?= e(Lang::current()) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title><?= e(t('INSTALL_TITLE')) ?></title>
    <link rel="stylesheet" href="<?= e(Uri::media('vendor/bootstrap/css/bootstrap.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(Uri::media('vendor/bootstrap-icons/bootstrap-icons.min.css')) ?>">
</head>
<body class="bg-body-tertiary">
<div class="container py-4" style="max-width: 46rem">
    <div class="d-flex align-items-center justify-content-between mb-4">
        <h1 class="h3 mb-0"><i class="bi bi-boxes me-2 text-primary"></i><?= e(t('INSTALL_TITLE')) ?></h1>
        <div class="btn-group btn-group-sm" role="group" aria-label="<?= e(t('INSTALL_LANGUAGE')) ?>">
            <?php foreach ($languages as $tag => $meta): ?>
                <a class="btn btn-outline-secondary<?= $tag === Lang::current() ? ' active' : '' ?>" href="?lang=<?= e($tag) ?>"><?= e($meta['name']) ?></a>
            <?php endforeach ?>
        </div>
    </div>

<?php if ($view === 'done'): ?>
    <div class="card shadow-sm"><div class="card-body p-4 text-center">
        <i class="bi bi-check-circle text-success display-4"></i>
        <h2 class="h4 mt-3"><?= e(t('INSTALL_DONE_TITLE')) ?></h2>
        <p class="text-body-secondary"><?= e(t('INSTALL_DONE_TEXT')) ?></p>
        <a class="btn btn-outline-primary me-2" href="<?= e(Uri::root()) ?>"><?= e(t('INSTALL_OPEN_SITE')) ?></a>
        <a class="btn btn-primary" href="<?= e(Uri::admin()) ?>"><?= e(t('INSTALL_OPEN_ADMIN')) ?></a>
    </div></div>

<?php elseif ($view === 'locked'): ?>
    <div class="alert alert-warning"><?= e(t('INSTALL_ALREADY')) ?></div>

<?php else: ?>
    <p class="text-body-secondary"><?= e(t('INSTALL_INTRO')) ?></p>

    <?php if ($message !== ''): ?>
        <div class="alert alert-danger"><?= e($message) ?></div>
    <?php endif ?>

    <div class="card shadow-sm mb-4">
        <div class="card-header fw-semibold"><?= e(t('INSTALL_REQUIREMENTS')) ?></div>
        <ul class="list-group list-group-flush">
            <?php foreach ($requirements as $req): ?>
                <li class="list-group-item d-flex justify-content-between">
                    <span><i class="bi <?= $req['ok'] ? 'bi-check-lg text-success' : 'bi-x-lg text-danger' ?> me-2"></i><?= e($req['label']) ?></span>
                    <small class="text-body-secondary"><?= e($req['note']) ?></small>
                </li>
            <?php endforeach ?>
        </ul>
    </div>

    <form method="post" novalidate>
        <input type="hidden" name="token" value="<?= e($_SESSION['token']) ?>">

        <div class="card shadow-sm mb-4">
            <div class="card-header fw-semibold"><?= e(t('INSTALL_SITE')) ?></div>
            <div class="card-body">
                <?= $field('sitename', t('INSTALL_SITENAME'), 'text', '', 'required autofocus') ?>
                <div class="row">
                    <div class="col-sm-6 mb-3">
                        <label class="form-label" for="f-language"><?= e(t('INSTALL_SITE_LANGUAGE')) ?></label>
                        <select class="form-select" id="f-language" name="language">
                            <?php foreach ($languages as $tag => $meta): ?>
                                <option value="<?= e($tag) ?>"<?= $tag === $input['language'] ? ' selected' : '' ?>><?= e($meta['name']) ?></option>
                            <?php endforeach ?>
                        </select>
                        <div class="form-text"><?= e(t('INSTALL_SITE_LANGUAGE_HINT')) ?></div>
                    </div>
                    <div class="col-sm-6 mb-3">
                        <span class="form-label d-block"><?= e(t('INSTALL_SITE_LANGUAGES')) ?></span>
                        <?php foreach ($languages as $tag => $meta): ?>
                            <div class="form-check form-check-inline">
                                <input class="form-check-input" type="checkbox" id="f-lang-<?= e($tag) ?>" name="languages[]" value="<?= e($tag) ?>"<?= in_array($tag, $input['languages'], true) ? ' checked' : '' ?>>
                                <label class="form-check-label" for="f-lang-<?= e($tag) ?>"><?= e($meta['name']) ?></label>
                            </div>
                        <?php endforeach ?>
                    </div>
                </div>
                <label class="form-label" for="f-timezone"><?= e(t('INSTALL_TIMEZONE')) ?></label>
                <select class="form-select<?= isset($errors['timezone']) ? ' is-invalid' : '' ?>" id="f-timezone" name="timezone">
                    <?php foreach (DateTimeZone::listIdentifiers() as $tz): ?>
                        <option<?= $tz === $input['timezone'] ? ' selected' : '' ?>><?= e($tz) ?></option>
                    <?php endforeach ?>
                </select>
            </div>
        </div>

        <div class="card shadow-sm mb-4">
            <div class="card-header fw-semibold"><?= e(t('INSTALL_DATABASE')) ?></div>
            <div class="card-body">
                <div class="mb-3">
                    <?php foreach (['sqlite' => t('INSTALL_DB_SQLITE'), 'mysql' => t('INSTALL_DB_MYSQL')] as $type => $label): ?>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="dbtype" id="f-db-<?= $type ?>" value="<?= $type ?>"<?= $input['dbtype'] === $type ? ' checked' : '' ?>>
                            <label class="form-check-label" for="f-db-<?= $type ?>"><?= e($label) ?></label>
                        </div>
                    <?php endforeach ?>
                </div>
                <div id="mysql-fields"<?= $input['dbtype'] === 'mysql' ? '' : ' hidden' ?>>
                    <div class="row">
                        <div class="col-sm-8"><?= $field('host', t('INSTALL_DB_HOST')) ?></div>
                        <div class="col-sm-4"><?= $field('port', t('INSTALL_DB_PORT'), 'text', '', 'inputmode="numeric"') ?></div>
                        <div class="col-sm-6"><?= $field('user', t('INSTALL_DB_USER'), 'text', '', 'autocomplete="off"') ?></div>
                        <div class="col-sm-6"><?= $field('password', t('INSTALL_DB_PASSWORD'), 'password', '', 'autocomplete="new-password"') ?></div>
                    </div>
                    <?= $field('db', t('INSTALL_DB_NAME'), 'text', t('INSTALL_DB_NAME_HINT')) ?>
                </div>
                <?= $field('dbprefix', t('INSTALL_DB_PREFIX'), 'text', t('INSTALL_DB_PREFIX_HINT')) ?>
            </div>
        </div>

        <div class="card shadow-sm mb-4">
            <div class="card-header fw-semibold"><?= e(t('INSTALL_ADMIN')) ?></div>
            <div class="card-body">
                <div class="row">
                    <div class="col-sm-6"><?= $field('admin_name', t('INSTALL_ADMIN_NAME')) ?></div>
                    <div class="col-sm-6"><?= $field('admin_username', t('INSTALL_ADMIN_USERNAME'), 'text', '', 'autocomplete="username"') ?></div>
                </div>
                <?= $field('admin_email', t('INSTALL_ADMIN_EMAIL'), 'email', '', 'autocomplete="email"') ?>
                <div class="row">
                    <div class="col-sm-6"><?= $field('admin_password', t('INSTALL_ADMIN_PASSWORD'), 'password', '', 'autocomplete="new-password"') ?></div>
                    <div class="col-sm-6"><?= $field('admin_password2', t('INSTALL_ADMIN_PASSWORD2'), 'password', '', 'autocomplete="new-password"') ?></div>
                </div>
            </div>
        </div>

        <button class="btn btn-primary btn-lg w-100" type="submit"><?= e(t('INSTALL_SUBMIT')) ?></button>
    </form>
    <script>
        document.querySelectorAll('input[name=dbtype]').forEach(function (radio) {
            radio.addEventListener('change', function () {
                document.getElementById('mysql-fields').hidden = this.value !== 'mysql';
            });
        });
    </script>
<?php endif ?>

    <p class="text-center text-body-secondary small mt-4">Pageyard <?= e(PY_VERSION) ?></p>
</div>
</body>
</html>
