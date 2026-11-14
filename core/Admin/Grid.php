<?php

namespace Pageyard\Admin;

use Pageyard\Pagination;
use Pageyard\Uri;

defined('PAGEYARD') or exit;

/** Markup helpers for administrator list views (all output is escaped here). */
final class Grid
{
    /** Sortable column header link. */
    public static function sort(string $view, array $state, string $column, string $label): string
    {
        $active = $state['sort'] === $column;
        $dir = $active && $state['dir'] === 'asc' ? 'desc' : 'asc';
        $icon = $active
            ? ' <i class="bi bi-caret-' . ($state['dir'] === 'asc' ? 'up' : 'down') . '-fill" aria-hidden="true"></i>'
            : '';
        return '<a class="py-sort" href="' . e(Uri::admin("view=$view&sort=$column&dir=$dir")) . '">' . e($label) . $icon . '</a>';
    }

    public static function checkAll(): string
    {
        return '<input class="form-check-input" type="checkbox" data-check-all aria-label="' . e(t('LIST_CHECK_ALL')) . '">';
    }

    public static function check(int $i, int $id, string $label): string
    {
        return '<input class="form-check-input" type="checkbox" name="cid[]" value="' . $id . '" id="cb' . $i
            . '" aria-label="' . e($label) . '">';
    }

    /** Status icon that toggles the row (task "<prefix>.<onTask|offTask>"). */
    public static function state(int $i, bool $on, string $prefix, string $onLabel, string $offLabel,
        string $onTask = 'unpublish', string $offTask = 'publish'): string
    {
        $title = $on ? t('LIST_CLICK_TO', $onLabel) : t('LIST_CLICK_TO', $offLabel);
        return '<button type="submit" class="btn btn-link p-0 py-status" form="adminForm" name="task" value="'
            . e($prefix . '.' . ($on ? $onTask : $offTask)) . '" data-row="cb' . $i . '" title="' . e($title) . '">'
            . ($on
                ? '<i class="bi bi-check-circle-fill text-success fs-5" aria-hidden="true"></i>'
                : '<i class="bi bi-x-circle-fill text-danger fs-5" aria-hidden="true"></i>')
            . '<span class="visually-hidden">' . e($on ? $onLabel : $offLabel) . '</span></button>';
    }

    /** Filled house for the home page, else a button making the row the home page (task "<prefix>.home"). */
    public static function home(int $i, string $prefix, bool $isHome): string
    {
        if ($isHome) {
            return '<i class="bi bi-house-fill text-warning fs-5" title="' . e(t('HOME_IS')) . '" aria-hidden="true"></i>'
                . '<span class="visually-hidden">' . e(t('HOME_IS')) . '</span>';
        }
        return '<button type="submit" class="btn btn-link p-0 py-status text-body-tertiary" form="adminForm" name="task" value="' . e("$prefix.home")
            . '" data-row="cb' . $i . '" title="' . e(t('HOME_SET')) . '"><i class="bi bi-house fs-5" aria-hidden="true"></i>'
            . '<span class="visually-hidden">' . e(t('HOME_SET')) . '</span></button>';
    }

    /** Up/down arrows for manual ordering (tasks "<prefix>.orderup" / "<prefix>.orderdown"). */
    public static function order(int $i, string $prefix, bool $first, bool $last): string
    {
        $button = fn (string $task, string $icon, string $label, bool $disabled) => $disabled
            ? '<span class="btn btn-sm btn-link px-1 disabled" aria-hidden="true"><i class="bi ' . $icon . '"></i></span>'
            : '<button type="submit" class="btn btn-sm btn-link px-1" form="adminForm" name="task" value="' . e("$prefix.$task")
                . '" data-row="cb' . $i . '" title="' . e($label) . '"><i class="bi ' . $icon . '" aria-hidden="true"></i>'
                . '<span class="visually-hidden">' . e($label) . '</span></button>';
        return '<span class="text-nowrap">' . $button('orderup', 'bi-arrow-up', t('LIST_MOVE_UP'), $first)
            . $button('orderdown', 'bi-arrow-down', t('LIST_MOVE_DOWN'), $last) . '</span>';
    }

