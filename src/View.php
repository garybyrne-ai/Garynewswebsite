<?php
declare(strict_types=1);

namespace MeNews;

use MeNews\Http\Response;

/**
 * Plain PHP template renderer. Templates live in /templates and receive extracted variables.
 * A page template is wrapped in templates/layout.php unless $layout is null.
 */
final class View
{
    /** Extra <script> markup a page template registers by setting $extraScripts. */
    private static string $extra = '';

    public static function render(string $template, array $data = [], ?string $layout = 'layout'): string
    {
        self::$extra = '';
        $content = self::partial($template, $data);
        if ($layout === null) {
            return $content;
        }
        return self::partial($layout, $data + ['content' => $content, 'extraScripts' => self::$extra]);
    }

    public static function partial(string $template, array $data = []): string
    {
        $file = ME_ROOT . '/templates/' . $template . '.php';
        if (!is_file($file)) {
            throw new \RuntimeException('Template not found: ' . $template);
        }
        extract($data, EXTR_SKIP);
        ob_start();
        try {
            require $file;
        } finally {
            $out = ob_get_clean();
        }
        if (isset($extraScripts) && is_string($extraScripts)) {
            self::$extra = $extraScripts;
        }
        return (string)$out;
    }

    public static function page(string $template, array $data = [], int $status = 200): Response
    {
        return Response::html(self::render($template, $data), $status);
    }
}
