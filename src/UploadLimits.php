<?php

declare(strict_types=1);

namespace Hushscript;

use Hushscript\Http\Body;

/** The caller's upload admission limits. A key the API adds later is in `toJson()`. */
final readonly class UploadLimits
{
    use RawBody;

    /**
     * @param  int  $maxDailySeconds  the daily transcription cap
     * @param  int  $maxActiveJobs  the most pipeline jobs in flight at once
     * @param  int  $activeJobs  pipeline jobs in flight now
     * @param  int  $usedTodaySeconds  billable seconds used today
     */
    private function __construct(
        public int $maxDailySeconds,
        public int $maxActiveJobs,
        public int $activeJobs,
        public int $usedTodaySeconds,
        /** @var array<mixed> */
        #[\SensitiveParameter] private array $raw,
    ) {
    }

    /** @internal */
    public static function fromApi(#[\SensitiveParameter] Body $limits): self
    {
        return new self($limits->count('max_daily_seconds'), $limits->count('max_active_jobs'), $limits->count('active_jobs'), $limits->count('used_today_seconds'), $limits->raw());
    }
}
