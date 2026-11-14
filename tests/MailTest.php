<?php

use Pageyard\Auth;
use Pageyard\Config;
use Pageyard\Database\Db;
use Pageyard\Database\Migrator;
use Pageyard\Mailer;
use Pageyard\PasswordReset;
use Pageyard\RateLimit;
use Pageyard\Settings;
use Pageyard\Site\ContactForm;
use Pageyard\Users;

defined('PAGEYARD') or exit;

/**
 * A database with one user, mail switched on and sent messages collected in the returned object.
 */
function mail_fixture(array $config = []): ArrayObject
{
    $db = memory_db();
    (new Migrator($db))->migrate();
    Db::setInstance($db);
    Settings::reset();
    Config::set($config + ['secret' => 'test', 'language' => 'en', 'languages' => 'en', 'sitename' => 'Site',
        'live_site' => 'https://example.com/cms', 'mail_transport' => 'mail', 'mail_from' => 'site@example.com']);
    $sent = new ArrayObject();
    Mailer::$fake = function (string $to, string $subject, string $text, string $replyTo) use ($sent) {
        $sent[] = compact('to', 'subject', 'text', 'replyTo');
        return null;
    };
    $_SERVER['REMOTE_ADDR'] = '10.0.0.1';
    $_SERVER['REQUEST_URI'] = '/cms/kontakt';
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_POST = $_GET = [];
    ContactForm::$now = null;
    return $sent;
}

function mail_user(): int
{
    [$id, $errors] = Users::save(0, ['name' => 'Anna', 'username' => 'anna', 'email' => 'anna@example.com', 'password' => 'oldpassword',
        'password2' => 'oldpassword', 'active' => true, 'admin' => true, 'language' => ''], 0);
    same([], $errors);
    return $id;
}

/** The token from a reset e-mail. */
function reset_token(array $mail): string
{
    check((bool) preg_match('~https://example\.com/cms/administrator/index\.php\?view=reset&token=([0-9a-f]{64})~', $mail['text'], $m), 'link in: ' . $mail['text']);
    return $m[1];
}

/** Posts the contact form with a stamp old enough to count. */
function post_contact(array $fields, array $params = []): string
{
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST = $fields + ['py_contact' => ContactForm::stamp(time() - 60), 'name' => 'Jan', 'email' => 'jan@example.org', 'message' => 'Dzień dobry'];
    $html = ContactForm::render($params);
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_POST = [];
    return $html;
}

/**
 * A minimal SMTP server for one connection: answers the given way and writes the conversation to a file.
 * @return array{resource, int} process and port
 */
function fake_smtp(string $log, bool $auth): array
{
    $server = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) substr(strrchr(stream_socket_get_name($server, false), ':'), 1);
    fclose($server);
    $code = 'error_reporting(0); $s = stream_socket_server("tcp://127.0.0.1:' . $port . '"); $c = stream_socket_accept($s, 10);'
        . ' $log = ' . var_export($log, true) . '; fwrite($c, "220 hi\r\n"); $data = false;'
        . ' while (($l = fgets($c)) !== false) { file_put_contents($log, $l, FILE_APPEND);'
        . ' if ($data) { if (rtrim($l) === ".") { $data = false; fwrite($c, "250 queued\r\n"); } continue; }'
        . ' $u = strtoupper(substr($l, 0, 4));'
        . ' if ($u === "EHLO") fwrite($c, "250-hello\r\n' . ($auth ? '250-AUTH LOGIN PLAIN\r\n' : '') . '250 OK\r\n");'
        . ' elseif ($u === "AUTH") fwrite($c, str_contains($l, ' . var_export(base64_encode("\0user\0good"), true) . ') ? "235 ok\r\n" : "535 no\r\n");'
        . ' elseif ($u === "DATA") { $data = true; fwrite($c, "354 go\r\n"); }'
        . ' elseif ($u === "QUIT") { fwrite($c, "221 bye\r\n"); break; }'
        . ' else fwrite($c, "250 ok\r\n"); }';
    $file = tempnam(sys_get_temp_dir(), 'smtp');
    file_put_contents($file, "<?php\n$code");
    $process = proc_open([PHP_BINARY, $file], [], $pipes);
    register_shutdown_function(fn () => @unlink($file));
    // Wait until it listens (without taking its one connection).
    usleep(600_000);
    return [$process, $port];
}

