# Error Page — AI agent notes

Replaces the WordPress critical-error screen with a branded 500 page built from the
[Error 500 Figma file](https://www.figma.com/design/yDW5xDvg7zhtoYqUsBl2So/Error-500-page?node-id=1-2).

## Files

```
error-page/
├── error-page.php                  # plugin header, constants, requires, bootstrap on plugins_loaded,
│                                   #   deactivation/uninstall hooks → Dropin::remove()
├── includes/
│   ├── class-settings.php          # Error_Page_Settings      — option, defaults, sanitize, settings form, colour→token CSS
│   ├── class-renderer.php          # Error_Page_Renderer      — the ONLY output path; context, CSS assembly, headers, template lookup
│   ├── class-error-handler.php     # Error_Page_Error_Handler — wp_php_error_message → scoped wp_die_handler
│   ├── class-dropin.php            # Error_Page_Dropin        — install/remove/status of wp-content/php-error.php (all static)
│   ├── class-admin-page.php        # Error_Page_Admin_Page    — menu, assets, preview route, drop-in sync, notices
│   ├── class-plugin.php            # Error_Page_Plugin        — orchestrator
│   └── dropin-bootstrap.php        # what the installed drop-in requires; runs before the plugin loads
├── templates/error-page.php        # markup, theme-overridable
├── assets/css/error-page.css       # tokens + rules
├── assets/img/server-error.svg     # Figma illustration, 136.792 × 81.8132, inlined at render
└── docs/superpowers/specs/2026-08-12-error-page-design.md
```

Dependencies (constructor-injected): `Renderer ← Settings`, `Error_Handler ← Renderer`,
`Admin_Page ← Settings, Renderer`. `Dropin` is static and depended on directly by
`Admin_Page` and `Settings` (form rendering).

Each subsystem owns its hooks via `register_hooks()`, called from
`Error_Page_Plugin::register_hooks()` on `plugins_loaded`. Constructors stay
side-effect-free. Only `Error_Handler` registers on the front end; `Settings` and
`Admin_Page` register behind `is_admin()`.

## Two interception paths

**Filter path** (`class-error-handler.php`). Core applies `wp_php_error_message` immediately
before `wp_die()` in `WP_Fatal_Error_Handler::display_default_error_template()`. We attach
`wp_die_handler` (priority 99) *from inside that filter*, so it can only affect that one call.

Do not move the `wp_die_handler` registration to `register_hooks()` — a global handler would
hijack every nonce failure, capability check and REST error on the site.

**Drop-in path** (`class-dropin.php` + `dropin-bootstrap.php`). Core requires
`WP_CONTENT_DIR/php-error.php` inside `display_error_template()`, *before* the default
template, with `$error` and `$handled` in scope. The installed file is a thin loader so plugin
updates apply without rewriting it. `dropin-bootstrap.php` may run before the plugin's own
bootstrap, so it defines `ERROR_PAGE_DIR` itself and requires only Settings + Renderer.

When the drop-in exists, `wp_php_error_message` never fires — the two paths cannot both run.

## Rendering rules

The renderer runs on a site that has already thrown a fatal, which drives every constraint:

- No `wp_enqueue_*`, no external requests, no webfont. CSS and the SVG are read with
  `file_get_contents()` and inlined.
- Every WordPress call goes through a guarded wrapper (`bloginfo()`, `attachment_url()`,
  `current_user_is_admin()`, `Settings::get_settings()`) — `function_exists` plus try/catch,
  each with a hardcoded default. Do not call WP functions directly from render code.
- Templates escape via the `escape` closure in the context array, not `esc_html()`, so a theme
  override still works if `wp-includes/formatting.php` never loaded.
- `build_css()` runs `str_ireplace('</style', ...)` last — a site override file could otherwise
  break out of the inline `<style>`.
- Non-HTML requests (AJAX, REST, XML-RPC, JSON, WP-CLI) bail in `should_render()`. Handing a
  JSON caller an HTML document breaks the consumer worse than the default screen does.

## CSS token layers

Assembled in `Renderer::build_css()`, later wins:

1. `assets/css/error-page.css`
2. first readable of `<child theme>/error-page.css`, `<parent theme>/…`,
   `WP_CONTENT_DIR/error-page.css`
3. `Settings::build_color_overrides()` — a `:root{}` block from the four colour fields

Nothing visual is hardcoded in `templates/error-page.php`. New visual values go in as tokens.

The Figma card is 834px wide with 200px inline / 100px block padding. Those are wrapped in
`clamp()` — 200px of inline padding inside a 375px viewport leaves no content width.

## Settings

One option, `error_page_settings`, defaults merged at read time in
`Settings::get_settings()`, so no activation hook is needed to seed it. New settings must be
added to `get_default_settings()`, `sanitize_settings()` and `render_settings_page()`.

`Error_Page_Settings::COLOR_TOKENS` maps a setting key to its CSS custom property; add
to that map rather than hardcoding a token name anywhere else.

`build_color_overrides()` re-validates the hex with a regex even though `sanitize_hex_color()`
already ran — the string is written straight into a `<style>` block.

## Drop-in lifecycle

Installed/removed by `Admin_Page::sync_dropin()`, hooked on
`update_option_error_page_settings` / `add_option_…`. On a write failure it stores a
transient notice and flips `dropin_enabled` back off, temporarily removing its own hook to
avoid recursion.

`Dropin::status()` returns `missing` / `ours` / `foreign`, keyed on the `SIGNATURE` constant
appearing in the file. A foreign drop-in is never overwritten or deleted, and the settings
checkbox is disabled while one exists.

`register_deactivation_hook` and `register_uninstall_hook` both call `Dropin::remove()`. The
drop-in must never outlive the plugin it points at — keep both.

## Environment gotchas (found while testing in wp_climate_investment)

- **Bedrock development config sets `WP_DISABLE_FATAL_ERROR_HANDLER = true`**
  (`config/environments/development.php`). WordPress then never runs its fatal handler, so
  *neither* path can fire locally. Stock WordPress renders nothing there either.
- **Roots Acorn** registers `set_error_handler`/`set_exception_handler`/`register_shutdown_function`
  when `app.debug` (`WP_DEBUG && WP_DEBUG_DISPLAY`) is true and no `fatal-error-handler.php`
  drop-in exists, and renders the Laravel debug screen instead. It stands down in production.
- To verify the real paths without a working fatal handler, reproduce core's call sequence from
  an mu-plugin: for the filter path, `apply_filters('wp_php_error_message', …)` then
  `wp_die($wp_error, '', ['response' => 500, 'exit' => false])`; for the drop-in path,
  `require WP_CONTENT_DIR . '/php-error.php'` with `$error` and `$handled` in scope. Both are
  byte-for-byte what `WP_Fatal_Error_Handler` does.
- Cycling W3 Total Cache rewrites `web/.htaccess` and reorders the custom blocks. Avoid
  deactivating it; `git checkout -- web/.htaccess` if it happens.

## When working on this plugin

- Keep `Renderer` the only place that produces output. Admin previews, the filter path and the
  drop-in path all funnel through `Renderer::render()`.
- Prefer a new CSS token over a new setting. Settings are for content and the four brand
  colours; anything else belongs in the stylesheet where a site can override it.
- Scope stays the fatal-error screen. `db-error.php`, `maintenance.php` and 404 were considered
  and deliberately left out (404 belongs to the theme).
