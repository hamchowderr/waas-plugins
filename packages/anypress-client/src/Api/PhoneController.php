<?php

namespace AnyPressClient\Api;

use AnyPressClient\Features\PluginBootstrap;
use WP_Error;
use WP_REST_Request;
use WP_REST_Server;

/**
 * What the phone receptionist needs from the store: the booking types it can offer, their open times, a booking for
 * a caller, and the caller saved in FluentCRM after the call. Only the AnyPress API can call it, signed with the
 * store's site key exactly like the site tools (SiteController::authorize), so it runs as the first administrator.
 *
 * Bookings go through FluentBooking's own slot lock and availability check, so a phone booking can never take a
 * time a web visitor just booked. Callers who give no email are kept under caller-<digits>@phone.invalid, which
 * never delivers and is the same for every call from that number.
 */
class PhoneController
{
    public const ROUTE = '/site/phone';
    private const CALENDAR_SLOT = 'FluentBooking\App\Models\CalendarSlot';
    private const SLOT_SERVICE = 'FluentBooking\App\Hooks\Handlers\TimeSlotServiceHandler';
    private const BOOKING_SERVICE = 'FluentBooking\App\Services\BookingService';
    private const HELPER = 'FluentBooking\App\Services\Helper';
    private const SLOT_LOCK = 'FluentBooking\App\Modules\MCP\Support\SlotLock';
    private const TAG = 'FluentCrm\App\Models\Tag';
    /** At most this many days and open times are read back to a caller. */
    private const DAYS = 7;
    private const TIMES_PER_DAY = 8;
    public const CALL_TAG = 'Phone call';

    /** @var SiteController */
    private $site;

    public function __construct(SiteController $site)
    {
        $this->site = $site;
        add_action('rest_api_init', [$this, 'register_rest_routes']);
    }

    public function register_rest_routes(): void
    {
        $routes = [
            ['/services', WP_REST_Server::READABLE, 'services'],
            ['/services/(?P<id>\d+)/times', WP_REST_Server::READABLE, 'times'],
            ['/bookings', WP_REST_Server::CREATABLE, 'book'],
            ['/callers', WP_REST_Server::CREATABLE, 'save_caller'],
        ];
        foreach ($routes as [$path, $methods, $callback]) {
            register_rest_route(PluginBootstrap::API_V1_NAMESPACE, self::ROUTE . $path, [
                'methods' => $methods,
                'callback' => [$this, $callback],
                'permission_callback' => [$this->site, 'authorize'],
            ]);
        }
    }

    /** Active FluentBooking booking types: id, title, minutes, and whether the owner must confirm each booking. */
    public function services()
    {
        if (!class_exists(self::CALENDAR_SLOT)) {
            return $this->missing('FluentBooking');
        }
        $class = self::CALENDAR_SLOT;
        $events = $class::where('status', 'active')->orderBy('id', 'ASC')->limit(50)->get();
        $out = [];
        foreach ($events as $event) {
            $out[] = [
                'id' => (int) $event->id,
                'title' => wp_strip_all_tags((string) $event->title),
                'minutes' => (int) $event->getDuration(),
                'description' => wp_trim_words(wp_strip_all_tags((string) $event->description), 40),
            ];
        }

        return ['services' => $out, 'timezone' => wp_timezone_string()];
    }

    /** ?from=YYYY-MM-DD (default today) &timezone=<IANA> (default the site's): open start times over the next days. */
    public function times(WP_REST_Request $request)
    {
        $event = $this->event((int) $request['id']);
        if (is_wp_error($event)) {
            return $event;
        }
        $timezone = $this->timezone((string) $request->get_param('timezone'), $event);
        $from = (string) $request->get_param('from');
        $start = preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) ? $from . ' 00:00:00' : gmdate('Y-m-d H:i:s');

        $handler = self::SLOT_SERVICE;
        $service = $handler::initService($event->calendar, $event);
        if (is_wp_error($service)) {
            return $this->bad($service->get_error_message());
        }
        $spots = $service->getAvailableSpots($start, $timezone, (int) $event->getDuration());
        if (is_wp_error($spots)) {
            return $this->bad($spots->get_error_message());
        }