    /** Small language badges: filled for languages the item has, outlined for missing ones. */
    public static function languages(array $have, array $all): string
    {
        if (count($all) < 2) {
            return '';
        }
        $html = '';
        foreach ($all as $lang) {
            $html .= in_array($lang, $have, true)
                ? '<span class="badge text-bg-secondary py-lang">' . e(strtoupper($lang)) . '</span>'
                : '<span class="badge border text-body-tertiary py-lang" title="' . e(t('LIST_NO_TRANSLATION')) . '">' . e(strtoupper($lang)) . '</span>';
        }
        return $html;
    }

    /** "Items per page" select for the filter form. */
    public static function limit(int $limit): string
    {
        $html = '<select class="form-select w-auto ms-auto" name="limit" aria-label="' . e(t('LIST_LIMIT')) . '" data-autosubmit>';
        foreach ([10, 20, 50, 100, 0] as $n) {
            $html .= '<option value="' . $n . '"' . ($limit === $n ? ' selected' : '') . '>' . ($n ? $n : e(t('LIST_ALL'))) . '</option>';
        }
        return $html . '</select>';
    }

    /** Search box for the filter form. */
    public static function search(string $value, string $hint): string
    {
        return '<div class="input-group py-search"><input class="form-control" type="search" name="search" value="' . e($value)
            . '" placeholder="' . e(t('LIST_SEARCH')) . '" aria-label="' . e(t('LIST_SEARCH')) . '" title="' . e($hint) . '">'
            . '<button class="btn btn-primary" type="submit" aria-label="' . e(t('LIST_SEARCH')) . '"><i class="bi bi-search" aria-hidden="true"></i></button></div>';
    }

    /**
     * Filter select that submits on change.
     * @param array<string, string> $options value => label
     */
    public static function filter(string $name, string $label, array $options, string $value): string
    {
        $html = '<select class="form-select w-auto" name="' . e($name) . '" aria-label="' . e($label) . '" data-autosubmit>'
            . '<option value="">' . e('- ' . $label . ' -') . '</option>';
        foreach ($options as $v => $l) {
            $html .= '<option value="' . e($v) . '"' . ((string) $v === $value ? ' selected' : '') . '>' . e($l) . '</option>';
        }
        return $html . '</select>';
    }

    /** "Clear" link shown while a search or filter is active. */
    public static function clear(string $view): string
    {
        return '<a class="btn btn-outline-secondary" href="' . e(Uri::admin("view=$view&reset=1")) . '">' . e(t('LIST_CLEAR')) . '</a>';
    }

    public static function empty(bool $filtered): string
    {
        return '<div class="alert alert-info"><i class="bi bi-info-circle me-2" aria-hidden="true"></i>'
            . e(t($filtered ? 'LIST_NO_MATCH' : 'LIST_EMPTY')) . '</div>';
    }

    public static function footer(Pagination $pagination): string
    {
        return '<div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mt-3">'
            . '<small class="text-body-secondary">' . e(t('LIST_TOTAL', $pagination->total)) . '</small>'
            . $pagination->render() . '</div>';
    }

    /** A badge for content that not everyone sees ('' for everyone). */
    public static function access(int $access): string
    {
        return match ($access) {
            1 => ' <span class="badge text-bg-warning ms-1"><i class="bi bi-lock me-1" aria-hidden="true"></i>' . e(t('ACCESS_MEMBERS_SHORT')) . '</span>',
            2 => ' <span class="badge text-bg-info ms-1"><i class="bi bi-person-dash me-1" aria-hidden="true"></i>' . e(t('ACCESS_GUESTS_SHORT')) . '</span>',
            default => '',
        };
    }
}
