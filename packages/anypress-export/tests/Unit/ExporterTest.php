<?php

namespace Tests\Unit;

use AnyPressExport\Api;
use AnyPressExport\ApiError;
use AnyPressExport\Exporter;
use AnyPressExport\ImportCode;
use PHPUnit\Framework\TestCase;

/** Answers like the AnyPress API and keeps what the export sent. */
final class FakeApi extends Api
{
    /** @var array<int, array> */
    public $calls = [];
    /** @var string|null */
    public $uploaded;
    /** @var int */
    public $maxBytes = 1073741824;

    /** @var int[] HTTP statuses the next PUTs fail with */
    public $putFailures = [];
    /** @var array<int, array{int, string}> errors the next start calls fail with */
    public $startFailures = [];
    /** @var string */
    public $status = 'waiting';
    /** @var int */
    public $uploads = 0;

    public function put(string $url, string $file, ?callable $heartbeat = null): void
    {
        $this->calls[] = ['put', $url];
        if ($heartbeat) $heartbeat();
        if ($this->putFailures) {
            $status = array_shift($this->putFailures);
            throw new ApiError($status === 403 ? 'The upload link has expired.' : "Uploading the export failed (HTTP $status).", $status);
        }
        $this->uploaded = (string) file_get_contents($file);
    }

    protected function post(string $path, array $body): array
    {
        $this->calls[] = [$path, $body];
        if ($path === 'upload') return ['uploadUrl' => 'https://bucket.example/imports/' . ++$this->uploads, 'maxBytes' => $this->maxBytes, 'store' => ['name' => 'Shop', 'size' => 'small', 'domain' => 'shop.anypress.site']];
        if ($path === 'start') {
            if ($this->startFailures) {
                [$status, $message] = array_shift($this->startFailures);
                throw new ApiError($message, $status);
            }
            $this->status = 'moving';
            return ['status' => 'moving'];
        }
        return ['status' => $this->status];
    }
}

