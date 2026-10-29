<?php

namespace WaaSHost\Features;

/**
 * The look of the customer's website panel (the cart's account page): one card per website, a status pill,
 * the main buttons in a row and the domain and email settings folded away until opened. Printed once, inline,
 * next to the first panel, so it only loads on that page. Colours follow the page's own text colour, so it
 * sits in FluentCart's portal and in a WooCommerce theme alike.
 */
class AccountStyles
{
    private static bool $printed = false;

    public static function print_once(): void
    {
        if (self::$printed) {
            return;
        }
        self::$printed = true;

        // AnyPress's own font, as on anypress.io and the dashboard.
        echo '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Hanken+Grotesk:wght@400;600;700;800&display=swap">';
        echo '<style id="waas-account-styles">' . self::css() . '</style>';
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
.waas-acct,.waas-acct-notice{font-family:"Hanken Grotesk",system-ui,sans-serif;color:#141414}
.waas-acct{display:grid;gap:22px;min-width:0;max-width:760px}
.waas-acct *,.waas-acct-notice *{box-sizing:border-box}
.waas-acct-site{min-width:0;max-width:100%;border:1.5px solid #141414;border-radius:16px;padding:22px 24px;background:#fff;font-size:16px}
.waas-acct-site:first-child{box-shadow:6px 6px 0 #ffc75a}
.waas-acct-site h3{margin:10px 0 2px;font-size:22px;font-weight:800;letter-spacing:-.01em;font-family:inherit;line-height:1.25;overflow-wrap:anywhere}
.waas-acct-visit{font-weight:700;color:#c8264d}
.waas-acct-status{display:inline-flex;align-items:center;gap:7px;padding:3px 11px;border-radius:999px;background:rgba(0,0,0,.05);font-size:.85em;font-weight:600}
.waas-acct-status i{width:8px;height:8px;border-radius:999px;background:#8a8580}
.waas-acct-status.is-ready i{background:#17703d}
.waas-acct-status.is-busy i{background:#e59a00}
.waas-acct-status.is-attention i{background:#c8264d}
.waas-acct-lead{margin:12px 0 0;color:#5f5a55;max-width:62ch}
.waas-acct-actions{display:flex;flex-wrap:wrap;gap:10px;margin-top:18px}
.waas-acct-actions a{text-decoration:none}
.waas-acct-btn{display:inline-flex;align-items:center;justify-content:center;padding:11px 18px;border-radius:10px;font-family:inherit;border:1.5px solid #141414;background:#fff;color:#141414;font-size:15px;font-weight:600;line-height:1.2;text-decoration:none;cursor:pointer}
.waas-acct-btn:hover{background:#f4f2ef;color:#141414}
.waas-acct-btn.is-primary{background:#141414;color:#fff}
.waas-acct-btn.is-primary:hover{background:#141414;color:#fff;box-shadow:0 4px 0 #ffb338}
.waas-acct-panel{border-top:1px solid rgba(0,0,0,.1);margin-top:18px}
.waas-acct-panel+.waas-acct-panel{margin-top:0}
.waas-acct-panel>summary{cursor:pointer;list-style:none;padding:14px 0;font-weight:600;display:flex;flex-wrap:wrap;align-items:center;gap:4px 10px}
.waas-acct-panel>summary::-webkit-details-marker{display:none}
.waas-acct-panel>summary::before{content:"+";display:inline-grid;place-items:center;width:22px;height:22px;border:1.5px solid currentColor;border-radius:999px;font-weight:700;line-height:1}
.waas-acct-panel[open]>summary::before{content:"\2013"}
.waas-acct-panel>summary small{font-weight:400;opacity:.7;overflow-wrap:anywhere}
.waas-acct-panel-body{padding:0 0 16px 32px}
.waas-acct-panel-body p{margin:0 0 10px}
.waas-acct-step{font-weight:600}
.waas-acct-step b{color:#c8264d}
.waas-acct-records{display:grid;gap:6px;margin:6px 0 16px}
.waas-acct-record{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.waas-acct-record b{min-width:58px;font-weight:600;text-align:center;padding:4px 8px;border-radius:6px;background:#141414;color:#fff;font-size:.8em}
.waas-acct-record code{font-size:14px;padding:5px 9px;border-radius:6px;background:rgba(0,0,0,.05);overflow-wrap:anywhere}
.waas-acct-form{display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;margin:8px 0 6px}
.waas-acct-form label{display:flex;flex-direction:column;gap:6px;flex:1 1 220px;font-weight:600}
.waas-acct-form input[type=text]{width:100%;padding:10px 12px;font-size:15px;border:1px solid rgba(0,0,0,.25);border-radius:8px;font-weight:400}
.waas-acct-error{color:#b3123a;font-weight:600}
.waas-acct-note{font-size:.92em;opacity:.8}
.waas-acct table.waas-mail-records{width:100%;border-collapse:collapse;margin:6px 0 14px;font-size:.92em}
.waas-acct table.waas-mail-records th,.waas-acct table.waas-mail-records td{text-align:left;padding:6px 8px;border-bottom:1px solid rgba(0,0,0,.08);vertical-align:top}
.waas-acct table.waas-mail-records code{overflow-wrap:anywhere}
.waas-acct .waas-choose-website-name{margin-top:14px}
.waas-acct .waas-choose-website-name label{display:flex;flex-direction:column;gap:6px;font-weight:600;margin:10px 0}
.waas-acct .waas-choose-website-name input[type=text]{padding:10px 12px;font-size:15px;border:1px solid rgba(0,0,0,.25);border-radius:8px;font-weight:400;max-width:420px}
.waas-acct-emails{margin:14px 0 0;opacity:.85}
.waas-acct-notice{display:flex;flex-wrap:wrap;align-items:center;gap:12px 16px;border:1.5px solid #141414;border-radius:16px;box-shadow:5px 5px 0 #ffc75a;padding:16px 20px;margin-bottom:18px;background:#fff;font-size:15px}
.waas-acct-notice p{margin:0;flex:1 1 260px;overflow-wrap:anywhere}
.waas-acct-notice .waas-acct-actions{margin-top:0}
@media (max-width:600px){.waas-acct-panel>summary small{flex-basis:100%;padding-left:32px}.waas-acct-site{padding:18px 16px}.waas-acct-panel-body{padding-left:0}.waas-acct-actions>*{flex:1 1 100%;text-align:center}.waas-acct table.waas-mail-records{display:block;overflow-x:auto}}
CSS;
    }
}
