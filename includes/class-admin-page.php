<?php
/**
 * Admin menu, asset loading, the preview route and drop-in synchronisation.
 *
 * @package error-page
 */

if (!defined('ABSPATH')) {
    exit;
}

class Error_Page_Admin_Page
{
    const PAGE_SLUG        = 'error-page';
    const PREVIEW_ARG      = 'error_preview';
    const PREVIEW_NONCE    = 'error_page_preview';
    const NOTICE_TRANSIENT = 'error_page_notice';

    /** @var Error_Page_Settings */
    private $settings;

    /** @var Error_Page_Renderer */
    private $renderer;

    public function __construct(Error_Page_Settings $settings, Error_Page_Renderer $renderer)
    {
        $this->settings = $settings;
        $this->renderer = $renderer;
    }

    public function register_hooks(): void
    {
        add_action('admin_menu', [$this, 'register_menu_pages']);
        add_action('admin_notices', [$this, 'render_notices']);
        add_action('update_option_' . Error_Page_Settings::OPTION_NAME, [$this, 'sync_dropin'], 10, 2);
        add_action('add_option_' . Error_Page_Settings::OPTION_NAME, [$this, 'sync_dropin_on_add'], 10, 2);
        add_filter('plugin_action_links_' . plugin_basename(ERROR_PAGE_FILE), [$this, 'add_action_link']);
    }

    public function register_menu_pages(): void
    {
        $hook = add_options_page(
            'Error Page',
            'Error Page',
            'manage_options',
            self::PAGE_SLUG,
            [$this, 'render_page']
        );

        if (!$hook) {
            return;
        }

        // `load-{hook}` fires before any output, so the preview can take over the response.
        add_action('load-' . $hook, [$this, 'maybe_render_preview']);
        add_action('load-' . $hook, [$this, 'register_asset_loading']);
    }

    /**
     * The form itself lives in Settings; this supplies the bits only the admin page knows.
     */
    public function render_page(): void
    {
        $this->settings->render_settings_page([
            'preview_url'   => $this->preview_url(),
            'dropin_status' => Error_Page_Dropin::status(),
            'dropin_path'   => Error_Page_Dropin::path(),
        ]);
    }

    /**
     * @param string[] $links
     *
     * @return string[]
     */
    public function add_action_link($links): array
    {
        $links = is_array($links) ? $links : [];

        array_unshift(
            $links,
            sprintf('<a href="%s">Settings</a>', esc_url($this->settings_url()))
        );

        return $links;
    }

    /* --------------------------------------------------------------------- *
     * Assets
     * --------------------------------------------------------------------- */

