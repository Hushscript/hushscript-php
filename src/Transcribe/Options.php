<?php

declare(strict_types=1);

namespace Hushscript\Transcribe;

use Hushscript\Exception\PreflightError;

/**
 * The options of `transcribe()` (spec 6.1), snake_case, checked before any request.
 */
final class Options
{
    public const MAX_CONCURRENCY = 8;

    public const DEFAULT_CONCURRENCY = 4;

    public const DEFAULT_WAIT_TIMEOUT = 3600.0;

    private const KEY_MIN = 16;

    private const KEY_MAX = 128;

    private const MAX_KEYTERMS = 1000;

    private const MAX_KEYTERM_WORDS = 6;

    private const MAX_MATTER_CODE = 64;

    private const MEDICAL_LANGUAGES = ['en', 'es', 'fr', 'de'];

    private const TRANSCRIBE = [
        'title', 'language', 'speakers', 'translate_to', 'keyterms', 'prompt', 'medical', 'multichannel',
        'auto_insights', 'matter_code', 'duration_seconds', 'idempotency_key', 'concurrency', 'wait_timeout',
        'on_progress', 'on_resume_token',
    ];

    private const RESUME = ['concurrency', 'wait_timeout', 'on_progress'];

    private const WAIT = ['wait_timeout', 'on_progress'];

    /**
     * @param  list<string>  $translateTo
     * @param  list<string>  $keyterms
     * @param  (\Closure(array<string, mixed>): void)|null  $onProgress
     * @param  (\Closure(string): void)|null  $onResumeToken
     */
    private function __construct(
        public readonly ?string $title,
        public readonly ?string $language,
        public readonly bool $speakers,
        public readonly array $translateTo,
        public readonly array $keyterms,
        public readonly ?string $prompt,
        public readonly bool $medical,
        public readonly bool $multichannel,
        public readonly ?bool $autoInsights,
        public readonly ?string $matterCode,
        public readonly float|int|null $durationSeconds,
        public readonly ?string $idempotencyKey,
        public readonly int $concurrency,
        public readonly float $waitTimeout,
        public readonly ?\Closure $onProgress,
        public readonly ?\Closure $onResumeToken,
    ) {
    }

