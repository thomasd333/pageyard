<?php
/**
 * Builds a clean copy of Pageyard for installing a new site:
 *
 *     php tools/build.php [--dirty] [--skip-tests]
 *
 * Result in dist/: pageyard-<version>/ (folder), pageyard-<version>.zip and its .sha256.
 *
 * Only files git knows about are taken (tracked, or new and not ignored), so configuration.php, the
 * database, uploaded images and your own scripts can't end up in the package. Development files are left
 * out. The script refuses uncommitted changes unless --dirty is given, then checks the result: required
 * files, nothing private, PHP syntax, and the zip against the folder.
 */

if (PHP_SAPI !== 'cli') {
    exit;
}

const EXCLUDED = ['tests/', 'tools/', 'docs/', '.github/', '.gitattributes', '.gitignore'];
const REQUIRED = [
    '.htaccess', 'web.config', 'index.php', 'README.md', 'core/bootstrap.php', 'administrator/index.php', 'installation/index.php',
    'templates/site/index.php', 'language/en/meta.php', 'language/pl/meta.php', 'core/Database/migrations/0001_initial.php',
    'media/vendor/bootstrap/css/bootstrap.min.css', 'media/vendor/tinymce/tinymce.min.js', 'media/admin/admin.js',
    'data/.htaccess', 'images/.htaccess', 'scripts/.htaccess',
];
/** The only files allowed in folders that hold site data on a working installation. */
const ONLY_THESE = [
    'data/'    => ['data/.htaccess', 'data/web.config'],
    'images/'  => ['images/.gitkeep', 'images/.htaccess', 'images/web.config'],
    'templates/custom/' => ['templates/custom/README.md'],
    'scripts/' => ['scripts/.htaccess', 'scripts/web.config', 'scripts/README.md', 'scripts/built-in/contact.php', 'scripts/built-in/gallery.php', 'scripts/built-in/latest-pages.php', 'scripts/examples/hello.php'],
];

function fail(string $message): never
{
    fwrite(STDERR, "\nBUILD FAILED: $message\n");
    exit(1);
}

function step(string $text): void
{
    echo "$text\n";
}

/** Runs a program without a shell. Returns [exit code, stdout + stderr]. */
function run(array $command, string $cwd): array
{
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, $cwd);
    if (!is_resource($process)) {
        fail('Cannot run ' . $command[0]);
    }
    $output = (string) stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    return [proc_close($process), $output];
}

function git(string $root, string ...$args): string
{
    [$code, $output] = run(['git', ...$args], $root);
    if ($code !== 0) {
        fail('git ' . implode(' ', $args) . ": $output");
    }
    return $output;
}

