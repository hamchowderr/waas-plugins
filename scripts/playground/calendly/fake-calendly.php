<?php
/**
 * Test only: a fake Calendly API v2 (https://api.calendly.com/), answered inside WordPress through pre_http_request,
 * with the shapes of Calendly's published spec (developer.calendly.com/openapi/calendly-api.yaml): one resource as
 * { resource }, lists as { collection, pagination: { count, next_page, next_page_token, ... } } paged with count (at
 * most 100, as Calendly allows; more gets 400) and page_token. The token is "calendly-test-token"; any other gets 401,
 * as Calendly does. Every call is counted in calendly_test_calls, and every token sent is kept in calendly_test_tokens
 * so the test can check the token is never saved anywhere else.
 *
 * The account (America/New_York): two availability schedules, "Working hours" (the default: weekdays 09:00-17:00, an
 * extra morning on a dated rule and a day off) and "Evenings" (Europe/London, Tuesday and Thursday 18:00-20:00).
 * Event types: "Intro call" (30 minutes, Zoom, on Working hours), "Workshop" (a 90-minute group event in person, on
 * Evenings), "Old consult" (inactive, a custom location) and a one-off meeting (AdhocEventType, which doesn't come
 * across). Upcoming bookings: 101 intro calls (so the list takes two pages), one workshop with three invitees out of
 * 8 seats, and one booking of an event type that was deleted since.
 */

const CALENDLY_TEST_TOKEN = 'calendly-test-token';

