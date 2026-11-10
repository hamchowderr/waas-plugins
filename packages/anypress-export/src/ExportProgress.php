<?php

namespace AnyPressExport;

defined('ABSPATH') || exit;

/** Where an export is, for the progress bar. */
final class ExportProgress
{
    /** Where the export is, for the progress bar: a step, a percentage, a sentence, and notes for the owner. */
    public static function of(array $state): array
    {
        $step = $state['step'] ?? 'database';
        $progress = self::where($state, $step);
        $notes = (array) ($state['site']['preflight']['notes'] ?? []);
        $changed = array_merge((array) ($state['skipped'] ?? []), (array) ($state['changed'] ?? []));
        if ($changed) {
            $more = (int) ($state['skippedCount'] ?? 0) - count($changed);
            $notes[] = [
                'id' => 'changed',
                'title' => __('Files that changed while we packed them', 'anypress-export'),
                'text' => $more > 0
                    /* translators: %d: how many more files */
                    ? sprintf(__('These were deleted or changed on this site during the move, so check them on AnyPress (and %d more).', 'anypress-export'), $more)
                    : __('These were deleted or changed on this site during the move, so check them on AnyPress.', 'anypress-export'),
                'items' => $changed,
            ];
        }
        return $progress + ['notes' => $notes];
    }

    private static function where(array $state, string $step): array
    {
        switch ($step) {
            case 'preflight':
                return ['step' => $step, 'percent' => 0, 'message' => __('Checking your site', 'anypress-export')];
            case 'files':
                return ['step' => $step, 'percent' => 2, 'message' => sprintf(
                    /* translators: %d: files listed so far */
                    __('Listing your files (%d so far)', 'anypress-export'),
                    (int) ($state['listing']['files'] ?? 0)
                )];
            case 'database':
                $tables = max(1, count($state['tables']));
                return ['step' => $step, 'percent' => 5 + (int) (10 * $state['table'] / $tables), 'message' => sprintf(
                    /* translators: 1: table number, 2: number of tables */
                    __('Exporting the database (table %1$d of %2$d)', 'anypress-export'),
                    min($state['table'] + 1, $tables),
                    $tables
                )];
            case 'pack':
                $done = $state['position'] > 0 ? $state['position'] : 0;
                $total = max(1, (int) @filesize($state['work'] . '/files.txt'));
                return ['step' => $step, 'percent' => 15 + (int) (60 * min(1, $done / $total)), 'message' => sprintf(
                    /* translators: %s: size packed so far, such as 120 MB */
                    __('Packing your files (%s so far)', 'anypress-export'),
                    Exporter::size((int) $state['packedBytes'])
                )];
            case 'upload':
                if (!empty($state['parts'])) {
                    return ['step' => $step, 'percent' => 75 + (int) (20 * count($state['parts']['done']) / max(1, $state['parts']['count'])), 'message' => sprintf(
                        /* translators: 1: size of the export, such as 3 GB, 2: parts sent, 3: number of parts */
                        __('Uploading %1$s to AnyPress (part %2$d of %3$d). If this page stops answering, come back: the upload carries on from the last part.', 'anypress-export'),
                        Exporter::size((int) $state['packedBytes']),
                        min(count($state['parts']['done']) + 1, $state['parts']['count']),
                        $state['parts']['count']
                    )];
                }
                $message = $state['packedBytes'] > Preflight::LARGE_BYTES
                    /* translators: %s: size of the export, such as 3 GB */
                    ? __('Uploading %s to AnyPress. If this page stops answering, the upload carries on here; for sites this big, wp anypress-export send on the command line is more reliable.', 'anypress-export')
                    /* translators: %s: size of the export, such as 120 MB */
                    : __('Uploading %s to AnyPress', 'anypress-export');
                $message = sprintf($message, Exporter::size((int) $state['packedBytes']));
                return ['step' => $step, 'percent' => 75, 'message' => $message];
            case 'start':
                return ['step' => $step, 'percent' => 95, 'message' => __('Telling AnyPress to set up your site', 'anypress-export')];
            default:
                return ['step' => $step, 'percent' => 100, 'message' => __('AnyPress is setting up your site', 'anypress-export')];
        }
    }
}
