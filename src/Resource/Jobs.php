<?php

declare(strict_types=1);

namespace Hushscript\Resource;

use Hushscript\Exception\PreflightError;
use Hushscript\Http\Body;
use Hushscript\Http\Exchange;
use Hushscript\Job;
use Hushscript\Page;
use Hushscript\Transcribe\Pipeline;
use Hushscript\Transcribe\Shape;

/**
 * The account's transcription jobs (spec 6.4).
 */
final class Jobs
{
    public function __construct(private readonly Exchange $exchange, private readonly Pipeline $pipeline)
    {
    }

    /**
     * Every job, newest first, fetching pages as it goes; `active` keeps only the jobs that have not finished.
     *
     * @param  array<string, mixed>  $options  `active` (a bool) is the only key
     * @return \Generator<int, Job>
     *
     * @throws \Hushscript\Exception\HushscriptException
     */
    public function list(array $options = []): \Generator
    {
        foreach (array_keys($options) as $name) {
            if ($name !== 'active') {
                throw new PreflightError('Unknown option "'.$name.'".', 'invalid_option');
            }
        }
        $active = $options['active'] ?? false;
        if (! is_bool($active)) {
            throw new PreflightError('active must be true or false.', 'invalid_option');
        }

        return Paginate::walk(fn (?string $cursor): Page => $this->exchange->fetch(
            'GET',
            '/v1/jobs',
            array_filter(['active' => $active ? 'true' : null, 'cursor' => $cursor], static fn (?string $value): bool => $value !== null),
            null,
            static fn (#[\SensitiveParameter] Body $body): Page => new Page(array_map(Job::fromApi(...), $body->list('jobs')), $body->nullableString('next_cursor')),
        ));
    }

    /** @throws \Hushscript\Exception\HushscriptException */
    public function get(string $id): Job
    {
        $segment = Shape::segment($id, 'job id');

        return $this->exchange->fetch('GET', '/v1/jobs/'.$segment, [], null, static fn (#[\SensitiveParameter] Body $body): Job => Job::fromApi($body));
    }

    /**
     * Polls until the job is `done` or `failed` (spec 8.6). A failed job is returned, not raised. A job
     * still running at `wait_timeout` (default 3600 s) raises WaitTimeoutError, which has no resume token
     * here: the job was not started by this call.
     *
     * @param  array<string, mixed>  $options  wait_timeout, on_progress
     *
     * @throws \Hushscript\Exception\HushscriptException
     */
    public function wait(string $id, array $options = []): Job
    {
        return $this->pipeline->wait($id, $options);
    }
}
