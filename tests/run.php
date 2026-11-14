<?php
/**
 * Minimal test runner (no PHPUnit, so no Composer):  php tests/run.php [filter]
 * MySQL tests run only with PY_TEST_MYSQL=user[:password]@host[:port]; they create
 * and drop a pageyard_test_* database.
 */

if (PHP_SAPI !== 'cli') {
    exit;
}

define('PY_CLIENT', 'tests');
require dirname(__DIR__) . '/core/bootstrap.php';

restore_exception_handler();
Pageyard\Log::$file = sys_get_temp_dir() . '/pageyard-tests.log';
Pageyard\Lang::set('en');

final class AssertionFailed extends Exception
{
}

function check(bool $condition, string $message = 'assertion failed'): void
{
    if (!$condition) {
        throw new AssertionFailed($message);
    }
}

function same(mixed $expected, mixed $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        throw new AssertionFailed(($message !== '' ? "$message: " : '')
            . 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function throws(callable $fn, string $class = Throwable::class, string $contains = ''): void
{
    try {
        $fn();
    } catch (Throwable $e) {
        check($e instanceof $class, "expected $class, got " . get_class($e) . ': ' . $e->getMessage());
        check($contains === '' || str_contains($e->getMessage(), $contains), "message '{$e->getMessage()}' lacks '$contains'");
        return;
    }
    throw new AssertionFailed("expected $class to be thrown");
}

/** A fresh temporary site root with the folders the installer needs. */
function temp_root(): string
{
    $dir = sys_get_temp_dir() . '/pageyard-test-' . bin2hex(random_bytes(4));
    mkdir($dir . '/data', 0777, true);
    mkdir($dir . '/images');
    register_shutdown_function(fn () => remove_dir($dir));
    return $dir;
}

function remove_dir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($items as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($dir);
}

/** @var array<string, callable> $tests */
$tests = [];
foreach (glob(__DIR__ . '/*Test.php') as $file) {
    $tests += require $file;
}

$filter = $argv[1] ?? '';
$failed = 0;
$run = 0;
foreach ($tests as $name => $fn) {
    if ($filter !== '' && !str_contains($name, $filter)) {
        continue;
    }
    $run++;
    try {
        $result = $fn();
        echo $result === 'skip' ? "  skip  $name\n" : "  ok    $name\n";
    } catch (Throwable $e) {
        $failed++;
        $where = (string) $e;
        if ($e instanceof AssertionFailed) {
            $frames = array_filter($e->getTrace(), fn ($f) => str_ends_with($f['file'] ?? '', 'Test.php'));
            $frame = reset($frames) ?: ['file' => $e->getFile(), 'line' => $e->getLine()];
            $where = basename($frame['file']) . ':' . $frame['line'];
        }
        echo "  FAIL  $name\n        {$e->getMessage()}\n        $where\n";
    }
}
echo $failed ? "\n$failed of $run tests failed\n" : "\nAll $run tests passed\n";
exit($failed ? 1 : 0);