function calendly_test_data(): array
{
    $api = 'https://api.calendly.com/';
    $user = $api . 'users/USER1';
    $working = $api . 'user_availability_schedules/SCHED1';
    $evenings = $api . 'user_availability_schedules/SCHED2';
    $day = fn ($wday, $intervals) => ['type' => 'wday', 'wday' => $wday, 'intervals' => $intervals];
    $nine_to_five = [['from' => '09:00', 'to' => '17:00']];
    $soon = gmdate('Y-m-d', strtotime('+10 days'));
    $off = gmdate('Y-m-d', strtotime('+11 days'));
    $schedules = [
        ['uri' => $working, 'default' => true, 'name' => 'Working hours', 'user' => $user, 'timezone' => 'America/New_York', 'rules' => [
            $day('sunday', []), $day('monday', $nine_to_five), $day('tuesday', $nine_to_five), $day('wednesday', $nine_to_five),
            $day('thursday', $nine_to_five), $day('friday', $nine_to_five), $day('saturday', []),
            ['type' => 'date', 'date' => $soon, 'intervals' => [['from' => '07:00', 'to' => '08:30'], ['from' => '09:00', 'to' => '17:00']]],
            ['type' => 'date', 'date' => $off, 'intervals' => []],
        ]],
        ['uri' => $evenings, 'default' => false, 'name' => 'Evenings', 'user' => $user, 'timezone' => 'Europe/London', 'rules' => [
            $day('sunday', []), $day('monday', []), $day('tuesday', [['from' => '18:00', 'to' => '20:00']]), $day('wednesday', []),
            $day('thursday', [['from' => '18:00', 'to' => '20:00']]), $day('friday', []), $day('saturday', []),
        ]],
    ];
    $type = fn ($id, $name, $minutes, $kind, $active, $locations, $schedule, $extra = []) => $extra + [
        'uri' => $api . 'event_types/' . $id, 'name' => $name, 'slug' => sanitize_title($name), 'duration' => $minutes, 'kind' => $kind,
        'active' => $active, 'secret' => false, 'type' => 'StandardEventType', 'booking_method' => 'instant', 'color' => '#8247f5',
        'description_plain' => "About $name", 'description_html' => "<p>About <strong>$name</strong></p><script>alert(1)</script>",
        'locations' => $locations, 'custom_questions' => [], 'scheduling_url' => 'https://calendly.example/' . sanitize_title($name),
        'pooling_type' => null, 'profile' => ['type' => 'User', 'name' => 'Rosa Diaz', 'owner' => $user], '_schedule' => $schedule,
    ];
    $types = [
        $type('ET1', 'Intro call', 30, 'solo', true, [['kind' => 'zoom_conference']], $working),
        $type('ET2', 'Workshop', 90, 'group', true, [['kind' => 'physical', 'location' => '12 Main St, Springfield', 'additional_info' => 'Ring the bell']], $evenings),
        $type('ET3', 'Old consult', 45, 'solo', false, [['kind' => 'custom', 'location' => 'We will call you on Signal']], $working),
        $type('ET4', 'Coffee with Sam', 15, 'solo', true, [], $working, ['type' => 'AdhocEventType']),
    ];
    $events = [];
    $invitees = [];
    $at = fn ($days, $hour, $minutes = 0) => gmdate('Y-m-d\TH:i:s.000000\Z', strtotime(gmdate('Y-m-d', strtotime("+$days days")) . sprintf(' %02d:%02d:00 UTC', $hour, $minutes)));
    $event = function ($id, $type, $start, $end, $location, $people, $limit = 1) use ($api, &$events, &$invitees, $user) {
        $uri = $api . 'scheduled_events/' . $id;
        $events[] = [
            'uri' => $uri, 'name' => 'Meeting', 'status' => 'active', 'start_time' => $start, 'end_time' => $end, 'event_type' => $api . 'event_types/' . $type,
            'location' => $location, 'invitees_counter' => ['total' => count($people), 'active' => count($people), 'limit' => $limit],
            'event_memberships' => [['user' => $user]], 'created_at' => '2025-01-01T00:00:00.000000Z',
        ];
        $invitees[$id] = array_map(fn ($person) => $person + ['uri' => $uri . '/invitees/' . md5($person['email']), 'status' => 'active', 'event' => $uri, 'rescheduled' => false], $people);
    };
    $event('EV-A', 'ET1', $at(3, 15), $at(3, 15, 30), ['type' => 'zoom', 'join_url' => 'https://zoom.example/j/123', 'status' => 'pushed'], [
        ['email' => 'pat@example.com', 'name' => 'Pat Morgan', 'first_name' => null, 'last_name' => null, 'timezone' => 'Europe/Berlin', 'text_reminder_number' => '+49 30 1234567', 'questions_and_answers' => [['question' => 'What should we talk about?', 'answer' => 'Moving my shop', 'position' => 0]]],
    ]);
    for ($n = 1; $n <= 100; $n++) {
        $event("EV-I$n", 'ET1', $at(4 + intdiv($n, 8), 13 + $n % 8), $at(4 + intdiv($n, 8), 13 + $n % 8, 30), ['type' => 'zoom', 'join_url' => "https://zoom.example/j/9$n"], [
            ['email' => "guest$n@example.com", 'name' => "Guest $n", 'first_name' => 'Guest', 'last_name' => (string) $n, 'timezone' => 'America/Chicago', 'questions_and_answers' => []],
        ]);
    }
    $event('EV-W', 'ET2', $at(5, 18), $at(5, 19, 30), ['type' => 'physical', 'location' => '12 Main St, Springfield'], [
        ['email' => 'ann@example.com', 'name' => 'Ann Cho', 'first_name' => 'Ann', 'last_name' => 'Cho', 'timezone' => 'Europe/London', 'questions_and_answers' => []],
        ['email' => 'ben@example.com', 'name' => 'Ben Ode', 'first_name' => 'Ben', 'last_name' => 'Ode', 'timezone' => null, 'questions_and_answers' => []],
        ['email' => 'cy@example.com', 'name' => 'Cy Young', 'first_name' => 'Cy', 'last_name' => 'Young', 'timezone' => 'Not/AZone', 'questions_and_answers' => []],
    ], 8);
    $event('EV-X', 'ET9', $at(6, 14), $at(6, 15), ['type' => 'custom', 'location' => 'Somewhere'], [
        ['email' => 'gone@example.com', 'name' => 'Gone Type', 'timezone' => 'UTC', 'questions_and_answers' => []],
    ]);

    return [
        'user' => ['uri' => $user, 'name' => 'Rosa Diaz', 'slug' => 'rosa', 'email' => 'rosa@example.com', 'timezone' => 'America/New_York', 'current_organization' => $api . 'organizations/ORG1', 'resource_type' => 'User'],
        'schedules' => $schedules,
        'types' => $types,
        'events' => $events,
        'invitees' => $invitees,
    ];
}

