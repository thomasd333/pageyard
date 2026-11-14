<?php

namespace Pageyard\Site;

defined('PAGEYARD') or exit;

/** What the site template needs besides the content: head data, breadcrumbs, language links. */
final class Document
{
    public string $lang = 'en';
    public string $title = '';
    public string $description = '';
    public string $canonical = '';
    /** Absolute or root-relative image path for og:image (site-relative "images/..." is fine). */
    public string $image = '';
    /** @var array<string, string> lang => URL of this content in that language */
    public array $alternates = [];
    /** @var list<array{string, ?string}> [title, url or null for the current item] */
    public array $breadcrumbs = [];
    public bool $isHome = false;
    /** UTC datetimes of a page (article:published_time / modified_time), null elsewhere. */
    public ?string $published = null;
    public ?string $modified = null;
    public bool $noindex = false;
    /** Administrator preview: why visitors may not see this page (empty list = they do), else null. */
    public ?array $preview = null;
    public int $status = 200;

    /**
     * Code from System > Template > Code for this place (head | body). Not in administrators' previews or
     * while the site is offline, so they don't count as visits.
     */
    public function code(string $place): string
    {
        return $this->preview === null && $this->status < 500 ? (string) \Pageyard\Settings::get("code_$place", '') : '';
    }
    /** Extra classes on <body>, e.g. "py-view-page". */
    public string $bodyClass = '';
}
