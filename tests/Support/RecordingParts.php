<?php

declare(strict_types=1);

namespace Hushscript\Tests\Support;

use GuzzleHttp\Promise\PromiseInterface;
use Hushscript\Clock;
use Hushscript\Transcribe\PartTransport;
use Psr\Http\Message\RequestInterface;

/** A scripted part transport that also keeps the bytes of every part request, in the order they were sent. */
final class RecordingParts implements PartTransport
{
    public readonly FakePartTransport $inner;

    /** @var list<array{url: string, body: string}> */
    public array $requests = [];

    /**
     * @param  \Closure(array{method: string, url: string, authorization: string, length: int, headers: array<mixed>}, int): array{\Psr\Http\Message\ResponseInterface|\Hushscript\Transcribe\PartFailure, float}|null  $script
     */
    public function __construct(Clock $clock, ?\Closure $script = null)
    {
        $this->inner = new FakePartTransport($clock, $script ?? static fn (): array => [PipelineHarness::stored(), 0.0]);
    }

    #[\Override]
    public function send(#[\SensitiveParameter] RequestInterface $request, float $timeout): PromiseInterface
    {
        $this->requests[] = ['url' => (string) $request->getUri(), 'body' => (string) $request->getBody()];

        return $this->inner->send($request, $timeout);
    }

    #[\Override]
    public function tick(): void
    {
        $this->inner->tick();
    }

    /** The bytes the parts carried, joined in part order: the last request sent for each part. */
    public function joined(): string
    {
        $byPart = [];
        foreach ($this->requests as $request) {
            preg_match('~/(\d+)(?:\?|$)~', $request['url'], $match);
            $byPart[(int) ($match[1] ?? 0)] = $request['body'];
        }
        ksort($byPart);

        return implode('', $byPart);
    }
}
