<?php
declare(strict_types=1);

/* ==============================================================
 *  View — plain-PHP template rendering inside a layout (plan §3.3)
 *
 *    View::page('member', 'shop/catalog', ['title' => 'Shop']);
 *    View::error(404);                       // branded error page
 *    $html = View::capture('partials/x');    // no output
 *    echo View::partial('empty_state');
 *
 *  Templates live in app/views/ and may not reach outside it.
 * ============================================================== */

final class View
{
    /** Absolute path of app/views/. */
    private static function viewsRoot(): string
    {
        return dirname(__DIR__) . '/views/';
    }

    /** Resolve a template name to a real file inside app/views/. */
    public static function locate(string $template): string
    {
        $template = str_replace('\\', '/', trim($template));
        $template = ltrim($template, '/');

        if ($template === '' || str_contains($template, '..')) {
            throw new InvalidArgumentException('Invalid view name: ' . $template);
        }

        $file = self::viewsRoot() . $template . '.php';

        if (!is_file($file)) {
            throw new RuntimeException('View not found: ' . $template);
        }

        return $file;
    }

    /**
     * Render a template and return its HTML.
     *
     * @param array<string, mixed> $data Variables available to the template
     */
    public static function capture(string $template, array $data = []): string
    {
        $file = self::locate($template);

        return self::captureFile($file, $data);
    }

    /** Render a template straight to the output buffer. */
    public static function render(string $template, array $data = []): void
    {
        echo self::capture($template, $data);
    }

    /**
     * Render a page inside a layout: header → template → footer.
     *
     * @param string               $layout member | admin | plain
     * @param array<string, mixed> $data
     */
    public static function page(string $layout, string $template, array $data = []): void
    {
        $file   = self::locate($template);
        $header = dirname(__DIR__) . '/layout/app_header.php';
        $footer = dirname(__DIR__) . '/layout/app_footer.php';

        $data = array_merge(
            [
                'layout'      => $layout,
                'current_nav' => null,
                'page_title'  => APP_NAME,
                'page_css'    => [],
                'page_js'     => [],
                'page_styles' => '',
                'nav_notes'   => [],
                'nav_badges'  => [],
            ],
            $data
        );

        (static function () use ($header, $footer, $file, $data): void {
            extract($data, EXTR_SKIP);
            require $header;
            require $file;
            require $footer;
        })();
    }

    /** Render one of app/views/partials/*.php and return it. */
    public static function partial(string $name, array $data = []): string
    {
        $name = ltrim(str_replace('\\', '/', $name), '/');

        return self::captureFile(self::viewsRoot() . 'partials/' . $name . '.php', $data, 'partial');
    }

    /**
     * Render an error page in the EcoWealth layout and stop.
     * 4xx/5xx pages are branded but never leak internals unless
     * APP_DEBUG is on (plan §3.7).
     *
     * @param array<string, mixed> $data Extra template variables
     */
    public static function error(int $code, array $data = []): never
    {
        [$heading, $message] = match ($code) {
            403     => ['Access denied', 'You do not have permission to open this page.'],
            404     => ['Page not found', 'The page you were looking for could not be found.'],
            419     => ['Session expired', 'Your session has expired. Please reload the page and try again.'],
            500     => ['Something went wrong', 'The page could not be loaded. Please try again in a moment.'],
            503     => ['Back shortly', 'The service is temporarily unavailable. Please try again shortly.'],
            default => ['Request failed', 'Please try again.'],
        };

        if (!headers_sent()) {
            http_response_code($code);
        }

        $data = array_merge([
            'code'    => $code,
            'heading' => $heading,
            'message' => $message,
        ], $data);

        try {
            self::page('plain', 'errors/' . (in_array($code, [403, 404, 500, 503], true) ? $code : 500), $data);
        } catch (Throwable $e) {
            /* The error page itself failed — last-resort plain text. */
            error_log('[ecowealth] error page failed: ' . $e->getMessage());
            echo '<!doctype html><html><body style="font-family:system-ui,sans-serif;padding:3rem">'
                . '<h1>' . e((string) $code) . ' — ' . e($heading) . '</h1>'
                . '<p>' . e($message) . '</p></body></html>';
        }

        exit;
    }

    /** Include a file with a fresh variable scope. */
    private static function captureFile(string $file, array $data, string $label = 'view'): string
    {
        if (!is_file($file)) {
            throw new RuntimeException(sprintf('%s not found: %s', ucfirst($label), $file));
        }

        $level = ob_get_level();
        ob_start();

        try {
            (static function () use ($file, $data): void {
                extract($data, EXTR_SKIP);
                require $file;
            })();
        } catch (Throwable $e) {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
            throw $e;
        }

        return (string) ob_get_clean();
    }
}
