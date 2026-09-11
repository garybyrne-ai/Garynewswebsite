<?php
declare(strict_types=1);

namespace MeNews\Services;

use MeNews\Config;

/**
 * Outbound email without dependencies. MAIL_TRANSPORT selects:
 *   log   - append to storage/logs/mail.log (default in development)
 *   mail  - PHP mail() (works on most shared hosts such as Cloudways)
 *   smtp  - plain SMTP with optional STARTTLS/SSL (SMTP_HOST, SMTP_PORT, SMTP_USER, SMTP_PASS, SMTP_SECURE)
 * Every message gets a plain-text alternative and a branded HTML wrapper.
 */
final class Mailer
{
    public static function transport(): string
    {
        $t = strtolower(Config::get('MAIL_TRANSPORT', Config::production() ? 'mail' : 'log'));
        return in_array($t, ['log', 'mail', 'smtp'], true) ? $t : 'log';
    }

    public static function from(): string
    {
        return Config::get('MAIL_FROM', 'news@' . (parse_url(Config::get('PUBLIC_BASE_URL', 'http://menews.ie'), PHP_URL_HOST) ?: 'menews.ie'));
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
            return match (self::transport()) {
                'mail' => self::viaMail($to, $subject, $html, $text),
                'smtp' => self::viaSmtp($to, $subject, $html, $text),
                default => self::viaLog($to, $subject, $text),
            };
        } catch (\Throwable $e) {
            error_log('Mailer: ' . $e->getMessage());
            return self::viaLog($to, $subject, $text . "\n[transport error: " . $e->getMessage() . ']');
        }
    }

    private static function wrap(string $subject, string $body): string
    {
        $base = rtrim(Config::get('PUBLIC_BASE_URL', ''), '/');
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
            'From: ' . Config::appName() . ' <' . self::from() . '>',
            'Reply-To: ' . Config::get('MAIL_REPLY_TO', self::from()),
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
        $host = Config::get('SMTP_HOST');
        $port = Config::int('SMTP_PORT', 587);
        $secure = strtolower(Config::get('SMTP_SECURE', 'tls'));
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
        $me = parse_url(Config::get('PUBLIC_BASE_URL', 'http://localhost'), PHP_URL_HOST) ?: 'localhost';
        $cmd('EHLO ' . $me, [250]);
        if ($secure === 'tls') {
            $cmd('STARTTLS', [220]);
            if (!stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new \RuntimeException('STARTTLS failed');
            }
            $cmd('EHLO ' . $me, [250]);
        }
        if (Config::get('SMTP_USER') !== '') {
            $cmd('AUTH LOGIN', [334]);
            $cmd(base64_encode(Config::get('SMTP_USER')), [334]);
            $cmd(base64_encode(Config::get('SMTP_PASS')), [235]);
        }
        $cmd('MAIL FROM:<' . self::from() . '>', [250]);
        $cmd('RCPT TO:<' . $to . '>', [250, 251]);
        $cmd('DATA', [354]);
        $b = 'me-' . bin2hex(random_bytes(8));
        $data = 'To: <' . $to . ">\r\nSubject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\nDate: " . date('r') . "\r\n" . implode("\r\n", self::headers($b)) . "\r\n\r\n" . self::body($b, $html, $text);
        $data = preg_replace('/^\./m', '..', $data) ?? $data;
        fwrite($sock, $data . "\r\n.\r\n");
        $r = $read();
        fwrite($sock, "QUIT\r\n");
        fclose($sock);
        return (int)substr($r, 0, 3) === 250;
    }
}
