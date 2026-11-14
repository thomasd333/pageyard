<?php

namespace Pageyard\Site;

use Pageyard\Access;
use Pageyard\Accounts;
use Pageyard\Blocks;
use Pageyard\Database\Db;
use Pageyard\Menus;
use Pageyard\Settings;

defined('PAGEYARD') or exit;

/**
 * Template options plus the blocks of the current request: published, assigned to what is shown
 * and having something to show in the language. Menus resolve to item trees with URLs.
 */
final class Layout
{
    public readonly array $options;
    /** @var array<string, list<array>>|null position => blocks ready to render */
    private ?array $blocks = null;

    /**
     * @param array{home: bool, page: ?int, category: ?int}|null $context what is shown (null = 404, offline)
     */
    public function __construct(private readonly ?array $context, private readonly string $lang)
    {
        $this->options = Settings::template();
    }

    /** True when the position has blocks (side panels also need to be switched on). */
    public function has(string $position): bool
    {
        if (($position === 'left' || $position === 'right') && !$this->options["show_$position"]) {
            return false;
        }
        return $this->blocks($position) !== [];
    }

    /** Blocks of a position, each with 'heading' (or ''), and 'items' (menu) or 'html' (content). */
    public function blocks(string $position): array
    {
        if ($this->blocks === null) {
            $this->blocks = $this->load();
        }
        return $this->blocks[$position] ?? [];
    }

    /** True when this request shows the side menu layout: it is chosen and the left position has blocks. */
    public function sidebar(): bool
    {
        return $this->options['layout'] === 'sidebar' && $this->has('left');
    }

    /** Bootstrap container class of the navbar, the content and the footer. */
    public function wrapper(): string
    {
        return $this->options['layout'] === 'sidebar' ? 'container-fluid' : $this->options['container'];
    }

    /**
     * Renders a position with the view html/blocks.php.
     * @param ?string $style markup to use instead of the position's own (e.g. "sidebar")
     */
    public function render(string $position, ?string $style = null): string
    {
        if (!$this->has($position)) {
            return '';
        }
        $blocks = $this->blocks($position);
        $position = $style ?? $position;
        ob_start();
        require App::templateFile('html/blocks.php');
        return (string) ob_get_clean();
    }

