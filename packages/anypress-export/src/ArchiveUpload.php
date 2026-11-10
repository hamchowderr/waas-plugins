<?php

namespace AnyPressExport;

defined('ABSPATH') || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messages are not printed here; Plugin and Cli show them as JSON or CLI text.
// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fclose, WordPress.WP.AlternativeFunctions.file_system_operations_fwrite, WordPress.WP.AlternativeFunctions.file_system_operations_fread, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir, WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- the export streams large files in chunks into its own temporary folder; WP_Filesystem has no streaming handles.

/** Sends the packed export to AnyPress (in parts where it can) and tells AnyPress to start the move: Exporter's last two steps. */
final class ArchiveUpload
{
    /**
     * Uploads the archive in parts, one part per call, so a dropped connection or a request that dies
     * costs one part: the next call asks AnyPress which parts it has and carries on. Part links expire
     * after 12 hours; an expired one is asked for again. An AnyPress without uploads in parts (404)
     * gets the archive in one request.
     */
    public static function step(array $state, Api $api, ?callable $heartbeat): array
    {
        $archive = $state['work'] . '/export.tar.gz';
        $size = (int) filesize($archive);
        if ($size > $state['maxBytes']) {
            throw new \RuntimeException(sprintf(
                /* translators: 1: size of the export, 2: size the AnyPress store holds */
                __('The export is %1$s, more than this AnyPress store holds (%2$s). Choose a larger size on AnyPress and start again.', 'anypress-export'),
                Exporter::size($size),
                Exporter::size($state['maxBytes'])
            ));
        }
        if (empty($state['parts'])) {
            try {
                $started = $api->startParts($size);
            } catch (ApiError $e) {
                if ($e->status !== 404) throw $e;
                return self::uploadWhole($state, $archive, $api, $heartbeat);
            }
            $state['parts'] = [
                'uploadId' => (string) $started['uploadId'],
                'size' => max(1, (int) $started['partSize']),
                'count' => max(1, (int) $started['partCount']),
                'done' => [],
                'urls' => [],
                'fresh' => false,
                'tries' => 0,
            ];
            return $state;
        }
        $parts = $state['parts'];
        $missing = array_values(array_diff(range(1, $parts['count']), $parts['done']));
        if (!$missing) {
            try {
                $api->finishParts($parts['uploadId']);
            } catch (ApiError $e) {
                if ($e->status !== 400 || $parts['fresh']) {
                    // 404: AnyPress no longer knows the upload, because an earlier finish joined it and its answer
                    // was lost (409: and the move began). Starting tells which: it says when nothing arrived.
                    if ($e->status !== 404 && $e->status !== 409) throw $e;
                    unset($state['parts']);
                    $state['step'] = 'start';
                    return $state;
                }
                // A part is missing after all: ask AnyPress which ones it has.
                $state['parts']['done'] = [];
                $state['parts']['urls'] = [];
                return $state;
            }
            unset($state['parts']);
            $state['step'] = 'start';
            return $state;
        }
        $part = $missing[0];
        if (!isset($parts['urls'][$part])) {
            try {
                $answer = $api->parts($parts['uploadId'], array_slice($missing, 0, 50));
            } catch (ApiError $e) {
                if ($e->status !== 404) throw $e;
                unset($state['parts']);
                return $state;
            }
            $parts['done'] = array_values(array_map('intval', (array) ($answer['done'] ?? [])));
            $parts['urls'] = [];
            foreach ((array) ($answer['urls'] ?? []) as $number => $url) {
                if (!in_array((int) $number, $parts['done'], true)) $parts['urls'][(int) $number] = (string) $url;
            }
            $parts['fresh'] = true;
            $state['parts'] = $parts;
            return $state;
        }
        $offset = ($part - 1) * $parts['size'];
        try {
            $api->putPart($parts['urls'][$part], $archive, $offset, min($parts['size'], $size - $offset), $heartbeat);
        } catch (ApiError $e) {
            if ($e->status === 403) {
                if ($parts['fresh']) throw new ApiError(__('AnyPress refused the upload. Cancel, then start again with a new code from AnyPress.', 'anypress-export'), 403);
                // The link expired (a move left overnight): ask for new ones.
                $state['parts']['urls'] = [];
                return $state;
            }
            if (($e->passing() || $e->status >= 500) && $parts['tries'] < 3) {
                // A network blip or a busy server: try this part again shortly.
                $state['parts']['tries']++;
                sleep(2 * $state['parts']['tries']);
                return $state;
            }
            throw $e;
        }
        $parts['done'][] = $part;
        unset($parts['urls'][$part]);
        $parts['fresh'] = false;
        $parts['tries'] = 0;
        $state['parts'] = $parts;
        return $state;
    }

    /** Uploads the archive in one request; asks AnyPress for a new link once if the saved one has expired. */
    private static function uploadWhole(array $state, string $archive, Api $api, ?callable $heartbeat): array
    {
        try {
            $api->put($state['uploadUrl'], $archive, $heartbeat);
        } catch (ApiError $e) {
            if ($e->status !== 403) throw $e;
            // Upload links last 12 hours; a move left overnight comes back to an expired one.
            $fresh = $api->upload();
            $state['uploadUrl'] = (string) $fresh['uploadUrl'];
            $state['maxBytes'] = (int) ($fresh['maxBytes'] ?? $state['maxBytes']);
            try {
                $api->put($state['uploadUrl'], $archive, $heartbeat);
            } catch (ApiError $again) {
                throw new ApiError($again->status === 403 ? __('AnyPress refused the upload. Cancel, then start again with a new code from AnyPress.', 'anypress-export') : $again->getMessage(), $again->status);
            }
        }
        $state['step'] = 'start';
        return $state;
    }

    /**
     * Tells AnyPress to set the store up. If an earlier request started it and died before saving its
     * state, AnyPress answers 409; when it confirms the move is under way, that counts as started.
     */
    public static function start(array $state, Api $api): void
    {
        try {
            $api->start(['sizeBytes' => (int) filesize($state['work'] . '/export.tar.gz')] + array_intersect_key(
                $state['site'],
                array_flip(['home', 'wordPressVersion', 'phpVersion', 'tablePrefix'])
            ));
        } catch (ApiError $e) {
            if ($e->status !== 409) throw $e;
            $status = $api->status();
            if (!in_array($status['status'] ?? '', ['moving', 'done'], true)) throw $e;
        }
    }
}
