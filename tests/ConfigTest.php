<?php

declare(strict_types=1);

namespace Hushscript\Tests;

use Hushscript\Client;
use Hushscript\Config;
use Hushscript\Exception\ConfigurationError;
use Hushscript\Log;
use Hushscript\SystemClock;
use Hushscript\Tests\Support\FakeClock;
use Hushscript\Tests\Support\FakeHttp;
use Hushscript\Tests\Support\Tokens;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    public function testDefaults(): void
    {
        $config = Config::resolve(Tokens::TEST, [], []);

        self::assertSame('https://api.hushscript.com', $config->baseUrl);
        self::assertSame(60.0, $config->timeout);
        self::assertSame(3, $config->maxRetries);
        self::assertSame('off', $config->logLevel);
        self::assertSame(Tokens::TEST, $config->credentials->token());
        self::assertInstanceOf(SystemClock::class, $config->clock);
        self::assertNull($config->httpClient);
    }

    public function testTheTokenFallsBackToTheEnvironment(): void
    {
        $config = Config::resolve(null, [], ['HUSHSCRIPT_TOKEN' => '  '.Tokens::TEST."\n"]);

        self::assertSame(Tokens::TEST, $config->credentials->token());
    }

    public function testAnExplicitTokenBeatsTheEnvironment(): void
    {
        $config = Config::resolve(Tokens::TEST, [], ['HUSHSCRIPT_TOKEN' => Tokens::ROTATED]);

        self::assertSame(Tokens::TEST, $config->credentials->token());
    }

    public function testTheProcessEnvironmentIsReadWhenNoneIsGiven(): void
    {
        putenv('HUSHSCRIPT_TOKEN='.Tokens::ROTATED);
        putenv('HUSHSCRIPT_LOG=debug');
        putenv('HUSHSCRIPT_BASE_URL=http://localhost:4010');

        try {
            $config = Config::resolve();
        } finally {
            putenv('HUSHSCRIPT_TOKEN');
            putenv('HUSHSCRIPT_LOG');
            putenv('HUSHSCRIPT_BASE_URL');
        }

        self::assertSame(Tokens::ROTATED, $config->credentials->token());
        self::assertSame('debug', $config->logLevel);
        self::assertSame('http://localhost:4010', $config->baseUrl);
    }

    public function testAnExplicitOptionBeatsItsEnvironmentVariable(): void
    {
        $config = Config::resolve(Tokens::TEST, ['log_level' => 'off', 'base_url' => 'https://example.com'], [
            'HUSHSCRIPT_LOG' => 'debug',
            'HUSHSCRIPT_BASE_URL' => 'https://other.example',
        ]);

        self::assertSame('off', $config->logLevel);
        self::assertSame('https://example.com', $config->baseUrl);
    }

    public function testTheEnvironmentSetsLogLevelAndBaseUrl(): void
    {
        $config = Config::resolve(Tokens::TEST, [], ['HUSHSCRIPT_LOG' => 'debug', 'HUSHSCRIPT_BASE_URL' => 'http://127.0.0.1:4010/']);

        self::assertSame('debug', $config->logLevel);
        self::assertSame('http://127.0.0.1:4010', $config->baseUrl);
    }

    public function testAMissingTokenCarriesTheSetupHint(): void
    {
        try {
            Config::resolve(null, [], []);
            self::fail('expected a ConfigurationError');
        } catch (ConfigurationError $error) {
            self::assertSame('config_invalid', $error->errorCode);
            self::assertNull($error->status);
            self::assertSame(
                'Set HUSHSCRIPT_TOKEN or pass a token. Create one in the Hushscript app: Account → MCP (app.hushscript.com/account/mcp).',
                $error->hint,
            );
        }
    }

    public function testAnEmptyExplicitTokenFallsBackToTheEnvironmentThenFails(): void
    {
        $this->expectException(ConfigurationError::class);
        Config::resolve('', [], []);
    }

    /** @return iterable<string, array{string}> */
    public static function badTokens(): iterable
    {
        yield 'wrong prefix' => ['abc1:eu:pat.00.00'];
        yield 'newline inside' => ["hsr1:eu:pat.00\n.00"];
        yield 'control char' => ["hsr1:eu:pat.00\x07.00"];
        yield 'space inside' => ['hsr1:eu:pat.00 .00'];
        yield 'non ascii' => ['hsr1:eu:pat.00é.00'];
        yield 'nul' => ["hsr1\0:eu:pat.00.00"];
    }

    #[DataProvider('badTokens')]
    public function testABadTokenIsRejectedWithoutEchoingIt(string $token): void
    {
        try {
            Config::resolve($token, [], []);
            self::fail('expected a ConfigurationError');
        } catch (ConfigurationError $error) {
            self::assertNull($error->hint);
            self::assertStringNotContainsString(trim($token), $error->getMessage());
            self::assertStringNotContainsString('pat.', $error->getMessage());
        }
    }

    /** @return iterable<string, array{mixed}> */
    public static function badBaseUrls(): iterable
    {
        yield 'plain http to a real host' => ['http://api.hushscript.com'];
        yield 'a path' => ['https://api.hushscript.com/v1'];
        yield 'a query' => ['https://api.hushscript.com?x=1'];
        yield 'a fragment' => ['https://api.hushscript.com#x'];
        yield 'credentials' => ['https://user:pw@api.hushscript.com'];
        yield 'no scheme' => ['api.hushscript.com'];
        yield 'ftp' => ['ftp://localhost'];
        yield 'localhost lookalike' => ['http://localhost.evil.example'];
        yield 'not a string' => [42];
    }

    #[DataProvider('badBaseUrls')]
    public function testABadBaseUrlIsRejected(mixed $baseUrl): void
    {
        $this->expectException(ConfigurationError::class);
        Config::resolve(Tokens::TEST, ['base_url' => $baseUrl], []);
    }

    /** @return iterable<string, array{string, string}> */
    public static function goodBaseUrls(): iterable
    {
        yield 'prod' => ['https://api.hushscript.com', 'https://api.hushscript.com'];
        yield 'upper case' => ['HTTPS://API.HUSHSCRIPT.COM/', 'https://api.hushscript.com'];
        yield 'localhost' => ['http://localhost:4010', 'http://localhost:4010'];
        yield 'ipv4 loopback' => ['http://127.0.0.1:4010', 'http://127.0.0.1:4010'];
        yield 'ipv6 loopback' => ['http://[::1]:4010', 'http://[::1]:4010'];
    }

    #[DataProvider('goodBaseUrls')]
    public function testAGoodBaseUrlIsNormalized(string $given, string $expected): void
    {
        self::assertSame($expected, Config::resolve(Tokens::TEST, ['base_url' => $given], [])->baseUrl);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function badOptions(): iterable
    {
        yield 'zero timeout' => [['timeout' => 0]];
        yield 'negative timeout' => [['timeout' => -1]];
        yield 'string timeout' => [['timeout' => '5']];
        yield 'infinite timeout' => [['timeout' => INF]];
        yield 'negative retries' => [['max_retries' => -1]];
        yield 'float retries' => [['max_retries' => 1.5]];
        yield 'bad log level' => [['log_level' => 'trace']];
        yield 'non string log level' => [['log_level' => 3]];
        yield 'bad clock' => [['clock' => 'now']];
        yield 'bad http client' => [['http_client' => new \stdClass]];
        yield 'unknown option' => [['maxRetries' => 2]];
    }

    /** @param array<string, mixed> $options */
    #[DataProvider('badOptions')]
    public function testABadOptionIsRejected(array $options): void
    {
        try {
            Config::resolve(Tokens::TEST, $options, []);
            self::fail('expected a ConfigurationError');
        } catch (ConfigurationError $error) {
            self::assertNull($error->hint);
        }
    }

    public function testABadLogLevelInTheEnvironmentIsRejected(): void
    {
        $this->expectException(ConfigurationError::class);
        Config::resolve(Tokens::TEST, [], ['HUSHSCRIPT_LOG' => 'verbose']);
    }

    public function testOptionsAreAccepted(): void
    {
        $clock = new FakeClock;
        $http = new FakeHttp;
        $config = Config::resolve(Tokens::TEST, ['timeout' => 5, 'max_retries' => 0, 'log_level' => 'debug', 'clock' => $clock, 'http_client' => $http], []);

        self::assertSame(5.0, $config->timeout);
        self::assertSame(0, $config->maxRetries);
        self::assertSame($clock, $config->clock);
        self::assertSame($http, $config->httpClient);
        self::assertSame(['off', 'debug'], Log::LEVELS);
    }

    public function testTheClientConstructorReportsConfigurationErrors(): void
    {
        $this->expectException(ConfigurationError::class);
        new Client(null, ['base_url' => 'ftp://nope']);
    }
}
