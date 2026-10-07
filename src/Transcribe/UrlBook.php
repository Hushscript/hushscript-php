<?php

declare(strict_types=1);

namespace Hushscript\Transcribe;

use Hushscript\Clock;

/**
 * The presigned URLs fetched so far. A URL older than 23 h counts as missing (spec 8.4).
 */
final class UrlBook
{
    public const MAX_AGE = 23 * 3600.0;

    /** @var array<int, array{string, float}> */
    private array $urls = [];

    public ?int $nextStart = null;

    public function __construct(private readonly Clock $clock)
    {
    }

    /** @param array<int, string> $urls */
    public function store(#[\SensitiveParameter] array $urls, ?int $nextPart): void
    {
        $now = $this->clock->now();
        foreach ($urls as $n => $url) {
            $this->urls[$n] = [$url, $now];
        }
        $this->nextStart = $nextPart;
    }

    /** The URL as last fetched, however old. */
    public function current(int $n): ?string
    {
        return ($this->urls[$n] ?? [null])[0];
    }

    public function get(int $n): ?string
    {
        $entry = $this->urls[$n] ?? null;

        return $entry === null || $this->clock->now() - $entry[1] > self::MAX_AGE ? null : $entry[0];
    }

    public function __serialize(): array
    {
        throw new \LogicException('UrlBook cannot be serialized.');
    }

    /** @param array<mixed> $data */
    public function __unserialize(array $data): void
    {
        throw new \LogicException('UrlBook cannot be unserialized.');
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['urls' => '…[redacted]'];
    }
}
