<?php

namespace AnyPressClient\Features;

/**
 * Keeps FluentCommunity's notification emails out of FluentSMTP's email log. Those emails quote what members wrote:
 * a preview of each private message, and posts and comments from private spaces. FluentSMTP keeps every email it
 * sends for 14 days (its log is on by default), so anyone who can open the log could read them there. Other emails
 * (orders, bookings, forms, password resets) are logged as before, and FluentSMTP still logs a failed send, which it
 * decides before asking this filter.
 *
 * FluentCommunity sends them through its own Mailer, which marks nothing on the email itself, so the email is
 * recognised by that Mailer being the one sending it.
 */
class CommunityMailLog
{
    public const MAILER = 'FluentCommunity\App\Services\Libs\Mailer';

    public function __construct()
    {
        add_filter('fluentmail_will_log_email', [$this, 'willLog']);
    }

    /** FluentSMTP's answer, except no for an email FluentCommunity's Mailer is sending. */
    public function willLog($isLogOn)
    {
        if (!$isLogOn) {
            return $isLogOn;
        }

        return self::sentByCommunity(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS)) ? false : $isLogOn; // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- only reads which class is sending, no output.
    }

    /** Whether FluentCommunity's Mailer is one of the callers in this backtrace. */
    public static function sentByCommunity(array $trace): bool
    {
        foreach ($trace as $frame) {
            if (($frame['class'] ?? '') === self::MAILER) {
                return true;
            }
        }

        return false;
    }
}
