<?php

declare(strict_types=1);

namespace Hushscript\Tests;

use Hushscript\Exception\AbortError;
use Hushscript\Exception\ApiError;
use Hushscript\Exception\AuthenticationError;
use Hushscript\Exception\ConfigurationError;
use Hushscript\Exception\ConflictError;
use Hushscript\Exception\DailyLimitError;
use Hushscript\Exception\FileLimitError;
use Hushscript\Exception\HushscriptException;
use Hushscript\Exception\InsufficientBalanceError;
use Hushscript\Exception\JobFailedError;
use Hushscript\Exception\NetworkError;
use Hushscript\Exception\NotFoundError;
use Hushscript\Exception\OutcomeUnknownError;
use Hushscript\Exception\PaymentMethodRequiredError;
use Hushscript\Exception\PermissionDeniedError;
use Hushscript\Exception\PreflightError;
use Hushscript\Exception\RateLimitError;
use Hushscript\Exception\ResumeMismatchError;
use Hushscript\Exception\UnsupportedMediaError;
use Hushscript\Exception\UploadError;
use Hushscript\Exception\ValidationError;
use Hushscript\Exception\WaitTimeoutError;
use Hushscript\Tests\Support\Tokens;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ExceptionTest extends TestCase
{
    /** @return iterable<string, array{class-string<HushscriptException>, class-string<HushscriptException>, string}> */
    public static function tree(): iterable
    {
        yield 'config' => [ConfigurationError::class, HushscriptException::class, 'config_invalid'];
        yield 'preflight' => [PreflightError::class, HushscriptException::class, 'preflight_failed'];
        yield 'unsupported media' => [UnsupportedMediaError::class, PreflightError::class, 'unsupported_media'];
        yield 'file limit' => [FileLimitError::class, PreflightError::class, 'size_out_of_range'];
        yield 'daily limit' => [DailyLimitError::class, PreflightError::class, 'daily_limit'];
        yield 'resume mismatch' => [ResumeMismatchError::class, PreflightError::class, 'resume_file_mismatch'];
        yield 'api' => [ApiError::class, HushscriptException::class, 'api_error'];
        yield 'auth' => [AuthenticationError::class, ApiError::class, 'unauthorized'];
        yield 'permission' => [PermissionDeniedError::class, ApiError::class, 'pat_forbidden'];
        yield 'balance' => [InsufficientBalanceError::class, ApiError::class, 'insufficient_balance'];
        yield 'not found' => [NotFoundError::class, ApiError::class, 'not_found'];
        yield 'conflict' => [ConflictError::class, ApiError::class, 'wrong_state'];
        yield 'validation' => [ValidationError::class, ApiError::class, 'bad_request'];
        yield 'rate limit' => [RateLimitError::class, ApiError::class, 'rate_limited'];
        yield 'payment method' => [PaymentMethodRequiredError::class, HushscriptException::class, 'no_default_card'];
        yield 'upload' => [UploadError::class, HushscriptException::class, 'upload_incomplete'];
        yield 'job failed' => [JobFailedError::class, HushscriptException::class, 'job_failed'];
        yield 'wait timeout' => [WaitTimeoutError::class, HushscriptException::class, 'wait_timeout'];
        yield 'abort' => [AbortError::class, HushscriptException::class, 'aborted'];
        yield 'network' => [NetworkError::class, HushscriptException::class, 'network_error'];
        yield 'outcome unknown' => [OutcomeUnknownError::class, HushscriptException::class, 'outcome_unknown'];
    }

    /**
     * @param  class-string<HushscriptException>  $class
     * @param  class-string<HushscriptException>  $parent
     */
    #[DataProvider('tree')]
    public function testTheClassTreeIsSpec92(string $class, string $parent, string $code): void
    {
        $error = new $class('message');

        self::assertSame($parent, get_parent_class($class));
        self::assertSame($code, $error->errorCode);
        self::assertNull($error->numericCode);
        self::assertNull($error->status);
        self::assertSame([], $error->details);
        self::assertNull($error->retryAfter);
    }

    public function testTheBaseCanBeRaisedDirectly(): void
    {
        $error = new HushscriptException('looped', 'pagination_loop');

        self::assertSame('pagination_loop', $error->errorCode);
        self::assertSame('hushscript_error', (new HushscriptException('x'))->errorCode);
    }

    public function testFieldsAndTheGetCodeContract(): void
    {
        $cause = new \RuntimeException('cause');
        $error = new RateLimitError('slow down', 'rate_limited', 5000, 429, null, ['a' => 1], 7, null, $cause);

        self::assertSame(5000, $error->getCode());
        self::assertSame($cause, $error->getPrevious());
        self::assertSame(7, $error->retryAfter);
        self::assertSame(['a' => 1], $error->details);
        self::assertSame('Too many requests. The SDK already waited and retried; slow down or try again in 7 s.', $error->hint);
        self::assertSame(0, (new NetworkError('x'))->getCode());
    }

    public function testAnExplicitHintBeatsTheCatalogAndAnEmptyOneMeansNone(): void
    {
        self::assertSame('mine', (new RateLimitError('x', 'rate_limited', hint: 'mine'))->hint);
        self::assertNull((new RateLimitError('x', 'rate_limited', hint: ''))->hint);
    }

    public function testConfigurationErrorsNeverGetACatalogHint(): void
    {
        self::assertNull((new ConfigurationError('x', 'unauthorized'))->hint);
    }

    public function testMessageAndHintAreRedacted(): void
    {
        $error = new ApiError('boom '.Tokens::TEST, hint: 'hint '.Tokens::TEST);

        self::assertSame('boom hsr…[redacted]', $error->getMessage());
        self::assertSame('hint hsr…[redacted]', $error->hint);
    }

    public function testTheResumeFieldsCanBeSetAfterwards(): void
    {
        $error = new UploadError('x');
        $key = bin2hex(random_bytes(8));
        $resume = bin2hex(random_bytes(8));
        $error->idempotencyKey = $key;
        $error->resumeToken = $resume;

        self::assertSame([$key, $resume], [$error->idempotencyKey, $error->resumeToken]);
    }

    public function testJobFailedErrorCarriesItsFields(): void
    {
        $error = new JobFailedError('failed', 'job_1', 'bad '.Tokens::TEST, 'media_unreadable');

        self::assertSame('job_1', $error->jobId);
        self::assertSame('bad hsr…[redacted]', $error->error);
        self::assertSame('media_unreadable', $error->failureKind);
        self::assertNull((new JobFailedError('failed'))->error);
    }
}
