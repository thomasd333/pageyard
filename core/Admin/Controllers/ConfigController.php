<?php

namespace Pageyard\Admin\Controllers;

use Pageyard\Accounts;
use Pageyard\Admin\Controller;
use Pageyard\Auth;
use Pageyard\Config;
use Pageyard\Lang;
use Pageyard\Log;
use Pageyard\Mailer;
use Pageyard\Pages;
use Pageyard\PasswordReset;
use Pageyard\Request;
use Pageyard\Session;
use Pageyard\Settings;
use Pageyard\Site\Sitemap;
use Pageyard\Uri;

defined('PAGEYARD') or exit;

/** Global Configuration: the editable part of configuration.php. */
final class ConfigController extends Controller
{
    public const TASKS = ['apply', 'save', 'cancel', 'testmail'];

    public const TABS = ['site', 'lang', 'system', 'users', 'mail', 'db'];

    public string $icon = 'bi-gear';
    public string $active = 'config';

    /** The tab holding a field, from its error key. */
    public static function tabOf(string $field): string
    {
        return match (true) {
            $field === 'sitename', str_ends_with($field, '.meta_description') => 'site',
            in_array($field, ['language', 'languages', 'admin_language'], true) => 'lang',
            $field === 'contact_email', str_starts_with($field, 'mail_'), str_starts_with($field, 'smtp_') => 'mail',
            in_array($field, ['site_accounts', 'site_session_lifetime', 'privacy_page'], true), str_starts_with($field, 'registration') => 'users',
            default => 'system',
        };
    }

    /** The tab to open: the first with an error, else the one asked for (?tab=), else the first. */
    public static function openTab(array $errors, string $asked): string
    {
        $withErrors = array_intersect(self::TABS, array_map(self::tabOf(...), array_keys($errors)));
        return reset($withErrors) ?: (in_array($asked, self::TABS, true) ? $asked : self::TABS[0]);
    }

    public function display(array $values = [], array $errors = []): void
    {
        $this->title = t('CONFIG_TITLE');
        $this->button('config.apply', t('TOOLBAR_SAVE'), 'bi-check-lg', 'btn-success');
        $this->button('config.save', t('TOOLBAR_SAVE_CLOSE'), 'bi-check2-all', 'btn-outline-success');
        $this->button('config.cancel', t('TOOLBAR_CLOSE'), 'bi-x-lg', 'btn-outline-danger', novalidate: true);
        if ($errors) {
            Session::flash(t('ERR_FORM'), 'danger');
        }
        $descriptions = $values['meta_description'] ?? Settings::get('meta_description', []);
        unset($values['meta_description']);
        $this->render('config', [
            'cfg'       => $values + Config::all(),
            'descriptions' => is_array($descriptions) ? $descriptions : [],
            'sitemap'   => Uri::absolute(Sitemap::url()),
            'errors'    => $errors,
            'open'      => self::openTab($errors, Request::str('tab')),
            'errorTabs' => array_map(self::tabOf(...), array_keys($errors)),
            'languages' => Lang::available(),
            'writable'  => is_writable(Config::file()),
            'pages'     => Pages::options((string) Config::get('language')),
            'mailReady' => PasswordReset::available(),
        ]);
    }

    public function apply(): void
    {
        $this->store();
        $this->redirect('view=config&tab=' . self::openTab([], Request::str('tab')));
    }

    public function save(): void
    {
        $this->store();
        $this->redirect();
    }

    /** Saves the form and sends a test message to the logged-in user. */
    public function testmail(): void
    {
        $this->store();
        $email = (string) Auth::user()['email'];
        $site = (string) Config::get('sitename');
        $error = Mailer::send($email, t('MAIL_TEST_SUBJECT', $site), t('MAIL_TEST_BODY', $site));
        $error === null ? Session::flash(t('MAIL_TEST_SENT', $email)) : Session::flash(t('MAIL_TEST_FAILED', $error), 'danger');
        $this->redirect('view=config&tab=mail');
    }

    public function cancel(): void
    {
        $this->redirect();
    }

