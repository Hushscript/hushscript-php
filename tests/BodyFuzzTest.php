<?php

declare(strict_types=1);

namespace Hushscript\Tests;

use GuzzleHttp\Psr7\Response;
use Hushscript\Credentials;
use Hushscript\Exception\ApiError;
use Hushscript\Exception\HushscriptException;
use Hushscript\Exception\OutcomeUnknownError;
use Hushscript\Http\Body;
use Hushscript\Http\Exchange;
use Hushscript\Purchase;
use Hushscript\Resource\Account;
use Hushscript\Resource\Agent;
use Hushscript\Resource\Billing;
use Hushscript\Resource\Transcripts;
use Hushscript\Tests\Support\FakeHttp;
use Hushscript\Tests\Support\PipelineHarness;
use Hushscript\Tests\Support\Rig;
use Hushscript\Tests\Support\Tokens;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every 2xx body of the friendly layer, mutated one field at a time in a fixed set of ways: the call either
 * succeeds or fails with an SDK error, and a body it refuses is `bad_response` carrying the actual status.
 * No TypeError, ValueError or JsonException ever reaches the caller.
 */
final class BodyFuzzTest extends TestCase
{
    private const SENTINEL = '"__mutation__"';

    /** Raw JSON texts put in place of one value. */
    private const MUTATIONS = [
        '"text"', '""', '-1', '0', '1.5', '1e400', '-1e400', '99999999999999999999', '9223372036854775807', '9223372036854775808',
        'true', 'false', '[]', '{}', '[1]', '{"a":1}', 'null', '253402300800', '-1',
    ];

