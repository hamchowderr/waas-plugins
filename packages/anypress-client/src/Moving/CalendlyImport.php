<?php

namespace AnyPressClient\Moving;

use FluentBooking\App\Models\Availability;
use FluentBooking\App\Models\Booking;
use FluentBooking\App\Models\Calendar;
use FluentBooking\App\Models\CalendarSlot;
use FluentBooking\App\Services\AvailabilityService;
use FluentBooking\App\Services\CalendarService;

/**
 * A Calendly account into FluentBooking, read through Calendly's API v2 with the account owner's own personal access
 * token. FluentBooking has no Calendly importer and Calendly has no export file, so this reads it list by list:
 * availability schedules become FluentBooking availability schedules (weekly hours and dated hours, in their own time
 * zone), event types become booking types on the administrator's own FluentBooking calendar (with their length,
 * description, locations and schedule; inactive ones as drafts), and upcoming bookings come across with each
 * invitee's name, email, time zone, answers and meeting link. FluentBooking's own services and models write it; no
 * booking email or reminder is sent for what comes across, and Calendly is only read.
 *
 * The token is used for this run only: it isn't saved, logged or shown.
 */
class CalendlyImport extends NativeImport
{
    private const DAYS = ['sunday' => 'sun', 'monday' => 'mon', 'tuesday' => 'tue', 'wednesday' => 'wed', 'thursday' => 'thu', 'friday' => 'fri', 'saturday' => 'sat'];

    private string $token = '';
    private ?CalendlyApi $api = null;
    private ?array $found = null;

    public function key(): string
    {
        return 'calendly';
    }

    public function target_name(): string
    {
        return 'FluentBooking';
    }

    protected function target_file(): string
    {
        return 'fluent-booking/fluent-booking.php';
    }

    protected function target_version(): string
    {
        return defined('FLUENT_BOOKING_VERSION') && class_exists(CalendarService::class) ? (string) FLUENT_BOOKING_VERSION : '';
    }

    public function inputs(): array
    {
        return [
            'token' => [
                'type' => 'string',
                'default' => '',
                'description' => 'A Calendly personal access token (Calendly: profile menu, Developer, Personal access tokens), with the read scopes for users, event types, availability and scheduled events. Used for this call only, never saved.',
            ],
        ];
    }

    public function configure(array $input): void
    {
        $token = trim((string) ($input['token'] ?? ''));
        if ($token !== $this->token) {
            $this->token = $token;
            $this->api = null;
            $this->found = null;
        }
    }

    private function api(): CalendlyApi
    {
        if (!$this->api) {
            $this->api = new CalendlyApi($this->token);
        }

        return $this->api;
    }

    protected function sources(): array
    {
        if ($this->found === null) {
            $this->found = ['found' => false, 'counts' => [], 'problem' => ''];
            if ($this->token === '') {
                $this->found['problem'] = __('Add a Calendly personal access token (in Calendly: profile menu, Developer, Personal access tokens). It is used for this run only and never saved.', 'anypress-client');
            } else {
                try {
                    $user = $this->api()->one('users/me');
                    $types = array_values(array_filter(
                        $this->api()->all('event_types', ['user' => (string) ($user['uri'] ?? '')]),
                        fn ($type) => ($type['type'] ?? 'StandardEventType') === 'StandardEventType'
                    ));
                    $schedules = $this->api()->list('user_availability_schedules', ['user' => (string) ($user['uri'] ?? '')]);
                    $events = $this->api()->all('scheduled_events', [
                        'user' => (string) ($user['uri'] ?? ''),
                        'status' => 'active',
                        'min_start_time' => gmdate('Y-m-d\TH:i:s\Z'),
                        'sort' => 'start_time:asc',
                    ]);
                    $this->found += ['user' => $user, 'types' => $types, 'schedules' => $schedules, 'events' => $events];
                    $this->found['counts'] = [
                        'schedules' => count($schedules),
                        'event_types' => count($types),
                        'bookings' => (int) array_sum(array_map(fn ($event) => (int) ($event['invitees_counter']['active'] ?? 1), $events)),
                    ];
                    $this->found['found'] = true;
                } catch (\RuntimeException $error) {
                    $this->found['problem'] = $error->getMessage();
                }
            }
        }

        return ['calendly' => ['name' => 'Calendly', 'found' => $this->found['found'], 'counts' => $this->found['counts']]];
    }

