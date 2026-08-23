<?php
/**
 * Filter-based interception of the core "critical error" screen.
 *
 * `WP_Fatal_Error_Handler::display_default_error_template()` applies the
 * `wp_php_error_message` filter immediately before it calls `wp_die()`. That gives us an
 * exact scope trigger: we attach our `wp_die_handler` from inside that filter and it is
 * therefore only ever in play for that one call. Every other `wp_die()` on the site — form
 * nonce failures, capability checks, REST errors — is untouched.
 *
 * @package error-page
 */

if (!defined('ABSPATH')) {
    exit;
}

class Error_Page_Error_Handler
{
    /** @var Error_Page_Renderer */
    private $renderer;

    /** @var array|null The fatal as reported by WordPress. */
    private $error = null;

    public function __construct(Error_Page_Renderer $renderer)
    {
        $this->renderer = $renderer;
    }

    public function register_hooks(): void
    {
        add_filter('wp_php_error_message', [$this, 'capture_error'], 10, 2);
    }

    /**
     * @param string $message
     * @param array  $error
     *
     * @return string Unchanged — we only use this filter for its timing.
     */
    public function capture_error($message, $error)
    {
        if (!$this->renderer->should_render()) {
            return $message;
        }

        $this->error = is_array($error) ? $error : null;

        // Priority 99 so we win against a theme or plugin that registers its own handler.
        add_filter('wp_die_handler', [$this, 'filter_die_handler'], 99);

        return $message;
    }

    /**
     * @param callable $handler
     *
     * @return callable
     */
    public function filter_die_handler($handler)
    {
        return [$this, 'handle_die'];
    }

    /**
     * Replaces `_default_wp_die_handler`, so we own the whole document rather than
     * rendering inside WordPress's `wp_die` shell.
     *
     * @param string|WP_Error $message
     * @param string          $title
     * @param array           $args
     */
    public function handle_die($message, $title = '', $args = []): void
    {
        // Nothing buffered by the failing request should leak into the page.
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        $this->renderer->render(['error' => $this->error]);

        exit;
    }
}
