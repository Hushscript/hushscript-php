<?php

declare(strict_types=1);

namespace Hushscript\Tests\Transcribe;

use GuzzleHttp\Promise\PromiseInterface;
use Hushscript\Credentials;
use Hushscript\Exception\ConflictError;
use Hushscript\Exception\ErrorMapper;
use Hushscript\Exception\HushscriptException;
use Hushscript\Hint;
use Hushscript\Tests\Support\FakeClock;
use Hushscript\Tests\Support\FakeHttp;
use Hushscript\Tests\Support\PipelineHarness;
use Hushscript\Tests\Support\Tokens;
use Hushscript\Transcribe\PartTransport;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

final class UnexpectedFailureTest extends TestCase
{
    /** @param \Closure(): \Throwable $make */
    private static function throwingTransport(\Closure $make): PartTransport
    {
        return new class ($make) implements PartTransport {
            /** @param \Closure(): \Throwable $make */
            public function __construct(private readonly \Closure $make)
            {
            }

            #[\Override]
            public function send(RequestInterface $request, float $timeout): PromiseInterface
            {
                throw ($this->make)();
            }

            #[\Override]
            public function tick(): void
            {
            }
        };
    }

    public function testAnythingThatIsNotAnSdkErrorAfterTheSendBecomesUnexpectedErrorWithTheKeyAndToken(): void
    {
        $harness = new PipelineHarness(
            new FakeClock(),
            transport: self::throwingTransport(static fn (): \Throwable => new \TypeError('boom '.Tokens::TEST)),
        );

        try {
            $harness->pipeline->transcribe($harness->path(), []);
            self::fail('expected an unexpected_error');
        } catch (HushscriptException $error) {
            self::assertSame('unexpected_error', $error->errorCode);
            self::assertNotNull($error->idempotencyKey);
            self::assertNotNull($error->resumeToken);
            self::assertNotNull($error->getPrevious());
            self::assertStringContainsString('TypeError', $error->getPrevious()->getMessage());
            self::assertStringNotContainsString(Tokens::TEST, $error->getPrevious()->getMessage());
            self::assertStringNotContainsString($error->resumeToken, print_r(\Hushscript\Tests\Support\SdkFrames::only($error)->getPrevious(), true));
        }
    }

    public function testACallbackThatThrowsAnSdkErrorPropagatesTheSameObjectUntouched(): void
    {
        $harness = new PipelineHarness();
        $mine = new ConflictError('mine', 'wrong_state');

        try {
            $harness->pipeline->transcribe($harness->path(), ['on_progress' => static function () use ($mine): never {
                throw $mine;
            }]);
            self::fail('expected the callback error');
        } catch (\Throwable $error) {
            self::assertSame($mine, $error);
            self::assertNull($mine->idempotencyKey);
            self::assertNull($mine->resumeToken);
        }
    }

    public function testACallbackThatThrowsAnythingElsePropagatesTheSameObject(): void
    {
        $harness = new PipelineHarness();
        $mine = new \DomainException('from the caller');

        try {
            $harness->pipeline->transcribe($harness->path(), ['on_resume_token' => static function () use ($mine): never {
                throw $mine;
            }]);
            self::fail('expected the callback error');
        } catch (\Throwable $error) {
            self::assertSame($mine, $error);
        }
    }

    public function testAHugeRetryAfterHeaderIsCappedNotAnError(): void
    {
        $error = ErrorMapper::fromResponse(
            FakeHttp::json(429, ['error' => 'rate_limited', 'code' => 5000], ['Retry-After' => str_repeat('9', 400)]),
            new Credentials(Tokens::TEST),
            'GET',
            '/v1/account',
        );

        self::assertSame(9007199254740991, $error->retryAfter);
    }

    public function testNumbersBeyondAnyIntegerNeverBreakAHint(): void
    {
        foreach ([1e400, -1e400, 9.9e300, NAN, '9'.str_repeat('9', 400), PHP_INT_MAX, PHP_INT_MIN] as $number) {
            $text = Hint::render('insufficient_balance', ['needed_seconds' => $number, 'balance_seconds' => $number]);
            self::assertIsString($text);
            $text = Hint::render('rate_limited', ['retry_after_seconds' => $number]);
            self::assertIsString($text);
        }

        self::assertSame('Too many requests.', Hint::render('rate_limited', ['retry_after_seconds' => PHP_INT_MIN]));
        self::assertSame(
            'Too many requests. The SDK already waited and retried; slow down or try again in 9223372036854775807 s.',
            Hint::render('rate_limited', ['retry_after_seconds' => PHP_INT_MAX]),
        );

        $error = ErrorMapper::fromResponse(
            FakeHttp::json(402, '{"error":"insufficient_balance","code":4002,"needed_seconds":1e400,"retry_after_seconds":1e400}'),
            new Credentials(Tokens::TEST),
            'POST',
            '/v1/uploads',
        );
        self::assertNull($error->retryAfter);
    }

    /** @return iterable<string, array{mixed}> */
    public static function absentHintNumbers(): iterable
    {
        yield 'positive infinity' => [INF];
        yield 'negative infinity' => [-INF];
        yield 'NaN' => [NAN];
        yield 'beyond integer range' => [9.9e300];
        yield 'numeric string' => [str_repeat('9', 401)];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('absentHintNumbers')]
    public function testUnusableHintNumbersDropTheDependentSentence(mixed $number): void
    {
        self::assertSame("Buy a pack with the SDK's buy method or in the app.", Hint::render('insufficient_balance', ['needed_seconds' => $number, 'balance_seconds' => $number]));
        self::assertSame('Too many requests.', Hint::render('rate_limited', ['retry_after_seconds' => $number]));
    }

    /** @return iterable<string, array{string, string, list<string>}> */
    public static function throwingCallbacks(): iterable
    {
        foreach (['domain', 'sdk'] as $kind) {
            yield 'progress '.$kind => ['on_progress', $kind, ['GET /v1/account']];
            yield 'resume token '.$kind => ['on_resume_token', $kind, ['GET /v1/account', 'POST /v1/uploads']];
        }
    }

    /** @param list<string> $requests */
    #[\PHPUnit\Framework\Attributes\DataProvider('throwingCallbacks')]
    public function testCallbackErrorsReachTheCallerUnchangedAndStopRequests(string $callback, string $kind, array $requests): void
    {
        $harness = new PipelineHarness();
        $mine = $kind === 'sdk' ? new ConflictError('mine', 'wrong_state') : new \DomainException('caller error');
        try {
            $harness->pipeline->transcribe($harness->path(), [$callback => static function () use ($mine): never {
                throw $mine;
            }]);
            self::fail('expected callback exception');
        } catch (\Throwable $error) {
            self::assertSame($mine, $error);
            if ($mine instanceof HushscriptException) {
                self::assertNull($mine->idempotencyKey);
                self::assertNull($mine->resumeToken);
                self::assertNull($mine->status);
                self::assertSame('wrong_state', $mine->errorCode);
            }
        }
        self::assertSame($requests, $harness->api->lines());
        self::assertSame([], $harness->parts->sent);
    }
}
