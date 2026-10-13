<?php

declare(strict_types=1);

namespace Hushscript\Tests;

use Hushscript\Client;
use Hushscript\Config;
use Hushscript\Exception\ConfigurationError;
use Hushscript\Exception\HushscriptException;
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

    /** @return iterable<string, array{?string}> */
    public static function unsetStrings(): iterable
    {
        yield 'null' => [null];
        yield 'empty' => [''];
        yield 'spaces' => ['   '];
        yield 'tab' => ["\t"];
        yield 'all PHP trim whitespace' => [" \t\r\n\0\x0B"];
    }

    #[DataProvider('unsetStrings')]
    public function testTheTokenFallsBackToTheEnvironment(?string $token): void
    {
        $config = Config::resolve($token, [], ['HUSHSCRIPT_TOKEN' => '  '.Tokens::TEST."\n"]);

        self::assertSame(Tokens::TEST, $config->credentials->token());
    }

    /** @return iterable<string, array{?string, ?string}> */
    public static function missingTokenSources(): iterable
    {
        foreach (self::unsetStrings() as $optionName => [$option]) {
            foreach (self::unsetStrings() as $envName => [$env]) {
                yield $optionName.' option, '.$envName.' environment' => [$option, $env];
            }
        }
    }

    #[DataProvider('missingTokenSources')]
    public function testUnsetTokenSourcesKeepTheMissingTokenError(?string $token, ?string $envToken): void
    {
        try {
            Config::resolve($token, [], ['HUSHSCRIPT_TOKEN' => $envToken]);
            self::fail('expected ConfigurationError');
        } catch (HushscriptException $error) {
            self::assertSame(ConfigurationError::class, $error::class);
            self::assertSame('config_invalid', $error->errorCode);
            self::assertSame(0, $error->getCode());
            self::assertNull($error->status);
            self::assertSame('No Hushscript token was provided.', $error->getMessage());
            self::assertSame(Config::SETUP_HINT, $error->hint);
        }
    }

    /** @return iterable<string, array{array<string, mixed>, array<string, ?string>, string, string}> */
    public static function unsetStringOptions(): iterable
    {
        $validEnv = ['HUSHSCRIPT_BASE_URL' => 'http://localhost:4010', 'HUSHSCRIPT_LOG' => 'debug'];
        $defaults = [Config::DEFAULT_BASE_URL, 'off'];
        yield 'omitted options use environment' => [[], $validEnv, 'http://localhost:4010', 'debug'];
        foreach (self::unsetStrings() as $optionName => [$option]) {
            $options = ['base_url' => $option, 'log_level' => $option];
            yield $optionName.' options use environment' => [$options, $validEnv, 'http://localhost:4010', 'debug'];
            yield $optionName.' options use defaults without environment' => [$options, [], ...$defaults];
            foreach (self::unsetStrings() as $envName => [$env]) {
                $blankEnv = ['HUSHSCRIPT_BASE_URL' => $env, 'HUSHSCRIPT_LOG' => $env];
                yield $optionName.' options, '.$envName.' environment use defaults' => [$options, $blankEnv, ...$defaults];
            }
        }
        foreach (self::unsetStrings() as $envName => [$env]) {
            yield 'omitted options, '.$envName.' environment use defaults' => [[], ['HUSHSCRIPT_BASE_URL' => $env, 'HUSHSCRIPT_LOG' => $env], ...$defaults];
        }
    }

    /**
     * @param  array<string, mixed>  $options
     * @param  array<string, ?string>  $env
     */
    #[DataProvider('unsetStringOptions')]
    public function testUnsetStringOptionsFallBackForAuthenticatedAndTokenlessCalls(array $options, array $env, string $baseUrl, string $logLevel): void
    {
        $authenticated = Config::resolve(Tokens::TEST, $options, $env);
        $tokenless = Config::tokenless($options, $env);

        self::assertSame($baseUrl, $authenticated->baseUrl);
        self::assertSame($logLevel, $authenticated->logLevel);
        self::assertSame($baseUrl, $tokenless->baseUrl);
        self::assertSame($logLevel, $tokenless->logLevel);
        self::assertSame('', $tokenless->credentials->token());
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
            self::assertSame(0, $error->getCode());
            self::assertNull($error->status);
            self::assertSame('No Hushscript token was provided.', $error->getMessage());
            self::assertSame(
                'Set HUSHSCRIPT_TOKEN or pass a token. Create one in the Hushscript app: Account → MCP (app.hushscript.com/account/mcp).',
                $error->hint,
            );
        }
    }

    /** @return iterable<string, array{string}> */
    public static function missingTokens(): iterable
    {
        yield 'empty' => [''];
        yield 'whitespace only' => ['   '];
    }

    #[DataProvider('missingTokens')]
    public function testAnEmptyOrBlankTokenWithoutTheEnvironmentFailsBeforeAnyRequest(string $token): void
    {
        $previous = getenv('HUSHSCRIPT_TOKEN');
        putenv('HUSHSCRIPT_TOKEN');
        $http = new FakeHttp();
        try {
            try {
                new Client($token, ['http_client' => $http]);
                self::fail('expected ConfigurationError');
            } catch (HushscriptException $error) {
                self::assertSame(ConfigurationError::class, $error::class);
                self::assertSame('config_invalid', $error->errorCode);
                self::assertNull($error->status);
                self::assertSame(Config::SETUP_HINT, $error->hint);
                self::assertSame('No Hushscript token was provided.', $error->getMessage());
                self::assertSame(0, $error->getCode());
            }
            self::assertSame(0, $http->count());
        } finally {
            putenv($previous === false ? 'HUSHSCRIPT_TOKEN' : 'HUSHSCRIPT_TOKEN='.$previous);
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function badTokens(): iterable
    {
        $characters = 'The token contains control or non-printable characters.';
        yield 'wrong prefix' => ['abc1:eu:pat.00.00', "This doesn't look like a Hushscript token."];
        yield 'newline inside' => ["hsr1:eu:pat.00\n.00", $characters];
        yield 'control char' => ["hsr1:eu:pat.00\x07.00", $characters];
        yield 'space inside' => ['hsr1:eu:pat.00 .00', $characters];
        yield 'non ascii' => ['hsr1:eu:pat.00é.00', $characters];
        yield 'nul' => ["hsr1\0:eu:pat.00.00", $characters];
        yield 'unicode whitespace is not PHP trim whitespace' => ["\u{00A0}", $characters];
    }

    #[DataProvider('badTokens')]
    public function testABadTokenIsRejectedWithoutEchoingIt(string $token, string $message): void
    {
        $http = new FakeHttp();
        try {
            new Client($token, ['http_client' => $http]);
            self::fail('expected a ConfigurationError');
        } catch (HushscriptException $error) {
            self::assertNull($error->hint);
            self::assertSame(ConfigurationError::class, $error::class);
            self::assertSame('config_invalid', $error->errorCode);
            self::assertSame(0, $error->getCode());
            self::assertNull($error->status);
            self::assertSame($message, $error->getMessage());
            self::assertSame(0, $http->count());
            self::assertStringNotContainsString(trim($token), $error->getMessage());
            self::assertStringNotContainsString('pat.', $error->getMessage());
        }
    }

    /** @return iterable<string, array{mixed, string}> */
    public static function badBaseUrls(): iterable
    {
        $origin = 'base_url must be https://, or http:// to localhost, 127.0.0.1 or [::1].';
        $path = 'base_url must not have a path, query or fragment.';
        yield 'plain http to a real host' => ['http://api.hushscript.com', $origin];
        yield 'a path' => ['https://api.hushscript.com/v1', $path];
        yield 'a query' => ['https://api.hushscript.com?x=1', $path];
        yield 'a fragment' => ['https://api.hushscript.com#x', $path];
        yield 'credentials' => ['https://user:pw@api.hushscript.com', $origin];
        yield 'no scheme' => ['api.hushscript.com', $origin];
        yield 'ftp' => ['ftp://localhost', $origin];
        yield 'localhost lookalike' => ['http://localhost.evil.example', $origin];
        yield 'not a string' => [42, 'base_url must be a string.'];
        yield 'padded origin retains validation' => [' https://api.hushscript.com ', $origin];
        yield 'unicode whitespace is not PHP trim whitespace' => ["\u{00A0}", $origin];
    }

    #[DataProvider('badBaseUrls')]
    public function testABadBaseUrlIsRejected(mixed $baseUrl, string $message): void
    {
        $http = new FakeHttp();
        try {
            new Client(Tokens::TEST, ['base_url' => $baseUrl, 'http_client' => $http]);
            self::fail('expected ConfigurationError');
        } catch (HushscriptException $error) {
            self::assertSame(ConfigurationError::class, $error::class);
            self::assertSame('config_invalid', $error->errorCode);
            self::assertNull($error->status);
            self::assertNull($error->hint);
            self::assertSame(0, $error->getCode());
            self::assertSame($message, $error->getMessage());
        }
        self::assertSame(0, $http->count());
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
        yield 'bad http client' => [['http_client' => new \stdClass()]];
        yield 'unknown option' => [['maxRetries' => 2]];
    }

    /** @param array<string, mixed> $options */
    #[DataProvider('badOptions')]
    public function testABadOptionIsRejected(array $options): void
    {
        $http = new FakeHttp();
        try {
            new Client(Tokens::TEST, $options + ['http_client' => $http]);
            self::fail('expected ConfigurationError');
        } catch (HushscriptException $error) {
            self::assertSame(ConfigurationError::class, $error::class);
            self::assertSame('config_invalid', $error->errorCode);
            self::assertNull($error->status);
            self::assertNull($error->hint);
        }
        self::assertSame(0, $http->count());
    }

    public function testABadLogLevelInTheEnvironmentIsRejected(): void
    {
        $this->expectException(ConfigurationError::class);
        Config::resolve(Tokens::TEST, [], ['HUSHSCRIPT_LOG' => 'verbose']);
    }

    /** @return iterable<string, array{?string, array<string, mixed>, array<string, ?string>, string}> */
    public static function invalidStringSources(): iterable
    {
        $tokenError = "This doesn't look like a Hushscript token.";
        $baseError = 'base_url must be https://, or http:// to localhost, 127.0.0.1 or [::1].';
        $logError = 'log_level must be one of off, debug.';
        yield 'bad token beats good environment' => ['bad', [], ['HUSHSCRIPT_TOKEN' => Tokens::TEST], $tokenError];
        yield 'bad environment token after blank option' => ["\t", [], ['HUSHSCRIPT_TOKEN' => 'bad'], $tokenError];
        yield 'bad base URL beats good environment' => [Tokens::TEST, ['base_url' => 'ftp://localhost'], ['HUSHSCRIPT_BASE_URL' => Config::DEFAULT_BASE_URL], $baseError];
        yield 'bad environment base URL after blank option' => [Tokens::TEST, ['base_url' => ''], ['HUSHSCRIPT_BASE_URL' => 'ftp://localhost'], $baseError];
        yield 'nonstring base URL beats good environment' => [Tokens::TEST, ['base_url' => false], ['HUSHSCRIPT_BASE_URL' => Config::DEFAULT_BASE_URL], 'base_url must be a string.'];
        yield 'bad log level beats good environment' => [Tokens::TEST, ['log_level' => 'trace'], ['HUSHSCRIPT_LOG' => 'debug'], $logError];
        yield 'bad environment log level after blank option' => [Tokens::TEST, ['log_level' => ' '], ['HUSHSCRIPT_LOG' => 'trace'], $logError];
        yield 'nonstring log level beats good environment' => [Tokens::TEST, ['log_level' => false], ['HUSHSCRIPT_LOG' => 'debug'], $logError];
        yield 'padded log level retains validation' => [Tokens::TEST, ['log_level' => ' debug '], ['HUSHSCRIPT_LOG' => 'debug'], $logError];
        yield 'unicode whitespace log level is not PHP trim whitespace' => [Tokens::TEST, ['log_level' => "\u{00A0}"], ['HUSHSCRIPT_LOG' => 'debug'], $logError];
        yield 'padded environment log level retains validation' => [Tokens::TEST, [], ['HUSHSCRIPT_LOG' => ' debug '], $logError];
        yield 'padded environment base URL retains validation' => [Tokens::TEST, [], ['HUSHSCRIPT_BASE_URL' => ' https://api.hushscript.com '], $baseError];
    }

    /**
     * @param  array<string, mixed>  $options
     * @param  array<string, ?string>  $env
     */
    #[DataProvider('invalidStringSources')]
    public function testNonblankInvalidStringSourcesAreNeverMaskedByFallback(?string $token, array $options, array $env, string $message): void
    {
        try {
            Config::resolve($token, $options, $env);
            self::fail('expected ConfigurationError');
        } catch (HushscriptException $error) {
            self::assertSame(ConfigurationError::class, $error::class);
            self::assertSame('config_invalid', $error->errorCode);
            self::assertSame(0, $error->getCode());
            self::assertNull($error->status);
            self::assertNull($error->hint);
            self::assertSame($message, $error->getMessage());
        }
    }

    public function testOptionsAreAccepted(): void
    {
        $clock = new FakeClock();
        $http = new FakeHttp();
        $config = Config::resolve(Tokens::TEST, ['timeout' => 5, 'max_retries' => 0, 'log_level' => 'debug', 'clock' => $clock, 'http_client' => $http], []);

        self::assertSame(5.0, $config->timeout);
        self::assertSame(0, $config->maxRetries);
        self::assertSame($clock, $config->clock);
        self::assertSame($http, $config->httpClient);
    }

    public function testTheClientConstructorReportsConfigurationErrors(): void
    {
        $this->expectException(ConfigurationError::class);
        new Client(null, ['base_url' => 'ftp://nope']);
    }
}
