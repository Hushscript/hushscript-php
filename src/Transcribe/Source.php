<?php

declare(strict_types=1);

namespace Hushscript\Transcribe;

use Hushscript\Exception\HushscriptException;
use Hushscript\Exception\PreflightError;
use Hushscript\Exception\UploadError;

/**
 * A file the pipeline reads in byte ranges. Every read opens the file, seeks and loops over fread:
 * a short read is not the end of the file, and nothing keeps more than the range asked for.
 */
final class Source
{
    private function __construct(
        public readonly string $path,
        public readonly int $size,
    ) {
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

        return new self($path, $stat['size']);
    }

    /** The first bytes, for the format sniffer; fewer when the file is shorter. */
    public function head(): string
    {
        return $this->read(0, min(Sniff::HEAD_BYTES, $this->size), 'file_unreadable');
    }

    /**
     * @throws UploadError source_read_failed when the file cannot be read or ends before the range does
     */
    public function readRange(int $offset, int $length): string
    {
        return $this->read($offset, $length, 'source_read_failed');
    }

    private function read(int $offset, int $length, string $code): string
    {
        if ($length <= 0) {
            return '';
        }
        [$handle, $failure] = self::capture(fn (): mixed => fopen($this->path, 'rb'));
        if (! is_resource($handle)) {
            throw $this->readFailure($code, $failure ?? 'it cannot be opened');
        }
        try {
            if (fseek($handle, $offset) !== 0) {
                throw $this->readFailure($code, 'it cannot be positioned');
            }
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
                $data = $data === '' ? $chunk : $data.$chunk;
            }
        } finally {
            fclose($handle);
        }
        if (strlen($data) < $length && $code === 'source_read_failed') {
            throw $this->readFailure($code, 'the file ended early');
        }

        return $data;
    }

    private function readFailure(string $code, string $reason): HushscriptException
    {
        if ($code === 'file_unreadable') {
            return self::unreadable($this->path, $reason);
        }

        return new UploadError('The file could not be read while it was uploading ('.$reason.').', $code, previous: new \RuntimeException($reason));
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
