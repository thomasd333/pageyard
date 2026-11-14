<?php
/**
 * Global Configuration.
 * @var array $cfg
 * @var array<string, string> $errors
 * @var string $open the tab to show
 * @var list<string> $errorTabs tabs with errors
 * @var array<string, array{name: string, locale: string}> $languages
 * @var bool $writable
 * @var array<string, string> $descriptions lang => default meta description
 * @var string $sitemap absolute URL of sitemap.xml
 */

use Pageyard\Admin\Form;
use Pageyard\Uri;

defined('PAGEYARD') or exit;

$names = array_map(fn ($m) => $m['name'], $languages);
$enabled = is_array($cfg['languages']) ? $cfg['languages'] : explode(',', (string) $cfg['languages']);
$timezones = array_combine(DateTimeZone::listIdentifiers(), DateTimeZone::listIdentifiers());
$tab = static fn (string $id, string $icon, string $label) =>
    '<li class="nav-item" role="presentation"><button class="nav-link' . ($open === $id ? ' active' : '') . (in_array($id, $errorTabs, true) ? ' text-danger' : '') . '" id="tab-' . $id
    . '" data-bs-toggle="tab" data-bs-target="#pane-' . $id . '" type="button" role="tab" aria-controls="pane-' . $id
    . '" aria-selected="' . ($open === $id ? 'true' : 'false') . '"><i class="bi ' . $icon . ' me-2" aria-hidden="true"></i>' . e($label)
    . (in_array($id, $errorTabs, true) ? ' <i class="bi bi-exclamation-circle-fill ms-1" aria-hidden="true"></i>' : '') . '</button></li>';
$pane = static fn (string $id): string => 'tab-pane fade' . ($open === $id ? ' show active' : '');
?>
<?php if (!$writable): ?>
    <div class="alert alert-warning"><i class="bi bi-exclamation-triangle me-2" aria-hidden="true"></i><?= e(t('CONFIG_NOT_WRITABLE')) ?></div>
<?php endif ?>