    protected function source_problem(): string
    {
        return (string) ($this->found['problem'] ?? '');
    }

    protected function target_counts(): array
    {
        if ($this->target_version() === '') {
            return ['schedules' => 0, 'event_types' => 0, 'bookings' => 0];
        }

        return [
            'schedules' => (int) Availability::query()->count(),
            'event_types' => (int) CalendarSlot::query()->count(),
            'bookings' => (int) Booking::query()->count(),
        ];
    }

    protected function target_blocker(array $counts): string
    {
        return '';
    }

    protected function target_warnings(): array
    {
        if (empty($this->found['found'])) {
            return [];
        }
        $warnings = [__('The booking types come onto your own FluentBooking calendar (the administrator running the switch). FluentBooking sends no confirmation or reminder for the bookings that come across; Calendly goes on sending its own reminders for them as long as they stay in Calendly, so leave them there until they have happened.', 'anypress-client')];
        $calls = 4 + count((array) $this->found['schedules']) + count((array) $this->found['events']);
        if ($calls > 40) {
            $warnings[] = sprintf(
                /* translators: %d: a number of calls. */
                __('The switch reads Calendly with about %d API calls (one for each upcoming booking), which can take a few minutes on Calendly\'s free plan.', 'anypress-client'),
                $calls
            );
        }

        return $warnings;
    }

    protected function import(string $source, array $before): array
    {
        $user_id = get_current_user_id();
        $added = ['calendar' => 0, 'schedules' => [], 'events' => [], 'bookings' => [], 'skipped' => 0, 'fixed' => []];
        $found = (array) $this->found;
        $owner = (array) ($found['user'] ?? []);
        $timezone = (string) ($owner['timezone'] ?? '') ?: wp_timezone_string();

        // Schedules first, so FluentBooking finds a default one and doesn't make its own "Weekly Hours".
        $has_default = (bool) AvailabilityService::getDefaultSchedule($user_id);
        $schedule_ids = [];
        $schedule_keys = [];
        foreach (array_values((array) $found['schedules']) as $index => $schedule) {
            $zone = (string) ($schedule['timezone'] ?? '') ?: $timezone;
            $default = !$has_default && !empty($schedule['default']);
            $title = sanitize_text_field((string) ($schedule['name'] ?? '')) ?: __('Calendly hours', 'anypress-client');
            $availability = Availability::create(AvailabilityService::createScheduleSchema(
                $user_id,
                $title,
                $default,
                $zone,
                'UTC',
                self::weekly((array) ($schedule['rules'] ?? [])),
                self::overrides((array) ($schedule['rules'] ?? []))
            ));
            $added['schedules'][] = (int) $availability->id;
            $schedule_ids[$index + 1] = (int) $availability->id;
            $schedule_keys[(string) ($schedule['uri'] ?? '')] = $index + 1;
            $has_default = $has_default || $default;
        }
        if (!$schedule_ids) {
            // Calendly gave no schedule: the host's FluentBooking default, made like FluentBooking makes it if need be.
            $fallback = AvailabilityService::getDefaultSchedule($user_id);
            if (!$fallback) {
                $fallback = Availability::create(AvailabilityService::createScheduleSchema($user_id, 'Weekly Hours', true, $timezone));
                $added['schedules'][] = (int) $fallback->id;
            }
            $schedule_ids[1] = (int) $fallback->id;
        }
        update_option($this->option(), array_merge($this->state(), ['schedules' => $added['schedules']]), false);
        $default_key = 0;
        foreach ((array) $found['schedules'] as $index => $schedule) {
            if (!empty($schedule['default'])) {
                $default_key = $index + 1;
            }
        }

        // Which schedule each event type uses: Calendly only says so through a filter on the event types list.
        $type_schedule = [];
        if (count($schedule_keys) > 1) {
            foreach ($schedule_keys as $uri => $key) {
                foreach ($this->api()->all('event_types', ['user' => (string) ($owner['uri'] ?? ''), 'user_availability_schedule' => $uri]) as $type) {
                    $type_schedule[(string) ($type['uri'] ?? '')] = $key;
                }
            }
        }

        $seats = [];
        foreach ((array) $found['events'] as $event) {
            $uri = (string) ($event['event_type'] ?? '');
            $seats[$uri] = max((int) ($seats[$uri] ?? 0), (int) ($event['invitees_counter']['limit'] ?? 0));
        }
        $events = [];
        foreach ((array) $found['types'] as $type) {
            $uri = (string) ($type['uri'] ?? '');
            $key = $type_schedule[$uri] ?? ($default_key ?: (int) array_key_first($schedule_ids));
            $events[] = self::event($type, (int) ($schedule_ids[$key] ?? 0), (int) ($seats[$uri] ?? 0));
        }

        // The administrator's host calendar: the one they have, or a new one with the event types on it. The events
        // carry FluentBooking's own schedule ids, and no availability list is passed, so FluentBooking keeps them.
        $created = [];
        $calendar = Calendar::where('user_id', $user_id)->where('type', 'simple')->first();
        if ($calendar && $events) {
            $created = CalendarService::createCalendarEvents($calendar, $events);
        } elseif ($events) {
            $made = CalendarService::createCalendar(['user_id' => $user_id, 'type' => 'simple', 'author_timezone' => $timezone, 'events' => $events]);
            if (is_wp_error($made)) {
                throw new \RuntimeException(esc_html($made->get_error_message()));
            }
            $added['calendar'] = (int) $made['calendar']->id;
            $created = CalendarSlot::where('calendar_id', $added['calendar'])->orderBy('id')->get();
        }
        $slots = [];
        foreach (array_values(is_array($created) ? $created : $created->all()) as $index => $slot) {
            $added['events'][] = (int) $slot->id;
            $slots[(string) ($found['types'][$index]['uri'] ?? '')] = $slot;
        }
        update_option($this->option(), array_merge($this->state(), ['calendar' => $added['calendar'], 'events' => $added['events']]), false);

        // Upcoming bookings, one for each active invitee, with no booking emails or calendar sync (no booking hooks run).
        foreach ((array) $found['events'] as $event) {
            $slot = $slots[(string) ($event['event_type'] ?? '')] ?? null;
            if (!$slot) {
                $added['skipped'] += max(1, (int) ($event['invitees_counter']['active'] ?? 1));
                continue;
            }
            $group = null;
            $invitees = $this->api()->all('scheduled_events/' . CalendlyApi::uuid((string) ($event['uri'] ?? '')) . '/invitees', ['status' => 'active']);
            foreach ($invitees as $invitee) {
                $data = self::booking($event, $invitee, $slot, $timezone);
                if ($data === null) {
                    $added['skipped']++;
                    continue;
                }
                if ($group !== null) {
                    $data['group_id'] = $group;
                }
                $booking = Booking::create($data);
                $booking->hosts()->attach([(int) $slot->user_id => ['status' => 'confirmed']]);
                $group = $group ?? $booking->group_id;
                $added['bookings'][] = (int) $booking->id;
            }
            update_option($this->option(), array_merge($this->state(), ['bookings' => $added['bookings']]), false);
        }

        return $added;
    }

