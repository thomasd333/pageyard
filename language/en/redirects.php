<?php
defined('PAGEYARD') or exit;

return [
    'REDIRECTS_TITLE'        => 'Redirects',
    'REDIRECTS_NEW'          => 'New redirect',
    'REDIRECTS_EDIT'         => 'Edit redirect',
    'REDIRECTS_SAVED'        => 'Redirect saved.',
    'REDIRECTS_INTRO'        => 'When you change the alias or category of a page, or the alias of a category, the old address is added here by itself, so links and search results keep working. Add others by hand, e.g. the addresses of a previous site.',
    'REDIRECTS_SEARCH_HINT'  => 'Searches the addresses.',
    'REDIRECTS_FROM'         => 'Old address',
    'REDIRECTS_FROM_HINT'    => 'The part after the site\'s address, e.g. /old-page. A pasted full address works too. A query (?id=5) is ignored.',
    'REDIRECTS_TO'           => 'Leads to',
    'REDIRECTS_URL_HINT'     => 'An address on this site (/new-page) or a full one (https://...).',
    'REDIRECTS_KIND'         => 'Added',
    'REDIRECTS_AUTO'         => 'automatically',
    'REDIRECTS_MANUAL'       => 'by hand',
    'REDIRECTS_HITS'         => 'Uses',
    'REDIRECTS_LAST_HIT'     => 'Last used',
    'REDIRECTS_TARGET_GONE'  => 'The target no longer exists in this language: this address gives a 404.',
    'REDIRECTS_ERR_RESERVED' => 'This address belongs to the system.',
    'REDIRECTS_ERR_LIVE'     => 'A page or category already uses this address.',
    'REDIRECTS_ERR_EXISTS'   => 'There is already a redirect from this address.',
    'REDIRECTS_ERR_URL'      => 'Enter an address on this site or a full one starting with http:// or https://.',
    'REDIRECTS_ERR_LOOP'     => 'This would lead back to the old address.',
];
