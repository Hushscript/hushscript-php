<?php

declare(strict_types=1);

namespace Hushscript\Transcribe;

use Hushscript\Exception\HushscriptException;
use Hushscript\Exception\PreflightError;
use Hushscript\Exception\UploadError;

/**
 * A file or a stream the pipeline reads in byte ranges. A path is opened for every read and a seekable
 * stream is sought, both looping over fread: a short read is not the end of the input, and nothing keeps
 * more than the range asked for. A stream that cannot seek is read once, in order, with at most one part
 * (and the sniffed head) held. The SDK never closes a stream it was given.
 */
final class Source
{
    public const NEEDS_SIZE = 'A stream that cannot seek needs `size` (its length in bytes) and `duration_seconds`.';

    private int $position = 0;

    private string $head = '';

    private bool $headTaken = false;

    /**
     * @param  resource|null  $stream
     */
    private function __construct(
        public readonly ?string $path,
        private $stream,
        public readonly int $size,
        public readonly bool $sequential,
    ) {
    }

    /**
     * @phpstan-assert-if-true resource $file
     */
    public static function isStream(mixed $file): bool
    {
        return is_resource($file) && get_resource_type($file) === 'stream';
    }

    /**
     * A stream the caller owns. A seekable one is measured (a `$size` given stands in for the measure
     * and is checked when the bytes are read); one that cannot seek needs `$size`, or the `$defaultSize`
     * a resume knows from its token.
     *
     * @param  resource  $stream
     *
     * @throws PreflightError invalid_option when a stream that cannot seek has no size; file_unreadable when a seekable one cannot be measured
     */
    public static function stream($stream, ?int $size, ?int $defaultSize = null): self
    {
        if ((stream_get_meta_data($stream)['seekable']) === true) {
            return new self(null, $stream, $size ?? self::measure($stream), false);
        }
        $size ??= $defaultSize;
        if ($size === null) {
            throw new PreflightError(self::NEEDS_SIZE, 'invalid_option');
        }

        return new self(null, $stream, $size, true);
    }

    /**
     * @param  resource  $stream
     */
    private static function measure($stream): int
    {
        [$moved] = self::capture(static fn (): mixed => fseek($stream, 0, SEEK_END));
        $end = $moved === 0 ? ftell($stream) : false;
        if ($end === false) {
            throw new PreflightError('The stream cannot be read: it cannot be measured.', 'file_unreadable', previous: new \RuntimeException('it cannot be measured'));
        }

        return $end;
    }

    /**
     * The size of a path without keeping it open; null when it cannot be sized, in which case the
     * error is raised later, when the file is opened (a resume only needs the size early).
     */
    public static function sizeOf(string $path): ?int
    {
        if ($path === '' || str_contains($path, "\0")) {
            return null;
        }
        /** @var array{mode: int, size: int}|false $stat */
        [$stat] = self::capture(static fn (): mixed => stat($path));
        if ($stat === false || ($stat['mode'] & 0170000) !== 0100000) {
            return null;
        }

        return $stat['size'];
    }

    /**
     * @throws PreflightError file_unreadable: a missing, non-regular or unopenable path, the OS error as the cause
     */
    public static function open(string $path): self
    {
        if ($path === '' || str_contains($path, "\0")) {
            throw self::unreadable($path, 'the path is empty or not valid');
        }
        // Sized by path first: opening a FIFO or a device for reading can block, so only a regular file is opened.
        /** @var array{mode: int, size: int}|false $before */
        [$before, $missing] = self::capture(static fn (): mixed => stat($path));
        if ($before === false) {
            // A path that cannot be sized does not exist or cannot be reached, so opening it cannot block: it names the OS error.
            [$opened, $why] = self::capture(static fn (): mixed => fopen($path, 'rb'));
            if (is_resource($opened)) {
                fclose($opened);
            }

            throw self::unreadable($path, $why ?? $missing ?? 'it cannot be inspected');
        }
        if (($before['mode'] & 0170000) !== 0100000) {
            throw self::unreadable($path, 'it is not a regular file');
        }
        [$handle, $failure] = self::capture(static fn (): mixed => fopen($path, 'rb'));
        if (! is_resource($handle)) {
            throw self::unreadable($path, $failure ?? 'it cannot be opened');
        }
        /** @var array{mode: int, size: int}|false $stat */
        [$stat] = self::capture(static fn (): mixed => fstat($handle));
        fclose($handle);
        if ($stat === false) {
            throw self::unreadable($path, 'it cannot be inspected');
        }
        if (($stat['mode'] & 0170000) !== 0100000) {
            throw self::unreadable($path, 'it is not a regular file');
        }

        return new self($path, null, $stat['size'], false);
    }

    /** The first bytes, for the format sniffer; fewer when the input is shorter. */
    public function head(): string
    {
        return $this->headWith('file_unreadable');
    }

    private function headWith(string $code): string
    {
        if ($this->sequential) {
            if (! $this->headTaken) {
                $this->head = $this->fill($this->input(), min(Sniff::HEAD_BYTES, $this->size), $code);
                $this->headTaken = true;
            }

            return $this->head;
        }

        return $this->read(0, min(Sniff::HEAD_BYTES, $this->size), $code);
    }

    /**
     * `$between` is called after every chunk of a stream that cannot seek, so a caller waiting on a slow
     * producer can keep a job alive.
     *
     * @param  (\Closure(): void)|null  $between
     *
     * @throws UploadError source_read_failed when the file cannot be read or ends before the range does;
     *                     stream_size_mismatch when a stream ends before the range does;
     *                     stream_not_seekable when a stream that cannot seek is asked for anything but the next range
     */
    public function readRange(int $offset, int $length, ?\Closure $between = null): string
    {
        if ($this->sequential) {
            return $this->readNext($offset, $length, $between);
        }

        return $this->read($offset, $length, 'source_read_failed');
    }

