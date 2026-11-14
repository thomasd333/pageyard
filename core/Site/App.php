<?php

namespace Pageyard\Site;

use Pageyard\Access;
use Pageyard\Accounts;
use Pageyard\Auth;
use Pageyard\Categories;
use Pageyard\Config;
use Pageyard\Home;
use Pageyard\Lang;
use Pageyard\Pagination;
use Pageyard\Request;
use Pageyard\Session;
use Pageyard\Settings;
use Pageyard\Str;
use Pageyard\Uri;

defined('PAGEYARD') or exit;

/**
 * Site request: resolve the URL, build the view, wrap it in templates/site/index.php.
 * Views are templates/site/html/<name>.php; they get their variables plus $doc. A file with the same path
 * under templates/custom/ replaces the original (an update of Pageyard leaves that folder alone).
 */
final class App
{
    public const TEMPLATE = 'site';
    public const CUSTOM = 'custom';
    public const CUSTOM_CSS = 'css/custom.css';

    private Document $doc;
    /** What is shown, for block assignments and active menu items; null on 404 / offline. */
    private ?array $context = null;

    public static function run(): void
    {
        (new self())->handle();
    }

    private function handle(): void
    {
        $this->doc = new Document();
        Lang::set((string) Config::get('language'));

        $path = Router::requestPath();
        if ($path === 'sitemap.xml' || $path === 'robots.txt') {
            $this->machineFile($path);
            return;
        }
        $previewId = Request::int('preview');
        if ($previewId > 0 && $this->isAdmin()) {
            $this->preview($previewId);
            return;
        }
        $match = Router::resolve($path);
        if ($match['status'] === 301) {
            // ?page=2 and the like survive a move; addresses outside the site don't get them.
            $query = (string) ($_SERVER['QUERY_STRING'] ?? '');
            $keep = $query !== '' && str_starts_with($match['redirect'], '/') && !str_contains($match['redirect'], '?');
            Uri::redirect($match['redirect'] . ($keep ? "?$query" : ''), 301);
        }
        Lang::set($match['lang']);
        $this->doc->lang = $match['lang'];

        // The administrator's session is checked (and closed) first; then the visitor's own on the site.
        $offline = Config::get('offline') && !$this->isAdmin();
        if (!$offline) {
            Access::setMember(Account::session() !== null);
        }
        if ($offline) {
            $this->doc->status = 503;
            $this->doc->noindex = true;
            $this->doc->title = t('SITE_OFFLINE_TITLE');
            header('Retry-After: 3600');
            $content = $this->view('offline');
        } else {
            $content = match ($match['type']) {
                'page'     => $this->page($match['id'], $match['lang'], $match['home']),
                'category' => $this->listing($match['id'], $match['lang'], $match['home']),
                default    => $this->other($path, $match['lang']),
            };
        }
        // Logged-in site users see their name in blocks and content for them: such pages are theirs alone.
        if (Access::member()) {
            header('Cache-Control: private, no-store');
        }

        http_response_code($this->doc->status);
        header('Content-Type: text/html; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        $this->render($content);
    }

    /**
     * ?preview=<page id>&lang=<tag> for logged-in administrators: the saved page whatever its state,
     * with a notice saying why visitors may not see it. Never indexed or cached.
     */
    private function preview(int $id): void
    {
        $lang = in_array(Request::str('lang'), Config::languages(), true) ? Request::str('lang') : (string) Config::get('language');
        Lang::set($lang);
        $this->doc->lang = $lang;
        $page = Content::pageAny($id, $lang);
        if ($page === null) {
            $content = $this->notFound($lang);
        } else {
            $reasons = [];
            if (!$page['state']) {
                $reasons[] = t('SITE_PREVIEW_UNPUBLISHED');
            }
            if ($page['publish_up'] > \Pageyard\Database\Db::now()) {
                $reasons[] = t('SITE_PREVIEW_SCHEDULED', Lang::date($page['publish_up']) . ' ' . py_date($page['publish_up'], 'H:i'));
            }
            if ($page['category_id'] !== null && !$page['category_state']) {
                $reasons[] = t('SITE_PREVIEW_CATEGORY');
            }
            if ($page['category_id'] !== null && Router::categoryUrl((int) $page['category_id'], $lang) === null) {
                $reasons[] = t('SITE_PREVIEW_NO_URL');
            }
            if ((int) $page['access'] === Access::MEMBERS || (int) $page['category_access'] === Access::MEMBERS) {
                $reasons[] = t('SITE_PREVIEW_MEMBERS');
            }
            $content = $this->page($id, $lang, Home::is('page', $id), $page);
            $this->doc->preview = $reasons;
            $this->doc->noindex = true;
        }
        http_response_code($this->doc->status);
        header('Content-Type: text/html; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('X-Robots-Tag: noindex');
        header('Cache-Control: no-store');
        $this->render($content);
    }

    /** A page (also the home page), with its category in the breadcrumbs. $page: preloaded (preview). */
    private function page(int $id, string $lang, bool $home, ?array $page = null): string
    {
        $page ??= Content::page($id, $lang);
        if ($page === null) {
            $this->loginWall($lang, fn () => Content::page($id, $lang));
            return $this->notFound($lang);
        }
        $category = $page['category_id'] !== null ? (int) $page['category_id'] : null;
        $this->context = ['home' => $home, 'page' => $id, 'category' => $category];
        $this->doc->isHome = $home;
        if (!$home) {
            if ($category !== null) {
                $title = Router::categories()[$category]['title'][$lang] ?? null;
                if ($title !== null) {
                    $this->doc->breadcrumbs[] = [$title, Router::categoryUrl($category, $lang)];
                }
            }
            $this->doc->breadcrumbs[] = [$page['title'], null];
        }
        $this->doc->title = $page['title'];
        $this->doc->description = $this->describe($lang, $home, $page['meta_description'], $page['intro'] ?: $page['body']);
        $this->doc->image = $page['image'];
        $this->doc->published = $page['publish_up'];
        $this->doc->modified = $page['modified'];
        $this->doc->bodyClass = 'py-view-page';
        $this->doc->canonical = (string) Router::pageUrl($id, $category, $page['alias'], $lang);
        foreach (Content::pageLanguages($id) as $l => $alias) {
            $url = Router::pageUrl($id, $category, $alias, $l);
            if ($url !== null) {
                $this->doc->alternates[$l] = $url;
            }
        }
        return $this->view('page', ['page' => $page]);
    }

    /** A category: blog or list layout of its pages, paginated with ?page=N. */
    private function listing(int $id, string $lang, bool $home): string
    {
        $category = Content::category($id, $lang);
        if ($category === null) {
            $this->loginWall($lang, fn () => Content::category($id, $lang));
            return $this->notFound($lang);
        }
        $this->context = ['home' => $home, 'page' => null, 'category' => $id];
        $p = Categories::params($category);
        $blog = $p['layout'] === 'blog';
        $perPage = $blog ? $p['intro_count'] + $p['link_count'] : $p['per_page'];
        $pageNo = max(1, Request::int('page', 1));
        $url = (string) Router::categoryUrl($id, $lang);

        $total = Content::categoryPages($id, $lang, $p['order'], 0, 0)['total'];
        $pagination = new Pagination($total, $perPage, $pageNo,
            fn (int $n) => $url . ($n > 1 ? (str_contains($url, '?') ? '&' : '?') . 'page=' . $n : ''));
        if ($pageNo > $pagination->pages) {
            return $this->notFound($lang);
        }
        $items = Content::categoryPages($id, $lang, $p['order'], $perPage, $pagination->offset())['items'];
        foreach ($items as &$item) {
            $item['url'] = Router::pageUrl((int) $item['id'], $id, $item['alias'], $lang);
        }
        unset($item);

        $this->doc->isHome = $home;
        $this->doc->title = $category['title'];
        $this->doc->description = $this->describe($lang, $home, $category['meta_description'], $category['description']);
        $this->doc->canonical = $pageNo > 1 ? $pagination->url($pageNo) : $url;
        $this->doc->bodyClass = 'py-view-' . ($blog ? 'blog' : 'list');
        if (!$home) {
            $this->doc->breadcrumbs = [[$category['title'], null]];
        }
        foreach (array_keys(Router::categories()[$id]['alias'] ?? []) as $l) {
            $this->doc->alternates[$l] = (string) Router::categoryUrl($id, $l);
        }

        return $this->view($blog ? 'blog' : 'list', [
            'title'      => $category['title'],
            'category'   => $category,
            'params'     => $p,
            'intro'      => $blog ? array_slice($items, 0, $p['intro_count']) : $items,
            'links'      => $blog ? array_slice($items, $p['intro_count']) : [],
            'pagination' => $pagination,
        ]);
    }

    /**
     * Something hidden from this visitor that a logged-in user would see: off to the login form, and back
     * here afterwards. Anything else stays a 404.
     */
    private function loginWall(string $lang, callable $find): void
    {
        if (Access::member() || !Accounts::enabled() || Access::as(true, $find) === null) {
            return;
        }
        $here = (string) ($_SERVER['REQUEST_URI'] ?? '');
        Account::session(true);
        Session::flash(t('ACCOUNT_LOGIN_NEEDED'), 'info');
        Uri::redirect(Account::url('login', $lang) . '?return=' . rawurlencode($here));
    }

    private function notFound(string $lang): string
    {
        $this->context = null;
        $this->doc->status = 404;
        $this->doc->noindex = true;
        $this->doc->isHome = false;
        $this->doc->breadcrumbs = [];
        $this->doc->alternates = [];
        $this->doc->canonical = '';
        $noHome = Home::get() === null;
        $this->doc->title = t($noHome ? 'SITE_NO_HOME_TITLE' : 'SITE_NOT_FOUND_TITLE');
        $this->doc->bodyClass = 'py-view-error';
        return $this->view('error', [
            'homeUrl' => Router::home($lang) !== null ? Router::url('', $lang) : null,
            'noHome'  => $noHome,
        ]);
    }

    /** A path content doesn't answer: the search, an account page, or a 404. */
    private function other(string $path, string $lang): string
    {
        if ($this->isSearch($path, $lang)) {
            return $this->search($lang);
        }
        $action = Accounts::enabled() ? Account::match($path, $lang) : null;
        if ($action !== null) {
            $this->context = null;
            $this->doc->isHome = false;
            $content = (new Account($lang, $this->doc, $this->view(...)))->handle($action);
            if ($content !== null) {
                return $content;
            }
        }
        return $this->notFound($lang);
    }

    private function isSearch(string $path, string $lang): bool
    {
        $prefix = $lang === (string) Config::get('language') ? '' : "$lang/";
        return trim($path, '/') === $prefix . Search::PATH;
    }

    /** /search?q=words: results in the current language. Not for search engines. */
    private function search(string $lang): string
    {
        $this->context = null;
        $this->doc->noindex = true;
        $this->doc->isHome = false;
        $this->doc->title = t('SEARCH_TITLE');
        $this->doc->bodyClass = 'py-view-search';
        $query = mb_substr(Request::str('q'), 0, Search::MAX_QUERY);
        $results = Search::run($query, $lang);
        $url = Router::url(Search::PATH, $lang);
        $pagination = new Pagination(count($results), Search::PER_PAGE, max(1, Request::int('page', 1)),
            fn (int $n) => $url . '?' . http_build_query(['q' => $query] + ($n > 1 ? ['page' => $n] : [])));
        return $this->view('search', [
            'query'      => $query,
            'form'       => Search::form($lang, $query),
            'tooShort'   => $query !== '' && !Search::words($query),
            'results'    => array_slice($results, $pagination->offset(), Search::PER_PAGE),
            'total'      => count($results),
            'pagination' => $pagination,
        ]);
    }

    /**
     * Meta description: the item's own, else the site's default on the home page, else an excerpt of
     * the content, else the site's default.
     */
    private function describe(string $lang, bool $home, string $own, ?string $content): string
    {
        $site = Settings::siteDescription($lang);
        if ($own !== '') {
            return $own;
        }
        if ($home && $site !== '') {
            return $site;
        }
        $excerpt = Str::excerpt(preg_replace('~\{script\s[^{}]*\}~i', '', (string) $content), 160);
        return $excerpt !== '' ? $excerpt : $site;
    }

    /** sitemap.xml and robots.txt. The sitemap is 404 for a hidden site and 503 while offline. */
    private function machineFile(string $path): void
    {
        header('X-Content-Type-Options: nosniff');
        if ($path === 'robots.txt') {
            header('Content-Type: text/plain; charset=utf-8');
            echo Sitemap::robots();
            return;
        }
        if (Config::get('robots', 'index') === 'noindex' || Config::get('offline')) {
            http_response_code(Config::get('offline') ? 503 : 404);
            header('Content-Type: text/plain; charset=utf-8');
            return;
        }
        header('Content-Type: application/xml; charset=utf-8');
        echo Sitemap::xml();
    }

    /** True when the visitor has an administrator session (offline mode, later previews). */
    private function isAdmin(): bool
    {
        if (!isset($_COOKIE[Session::ADMIN])) {
            return false;
        }
        Session::start(Session::ADMIN, Uri::root());
        $admin = Auth::user() !== null;
        session_write_close();
        return $admin;
    }

    /** Renders the view html/<name>.php to a string. */
    private function view(string $name, array $vars = []): string
    {
        $file = self::templateFile("html/$name.php");
        return (static function (string $__file, array $__vars): string {
            extract($__vars, EXTR_SKIP);
            ob_start();
            require $__file;
            return (string) ob_get_clean();
        })($file, $vars + ['doc' => $this->doc]);
    }

    private function render(string $content): void
    {
        $doc = $this->doc;
        $layout = new Layout($this->context, $doc->lang);
        require self::templateFile('index.php');
    }

    /** A template file: the copy in templates/custom/ if there is one, else the original. */
    public static function templateFile(string $path, string $root = PY_ROOT): string
    {
        $custom = "$root/templates/" . self::CUSTOM . "/$path";
        return is_file($custom) ? $custom : "$root/templates/" . self::TEMPLATE . "/$path";
    }

    /** URL of templates/custom/css/custom.css (changes with the file), or null without one. */
    public static function customStylesheet(string $root = PY_ROOT): ?string
    {
        $file = "$root/templates/" . self::CUSTOM . '/' . self::CUSTOM_CSS;
        return is_file($file) ? Uri::root() . 'templates/' . self::CUSTOM . '/' . self::CUSTOM_CSS . '?v=' . filemtime($file) : null;
    }

    /**
     * Files in templates/custom/. "replaces": it stands in for an original; "newer": that original
     * changed after the copy was made, so the copy may lack something.
     * @return list<array{path: string, replaces: bool, newer: bool}>
     */
    public static function customFiles(string $root = PY_ROOT): array
    {
        $dir = "$root/templates/" . self::CUSTOM;
        if (!is_dir($dir)) {
            return [];
        }
        $files = [];
        $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($items as $item) {
            $path = substr(str_replace('\\', '/', $item->getPathname()), strlen($dir) + 1);
            if (!$item->isFile() || $path === 'README.md') {
                continue;
            }
            $original = "$root/templates/" . self::TEMPLATE . "/$path";
            $replaces = is_file($original);
            $files[] = ['path' => $path, 'replaces' => $replaces, 'newer' => $replaces && filemtime($original) > $item->getMTime()];
        }
        usort($files, fn ($a, $b) => strcmp($a['path'], $b['path']));
        return $files;
    }
}
