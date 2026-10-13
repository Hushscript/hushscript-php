<?php

declare(strict_types=1);

namespace Hushscript\Tests\Support;

/**
 * A stream wrapper over bytes held in memory, scripted to fail the way a device or a network stream
 * can. Reads are counted and capped, so a reader that loops on a stream that never ends fails
 * instead of hanging.
 *
 * Script keys: `chunk` (most bytes one read returns), `readFailsFrom` (reads from this offset return
 * false), `throwFrom` (reads from this offset throw an Error), `warnFrom` (reads from this offset raise a
 * notice), `endless` (at the end a read returns nothing and the stream never reports its end),
 * `seekFailsFrom` (seeks to this offset or later fail), `seekEnd` (false: a seek from the end
 * fails), `stat` (the mode fstat reports; null: fstat fails), `urlStat` (the mode stat reports).
 */
final class ScriptedStream
{
    public const PROTOCOL = 'hs-scripted';

    private const CAP = 10_000;

    /** @var array<string, array<string, mixed>> */
    private static array $scripts = [];

    public static int $opened = 0;

    public static int $closed = 0;

    /** @var resource|null set by PHP from the context given to fopen */
    public $context;

    private string $name = '';

    private int $position = 0;

    private int $reads = 0;

    /**
     * @param  array<string, mixed>  $script
     */
    public static function path(string $name, string $bytes, array $script = []): string
    {
        if (! in_array(self::PROTOCOL, stream_get_wrappers(), true)) {
            stream_wrapper_register(self::PROTOCOL, self::class);
        }
        self::$scripts[$name] = ['bytes' => $bytes] + $script;
        self::$opened = 0;
        self::$closed = 0;

        return self::PROTOCOL.'://'.$name;
    }

    /**
     * @param  array<string, mixed>  $script
     * @return resource
     */
    public static function open(string $name, string $bytes, array $script = [])
    {
        $handle = fopen(self::path($name, $bytes, $script), 'rb');
        if (! is_resource($handle)) {
            throw new \LogicException('The scripted stream did not open.');
        }

        return $handle;
    }

    /** @return array<string, mixed> */
    private static function script(string $path): array
    {
        return self::$scripts[substr($path, strlen(self::PROTOCOL) + 3)] ?? [];
    }

    private function bytes(): string
    {
        $bytes = self::$scripts[$this->name]['bytes'] ?? '';

        return is_string($bytes) ? $bytes : '';
    }

    private function at(string $key): bool
    {
        $from = self::$scripts[$this->name][$key] ?? null;

        return is_int($from) && $this->position >= $from;
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        $this->name = substr($path, strlen(self::PROTOCOL) + 3);
        if (! isset(self::$scripts[$this->name])) {
            return false;
        }
        self::$opened++;

        return true;
    }

    public function stream_read(int $count): string|false
    {
        if (++$this->reads > self::CAP) {
            throw new \LogicException('The reader did not stop reading a stream that had nothing more.');
        }
        if ($this->at('throwFrom')) {
            // An Error, not an Exception: getID3 turns an Exception from a read into an error entry.
            throw new \Error('The scripted read failed.');
        }
        if ($this->at('warnFrom')) {
            trigger_error('A scripted notice.', E_USER_NOTICE);
        }
        if ($this->at('readFailsFrom')) {
            return false;
        }
        $chunk = self::$scripts[$this->name]['chunk'] ?? 8192;
        $data = substr($this->bytes(), $this->position, min($count, is_int($chunk) ? $chunk : 8192));
        $this->position += strlen($data);

        return $data;
    }

    public function stream_eof(): bool
    {
        return $this->position >= strlen($this->bytes()) && (self::$scripts[$this->name]['endless'] ?? false) !== true;
    }

    public function stream_seek(int $offset, int $whence): bool
    {
        $script = self::$scripts[$this->name];
        if ($whence === SEEK_END && ($script['seekEnd'] ?? true) === false) {
            return false;
        }
        $target = match ($whence) {
            SEEK_END => strlen($this->bytes()) + $offset,
            SEEK_CUR => $this->position + $offset,
            default => $offset,
        };
        $failsFrom = $script['seekFailsFrom'] ?? null;
        if ($target < 0 || (is_int($failsFrom) && $target >= $failsFrom)) {
            return false;
        }
        $this->position = $target;

        return true;
    }

    public function stream_tell(): int
    {
        return $this->position;
    }

    /** @return array{size: int, mode: int}|false */
    public function stream_stat(): array|false
    {
        $script = self::$scripts[$this->name];
        if (array_key_exists('stat', $script) && $script['stat'] === null) {
            return false;
        }
        $mode = $script['stat'] ?? 0100644;

        return ['size' => strlen($this->bytes()), 'mode' => is_int($mode) ? $mode : 0100644];
    }

    /** @return array{size: int, mode: int}|false */
    public function url_stat(string $path, int $flags): array|false
    {
        $script = self::script($path);
        if ($script === []) {
            return false;
        }
        $mode = $script['urlStat'] ?? 0100644;
        $bytes = $script['bytes'] ?? '';

        return ['size' => is_string($bytes) ? strlen($bytes) : 0, 'mode' => is_int($mode) ? $mode : 0100644];
    }

    public function stream_close(): void
    {
        self::$closed++;
    }
}
