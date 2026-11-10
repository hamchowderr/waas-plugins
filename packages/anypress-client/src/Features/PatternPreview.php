<?php

namespace AnyPressClient\Features;

use AnyPressClient\Core\SignedRequest;

/**
 * A page that shows one pattern (a section, a starter page or a kit's home page) in this website's look, or in one
 * of the theme's style variations, for the preview gallery in AI apps. The AnyPress API asks for a link through the
 * signed preview route; the link carries a token signed with the site key that runs out after PREVIEW_TTL, so the
 * page can be framed by the app without a sign-in. It changes nothing: the variation is only applied to this one
 * page load, through WordPress's own wp_theme_json_data_user filter.
 */
class PatternPreview
{
    public const QUERY = 'anypress_preview';
    public const PREVIEW_TTL = 900;

    public function __construct()
    {
        // Only requests that carry a preview token do any work.
        if (isset($_GET[self::QUERY])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the token is checked before anything is shown.
            add_action('template_redirect', [$this, 'render'], 0);
        }
    }

    /** A preview link for one pattern, optionally in a style variation; '' when the site has no site key. */
    public static function url(string $pattern, string $variation = ''): string
    {
        $key = SignedRequest::key();
        if ($key === '') {
            return '';
        }
        $payload = self::encode((string) wp_json_encode(['p' => $pattern, 'v' => $variation, 'e' => time() + self::PREVIEW_TTL]));

        return add_query_arg(self::QUERY, $payload . '.' . self::encode(hash_hmac('sha256', $payload, $key, true)), home_url('/'));
    }

    /** What a valid token asks for: [pattern, variation], or null. */
    public static function read(string $token, ?int $now = null): ?array
    {
        $key = SignedRequest::key();
        $parts = explode('.', $token);
        if ($key === '' || count($parts) !== 2) {
            return null;
        }
        if (!hash_equals(self::encode(hash_hmac('sha256', $parts[0], $key, true)), $parts[1])) {
            return null;
        }
        $data = json_decode((string) base64_decode(strtr($parts[0], '-_', '+/'), true), true);
        if (!is_array($data) || !is_string($data['p'] ?? null) || (int) ($data['e'] ?? 0) < ($now ?? time())) {
            return null;
        }

        return [$data['p'], is_string($data['v'] ?? null) ? $data['v'] : ''];
    }

    public function render(): void
    {
        $asked = self::read(sanitize_text_field(wp_unslash($_GET[self::QUERY] ?? ''))); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the signed token is the check.
        $pattern = $asked ? \WP_Block_Patterns_Registry::get_instance()->get_registered($asked[0]) : null;
        if (!$pattern) {
            wp_die(esc_html__('This preview link has run out. Ask for a new one.', 'anypress-client'), '', ['response' => 403]);
        }
        $variation = $asked[1] !== '' ? $this->variation($asked[1]) : null;
        if ($variation) {
            add_filter('wp_theme_json_data_user', fn ($data) => $data->update_with(array_intersect_key($variation, array_flip(['version', 'settings', 'styles']))));
        }
        add_filter('show_admin_bar', '__return_false');
        nocache_headers();
        header('X-Robots-Tag: noindex, nofollow');
        // Shown inside AI apps' panels, whatever their address.
        header('Content-Security-Policy: frame-ancestors *');
        header_remove('X-Frame-Options');

        // Rendered before wp_head, as WordPress's own block template canvas does, so the blocks' styles are printed.
        $content = do_blocks($pattern['content']);
        ?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo('charset'); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php wp_head(); ?>
</head>
<body <?php body_class('anypress-preview'); ?>>
<div class="wp-site-blocks"><?php echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the theme's registered pattern, rendered by WordPress. ?></div>
<?php wp_footer(); ?>
</body>
</html>
        <?php
        exit;
    }

    private function variation(string $slug): ?array
    {
        foreach (\WP_Theme_JSON_Resolver::get_style_variations() as $variation) {
            if (($variation['slug'] ?? sanitize_title($variation['title'])) === $slug) {
                return $variation;
            }
        }

        return null;
    }

    private static function encode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
