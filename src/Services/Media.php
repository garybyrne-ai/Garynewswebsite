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
        if (!copy($src, Config::storage() . '/uploads/public/' . $name)) {
            throw new RuntimeException('Unable to publish media');
        }
        return $name;
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
