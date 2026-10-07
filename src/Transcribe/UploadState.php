<?php

declare(strict_types=1);

namespace Hushscript\Transcribe;

/**
 * Which way the parts go and what has been acknowledged. The way is one decision: undecided, then
 * direct (the first direct success) or proxied (the first part that gives up on direct), and never
 * both, and only ever from undecided. A direct success that arrives after the switch loses: its
 * part is sent through the API again (R-14b).
 */
final class UploadState
{
    public const UNDECIDED = 'undecided';

    public const DIRECT = 'direct';

    public const PROXIED = 'proxied';

    public const REFRESH = 'refresh';

    public const FALLBACK = 'fallback';

    public const EXHAUSTED = 'exhausted';

    /** @var array<int, string> */
    public array $etags = [];

    public function __construct(public string $mode)
    {
    }

    public function proxied(): bool
    {
        return $this->mode === self::PROXIED;
    }

    /** Notes a direct success; false when the upload went through the API in the meantime. */
    public function record(int $n, string $etag): bool
    {
        if ($this->mode === self::PROXIED) {
            return false;
        }
        $this->mode = self::DIRECT;
        $this->etags[$n] = $etag;

        return true;
    }

    /**
     * What a part does after its direct PUT failed even with its retries.
     *
     * An R2 403 means an expired URL: refresh it, once. Otherwise, while no direct PUT has succeeded,
     * every remaining part goes through the API (the app's rule). After a success the part keeps
     * trying direct: its retries, then a re-fetched URL and the retries again (spec 8.4, R8), then
     * `parts_exhausted`.
     *
     * @return self::REFRESH|self::FALLBACK|self::EXHAUSTED
     */
    public function decide(PartRun $run, ?int $status): string
    {
        if ($this->mode === self::PROXIED) {
            return self::FALLBACK;
        }
        if ($status === 403 && $run->refreshes < 1) {
            $run->refreshes++;

            return self::REFRESH;
        }
        if ($this->mode === self::UNDECIDED && $run->rounds === 0) {
            $this->mode = self::PROXIED;

            return self::FALLBACK;
        }
        $run->rounds++;

        return $run->rounds === 1 ? self::REFRESH : self::EXHAUSTED;
    }

    /**
     * The body of `complete`: the sorted parts of a direct upload, none for a proxied one.
     *
     * @return array{parts: list<array{n: int, etag: string}>}|null
     */
    public function partsBody(): ?array
    {
        if ($this->mode === self::PROXIED) {
            return null;
        }
        ksort($this->etags);
        $parts = [];
        foreach ($this->etags as $n => $etag) {
            $parts[] = ['n' => $n, 'etag' => $etag];
        }

        return ['parts' => $parts];
    }
}
