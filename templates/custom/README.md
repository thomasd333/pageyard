# templates/custom/

Your changes to the site template. An update of Pageyard replaces `templates/site/` but brings nothing
into this folder, so what you put here stays.

- **Change a view or the frame:** copy a file from `templates/site/` here under the same path and edit the
  copy, e.g. `templates/site/html/blog.php` to `templates/custom/html/blog.php`. Pageyard uses your copy
  instead. Available: `index.php` (the frame), `html/page.php`, `blog.php`, `list.php`, `blocks.php`,
  `error.php`, `offline.php`, `search.php`, `account.php` (logging in, registration, profile).
- **Add styles:** create `css/custom.css`. It is loaded after Bootstrap and `template.css`, without touching
  any other file. Styles typed in System > Template come after it.
- **Images, fonts, scripts:** put them here too and link them as `templates/custom/...` from the site's root.

Copied files start with `defined('PAGEYARD') or exit;` like the originals. PHP files in this folder can't be
requested directly on Apache.

System > Template > Files lists your files. A copy marked "original is newer" may lack something that changed
in `templates/site/`: compare the two.

Only this README is part of Pageyard; your files are not tracked by git.
