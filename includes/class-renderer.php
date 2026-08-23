<?php
/**
 * Builds and prints the error page.
 *
 * This is the only place that produces output, and it is the only class that runs on a
 * site which has already thrown a fatal error. Every WordPress call it makes is guarded
 * so that a broken database or a half-loaded core cannot turn a styled 500 into a blank
 * page.
 *
 * @package error-page
 */

if (!defined('ABSPATH')) {
    exit;
}

class Error_Page_Renderer
{
    /** Relative to the plugin root. */
    const BASE_STYLESHEET = 'assets/css/error-page.css';
    const DEFAULT_ICON    = 'assets/img/server-error.svg';
    const TEMPLATE        = 'templates/error-page.php';

    /** Theme sub-directory searched for a template override. */
    const TEMPLATE_OVERRIDE = 'error-page/error-page.php';

    /** Filename searched for in the themes and in wp-content. */
    const CSS_OVERRIDE = 'error-page.css';

    /** @var Error_Page_Settings */
    private $settings;

    public function __construct(Error_Page_Settings $settings)
    {
        $this->settings = $settings;
    }

    /**
     * Should the branded page replace the core screen for this request?
     */
    public function should_render(): bool
    {
        $enabled = (bool) $this->settings->get('enabled');

        if ($this->is_non_html_request()) {
            $enabled = false;
        }

        if (function_exists('apply_filters')) {
            $enabled = (bool) apply_filters('error_page_should_render', $enabled);
        }

        return $enabled;
    }

    /**
     * AJAX, REST, XML-RPC and WP-CLI callers expect JSON or plain text. Handing them an
     * HTML document would break the consumer far more visibly than the default screen.
     */
    private function is_non_html_request(): bool
    {
        if (defined('DOING_AJAX') && DOING_AJAX) {
            return true;
        }

        if (defined('REST_REQUEST') && REST_REQUEST) {
            return true;
        }

        if (defined('XMLRPC_REQUEST') && XMLRPC_REQUEST) {
            return true;
        }

        if (defined('WP_CLI') && WP_CLI) {
            return true;
        }

        if (function_exists('wp_is_json_request') && wp_is_json_request()) {
            return true;
        }

        return false;
    }

    /**
     * Print the page. Does not exit — the caller decides.
     *
     * @param array $args {
     *     @type array|null $error   The fatal as reported by WordPress, if available.
     *     @type bool       $preview Render for the admin preview (HTTP 200, no noindex).
     * }
     */
    public function render(array $args = []): void
    {
        $context = $this->build_context($args);

        $this->send_headers($context);

        $error = $context;

        include $this->locate_template();
    }

    public function build_context(array $args = []): array
    {
        $settings   = $this->settings->get_settings();
        $is_preview = !empty($args['preview']);
        $error      = isset($args['error']) && is_array($args['error']) ? $args['error'] : null;

        $code  = (string) $settings['error_code'];
        $title = (string) $settings['title'];

        return [
            'is_preview'     => $is_preview,
            'status_code'    => $is_preview ? 200 : 500,
            'lang'           => $this->site_language(),
            'charset'        => $this->site_charset(),
            'document_title' => $this->build_document_title($code, $title),
            'code'           => $code,
            'title'          => $title,
            'message'        => $this->build_message($settings),
            'icon'           => $this->build_icon($settings),
            'css'            => $this->build_css($settings),
            'details'        => $this->build_details($settings, $error),

            // Templates (including theme overrides) escape through this, so they never
            // have to care whether wp-includes/formatting.php managed to load.
            'escape'         => function ($value) {
                return $this->escape($value);
            },
        ];
    }

    /* --------------------------------------------------------------------- *
     * Context pieces
     * --------------------------------------------------------------------- */

    /**
     * The code and the title are both optional, so the browser tab needs a fallback.
     */
    private function build_document_title(string $code, string $title): string
    {
        $document_title = trim($code . ' ' . $title);

        if ('' !== $document_title) {
            return $document_title;
        }

        return $this->bloginfo('name', 'Error');
    }

    /**
     * The stored message is already `wp_kses_post`-sanitized, so it may contain a link.
     * Only the substituted site name needs escaping here.
     */
    private function build_message(array $settings): string
    {
        $message = (string) $settings['message'];

        return str_replace('{site_name}', $this->escape($this->site_name()), $message);
    }

    /**
     * Returns ready-to-print markup: an inline SVG, an <img>, or an empty string.
     */
    private function build_icon(array $settings): string
    {
        $mode = (string) $settings['icon_mode'];

        if ('none' === $mode) {
            return '';
        }

        if ('custom' === $mode && !empty($settings['icon_id'])) {
            $url = $this->attachment_url((int) $settings['icon_id']);

            if ('' !== $url) {
                return sprintf('<img src="%s" alt="" />', $this->escape($url));
            }
        }

        // Inlined rather than linked: an external request cannot be relied on while the
        // site is failing, and the SVG must render even if rewrite rules are broken.
        return $this->read_file(ERROR_PAGE_DIR . self::DEFAULT_ICON);
    }