    /**
     * Calendly's weekday rules as FluentBooking's weekly hours (local time).
     *
     * @param array<int, array> $rules Calendly's rules: [{type: wday, wday: monday, intervals: [{from, to}]}, ...]
     */
    public static function weekly(array $rules): array
    {
        $week = [];
        foreach (self::DAYS as $day) {
            $week[$day] = ['enabled' => false, 'slots' => []];
        }
        foreach ($rules as $rule) {
            $day = self::DAYS[strtolower((string) ($rule['wday'] ?? ''))] ?? '';
            if (($rule['type'] ?? '') !== 'wday' || $day === '') {
                continue;
            }
            $slots = self::slots((array) ($rule['intervals'] ?? []));
            $week[$day] = ['enabled' => (bool) $slots, 'slots' => $slots];
        }

        return $week;
    }

    /**
     * Calendly's dated rules with hours as FluentBooking's date overrides (date => slots, local time). A dated rule
     * with no hours (a day off) has no FluentBooking equivalent and is left out.
     */
    public static function overrides(array $rules): array
    {
        $dates = [];
        foreach ($rules as $rule) {
            $date = (string) ($rule['date'] ?? '');
            if (($rule['type'] ?? '') !== 'date' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                continue;
            }
            $slots = self::slots((array) ($rule['intervals'] ?? []));
            if ($slots) {
                $dates[$date] = $slots;
            }
        }

        return $dates;
    }