        $days = [];
        foreach (array_filter((array) $spots) as $date => $slots) {
            if (count($days) >= self::DAYS) {
                break;
            }
            $times = [];
            foreach ((array) $slots as $slot) {
                if (!empty($slot['start'])) {
                    $times[] = (string) $slot['start'];
                }
            }
            if ($times) {
                $days[] = ['date' => (string) $date, 'times' => array_slice($times, 0, self::TIMES_PER_DAY)];
            }
        }

        return ['service' => (int) $event->id, 'timezone' => $timezone, 'days' => $days];
    }

    /**
     * { service, start: "YYYY-MM-DD HH:MM:SS" in timezone, timezone?, name, phone, email?, address?, note? }
     * Books one time for the caller, as FluentBooking's own booking form would, marked as booked by phone.
     */
    public function book(WP_REST_Request $request)
    {
        $event = $this->event((int) $request->get_param('service'));
        if (is_wp_error($event)) {
            return $event;
        }
        $name = sanitize_text_field((string) $request->get_param('name'));
        $phone = $this->phone((string) $request->get_param('phone'));
        $start = sanitize_text_field((string) $request->get_param('start'));
        if ($name === '' || $phone === '' || !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}(:\d{2})?$/', $start)) {
            return $this->bad('Send service, start ("YYYY-MM-DD HH:MM:SS"), name and phone.');
        }
        $timezone = $this->timezone((string) $request->get_param('timezone'), $event);
        $email = $this->email((string) $request->get_param('email'), $phone);
        $duration = (int) $event->getDuration();

        $utcStart = (new \DateTimeImmutable($start, new \DateTimeZone($timezone)))->setTimezone(new \DateTimeZone('UTC'));
        $data = [
            'person_time_zone' => $timezone,
            'start_time' => $utcStart->format('Y-m-d H:i:s'),
            'end_time' => $utcStart->modify('+' . $duration . ' minutes')->format('Y-m-d H:i:s'),
            'name' => $name,
            'email' => $email,
            'phone' => $phone,
            'address' => sanitize_textarea_field((string) $request->get_param('address')),
            'message' => sanitize_textarea_field((string) $request->get_param('note')),
            'status' => 'scheduled',
            'source' => 'phone',
            'event_type' => $event->event_type,
            'slot_minutes' => $duration,
            'utm_source' => 'phone-receptionist',
            'location_details' => ['type' => 'phone_guest', 'description' => $phone],
        ];
        if ($event->isConfirmationRequired($data['start_time'])) {
            $data['status'] = 'pending';
        }

        $handler = self::SLOT_SERVICE;
        $service = $handler::initService($event->calendar, $event);
        if (is_wp_error($service)) {
            return $this->bad($service->get_error_message());
        }
        $helper = self::HELPER;
        $lockClass = self::SLOT_LOCK;
        $locks = $helper::lockSlot($event, $data['start_time'], $data['end_time']);
        $open = $locks ? $service->isSpotAvailable($data['start_time'], $data['end_time'], $duration) : false;
        // Older FluentBooking has no SlotLock::renewAll; the lock itself still holds the time.
        $held = !class_exists($lockClass) || $lockClass::renewAll($locks);
        if (!$open || !$held) {
            return new WP_Error('anypress_phone_taken', 'That time is no longer open. Offer another time.', ['status' => 409]);
        }
        if ($event->isRoundRobin()) {
            $data['host_user_id'] = $service->hostUserId;
        }
        $helper::releaseSlotLocksAfterInsert($locks);

        $bookingService = self::BOOKING_SERVICE;
        try {
            $booking = $bookingService::createBooking($data, $event, []);
        } catch (\Throwable $e) {
            return $this->bad($e->getMessage());
        }
        if (is_wp_error($booking)) {
            return $this->bad($booking->get_error_message());
        }

        return [
            'booked' => true,
            'id' => (int) $booking->id,
            'status' => (string) $booking->status,
            'service' => wp_strip_all_tags((string) $event->title),
            'start' => $start,
            'timezone' => $timezone,
        ];
    }

    /**
     * { name?, phone, email?, interested?: service name, summary?, urgent?: bool }
     * Adds or updates the caller in FluentCRM with the "Phone call" tag, "<service>: Interested" when they asked
     * about one, and "Urgent call" when they needed someone straight away. Never subscribes them to emails.
     */
    public function save_caller(WP_REST_Request $request)
    {
        if (!function_exists('FluentCrmApi') || !class_exists(self::TAG)) {
            return $this->missing('FluentCRM');
        }
        $phone = $this->phone((string) $request->get_param('phone'));
        if ($phone === '') {
            return $this->bad('Send the caller\'s phone.');
        }
        $name = trim(sanitize_text_field((string) $request->get_param('name')));
        [$first, $last] = array_pad(explode(' ', $name, 2), 2, '');

        $titles = [self::CALL_TAG];
        $interested = trim(preg_replace('/\s+/u', ' ', sanitize_text_field((string) $request->get_param('interested'))));
        if ($interested !== '') {
            $titles[] = mb_substr($interested, 0, 80) . ': Interested';
        }
        if (rest_sanitize_boolean($request->get_param('urgent'))) {
            $titles[] = 'Urgent call';
        }

        $contacts = FluentCrmApi('contacts');
        $email = $this->email((string) $request->get_param('email'), $phone);
        $existing = $contacts->getContact($email);
        $data = array_filter(['email' => $email, 'first_name' => $first, 'last_name' => $last, 'phone' => $phone, 'source' => 'phone']);
        if (!$existing) {
            $data['status'] = 'transactional';
        }
        $contact = $contacts->createOrUpdate($data);
        if (!$contact) {
            return $this->bad('FluentCRM did not save the caller.');
        }
        $contact->attachTags($this->tag_ids($titles));

        $summary = sanitize_textarea_field((string) $request->get_param('summary'));
        if ($summary !== '' && class_exists('FluentCrm\App\Models\SubscriberNote')) {
            \FluentCrm\App\Models\SubscriberNote::create([
                'subscriber_id' => $contact->id,
                'type' => 'call',
                'title' => 'Phone call',
                'description' => $summary,
            ]);
        }

        return ['saved' => true, 'contact' => (int) $contact->id, 'new' => !$existing, 'tags' => $titles];
    }

    /** @return int[] ids of the tags with these titles, making the ones that don't exist yet. */
    private function tag_ids(array $titles): array
    {
        $class = self::TAG;
        $ids = [];
        foreach ($titles as $title) {
            $tag = $class::where('title', $title)->first() ?: $class::create(['title' => $title, 'slug' => sanitize_title($title)]);
            $ids[] = (int) $tag->id;
        }

        return $ids;
    }

    private function event(int $id)
    {
        if (!class_exists(self::CALENDAR_SLOT)) {
            return $this->missing('FluentBooking');
        }
        $class = self::CALENDAR_SLOT;
        $event = $id > 0 ? $class::find($id) : null;
        if (!$event || $event->status !== 'active') {
            return new WP_Error('anypress_phone_no_service', 'That booking type is not open for bookings.', ['status' => 404]);
        }

        return $event;
    }

    private function timezone(string $wanted, $event): string
    {
        if ($wanted !== '' && in_array($wanted, \DateTimeZone::listIdentifiers(), true)) {
            return $wanted;
        }
        $site = wp_timezone_string();

        return in_array($site, \DateTimeZone::listIdentifiers(), true) ? $site : ((string) ($event->calendar->author_timezone ?? '') ?: 'UTC');
    }

    /** "+1 (555) 010-2000" as "+15550102000"; "" when it has too few digits to be a phone number. */
    private function phone(string $raw): string
    {
        $digits = preg_replace('/\D+/', '', $raw);
        if (strlen($digits) < 7 || strlen($digits) > 15) {
            return '';
        }

        return (strpos(trim($raw), '+') === 0 ? '+' : '') . $digits;
    }

    private function email(string $raw, string $phone): string
    {
        $email = sanitize_email($raw);

        return is_email($email) ? strtolower($email) : 'caller-' . ltrim($phone, '+') . '@phone.invalid';
    }

    private function missing(string $plugin): WP_Error
    {
        return new WP_Error('anypress_phone_missing', $plugin . ' is not active on this site.', ['status' => 409]);
    }

    private function bad(string $message): WP_Error
    {
        return new WP_Error('anypress_phone_bad_request', $message, ['status' => 400]);
    }
}
