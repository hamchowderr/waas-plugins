<?php

namespace WaaSClient\Features;

use WaaSClient\Core\ConnectLink;
use WaaSClient\Core\SignedRequest;

/**
 * The page a store owner lands on from "Sign in with your website" (wp-admin/admin.php?page=waas-connect): it names
 * the AI app and what it may do, and Allow or Cancel sends the owner back to the AnyPress API with a signed answer.
 * Not in any menu. Only administrators (manage_options) can answer; WordPress asks anyone else to sign in first.
 */
class ConnectPage
{
    const PAGE = 'waas-connect';
    const ACTION = 'waas_connect';

    public function __construct()
    {
        add_action('admin_menu', [$this, 'add_page']);
        add_action('admin_post_' . self::ACTION, [$this, 'answer']);
    }

    public function add_page()
    {
        add_submenu_page('', __('Connect an AI app', 'waas-client'), '', 'manage_options', self::PAGE, [$this, 'render']);
    }

    /** The link from the API, checked, from the query string or the form. */
    private function link(array $params): ?array
    {
        return ConnectLink::verify(SignedRequest::key(), array_map(fn ($value) => is_string($value) ? wp_unslash($value) : $value, $params));
    }

    public function render()
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the link is signed by the API with this website's key; link() checks the signature.
        $link = $this->link($_GET);
?>
        <div class="wrap">
            <h1><?php esc_html_e('Connect an AI app', 'waas-client'); ?></h1>
            <?php if (!$link) : ?>
                <div class="notice notice-error inline"><p><?php esc_html_e('This link is not valid or has expired. Go back to the app and connect again.', 'waas-client'); ?></p></div>
            <?php else : ?>
                <p style="font-size:15px">
                    <?php echo wp_kses(sprintf(
                        /* translators: %s: the AI app's name */
                        __('<strong>%s</strong> wants to work on this website.', 'waas-client'),
                        esc_html($link['app'])
                    ), ['strong' => []]); ?>
                </p>
                <p><?php $link['write'] === '1'
                    ? esc_html_e('It will be able to read and change this website\'s pages, design, header, footer and menus, and add images. Every page change is saved as a revision you can go back to.', 'waas-client')
                    : esc_html_e('It will be able to read this website\'s pages, design, header, footer and menus.', 'waas-client'); ?></p>
                <p><?php esc_html_e('It cannot install or change plugins or themes, change settings, or see orders, customers or other people\'s accounts.', 'waas-client'); ?></p>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION); ?>">
                    <?php wp_nonce_field(self::ACTION); ?>
                    <?php foreach (array_merge(ConnectLink::FIELDS, ['s']) as $field) : ?>
                        <input type="hidden" name="<?php echo esc_attr($field); ?>" value="<?php echo esc_attr(sanitize_text_field(wp_unslash($_GET[$field] ?? ''))); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the signed link's own fields, checked above. ?>">
                    <?php endforeach; ?>
                    <?php if ($link['write'] === '1') : ?>
                        <p><label><input type="checkbox" name="allow_write" value="1" checked> <?php esc_html_e('Allow it to make changes, not only look', 'waas-client'); ?></label></p>
                    <?php endif; ?>
                    <p class="description">
                        <?php echo esc_html(sprintf(
                            /* translators: %s: where the app sends you back to, such as claude.ai */
                            __('Afterwards you go back to %s.', 'waas-client'),
                            $link['to']
                        )); ?>
                    </p>
                    <p class="submit">
                        <button type="submit" name="answer" value="allow" class="button button-primary"><?php esc_html_e('Allow', 'waas-client'); ?></button>
                        <button type="submit" name="answer" value="deny" class="button"><?php esc_html_e('Cancel', 'waas-client'); ?></button>
                    </p>
                </form>
            <?php endif; ?>
        </div>
<?php
    }

    public function answer()
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Only this website\'s administrators can connect an AI app.', 'waas-client'), '', ['response' => 403]);
        }
        check_admin_referer(self::ACTION);
        $link = $this->link($_POST);
        if (!$link) {
            wp_die(esc_html__('This link is not valid or has expired. Go back to the app and connect again.', 'waas-client'), '', ['response' => 400]);
        }
        $answer = 'deny';
        if (sanitize_key(wp_unslash($_POST['answer'] ?? '')) === 'allow') {
            $answer = $link['write'] === '1' && !empty($_POST['allow_write']) ? 'allow' : 'allow-read';
        }
        // The answer URL is the API's, signed into the link with this website's key, so this is not an open redirect.
        wp_redirect(ConnectLink::answer_url(SignedRequest::key(), $link, $answer)); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
        exit;
    }
}