    private function store(): void
    {
        // The history's fields are disabled while it is off, so they aren't posted: their values stay.
        $historyOn = Request::bool('history_enabled');
        // Likewise the account options while accounts (or registration) are off.
        $accountsOn = Request::bool('site_accounts');
        $registrationOn = $accountsOn ? Request::bool('registration') : (bool) Config::get('registration', false);
        $keep = static fn (string $name, bool $posted, mixed $value): mixed => $posted ? $value : Config::get($name);
        $in = [
            'sitename'         => Request::str('sitename'),
            'offline'          => Request::bool('offline'),
            'language'         => Request::str('language'),
            'languages'        => Request::list('languages'),
            'admin_language'   => Request::str('admin_language'),
            'timezone'         => Request::str('timezone'),
            'live_site'        => rtrim(Request::str('live_site'), '/'),
            'sef_rewrite'      => Request::bool('sef_rewrite'),
            'robots'           => Request::bool('robots_hide') ? 'noindex' : 'index',
            'sitename_title'   => Request::str('sitename_title'),
            'blog_text'        => Request::str('blog_text') === 'excerpt' ? 'excerpt' : 'full',
            'debug'            => Request::bool('debug'),
            'session_lifetime' => Request::int('session_lifetime'),
            'image_max'        => Request::int('image_max'),
            'image_quality'    => Request::int('image_quality'),
            'image_webp'       => Request::bool('image_webp'),
            'history_enabled'  => $historyOn,
            'history_days'     => $historyOn ? Request::int('history_days') : (int) Config::get('history_days', 7),
            'history_interval' => $historyOn ? Request::int('history_interval') : (int) Config::get('history_interval', 10),
            'site_accounts'    => $accountsOn,
            'site_session_lifetime' => (int) $keep('site_session_lifetime', $accountsOn, Request::int('site_session_lifetime')),
            'registration'     => $registrationOn,
            'registration_activation' => (string) $keep('registration_activation', $accountsOn && $registrationOn, Request::str('registration_activation')),
            'registration_notify' => (bool) $keep('registration_notify', $accountsOn && $registrationOn, Request::bool('registration_notify')),
            'privacy_page'     => (int) $keep('privacy_page', $accountsOn && $registrationOn, Request::int('privacy_page')),
            'mail_transport'   => Request::str('mail_transport'),
            'mail_from'        => Request::str('mail_from'),
            'mail_from_name'   => Request::str('mail_from_name'),
            'contact_email'    => Request::str('contact_email'),
            'smtp_host'        => Request::str('smtp_host'),
            'smtp_port'        => Request::int('smtp_port'),
            'smtp_secure'      => Request::str('smtp_secure'),
            'smtp_user'        => Request::str('smtp_user'),
        ];
        // An empty field keeps the password, unless the login was removed too.
        $password = Request::raw('smtp_password');
        $in['smtp_password'] = $password !== '' ? $password : ($in['smtp_user'] !== '' ? (string) Config::get('smtp_password', '') : '');

        $err = [];
        if ($in['sitename'] === '') {
            $err['sitename'] = t('ERR_REQUIRED');
        }
        if (!Lang::exists($in['language'])) {
            $err['language'] = t('ERR_REQUIRED');
        }
        if (!Lang::exists($in['admin_language'])) {
            $err['admin_language'] = t('ERR_REQUIRED');
        }
        if (!in_array($in['timezone'], \DateTimeZone::listIdentifiers(), true)) {
            $err['timezone'] = t('ERR_REQUIRED');
        }
        if ($in['live_site'] !== '' && !preg_match('~^https?://[^\s/]+(/\S*)?$~', $in['live_site'])) {
            $err['live_site'] = t('CONFIG_ERR_LIVE_SITE');
        }
        if ($in['image_max'] !== 0 && ($in['image_max'] < 400 || $in['image_max'] > 8000)) {
            $err['image_max'] = t('CONFIG_ERR_IMAGE_MAX');
        }
        if ($in['image_quality'] < 40 || $in['image_quality'] > 100) {
            $err['image_quality'] = t('ERR_RANGE', 40, 100);
        }
        if ($historyOn && ($in['history_days'] < 1 || $in['history_days'] > 365)) {
            $err['history_days'] = t('ERR_RANGE', 1, 365);
        }
        if ($historyOn && ($in['history_interval'] < 0 || $in['history_interval'] > 1440)) {
            $err['history_interval'] = t('ERR_RANGE', 0, 1440);
        }
        if ($in['session_lifetime'] < 1 || $in['session_lifetime'] > 1440) {
            $err['session_lifetime'] = t('CONFIG_ERR_SESSION');
        }

        if ($in['site_session_lifetime'] < 5 || $in['site_session_lifetime'] > 43200) {
            $err['site_session_lifetime'] = t('ERR_RANGE', 5, 43200);
        }
        if (!in_array($in['registration_activation'], Accounts::ACTIVATIONS, true)) {
            $in['registration_activation'] = 'email';
        }
        if ($in['privacy_page'] !== 0 && Pages::find($in['privacy_page']) === null) {
            $err['privacy_page'] = t('ERR_REQUIRED');
        }

        if (!in_array($in['mail_transport'], Mailer::TRANSPORTS, true)) {
            $in['mail_transport'] = 'off';
        }
        if (!in_array($in['smtp_secure'], Mailer::SECURE, true)) {
            $in['smtp_secure'] = 'tls';
        }
        if ($in['mail_transport'] !== 'off' && !filter_var($in['mail_from'], FILTER_VALIDATE_EMAIL)) {
            $err['mail_from'] = t('MAIL_ERR_FROM');
        }
        if ($in['contact_email'] !== '' && !filter_var($in['contact_email'], FILTER_VALIDATE_EMAIL)) {
            $err['contact_email'] = t('MAIL_ERR_FROM');
        }
        if ($in['mail_transport'] === 'smtp') {
            if ($in['smtp_host'] === '') {
                $err['smtp_host'] = t('ERR_REQUIRED');
            }
            if ($in['smtp_port'] < 1 || $in['smtp_port'] > 65535) {
                $err['smtp_port'] = t('ERR_RANGE', 1, 65535);
            }
        }

        if (!in_array($in['sitename_title'], ['after', 'before', 'none'], true)) {
            $in['sitename_title'] = 'after';
        }
        $descriptions = [];
        foreach (Request::translations() as $lang => $fields) {
            $text = trim((string) ($fields['meta_description'] ?? ''));
            if ($text === '' || !Lang::exists((string) $lang)) {
                continue;
            }
            if (mb_strlen($text) > 300) {
                $err["$lang.meta_description"] = t('ERR_TOO_LONG', 300);
            }
            $descriptions[$lang] = $text;
        }

        $languages = array_values(array_filter($in['languages'], [Lang::class, 'exists']));
        $in['languages'] = implode(',', array_unique([$in['language'], ...$languages]));

        if ($err) {
            unset($in['smtp_password']);
            $this->display($in + ['meta_description' => $descriptions], $err);
            exit;
        }
        try {
            Config::write(array_merge(Config::all(), $in));
            Settings::set('meta_description', $descriptions);
            if ($in['mail_transport'] !== 'off') {
                PasswordReset::rememberOrigin();
            }
        } catch (\RuntimeException $e) {
            Session::flash($e->getMessage(), 'danger');
            $this->display($in);
            exit;
        }
        Log::info('Global configuration saved.');
        Session::flash(t('CONFIG_SAVED'));
    }
}
