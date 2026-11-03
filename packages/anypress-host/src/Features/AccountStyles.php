<?php

namespace AnyPressHost\Features;

/**
 * The look of the customer's website panel (the cart's account page): one card per website, a status pill,
 * the main buttons in a row and the domain and email settings folded away until opened. Printed once, inline,
 * next to the first panel, so it only loads on that page. Colours follow the page's own text colour, so it
 * sits in FluentCart's portal and in a WooCommerce theme alike.
 */
class AccountStyles
{
    private const HANDLE = 'anypress-account';

    private static bool $printed = false;

    public static function print_once(): void
    {
        if (self::$printed) {
            return;
        }
        self::$printed = true;

        // Through WordPress's style queue, so caching and optimisation plugins see it. AnyPress's own font, as on
        // anypress.io and the dashboard, comes with the plugin: visitors' browsers never call a font service.
        wp_register_style(self::HANDLE, false, [], defined('ANYPRESS_HOST_VERSION') ? ANYPRESS_HOST_VERSION : null);
        wp_add_inline_style(self::HANDLE, self::font_face() . self::css());
        wp_print_styles(self::HANDLE);
    }

    private static function font_face(): string
    {
        $url = esc_url(plugins_url('assets/fonts/hanken-grotesk-latin.woff2', dirname(__DIR__, 2) . '/anypress-host.php'));

        return "@font-face{font-family:\"Hanken Grotesk\";font-style:normal;font-weight:400 800;font-display:swap;src:url({$url}) format(\"woff2\")}";
    }

    /** For tests: print again on the next call. */
    public static function reset(): void
    {
        self::$printed = false;
    }

