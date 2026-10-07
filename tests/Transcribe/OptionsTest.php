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
        yield 'concurrency nine' => [['concurrency' => 9]];
        yield 'concurrency float' => [['concurrency' => 2.0]];
        yield 'concurrency string' => [['concurrency' => '4']];
        yield 'wait timeout zero' => [['wait_timeout' => 0]];
        yield 'wait timeout negative' => [['wait_timeout' => -1]];
        yield 'wait timeout NaN' => [['wait_timeout' => NAN]];
        yield 'wait timeout infinite' => [['wait_timeout' => INF]];
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
        yield 'progress not callable' => [['on_progress' => 'no_such_function_here']];
        yield 'translate_to with a number' => [['translate_to' => ['es', 3]]];
    }

    /** @param array<string, mixed> $options */
    #[DataProvider('invalid')]
    public function testAnInvalidOptionIsRefusedBeforeAnyRequest(array $options): void
    {
        try {
            Options::forTranscribe($options);
            self::fail('expected a PreflightError');
        } catch (PreflightError $error) {
            self::assertSame('invalid_option', $error->errorCode);
            self::assertNull($error->status);
        }
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

    public function testTheEstimateAddsAQuarterPerTranslationAndDoublesForMedical(): void
    {
        self::assertSame(21, Preflight::estimate(21, false, []));
        self::assertSame(42, Preflight::estimate(21, true, []));
        self::assertSame(32, Preflight::estimate(21, false, ['es', 'fr']));
        self::assertSame(48, Preflight::estimate(21, true, ['es']));
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
                self::assertSame('invalid_option', $error->errorCode);
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
                unset($event, $secret);
            },
            'on_resume_token' => static function (string $token) use ($secret): void {
                unset($token, $secret);
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
}
