# Error Page

Replaces the default WordPress &ldquo;critical error&rdquo; screen with a branded 500 page. Every
visual value is a CSS custom property, so the same plugin drops into any site and gets
re-themed without touching PHP.

Design source: [Error 500 page](https://www.figma.com/design/yDW5xDvg7zhtoYqUsBl2So/Error-500-page?node-id=1-2).

## Features

- **Branded fatal-error page** — illustration, error code, title and message, centred on a card.
- **Two interception paths** — a filter path that works as soon as the plugin is active, plus an
  optional `php-error.php` drop-in for fatals that happen before the plugin loads.
- **Three theming layers** — plugin stylesheet, a site `error-page.css`, then the colour
  fields on the settings screen. Nothing is hardcoded in the markup.
- **Theme-overridable template** for layouts that need more than tokens.
- **Safe on a broken site** — no enqueued assets, no external requests, no webfont; every
  WordPress call guarded so a dead database still yields a styled page.
- **Not indexable** — sends `500`, `Retry-After`, `X-Robots-Tag: noindex` and a robots meta tag.
- **Preview** from the settings screen, so you never have to break the site to check styling.

## Requirements

- WordPress 5.8+
- PHP 7.4+

## Installation

Drop the `error-page/` folder into `wp-content/plugins/` (or `web/app/plugins/` on
Bedrock), then activate **Error Page** from the Plugins screen.

Settings live at **Settings → Error Page**.

## How it intercepts the error

### Filter path (always on)

`WP_Fatal_Error_Handler::display_default_error_template()` applies the `wp_php_error_message`
filter immediately before it calls `wp_die()`. The plugin uses that as a precise scope trigger:
it attaches its own `wp_die_handler` from inside that filter, so it is only ever in play for
that one call. Every other `wp_die()` on the site is untouched.

This covers fatals that occur after the plugin has loaded — the large majority.

### Drop-in path (opt-in)

Tick **Install `php-error.php` for full coverage** on the settings screen. Core checks
`wp-content/php-error.php` before falling back to its own template, so this also covers fatals
in mu-plugins, earlier-loading plugins and `wp-config.php`.

The installed file is a thin loader; all the rendering stays in the plugin, so plugin updates
apply without rewriting the drop-in. It is removed automatically when you switch the option
off, deactivate the plugin, or uninstall it. A `php-error.php` the plugin did not create is
never overwritten or deleted.

> If your `wp-content` is version-controlled, note that the drop-in lands outside the plugin
> folder and will show up in `git status`.

### When it will not fire

- `WP_DISABLE_FATAL_ERROR_HANDLER` is true — WordPress never runs its fatal handler, so neither
  path is reached. Bedrock sets this in `config/environments/development.php` by default.
- Roots Acorn registers its own error handler while `WP_DEBUG && WP_DEBUG_DISPLAY` is true, and
  it renders the Laravel-style debug screen instead. Acorn stands down in production.
- AJAX, REST, XML-RPC, JSON and WP-CLI requests are deliberately skipped — those callers expect
  JSON or plain text, so WordPress's default response is used.

## Settings

| Setting | Description | Default |
|---|---|---|
| Custom error page | Master on/off | Enabled |
| Error code | Large numeral. Optional — empty hides it | `500` |
| Title | Line under the code. Optional — empty hides it | `Internal Server Error` |
| Message | Body copy; `{site_name}` is substituted, basic HTML allowed | See below |
| Illustration | Default SVG, a custom image from the media library, or none | Default |
| Page / card / text / accent colour | Empty means &ldquo;use the stylesheet value&rdquo; | Empty |
| Error details | Show the underlying error to administrators | Off |
| Drop-in | Install `php-error.php` | Off |

Default message: *&ldquo;{site_name} currently can&rsquo;t serve this page. Our technical team
have already been automatically notified. Please try again in a few moments.&rdquo;*

Error details are only ever rendered when the setting is on **and** `WP_DEBUG` is true **and**
the visitor can `manage_options`.

## Re-theming on another site

### 1. Override the tokens (usual case)

Create `error-page.css` in your child theme, parent theme, or `wp-content/`. The first
one found is inlined after the plugin stylesheet, so you only redefine what you need:

```css
:root {
    --error-bg: #06251f;
    --error-card-bg: #0f3d33;
    --error-text-color: #eafff7;
    --error-card-radius: 24px;
    --error-code-size: 96px;
}
```

Available tokens:

| Token | Default |
|---|---|
| `--error-bg` | `#e9e9e9` |
| `--error-card-bg` | `#ffffff` |
| `--error-card-max-width` | `834px` |
| `--error-card-radius` | `0` |
| `--error-card-shadow` | `none` |
| `--error-card-padding-block` | `clamp(48px, 8vw, 100px)` |
| `--error-card-padding-inline` | `clamp(24px, 12vw, 200px)` |
| `--error-gap` | `30px` |
| `--error-page-padding` | `24px` |
| `--error-font-family` | Inter → system stack |
| `--error-text-color` | `#000000` |
| `--error-accent` | `#e10600` |
| `--error-code-size` / `-weight` / `-line-height` / `-letter-spacing` | `clamp(48px, 10vw, 64px)` / `700` / `1.2` / `normal` |
| `--error-title-size` / `-weight` / `-line-height` | `clamp(20px, 4vw, 24px)` / `700` / `1.2` |
| `--error-message-size` / `-weight` / `-line-height` / `-max-width` | `clamp(16px, 3vw, 20px)` / `400` / `1.3` / `434px` |
| `--error-icon-width` / `-aspect` | `136.792px` / `136.792 / 81.8132` |
| `--error-details-*` | greys for the admin detail block |

### 2. Settings colours

The four colour fields are emitted last, so they win over both the plugin stylesheet and the
site override file. Leave them empty to defer to CSS.

### 3. Replace the markup

Copy `templates/error-page.php` to `<your-theme>/error-page/error-page.php`. The context
array `$error` gives you `code`, `title`, `message`, `icon`, `css`, `details`, `lang`,
`charset`, `document_title` and an `escape` callable.

## Filter

`error_page_should_render` (bool) — return false to fall back to the WordPress default
for a given request.

```php
add_filter('error_page_should_render', function ($should) {
    return is_admin() ? false : $should;
});
```

## Testing the page

Use **Preview the error page** on the settings screen — it renders the real template with the
saved settings, at HTTP 200 so the browser displays it normally.

To exercise the actual fatal path, the environment must have the WordPress fatal handler
enabled (see *When it will not fire* above).
