<?php

namespace AnyPressExport;

defined('ABSPATH') || exit;

/** The AnyPress API calls the export makes, with the import code as the only credential. */
class Api
{
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
     * PUTs the archive to the upload link in one streamed request.
     *
     * @throws \RuntimeException
     */
    public function put(string $url, string $file): void
    {
        if (!function_exists('curl_init')) throw new \RuntimeException('This server has no cURL for PHP, which the upload needs. Ask your host to turn it on.');
        $fh = fopen($file, 'rb');
        if (!$fh) throw new \RuntimeException('Could not read the export to upload it.');
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_UPLOAD => true,
            CURLOPT_INFILE => $fh,
            CURLOPT_INFILESIZE => filesize($file),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_HTTPHEADER => ['Content-Type: application/octet-stream', 'Expect:'],
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        fclose($fh);
        if ($body === false || $status < 200 || $status >= 300) {
            throw new \RuntimeException('Uploading the export failed' . ($status ? " (HTTP $status)" : '') . ($error ? ": $error" : '.') . ' Try again.');
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
            throw new \RuntimeException('Could not reach AnyPress: ' . $response->get_error_message());
        }
        $status = (int) wp_remote_retrieve_response_code($response);
        $data = json_decode((string) wp_remote_retrieve_body($response), true);
        if ($status < 200 || $status >= 300) {
            $message = is_array($data) && is_string($data['message'] ?? null) ? $data['message'] : "AnyPress answered HTTP $status.";
            throw new \RuntimeException($message);
        }
        if (!is_array($data)) throw new \RuntimeException('AnyPress sent an answer this plugin does not understand.');
        return $data;
    }
}
