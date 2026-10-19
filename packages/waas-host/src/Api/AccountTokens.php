<?php

namespace WaaSHost\Api;

/**
 * Sign-in codes and tokens for the account API's token sign-in (see AccountSignIn).
 *
 * - A code is single use and lives CODE_TTL seconds, in a transient keyed by its hash.
 * - Tokens belong to a session, kept in the user's meta with only hashes of the tokens. Access tokens live ACCESS_TTL
 *   seconds; each refresh replaces both tokens. A refresh token used a second time means it was copied, so the whole
 *   session is revoked. A session ends after SESSION_TTL whatever happens.
 * - Tokens read "at|rt.<user id>.<session>.<secret>", so they can be looked up without a table.
 */
class AccountTokens
{
    public const CODE_TTL = 60;
    public const ACCESS_TTL = 900;
    public const REFRESH_TTL = 30 * 86400;
    public const SESSION_TTL = 90 * 86400;
    /** Sessions kept per user; the oldest goes first. */
    public const MAX_SESSIONS = 20;
    /** Rotated refresh tokens remembered per session, to spot one used twice. */
    public const MAX_USED = 20;

    public const SESSIONS_META = 'waas_account_sessions';
    public const CODE_TRANSIENT = 'waas_acct_code_';
    /** A used code, kept a while so a second try at it revokes what it gave. */
    public const USED_CODE_TRANSIENT = 'waas_acct_used_';
    public const USED_CODE_TTL = 600;

    /**
     * A one-time code for the authorize step.
     *
     * @return string the code to send back to the client
     */
    public function issue_code(string $client_id, string $redirect_uri, int $user_id, string $code_challenge): string
    {
        $code = self::base64url(random_bytes(32));
        set_transient(self::CODE_TRANSIENT . self::hash($code), [
            'client_id' => $client_id,
            'redirect_uri' => $redirect_uri,
            'user_id' => $user_id,
            'code_challenge' => $code_challenge,
            'expires' => time() + self::CODE_TTL,
        ], self::CODE_TTL);

        return $code;
    }

    /**
     * Takes a code: it works once. A code that was already taken revokes the session it gave.
     *
     * @return array|null what issue_code stored, or null when the code is unknown, used or expired
     */
    public function consume_code(string $code): ?array
    {
        if ($code === '') {
            return null;
        }
        $hash = self::hash($code);
        $key = self::CODE_TRANSIENT . $hash;
        $data = get_transient($key);
        // Only the request that deletes the code may use it, so two at once can't both succeed.
        if (!is_array($data) || !delete_transient($key)) {
            $used = get_transient(self::USED_CODE_TRANSIENT . $hash);
            if (is_array($used)) {
                $this->revoke_session((int) $used['user_id'], (string) $used['session']);
            }
            return null;
        }

        return (int) ($data['expires'] ?? 0) >= time() ? $data + ['hash' => $hash] : null;
    }

    /**
     * Starts a session for a code just taken with consume_code.
     *
     * @return array the token response: access_token, token_type, expires_in, refresh_token
     */
    public function issue_for_code(array $code, string $client_id): array
    {
        $user_id = (int) $code['user_id'];
        $session = bin2hex(random_bytes(16));
        set_transient(self::USED_CODE_TRANSIENT . $code['hash'], ['user_id' => $user_id, 'session' => $session], self::USED_CODE_TTL);

        $sessions = $this->sessions($user_id);
        $sessions[$session] = ['client_id' => $client_id, 'created' => time(), 'used' => []];

        return $this->rotate($user_id, $session, $sessions);
    }

    /**
     * New tokens for a refresh token, which then stops working.
     *
     * @return array|null the token response, or null when the token isn't valid for this client
     */
    public function refresh(string $refresh_token, string $client_id): ?array
    {
        $token = self::parse($refresh_token, 'rt');
        if ($token === null) {
            return null;
        }
        [$user_id, $session] = $token;
        $sessions = $this->sessions($user_id);
        $current = $sessions[$session] ?? null;
        if ($current === null || $current['client_id'] !== $client_id) {
            return null;
        }

        $hash = self::hash($refresh_token);
        if (in_array($hash, $current['used'], true)) {
            $this->revoke_session($user_id, $session);
            return null;
        }
        if (!hash_equals($current['refresh'], $hash) || $current['refresh_expires'] < time()) {
            return null;
        }

        $sessions[$session]['used'] = array_slice(array_merge($current['used'], [$hash]), -self::MAX_USED);

        return $this->rotate($user_id, $session, $sessions);
    }

