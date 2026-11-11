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
.anypress-acct-form input[type=search]{width:100%;padding:10px 12px;font-size:15px;border:1px solid rgba(0,0,0,.25);border-radius:8px;font-weight:400}
.anypress-acct-domain-buy{margin-top:14px}
.anypress-acct-domain-list{list-style:none;margin:0 0 16px;padding:0;display:grid;gap:8px}
.anypress-acct-domain-list label{display:flex;flex-wrap:wrap;align-items:center;gap:6px 12px;padding:12px 14px;border:1px solid rgba(0,0,0,.18);border-radius:10px;cursor:pointer}
.anypress-acct-domain-list label:has(input:checked){border:1.5px solid #141414;box-shadow:3px 3px 0 #ffc75a}
.anypress-acct-domain-list strong{overflow-wrap:anywhere}
.anypress-acct-domain-list span{margin-left:auto;font-variant-numeric:tabular-nums;opacity:.8}
.anypress-acct-contact{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;margin:8px 0 18px}
.anypress-acct-contact label{display:flex;flex-direction:column;gap:6px;font-weight:600}
.anypress-acct-contact label.is-wide{grid-column:1/-1}
.anypress-acct-contact small{font-weight:400;opacity:.7}
.anypress-acct-contact input{width:100%;padding:10px 12px;font-size:15px;border:1px solid rgba(0,0,0,.25);border-radius:8px;font-weight:400}
.anypress-acct-emails{margin:14px 0 0;opacity:.85}
.anypress-acct-care-result{border-radius:10px;padding:11px 14px;font-weight:600}
.anypress-acct-care-result.is-ok{background:#e7f4ec;color:#0f5a30}
.anypress-acct-care-result.is-bad{background:#fde9ee;color:#b3123a}
.anypress-acct-care-time{display:grid;gap:8px;max-width:460px;margin:2px 0 18px}
.anypress-acct-panel-body .anypress-acct-care-time p{margin:0}
.anypress-acct-care-left b{font-size:28px;font-weight:800;line-height:1.1;letter-spacing:-.01em;font-variant-numeric:tabular-nums}
.anypress-acct-care-left span{opacity:.7}
.anypress-acct-care-meter{height:8px;border-radius:999px;background:rgba(0,0,0,.08);overflow:hidden}
.anypress-acct-care-meter i{display:block;height:100%;background:#17703d;border-radius:999px}
.is-low .anypress-acct-care-meter i{background:#e89a12}.is-low .anypress-acct-care-left b{color:#8a5a00}
.is-out .anypress-acct-care-left b{color:#b3123a}
.anypress-acct-care-facts{display:flex;flex-wrap:wrap;gap:4px 16px;font-size:.88em;opacity:.7}
.anypress-acct-care-extra{font-size:.92em;padding:8px 12px;border-radius:8px;background:rgba(0,0,0,.04)}
.anypress-acct-care-request{display:grid;gap:10px;margin:0 0 14px;justify-items:start}
.anypress-acct-care-request label{display:grid;gap:6px;font-weight:600;width:100%}
.anypress-acct-care-request textarea{width:100%;min-height:104px;padding:10px 12px;font:inherit;font-size:15px;font-weight:400;border:1px solid rgba(0,0,0,.25);border-radius:8px}
.anypress-acct-care-request textarea:focus{outline:2px solid #141414;outline-offset:0;border-color:#141414}
.anypress-acct-panel-body .anypress-acct-care-links{display:flex;flex-wrap:wrap;align-items:center;gap:10px 18px;margin:0;padding-top:14px;border-top:1px solid rgba(0,0,0,.08)}
.anypress-acct-care-manage{color:inherit;font-size:.92em;opacity:.8}
.anypress-acct-care-period{display:inline-flex;padding:3px;border-radius:999px;background:rgba(0,0,0,.06);margin:0 0 14px}
.anypress-acct-care-period label{cursor:pointer}
.anypress-acct-care-period input{position:absolute;opacity:0;width:1px;height:1px}
.anypress-acct-care-period span{display:block;padding:7px 16px;border-radius:999px;font-weight:600;font-size:14px}
.anypress-acct-care-period small{font-weight:600;font-size:.8em;color:#17703d;margin-left:4px}
.anypress-acct-care-period input:checked+span{background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.14)}
.anypress-acct-care-period input:focus-visible+span{outline:2px solid #141414;outline-offset:1px}
.anypress-acct-care-tiers{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:12px;margin:0 0 12px}
.anypress-acct-care-tier{display:flex;flex-direction:column;gap:10px;padding:16px;border:1px solid rgba(0,0,0,.16);border-radius:12px;background:#fff;min-width:0}
.anypress-acct-care-tier.is-picked{border:1.5px solid #141414;box-shadow:4px 4px 0 #ffc75a}
.anypress-acct-panel-body .anypress-acct-care-tier p{margin:0}
.anypress-acct-care-name{display:flex;flex-wrap:wrap;align-items:center;gap:6px 8px}
.anypress-acct-care-name em{font-style:normal;font-size:.75em;font-weight:700;padding:2px 8px;border-radius:999px;background:#141414;color:#fff}
.anypress-acct-care-price{display:flex;flex-direction:column;gap:10px}
.anypress-acct-care-price b{font-size:26px;font-weight:800;letter-spacing:-.01em;font-variant-numeric:tabular-nums}
.anypress-acct-care-price span{opacity:.7;font-size:.92em}
.anypress-acct-care-price .anypress-acct-btn{width:100%;padding:10px 12px;font-size:14px}
.anypress-acct-care-tier ul{list-style:none;margin:0;padding:10px 0 0;border-top:1px solid rgba(0,0,0,.08);display:grid;gap:5px;font-size:.88em}
.anypress-acct-care-tier li{margin:0;padding-left:20px;position:relative}
.anypress-acct-care-tier li::before{content:"";position:absolute;left:2px;top:.45em;width:9px;height:5px;border-left:2px solid #17703d;border-bottom:2px solid #17703d;transform:rotate(-45deg)}
.anypress-acct-care .is-yearly{display:none}
.anypress-acct-care:has(.anypress-acct-care-period input[value=yearly]:checked) .is-yearly{display:flex}
.anypress-acct-care:has(.anypress-acct-care-period input[value=yearly]:checked) .is-monthly{display:none}
.anypress-acct-panel-body .anypress-acct-care-sub{margin:18px 0 6px;font-size:12px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;opacity:.6}
.anypress-acct-care-services{list-style:none;margin:0;padding:0;display:grid;border:1px solid rgba(0,0,0,.12);border-radius:12px}
.anypress-acct-care-services li{display:grid;grid-template-columns:minmax(0,1fr) auto auto;gap:6px 14px;align-items:center;padding:12px 14px;margin:0;border-top:1px solid rgba(0,0,0,.08)}
.anypress-acct-care-services li:first-child{border-top:0}
.anypress-acct-care-services small{display:block;font-size:.86em;opacity:.7;margin-top:2px}
.anypress-acct-care-services b{font-variant-numeric:tabular-nums;white-space:nowrap}
.anypress-acct-care-services .anypress-acct-btn{padding:7px 14px;font-size:14px}
@media (max-width:600px){.anypress-acct-care-services li{grid-template-columns:minmax(0,1fr) auto}.anypress-acct-care-services li>div{grid-column:1/-1}.anypress-acct-care-period{display:flex}.anypress-acct-care-period label{flex:1;text-align:center}}
.anypress-acct-notice{font-family:"Hanken Grotesk",system-ui,sans-serif;color:#141414}
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
.anypress-acct-form input[type=search]{width:100%;padding:10px 12px;font-size:15px;border:1px solid rgba(0,0,0,.25);border-radius:8px;font-weight:400}
.anypress-acct-domain-buy{margin-top:14px}
.anypress-acct-domain-list{list-style:none;margin:0 0 16px;padding:0;display:grid;gap:8px}
.anypress-acct-domain-list label{display:flex;flex-wrap:wrap;align-items:center;gap:6px 12px;padding:12px 14px;border:1px solid rgba(0,0,0,.18);border-radius:10px;cursor:pointer}
.anypress-acct-domain-list label:has(input:checked){border:1.5px solid #141414;box-shadow:3px 3px 0 #ffc75a}
.anypress-acct-domain-list strong{overflow-wrap:anywhere}
.anypress-acct-domain-list span{margin-left:auto;font-variant-numeric:tabular-nums;opacity:.8}
.anypress-acct-contact{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;margin:8px 0 18px}
.anypress-acct-contact label{display:flex;flex-direction:column;gap:6px;font-weight:600}
.anypress-acct-contact label.is-wide{grid-column:1/-1}
.anypress-acct-contact small{font-weight:400;opacity:.7}
.anypress-acct-contact input{width:100%;padding:10px 12px;font-size:15px;border:1px solid rgba(0,0,0,.25);border-radius:8px;font-weight:400}
.anypress-acct-emails{margin:14px 0 0;opacity:.85}
.anypress-acct-care-meter{height:8px;max-width:420px;border-radius:999px;background:rgba(0,0,0,.08);overflow:hidden;margin:4px 0 14px}
.anypress-acct-care-meter i{display:block;height:100%;background:#17703d;border-radius:999px}
.anypress-acct-care-request{display:grid;gap:10px;margin:6px 0 12px;justify-items:start}
.anypress-acct-care-request label{display:grid;gap:6px;font-weight:600;width:100%}
.anypress-acct-care-request textarea{width:100%;min-height:104px;padding:10px 12px;font:inherit;font-size:15px;font-weight:400;border:1px solid rgba(0,0,0,.25);border-radius:8px}
.anypress-acct-care-offer{display:flex;flex-wrap:wrap;align-items:center;gap:4px 12px;padding:12px 14px;border:1px solid rgba(0,0,0,.18);border-radius:10px;color:inherit;text-decoration:none}
.anypress-acct-care-offer:hover{border-color:#141414;color:inherit}
.anypress-acct-care-offer.is-picked{border:1.5px solid #141414;box-shadow:3px 3px 0 #ffc75a}
.anypress-acct-care-offer em{font-style:normal;font-size:.8em;font-weight:700;padding:2px 8px;border-radius:999px;background:#141414;color:#fff}
.anypress-acct-care-offer small{flex-basis:100%;opacity:.7}
.anypress-acct-notice{display:flex;flex-wrap:wrap;align-items:center;gap:12px 16px;border:1.5px solid #141414;border-radius:16px;box-shadow:5px 5px 0 #ffc75a;padding:16px 20px;margin-bottom:18px;background:#fff;font-size:15px}
.anypress-acct-notice p{margin:0;flex:1 1 260px;overflow-wrap:anywhere}
.anypress-acct-notice .anypress-acct-actions{margin-top:0}
@media (max-width:600px){.anypress-acct-contact{grid-template-columns:1fr}.anypress-acct-domain-buy>.anypress-acct-btn{width:100%}.anypress-acct-panel>summary small{flex-basis:100%;padding-left:32px}.anypress-acct-site{padding:18px 16px}.anypress-acct-panel-body{padding-left:0}.anypress-acct-actions>*,.anypress-acct-form>.anypress-acct-btn{flex:1 1 100%;text-align:center}.anypress-acct .anypress-choose-website-name>.anypress-acct-btn{display:flex;width:100%}.anypress-acct table.anypress-mail-records{display:block;overflow-x:auto}}
CSS;
    }
}
