<?php

namespace Pageyard;

defined('PAGEYARD') or exit;

/**
 * Turns PHP warnings into exceptions and shows a plain error page.
 * Details are shown only with $debug on (or before installation); they always go to the log.
 */
final class ErrorHandler
{
    public static function register(): void
    {
        error_reporting(E_ALL);
        ini_set('display_errors', '0');

        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            if (!(error_reporting() & $severity)) {
                return false;
            }
            throw new \ErrorException($message, 0, $severity, $file, $line);
        });

        set_exception_handler([self::class, 'handle']);
    }

    public static function handle(\Throwable $e): void
    {
        Log::error(get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine()
            . "\n" . $e->getTraceAsString());

        $debug = !Config::isLoaded() || Config::get('debug', false);

        if (PHP_SAPI === 'cli') {
            fwrite(STDERR, $debug ? (string) $e : $e->getMessage());
            fwrite(STDERR, "\n");
            exit(1);
        }

        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: text/html; charset=utf-8');
        }

        $details = $debug
            ? '<pre style="white-space:pre-wrap">' . e((string) $e) . '</pre>'
            : '<p>The error has been written to the log.</p>';
        echo '<!doctype html><meta charset="utf-8"><title>Error</title>'
            . '<div style="font-family:system-ui,sans-serif;max-width:60rem;margin:3rem auto;padding:0 1rem">'
            . '<h1>Something went wrong</h1>' . $details . '</div>';
    }
}
