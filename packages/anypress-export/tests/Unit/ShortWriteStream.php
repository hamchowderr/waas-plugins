<?php

namespace Tests\Unit;

/** A stream that takes `$room` bytes in all and then no more, like a disk that fills up. */
final class ShortWriteStream
{
    /** @var int */
    public static $room = 0;
    /** @var resource|null */
    public $context;

    public static function register(): void
    {
        if (!in_array('shortwrite', stream_get_wrappers(), true)) stream_wrapper_register('shortwrite', self::class);
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$opened): bool
    {
        return true;
    }

    public function stream_write(string $data): int
    {
        $n = min(strlen($data), self::$room);
        self::$room -= $n;
        return $n;
    }

    public function stream_flush(): bool
    {
        return true;
    }

    public function stream_close(): void
    {
    }

    public function stream_stat(): array
    {
        return [];
    }

    public function url_stat(string $path, int $flags)
    {
        return false;
    }
}