    public function register_asset_loading(): void
    {
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    public function enqueue_assets(): void
    {
        wp_enqueue_media();

        wp_enqueue_style('wp-color-picker');
        wp_enqueue_script('wp-color-picker');

        wp_add_inline_script('wp-color-picker', $this->admin_script(), 'after');
    }

    private function admin_script(): string
    {
        return <<<'JS'
jQuery(function ($) {
    $('.error-color').wpColorPicker();

    var frame;

    // The image picker only applies to the custom mode — keep it out of the way otherwise.
    var $iconModes = $('#error-icon-modes input[type="radio"]');
    var $iconControls = $('#error-icon-controls');

    function syncIconControls() {
        $iconControls.toggle($iconModes.filter(':checked').val() === 'custom');
    }

    $iconModes.on('change', syncIconControls);
    syncIconControls();

    $('#error-icon-select').on('click', function (event) {
        event.preventDefault();

        if (!frame) {
            frame = wp.media({
                title: 'Choose an illustration',
                button: { text: 'Use this image' },
                multiple: false
            });

            frame.on('select', function () {
                var attachment = frame.state().get('selection').first().toJSON();

                $('#error-icon-id').val(attachment.id);
                $('#error-icon-preview')
                    .attr('src', attachment.sizes && attachment.sizes.medium
                        ? attachment.sizes.medium.url
                        : attachment.url)
                    .show();
                $('#error-icon-remove').show();
                $('#error-icon-mode-custom').prop('checked', true);
                syncIconControls();
            });
        }

        frame.open();
    });

    $('#error-icon-remove').on('click', function (event) {
        event.preventDefault();

        // Clear the selection but stay in custom mode, so the picker does not vanish
        // out from under the click.
        $('#error-icon-id').val('');
        $('#error-icon-preview').removeAttr('src').hide();
        $(this).hide();
    });
});
JS;
    }

    /* --------------------------------------------------------------------- *
     * Preview
     * --------------------------------------------------------------------- */

    public function maybe_render_preview(): void
    {
        if (empty($_GET[self::PREVIEW_ARG])) {
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_die('You do not have permission to preview the error page.');
        }

        check_admin_referer(self::PREVIEW_NONCE);

        $this->renderer->render([
            'preview' => true,
            'error'   => [
                'type'    => E_ERROR,
                'message' => 'Preview: this is what visitors see when a fatal error occurs.',
                'file'    => ERROR_PAGE_FILE,
                'line'    => 1,
            ],
        ]);

        exit;
    }

    public function preview_url(): string
    {
        return wp_nonce_url(
            add_query_arg(self::PREVIEW_ARG, '1', $this->settings_url()),
            self::PREVIEW_NONCE
        );
    }

    public function settings_url(): string
    {
        return admin_url('options-general.php?page=' . self::PAGE_SLUG);
    }

    /* --------------------------------------------------------------------- *
     * Drop-in synchronisation
     * --------------------------------------------------------------------- */

    /**
     * @param mixed $value
     */
    public function sync_dropin_on_add($option, $value): void
    {
        $this->sync_dropin([], $value);
    }

    /**
     * @param mixed $old_value
     * @param mixed $value
     */
    public function sync_dropin($old_value, $value): void
    {
        if (!is_array($value)) {
            return;
        }

        if (empty($value['dropin_enabled'])) {
            Error_Page_Dropin::remove();

            return;
        }

        if (Error_Page_Dropin::is_installed()) {
            return;
        }

        $result = Error_Page_Dropin::install();

        if (!is_wp_error($result)) {
            return;
        }

        set_transient(self::NOTICE_TRANSIENT, $result->get_error_message(), MINUTE_IN_SECONDS);

        // Never leave the toggle claiming something that did not happen.
        $value['dropin_enabled'] = false;

        remove_action('update_option_' . Error_Page_Settings::OPTION_NAME, [$this, 'sync_dropin'], 10);
        update_option(Error_Page_Settings::OPTION_NAME, $value);
        add_action('update_option_' . Error_Page_Settings::OPTION_NAME, [$this, 'sync_dropin'], 10, 2);
    }

    /* --------------------------------------------------------------------- *
     * Notices
     * --------------------------------------------------------------------- */

    public function render_notices(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $message = get_transient(self::NOTICE_TRANSIENT);

        if ($message) {
            delete_transient(self::NOTICE_TRANSIENT);

            printf(
                '<div class="notice notice-error"><p><strong>Error Page:</strong> %s</p></div>',
                esc_html($message)
            );
        }

        $screen = function_exists('get_current_screen') ? get_current_screen() : null;

        if (!$screen || false === strpos((string) $screen->id, self::PAGE_SLUG)) {
            return;
        }

        if (Error_Page_Dropin::STATUS_FOREIGN === Error_Page_Dropin::status()) {
            printf(
                '<div class="notice notice-warning"><p><strong>Error Page:</strong> '
                . 'a <code>php-error.php</code> drop-in already exists at <code>%s</code> and was not '
                . 'created by this plugin. It takes priority over everything below. Remove or rename it '
                . 'to use the drop-in option.</p></div>',
                esc_html(Error_Page_Dropin::path())
            );
        }
    }
}
