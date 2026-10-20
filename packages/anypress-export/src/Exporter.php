<?php

namespace AnyPressExport;

defined('ABSPATH') || exit;

/**
 * Moves this site to AnyPress in steps that each fit in a short request: dump the database, list
 * wp-content, pack both into a gzipped tar, upload it, and start the move. The state lives in an
 * array the caller keeps between requests (an option on the site); run() advances it until its time
 * budget is spent. The WP-CLI command runs it to the end in one go.
 *
 * The archive holds database.sql, waas.json (what AnyPress needs to know about the site) and
 * wp-content/, which is what the AnyPress store agent imports.
 */
final class Exporter
{
    const STEPS = ['database', 'files', 'pack', 'upload', 'start', 'moving'];

    /** @var Database */
    private $db;
    /** @var string */
    private $contentDir;

    public function __construct(Database $db, string $contentDir)
    {
        $this->db = $db;
        $this->contentDir = rtrim($contentDir, '/');
    }

    /**
     * Checks the code with AnyPress and prepares the work folder (under `$workBase`, kept out of the
     * export and closed to the web). `$site` is what waas.json tells AnyPress about the site.
     *
     * @param array<string, mixed> $site
     */
    public function begin(ImportCode $code, Api $api, string $workBase, array $site): array
    {
        $upload = $api->upload();
        $work = rtrim($workBase, '/') . '/anypress-export-' . bin2hex(random_bytes(8));
        if (!@mkdir($work, 0700, true)) throw new \RuntimeException('Could not create a work folder in the uploads folder.');
        file_put_contents("$work/index.php", "<?php\n// Silence is golden.\n");
        file_put_contents("$work/.htaccess", "Require all denied\nDeny from all\n");
        file_put_contents("$work/waas.json", (string) json_encode($site, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        (new SqlDump($this->db, "$work/database.sql"))->begin();
        clearstatcache(true, "$work/database.sql");
        return [
            'code' => $code->code(),
            'step' => 'database',
            'work' => $work,
            'uploadUrl' => (string) $upload['uploadUrl'],
            'maxBytes' => (int) $upload['maxBytes'],
            'store' => (array) ($upload['store'] ?? []),
            'site' => $site,
            'tables' => $this->db->tables(),
            'table' => 0,
            'offset' => 0,
            'listed' => null,
            'position' => 0,
            'fileOffset' => 0,
            'entry' => [],
            'packedBytes' => 0,
            'sqlBytes' => (int) filesize("$work/database.sql"),
            'startedAt' => time(),
        ];
    }

    /**
     * Advances the export until `$seconds` have passed or it waits on AnyPress. Returns the new state.
     * Throws with a message for the person on failure; the state is then left as it was before the step.
     */
    public function run(array $state, Api $api, float $seconds): array
    {
        $deadline = microtime(true) + $seconds;
        while (microtime(true) < $deadline) {
            switch ($state['step']) {
                case 'database':
                    $state = $this->database($state, $deadline);
                    break;
                case 'files':
                    $state = $this->files($state);
                    break;
                case 'pack':
                    $state = $this->pack($state, $deadline);
                    break;
                case 'upload':
                    $archive = $state['work'] . '/export.tar.gz';
                    $size = (int) filesize($archive);
                    if ($size > $state['maxBytes']) {
                        throw new \RuntimeException(sprintf(
                            'The export is %s, more than this AnyPress store holds (%s). Choose a larger size on AnyPress and start again.',
                            self::size($size),
                            self::size($state['maxBytes'])
                        ));
                    }
                    $api->put($state['uploadUrl'], $archive);
                    $state['step'] = 'start';
                    break;
                case 'start':
                    $api->start(['sizeBytes' => (int) filesize($state['work'] . '/export.tar.gz')] + array_intersect_key(
                        $state['site'],
                        array_flip(['home', 'wordPressVersion', 'phpVersion', 'tablePrefix'])
                    ));
                    self::remove($state['work']);
                    $state['step'] = 'moving';
                    return $state;
                default:
                    return $state;
            }
        }
        return $state;
    }

    private function database(array $state, float $deadline): array
    {
        $file = $state['work'] . '/database.sql';
        // Cut back to what the saved state covers, in case a request failed half-way through a batch.
        clearstatcache(true, $file);
        if (isset($state['sqlBytes']) && filesize($file) !== $state['sqlBytes']) {
            $fh = fopen($file, 'r+b');
            ftruncate($fh, (int) $state['sqlBytes']);
            fclose($fh);
        }
        $dump = new SqlDump($this->db, $file);
        while ($state['table'] < count($state['tables']) && microtime(true) < $deadline) {
            $next = $dump->dumpBatch($state['tables'][$state['table']], $state['offset']);
            if ($next === null) {
                $state['table']++;
                $state['offset'] = 0;
            } else {
                $state['offset'] = $next;
            }
            clearstatcache(true, $file);
            $state['sqlBytes'] = (int) filesize($file);
        }
        if ($state['table'] >= count($state['tables'])) {
            $dump->end();
            $state['step'] = 'files';
        }
        return $state;
    }

    private function files(array $state): array
    {
        $state['listed'] = FileList::build($this->contentDir, $state['work'] . '/files.txt', [$state['work']]);
        $state['step'] = 'pack';
        return $state;
    }

    /**
     * Packs waas.json, database.sql and then the listed files, from where the last request stopped.
     * `position` is where the current file's line starts in files.txt, or -1 for database.sql; a file
     * cut short by the deadline carries on from `fileOffset`. The archive is first cut back to the size
     * recorded with the state, so a request that failed half-way leaves nothing behind.
     */
    private function pack(array $state, float $deadline): array
    {
        $work = $state['work'];
        $archive = "$work/export.tar.gz";
        clearstatcache(true, $archive);
        if (is_file($archive) && filesize($archive) !== $state['packedBytes']) {
            $fh = fopen($archive, 'r+b');
            ftruncate($fh, (int) $state['packedBytes']);
            fclose($fh);
            clearstatcache(true, $archive);
        }
        $tar = new TarGz($archive);
        $tar->open();
        if (empty($state['packedBytes'])) {
            $tar->addString('waas.json', (string) file_get_contents("$work/waas.json"), time());
            $state['position'] = -1;
        }
        $list = fopen("$work/files.txt", 'rb');
        $finished = false;
        while (microtime(true) < $deadline) {
            if ($state['position'] === -1) {
                [$path, $name, $next] = ["$work/database.sql", 'database.sql', 0];
            } else {
                fseek($list, $state['position']);
                $line = fgets($list);
                if ($line === false) {
                    $finished = true;
                    break;
                }
                $rel = rtrim($line, "\n");
                [$path, $name, $next] = [$this->contentDir . '/' . $rel, 'wp-content/' . $rel, (int) ftell($list)];
            }
            $entry = $state['entry'];
            $offset = $tar->addFile($path, $name, (int) $state['fileOffset'], $deadline, $entry);
            if ($offset === null) {
                $state['position'] = $next;
                $state['fileOffset'] = 0;
                $state['entry'] = [];
            } else {
                $state['fileOffset'] = $offset;
                $state['entry'] = $entry;
                break;
            }
        }
        fclose($list);
        $tar->close();
        if ($finished) {
            $tar->finish();
            $state['step'] = 'upload';
        }
        clearstatcache(true, $archive);
        $state['packedBytes'] = (int) filesize($archive);
        if ($state['packedBytes'] > $state['maxBytes']) {
            throw new \RuntimeException(sprintf(
                'The export is already %s, more than this AnyPress store holds (%s). Choose a larger size on AnyPress and start again.',
                self::size($state['packedBytes']),
                self::size($state['maxBytes'])
            ));
        }
        return $state;
    }

    /** Where the export is, for the progress bar: a step, a percentage and a sentence. */
    public static function progress(array $state): array
    {
        $step = $state['step'] ?? 'database';
        switch ($step) {
            case 'database':
                $tables = max(1, count($state['tables']));
                return ['step' => $step, 'percent' => (int) (10 * $state['table'] / $tables), 'message' => sprintf('Exporting the database (table %d of %d)', min($state['table'] + 1, $tables), $tables)];
            case 'files':
                return ['step' => $step, 'percent' => 10, 'message' => 'Listing your files'];
            case 'pack':
                $done = $state['position'] > 0 ? $state['position'] : 0;
                $total = max(1, (int) @filesize($state['work'] . '/files.txt'));
                return ['step' => $step, 'percent' => 10 + (int) (60 * min(1, $done / $total)), 'message' => sprintf('Packing your files (%s so far)', self::size((int) $state['packedBytes']))];
            case 'upload':
                return ['step' => $step, 'percent' => 75, 'message' => sprintf('Uploading %s to AnyPress', self::size((int) $state['packedBytes']))];
            case 'start':
                return ['step' => $step, 'percent' => 95, 'message' => 'Telling AnyPress to set up your site'];
            default:
                return ['step' => $step, 'percent' => 100, 'message' => 'AnyPress is setting up your site'];
        }
    }

    public static function size(int $bytes): string
    {
        if ($bytes >= 1024 ** 3) return round($bytes / 1024 ** 3, 1) . ' GB';
        if ($bytes >= 1024 ** 2) return round($bytes / 1024 ** 2, 1) . ' MB';
        return max(1, (int) round($bytes / 1024)) . ' KB';
    }

    /** Deletes the work folder. */
    public static function remove(string $dir): void
    {
        if ($dir === '' || !is_dir($dir) || strpos(basename($dir), 'anypress-export-') !== 0) return;
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $file) {
            $file->isDir() && !$file->isLink() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
        }
        @rmdir($dir);
    }
}
