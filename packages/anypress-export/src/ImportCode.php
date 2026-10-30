<?php

namespace AnyPressExport;

defined('ABSPATH') || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messages are not printed here; Plugin and Cli show them as JSON or CLI text.

/**
 * The import code AnyPress shows for a store waiting for its site: "AP1." and base64url JSON holding
 * the API's address (u) and the import's token (t). The token is the only credential the plugin
 * needs; it works until the move starts.
 */
final class ImportCode
{
    /** @var string */
    public $apiUrl;
    /** @var string */
    public $token;

    private function __construct(string $apiUrl, string $token)
    {
        $this->apiUrl = $apiUrl;
        $this->token = $token;
    }

    /**
     * Reads a pasted code. The API must be on https, unless `$allowHttp` (local testing only).
     *
     * @throws \InvalidArgumentException with a message for the person who pasted it.
     */
    public static function parse(string $code, bool $allowHttp = false): self
    {
        $code = preg_replace('/\s+/', '', $code);
        if (strpos($code, 'AP1.') !== 0) {
            throw new \InvalidArgumentException(__('This is not an AnyPress import code. Copy it again from AnyPress: it starts with AP1.', 'anypress-export'));
        }
        $json = base64_decode(strtr(substr($code, 4), '-_', '+/'), true);
        $data = $json === false ? null : json_decode($json, true);
        $url = is_array($data) && is_string($data['u'] ?? null) ? rtrim($data['u'], '/') : '';
        $token = is_array($data) && is_string($data['t'] ?? null) ? $data['t'] : '';
        if ($url === '' || $token === '') {
            throw new \InvalidArgumentException(__('This import code is incomplete. Copy the whole code again from AnyPress.', 'anypress-export'));
        }
        $scheme = wp_parse_url($url, PHP_URL_SCHEME);
        if ($scheme !== 'https' && !($allowHttp && $scheme === 'http')) {
            throw new \InvalidArgumentException(__('This import code does not point to a secure AnyPress address.', 'anypress-export'));
        }
        return new self($url, $token);
    }

    /** The code again, for the API calls that take it. */
    public function code(): string
    {
        return 'AP1.' . rtrim(strtr(base64_encode((string) json_encode(['u' => $this->apiUrl, 't' => $this->token], JSON_UNESCAPED_SLASHES)), '+/', '-_'), '=');
    }
}
