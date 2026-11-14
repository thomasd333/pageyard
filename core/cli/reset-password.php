<?php
/**
 * Emergency password reset, for when you can't log in and e-mail isn't set up:
 *
 *     php core/cli/reset-password.php                  lists the users
 *     php core/cli/reset-password.php admin            sets a random password and prints it
 *     php core/cli/reset-password.php admin "my pass"  sets this password
 *
 * Also clears failed login attempts, so a blocked login works at once.
 */

if (PHP_SAPI !== 'cli') {
    exit;
}

define('PY_CLIENT', 'cli');
require dirname(__DIR__) . '/bootstrap.php';

use Pageyard\Config;
use Pageyard\Database\Db;
use Pageyard\Users;

if (!Config::exists()) {
    fwrite(STDERR, "Pageyard is not installed here (no configuration.php).\n");
    exit(1);
}

$db = Db::get();
$login = strtolower(trim($argv[1] ?? ''));
if ($login === '') {
    echo "Usage: php core/cli/reset-password.php <username or e-mail> [new password]\n\nUsers:\n";
    foreach ($db->fetchAll('SELECT username, email, active, admin FROM #__users ORDER BY admin DESC, username') as $u) {
        echo "  {$u['username']} <{$u['email']}>" . ($u['admin'] ? ' (administrator)' : '') . ($u['active'] ? '' : ' (blocked)') . "\n";
    }
    exit(0);
}

$user = $db->fetchRow('SELECT * FROM #__users WHERE username = ? OR email = ?', [$login, $login]);
if ($user === null) {
    fwrite(STDERR, "No user \"$login\". Run without arguments to list the users.\n");
    exit(1);
}

$password = $argv[2] ?? bin2hex(random_bytes(8));
if (mb_strlen($password) < Users::MIN_PASSWORD) {
    fwrite(STDERR, 'The password needs at least ' . Users::MIN_PASSWORD . " characters.\n");
    exit(1);
}
Users::setPassword((int) $user['id'], $password);
Pageyard\Log::info("Password reset from the command line: {$user['username']}");

echo "Password of {$user['username']} changed.\n";
if (!isset($argv[2])) {
    echo "New password: $password\n";
}
if (!$user['admin']) {
    echo "Note: this account is not an administrator, so it can only log in on the site.\n";
}
if (!$user['active']) {
    echo "Note: this account is blocked. Another administrator can activate it under Users.\n";
}
