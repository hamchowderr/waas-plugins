<?php
/**
 * Test only: fake Brevo (https://api.brevo.com/v3/) and MailerLite (https://connect.mailerlite.com/api/) APIs,
 * answered inside WordPress through pre_http_request with the shapes of their published references, so no account
 * or key is needed. Brevo's key is "xkeysib-test" (api-key header) and MailerLite's token "ml-test" (Bearer); any
 * other gets 401, as they do. Every call is counted in crm_import_test_calls, and every key sent is kept in
 * crm_import_test_keys so the test can check it is saved nowhere else.
 *
 * Brevo: two lists (Newsletter with 3 contacts, VIP with 250 so it takes two pages of 200), attributes FIRSTNAME,
 * LASTNAME, SMS and a custom COMPANY; bob@anypress.example is blocklisted for email.
 * MailerLite: two groups (Customers with 3 subscribers: active, unsubscribed, bounced; Leads with 230, two pages by
 * cursor), fields name, last_name, phone, city and a custom company.
 */

function crm_import_test_log(string $key): void
{
    update_option('crm_import_test_calls', (int) get_option('crm_import_test_calls', 0) + 1, false);
    $keys = (array) get_option('crm_import_test_keys', []);
    $keys[] = $key;
    update_option('crm_import_test_keys', array_values(array_unique($keys)), false);
}

function crm_import_test_answer(int $code, $body): array
{
    return ['headers' => ['content-type' => 'application/json'], 'body' => wp_json_encode($body), 'response' => ['code' => $code, 'message' => $code === 200 ? 'OK' : 'Unauthorized'], 'cookies' => [], 'filename' => null];
}

function crm_import_test_brevo(string $path, array $query): array
{
    $contact = fn ($id, $email, $first, $last, $lists, $blocked = false, $extra = []) => [
        'id' => $id, 'email' => $email, 'emailBlacklisted' => $blocked, 'smsBlacklisted' => false, 'whatsappBlacklisted' => false,
        'createdAt' => '2024-03-0' . (1 + $id % 8) . 'T10:00:00.000+02:00', 'modifiedAt' => '2025-01-01T00:00:00.000+02:00', 'listIds' => $lists,
        'attributes' => array_merge(['FIRSTNAME' => $first, 'LASTNAME' => $last], $extra),
    ];
    $lists = [
        1 => [$contact(1, 'alice@anypress.example', 'Alice', 'Ng', [1, 2], false, ['SMS' => '+15550100', 'COMPANY' => 'Acme']), $contact(2, 'bob@anypress.example', 'Bob', 'Ruiz', [1], true), $contact(3, 'cara@anypress.example', 'Cara', 'Diaz', [1])],
        2 => array_merge([$contact(1, 'alice@anypress.example', 'Alice', 'Ng', [1, 2], false, ['SMS' => '+15550100', 'COMPANY' => 'Acme'])], array_map(fn ($i) => $contact(100 + $i, "vip$i@anypress.example", "Vip$i", 'Member', [2]), range(1, 249))),
    ];
    if ($path === 'contacts/lists') {
        $all = [['id' => 1, 'name' => 'Newsletter', 'totalBlacklisted' => 1, 'totalSubscribers' => 2, 'uniqueSubscribers' => 3, 'folderId' => 1], ['id' => 2, 'name' => 'VIP', 'totalBlacklisted' => 0, 'totalSubscribers' => 250, 'uniqueSubscribers' => 250, 'folderId' => 1]];
        return [200, ['lists' => array_slice($all, (int) ($query['offset'] ?? 0), (int) ($query['limit'] ?? 10)), 'count' => count($all)]];
    }
    if ($path === 'contacts/attributes') {
        $a = fn ($name, $category = 'normal', $type = 'text') => ['name' => $name, 'category' => $category, 'type' => $type];
        return [200, ['attributes' => [$a('FIRSTNAME'), $a('LASTNAME'), $a('SMS'), $a('COMPANY'), $a('ORDER_ID', 'transactional', 'id')]]];
    }
    if (preg_match('#^contacts/lists/(\d+)/contacts$#', $path, $m) && isset($lists[(int) $m[1]])) {
        $all = $lists[(int) $m[1]];
        return [200, ['contacts' => array_slice($all, (int) ($query['offset'] ?? 0), (int) ($query['limit'] ?? 50)), 'count' => count($all)]];
    }

    return [404, ['code' => 'document_not_found', 'message' => 'Not found']];
}

