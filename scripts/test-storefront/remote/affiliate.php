<?php
/**
 * AnyPress's own affiliate program on the shop (Beads waas-yxj): FluentAffiliate's settings, the /affiliates page
 * with its sign-up form and affiliate dashboard, the email sender, and a footer link to the program's page on
 * anypress.io (which carries the terms). Safe to run again.
 *   wp eval-file affiliate.php --user=admin
 *
 * The free plugin pays commission on the first payment only; renewals need FluentAffiliate Pro.
 */
if (!class_exists('\FluentAffiliate\App\Helper\Utility')) {
    echo "FluentAffiliate isn't active\n";
    return;
}
$utility = '\FluentAffiliate\App\Helper\Utility';
$terms = 'https://anypress.io/affiliates';

$page = get_page_by_path('affiliates');
if (!$page) {
    $id = wp_insert_post([
        'post_type' => 'page',
        'post_status' => 'publish',
        'post_title' => 'Affiliates',
        'post_name' => 'affiliates',
        'post_content' => "<!-- wp:paragraph -->\n<p>Earn 30% of every new customer's first payment. Sign up below, or sign in to see your link and earnings. <a href=\"$terms#terms\">Program terms</a>.</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:shortcode -->\n[fluent_affiliate_portal]\n<!-- /wp:shortcode -->",
    ], true);
    if (is_wp_error($id)) {
        echo 'Page: ' . $id->get_error_message() . "\n";
        return;
    }
    $page = get_post($id);
}
echo "Page: /affiliates is #{$page->ID}\n";

$utility::updateReferralSettings([
    'rate_type' => 'percentage',
    'rate' => 30,
    'cookie_duration' => 30,
    'payout_method' => 'paypal',
    'credit_last_referrer' => 'yes',
    'self_referral_disabled' => 'yes',
    'exclude_tax' => 'yes',
    'exclude_discount' => 'yes',
    'dashboard_page_id' => (int) $page->ID,
]);
$utility::updateOption('_fluent_cart_connector_config', array_merge((array) $utility::getOption('_fluent_cart_connector_config', []), ['is_enabled' => 'yes']));
$utility::updateOption('_registration_settings', ['enabled' => 'yes', 'require_approval' => 'yes']);
echo "Settings: 30% of the first payment, 30-day cookie, PayPal, sign-ups need approval\n";

// One welcome at approval: the shop's CRM sends "You're an AnyPress affiliate", so FluentAffiliate's own approval
// email is off. Its other emails (application received, each sale, payouts) stay on.
$notifications = (array) $utility::getOption('email_notifications', []);
$notifications['account_approved_to_affiliate'] = array_merge((array) ($notifications['account_approved_to_affiliate'] ?? []), [
    'active' => 'no', 'is_default_body' => 'yes', 'email_body' => '',
]);
$utility::updateOption('email_notifications', $notifications);
echo "Email: FluentAffiliate's approval email off (the CRM welcome replaces it)\n";

// The shop's mail relay sends from the platform address under this name; replies go to support.
$email = array_merge($utility::defaultEmailSettings(), (array) $utility::getOption('global_email_settings', []));
$utility::updateOption('global_email_settings', array_merge($email, [
    'send_from_name' => 'AnyPress',
    'send_from_email' => 'support@anypress.io',
    'reply_to_name' => 'AnyPress',
    'reply_to_email' => 'support@anypress.io',
    'admin_email' => 'admin@otakusolutions.io',
    'email_footer' => "You are getting this email because you are an AnyPress affiliate. Program terms: $terms#terms",
]));
echo "Email: from AnyPress, replies to support@anypress.io\n";

// The footer (a custom template part holding the legal links) gets an Affiliates link after Refunds.
$footer = get_posts(['post_type' => 'wp_template_part', 'name' => 'footer', 'post_status' => 'publish', 'numberposts' => 1]);
$footer = $footer ? $footer[0] : null;
if (!$footer) {
    echo "Footer: no custom footer template part found\n";
} elseif (strpos($footer->post_content, $terms) !== false) {
    echo "Footer: link already there\n";
} elseif (preg_match('#<!-- wp:navigation-link (\{[^}]*refund-policy[^}]*\}) /-->#', $footer->post_content, $m)) {
    $link = json_decode($m[1], true);
    $link = array_merge($link, ['label' => 'Affiliates', 'url' => $terms, 'kind' => 'custom', 'type' => 'custom']);
    unset($link['id']);
    $content = str_replace($m[0], $m[0] . "\n<!-- wp:navigation-link " . wp_json_encode($link, JSON_UNESCAPED_SLASHES) . ' /-->', $footer->post_content);
    wp_update_post(['ID' => $footer->ID, 'post_content' => $content]);
    echo "Footer: navigation link added to #{$footer->ID}\n";
} elseif (preg_match('#<a [^>]*href="[^"]*refund-policy[^"]*"[^>]*>.*?</a>#', $footer->post_content, $m)) {
    $affiliate = preg_replace(['#href="[^"]*"#', '#>.*?</a>$#'], ['href="' . $terms . '"', '>Affiliates</a>'], $m[0]);
    $sep = preg_match('#refund-policy[^<]*</a>\s*([^<\s][^<]*?)\s*<a #', $footer->post_content, $s) ? " {$s[1]} " : ' · ';
    $content = str_replace($m[0], $m[0] . $sep . $affiliate, $footer->post_content);
    wp_update_post(['ID' => $footer->ID, 'post_content' => $content]);
    echo "Footer: link added to #{$footer->ID}\n";
} else {
    echo "Footer: couldn't find the Refunds link in #{$footer->ID}; left unchanged\n";
}
