<?php

declare(strict_types=1);

namespace Hushscript\Tests\Transcribe;

use Hushscript\Exception\ConfigurationError;
use Hushscript\Exception\ResumeMismatchError;
use Hushscript\Transcribe\Resume;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ResumeTokenTest extends TestCase
{
    public function testTheTokenIsTheSameBytesAsInEverySdk(): void
    {
        $token = Resume::state('job_1', 'key-0123456789abcdef', 1234, 21)->encode();

        self::assertSame(
            rtrim(strtr(base64_encode('{"v":1,"jobId":"job_1","idempotencyKey":"key-0123456789abcdef","size":1234,"durationSeconds":21}'), '+/', '-_'), '='),
            $token,
        );
        self::assertDoesNotMatchRegularExpression('/[=+\/]/', $token);
    }

    public function testTheTokenRoundTripsForArbitraryValidInputs(): void
    {
        mt_srand(5);
        for ($round = 0; $round < 200; $round++) {
            $job = 'job_'.bin2hex(random_bytes(mt_rand(1, 12))).'/'.chr(mt_rand(0x21, 0x7E));
            $key = '';
            for ($index = 0, $length = mt_rand(16, 128); $index < $length; $index++) {
                $key .= chr(mt_rand(0x21, 0x7E));
            }
            $size = mt_rand(1, PHP_INT_MAX);
            $duration = mt_rand(15, 36000);

            $state = Resume::decode(Resume::state($job, $key, $size, $duration)->encode());

            self::assertSame([$job, $key, $size, $duration], [$state->jobId, $state->idempotencyKey, $state->size, $state->durationSeconds]);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function malformed(): iterable
    {
        yield 'empty' => [''];
        yield 'not base64' => ['!!!'];
        yield 'padding' => ['abcd=='];
        yield 'not json' => [rtrim(strtr(base64_encode('hello'), '+/', '-_'), '=')];
        yield 'json list' => [rtrim(strtr(base64_encode('[1]'), '+/', '-_'), '=')];
        yield 'wrong version' => [rtrim(strtr(base64_encode('{"v":2,"jobId":"j","idempotencyKey":"k","size":1,"durationSeconds":1}'), '+/', '-_'), '=')];
        yield 'no job' => [rtrim(strtr(base64_encode('{"v":1,"idempotencyKey":"k","size":1,"durationSeconds":1}'), '+/', '-_'), '=')];
        yield 'dot job' => [rtrim(strtr(base64_encode('{"v":1,"jobId":"..","idempotencyKey":"k","size":1,"durationSeconds":1}'), '+/', '-_'), '=')];
        yield 'empty job' => [rtrim(strtr(base64_encode('{"v":1,"jobId":"","idempotencyKey":"k","size":1,"durationSeconds":1}'), '+/', '-_'), '=')];
        yield 'float size' => [rtrim(strtr(base64_encode('{"v":1,"jobId":"j","idempotencyKey":"k","size":1.5,"durationSeconds":1}'), '+/', '-_'), '=')];
        yield 'string duration' => [rtrim(strtr(base64_encode('{"v":1,"jobId":"j","idempotencyKey":"k","size":1,"durationSeconds":"1"}'), '+/', '-_'), '=')];
    }

    #[DataProvider('malformed')]
    public function testAMalformedTokenIsAConfigurationErrorThatDoesNotEchoIt(string $token): void
    {
        try {
            Resume::decode($token);
            self::fail('expected a ConfigurationError');
        } catch (ConfigurationError $error) {
            self::assertSame('config_invalid', $error->errorCode);
            if ($token !== '') {
                self::assertStringNotContainsString($token, $error->getMessage());
            }
        }
    }

    public function testADifferentSizeIsRefused(): void
    {
        $state = Resume::state('j', 'k', 100, 21);
        $state->checkSameFile(100);

        try {
            $state->checkSameFile(101);
            self::fail('expected a ResumeMismatchError');
        } catch (ResumeMismatchError $error) {
            self::assertSame('resume_file_mismatch', $error->errorCode);
            self::assertNotNull($error->hint);
        }
    }

    public function testTheMissingFileAndMissingJobErrorsCarryTheirCodes(): void
    {
        self::assertSame('resume_file_required', Resume::fileRequired()->errorCode);
        self::assertNotNull(Resume::fileRequired()->hint);
        $cause = new \RuntimeException('x');
        self::assertSame('resume_job_missing', Resume::jobMissing($cause)->errorCode);
        self::assertSame($cause, Resume::jobMissing($cause)->getPrevious());
    }
}
