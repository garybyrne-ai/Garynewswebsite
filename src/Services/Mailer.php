<?php
declare(strict_types=1);

namespace MeNews\Services;

use MeNews\Config;
use MeNews\Database;

/**
 * Outbound email without dependencies. The transport is set in the newsroom
 * (Settings → Email delivery), falling back to MAIL_TRANSPORT in .env:
 *   log   - append to storage/logs/mail.log (default in development)
 *   mail  - PHP mail() (works on most shared hosts such as Cloudways)
 *   smtp  - SMTP with STARTTLS/SSL, for Brevo's relay or any other host
 *   brevo - Brevo's transactional API over HTTPS, which needs no open mail port
 * Every message gets a plain-text alternative and a branded HTML wrapper.
 */
final class Mailer
{
    public const TRANSPORTS = ['log', 'mail', 'smtp', 'brevo'];

    /** A newsroom setting, falling back to .env and then the given default. */
    public static function setting(string $key, string $env, string $default = ''): string
    {
        $v = '';
        try {
            $v = Database::installed() ? trim((string)Database::setting('mail_' . $key, '')) : '';
        } catch (\Throwable $e) {
            $v = '';
        }
        return $v !== '' ? $v : Config::get($env, $default);
    }

    public static function transport(): string
    {
        $t = strtolower(self::setting('transport', 'MAIL_TRANSPORT', Config::production() ? 'mail' : 'log'));
        return in_array($t, self::TRANSPORTS, true) ? $t : 'log';
    }

    public static function from(): string
    {
        $host = parse_url(Config::baseUrl(), PHP_URL_HOST) ?: 'menews.ie';
        $from = self::setting('from', 'MAIL_FROM', 'news@' . $host);
        return filter_var($from, FILTER_VALIDATE_EMAIL) ? $from : 'news@' . $host;
    }

    public static function fromName(): string
    {
        return self::setting('from_name', 'MAIL_FROM_NAME', Config::appName());
    }

    public static function replyTo(): string
    {
        $r = self::setting('reply_to', 'MAIL_REPLY_TO', '');
        return filter_var($r, FILTER_VALIDATE_EMAIL) ? $r : self::from();
    }

    /** True when the chosen transport has everything it needs. */
    public static function configured(): bool
    {
        return match (self::transport()) {
            'smtp' => self::setting('smtp_host', 'SMTP_HOST') !== '',
            'brevo' => Secrets::get('BREVO_API_KEY') !== '',
            default => true,
        };
    }