<form id="adminForm" method="post" action="<?= e(Uri::admin()) ?>" novalidate data-track-changes>
    <?= Form::token() ?>
    <input type="hidden" name="tab" value="<?= e($open) ?>">
    <ul class="nav nav-tabs py-tabs" role="tablist" data-remember-tab>
        <?= $tab('site', 'bi-globe', t('CONFIG_TAB_SITE')) ?>
        <?= $tab('lang', 'bi-translate', t('CONFIG_TAB_LANGUAGES')) ?>
        <?= $tab('system', 'bi-sliders', t('CONFIG_TAB_SYSTEM')) ?>
        <?= $tab('users', 'bi-people', t('CONFIG_TAB_USERS')) ?>
        <?= $tab('mail', 'bi-envelope', t('CONFIG_TAB_MAIL')) ?>
        <?= $tab('db', 'bi-database', t('CONFIG_TAB_DATABASE')) ?>
    </ul>
    <div class="card py-card py-card-tabbed">
        <div class="card-body tab-content">
            <div class="<?= $pane('site') ?>" id="pane-site" role="tabpanel" aria-labelledby="tab-site">
                <div class="row">
                    <div class="col-lg-6">
                        <?= Form::text('sitename', t('CONFIG_SITENAME'), $cfg['sitename'], $errors, '', 'text', 'required') ?>
                        <?= Form::toggle('offline', t('CONFIG_OFFLINE'), (bool) $cfg['offline'], $errors, t('CONFIG_OFFLINE_HINT')) ?>
                        <?= Form::select('blog_text', t('CONFIG_BLOG_TEXT'), [
                            'full' => t('CONFIG_BLOG_TEXT_FULL'), 'excerpt' => t('CONFIG_BLOG_TEXT_EXCERPT'),
                        ], $cfg['blog_text'], $errors, t('CONFIG_BLOG_TEXT_HINT')) ?>
                    </div>
                    <div class="col-lg-6">
                        <fieldset>
                            <legend class="fs-6 fw-semibold mb-3"><?= e(t('CONFIG_SEO')) ?></legend>
                            <?= Form::select('sitename_title', t('CONFIG_TITLE_SITENAME'), [
                                'after' => t('CONFIG_TITLE_AFTER'), 'before' => t('CONFIG_TITLE_BEFORE'), 'none' => t('CONFIG_TITLE_NONE'),
                            ], $cfg['sitename_title'], $errors, t('CONFIG_TITLE_SITENAME_HINT')) ?>
                            <?php foreach ($enabled as $tag): if (!isset($names[$tag])) continue; ?>
                                <?= Form::trArea($tag, 'meta_description', t('CONFIG_META_DESCRIPTION') . (count($enabled) > 1 ? ' (' . $names[$tag] . ')' : ''),
                                    $descriptions[$tag] ?? '', $errors, $tag === $enabled[0] ? t('CONFIG_META_DESCRIPTION_HINT') : '', 0, 'rows="2" maxlength="300"') ?>
                            <?php endforeach ?>
                            <?= Form::toggle('robots_hide', t('CONFIG_ROBOTS_HIDE'), $cfg['robots'] === 'noindex', $errors, t('CONFIG_ROBOTS_HIDE_HINT')) ?>
                            <p class="small mb-0">
                                <span class="text-body-secondary"><?= e(t('CONFIG_SITEMAP')) ?>:</span>
                                <a href="<?= e($sitemap) ?>" target="_blank" rel="noopener"><?= e($sitemap) ?></a>
                                <span class="d-block form-text"><?= e(t('CONFIG_SITEMAP_HINT')) ?></span>
                            </p>
                        </fieldset>
                    </div>
                </div>
            </div>

            <div class="<?= $pane('lang') ?>" id="pane-lang" role="tabpanel" aria-labelledby="tab-lang">
                <div class="row">
                    <div class="col-lg-6">
                        <?= Form::select('language', t('CONFIG_LANGUAGE'), $names, $cfg['language'], $errors, t('CONFIG_LANGUAGE_HINT')) ?>
                        <fieldset class="mb-3">
                            <legend class="form-label fs-6"><?= e(t('CONFIG_LANGUAGES')) ?></legend>
                            <?php foreach ($names as $tag => $name): ?>
                                <div class="form-check form-check-inline">
                                    <input class="form-check-input" type="checkbox" name="languages[]" value="<?= e($tag) ?>" id="f-lang-<?= e($tag) ?>"<?= in_array($tag, $enabled, true) ? ' checked' : '' ?>>
                                    <label class="form-check-label" for="f-lang-<?= e($tag) ?>"><?= e($name) ?></label>
                                </div>
                            <?php endforeach ?>
                            <div class="form-text"><?= e(t('CONFIG_LANGUAGES_HINT')) ?></div>
                        </fieldset>
                        <?= Form::select('admin_language', t('CONFIG_ADMIN_LANGUAGE'), $names, $cfg['admin_language'], $errors, t('CONFIG_ADMIN_LANGUAGE_HINT')) ?>
                    </div>
                </div>
            </div>

            <div class="<?= $pane('system') ?>" id="pane-system" role="tabpanel" aria-labelledby="tab-system">
                <div class="row">
                    <div class="col-lg-6">
                        <?= Form::select('timezone', t('CONFIG_TIMEZONE'), $timezones, $cfg['timezone'], $errors) ?>
                        <?= Form::text('session_lifetime', t('CONFIG_SESSION'), $cfg['session_lifetime'], $errors, t('CONFIG_SESSION_HINT'), 'number', 'min="1" max="1440"') ?>
                        <?= Form::toggle('sef_rewrite', t('CONFIG_SEF'), (bool) $cfg['sef_rewrite'], $errors, t('CONFIG_SEF_HINT')) ?>
                        <?= Form::text('live_site', t('CONFIG_LIVE_SITE'), $cfg['live_site'], $errors, t('CONFIG_LIVE_SITE_HINT'), 'url', 'placeholder="https://example.com"') ?>
                        <?= Form::toggle('debug', t('CONFIG_DEBUG'), (bool) $cfg['debug'], $errors, t('CONFIG_DEBUG_HINT')) ?>
                    </div>
                    <div class="col-lg-6">
                        <fieldset>
                            <legend class="fs-6 fw-semibold mb-3"><?= e(t('CONFIG_IMAGES')) ?></legend>
                            <?= Form::text('image_max', t('CONFIG_IMAGE_MAX'), $cfg['image_max'], $errors, t('CONFIG_IMAGE_MAX_HINT'), 'number', 'min="0" max="8000" step="100"') ?>
                            <?= Form::text('image_quality', t('CONFIG_IMAGE_QUALITY'), $cfg['image_quality'], $errors, t('CONFIG_IMAGE_QUALITY_HINT'), 'number', 'min="40" max="100"') ?>
                            <?= Form::toggle('image_webp', t('CONFIG_IMAGE_WEBP'), (bool) $cfg['image_webp'], $errors, t(Pageyard\Images::webpAvailable() ? 'CONFIG_IMAGE_WEBP_HINT' : 'CONFIG_IMAGE_WEBP_NONE'), !Pageyard\Images::webpAvailable()) ?>
                            <?php if (!Pageyard\Images::available()): ?>
                                <div class="alert alert-warning small"><?= e(t('CONFIG_IMAGES_NO_GD')) ?></div>
                            <?php endif ?>
                        </fieldset>
                        <fieldset>
                            <legend class="fs-6 fw-semibold mb-3"><?= e(t('CONFIG_HISTORY')) ?></legend>
                            <?php
                            // history_days = 0 is how older versions switched the history off.
                            $badDays = isset($errors['history_days']);
                            $historyOn = $cfg['history_enabled'] && ((int) $cfg['history_days'] > 0 || $badDays);
                            ?>
                            <?= Form::toggle('history_enabled', t('CONFIG_HISTORY_ENABLED'), (bool) $historyOn, $errors, t('CONFIG_HISTORY_ENABLED_HINT')) ?>
                            <fieldset class="py-dependent" data-enable-if="history_enabled:1"<?= $historyOn ? '' : ' disabled' ?>>
                                <?= Form::text('history_days', t('CONFIG_HISTORY_DAYS'), $badDays ? $cfg['history_days'] : ((int) $cfg['history_days'] ?: 7), $errors, t('CONFIG_HISTORY_DAYS_HINT'), 'number', 'min="1" max="365"') ?>
                                <?= Form::text('history_interval', t('CONFIG_HISTORY_INTERVAL'), $cfg['history_interval'], $errors, t('CONFIG_HISTORY_INTERVAL_HINT'), 'number', 'min="0" max="1440"') ?>
                            </fieldset>
                        </fieldset>
                    </div>
                </div>
            </div>

            <div class="<?= $pane('users') ?>" id="pane-users" role="tabpanel" aria-labelledby="tab-users">
                <p class="text-body-secondary"><?= e(t('CONFIG_USERS_INTRO')) ?></p>
                <div class="row">
                    <div class="col-lg-6">
                        <?= Form::toggle('site_accounts', t('CONFIG_SITE_ACCOUNTS'), (bool) $cfg['site_accounts'], $errors, t('CONFIG_SITE_ACCOUNTS_HINT')) ?>
                        <fieldset class="py-dependent" data-enable-if="site_accounts:1"<?= $cfg['site_accounts'] ? '' : ' disabled' ?>>
                            <?= Form::text('site_session_lifetime', t('CONFIG_SITE_SESSION'), $cfg['site_session_lifetime'], $errors, t('CONFIG_SITE_SESSION_HINT'), 'number', 'min="5" max="43200"') ?>
                            <?= Form::toggle('registration', t('CONFIG_REGISTRATION'), (bool) $cfg['registration'], $errors, t('CONFIG_REGISTRATION_HINT')) ?>
                            <?php if (!$mailReady): ?>
                                <div class="alert alert-warning small"><?= e(t('CONFIG_REGISTRATION_NO_MAIL')) ?></div>
                            <?php endif ?>
                        </fieldset>
                    </div>
                    <div class="col-lg-6">
                        <fieldset class="py-dependent" data-enable-if="site_accounts:1;registration:1"<?= $cfg['site_accounts'] && $cfg['registration'] ? '' : ' disabled' ?>>
                            <?= Form::select('registration_activation', t('CONFIG_ACTIVATION'), [
                                'email' => t('CONFIG_ACTIVATION_EMAIL'), 'admin' => t('CONFIG_ACTIVATION_ADMIN'), 'both' => t('CONFIG_ACTIVATION_BOTH'),
                            ], $cfg['registration_activation'], $errors, t('CONFIG_ACTIVATION_HINT')) ?>
                            <?= Form::toggle('registration_notify', t('CONFIG_REGISTRATION_NOTIFY'), (bool) $cfg['registration_notify'], $errors, t('CONFIG_REGISTRATION_NOTIFY_HINT')) ?>
                            <?= Form::select('privacy_page', t('CONFIG_PRIVACY_PAGE'), ['0' => t('CONFIG_PRIVACY_NONE')] + $pages, $cfg['privacy_page'], $errors, t('CONFIG_PRIVACY_PAGE_HINT')) ?>
                        </fieldset>
                    </div>
                </div>
                <p class="small text-body-secondary mb-0"><?= e(t('CONFIG_ACCOUNTS_ADDRESSES')) ?>
                    <code><?= e(Pageyard\Site\Account::url('login', (string) Pageyard\Config::get('language'))) ?></code>,
                    <code><?= e(Pageyard\Site\Account::url('register', (string) Pageyard\Config::get('language'))) ?></code>,
                    <code><?= e(Pageyard\Site\Account::url('', (string) Pageyard\Config::get('language'))) ?></code>
                </p>
            </div>

            <div class="<?= $pane('mail') ?>" id="pane-mail" role="tabpanel" aria-labelledby="tab-mail">
                <p class="text-body-secondary"><?= e(t('MAIL_INTRO')) ?></p>
                <div class="row">
                    <div class="col-lg-6">
                        <?= Form::select('mail_transport', t('MAIL_TRANSPORT'), ['off' => t('MAIL_TRANSPORT_OFF'), 'mail' => t('MAIL_TRANSPORT_MAIL'), 'smtp' => t('MAIL_TRANSPORT_SMTP')], $cfg['mail_transport'], $errors) ?>
                        <div data-hide-if="mail_transport:off">
                            <?= Form::text('mail_from', t('MAIL_FROM'), $cfg['mail_from'], $errors, t('MAIL_FROM_HINT'), 'email') ?>
                            <?= Form::text('mail_from_name', t('MAIL_FROM_NAME'), $cfg['mail_from_name'], $errors) ?>
                            <?= Form::text('contact_email', t('MAIL_CONTACT'), $cfg['contact_email'], $errors, t('MAIL_CONTACT_HINT'), 'email') ?>
                        </div>
                    </div>
                    <div class="col-lg-6" data-show-if="mail_transport:smtp">
                        <div class="row">
                            <div class="col-8"><?= Form::text('smtp_host', t('MAIL_SMTP_HOST'), $cfg['smtp_host'], $errors, '', 'text', 'placeholder="smtp.example.com"') ?></div>
                            <div class="col-4"><?= Form::text('smtp_port', t('MAIL_SMTP_PORT'), $cfg['smtp_port'], $errors, '', 'number', 'min="1" max="65535"') ?></div>
                        </div>
                        <?= Form::select('smtp_secure', t('MAIL_SMTP_SECURE'), ['tls' => 'STARTTLS', 'ssl' => 'SSL', 'none' => t('MAIL_SECURE_NONE')], $cfg['smtp_secure'], $errors, t('MAIL_SMTP_PORT_HINT')) ?>
                        <?= Form::text('smtp_user', t('MAIL_SMTP_USER'), $cfg['smtp_user'], $errors, t('MAIL_SMTP_USER_HINT'), 'text', 'autocomplete="off"') ?>
                        <?= Form::text('smtp_password', t('MAIL_SMTP_PASSWORD'), '', $errors, t('MAIL_SMTP_PASSWORD_HINT'), 'password', 'autocomplete="new-password"') ?>
                    </div>
                </div>
                <div data-hide-if="mail_transport:off">
                    <button class="btn btn-outline-primary" type="submit" name="task" value="config.testmail"><i class="bi bi-send me-2" aria-hidden="true"></i><?= e(t('MAIL_TEST')) ?></button>
                    <div class="form-text"><?= e(t('MAIL_TEST_HINT', (string) (Pageyard\Auth::user()['email'] ?? ''))) ?></div>
                </div>
            </div>

            <div class="<?= $pane('db') ?>" id="pane-db" role="tabpanel" aria-labelledby="tab-db">
                <p class="text-body-secondary"><?= e(t('CONFIG_DB_READONLY')) ?></p>
                <dl class="row mb-0">
                    <dt class="col-sm-3 fw-normal text-body-secondary"><?= e(t('CONFIG_DB_TYPE')) ?></dt>
                    <dd class="col-sm-9"><?= e($cfg['dbtype'] === 'sqlite' ? 'SQLite' : 'MySQL') ?></dd>
                    <?php if ($cfg['dbtype'] === 'sqlite'): ?>
                        <dt class="col-sm-3 fw-normal text-body-secondary"><?= e(t('CONFIG_DB_FILE')) ?></dt>
                        <dd class="col-sm-9"><code><?= e($cfg['db_path']) ?></code></dd>
                    <?php else: ?>
                        <dt class="col-sm-3 fw-normal text-body-secondary"><?= e(t('CONFIG_DB_HOST')) ?></dt>
                        <dd class="col-sm-9"><?= e($cfg['host'] . ':' . $cfg['port']) ?></dd>
                        <dt class="col-sm-3 fw-normal text-body-secondary"><?= e(t('CONFIG_DB_NAME')) ?></dt>
                        <dd class="col-sm-9"><?= e($cfg['db']) ?></dd>
                    <?php endif ?>
                    <dt class="col-sm-3 fw-normal text-body-secondary"><?= e(t('CONFIG_DB_PREFIX')) ?></dt>
                    <dd class="col-sm-9"><code><?= e($cfg['dbprefix']) ?></code></dd>
                </dl>
            </div>
        </div>
    </div>
</form>
