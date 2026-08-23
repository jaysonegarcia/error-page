<?php
/**
 * Option storage, defaults, sanitization and the settings form.
 *
 * @package error-page
 */

if (!defined('ABSPATH')) {
    exit;
}

class Error_Page_Settings
{
    const OPTION_NAME = 'error_page_settings';
    const GROUP_NAME  = 'error_page';

    const ICON_MODES = ['default', 'custom', 'none'];

    /**
     * Colour settings mapped to the CSS custom property they override.
     *
     * @var array<string, string>
     */
    const COLOR_TOKENS = [
        'bg_color'     => '--error-bg',
        'card_color'   => '--error-card-bg',
        'text_color'   => '--error-text-color',
        'accent_color' => '--error-accent',
    ];

    public function register_hooks(): void
    {
        add_action('admin_init', [$this, 'register_settings']);
    }

    public static function get_default_settings(): array
    {
        return [
            'enabled'            => true,
            'error_code'         => '500',
            'title'              => 'Internal Server Error',
            'message'            => '{site_name} currently can\'t serve this page. Our technical team '
                                  . 'have already been automatically notified. Please try again in a few moments.',
            'icon_mode'          => 'default',
            'icon_id'            => 0,
            'bg_color'           => '',
            'card_color'         => '',
            'text_color'         => '',
            'accent_color'       => '',
            'show_error_details' => false,
            'dropin_enabled'     => false,
        ];
    }

    /**
     * Read the settings.
     *
     * Deliberately defensive: this runs while rendering the fatal-error page, so a
     * broken or unavailable database must not turn a styled 500 into a blank one.
     */
    public function get_settings(): array
    {
        $defaults = self::get_default_settings();

        if (!function_exists('get_option')) {
            return $defaults;
        }

        try {
            $stored = get_option(self::OPTION_NAME, []);
        } catch (\Throwable $e) {
            return $defaults;
        }

        if (!is_array($stored)) {
            return $defaults;
        }

        return array_merge($defaults, $stored);
    }

    public function get(string $key)
    {
        $settings = $this->get_settings();

        return $settings[$key] ?? null;
    }

    public function register_settings(): void
    {
        register_setting(
            self::GROUP_NAME,
            self::OPTION_NAME,
            [
                'type'              => 'array',
                'sanitize_callback' => [$this, 'sanitize_settings'],
                'default'           => self::get_default_settings(),
            ]
        );
    }

    /**
     * @param mixed $input
     */
    public function sanitize_settings($input): array
    {
        $defaults = self::get_default_settings();

        if (!is_array($input)) {
            return $defaults;
        }

        $clean = [];

        $clean['enabled']            = !empty($input['enabled']);
        $clean['show_error_details'] = !empty($input['show_error_details']);
        $clean['dropin_enabled']     = !empty($input['dropin_enabled']);

        // Both are optional: clearing a field hides that line rather than restoring the
        // default, so a site can show the code alone, the wording alone, or neither.
        $clean['error_code'] = isset($input['error_code']) ? sanitize_text_field($input['error_code']) : '';
        $clean['title']      = isset($input['title']) ? sanitize_text_field($input['title']) : '';

        $message = isset($input['message']) ? wp_kses_post($input['message']) : '';
        $clean['message'] = '' === trim(wp_strip_all_tags($message)) ? $defaults['message'] : $message;

        $icon_mode = isset($input['icon_mode']) ? (string) $input['icon_mode'] : '';
        $clean['icon_mode'] = in_array($icon_mode, self::ICON_MODES, true)
            ? $icon_mode
            : $defaults['icon_mode'];

        $clean['icon_id'] = isset($input['icon_id']) ? absint($input['icon_id']) : 0;

        foreach (array_keys(self::COLOR_TOKENS) as $color_key) {
            $value = isset($input[$color_key]) ? trim((string) $input[$color_key]) : '';
            $clean[$color_key] = '' === $value ? '' : (string) sanitize_hex_color($value);
        }

        return $clean;
    }