    /** Send. Returns true when handed to a transport (or logged). Never throws to callers. */
    public static function send(string $to, string $subject, string $html, string $text = ''): bool
    {
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return false;
        }
        $text = $text !== '' ? $text : trim(html_entity_decode(strip_tags(preg_replace('/<br\s*\/?>|<\/p>|<\/h\d>|<\/li>/i', "\n", $html) ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $html = self::wrap($subject, $html);
        try {
            $ok = match (self::transport()) {
                'mail' => self::viaMail($to, $subject, $html, $text),
                'smtp' => self::viaSmtp($to, $subject, $html, $text),
                'brevo' => self::viaBrevo($to, $subject, $html, $text),
                default => self::viaLog($to, $subject, $text),
            };
            self::record($ok ? '' : 'The mail server accepted nothing back');
            return $ok;
        } catch (\Throwable $e) {
            error_log('Mailer: ' . $e->getMessage());
            self::record($e->getMessage());
            // Never lose the message: fall back to the log so the newsroom can see what failed.
            return self::viaLog($to, $subject, $text . "\n[transport error: " . $e->getMessage() . ']');
        }
    }

    /** Remember how the last send went, so Settings can show it. */
    private static function record(string $error): void
    {
        try {
            if (!Database::installed()) {
                return;
            }
            Database::setSetting('mail_last_error', $error);
            if ($error === '') {
                Database::setSetting('mail_last_sent_at', now());
            }
        } catch (\Throwable $e) {
            // never let bookkeeping break a send
        }
    }

    private static function wrap(string $subject, string $body): string
    {
        $base = rtrim(Config::baseUrl(), '/');
        return '<!doctype html><html><head><meta charset="utf-8"><title>' . e($subject) . '</title></head>'
            . '<body style="margin:0;background:#f3f7f4;font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:#0b1410">'
            . '<div style="max-width:620px;margin:0 auto;padding:24px 16px">'
            . '<div style="padding:14px 0 18px;font-weight:800;font-size:20px;letter-spacing:-.02em"><span style="color:#139a5c">ME</span> News <span style="font-weight:500;font-size:11px;letter-spacing:.2em;color:#59685f">IRELAND</span></div>'
            . '<div style="background:#fff;border:1px solid #d9e3dc;border-radius:16px;padding:22px 24px;font-size:16px;line-height:1.6">' . $body . '</div>'
            . '<p style="color:#59685f;font-size:12px;line-height:1.5;padding:16px 4px">ME News Ireland · <a href="' . e($base) . '/privacy" style="color:#59685f">Privacy</a> · <a href="' . e($base) . '/about" style="color:#59685f">How we check things</a></p>'
            . '</div></body></html>';
    }

    private static function headers(string $boundary): array
    {
        return [
            'From: ' . self::encodeName(self::fromName()) . ' <' . self::from() . '>',
            'Reply-To: ' . self::replyTo(),
            'MIME-Version: 1.0',
            'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
            'X-Mailer: MENews/' . ME_VERSION,
        ];
    }

    private static function body(string $boundary, string $html, string $text): string
    {
        return "--{$boundary}\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n{$text}\r\n\r\n"
            . "--{$boundary}\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n{$html}\r\n\r\n--{$boundary}--\r\n";
    }

    private static function encodeName(string $name): string
    {
        return preg_match('/^[\x20-\x7E]+$/', $name) ? '"' . str_replace('"', '', $name) . '"' : '=?UTF-8?B?' . base64_encode($name) . '?=';
    }

    /** Brevo's transactional API: HTTPS only, so it works where SMTP ports are blocked. */
    private static function viaBrevo(string $to, string $subject, string $html, string $text): bool
    {
        $key = Secrets::get('BREVO_API_KEY');
        if ($key === '') {
            throw new \RuntimeException('Brevo API key is not set');
        }
        $res = Remote::json('https://api.brevo.com/v3/smtp/email', [
            'sender' => ['name' => self::fromName(), 'email' => self::from()],
            'replyTo' => ['email' => self::replyTo()],
            'to' => [['email' => $to]],
            'subject' => $subject,
            'htmlContent' => $html,
            'textContent' => $text,
        ], ['api-key: ' . $key, 'accept: application/json'], 'json', 30);
        if (!empty($res['messageId'])) {
            return true;
        }
        throw new \RuntimeException('Brevo refused the message: ' . ($res['message'] ?? json_encode($res)));
    }

    private static function viaMail(string $to, string $subject, string $html, string $text): bool
    {
        $b = 'me-' . bin2hex(random_bytes(8));
        return mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', self::body($b, $html, $text), implode("\r\n", self::headers($b)));
    }

    private static function viaLog(string $to, string $subject, string $text): bool
    {
        $line = '[' . now() . "] To: {$to}\nSubject: {$subject}\n{$text}\n" . str_repeat('-', 60) . "\n";
        file_put_contents(Config::storage() . '/logs/mail.log', $line, FILE_APPEND | LOCK_EX);
        return true;
    }

    private static function viaSmtp(string $to, string $subject, string $html, string $text): bool
    {
        $host = self::setting('smtp_host', 'SMTP_HOST');
        $port = (int)(self::setting('smtp_port', 'SMTP_PORT', '587') ?: 587);
        $secure = strtolower(self::setting('smtp_secure', 'SMTP_SECURE', 'tls'));
        $user = self::setting('smtp_user', 'SMTP_USER');
        $pass = Secrets::get('SMTP_PASS');
        if ($host === '') {
            throw new \RuntimeException('SMTP_HOST is not set');
        }
        $sock = @stream_socket_client(($secure === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port, $errno, $errstr, 20);
        if (!$sock) {
            throw new \RuntimeException("SMTP connect failed: {$errstr}");
        }
        stream_set_timeout($sock, 20);
        $read = static function () use ($sock): string {
            $out = '';
            while (($line = fgets($sock, 515)) !== false) {
                $out .= $line;
                if (strlen($line) < 4 || $line[3] !== '-') {
                    break;
                }
            }
            return $out;
        };
        $cmd = static function (string $c, array $ok) use ($sock, $read): string {
            fwrite($sock, $c . "\r\n");
            $r = $read();
            if (!in_array((int)substr($r, 0, 3), $ok, true)) {
                throw new \RuntimeException('SMTP ' . trim($c === '' ? 'banner' : strtok($c, ' ')) . ' failed: ' . trim($r));
            }
            return $r;
        };
        $read();
        $me = parse_url(Config::baseUrl(), PHP_URL_HOST) ?: 'localhost';
        $cmd('EHLO ' . $me, [250]);
        if ($secure === 'tls') {
            $cmd('STARTTLS', [220]);
            if (!stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new \RuntimeException('STARTTLS failed');
            }
            $cmd('EHLO ' . $me, [250]);
        }
        if ($user !== '') {
            $cmd('AUTH LOGIN', [334]);
            $cmd(base64_encode($user), [334]);
            $cmd(base64_encode($pass), [235]);
        }
        $cmd('MAIL FROM:<' . self::from() . '>', [250]);
        $cmd('RCPT TO:<' . $to . '>', [250, 251]);
        $cmd('DATA', [354]);
        $b = 'me-' . bin2hex(random_bytes(8));
        $data = 'To: <' . $to . ">\r\nSubject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\nMessage-ID: <" . bin2hex(random_bytes(10)) . '@' . $me . ">\r\nDate: " . date('r') . "\r\n" . implode("\r\n", self::headers($b)) . "\r\n\r\n" . self::body($b, $html, $text);
        $data = preg_replace('/^\./m', '..', $data) ?? $data;
        fwrite($sock, $data . "\r\n.\r\n");
        $r = $read();
        fwrite($sock, "QUIT\r\n");
        fclose($sock);
        return (int)substr($r, 0, 3) === 250;
    }
}
