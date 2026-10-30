<?php

namespace AnyPressExport;

defined('ABSPATH') || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messages are not printed here; Plugin and Cli show them as JSON or CLI text.
// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fclose, WordPress.WP.AlternativeFunctions.file_system_operations_fwrite, WordPress.WP.AlternativeFunctions.file_system_operations_fread, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir, WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- the export streams large files in chunks into its own temporary folder; WP_Filesystem has no streaming handles.

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
    const STEPS = ['preflight', 'files', 'database', 'pack', 'upload', 'start', 'moving'];
    /** The shape of the saved state. An export begun by an older version can't carry on with this one. */
    const FORMAT = 2;
    /** How many files that went missing or changed while packing are kept by name (all are counted). */
    const REPORT = 50;

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
     * export and closed to the web). `$site` is what waas.json tells AnyPress about the site, with what
     * WordPress knows of the preflight checks under `preflight`; the database and file checks are added
     * by the first steps.
     *
     * @param array<string, mixed> $site
     */
    public function begin(ImportCode $code, Api $api, string $workBase, array $site): array
    {
        $upload = $api->upload();
        $work = rtrim($workBase, '/') . '/anypress-export-' . bin2hex(random_bytes(8));
        if (!@mkdir($work, 0700, true)) throw new \RuntimeException(__('Could not create a work folder in the uploads folder.', 'anypress-export'));
        file_put_contents("$work/index.php", "<?php\n// Silence is golden.\n");
        file_put_contents("$work/.htaccess", "Require all denied\nDeny from all\n");
        $state = [
            'format' => self::FORMAT,
            'code' => $code->code(),
            'step' => 'preflight',
            'work' => $work,
            'uploadUrl' => (string) $upload['uploadUrl'],
            'maxBytes' => (int) $upload['maxBytes'],
            'store' => (array) ($upload['store'] ?? []),
            'site' => $site,
            'tables' => $this->db->tables(),
            'stats' => [],
            'table' => 0,
            'cursor' => [],
            'listing' => FileList::start(),
            'listBytes' => 0,
            'listed' => null,
            'position' => 0,
            'fileOffset' => 0,
            'entry' => [],
            'packedBytes' => 0,
            'skipped' => [],
            'changed' => [],
            'skippedCount' => 0,
            'startedAt' => time(),
        ];
        self::writeSite($state);
        (new SqlDump($this->db, "$work/database.sql"))->begin();
        clearstatcache(true, "$work/database.sql");
        $state['sqlBytes'] = (int) filesize("$work/database.sql");
        return $state;
    }

    /**
     * Advances the export until `$seconds` have passed or it waits on AnyPress. Returns the new state.
     * Throws with a message for the person on failure; the state is then left as it was before the step.
     * `$heartbeat` is called now and then during a long upload, so the caller can show it's still alive.
     */
    public function run(array $state, Api $api, float $seconds, ?callable $heartbeat = null): array
    {
        if (($state['format'] ?? 1) < self::FORMAT && ($state['step'] ?? '') !== 'moving') {
            throw new \RuntimeException(__('AnyPress Export was updated while it was packing this site. Choose Cancel, then start again with the same code.', 'anypress-export'));
        }
        $deadline = microtime(true) + $seconds;
        while (microtime(true) < $deadline) {
            switch ($state['step']) {
                case 'preflight':
                    $state = $this->preflight($state);
                    break;
                case 'files':
                    $state = $this->files($state, $deadline);
                    break;
                case 'database':
                    $state = $this->database($state, $deadline);
                    break;
                case 'pack':
                    $state = $this->pack($state, $deadline);
                    break;
                case 'upload':
                    $state = $this->upload($state, $api, $heartbeat);
                    break;
                case 'start':
                    $this->start($state, $api);
                    self::remove($state['work']);
                    $state['step'] = 'moving';
                    return $state;
                default:
                    return $state;
            }
        }
        return $state;
    }

    /** The database's part of the checks: what stays behind, and table sizes for the batches and the disk check. */
    private function preflight(array $state): array
    {
        $state['stats'] = $this->db->tableStats();
        $bytes = 0;
        foreach ($state['stats'] as $table => $stats) {
            if (in_array($table, $state['tables'], true)) $bytes += (int) $stats['bytes'];
        }
        $report = (array) ($state['site']['preflight'] ?? []);
        $report = $this->db->leftBehind() + ['databaseBytes' => $bytes] + $report;
        $free = function_exists('disk_free_space') ? @disk_free_space($state['work']) : false;
        $report['disk'] = ['free' => $free === false ? null : (int) $free, 'need' => 0];
        $state['site']['preflight'] = $report;
        $state['step'] = 'files';
        self::writeSite($state);
        return $state;
    }

    /** Lists wp-content into files.txt, resuming where the last request stopped. */
    private function files(array $state, float $deadline): array
    {
        $list = $state['work'] . '/files.txt';
        $out = self::reopen($list, (int) $state['listBytes']);
        try {
            $state['listing'] = FileList::step($this->contentDir, $out, $state['listing'], [$state['work']], $deadline);
            $state['listBytes'] = (int) ftell($out);
        } finally {
            fclose($out);
        }
        if (!empty($state['listing']['done'])) {
            $state['listed'] = ['files' => (int) $state['listing']['files'], 'bytes' => (int) $state['listing']['bytes']];
            $report = (array) ($state['site']['preflight'] ?? []);
            $report['files'] = Preflight::files($state['listing']);
            $report['disk']['need'] = Preflight::diskNeed((int) $state['listing']['uploadsBytes'], (int) ($report['databaseBytes'] ?? 0));
            $report['notes'] = Preflight::notes($report);
            $state['site']['preflight'] = $report;
            $state['listing'] = ['done' => true];
            $state['step'] = 'database';
            self::writeSite($state);
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
            $table = $state['tables'][$state['table']];
            $cursor = $dump->dumpBatch($table, (array) $state['cursor'], $state['stats'][$table] ?? null);
            if (!empty($cursor['done'])) {
                $state['site']['tables'][$table] = (int) $cursor['rows'];
                $state['table']++;
                $state['cursor'] = [];
            } else {
                $state['cursor'] = $cursor;
            }
            clearstatcache(true, $file);
            $state['sqlBytes'] = (int) filesize($file);
        }
        if ($state['table'] >= count($state['tables'])) {
            $dump->end();
            clearstatcache(true, $file);
            $state['sqlBytes'] = (int) filesize($file);
            $state['step'] = 'pack';
            self::writeSite($state);
        }
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
                if ($state['position'] === -1 && !empty($entry['skipped'])) {
                    throw new \RuntimeException(__('The database export went missing from the work folder. Cancel, then start again.', 'anypress-export'));
                }
                if ($state['position'] !== -1 && (!empty($entry['skipped']) || !empty($entry['changed']))) {
                    $kind = !empty($entry['skipped']) ? 'skipped' : 'changed';
                    $state['skippedCount'] = (int) ($state['skippedCount'] ?? 0) + 1;
                    if (count($state[$kind]) < self::REPORT) $state[$kind][] = substr($name, strlen('wp-content/'));
                }
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
                /* translators: 1: size of the export so far, 2: size the AnyPress store holds */
                __('The export is already %1$s, more than this AnyPress store holds (%2$s). Choose a larger size on AnyPress and start again.', 'anypress-export'),
                self::size($state['packedBytes']),
                self::size($state['maxBytes'])
            ));
        }
        return $state;
    }

    /** Uploads the archive; asks AnyPress for a new link once if the saved one has expired. */
    private function upload(array $state, Api $api, ?callable $heartbeat): array
    {
        $archive = $state['work'] . '/export.tar.gz';
        $size = (int) filesize($archive);
        if ($size > $state['maxBytes']) {
            throw new \RuntimeException(sprintf(
                /* translators: 1: size of the export, 2: size the AnyPress store holds */
                __('The export is %1$s, more than this AnyPress store holds (%2$s). Choose a larger size on AnyPress and start again.', 'anypress-export'),
                self::size($size),
                self::size($state['maxBytes'])
            ));
        }
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
    private function start(array $state, Api $api): void
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

    /** Where the export is, for the progress bar: a step, a percentage, a sentence, and notes for the owner. */
    public static function progress(array $state): array
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
                    self::size((int) $state['packedBytes'])
                )];
            case 'upload':
                $message = $state['packedBytes'] > Preflight::LARGE_BYTES
                    /* translators: %s: size of the export, such as 3 GB */
                    ? __('Uploading %s to AnyPress. If this page stops answering, the upload carries on here; for sites this big, wp anypress-export send on the command line is more reliable.', 'anypress-export')
                    /* translators: %s: size of the export, such as 120 MB */
                    : __('Uploading %s to AnyPress', 'anypress-export');
                $message = sprintf($message, self::size((int) $state['packedBytes']));
                return ['step' => $step, 'percent' => 75, 'message' => $message];
            case 'start':
                return ['step' => $step, 'percent' => 95, 'message' => __('Telling AnyPress to set up your site', 'anypress-export')];
            default:
                return ['step' => $step, 'percent' => 100, 'message' => __('AnyPress is setting up your site', 'anypress-export')];
        }
    }

    /** Writes waas.json from the state's site details. */
    private static function writeSite(array $state): void
    {
        $site = $state['site'];
        if (isset($site['tables'])) $site['tables'] = (object) $site['tables'];
        $json = (string) json_encode($site, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if (@file_put_contents($state['work'] . '/waas.json', $json) !== strlen($json)) {
            throw new \RuntimeException(__('Could not write the export. Is the disk full? Free up space on this server, then cancel and start again.', 'anypress-export'));
        }
    }

    /** Opens a file to append to, first cut back to `$bytes` (what the saved state covers). @return resource */
    private static function reopen(string $file, int $bytes)
    {
        $fh = @fopen($file, 'c+b');
        if (!$fh) throw new \RuntimeException(__('Could not write the export. Is the disk full? Free up space on this server, then cancel and start again.', 'anypress-export'));
        ftruncate($fh, $bytes);
        fseek($fh, $bytes);
        return $fh;
    }

    /**
     * Removes work folders under `$base` that nothing has touched for `$maxAge` seconds: exports that
     * were never finished or cancelled, which would otherwise keep a copy of the database in uploads.
     * Returns the folders removed.
     *
     * @return string[]
     */
    public static function cleanup(string $base, int $maxAge, ?int $now = null): array
    {
        $now = $now ?? time();
        $removed = [];
        foreach ((array) glob(rtrim($base, '/') . '/anypress-export-*', GLOB_ONLYDIR) as $dir) {
            if (!preg_match('/^anypress-export-[0-9a-f]{16}$/', basename((string) $dir)) || is_link((string) $dir)) continue;
            $newest = (int) @filemtime((string) $dir);
            foreach ((array) glob("$dir/*") as $file) $newest = max($newest, (int) @filemtime((string) $file));
            if ($now - $newest >= $maxAge) {
                self::remove((string) $dir);
                $removed[] = (string) $dir;
            }
        }
        return $removed;
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
            $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : wp_delete_file($file->getPathname());
        }
        rmdir($dir);
    }
}
