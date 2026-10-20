<?php

namespace Tests\Unit;

use AnyPressExport\Api;
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

    public function put(string $url, string $file): void
    {
        $this->uploaded = (string) file_get_contents($file);
        $this->calls[] = ['put', $url];
    }

    protected function post(string $path, array $body): array
    {
        $this->calls[] = [$path, $body];
        if ($path === 'upload') return ['uploadUrl' => 'https://bucket.example/imports/1', 'maxBytes' => $this->maxBytes, 'store' => ['name' => 'Shop', 'size' => 'small', 'domain' => 'shop.anypress.site']];
        if ($path === 'start') return ['status' => 'moving'];
        return ['status' => 'waiting'];
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
        $db = new FakeDatabase();
        $db->tables['wpx_options'] = ['columns' => ['option_id' => 'bigint', 'option_name' => 'varchar(191)'], 'rows' => [['1', 'siteurl']]];

        $api = new FakeApi(self::code());
        $exporter = new Exporter($db, $content);
        $state = $exporter->begin(self::code(), $api, "$content/uploads", ['home' => 'https://old.example', 'tablePrefix' => 'wpx_', 'wordPressVersion' => '6.6', 'phpVersion' => '8.1', 'name' => 'Shop']);
        $this->assertStringStartsWith("$content/uploads/anypress-export-", $state['work']);
        $work = $state['work'];

        $runs = 0;
        $split = false;
        while ($state['step'] !== 'moving') {
            $state = $exporter->run($state, $api, 0.001);
            $state = json_decode(json_encode($state), true); // as an option would keep it
            if ($state['step'] === 'pack' && $state['fileOffset'] > 0) $split = true;
            $this->assertLessThan(10000, ++$runs);
        }
        $this->assertTrue($split, 'a large file was packed over several steps');
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
        $this->assertSame('wpx_', json_decode(file_get_contents("$out/waas.json"), true)['tablePrefix']);
        $this->assertStringContainsString("INSERT INTO `wpx_options` VALUES\n('1','siteurl');", file_get_contents("$out/database.sql"));
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
}
