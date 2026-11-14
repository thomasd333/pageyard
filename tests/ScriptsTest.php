<?php

use Pageyard\Config;
use Pageyard\Log;
use Pageyard\Site\Html;
use Pageyard\Site\Scripts;

defined('PAGEYARD') or exit;

/** A temporary scripts/ folder with a few scripts; returns the site root. */
function scripts_fixture(): string
{
    $root = temp_root();
    mkdir("$root/scripts/sub", 0777, true);
    file_put_contents("$root/scripts/hello.php", "<?php\n/**\n * Says hello.\n * @param name who\n * @example name=\"Anna Nowak\"\n */\ndefined('PAGEYARD') or exit;\necho '<b>Hello ' . e(\$params['name'] ?? 'world') . '</b>';\n");
    file_put_contents("$root/scripts/sub/block.php", "<?php\ndefined('PAGEYARD') or exit;\nreturn '<div class=\"x\">' . count(\$params) . '</div>';\n");
    file_put_contents("$root/scripts/broken.php", "<?php\ndefined('PAGEYARD') or exit;\necho 'half';\nthrow new RuntimeException('boom');\n");
    file_put_contents("$root/scripts/echo-tag.php", "<?php\ndefined('PAGEYARD') or exit;\necho '<p>{script hello}</p>';\n");
    file_put_contents("$root/scripts/notes.txt", 'not a script');
    file_put_contents("$root/secret.php", "<?php\necho 'SECRET';\n");
    Scripts::$dir = "$root/scripts";
    Log::$file = "$root/data/test.log";
    Config::set(['debug' => false]);
    return $root;
}

return [
    'scripts: parameters' => function () {
        same(['a' => '1', 'b' => 'two words', 'c' => 'x y', 'flag' => true, 'd' => ''],
            Scripts::params(' a=1 b="two words" c=\'x y\' flag d=""'));
        same(['name' => 'Anna Nowak', 'q' => 'a<b'], Scripts::params('name=&quot;Anna Nowak&quot;&nbsp;q=a&lt;b'), 'entities from the editor');
        same(['t' => 'cytat'], Scripts::params('t=„cytat”'), 'Polish quotes typed in the editor');
        same([], Scripts::params(''));
    },

    'scripts: resolving paths stays inside scripts/' => function () {
        $root = scripts_fixture();
        check(Scripts::resolve('hello') !== null);
        same(Scripts::resolve('hello'), Scripts::resolve('hello.php'));
        check(Scripts::resolve('sub/block') !== null);
        same(null, Scripts::resolve('../secret'), 'outside');
        same(null, Scripts::resolve('sub/../../secret.php'));
        same(null, Scripts::resolve('notes.txt'), 'not .php');
        same(null, Scripts::resolve('missing'));
        same(null, Scripts::resolve('a b'));
        same(null, Scripts::resolve(''));
        check(is_file("$root/secret.php"));
    },

    'scripts: tags in content' => function () {
        scripts_fixture();
        same('<p>Hi: <b>Hello Anna Nowak</b>!</p>', Scripts::render('<p>Hi: {script hello name="Anna Nowak"}!</p>'));
        same('<div class="x">2</div>', Scripts::render('<p>{script sub/block a=1 b=2}</p>'), 'alone in a paragraph: replaces it');
        same('<div class="x">1</div>', Scripts::render('<p class="lead">&nbsp;{script&nbsp;sub/block&nbsp;a=1}&nbsp;</p>'));
        same('<div class="x">1</div>', Scripts::render("<p>\u{a0}{script\u{a0}sub/block\u{a0}a=1}\u{a0}</p>"), 'raw non-breaking spaces (entity_encoding raw)');
        same('<b>Hello world</b><b>Hello world</b>', Scripts::render('{script hello}{SCRIPT hello.php}'));
        same('<p>{script hello}</p>', Scripts::render('<p>{script echo-tag}</p>'), 'output is not scanned again');
        same('{scripts} {script}', Scripts::render('{scripts} {script}'), 'not tags');
        same('<p>No tags</p>', Scripts::render('<p>No tags</p>'));
        same('<p><a href="/cms/o-nas">x</a></p><b>Hello world</b>',
            (function () { Config::set(['live_site' => 'http://example.com/cms', 'debug' => false]); return Html::content('<p><a href="o-nas">x</a></p><p>{script hello}</p>'); })());
    },

    'scripts: errors are logged, details only with debug' => function () {
        $root = scripts_fixture();
        $level = ob_get_level();
        same('<p>a <!-- {script} error, see data/logs/pageyard.log --> b</p>', Scripts::render('<p>a {script broken} b</p>'));
        same($level, ob_get_level(), 'output buffers cleaned up');
        same('<!-- {script} error, see data/logs/pageyard.log -->', Scripts::render('{script ../secret}'));
        $log = (string) file_get_contents("$root/data/test.log");
        check(str_contains($log, '{script broken}: boom') && str_contains($log, '{script ../secret}: not found'), $log);
        Config::set(['debug' => true]);
        same('<div class="alert alert-danger py-script-error">{script broken}: boom</div>', Scripts::render('<p>{script broken}</p>'));
    },

    'scripts: list for the editor' => function () {
        scripts_fixture();
        same([
            ['path' => 'broken', 'description' => '', 'example' => ''],
            ['path' => 'echo-tag', 'description' => '', 'example' => ''],
            ['path' => 'hello', 'description' => 'Says hello.', 'example' => 'name="Anna Nowak"'],
            ['path' => 'sub/block', 'description' => '', 'example' => ''],
        ], Scripts::available());
        Scripts::$dir = null;
        $paths = array_column(Scripts::available(), 'path');
        check(in_array('built-in/gallery', $paths, true) && in_array('built-in/contact', $paths, true)
            && in_array('built-in/latest-pages', $paths, true) && in_array('examples/hello', $paths, true), implode(',', $paths));
    },

    'scripts: tags from when the built-in ones lived in examples/ keep working' => function () {
        Scripts::$dir = null;
        same(Scripts::resolve('built-in/gallery'), Scripts::resolve('examples/gallery'));
        check(Scripts::resolve('built-in/gallery') !== null);
        check(Scripts::resolve('examples/hello') !== null, 'still there');
        same(null, Scripts::resolve('examples/nothing'));
        same(null, Scripts::resolve('examples/../../configuration'));
    },
];
