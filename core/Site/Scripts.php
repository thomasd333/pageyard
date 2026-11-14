<?php

namespace Pageyard\Site;

use Pageyard\Config;
use Pageyard\Log;

defined('PAGEYARD') or exit;

/**
 * {script path key=value other="quoted value"} tags in content: runs scripts/<path>(.php) with the
 * parameters in $params and puts its output (echoed, or a returned string) in place of the tag.
 * A tag alone in a paragraph replaces the whole <p>, so block output stays valid HTML.
 * The path must resolve (realpath) to a .php file inside scripts/. Output of scripts is not scanned
 * for further tags. Errors are logged; details are shown only with $debug on.
 */
final class Scripts
{
    /** Overridable for tests; default PY_ROOT/scripts. */
    public static ?string $dir = null;

    private const TAG = '\{script\s+([^\s{}]+)([^{}]*)\}';

    public static function dir(): string
    {
        return self::$dir ?? PY_ROOT . '/scripts';
    }

    /** Replaces all {script} tags in $html with the scripts' output. */
    public static function render(string $html): string
    {
        if (stripos($html, '{script') === false) {
            return $html;
        }
        // The editor may put non-breaking spaces between the parts of a tag.
        $html = (string) preg_replace_callback('~\{script[^{}]*\}~iu', fn ($m) => str_replace(['&nbsp;', "\u{a0}"], ' ', $m[0]), $html);
        // One pass, so the output of a script is never scanned again. A tag that is the only thing
        // in its paragraph replaces the paragraph (optional <p ...> before and </p> after it).
        $space = '(?:\s|&nbsp;|\x{a0})*';
        return (string) preg_replace_callback(
            '~(<p(?:\s[^>]*)?>' . $space . ')?' . self::TAG . '(' . $space . '</p>)?~iu',
            function (array $m): string {
                $open = $m[1];
                $close = $m[4] ?? '';
                $output = self::tag($m[2], $m[3]);
                return $open !== '' && $close !== '' ? $output : $open . $output . $close;
            },
            $html,
        );
    }

    /**
     * key=value pairs: value bare, "double" or 'single' quoted; a key without value is true.
     * Text comes from the editor, so HTML entities (&quot;, &nbsp;...) are decoded first.
     * @return array<string, string|true>
     */
    public static function params(string $text): array
    {
        $text = str_replace("\u{a0}", ' ', html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $text = strtr($text, ['„' => '"', '”' => '"', '“' => '"']);
        preg_match_all('~([A-Za-z_][A-Za-z0-9_-]*)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|(\S+)))?~u', $text, $matches, PREG_SET_ORDER);
        $params = [];
        foreach ($matches as $m) {
            $params[$m[1]] = isset($m[2]) ? $m[2] . ($m[3] ?? '') . ($m[4] ?? '') : true;
        }
        return $params;
    }

    /** Absolute path of a script inside scripts/, or null when it is outside, missing or not .php. */
    public static function resolve(string $path): ?string
    {
        $path = trim(str_replace('\\', '/', $path), '/');
        if ($path === '' || !preg_match('~^[A-Za-z0-9_./-]+$~', $path)) {
            return null;
        }
        if (!str_ends_with(strtolower($path), '.php')) {
            $path .= '.php';
        }
        $base = realpath(self::dir());
        $file = realpath(self::dir() . '/' . $path);
        // Tags written while the built-in scripts lived in examples/.
        if ($file === false && str_starts_with($path, 'examples/')) {
            $file = realpath(self::dir() . '/built-in/' . substr($path, 9));
        }
        if ($base === false || $file === false || !is_file($file) || !str_starts_with($file, $base . DIRECTORY_SEPARATOR)) {
            return null;
        }
        return $file;
    }

    /**
     * Scripts for the editor's "Insert script" dialog: path (relative, without .php), description
     * (first line of the first doc comment) and example parameters (its @example line).
     * @return list<array{path: string, description: string, example: string}>
     */
    public static function available(): array
    {
        $base = realpath(self::dir());
        if ($base === false) {
            return [];
        }
        $list = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
                continue;
            }
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($base) + 1));
            $head = (string) file_get_contents($file->getPathname(), false, null, 0, 4096);
            $description = '';
            $example = '';
            if (preg_match('~/\*\*(.*?)\*/~s', $head, $doc)) {
                foreach (preg_split('/\R/', $doc[1]) as $line) {
                    $line = trim(ltrim(trim($line), '*'));
                    if (str_starts_with($line, '@example')) {
                        $example = trim(substr($line, 8));
                    } elseif ($description === '' && $line !== '' && $line[0] !== '@') {
                        $description = $line;
                    }
                }
            }
            $list[] = ['path' => substr($relative, 0, -4), 'description' => $description, 'example' => $example];
        }
        usort($list, fn ($a, $b) => strnatcasecmp($a['path'], $b['path']));
        return $list;
    }

    private static function tag(string $path, string $paramText): string
    {
        $file = self::resolve(html_entity_decode($path, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($file === null) {
            Log::error("{script $path}: not found in scripts/ (or outside it)");
            return self::failure($path, 'script not found');
        }
        $level = ob_get_level();
        ob_start();
        try {
            $result = (static function (string $__file, array $params): mixed {
                return include $__file;
            })($file, self::params($paramText));
            $output = (string) ob_get_clean();
            return $output . (is_string($result) ? $result : '');
        } catch (\Throwable $e) {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
            Log::error("{script $path}: " . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
            return self::failure($path, $e->getMessage());
        }
    }

    private static function failure(string $path, string $message): string
    {
        return Config::get('debug', false)
            ? '<div class="alert alert-danger py-script-error">{script ' . e($path) . '}: ' . e($message) . '</div>'
            : '<!-- {script} error, see data/logs/pageyard.log -->';
    }
}
