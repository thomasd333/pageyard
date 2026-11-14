<?php
/**
 * Image gallery: thumbnails of a folder from the media library, opened in a viewer.
 * @param folder    folder inside images/ (required)
 * @param columns   thumbnails per row on a large screen, 2-6 (default 4)
 * @param order     name or newest (default name)
 * @param limit     show at most this many
 * @param captions  show file names as captions
 * @example folder=gallery columns=4
 */

defined('PAGEYARD') or exit;

/** @var array<string, string|true> $params */
echo Pageyard\Site\Gallery::render($params);