    /** @param array<string, mixed> $raw */
    public static function forTranscribe(#[\SensitiveParameter] array $raw): self
    {
        return self::from($raw, self::TRANSCRIBE);
    }

    /** @param array<string, mixed> $raw */
    public static function forResume(#[\SensitiveParameter] array $raw): self
    {
        return self::from($raw, self::RESUME);
    }

    /** @param array<string, mixed> $raw */
    public static function forWait(#[\SensitiveParameter] array $raw): self
    {
        return self::from($raw, self::WAIT);
    }

    /**
     * @param  array<string, mixed>  $raw
     * @param  list<string>  $allowed
     */
    private static function from(#[\SensitiveParameter] array $raw, array $allowed): self
    {
        foreach (array_keys($raw) as $name) {
            if (! in_array($name, $allowed, true)) {
                throw self::invalid('Unknown option "'.$name.'".');
            }
        }
        $concurrency = $raw['concurrency'] ?? self::DEFAULT_CONCURRENCY;
        if (! is_int($concurrency) || $concurrency < 1 || $concurrency > self::MAX_CONCURRENCY) {
            throw self::invalid('concurrency must be a whole number from 1 to '.self::MAX_CONCURRENCY.'.');
        }
        $waitTimeout = self::positiveNumber($raw['wait_timeout'] ?? self::DEFAULT_WAIT_TIMEOUT);
        if ($waitTimeout === null) {
            throw self::invalid('wait_timeout must be a positive number of seconds.');
        }
        $duration = $raw['duration_seconds'] ?? null;
        if ($duration !== null && self::positiveNumber($duration) === null) {
            throw self::invalid('duration_seconds must be a positive number of seconds.');
        }
        $key = self::optionalString($raw, 'idempotency_key');
        if ($key !== null && (strlen($key) < self::KEY_MIN || strlen($key) > self::KEY_MAX || preg_match('/^[\x21-\x7E]+$/D', $key) !== 1)) {
            throw self::invalid('idempotency_key must be '.self::KEY_MIN.' to '.self::KEY_MAX.' visible ASCII characters, no spaces.');
        }
        $language = self::optionalString($raw, 'language');
        $medical = self::flag($raw, 'medical', false);
        if ($medical && ! in_array($language, self::MEDICAL_LANGUAGES, true)) {
            throw self::invalid('medical needs language to be one of en, es, fr, de.');
        }
        $keyterms = self::strings($raw, 'keyterms');
        if (count($keyterms) > self::MAX_KEYTERMS) {
            throw self::invalid('keyterms takes up to '.self::MAX_KEYTERMS.' terms of at most '.self::MAX_KEYTERM_WORDS.' words.');
        }
        foreach ($keyterms as $term) {
            $words = preg_split('/\s+/u', trim($term), -1, PREG_SPLIT_NO_EMPTY);
            if ($words !== false && count($words) > self::MAX_KEYTERM_WORDS) {
                throw self::invalid('keyterms takes up to '.self::MAX_KEYTERMS.' terms of at most '.self::MAX_KEYTERM_WORDS.' words.');
            }
        }
        $matterCode = self::optionalString($raw, 'matter_code');
        if ($matterCode !== null && mb_strlen($matterCode) > self::MAX_MATTER_CODE) {
            throw self::invalid('matter_code must be at most '.self::MAX_MATTER_CODE.' characters.');
        }
        $auto = $raw['auto_insights'] ?? null;
        if ($auto !== null && ! is_bool($auto)) {
            throw self::invalid('auto_insights must be true, false or null.');
        }

        return new self(
            self::optionalString($raw, 'title'),
            $language,
            self::flag($raw, 'speakers', true),
            self::strings($raw, 'translate_to'),
            $keyterms,
            self::optionalString($raw, 'prompt'),
            $medical,
            self::flag($raw, 'multichannel', false),
            $auto,
            $matterCode,
            is_int($duration) || is_float($duration) ? $duration : null,
            $key,
            $concurrency,
            $waitTimeout,
            self::callback($raw, 'on_progress'),
            self::callback($raw, 'on_resume_token'),
        );
    }

    public function newKey(): string
    {
        return $this->idempotencyKey ?? self::uuid4();
    }

    public static function uuid4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);
        $hex = bin2hex($bytes);

        return substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-'.substr($hex, 12, 4).'-'.substr($hex, 16, 4).'-'.substr($hex, 20);
    }

    /** @return array<string, mixed> */
    public function transcriptionOptions(): array
    {
        $wire = [];
        if ($this->language !== null) {
            $wire['language_code'] = $this->language;
        }
        if (! $this->speakers && $this->translateTo === []) {
            $wire['speaker_detection'] = false;
        }
        if ($this->translateTo !== []) {
            $wire['translation_targets'] = $this->translateTo;
        }
        if ($this->keyterms !== []) {
            $wire['keyterms_prompt'] = $this->keyterms;
        }
        if ($this->prompt !== null) {
            $wire['prompt'] = $this->prompt;
        }
        if ($this->medical) {
            $wire['medical_mode'] = true;
        }
        if ($this->multichannel) {
            $wire['multichannel'] = true;
        }
        if ($this->autoInsights !== null) {
            $wire['auto_generate_insights'] = $this->autoInsights;
        }

        return $wire;
    }

    /** @param array<string, mixed> $progress */
    public function progress(array $progress): void
    {
        if ($this->onProgress !== null) {
            ($this->onProgress)($progress);
        }
    }

    private static function positiveNumber(mixed $value): ?float
    {
        return (is_int($value) || is_float($value)) && is_finite((float) $value) && $value > 0 ? (float) $value : null;
    }

    /** @param array<string, mixed> $raw */
    private static function optionalString(#[\SensitiveParameter] array $raw, string $name): ?string
    {
        $value = $raw[$name] ?? null;
        if ($value !== null && ! is_string($value)) {
            throw self::invalid($name.' must be a string.');
        }

        return $value;
    }

    /** @param array<string, mixed> $raw */
    private static function flag(#[\SensitiveParameter] array $raw, string $name, bool $default): bool
    {
        $value = $raw[$name] ?? $default;
        if (! is_bool($value)) {
            throw self::invalid($name.' must be true or false.');
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return list<string>
     */
    private static function strings(#[\SensitiveParameter] array $raw, string $name): array
    {
        $value = $raw[$name] ?? [];
        if (! is_array($value) || ! array_is_list($value)) {
            throw self::invalid($name.' must be a list of strings.');
        }
        foreach ($value as $item) {
            if (! is_string($item)) {
                throw self::invalid($name.' must be a list of strings.');
            }
        }

        return $value;
    }

    /** @param array<string, mixed> $raw */
    private static function callback(#[\SensitiveParameter] array $raw, string $name): ?\Closure
    {
        $value = $raw[$name] ?? null;
        if ($value === null) {
            return null;
        }
        if (! is_callable($value)) {
            throw self::invalid($name.' must be callable.');
        }

        return \Closure::fromCallable($value);
    }

    /**
     * The options without the caller's callbacks, whose captured variables could hold anything (a resume token among them).
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        $fields = get_object_vars($this);
        foreach (['onProgress', 'onResumeToken'] as $callback) {
            $fields[$callback] = $fields[$callback] === null ? null : '…[callback]';
        }

        return $fields;
    }

    private static function invalid(string $message): PreflightError
    {
        return new PreflightError($message, 'invalid_option');
    }
}