function removeTree(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($items as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($dir);
}

$root = str_replace('\\', '/', dirname(__DIR__));
$options = array_slice($argv, 1);
if ($unknown = array_diff($options, ['--dirty', '--skip-tests'])) {
    fail('Unknown option: ' . implode(' ', $unknown));
}
if (!extension_loaded('zip')) {
    fail('The PHP zip extension is needed.');
}

if (!preg_match("/const PY_VERSION = '([^']+)';/", (string) file_get_contents("$root/core/bootstrap.php"), $m)) {
    fail('PY_VERSION not found in core/bootstrap.php.');
}
$version = $m[1];
$name = "pageyard-$version";
step("Pageyard $version");

$changes = trim(git($root, 'status', '--short'));
if ($changes !== '') {
    if (!in_array('--dirty', $options, true)) {
        fail("There are uncommitted changes. Commit them, or build anyway with --dirty:\n$changes");
    }
    step('Warning: building with uncommitted changes (--dirty).');
}

if (!in_array('--skip-tests', $options, true)) {
    step('Running the tests...');
    [$code, $output] = run([PHP_BINARY, 'tests/run.php'], $root);
    if ($code !== 0) {
        fail("Tests failed:\n$output");
    }
    $lines = array_values(array_filter(explode("\n", trim($output))));
    step('  ' . trim((string) end($lines)));
}

// The files: known to git, present on disk, not development files.
$files = [];
foreach (explode("\0", git($root, 'ls-files', '-z', '--cached', '--others', '--exclude-standard')) as $file) {
    if ($file === '' || !is_file("$root/$file")) {
        continue;
    }
    foreach (EXCLUDED as $prefix) {
        if ($file === $prefix || (str_ends_with($prefix, '/') && str_starts_with($file, $prefix))) {
            continue 2;
        }
    }
    $files[] = $file;
}
sort($files, SORT_STRING);

// Checks on the list.
foreach (REQUIRED as $file) {
    if (!in_array($file, $files, true)) {
        fail("Required file missing: $file");
    }
}
foreach ($files as $file) {
    if (preg_match('~(^|/)(configuration\.php|CLAUDE\.md)$|\.(sqlite|sqlite-wal|sqlite-shm|log|gz|code-workspace)$~i', $file)) {
        fail("A private or generated file would be packaged: $file");
    }
    foreach (ONLY_THESE as $folder => $allowed) {
        if (str_starts_with($file, $folder) && !in_array($file, $allowed, true)) {
            fail("Unexpected file in $folder (site data?): $file");
        }
    }
}

// The folder.
$dist = "$root/dist";
$target = "$dist/$name";
$zipFile = "$dist/$name.zip";
if (!is_dir($dist) && !mkdir($dist, 0775, true)) {
    fail("Cannot create $dist");
}
removeTree($target);
@unlink($zipFile);
@unlink("$zipFile.sha256");
foreach ($files as $file) {
    $to = "$target/$file";
    if ((!is_dir(dirname($to)) && !mkdir(dirname($to), 0775, true)) || !copy("$root/$file", $to)) {
        fail("Cannot copy $file");
    }
}
step('Copied ' . count($files) . " files to dist/$name/");

// Nothing from this machine in the text files.
$needles = array_filter(array_unique([
    $root, str_replace('/', '\\', $root), (string) getenv('USERPROFILE'),
    str_replace('\\', '/', (string) getenv('USERPROFILE')),
    trim(git($root, 'config', 'user.name')), trim(git($root, 'config', 'user.email')),
]));
foreach ($files as $file) {
    if (!preg_match('/\.(php|js|css|json|md|config|htaccess|txt|html|xml)$|(^|\/)\.htaccess$/i', $file)) {
        continue;
    }
    $text = (string) file_get_contents("$target/$file");
    foreach ($needles as $needle) {
        if (stripos($text, $needle) !== false) {
            fail("$file contains \"$needle\".");
        }
    }
}
step('Privacy scan: clean.');

// PHP syntax.
$php = array_values(array_filter($files, fn ($f) => str_ends_with($f, '.php')));
foreach ($php as $file) {
    [$code, $output] = run([PHP_BINARY, '-l', "$target/$file"], $root);
    if ($code !== 0) {
        fail("Syntax error in $file: $output");
    }
}
step('PHP syntax: ' . count($php) . ' files OK.');

// The zip: everything inside one folder named after the version.
$zip = new ZipArchive();
if ($zip->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    fail("Cannot create $zipFile");
}
foreach ($files as $file) {
    if (!$zip->addFile("$target/$file", "$name/$file")) {
        fail("Cannot add $file to the zip");
    }
}
if (!$zip->close()) {
    fail('Cannot write the zip.');
}

// Read it back.
$check = new ZipArchive();
if ($check->open($zipFile, ZipArchive::RDONLY) !== true || $check->numFiles !== count($files)) {
    fail('The zip does not match the file list.');
}
foreach ($files as $file) {
    if (hash('sha256', (string) $check->getFromName("$name/$file")) !== hash_file('sha256', "$target/$file")) {
        fail("The zip differs from the folder: $file");
    }
}
$check->close();

$hash = hash_file('sha256', $zipFile);
file_put_contents("$zipFile.sha256", "$hash  $name.zip\n");
step('');
step("dist/$name.zip  " . number_format(filesize($zipFile) / 1048576, 1) . " MB");
step("sha256 $hash");
step('Done.' . ($changes !== '' ? ' (built with uncommitted changes)' : ''));