final class ExporterTest extends TestCase
{
    /** @var string */
    private $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/anypress-export-test-' . getmypid() . '-' . mt_rand();
        mkdir("$this->dir/content/plugins/shop", 0777, true);
        mkdir("$this->dir/content/uploads", 0777, true);
        mkdir("$this->dir/out", 0777, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    private static function code(): ImportCode
    {
        return ImportCode::parse('AP1.' . rtrim(strtr(base64_encode(json_encode(['u' => 'https://api.anypress.io', 't' => 'tok'])), '+/', '-_'), '='));
    }

    public function testMovesTheSiteInShortStepsAndTheArchiveExtractsWithTar(): void
    {
        $content = "$this->dir/content";
        file_put_contents("$content/plugins/shop/shop.php", '<?php // shop');
        $big = random_bytes(3 * 1048576 + 17);
        file_put_contents("$content/uploads/video.bin", $big);
        $long = str_repeat('a-very-long-folder-name/', 6) . 'file-with-a-long-name.txt';
        mkdir(dirname("$content/uploads/$long"), 0777, true);
        file_put_contents("$content/uploads/$long", 'long');
        symlink('/srv/shared/media', "$content/uploads/shared");
        $db = new FakeDatabase();
        $db->tables['wpx_options'] = ['columns' => ['option_id' => 'bigint', 'option_name' => 'varchar(191)'], 'rows' => [['1', 'siteurl']]];
        $db->tables['wpx_log'] = ['columns' => ['message' => 'text'], 'rows' => [['a'], ['b'], ['c']], 'key' => [], 'avg' => 1000];
        $db->behind['otherTables'] = ['legacy_orders'];
        $db->behind['triggers'] = ['wpx_audit'];

        $api = new FakeApi(self::code());
        $exporter = new Exporter($db, $content);
        $preflight = ['tablePrefix' => 'wpx_', 'configConstants' => ['GF_LICENSE_KEY'], 'blockers' => [], 'warnings' => []];
        $state = $exporter->begin(self::code(), $api, "$content/uploads", ['home' => 'https://old.example', 'tablePrefix' => 'wpx_', 'wordPressVersion' => '6.6', 'phpVersion' => '8.1', 'name' => 'Shop', 'preflight' => $preflight]);
        $this->assertSame('preflight', $state['step']);
        $this->assertStringStartsWith("$content/uploads/anypress-export-", $state['work']);
        $work = $state['work'];

        $runs = 0;
        $split = false;
        $beats = 0;
        while ($state['step'] !== 'moving') {
            $state = $exporter->run($state, $api, 0.001, function () use (&$beats) {
                $beats++;
            });
            $state = json_decode(json_encode($state), true); // as an option would keep it
            if ($state['step'] === 'pack' && $state['fileOffset'] > 0) $split = true;
            $this->assertLessThan(10000, ++$runs);
        }
        $this->assertTrue($split, 'a large file was packed over several steps');
        $this->assertSame(1, $beats, 'the upload kept its lock alive');
        $this->assertDirectoryDoesNotExist($work, 'the work folder is removed once started');

        [$put, $start] = array_slice($api->calls, -2);
        $this->assertSame(['put', 'https://bucket.example/imports/1'], $put);
        $this->assertSame('start', $start[0]);
        $this->assertEquals(['sizeBytes' => strlen($api->uploaded), 'home' => 'https://old.example', 'wordPressVersion' => '6.6', 'phpVersion' => '8.1', 'tablePrefix' => 'wpx_'], $start[1]['source']);

        file_put_contents("$this->dir/export.tar.gz", $api->uploaded);
        exec('tar -xzf ' . escapeshellarg("$this->dir/export.tar.gz") . ' -C ' . escapeshellarg("$this->dir/out") . ' 2>&1', $output, $code);
        $this->assertSame(0, $code, implode("\n", $output));
        $out = "$this->dir/out";
        $this->assertSame('<?php // shop', file_get_contents("$out/wp-content/plugins/shop/shop.php"));
        $this->assertSame($big, file_get_contents("$out/wp-content/uploads/video.bin"));
        $this->assertSame('long', file_get_contents("$out/wp-content/uploads/$long"));
        $site = json_decode(file_get_contents("$out/waas.json"), true);
        $this->assertSame('wpx_', $site['tablePrefix']);
        // Row counts for the import to compare, and the preflight report.
        $this->assertSame(['wpx_log' => 3, 'wpx_options' => 1], $site['tables']);
        $report = $site['preflight'];
        $this->assertSame(['legacy_orders'], $report['otherTables']);
        $this->assertSame(['wpx_audit'], $report['triggers']);
        $this->assertSame(3000, $report['databaseBytes']);
        $this->assertSame(1, $report['files']['symlinkCount']);
        $this->assertSame([['path' => 'uploads/shared', 'target' => '/srv/shared/media']], $report['files']['symlinks']);
        $this->assertSame(strlen($big) + 4, $report['files']['uploadsBytes']);
        $this->assertTrue($report['files']['complete']);
        $this->assertSame(strlen($big) + 4 + 6000, $report['disk']['need']);
        $ids = array_column($report['notes'], 'id');
        $this->assertContains('behind', $ids);
        $this->assertContains('constants', $ids);
        $this->assertSame($report['notes'], Exporter::progress($state)['notes']);
        $this->assertStringContainsString("INSERT INTO `wpx_options` (`option_id`, `option_name`) VALUES\n('1','siteurl');", file_get_contents("$out/database.sql"));
        $this->assertFileDoesNotExist("$out/wp-content/uploads/" . basename($work) . '/database.sql');
    }

    public function testStopsWhenTheExportOutgrowsTheStore(): void
    {
        file_put_contents("$this->dir/content/uploads/big.bin", random_bytes(200000));
        $api = new FakeApi(self::code());
        $api->maxBytes = 100000;
        $exporter = new Exporter(new FakeDatabase(), "$this->dir/content");
        $state = $exporter->begin(self::code(), $api, "$this->dir/content/uploads", []);
        $this->expectExceptionMessage('more than this AnyPress store holds');
        for ($i = 0; $i < 100; $i++) $state = $exporter->run($state, $api, 5);
    }

    public function testCarriesOnCleanlyAfterARequestDiedHalfWay(): void
    {
        $big = random_bytes(3 * 1048576);
        file_put_contents("$this->dir/content/uploads/big.bin", $big);
        $api = new FakeApi(self::code());
        $exporter = new Exporter(new FakeDatabase(), "$this->dir/content");
        $state = $exporter->begin(self::code(), $api, "$this->dir/content/uploads", []);
        while (!($state['step'] === 'pack' && $state['packedBytes'] > 0)) $state = $exporter->run($state, $api, 0.001);
        $this->assertSame('pack', $state['step']);
        // A request that wrote part of the next step and died before its state was saved.
        file_put_contents($state['work'] . '/export.tar.gz', 'half-written garbage', FILE_APPEND);
        while ($state['step'] !== 'moving') $state = $exporter->run($state, $api, 0.001);
        file_put_contents("$this->dir/export.tar.gz", $api->uploaded);
        exec('tar -xzf ' . escapeshellarg("$this->dir/export.tar.gz") . ' -C ' . escapeshellarg("$this->dir/out") . ' 2>&1', $output, $code);
        $this->assertSame(0, $code, implode("\n", $output));
        $this->assertSame($big, file_get_contents("$this->dir/out/wp-content/uploads/big.bin"));
    }

    public function testAnExpiredUploadLinkIsRenewedOnce(): void
    {
        file_put_contents("$this->dir/content/uploads/a.txt", 'a');
        $api = new FakeApi(self::code());
        $api->putFailures = [403];
        $exporter = new Exporter(new FakeDatabase(), "$this->dir/content");
        $state = $exporter->begin(self::code(), $api, "$this->dir/content/uploads", []);
        while ($state['step'] !== 'moving') $state = $exporter->run($state, $api, 5);
        $puts = array_values(array_filter($api->calls, function ($call) {
            return $call[0] === 'put';
        }));
        $this->assertSame([['put', 'https://bucket.example/imports/1'], ['put', 'https://bucket.example/imports/2']], $puts);
        $this->assertNotNull($api->uploaded);
    }

    public function testAnUploadRefusedTwiceTellsThePersonToStartAgain(): void
    {
        $api = new FakeApi(self::code());
        $api->putFailures = [403, 403];
        $exporter = new Exporter(new FakeDatabase(), "$this->dir/content");
        $state = $exporter->begin(self::code(), $api, "$this->dir/content/uploads", []);
        $this->expectExceptionMessage('start again with a new code');
        while ($state['step'] !== 'moving') $state = $exporter->run($state, $api, 5);
    }

    public function testAStartThatAlreadyHappenedCountsAsStarted(): void
    {
        $api = new FakeApi(self::code());
        $exporter = new Exporter(new FakeDatabase(), "$this->dir/content");
        $state = $exporter->begin(self::code(), $api, "$this->dir/content/uploads", []);
        // An earlier request started the move and died before it saved the state.
        $api->status = 'moving';
        $api->startFailures = [[409, 'This site is already being moved in.']];
        while ($state['step'] !== 'moving') $state = $exporter->run($state, $api, 5);
        $this->assertSame(['start', 'status'], array_slice(array_column($api->calls, 0), -2));
        $this->assertSame('moving', $state['step']);
        $this->assertDirectoryDoesNotExist($state['work']);
    }

    public function testAConflictWhileTheStoreIsNotMovingIsAnError(): void
    {
        $api = new FakeApi(self::code());
        $exporter = new Exporter(new FakeDatabase(), "$this->dir/content");
        $state = $exporter->begin(self::code(), $api, "$this->dir/content/uploads", []);
        $api->status = 'failed';
        $api->startFailures = [[409, 'This site is already being moved in.']];
        $this->expectExceptionMessage('already being moved in');
        for ($i = 0; $i < 100; $i++) $state = $exporter->run($state, $api, 5);
    }

    public function testAFileDeletedAfterListingIsLeftOutAndReported(): void
    {
        file_put_contents("$this->dir/content/uploads/kept.txt", 'kept');
        file_put_contents("$this->dir/content/uploads/gone.txt", 'gone');
        $api = new FakeApi(self::code());
        $exporter = new Exporter(new FakeDatabase(), "$this->dir/content");
        $state = $exporter->begin(self::code(), $api, "$this->dir/content/uploads", []);
        while ($state['step'] !== 'database') $state = $exporter->run($state, $api, 0.000001);
        unlink("$this->dir/content/uploads/gone.txt");
        while ($state['step'] !== 'moving') $state = $exporter->run($state, $api, 5);

        $this->assertSame(['uploads/gone.txt'], $state['skipped']);
        $notes = Exporter::progress($state)['notes'];
        $this->assertSame('changed', end($notes)['id']);
        $this->assertSame(['uploads/gone.txt'], end($notes)['items']);
        file_put_contents("$this->dir/export.tar.gz", $api->uploaded);
        exec('tar -xzf ' . escapeshellarg("$this->dir/export.tar.gz") . ' -C ' . escapeshellarg("$this->dir/out") . ' 2>&1', $output, $code);
        $this->assertSame(0, $code, implode("\n", $output));
        $this->assertSame('kept', file_get_contents("$this->dir/out/wp-content/uploads/kept.txt"));
        $this->assertFileDoesNotExist("$this->dir/out/wp-content/uploads/gone.txt");
    }

    public function testAnExportBegunByAnOlderVersionAsksToStartAgain(): void
    {
        $api = new FakeApi(self::code());
        $exporter = new Exporter(new FakeDatabase(), "$this->dir/content");
        $this->assertSame('moving', $exporter->run(['step' => 'moving'], $api, 1)['step'], 'a move already handed over is unaffected');
        $this->expectExceptionMessage('start again with the same code');
        $exporter->run(['step' => 'database', 'table' => 0, 'offset' => 0], $api, 1);
    }

    public function testCleanupRemovesOnlyAbandonedWorkFolders(): void
    {
        $base = "$this->dir/content/uploads";
        $old = "$base/anypress-export-0123456789abcdef";
        $fresh = "$base/anypress-export-fedcba9876543210";
        $other = "$base/anypress-export-notes";
        foreach ([$old, $fresh, $other] as $dir) {
            mkdir($dir);
            file_put_contents("$dir/database.sql", 'x');
        }
        $week = 7 * 86400;
        foreach ([$old, "$old/database.sql", $other, "$other/database.sql"] as $path) touch($path, time() - $week - 60);
        $this->assertSame([$old], Exporter::cleanup($base, $week));
        $this->assertDirectoryDoesNotExist($old);
        $this->assertDirectoryExists($fresh);
        $this->assertDirectoryExists($other, 'only folders the export names itself are removed');
        $this->assertSame([$fresh], Exporter::cleanup($base, 0), 'on deactivation every work folder goes');
    }
}
