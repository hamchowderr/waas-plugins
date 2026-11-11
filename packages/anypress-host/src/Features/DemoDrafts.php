<?php

namespace AnyPressHost\Features;

/**
 * A website built as a demo in the visitor's browser (WordPress Playground) and saved with the API as a demo draft
 * becomes the store they buy. The "Make this my site" button sends them here with `?demo=<draft id>`; the id is kept
 * in a cookie until checkout, saved on the order, and sent with the store's create call (demoDraftId), so the new
 * store starts with the demo's pages, menus, styles and photos.
 */
final class DemoDrafts
{
    public const COOKIE = 'anypress_demo';
    /** The API's draft ids: `demo_` and 22 base64url characters. */
    private const PATTERN = '/^demo_[A-Za-z0-9_-]{22}$/';
    /** Drafts are kept 14 days. */
    private const KEEP = 14 * DAY_IN_SECONDS;

    public static function init(): void
    {
        add_action('init', [self::class, 'remember']);
    }

    /** A visit with `?demo=<draft id>` remembers the draft for this visitor's checkout. */
    public static function remember(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a link from the demo page, only remembered.
        $id = self::valid(isset($_GET['demo']) ? sanitize_text_field(wp_unslash((string) $_GET['demo'])) : '');
        if ($id === '' || headers_sent()) {
            return;
        }
        self::cookie($id, time() + self::KEEP);
        $_COOKIE[self::COOKIE] = $id;
    }

    /** The demo draft this visitor brought to checkout, or ''. */
    public static function pending(): string
    {
        return self::valid(isset($_COOKIE[self::COOKIE]) ? sanitize_text_field(wp_unslash((string) $_COOKIE[self::COOKIE])) : '');
    }

    /** The draft is on the order now: a later purchase by the same visitor starts from the template. */
    public static function forget(): void
    {
        unset($_COOKIE[self::COOKIE]);
        if (!headers_sent()) {
            self::cookie('', time() - HOUR_IN_SECONDS);
        }
    }

    public static function valid(string $id): string
    {
        return preg_match(self::PATTERN, $id) === 1 ? $id : '';
    }

    private static function cookie(string $value, int $expires): void
    {
        setcookie(self::COOKIE, $value, [
            'expires' => $expires,
            'path' => defined('COOKIEPATH') && COOKIEPATH ? COOKIEPATH : '/',
            'domain' => defined('COOKIE_DOMAIN') && COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
            'secure' => is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}