    /** @return array<string, list<array>> */
    private function load(): array
    {
        $db = Db::get();
        $rows = $db->fetchAll('SELECT b.*, t.lang AS tr_lang, t.title AS heading, t.content, t.link_label FROM #__blocks b
            LEFT JOIN ' . Blocks::T . ' t ON t.block_id = b.id AND t.lang = ?
            WHERE b.state = 1 AND ' . Access::sql('b.access') . ' ORDER BY b.ordering, b.id', [$this->lang]);
        $out = [];
        foreach ($rows as $block) {
            if (!Blocks::showsOn($block, $this->context)) {
                continue;
            }
            if ($block['type'] === 'hero') {
                $block['hero'] = $this->hero($block);
                if ($block['hero'] === null) {
                    continue;
                }
            }
            $block['heading'] = $block['show_title'] ? (string) ($block['heading'] ?? '') : '';
            if ($block['type'] === 'hero') {
                // Ready above.
            } elseif ($block['type'] === 'menu') {
                $block['items'] = $this->menu((int) $block['menu_id']);
                if (!$block['items']) {
                    continue;
                }
            } elseif ($block['type'] === 'search') {
                $block['html'] = Search::form($this->lang);
            } elseif ($block['type'] === 'user') {
                $block['user'] = Account::blockData($this->lang);
                if ($block['user'] === null) {
                    continue;  // site accounts are off
                }
                $block['html'] = Account::block($this->lang);  // for positions without their own markup
            } else {
                if (($block['content'] ?? null) === null) {
                    continue;  // no content in this language
                }
                $block['html'] = Html::content($block['content']);
            }
            $out[$block['position']][] = $block;
        }
        return $out;
    }

    /**
     * What a hero shows in this language, or null when it has nothing for it. A hero with texts in
     * other languages isn't shown without its own; one that is only an image shows in every language.
     */
    private function hero(array $block): ?array
    {
        $image = (string) $block['image'];
        if ($block['tr_lang'] === null
            && ($image === '' || Db::get()->fetchValue('SELECT 1 FROM ' . Blocks::T . ' WHERE block_id = ?', [(int) $block['id']]))) {
            return null;
        }
        $params = Blocks::params($block);
        $label = (string) ($block['link_label'] ?? '');
        $link = $label !== '' ? $this->link(['type' => $params['link_type'], 'page_id' => $params['link_page'],
            'category_id' => $params['link_category'], 'url' => $params['link_url'], 'label' => $label]) : null;
        return [
            'heading' => (string) ($block['heading'] ?? ''),
            'text'    => (string) ($block['content'] ?? ''),
            'image'   => $image,
            'height'  => $params['height'],
            'align'   => $params['align'],
            'overlay' => $params['overlay'],
            'button'  => $link === null ? null : ['label' => $label, 'url' => $link[1], 'new_window' => $params['link_type'] === 'url' && $params['new_window']],
        ];
    }

    /**
     * Visible items of a menu in this language as a tree:
     * [label, url, external, new_window, active, children => [...]].
     */
    public function menu(int $menuId): array
    {
        $tree = [];
        $children = [];
        $ctx = $this->context;
        foreach (Menus::items($menuId, $this->lang) as $item) {
            if (!$item['state'] || !Access::allows((int) $item['access'])) {
                continue;
            }
            $link = $this->link($item);
            if ($link === null) {
                continue;
            }
            [$title, $url] = $link;
            $isUrl = $item['type'] === 'url';
            $node = [
                'label'      => $item['label'] ?: $title,
                'url'        => $url,
                'external'   => $isUrl && preg_match('~^https?://~i', $url) === 1,
                'new_window' => $isUrl && (bool) $item['new_window'],
                'active'     => $ctx !== null && match ($item['type']) {
                    'home'     => $ctx['home'],
                    'page'     => $ctx['page'] === (int) $item['page_id'],
                    'category' => $ctx['category'] === (int) $item['category_id'],
                    'account'  => Account::match(Router::requestPath(), $this->lang) === (string) $item['url'],
                    default    => false,
                },
                'children'   => [],
            ];
            if ((int) $item['parent_id'] === 0) {
                $tree[(int) $item['id']] = $node;
            } elseif (isset($tree[(int) $item['parent_id']])) {
                $children[(int) $item['parent_id']][] = $node;
            }
            // Children of a hidden parent are hidden too.
        }
        foreach ($children as $parentId => $nodes) {
            $tree[$parentId]['children'] = $nodes;
            if (array_filter($nodes, fn ($n) => $n['active'])) {
                $tree[$parentId]['active'] = true;
            }
        }
        return array_values($tree);
    }

    /** [default label, url] of a menu item in this language, or null when it leads nowhere visible. */
    private function link(array $item): ?array
    {
        $lang = $this->lang;
        switch ($item['type']) {
            case 'url':
                return $item['label'] ? ['', (string) $item['url']] : null;  // needs a label in this language
            case 'account':
                // The profile for logged-in users, logging in and registering for guests.
                $page = (string) $item['url'];
                $shown = Accounts::enabled() && ($page === '' ? Access::member() : !Access::member())
                    && ($page !== 'register' || Accounts::registrationOpen());
                return $shown ? [Menus::accountTitle($page), Account::url($page, $lang)] : null;
            case 'home':
                $home = Router::home($lang);
                if ($home === null) {
                    return null;
                }
                $title = $home['type'] === 'page'
                    ? (Content::pageLink($home['id'], $lang)['title'] ?? null)
                    : (Content::category($home['id'], $lang)['title'] ?? null);
                return $title === null ? null : [$title, Router::url('', $lang)];
            case 'page':
                $page = Content::pageLink((int) $item['page_id'], $lang);
                $url = $page !== null ? Router::pageUrl((int) $page['id'], $page['category_id'] !== null ? (int) $page['category_id'] : null, $page['alias'], $lang) : null;
                return $url === null ? null : [$page['title'], $url];
            case 'category':
                $category = Content::category((int) $item['category_id'], $lang);
                $url = $category !== null ? Router::categoryUrl((int) $category['id'], $lang) : null;
                return $url === null ? null : [$category['title'], $url];
        }
        return null;
    }
}