    /**
     * Whether nothing is left past `size`: a stream that holds more than it declared is not (spec 7.3).
     * A path is always at its end; it was sized by `stat`.
     */
    public function atEnd(): bool
    {
        if ($this->stream === null) {
            return true;
        }
        if (! $this->sequential && fseek($this->stream, $this->size) !== 0) {
            throw $this->readFailure('source_read_failed', 'it cannot be positioned');
        }

        return $this->fill($this->stream, 1, 'source_read_failed') === '';
    }

    /**
     * A file the duration probe may read and close: a fresh handle on a path, a window onto a seekable
     * stream whose close leaves the stream open; null for a stream that cannot seek or a path that
     * cannot be opened.
     *
     * @return resource|null
     */
    public function probeHandle()
    {
        if ($this->sequential) {
            return null;
        }
        if ($this->stream !== null) {
            return StreamView::of($this->stream, $this->size);
        }
        [$handle] = self::capture(fn (): mixed => fopen((string) $this->path, 'rb'));

        return is_resource($handle) ? $handle : null;
    }

    /**
     * @return resource
     */
    private function input()
    {
        return $this->stream ?? throw new \LogicException('A path has no stream.');
    }

    /**
     * @param  (\Closure(): void)|null  $between
     */
    private function readNext(int $offset, int $length, ?\Closure $between): string
    {
        if ($offset !== $this->position) {
            throw new UploadError('A stream that cannot seek can only be uploaded in order, one part at a time.', 'stream_not_seekable');
        }
        $head = $this->headWith('source_read_failed');
        if ($offset >= strlen($head)) {
            $data = $this->fill($this->input(), $length, 'source_read_failed', $between);
        } else {
            $data = substr($head, $offset, $length);
            if (strlen($data) < $length) {
                $data .= $this->fill($this->input(), $length - strlen($data), 'source_read_failed', $between);
            }
        }
        $this->position += strlen($data);
        if (strlen($data) < $length) {
            throw self::sizeMismatch();
        }

        return $data;
    }

    private function read(int $offset, int $length, string $code): string
    {
        if ($length <= 0) {
            return '';
        }
        if ($this->stream !== null) {
            if (fseek($this->stream, $offset) !== 0) {
                throw $this->readFailure($code, 'it cannot be positioned');
            }
            $data = $this->fill($this->stream, $length, $code);
            if (strlen($data) < $length && $code === 'source_read_failed') {
                throw self::sizeMismatch();
            }

            return $data;
        }
        [$handle, $failure] = self::capture(fn (): mixed => fopen((string) $this->path, 'rb'));
        if (! is_resource($handle)) {
            throw $this->readFailure($code, $failure ?? 'it cannot be opened');
        }
        try {
            if (fseek($handle, $offset) !== 0) {
                throw $this->readFailure($code, 'it cannot be positioned');
            }
            $data = $this->fill($handle, $length, $code);
        } finally {
            fclose($handle);
        }
        if (strlen($data) < $length && $code === 'source_read_failed') {
            throw $this->readFailure($code, 'the file ended early');
        }

        return $data;
    }

    /**
     * Up to `$length` bytes, reading on until they are all there or the input ends: a read that
     * returns less than asked is not the end.
     *
     * @param  resource  $handle
     * @param  (\Closure(): void)|null  $between  called after each chunk that leaves the range unfinished
     */
    private function fill($handle, int $length, string $code, ?\Closure $between = null): string
    {
        $data = '';
        while (strlen($data) < $length) {
            [$chunk] = self::capture(static fn (): mixed => fread($handle, max(1, $length - strlen($data))));
            if (! is_string($chunk)) {
                throw $this->readFailure($code, 'a read failed');
            }
            if ($chunk === '') {
                if (feof($handle)) {
                    break;
                }
                throw $this->readFailure($code, 'a read returned nothing');
            }
            $data .= $chunk;
            if ($between !== null && strlen($data) < $length) {
                $between();
            }
        }

        return $data;
    }

    private function readFailure(string $code, string $reason): HushscriptException
    {
        if ($code === 'file_unreadable') {
            return $this->path === null
                ? new PreflightError('The stream cannot be read: '.$reason.'.', $code, previous: new \RuntimeException($reason))
                : self::unreadable($this->path, $reason);
        }

        return new UploadError('The '.($this->path === null ? 'stream' : 'file').' could not be read while it was uploading ('.$reason.').', $code, previous: new \RuntimeException($reason));
    }

    public static function sizeMismatch(): UploadError
    {
        return new UploadError('The stream did not hold the number of bytes that was declared.', 'stream_size_mismatch');
    }

    /**
     * @return array{mixed, string|null}  the call's value and the text of the PHP warning it raised
     */
    private static function capture(\Closure $call): array
    {
        $message = null;
        set_error_handler(static function (int $level, string $text) use (&$message): bool {
            $message = $text;

            return true;
        });
        try {
            $value = $call();
        } finally {
            restore_error_handler();
        }
        $reason = $message === null ? null : trim(substr($message, (int) strrpos($message, ': ') + (str_contains($message, ': ') ? 2 : 0)));

        return [$value, $reason];
    }

    private static function unreadable(string $path, string $reason): PreflightError
    {
        return new PreflightError(
            'The file '.$path.' cannot be read: '.$reason.'.',
            'file_unreadable',
            previous: new \RuntimeException($reason),
        );
    }
}
