<?php

declare(strict_types=1);

namespace Hushscript\Transcribe;

/**
 * A read-only window onto a stream the caller owns, for the duration probe: the readers it feeds
 * (getID3 among them) close the handle they are given, and closing the window leaves the stream open.
 * The window reports the declared size as its own, so a reader never has to ask the stream.
 */
final class StreamView
{
    private const PROTOCOL = 'hushscript-view';

    /** @var resource|null set by PHP from the context given to fopen */
    public $context;

    /** @var resource|null */
    private $inner;

    private int $size = 0;

    /**
     * @param  resource  $stream  a seekable stream
     * @return resource|null
     */
    public static function of($stream, int $size)
    {
        if (! in_array(self::PROTOCOL, stream_get_wrappers(), true) && ! stream_wrapper_register(self::PROTOCOL, self::class)) {
            return null;
        }
        $context = stream_context_create([self::PROTOCOL => ['stream' => $stream, 'size' => $size]]);
        $view = @fopen(self::PROTOCOL.'://stream', 'rb', false, $context);

        return is_resource($view) ? $view : null;
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        $given = is_resource($this->context) ? stream_context_get_options($this->context)[self::PROTOCOL] ?? null : null;
        $stream = is_array($given) ? ($given['stream'] ?? null) : null;
        $size = is_array($given) ? ($given['size'] ?? null) : null;
        if (! Source::isStream($stream) || ! is_int($size)) {
            return false;
        }
        $this->inner = $stream;
        $this->size = $size;

        return fseek($stream, 0) === 0;
    }

    public function stream_read(int $count): string|false
    {
        return $this->inner === null ? false : fread($this->inner, max(1, $count));
    }

    public function stream_eof(): bool
    {
        return $this->inner === null || feof($this->inner);
    }

    public function stream_seek(int $offset, int $whence): bool
    {
        if ($this->inner === null) {
            return false;
        }
        if ($whence === SEEK_END) {
            $offset += $this->size;
            $whence = SEEK_SET;
        }

        return fseek($this->inner, $offset, $whence) === 0;
    }

    public function stream_tell(): int
    {
        return $this->inner === null ? 0 : (int) ftell($this->inner);
    }

    /** @return array{mode: int, size: int} */
    public function stream_stat(): array
    {
        return ['mode' => 0100444, 'size' => $this->size];
    }

    public function stream_close(): void
    {
        $this->inner = null;
    }
}
