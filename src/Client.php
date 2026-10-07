<?php

declare(strict_types=1);

namespace Hushscript;

use GuzzleHttp\Client as GuzzleClient;
use Hushscript\Http\Exchange;
use Hushscript\Http\RetryMiddleware;
use Hushscript\Http\RetryPolicy;
use Hushscript\Http\WaitTicker;
use Hushscript\Resource\Account as AccountResource;
use Hushscript\Resource\Jobs;
use Hushscript\Resource\Transcripts;
use Hushscript\Transcribe\GuzzleMultiTransport;
use Hushscript\Transcribe\PartTransport;
use Hushscript\Transcribe\Pipeline;
use Hushscript\Transcribe\SyncTransport;
use Psr\Http\Client\ClientInterface;

/**
 * Entry point of the SDK. The token never appears in any representation of this object.
 */
final class Client
{
    private readonly Config $config;

    private readonly Api $api;

    private readonly Pipeline $pipeline;

    private readonly Jobs $jobs;

    private readonly Transcripts $transcripts;

    private readonly AccountResource $accountResource;

    /**
     * @param  array<string, mixed>  $options  base_url, timeout, max_retries, log_level, clock, http_client (a PSR-18 client), part_transport (advanced: how part uploads are sent)
     *
     * @throws \Hushscript\Exception\ConfigurationError
     */
    public function __construct(#[\SensitiveParameter] ?string $token = null, #[\SensitiveParameter] array $options = [])
    {
        $this->config = Config::resolve($token, $options);
        $log = new Log($this->config->logLevel);
        $inner = $this->config->httpClient === null
            ? self::defaultHttpClient($this->config->timeout)
            : self::withoutRedirects($this->config->httpClient, $log);
        $ticker = new WaitTicker();
        $transport = new RetryMiddleware(
            $inner,
            $this->config->credentials,
            $this->config->baseUrl,
            $this->config->clock,
            $log,
            $this->config->maxRetries,
            ticker: $ticker,
        );
        $exchange = new Exchange($transport, $this->config->credentials, $this->config->baseUrl);
        $this->api = new Api($exchange);
        $config = $this->config;
        $this->pipeline = new Pipeline(
            $exchange,
            $config->credentials,
            $config->baseUrl,
            $config->clock,
            $log,
            $config->maxRetries,
            static fn (): PartTransport => $config->partTransport
                ?? ($config->httpClient === null && GuzzleMultiTransport::available() ? new GuzzleMultiTransport($config->timeout) : new SyncTransport($inner)),
            RetryPolicy::fullJitter(...),
            $ticker,
        );
        $this->jobs = new Jobs($exchange, $this->pipeline);
        $this->transcripts = new Transcripts($exchange);
        $this->accountResource = new AccountResource($exchange);
    }

    public function api(): Api
    {
        return $this->api;
    }

    /**
     * Uploads a file, waits for the transcript and returns it (spec 8). Part uploads run concurrently
     * on the default HTTP client; with a client of your own they go one at a time.
     *
     * @param  string  $path  a path to an MP3, M4A/MP4/MOV, WAV, FLAC or Ogg file
     * @param  array<string, mixed>  $options  title, language, speakers, translate_to, keyterms, prompt, medical,
     *                                         multichannel, auto_insights, matter_code, duration_seconds,
     *                                         idempotency_key, concurrency, wait_timeout, on_progress, on_resume_token
     *
     * @throws \Hushscript\Exception\HushscriptException
     */
    public function transcribe(string $path, array $options = []): Transcript
    {
        return $this->pipeline->transcribe($path, $options);
    }

    /**
     * Picks a transcription up from the resume token of an earlier call (spec 7.3). The file is needed
     * only while the job is still uploading. The options may also come second: `resume($token, $options)`.
     *
     * @param  array<string, mixed>|string|null  $file  a path, or the options when no file is given
     * @param  array<string, mixed>  $options  concurrency, wait_timeout, on_progress
     *
     * @throws \Hushscript\Exception\HushscriptException
     */
    public function resume(#[\SensitiveParameter] string $token, array|string|null $file = null, array $options = []): Transcript
    {
        if (is_array($file)) {
            return $this->pipeline->resume($token, null, $file);
        }

        return $this->pipeline->resume($token, $file, $options);
    }

    public function jobs(): Jobs
    {
        return $this->jobs;
    }

    public function transcripts(): Transcripts
    {
        return $this->transcripts;
    }

    /**
     * What can be spent now (spec 6.3): `seconds` is already net of holds, not "balance minus held".
     *
     * @throws \Hushscript\Exception\HushscriptException
     */
    public function balance(): Balance
    {
        return $this->accountResource->balance();
    }

    /** @throws \Hushscript\Exception\HushscriptException */
    public function account(): Account
    {
        return $this->accountResource->account();
    }

    /**
     * Redirects are never followed (R55). A client that can be reconfigured, like Symfony's
     * HttpClient, is told so; any other client must already behave that way (README). The method is
     * called only when it is public and takes one array (or untyped) argument; anything else, or a
     * failure, leaves the client as it is, because a foreign client's quirks never break construction.
     */
    private static function withoutRedirects(ClientInterface $client, Log $log): ClientInterface
    {
        if (! self::acceptsOptions($client)) {
            return $client;
        }
        try {
            $configured = $client->withOptions(['max_redirects' => 0]); // @phpstan-ignore method.notFound
        } catch (\Throwable $failure) {
            $log->note('withOptions() failed ('.$failure::class.'); the HTTP client is used as it is');

            return $client;
        }

        return $configured instanceof ClientInterface ? $configured : $client;
    }

    private static function acceptsOptions(ClientInterface $client): bool
    {
        if (! method_exists($client, 'withOptions')) {
            return false;
        }
        try {
            $method = new \ReflectionMethod($client, 'withOptions');
        } catch (\ReflectionException) {
            return false;
        }
        if (! $method->isPublic() || $method->isStatic() || $method->getNumberOfRequiredParameters() !== 1) {
            return false;
        }
        $type = $method->getParameters()[0]->getType();

        return $type === null || ($type instanceof \ReflectionNamedType && in_array($type->getName(), ['array', 'mixed'], true));
    }

    private static function defaultHttpClient(float $timeout): ClientInterface
    {
        // Redirects are never followed and 4xx/5xx come back as responses: the SDK's transport
        // decides what to retry and builds the typed error.
        return new GuzzleClient([
            'timeout' => $timeout,
            'connect_timeout' => $timeout,
            'allow_redirects' => false,
            'http_errors' => false,
        ]);
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['baseUrl' => $this->config->baseUrl, 'token' => Redact::MARKER];
    }

    public function __serialize(): array
    {
        throw new \LogicException('Client cannot be serialized.');
    }

    /** @param array<mixed> $data */
    public function __unserialize(array $data): void
    {
        throw new \LogicException('Client cannot be unserialized.');
    }
}
