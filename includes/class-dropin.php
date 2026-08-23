<?php
/**
 * Manages the optional `wp-content/php-error.php` drop-in.
 *
 * Core checks for this file inside `WP_Fatal_Error_Handler::display_error_template()`
 * before falling back to its own template, so the drop-in catches fatals that happen
 * before this plugin has loaded — mu-plugins, earlier-loading plugins, wp-config.
 *
 * The installed file is a thin loader. All rendering stays in the plugin so updates take
 * effect without rewriting the drop-in.
 *
 * @package error-page
 */

if (!defined('ABSPATH')) {
    exit;
}

class Error_Page_Dropin
{
    /** Marker used to tell our drop-in apart from somebody else's. */
    const SIGNATURE = 'Error Page drop-in';

    const STATUS_MISSING = 'missing';
    const STATUS_OURS    = 'ours';
    const STATUS_FOREIGN = 'foreign';

    public static function path(): string
    {
        if (!defined('WP_CONTENT_DIR')) {
            return '';
        }

        return WP_CONTENT_DIR . '/php-error.php';
    }

    public static function status(): string
    {
        $path = self::path();

        if ('' === $path || !file_exists($path)) {
            return self::STATUS_MISSING;
        }

        $contents = is_readable($path) ? file_get_contents($path) : false;

        if (is_string($contents) && false !== strpos($contents, self::SIGNATURE)) {
            return self::STATUS_OURS;
        }

        return self::STATUS_FOREIGN;
    }

    public static function is_installed(): bool
    {
        return self::STATUS_OURS === self::status();
    }

    /**
     * @return true|WP_Error
     */
    public static function install()
    {
        $path = self::path();

        if ('' === $path) {
            return new WP_Error(
                'error_page_no_content_dir',
                'WP_CONTENT_DIR is not defined, so the drop-in cannot be installed.'
            );
        }

        if (self::STATUS_FOREIGN === self::status()) {
            return new WP_Error(
                'error_page_foreign_dropin',
                sprintf(
                    'A php-error.php drop-in already exists at %s and was not created by this plugin. '
                    . 'Remove or rename it first.',
                    $path
                )
            );
        }

        if (!is_writable(dirname($path))) {
            return new WP_Error(
                'error_page_dropin_not_writable',
                sprintf('%s is not writable.', dirname($path))
            );
        }

        if (false === file_put_contents($path, self::contents())) {
            return new WP_Error(
                'error_page_dropin_write_failed',
                sprintf('Could not write %s.', $path)
            );
        }

        return true;
    }

    /**
     * Never deletes a drop-in we did not create.
     */
    public static function remove(): bool
    {
        if (self::STATUS_OURS !== self::status()) {
            return false;
        }

        return (bool) @unlink(self::path());
    }

    /**
     * The drop-in body. Bakes in the plugin folder name so a renamed folder still works.
     */
    public static function contents(): string
    {
        $folder = defined('ERROR_PAGE_DIR')
            ? basename(rtrim(ERROR_PAGE_DIR, '/\\'))
            : 'error-page';

        $bootstrap = "WP_PLUGIN_DIR . '/" . $folder . "/includes/dropin-bootstrap.php'";
        $signature = self::SIGNATURE;

        return <<<PHP
<?php
/**
 * {$signature}
 *
 * Managed automatically. Do not edit; your changes will be overwritten.
 * Remove it from Settings → Error Page, or simply delete this file.
 *
 * @var array \$error   The fatal error reported by WordPress.
 * @var bool  \$handled Whether recovery mode already handled it.
 */

if (!defined('ABSPATH')) {
    exit;
}

\$error_page_bootstrap = defined('WP_PLUGIN_DIR') ? {$bootstrap} : '';

if ('' !== \$error_page_bootstrap && is_readable(\$error_page_bootstrap)) {
    require \$error_page_bootstrap;
    return;
}

// The plugin has been removed. Print something rather than a blank page.
if (!headers_sent()) {
    header('Content-Type: text/html; charset=UTF-8', true, 500);
    header('Retry-After: 300');
    header('X-Robots-Tag: noindex, nofollow', true);
}

echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">'
    . '<meta name="viewport" content="width=device-width,initial-scale=1">'
    . '<meta name="robots" content="noindex, nofollow">'
    . '<title>500 Internal Server Error</title><style>'
    . 'body{margin:0;padding:24px;min-height:100vh;display:flex;align-items:center;'
    . 'justify-content:center;background:#e9e9e9;color:#000;'
    . 'font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif}'
    . 'main{max-width:834px;padding:64px 40px;background:#fff;text-align:center}'
    . 'h1{margin:0;font-size:64px;line-height:1.2}'
    . 'p{margin:0;font-size:20px;line-height:1.3}'
    . '.t{font-size:24px;font-weight:700;margin-bottom:30px}'
    . '</style></head><body><main><h1>500</h1>'
    . '<p class="t">Internal Server Error</p>'
    . '<p>This website currently can\'t serve this page. Our technical team have already '
    . 'been automatically notified. Please try again in a few moments.</p></main></body></html>';

PHP;
    }
}
