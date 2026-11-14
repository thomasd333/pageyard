# scripts/

Your own PHP scripts, embedded in content (pages, category descriptions, HTML blocks) with

    {script path/inside/scripts key=value other="quoted value" flag}

or with the "Insert script" button in the editor.

- The path is relative to this folder; `.php` may be left out. It must stay inside `scripts/`.
- Parameters arrive in `$params` (strings; a key without a value is `true`).
- Print the output (`echo`, or HTML after `?>`), or `return` a string. Escape what you print with `e()`.
- A tag alone in a paragraph replaces the whole paragraph, so a script may output block elements.
- Start every script with `defined('PAGEYARD') or exit;`. Pageyard's classes are available
  (`Pageyard\Lang`, `Pageyard\Site\Content`, `Pageyard\Site\Router`, `Pageyard\Database\Db`...).
- Errors are written to `data/logs/pageyard.log`; with `$debug` on they are also shown on the page.
- Describe a script in its first doc comment: the first line is shown in the editor's dialog,
  `@example key=value` fills in the parameters. See `examples/hello.php`.

`built-in/` holds the scripts that come with Pageyard (contact form, gallery, latest pages): leave them where they
are, because an update replaces them. Copy one under another name to change it. `examples/` holds a sample to learn from.
Only these two folders are part of Pageyard; your own scripts are not tracked by git.

The folder is closed to direct web access (.htaccess for Apache, web.config for IIS).
On nginx add `location ^~ /scripts/ { deny all; }` yourself.
