<?php

namespace Pageyard\Admin\Controllers;

use Pageyard\Admin\Controller;
use Pageyard\Log;
use Pageyard\Media;
use Pageyard\Request;
use Pageyard\Revisions;
use Pageyard\Session;
use Pageyard\Settings;
use Pageyard\Site\App;
use Pageyard\Uri;

defined('PAGEYARD') or exit;

/** System > Template: layout options of the site template and the custom CSS. */
final class TemplateController extends Controller
{
    public const TASKS = ['apply', 'save', 'cancel'];
    public const MAX_CSS = 200_000;
    public const MAX_CODE = 50_000;
    public const TABS = ['layout', 'look', 'css', 'code', 'files'];

    public string $icon = 'bi-palette';
    public string $active = 'template';

    /** The tab holding a field, from its error key. */
    public static function tabOf(string $field): string
    {
        return match (true) {
            $field === 'custom_css' => 'css',
            str_starts_with($field, 'code_') => 'code',
            $field === 'show_left', $field === 'show_right', $field === 'layout', str_ends_with($field, '_width') => 'layout',
            default => 'look',
        };
    }

    /** The tab to open: the first with an error, else the one asked for (?tab=), else the first. */
    public static function openTab(array $errors, string $asked): string
    {
        $withErrors = array_intersect(self::TABS, array_map(self::tabOf(...), array_keys($errors)));
        return reset($withErrors) ?: (in_array($asked, self::TABS, true) ? $asked : self::TABS[0]);
    }

    public function display(?array $options = null, ?string $css = null, array $errors = [], ?array $code = null): void
    {
        $this->title = t('TEMPLATE_TITLE');
        $this->button('template.apply', t('TOOLBAR_SAVE'), 'bi-check-lg', 'btn-success');
        $this->button('template.save', t('TOOLBAR_SAVE_CLOSE'), 'bi-check2-all', 'btn-outline-success');
        $this->button('template.cancel', t('TOOLBAR_CLOSE'), 'bi-x-lg', 'btn-outline-danger', novalidate: true);
        if (Revisions::linked('css', 0)) {
            $this->link(Uri::admin('view=history&type=css&id=0'), t('HISTORY_TOOLBAR_CSS', Revisions::count('css', 0)), 'bi-clock-history', newTab: false);
        }
        if ($errors) {
            Session::flash(t('ERR_FORM'), 'danger');
        }
        $this->render('template', [
            'o'         => $options ?? Settings::template(),
            'css'       => $css ?? (string) Settings::get('custom_css', ''),
            'errors'    => $errors,
            'open'      => self::openTab($errors, Request::str('tab')),
            'errorTabs' => array_map(self::tabOf(...), array_keys($errors)),
            'maxUpload' => Media::maxSizeMb(),
            'files'     => App::customFiles(),
            'code'      => $code ?? ['head' => (string) Settings::get('code_head', ''), 'body' => (string) Settings::get('code_body', '')],
        ]);
    }

    public function apply(): void
    {
        $this->store();
        $this->redirect('view=template&tab=' . self::openTab([], Request::str('tab')));
    }

    public function save(): void
    {
        $this->store();
        $this->redirect();
    }

    public function cancel(): void
    {
        $this->redirect();
    }

    private function store(): void
    {
        $in = [];
        foreach (array_keys(Settings::TEMPLATE) as $name) {
            $in[$name] = is_bool(Settings::TEMPLATE[$name]) ? Request::bool($name) : Request::str($name);
        }
        [$options, $errors] = Settings::validateTemplate($in);
        $css = str_replace("\r\n", "\n", Request::raw('custom_css'));
        if (strlen($css) > self::MAX_CSS) {
            $errors['custom_css'] = t('TEMPLATE_ERR_CSS_SIZE', intdiv(self::MAX_CSS, 1000));
        }
        $code = [];
        foreach (['head', 'body'] as $place) {
            $code[$place] = trim(str_replace("\r\n", "\n", Request::raw("code_$place")));
            if (strlen($code[$place]) > self::MAX_CODE) {
                $errors["code_$place"] = t('TEMPLATE_ERR_CSS_SIZE', intdiv(self::MAX_CODE, 1000));
            }
        }
        if ($errors) {
            $this->display($options, $css, $errors, $code);
            exit;
        }
        Settings::set('template', $options);
        Settings::set('code_head', $code['head']);
        Settings::set('code_body', $code['body']);
        Settings::saveCustomCss($css);
        Log::info('Template settings saved.');
        Session::flash(t('TEMPLATE_SAVED'));
    }
}
