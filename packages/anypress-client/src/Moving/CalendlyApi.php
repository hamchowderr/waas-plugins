<?php

namespace AnyPressClient\Moving;

/**
 * Reads a Calendly account through Calendly's API v2 (https://developer.calendly.com/api-docs), with the account
 * owner's own personal access token. Calendly publishes no PHP library for its API, so this calls it with the
 * WordPress HTTP API. It only reads (GET); the token stays in memory and is never saved or shown.
 *
 * Calendly allows 500 calls a minute on paid plans and 50 on the free plan; on 429 it says how many seconds until
 * the minute resets, and this waits that long and tries again.
 */
class CalendlyApi
{
    public const BASE = 'https://api.calendly.com/';
    private const PER_PAGE = 100;

    private string $token;

    public function __construct(string $token)
    {
        $this->token = $token;
    }

    /** One resource, such as users/me. */
    public function one(string $path, array $query = []): array
    {
        return (array) ($this->get($path, $query)['resource'] ?? []);
    }

    /** Every record of a list, page by page (Calendly pages with page_token). */
    public function all(string $path, array $query = []): array
    {
        $records = [];
        $token = '';
        for ($page = 1; $page <= 10000; $page++) {
            $data = $this->get($path, $query + ['count' => self::PER_PAGE] + ($token !== '' ? ['page_token' => $token] : []));
            $records = array_merge($records, array_values((array) ($data['collection'] ?? [])));
            $token = (string) ($data['pagination']['next_page_token'] ?? '');
            if ($token === '') {
                break;
            }
        }

        return $records;
    }

    /** A list Calendly answers in one go, without pages (availability schedules). */
    public function list(string $path, array $query = []): array
    {
        return array_values((array) ($this->get($path, $query)['collection'] ?? []));
    }

    /** The last part of a Calendly URI (https://api.calendly.com/scheduled_events/ABC => ABC). */
    public static function uuid(string $uri): string
    {
        return (string) basename((string) wp_parse_url($uri, PHP_URL_PATH));
    }

    private function get(string $path, array $query): array
    {
        $url = add_query_arg(array_map('rawurlencode', array_map('strval', $query)), self::BASE . ltrim($path, '/'));
        $try = 0;
        do {
            $response = wp_remote_get($url, [
                'timeout' => 30,
                'headers' => ['Authorization' => 'Bearer ' . $this->token, 'Accept' => 'application/json'],
            ]);
            if (is_wp_error($response)) {
                throw new \RuntimeException(esc_html(sprintf('Calendly could not be reached (%s).', $response->get_error_message())));
            }
            $status = (int) wp_remote_retrieve_response_code($response);
            $again = ($status === 429 || $status >= 500) && $try++ < 3;
            if ($again) {
                sleep(min(60, max(1, (int) wp_remote_retrieve_header($response, 'x-ratelimit-reset') ?: 5 * $try)));
            }
        } while ($again);
        if ($status === 401) {
            throw new \RuntimeException('Calendly did not accept the token. Make a personal access token in Calendly: profile menu, Developer, Personal access tokens.');
        }
        if ($status === 403) {
            throw new \RuntimeException(esc_html(sprintf('Calendly refused %s: give the token the read scopes for users, event types, availability and scheduled events.', $path)));
        }
        if ($status !== 200) {
            throw new \RuntimeException(esc_html(sprintf('Calendly answered %d for %s.', $status, $path)));
        }
        $data = json_decode((string) wp_remote_retrieve_body($response), true);
        if (!is_array($data)) {
            throw new \RuntimeException(esc_html(sprintf('Calendly sent something that is not JSON for %s.', $path)));
        }

        return $data;
    }
}
