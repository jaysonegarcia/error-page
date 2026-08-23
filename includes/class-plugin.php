<?php
/**
 * Orchestrator: builds the subsystems and lets each register its own hooks.
 *
 * @package error-page
 */

if (!defined('ABSPATH')) {
    exit;
}

class Error_Page_Plugin
{
    /** @var Error_Page_Settings */
    private $settings;

    /** @var Error_Page_Renderer */
    private $renderer;

    /** @var Error_Page_Error_Handler */
    private $error_handler;

    /** @var Error_Page_Admin_Page */
    private $admin_page;

    public function __construct()
    {
        $this->settings      = new Error_Page_Settings();
        $this->renderer      = new Error_Page_Renderer($this->settings);
        $this->error_handler = new Error_Page_Error_Handler($this->renderer);
        $this->admin_page    = new Error_Page_Admin_Page($this->settings, $this->renderer);
    }

    public function register_hooks(): void
    {
        $this->error_handler->register_hooks();

        if (is_admin()) {
            $this->settings->register_hooks();
            $this->admin_page->register_hooks();
        }
    }
}