    /**
     * @param array $context {
     *     @type string $preview_url
     *     @type string $dropin_status
     *     @type string $dropin_path
     * }
     */
    public function render_settings_page(array $context = []): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $settings      = $this->get_settings();
        $preview_url   = $context['preview_url'] ?? '';
        $dropin_status = $context['dropin_status'] ?? Error_Page_Dropin::STATUS_MISSING;
        $dropin_path   = $context['dropin_path'] ?? '';
        $icon_url      = $settings['icon_id'] ? wp_get_attachment_image_url((int) $settings['icon_id'], 'medium') : '';
        $name          = self::OPTION_NAME;

        ?>
        <div class="wrap">
            <h1>Error Page</h1>
            <p class="description">
                Replaces the default WordPress &ldquo;critical error&rdquo; screen with a branded
                <?php echo esc_html($settings['error_code']); ?> page.
            </p>

            <?php if ($preview_url) : ?>
                <p>
                    <a href="<?php echo esc_url($preview_url); ?>" class="button button-secondary" target="_blank" rel="noopener">
                        Preview the error page
                    </a>
                </p>
            <?php endif; ?>

            <form method="post" action="options.php">
                <?php settings_fields(self::GROUP_NAME); ?>

                <h2 class="title">Content</h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">Custom error page</th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr($name); ?>[enabled]" value="1"
                                    <?php checked(!empty($settings['enabled'])); ?> />
                                Show the branded page instead of the WordPress default
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="error-code">Error code</label></th>
                        <td>
                            <input type="text" id="error-code" class="small-text"
                                name="<?php echo esc_attr($name); ?>[error_code]"
                                value="<?php echo esc_attr($settings['error_code']); ?>" />
                            <p class="description">Optional &mdash; leave empty to hide the large numeral.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="error-title">Title</label></th>
                        <td>
                            <input type="text" id="error-title" class="regular-text"
                                name="<?php echo esc_attr($name); ?>[title]"
                                value="<?php echo esc_attr($settings['title']); ?>" />
                            <p class="description">Optional &mdash; leave empty to hide this line.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="error-message">Message</label></th>
                        <td>
                            <textarea id="error-message" class="large-text" rows="4"
                                name="<?php echo esc_attr($name); ?>[message]"><?php
                                    echo esc_textarea($settings['message']);
                            ?></textarea>
                            <p class="description">
                                <code>{site_name}</code> is replaced with this site&rsquo;s name. Basic HTML is allowed.
                            </p>
                        </td>
                    </tr>
                </table>

                <h2 class="title">Illustration</h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">Image</th>
                        <td>
                            <fieldset id="error-icon-modes">
                                <label>
                                    <input type="radio" id="error-icon-mode-default"
                                        name="<?php echo esc_attr($name); ?>[icon_mode]" value="default"
                                        <?php checked('default', $settings['icon_mode']); ?> />
                                    Default illustration
                                </label><br />
                                <label>
                                    <input type="radio" id="error-icon-mode-custom"
                                        name="<?php echo esc_attr($name); ?>[icon_mode]" value="custom"
                                        <?php checked('custom', $settings['icon_mode']); ?> />
                                    Custom image
                                </label><br />
                                <label>
                                    <input type="radio" name="<?php echo esc_attr($name); ?>[icon_mode]" value="none"
                                        <?php checked('none', $settings['icon_mode']); ?> />
                                    No image
                                </label>
                            </fieldset>

                            <?php
                            // Only meaningful for the custom mode. Hidden here on load and
                            // toggled by the radios in JS; the hidden input still submits
                            // while hidden, so an existing selection survives a save made
                            // in another mode.
                            $is_custom = 'custom' === $settings['icon_mode'];
                            ?>
                            <div id="error-icon-controls"<?php echo $is_custom ? '' : ' style="display:none;"'; ?>>
                                <p style="margin-top:12px;">
                                    <input type="hidden" id="error-icon-id"
                                        name="<?php echo esc_attr($name); ?>[icon_id]"
                                        value="<?php echo esc_attr((string) $settings['icon_id']); ?>" />
                                    <button type="button" class="button" id="error-icon-select">Choose image</button>
                                    <button type="button" class="button-link" id="error-icon-remove"
                                        <?php echo $icon_url ? '' : 'style="display:none;"'; ?>>Remove</button>
                                </p>
                                <p>
                                    <img id="error-icon-preview" alt=""
                                        src="<?php echo esc_url($icon_url); ?>"
                                        style="max-width:200px;height:auto;<?php echo $icon_url ? '' : 'display:none;'; ?>" />
                                </p>
                            </div>
                        </td>
                    </tr>
                </table>

