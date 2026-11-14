<?php

namespace Pageyard;

defined('PAGEYARD') or exit;

/**
 * Plain-text e-mail through PHP's mail() or a small SMTP client (no libraries).
 * send() never throws: it returns an error message, and the caller decides what the user sees.
 */
final class Mailer
{
    public const TRANSPORTS = ['off', 'mail', 'smtp'];
    public const SECURE = ['none', 'tls', 'ssl'];
    private const TIMEOUT = 15;

    /** Tests: receives (to, subject, text, replyTo) instead of sending; returns an error or null. */
    public static ?\Closure $fake = null;

    public static function enabled(): bool
    {
        return in_array(Config::get('mail_transport', 'off'), ['mail', 'smtp'], true)
            && filter_var((string) Config::get('mail_from', ''), FILTER_VALIDATE_EMAIL) !== false;
    }

    /** Sends a message; returns null, or an error message (also logged). */
    public static function send(string $to, string $subject, string $text, string $replyTo = ''): ?string
    {
        try {
            if (!self::enabled()) {
                throw new \RuntimeException('Sending e-mail is switched off (Global Configuration > E-mail).');
            }
            $to = self::oneLine($to);
            $replyTo = self::oneLine($replyTo);
            if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
                throw new \RuntimeException("Not a valid recipient: $to");
            }
            if (self::$fake !== null) {
                $error = (self::$fake)($to, $subject, $text, $replyTo);
                if ($error !== null) {
                    throw new \RuntimeException($error);
                }
                return null;
            }
            $headers = self::headers($replyTo);
            $body = chunk_split(base64_encode(str_replace(["\r\n", "\r"], "\n", $text)));
            $encoded = self::encode(self::oneLine($subject));
            if (Config::get('mail_transport') === 'smtp') {
                self::smtp($to, $encoded, $headers, $body);
            } elseif (!@mail($to, $encoded, $body, implode("\r\n", $headers))) {
                throw new \RuntimeException('PHP\'s mail() refused the message. Check the server\'s mail settings, or use SMTP.');
            }
            return null;
        } catch (\Throwable $e) {
            Log::error('E-mail not sent: ' . $e->getMessage());
            return $e->getMessage();
        }
    }

    /** @return list<string> */
    private static function headers(string $replyTo): array
    {
        $from = (string) Config::get('mail_from');
        $name = self::oneLine((string) Config::get('mail_from_name', ''));
        $headers = [
            'From: ' . ($name !== '' ? self::encode($name) . " <$from>" : $from),
            'Date: ' . date('r'),
            'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . substr((string) strrchr($from, '@'), 1) . '>',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
        ];
        if ($replyTo !== '' && filter_var($replyTo, FILTER_VALIDATE_EMAIL) !== false) {
            $headers[] = "Reply-To: $replyTo";
        }
        return $headers;
    }

    /** @param list<string> $headers */
    private static function smtp(string $to, string $subject, array $headers, string $body): void
    {
        $host = (string) Config::get('smtp_host');
        $port = (int) Config::get('smtp_port', 587);
        $secure = (string) Config::get('smtp_secure', 'tls');
        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_client(($secure === 'ssl' ? 'ssl://' : 'tcp://') . "$host:$port", $errno, $errstr, self::TIMEOUT);
        if ($socket === false) {
            throw new \RuntimeException("Cannot connect to $host:$port: $errstr");
        }
        try {
            stream_set_timeout($socket, self::TIMEOUT);
            $from = (string) Config::get('mail_from');
            $domain = substr((string) strrchr($from, '@'), 1);
            self::expect($socket, 220);
            $hello = self::command($socket, "EHLO $domain", 250);
            if ($secure === 'tls') {
                self::command($socket, 'STARTTLS', 220);
                if (@stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT) !== true) {
                    throw new \RuntimeException('The TLS connection could not be set up.');
                }
                $hello = self::command($socket, "EHLO $domain", 250);
            }
            $user = (string) Config::get('smtp_user', '');
            if ($user !== '') {
                $password = (string) Config::get('smtp_password', '');
                if (preg_match('/^250[ -]AUTH\b.*\bPLAIN\b/mi', $hello)) {
                    self::command($socket, 'AUTH PLAIN ' . base64_encode("\0$user\0$password"), 235, true);
                } else {
                    self::command($socket, 'AUTH LOGIN', 334);
                    self::command($socket, base64_encode($user), 334, true);
                    self::command($socket, base64_encode($password), 235, true);
                }
            }
            self::command($socket, "MAIL FROM:<$from>", 250);
            self::command($socket, "RCPT TO:<$to>", [250, 251]);
            self::command($socket, 'DATA', 354);
            $lines = [...$headers, "To: $to", "Subject: $subject", ''];
            $message = implode("\r\n", $lines) . "\r\n" . str_replace("\n", "\r\n", str_replace("\r\n", "\n", $body));
            // The body is base64, but a leading dot must be doubled anywhere in a message.
            $message = (string) preg_replace('/^\./m', '..', $message);
            fwrite($socket, rtrim($message, "\r\n") . "\r\n.\r\n");
            self::expect($socket, 250);
            fwrite($socket, "QUIT\r\n");
        } finally {
            fclose($socket);
        }
    }

    /**
     * @param resource $socket
     * @param int|list<int> $codes
     * @param bool $secret the command is not repeated in an error
     */
    private static function command($socket, string $line, int|array $codes, bool $secret = false): string
    {
        fwrite($socket, "$line\r\n");
        return self::expect($socket, $codes, $secret ? 'authentication' : $line);
    }

    /**
     * Reads a reply (possibly several lines) and checks its code.
     * @param resource $socket
     * @param int|list<int> $codes
     */
    private static function expect($socket, int|array $codes, string $after = 'connecting'): string
    {
        $reply = '';
        while (($line = fgets($socket, 1024)) !== false) {
            $reply .= $line;
            if (strlen($line) < 4 || $line[3] !== '-') {
                break;
            }
        }
        if ($reply === '') {
            throw new \RuntimeException("The mail server didn't answer ($after).");
        }
        if (!in_array((int) substr($reply, 0, 3), (array) $codes, true)) {
            throw new \RuntimeException("The mail server refused ($after): " . trim($reply));
        }
        return $reply;
    }

    /** No line breaks: nothing from a form can add a header. */
    private static function oneLine(string $text): string
    {
        return trim((string) preg_replace('/[\x00-\x1f\x7f]+/', ' ', $text));
    }

    /** Header text in UTF-8 when it isn't plain ASCII. */
    private static function encode(string $text): string
    {
        return preg_match('/[^\x20-\x7e]/', $text) ? mb_encode_mimeheader($text, 'UTF-8', 'B', "\r\n") : $text;
    }
}
