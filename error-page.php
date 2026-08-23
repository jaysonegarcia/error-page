<?php
/**
 * Plugin Name:       Error Page
 * Description:       Replaces the default WordPress "critical error" screen with a branded 500 page. Colours, spacing and type are exposed as CSS custom properties so the same plugin can be re-themed on every site without touching PHP.
 * Version:           1.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Jayson Garcia
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       error-page
 */

if (!defined('ABSPATH')) {
    exit;
}

define('ERROR_PAGE_VERSION', '1.0.0');
define('ERROR_PAGE_FILE', __FILE__);
define('ERROR_PAGE_DIR', plugin_dir_path(__FILE__));

require_once ERROR_PAGE_DIR . 'includes/class-settings.php';
require_once ERROR_PAGE_DIR . 'includes/class-renderer.php';
require_once ERROR_PAGE_DIR . 'includes/class-error-handler.php';
require_once ERROR_PAGE_DIR . 'includes/class-dropin.php';
require_once ERROR_PAGE_DIR . 'includes/class-admin-page.php';
require_once ERROR_PAGE_DIR . 'includes/class-plugin.php';

add_action('plugins_loaded', function () {
    (new Error_Page_Plugin())->register_hooks();
});

/*
 * The drop-in must never outlive the plugin it points at, so tear it down on
 * both deactivation and uninstall.
 */
register_deactivation_hook(__FILE__, ['Error_Page_Dropin', 'remove']);
register_uninstall_hook(__FILE__, ['Error_Page_Dropin', 'remove']);