                <h2 class="title">Colours</h2>
                <p class="description">
                    Leave a field empty to keep the value from the stylesheet.
                </p>
                <table class="form-table" role="presentation">
                    <?php
                    $color_labels = [
                        'bg_color'     => 'Page background',
                        'card_color'   => 'Card background',
                        'text_color'   => 'Text',
                        'accent_color' => 'Accent',
                    ];

                    foreach ($color_labels as $key => $label) :
                        ?>
                        <tr>
                            <th scope="row">
                                <label for="error-<?php echo esc_attr($key); ?>">
                                    <?php echo esc_html($label); ?>
                                </label>
                            </th>
                            <td>
                                <input type="text" class="error-color"
                                    id="error-<?php echo esc_attr($key); ?>"
                                    name="<?php echo esc_attr($name); ?>[<?php echo esc_attr($key); ?>]"
                                    value="<?php echo esc_attr($settings[$key]); ?>" />
                                <p class="description">
                                    <code><?php echo esc_html(self::COLOR_TOKENS[$key]); ?></code>
                                </p>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </table>

                <h2 class="title">Coverage</h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">Drop-in</th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr($name); ?>[dropin_enabled]" value="1"
                                    <?php checked(!empty($settings['dropin_enabled'])); ?>
                                    <?php disabled(Error_Page_Dropin::STATUS_FOREIGN === $dropin_status); ?> />
                                Install <code>php-error.php</code> for full coverage
                            </label>
                            <p class="description">
                                Without this, the branded page covers fatal errors that happen after this plugin
                                loads &mdash; the large majority. With it, errors in mu-plugins, earlier-loading
                                plugins and <code>wp-config.php</code> are covered too.
                            </p>
                            <p class="description">
                                <strong>Note:</strong> this writes
                                <code><?php echo esc_html($dropin_path); ?></code>, outside the plugin folder.
                                If your <code>wp-content</code> is version-controlled, the file will show up in
                                <code>git status</code>. It is removed automatically when you switch this off or
                                deactivate the plugin.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Error details</th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr($name); ?>[show_error_details]" value="1"
                                    <?php checked(!empty($settings['show_error_details'])); ?> />
                                Show the underlying error to administrators
                            </label>
                            <p class="description">
                                Only ever rendered when <code>WP_DEBUG</code> is enabled
                                <em>and</em> the visitor can manage options. Currently
                                <code>WP_DEBUG</code> is
                                <strong><?php echo defined('WP_DEBUG') && WP_DEBUG ? 'on' : 'off'; ?></strong>.
                            </p>
                        </td>
                    </tr>
                </table>

                <?php submit_button(); ?>
            </form>

            <h2 class="title">Re-theming on another site</h2>
            <p>
                Everything visual is a CSS custom property. Drop a file named
                <code>error-page.css</code> into your theme (or into
                <code>wp-content/</code>) and redefine only the tokens you need &mdash; it is
                inlined after the plugin stylesheet and before the colours above.
            </p>
            <p>
                For a different layout entirely, copy
                <code>templates/error-page.php</code> to
                <code>&lt;your-theme&gt;/error-page/error-page.php</code>.
            </p>
        </div>
        <?php
    }

    /**
     * Build the `:root` block that lets the settings colours win over the stylesheet.
     */
    public function build_color_overrides(array $settings): string
    {
        $declarations = [];

        foreach (self::COLOR_TOKENS as $key => $token) {
            $value = isset($settings[$key]) ? trim((string) $settings[$key]) : '';

            // Only hex values ever reach this point, but re-check: this string is
            // written straight into a <style> block.
            if ('' === $value || !preg_match('/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $value)) {
                continue;
            }

            $declarations[] = sprintf('%s: %s;', $token, $value);
        }

        if (empty($declarations)) {
            return '';
        }

        return ":root {\n    " . implode("\n    ", $declarations) . "\n}\n";
    }
}
