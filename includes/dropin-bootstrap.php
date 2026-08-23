<?php
/**
 * Entry point for the `wp-content/php-error.php` drop-in.
 *
 * Reached from inside `WP_Fatal_Error_Handler::display_error_template()`, so `$error` and
 * `$handled` are in scope. This may run before the plugin itself has loaded — it must not
 * assume any of the plugin's bootstrap has happened.
 *
 * @var array $error   The fatal error reported by WordPress.
 * @var bool  $handled Whether recovery mode already handled it.
 *
 * @package error-page
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('ERROR_PAGE_DIR')) {
    define('ERROR_PAGE_DIR', dirname(__DIR__) . '/');
}

require_once ERROR_PAGE_DIR . 'includes/class-settings.php';
require_once ERROR_PAGE_DIR . 'includes/class-renderer.php';

$error_page_renderer = new Error_Page_Renderer(new Error_Page_Settings());

if (!$error_page_renderer->should_render()) {
    /*
     * A JSON or CLI caller, or the page is switched off. Hand back to what core would have
     * printed — `wp_die()` picks the right handler for the request type.
     */
    if (function_exists('wp_die')) {
        wp_die(
            'There has been a critical error on this website.',
            '',
            [
                'response'  => 500,
                'exit'      => false,
                'code'      => 'critical_error',
                'back_link' => false,
            ]
        );
    }

    return;
}

$error_page_renderer->render([
    'error' => isset($error) && is_array($error) ? $error : null,
]);

exit;
