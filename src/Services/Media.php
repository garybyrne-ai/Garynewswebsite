<?php
declare(strict_types=1);

namespace MeNews\Services;

use MeNews\Config;
use MeNews\Http\HttpException;
use RuntimeException;

/** Upload quarantine, publication and range-aware streaming for community media. */
final class Media
{
    public const TYPES = [
        'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif',
        'video/mp4' => 'mp4', 'video/webm' => 'webm', 'video/quicktime' => 'mov',
    ];

    public static function path(string $name, string $folder): string
    {
        $file = Config::storage() . '/uploads/' . $folder . '/' . basename(str_replace('\\', '/', $name));
        if (!is_file($file)) {
            throw new RuntimeException('Media file is missing');
        }
        return $file;
    }

    /** Validate an upload and move it into quarantine. Returns [filename, type]. */
    public static function quarantine(array $f, string $id): array
    {
        $maxBytes = Config::int('MAX_UPLOAD_MB', 200) * 1048576;
        if (in_array($f['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) || (int)$f['size'] > $maxBytes) {
            throw new HttpException(413, 'Upload too large');
        }
        if ($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
            throw new HttpException(400, 'Upload failed');
        }
        $mime = (string)mime_content_type($f['tmp_name']);
        if (!isset(self::TYPES[$mime])) {
            throw new HttpException(400, 'Use a JPG, PNG, WebP, GIF, MP4, WebM or MOV file');
        }
        $type = str_starts_with($mime, 'image/') ? 'image' : 'video';
        if ($type === 'image' && @getimagesize($f['tmp_name']) === false) {
            throw new HttpException(400, 'Invalid image');
        }
        $name = $id . '.' . self::TYPES[$mime];
        if (!move_uploaded_file($f['tmp_name'], Config::storage() . '/uploads/quarantine/' . $name)) {
            throw new HttpException(500, 'Unable to save upload');
        }
        return [$name, $type];
    }

    public static function publish(array $story): ?string
    {
        if (empty($story['media_original'])) {
            return null;
        }
        $src = self::path($story['media_original'], 'quarantine');
        $name = $story['id'] . '.' . pathinfo($src, PATHINFO_EXTENSION);
        $dest = Config::storage() . '/uploads/public/' . $name;
        // Published JPEGs are re-encoded so camera metadata (GPS, device, timestamps) never leaves quarantine.
        if (($story['media_type'] ?? '') === 'image' && function_exists('imagecreatefromjpeg') && in_array(strtolower(pathinfo($src, PATHINFO_EXTENSION)), ['jpg', 'jpeg'], true)) {
            $im = @imagecreatefromjpeg($src);
            if ($im) {
                $ok = imagejpeg($im, $dest, 88);
                imagedestroy($im);
                if ($ok) {
                    return $name;
                }
            }
        }
        if (!copy($src, $dest)) {
            throw new RuntimeException('Unable to publish media');
        }
        return $name;
    }

    /**
     * What editors need to judge a photo: when it was taken, on what, and where (EXIF GPS),
     * plus a perceptual hash so the same image turning up twice is flagged.
     * @return array{exif:array,hash:?string}
     */
    public static function inspect(string $file, string $type): array
    {
        $out = ['exif' => [], 'hash' => null];
        if ($type !== 'image') {
            return $out;
        }
        if (function_exists('exif_read_data') && in_array(strtolower(pathinfo($file, PATHINFO_EXTENSION)), ['jpg', 'jpeg'], true)) {
            $x = @exif_read_data($file, 'ANY_TAG', true) ?: [];
            $exif = [];
            $taken = $x['EXIF']['DateTimeOriginal'] ?? $x['IFD0']['DateTime'] ?? null;
            if ($taken) {
                $exif['taken'] = (string)$taken;
            }
            $device = trim(((string)($x['IFD0']['Make'] ?? '')) . ' ' . ((string)($x['IFD0']['Model'] ?? '')));
            if ($device !== '') {
                $exif['device'] = $device;
            }
            if (!empty($x['IFD0']['Software'])) {
                $exif['software'] = (string)$x['IFD0']['Software'];
            }
            $gps = $x['GPS'] ?? [];
            if (!empty($gps['GPSLatitude']) && !empty($gps['GPSLongitude'])) {
                $lat = self::gpsToDecimal($gps['GPSLatitude'], (string)($gps['GPSLatitudeRef'] ?? 'N'));
                $lng = self::gpsToDecimal($gps['GPSLongitude'], (string)($gps['GPSLongitudeRef'] ?? 'E'));
                if ($lat !== null && $lng !== null) {
                    $exif['gps'] = ['lat' => round($lat, 5), 'lng' => round($lng, 5)];
                }
            }
            $exif['has_metadata'] = (bool)$exif;
            $out['exif'] = $exif;
        }
        $out['hash'] = self::averageHash($file);
        return $out;
    }

    private static function gpsToDecimal(array $parts, string $ref): ?float
    {
        $vals = [];
        foreach (array_slice($parts, 0, 3) as $p) {
            if (is_string($p) && str_contains($p, '/')) {
                [$a, $b] = explode('/', $p, 2);
                $vals[] = (float)$b === 0.0 ? 0.0 : (float)$a / (float)$b;
            } else {
                $vals[] = (float)$p;
            }
        }
        if (count($vals) < 2) {
            return null;
        }
        $dec = $vals[0] + ($vals[1] ?? 0) / 60 + ($vals[2] ?? 0) / 3600;
        return in_array(strtoupper($ref), ['S', 'W'], true) ? -$dec : $dec;
    }

    /** 64-bit average hash (8×8 greyscale) as 16 hex chars; null when GD cannot read the file. */
    public static function averageHash(string $file): ?string
    {
        if (!function_exists('imagecreatefromstring')) {
            return null;
        }
        $im = @imagecreatefromstring((string)@file_get_contents($file));
        if (!$im) {
            return null;
        }
        $small = imagecreatetruecolor(8, 8);
        imagecopyresampled($small, $im, 0, 0, 0, 0, 8, 8, imagesx($im), imagesy($im));
        $px = [];
        for ($y = 0; $y < 8; $y++) {
            for ($x = 0; $x < 8; $x++) {
                $c = imagecolorat($small, $x, $y);
                $px[] = (int)round((($c >> 16) & 255) * .299 + (($c >> 8) & 255) * .587 + ($c & 255) * .114);
            }
        }
        imagedestroy($im);
        imagedestroy($small);
        $avg = array_sum($px) / 64;
        $bits = '';
        foreach ($px as $v) {
            $bits .= $v >= $avg ? '1' : '0';
        }
        return str_pad(base_convert(substr($bits, 0, 32), 2, 16), 8, '0', STR_PAD_LEFT) . str_pad(base_convert(substr($bits, 32), 2, 16), 8, '0', STR_PAD_LEFT);
    }

    /** Hamming distance between two average hashes (0 = identical, ≤ 6 = near-duplicate). */
    public static function hashDistance(string $a, string $b): int
    {
        $d = 0;
        for ($i = 0; $i < 16; $i++) {
            $d += substr_count(str_pad(decbin(hexdec($a[$i]) ^ hexdec($b[$i])), 4, '0', STR_PAD_LEFT), '1');
        }
        return $d;
    }

    /** A URL to quarantined media that works for 24 h without a session, for reverse image search tools. */
    public static function signedQuarantineUrl(string $storyId): string
    {
        $day = gmdate('Y-m-d');
        return absolute_url('/media/q/' . $storyId . '/' . self::sign($storyId, $day));
    }

    public static function sign(string $storyId, string $day): string
    {
        return substr(hash_hmac('sha256', $storyId . '|' . $day, Config::secret()), 0, 32);
    }

    public static function verifySignature(string $storyId, string $sig): bool
    {
        foreach ([gmdate('Y-m-d'), gmdate('Y-m-d', time() - 86400)] as $day) {
            if (hash_equals(self::sign($storyId, $day), $sig)) {
                return true;
            }
        }
        return false;
    }

    /** Stream a published file with HTTP range support. Exits after sending. */
    public static function stream(string $file): never
    {
        $mime = (string)mime_content_type($file);
        if (!isset(self::TYPES[$mime])) {
            throw new HttpException(404, 'Unsupported media');
        }
        $size = filesize($file);
        $start = 0;
        $end = $size - 1;
        header('Content-Type: ' . $mime);
        header('Accept-Ranges: bytes');
        header('Cache-Control: public, max-age=86400');
        header('X-Content-Type-Options: nosniff');
        if (isset($_SERVER['HTTP_RANGE'])) {
            if (!preg_match('/^bytes=(\d*)-(\d*)$/', $_SERVER['HTTP_RANGE'], $range) || ($range[1] === '' && $range[2] === '')) {
                header('Content-Range: bytes */' . $size);
                throw new HttpException(416, 'Invalid range');
            }
            if ($range[1] === '') {
                $start = max(0, $size - (int)$range[2]);
            } else {
                $start = (int)$range[1];
                if ($range[2] !== '') {
                    $end = min($end, (int)$range[2]);
                }
            }
            if ($start > $end || $start >= $size) {
                header('Content-Range: bytes */' . $size);
                throw new HttpException(416, 'Invalid range');
            }
            http_response_code(206);
            header("Content-Range: bytes {$start}-{$end}/{$size}");
        }
        header('Content-Length: ' . ($end - $start + 1));
        $fp = fopen($file, 'rb');
        fseek($fp, $start);
        $left = $end - $start + 1;
        while ($left > 0 && !feof($fp)) {
            $chunk = fread($fp, min(65536, $left));
            echo $chunk;
            $left -= strlen($chunk);
        }
        fclose($fp);
        exit;
    }

    /** Run an external command (ffmpeg / ffprobe) and return stdout. */
    public static function command(array $args): string
    {
        if (!function_exists('proc_open')) {
            throw new RuntimeException('Video processing requires proc_open and FFmpeg');
        }
        $process = proc_open($args, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', Config::storage() . '/logs/ffmpeg.log', 'a']], $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start video processing');
        }
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        if (proc_close($process) !== 0) {
            throw new RuntimeException('Video processing failed');
        }
        return (string)$out;
    }
}
