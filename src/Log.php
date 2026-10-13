<?php

declare(strict_types=1);

namespace Hushscript;

/**
 * One line per attempt: method, path, status, attempt, duration and retry delay (spec 10). Never
 * headers, query strings, bodies, titles or transcript text; the caller passes a path without a
 * query and direct-to-R2 URLs arrive as `r2:part/{n}`.
 */
final class Log
{
    public const LEVELS = ['off', 'debug'];

    private readonly bool $enabled;

    /** @var \Closure(string): void */
    private readonly \Closure $sink;

    /**
     * @param  (\Closure(string): void)|null  $sink  receives each finished line; default is standard error
     */
    public function __construct(string $level = 'off', ?\Closure $sink = null)
    {
        $this->enabled = $level === 'debug';
        $this->sink = $sink ?? static function (string $line): void {
            file_put_contents('php://stderr', $line."\n");
        };
    }

    /** A fixed line about the SDK itself; it never carries a request, URL or body. */
    public function note(string $message): void
    {
        if ($this->enabled) {
            ($this->sink)(Redact::text('hushscript '.$message));
        }
    }

    public function attempt(string $method, #[\SensitiveParameter] string $path, ?int $status, int $attempt, float $durationMs, ?float $retryDelay): void
    {
        if (! $this->enabled) {
            return;
        }
        $outcome = $status === null ? 'error' : (string) $status;
        $delay = $retryDelay === null ? '' : ' retry_in='.rtrim(rtrim(sprintf('%.3f', $retryDelay), '0'), '.').'s';
        ($this->sink)(Redact::text(sprintf('hushscript %s %s %s attempt=%d %dms%s', $method, $path, $outcome, $attempt, (int) round($durationMs), $delay)));
    }
}
