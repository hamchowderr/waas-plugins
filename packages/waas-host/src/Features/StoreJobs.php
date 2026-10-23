<?php

namespace WaaSHost\Features;

use WaaSHost\Commerce\Commerce;
use WaaSHost\Core\Tenant;

/**
 * Runs the provisioning API calls for a store in the background, never inside a cart hook.
 *
 * Each store (subscription) has its own queue of operations in subscription meta, so the work survives a restart and
 * runs in the order it was asked for. A cart hook only appends to the queue and schedules a runner (Action Scheduler,
 * which WooCommerce and FluentCart both ship; WP-Cron if it is missing). The runner takes the operations one at a time:
 * an operation that throws is retried with backoff, and the ones behind it wait. After the last retry the store is
 * marked Failed, the queue stays as it is, and an admin notice offers a retry.
 *
 * Handlers must be safe to run again: $attempt (previous failures of this operation) tells them whether an earlier
 * try may already have reached the API.
 */
class StoreJobs
{
    public const QUEUE_META = 'waas_store_jobs';
    public const FAILURE_META = 'waas_store_job_failure';
    public const RUN_HOOK = 'waas_run_store_jobs';
    public const SWEEP_HOOK = 'waas_sweep_store_jobs';
    public const RETRY_ACTION = 'waas_retry_store_jobs';
    public const GROUP = 'waas-host';
    /**
     * Seconds to wait after the 1st, 2nd, ... failure; one more failure after the last marks the store Failed and
     * alerts the admin. About two hours in all.
     */
    public const RETRY_DELAYS = [60, 300, 900, 1800, 3600];
    /** A runner holding the lock longer than this is assumed dead. */
    public const LOCK_TTL = 600;

    /** @var array<string, callable> operation => handler($subscription_id, array $args, int $attempt): ?array */
    private array $handlers = [];

    public function __construct()
    {
        add_action(self::RUN_HOOK, [$this, 'run']);
        add_action(self::SWEEP_HOOK, [$this, 'sweep']);
        add_action('init', [$this, 'schedule_sweep']);
        add_action('admin_notices', [$this, 'show_failed_notice']);
        add_action('admin_post_' . self::RETRY_ACTION, [$this, 'handle_retry_request']);
    }

    /**
     * A handler returns null when the operation is done, or ['replace' => [[op, args], ...]] to put other
     * operations in its place (they run next). It throws to have the operation retried.
     */
    public function register(string $op, callable $handler): void
    {
        $this->handlers[$op] = $handler;
    }

    public function enqueue($subscription_id, string $op, array $args = []): void
    {
        $queue = $this->queue($subscription_id);
        $queue[] = ['op' => $op, 'args' => $args, 'attempts' => 0];
        $this->save_queue($subscription_id, $queue);

        if (!$this->failure($subscription_id)) {
            $this->schedule_run($subscription_id, time());
        }
    }

    public function has_pending($subscription_id, array $ops): bool
    {
        foreach ($this->queue($subscription_id) as $job) {
            if (in_array($job['op'], $ops, true)) {
                return true;
            }
        }

        return false;
    }

    /** The store's state, as it will be once the queue has run (also while the store is marked Failed). */
    public function status($subscription_id): string
    {
        $failure = $this->failure($subscription_id);

        return $failure ? (string) ($failure['state'] ?? '') : (string) (new Tenant($subscription_id))->get_status();
    }

    public function set_status($subscription_id, string $status): void
    {
        $failure = $this->failure($subscription_id);
        if ($failure) {
            // Shown as Failed until an admin retries; the retry puts this state back.
            $failure['state'] = $status;
            Commerce::require_adapter()->update_subscription_meta($subscription_id, self::FAILURE_META, $failure);
            return;
        }

        (new Tenant($subscription_id))->update_status($status);
    }

    /** Runner, from Action Scheduler or WP-Cron. Takes the queue head first; stops at the first failure. */
    public function run($subscription_id): void
    {
        if ($this->failure($subscription_id)) {
            return;
        }
        if (!$this->lock($subscription_id)) {
            // Another runner is busy with this store; come back in case it finishes before seeing new work.
            $this->schedule_run($subscription_id, time() + 30);
            return;
        }

        try {
            for ($done = 0; $done < 20; $done++) {
                $queue = $this->queue($subscription_id);
                if (!$queue) {
                    return;
                }

                $job = $queue[0];
                $handler = $this->handlers[$job['op']] ?? null;

                try {
                    if ($handler === null) {
                        throw new \RuntimeException('No handler for store job ' . $job['op']);
                    }
                    $result = $handler($subscription_id, $job['args'], (int) $job['attempts']);
                } catch (\Throwable $error) {
                    $this->failed($subscription_id, $queue, $error);
                    return;
                }

                $replacement = [];
                foreach ((array) ($result['replace'] ?? []) as [$op, $args]) {
                    $replacement[] = ['op' => $op, 'args' => $args, 'attempts' => 0];
                }
                // Re-read: a cart hook may have added work while the handler ran.
                $current = $this->queue($subscription_id);
                array_shift($current);
                $this->save_queue($subscription_id, array_merge($replacement, $current));
            }

            $this->schedule_run($subscription_id, time());
        } finally {
            $this->unlock($subscription_id);
        }
    }