return [
    'mail: off or without a sender nothing is sent' => function () {
        $sent = mail_fixture(['mail_transport' => 'off']);
        check(!Mailer::enabled());
        check(Mailer::send('a@example.com', 's', 't') !== null);
        Config::set(['mail_transport' => 'smtp', 'mail_from' => 'not an address']);
        check(!Mailer::enabled());
        same(0, count($sent));
        Mailer::$fake = null;
    },

    'mail: SMTP conversation, encoding, no header injection' => function () {
        mail_fixture();
        Mailer::$fake = null;
        $log = sys_get_temp_dir() . '/py-smtp-' . bin2hex(random_bytes(4));
        register_shutdown_function(fn () => @unlink($log));
        [$process, $port] = fake_smtp($log, true);
        Config::set(array_merge(Config::all(), ['mail_transport' => 'smtp', 'smtp_host' => '127.0.0.1', 'smtp_port' => $port,
            'smtp_secure' => 'none', 'smtp_user' => 'user', 'smtp_password' => 'good', 'mail_from_name' => 'Zażółć']));
        same(null, Mailer::send('to@example.com', "Temat ą\r\nBcc: evil@example.com", ".kropka\nDruga linia", 'jan@example.org'));
        proc_close($process);
        $talk = (string) file_get_contents($log);
        check(str_contains($talk, 'AUTH PLAIN ' . base64_encode("\0user\0good")));
        check(str_contains($talk, 'MAIL FROM:<site@example.com>'));
        check(str_contains($talk, 'RCPT TO:<to@example.com>'));
        check(str_contains($talk, 'Reply-To: jan@example.org'));
        check(str_contains($talk, 'Subject: Temat =?UTF-8?B?'));
        check(str_contains($talk, 'From: =?UTF-8?B?'));
        check(!preg_match('/^Bcc:/mi', $talk), 'injected header');
        check(str_contains($talk, base64_encode(".kropka\nDruga linia")), 'body');
    },

    'mail: SMTP errors come back as text and don\'t show the password' => function () {
        mail_fixture();
        Mailer::$fake = null;
        $log = sys_get_temp_dir() . '/py-smtp-' . bin2hex(random_bytes(4));
        register_shutdown_function(fn () => @unlink($log));
        [$process, $port] = fake_smtp($log, true);
        Config::set(array_merge(Config::all(), ['mail_transport' => 'smtp', 'smtp_host' => '127.0.0.1', 'smtp_port' => $port,
            'smtp_secure' => 'none', 'smtp_user' => 'user', 'smtp_password' => 'bad-password']));
        $error = (string) Mailer::send('to@example.com', 's', 't');
        proc_terminate($process);
        proc_close($process);
        check(str_contains($error, 'refused (authentication)'), $error);
        check(!str_contains($error, base64_encode("\0user\0bad-password")));

        Config::set(array_merge(Config::all(), ['smtp_port' => 1]));
        check(str_contains((string) Mailer::send('to@example.com', 's', 't'), 'Cannot connect'));
        check(str_contains((string) Mailer::send('nope', 's', 't'), 'Not a valid recipient'));
    },

    'rate limit: counts per key and window' => function () {
        mail_fixture();
        check(RateLimit::hit('x', 'a', 2, 60));
        check(RateLimit::hit('x', 'a', 2, 60));
        check(!RateLimit::hit('x', 'a', 2, 60));
        check(RateLimit::hit('x', 'b', 2, 60), 'another key');
        check(RateLimit::hit('y', 'a', 2, 60), 'another action');
        Db::get()->query('UPDATE #__rate_limits SET created = ?', [gmdate('Y-m-d H:i:s', time() - 7200)]);
        check(RateLimit::hit('x', 'a', 2, 60), 'window passed');
        Db::get()->query('UPDATE #__rate_limits SET created = ?', [gmdate('Y-m-d H:i:s', time() - 90000)]);
        RateLimit::hit('z', 'a', 2, 60);
        same(1, (int) Db::get()->fetchValue('SELECT COUNT(*) FROM #__rate_limits'), 'old rows are removed');
    },

    'password reset: the whole way' => function () {
        $sent = mail_fixture();
        $id = mail_user();
        check(PasswordReset::available());

        PasswordReset::request('nobody@example.com');
        same(0, count($sent), 'unknown account');

        PasswordReset::request('ANNA@example.com ');
        same(1, count($sent));
        same('anna@example.com', $sent[0]['to']);
        $token = reset_token($sent[0]);
        check(!str_contains((string) Db::get()->fetchValue('SELECT token_hash FROM #__password_resets'), $token), 'only a hash is stored');
        same($id, (int) PasswordReset::userFor($token)['id']);
        same(null, PasswordReset::userFor('x'));
        same(null, PasswordReset::userFor(str_repeat('a', 64)));

        check(isset(PasswordReset::complete($token, 'short', 'short')['password']));
        check(isset(PasswordReset::complete($token, 'newpassword', 'other')['password2']));
        check(PasswordReset::userFor($token) !== null, 'errors don\'t use up the token');
        same([], PasswordReset::complete($token, 'newpassword', 'newpassword'));
        check(password_verify('newpassword', (string) Db::get()->fetchValue('SELECT password FROM #__users WHERE id = ?', [$id])));
        same(null, PasswordReset::userFor($token), 'works once');
        check(isset(PasswordReset::complete($token, 'another1', 'another1')['token']));
        Mailer::$fake = null;
    },

    'password reset: expiry, blocked users, a newer link replaces the old one, limits' => function () {
        $sent = mail_fixture();
        $id = mail_user();
        PasswordReset::request('anna');
        $first = reset_token($sent[0]);
        PasswordReset::request('anna');
        same(null, PasswordReset::userFor($first), 'replaced');
        $second = reset_token($sent[1]);
        Db::get()->query('UPDATE #__password_resets SET created = ?', [gmdate('Y-m-d H:i:s', time() - 3700)]);
        same(null, PasswordReset::userFor($second), 'expired');

        PasswordReset::request('anna');
        same(3, count($sent));
        PasswordReset::request('anna');
        same(3, count($sent), 'three per hour for a user');

        Db::get()->query('DELETE FROM #__rate_limits');
        Db::get()->update('#__users', ['active' => 0], ['id' => $id]);
        PasswordReset::request('anna');
        same(3, count($sent), 'blocked');
        Mailer::$fake = null;
    },

    'password reset: the link never uses the request\'s host' => function () {
        $sent = mail_fixture(['live_site' => '']);
        mail_user();
        $_SERVER['HTTP_HOST'] = 'evil.example';
        $script = $_SERVER['SCRIPT_NAME'];
        $_SERVER['SCRIPT_NAME'] = '/administrator/index.php';
        check(!PasswordReset::available(), 'no known address yet');
        PasswordReset::request('anna');
        same(0, count($sent));

        Settings::set('site_origin', 'https://real.example');
        check(PasswordReset::available());
        PasswordReset::request('anna');
        check(str_contains($sent[0]['text'], 'https://real.example/'), $sent[0]['text']);
        check(!str_contains($sent[0]['text'], 'evil.example'));
        unset($_SERVER['HTTP_HOST']);
        $_SERVER['SCRIPT_NAME'] = $script;
        Mailer::$fake = null;
    },

    'password change ends other sessions, but not the changing one' => function () {
        mail_fixture();
        $id = mail_user();
        $_SESSION = [];
        same(null, Auth::login('anna', 'oldpassword'));
        check(Auth::user() !== null);

        Users::setPassword($id, 'changedpass');
        $reset = new ReflectionProperty(Auth::class, 'user');
        $reset->setValue(null, null);
        same(null, Auth::user(), 'old session ended');

        $_SESSION = [];
        same(null, Auth::login('anna', 'changedpass'));
        $reset->setValue(null, null);
        Users::setPassword($id, 'changedagain');
        Auth::passwordChanged();
        $reset->setValue(null, null);
        same($id, (int) Auth::user()['id'], 'this session stays');

        // Sessions from before this feature have no fingerprint and carry on.
        $_SESSION = ['user_id' => $id, 'last_seen' => time()];
        $reset->setValue(null, null);
        same($id, (int) Auth::user()['id']);
        check(isset($_SESSION['password']));
        $reset->setValue(null, null);
        $_SESSION = [];
        Mailer::$fake = null;
    },

    'password: setting it clears failed logins' => function () {
        mail_fixture();
        $id = mail_user();
        $_SESSION = [];
        for ($i = 0; $i < Auth::MAX_PER_USER; $i++) {
            Db::get()->insert('#__login_attempts', ['ip' => '1.1.1.1', 'username' => 'anna', 'created' => Db::now()]);
        }
        check(Auth::login('anna', 'oldpassword') !== null, 'locked out');
        Users::setPassword($id, 'freshpassword');
        same(null, Auth::login('anna', 'freshpassword'));
        (new ReflectionProperty(Auth::class, 'user'))->setValue(null, null);
        $_SESSION = [];
        Mailer::$fake = null;
    },

    'contact form: shown, validated, sent' => function () {
        $sent = mail_fixture(['contact_email' => 'biuro@example.com']);
        $html = ContactForm::render([]);
        check(str_contains($html, 'name="py_contact"'));
        check(str_contains($html, 'name="website"'), 'trap');

        $html = post_contact(['name' => '', 'email' => 'nope', 'message' => '']);
        same(3, substr_count($html, 'invalid-feedback d-block'));
        same(0, count($sent));
        $html = post_contact(['message' => '<b>keep</b>', 'email' => 'nope']);
        check(str_contains($html, '&lt;b&gt;keep&lt;/b&gt;'), 'typed text stays, escaped');

        // headers_sent() is true under the test runner's output, so the success shows in place.
        $html = post_contact(['message' => "Linia 1\nLinia 2", 'name' => "Jan\r\nBcc: evil@example.com"]);
        check(str_contains($html, 'alert-success'), $html);
        same(1, count($sent));
        same('biuro@example.com', $sent[0]['to']);
        same('jan@example.org', $sent[0]['replyTo']);
        check(str_contains($sent[0]['text'], "Linia 1\nLinia 2"));
        check(str_contains($sent[0]['text'], 'https://example.com/cms/kontakt'));

        post_contact([], ['to' => 'inny@example.com', 'subject' => 'Zapytanie']);
        same('inny@example.com', $sent[1]['to']);
        same('Zapytanie', $sent[1]['subject']);
        Mailer::$fake = null;
    },

    'contact form: spam protection' => function () {
        $sent = mail_fixture();
        check(str_contains(post_contact(['website' => 'http://spam']), 'alert-success'), 'looks sent');
        same(0, count($sent), 'the trap');

        foreach ([ContactForm::stamp(time()), ContactForm::stamp(time() - 90000), 'x', '', time() - 60 . '.forged'] as $stamp) {
            check(str_contains(post_contact(['py_contact' => $stamp]), t('CONTACT_ERR_TIME')), "stamp $stamp");
        }
        same(0, count($sent));

        for ($i = 0; $i < ContactForm::LIMIT_PER_HOUR; $i++) {
            check(str_contains(post_contact([]), 'alert-success'));
        }
        check(str_contains(post_contact([]), t('CONTACT_ERR_LIMIT')));
        same(ContactForm::LIMIT_PER_HOUR, count($sent));
        Mailer::$fake = null;
    },

    'contact form: consent, and a site without e-mail' => function () {
        $sent = mail_fixture();
        $params = ['consent' => 'Zgadzam się'];
        check(str_contains(ContactForm::render($params), 'Zgadzam się'));
        check(str_contains(post_contact([], $params), 'id="py-contact-consent"'), 'required');
        same(0, count($sent));
        check(str_contains(post_contact(['consent' => '1'], $params), 'alert-success'));
        same(1, count($sent));

        Config::set(['mail_transport' => 'off']);
        check(str_contains(ContactForm::render([]), e(t('CONTACT_UNAVAILABLE'))));
        Mailer::$fake = null;
    },
];
