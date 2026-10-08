<?php

declare(strict_types=1);

namespace Hushscript\Tests\Transcribe;

use Hushscript\Exception\PreflightError;
use Hushscript\Transcribe\Options;
use Hushscript\Transcribe\Preflight;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OptionsTest extends TestCase
{
    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalid(): iterable
    {
        yield 'unknown key' => [['durationSeconds' => 30]];
        yield 'concurrency zero' => [['concurrency' => 0]];
        yield 'concurrency negative' => [['concurrency' => -1]];
        yield 'concurrency nine' => [['concurrency' => 9]];
        yield 'concurrency float' => [['concurrency' => 1.5]];
        yield 'concurrency whole float' => [['concurrency' => 2.0]];
        yield 'concurrency string' => [['concurrency' => '2']];
        yield 'concurrency string four' => [['concurrency' => '4']];
        yield 'wait timeout zero' => [['wait_timeout' => 0]];
        yield 'wait timeout negative' => [['wait_timeout' => -1]];
        yield 'wait timeout NaN' => [['wait_timeout' => NAN]];
        yield 'wait timeout infinite' => [['wait_timeout' => INF]];
        yield 'duration negative' => [['duration_seconds' => -1]];
        yield 'key 15 characters' => [['idempotency_key' => str_repeat('a', 15)]];
        yield 'duration zero' => [['duration_seconds' => 0]];
        yield 'duration NaN' => [['duration_seconds' => NAN]];
        yield 'duration infinite' => [['duration_seconds' => INF]];
        yield 'duration string' => [['duration_seconds' => '30']];
        yield 'key too short' => [['idempotency_key' => 'short']];
        yield 'key with a space' => [['idempotency_key' => '0123456789abcdef 0123']];
        yield 'key too long' => [['idempotency_key' => str_repeat('a', 129)]];
        yield 'key with a control character' => [['idempotency_key' => "0123456789abcdef\x01"]];
        yield 'medical without a language' => [['medical' => true]];
        yield 'medical in the wrong language' => [['medical' => true, 'language' => 'ja']];
        yield 'too many keyterms' => [['keyterms' => array_fill(0, 1001, 'a')]];
        yield 'a keyterm of seven words' => [['keyterms' => ['one two three four five six seven']]];
        yield 'keyterms not a list' => [['keyterms' => ['a' => 'b']]];
        yield 'matter code too long' => [['matter_code' => str_repeat('m', 65)]];
        yield 'title not a string' => [['title' => 5]];
        yield 'speakers not a bool' => [['speakers' => 'yes']];
        yield 'auto insights not a bool' => [['auto_insights' => 1]];
        yield 'resume token not callable' => [['on_resume_token' => 'no_such_function_here']];
        yield 'progress not callable' => [['on_progress' => 'no_such_function_here']];
        yield 'translate_to with a number' => [['translate_to' => ['es', 3]]];
    }

    /** @param array<string, mixed> $options */
    #[DataProvider('invalid')]
    public function testAnInvalidOptionIsRefusedBeforeAnyRequest(array $options): void
    {
        $harness = new \Hushscript\Tests\Support\PipelineHarness();
        try {
            $harness->pipeline->transcribe($harness->path(), $options);
            self::fail('expected a PreflightError');
        } catch (PreflightError $error) {
            self::assertSame(PreflightError::class, $error::class);
            self::assertSame('invalid_option', $error->errorCode);
            self::assertNull($error->status);
        }
        self::assertSame([], $harness->api->requests);
        self::assertSame([], $harness->parts->sent);
    }

    public function testTheDefaults(): void
    {
        $options = Options::forTranscribe([]);

        self::assertSame(4, $options->concurrency);
        self::assertSame(3600.0, $options->waitTimeout);
        self::assertTrue($options->speakers);
        self::assertSame([], $options->transcriptionOptions());
        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $options->newKey());
        self::assertNotSame($options->newKey(), $options->newKey());
    }

    public function testACallerKeyIsKept(): void
    {
        self::assertSame('0123456789abcdef', Options::forTranscribe(['idempotency_key' => '0123456789abcdef'])->newKey());
    }

    public function testTheOptionsBecomeTheApiBody(): void
    {
        $options = Options::forTranscribe([
            'title' => 'Board call',
            'language' => 'de',
            'speakers' => false,
            'keyterms' => ['Hushscript', 'two words'],
            'prompt' => 'A meeting',
            'medical' => true,
            'multichannel' => true,
            'auto_insights' => false,
            'matter_code' => 'M-1',
        ]);

        self::assertSame(
            [
                'language_code' => 'de',
                'speaker_detection' => false,
                'keyterms_prompt' => ['Hushscript', 'two words'],
                'prompt' => 'A meeting',
                'medical_mode' => true,
                'multichannel' => true,
                'auto_generate_insights' => false,
            ],
            $options->transcriptionOptions(),
        );
        self::assertSame(
            ['size_bytes' => 10, 'duration_seconds' => 21, 'idempotency_key' => 'k', 'title' => 'Board call', 'matter_code' => 'M-1', 'transcription_options' => $options->transcriptionOptions()],
            Preflight::createBody(10, 21, $options, 'k'),
        );
    }

    public function testTranslationForcesSpeakersAndListsTheTargets(): void
    {
        $wire = Options::forTranscribe(['speakers' => false, 'translate_to' => ['es', 'fr']])->transcriptionOptions();

        self::assertSame(['translation_targets' => ['es', 'fr']], $wire);
    }

    public function testResumeAndWaitTakeTheirOwnOptionsOnly(): void
    {
        self::assertSame(2, Options::forResume(['concurrency' => 2])->concurrency);
        self::assertSame(9.0, Options::forWait(['wait_timeout' => 9])->waitTimeout);
        foreach ([fn () => Options::forResume(['title' => 'x']), fn () => Options::forWait(['concurrency' => 2])] as $call) {
            try {
                $call();
                self::fail('expected a PreflightError');
            } catch (PreflightError $error) {
                self::assertSame(PreflightError::class, $error::class);
                self::assertSame('invalid_option', $error->errorCode);
                self::assertNull($error->status);
            }
        }
    }

    public function testProgressReachesTheCallback(): void
    {
        $seen = [];
        $options = Options::forTranscribe(['on_progress' => static function (array $progress) use (&$seen): void {
            $seen[] = $progress;
        }]);

        $options->progress(['stage' => 'uploading']);
        Options::forTranscribe([])->progress(['stage' => 'ignored']);

        self::assertSame([['stage' => 'uploading']], $seen);
    }

    public function testADumpOfTheOptionsShowsNoCallback(): void
    {
        $secret = 'captured-resume-token-value';
        $options = Options::forTranscribe([
            'on_progress' => static function (array $event) use ($secret): void {
                $held = [$event, $secret];
                unset($held);
            },
            'on_resume_token' => static function (string $token) use ($secret): void {
                $held = [$token, $secret];
                unset($held);
            },
        ]);

        $dump = print_r($options, true);
        ob_start();
        var_dump($options);
        $dump .= (string) ob_get_clean();

        self::assertStringNotContainsString('Closure', $dump);
        self::assertStringNotContainsString($secret, $dump);
        self::assertStringContainsString('…[callback]', $dump);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function acceptedBoundaries(): iterable
    {
        yield 'concurrency eight' => [['concurrency' => 8]];
        yield '1000 keyterms' => [['keyterms' => array_fill(0, 1000, 'term')]];
        yield 'six words' => [['keyterms' => ['one two three four five six']]];
        yield '64 character matter code' => [['matter_code' => str_repeat('m', 64)]];
        yield '128 character key' => [['idempotency_key' => str_repeat('k', 128)]];
    }

    /** @param array<string, mixed> $raw */
    #[DataProvider('acceptedBoundaries')]
    public function testAcceptedOptionBoundariesReachCreateUnchanged(array $raw): void
    {
        $harness = new \Hushscript\Tests\Support\PipelineHarness();
        $harness->pipeline->transcribe($harness->path(), $raw);
        $create = $harness->api->to('POST', '/v1/uploads');
        self::assertCount(1, $create);
        $body = json_decode((string) $create[0]->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        $options = Options::forTranscribe($raw);
        self::assertSame($raw['concurrency'] ?? 4, $options->concurrency);
        self::assertSame($raw['keyterms'] ?? [], $options->keyterms);
        self::assertSame($raw['matter_code'] ?? null, $options->matterCode);
        if (isset($raw['idempotency_key'])) {
            self::assertSame($raw['idempotency_key'], $body['idempotency_key']);
        }
        if (isset($raw['keyterms'])) {
            self::assertIsArray($body['transcription_options']);
            self::assertSame($raw['keyterms'], $body['transcription_options']['keyterms_prompt']);
        }
        if (isset($raw['matter_code'])) {
            self::assertSame($raw['matter_code'], $body['matter_code']);
        }
    }

    /** @return iterable<string, array{string, array<string, mixed>}> */
    public static function invalidOtherOptions(): iterable
    {
        foreach (['resume', 'wait'] as $operation) {
            yield $operation.' unknown' => [$operation, ['unknown' => 1]];
        }
        foreach ([0, -1, 9, 1.5, '2'] as $index => $concurrency) {
            yield 'resume concurrency '.$index => ['resume', ['concurrency' => $concurrency]];
        }
    }

    /** @param array<string, mixed> $raw */
    #[DataProvider('invalidOtherOptions')]
    public function testResumeAndWaitRejectInvalidOptionsWithoutRequests(string $operation, array $raw): void
    {
        $harness = new \Hushscript\Tests\Support\PipelineHarness();
        try {
            if ($operation === 'resume') {
                $token = \Hushscript\Transcribe\Resume::state(\Hushscript\Tests\Support\PipelineHarness::JOB, 'key-1', $harness->size, 20)->encode();
                $harness->pipeline->resume($token, null, $raw);
            } else {
                $harness->pipeline->wait('job_1', $raw);
            }
            self::fail('expected invalid_option');
        } catch (PreflightError $error) {
            self::assertSame(PreflightError::class, $error::class);
            self::assertSame('invalid_option', $error->errorCode);
            self::assertNull($error->status);
        }
        self::assertSame([], $harness->api->requests);
        self::assertSame([], $harness->parts->sent);
    }
}
