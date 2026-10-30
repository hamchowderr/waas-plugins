<?php

namespace AnyPressExport;

defined('ABSPATH') || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messages are not printed here; Plugin and Cli show them as JSON or CLI text.
// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fclose, WordPress.WP.AlternativeFunctions.file_system_operations_fwrite, WordPress.WP.AlternativeFunctions.file_system_operations_fread, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir, WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- writes the file list line by line to a temporary file; WP_Filesystem has no streaming handles.

/**
 * The wp-content files that go into the export, one relative path per line in a list file, so the
 * archive can be built over several requests. Leaves out caches, other backup plugins' archives,
 * drop-ins that only work on the old host, logs, symbolic links and the export's own work folder.
 *
 * The listing itself also runs in steps: a cursor (the folders still to read, and how far into the
 * current one it got) carries it from one request to the next, so a site with hundreds of thousands
 * of uploads is listed within each request's time budget. It also notes what it leaves out on
 * purpose that the owner should know about: symbolic links and files it can't read.
 */
final class FileList
{
    /** Top-level wp-content entries never exported. */
    const SKIP_TOP = [
        'cache', 'upgrade', 'upgrade-temp-backup', 'ai1wm-backups', 'updraft', 'backups-dup-lite', 'backups-dup-pro',
        'wpvividbackups', 'backup-db', 'backupwordpress', 'wflogs', 'et-cache', 'object-cache.php', 'advanced-cache.php',
        'db.php', 'wp-cache-config.php', 'debug.log',
    ];
    /** How many skipped paths of each kind are kept by name (all are counted). */
    const REPORT = 50;

    /** A cursor for a new listing. */
    public static function start(): array
    {
        return [
            'dirs' => [''],
            'dir' => null,
            'index' => 0,
            'files' => 0,
            'bytes' => 0,
            'uploadsBytes' => 0,
            'symlinks' => [],
            'symlinkCount' => 0,
            'unreadable' => [],
            'unreadableCount' => 0,
            'done' => false,
        ];
    }

    /**
     * Lists from `$cursor` until `$deadline` (microtime) or the end, writing each file's path to `$out`
     * (a stream, or null to only count). Always gets through at least one entry. Returns the new cursor;
     * `done` is true at the end.
     *
     * @param resource|null $out
     * @param string[] $skip absolute paths to leave out, such as the work folder
     */
    public static function step(string $contentDir, $out, array $cursor, array $skip, float $deadline): array
    {
        $contentDir = rtrim($contentDir, '/');
        $skip = array_map(function ($path) {
            return rtrim($path, '/');
        }, $skip);
        $did = 0;
        for (;;) {
            if ($cursor['dir'] === null) {
                if (!$cursor['dirs']) {
                    $cursor['done'] = true;
                    return $cursor;
                }
                $cursor['dir'] = array_pop($cursor['dirs']);
                $cursor['index'] = 0;
            }
            $rel = (string) $cursor['dir'];
            $entries = @scandir($rel === '' ? $contentDir : "$contentDir/$rel");
            $entries = $entries === false ? [] : $entries;
            for ($i = (int) $cursor['index'], $n = count($entries); $i < $n; $i++) {
                if ($did++ > 0 && microtime(true) >= $deadline) {
                    $cursor['index'] = $i;
                    return $cursor;
                }
                $name = $entries[$i];
                if ($name === '.' || $name === '..') continue;
                if ($rel === '' && in_array($name, self::SKIP_TOP, true)) continue;
                $childRel = $rel === '' ? $name : "$rel/$name";
                $childAbs = "$contentDir/$childRel";
                if (in_array($childAbs, $skip, true) || strpos($childRel, "\n") !== false) continue;
                if (is_link($childAbs)) {
                    $cursor['symlinkCount']++;
                    if (count($cursor['symlinks']) < self::REPORT) {
                        $cursor['symlinks'][] = ['path' => $childRel, 'target' => (string) @readlink($childAbs)];
                    }
                } elseif (is_dir($childAbs)) {
                    $cursor['dirs'][] = $childRel;
                } elseif (is_file($childAbs) && is_readable($childAbs)) {
                    if ($out !== null) {
                        $line = $childRel . "\n";
                        if (fwrite($out, $line) !== strlen($line)) {
                            throw new \RuntimeException(__('Could not write the file list. Is the disk full? Free up space on this server, then cancel and start again.', 'anypress-export'));
                        }
                    }
                    $size = (int) @filesize($childAbs);
                    $cursor['files']++;
                    $cursor['bytes'] += $size;
                    if (strpos($childRel, 'uploads/') === 0) $cursor['uploadsBytes'] += $size;
                } else {
                    $cursor['unreadableCount']++;
                    if (count($cursor['unreadable']) < self::REPORT) $cursor['unreadable'][] = $childRel;
                }
            }
            $cursor['dir'] = null;
        }
    }

    /**
     * Lists everything in one go (or until `$deadline`) into `$listFile` (null to only count).
     *
     * @param string[] $skip
     */
    public static function build(string $contentDir, ?string $listFile, array $skip = [], float $deadline = INF): array
    {
        $out = null;
        if ($listFile !== null) {
            $out = fopen($listFile, 'wb');
            if (!$out) throw new \RuntimeException(__('Could not write the file list.', 'anypress-export'));
        }
        try {
            return self::step($contentDir, $out, self::start(), $skip, $deadline);
        } finally {
            if ($out) fclose($out);
        }
    }
}
