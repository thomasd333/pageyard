<?php

namespace Pageyard\Admin\Controllers;

use Pageyard\Log;
use Pageyard\Media;
use Pageyard\Request;
use Pageyard\Uri;

defined('PAGEYARD') or exit;

/**
 * AJAX image upload used by TinyMCE, image fields ("folder": images/<folder>/<yyyy-mm>/) and the media
 * library ("dir": that folder inside images/). Answers JSON.
 */
final class MediaController
{
    public const TASKS = ['upload'];

    public function upload(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        $folder = Request::str('folder', 'pages');
        $dir = Request::str('dir');
        try {
            if ($dir !== '') {
                $dir = Media::folder($dir) ?? throw new \RuntimeException(t('ERR_NOT_FOUND'));
                $path = Media::storeInto($_FILES['file'] ?? [], $dir);
            } elseif (!in_array($folder, ['pages', 'categories', 'site'], true)) {
                throw new \RuntimeException('Bad folder.');
            } else {
                $path = Media::storeUpload($_FILES['file'] ?? [], $folder);
            }
            Log::info("Uploaded $path");
            // "path" is what we store; "location" is what TinyMCE inserts (resolved against document_base_url).
            echo json_encode(['path' => $path, 'location' => $path, 'url' => Uri::root() . $path], JSON_UNESCAPED_SLASHES);
        } catch (\RuntimeException $e) {
            http_response_code(400);
            echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
    }
}