    /** Admin retry: the store gets its state back and the queue runs again from the operation that failed. */
    public function retry($subscription_id): void
    {
        $failure = $this->failure($subscription_id);
        if (!$failure) {
            return;
        }

        $commerce = Commerce::require_adapter();
        $commerce->update_subscription_meta($subscription_id, self::FAILURE_META, '');
        (new Tenant($subscription_id))->update_status((string) ($failure['state'] ?? ''));

        $queue = $this->queue($subscription_id);
        if ($queue) {
            $queue[0]['attempts'] = 0;
            $this->save_queue($subscription_id, $queue);
            $this->schedule_run($subscription_id, time());
        }
    }

    /** Every few minutes: start a runner for any store with work and none scheduled (e.g. after a crash). */
    public function sweep(): void
    {
        $commerce = Commerce::adapter();
        if ($commerce === null) {
            return;
        }

        foreach (array_keys($commerce->find_subscriptions_by_meta(self::QUEUE_META, [''])) as $subscription_id) {
            if ($this->queue($subscription_id) && !$this->failure($subscription_id) && !$this->is_scheduled($subscription_id)) {
                $this->schedule_run($subscription_id, time());
            }
        }
    }

    public function schedule_sweep(): void
    {
        // Checked only where it costs a visitor nothing.
        if (!is_admin() && !wp_doing_cron()) {
            return;
        }

        if (function_exists('as_schedule_recurring_action')) {
            if (!as_has_scheduled_action(self::SWEEP_HOOK, [], self::GROUP)) {
                as_schedule_recurring_action(time() + 300, 300, self::SWEEP_HOOK, [], self::GROUP);
            }
        } elseif (!wp_next_scheduled(self::SWEEP_HOOK)) {
            wp_schedule_event(time() + 300, 'hourly', self::SWEEP_HOOK);
        }
    }

    /** @return array<int|string, array> subscription id => failure */
    public function failed_stores(): array
    {
        $commerce = Commerce::adapter();
        if ($commerce === null) {
            return [];
        }

        $failed = [];
        foreach (array_keys($commerce->find_subscriptions_by_meta(self::FAILURE_META, [''])) as $subscription_id) {
            if ($failure = $this->failure($subscription_id)) {
                $failed[$subscription_id] = $failure;
            }
        }

        return $failed;
    }

    public function show_failed_notice(): void
    {
        if (!current_user_can('manage_options') || !($failed = $this->failed_stores())) {
            return;
        }

        echo '<div class="notice notice-error"><p><strong>' . esc_html(sprintf(
            _n('%d website could not be updated through the provisioning API.', '%d websites could not be updated through the provisioning API.', count($failed), WAAS_HOST_TEXTDOMAIN),
            count($failed)
        )) . '</strong></p><ul>';
        foreach ($failed as $subscription_id => $failure) {
            $retry_url = wp_nonce_url(
                admin_url('admin-post.php?action=' . self::RETRY_ACTION . '&subscription_id=' . rawurlencode((string) $subscription_id)),
                self::RETRY_ACTION . '_' . $subscription_id
            );
            echo '<li>' . esc_html(sprintf(
                'Subscription #%s: "%s" failed %d times, last at %s: %s',
                $subscription_id,
                $failure['op'] ?? '',
                $failure['attempts'] ?? 0,
                wp_date('Y-m-d H:i', (int) ($failure['at'] ?? 0)),
                $failure['error'] ?? ''
            )) . ' <a href="' . esc_url($retry_url) . '">' . esc_html__('Retry', WAAS_HOST_TEXTDOMAIN) . '</a></li>';
        }
        echo '</ul></div>';
    }

    public function handle_retry_request(): void
    {
        $subscription_id = isset($_GET['subscription_id']) ? sanitize_text_field(wp_unslash($_GET['subscription_id'])) : '';
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to do this.', WAAS_HOST_TEXTDOMAIN), 403);
        }
        check_admin_referer(self::RETRY_ACTION . '_' . $subscription_id);

