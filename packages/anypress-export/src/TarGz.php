<?php

namespace AnyPressExport;

defined('ABSPATH') || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messages are not printed here; Plugin and Cli show them as JSON or CLI text.
// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fclose, WordPress.WP.AlternativeFunctions.file_system_operations_fwrite, WordPress.WP.AlternativeFunctions.file_system_operations_fread, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir, WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- builds a .tar.gz in chunks; WP_Filesystem has no streaming handles.

/**
 * Writes the export as a gzipped tar that grows over several requests. Each request appends one gzip
 * member to the file, and gzip readers treat members back to back as one stream, so the archive is
 * written once from start to end (no rewriting, as zip appends need), and a large file can be split
 * across requests at any byte. Long names use GNU tar's long-name entries.
 */
final class TarGz
{
    const BLOCK = 512;
    const READ = 1048576;

    /** @var string */
    private $file;
    /** @var resource|null */
    private $out;
    /** @var resource|false|null */
    private $deflate;

    public function __construct(string $file)
    {
        $this->file = $file;
    }

    /** Opens a new gzip member at the end of the archive. */
    public function open(): void
    {
        $out = fopen($this->file, 'ab');
        if (!$out) throw new \RuntimeException(__('Could not write the export archive.', 'anypress-export'));
        $this->out = $out;
        $this->deflate = deflate_init(ZLIB_ENCODING_GZIP, ['level' => 5]);
        if (!$this->deflate) throw new \RuntimeException(__('This server cannot compress files (zlib).', 'anypress-export'));
    }

    /** Ends the gzip member. The archive is valid up to here; finish() makes it a complete tar. */
    public function close(): void
    {
        $this->emit('', ZLIB_FINISH);
        $flushed = fflush($this->out);
        fclose($this->out);
        if (!$flushed) throw new \RuntimeException(__('Could not write the export archive. Is the disk full? Free up space on this server, then cancel and start again.', 'anypress-export'));
        $this->out = null;
        $this->deflate = null;
    }

    /** The two empty blocks that end a tar, in their own member. */
    public function finish(): void
    {
        $this->open();
        $this->emit(str_repeat("\0", self::BLOCK * 2));
        $this->close();
    }

    /** Adds a whole file from a string. */
    public function addString(string $name, string $data, int $mtime): void
    {
        $this->header($name, strlen($data), $mtime);
        $this->emit($data . $this->padding(strlen($data)));
    }

    /**
     * Adds `$path` as `$name`, or carries on with it from `$offset` (the bytes already written), until
     * `$deadline` (microtime). Returns the new offset, or null once the file is done. A file that is gone
     * or unreadable when it comes up is left out (nothing is written, and `$entry['skipped']` says so).
     * The size is fixed when the header is written: a file that shrinks or disappears after that is
     * padded with zeros and one that grows is cut, so the tar stays valid; `$entry['changed']` says so.
     *
     * @param array{size?: int, skipped?: bool, changed?: bool} $entry what the header gave, kept between calls
     */
    public function addFile(string $path, string $name, int $offset, float $deadline, array &$entry): ?int
    {
        $in = is_file($path) ? @fopen($path, 'rb') : false;
        if ($offset === 0) {
            if (!$in) {
                $entry = ['skipped' => true];
                return null;
            }
            $stat = fstat($in);
            $size = (int) ($stat['size'] ?? 0);
            $entry = ['size' => $size];
            $this->header($name, $size, (int) ($stat['mtime'] ?? 0));
        }
        $size = (int) ($entry['size'] ?? 0);
        if ($in && $offset > 0) fseek($in, $offset);
        while ($offset < $size) {
            $chunk = $in ? (string) fread($in, min(self::READ, $size - $offset)) : '';
            if ($chunk === '') {
                $chunk = str_repeat("\0", min(self::READ, $size - $offset));
                $entry['changed'] = true;
            }
            $this->emit($chunk);
            $offset += strlen($chunk);
            if ($offset < $size && microtime(true) > $deadline) {
                if ($in) fclose($in);
                return $offset;
            }
        }
        if ($in) fclose($in);
        $this->emit($this->padding($size));
        return null;
    }

    private function header(string $name, int $size, int $mtime): void
    {
        if (strlen($name) > 100) {
            // GNU long name: an entry named ././@LongLink whose data is the real name.
            $long = $name . "\0";
            $this->emit($this->block('././@LongLink', strlen($long), 0, 'L') . $long . $this->padding(strlen($long)));
            $name = substr($name, 0, 100);
        }
        $this->emit($this->block($name, $size, $mtime, '0'));
    }

    private function block(string $name, int $size, int $mtime, string $type): string
    {
        $header = str_pad($name, 100, "\0")
            . sprintf('%07o', 0644) . "\0"
            . sprintf('%07o', 0) . "\0"
            . sprintf('%07o', 0) . "\0"
            . $this->octal($size, 12)
            . sprintf('%011o', max(0, $mtime)) . "\0"
            . str_repeat(' ', 8)
            . $type
            . str_repeat("\0", 100)
            . "ustar\0" . '00'
            . str_repeat("\0", 32 + 32)
            . str_repeat("\0", 8 + 8)
            . str_repeat("\0", 155);
        $header = str_pad($header, self::BLOCK, "\0");
        $sum = 0;
        for ($i = 0; $i < self::BLOCK; $i++) $sum += ord($header[$i]);
        return substr_replace($header, sprintf('%06o', $sum) . "\0 ", 148, 8);
    }

    /** A size field: octal while it fits (8 GiB), GNU base-256 beyond. */
    private function octal(int $value, int $width): string
    {
        if ($value < 8 ** ($width - 1)) return sprintf('%0' . ($width - 1) . 'o', $value) . "\0";
        $bytes = '';
        for ($i = 0; $i < $width - 1; $i++) {
            $bytes = chr($value & 0xff) . $bytes;
            $value >>= 8;
        }
        return "\x80" . $bytes;
    }

    private function padding(int $size): string
    {
        $rest = $size % self::BLOCK;
        return $rest ? str_repeat("\0", self::BLOCK - $rest) : '';
    }

    private function emit(string $data, int $flush = ZLIB_NO_FLUSH): void
    {
        $compressed = deflate_add($this->deflate, $data, $flush);
        if ($compressed === false) throw new \RuntimeException(__('Compressing the export failed.', 'anypress-export'));
        // A short write (fewer bytes than asked, as on a nearly full disk) would leave a corrupt archive.
        if ($compressed !== '' && fwrite($this->out, $compressed) !== strlen($compressed)) {
            throw new \RuntimeException(__('Could not write the export archive. Is the disk full? Free up space on this server, then cancel and start again.', 'anypress-export'));
        }
    }
}