    /**
     * Base stylesheet, then the site override file, then the settings colours.
     */
    public function build_css(array $settings): string
    {
        $css = $this->read_file(ERROR_PAGE_DIR . self::BASE_STYLESHEET);

        $override = $this->locate_css_override();

        if ('' !== $override) {
            $css .= "\n\n/* Site override: " . $override . " */\n" . $this->read_file($override);
        }

        $colors = $this->settings->build_color_overrides($settings);

        if ('' !== $colors) {
            $css .= "\n\n/* Settings colours */\n" . $colors;
        }

        // The stylesheet is printed inline; a stray closing tag in a site override would
        // break out of the <style> element.
        return str_ireplace('</style', '<\/style', $css);
    }

    /**
     * The real error, for administrators only, and only when WP_DEBUG is on.
     *
     * @return array<string, string>|null
     */
    private function build_details(array $settings, ?array $error): ?array
    {
        if (null === $error || empty($settings['show_error_details'])) {
            return null;
        }

        if (!defined('WP_DEBUG') || !WP_DEBUG) {
            return null;
        }

        if (!$this->current_user_is_admin()) {
            return null;
        }

        return [
            'message' => isset($error['message']) ? (string) $error['message'] : '',
            'file'    => isset($error['file']) ? (string) $error['file'] : '',
            'line'    => isset($error['line']) ? (string) $error['line'] : '',
        ];
    }

    /* --------------------------------------------------------------------- *
     * Locating overridable files
     * --------------------------------------------------------------------- */

    public function locate_template(): string
    {
        foreach ($this->theme_directories() as $directory) {
            $candidate = $directory . '/' . self::TEMPLATE_OVERRIDE;

            if (is_readable($candidate)) {
                return $candidate;
            }
        }

        return ERROR_PAGE_DIR . self::TEMPLATE;
    }

    /**
     * @return string Absolute path, or an empty string when no override exists.
     */
    public function locate_css_override(): string
    {
        $candidates = [];

        foreach ($this->theme_directories() as $directory) {
            $candidates[] = $directory . '/' . self::CSS_OVERRIDE;
        }

        if (defined('WP_CONTENT_DIR')) {
            $candidates[] = WP_CONTENT_DIR . '/' . self::CSS_OVERRIDE;
        }

        foreach ($candidates as $candidate) {
            if (is_readable($candidate)) {
                return $candidate;
            }
        }

        return '';
    }

    /**
     * Child theme first, then parent. Empty when the theme API is unavailable.
     *
     * @return string[]
     */
    private function theme_directories(): array
    {
        $directories = [];

        try {
            if (function_exists('get_stylesheet_directory')) {
                $directories[] = get_stylesheet_directory();
            }

            if (function_exists('get_template_directory')) {
                $directories[] = get_template_directory();
            }
        } catch (\Throwable $e) {
            return [];
        }

        return array_values(array_unique(array_filter($directories)));
    }

    /* --------------------------------------------------------------------- *
     * Output helpers
     * --------------------------------------------------------------------- */

    private function send_headers(array $context): void
    {
        if (headers_sent()) {
            return;
        }

        header('Content-Type: text/html; charset=' . $context['charset'], true, $context['status_code']);

        if ($context['is_preview']) {
            return;
        }

        header('Retry-After: 300');
        header('X-Robots-Tag: noindex, nofollow', true);

        // The error page must never be treated as the canonical response for the URL.
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    }

    /**
     * Escaping that still works if wp-includes/formatting.php never loaded.
     *
     * @param mixed $value
     */
    public function escape($value): string
    {
        $value = (string) $value;

        if (function_exists('esc_html')) {
            return esc_html($value);
        }

        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    private function read_file(string $path): string
    {
        if (!is_readable($path)) {
            return '';
        }

        $contents = file_get_contents($path);

        return false === $contents ? '' : $contents;
    }

    /* --------------------------------------------------------------------- *
     * Guarded WordPress lookups
     * --------------------------------------------------------------------- */

    private function site_name(): string
    {
        return $this->bloginfo('name', 'This website');
    }

    private function site_language(): string
    {
        $language = $this->bloginfo('language', 'en');

        return '' === $language ? 'en' : str_replace('_', '-', $language);
    }

    private function site_charset(): string
    {
        $charset = $this->bloginfo('charset', 'UTF-8');

        return '' === $charset ? 'UTF-8' : $charset;
    }

    private function bloginfo(string $key, string $fallback): string
    {
        if (!function_exists('get_bloginfo')) {
            return $fallback;
        }

        try {
            $value = trim((string) get_bloginfo($key));
        } catch (\Throwable $e) {
            return $fallback;
        }

        return '' === $value ? $fallback : $value;
    }

    private function attachment_url(int $attachment_id): string
    {
        if (!function_exists('wp_get_attachment_url')) {
            return '';
        }

        try {
            $url = wp_get_attachment_url($attachment_id);
        } catch (\Throwable $e) {
            return '';
        }

        return is_string($url) ? $url : '';
    }

    private function current_user_is_admin(): bool
    {
        if (!function_exists('current_user_can')) {
            return false;
        }

        try {
            return (bool) current_user_can('manage_options');
        } catch (\Throwable $e) {
            return false;
        }
    }
}
