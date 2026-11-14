<?php

namespace Pageyard;

defined('PAGEYARD') or exit;

/** Page numbers for lists (back end now, site lists later). */
final class Pagination
{
    public readonly int $pages;
    public readonly int $page;

    /**
     * @param int $limit rows per page, 0 = all
     * @param \Closure(int): string $url builds the link to a page number
     */
    public function __construct(
        public readonly int $total,
        public readonly int $limit,
        int $page,
        private readonly \Closure $url,
    ) {
        $this->pages = $limit > 0 ? max(1, (int) ceil($total / $limit)) : 1;
        $this->page = min(max(1, $page), $this->pages);
    }

    /** Link to a page number. */
    public function url(int $page): string
    {
        return ($this->url)($page);
    }

    public function offset(): int
    {
        return $this->limit > 0 ? ($this->page - 1) * $this->limit : 0;
    }

    /** Bootstrap pagination markup; empty when everything fits on one page. */
    public function render(): string
    {
        if ($this->pages <= 1) {
            return '';
        }
        $item = function (int $page, string $label, bool $disabled = false, bool $active = false, string $aria = ''): string {
            $class = 'page-item' . ($disabled ? ' disabled' : '') . ($active ? ' active' : '');
            $attr = $aria !== '' ? ' aria-label="' . e($aria) . '"' : '';
            if ($disabled || $active) {
                return "<li class=\"$class\"><span class=\"page-link\"$attr" . ($active ? ' aria-current="page"' : '') . ">$label</span></li>";
            }
            return "<li class=\"$class\"><a class=\"page-link\" href=\"" . e(($this->url)($page)) . "\"$attr>$label</a></li>";
        };

        $html = $item($this->page - 1, '&lsaquo;', $this->page === 1, false, t('PAGINATION_PREV'));
        foreach ($this->window() as $p) {
            $html .= $p === 0 ? $item(0, '&hellip;', true) : $item($p, (string) $p, false, $p === $this->page);
        }
        $html .= $item($this->page + 1, '&rsaquo;', $this->page === $this->pages, false, t('PAGINATION_NEXT'));
        return '<nav aria-label="' . e(t('PAGINATION')) . '"><ul class="pagination pagination-sm mb-0">' . $html . '</ul></nav>';
    }

    /** Page numbers to show: first, last and two around the current one; 0 marks a gap. */
    public function window(): array
    {
        $show = array_unique([1, $this->pages, ...range(max(1, $this->page - 2), min($this->pages, $this->page + 2))]);
        sort($show);
        $out = [];
        $prev = 0;
        foreach ($show as $p) {
            if ($p - $prev > 1) {
                $out[] = 0;
            }
            $out[] = $p;
            $prev = $p;
        }
        return $out;
    }
}
