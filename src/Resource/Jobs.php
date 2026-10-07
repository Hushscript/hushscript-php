<?php

declare(strict_types=1);

namespace Hushscript\Resource;

use Hushscript\Transcribe\Pipeline;

/**
 * Jobs (spec 6.4). Until the rest of the friendly layer arrives, `wait()` is the one call.
 */
final class Jobs
{
    public function __construct(private readonly Pipeline $pipeline)
    {
    }

    /**
     * Polls until the job is done or failed. A job still running at `wait_timeout` (default 3600 s)
     * raises WaitTimeoutError, which has no resume token here: the job was not started by this call.
     *
     * @param  array<string, mixed>  $options  wait_timeout, on_progress
     * @return array<string, mixed>  the finished job, as the API sent it
     *
     * @throws \Hushscript\Exception\HushscriptException
     */
    public function wait(string $id, array $options = []): array
    {
        return $this->pipeline->wait($id, $options);
    }
}
