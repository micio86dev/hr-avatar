<?php

namespace Tests\Support;

/**
 * A request body that can be read once, in pieces of a chosen size, and never rewound: what a request's input is
 * once something has drained it. A test that hands this to a request proves that whoever read the body first handed
 * it on, because reading it again finds nothing.
 */
final class OneShotStream
{
    private const SCHEME = 'hra-oneshot';

    /** @var array<int, array{body: string, chunk: int}> */
    private static array $bodies = [];

    private static int $next = 0;

    /** @var resource|null set by PHP when the stream is opened */
    public $context;

    private int $id = 0;

    private int $offset = 0;

    /**
     * @param  positive-int  $chunk  the most bytes a single read returns
     * @return resource
     */
    public static function open(string $body, int $chunk): mixed
    {
        if (! in_array(self::SCHEME, stream_get_wrappers(), true)) {
            stream_wrapper_register(self::SCHEME, self::class);
        }

        $id = self::$next++;
        self::$bodies[$id] = ['body' => $body, 'chunk' => $chunk];

        return fopen(self::SCHEME.'://'.$id, 'r');
    }

    public function stream_open(string $path): bool
    {
        $this->id = (int) substr($path, strlen(self::SCHEME.'://'));

        return isset(self::$bodies[$this->id]);
    }

    public function stream_read(int $count): string
    {
        ['body' => $body, 'chunk' => $chunk] = self::$bodies[$this->id];
        $piece = substr($body, $this->offset, min($count, $chunk));
        $this->offset += strlen($piece);

        return $piece;
    }

    public function stream_eof(): bool
    {
        return $this->offset >= strlen(self::$bodies[$this->id]['body']);
    }

    /** Refused: this is what makes the stream one-shot. */
    public function stream_seek(int $offset, int $whence): bool
    {
        return false;
    }

    /** @return array<string, int> */
    public function stream_stat(): array
    {
        return [];
    }

    public function stream_close(): void
    {
        unset(self::$bodies[$this->id]);
    }
}
