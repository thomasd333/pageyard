<?php

use Pageyard\Config;
use Pageyard\Home;
use Pageyard\Settings;
use Pageyard\Site\Router;
use Pageyard\Site\Sitemap;
use Pageyard\Uri;

defined('PAGEYARD') or exit;

return [
    'seo: absolute URLs' => function () {
        site_config();
        same('http://example.com/cms/o-nas', Uri::absolute('/cms/o-nas'));
        same('http://example.com/cms/images/a.jpg', Uri::absolute('images/a.jpg'));
        same('https://x.pl/a', Uri::absolute('https://x.pl/a'));
        site_config(['live_site' => 'https://example.com:8443/cms']);
        same('https://example.com:8443/cms/a', Uri::absolute('/cms/a'));
    },

    'seo: sitemap entries and XML' => function () {
        $ids = site_fixture();
        $urls = array_map(fn ($e) => $e['urls'], Sitemap::entries());
        // Home (the news category), page a, page "about"; b is scheduled, c unpublished.
        same([
            ['pl' => '/cms/', 'en' => '/cms/en/'],
            ['pl' => '/cms/aktualnosci/pierwszy-wpis', 'en' => '/cms/en/news/first-post'],
            ['pl' => '/cms/o-nas', 'en' => '/cms/en/about-us'],
        ], $urls);

        $xml = Sitemap::xml();
        check(simplexml_load_string($xml) !== false, 'valid XML');
        same(6, substr_count($xml, '<url>'));
        check(str_contains($xml, '<loc>http://example.com/cms/en/about-us</loc>'));
        check(str_contains($xml, '<xhtml:link rel="alternate" hreflang="pl" href="http://example.com/cms/o-nas"/>'));
        check((bool) preg_match('~<lastmod>\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ</lastmod>~', $xml));

        // Another home page: the category is listed itself, with the lastmod of its newest page.
        Home::set('page', $ids['about']);
        Router::reset();
        $entries = Sitemap::entries();
        same(['pl' => '/cms/', 'en' => '/cms/en/'], $entries[0]['urls']);
        same(['pl' => '/cms/aktualnosci', 'en' => '/cms/en/news'], $entries[1]['urls']);
        same(3, count($entries), 'the home page is not listed twice');

        // One language: no alternates.
        site_config(['languages' => 'pl']);
        check(!str_contains(Sitemap::xml(), 'xhtml:link rel'));
    },

    'seo: robots.txt' => function () {
        site_config();
        same("User-agent: *\nDisallow: /cms/administrator/\nDisallow: /cms/installation/\n\nSitemap: http://example.com/cms/sitemap.xml\n", Sitemap::robots());
        site_config(['sef_rewrite' => false]);
        check(str_contains(Sitemap::robots(), 'Sitemap: http://example.com/cms/index.php/sitemap.xml'));
        site_config(['robots' => 'noindex']);
        same("User-agent: *\nDisallow: /cms/\n", Sitemap::robots());
    },

    'seo: site description per language' => function () {
        site_fixture();
        Settings::set('meta_description', ['pl' => 'Opis', 'en' => 'About']);
        Settings::reset();
        same(['Opis', 'About', ''], [Settings::siteDescription('pl'), Settings::siteDescription('en'), Settings::siteDescription('de')]);
        same('index', Config::get('robots'));
    },
];
