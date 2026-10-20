<?php

namespace AnyPressExport;

defined('ABSPATH') || exit;

/**
 * The wp-content files that go into the export, one relative path per line in a list file, so the
 * archive can be built over several requests. Leaves out caches, other backup plugins' archives,
 * drop-ins that only work on the old host, logs, symbolic links and the export's own work folder.
 */
final class FileList
{
    /** Top-level wp-content entries never exported. */
    const SKIP_TOP = [
        'cache', 'upgrade', 'upgrade-temp-backup', 'ai1wm-backups', 'updraft', 'backups-dup-lite', 'backups-dup-pro',
        'wpvividbackups', 'backup-db', 'backupwordpress', 'wflogs', 'et-cache', 'object-cache.php', 'advanced-cache.php',
        'db.php', 'wp-cache-config.php', 'debug.log',
    ];

    /**
     * Writes the list for `$contentDir` to `$listFile`, leaving out `$skip` (absolute paths, such as the
     * work folder). Returns the number of files and their total size.
     *
     * @param string[] $skip
     * @return array{files: int, bytes: int}
     */
    public static function build(string $contentDir, string $listFile, array $skip = []): array
    {
        $contentDir = rtrim($contentDir, '/');
        $skip = array_map(function ($path) {
            return rtrim($path, '/');
        }, $skip);
        $out = fopen($listFile, 'wb');
        if (!$out) throw new \RuntimeException('Could not write the file list.');
        $files = 0;
        $bytes = 0;
        $dirs = [''];
        while ($dirs) {
            $rel = array_pop($dirs);
            $abs = $rel === '' ? $contentDir : "$contentDir/$rel";
            $entries = @scandir($abs);
            if ($entries === false) continue;
            foreach ($entries as $name) {
                if ($name === '.' || $name === '..') continue;
                $childRel = $rel === '' ? $name : "$rel/$name";
                $childAbs = "$contentDir/$childRel";
                if ($rel === '' && in_array($name, self::SKIP_TOP, true)) continue;
                if (in_array($childAbs, $skip, true) || is_link($childAbs)) continue;
                if (strpos($childRel, "\n") !== false) continue;
                if (is_dir($childAbs)) {
                    $dirs[] = $childRel;
                } elseif (is_file($childAbs) && is_readable($childAbs)) {
                    fwrite($out, $childRel . "\n");
                    $files++;
                    $bytes += (int) filesize($childAbs);
                }
            }
        }
        fclose($out);
        return ['files' => $files, 'bytes' => $bytes];
    }
}
