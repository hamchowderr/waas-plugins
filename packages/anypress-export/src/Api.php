<?php

namespace AnyPressExport;

defined('ABSPATH') || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messages are not printed here; Plugin and Cli show them as JSON or CLI text.
// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fclose, WordPress.WP.AlternativeFunctions.file_system_operations_fwrite, WordPress.WP.AlternativeFunctions.file_system_operations_fread, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir, WordPress.WP.AlternativeFunctions.file_system_operations_mkdir, WordPress.WP.AlternativeFunctions.curl_curl_init, WordPress.WP.AlternativeFunctions.curl_curl_setopt_array, WordPress.WP.AlternativeFunctions.curl_curl_exec, WordPress.WP.AlternativeFunctions.curl_curl_getinfo, WordPress.WP.AlternativeFunctions.curl_curl_error, WordPress.WP.AlternativeFunctions.curl_curl_close -- the upload streams a multi-gigabyte archive from a file handle (CURLOPT_INFILE); the HTTP API and WP_Filesystem read whole files into memory.

/** The AnyPress API calls the export makes, with the import code as the only credential. */
class Api
{
    /** Why a server without cURL can't move: shown before anything is packed. */
    public static function noCurl(): string
    {
        return __('This server has no cURL for PHP, which the upload needs. Ask your host to turn it on, then start again.', 'anypress-export');
    }

    /** @var ImportCode */
    private $code;

    public function __construct(ImportCode $code)
    {
        $this->code = $code;
    }

    /** @return array{uploadUrl: string, maxBytes: int, store: array} */
    public function upload(): array
    {
        return $this->post('upload', []);
    }

    /** @param array<string, mixed> $source what the export tells about this site */
    public function start(array $source): array
    {
        return $this->post('start', ['source' => $source]);
    }

    /** @return array{status: string, store: array, url?: string} */
    public function status(): array
    {
        return $this->post('status', []);
    }

    /**
     * PUTs the archive to the upload link in one streamed request. `$heartbeat` is called about every
     * 20 seconds while it runs (the plugin renews its lock with it, so a reload can't start a second
     * upload). A link that has expired or is refused (HTTP 403) throws ApiError with status 403, so the
     * caller can ask AnyPress for a new one.
     *
     * @throws ApiError
     */
    public function put(string $url, string $file, ?callable $heartbeat = null): void
    {
        if (!function_exists('curl_init')) throw new ApiError(self::noCurl());
        $fh = fopen($file, 'rb');
        if (!$fh) throw new ApiError(__('Could not read the export to upload it.', 'anypress-export'));
        $last = time();
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_UPLOAD => true,
            CURLOPT_INFILE => $fh,
            CURLOPT_INFILESIZE => filesize($file),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 30,
            // Give up on a stalled upload (under 1 KB/s for two minutes) instead of hanging for good.
            CURLOPT_LOW_SPEED_LIMIT => 1024,
            CURLOPT_LOW_SPEED_TIME => 120,
            CURLOPT_HTTPHEADER => ['Content-Type: application/octet-stream', 'Expect:'],
            CURLOPT_NOPROGRESS => $heartbeat === null,
            CURLOPT_PROGRESSFUNCTION => function () use ($heartbeat, &$last) {
                if ($heartbeat !== null && time() - $last >= 20) {
                    $last = time();
                    $heartbeat();
                }
                return 0;
            },
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        fclose($fh);
        if ($status === 403) throw new ApiError(__('The upload link has expired.', 'anypress-export'), 403);
        if ($body === false || $status < 200 || $status >= 300) {
            throw new ApiError(sprintf(
                /* translators: %s: why, such as "(HTTP 502)" or a network error */
                __('Uploading the export failed %s. Try again.', 'anypress-export'),
                trim(($status ? "(HTTP $status)" : '') . ($error ? " $error" : ''))
            ), $status);
        }
    }

    /** @param array<string, mixed> $body */
    protected function post(string $path, array $body): array
    {
        $response = wp_remote_post($this->code->apiUrl . '/imports/' . $path, [
            'timeout' => 60,
            'headers' => ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
            'body' => wp_json_encode(['code' => $this->code->code()] + $body),
        ]);
        if (is_wp_error($response)) {
            throw new ApiError(sprintf(
                /* translators: %s: the network error */
                __('Could not reach AnyPress: %s', 'anypress-export'),
                $response->get_error_message()
            ));
        }
        $status = (int) wp_remote_retrieve_response_code($response);
        $data = json_decode((string) wp_remote_retrieve_body($response), true);
        if ($status < 200 || $status >= 300) {
            $message = is_array($data) && is_string($data['message'] ?? null) ? $data['message'] : sprintf(
                /* translators: %d: HTTP status code */
                __('AnyPress answered HTTP %d.', 'anypress-export'),
                $status
            );
            throw new ApiError($message, $status);
        }
        if (!is_array($data)) throw new ApiError(__('AnyPress sent an answer this plugin does not understand.', 'anypress-export'));
        return $data;
    }
}
