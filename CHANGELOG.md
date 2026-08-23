# Changelog

## 1.0.0

- Initial release.
- Branded 500 page replacing the WordPress critical error screen, built from the
  Error 500 Figma design.
- Filter-based interception via `wp_php_error_message` + a scoped `wp_die_handler`.
- Optional `wp-content/php-error.php` drop-in for fatals that happen before the plugin loads.
- All visual values exposed as CSS custom properties, with three override layers:
  plugin stylesheet → site `error-page.css` → settings colour fields.
- Settings screen with content fields, illustration picker, colour pickers and a preview.
- Illustration picker controls are only shown while **Custom image** is selected; Remove
  clears the selected image without switching the mode back to Default illustration.
- Error code and title are both optional — clearing either field hides that line, and the
  heading wrapper is skipped entirely when neither is set.
- Theme-overridable template at `<theme>/error-page/error-page.php`.
