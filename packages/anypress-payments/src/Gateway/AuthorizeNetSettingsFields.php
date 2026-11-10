<?php

namespace AnyPressPayments\Gateway;

/** The Authorize.Net settings screen in FluentCart: the store mode, each mode's credentials and status, the webhook. */
class AuthorizeNetSettingsFields
{
    private AuthorizeNetSettings $settings;

    public function __construct(AuthorizeNetSettings $settings)
    {
        $this->settings = $settings;
    }

    public function fields(): array
    {
        return [
            'notice'       => [
                'value' => $this->modeNotice(),
                'label' => __('Authorize.Net', 'anypress-payments'),
                'type'  => 'notice',
            ],
            'payment_mode' => [
                'type'   => 'tabs',
                'schema' => [
                    [
                        'type'   => 'tab',
                        'label'  => __('Live credentials', 'anypress-payments'),
                        'value'  => 'live',
                        'schema' => $this->credentialFields('live'),
                    ],
                    [
                        'type'   => 'tab',
                        'label'  => __('Test credentials', 'anypress-payments'),
                        'value'  => 'test',
                        'schema' => $this->credentialFields('test'),
                    ],
                ],
            ],
            'webhook_info' => [
                'value' => $this->webhookInfo(),
                'label' => __('Webhook', 'anypress-payments'),
                'type'  => 'html_attr',
            ],
        ];
    }

    private function credentialFields(string $mode): array
    {
        $where = $mode === 'test'
            ? __('Sandbox account (sandbox.authorize.net)', 'anypress-payments')
            : __('Live account (account.authorize.net)', 'anypress-payments');
        $path = __('Account > Settings > Security Settings > API Credentials and Keys', 'anypress-payments');

        return [
            $mode . '_status'          => [
                'value' => $this->statusInfo($mode),
                'label' => __('Status', 'anypress-payments'),
                'type'  => 'html_attr',
            ],
            $mode . '_api_login_id'    => [
                'value'       => '',
                'label'       => __('API Login ID', 'anypress-payments'),
                'type'        => 'text',
                'placeholder' => __('API Login ID', 'anypress-payments'),
                /* translators: 1: which Authorize.Net account, 2: menu path */
                'tooltip'     => sprintf(__('%1$s: %2$s.', 'anypress-payments'), $where, $path),
            ],
            $mode . '_transaction_key' => [
                'value'       => '',
                'label'       => __('Transaction Key', 'anypress-payments'),
                'type'        => 'password',
                'placeholder' => __('Transaction Key', 'anypress-payments'),
                /* translators: 1: which Authorize.Net account, 2: menu path */
                'tooltip'     => sprintf(__('%1$s: %2$s > New Transaction Key.', 'anypress-payments'), $where, $path),
            ],
            $mode . '_signature_key'   => [
                'value'       => '',
                'label'       => __('Signature Key (optional)', 'anypress-payments'),
                'type'        => 'password',
                'placeholder' => __('Signature Key', 'anypress-payments'),
                /* translators: 1: which Authorize.Net account, 2: menu path */
                'tooltip'     => sprintf(__('%1$s: %2$s > New Signature Key. Lets refunds and voids made in Authorize.Net show up here.', 'anypress-payments'), $where, $path),
            ],
        ];
    }

    /** Which keys the store is using, like FluentCart's renderStoreModeNotice (Core/AbstractPaymentGateway.php:419). */
    private function modeNotice(): string
    {
        $mode = $this->settings->getMode();
        $settingsLink = '<a href="' . esc_url(admin_url('admin.php?page=fluent-cart#/settings/store-settings/')) . '">' . esc_html__('Store Settings', 'anypress-payments') . '</a>';

        if ($mode === 'test') {
            $text = '<span class="text-warning-500">' . esc_html__('Your store is in test mode: cards are charged in the Authorize.Net sandbox with the test credentials. No real money moves.', 'anypress-payments') . '</span>';
        } else {
            $text = '<span class="text-success-500">' . esc_html__('Your store is in live mode: cards are charged with the live credentials.', 'anypress-payments') . '</span>';
        }

        /* translators: %s: link to FluentCart's Store Settings */
        $how = sprintf(esc_html__('Switch with Order Mode in %s.', 'anypress-payments'), $settingsLink);

        return '<div class="mt-5">' . $text . ' ' . $how . '</div>';
    }

    /** One line about a mode's credentials: connected to which account, or what's wrong. */
    private function statusInfo(string $mode): string
    {
        $login = $this->settings->getApiLoginId($mode);
        $error = $this->settings->forMode('error', $mode);
        $merchant = $this->settings->forMode('merchant_name', $mode);
        $verifiedAt = (int) $this->settings->forMode('verified_at', $mode);
        $inUse = $mode === $this->settings->getMode();

        if ($login === '') {
            $line = $inUse
                ? '<strong style="color:#b32d2e">' . esc_html__('Not set up. Card payments are off until these are filled in.', 'anypress-payments') . '</strong>'
                : esc_html__('Not set up.', 'anypress-payments');
        } elseif ($error !== '') {
            /* translators: %s: Authorize.Net's error message */
            $line = '<strong style="color:#b32d2e">' . esc_html(sprintf(__('Not connected: %s', 'anypress-payments'), $error)) . '</strong>';
        } elseif ($this->settings->getPublicClientKey($mode) !== '') {
            $name = $merchant !== '' ? $merchant : $login;
            /* translators: %s: the merchant's name at Authorize.Net */
            $line = '<strong style="color:#00a32a">' . esc_html(sprintf(__('Connected to %s', 'anypress-payments'), $name)) . '</strong>';
            if ($verifiedAt) {
                /* translators: %s: date and time */
                $line .= ' · ' . esc_html(sprintf(__('checked %s', 'anypress-payments'), wp_date(get_option('date_format') . ' ' . get_option('time_format'), $verifiedAt)));
            }
            if ($mode === 'live' && $this->settings->forMode('account_test_mode', $mode) === 'yes') {
                $line .= '<br><strong style="color:#b32d2e">' . esc_html__('This Authorize.Net account is in Test Mode, so its charges are not real. Turn Test Mode off in the Merchant Interface (Account > Settings > Test Mode).', 'anypress-payments') . '</strong>';
            }
        } else {
            $line = esc_html__('Saved but not checked yet. Save again to check the credentials with Authorize.Net.', 'anypress-payments');
        }

        $parts = ['<p>' . $line . '</p>'];
        if ($inUse) {
            $parts[] = '<p>' . esc_html__('The store uses these credentials now.', 'anypress-payments') . '</p>';
        }

        $webhook = $this->settings->forMode('webhook_status', $mode);
        $last = AuthorizeNetSettings::lastWebhookAt($mode);
        if ($webhook !== '' || $last) {
            $line = esc_html($webhook);
            if ($last) {
                /* translators: %s: how long ago, e.g. "5 mins" */
                $line .= ($line !== '' ? ' ' : '') . esc_html(sprintf(__('Last notification received %s ago.', 'anypress-payments'), human_time_diff($last)));
            }
            $parts[] = '<p>' . $line . '</p>';
        }

        return implode('', $parts);
    }

    /** What the settings page says about the webhook. */
    private function webhookInfo(): string
    {
        return '<p>' . esc_html__('With a Signature Key, saving these settings registers a webhook with Authorize.Net, so refunds and voids made in Authorize.Net, and payments it confirms late, show up in FluentCart. Payments work without it.', 'anypress-payments') . '</p>'
            . '<p>' . esc_html__('Webhook URL:', 'anypress-payments') . ' <code>' . esc_html(Webhook::url()) . '</code></p>';
    }
}
