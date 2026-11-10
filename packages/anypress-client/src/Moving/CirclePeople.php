<?php

namespace AnyPressClient\Moving;

/**
 * Circle members as WordPress users, for the Circle switch: a member whose email the site already has is matched to
 * that user; anyone else gets a new subscriber with a random password (no email is sent: the switch holds email back,
 * and members set their own password with "Lost your password?"). New and matched users are recorded in the switch's
 * state as they are made, so undo finds them even after a run that stopped half way.
 */
class CirclePeople
{
    /** Posts and comments by someone who left Circle (no email), shown as the person running the switch. */
    public int $stand_ins = 0;

    /** @var array The switch's state: 'users' (made here) and 'matched' (already there), by reference. */
    private array $state;
    /** @var int[] lower-case email => user id */
    private array $by_email = [];
    /** @var string[] user id => Circle headline */
    private array $headlines = [];

    public function __construct(array &$state)
    {
        $this->state = &$state;
    }

    /** The user for a Circle member record (id, email, name, first_name, last_name, headline), or 0 without an email. */
    public function user(array $member): int
    {
        $email = strtolower(trim((string) ($member['email'] ?? '')));
        if (!is_email($email)) {
            return 0;
        }
        if (isset($this->by_email[$email])) {
            return $this->by_email[$email];
        }
        $existing = get_user_by('email', $email);
        if ($existing) {
            $id = (int) $existing->ID;
            $this->state['matched'][] = $id;
        } else {
            $id = $this->make($email, $member);
            if (!$id) {
                return 0;
            }
            $this->state['users'][] = $id;
        }
        update_user_meta($id, CircleImport::MEMBER_META, (int) ($member['id'] ?? 0));
        $this->by_email[$email] = $id;
        $this->headlines[$id] = sanitize_text_field((string) ($member['headline'] ?? ''));

        return $id;
    }

    /** The author of a post or comment: their user (made if need be), or the person running the switch. */
    public function author(string $email, string $name): int
    {
        $id = $this->user(['email' => $email, 'name' => $name]);
        if ($id) {
            return $id;
        }
        $this->stand_ins++;

        return get_current_user_id();
    }

    /** @return string[] user id => headline, for everyone met in this run. */
    public function profiles(): array
    {
        return $this->headlines;
    }

    private function make(string $email, array $member): int
    {
        $name = sanitize_text_field((string) ($member['name'] ?? ''));
        $first = sanitize_text_field((string) ($member['first_name'] ?? ''));
        $last = sanitize_text_field((string) ($member['last_name'] ?? ''));
        $login = sanitize_user(strtok($email, '@'), true) ?: 'member';
        $try = $login;
        for ($n = 2; username_exists($try); $n++) {
            $try = $login . $n;
        }
        $id = wp_insert_user([
            'user_login' => $try,
            'user_email' => $email,
            'user_pass' => wp_generate_password(32),
            'display_name' => $name !== '' ? $name : trim($first . ' ' . $last),
            'first_name' => $first,
            'last_name' => $last,
            'role' => 'subscriber',
        ]);

        return is_wp_error($id) ? 0 : (int) $id;
    }
}