        // Carts use integer ids; keep the runner's arguments identical to the ones the cart hooks schedule.
        $this->retry(ctype_digit($subscription_id) ? (int) $subscription_id : $subscription_id);

        wp_safe_redirect(wp_get_referer() ?: admin_url());
        exit;
    }

    private function failed($subscription_id, array $queue, \Throwable $error): void
    {
        $attempts = (int) $queue[0]['attempts'] + 1;
        $queue[0]['attempts'] = $attempts;
        $queue[0]['error'] = $error->getMessage();

        // Re-read and keep anything a cart hook added while the handler ran.
        $current = $this->queue($subscription_id);
        $current[0] = $queue[0];
        $this->save_queue($subscription_id, $current);

        if ($attempts <= count(self::RETRY_DELAYS)) {
            $this->schedule_run($subscription_id, time() + self::RETRY_DELAYS[$attempts - 1]);
            return;
        }

        $commerce = Commerce::require_adapter();
        $commerce->update_subscription_meta($subscription_id, self::FAILURE_META, [
            'op' => $queue[0]['op'],
            'attempts' => $attempts,
            'error' => $error->getMessage(),
            'at' => time(),
            'state' => (string) (new Tenant($subscription_id))->get_status(),
        ]);
        (new Tenant($subscription_id))->update_status(Tenant::FAILED);
        error_log(sprintf('WaaS Host: store job "%s" for subscription %s failed %d times: %s', $queue[0]['op'], $subscription_id, $attempts, $error->getMessage()));

        $this->email_admin($subscription_id, $queue[0]['op'], $attempts, $error->getMessage());

        do_action('waas_store_job_failed', $subscription_id, $queue[0]['op'], $error->getMessage());
    }

    /** Once per failure: a failed store runs nothing more (so fails no more) until an admin retries it. */
    private function email_admin($subscription_id, string $op, int $attempts, string $error): void
    {
        $subject = sprintf(__('[%s] A website could not be set up or updated', WAAS_HOST_TEXTDOMAIN), wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES));
        $message = sprintf(
            __("The provisioning API kept failing for subscription #%1\$s.\n\nJob: %2\$s\nTries: %3\$d\nLast error: %4\$s\n\nThe customer sees \"Setup delayed\". Retry it from the notice in the WordPress admin once the API works again:\n%5\$s", WAAS_HOST_TEXTDOMAIN),
            $subscription_id,
            $op,
            $attempts,
            $error,
            admin_url()
        );

        if (!wp_mail((string) get_option('admin_email'), $subject, $message)) {
            error_log('WaaS Host: could not email the admin about the failed store job for subscription ' . $subscription_id);
        }
    }

    private function queue($subscription_id): array
    {
        $queue = Commerce::require_adapter()->get_subscription_meta($subscription_id, self::QUEUE_META);

        return is_array($queue) ? array_values($queue) : [];
    }

    private function save_queue($subscription_id, array $queue): void
    {
        Commerce::require_adapter()->update_subscription_meta($subscription_id, self::QUEUE_META, $queue ? array_values($queue) : '');
    }

    private function failure($subscription_id): array
    {
        $failure = Commerce::require_adapter()->get_subscription_meta($subscription_id, self::FAILURE_META);

        return is_array($failure) ? $failure : [];
    }

    private function schedule_run($subscription_id, int $timestamp): void
    {
        if ($this->is_scheduled($subscription_id)) {
            return;
        }

        if (function_exists('as_schedule_single_action')) {
            as_schedule_single_action($timestamp, self::RUN_HOOK, [$subscription_id], self::GROUP);
        } else {
            wp_schedule_single_event($timestamp, self::RUN_HOOK, [$subscription_id]);
        }
    }

    /** A pending runner. The one running right now does not count: it may be the caller, asking for its retry. */
    private function is_scheduled($subscription_id): bool
    {
        if (function_exists('as_next_scheduled_action')) {
            // A timestamp for a pending action, true for one that is running, false for none.
            return is_int(as_next_scheduled_action(self::RUN_HOOK, [$subscription_id], self::GROUP));
        }

        return (bool) wp_next_scheduled(self::RUN_HOOK, [$subscription_id]);
    }

    private function lock($subscription_id): bool
    {
        $name = 'waas_store_jobs_lock_' . md5((string) $subscription_id);
        if (add_option($name, time(), '', 'no')) {
            return true;
        }

        if ((int) get_option($name) < time() - self::LOCK_TTL) {
            delete_option($name);
            return add_option($name, time(), '', 'no');
        }

        return false;
    }

    private function unlock($subscription_id): void
    {
        delete_option('waas_store_jobs_lock_' . md5((string) $subscription_id));
    }
}
