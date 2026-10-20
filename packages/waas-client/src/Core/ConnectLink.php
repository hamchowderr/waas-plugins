<?php

namespace WaaSClient\Core;

/**
 * "Sign in with your website" for the AnyPress connector. The API sends a store owner here with a link signed
 * with the store's site key: HMAC-SHA256 over "waas-connect\n<request>\n<app>\n<to>\n<write>\n<back>\n<t>", where
 * app is the AI app's name, to is where it returns the owner to, write is '1' when it asks to make changes, back is
 * the API's answer URL and t the Unix time the link stops working. The owner's answer goes to back, signed over
 * "waas-connect-answer\n<request>\n<answer>", so the API knows this website's admin gave it.
 */
class ConnectLink
{
    public const FIELDS = ['request', 'app', 'to', 'write', 'back', 't'];
    public const ANSWERS = ['allow', 'allow-read', 'deny'];

    /** The link's fields when $params carries a current link signed with $key; null otherwise. */
    public static function verify(string $key, array $params, ?int $now = null): ?array
    {
        if ($key === '') {
            return null;
        }
        $link = [];
        foreach (self::FIELDS as $field) {
            if (!isset($params[$field]) || !is_string($params[$field])) {
                return null;
            }
            $link[$field] = $params[$field];
        }
        $signature = $params['s'] ?? '';
        if (!is_string($signature) || !preg_match('/^[0-9a-f]{64}$/', $signature) || !hash_equals(self::sign($key, $link), $signature)) {
            return null;
        }
        if (!preg_match('/^\d{1,12}$/', $link['t']) || (int) $link['t'] < ($now ?? time()) || !in_array($link['write'], ['0', '1'], true)) {
            return null;
        }
        $back = wp_parse_url($link['back']);
        if (($back['scheme'] ?? '') !== 'https' || empty($back['host'])) {
            return null;
        }

        return $link;
    }

    public static function sign(string $key, array $link): string
    {
        $values = array_map(fn ($field) => (string) ($link[$field] ?? ''), self::FIELDS);

        return hash_hmac('sha256', implode("\n", array_merge(['waas-connect'], $values)), $key);
    }

    /** Where to send the owner with their answer: the API's answer URL, with the answer signed. */
    public static function answer_url(string $key, array $link, string $answer): string
    {
        $signature = hash_hmac('sha256', implode("\n", ['waas-connect-answer', $link['request'], $answer]), $key);
        $query = http_build_query(['request' => $link['request'], 'answer' => $answer, 's' => $signature], '', '&', PHP_QUERY_RFC3986);

        return strtok($link['back'], '?#') . '?' . $query;
    }
}