    /** The user an access token signs in, or 0 when it isn't valid. */
    public function user_for_access_token(string $access_token): int
    {
        $token = self::parse($access_token, 'at');
        if ($token === null) {
            return 0;
        }
        [$user_id, $session] = $token;
        $current = $this->sessions($user_id)[$session] ?? null;
        if ($current === null || !hash_equals($current['access'], self::hash($access_token)) || $current['access_expires'] < time()) {
            return 0;
        }

        return $user_id;
    }

    /** Ends the session a token (access or refresh) belongs to, when it belongs to this client. */
    public function revoke(string $token, string $client_id): void
    {
        $parsed = self::parse($token, 'at') ?? self::parse($token, 'rt');
        if ($parsed === null) {
            return;
        }
        [$user_id, $session] = $parsed;
        $current = $this->sessions($user_id)[$session] ?? null;
        $hash = self::hash($token);
        $known = $current !== null && (hash_equals($current['access'], $hash) || hash_equals($current['refresh'], $hash) || in_array($hash, $current['used'], true));
        if ($known && $current['client_id'] === $client_id) {
            $this->revoke_session($user_id, $session);
        }
    }

    /** Ends every session of the user (their password changed, say). */
    public function revoke_all(int $user_id): void
    {
        delete_user_meta($user_id, self::SESSIONS_META);
    }

    public static function base64url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private function rotate(int $user_id, string $session, array $sessions): array
    {
        $now = time();
        $access = 'at.' . $user_id . '.' . $session . '.' . self::base64url(random_bytes(32));
        $refresh = 'rt.' . $user_id . '.' . $session . '.' . self::base64url(random_bytes(32));
        $sessions[$session]['access'] = self::hash($access);
        $sessions[$session]['access_expires'] = $now + self::ACCESS_TTL;
        $sessions[$session]['refresh'] = self::hash($refresh);
        $sessions[$session]['refresh_expires'] = min($now + self::REFRESH_TTL, $sessions[$session]['created'] + self::SESSION_TTL);

        // Newest last; the oldest go when there are too many.
        uasort($sessions, fn ($a, $b) => $a['created'] <=> $b['created']);
        $sessions = array_slice($sessions, -self::MAX_SESSIONS, null, true);
        update_user_meta($user_id, self::SESSIONS_META, $sessions);

        return [
            'access_token' => $access,
            'token_type' => 'Bearer',
            'expires_in' => self::ACCESS_TTL,
            'refresh_token' => $refresh,
        ];
    }

    private function revoke_session(int $user_id, string $session): void
    {
        $sessions = $this->sessions($user_id);
        if (isset($sessions[$session])) {
            unset($sessions[$session]);
            update_user_meta($user_id, self::SESSIONS_META, $sessions);
        }
    }

    /** The user's live sessions; ended ones are left out. */
    private function sessions(int $user_id): array
    {
        $sessions = get_user_meta($user_id, self::SESSIONS_META, true);
        $now = time();

        return array_filter(is_array($sessions) ? $sessions : [], fn ($session) => is_array($session)
            && isset($session['client_id'], $session['created'], $session['access'], $session['refresh'], $session['used'])
            && (int) ($session['refresh_expires'] ?? 0) >= $now);
    }

    /** @return array{0: int, 1: string}|null the user id and session of a token of this type */
    private static function parse(string $token, string $type): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 4 || $parts[0] !== $type || !ctype_digit($parts[1]) || !preg_match('/^[0-9a-f]{32}$/', $parts[2]) || $parts[3] === '') {
            return null;
        }

        return [(int) $parts[1], $parts[2]];
    }

    /** Tokens are kept only as keyed hashes, so a copy of the database can't be used to sign in. */
    private static function hash(string $token): string
    {
        return hash_hmac('sha256', $token, wp_salt('auth'));
    }
}
