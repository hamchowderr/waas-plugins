<?php

namespace Tests\Unit;

use AnyPressExport\TarGz;
use PHPUnit\Framework\TestCase;

final class TarGzTest extends TestCase
{
    public function testFailsOnAShortWriteInsteadOfLeavingACorruptArchive(): void
    {
        ShortWriteStream::register();
        ShortWriteStream::$room = 50;
        $source = (string) tempnam(sys_get_temp_dir(), 'tar');
        file_put_contents($source, random_bytes(200000));
        $tar = new TarGz('shortwrite://export.tar.gz');
        $tar->open();
        $entry = [];
        try {
            $this->expectExceptionMessage('Is the disk full?');
            $tar->addFile($source, 'wp-content/uploads/a.bin', 0, INF, $entry);
            $tar->close();
        } finally {
            unlink($source);
        }
    }

    public function testAFileGoneBeforeItsTurnIsSkippedNotZeroFilled(): void
    {
        $archive = (string) tempnam(sys_get_temp_dir(), 'tar');
        $tar = new TarGz($archive);
        $tar->open();
        $entry = [];
        $this->assertNull($tar->addFile('/nonexistent/file.txt', 'wp-content/file.txt', 0, INF, $entry));
        $this->assertSame(['skipped' => true], $entry);
        $tar->close();
        $this->assertSame('', (string) gzdecode((string) file_get_contents($archive)), 'nothing was written for it');
        unlink($archive);
    }

    public function testAFileThatShrinksIsPaddedAndMarkedChanged(): void
    {
        $archive = (string) tempnam(sys_get_temp_dir(), 'tar');
        $source = (string) tempnam(sys_get_temp_dir(), 'src');
        file_put_contents($source, str_repeat('x', 3 * 1048576));
        $tar = new TarGz($archive);
        $tar->open();
        $entry = [];
        $offset = $tar->addFile($source, 'wp-content/log.txt', 0, microtime(true) - 1, $entry);
        $this->assertSame(1048576, $offset);
        file_put_contents($source, 'short now');
        $this->assertNull($tar->addFile($source, 'wp-content/log.txt', $offset, INF, $entry));
        $this->assertTrue($entry['changed']);
        $tar->close();
        unlink($archive);
        unlink($source);
    }
}
