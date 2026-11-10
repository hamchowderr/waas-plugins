<?php

namespace AnyPressClient\Moving;

/**
 * Reads a Circle community through Circle's Admin API v2 (https://api.circle.so/apis/admin-api), with the community
 * admin's own Admin V2 token. Circle publishes no PHP library for its Admin API, so this calls it with the WordPress
 * HTTP API. It only reads (GET); the token stays in memory and is never saved or shown.
 *
 * Every call counts against the Circle plan's monthly Admin API allowance, so lists are read 100 at a time (the most
 * Circle allows) and $requests counts the calls made.
 */
class CircleApi
{
    public const BASE = 'https://app.circle.so/api/admin/v2/';
    private const PER_PAGE = 100;

    public int $requests = 0;

    private string $token;

    public function __construct(string $token)
    {
        $this->token = $token;
    }

    /** How many records a list holds, from one call. */
    public function count(string $path, array $query = []): int
    {
        return (int) ($this->get($path, $query + ['page' => 1, 'per_page' => 1])['count'] ?? 0);
    }

    /** Every record of a list, page by page. */
    public function all(string $path, array $query = []): array
    {
        $records = [];
        for ($page = 1; $page <= 10000; $page++) {
            $data = $this->get($path, $query + ['page' => $page, 'per_page' => self::PER_PAGE]);
            $records = array_merge($records, array_values((array) ($data['records'] ?? [])));
            if (empty($data['has_next_page'])) {
                break;
            }
        }

        return $records;
    }

    private function get(string $path, array $query): array
    {
        $url = add_query_arg(array_map('rawurlencode', array_map('strval', $query)), self::BASE . ltrim($path, '/'));
        $try = 0;
        do {
            $this->requests++;
            $response = wp_remote_get($url, [
                'timeout' => 30,
                'headers' => ['Authorization' => 'Bearer ' . $this->token, 'Accept' => 'application/json'],
            ]);
            if (is_wp_error($response)) {
                throw new \RuntimeException(esc_html(sprintf('Circle could not be reached (%s).', $response->get_error_message())));
            }
            $status = (int) wp_remote_retrieve_response_code($response);
            // Circle allows 2,000 calls per 5 minutes; on 429 it says when to try again.
            $again = ($status === 429 || $status >= 500) && $try++ < 3;
            if ($again) {
                sleep(min(60, max(1, (int) wp_remote_retrieve_header($response, 'retry-after') ?: 5 * $try)));
            }
        } while ($again);
        if ($status === 401) {
            throw new \RuntimeException('Circle did not accept the token. Make an Admin V2 token in Circle under Settings, Developers, Tokens.');
        }
        if ($status === 403) {
            throw new \RuntimeException('Circle refused: the community\'s plan has no Admin API (it comes with Business and higher plans), or its monthly allowance is used up.');
        }
        if ($status !== 200) {
            throw new \RuntimeException(esc_html(sprintf('Circle answered %d for %s.', $status, $path)));
        }
        $data = json_decode((string) wp_remote_retrieve_body($response), true);
        if (!is_array($data)) {
            throw new \RuntimeException(esc_html(sprintf('Circle sent something that is not JSON for %s.', $path)));
        }

        return $data;
    }
}