    private static function css(): string
    {
        return <<<'CSS'
.fct_customer_profile_wrap.fct_customer_profile_wrap{max-width:1040px!important;margin-left:auto!important;margin-right:auto!important;font-family:"Hanken Grotesk",system-ui,sans-serif}
.anypress-acct,.anypress-acct-notice{font-family:"Hanken Grotesk",system-ui,sans-serif;color:#141414}
.anypress-acct{display:grid;gap:22px;min-width:0;max-width:760px}
.anypress-acct *,.anypress-acct-notice *{box-sizing:border-box}
.anypress-acct-site{min-width:0;max-width:100%;border:1.5px solid #141414;border-radius:16px;padding:22px 24px;background:#fff;font-size:16px}
.anypress-acct-site:first-child{box-shadow:6px 6px 0 #ffc75a}
.anypress-acct-site h3{margin:10px 0 2px;font-size:22px;font-weight:800;letter-spacing:-.01em;font-family:inherit;line-height:1.25;overflow-wrap:break-word}
.anypress-acct-visit{font-weight:700;color:#c8264d}
.anypress-acct-status{display:inline-flex;align-items:center;gap:7px;padding:3px 11px;border-radius:999px;background:rgba(0,0,0,.05);font-size:.85em;font-weight:600}
.anypress-acct-status i{width:8px;height:8px;border-radius:999px;background:#8a8580}
.anypress-acct-status.is-ready i{background:#17703d}
.anypress-acct-status.is-busy i{background:#e59a00}
.anypress-acct-status.is-attention i{background:#c8264d}
.anypress-acct-lead{margin:12px 0 0;color:#5f5a55;max-width:62ch}
.anypress-acct-actions{display:flex;flex-wrap:wrap;gap:10px;margin-top:18px}
.anypress-acct-actions a{text-decoration:none}
.anypress-acct-btn{display:inline-flex;align-items:center;justify-content:center;padding:11px 18px;border-radius:10px;font-family:inherit;border:1.5px solid #141414;background:#fff;color:#141414;font-size:15px;font-weight:600;line-height:1.2;text-decoration:none;cursor:pointer}
.anypress-acct-btn:hover{background:#f4f2ef;color:#141414}
.anypress-acct-btn.is-primary{background:#141414;color:#fff}
.anypress-acct-btn.is-primary:hover{background:#141414;color:#fff;box-shadow:0 4px 0 #ffb338}
.anypress-acct-panel{border-top:1px solid rgba(0,0,0,.1);margin-top:18px}
.anypress-acct-panel+.anypress-acct-panel{margin-top:0}
.anypress-acct-panel>summary{cursor:pointer;list-style:none;padding:14px 0;font-weight:600;display:flex;flex-wrap:wrap;align-items:center;gap:4px 10px}
.anypress-acct-panel>summary::-webkit-details-marker{display:none}
.anypress-acct-panel>summary::before{content:"+";display:inline-grid;place-items:center;width:22px;height:22px;border:1.5px solid currentColor;border-radius:999px;font-weight:700;line-height:1}
.anypress-acct-panel[open]>summary::before{content:"\2013"}
.anypress-acct-panel>summary small{font-weight:400;opacity:.7;overflow-wrap:anywhere}
.anypress-acct-panel-body{padding:0 0 16px 32px}
.anypress-acct-panel-body p{margin:0 0 10px}
.anypress-acct-step{font-weight:600}
.anypress-acct-step b{color:#c8264d}
.anypress-acct-records{display:grid;gap:6px;margin:6px 0 16px}
.anypress-acct-record{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.anypress-acct-record b{min-width:58px;font-weight:600;text-align:center;padding:4px 8px;border-radius:6px;background:#141414;color:#fff;font-size:.8em}
.anypress-acct-record code{font-size:14px;padding:5px 9px;border-radius:6px;background:rgba(0,0,0,.05);overflow-wrap:anywhere}
.anypress-acct-form{display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;margin:8px 0 6px}
.anypress-acct-form label{display:flex;flex-direction:column;gap:6px;flex:1 1 220px;font-weight:600}
.anypress-acct-form input[type=text]{width:100%;padding:10px 12px;font-size:15px;border:1px solid rgba(0,0,0,.25);border-radius:8px;font-weight:400}
.anypress-acct-error{color:#b3123a;font-weight:600}
.anypress-acct-note{font-size:.92em;opacity:.8}
.anypress-acct table.anypress-mail-records{width:100%;border-collapse:collapse;margin:6px 0 14px;font-size:.92em}
.anypress-acct table.anypress-mail-records th,.anypress-acct table.anypress-mail-records td{text-align:left;padding:6px 8px;border-bottom:1px solid rgba(0,0,0,.08);vertical-align:top}
.anypress-acct table.anypress-mail-records code{overflow-wrap:anywhere}
.anypress-acct .anypress-choose-website-name{margin-top:14px}
.anypress-acct .anypress-choose-website-name label{display:flex;flex-direction:column;gap:6px;font-weight:600;margin:10px 0}
.anypress-acct .anypress-choose-website-name input[type=text]{padding:10px 12px;font-size:15px;border:1px solid rgba(0,0,0,.25);border-radius:8px;font-weight:400;max-width:420px}
.anypress-acct-emails{margin:14px 0 0;opacity:.85}
.anypress-acct-notice{display:flex;flex-wrap:wrap;align-items:center;gap:12px 16px;border:1.5px solid #141414;border-radius:16px;box-shadow:5px 5px 0 #ffc75a;padding:16px 20px;margin-bottom:18px;background:#fff;font-size:15px}
.anypress-acct-notice p{margin:0;flex:1 1 260px;overflow-wrap:anywhere}
.anypress-acct-notice .anypress-acct-actions{margin-top:0}
@media (max-width:600px){.anypress-acct-panel>summary small{flex-basis:100%;padding-left:32px}.anypress-acct-site{padding:18px 16px}.anypress-acct-panel-body{padding-left:0}.anypress-acct-actions>*,.anypress-acct-form>.anypress-acct-btn{flex:1 1 100%;text-align:center}.anypress-acct .anypress-choose-website-name>.anypress-acct-btn{display:flex;width:100%}.anypress-acct table.anypress-mail-records{display:block;overflow-x:auto}}
CSS;
    }
}