add_filter('pre_http_request', function ($pre, $args, $url) {
    $base = 'https://api.calendly.com/';
    if (strpos($url, $base) !== 0) {
        return $pre;
    }
    update_option('calendly_test_calls', (int) get_option('calendly_test_calls', 0) + 1, false);
    $auth = (string) ($args['headers']['Authorization'] ?? '');
    $tokens = (array) get_option('calendly_test_tokens', []);
    $tokens[] = $auth;
    update_option('calendly_test_tokens', array_values(array_unique($tokens)), false);
    $answer = fn ($code, $body) => ['headers' => ['content-type' => 'application/json'], 'body' => wp_json_encode($body), 'response' => ['code' => $code, 'message' => ''], 'cookies' => [], 'filename' => null];
    if ($auth !== 'Bearer ' . CALENDLY_TEST_TOKEN) {
        return $answer(401, ['title' => 'Unauthenticated', 'message' => 'The access token is invalid']);
    }
    if (strtoupper((string) ($args['method'] ?? 'GET')) !== 'GET') {
        return $answer(405, ['message' => 'The test API only reads.']);
    }
    $parts = wp_parse_url($url);
    $path = trim((string) $parts['path'], '/');
    parse_str($parts['query'] ?? '', $query);
    $data = calendly_test_data();
    if ($path === 'users/me') {
        return $answer(200, ['resource' => $data['user']]);
    }
    if (isset($query['user']) && $query['user'] !== $data['user']['uri']) {
        return $answer(403, ['title' => 'Permission Denied', 'message' => 'You do not have permission']);
    }
    if ($path === 'user_availability_schedules') {
        return $answer(200, ['collection' => $data['schedules']]);
    }
    if ($path === 'event_types') {
        $records = $data['types'];
        if (isset($query['user_availability_schedule'])) {
            $records = array_values(array_filter($records, fn ($type) => $type['_schedule'] === $query['user_availability_schedule']));
        }
        $records = array_map(fn ($type) => array_diff_key($type, ['_schedule' => 1]), $records);
    } elseif ($path === 'scheduled_events') {
        if (($query['status'] ?? '') !== 'active' || empty($query['min_start_time'])) {
            return $answer(400, ['title' => 'Invalid Argument', 'message' => 'The test only answers upcoming active events.']);
        }
        $records = $data['events'];
    } elseif (preg_match('#^scheduled_events/([^/]+)/invitees$#', $path, $match) && isset($data['invitees'][$match[1]])) {
        $records = $data['invitees'][$match[1]];
    } else {
        return $answer(404, ['title' => 'Resource Not Found', 'message' => 'The server could not find the requested resource.']);
    }
    $count = (int) ($query['count'] ?? 20);
    if ($count < 1 || $count > 100) {
        return $answer(400, ['title' => 'Invalid Argument', 'message' => 'count must be between 1 and 100']);
    }
    $offset = (int) ($query['page_token'] ?? 0);
    $next = $offset + $count < count($records) ? (string) ($offset + $count) : null;

    return $answer(200, [
        'collection' => array_slice($records, $offset, $count),
        'pagination' => ['count' => count(array_slice($records, $offset, $count)), 'next_page' => $next ? $url . '&page_token=' . $next : null, 'previous_page' => null, 'next_page_token' => $next, 'previous_page_token' => null],
    ]);
}, 10, 3);
