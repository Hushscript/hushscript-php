<?php

declare(strict_types=1);

namespace Hushscript\Resource;

use Hushscript\DeletedTranscript;
use Hushscript\Exception\PreflightError;
use Hushscript\Http\Body;
use Hushscript\Http\Exchange;
use Hushscript\Page;
use Hushscript\Transcribe\Shape;
use Hushscript\Transcript;
use Hushscript\TranscriptSummary;

/**
 * The account's finished transcripts (spec 6.4). Ones still processing are jobs: see `jobs()->list(['active' => true])`.
 */
final class Transcripts
{
    public function __construct(private readonly Exchange $exchange)
    {
    }

    /**
     * Every finished transcript, newest first, fetching pages as it goes. `page_size` is 1 to 100 (the API
     * clamps it there) and defaults to 50. The options are checked here, before any request, not when the
     * generator is first used.
     *
     * @param  array{page_size?: int|null}  $options
     * @return \Generator<int, TranscriptSummary>
     *
     * @throws \Hushscript\Exception\HushscriptException
     */
    public function list(array $options = []): \Generator
    {
        $size = self::pageSize($options);

        return Paginate::walk(fn (?string $cursor): Page => $this->summaries($cursor, $size));
    }

    /**
     * One page of `list()`; `cursor` (a string) is a previous page's `nextCursor`, `page_size` a whole number of 1 or more.
     *
     * @param  array<string, mixed>  $options
     * @return Page<TranscriptSummary>
     *
     * @throws \Hushscript\Exception\HushscriptException
     */
    public function page(array $options = []): Page
    {
        $cursor = $options['cursor'] ?? null;
        $size = self::pageSize($options, ['cursor']);
        if ($cursor !== null && ! is_string($cursor)) {
            throw new PreflightError('cursor must be a string.', 'invalid_option');
        }

        return $this->summaries($cursor, $size);
    }

    /** @throws \Hushscript\Exception\HushscriptException */
    public function get(string $id): Transcript
    {
        $segment = Shape::segment($id, 'transcript id');

        return $this->exchange->fetch('GET', '/v1/transcripts/'.$segment, [], null, static fn (#[\SensitiveParameter] Body $body): Transcript => Transcript::fromApi($body));
    }

    /**
     * The deletion records, by upload time, newest first.
     *
     * @return \Generator<int, DeletedTranscript>
     */
    public function deleted(): \Generator
    {
        return Paginate::walk(fn (?string $cursor): Page => $this->exchange->fetch(
            'GET',
            '/v1/transcripts/deleted',
            self::query($cursor, null),
            null,
            static fn (#[\SensitiveParameter] Body $body): Page => new Page(array_map(DeletedTranscript::fromApi(...), $body->list('deleted')), $body->nullableString('next_cursor')),
        ));
    }

    /** @return Page<TranscriptSummary> */
    private function summaries(?string $cursor, ?int $size): Page
    {
        return $this->exchange->fetch(
            'GET',
            '/v1/transcripts',
            self::query($cursor, $size),
            null,
            static fn (#[\SensitiveParameter] Body $body): Page => new Page(array_map(TranscriptSummary::fromApi(...), $body->list('transcripts')), $body->nullableString('next_cursor')),
        );
    }

    /**
     * @param  array<string, mixed>  $options
     * @param  list<string>  $also
     */
    private static function pageSize(array $options, array $also = []): ?int
    {
        foreach (array_keys($options) as $name) {
            if ($name !== 'page_size' && ! in_array($name, $also, true)) {
                throw new PreflightError('Unknown option "'.$name.'".', 'invalid_option');
            }
        }
        $size = $options['page_size'] ?? null;
        if ($size !== null && (! is_int($size) || $size < 1)) {
            throw new PreflightError('page_size must be a whole number of 1 or more.', 'invalid_option');
        }

        return $size;
    }

    /** @return array<string, int|string> */
    private static function query(?string $cursor, ?int $size): array
    {
        $query = [];
        if ($size !== null) {
            $query['limit'] = $size;
        }
        if ($cursor !== null) {
            $query['cursor'] = $cursor;
        }

        return $query;
    }
}