    /**
     * @return array<string, array{array<string, mixed>, \Closure(Rig): mixed}>
     */
    private static function targets(): array
    {
        $row = ['id' => 'tr_1', 'title' => 'T', 'language_detected' => 'en', 'duration_seconds' => 30, 'created_at' => 1760000000, 'auto_delete_at' => 1770000000, 'tags' => ['a']];
        $deleted = ['id' => 'tr_1', 'title' => 'T', 'duration_seconds' => 30, 'created_at' => 1760000000, 'deleted_at' => 1760000100, 'cause' => 'manual', 'receipt' => ['k' => 'v']];
        $transcript = PipelineHarness::transcriptBody();
        $transcript['body'] = [...(array) $transcript['body'], 'translations' => ['es' => ['utterances' => [['speaker' => 'A', 'start_ms' => 1.5, 'end_ms' => 2000, 'text' => 'hola']]]]];
        $transcript['speaker_names'] = ['A' => 'Dana', '0' => 'Zero'];

        return [
            'balance' => [['balance_seconds' => 100, 'held_seconds' => 1, 'credits_expire_at' => 1760000000, 'purchases_remaining_in_window' => 3], static fn (Rig $rig): mixed => (new Account($rig->exchange))->balance()],
            'account' => [['balance_seconds' => 100, 'held_seconds' => 1, 'credits_expire_at' => null, 'card_on_file' => false, 'data_region' => 'eu', 'limits' => ['max_daily_seconds' => 1, 'max_active_jobs' => 2, 'active_jobs' => 0, 'used_today_seconds' => 0]], static fn (Rig $rig): mixed => (new Account($rig->exchange))->account()],
            'transcript' => [$transcript, static fn (Rig $rig): mixed => (new Transcripts($rig->exchange))->get('tr_1')->toSrt('es')],
            'transcripts page' => [['transcripts' => [$row], 'next_cursor' => null], static fn (Rig $rig): mixed => iterator_to_array((new Transcripts($rig->exchange))->list(), false)],
            'deleted page' => [['deleted' => [$deleted], 'next_cursor' => null], static fn (Rig $rig): mixed => iterator_to_array((new Transcripts($rig->exchange))->deleted(), false)],
            'job' => [PipelineHarness::jobBody('done'), static fn (Rig $rig): mixed => (new \Hushscript\Resource\Jobs($rig->exchange, self::pipeline()))->get('job_1')],
            'packs' => [['packs' => [['id' => '45min', 'amount' => 599, 'currency' => 'USD', 'seconds' => 2700, 'referral_bonus_seconds' => 0, 'available' => true]]], static fn (Rig $rig): mixed => (new Billing($rig->exchange))->packs()],
            'cards' => [['default_id' => 'pm_1', 'methods' => [['id' => 'pm_1', 'type' => 'card', 'brand' => 'visa', 'last4' => '4242', 'exp_month' => 1, 'exp_year' => 2030, 'email' => 'a@example.com', 'is_default' => true, 'fast_checkout_eligible' => true]]], static fn (Rig $rig): mixed => (new Billing($rig->exchange))->cards()],
            'purchase' => [['status' => 'ok', 'balance_seconds' => 100], static fn (Rig $rig): mixed => $rig->exchange->fetch('POST', '/v1/billing/purchase', [], [], static fn (#[\SensitiveParameter] Body $body): Purchase => Purchase::fromApi($body))],
            'setup session' => [['checkout_url' => 'https://checkout.example.com/s'], static fn (Rig $rig): mixed => (new Billing($rig->exchange))->addCardUrl()],
            'transactions page' => [['transactions' => [['id' => 1, 'seconds' => 60, 'amount_cents' => 599, 'amount_refunded_cents' => 0, 'currency' => 'usd', 'status' => 'succeeded', 'created_at' => 1760000000, 'payment_method_type' => 'card', 'payment_method_brand' => 'visa', 'payment_method_last4' => '4242', 'receipt_number' => 'R', 'initiated_via' => 'sdk']], 'next_cursor' => null], static fn (Rig $rig): mixed => iterator_to_array((new Billing($rig->exchange))->transactions(), false)],
            'ledger page' => [['entries' => [['id' => 1, 'delta_seconds' => -60, 'kind' => 'settle', 'job_id' => 'j', 'note' => 'n', 'created_at' => 1760000000]], 'next_cursor' => null], static fn (Rig $rig): mixed => iterator_to_array((new Billing($rig->exchange))->ledger(), false)],
            'holds page' => [['holds' => [['id' => 'h', 'kind' => 'transcription', 'seconds' => 60, 'created_at' => 1760000000]], 'next_cursor' => null], static fn (Rig $rig): mixed => iterator_to_array((new Billing($rig->exchange))->holds(), false)],
            'signup' => [['signup_id' => 'sg_1', 'claim_secret' => 'cs_x', 'checkout_url' => 'https://checkout.example.com/p', 'amount' => 599, 'currency' => 'USD', 'expires_at' => 1760001800], static fn (Rig $rig): mixed => Agent::signup($rig->exchange, ['contact_email' => 'a@example.com'])],
            'jobs page' => [['jobs' => [PipelineHarness::jobBody('queued')], 'next_cursor' => null], static fn (Rig $rig): mixed => iterator_to_array((new \Hushscript\Resource\Jobs($rig->exchange, self::pipeline()))->list(), false)],
        ];
    }

    private static function pipeline(): \Hushscript\Transcribe\Pipeline
    {
        return (new PipelineHarness())->pipeline;
    }

    /**
     * @param  array<mixed>  $value
     * @param  list<string|int>  $prefix
     * @return list<list<string|int>>
     */
    private static function paths(array $value, array $prefix = []): array
    {
        $out = [];
        foreach ($value as $key => $item) {
            $out[] = [...$prefix, $key];
            if (is_array($item) && $item !== []) {
                $out = [...$out, ...self::paths($item, [...$prefix, $key])];
            }
        }

        return $out;
    }

    /**
     * @param  array<mixed>  $body
     * @param  list<string|int>  $path
     * @return array<mixed>
     */
    private static function with(array $body, array $path, ?string $sentinel): array
    {
        $key = array_shift($path);
        if ($path === []) {
            if ($sentinel === null) {
                unset($body[$key]);
            } else {
                $body[$key] = $sentinel;
            }

            return $body;
        }
        $inner = $body[$key];
        $body[$key] = self::with(is_array($inner) ? $inner : [], $path, $sentinel);

        return $body;
    }

    /** @return iterable<string, array{string}> */
    public static function names(): iterable
    {
        foreach (array_keys(self::targets()) as $name) {
            yield $name => [$name];
        }
    }

    #[DataProvider('names')]
    public function testMutatedBodiesNeverEscapeAsAnythingButAnSdkError(string $name): void
    {
        [$body, $call] = self::targets()[$name];
        $this->attempt($body, $call, json_encode($body, JSON_THROW_ON_ERROR), true);
        $runs = 0;
        foreach (self::paths($body) as $path) {
            $texts = [json_encode(self::with($body, $path, null), JSON_THROW_ON_ERROR)];
            foreach (self::MUTATIONS as $mutation) {
                $texts[] = str_replace(self::SENTINEL, $mutation, json_encode(self::with($body, $path, '__mutation__'), JSON_THROW_ON_ERROR));
            }
            foreach ($texts as $text) {
                $this->attempt($body, $call, $text, false);
                $runs++;
            }
        }
        self::assertGreaterThanOrEqual(1 + count(self::MUTATIONS), $runs);
    }

    /**
     * @param  array<mixed>  $body
     * @param  \Closure(Rig): mixed  $call
     */
    private function attempt(array $body, \Closure $call, string $text, bool $mustSucceed): void
    {
        // The same answer to every request: a list whose cursor is a string asks for its next page too.
        $http = new class ($text) implements \Psr\Http\Client\ClientInterface {
            public function __construct(private readonly string $text)
            {
            }

            public function sendRequest(\Psr\Http\Message\RequestInterface $request): \Psr\Http\Message\ResponseInterface
            {
                return new Response(203, ['Content-Type' => 'application/json'], $this->text);
            }
        };
        try {
            $call(new Rig(new FakeHttp(), inner: $http));
            $this->addToAssertionCount(1);
        } catch (HushscriptException $error) {
            if ($error->errorCode === 'pagination_loop') {
                return;
            }
            self::assertFalse($mustSucceed, 'the valid body is accepted');
            self::assertInstanceOf(ApiError::class, $error, $text);
            self::assertSame('bad_response', $error->errorCode, $text);
            self::assertSame(203, $error->status, 'the status the API actually answered');
        }
    }

    public function testAVeryDeeplyNestedBodyIsBadResponseNotAnException(): void
    {
        $deep = str_repeat('[', 5000).str_repeat(']', 5000);
        foreach (['{"balance_seconds":1,"held_seconds":0,"x":'.$deep.'}', $deep, '{"a":'.$deep.'}'] as $text) {
            $this->attempt([], static fn (Rig $rig): mixed => (new Account($rig->exchange))->balance(), $text, false);
        }
    }

    public function testNumbersOutsideTheRangeAreRefusedWhereACountIsNeeded(): void
    {
        foreach (['9223372036854775808', '1e400', '-1', '1.0'] as $number) {
            $this->attempt([], static fn (Rig $rig): mixed => (new Account($rig->exchange))->balance(), '{"balance_seconds":100,"held_seconds":'.$number.'}', false);
        }
        $ok = new Rig(new FakeHttp([new Response(200, [], '{"balance_seconds":9223372036854775807,"held_seconds":0}')]));
        self::assertSame(9223372036854775807, (new Account($ok->exchange))->balance()->seconds);
    }

    /** @return iterable<string, array{string}> */
    public static function credentialRoutes(): iterable
    {
        yield 'claim' => ['claim'];
        yield 'rotate' => ['rotate'];
    }

    /**
     * A claim or rotation answer that cannot be used is a lost credential, not bad_response (spec 7.4): whatever
     * one field holds, the call succeeds or raises `OutcomeUnknownError`, and the token the answer carried is kept.
     */
    #[DataProvider('credentialRoutes')]
    public function testAMutatedCredentialAnswerIsASuccessOrAnUnknownOutcomeNeverAnythingElse(string $route): void
    {
        $token = 'hsr1:eu:pat.'.str_repeat('a', 32).'.'.str_repeat('b', 64);
        $body = $route === 'claim'
            ? ['user_id' => 'u', 'pat' => $token, 'scopes' => ['a'], 'balance_seconds' => 5]
            : ['pat' => $token, 'pat_id' => 'p', 'scopes' => ['a'], 'expires_at' => 1760000000];
        $runs = 0;
        foreach (self::paths($body) as $path) {
            $texts = [json_encode(self::with($body, $path, null), JSON_THROW_ON_ERROR)];
            foreach (self::MUTATIONS as $mutation) {
                $texts[] = str_replace(self::SENTINEL, $mutation, json_encode(self::with($body, $path, '__mutation__'), JSON_THROW_ON_ERROR));
            }
            foreach ($texts as $text) {
                $credentials = new Credentials(Tokens::TEST);
                $exchange = new Exchange(new FakeHttp([new Response(200, [], $text)]), $credentials, Rig::BASE);
                try {
                    $route === 'claim'
                        ? Agent::claim($exchange, $credentials, '/v1/agent/accounts/sg_1/claim', 'cs_0123456789abcdef')
                        : (new Agent($exchange, $credentials))->rotateToken();
                } catch (OutcomeUnknownError $error) {
                    self::assertStringNotContainsString($token, print_r($error, true).$error->getMessage(), $text);
                }
                $runs++;
            }
        }
        self::assertGreaterThanOrEqual(1 + count(self::MUTATIONS), $runs);
    }

    /** @return iterable<string, array{string}> */
    public static function nestedModelNames(): iterable
    {
        foreach (['job options', 'upload limits', 'job', 'cards', 'packs', 'transcripts page', 'deleted page', 'transactions page', 'ledger page', 'holds page'] as $name) {
            yield $name => [$name];
        }
    }

    /** @return array<mixed> */
    private static function nestedModelBody(string $name): array
    {
        if ($name === 'job options') {
            $job = PipelineHarness::jobBody();
            self::assertIsArray($job['options']);

            return $job['options'];
        }
        if ($name === 'upload limits') {
            return ['max_daily_seconds' => 100, 'max_active_jobs' => 2, 'active_jobs' => 0, 'used_today_seconds' => 0];
        }
        $body = self::targets()[$name][0];
        if ($name === 'job') {
            return $body;
        }
        $key = match ($name) {
            'cards' => 'methods',
            'packs' => 'packs',
            'transcripts page' => 'transcripts',
            'deleted page' => 'deleted',
            'transactions page' => 'transactions',
            'ledger page' => 'entries',
            'holds page' => 'holds',
            default => self::fail('unknown model '.$name),
        };
        self::assertIsArray($body[$key]);
        self::assertIsArray($body[$key][0]);

        return $body[$key][0];
    }

    /** @param array<mixed> $fields */
    private static function nestedModel(string $name, array $fields): \Hushscript\JobOptions|\Hushscript\UploadLimits|\Hushscript\Job|\Hushscript\Card|\Hushscript\Pack|\Hushscript\TranscriptSummary|\Hushscript\DeletedTranscript|\Hushscript\Transaction|\Hushscript\LedgerEntry|\Hushscript\Hold
    {
        $body = Body::of($fields, 'response');

        return match ($name) {
            'job options' => \Hushscript\JobOptions::fromApi($body),
            'upload limits' => \Hushscript\UploadLimits::fromApi($body),
            'job' => \Hushscript\Job::fromApi($body),
            'cards' => \Hushscript\Card::fromApi($body),
            'packs' => \Hushscript\Pack::fromApi($body),
            'transcripts page' => \Hushscript\TranscriptSummary::fromApi($body),
            'deleted page' => \Hushscript\DeletedTranscript::fromApi($body),
            'transactions page' => \Hushscript\Transaction::fromApi($body),
            'ledger page' => \Hushscript\LedgerEntry::fromApi($body),
            'holds page' => \Hushscript\Hold::fromApi($body),
            default => self::fail('unknown model '.$name),
        };
    }

    #[DataProvider('nestedModelNames')]
    public function testNestedModelsKeepTheirDecodedSnakeCaseBodyInToJson(string $name): void
    {
        foreach ([null, '', ' ', "\t", "\u{2003}", false, 0, 1.5, [], ['nested_key' => ['items' => [null, 'value', ['future_flag' => true]]]]] as $unknown) {
            $fields = [...self::nestedModelBody($name), 'future_field' => $unknown];
            $model = self::nestedModel($name, $fields);

            self::assertSame($fields, $model->toJson());
            $copy = $model->toJson();
            $copy['future_field'] = 'changed';
            self::assertSame($fields, $model->toJson(), 'mutating an accessor result leaves the model intact');
        }
    }

    #[DataProvider('nestedModelNames')]
    public function testRawRetentionDoesNotAcceptMalformedKnownNestedFields(string $name): void
    {
        [$key, $type] = match ($name) {
            'job options' => ['speaker_detection', 'a boolean'],
            'upload limits' => ['max_daily_seconds', 'a whole number'],
            'job' => ['created_at', 'a unix timestamp in seconds'],
            'cards' => ['exp_month', 'a whole number'],
            'packs', 'holds page', 'transactions page' => ['seconds', 'a whole number'],
            'transcripts page', 'deleted page' => ['duration_seconds', 'a whole number'],
            'ledger page' => ['delta_seconds', 'a whole number'],
            default => self::fail('unknown model '.$name),
        };
        foreach ([null, '', ' ', "\t", "\u{2003}", [], '12', 1.5] as $invalid) {
            $fields = [...self::nestedModelBody($name), $key => $invalid, 'future_field' => ['nested_key' => true]];
            try {
                self::nestedModel($name, $fields);
                self::fail('expected BadBody');
            } catch (\Hushscript\Http\BadBody $error) {
                self::assertSame(0, $error->getCode());
                self::assertSame('response.'.$key.': expected '.$type, $error->getMessage());
            }
        }
    }
}
