<?php

namespace Pageyard\Admin;

use Pageyard\Session;

defined('PAGEYARD') or exit;

/** Bootstrap form fields for administrator views. Labels and hints are plain text. */
final class Form
{
    public static function token(): string
    {
        return '<input type="hidden" name="token" value="' . e(Session::token()) . '">';
    }

    public static function text(string $name, string $label, mixed $value, array $errors = [], string $hint = '',
        string $type = 'text', string $attrs = ''): string
    {
        $id = 'f-' . $name;
        $invalid = isset($errors[$name]) ? ' is-invalid' : '';
        return '<div class="mb-3"><label class="form-label" for="' . e($id) . '">' . e($label) . '</label>'
            . '<input class="form-control' . $invalid . '" type="' . e($type) . '" id="' . e($id) . '" name="' . e($name) . '"'
            . ($type === 'password' ? '' : ' value="' . e($value) . '"') . ($attrs !== '' ? " $attrs" : '') . '>'
            . self::feedback($name, $errors, $hint) . '</div>';
    }

    /** @param array<string, string> $options value => label */
    public static function select(string $name, string $label, array $options, mixed $value, array $errors = [],
        string $hint = ''): string
    {
        $id = 'f-' . $name;
        $html = '<div class="mb-3"><label class="form-label" for="' . e($id) . '">' . e($label) . '</label>'
            . '<select class="form-select' . (isset($errors[$name]) ? ' is-invalid' : '') . '" id="' . e($id) . '" name="' . e($name) . '">';
        foreach ($options as $optValue => $optLabel) {
            $html .= '<option value="' . e($optValue) . '"' . ((string) $optValue === (string) $value ? ' selected' : '') . '>'
                . e($optLabel) . '</option>';
        }
        return $html . '</select>' . self::feedback($name, $errors, $hint) . '</div>';
    }

    /** On/off switch; posts "1" when on and nothing when off. */
    /** Choices for an "Access" select; $guests adds "guests only" (menu items, blocks). */
    public static function accessOptions(bool $guests = false): array
    {
        return [0 => t('ACCESS_ALL'), 1 => t('ACCESS_MEMBERS')] + ($guests ? [2 => t('ACCESS_GUESTS')] : []);
    }

    public static function toggle(string $name, string $label, bool $on, array $errors = [], string $hint = '',
        bool $disabled = false): string
    {
        $id = 'f-' . $name;
        return '<div class="mb-3"><div class="form-check form-switch">'
            . '<input class="form-check-input' . (isset($errors[$name]) ? ' is-invalid' : '') . '" type="checkbox" role="switch" id="'
            . e($id) . '" name="' . e($name) . '" value="1"' . ($on ? ' checked' : '') . ($disabled ? ' disabled' : '') . '>'
            . '<label class="form-check-label" for="' . e($id) . '">' . e($label) . '</label>'
            . self::feedback($name, $errors, $hint) . '</div></div>';
    }

    /** Text input for a translated field: name tr[lang][field], error key "lang.field". */
    public static function trText(string $lang, string $field, string $label, mixed $value, array $errors = [],
        string $hint = '', string $attrs = ''): string
    {
        $id = "f-$lang-$field";
        $key = "$lang.$field";
        return '<div class="mb-3"><label class="form-label" for="' . e($id) . '">' . e($label) . '</label>'
            . '<input class="form-control' . (isset($errors[$key]) ? ' is-invalid' : '') . '" type="text" id="' . e($id)
            . '" name="tr[' . e($lang) . '][' . e($field) . ']" value="' . e($value) . '"' . ($attrs !== '' ? " $attrs" : '') . '>'
            . self::feedback($key, $errors, $hint) . '</div>';
    }

    /**
     * Textarea for a translated field; with $editorHeight > 0 it becomes a TinyMCE editor (media/admin/editor.js).
     */
    public static function trArea(string $lang, string $field, string $label, ?string $value, array $errors = [],
        string $hint = '', int $editorHeight = 0, string $attrs = ''): string
    {
        $id = "f-$lang-$field";
        $key = "$lang.$field";
        $class = $editorHeight > 0 ? 'form-control py-editor' : 'form-control';
        return '<div class="mb-3"><label class="form-label" for="' . e($id) . '">' . e($label) . '</label>'
            . '<textarea class="' . $class . (isset($errors[$key]) ? ' is-invalid' : '') . '" id="' . e($id)
            . '" name="tr[' . e($lang) . '][' . e($field) . ']"' . ($editorHeight > 0 ? ' data-height="' . $editorHeight . '"' : '')
            . ($attrs !== '' ? " $attrs" : '') . '>' . e($value) . '</textarea>'
            . self::feedback($key, $errors, $hint) . '</div>';
    }

    /**
     * Language tabs for translated fields (panes are #pane-lang-<tag>); empty with one language.
     * A tab whose fields have errors is marked.
     *
     * @param array<string, string> $languages tag => name, default first
     */
    public static function langTabs(array $languages, array $errors): string
    {
        if (count($languages) < 2) {
            return '';
        }
        $html = '<ul class="nav nav-tabs py-tabs" role="tablist">';
        $first = true;
        foreach ($languages as $tag => $name) {
            $hasError = (bool) array_filter(array_keys($errors), fn ($k) => str_starts_with($k, "$tag."));
            $html .= '<li class="nav-item" role="presentation"><button class="nav-link' . ($first ? ' active' : '')
                . ($hasError ? ' text-danger' : '') . '" id="tab-lang-' . e($tag) . '" data-bs-toggle="tab" data-bs-target="#pane-lang-'
                . e($tag) . '" type="button" role="tab" aria-controls="pane-lang-' . e($tag) . '" aria-selected="' . ($first ? 'true' : 'false') . '">'
                . '<span class="badge text-bg-secondary me-2">' . e(strtoupper($tag)) . '</span>' . e($name)
                . ($first ? ' <small class="text-body-secondary">(' . e(t('LANG_DEFAULT')) . ')</small>' : '')
                . ($hasError ? ' <i class="bi bi-exclamation-circle-fill ms-1" aria-hidden="true"></i>' : '')
                . '</button></li>';
            $first = false;
        }
        return $html . '</ul>';
    }

    private static function feedback(string $name, array $errors, string $hint): string
    {
        $html = isset($errors[$name]) ? '<div class="invalid-feedback">' . e($errors[$name]) . '</div>' : '';
        return $html . ($hint !== '' ? '<div class="form-text">' . e($hint) . '</div>' : '');
    }
}