    private static function slots(array $intervals): array
    {
        $slots = [];
        foreach ($intervals as $interval) {
            $from = (string) ($interval['from'] ?? '');
            $to = (string) ($interval['to'] ?? '');
            if (preg_match('/^\d{2}:\d{2}$/', $from) && preg_match('/^\d{2}:\d{2}$/', $to) && $from < $to) {
                $slots[] = ['start' => $from, 'end' => $to];
            }
        }

        return $slots;
    }

    /** A Calendly event type as the event data FluentBooking's CalendarService takes, on FluentBooking schedule $schedule. */
    public static function event(array $type, int $schedule, int $seats): array
    {
        $group = ($type['kind'] ?? 'solo') === 'group';
        $locations = array_values(array_filter(array_map([self::class, 'location'], (array) ($type['locations'] ?? []))));

        $event = [
            'title' => sanitize_text_field((string) ($type['name'] ?? '')) ?: __('Calendly meeting', 'anypress-client'),
            'duration' => max(5, (int) round((float) ($type['duration'] ?? 30))),
            'description' => wp_kses_post((string) ($type['description_html'] ?? '')),
            'status' => !empty($type['active']) ? 'active' : 'draft',
            'event_type' => $group ? 'group' : 'single',
            'max_book_per_slot' => $group ? max(2, $seats) : 1,
            'is_display_spots' => $group,
            'location_settings' => $locations,
            'color_schema' => preg_match('/^#[0-9a-fA-F]{6}$/', (string) ($type['color'] ?? '')) ? (string) $type['color'] : '#0099ff',
        ];
        return $event + ['availability_type' => 'existing_schedule', 'availability_id' => $schedule];
    }

    /** A Calendly event type location as a FluentBooking one, or null when it has none. */
    public static function location(array $location): ?array
    {
        $where = sanitize_text_field((string) ($location['location'] ?? ''));
        $kind = (string) ($location['kind'] ?? '');
        $online = [
            'google_conference' => 'Google Meet',
            'zoom_conference' => 'Zoom',
            'microsoft_teams_conference' => 'Microsoft Teams',
            'gotomeeting_conference' => 'GoTo Meeting',
            'webex_conference' => 'Webex',
        ];
        if (isset($online[$kind])) {
            return ['type' => 'online_meeting', 'title' => $online[$kind], 'meeting_link' => wp_http_validate_url($where) ? $where : '', 'display_on_booking' => 'no'];
        }
        switch ($kind) {
            case 'physical':
                return ['type' => 'in_person_organizer', 'title' => __('In person', 'anypress-client'), 'description' => $where, 'display_on_booking' => 'yes'];
            case 'ask_invitee':
                return ['type' => 'in_person_guest', 'title' => __('Your address', 'anypress-client')];
            case 'outbound_call':
                return ['type' => 'phone_guest', 'title' => __('Phone call', 'anypress-client')];
            case 'inbound_call':
                return ['type' => 'phone_organizer', 'title' => __('Phone call', 'anypress-client'), 'host_phone_number' => sanitize_text_field((string) ($location['phone_number'] ?? $where)), 'display_on_booking' => 'yes'];
            case 'custom':
                return ['type' => 'custom', 'title' => $where ?: __('Custom', 'anypress-client'), 'description' => $where, 'display_on_booking' => 'yes'];
        }

        return null;
    }

    /** A Calendly scheduled event's location as a FluentBooking booking's location details. */
    public static function booking_location(array $location): array
    {
        $type = (string) ($location['type'] ?? '');
        $where = sanitize_text_field((string) ($location['location'] ?? ''));
        $link = esc_url_raw((string) ($location['join_url'] ?? ''));
        if ($link !== '') {
            return ['type' => 'online_meeting', 'online_platform_link' => $link];
        }
        $map = ['physical' => 'in_person_organizer', 'ask_invitee' => 'in_person_guest', 'outbound_call' => 'phone_guest', 'inbound_call' => 'phone_organizer'];

        return ['type' => $map[$type] ?? 'custom', 'description' => $where];
    }

