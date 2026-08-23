<?php
/**
 * The error page markup.
 *
 * Copy this file to `<your-theme>/error-page/error-page.php` to override it.
 * Prefer overriding the CSS custom properties first — most re-brands need no PHP.
 *
 * @var array $error Context built by Error_Page_Renderer::build_context().
 *
 * @package error-page
 */

if (!defined('ABSPATH')) {
    exit;
}

?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($error['lang'], ENT_QUOTES, 'UTF-8'); ?>">
<head>
    <meta charset="<?php echo htmlspecialchars($error['charset'], ENT_QUOTES, 'UTF-8'); ?>" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <meta name="robots" content="noindex, nofollow" />
    <title><?php echo $error['escape']($error['document_title']); ?></title>
    <style><?php echo $error['css']; // phpcs:ignore WordPress.Security.EscapeOutput ?></style>
</head>
<body class="error-page">
    <main class="error-card" role="main">
        <?php if ('' !== $error['icon']) : ?>
            <div class="error-icon" aria-hidden="true">
                <?php echo $error['icon']; // phpcs:ignore WordPress.Security.EscapeOutput ?>
            </div>
        <?php endif; ?>

        <?php
        // Both are optional. Skip the wrapper entirely when neither is set, so the card
        // does not carry an empty flex child and a doubled gap.
        if ('' !== $error['code'] || '' !== $error['title']) :
            ?>
            <div class="error-heading">
                <?php if ('' !== $error['code']) : ?>
                    <h1 class="error-code"><?php echo $error['escape']($error['code']); ?></h1>
                <?php endif; ?>

                <?php if ('' !== $error['title']) : ?>
                    <p class="error-title"><?php echo $error['escape']($error['title']); ?></p>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ('' !== $error['message']) : ?>
            <p class="error-message">
                <?php echo $error['message']; // phpcs:ignore WordPress.Security.EscapeOutput ?>
            </p>
        <?php endif; ?>

        <?php if (null !== $error['details']) : ?>
            <details class="error-details">
                <summary>Error details</summary>
                <pre><?php
                    echo $error['escape']($error['details']['message']);

                    if ('' !== $error['details']['file']) {
                        echo "\n\n" . $error['escape'](
                            $error['details']['file'] . ':' . $error['details']['line']
                        );
                    }
                ?></pre>
            </details>
        <?php endif; ?>
    </main>
</body>
</html>