function crm_import_test_mailerlite(string $path, array $query): array
{
    $sub = fn ($id, $email, $status, $name, $last, $extra = []) => [
        'id' => (string) $id, 'email' => $email, 'status' => $status, 'source' => 'api', 'sent' => 0, 'opens_count' => 0, 'clicks_count' => 0,
        'ip_address' => '203.0.113.' . ($id % 250), 'subscribed_at' => '2023-05-0' . (1 + $id % 8) . ' 09:30:00', 'created_at' => '2023-05-01 09:00:00', 'updated_at' => '2025-01-01 00:00:00',
        'fields' => array_merge(['name' => $name, 'last_name' => $last, 'phone' => null, 'city' => null, 'company' => null], $extra),
    ];
    $groups = [
        '9001' => [$sub(1, 'dina@anypress.example', 'active', 'Dina', 'Lee', ['city' => 'Oakland', 'company' => 'Globex']), $sub(2, 'evan@anypress.example', 'unsubscribed', 'Evan', 'Park'), $sub(3, 'fay@anypress.example', 'bounced', 'Fay', 'Wong')],
        '9002' => array_map(fn ($i) => $sub(100 + $i, "lead$i@anypress.example", 'active', "Lead$i", 'Person'), range(1, 230)),
    ];
    if ($path === 'groups') {
        $g = fn ($id, $name, $active, $unsub, $bounced) => ['id' => $id, 'name' => $name, 'active_count' => $active, 'sent_count' => 0, 'opens_count' => 0, 'unsubscribed_count' => $unsub, 'unconfirmed_count' => 0, 'bounced_count' => $bounced, 'junk_count' => 0, 'created_at' => '2023-01-01 00:00:00'];
        return [200, ['data' => [$g('9001', 'Customers', 1, 1, 1), $g('9002', 'Leads', 230, 0, 0)], 'meta' => ['current_page' => 1, 'last_page' => 1, 'per_page' => (int) ($query['limit'] ?? 25), 'total' => 2]]];
    }
    if ($path === 'fields') {
        $f = fn ($key, $name, $type = 'text') => ['id' => crc32($key), 'name' => $name, 'key' => $key, 'type' => $type, 'is_default' => $key !== 'company'];
        return [200, ['data' => [$f('email', 'Email'), $f('name', 'Name'), $f('last_name', 'Last name'), $f('phone', 'Phone'), $f('city', 'City'), $f('company', 'Company')], 'meta' => ['total' => 6]]];
    }
    if (preg_match('#^groups/(\d+)/subscribers$#', $path, $m) && isset($groups[$m[1]])) {
        $all = $groups[$m[1]];
        $limit = (int) ($query['limit'] ?? 25);
        $start = isset($query['cursor']) ? (int) base64_decode((string) $query['cursor']) : 0;
        $next = $start + $limit < count($all) ? base64_encode((string) ($start + $limit)) : null;
        return [200, ['data' => array_slice($all, $start, $limit), 'links' => [], 'meta' => ['path' => $path, 'per_page' => $limit, 'next_cursor' => $next, 'prev_cursor' => null]]];
    }

    return [404, ['message' => 'Resource not found.']];
}

add_filter('pre_http_request', function ($pre, $args, $url) {
    $parts = wp_parse_url($url);
    $host = $parts['host'] ?? '';
    parse_str($parts['query'] ?? '', $query);
    $headers = array_change_key_case((array) ($args['headers'] ?? []), CASE_LOWER);
    if ($host === 'api.brevo.com' && str_starts_with($parts['path'] ?? '', '/v3/')) {
        $key = (string) ($headers['api-key'] ?? '');
        crm_import_test_log($key);
        if ($key !== 'xkeysib-test') {
            return crm_import_test_answer(401, ['code' => 'unauthorized', 'message' => 'Key not found']);
        }
        [$code, $body] = crm_import_test_brevo(substr($parts['path'], 4), $query);
        return crm_import_test_answer($code, $body);
    }
    if ($host === 'connect.mailerlite.com' && str_starts_with($parts['path'] ?? '', '/api/')) {
        $key = (string) preg_replace('/^Bearer\s+/', '', (string) ($headers['authorization'] ?? ''));
        crm_import_test_log($key);
        if ($key !== 'ml-test') {
            return crm_import_test_answer(401, ['message' => 'Unauthenticated.']);
        }
        [$code, $body] = crm_import_test_mailerlite(substr($parts['path'], 5), $query);
        return crm_import_test_answer($code, $body);
    }

    return $pre;
}, 10, 3);
