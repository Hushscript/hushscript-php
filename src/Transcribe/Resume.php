<?php

declare(strict_types=1);

namespace Hushscript\Transcribe;

use Hushscript\Exception\ConfigurationError;
use Hushscript\Exception\ResumeMismatchError;

/**
 * The resume token (spec 6.1): base64url of `{v, jobId, idempotencyKey, size, durationSeconds}`,
 * the same bytes in every SDK, and the checks that bind a resumed call to the original file (7.3).
 */
final class Resume
{
    private const VERSION = 1;

    private function __construct(
        public readonly string $jobId,
        public readonly string $idempotencyKey,
        public readonly int $size,
        public readonly int $durationSeconds,
    ) {
    }

    public static function state(string $jobId, string $idempotencyKey, int $size, int $durationSeconds): self
    {
        return new self($jobId, $idempotencyKey, $size, $durationSeconds);
    }

    public function withJob(string $jobId): self
    {
        return new self($jobId, $this->idempotencyKey, $this->size, $this->durationSeconds);
    }

    public function encode(): string
    {
        $json = json_encode([
            'v' => self::VERSION,
            'jobId' => $this->jobId,
            'idempotencyKey' => $this->idempotencyKey,
            'size' => $this->size,
            'durationSeconds' => $this->durationSeconds,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }

    public static function decode(#[\SensitiveParameter] string $token): self
    {
        $invalid = new ConfigurationError('This is not a resume token from this SDK.');
        $bytes = preg_match('/^[A-Za-z0-9_-]+$/D', $token) === 1 ? base64_decode(strtr($token, '-_', '+/'), true) : false;
        if ($bytes === false) {
            throw $invalid;
        }
        $fields = json_decode($bytes, true);
        if (! is_array($fields) || ($fields['v'] ?? null) !== self::VERSION) {
            throw $invalid;
        }
        $jobId = $fields['jobId'] ?? null;
        $key = $fields['idempotencyKey'] ?? null;
        $size = $fields['size'] ?? null;
        $duration = $fields['durationSeconds'] ?? null;
        if (! is_string($jobId) || $jobId === '' || $jobId === '.' || $jobId === '..' || ! is_string($key) || $key === '' || ! is_int($size) || ! is_int($duration)) {
            throw $invalid;
        }

        return new self($jobId, $key, $size, $duration);
    }

    /** A replay with a different file silently returns the old job (7.3), so it is refused before any request. */
    public function checkSameFile(int $size): void
    {
        if ($size !== $this->size) {
            throw new ResumeMismatchError('The file is '.$size.' bytes; the upload started with '.$this->size.'.', 'resume_file_mismatch');
        }
    }

    public static function fileRequired(): ResumeMismatchError
    {
        return new ResumeMismatchError('The upload was not finished and no file was given.', 'resume_file_required');
    }

    public static function jobMissing(#[\SensitiveParameter] ?\Throwable $previous = null): ResumeMismatchError
    {
        return new ResumeMismatchError('The job behind this resume token no longer exists.', 'resume_job_missing', previous: $previous);
    }
}
