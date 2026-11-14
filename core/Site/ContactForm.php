<?php

namespace Pageyard\Site;

use Pageyard\Config;
use Pageyard\Log;
use Pageyard\Mailer;
use Pageyard\RateLimit;
use Pageyard\Request;
use Pageyard\Uri;

defined('PAGEYARD') or exit;

/**
 * Contact form, embedded with {script built-in/contact}. Messages are only e-mailed, not stored.
 *
 * Spam protection without a CAPTCHA or cookies: a field people don't see, a signed time in the form
 * (too fast or too old doesn't count), and a limit per address.
 */
final class ContactForm
{
    public const MIN_SECONDS = 3;
    public const MAX_SECONDS = 86400;
    public const LIMIT_PER_HOUR = 5;
    private const MAX_MESSAGE = 5000;

    /** Tests: replaces the clock. */
    public static ?int $now = null;

    /** @param array<string, string|true> $params to, subject, consent */
    public static function render(array $params): string
    {
        $text = static fn (string $key): string => is_string($params[$key] ?? null) ? trim($params[$key]) : '';
        $to = $text('to') !== '' ? $text('to') : (string) (Config::get('contact_email', '') ?: Config::get('mail_from', ''));
        if (!Mailer::enabled() || filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            Log::error('Contact form: e-mail is not set up (Global Configuration > E-mail).');
            return '<div class="alert alert-warning py-contact" role="alert">' . e(t('CONTACT_UNAVAILABLE')) . '</div>';
        }
        if (Request::str('contact') === 'sent' && !Request::isPost()) {
            return '<div class="alert alert-success py-contact" id="py-contact" role="alert">' . e(t('CONTACT_SENT')) . '</div>';
        }

        $values = ['name' => '', 'email' => '', 'message' => '', 'consent' => false];
        $errors = [];
        $general = '';
        if (Request::isPost() && Request::has('py_contact')) {
            $values = ['name' => Request::str('name'), 'email' => Request::str('email'),
                'message' => Request::str('message'), 'consent' => Request::bool('consent')];
            $result = self::handle($values, $to, $text('subject'), $text('consent') !== '');
            if ($result === true) {
                if (!headers_sent()) {
                    $path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
                    Uri::redirect($path . '?contact=sent#py-contact');
                }
                return '<div class="alert alert-success py-contact" id="py-contact" role="alert">' . e(t('CONTACT_SENT')) . '</div>';
            }
            [$errors, $general] = $result;
        }
        return self::form($values, $errors, $general, $text('consent'));
    }

    /**
     * @return true|array{array<string, string>, string} sent, or [field errors, general error]
     */
    private static function handle(array $values, string $to, string $subject, bool $needsConsent): true|array
    {
        // A filled trap: the sender is told it worked and nothing is sent.
        if (Request::str('website') !== '') {
            return true;
        }
        $errors = [];
        if ($values['name'] === '') {
            $errors['name'] = t('ERR_REQUIRED');
        } elseif (mb_strlen($values['name']) > 100) {
            $errors['name'] = t('ERR_TOO_LONG', 100);
        }
        if (filter_var($values['email'], FILTER_VALIDATE_EMAIL) === false) {
            $errors['email'] = t('CONTACT_ERR_EMAIL');
        }
        if ($values['message'] === '') {
            $errors['message'] = t('ERR_REQUIRED');
        } elseif (mb_strlen($values['message']) > self::MAX_MESSAGE) {
            $errors['message'] = t('ERR_TOO_LONG', self::MAX_MESSAGE);
        }
        if ($needsConsent && !$values['consent']) {
            $errors['consent'] = t('ERR_REQUIRED');
        }
        if ($errors) {
            return [$errors, ''];
        }
        if (!self::timeIsValid(Request::str('py_contact'))) {
            return [[], t('CONTACT_ERR_TIME')];
        }
        if (!RateLimit::hit('contact', Request::ip(), self::LIMIT_PER_HOUR, 60)) {
            return [[], t('CONTACT_ERR_LIMIT')];
        }

        $site = (string) Config::get('sitename');
        $address = Uri::absolute((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH));
        $body = t('CONTACT_MAIL_BODY', $site, $address, $values['name'], $values['email'], $values['message']);
        $error = Mailer::send($to, $subject !== '' ? $subject : t('CONTACT_MAIL_SUBJECT', $site, $values['name']), $body, $values['email']);
        return $error === null ? true : [[], t('CONTACT_ERR_SEND')];
    }

    /** "<time>.<signature>" for a form ($form: contact, register). */
    public static function stamp(?int $time = null, string $form = 'contact'): string
    {
        $time ??= self::$now ?? time();
        return $time . '.' . hash_hmac('sha256', "$form|$time", (string) Config::get('secret', ''));
    }

    public static function timeIsValid(string $stamp, string $form = 'contact'): bool
    {
        [$time, $signature] = array_pad(explode('.', $stamp, 2), 2, '');
        if (!ctype_digit($time) || !hash_equals(self::stamp((int) $time, $form), $stamp) || $signature === '') {
            return false;
        }
        $age = (self::$now ?? time()) - (int) $time;
        return $age >= self::MIN_SECONDS && $age <= self::MAX_SECONDS;
    }

    private static function form(array $values, array $errors, string $general, string $consent): string
    {
        $field = static function (string $name, string $label, string $control) use ($errors): string {
            $error = isset($errors[$name]) ? '<div class="invalid-feedback d-block">' . e($errors[$name]) . '</div>' : '';
            return '<div class="mb-3"><label class="form-label" for="py-contact-' . $name . '">' . e($label) . '</label>' . $control . $error . '</div>';
        };
        $invalid = static fn (string $name): string => isset($errors[$name]) ? ' is-invalid' : '';
        $action = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);

        $html = '<form class="py-contact" id="py-contact" method="post" action="' . e($action) . '#py-contact" novalidate>';
        if ($general !== '') {
            $html .= '<div class="alert alert-danger" role="alert">' . e($general) . '</div>';
        }
        $html .= '<input type="hidden" name="py_contact" value="' . e(self::stamp()) . '">'
            // The trap: off-screen for people, attractive to bots.
            . '<div style="position:absolute;left:-10000px" aria-hidden="true"><label>Website<input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>'
            . $field('name', t('CONTACT_NAME'), '<input class="form-control' . $invalid('name') . '" id="py-contact-name" name="name" maxlength="100" autocomplete="name" required value="' . e($values['name']) . '">')
            . $field('email', t('CONTACT_EMAIL'), '<input class="form-control' . $invalid('email') . '" type="email" id="py-contact-email" name="email" autocomplete="email" required value="' . e($values['email']) . '">')
            . $field('message', t('CONTACT_MESSAGE'), '<textarea class="form-control' . $invalid('message') . '" id="py-contact-message" name="message" rows="6" maxlength="' . self::MAX_MESSAGE . '" required>' . e($values['message']) . '</textarea>');
        if ($consent !== '') {
            $html .= '<div class="form-check mb-3"><input class="form-check-input' . $invalid('consent') . '" type="checkbox" id="py-contact-consent" name="consent" value="1"' . ($values['consent'] ? ' checked' : '') . ' required>'
                . '<label class="form-check-label" for="py-contact-consent">' . e($consent) . '</label>'
                . (isset($errors['consent']) ? '<div class="invalid-feedback d-block">' . e($errors['consent']) . '</div>' : '') . '</div>';
        }
        return $html . '<button class="btn btn-primary" type="submit">' . e(t('CONTACT_SEND')) . '</button></form>';
    }
}