    /**
     * A Calendly invitee of a scheduled event as the fields of a FluentBooking booking, or null without an email or
     * time.
     */
    public static function booking(array $event, array $invitee, object $slot, string $timezone): ?array
    {
        $email = sanitize_email((string) ($invitee['email'] ?? ''));
        $start = strtotime((string) ($event['start_time'] ?? ''));
        $end = strtotime((string) ($event['end_time'] ?? ''));
        if (!is_email($email) || !$start || !$end || $end <= $start) {
            return null;
        }
        $first = (string) ($invitee['first_name'] ?? '');
        $last = (string) ($invitee['last_name'] ?? '');
        if ($first === '' && $last === '') {
            $parts = explode(' ', trim((string) ($invitee['name'] ?? '')), 2);
            $first = $parts[0];
            $last = $parts[1] ?? '';
        }
        $answers = [];
        foreach ((array) ($invitee['questions_and_answers'] ?? []) as $answer) {
            $answers[] = sanitize_text_field((string) ($answer['question'] ?? '')) . ': ' . sanitize_textarea_field((string) ($answer['answer'] ?? ''));
        }
        $zone = (string) ($invitee['timezone'] ?? '');

        return [
            'calendar_id' => (int) $slot->calendar_id,
            'event_id' => (int) $slot->id,
            'host_user_id' => (int) $slot->user_id,
            // 0, not the administrator running the switch (FluentBooking would otherwise link the booking to them).
            'person_user_id' => 0,
            'person_time_zone' => in_array($zone, timezone_identifiers_list(), true) ? $zone : $timezone,
            'start_time' => gmdate('Y-m-d H:i:s', $start),
            'end_time' => gmdate('Y-m-d H:i:s', $end),
            'slot_minutes' => (int) round(($end - $start) / 60),
            'first_name' => sanitize_text_field($first),
            'last_name' => sanitize_text_field($last),
            'email' => $email,
            'phone' => sanitize_text_field((string) ($invitee['text_reminder_number'] ?? '')),
            'message' => implode("\n", $answers),
            'location_details' => self::booking_location((array) ($event['location'] ?? [])),
            'status' => 'scheduled',
            'event_type' => (string) $slot->event_type,
            'source' => 'calendly',
        ];
    }

    protected function compare_keys(): array
    {
        return ['schedules' => 'schedules', 'event_types' => 'event_types', 'bookings' => 'bookings'];
    }

    protected function remove(array $state, bool $dry_run): array
    {
        $removed = ['bookings' => 0, 'event_types' => 0, 'schedules' => 0, 'calendar' => 0];
        foreach (Booking::whereIn('id', array_map('intval', (array) ($state['bookings'] ?? [])) ?: [0])->get() as $booking) {
            $removed['bookings']++;
            if (!$dry_run) {
                $booking->hosts()->detach();
                $booking->delete();
            }
        }
        foreach (CalendarSlot::whereIn('id', array_map('intval', (array) ($state['events'] ?? [])) ?: [0])->get() as $slot) {
            $removed['event_types']++;
            if (!$dry_run) {
                $slot->event_metas()->delete();
                $slot->delete();
            }
        }
        foreach (Availability::whereIn('id', array_map('intval', (array) ($state['schedules'] ?? [])) ?: [0])->get() as $schedule) {
            $removed['schedules']++;
            if (!$dry_run) {
                $schedule->delete();
            }
        }
        // The calendar the switch made, unless it has booking types the switch didn't add.
        $calendar = !empty($state['calendar']) ? Calendar::find((int) $state['calendar']) : null;
        $ours = array_map('intval', (array) ($state['events'] ?? [])) ?: [0];
        if ($calendar && !CalendarSlot::where('calendar_id', $calendar->id)->whereNotIn('id', $dry_run ? $ours : [0])->exists()) {
            $removed['calendar'] = 1;
            if (!$dry_run) {
                $calendar->metas()->delete();
                $calendar->delete();
            }
        }

        return $removed;
    }

    protected function lost(string $source): array
    {
        return [
            __('Past and cancelled bookings: only upcoming bookings come across.', 'anypress-client'),
            __('Custom questions on the booking form: add them again under each booking type\'s Booking Form. Invitees\' answers to them come across in each booking\'s note.', 'anypress-client'),
            __('Days off in a Calendly schedule (dated rules with no hours), buffers, minimum notice, daily limits, one-off meetings, polls, routing forms, workflows and payments.', 'anypress-client'),
            __('Team members\' event types and bookings: the token only reads its owner\'s. Each team member can run the switch with their own token.', 'anypress-client'),
            __('Zoom, Google Meet and Teams links for new bookings: Calendly made them. Upcoming bookings keep their link; for new ones, set a meeting link on the booking type or connect the meeting app in FluentBooking.', 'anypress-client'),
        ];
    }
}
