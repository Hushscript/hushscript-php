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

    public function direct(): bool
    {
        return $this->mode === self::DIRECT;
    }

    /** Notes a direct success. The caller has checked that the upload has not gone through the API. */
    public function record(int $n, string $etag): void
    {
        $this->mode = self::DIRECT;
        $this->etags[$n] = $etag;
    }

    /**
     * What a part does after its direct PUT failed even with its retries.
     *
     * An R2 403 means an expired URL, and after a direct success any failure may: the part gets one
     * fresh URL page and its retries again (spec 8.4, R8). Past that, while no direct PUT has
     * succeeded, every remaining part goes through the API (the app's rule); after a success the part
     * is `parts_exhausted`.
     *
     * @return self::REFRESH|self::FALLBACK|self::EXHAUSTED
     */
    public function decide(PartRun $run, ?int $status): string
    {
        if ($this->mode === self::PROXIED) {
            return self::FALLBACK;
        }
        if ($run->refreshes < 1 && ($status === 403 || $this->mode === self::DIRECT)) {
            $run->refreshes++;

            return self::REFRESH;
        }

        return $this->fallBack() ? self::FALLBACK : self::EXHAUSTED;
    }

    /**
     * Moves an undecided upload to the API and says so; true as well when it already went that way,
     * false when a direct PUT has succeeded, which rules the fallback out.
     */
    public function fallBack(): bool
    {
        if ($this->mode === self::DIRECT) {
            return false;
        }
        $this->mode = self::PROXIED;

        return true;
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
