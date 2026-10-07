<?php

declare(strict_types=1);

namespace Hushscript\Tests;

use Hushscript\Client;
use Hushscript\Exception\NetworkError;
use Hushscript\SystemClock;
use Hushscript\Tests\Support\FakeClock;
use Hushscript\Tests\Support\FakeHttp;
use Hushscript\Tests\Support\OptionsHttp;
use Hushscript\Tests\Support\Tokens;
use Hushscript\Version;
use PHPUnit\Framework\TestCase;

final class ClientTest extends TestCase
{
    public function testTheApiWorksThroughACustomPsr18Client(): void
    {
        $http = new FakeHttp([FakeHttp::json(500), FakeHttp::json(200, ['balance_seconds' => 60])]);
        $clock = new FakeClock();
        $client = new Client(Tokens::TEST, ['base_url' => 'http://127.0.0.1:4010', 'http_client' => $http, 'clock' => $clock]);

        self::assertSame(['balance_seconds' => 60], $client->api()->request('GET', '/v1/account'));
        self::assertSame($client->api(), $client->api());
        self::assertCount(2, $http->requests);
        self::assertCount(1, $clock->sleeps);
    }

    public function testTheDefaultClientRetriesAConnectionRefusalAsNotSent(): void
    {
        // Real Guzzle and cURL against a loopback port nobody listens on: connection refused is a setup failure.
        $clock = new FakeClock();
        $client = new Client(Tokens::TEST, ['base_url' => 'http://127.0.0.1:9', 'clock' => $clock, 'max_retries' => 2, 'timeout' => 5]);

        try {
            $client->api()->request('POST', '/v1/org/members', body: ['x' => 1]);
            self::fail('expected a NetworkError');
        } catch (NetworkError $error) {
            self::assertStringContainsString('POST /v1/org/members', $error->getMessage());
        }
        self::assertCount(2, $clock->sleeps, 'a refused connection was not sent, so the mutation was retried twice');
    }

    public function testACustomClientWithOptionsIsToldNotToFollowRedirects(): void
    {
        $answers = new FakeHttp([FakeHttp::json(200, ['balance_seconds' => 60])]);
        $http = OptionsHttp::answering($answers);
        $client = new Client(Tokens::TEST, ['base_url' => 'http://127.0.0.1:4010', 'http_client' => $http, 'clock' => new FakeClock()]);

        self::assertSame(['balance_seconds' => 60], $client->api()->request('GET', '/v1/account'));

        self::assertSame([['max_redirects' => 0]], $http->optionCalls);
        self::assertSame([true], $http->sentConfigured, 'the request went through the client that withOptions() returned');
    }

    public function testTheMaxRetriesOptionIsHonored(): void
    {
        $http = new FakeHttp([FakeHttp::json(503), FakeHttp::json(200, [])]);
        $client = new Client(Tokens::TEST, ['base_url' => 'http://127.0.0.1:4010', 'http_client' => $http, 'clock' => new FakeClock(), 'max_retries' => 0]);

        $this->expectException(\Hushscript\Exception\ApiError::class);
        $client->api()->request('GET', '/v1/account');
    }

    public function testAClientReadsTheTokenFromTheEnvironment(): void
    {
        putenv('HUSHSCRIPT_TOKEN='.Tokens::TEST);
        $http = new FakeHttp([FakeHttp::json(200, [])]);

        try {
            $client = new Client(null, ['base_url' => 'http://127.0.0.1:4010', 'http_client' => $http]);
        } finally {
            putenv('HUSHSCRIPT_TOKEN');
        }
        $client->api()->request('GET', '/v1/account');

        self::assertSame('Bearer '.Tokens::TEST, $http->authorizations[0]);
    }

    public function testTheSystemClockSleepsAndIsMonotonic(): void
    {
        $clock = new SystemClock();
        $before = $clock->now();

        $clock->sleep(0.01);
        $clock->sleep(0.0);
        $clock->sleep(-1.0);

        self::assertGreaterThanOrEqual(0.009, $clock->now() - $before);
    }

    public function testTheVersionIsSemver(): void
    {
        self::assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', Version::SDK);
    }
}
